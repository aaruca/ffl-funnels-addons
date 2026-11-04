<?php
/**
 * Standalone regression harness for WooBooster's Apply Coupon engine.
 *
 * Run: php tests/smoke/woobooster-coupon-engine-smoke.php
 *
 * The cart stand-in follows WC_Cart where it matters here: calculate_totals()
 * resets the totals before `woocommerce_before_calculate_totals`, apply_coupon()
 * validates through WC_Discounts and adds an error notice when it refuses,
 * individual-use coupons remove or block the others, and applying or removing
 * a coupon recalculates the totals (priority 20). The classic checkout
 * refuses to create the order while an error notice exists; the per-customer
 * email check below mirrors WC_Cart::check_customer_coupons(). No live store
 * or database is used; staging should additionally run a real checkout.
 */

define('ABSPATH', __DIR__);

$checks = 0;
function check($test, $message)
{
    global $checks;
    $checks++;
    if (!$test) {
        throw new RuntimeException("FAIL: $message");
    }
}

/* ── WordPress / WooCommerce stand-ins ────────────────────────────────── */

$hooks = array();
function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    global $hooks;
    $hooks[$hook][$priority][] = array($callback, $args);
}
function add_action($hook, $callback, $priority = 10, $args = 1) { add_filter($hook, $callback, $priority, $args); }
function apply_filters($hook, $value, ...$args)
{
    global $hooks;
    $callbacks = isset($hooks[$hook]) ? $hooks[$hook] : array();
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as $entry) {
            $value = call_user_func_array($entry[0], array_slice(array_merge(array($value), $args), 0, $entry[1]));
        }
    }
    return $value;
}
function do_action($hook, ...$args)
{
    global $hooks;
    $callbacks = isset($hooks[$hook]) ? $hooks[$hook] : array();
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as $entry) {
            call_user_func_array($entry[0], array_slice($args, 0, $entry[1]));
        }
    }
}

