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

        update_comment_meta($comment_id, self::APPROVED_NOTIFIED_META, 1);
        wp_mail($email, $subject, $body);
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

        update_comment_meta($reply_id, self::REPLY_NOTIFIED_META, 1);
        wp_mail($to, $subject, $body);
    }
}
