<?php
/**
 * Customer requests — storefront.
 *
 * `[ffla_order_requests]` renders an empty, cache-safe container; everything
 * private is loaded through admin-ajax, which is never page-cached. The same
 * app is embedded in My Account → View order for signed-in customers.
 *
 * How a visitor proves access:
 * - Order number + billing email (guest access) → a short-lived signed ticket
 *   for that order. Failures never say which of the two was wrong.
 * - The request's access key, from the tracking link in their emails.
 * - Signed in as the order's customer (nonce fetched over AJAX, so cached
 *   pages never carry one).
 *
 * Abuse controls: honeypot field, minimum fill time, per-IP and per-order
 * rate limits, per-order request caps and strict upload checks.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Public
{
    const TICKET_TTL = 7200;
    const ENDPOINTS = ['session', 'verify', 'submit', 'view', 'reply', 'withdraw', 'file', 'tracking', 'rate'];

    public static function boot(): void
    {
        add_shortcode('ffla_order_requests', [__CLASS__, 'shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'register_assets']);
        foreach (self::ENDPOINTS as $endpoint) {
            add_action('wp_ajax_ffla_req_' . $endpoint, [__CLASS__, 'ajax_' . $endpoint]);
            add_action('wp_ajax_nopriv_ffla_req_' . $endpoint, [__CLASS__, 'ajax_' . $endpoint]);
        }
        add_action('woocommerce_view_order', [__CLASS__, 'view_order'], 25);
        add_filter('woocommerce_my_account_my_orders_actions', [__CLASS__, 'order_actions'], 20, 2);

        // My Account tab (endpoint registered through WooCommerce's query vars).
        $endpoint = self::endpoint();
        add_filter('woocommerce_get_query_vars', [__CLASS__, 'query_vars']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_items'], 20);
        add_action('woocommerce_account_' . $endpoint . '_endpoint', [__CLASS__, 'account_endpoint']);
        add_filter('woocommerce_endpoint_' . $endpoint . '_title', [__CLASS__, 'endpoint_title'], 10, 2);
        add_action('wp_loaded', [__CLASS__, 'maybe_flush_rewrites'], 99);
    }

    /* ── My Account tab ────────────────────────────────────────────────── */

    /** URL slug of the tab: /my-account/{slug}/. */
    public static function endpoint(): string
    {
        $slug = sanitize_title((string) apply_filters('ffla_requests_account_endpoint', 'returns-issues'));
        return '' !== $slug ? $slug : 'returns-issues';
    }

    public static function account_tab(): bool
    {
        return FFLA_Requests::enabled() && FFLA_Customer_Operations_Settings::enabled('requests_account_tab');
    }

    public static function account_url(string $order_number = ''): string
    {
        $url = wc_get_account_endpoint_url(self::endpoint());
        return '' !== $order_number ? add_query_arg('ffla_order', rawurlencode($order_number), $url) : $url;
    }

    public static function query_vars($vars)
    {
        if (is_array($vars) && self::account_tab()) {
            $vars[self::endpoint()] = self::endpoint();
        }
        return $vars;
    }

    public static function menu_label(): string
    {
        $label = trim((string) FFLA_Requests::setting('requests_account_label'));
        return '' !== $label ? $label : __('Returns & Issues', 'ffl-funnels-addons');
    }

    /** Insert the tab after Orders; show how many requests wait for the customer. */
    public static function menu_items($items)
    {
        if (!is_array($items) || !self::account_tab() || !is_user_logged_in()) {
            return $items;
        }
        $label = self::menu_label();
        $waiting = self::waiting_count(get_current_user_id());
        if ($waiting) {
            $label .= ' (' . number_format_i18n($waiting) . ')';
        }

        $out = [];
        foreach ($items as $key => $value) {
            $out[$key] = $value;
            if ('orders' === $key) {
                $out[self::endpoint()] = $label;
            }
        }
        if (!isset($out[self::endpoint()])) {
            $logout = isset($out['customer-logout']) ? ['customer-logout' => $out['customer-logout']] : [];
            unset($out['customer-logout']);
            $out[self::endpoint()] = $label;
            $out += $logout;
        }
        return $out;
    }

    private static function waiting_count(int $user_id): int
    {
        global $wpdb;
        if (!$user_id || get_option(FFLA_Requests::DB_OPTION) !== FFLA_Requests::DB_VERSION) {
            return 0;
        }
        $t = FFLA_Requests::tables();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['requests']} WHERE customer_id = %d AND status = 'waiting_customer'", $user_id)); // phpcs:ignore
    }

    public static function endpoint_title($title, $endpoint = '')
    {
        return self::account_tab() ? self::menu_label() : $title;
    }

    public static function account_endpoint(): void
    {
        if (!self::account_tab()) {
            echo '<p>' . esc_html__('Requests are not available right now. Please contact the store.', 'ffl-funnels-addons') . '</p>';
            return;
        }
        echo self::shortcode(['account' => '1']); // phpcs:ignore WordPress.Security.EscapeOutput
    }

    /** Refresh permalinks once when the tab is switched on or off (or its slug changes). */
    public static function maybe_flush_rewrites(): void
    {
        $want = self::account_tab() ? self::endpoint() : '';
        if (get_option('ffla_requests_endpoint') !== $want) {
            flush_rewrite_rules(false);
            update_option('ffla_requests_endpoint', $want, true);
        }
    }

    /* ── Assets and markup ─────────────────────────────────────────────── */

    public static function register_assets(): void
    {
        $dir = dirname(__DIR__, 2) . '/assets/';
        $url = FFLA_URL . 'modules/customer-notes/assets/';
        FFLA_Requests_Files::register_assets($url, $dir);
        wp_register_style('ffla-requests', $url . 'requests.css', ['ffla-request-files'], (string) @filemtime($dir . 'requests.css')); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        wp_register_script('ffla-requests', $url . 'requests.js', ['ffla-request-files'], (string) @filemtime($dir . 'requests.js'), true); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        // Load the stylesheet in <head> when the shortcode is in the content;
        // page builders fall back to the footer copy enqueued by the shortcode.
        $post = get_post();
        if (FFLA_Requests::enabled() && (is_singular() || is_front_page()) && $post && has_shortcode((string) $post->post_content, 'ffla_order_requests')) {
            wp_enqueue_style('ffla-requests');
        }
    }

    public static function shortcode($atts = []): string
    {
        if (!FFLA_Requests::enabled()) {
            return current_user_can('manage_woocommerce')
                ? '<p class="ffla-req-admin-hint">' . esc_html__('Customer requests are turned off. Enable them in Customer & Order Management → Customer Requests (only store managers see this note).', 'ffl-funnels-addons') . '</p>'
                : '';
        }

        $atts = shortcode_atts(['type' => 'both', 'order' => '', 'title' => '', 'account' => ''], (array) $atts, 'ffla_order_requests');
        $types = FFLA_Requests::types_enabled();
        if (in_array($atts['type'], ['issue', 'return'], true)) {
            $types = array_values(array_intersect($types, [$atts['type']]));
        }
        if (!$types) {
            return '';
        }

        if (!wp_style_is('ffla-requests', 'registered')) {
            self::register_assets();
        }
        wp_enqueue_style('ffla-requests');
        wp_enqueue_script('ffla-requests');

        static $instance = 0;
        $instance++;

        return '<div ' . (1 === $instance ? 'id="ffla-requests" ' : '') . 'class="ffla-req" data-config="' . esc_attr((string) wp_json_encode(self::config($types, (string) $atts['order'], !empty($atts['account'])))) . '">'
            . ('' !== $atts['title'] ? '<h2 class="ffla-req-heading">' . esc_html($atts['title']) . '</h2>' : '')
            . '<div class="ffla-req-app" aria-live="polite"><p class="ffla-req-loading">' . esc_html__('Loading…', 'ffl-funnels-addons') . '</p></div>'
            . '<noscript><p>' . esc_html__('Please enable JavaScript to report a problem or request a return, or contact the store.', 'ffl-funnels-addons') . '</p></noscript>'
            . '</div>';
    }

    /** Static (cacheable) configuration — no customer data. */
    private static function config(array $types, string $order, bool $account = false): array
    {
        $myaccount = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : '';

        return [
            'ajax'          => admin_url('admin-ajax.php'),
            'types'         => $types,
            'order'         => $order,
            'embedded'      => '' !== $order,
            'account'       => $account,
            'intro'         => str_replace('\\n', "\n", (string) FFLA_Requests::setting('requests_intro')),
            'guests'        => FFLA_Customer_Operations_Settings::enabled('requests_guests'),
            'uploads'       => FFLA_Customer_Operations_Settings::enabled('requests_uploads'),
            'maxFiles'      => FFLA_Requests_Files::per_upload(),
            'maxFileBytes'  => FFLA_Requests_Files::MAX_UPLOAD,
            'postLimit'     => FFLA_Requests_Files::post_limit(),
            'maxVideoBytes' => FFLA_Requests_Files::videos_enabled() ? FFLA_Requests_Files::video_limit() : 0,
            'heicServer'    => FFLA_Requests_Files::heic_supported(),
            'accept'        => FFLA_Requests_Files::accept(),
            'maxText'       => FFLA_Requests::MAX_TEXT,
            'loginUrl'      => $myaccount ? $myaccount : wp_login_url(),
            'reasons'       => ['issue' => FFLA_Requests::reasons('issue'), 'return' => FFLA_Requests::reasons('return')],
            'preferences'   => FFLA_Requests::preferences(),
            'firearmNotice' => self::fill_notice((string) FFLA_Requests::setting('requests_firearm_notice')),
            'photoReasons'  => array_values(FFLA_Requests_Rules::photo_reasons()),
            'feeWaived'     => array_values(FFLA_Requests_Rules::fee_waived_reasons()),
            'fflRequired'   => FFLA_Customer_Operations_Settings::enabled('requests_ffl_required'),
            'carriers'      => FFLA_Requests::carriers(),
            'i18n'          => self::strings() + FFLA_Requests_Files::picker_strings(),
        ];
    }

    private static function strings(): array
    {
        return [
            'findTitle'       => __('Find your order', 'ffl-funnels-addons'),
            'orderNumber'     => __('Order or request number', 'ffl-funnels-addons'),
            'email'           => __('Email used at checkout', 'ffl-funnels-addons'),
            'continue'        => __('Continue', 'ffl-funnels-addons'),
            'signIn'          => __('Sign in to report a problem or request a return.', 'ffl-funnels-addons'),
            'signInLink'      => __('Sign in', 'ffl-funnels-addons'),
            'yourOrders'      => __('Your recent orders', 'ffl-funnels-addons'),
            'pickOrder'       => __('Choose an order to report a problem or request a return.', 'ffl-funnels-addons'),
            'noRequests'      => __('You have no requests yet.', 'ffl-funnels-addons'),
            'yourRequests'    => __('Your requests', 'ffl-funnels-addons'),
            'otherOrder'      => __('Find another order by number and email', 'ffl-funnels-addons'),
            'noOrders'        => __('No orders found on your account.', 'ffl-funnels-addons'),
            'select'          => __('Select', 'ffl-funnels-addons'),
            'order'           => __('Order', 'ffl-funnels-addons'),
            'placed'          => __('Placed', 'ffl-funnels-addons'),
            'status'          => __('Status', 'ffl-funnels-addons'),
            'total'           => __('Total', 'ffl-funnels-addons'),
            'requestsOnOrder' => __('Requests on this order', 'ffl-funnels-addons'),
            'whatHappened'    => __('How can we help?', 'ffl-funnels-addons'),
            'issue'           => __('Report a problem', 'ffl-funnels-addons'),
            'issueHint'       => __('Not received, damaged, missing or wrong item, billing or transfer question.', 'ffl-funnels-addons'),
            'return'          => __('Request a return', 'ffl-funnels-addons'),
            'returnHint'      => __('Choose the items and quantities you want to send back.', 'ffl-funnels-addons'),
            'back'            => __('Back', 'ffl-funnels-addons'),
            'reason'          => __('Reason', 'ffl-funnels-addons'),
            'chooseReason'    => __('Choose a reason', 'ffl-funnels-addons'),
            'itemsReturn'     => __('Items to return', 'ffl-funnels-addons'),
            'itemsIssue'      => __('Which items are affected? (optional)', 'ffl-funnels-addons'),
            'qty'             => __('Quantity', 'ffl-funnels-addons'),
            'notAvailable'    => __('Already returned or requested', 'ffl-funnels-addons'),
            'firearm'         => __('Firearm', 'ffl-funnels-addons'),
            'preferred'       => __('What would you like us to do? (optional)', 'ffl-funnels-addons'),
            'noPreference'    => __('— Choose —', 'ffl-funnels-addons'),
            'message'         => __('Describe the problem', 'ffl-funnels-addons'),
            'messageHint'     => __('Include anything that helps: what happened, when, photos of damage or labels. Do not include card numbers or ID documents.', 'ffl-funnels-addons'),
            'filesLabel'      => __('Photos or documents (optional)', 'ffl-funnels-addons'),
            'filesHint'       => FFLA_Requests_Files::videos_enabled()
                ? __('Photos, PDFs or short videos — as many as you need. Photos are resized before sending.', 'ffl-funnels-addons')
                : __('Photos or PDFs — as many as you need. Photos are resized before sending.', 'ffl-funnels-addons'),
            /* translators: %d: number of files the server accepts at once */
            'tooManyFiles'    => __('You can send %d files at a time. Send the rest with a reply after this one.', 'ffl-funnels-addons'),
            'tooLarge'        => __('These files are too large to send together. Send some of them with a reply after this one.', 'ffl-funnels-addons'),
            'preparing'       => __('Preparing photos…', 'ffl-funnels-addons'),
            'fileTooBig'      => __('One of the files is too large.', 'ffl-funnels-addons'),
            'submit'          => __('Send request', 'ffl-funnels-addons'),
            'sending'         => __('Sending…', 'ffl-funnels-addons'),
            'created'         => __('Thanks — your request was received. We emailed you a link to follow it; you can also bookmark this page.', 'ffl-funnels-addons'),
            'request'         => __('Request', 'ffl-funnels-addons'),
            'opened'          => __('Opened', 'ffl-funnels-addons'),
            'updated'         => __('Last update', 'ffl-funnels-addons'),
            'items'           => __('Items', 'ffl-funnels-addons'),
            'prefers'         => __('Requested outcome', 'ffl-funnels-addons'),
            'timeline'        => __('History', 'ffl-funnels-addons'),
            'reply'           => __('Add a reply', 'ffl-funnels-addons'),
            'replyReopen'     => __('Still not resolved? Reply below and we will reopen your request.', 'ffl-funnels-addons'),
            'sendReply'       => __('Send reply', 'ffl-funnels-addons'),
            'withdraw'        => __('Cancel this request', 'ffl-funnels-addons'),
            'withdrawConfirm' => __('Yes, cancel it', 'ffl-funnels-addons'),
            'withdrawKeep'    => __('Keep it open', 'ffl-funnels-addons'),
            'withdrawWhy'     => __('Reason for cancelling (optional)', 'ffl-funnels-addons'),
            'resolution'      => __('Resolution', 'ffl-funnels-addons'),
            'instructions'    => __('Return instructions', 'ffl-funnels-addons'),
            'copyLink'        => __('Copy link to this request', 'ffl-funnels-addons'),
            'copied'          => __('Link copied', 'ffl-funnels-addons'),
            'viewOrder'       => __('All requests for this order', 'ffl-funnels-addons'),
            'retry'           => __('Something went wrong. Please try again.', 'ffl-funnels-addons'),
            'required'        => __('Please fill in the required fields.', 'ffl-funnels-addons'),
            'chooseItems'     => __('Select at least one item to return.', 'ffl-funnels-addons'),
            'stepSkipped'     => __('Skipped', 'ffl-funnels-addons'),
            'none'            => __('None yet.', 'ffl-funnels-addons'),
            'charsLeft'       => __('characters left', 'ffl-funnels-addons'),
            'photoNeeded'     => __('Please add at least one photo for this reason.', 'ffl-funnels-addons'),
            'photoHint'       => __('A photo is required for this reason.', 'ffl-funnels-addons'),
            'cannotReturn'    => __('Not returnable', 'ffl-funnels-addons'),
            /* translators: %s: percent */
            'feeLine'         => __('Restocking fee %s%%', 'ffl-funnels-addons'),
            'feeTitle'        => __('Restocking fee', 'ffl-funnels-addons'),
            /* translators: 1: fee amount, 2: estimated refund */
            'feeEstimate'     => __('Restocking fee: %1$s. Estimated refund for these items: %2$s (shipping not included). The store confirms the final amount.', 'ffl-funnels-addons'),
            'feeWaivedNote'   => __('No restocking fee for this reason.', 'ffl-funnels-addons'),
            'fflTitle'        => __('FFL dealer for the return', 'ffl-funnels-addons'),
            'fflHint'         => __('A firearm can only come back through a licensed dealer. Tell us which dealer will ship it.', 'ffl-funnels-addons'),
            'fflUseOrder'     => __('Use the dealer from this order', 'ffl-funnels-addons'),
            'fflOther'        => __('Use a different dealer', 'ffl-funnels-addons'),
            'fflName'         => __('Dealer name', 'ffl-funnels-addons'),
            'fflLicense'      => __('FFL license number', 'ffl-funnels-addons'),
            'fflLicenseHint'  => __('15 characters, e.g. 1-23-456-78-9A-12345', 'ffl-funnels-addons'),
            'fflAddress'      => __('Street address', 'ffl-funnels-addons'),
            'fflCity'         => __('City', 'ffl-funnels-addons'),
            'fflState'        => __('State', 'ffl-funnels-addons'),
            'fflZip'          => __('ZIP code', 'ffl-funnels-addons'),
            'fflPhone'        => __('Dealer phone', 'ffl-funnels-addons'),
            'fflEmail'        => __('Dealer email', 'ffl-funnels-addons'),
            'fflMissing'      => __('Enter the dealer name, a valid 15-character FFL license number, city and state.', 'ffl-funnels-addons'),
            'fflLabel'        => __('Return dealer (FFL)', 'ffl-funnels-addons'),
            'label'           => __('Your return label', 'ffl-funnels-addons'),
            'labelHint'       => __('Print it and attach it to the package.', 'ffl-funnels-addons'),
            'shipTitle'       => __('Shipped it back?', 'ffl-funnels-addons'),
            'shipHint'        => __('Enter the carrier and tracking number so we can watch for your package.', 'ffl-funnels-addons'),
            'carrier'         => __('Carrier', 'ffl-funnels-addons'),
            'trackingNumber'  => __('Tracking number', 'ffl-funnels-addons'),
            'saveTracking'    => __('Save tracking number', 'ffl-funnels-addons'),
            'shipment'        => __('Return shipment', 'ffl-funnels-addons'),
            'trackPackage'    => __('Track package', 'ffl-funnels-addons'),
            'rateTitle'       => __('How did we do?', 'ffl-funnels-addons'),
            'rateComment'     => __('Anything we could do better? (optional)', 'ffl-funnels-addons'),
            'rateSend'        => __('Send rating', 'ffl-funnels-addons'),
            /* translators: %d: stars */
            'rateStars'       => __('%d out of 5 stars', 'ffl-funnels-addons'),
            'rateThanks'      => __('Thanks for your feedback!', 'ffl-funnels-addons'),
            'rateChange'      => __('Change rating', 'ffl-funnels-addons'),
            'rateChoose'      => __('Choose 1 to 5 stars.', 'ffl-funnels-addons'),
            'refunded'        => __('Refunded so far', 'ffl-funnels-addons'),
        ];
    }

    private static function fill_notice(string $template): string
    {
        return str_replace(['\\n', '{store_name}'], ["\n", wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)], $template);
    }

    /** My Account → View order: the same app, already opened on this order. */
    public static function view_order($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!FFLA_Requests::enabled() || !FFLA_Requests::is_owner($order)) {
            return;
        }
        echo '<section class="ffla-req-account"><h2>' . esc_html__('Need help with this order?', 'ffl-funnels-addons') . '</h2>'
            . self::shortcode(['order' => (string) $order->get_order_number()]) // phpcs:ignore WordPress.Security.EscapeOutput
            . '</section>';
    }

    /** My Account → Orders: "Get help" action. */
    public static function order_actions($actions, $order)
    {
        if (is_array($actions) && FFLA_Requests::enabled() && FFLA_Requests::is_owner($order)
            && !in_array($order->get_status(), ['pending', 'failed', 'cancelled', 'checkout-draft'], true)) {
            $actions['ffla-help'] = [
                'url'  => self::account_tab() ? self::account_url((string) $order->get_order_number()) : $order->get_view_order_url() . '#ffla-requests',
                'name' => __('Get help', 'ffl-funnels-addons'),
            ];
        }
        return $actions;
    }

    /* ── Endpoints ─────────────────────────────────────────────────────── */

    /** Who is asking: signed-in customers get a nonce and their orders. */
    public static function ajax_session(): void
    {
        self::guard();
        $out = ['loggedIn' => is_user_logged_in()];
        if (!$out['loggedIn']) {
            wp_send_json_success($out);
        }

        $out['nonce'] = wp_create_nonce('ffla_req');
        if (!empty($_POST['embedded'])) { // phpcs:ignore WordPress.Security.NonceVerification
            wp_send_json_success($out);
        }

        $user_id = get_current_user_id();
        $out['orders'] = [];
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'customer_id' => $user_id,
                'limit'       => 20,
                'orderby'     => 'date',
                'order'       => 'DESC',
                'type'        => 'shop_order',
                'status'      => array_diff(array_keys(wc_get_order_statuses()), ['wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft']),
            ]);
            foreach ($orders as $order) {
                $out['orders'][] = [
                    'number' => (string) $order->get_order_number(),
                    'date'   => $order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '',
                    'status' => wc_get_order_status_name($order->get_status()),
                    'total'  => self::plain_price($order->get_total(), $order->get_currency()),
                ];
            }
        }
        $mine = FFLA_Requests::query(['view' => 'all', 'customer_id' => $user_id, 'per_page' => 20, 'orderby' => 'updated_at']);
        $out['requests'] = array_map([__CLASS__, 'summary'], $mine['rows']);

        wp_send_json_success($out);
    }

    /**
     * Open an order (number + email, or the signed-in owner) — or, when the
     * number is a request number and the email matches, that request.
     */
    public static function ajax_verify(): void
    {
        self::guard();
        // phpcs:disable WordPress.Security.NonceVerification
        $number = substr(trim(sanitize_text_field(wp_unslash($_POST['order'] ?? ''))), 0, 60);
        $email = strtolower(trim(sanitize_text_field(wp_unslash($_POST['email'] ?? ''))));
        // phpcs:enable

        if (self::honeypot() || '' === $number) {
            self::fail(__('Enter your order number and the email used at checkout.', 'ffl-funnels-addons'));
        }

        // Back to an order the visitor already opened (ticket still valid).
        $ticket_order = self::ticket_order((string) wp_unslash($_POST['ticket'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        if ($ticket_order && (string) $ticket_order->get_order_number() === ltrim($number, '#')) {
            wp_send_json_success(self::order_payload($ticket_order));
        }

        if (self::owner_nonce()) {
            $order = FFLA_Requests::find_order($number);
            if ($order && FFLA_Requests::is_owner($order)) {
                wp_send_json_success(self::order_payload($order));
            }
        }

        if (!FFLA_Customer_Operations_Settings::enabled('requests_guests')) {
            self::fail(is_user_logged_in()
                ? __('This order is not on your account. Sign in with the account that placed it.', 'ffl-funnels-addons')
                : __('Please sign in to continue.', 'ffl-funnels-addons'), 403);
        }
        if ('' === $email) {
            self::fail(__('Enter the email address used at checkout.', 'ffl-funnels-addons'));
        }
        if (!FFLA_Requests::rate_limit('verify', 10, 15 * MINUTE_IN_SECONDS)
            || !FFLA_Requests::rate_limit('verify-order:' . strtolower(ltrim($number, '#')), 15, HOUR_IN_SECONDS, false)) {
            self::fail(__('Too many attempts. Please wait a few minutes and try again.', 'ffl-funnels-addons'), 429);
        }

        $request = FFLA_Requests::get_by_number($number);
        if ($request && '' !== (string) $request->customer_email && hash_equals((string) $request->customer_email, $email)) {
            wp_send_json_success(['kind' => 'request', 'request' => self::view_payload($request)]);
        }

        $order = FFLA_Requests::verify($number, $email);
        if (!$order) {
            self::fail(__("We couldn't find an order with that number and email. Check both and try again.", 'ffl-funnels-addons'), 404);
        }
        wp_send_json_success(self::order_payload($order));
    }

    public static function ajax_submit(): void
    {
        self::guard();
        // phpcs:disable WordPress.Security.NonceVerification
        if (self::honeypot() || (int) ($_POST['elapsed'] ?? 0) < 2500) {
            self::fail(__('Please take a moment to fill in the form, then send it again.', 'ffl-funnels-addons'));
        }
        // Attempts (including ones rejected by validation) and created requests are limited separately.
        if (!FFLA_Requests::rate_limit('submit-try', 30, HOUR_IN_SECONDS) || !FFLA_Requests::rate_limit('submit', 6, HOUR_IN_SECONDS, true, false)) {
            self::fail(__('Too many requests from this connection. Please try again later or contact the store.', 'ffl-funnels-addons'), 429);
        }

        $order = self::ticket_order((string) wp_unslash($_POST['ticket'] ?? ''));
        if (!$order && self::owner_nonce()) {
            $candidate = FFLA_Requests::find_order(sanitize_text_field(wp_unslash($_POST['order'] ?? '')));
            $order = FFLA_Requests::is_owner($candidate) ? $candidate : null;
        }
        if (!$order) {
            self::fail(__('Your session expired. Please find your order again.', 'ffl-funnels-addons'), 403);
        }

        $type = sanitize_key(wp_unslash($_POST['type'] ?? ''));
        $data = [
            'type'      => $type,
            'reason'    => sanitize_key(wp_unslash($_POST['reason'] ?? '')),
            'preferred' => sanitize_key(wp_unslash($_POST['preferred'] ?? '')),
            'message'   => wp_unslash($_POST['message'] ?? ''),
            'items'     => [],
        ];
        foreach ((array) wp_unslash($_POST['items'] ?? []) as $item_id => $qty) {
            if (is_scalar($qty)) {
                $data['items'][absint($item_id)] = absint($qty);
            }
        }
        if (isset($_POST['ffl']) && is_array($_POST['ffl'])) {
            $data['ffl'] = array_map(static function ($v) { return is_scalar($v) ? (string) $v : ''; }, (array) wp_unslash($_POST['ffl']));
        }
        $source = !empty($_POST['embedded']) && FFLA_Requests::is_owner($order) ? 'my_account' : 'public_form';
        // phpcs:enable

        // A double-click or resubmission returns the request just created.
        $duplicate = self::recent_duplicate($order, $data);
        if ($duplicate) {
            wp_send_json_success(['kind' => 'request', 'created' => true, 'request' => self::view_payload($duplicate)]);
        }

        $actor = ['type' => 'customer', 'id' => get_current_user_id()];
        try {
            $files = FFLA_Customer_Operations_Settings::enabled('requests_uploads') ? FFLA_Requests_Files::from_request('files') : [];
            $prepared = $files ? FFLA_Requests_Files::prepare($files, 0) : [];
            $data['files_count'] = count($prepared);
            $request = FFLA_Requests::create($order, $data, $source, $actor);
        } catch (InvalidArgumentException $e) {
            self::fail($e->getMessage());
        } catch (Throwable $e) {
            self::fail(__('The request could not be saved. Please try again or contact the store.', 'ffl-funnels-addons'), 500);
        }

        FFLA_Requests::rate_limit('submit', 6, HOUR_IN_SECONDS);
        if ($prepared) {
            $events = FFLA_Requests::events((int) $request->id);
            FFLA_Requests_Files::store($request, $prepared, $actor, true, $events ? (int) $events[0]->id : 0);
        }

        wp_send_json_success(['kind' => 'request', 'created' => true, 'request' => self::view_payload(FFLA_Requests::get((int) $request->id))]);
    }

    public static function ajax_view(): void
    {
        self::guard();
        if (!FFLA_Requests::rate_limit('view', 120, 10 * MINUTE_IN_SECONDS)) {
            self::fail(__('Too many attempts. Please wait a few minutes and try again.', 'ffl-funnels-addons'), 429);
        }
        $request = self::authorize();
        wp_send_json_success(['kind' => 'request', 'request' => self::view_payload($request)]);
    }

    public static function ajax_reply(): void
    {
        self::guard();
        if (self::honeypot() || !FFLA_Requests::rate_limit('reply', 30, HOUR_IN_SECONDS)) {
            self::fail(__('Too many messages from this connection. Please try again later.', 'ffl-funnels-addons'), 429);
        }
        $request = self::authorize();
        $actor = ['type' => 'customer', 'id' => get_current_user_id()];
        $text = FFLA_Requests::clean_text(wp_unslash($_POST['message'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification

        try {
            $files = FFLA_Customer_Operations_Settings::enabled('requests_uploads') ? FFLA_Requests_Files::from_request('files') : [];
            $prepared = $files ? FFLA_Requests_Files::prepare($files, FFLA_Requests_Files::count((int) $request->id), FFLA_Requests_Files::count_videos((int) $request->id)) : [];
            $event_id = FFLA_Requests::add_message($request, $actor, $text, true);
        } catch (InvalidArgumentException $e) {
            self::fail($e->getMessage());
        } catch (Throwable $e) {
            self::fail(__('Your reply could not be saved. Please try again.', 'ffl-funnels-addons'), 500);
        }
        if ($prepared) {
            FFLA_Requests_Files::store($request, $prepared, $actor, true, $event_id);
        }

        $request = FFLA_Requests::get((int) $request->id);
        FFLA_Requests_Mail::staff_reply($request, $text);
        do_action('ffla_request_customer_replied', $request, $text, 'page');
        wp_send_json_success(['kind' => 'request', 'request' => self::view_payload($request)]);
    }

    public static function ajax_withdraw(): void
    {
        self::guard();
        $request = self::authorize();
        if (!self::can_withdraw($request)) {
            self::fail(__('This request can no longer be cancelled online. Reply to it instead.', 'ffl-funnels-addons'));
        }
        $note = FFLA_Requests::clean_text(wp_unslash($_POST['message'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        try {
            FFLA_Requests::close($request, 'withdrawn', $note, ['type' => 'customer', 'id' => get_current_user_id()]);
        } catch (InvalidArgumentException $e) {
            self::fail($e->getMessage());
        }
        $request = FFLA_Requests::get((int) $request->id);
        FFLA_Requests_Mail::staff_reply($request, trim(__('The customer cancelled this request.', 'ffl-funnels-addons') . "\n\n" . $note));
        wp_send_json_success(['kind' => 'request', 'request' => self::view_payload($request)]);
    }

    /** Customer enters the carrier and tracking number of their return. */
    public static function ajax_tracking(): void
    {
        self::guard();
        if (self::honeypot() || !FFLA_Requests::rate_limit('reply', 30, HOUR_IN_SECONDS)) {
            self::fail(__('Too many messages from this connection. Please try again later.', 'ffl-funnels-addons'), 429);
        }
        $request = self::authorize();
        if (!self::can_track($request)) {
            self::fail(__('Tracking can be added once your return is approved.', 'ffl-funnels-addons'));
        }
        try {
            // phpcs:ignore WordPress.Security.NonceVerification
            FFLA_Requests::set_return_tracking($request, sanitize_key(wp_unslash($_POST['carrier'] ?? '')), sanitize_text_field(wp_unslash($_POST['tracking'] ?? '')), ['type' => 'customer', 'id' => get_current_user_id()]);
        } catch (InvalidArgumentException $e) {
            self::fail($e->getMessage());
        }
        $request = FFLA_Requests::get((int) $request->id);
        FFLA_Requests_Mail::staff_tracking($request);
        wp_send_json_success(['kind' => 'request', 'request' => self::view_payload($request)]);
    }

    public static function ajax_rate(): void
    {
        self::guard();
        if (!FFLA_Customer_Operations_Settings::enabled('requests_ratings') || !FFLA_Requests::rate_limit('reply', 30, HOUR_IN_SECONDS)) {
            self::fail(__('Ratings are not available.', 'ffl-funnels-addons'));
        }
        $request = self::authorize();
        try {
            // phpcs:ignore WordPress.Security.NonceVerification
            FFLA_Requests::rate($request, absint($_POST['rating'] ?? 0), (string) wp_unslash($_POST['comment'] ?? ''));
        } catch (InvalidArgumentException $e) {
            self::fail($e->getMessage());
        }
        wp_send_json_success(['kind' => 'request', 'request' => self::view_payload(FFLA_Requests::get((int) $request->id))]);
    }

    public static function ajax_file(): void
    {
        if (!FFLA_Requests::enabled()) {
            wp_die(esc_html__('Not found.', 'ffl-funnels-addons'), '', ['response' => 404]);
        }
        if (!FFLA_Requests::rate_limit('file', 200, 10 * MINUTE_IN_SECONDS)) {
            wp_die(esc_html__('Too many downloads. Please wait a few minutes.', 'ffl-funnels-addons'), '', ['response' => 429]);
        }
        $request = self::authorize(false);
        if (!$request) {
            wp_die(esc_html__('This link is not valid.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        FFLA_Requests_Files::stream($request, (string) wp_unslash($_GET['file'] ?? ''), true); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /* ── Access ────────────────────────────────────────────────────────── */

    private static function guard(): void
    {
        if (!FFLA_Requests::enabled() || !function_exists('wc_get_order')) {
            self::fail(__('Requests are not available right now. Please contact the store.', 'ffl-funnels-addons'), 404);
        }
        FFLA_Requests::maybe_install_now();
    }

    /**
     * The request named in `number`, if the visitor holds its key, a ticket
     * for its order, or is its order's signed-in customer.
     */
    private static function authorize(bool $fail = true)
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $request = FFLA_Requests::get_by_number(substr(sanitize_text_field(wp_unslash($_REQUEST['number'] ?? '')), 0, 60));
        $key = preg_replace('/[^a-f0-9]/', '', strtolower((string) wp_unslash($_REQUEST['key'] ?? '')));
        $ticket = (string) wp_unslash($_REQUEST['ticket'] ?? '');
        // phpcs:enable

        $ok = false;
        if ($request) {
            if ('' !== $key && FFLA_Requests::check_key($request, $key)) {
                $ok = true;
            } elseif ('' !== $ticket) {
                $order = self::ticket_order($ticket);
                $ok = $order && (int) $order->get_id() === (int) $request->order_id;
            }
            if (!$ok && self::owner_nonce()) {
                $ok = FFLA_Requests::is_owner(wc_get_order((int) $request->order_id));
            }
        }

        if ($ok) {
            return $request;
        }
        if ($fail) {
            self::fail(__('This link is not valid or has expired. Find your order again to see its requests.', 'ffl-funnels-addons'), 403);
        }
        return null;
    }

    private static function owner_nonce(): bool
    {
        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        return is_user_logged_in() && '' !== $nonce && false !== wp_verify_nonce($nonce, 'ffla_req');
    }

    /** Signed, expiring proof that the visitor matched an order's billing email. */
    public static function ticket($order): string
    {
        $expires = time() + self::TICKET_TTL;
        return $order->get_id() . '.' . $expires . '.' . self::ticket_sig((int) $order->get_id(), $expires, (string) $order->get_billing_email());
    }

    public static function ticket_order(string $ticket)
    {
        $parts = explode('.', $ticket);
        if (3 !== count($parts) || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int) $parts[1] < time()) {
            return null;
        }
        $order = wc_get_order((int) $parts[0]);
        if (!$order instanceof WC_Order || 'shop_order' !== $order->get_type()) {
            return null;
        }
        $expected = self::ticket_sig((int) $order->get_id(), (int) $parts[1], (string) $order->get_billing_email());
        return hash_equals($expected, $parts[2]) ? $order : null;
    }

    private static function ticket_sig(int $order_id, int $expires, string $email): string
    {
        return substr(hash_hmac('sha256', 'ffla-ticket|' . $order_id . '|' . $expires . '|' . strtolower(trim($email)), wp_salt('auth')), 0, 40);
    }

    private static function honeypot(): bool
    {
        return !empty($_POST['website']); // phpcs:ignore WordPress.Security.NonceVerification
    }

    private static function fail(string $message, int $status = 400): void
    {
        wp_send_json_error(['message' => $message], $status);
    }

    /* ── Payloads ──────────────────────────────────────────────────────── */

    private static function order_payload($order): array
    {
        $items = [];
        foreach (FFLA_Requests::returnable_items($order) as $line) {
            $items[] = [
                'id'        => $line['item_id'],
                'name'      => $line['name'],
                'ordered'   => $line['ordered'],
                'available' => $line['available'],
                'firearm'   => $line['firearm'],
                'price'     => $line['price'],
                'fee'       => $line['fee'],
                'blocked'   => $line['blocked'],
                'note'      => $line['note'],
            ];
        }
        $dealer = FFLA_Requests::order_dealer($order);

        return [
            'kind'        => 'order',
            'ticket'      => self::ticket($order),
            'order'       => [
                'number' => (string) $order->get_order_number(),
                'date'   => $order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '',
                'status' => wc_get_order_status_name($order->get_status()),
                'total'  => self::plain_price($order->get_total(), $order->get_currency()),
                'items'  => $items,
                'dealer' => $dealer ? self::dealer_summary($dealer) : '',
            ],
            'currency'    => [
                'symbol'   => html_entity_decode(get_woocommerce_currency_symbol($order->get_currency()), ENT_QUOTES, 'UTF-8'),
                'decimals' => wc_get_price_decimals(),
                'dec'      => wc_get_price_decimal_separator(),
                'thou'     => wc_get_price_thousand_separator(),
                'format'   => html_entity_decode(get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8'),
            ],
            'eligibility' => FFLA_Requests::eligibility($order),
            'requests'    => array_map([__CLASS__, 'summary'], array_reverse(FFLA_Requests::for_order($order->get_id()))),
        ];
    }

    private static function summary($request): array
    {
        return [
            'number'      => $request->number,
            'orderNumber' => $request->order_number,
            'type'        => $request->type,
            'typeLabel'   => FFLA_Requests::type_label($request->type),
            'status'      => $request->status,
            'statusLabel' => FFLA_Requests::status_label($request->status, 'customer'),
            'open'        => FFLA_Requests::is_open($request->status),
            'updated'     => FFLA_Requests::local_time($request->updated_at),
        ];
    }

    /** Everything the customer may see about one request. */
    public static function view_payload($request): array
    {
        $key = FFLA_Requests::access_key($request);
        $events = FFLA_Requests::events((int) $request->id, true);
        $store = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $resolutions = FFLA_Requests::resolutions();

        $files_by_event = [];
        $labels = [];
        foreach (FFLA_Requests_Files::for_request((int) $request->id, true) as $file) {
            $entry = [
                'name'  => $file->name,
                'url'   => add_query_arg(['action' => 'ffla_req_file', 'number' => rawurlencode($request->number), 'key' => $key, 'file' => $file->token], admin_url('admin-ajax.php')),
                'image' => 0 === strpos($file->mime, 'image/'),
                'video' => 0 === strpos($file->mime, 'video/'),
                'mime'  => $file->mime,
                'size'  => size_format((int) $file->size),
            ];
            $files_by_event[(int) $file->event_id][] = $entry;
            if ('label' === $file->kind) {
                $labels[] = $entry;
            }
        }

        $timeline = [];
        foreach ($events as $event) {
            $meta = json_decode((string) $event->meta, true);
            $meta = is_array($meta) ? $meta : [];
            $mine = 'customer' === $event->actor_type;
            switch ($event->kind) {
                case 'created':
                    $title = $mine ? __('You opened this request', 'ffl-funnels-addons')
                        /* translators: %s: store name */
                        : sprintf(__('%s opened this request for you', 'ffl-funnels-addons'), $store);
                    break;
                case 'message':
                    /* translators: %s: store name */
                    $title = $mine ? __('You wrote', 'ffl-funnels-addons') : sprintf(__('%s replied', 'ffl-funnels-addons'), $store);
                    break;
                case 'status':
                    /* translators: %s: status */
                    $title = sprintf(__('Status: %s', 'ffl-funnels-addons'), FFLA_Requests::status_label((string) ($meta['to'] ?? ''), 'customer'));
                    break;
                case 'resolution':
                    /* translators: %s: outcome */
                    $title = sprintf(__('Closed — %s', 'ffl-funnels-addons'), $resolutions[$meta['resolution'] ?? ''] ?? '');
                    break;
                case 'reopen':
                    $title = __('Request reopened', 'ffl-funnels-addons');
                    break;
                case 'tracking':
                    $title = $mine ? __('You shipped the return', 'ffl-funnels-addons') : __('Return tracking added', 'ffl-funnels-addons');
                    break;
                case 'refund':
                    $title = __('Refund issued', 'ffl-funnels-addons');
                    break;
                default:
                    continue 2;
            }
            $timeline[] = [
                'who'   => $mine ? 'customer' : 'store',
                'kind'  => $event->kind,
                'title' => $title,
                'text'  => (string) $event->body,
                'time'  => FFLA_Requests::local_time($event->created_at),
                'files' => $files_by_event[(int) $event->id] ?? [],
            ];
        }

        $open = FFLA_Requests::is_open($request->status);
        $reasons = FFLA_Requests::reasons($request->type);
        $instructions = '';
        if ('return' === $request->type && in_array($request->status, ['approved', 'item_received'], true)) {
            $instructions = FFLA_Requests_Mail::fill((string) FFLA_Requests::setting('requests_return_instructions'), $request);
        }
        $can_reopen = !$open && FFLA_Requests::can_customer_reopen($request);

        return [
            'number'        => $request->number,
            'key'           => $key,
            'orderNumber'   => $request->order_number,
            'type'          => $request->type,
            'typeLabel'     => FFLA_Requests::type_label($request->type),
            'status'        => $request->status,
            'statusLabel'   => FFLA_Requests::status_label($request->status, 'customer'),
            'open'          => $open,
            'reason'        => $reasons[$request->reason] ?? $request->reason,
            'preferred'     => $request->preferred ? (FFLA_Requests::preferences()[$request->preferred] ?? '') : '',
            'created'       => FFLA_Requests::local_time($request->created_at),
            'updated'       => FFLA_Requests::local_time($request->updated_at),
            'items'         => array_map(static function ($line) {
                return ['name' => (string) $line['name'], 'qty' => (int) $line['qty'], 'firearm' => !empty($line['firearm']), 'fee' => (float) ($line['fee'] ?? 0)];
            }, FFLA_Requests::items($request)),
            'labels'        => $labels,
            'shipment'      => '' !== (string) $request->return_tracking ? [
                'carrier'  => FFLA_Requests::carriers()[$request->return_carrier] ?? $request->return_carrier,
                'tracking' => $request->return_tracking,
                'link'     => FFLA_Requests::tracking_link((string) $request->return_carrier, (string) $request->return_tracking),
            ] : null,
            'canTrack'      => self::can_track($request),
            'ffl'           => ($ffl = FFLA_Requests::ffl($request)) ? self::dealer_summary($ffl) : '',
            'refunded'      => (float) $request->refund_total > 0 ? self::plain_price((float) $request->refund_total, (string) (wc_get_order((int) $request->order_id) ? wc_get_order((int) $request->order_id)->get_currency() : get_woocommerce_currency())) : '',
            'rating'        => (int) $request->rating,
            'ratingComment' => (string) $request->rating_comment,
            'canRate'       => !$open && FFLA_Customer_Operations_Settings::enabled('requests_ratings') && !in_array($request->resolution, ['withdrawn', 'duplicate'], true),
            'firearmNotice' => $request->has_firearm ? FFLA_Requests_Mail::fill((string) FFLA_Requests::setting('requests_firearm_notice'), $request) : '',
            'instructions'  => $instructions,
            'resolution'    => $open ? null : [
                'label' => $resolutions[$request->resolution] ?? '',
                'note'  => (string) $request->resolution_note,
                'date'  => FFLA_Requests::local_time($request->closed_at),
            ],
            'steps'         => self::steps($request, $events),
            'timeline'      => $timeline,
            'canReply'      => $open || $can_reopen,
            'reopenNote'    => $can_reopen,
            'canWithdraw'   => self::can_withdraw($request),
            'uploads'       => FFLA_Customer_Operations_Settings::enabled('requests_uploads') && FFLA_Requests_Files::count((int) $request->id) < FFLA_Requests_Files::MAX_PER_REQUEST,
            'url'           => FFLA_Requests::tracking_url($request),
        ];
    }

    /**
     * Progress tracker: the normal path for the request type, marking steps
     * done / current / upcoming, and return steps skipped when a return was
     * closed before reaching them.
     */
    private static function steps($request, array $public_events): array
    {
        $flow = 'return' === $request->type
            ? ['new' => __('Received', 'ffl-funnels-addons'), 'in_review' => __('Under review', 'ffl-funnels-addons'), 'approved' => __('Approved', 'ffl-funnels-addons'), 'item_received' => __('Item received', 'ffl-funnels-addons'), 'closed' => __('Resolved', 'ffl-funnels-addons')]
            : ['new' => __('Received', 'ffl-funnels-addons'), 'in_review' => __('Under review', 'ffl-funnels-addons'), 'closed' => __('Resolved', 'ffl-funnels-addons')];

        $visited = ['new' => true];
        foreach ($public_events as $event) {
            if ('status' === $event->kind) {
                $meta = json_decode((string) $event->meta, true);
                if (!empty($meta['to'])) {
                    $visited[$meta['to']] = true;
                }
            }
        }

        $status = in_array($request->status, ['waiting_customer', 'waiting_carrier'], true) ? 'in_review' : $request->status;
        $keys = array_keys($flow);
        $current = array_search($status, $keys, true);
        $current = false === $current ? 0 : $current;

        $steps = [];
        foreach ($keys as $i => $key) {
            if ('closed' === $request->status) {
                $state = ('closed' === $key || 'new' === $key || isset($visited[$key]) || ('in_review' === $key)) ? 'done' : 'skipped';
            } else {
                $state = $i < $current ? 'done' : ($i === $current ? 'current' : 'todo');
            }
            $label = $flow[$key];
            if ('closed' === $key && 'closed' === $request->status && 'withdrawn' === $request->resolution) {
                $label = __('Cancelled', 'ffl-funnels-addons');
            }
            $steps[] = ['key' => $key, 'label' => $label, 'state' => $state];
        }
        return $steps;
    }

    /** Customers add return tracking while the return is approved. */
    private static function can_track($request): bool
    {
        return 'return' === $request->type && 'approved' === $request->status;
    }

    /** One-line dealer description: "Name · FFL 1-23-… · City, ST". */
    private static function dealer_summary(array $ffl): string
    {
        return implode(' · ', array_filter([
            (string) ($ffl['name'] ?? ''),
            'FFL ' . FFLA_Requests::format_license((string) ($ffl['license'] ?? '')),
            trim(($ffl['city'] ?? '') . ', ' . ($ffl['state'] ?? ''), ', '),
        ]));
    }

    private static function can_withdraw($request): bool
    {
        return in_array($request->status, ['new', 'in_review', 'waiting_customer', 'waiting_carrier', 'approved'], true);
    }

    /** Same order, type, reason and description in the last two minutes. */
    private static function recent_duplicate($order, array $data)
    {
        $rows = FFLA_Requests::for_order((int) $order->get_id());
        $last = $rows ? end($rows) : null;
        if (!$last || !FFLA_Requests::is_open($last->status) || $last->type !== $data['type'] || $last->reason !== $data['reason'] || strtotime($last->created_at . ' UTC') < time() - 120) {
            return null;
        }
        $events = FFLA_Requests::events((int) $last->id, true);
        return $events && (string) $events[0]->body === FFLA_Requests::clean_text($data['message']) ? $last : null;
    }

    private static function plain_price($amount, string $currency): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price((float) $amount, ['currency' => $currency])), ENT_QUOTES, 'UTF-8');
    }
}
