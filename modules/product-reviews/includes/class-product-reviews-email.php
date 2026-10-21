<?php
/**
 * Product Reviews email requests.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Email
{
    const ACTION_SEND_REVIEW_REQUEST = 'ffla_send_product_review_request';

    const ACTION_SEND_ORDER_REVIEW_BUNDLE = 'ffla_send_order_review_bundle';

    const GROUP = 'ffla-product-reviews';

    /** Set once per order so a re-entered "completed" status cannot double-send. */
    const SCHEDULED_META = '_ffla_review_request_scheduled';

    const BUNDLE_SENT_META = '_ffla_review_bundle_sent';

    /** Suffixed with the product ID in per-product mode. */
    const PRODUCT_SENT_META_PREFIX = '_ffla_review_sent_';

    /** Failed wp_mail() calls so far, for the bundle email or one product. */
    const BUNDLE_ATTEMPTS_META = '_ffla_review_bundle_attempts';

    const PRODUCT_ATTEMPTS_META_PREFIX = '_ffla_review_attempts_';

    const MAX_SEND_ATTEMPTS = 3;

    /** Added to the request delay to get the oldest order still mailed. */
    const ORDER_AGE_GRACE_DAYS = 30;

    public static function init(): void
    {
        add_action('woocommerce_order_status_completed', [__CLASS__, 'schedule_requests_for_order'], 20, 1);

        // A customer who sent the goods back should not be asked how they liked
        // them. Both statuses drop any request still sitting in the queue.
        add_action('woocommerce_order_status_refunded', [__CLASS__, 'cancel_requests_for_order'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'cancel_requests_for_order'], 10, 1);

        add_action(self::ACTION_SEND_REVIEW_REQUEST, [__CLASS__, 'send_review_request'], 10, 3);
        add_action(self::ACTION_SEND_ORDER_REVIEW_BUNDLE, [__CLASS__, 'send_order_review_bundle'], 10, 1);
    }

    /* ---------------------------------------------------------------------
     * Scheduling
     * ------------------------------------------------------------------- */

    public static function schedule_requests_for_order(int $order_id): void
    {
        // Off, or no published hub page for the link to open.
        if (!Product_Reviews_Core::requests_ready()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $email = $order->get_billing_email();
        if (!is_email($email)) {
            return;
        }

        if (Product_Reviews_Notifications::is_opted_out($email)) {
            return;
        }

        if ($order->get_meta(self::SCHEDULED_META)) {
            return;
        }

        $delay_days = max(0, (int) Product_Reviews_Core::get_setting('request_delay_days', '7'));
        $timestamp  = time() + ($delay_days * DAY_IN_SECONDS);
        $mode       = Product_Reviews_Core::get_setting('request_email_mode', 'bundle');

        if (self::order_too_old($order, $timestamp)) {
            return;
        }

        // Nothing to ask about if every product in the order is already
        // reviewed by this customer, refunded, or closed to reviews.
        $pending = self::pending_product_ids($order, $email);
        if (empty($pending)) {
            return;
        }

        if ('bundle' === $mode) {
            if ($order->get_meta(self::BUNDLE_SENT_META)) {
                return;
            }
            self::schedule_action($timestamp, self::ACTION_SEND_ORDER_REVIEW_BUNDLE, [$order_id]);
        } else {
            $user_id = (int) $order->get_user_id();
            foreach ($pending as $product_id) {
                if ($order->get_meta(self::PRODUCT_SENT_META_PREFIX . $product_id)) {
                    continue;
                }
                self::schedule_action($timestamp, self::ACTION_SEND_REVIEW_REQUEST, [$order_id, $product_id, $user_id]);
            }
        }

        $order->update_meta_data(self::SCHEDULED_META, 1);
        $order->save();
    }

    public static function cancel_requests_for_order(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $user_id = (int) $order->get_user_id();

        self::unschedule_action(self::ACTION_SEND_ORDER_REVIEW_BUNDLE, [$order_id]);

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product_id = Product_Reviews_Core::line_item_parent_product_id($item);
            if ($product_id > 0) {
                self::unschedule_action(self::ACTION_SEND_REVIEW_REQUEST, [$order_id, $product_id, $user_id]);
            }
        }

        // Cleared, not preserved: if the order is completed again later the
        // request should be scheduled again. The per-email "sent" flags stay,
        // so nothing already delivered is repeated.
        $order->delete_meta_data(self::SCHEDULED_META);
        $order->save();
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function schedule_action(int $timestamp, string $hook, array $args): void
    {
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action($hook, $args, self::GROUP)) {
                as_schedule_single_action($timestamp, $hook, $args, self::GROUP);
            }

            return;
        }

        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event($timestamp, $hook, $args);
        }
    }

    /**
     * Queue the action that is running right now once more. Action
     * Scheduler's duplicate check counts the running action, so
     * schedule_action() would skip this.
     *
     * @param array<int, mixed> $args
     */
    private static function schedule_retry(int $timestamp, string $hook, array $args): void
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, $hook, $args, self::GROUP);

            return;
        }

        wp_schedule_single_event($timestamp, $hook, $args);
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function unschedule_action(string $hook, array $args): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, $args, self::GROUP);
        }

        wp_clear_scheduled_hook($hook, $args);
    }

    /* ---------------------------------------------------------------------
     * Eligibility
     * ------------------------------------------------------------------- */

    /**
     * Parent product IDs in the order the customer can still review and has
     * not reviewed yet.
     *
     * @return array<int, int>
     */
    private static function pending_product_ids(\WC_Order $order, string $email): array
    {
        $user_id = (int) $order->get_user_id();
        $ids     = [];

        foreach (Product_Reviews_Core::reviewable_product_ids($order) as $product_id) {
            if (!Product_Reviews_Core::customer_has_review_for_product($email, $product_id, $user_id)) {
                $ids[] = $product_id;
            }
        }

        return $ids;
    }

    /**
     * Completed, or a status the store moves orders to after completing them
     * (a custom "Delivered", for example). A core status that comes before
     * completion or ends the order never qualifies.
     */
    private static function order_status_allows_request(\WC_Order $order): bool
    {
        if ($order->has_status('completed')) {
            $allowed = true;
        } elseif ($order->has_status(['pending', 'processing', 'on-hold', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash'])) {
            $allowed = false;
        } else {
            $allowed = null !== $order->get_date_completed();
        }

        return (bool) apply_filters('ffla_product_reviews_request_status_allowed', $allowed, $order);
    }

    /**
     * An order created more than delay + 30 days before its email would go
     * out is history rather than a recent purchase: imported orders, old
     * orders completed in bulk, and emails left in a stalled queue are not
     * sent.
     */
    private static function order_too_old(\WC_Order $order, int $send_at): bool
    {
        $created = $order->get_date_created();
        if (!$created) {
            return false;
        }

        $delay_days = max(0, (int) Product_Reviews_Core::get_setting('request_delay_days', '7'));
        $max_days   = (int) apply_filters(
            'ffla_product_reviews_request_max_order_age_days',
            $delay_days + self::ORDER_AGE_GRACE_DAYS,
            $order
        );

        return $max_days > 0 && ($send_at - $created->getTimestamp()) > $max_days * DAY_IN_SECONDS;
    }

    /**
     * Re-checked at send time, not just at schedule time: the delay is days
     * long, and in the meantime requests may have been switched off, or the
     * customer may have reviewed, refunded, or unsubscribed. Returns the
     * billing email, or '' when the send must abort.
     *
     * Deliberately untyped: wc_get_order() returns WC_Order|false, and `false`
     * does not satisfy a nullable class type hint.
     *
     * @param \WC_Order|false|null $order
     */
    private static function order_still_eligible($order): string
    {
        if (!Product_Reviews_Core::requests_ready()) {
            return '';
        }

        if (!$order instanceof \WC_Order) {
            return '';
        }

        if (!self::order_status_allows_request($order)) {
            // Completed → Processing → Completed again: the second completion
            // must be able to schedule the request again, or it is never sent.
            self::release_order($order);

            return '';
        }

        if (self::order_too_old($order, time())) {
            return '';
        }

        $email = (string) $order->get_billing_email();
        if (!is_email($email)) {
            return '';
        }

        if (Product_Reviews_Notifications::is_opted_out($email)) {
            return '';
        }

        return $email;
    }

    private static function release_order(\WC_Order $order): void
    {
        if (!$order->get_meta(self::SCHEDULED_META)) {
            return;
        }

        $order->delete_meta_data(self::SCHEDULED_META);
        $order->save();
    }

    /* ---------------------------------------------------------------------
     * Sending
     * ------------------------------------------------------------------- */

    public static function send_order_review_bundle(int $order_id): void
    {
        $order = wc_get_order($order_id);
        $email = self::order_still_eligible($order);
        if ($email === '') {
            return;
        }

        if ($order->get_meta(self::BUNDLE_SENT_META)) {
            return;
        }

        $pending = self::pending_product_ids($order, $email);
        if (empty($pending)) {
            return;
        }

        $names = [];
        foreach ($pending as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $names[] = $product->get_name();
            }
        }
        $product_names_list = implode(', ', array_filter($names));

        $token = Product_Reviews_Core::build_order_review_token($order_id, $email);
        $url   = Product_Reviews_Core::order_review_landing_url($token);

        $sent = self::send(
            $email,
            self::default_bundle_template(),
            [
                '{customer_name}'      => self::customer_name($order),
                '{product_name}'       => $product_names_list,
                '{product_names_list}' => $product_names_list,
                '{review_url}'         => esc_url_raw($url),
                '{review_order_url}'   => esc_url_raw($url),
                '{order_id}'           => (string) $order_id,
                '{user_id}'            => (string) (int) $order->get_user_id(),
            ]
        );

        if (!$sent) {
            self::handle_send_failure($order, self::ACTION_SEND_ORDER_REVIEW_BUNDLE, [$order_id], self::BUNDLE_ATTEMPTS_META);

            return;
        }

        $order->update_meta_data(self::BUNDLE_SENT_META, time());
        $order->save();
    }

    public static function send_review_request(int $order_id, int $product_id, int $user_id): void
    {
        $order = wc_get_order($order_id);
        $email = self::order_still_eligible($order);
        if ($email === '') {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $sent_meta = self::PRODUCT_SENT_META_PREFIX . $product_id;
        if ($order->get_meta($sent_meta)) {
            return;
        }

        // Refunded in full or closed to reviews since the email was queued.
        if (!in_array($product_id, Product_Reviews_Core::reviewable_product_ids($order), true)) {
            return;
        }

        // The customer may have reviewed this product during the delay window.
        if (Product_Reviews_Core::customer_has_review_for_product($email, $product_id, (int) $order->get_user_id())) {
            return;
        }

        $token = Product_Reviews_Core::build_order_review_token($order_id, $email);
        $url   = Product_Reviews_Core::order_review_landing_url($token);

        $sent = self::send(
            $email,
            self::default_single_template(),
            [
                '{customer_name}'      => self::customer_name($order),
                '{product_name}'       => $product->get_name(),
                '{product_names_list}' => $product->get_name(),
                '{review_url}'         => esc_url_raw($url),
                '{review_order_url}'   => esc_url_raw($url),
                '{order_id}'           => (string) $order_id,
                '{user_id}'            => (string) $user_id,
            ]
        );

        if (!$sent) {
            self::handle_send_failure(
                $order,
                self::ACTION_SEND_REVIEW_REQUEST,
                [$order_id, $product_id, $user_id],
                self::PRODUCT_ATTEMPTS_META_PREFIX . $product_id
            );

            return;
        }

        $order->update_meta_data($sent_meta, time());
        $order->save();
    }

    /**
     * wp_mail() reported a failure. Nothing is marked as sent; the action is
     * queued again an hour later, three attempts in all, and every failure is
     * logged (WooCommerce → Status → Logs, source ffla-product-reviews).
     *
     * @param array<int, mixed> $args
     */
    private static function handle_send_failure(\WC_Order $order, string $hook, array $args, string $attempts_meta): void
    {
        $attempts = (int) $order->get_meta($attempts_meta) + 1;
        $order->update_meta_data($attempts_meta, $attempts);
        $order->save();

        $retry = $attempts < self::MAX_SEND_ATTEMPTS;
        if ($retry) {
            self::schedule_retry(time() + HOUR_IN_SECONDS, $hook, $args);
        }

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning(
                sprintf(
                    'Order #%d: review request email could not be sent (attempt %d of %d). %s',
                    $order->get_id(),
                    $attempts,
                    self::MAX_SEND_ATTEMPTS,
                    $retry ? 'Trying again in one hour.' : 'Giving up; check that the site can send email.'
                ),
                ['source' => 'ffla-product-reviews']
            );
        }
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    private static function customer_name(\WC_Order $order): string
    {
        $name = trim((string) $order->get_billing_first_name());

        return $name !== '' ? $name : __('Customer', 'ffl-funnels-addons');
    }

    private static function normalize_template(string $template): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace("\r", '', $template)));
    }

    private static function default_bundle_template(): string
    {
        return __("Hi {customer_name},\n\nWe would love your feedback on:\n{product_names_list}\n\nLeave your reviews here:\n{review_order_url}\n\nThank you!", 'ffl-funnels-addons');
    }

    private static function default_single_template(): string
    {
        return __("Hi {customer_name},\n\nWe would love your feedback on {product_name}.\n\nLeave your review here:\n{review_url}\n\nThank you!", 'ffl-funnels-addons');
    }

    /**
     * From and Reply-To as set in WooCommerce → Settings → Emails, so review
     * emails come from the store like its order emails, not from WordPress's
     * default wordpress@ address. Reply-To is WooCommerce's reply-to address
     * when that setting is on, otherwise the From address.
     *
     * @return array<int, string>
     */
    public static function mail_headers(): array
    {
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        $from_address = sanitize_email((string) get_option('woocommerce_email_from_address', ''));
        $from_name    = self::header_name((string) get_option('woocommerce_email_from_name', ''));
        if (is_email($from_address)) {
            $headers[] = 'From: ' . self::format_address($from_name, $from_address);
        }

        $reply_address = $from_address;
        $reply_name    = $from_name;
        if ('yes' === get_option('woocommerce_email_reply_to_enabled', 'no')) {
            $candidate = sanitize_email((string) get_option('woocommerce_email_reply_to_address', ''));
            if (is_email($candidate)) {
                $reply_address = $candidate;
                $reply_name    = self::header_name((string) get_option('woocommerce_email_reply_to_name', ''));
            }
        }

        if (is_email($reply_address)) {
            $headers[] = 'Reply-To: ' . self::format_address($reply_name, $reply_address);
        }

        return $headers;
    }

    /**
     * A display name safe inside a header: no line breaks (header injection),
     * and no quotes, brackets or commas, which wp_mail() would read as address
     * syntax.
     */
    private static function header_name(string $name): string
    {
        return trim(str_replace(["\r", "\n", '"', '<', '>', ','], '', wp_specialchars_decode($name, ENT_QUOTES)));
    }

    private static function format_address(string $name, string $address): string
    {
        return $name !== '' ? $name . ' <' . $address . '>' : $address;
    }

    /**
     * @param array<string, string> $replacements
     * @return bool What wp_mail() returned.
     */
    private static function send(string $email, string $default_template, array $replacements): bool
    {
        $settings = Product_Reviews_Core::get_settings();

        $subject = sanitize_text_field($settings['email_subject'] ?? '');
        if ($subject === '') {
            $subject = __('How was your purchase?', 'ffl-funnels-addons');
        }

        $heading  = sanitize_text_field($settings['email_heading'] ?? '');
        $template = (string) ($settings['email_template'] ?? '');

        // An empty template, or the untouched per-product default while one
        // email per order is sent, uses the built-in text for this kind of
        // email (otherwise a bundle email would say "your feedback on" one
        // product name list with a per-product wording).
        $stock_single = Product_Reviews_Core::get_default_settings()['email_template'];
        if ($template === '' || (self::normalize_template($template) === self::normalize_template($stock_single) && $default_template !== $stock_single)) {
            $template = $default_template;
        }

        $unsubscribe_url = Product_Reviews_Notifications::unsubscribe_url($email);
        $replacements['{unsubscribe_url}'] = esc_url_raw($unsubscribe_url);

        $body = strtr($template, $replacements);
        $body = trim($heading . "\n\n" . $body);

        // Every review request must carry a way out. If the shop placed the
        // placeholder themselves, respect where they put it.
        if (strpos($template, '{unsubscribe_url}') === false) {
            $body .= Product_Reviews_Notifications::unsubscribe_footer($email);
        }

        return (bool) wp_mail($email, $subject, $body, self::mail_headers());
    }
}