function is_admin() { return false; }
function __($text, $domain = '') { return $text; }
function esc_html($text) { return (string) $text; }
function absint($value) { return abs((int) $value); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_email($email) { return trim((string) $email); }
function wp_json_encode($value) { return json_encode($value); }
function current_time($type, $gmt = 0) { return gmdate('Y-m-d H:i:s'); }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function get_current_user_id() { return $GLOBALS['user_id']; }
function wp_get_current_user() { return (object) array('ID' => $GLOBALS['user_id'], 'user_email' => $GLOBALS['user_email']); }
function get_post_status($id) { return isset($GLOBALS['coupon_defs'][$id]) ? $GLOBALS['coupon_defs'][$id]['status'] : false; }
function wc_coupons_enabled() { return true; }
function wc_format_coupon_code($code) { return strtolower(trim((string) $code)); }
function wc_strtolower($value) { return strtolower((string) $value); }
function get_object_taxonomies($type) { return array('product_cat'); }
function get_term_by($field, $value, $taxonomy) { return false; }
function term_is_ancestor_of($a, $b, $taxonomy) { return false; }
function wp_get_post_terms($product_id, $taxonomies, $args = array())
{
    $terms = array();
    foreach ($GLOBALS['products'][$product_id]['cats'] as $slug) {
        $terms[] = (object) array('taxonomy' => 'product_cat', 'slug' => $slug, 'term_id' => crc32($slug));
    }
    return $terms;
}
function wc_get_product($id)
{
    if (!isset($GLOBALS['products'][$id])) {
        return false;
    }
    return new class($GLOBALS['products'][$id]['price']) {
        private $price;
        public function __construct($price) { $this->price = $price; }
        public function get_price() { return $this->price; }
    };
}

// Notices live in the session, as in WooCommerce.
function wc_add_notice($message, $type = 'success', $data = array())
{
    $notices = WC()->session->get('wc_notices', array());
    if ('' !== (string) $message) {
        $notices[$type][] = array('notice' => $message, 'data' => $data);
    }
    WC()->session->set('wc_notices', $notices);
}
function wc_get_notices($type = '')
{
    $notices = WC()->session->get('wc_notices', array());
    if ('' === $type) {
        return $notices;
    }
    return isset($notices[$type]) ? $notices[$type] : array();
}
function wc_set_notices($notices) { WC()->session->set('wc_notices', $notices); }
function wc_has_notice($message, $type = 'success')
{
    foreach (wc_get_notices($type) as $notice) {
        if ($notice['notice'] === $message) {
            return true;
        }
    }
    return false;
}
function wc_notice_count($type = '') { return count(wc_get_notices($type)); }
function wc_clear_notices() { WC()->session->set('wc_notices', array()); }

function WC() { return $GLOBALS['wc']; }

class WP_Error
{
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}

class StubSession
{
    public $data = array();
    public function get($key, $default = null) { return array_key_exists($key, $this->data) ? $this->data[$key] : $default; }
    public function set($key, $value) { $this->data[$key] = $value; }
}

class StubCustomer
{
    public $billing_email = '';
    public function get_billing_email() { return $this->billing_email; }
    public function set_billing_email($email) { $this->billing_email = $email; }
}

class StubCouponStore
{
    public function get_usage_by_email($coupon, $email)
    {
        $used = $GLOBALS['coupon_defs'][$coupon->get_id()]['used_by_email'];
        return isset($used[$email]) ? $used[$email] : 0;
    }
}

class WC_Coupon
{
    const E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY = 104;
    const E_WC_COUPON_USAGE_LIMIT_REACHED = 106;

    private $def = null;
    private $error_message = '';

    public function __construct($id_or_code)
    {
        foreach ($GLOBALS['coupon_defs'] as $id => $def) {
            if ((is_int($id_or_code) && $id === $id_or_code) || (is_string($id_or_code) && strtolower($def['code']) === strtolower($id_or_code))) {
                $this->def = $def + array('id' => $id);
            }
        }
    }
    public function get_id() { return $this->def ? $this->def['id'] : 0; }
    public function get_code() { return $this->def ? $this->def['code'] : ''; }
    public function get_individual_use() { return !empty($this->def['individual_use']); }
    public function get_minimum_amount() { return (float) ($this->def['min'] ?? 0); }
    public function get_email_restrictions() { return $this->def['emails'] ?? array(); }
    public function get_usage_limit_per_user() { return (int) ($this->def['per_user'] ?? 0); }
    public function get_data_store() { return new StubCouponStore(); }
    public function get_date_modified() { return null; }
    public function get_date_expires() { return null; }
    public function get_usage_limit() { return 0; }
    public function get_usage_count() { return 0; }
    public function is_valid()
    {
        $valid = (new WC_Discounts(WC()->cart))->is_coupon_valid($this);
        if (is_wp_error($valid)) {
            $this->error_message = $valid->get_error_message();
            return false;
        }
        return $valid;
    }
    public function get_error_message() { return $this->error_message; }
    public function add_coupon_message($code)
    {
        $messages = array(
            self::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY => sprintf('Sorry, coupon "%s" has already been applied and cannot be used in conjunction with other coupons.', $this->get_code()),
            self::E_WC_COUPON_USAGE_LIMIT_REACHED => sprintf('Usage limit for coupon "%s" has been reached.', $this->get_code()),
        );
        if ($code < 200 && !wc_has_notice($messages[$code], 'error')) {
            wc_add_notice($messages[$code], 'error');
        }
    }
}

class WC_Discounts
{
    public static $calls = 0;
    private $cart;
    public function __construct($cart) { $this->cart = $cart; }
    public function is_coupon_valid($coupon)
    {
        self::$calls++;
        if (!$coupon->get_id()) {
            return new WP_Error('invalid_coupon', 'Coupon does not exist.');
        }
        if ($coupon->get_minimum_amount() > 0 && $coupon->get_minimum_amount() > $this->cart->get_displayed_subtotal()) {
            return new WP_Error('invalid_coupon', sprintf('The minimum spend for coupon "%s" is $%s.', $coupon->get_code(), $coupon->get_minimum_amount()));
        }
        $allowed = $coupon->get_email_restrictions();
        if ($allowed) {
            $emails = array_filter(array($GLOBALS['user_email'], $this->cart->get_customer()->get_billing_email()));
            if (!array_intersect($emails, $allowed)) {
                return new WP_Error('invalid_coupon', sprintf('Please enter a valid email to use coupon code "%s".', $coupon->get_code()));
            }
        }
        return true;
    }
}

class StubCart
{
    public $cart_contents = array();
    public $applied_coupons = array();
    public $totals = array('subtotal' => 0);
    public $customer;
    public $refuse_inside_apply = array();

    public function __construct() { $this->customer = new StubCustomer(); }
    public function get_cart() { return $this->cart_contents; }
    public function is_empty() { return empty($this->cart_contents); }
    public function get_customer() { return $this->customer; }
    public function get_subtotal() { return $this->totals['subtotal']; }
    public function get_displayed_subtotal() { return $this->get_subtotal(); }
    public function get_applied_coupons() { return $this->applied_coupons; }

    public function add($product_id, $qty = 1)
    {
        $key = 'line' . $product_id;
        if (isset($this->cart_contents[$key])) {
            $this->cart_contents[$key]['quantity'] += $qty;
        } else {
            $this->cart_contents[$key] = array('product_id' => $product_id, 'variation_id' => 0, 'quantity' => $qty);
        }
        $this->calculate_totals();
    }

    public function set_quantity($product_id, $qty)
    {
        $this->cart_contents['line' . $product_id]['quantity'] = $qty;
        $this->calculate_totals();
    }

    public function calculate_totals()
    {
        $this->totals = array('subtotal' => 0); // WC_Cart::reset_totals().
        if ($this->is_empty()) {
            return;
        }
        do_action('woocommerce_before_calculate_totals', $this);
        $subtotal = 0;
        foreach ($this->cart_contents as $item) {
            $subtotal += $GLOBALS['products'][$item['product_id']]['price'] * $item['quantity'];
        }
        $this->totals = array('subtotal' => $subtotal);
        do_action('woocommerce_after_calculate_totals', $this);
    }

    public function has_discount($code = '')
    {
        return in_array(wc_format_coupon_code($code), $this->applied_coupons, true);
    }

    public function apply_coupon($code)
    {
        $code = wc_format_coupon_code($code);
        $coupon = new WC_Coupon($code);
        if (!$coupon->is_valid()) {
            wc_add_notice($coupon->get_error_message(), 'error');
            return false;
        }
        if ($this->has_discount($code)) {
            return false;
        }
        if (in_array($code, $this->refuse_inside_apply, true)) {
            // Refused inside apply_coupon() after validation (e.g. by a plugin).
            wc_add_notice(sprintf('Coupon "%s" cannot be applied right now.', $code), 'error');
            return false;
        }
        if ($coupon->get_individual_use()) {
            $keep = apply_filters('woocommerce_apply_individual_use_coupon', array(), $coupon, $this->applied_coupons);
            foreach ($this->applied_coupons as $applied) {
                if (!in_array($applied, $keep, true)) {
                    $this->remove_coupon($applied);
                }
            }
        }
        foreach ($this->applied_coupons as $applied) {
            $other = new WC_Coupon($applied);
            if ($other->get_individual_use() && false === apply_filters('woocommerce_apply_with_individual_use_coupon', false, $coupon, $other, $this->applied_coupons)) {
                $other->add_coupon_message(WC_Coupon::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY);
                return false;
            }
        }
        $this->applied_coupons[] = $code;
        wc_add_notice('Coupon code applied successfully.', 'success');
        do_action('woocommerce_applied_coupon', $code);
        return true;
    }

    public function remove_coupon($code)
    {
        $code = wc_format_coupon_code($code);
        $this->applied_coupons = array_values(array_diff($this->applied_coupons, array($code)));
        do_action('woocommerce_removed_coupon', $code);
        return true;
    }

    public function empty_cart()
    {
        $this->cart_contents = array();
        $this->applied_coupons = array();
        do_action('woocommerce_cart_emptied', true);
    }

    // WC_Cart::check_customer_coupons(), the guest per-customer limit part.
    public function check_customer_coupons($posted)
    {
        foreach ($this->applied_coupons as $code) {
            $coupon = new WC_Coupon($code);
            $limit = $coupon->get_usage_limit_per_user();
            if ($limit > 0 && 0 === get_current_user_id()
                && $coupon->get_data_store()->get_usage_by_email($coupon, $posted['billing_email']) >= $limit) {
                $coupon->add_coupon_message(WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED);
            }
        }
    }
}

class StubWpdb
{
    public $prefix = 'wp_';
    public function get_results($sql)
    {
        // WooBooster_Coupon::get_coupon_rules(): active rules with an Apply Coupon action.
        return false !== strpos($sql, "action_source = 'apply_coupon'") ? array_values($GLOBALS['rules']) : array();
    }
}

class WooBooster_Rule
{
    public static function get_conditions($rule_id) { return $GLOBALS['conditions'][$rule_id] ?? array(); }
    public static function get_actions($rule_id) { return $GLOBALS['actions'][$rule_id] ?? array(); }
}

require dirname(__DIR__, 2) . '/modules/woobooster/includes/class-woobooster-coupon.php';

/* ── Store fixture ────────────────────────────────────────────────────── */

$products = array(
    11 => array('price' => 40.0, 'cats' => array('rifles')),
    12 => array('price' => 10.0, 'cats' => array('ammo')),
    13 => array('price' => 25.0, 'cats' => array('holsters')),
);

$coupon_defs = array(
    501 => array('code' => 'bulk10', 'status' => 'publish'),
    502 => array('code' => 'spend100', 'status' => 'publish', 'min' => 100),
    503 => array('code' => 'solo', 'status' => 'publish', 'individual_use' => true),
    504 => array('code' => 'once', 'status' => 'publish', 'per_user' => 1, 'used_by_email' => array('used@example.com' => 1)),
    505 => array('code' => 'vip', 'status' => 'publish', 'emails' => array('vip@example.com')),
    601 => array('code' => 'mine', 'status' => 'publish'),
    602 => array('code' => 'mysolo', 'status' => 'publish', 'individual_use' => true),
);

function cond($attribute, $value, $min_qty = 1)
{
    return (object) array(
        'condition_attribute' => $attribute,
        'condition_value'     => $value,
        'condition_operator'  => 'equals',
        'min_quantity'        => $min_qty,
        'include_children'    => 0,
    );
}

function coupon_action($coupon_id, $message = '')
{
    return (object) array('action_source' => 'apply_coupon', 'action_coupon_id' => $coupon_id, 'action_coupon_message' => $message);
}

/**
 * A fresh store: one coupon rule per entry ($coupon_id => condition), an
 * empty cart and session, and the engine and WooCommerce's own cart hooks
 * registered as on a real request.
 */
function fresh_store(array $rule_defs)
{
    $GLOBALS['rules'] = array();
    $GLOBALS['conditions'] = array();
    $GLOBALS['actions'] = array();
    $id = 0;
    foreach ($rule_defs as $coupon_id => $def) {
        $id++;
        $GLOBALS['rules'][$id] = (object) array('id' => $id, 'priority' => 10, 'status' => 1, 'start_date' => null, 'end_date' => null);
        $GLOBALS['conditions'][$id] = array(0 => array($def[0]));
        $GLOBALS['actions'][$id] = array(0 => array(coupon_action($coupon_id, $def[1] ?? '')));
    }
    $GLOBALS['user_id'] = 0;
    $GLOBALS['user_email'] = '';
    $GLOBALS['wc'] = (object) array('session' => new StubSession(), 'cart' => new StubCart());
    WC_Discounts::$calls = 0;
    new_request();
}

/** Same session and cart, new PHP request: hooks and per-request state rebuilt. */
function new_request()
{
    $GLOBALS['hooks'] = array();
    $cart = WC()->cart;
    add_action('woocommerce_applied_coupon', array($cart, 'calculate_totals'), 20, 0);
    add_action('woocommerce_removed_coupon', array($cart, 'calculate_totals'), 20, 0);
    add_action('woocommerce_after_checkout_validation', array($cart, 'check_customer_coupons'), 1);
    $GLOBALS['engine'] = new WooBooster_Coupon();
    $GLOBALS['engine']->init();
}

function applied() { return WC()->cart->get_applied_coupons(); }
function errors() { return array_column(wc_get_notices('error'), 'notice'); }
function infos() { return array_column(wc_get_notices('notice'), 'notice'); }
function successes() { return array_column(wc_get_notices('success'), 'notice'); }
function tracked() { return array_column(WC()->session->get(WooBooster_Coupon::SESSION_KEY, array()), 'code'); }

$wpdb = new StubWpdb();

/* ── 1. Minimum spend not met: not applied, no error notice ───────────── */

fresh_store(array(502 => array(cond('specific_product', '11'))));
WC()->cart->add(11); // $40, the coupon needs $100.
check(array() === applied(), 'a coupon whose minimum spend is not met is not applied');
check(array() === errors(), 'refusing an automatic coupon leaves no error notice (checkout would refuse the order)');
check(1 === WC_Discounts::$calls, 'the coupon is checked once');

/* ── 2. The refusal is remembered until the cart changes ──────────────── */

WC()->cart->calculate_totals();
WC()->cart->calculate_totals();
new_request();
WC()->cart->calculate_totals(); // e.g. the checkout page, the next request.
check(1 === WC_Discounts::$calls, 'the refusal is remembered: not retried on every totals calculation or request');
check(array() === errors(), 'still no error notice');

WC()->cart->set_quantity(11, 3); // $120 now.
check(array('spend100') === applied(), 'once the cart reaches the minimum spend the coupon is applied');
check(array('spend100') === tracked(), 'and tracked as automatic');
check(in_array('Coupon "SPEND100" has been automatically applied based on your cart!', successes(), true), 'with the default success message');
check(!in_array('Coupon code applied successfully.', successes(), true), "WooCommerce's own coupon message is replaced");

wc_clear_notices();
WC()->cart->set_quantity(11, 1); // Back under $100; the rule still matches.
check(array() === applied() && array() === tracked(), 'an automatic coupon that stops being valid is removed');
check(array() === errors(), 'without an error notice');
check(array('Coupon "SPEND100" no longer applies and was removed.') === infos(), 'with a short informational notice');
$calls = WC_Discounts::$calls;
WC()->cart->calculate_totals();
check($calls === WC_Discounts::$calls, 'and not retried until the cart changes again');

/* ── 3. Existing behaviour: applied while matching, removed after ─────── */

fresh_store(array(501 => array(cond('__store_all', '1', 2), 'Buy 2, save 10%!')));
WC()->cart->add(12);
check(array() === applied(), 'not applied before the cart matches (Min. in cart 2)');
WC()->cart->set_quantity(12, 2);
check(array('bulk10') === applied(), 'applied once the cart matches');
check(in_array('Buy 2, save 10%!', successes(), true), 'the custom cart message is shown');
WC()->cart->set_quantity(12, 1);
check(array() === applied() && array() === tracked(), 'removed when the cart stops matching');
check(array() === errors() && array() === infos(), 'quietly, as before');

/* ── 4. Individual use never removes or blocks the shopper's coupons ──── */

// Our coupon is individual use; the shopper already applied their own.
fresh_store(array(503 => array(cond('__store_all', '1'))));
WC()->cart->add(12);
check(array('solo') === applied(), 'an individual-use automatic coupon applies to a cart without other coupons');
WC()->cart->empty_cart();
WC()->cart->cart_contents = array('line12' => array('product_id' => 12, 'variation_id' => 0, 'quantity' => 1));
WC()->cart->applied_coupons = array('mine');
wc_clear_notices();
WC()->cart->calculate_totals();
check(array('mine') === applied(), "an individual-use automatic coupon never removes the shopper's coupon");
check(array() === errors(), 'and adds no error');

// The shopper's coupon is individual use.
fresh_store(array(501 => array(cond('__store_all', '1'))));
WC()->cart->cart_contents = array('line12' => array('product_id' => 12, 'variation_id' => 0, 'quantity' => 1));
WC()->cart->applied_coupons = array('mysolo');
WC()->cart->calculate_totals();
check(array('mysolo') === applied(), "the shopper's individual-use coupon stays alone");
check(array() === errors(), 'no "cannot be used in conjunction" error from the automatic coupon');

// Pushed out by an individual-use coupon the shopper applies: back once it goes.
fresh_store(array(501 => array(cond('__store_all', '1'))));
WC()->cart->add(12);
check(array('bulk10') === applied(), 'automatic coupon applied');
wc_clear_notices();
check(true === WC()->cart->apply_coupon('mysolo'), 'the shopper applies an individual-use coupon');
check(array('mysolo') === applied(), 'WooCommerce removes the automatic coupon for it');
WC()->cart->calculate_totals();
check(array('mysolo') === applied() && array() === errors(), 'the automatic coupon waits quietly while it is there');
WC()->cart->remove_coupon('mysolo');
check(array('bulk10') === applied(), 'and comes back once the shopper removes it');

/* ── 5. Removed by the shopper: not applied again ─────────────────────── */

fresh_store(array(501 => array(cond('__store_all', '1'))));
WC()->cart->add(12);
check(array('bulk10') === applied(), 'automatic coupon applied');
WC()->cart->remove_coupon('bulk10'); // The cart's [Remove] link; WooCommerce recalculates.
check(array() === applied() && array() === tracked(), 'a coupon the shopper removes stays removed');
new_request();
WC()->cart->calculate_totals();
WC()->cart->set_quantity(12, 3);
check(array() === applied(), 'also on later requests and after a quantity change');
WC()->cart->add(13);
check(array('bulk10') === applied(), 'adding another product applies it again');
WC()->cart->remove_coupon('bulk10');
WC()->cart->empty_cart();
WC()->cart->add(12);
check(array('bulk10') === applied(), 'and so does emptying the cart');

/* ── 6. Refused inside apply_coupon(): only its own notices go ────────── */

fresh_store(array(501 => array(cond('__store_all', '1'))));
WC()->cart->refuse_inside_apply = array('bulk10');
wc_add_notice('Please choose a shipping method.', 'error');
WC()->cart->add(12);
check(array() === applied(), 'not applied when apply_coupon() refuses it');
check(array('Please choose a shipping method.') === errors(), "the notice apply_coupon() added is removed, other errors are kept");
WC()->cart->calculate_totals();
check(array('Please choose a shipping method.') === errors(), 'and it is not retried on the next calculation');

/* ── 7. Checkout: a billing email that may not use the coupon ─────────── */

// Guest whose billing email has used up the per-customer limit.
fresh_store(array(504 => array(cond('__store_all', '1'))));
WC()->cart->add(12);
check(array('once') === applied(), 'per-customer coupon applied for a guest');
wc_clear_notices();
// Place order: WC_Checkout::update_session() sets the email and recalculates,
// then validation runs.
WC()->cart->customer->set_billing_email('used@example.com');
WC()->cart->calculate_totals();
do_action('woocommerce_after_checkout_validation', array('billing_email' => 'used@example.com'), null);
check(array() === applied(), 'the coupon is removed at checkout');
check(0 === wc_notice_count('error'), 'with no error notice, so the order can be placed');
check(array('Coupon "ONCE" no longer applies and was removed.') === infos(), 'the shopper gets a short informational notice');
wc_clear_notices();
new_request();
WC()->cart->calculate_totals();
do_action('woocommerce_after_checkout_validation', array('billing_email' => 'used@example.com'), null);
check(array() === applied() && 0 === wc_notice_count('error'), 'a second Place order does not apply it again');
WC()->cart->customer->set_billing_email('new@example.com');
WC()->cart->calculate_totals();
check(array('once') === applied(), 'a different billing email gets it back');

// Allowed emails: WooCommerce's check sees the billing email in the totals pass.
fresh_store(array(505 => array(cond('__store_all', '1'))));
WC()->cart->customer->set_billing_email('vip@example.com');
WC()->cart->add(12);
check(array('vip') === applied(), 'email-restricted coupon applied for an allowed email');
wc_clear_notices();
WC()->cart->customer->set_billing_email('someone@example.com');
WC()->cart->calculate_totals();
do_action('woocommerce_after_checkout_validation', array('billing_email' => 'someone@example.com'), null);
check(array() === applied() && 0 === wc_notice_count('error'), 'removed for an email it does not allow, without an error');
check(1 === count(infos()), 'one informational notice');

// Block checkout (Store API) checks before building the order.
fresh_store(array(504 => array(cond('__store_all', '1'))));
WC()->cart->add(12);
WC()->cart->customer->set_billing_email('used@example.com');
do_action('woocommerce_store_api_checkout_update_customer_from_request', WC()->cart->customer, null);
check(array() === applied() && 0 === wc_notice_count('error'), 'the Store API checkout drops it before the order is built');

echo $checks . " checks passed (woobooster coupon engine).\n";
