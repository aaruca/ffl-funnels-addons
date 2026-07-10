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
        if ('1' !== Product_Reviews_Core::get_setting('enable_requests', '1')) {
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
        $mode       = Product_Reviews_Core::get_setting('request_email_mode', 'per_product');

        // Nothing to ask about if every product in the order is already
        // reviewed by this customer.
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
     * Parent product IDs in the order the customer has not reviewed yet.
     *
     * @return array<int, int>
     */
    private static function pending_product_ids(\WC_Order $order, string $email): array
    {
        $email = strtolower(trim($email));
        $ids   = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product_id = Product_Reviews_Core::line_item_parent_product_id($item);
            if ($product_id <= 0 || isset($ids[$product_id])) {
                continue;
            }

            if (Product_Reviews_Core::customer_has_review_for_product($email, $product_id)) {
                continue;
            }

            $ids[$product_id] = $product_id;
        }

        return array_values($ids);
    }

    /**
     * Re-checked at send time, not just at schedule time: the delay is days
     * long, and the customer may have reviewed, refunded, or unsubscribed in
     * the meantime. Returns the billing email, or '' when the send must abort.
     *
     * Deliberately untyped: wc_get_order() returns WC_Order|false, and `false`
     * does not satisfy a nullable class type hint.
     *
     * @param \WC_Order|false|null $order
     */
    private static function order_still_eligible($order): string
    {
        if (!$order instanceof \WC_Order) {
            return '';
        }

        if (!$order->has_status('completed')) {
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

        self::send(
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

        // The customer may have reviewed this product during the delay window.
        if (Product_Reviews_Core::customer_has_review_for_product(strtolower(trim($email)), $product_id)) {
            return;
        }

        $token = Product_Reviews_Core::build_order_review_token($order_id, $email);
        $url   = Product_Reviews_Core::order_review_landing_url($token);

        self::send(
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

        $order->update_meta_data($sent_meta, time());
        $order->save();
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    private static function customer_name(\WC_Order $order): string
    {
        $name = trim((string) $order->get_billing_first_name());

        return $name !== '' ? $name : __('Customer', 'ffl-funnels-addons');
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
     * @param array<string, string> $replacements
     */
    private static function send(string $email, string $default_template, array $replacements): void
    {
        $settings = Product_Reviews_Core::get_settings();

        $subject = sanitize_text_field($settings['email_subject'] ?? '');
        if ($subject === '') {
            $subject = __('How was your purchase?', 'ffl-funnels-addons');
        }

        $heading  = sanitize_text_field($settings['email_heading'] ?? '');
        $template = (string) ($settings['email_template'] ?? '');
        if ($template === '') {
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

        wp_mail($email, $subject, $body);
    }
}
