<?php
/**
 * Review requests on the order screens: a "Review request" box on the order
 * (status, send now, copy the review link, QR code, WhatsApp, cancel), the
 * "Send review request" bulk action on the orders list, and the test email
 * and preview on the settings page.
 *
 * Works with both order storages: the posts screen (`shop_order`) and
 * WooCommerce's own orders screen (HPOS, `woocommerce_page_wc-orders`).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Order_Tools
{
    const SEND_ACTION = 'ffla_review_send_now';

    const CANCEL_ACTION = 'ffla_review_cancel';

    const BULK_ACTION = 'ffla_send_review_request';

    const NOTICE_TRANSIENT = 'ffla_review_order_notice_';

    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'register_meta_box'], 30);
        add_action('admin_post_' . self::SEND_ACTION, [$this, 'handle_send_now']);
        add_action('admin_post_' . self::CANCEL_ACTION, [$this, 'handle_cancel']);
        add_action('admin_notices', [$this, 'render_notice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_order_assets']);

        add_filter('bulk_actions-edit-shop_order', [$this, 'add_bulk_action']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'add_bulk_action']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_bulk_action'], 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle_bulk_action'], 10, 3);

        add_action('wp_ajax_ffla_review_send_test', [$this, 'ajax_send_test']);
        add_action('admin_post_ffla_review_email_preview', [$this, 'handle_preview']);
    }

    private static function can_manage(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('edit_shop_orders');
    }

    /* ---------------------------------------------------------------------
     * Order screen box
     * ------------------------------------------------------------------- */

    /**
     * @return array<int, string>
     */
    private static function order_screens(): array
    {
        $screens = ['shop_order'];
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        return array_values(array_unique(array_filter($screens)));
    }

    public function register_meta_box(): void
    {
        foreach (self::order_screens() as $screen) {
            add_meta_box(
                'ffla-review-request',
                __('Review request', 'ffl-funnels-addons'),
                [$this, 'render_meta_box'],
                $screen,
                'side',
                'default'
            );
        }
    }

    /**
     * @param \WP_Post|\WC_Order|mixed $post_or_order
     */
    public function render_meta_box($post_or_order): void
    {
        $order = $post_or_order instanceof \WC_Order ? $post_or_order : wc_get_order($post_or_order instanceof \WP_Post ? $post_or_order->ID : 0);
        if (!$order instanceof \WC_Order) {
            return;
        }

        echo self::meta_box_html($order); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
    }

    public static function meta_box_html(\WC_Order $order): string
    {
        $status   = Product_Reviews_Email::request_status($order);
        $order_id = (int) $order->get_id();
        $date     = static function (int $ts): string {
            return $ts > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : '';
        };

        $html  = '<div class="ffla-review-request-box">';
        $html .= '<p><strong>' . esc_html__('Status:', 'ffl-funnels-addons') . '</strong> ' . esc_html($status['label']) . '</p>';
        $html .= '<ul class="ffla-review-request-box__facts">';

        $facts = [];
        if ($status['scheduled_for'] > 0 && 'scheduled' === $status['key']) {
            $facts[] = [__('Scheduled for', 'ffl-funnels-addons'), $date($status['scheduled_for'])];
        }
        if ($status['sent'] > 0) {
            $facts[] = [__('Sent', 'ffl-funnels-addons'), $date($status['sent'])];
        }
        if ($status['opened'] > 0) {
            $facts[] = [__('Opened', 'ffl-funnels-addons'), $date($status['opened'])];
        }
        if ($status['clicked'] > 0) {
            $facts[] = [__('Review page opened', 'ffl-funnels-addons'), $date($status['clicked'])];
        }
        if ($status['reviewed'] > 0) {
            $facts[] = [__('Reviews through the link', 'ffl-funnels-addons'), number_format_i18n($status['reviewed'])];
        }
        if ($status['reminder'] > 0) {
            $facts[] = [__('Reminder sent', 'ffl-funnels-addons'), $date($status['reminder'])];
        }
        $coupon = (string) $order->get_meta('_ffla_review_coupon');
        if ($coupon !== '') {
            $facts[] = [__('Coupon for a review', 'ffl-funnels-addons'), $coupon];
        }
        if ($status['failed'] > 0 && $status['sent'] <= 0) {
            $facts[] = [__('Failed attempts', 'ffl-funnels-addons'), number_format_i18n($status['failed'])];
        }
        foreach ($facts as $fact) {
            $html .= '<li>' . esc_html($fact[0]) . ': ' . esc_html($fact[1]) . '</li>';
        }
        $html .= '</ul>';

        if (!Product_Reviews_Core::hub_page_ready()) {
            $html .= '<p class="description">' . esc_html__('Select a published Hub page under Product Reviews → Review requests to send requests.', 'ffl-funnels-addons') . '</p></div>';

            return $html;
        }

        if ($order->has_status(Product_Reviews_Core::TOKEN_DEAD_STATUSES)) {
            $html .= '<p class="description">' . esc_html__('Refunded, cancelled and failed orders get no review request.', 'ffl-funnels-addons') . '</p></div>';

            return $html;
        }

        $send_url = wp_nonce_url(
            add_query_arg(['action' => self::SEND_ACTION, 'order_id' => $order_id], admin_url('admin-post.php')),
            self::SEND_ACTION . '_' . $order_id
        );
        $html .= '<p><a class="button button-primary" href="' . esc_url($send_url) . '">'
            . esc_html($status['sent'] > 0 ? __('Send again now', 'ffl-funnels-addons') : __('Send review request now', 'ffl-funnels-addons'))
            . '</a></p>';

        if ('scheduled' === $status['key']) {
            $cancel_url = wp_nonce_url(
                add_query_arg(['action' => self::CANCEL_ACTION, 'order_id' => $order_id], admin_url('admin-post.php')),
                self::CANCEL_ACTION . '_' . $order_id
            );
            $html .= '<p><a href="' . esc_url($cancel_url) . '">' . esc_html__('Cancel the scheduled email', 'ffl-funnels-addons') . '</a></p>';
        }

        $link = self::review_link($order);
        $html .= '<div class="ffla-review-request-box__share" data-ffla-review-link="' . esc_attr($link) . '">';
        $html .= '<button type="button" class="button" data-ffla-copy-link>' . esc_html__('Copy review link', 'ffl-funnels-addons') . '</button> ';
        $html .= '<button type="button" class="button" data-ffla-qr-show>' . esc_html__('QR code', 'ffl-funnels-addons') . '</button>';

        $whatsapp = self::whatsapp_url($order, $link);
        if ($whatsapp !== '') {
            $html .= ' <a class="button" href="' . esc_url($whatsapp) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('WhatsApp', 'ffl-funnels-addons') . '</a>';
        }

        $html .= '<span class="ffla-review-request-box__copied" data-ffla-copied aria-live="polite"></span>';
        $html .= '<div class="ffla-review-request-box__qr" data-ffla-qr hidden>';
        $html .= '<div data-ffla-qr-image></div>';
        $html .= '<p><button type="button" class="button-link" data-ffla-qr-download>' . esc_html__('Download', 'ffl-funnels-addons') . '</button> · '
            . '<button type="button" class="button-link" data-ffla-qr-print>' . esc_html__('Print', 'ffl-funnels-addons') . '</button></p>';
        $html .= '</div>';
        $html .= '<p class="description">' . esc_html(sprintf(
            /* translators: %d: number of days */
            __('The link opens this order’s review page for %d days. Share it only with this customer.', 'ffl-funnels-addons'),
            Product_Reviews_Core::review_link_days()
        )) . '</p>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * A fresh signed link to this order's review page.
     */
    public static function review_link(\WC_Order $order): string
    {
        $token = Product_Reviews_Core::build_order_review_token((int) $order->get_id(), (string) $order->get_billing_email());

        return Product_Reviews_Core::order_review_landing_url($token);
    }

    /**
     * Click-to-chat link with the store's message filled in, for the
     * billing phone. Numbers without a country code get the billing
     * country's calling code.
     */
    public static function whatsapp_url(\WC_Order $order, string $link): string
    {
        $phone = self::whatsapp_phone($order);
        if ($phone === '') {
            return '';
        }

        $template = (string) Product_Reviews_Core::get_setting('whatsapp_template', '');
        if (trim($template) === '') {
            $template = (string) (Product_Reviews_Core::get_default_settings()['whatsapp_template'] ?? '');
        }

        $name    = trim((string) $order->get_billing_first_name());
        $message = strtr($template, [
            '{customer_name}' => $name !== '' ? $name : __('there', 'ffl-funnels-addons'),
            '{store_name}'    => Product_Reviews_Email_Template::store_name(),
            '{order_number}'  => (string) $order->get_order_number(),
            '{review_url}'    => $link,
        ]);

        return 'https://wa.me/' . $phone . '?text=' . rawurlencode(wp_strip_all_tags($message));
    }

    public static function whatsapp_phone(\WC_Order $order): string
    {
        $raw = trim((string) $order->get_billing_phone());
        if ($raw === '') {
            return '';
        }

        $international = strpos($raw, '+') === 0 || strpos($raw, '00') === 0;
        $digits        = (string) preg_replace('/\D+/', '', $raw);
        if (strpos($raw, '00') === 0) {
            $digits = substr($digits, 2);
        }

        if (!$international) {
            $code = '';
            if (function_exists('WC') && WC()->countries && method_exists(WC()->countries, 'get_country_calling_code')) {
                $code = WC()->countries->get_country_calling_code((string) $order->get_billing_country());
                $code = is_array($code) ? (string) reset($code) : (string) $code;
            }
            $code = (string) preg_replace('/\D+/', '', $code);
            // Without + or 00 the number is national, unless it already
            // starts with the calling code and is as long as an international
            // number (11+ digits: 1 555 123 4567, 44 7700 900123). A trunk 0
            // always means national. An Indian 91234 56789 is national.
            $has_code = $code !== '' && strpos($digits, '0') !== 0 && strpos($digits, $code) === 0 && strlen($digits) >= 11;
            if ($code !== '' && !$has_code) {
                $digits = $code . ltrim($digits, '0');
            }
        }

        $digits = (string) apply_filters('ffla_product_reviews_whatsapp_phone', $digits, $order);

        return strlen($digits) >= 8 ? $digits : '';
    }

    public function enqueue_order_assets(string $hook = ''): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array((string) $screen->id, self::order_screens(), true)) {
            return;
        }

        $base = FFLA_URL . 'modules/product-reviews/';
        wp_enqueue_script('ffla-qrcode-generator', $base . 'assets/js/vendor/qrcode-generator/qrcode.js', [], '2.0.4', true);
        wp_enqueue_script('ffla-product-reviews-order', $base . 'admin/js/product-reviews-order.js', ['ffla-qrcode-generator'], FFLA_VERSION, true);
        wp_localize_script('ffla-product-reviews-order', 'fflaReviewOrder', [
            'copied'     => __('Link copied.', 'ffl-funnels-addons'),
            'copyFailed' => __('Copy failed: select the link and copy it.', 'ffl-funnels-addons'),
            'qrTitle'    => __('Review your order', 'ffl-funnels-addons'),
        ]);

        wp_register_style('ffla-product-reviews-order', false, [], FFLA_VERSION);
        wp_enqueue_style('ffla-product-reviews-order');
        wp_add_inline_style('ffla-product-reviews-order', '.ffla-review-request-box__facts{margin:0 0 10px;padding:0;list-style:none;color:#50575e}.ffla-review-request-box__facts li{margin:0 0 2px}.ffla-review-request-box__share .button{margin:0 4px 6px 0}.ffla-review-request-box__qr{margin-top:8px}.ffla-review-request-box__qr svg{display:block;width:180px;height:180px}.ffla-review-request-box__copied{display:block;color:#008a20;min-height:1em}');
    }

    /* ---------------------------------------------------------------------
     * Send now, cancel, bulk send
     * ------------------------------------------------------------------- */

    private static function order_edit_url(int $order_id): string
    {
        $order = wc_get_order($order_id);
        if ($order instanceof \WC_Order && method_exists($order, 'get_edit_order_url')) {
            return (string) $order->get_edit_order_url();
        }

        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    private static function store_notice(string $type, string $message): void
    {
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), ['type' => $type, 'message' => $message], MINUTE_IN_SECONDS * 5);
    }

    public function handle_send_now(): void
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        check_admin_referer(self::SEND_ACTION . '_' . $order_id);
        if (!self::can_manage()) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }

        $result = Product_Reviews_Email::send_now($order_id);
        self::store_notice($result['sent'] ? 'success' : 'error', $result['message']);

        wp_safe_redirect(self::order_edit_url($order_id));
        exit;
    }

    public function handle_cancel(): void
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        check_admin_referer(self::CANCEL_ACTION . '_' . $order_id);
        if (!self::can_manage()) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }

        $order = wc_get_order($order_id);
        if ($order instanceof \WC_Order) {
            Product_Reviews_Email::unschedule_for_order($order);
            // Stays marked as scheduled, so completing it again does not
            // queue the email again. Send now still works.
            $order->delete_meta_data(Product_Reviews_Email::SCHEDULED_FOR_META);
            $order->update_meta_data(Product_Reviews_Email::SKIPPED_META, 'cancelled');
            Product_Reviews_Email::save_order($order);
            $order->add_order_note(__('Scheduled review request cancelled.', 'ffl-funnels-addons'));
            self::store_notice('success', __('The scheduled review request was cancelled.', 'ffl-funnels-addons'));
        }

        wp_safe_redirect(self::order_edit_url($order_id));
        exit;
    }

    public function render_notice(): void
    {
        $key    = self::NOTICE_TRANSIENT . get_current_user_id();
        $notice = get_transient($key);
        if (!is_array($notice) || empty($notice['message'])) {
            return;
        }
        delete_transient($key);

        $class = 'success' === ($notice['type'] ?? '') ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p><strong>' . esc_html__('Product Reviews:', 'ffl-funnels-addons') . '</strong> '
            . esc_html((string) $notice['message']) . '</p></div>';
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function add_bulk_action(array $actions): array
    {
        if (Product_Reviews_Core::hub_page_ready()) {
            $actions[self::BULK_ACTION] = __('Send review request', 'ffl-funnels-addons');
        }

        return $actions;
    }

    /**
     * @param string           $redirect
     * @param string           $action
     * @param array<int, mixed> $ids
     * @return string
     */
    public function handle_bulk_action($redirect, $action, $ids)
    {
        if (self::BULK_ACTION !== $action || !self::can_manage()) {
            return $redirect;
        }

        $queued = Product_Reviews_Email::queue_manual(array_map('absint', (array) $ids));
        self::store_notice('success', sprintf(
            /* translators: %s: number of orders */
            _n(
                'Review request queued for %s order. It goes out within a few minutes; orders that cannot get one (unsubscribed, no consent, nothing to review) are skipped.',
                'Review requests queued for %s orders. They go out within a few minutes; orders that cannot get one (unsubscribed, no consent, nothing to review) are skipped.',
                $queued,
                'ffl-funnels-addons'
            ),
            number_format_i18n($queued)
        ));

        return $redirect;
    }

    /* ---------------------------------------------------------------------
     * Test email and preview (settings page)
     * ------------------------------------------------------------------- */

    public function ajax_send_test(): void
    {
        check_ajax_referer('ffla_review_send_test', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')], 403);
        }

        $to   = isset($_POST['to']) ? sanitize_email(wp_unslash($_POST['to'])) : '';
        $kind = isset($_POST['kind']) ? sanitize_key(wp_unslash($_POST['kind'])) : 'request';
        if (!is_email($to)) {
            wp_send_json_error(['message' => __('Enter a valid email address.', 'ffl-funnels-addons')]);
        }

        $sent = 'coupon' === $kind && class_exists('Product_Reviews_Coupons')
            ? Product_Reviews_Coupons::send_test($to)
            : Product_Reviews_Email::send_test($to, 'reminder' === $kind ? 'reminder' : 'request');

        if (!$sent) {
            wp_send_json_error(['message' => __('wp_mail() could not send it. Check that the site can send email.', 'ffl-funnels-addons')]);
        }

        wp_send_json_success(['message' => sprintf(
            /* translators: %s: email address */
            __('Test sent to %s.', 'ffl-funnels-addons'),
            $to
        )]);
    }

    public function handle_preview(): void
    {
        check_admin_referer('ffla_review_email_preview');
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }

        $kind = isset($_GET['kind']) && 'reminder' === $_GET['kind'] ? 'reminder' : 'request';

        nocache_headers();
        echo self::preview_html($kind); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the email, built from escaped parts.
        exit;
    }

    /**
     * The email as the customer would get it: the HTML itself, or the plain
     * text inside a page.
     */
    public static function preview_html(string $kind): string
    {
        $user  = wp_get_current_user();
        $email = Product_Reviews_Email_Template::render($kind, Product_Reviews_Email::sample_data($user instanceof \WP_User ? (string) $user->user_email : ''));

        if ($email['html']) {
            return $email['body'];
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html($email['subject']) . '</title></head><body style="font-family:monospace;padding:24px;">'
            . '<p><strong>' . esc_html($email['subject']) . '</strong></p><pre style="white-space:pre-wrap;">' . esc_html($email['body']) . '</pre></body></html>';
    }
}
