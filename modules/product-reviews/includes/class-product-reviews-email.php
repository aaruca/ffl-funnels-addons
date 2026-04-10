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

    public static function init(): void
    {
        add_action('woocommerce_order_status_completed', [__CLASS__, 'schedule_requests_for_order'], 20, 1);
        add_action(self::ACTION_SEND_REVIEW_REQUEST, [__CLASS__, 'send_review_request'], 10, 3);
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

        $review_url = add_query_arg(['review_order' => $order_id], get_permalink($product_id) . '#reviews');
        $customer_name = trim((string) $order->get_billing_first_name());
        if ($customer_name === '') {
            $customer_name = __('Customer', 'ffl-funnels-addons');
        }

        $replacements = [
            '{customer_name}' => $customer_name,
            '{product_name}'  => $product->get_name(),
            '{review_url}'    => esc_url_raw($review_url),
            '{order_id}'      => (string) $order_id,
            '{user_id}'       => (string) $user_id,
        ];

        $body = strtr($template, $replacements);
        $body = trim($heading . "\n\n" . $body);

        wp_mail($email, $subject, $body);
    }
}
