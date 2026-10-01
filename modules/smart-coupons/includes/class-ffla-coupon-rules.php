<?php
/**
 * Smart Coupons — validation rules.
 *
 * Conditions are checked whenever WooCommerce validates a coupon in the cart.
 * Facts the cart may not know yet (billing email, state, delivery and payment
 * method) are checked again when the order is placed.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Coupon_Rules
{
    const THROTTLE_WINDOW = 600;

    /** @var bool Prevent recursion while comparing discounts. */
    private static $comparing = false;

    /** @var array<string, string[]> Coupon being added => coupons it replaces (best discount wins). */
    private static $swaps = [];

    public static function boot(): void
    {
        add_filter('woocommerce_coupon_is_valid', [__CLASS__, 'validate'], 20, 3);
        add_action('woocommerce_after_checkout_validation', [__CLASS__, 'checkout_validation'], 20, 2);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [__CLASS__, 'store_api_validation'], 20, 2);
        add_filter('woocommerce_coupon_error', [__CLASS__, 'coupon_error'], 20, 3);
        add_action('woocommerce_applied_coupon', [__CLASS__, 'finish_swap'], 5);
    }

    /**
     * @param bool         $valid
     * @param WC_Coupon    $coupon
     * @param WC_Discounts $discounts
     * @return bool
     * @throws Exception With the message shown to the customer.
     */
    public static function validate($valid, $coupon, $discounts = null)
    {
        if (!$valid || !$coupon instanceof WC_Coupon) {
            return $valid;
        }
        $cart = $discounts instanceof WC_Discounts ? $discounts->get_object() : (function_exists('WC') ? WC()->cart : null);
        if (!$cart instanceof WC_Cart) {
            return $valid; // Orders edited in the admin keep WooCommerce's own checks.
        }

        if (self::throttled()) {
            throw new Exception(esc_html(self::throttle_message()), 429);
        }

        $o = FFLA_Coupon_Settings::coupon($coupon);

        if ('' !== $o['starts'] && wp_date('Y-m-d') < $o['starts']) {
            /* translators: %s: date */
            throw new Exception(esc_html(sprintf(__('This coupon starts on %s.', 'ffl-funnels-addons'), date_i18n(get_option('date_format'), strtotime($o['starts'])))), 100);
        }

        if ($o['roles']) {
            $user = wp_get_current_user();
            $roles = $user && $user->exists() ? (array) $user->roles : ['guest'];
            if (!array_intersect($roles, $o['roles'])) {
                throw new Exception(esc_html(in_array('guest', $roles, true)
                    ? __('Please sign in to use this coupon.', 'ffl-funnels-addons')
                    : __('This coupon is not available for your account.', 'ffl-funnels-addons')), 100);
            }
        }

        if ($o['first_order']) {
            $who = is_user_logged_in() ? get_current_user_id() : (function_exists('WC') && WC()->customer ? WC()->customer->get_billing_email() : '');
            if ($who && self::has_orders($who)) {
                throw new Exception(esc_html__('This coupon is only for your first order.', 'ffl-funnels-addons'), 100);
            }
        }

        if ($o['min_qty'] > 0) {
            $qty = 0;
            foreach ($cart->get_cart() as $item) {
                if (!$o['qty_cats'] || FFLA_Coupon_Settings::in_categories($item['data'], $o['qty_cats'])) {
                    $qty += (int) $item['quantity'];
                }
            }
            if ($qty < $o['min_qty']) {
                throw new Exception(esc_html(sprintf(
                    /* translators: %d: quantity */
                    _n('Add at least %d qualifying item to use this coupon.', 'Add at least %d qualifying items to use this coupon.', $o['min_qty'], 'ffl-funnels-addons'),
                    $o['min_qty']
                )), 100);
            }
        }

        // Nothing left to discount once firearms / protected items are set aside.
        if (!FFLA_Coupon_Discounts::allows_protected($coupon)) {
            $other = false;
            $protected = false;
            foreach ($cart->get_cart() as $item) {
                if (!empty($item['ffla_gift'])) {
                    continue;
                }
                if (FFLA_Coupon_Settings::is_protected($item['data'])) {
                    $protected = true;
                } else {
                    $other = true;
                }
            }
            if ($protected && !$other) {
                throw new Exception(esc_html__('Coupons cannot be used on firearms or the other items in your cart.', 'ffl-funnels-addons'), 100);
            }
        }

        if ($coupon->is_type(FFLA_Coupon_Discounts::TIERED)) {
            $t = FFLA_Coupon_Discounts::tier($coupon, $cart);
            if (!$t['tier']) {
                $lowest = $o['tiers'] ? $o['tiers'][0]['min'] : 0;
                /* translators: %s: amount */
                throw new Exception(esc_html(sprintf(__('Spend %s more on qualifying items to use this coupon.', 'ffl-funnels-addons'), html_entity_decode(wp_strip_all_tags(wc_price(max(0, $lowest - $t['subtotal']))), ENT_QUOTES, 'UTF-8'))), 100);
            }
        }
        if ($coupon->is_type(FFLA_Coupon_Discounts::BXGY) && FFLA_Coupon_Discounts::bxgy($coupon, $cart)['free'] < 1) {
            /* translators: 1: buy quantity, 2: get quantity */
            throw new Exception(esc_html(sprintf(__('Add %1$d qualifying items to get %2$d at the discount.', 'ffl-funnels-addons'), $o['buy_qty'] + ($o['get_cats'] || $o['get_products'] ? 0 : $o['get_qty']), $o['get_qty'])), 100);
        }

        $context = self::cart_context();
        self::check_context($o, $context);

        if ($o['per_customer'] > 0 && '' !== $context['email'] && self::uses_by_person($coupon->get_code(), $context) >= $o['per_customer']) {
            throw new Exception(esc_html__('This coupon was already used for your account or address.', 'ffl-funnels-addons'), 100);
        }

        self::check_stacking($coupon, $cart);

        return $valid;
    }

    /**
     * Delivery, state and payment method — only when known.
     *
     * @throws Exception
     */
    private static function check_context(array $o, array $c): void
    {
        if ('' !== $o['delivery'] && null !== $c['pickup']) {
            if ('pickup' === $o['delivery'] && !$c['pickup']) {
                throw new Exception(esc_html__('This coupon is for in-store pickup orders.', 'ffl-funnels-addons'), 100);
            }
            if ('shipping' === $o['delivery'] && $c['pickup']) {
                throw new Exception(esc_html__('This coupon is for shipped orders.', 'ffl-funnels-addons'), 100);
            }
        }
        if ($o['states'] && '' !== $c['state'] && !in_array(strtoupper($c['state']), $o['states'], true)) {
            throw new Exception(esc_html__('This coupon is not available in your state.', 'ffl-funnels-addons'), 100);
        }
        if ($o['payments'] && '' !== $c['payment'] && !in_array($c['payment'], $o['payments'], true)) {
            $gateways = function_exists('WC') && WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
            $names = array_map(static function ($id) use ($gateways) {
                return isset($gateways[$id]) ? wp_strip_all_tags($gateways[$id]->get_title()) : $id;
            }, $o['payments']);
            /* translators: %s: payment methods */
            throw new Exception(esc_html(sprintf(__('This coupon only works when paying with %s.', 'ffl-funnels-addons'), implode(', ', $names))), 100);
        }
    }

    /** What the cart knows so far about the buyer. */
    private static function cart_context(): array
    {
        $out = ['email' => '', 'phone' => '', 'address' => '', 'state' => '', 'pickup' => null, 'payment' => ''];
        if (!function_exists('WC') || !WC()->customer) {
            return $out;
        }
        $customer = WC()->customer;
        $user = wp_get_current_user();
        $out['email'] = (string) ($customer->get_billing_email() ?: ($user && $user->exists() ? $user->user_email : ''));
        $out['phone'] = (string) $customer->get_billing_phone();
        $out['address'] = self::address_key((string) $customer->get_shipping_address_1(), (string) $customer->get_shipping_postcode());
        $out['state'] = (string) ($customer->get_shipping_state() ?: $customer->get_billing_state());
        if (WC()->session) {
            $methods = (array) WC()->session->get('chosen_shipping_methods', []);
            $methods = array_filter($methods);
            if ($methods && WC()->cart && WC()->cart->needs_shipping()) {
                $out['pickup'] = self::methods_are_pickup($methods);
            }
            // Payment method is checked only when the order is placed: customers switch it freely.
        }
        return $out;
    }

    private static function methods_are_pickup(array $methods): bool
    {
        $pickup = true;
        foreach ($methods as $method) {
            if (false === strpos((string) $method, 'pickup')) {
                $pickup = false;
            }
        }
        return (bool) apply_filters('ffla_coupons_is_pickup', $pickup, $methods);
    }

    /** Checkout: everything the customer entered is known now. */
    public static function checkout_validation($data, $errors): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        $context = [
            'email'   => (string) ($data['billing_email'] ?? ''),
            'phone'   => (string) ($data['billing_phone'] ?? ''),
            'address' => self::address_key((string) (!empty($data['ship_to_different_address']) ? ($data['shipping_address_1'] ?? '') : ($data['billing_address_1'] ?? '')), (string) (!empty($data['ship_to_different_address']) ? ($data['shipping_postcode'] ?? '') : ($data['billing_postcode'] ?? ''))),
            'state'   => (string) (!empty($data['ship_to_different_address']) ? ($data['shipping_state'] ?? '') : ($data['billing_state'] ?? '')),
            'pickup'  => WC()->cart->needs_shipping() && !empty($data['shipping_method']) ? self::methods_are_pickup((array) $data['shipping_method']) : null,
            'payment' => (string) ($data['payment_method'] ?? ''),
        ];
        foreach (self::order_errors(WC()->cart->get_applied_coupons(), $context) as $message) {
            $errors->add('ffla_coupon', $message);
        }
    }

    /** Block checkout (Store API): same checks, from the order being placed. */
    public static function store_api_validation($order, $request): void
    {
        if (!$order instanceof WC_Order) {
            return;
        }
        $methods = [];
        foreach ($order->get_shipping_methods() as $method) {
            $methods[] = $method->get_method_id();
        }
        $context = [
            'email'   => (string) $order->get_billing_email(),
            'phone'   => (string) $order->get_billing_phone(),
            'address' => self::address_key((string) ($order->get_shipping_address_1() ?: $order->get_billing_address_1()), (string) ($order->get_shipping_postcode() ?: $order->get_billing_postcode())),
            'state'   => (string) ($order->get_shipping_state() ?: $order->get_billing_state()),
            'pickup'  => $methods ? self::methods_are_pickup($methods) : null,
            'payment' => is_object($request) && method_exists($request, 'get_param') ? (string) $request->get_param('payment_method') : (string) $order->get_payment_method(),
        ];
        $errors = self::order_errors($order->get_coupon_codes(), $context);
        if ($errors && class_exists('Automattic\WooCommerce\StoreApi\Exceptions\RouteException')) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('ffla_coupon', esc_html(implode(' ', $errors)), 400);
        }
    }

    /** @return string[] One message per coupon that fails now that the buyer is known. */
    private static function order_errors(array $codes, array $context): array
    {
        $errors = [];
        foreach ($codes as $code) {
            $coupon = new WC_Coupon($code);
            if (!$coupon->get_id()) {
                continue;
            }
            $o = FFLA_Coupon_Settings::coupon($coupon);
            try {
                self::check_context($o, $context);
                if ($o['first_order'] && '' !== $context['email'] && !is_user_logged_in() && self::has_orders($context['email'])) {
                    throw new Exception(__('This coupon is only for your first order.', 'ffl-funnels-addons'));
                }
                if ($o['per_customer'] > 0 && self::uses_by_person($coupon->get_code(), $context) >= $o['per_customer']) {
                    throw new Exception(__('This coupon was already used for your account or address.', 'ffl-funnels-addons'));
                }
            } catch (Exception $e) {
                /* translators: 1: coupon code, 2: reason */
                $errors[] = sprintf(__('Coupon "%1$s": %2$s Remove it or change your details.', 'ffl-funnels-addons'), strtoupper($code), wp_strip_all_tags(html_entity_decode($e->getMessage(), ENT_QUOTES, 'UTF-8')));
            }
        }
        return $errors;
    }

    /** Paid orders for a user ID or billing email. */
    public static function has_orders($who): bool
    {
        if (!function_exists('wc_get_orders') || !$who) {
            return false;
        }
        $ids = wc_get_orders([
            'customer' => $who,
            'status'   => array_map(static function ($s) { return 'wc-' . $s; }, wc_get_is_paid_statuses()),
            'limit'    => 1,
            'return'   => 'ids',
        ]);
        return !empty($ids);
    }

    /* ── Per-person limit ──────────────────────────────────────────────── */

    public static function normalize_email(string $email): string
    {
        $email = strtolower(trim($email));
        if (!strpos($email, '@')) {
            return $email;
        }
        [$local, $domain] = explode('@', $email, 2);
        $local = explode('+', $local)[0];
        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }
        return $local . '@' . $domain;
    }

    private static function phone_key(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        return strlen($digits) >= 7 ? substr($digits, -10) : '';
    }

    public static function address_key(string $line, string $postcode): string
    {
        $line = preg_replace('/[^a-z0-9]/', '', strtolower($line));
        $zip = substr(preg_replace('/[^0-9a-z]/', '', strtolower($postcode)), 0, 5);
        return '' !== $line && '' !== $zip ? $line . '|' . $zip : '';
    }

    /** How many past orders used this coupon for the same person (email, phone or address). */
    public static function uses_by_person(string $code, array $context): int
    {
        global $wpdb;
        $email = self::normalize_email($context['email']);
        $phone = self::phone_key($context['phone']);
        $address = $context['address'];
        if ('' === $email && '' === $phone && '' === $address) {
            return 0;
        }
        $order_ids = $wpdb->get_col($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_item_type = 'coupon' AND order_item_name = %s ORDER BY order_id DESC LIMIT 500",
            wc_format_coupon_code($code)
        ));
        $count = 0;
        foreach ($order_ids as $order_id) {
            $order = wc_get_order((int) $order_id);
            if (!$order || in_array($order->get_status(), ['cancelled', 'failed', 'trash', 'checkout-draft'], true)) {
                continue;
            }
            $same = ('' !== $email && self::normalize_email((string) $order->get_billing_email()) === $email)
                || ('' !== $phone && self::phone_key((string) $order->get_billing_phone()) === $phone)
                || ('' !== $address && self::address_key((string) ($order->get_shipping_address_1() ?: $order->get_billing_address_1()), (string) ($order->get_shipping_postcode() ?: $order->get_billing_postcode())) === $address);
            if ($same) {
                $count++;
            }
        }
        return $count;
    }

    /* ── Stacking ──────────────────────────────────────────────────────── */

    /**
     * A coupon is checked only against coupons applied before it, so two
     * conflicting coupons never knock each other out.
     *
     * @throws Exception
     */
    private static function check_stacking(WC_Coupon $coupon, WC_Cart $cart): void
    {
        if (self::$comparing) {
            return;
        }
        $code = $coupon->get_code();
        $applied = array_values($cart->get_applied_coupons()); // Keys can have gaps after a removal.
        $position = array_search($code, $applied, true);
        $earlier = false === $position ? $applied : array_slice($applied, 0, $position);

        $conflicts = [];
        foreach ($earlier as $other_code) {
            if ($other_code === $code) {
                continue;
            }
            $other = new WC_Coupon($other_code);
            if ($other->get_id() && !self::compatible($coupon, $other)) {
                $conflicts[] = $other;
            }
        }
        if (!$conflicts) {
            return;
        }

        // Only while the customer adds this coupon can we swap coupons out.
        if (false === $position && FFLA_Coupon_Settings::value('best_wins')) {
            $mine = self::discount_alone($coupon, $cart);
            $theirs = 0.0;
            foreach ($conflicts as $other) {
                $theirs += self::discount_alone($other, $cart);
            }
            if ($mine > $theirs) {
                // Swapped once the coupon is applied (classic and block carts save the list differently).
                self::$swaps[$code] = array_map(static function ($other) {
                    return $other->get_code();
                }, $conflicts);
                return;
            }
            /* translators: %s: coupon code */
            throw new Exception(esc_html(sprintf(__('Your coupon %s already gives a bigger discount, so we kept it.', 'ffl-funnels-addons'), strtoupper($conflicts[0]->get_code()))), 100);
        }

        /* translators: 1: coupon, 2: other coupon */
        throw new Exception(esc_html(sprintf(__('%1$s cannot be combined with %2$s.', 'ffl-funnels-addons'), strtoupper($code), strtoupper($conflicts[0]->get_code()))), 100);
    }

    public static function finish_swap($code): void
    {
        $code = wc_format_coupon_code((string) $code);
        if (empty(self::$swaps[$code]) || !function_exists('WC') || !WC()->cart) {
            return;
        }
        foreach (self::$swaps[$code] as $other) {
            WC()->cart->remove_coupon($other);
        }
        unset(self::$swaps[$code]);
        /* translators: %s: coupon code */
        wc_add_notice(sprintf(__('We kept the bigger discount: %s replaced your other coupon.', 'ffl-funnels-addons'), strtoupper($code)), 'notice');
    }

    private static function compatible(WC_Coupon $a, WC_Coupon $b): bool
    {
        if (FFLA_Coupon_Settings::is_credit($a) || FFLA_Coupon_Settings::is_credit($b)) {
            return true; // Store credit always combines.
        }
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            $o = FFLA_Coupon_Settings::coupon($x);
            if ('none' === $o['stack'] || ('only' === $o['stack'] && !in_array($y->get_code(), $o['stack_codes'], true))) {
                return false;
            }
        }
        return true;
    }

    /** What a coupon alone would take off the current cart. */
    public static function discount_alone(WC_Coupon $coupon, WC_Cart $cart): float
    {
        self::$comparing = true;
        FFLA_Coupon_Discounts::reset();
        try {
            $discounts = new WC_Discounts($cart);
            $discounts->apply_coupon($coupon, false);
            $total = array_sum($discounts->get_discounts_by_coupon());
        } catch (Throwable $e) {
            $total = 0;
        }
        FFLA_Coupon_Discounts::reset();
        self::$comparing = false;
        return (float) $total;
    }

    /* ── Guessing protection ───────────────────────────────────────────── */

    private static function throttle_key(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return 'ffla_cpn_fail_' . md5((string) apply_filters('ffla_coupons_client_ip', $ip));
    }

    public static function throttled(): bool
    {
        return FFLA_Coupon_Settings::value('throttle') && (int) get_transient(self::throttle_key()) >= (int) FFLA_Coupon_Settings::value('throttle_max');
    }

    private static function throttle_message(): string
    {
        return __('Too many coupon attempts. Please try again in 10 minutes.', 'ffl-funnels-addons');
    }

    /**
     * Count unknown codes; once throttled every coupon error reads the same,
     * so a guesser cannot tell a real code from a fake one.
     */
    public static function coupon_error($message, $code, $coupon)
    {
        // WooCommerce's "not applicable" when only firearms / protected items are in the cart.
        if (WC_Coupon::E_WC_COUPON_NOT_APPLICABLE === (int) $code && $coupon instanceof WC_Coupon && function_exists('WC') && WC()->cart
            && !FFLA_Coupon_Discounts::allows_protected($coupon)) {
            $protected = false;
            foreach (WC()->cart->get_cart() as $item) {
                if (!empty($item['ffla_gift'])) {
                    continue;
                }
                if (!FFLA_Coupon_Settings::is_protected($item['data'])) {
                    $protected = false;
                    break;
                }
                $protected = true;
            }
            if ($protected) {
                return __('Coupons cannot be used on firearms or the other items in your cart.', 'ffl-funnels-addons');
            }
        }
        if (!FFLA_Coupon_Settings::value('throttle')) {
            return $message;
        }
        if (self::throttled()) {
            return self::throttle_message();
        }
        if (WC_Coupon::E_WC_COUPON_NOT_EXIST === (int) $code) {
            $key = self::throttle_key();
            set_transient($key, (int) get_transient($key) + 1, self::THROTTLE_WINDOW);
        }
        return $message;
    }
}
