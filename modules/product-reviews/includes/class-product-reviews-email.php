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

    public static function init(): void
    {
        add_action('woocommerce_order_status_completed', [__CLASS__, 'schedule_requests_for_order'], 20, 1);
        add_action(self::ACTION_SEND_REVIEW_REQUEST, [__CLASS__, 'send_review_request'], 10, 3);
        add_action(self::ACTION_SEND_ORDER_REVIEW_BUNDLE, [__CLASS__, 'send_order_review_bundle'], 10, 1);
    }

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

        $user_id = (int) $order->get_user_id();
        $delay_days = max(0, (int) Product_Reviews_Core::get_setting('request_delay_days', '7'));
        $timestamp  = time() + ($delay_days * DAY_IN_SECONDS);
        $mode       = Product_Reviews_Core::get_setting('request_email_mode', 'per_product');

        if ($mode === 'bundle') {
            $args = [$order_id];
            if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
                $scheduled = as_next_scheduled_action(self::ACTION_SEND_ORDER_REVIEW_BUNDLE, $args, 'ffla-product-reviews');
                if (!$scheduled) {
                    as_schedule_single_action($timestamp, self::ACTION_SEND_ORDER_REVIEW_BUNDLE, $args, 'ffla-product-reviews');
                }

                return;
            }

            $hook = self::ACTION_SEND_ORDER_REVIEW_BUNDLE;
            if (!wp_next_scheduled($hook, $args)) {
                wp_schedule_single_event($timestamp, $hook, $args);
            }

            return;
        }

        foreach ($order->get_items() as $item) {
            $product_id = (int) $item->get_product_id();
            if ($product_id <= 0) {
                continue;
            }

            $args = [$order_id, $product_id, $user_id];

            if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
                $scheduled = as_next_scheduled_action(self::ACTION_SEND_REVIEW_REQUEST, $args, 'ffla-product-reviews');
                if (!$scheduled) {
                    as_schedule_single_action($timestamp, self::ACTION_SEND_REVIEW_REQUEST, $args, 'ffla-product-reviews');
                }
                continue;
            }

            $hook = self::ACTION_SEND_REVIEW_REQUEST;
            if (!wp_next_scheduled($hook, $args)) {
                wp_schedule_single_event($timestamp, $hook, $args);
            }
        }
    }

    public static function send_order_review_bundle(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $email = $order->get_billing_email();
        if (!is_email($email)) {
            return;
        }

        $settings = Product_Reviews_Core::get_settings();

        $subject = sanitize_text_field($settings['email_subject'] ?? '');
        if ($subject === '') {
            $subject = __('How was your purchase?', 'ffl-funnels-addons');
        }

        $heading = sanitize_text_field($settings['email_heading'] ?? '');
        $template = (string) ($settings['email_template'] ?? '');
        if ($template === '') {
            $template = __("Hi {customer_name},\n\nWe would love your feedback on:\n{product_names_list}\n\nLeave your reviews here:\n{review_order_url}\n\nThank you!", 'ffl-funnels-addons');
        }

        $customer_name = trim((string) $order->get_billing_first_name());
        if ($customer_name === '') {
            $customer_name = __('Customer', 'ffl-funnels-addons');
        }

        $token = Product_Reviews_Core::build_order_review_token($order_id, $email);
        $review_order_url = Product_Reviews_Core::order_review_landing_url($token);

        $names = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $pid = Product_Reviews_Core::line_item_parent_product_id($item);
            if ($pid <= 0) {
                continue;
            }
            $p = wc_get_product($pid);
            $names[] = $p ? $p->get_name() : (string) $item->get_name();
        }
        $names = array_values(array_unique(array_filter($names)));
        $product_names_list = implode(', ', $names);

        $replacements = [
            '{customer_name}'     => $customer_name,
            '{product_name}'      => $product_names_list,
            '{product_names_list}' => $product_names_list,
            '{review_url}'        => esc_url_raw($review_order_url),
            '{review_order_url}'  => esc_url_raw($review_order_url),
            '{order_id}'          => (string) $order_id,
            '{user_id}'           => (string) (int) $order->get_user_id(),
        ];

        $body = strtr($template, $replacements);
        $body = trim($heading . "\n\n" . $body);

        wp_mail($email, $subject, $body);
    }

    public static function send_review_request(int $order_id, int $product_id, int $user_id): void
    {
        $order = wc_get_order($order_id);
        $product = wc_get_product($product_id);
        if (!$order || !$product) {
            return;
        }

        $email = $order->get_billing_email();
        if (!is_email($email)) {
            return;
        }

        $settings = Product_Reviews_Core::get_settings();

        $subject = sanitize_text_field($settings['email_subject'] ?? '');
        if ($subject === '') {
            $subject = __('How was your purchase?', 'ffl-funnels-addons');
        }

        $heading = sanitize_text_field($settings['email_heading'] ?? '');
        $template = (string) ($settings['email_template'] ?? '');
        if ($template === '') {
            $template = __("Hi {customer_name},\n\nWe would love your feedback on {product_name}.\n\nLeave your review here:\n{review_url}\n\nThank you!", 'ffl-funnels-addons');
        }

        $token = Product_Reviews_Core::build_order_review_token($order_id, $email);
        $review_url = Product_Reviews_Core::order_review_landing_url($token);
        $customer_name = trim((string) $order->get_billing_first_name());
        if ($customer_name === '') {
            $customer_name = __('Customer', 'ffl-funnels-addons');
        }

        $replacements = [
            '{customer_name}'      => $customer_name,
            '{product_name}'       => $product->get_name(),
            '{product_names_list}' => $product->get_name(),
            '{review_url}'         => esc_url_raw($review_url),
            '{review_order_url}'   => esc_url_raw($review_url),
            '{order_id}'           => (string) $order_id,
            '{user_id}'            => (string) $user_id,
        ];

        $body = strtr($template, $replacements);
        $body = trim($heading . "\n\n" . $body);

        wp_mail($email, $subject, $body);
    }
}
