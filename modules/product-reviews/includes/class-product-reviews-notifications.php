<?php
/**
 * Reviewer-facing notifications and the email opt-out list.
 *
 * Covers the two emails a reviewer expects but WordPress never sends: "your
 * review was published" (core only notifies the moderator) and "the store
 * replied to your review" (core's wp_notify_postauthor notifies the author of
 * the *post*, never the author of the parent comment).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Notifications
{
    const OPTOUT_OPTION = 'ffla_review_email_optouts';

    const UNSUBSCRIBE_ACTION = 'ffla_review_unsubscribe';

    const UNSUBSCRIBE_CONFIRM_ACTION = 'ffla_review_unsubscribe_confirm';

    /** Guards against re-sending when a review is unapproved and approved again. */
    const APPROVED_NOTIFIED_META = '_ffla_approved_notified';

    const REPLY_NOTIFIED_META = '_ffla_reply_notified';

    const STORE_REPLY_META = 'ffla_is_store_reply';

    /** On a review: the store was told about it. */
    const STORE_NOTIFIED_META = '_ffla_store_review_notified';

    public static function init(): void
    {
        add_action('transition_comment_status', [__CLASS__, 'on_comment_status_transition'], 10, 3);
        add_action('comment_post', [__CLASS__, 'on_comment_post'], 20, 2);

        add_action('admin_post_' . self::UNSUBSCRIBE_ACTION, [__CLASS__, 'render_unsubscribe_confirm']);
        add_action('admin_post_nopriv_' . self::UNSUBSCRIBE_ACTION, [__CLASS__, 'render_unsubscribe_confirm']);
        add_action('admin_post_' . self::UNSUBSCRIBE_CONFIRM_ACTION, [__CLASS__, 'handle_unsubscribe']);
        add_action('admin_post_nopriv_' . self::UNSUBSCRIBE_CONFIRM_ACTION, [__CLASS__, 'handle_unsubscribe']);
    }

    /* ---------------------------------------------------------------------
     * Opt-out list
     * ------------------------------------------------------------------- */

    private static function email_hash(string $email): string
    {
        return md5(strtolower(trim($email)));
    }

    /**
     * @return array<string, int> hash => opt-out timestamp
     */
    private static function get_optouts(): array
    {
        $stored = get_option(self::OPTOUT_OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    public static function optout_count(): int
    {
        return count(self::get_optouts());
    }

    public static function is_opted_out(string $email): bool
    {
        if ($email === '') {
            return false;
        }

        return array_key_exists(self::email_hash($email), self::get_optouts());
    }

    public static function opt_out(string $email): void
    {
        if (!is_email($email)) {
            return;
        }

        $optouts = self::get_optouts();
        $hash    = self::email_hash($email);
        if (isset($optouts[$hash])) {
            return;
        }

        $optouts[$hash] = time();
        update_option(self::OPTOUT_OPTION, $optouts, false);
    }

    public static function opt_in(string $email): void
    {
        $optouts = self::get_optouts();
        $hash    = self::email_hash($email);
        if (!isset($optouts[$hash])) {
            return;
        }

        unset($optouts[$hash]);
        update_option(self::OPTOUT_OPTION, $optouts, false);
    }

    /* ---------------------------------------------------------------------
     * Unsubscribe link
     * ------------------------------------------------------------------- */

    private static function unsubscribe_secret(): string
    {
        return wp_salt('ffla_product_reviews_unsubscribe');
    }

    /**
     * Unsubscribe tokens never expire. A link that stops working is a link that
     * forces the recipient to mark the mail as spam instead.
     */
    public static function build_unsubscribe_token(string $email): string
    {
        $hash    = self::email_hash($email);
        $sig     = hash_hmac('sha256', $hash, self::unsubscribe_secret());
        $payload = $hash . '|' . $sig;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private static function parse_unsubscribe_token(string $token): ?string
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $s   = strtr($token, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }

        $bin = base64_decode($s, true);
        if ($bin === false) {
            return null;
        }

        $parts = explode('|', $bin, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$hash, $sig] = $parts;
        if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
            return null;
        }

        $expected = hash_hmac('sha256', $hash, self::unsubscribe_secret());
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return $hash;
    }

    public static function unsubscribe_url(string $email): string
    {
        return add_query_arg(
            [
                'action' => self::UNSUBSCRIBE_ACTION,
                't'      => rawurlencode(self::build_unsubscribe_token($email)),
            ],
            admin_url('admin-post.php')
        );
    }

    /**
     * Corporate mail scanners follow every link in an inbound message. A
     * one-click GET unsubscribe would therefore opt people out before they ever
     * open the email, so the link only renders a confirmation form and the
     * actual opt-out happens on POST.
     */
    public static function render_unsubscribe_confirm(): void
    {
        $token = isset($_GET['t']) ? sanitize_text_field(wp_unslash($_GET['t'])) : '';
        if (self::parse_unsubscribe_token($token) === null) {
            wp_die(
                esc_html__('This unsubscribe link is not valid.', 'ffl-funnels-addons'),
                esc_html__('Unsubscribe', 'ffl-funnels-addons'),
                ['response' => 400]
            );
        }

        // Not passed through wp_kses_post(): its allowlist is post-content tags,
        // which excludes form, input, and button — it would strip the page to
        // nothing. Every interpolated value is escaped individually instead.
        $form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::UNSUBSCRIBE_CONFIRM_ACTION) . '">'
            . '<input type="hidden" name="t" value="' . esc_attr($token) . '">'
            . '<p>' . esc_html__('Stop sending me review request emails.', 'ffl-funnels-addons') . '</p>'
            . '<p><button type="submit">' . esc_html__('Confirm unsubscribe', 'ffl-funnels-addons') . '</button></p>'
            . '</form>';

        wp_die(
            $form, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            esc_html__('Unsubscribe', 'ffl-funnels-addons'),
            ['response' => 200]
        );
    }

    public static function handle_unsubscribe(): void
    {
        $token = isset($_POST['t']) ? sanitize_text_field(wp_unslash($_POST['t'])) : '';
        $hash  = self::parse_unsubscribe_token($token);

        if ($hash === null) {
            wp_die(
                esc_html__('This unsubscribe link is not valid.', 'ffl-funnels-addons'),
                esc_html__('Unsubscribe', 'ffl-funnels-addons'),
                ['response' => 400]
            );
        }

        // The token carries the hash, not the address, so the list is keyed by
        // hash and we never need to know the plaintext email to honour it.
        $optouts = self::get_optouts();
        if (!isset($optouts[$hash])) {
            $optouts[$hash] = time();
            update_option(self::OPTOUT_OPTION, $optouts, false);
        }

        wp_die(
            esc_html__('You will no longer receive review request emails.', 'ffl-funnels-addons'),
            esc_html__('Unsubscribed', 'ffl-funnels-addons'),
            ['response' => 200]
        );
    }

    /**
     * Appended to review request emails only. The approval and reply notices are
     * direct responses to something the recipient did, not solicitations.
     */
    public static function unsubscribe_footer(string $email): string
    {
        return "\n\n---\n" . sprintf(
            /* translators: %s: unsubscribe URL */
            __('Do not want these emails? Unsubscribe here: %s', 'ffl-funnels-addons'),
            self::unsubscribe_url($email)
        );
    }

    /* ---------------------------------------------------------------------
     * Reviewer notifications
     * ------------------------------------------------------------------- */

    /**
     * @param int|string $comment_approved
     */
    public static function on_comment_post(int $comment_id, $comment_approved): void
    {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }

        self::maybe_flag_store_reply($comment);
        Product_Reviews_Core::flush_product_review_caches((int) $comment->comment_post_ID);

        if (!in_array((string) $comment_approved, ['spam', 'trash'], true)) {
            self::maybe_notify_store($comment);
        }

        if (1 === (int) $comment_approved) {
            self::maybe_notify_reply($comment);
        }
    }

    /**
     * @param string      $new_status
     * @param string      $old_status
     * @param \WP_Comment $comment
     */
    public static function on_comment_status_transition($new_status, $old_status, $comment): void
    {
        if (!$comment instanceof \WP_Comment) {
            return;
        }

        Product_Reviews_Core::flush_product_review_caches((int) $comment->comment_post_ID);

        if ('approved' !== $new_status || 'approved' === $old_status) {
            return;
        }

        if ((int) $comment->comment_parent > 0) {
            self::maybe_notify_reply($comment);
            return;
        }

        self::maybe_notify_review_approved($comment);
    }

    private static function is_product_review(\WP_Comment $comment): bool
    {
        return 'review' === $comment->comment_type
            && 'product' === get_post_type((int) $comment->comment_post_ID);
    }

    /**
     * Record who answered at the moment they answered. Deciding "is this the
     * store?" at render time would silently reclassify old replies whenever a
     * staff member's role changes.
     */
    private static function maybe_flag_store_reply(\WP_Comment $comment): void
    {
        if ((int) $comment->comment_parent <= 0) {
            return;
        }

        $parent = get_comment((int) $comment->comment_parent);
        if (!$parent || !self::is_product_review($parent)) {
            return;
        }

        $user_id = (int) $comment->user_id;
        if ($user_id > 0 && user_can($user_id, 'moderate_comments')) {
            update_comment_meta((int) $comment->comment_ID, self::STORE_REPLY_META, 1);
        }
    }

    private static function maybe_notify_review_approved(\WP_Comment $comment): void
    {
        if ('1' !== Product_Reviews_Core::get_setting('notify_on_approved', '1')) {
            return;
        }

        if (!self::is_product_review($comment)) {
            return;
        }

        $comment_id = (int) $comment->comment_ID;
        if (get_comment_meta($comment_id, self::APPROVED_NOTIFIED_META, true)) {
            return;
        }

        $email = (string) $comment->comment_author_email;
        if (!is_email($email)) {
            return;
        }

        $product_id = (int) $comment->comment_post_ID;
        $product    = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $name       = $product ? $product->get_name() : get_the_title($product_id);
        $permalink  = get_permalink($product_id);

        $subject = apply_filters(
            'ffla_review_approved_email_subject',
            sprintf(
                /* translators: %s: product name */
                __('Your review of %s is now published', 'ffl-funnels-addons'),
                $name
            ),
            $comment
        );

        $body = apply_filters(
            'ffla_review_approved_email_body',
            sprintf(
                /* translators: 1: reviewer name, 2: product name, 3: product URL */
                __("Hi %1\$s,\n\nThanks for reviewing %2\$s. Your review is now live:\n%3\$s\n\nWe appreciate you taking the time.", 'ffl-funnels-addons'),
                $comment->comment_author !== '' ? $comment->comment_author : __('there', 'ffl-funnels-addons'),
                $name,
                $permalink ? $permalink . '#reviews' : home_url('/')
            ),
            $comment
        );

        self::send($email, $subject, $body, $comment_id, self::APPROVED_NOTIFIED_META, 'approval');
    }

    private static function maybe_notify_reply(\WP_Comment $reply): void
    {
        if ('1' !== Product_Reviews_Core::get_setting('notify_on_reply', '1')) {
            return;
        }

        $parent_id = (int) $reply->comment_parent;
        if ($parent_id <= 0) {
            return;
        }

        $parent = get_comment($parent_id);
        if (!$parent || !self::is_product_review($parent)) {
            return;
        }

        $reply_id = (int) $reply->comment_ID;
        if (get_comment_meta($reply_id, self::REPLY_NOTIFIED_META, true)) {
            return;
        }

        // Only the store's answers are relayed. Anyone else's approved reply
        // would otherwise turn this into a way to email a reviewer any text.
        if ((int) get_comment_meta($reply_id, self::STORE_REPLY_META, true) !== 1) {
            return;
        }

        $to = (string) $parent->comment_author_email;
        if (!is_email($to)) {
            return;
        }

        // Do not mail people about their own follow-up to their own review.
        if (strtolower(trim($to)) === strtolower(trim((string) $reply->comment_author_email))) {
            return;
        }

        $product_id = (int) $parent->comment_post_ID;
        $product    = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $name       = $product ? $product->get_name() : get_the_title($product_id);
        $permalink  = get_permalink($product_id);
        $link       = $permalink ? $permalink . '#comment-' . $reply_id : home_url('/');

        $subject = apply_filters(
            'ffla_review_reply_email_subject',
            sprintf(
                /* translators: %s: product name */
                __('Someone replied to your review of %s', 'ffl-funnels-addons'),
                $name
            ),
            $reply,
            $parent
        );

        $body = apply_filters(
            'ffla_review_reply_email_body',
            sprintf(
                /* translators: 1: reviewer name, 2: product name, 3: reply text, 4: link to the reply */
                __("Hi %1\$s,\n\nYou received a reply to your review of %2\$s:\n\n%3\$s\n\nRead it here:\n%4\$s", 'ffl-funnels-addons'),
                $parent->comment_author !== '' ? $parent->comment_author : __('there', 'ffl-funnels-addons'),
                $name,
                wp_strip_all_tags((string) $reply->comment_content),
                $link
            ),
            $reply,
            $parent
        );

        self::send($to, $subject, $body, $reply_id, self::REPLY_NOTIFIED_META, 'reply');
    }

    /**
     * Emails → New review emails: the store hears about every new review
     * (or only low ratings), with the rating, the text, whether it waits for
     * approval and a link to it.
     */
    private static function maybe_notify_store(\WP_Comment $review): void
    {
        if ('1' !== Product_Reviews_Core::get_setting('notify_store_new_review', '0') || !self::is_product_review($review) || (int) $review->comment_parent > 0) {
            return;
        }

        $review_id = (int) $review->comment_ID;
        if (get_comment_meta($review_id, self::STORE_NOTIFIED_META, true)) {
            return;
        }

        $rating = (int) get_comment_meta($review_id, 'rating', true);
        $max    = max(1, min(5, absint(Product_Reviews_Core::get_setting('notify_store_max_rating', '5')) ?: 5));
        if ($rating > $max) {
            return;
        }

        $to = sanitize_email((string) Product_Reviews_Core::get_setting('notify_store_email', ''));
        if (!is_email($to)) {
            $to = (string) get_option('admin_email');
        }
        if (!is_email($to)) {
            return;
        }

        $product_id = (int) $review->comment_post_ID;
        $product    = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $name       = $product ? $product->get_name() : get_the_title($product_id);
        $held       = '1' !== (string) $review->comment_approved;
        $media      = get_comment_meta($review_id, 'ffla_review_media_ids', true);
        $media      = is_array($media) ? count($media) : 0;

        $lines = [
            sprintf(
                /* translators: 1: reviewer name, 2: product name */
                __('%1$s reviewed %2$s.', 'ffl-funnels-addons'),
                (string) $review->comment_author,
                $name
            ),
            '',
            $rating > 0 ? sprintf(
                /* translators: %d: star rating 1-5 */
                __('Rating: %d of 5 stars', 'ffl-funnels-addons'),
                $rating
            ) : __('Rating: none', 'ffl-funnels-addons'),
            Product_Reviews_Core::is_verified_purchase($review_id) ? __('Verified buyer: yes', 'ffl-funnels-addons') : __('Verified buyer: no', 'ffl-funnels-addons'),
        ];
        if ($media > 0) {
            $lines[] = sprintf(
                /* translators: %d: number of files */
                _n('%d photo or video attached', '%d photos or videos attached', $media, 'ffl-funnels-addons'),
                $media
            );
        }
        $lines[] = '';
        $lines[] = wp_strip_all_tags((string) $review->comment_content);
        $lines[] = '';
        $lines[] = $held
            ? __('It waits for your approval:', 'ffl-funnels-addons') . "\n" . Product_Reviews_Core::reviews_admin_url('moderated')
            : __('It is published:', 'ffl-funnels-addons') . "\n" . get_permalink($product_id) . '#review-' . $review_id;

        $subject = sprintf(
            /* translators: 1: star rating, 2: product name */
            __('New %1$s-star review of %2$s', 'ffl-funnels-addons'),
            $rating > 0 ? (string) $rating : '–',
            $name
        );

        if (wp_mail($to, $subject, implode("\n", $lines), self::mail_headers())) {
            update_comment_meta($review_id, self::STORE_NOTIFIED_META, 1);
        }
    }

    /**
     * The "already notified" flag is only written when wp_mail() succeeds, so
     * a failed send is not silently recorded as done: the next approval of
     * the review (or reply) tries again. Failures are logged under
     * WooCommerce → Status → Logs (source ffla-product-reviews).
     */
    private static function send(string $to, string $subject, string $body, int $comment_id, string $notified_meta, string $kind): void
    {
        if (wp_mail($to, $subject, $body, self::mail_headers())) {
            update_comment_meta($comment_id, $notified_meta, 1);

            return;
        }

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning(
                sprintf('Comment #%d: review %s email could not be sent; it is tried again the next time the comment is approved.', $comment_id, $kind),
                ['source' => 'ffla-product-reviews']
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private static function mail_headers(): array
    {
        return class_exists('Product_Reviews_Email') ? Product_Reviews_Email::mail_headers() : [];
    }
}
