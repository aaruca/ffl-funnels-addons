<?php
/**
 * Coupons for reviews (Coupons tab).
 *
 * When a review is published (straight away, or when a held review is
 * approved), its author gets a coupon by email if:
 * - the review carries Verified buyer (or, in the stricter mode, was sent
 *   through the order's email link);
 * - it is tied to an order (the email link's order, or the newest order of
 *   the signed-in buyer that contains the product) that has not earned a
 *   coupon yet: one coupon per order;
 * - the customer's role qualifies.
 *
 * The coupon does not depend on the rating. Which tier applies depends on
 * how many photos or videos the review has. A tier either creates a unique
 * coupon (restricted to the customer's email, one use) or sends an existing
 * coupon code. Reviews that earned a coupon can show an "incentivized
 * review" badge, which disclosure rules (such as the FTC's in the US)
 * expect.
 *
 * Other coupon rules on the store apply as usual; with the Smart Coupons
 * module on, its guardrails (firearms left out, minimum prices) cover these
 * coupons too.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Coupons
{
    const ACTION_SEND_EMAIL = 'ffla_send_review_coupon_email';

    /** On the review: the coupon code it earned. */
    const REVIEW_CODE_META = 'ffla_review_coupon_code';

    /** On the review: a reward decision was made (so it is made once). */
    const REVIEW_CHECKED_META = '_ffla_review_coupon_checked';

    /** On the review: the badge shows. */
    const INCENTIVIZED_META = 'ffla_incentivized';

    /** On the review: coupon email sent (timestamp) and failed attempts. */
    const EMAIL_SENT_META = '_ffla_review_coupon_emailed';

    const EMAIL_ATTEMPTS_META = '_ffla_review_coupon_attempts';

    /** On the order: the coupon its review earned. */
    const ORDER_CODE_META = '_ffla_review_coupon';

    /** On the coupon: the review it was made for. */
    const COUPON_REVIEW_META = '_ffla_review_coupon_review';

    const TIERS = 3;

    /** When coupons were last switched on (Unix time): older reviews earn none. */
    const SINCE_OPTION = 'ffla_review_coupons_since';

    const MAX_EMAIL_ATTEMPTS = 3;

    public static function init(): void
    {
        add_action('comment_post', [__CLASS__, 'on_comment_post'], 30, 2);
        add_action('transition_comment_status', [__CLASS__, 'on_status_transition'], 30, 3);
        add_action(self::ACTION_SEND_EMAIL, [__CLASS__, 'send_email'], 10, 1);
        add_filter('ffla_product_reviews_card_badges', [__CLASS__, 'card_badge'], 10, 2);
        add_action('ffla_product_reviews_settings_saved', [__CLASS__, 'on_settings_saved'], 10, 3);
    }

    /**
     * Remember when coupons were switched on, so a backlog of held reviews
     * approved afterwards (or an old review approved again) earns nothing.
     *
     * @param string|mixed               $tab
     * @param array<string, mixed>|mixed $new
     * @param array<string, mixed>|mixed $old
     */
    public static function on_settings_saved($tab, $new, $old): void
    {
        $now = is_array($new) && '1' === (string) ($new['coupon_enabled'] ?? '');
        $was = is_array($old) && '1' === (string) ($old['coupon_enabled'] ?? '');
        if ($now && !$was) {
            update_option(self::SINCE_OPTION, time(), false);
        }
    }

    public static function enabled(): bool
    {
        return '1' === Product_Reviews_Core::get_setting('coupon_enabled', '0');
    }

    /* ---------------------------------------------------------------------
     * When a review is published
     * ------------------------------------------------------------------- */

    /**
     * @param int|mixed        $comment_id
     * @param int|string|mixed $approved
     */
    public static function on_comment_post($comment_id, $approved): void
    {
        if ('1' === (string) $approved) {
            self::maybe_reward((int) $comment_id);
        }
    }

    /**
     * @param string|mixed $new_status
     * @param string|mixed $old_status
     * @param \WP_Comment|mixed $comment
     */
    public static function on_status_transition($new_status, $old_status, $comment): void
    {
        if ('approved' === $new_status && 'approved' !== $old_status && $comment instanceof \WP_Comment) {
            self::maybe_reward((int) $comment->comment_ID);
        }
    }

    /**
     * Decide once per review; returns the coupon code it earned, or ''.
     */
    public static function maybe_reward(int $review_id): string
    {
        if (!self::enabled()) {
            return '';
        }

        $review = get_comment($review_id);
        if (!$review instanceof \WP_Comment || '1' !== (string) $review->comment_approved
            || 'review' !== $review->comment_type || (int) $review->comment_parent > 0
            || 'product' !== get_post_type((int) $review->comment_post_ID)) {
            return '';
        }

        if (get_comment_meta($review_id, self::REVIEW_CHECKED_META, true)) {
            return (string) get_comment_meta($review_id, self::REVIEW_CODE_META, true);
        }

        $since = (int) get_option(self::SINCE_OPTION, 0);
        if ($since > 0 && strtotime((string) $review->comment_date_gmt . ' UTC') < $since) {
            update_comment_meta($review_id, self::REVIEW_CHECKED_META, 'before_enabled');

            return '';
        }

        $order = self::reward_order($review);
        $email = (string) $review->comment_author_email;

        if (!$order instanceof \WC_Order || !is_email($email) || !self::role_qualifies($review, $order)) {
            update_comment_meta($review_id, self::REVIEW_CHECKED_META, 'not_eligible');

            return '';
        }

        // One coupon per order, however many of its products are reviewed.
        if ($order->get_meta(self::ORDER_CODE_META)) {
            update_comment_meta($review_id, self::REVIEW_CHECKED_META, 'order_rewarded');

            return '';
        }

        $tier = self::tier_for($review_id);
        if (null === $tier || 'none' === $tier['type']) {
            update_comment_meta($review_id, self::REVIEW_CHECKED_META, 'no_tier');

            return '';
        }

        $code = 'existing' === $tier['type']
            ? self::existing_code($tier['existing'])
            : self::create_coupon($tier, $review, $order, $email);

        if ($code === '') {
            update_comment_meta($review_id, self::REVIEW_CHECKED_META, 'coupon_failed');
            self::log(sprintf('Review #%d: no coupon could be made (check the Coupons tab).', $review_id));

            return '';
        }

        // Recorded before the email goes out, so a second approval or a
        // parallel request cannot issue a second coupon for the order.
        $order->update_meta_data(self::ORDER_CODE_META, $code);
        $order->save();
        update_comment_meta($review_id, self::REVIEW_CHECKED_META, 'rewarded');
        update_comment_meta($review_id, self::REVIEW_CODE_META, $code);
        update_comment_meta($review_id, self::INCENTIVIZED_META, 1);

        self::send_email($review_id);

        return $code;
    }

    /**
     * The order behind the review: the signed link's order, or the newest
     * order of the signed-in buyer that still contains the product. Reviews
     * without Verified buyer earn nothing.
     *
     * @return \WC_Order|null
     */
    private static function reward_order(\WP_Comment $review): ?\WC_Order
    {
        $review_id  = (int) $review->comment_ID;
        $product_id = (int) $review->comment_post_ID;

        $order_id = (int) get_comment_meta($review_id, 'ffla_review_order_id', true);
        if ($order_id > 0) {
            $order = wc_get_order($order_id);

            return $order instanceof \WC_Order ? $order : null;
        }

        if ('link' === Product_Reviews_Core::get_setting('coupon_who', 'verified')) {
            return null;
        }

        if (!Product_Reviews_Core::is_verified_purchase($review_id) || (int) $review->user_id <= 0) {
            return null;
        }

        $orders = wc_get_orders([
            'customer_id' => (int) $review->user_id,
            'status'      => ['wc-completed', 'wc-processing'],
            'limit'       => 20,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        foreach ((array) $orders as $order) {
            if ($order instanceof \WC_Order && Product_Reviews_Core::order_contains_reviewable_product($order, $product_id)) {
                return $order;
            }
        }

        return null;
    }

    private static function role_qualifies(\WP_Comment $review, \WC_Order $order): bool
    {
        $mode = (string) Product_Reviews_Core::get_setting('coupon_roles_mode', 'all');
        if ('include' !== $mode && 'exclude' !== $mode) {
            return true;
        }

        $user_id = (int) $review->user_id ?: (int) $order->get_user_id();
        $user    = $user_id > 0 ? get_userdata($user_id) : false;
        $roles   = $user instanceof \WP_User ? (array) $user->roles : ['guest'];
        $match   = !empty(array_intersect($roles, (array) Product_Reviews_Core::get_setting('coupon_roles', [])));

        return 'include' === $mode ? $match : !$match;
    }

    /**
     * The tier for this many photos or videos: the highest tier whose
     * minimum the review reaches. Tier 1 has no minimum.
     *
     * @return array{tier:int,type:string,existing:string,discount_type:string,amount:float}|null
     */
    public static function tier_for(int $review_id): ?array
    {
        $media = get_comment_meta($review_id, 'ffla_review_media_ids', true);
        $count = is_array($media) ? count(array_filter(array_map('absint', $media))) : 0;

        $chosen = null;
        for ($i = 1; $i <= self::TIERS; $i++) {
            $min = 1 === $i ? 0 : absint(Product_Reviews_Core::get_setting('coupon_tier' . $i . '_min_media', (string) ($i === 2 ? 1 : 3)));
            if ($count < $min) {
                continue;
            }
            $type = (string) Product_Reviews_Core::get_setting('coupon_tier' . $i . '_type', 1 === $i ? 'new' : 'off');
            if ('off' === $type) {
                continue;
            }
            $chosen = [
                'tier'          => $i,
                'type'          => $type,
                'existing'      => (string) Product_Reviews_Core::get_setting('coupon_tier' . $i . '_existing', ''),
                'discount_type' => (string) Product_Reviews_Core::get_setting('coupon_tier' . $i . '_discount_type', 'percent'),
                'amount'        => (float) Product_Reviews_Core::get_setting('coupon_tier' . $i . '_amount', '10'),
            ];
        }

        return $chosen;
    }

    private static function existing_code(string $code): string
    {
        $code = trim($code);
        if ($code === '' || !function_exists('wc_get_coupon_id_by_code')) {
            return '';
        }

        return wc_get_coupon_id_by_code($code) > 0 ? $code : '';
    }

    /**
     * @param array<string, mixed> $tier
     */
    private static function create_coupon(array $tier, \WP_Comment $review, \WC_Order $order, string $email): string
    {
        if (!class_exists('WC_Coupon') || (float) $tier['amount'] <= 0) {
            return '';
        }

        $type = in_array($tier['discount_type'], ['percent', 'fixed_cart', 'fixed_product'], true) ? $tier['discount_type'] : 'percent';
        $code = self::unique_code();
        if ($code === '') {
            return '';
        }

        $coupon = new \WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type($type);
        $coupon->set_amount('percent' === $type ? min(100.0, (float) $tier['amount']) : (float) $tier['amount']);
        $coupon->set_description(sprintf(
            /* translators: 1: review ID, 2: order number */
            __('Thank-you coupon for review #%1$d (order #%2$s), made by Product Reviews.', 'ffl-funnels-addons'),
            (int) $review->comment_ID,
            (string) $order->get_order_number()
        ));

        $days = absint(Product_Reviews_Core::get_setting('coupon_expiry_days', '30'));
        if ($days > 0) {
            $coupon->set_date_expires(time() + $days * DAY_IN_SECONDS);
        }

        if ('1' !== Product_Reviews_Core::get_setting('coupon_shareable', '0')) {
            $coupon->set_email_restrictions([strtolower($email)]);
        }

        $coupon->set_usage_limit(max(1, absint(Product_Reviews_Core::get_setting('coupon_usage_limit', '1'))));
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_individual_use('1' === Product_Reviews_Core::get_setting('coupon_individual_use', '1'));
        $coupon->set_exclude_sale_items('1' === Product_Reviews_Core::get_setting('coupon_exclude_sale', '0'));
        $coupon->set_free_shipping('1' === Product_Reviews_Core::get_setting('coupon_free_shipping', '0'));

        $min = (float) Product_Reviews_Core::get_setting('coupon_minimum_spend', '');
        if ($min > 0) {
            $coupon->set_minimum_amount($min);
        }
        $max = (float) Product_Reviews_Core::get_setting('coupon_maximum_spend', '');
        if ($max > 0) {
            $coupon->set_maximum_amount($max);
        }

        $cats = array_values(array_filter(array_map('absint', (array) Product_Reviews_Core::get_setting('coupon_categories', []))));
        if (!empty($cats)) {
            $coupon->set_product_categories($cats);
        }
        $excluded = array_values(array_filter(array_map('absint', (array) Product_Reviews_Core::get_setting('coupon_excluded_categories', []))));
        if (!empty($excluded)) {
            $coupon->set_excluded_product_categories($excluded);
        }

        $coupon->update_meta_data(self::COUPON_REVIEW_META, (int) $review->comment_ID);

        /**
         * The coupon about to be saved for a review.
         *
         * @param \WC_Coupon  $coupon
         * @param \WP_Comment $review
         * @param \WC_Order   $order
         */
        do_action('ffla_product_reviews_before_coupon_save', $coupon, $review, $order);

        $id = $coupon->save();

        return $id > 0 ? $code : '';
    }

    private static function unique_code(): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) Product_Reviews_Core::get_setting('coupon_prefix', 'REVIEW-')));

        for ($i = 0; $i < 5; $i++) {
            $code = $prefix . strtoupper(wp_generate_password(8, false, false));
            if (!function_exists('wc_get_coupon_id_by_code') || 0 === (int) wc_get_coupon_id_by_code($code)) {
                return $code;
            }
        }

        return '';
    }

    /* ---------------------------------------------------------------------
     * Email
     * ------------------------------------------------------------------- */

    /**
     * Send (or retry) the coupon email for a review that earned one. Marked
     * as sent only when wp_mail() succeeds; a failure is retried an hour
     * later, three attempts in all.
     */
    public static function send_email(int $review_id): void
    {
        $review = get_comment($review_id);
        $code   = (string) get_comment_meta($review_id, self::REVIEW_CODE_META, true);
        if (!$review instanceof \WP_Comment || $code === '' || get_comment_meta($review_id, self::EMAIL_SENT_META, true)) {
            return;
        }

        $to = (string) $review->comment_author_email;
        if (!is_email($to)) {
            return;
        }

        $email = self::render_email(self::email_data($review, $code));
        // The store's headers only: the request emails' own Reply-To and BCC
        // are for requests and reminders.
        if (wp_mail($to, $email['subject'], $email['body'], Product_Reviews_Email::mail_headers($email['html'] ? 'text/html' : 'text/plain'))) {
            update_comment_meta($review_id, self::EMAIL_SENT_META, time());

            return;
        }

        $attempts = (int) get_comment_meta($review_id, self::EMAIL_ATTEMPTS_META, true) + 1;
        update_comment_meta($review_id, self::EMAIL_ATTEMPTS_META, $attempts);
        if ($attempts < self::MAX_EMAIL_ATTEMPTS && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + HOUR_IN_SECONDS, self::ACTION_SEND_EMAIL, [$review_id], Product_Reviews_Email::GROUP);
        }
        self::log(sprintf('Review #%d: coupon email could not be sent (attempt %d of %d).', $review_id, $attempts, self::MAX_EMAIL_ATTEMPTS));
    }

    /**
     * @return array<string, mixed>
     */
    public static function email_data(\WP_Comment $review, string $code): array
    {
        $coupon_id = function_exists('wc_get_coupon_id_by_code') ? (int) wc_get_coupon_id_by_code($code) : 0;
        $coupon    = $coupon_id > 0 && class_exists('WC_Coupon') ? new \WC_Coupon($coupon_id) : null;
        $product   = wc_get_product((int) $review->comment_post_ID);
        $name      = trim((string) $review->comment_author);
        $first     = $name !== '' ? (string) (preg_split('/\s+/', $name) ?: [$name])[0] : '';

        return [
            'customer_name' => $first !== '' ? $first : __('Customer', 'ffl-funnels-addons'),
            'coupon_code'   => $code,
            'coupon_amount' => $coupon ? self::amount_text($coupon) : '',
            'coupon_expiry' => $coupon && $coupon->get_date_expires() ? wp_date((string) get_option('date_format'), $coupon->get_date_expires()->getTimestamp()) : '',
            'product_name'  => $product ? $product->get_name() : '',
            'shop_url'      => function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('shop') : home_url('/'),
        ];
    }

    /**
     * "10%" or "$15.00".
     *
     * @param \WC_Coupon $coupon
     */
    private static function amount_text($coupon): string
    {
        $amount = (float) $coupon->get_amount();
        if ('percent' === $coupon->get_discount_type()) {
            return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . '%';
        }

        return function_exists('wc_price') ? wp_strip_all_tags(html_entity_decode(wc_price($amount), ENT_QUOTES, 'UTF-8')) : (string) $amount;
    }

    /**
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private static function placeholders(array $data): array
    {
        return [
            '{customer_name}' => (string) $data['customer_name'],
            '{coupon_code}'   => (string) $data['coupon_code'],
            '{coupon_amount}' => (string) $data['coupon_amount'],
            '{coupon_expiry}' => (string) $data['coupon_expiry'],
            '{product_name}'  => (string) $data['product_name'],
            '{shop_url}'      => (string) $data['shop_url'],
            '{store_name}'    => Product_Reviews_Email_Template::store_name(),
        ];
    }

    public static function default_text(bool $html): string
    {
        return $html
            ? __("Hi {customer_name},\n\nThank you for reviewing {product_name}. As a thank-you, here is {coupon_amount} off your next order.", 'ffl-funnels-addons')
            : __("Hi {customer_name},\n\nThank you for reviewing {product_name}. As a thank-you, here is {coupon_amount} off your next order.\n\nYour code: {coupon_code}\n{coupon_expiry_line}\nShop now: {shop_url}", 'ffl-funnels-addons');
    }

    /**
     * @param array<string, string> $data
     * @return array{subject:string,body:string,html:bool}
     */
    public static function render_email(array $data): array
    {
        $html         = Product_Reviews_Email_Template::is_html();
        $placeholders = self::placeholders($data);

        $subject = trim((string) Product_Reviews_Core::get_setting('coupon_email_subject', ''));
        if ($subject === '') {
            $subject = __('Thanks for your review: a coupon for you', 'ffl-funnels-addons');
        }
        $subject = trim(wp_strip_all_tags(strtr($subject, $placeholders)));

        $heading = trim((string) Product_Reviews_Core::get_setting('coupon_email_heading', ''));
        if ($heading === '') {
            $heading = __('Thank you for your review', 'ffl-funnels-addons');
        }
        $heading = trim(wp_strip_all_tags(strtr($heading, $placeholders)));

        $template = (string) Product_Reviews_Core::get_setting('coupon_email_text', '');
        if (trim(wp_strip_all_tags($template)) === '') {
            $template = self::default_text($html);
        }

        $expiry_line = $data['coupon_expiry'] !== ''
            ? sprintf(
                /* translators: %s: date */
                __('Valid until %s.', 'ffl-funnels-addons'),
                $data['coupon_expiry']
            )
            : '';

        if (!$html) {
            $text = wp_strip_all_tags(str_replace(['<br>', '<br />', '</p>'], ["\n", "\n", "\n\n"], $template));
            $body = strtr($text, $placeholders + ['{coupon_expiry_line}' => $expiry_line]);
            if (strpos($text, '{coupon_code}') === false) {
                $body .= "\n\n" . sprintf(
                    /* translators: %s: coupon code */
                    __('Your code: %s', 'ffl-funnels-addons'),
                    $data['coupon_code']
                ) . ($expiry_line !== '' ? "\n" . $expiry_line : '');
            }

            return ['subject' => $subject, 'body' => trim($heading . "\n\n" . trim($body)), 'html' => false];
        }

        $design  = Product_Reviews_Email_Template::design();
        $escaped = [];
        foreach ($placeholders + ['{coupon_expiry_line}' => $expiry_line] as $key => $value) {
            $escaped[$key] = '{shop_url}' === $key ? esc_url($value) : esc_html($value);
        }

        $middle = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:16px 0 4px;"><tr>'
            . '<td align="center" style="padding:20px;border:2px dashed ' . esc_attr($design['brand']) . ';border-radius:8px;">'
            . '<div style="font-size:13px;letter-spacing:0.08em;text-transform:uppercase;color:#6b7280;">' . esc_html__('Your coupon code', 'ffl-funnels-addons') . '</div>'
            . '<div style="margin-top:6px;font-family:Consolas,Menlo,monospace;font-size:26px;font-weight:700;letter-spacing:0.06em;color:' . esc_attr($design['text']) . ';">' . esc_html($data['coupon_code']) . '</div>'
            . ($data['coupon_amount'] !== '' ? '<div style="margin-top:6px;font-size:15px;">' . esc_html(sprintf(
                /* translators: %s: discount, e.g. 10% or $15.00 */
                __('%s off your next order', 'ffl-funnels-addons'),
                $data['coupon_amount']
            )) . '</div>' : '')
            . ($expiry_line !== '' ? '<div style="margin-top:4px;font-size:13px;color:#6b7280;">' . esc_html($expiry_line) . '</div>' : '')
            . '</td></tr></table>';

        $button = trim((string) Product_Reviews_Core::get_setting('coupon_button_text', ''));

        return [
            'subject' => $subject,
            'body'    => Product_Reviews_Email_Template::wrap([
                'subject'      => $subject,
                'heading'      => $heading,
                'text'         => Product_Reviews_Email_Template::text_html($template, $escaped),
                'middle'       => $middle,
                'button_label' => $button !== '' ? $button : __('Shop now', 'ffl-funnels-addons'),
                'button_url'   => $data['shop_url'],
            ]),
            'html'    => true,
        ];
    }

    /**
     * Test send with a made-up code; nothing is created.
     */
    public static function send_test(string $to): bool
    {
        if (!is_email($to)) {
            return false;
        }

        $data  = [
            'customer_name' => __('Customer', 'ffl-funnels-addons'),
            'coupon_code'   => strtoupper((string) Product_Reviews_Core::get_setting('coupon_prefix', 'REVIEW-')) . 'TEST1234',
            'coupon_amount' => self::tier_preview_amount(),
            'coupon_expiry' => absint(Product_Reviews_Core::get_setting('coupon_expiry_days', '30')) > 0
                ? wp_date((string) get_option('date_format'), time() + absint(Product_Reviews_Core::get_setting('coupon_expiry_days', '30')) * DAY_IN_SECONDS)
                : '',
            'product_name'  => __('the product', 'ffl-funnels-addons'),
            'shop_url'      => function_exists('wc_get_page_permalink') ? (string) wc_get_page_permalink('shop') : home_url('/'),
        ];
        $email = self::render_email($data);

        return (bool) wp_mail($to, '[' . __('Test', 'ffl-funnels-addons') . '] ' . $email['subject'], $email['body'], Product_Reviews_Email::mail_headers($email['html'] ? 'text/html' : 'text/plain'));
    }

    private static function tier_preview_amount(): string
    {
        $type   = (string) Product_Reviews_Core::get_setting('coupon_tier1_discount_type', 'percent');
        $amount = (float) Product_Reviews_Core::get_setting('coupon_tier1_amount', '10');

        if ('percent' === $type) {
            return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . '%';
        }

        return function_exists('wc_price') ? wp_strip_all_tags(html_entity_decode(wc_price($amount), ENT_QUOTES, 'UTF-8')) : (string) $amount;
    }

    /* ---------------------------------------------------------------------
     * Disclosure badge
     * ------------------------------------------------------------------- */

    /**
     * @param string      $html
     * @param \WP_Comment $review
     */
    public static function card_badge($html, $review): string
    {
        $html = (string) $html;
        if (!$review instanceof \WP_Comment || '1' !== Product_Reviews_Core::get_setting('coupon_badge', '1')) {
            return $html;
        }

        if ((int) get_comment_meta((int) $review->comment_ID, self::INCENTIVIZED_META, true) !== 1) {
            return $html;
        }

        $label = trim((string) Product_Reviews_Core::get_setting('coupon_badge_text', ''));
        if ($label === '') {
            $label = __('Received a coupon for this review', 'ffl-funnels-addons');
        }

        return $html . '<span class="ffla-review-card__incentivized">' . esc_html($label) . '</span>';
    }

    private static function log(string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning($message, ['source' => 'ffla-product-reviews']);
        }
    }
}
