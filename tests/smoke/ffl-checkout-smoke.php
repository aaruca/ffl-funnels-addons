<?php
/**
 * Standalone regression harness for the FFL Checkout module (PHP side).
 *
 * Run: php tests/smoke/ffl-checkout-smoke.php
 *
 * Covers: the vendor selector shortcode (setting off, no output-buffer leak,
 * type-safe pre-selection, nothing pre-checked when the cart has no known
 * vendor, store currency), the vendor AJAX/cart/order hooks only existing
 * while the selector is on, the borrowed Mapbox token cache being tied to the
 * g-FFL key (and flushed when it changes), and the dealer finder rendering
 * once per page without page-level `let` declarations.
 */

define('ABSPATH', __DIR__);
define('FFLA_PATH', dirname(__DIR__, 2) . '/');
define('FFLA_URL', 'https://example.test/wp-content/plugins/ffl-funnels-addons/');
define('FFLA_VERSION', 'test');
define('MINUTE_IN_SECONDS', 60);

$checks = 0;
function check($test, $message)
{
    global $checks;
    $checks++;
    if (!$test) {
        throw new RuntimeException("FAIL: $message");
    }
}

/* ── WordPress / WooCommerce stubs ─────────────────────────────────────── */

$GLOBALS['options']    = [];
$GLOBALS['meta']       = [];
$GLOBALS['transients'] = [];
$GLOBALS['hooks']      = [];
$GLOBALS['shortcodes'] = [];
$GLOBALS['remote']     = [];   // queued responses for wp_safe_remote_post / wp_remote_post
$GLOBALS['requests']   = 0;

class WP_Error
{
    private $message;
    public function __construct($code = '', $message = '') { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function __($t, $d = null) { return $t; }
function esc_html__($t, $d = null) { return htmlspecialchars($t, ENT_QUOTES); }
function esc_html_e($t, $d = null) { echo htmlspecialchars($t, ENT_QUOTES); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function wp_kses_post($t) { return (string) $t; }
function checked($checked, $current = true, $echo = true)
{
    $out = ((string) $checked === (string) $current) ? " checked='checked'" : '';
    if ($echo) {
        echo $out;
    }
    return $out;
}
function wc_price($price) { return '<span class="amount">&#36;' . number_format((float) $price, 2) . '</span>'; }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['options']) ? $GLOBALS['options'][$k] : $d; }
function update_option($k, $v)
{
    $GLOBALS['options'][$k] = $v;
    foreach ($GLOBALS['hooks']['update_option_' . $k] ?? [] as $cb) {
        call_user_func($cb);
    }
    return true;
}
function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][(int) $id][$k] ?? ''; }
function get_transient($k) { return array_key_exists($k, $GLOBALS['transients']) ? $GLOBALS['transients'][$k] : false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; return true; }
function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; return true; }
function add_shortcode($tag, $cb) { $GLOBALS['shortcodes'][$tag] = $cb; }
function get_site_url() { return 'https://shop.example.test'; }
function home_url() { return 'https://shop.example.test'; }
function admin_url($p = '') { return 'https://shop.example.test/wp-admin/' . $p; }
function get_current_user_id() { return 0; }
function get_user_meta($id, $k, $single = false) { return ''; }
function wp_get_active_and_valid_plugins() { return []; }
function plugin_dir_url($f) { return ''; }
function get_term($id, $tax = '') { return (object) ['name' => (string) ($GLOBALS['terms'][$id] ?? '')]; }
function fake_remote()
{
    $GLOBALS['requests']++;
    return array_shift($GLOBALS['remote']) ?? ['code' => 500, 'body' => ''];
}
function wp_safe_remote_post($url, $args = []) { return fake_remote(); }
function wp_remote_post($url, $args = []) { return fake_remote(); }
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

class Fake_Attribute
{
    private $name;
    private $options;
    public function __construct($name, $options) { $this->name = $name; $this->options = $options; }
    public function get_name() { return $this->name; }
    public function get_options() { return $this->options; }
}
class Fake_Product
{
    public $id;
    public $sku;
    public $attributes;
    public $meta = [];
    public function __construct($id, $sku, $attributes) { $this->id = $id; $this->sku = $sku; $this->attributes = $attributes; }
    public function get_id() { return $this->id; }
    public function get_name() { return 'Product ' . $this->id; }
    public function get_sku() { return $this->sku; }
    public function get_attributes() { return $this->attributes; }
    public function get_parent_id() { return 0; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
}
$GLOBALS['wc_products'] = [];
function wc_get_product($id) { return $GLOBALS['wc_products'][(int) $id] ?? false; }

class Fake_Cart
{
    public $items = [];
    public function get_cart() { return $this->items; }
}
class Fake_WC
{
    public $cart;
    public function __construct() { $this->cart = new Fake_Cart(); }
}
$GLOBALS['wc'] = new Fake_WC();
function WC() { return $GLOBALS['wc']; }

require FFLA_PATH . 'modules/ffl-checkout/includes/class-ffl-checkout-mapbox.php';
require FFLA_PATH . 'modules/ffl-checkout/includes/class-ffl-checkout-vendor-api.php';
require FFLA_PATH . 'modules/ffl-checkout/includes/class-ffl-checkout-ajax.php';
require FFLA_PATH . 'modules/ffl-checkout/includes/class-ffl-checkout-vendor-cart.php';
require FFLA_PATH . 'modules/ffl-checkout/includes/class-ffl-checkout-vendor-shortcode.php';
require FFLA_PATH . 'modules/ffl-checkout/includes/class-ffl-checkout-dealer-bridge.php';

/* ── 1. Vendor hooks only exist while the selector is on ──────────────── */

$GLOBALS['options']['ffl_checkout_settings'] = ['vendor_selector_enabled' => '0'];
FFL_Checkout_Ajax::init();
FFL_Checkout_Vendor_Cart::init();
check(empty($GLOBALS['hooks']['wp_ajax_nopriv_ffl_update_cart_vendor']), 'vendor AJAX endpoint is off with the setting off');
check(empty($GLOBALS['hooks']['woocommerce_get_cart_item_from_session']), 'vendor cart hook is off with the setting off');
check(empty($GLOBALS['hooks']['woocommerce_checkout_create_order_line_item']), 'vendor order hook is off with the setting off');

$GLOBALS['options']['ffl_checkout_settings'] = ['vendor_selector_enabled' => '1'];
FFL_Checkout_Ajax::init();
FFL_Checkout_Vendor_Cart::init();
check(!empty($GLOBALS['hooks']['wp_ajax_nopriv_ffl_update_cart_vendor']), 'vendor AJAX endpoint is on with the setting on');
check(!empty($GLOBALS['hooks']['woocommerce_get_cart_item_from_session']), 'vendor cart hook is on with the setting on');
check(!method_exists('FFL_Checkout_Ajax', 'get_mapbox_token') && !method_exists('FFL_Checkout_Ajax', 'get_vendor_options'), 'unused AJAX handlers are gone');

/* ── 2. Vendor selector shortcode ─────────────────────────────────────── */

$GLOBALS['options']['g_ffl_cockpit_key'] = 'cockpit-key';
$GLOBALS['terms'][501] = '012345678905';
$GLOBALS['wc_products'][41] = new Fake_Product(41, 'D2|ABC', [new Fake_Attribute('pa_upc', [501])]);
$GLOBALS['meta'][41]['automated_listing'] = '1';
$GLOBALS['wc_products'][42] = new Fake_Product(42, 'X', []);

// API answers with numeric IDs (JSON-decoded), the session holds a string.
$vendor_options = [
    ['warehouse_id' => 7, 'distid' => 'D1', 'sku' => 'S7', 'price' => 499.99, 'qty' => 3, 'shipping_class' => 'ground'],
    ['warehouse_id' => 9, 'distid' => 'D2', 'sku' => 'S9', 'price' => 489.5, 'qty' => 1, 'shipping_class' => 'ground'],
];
$GLOBALS['transients']['ffl_vendor_opts_' . md5('012345678905')] = $vendor_options;

$level = ob_get_level();
WC()->cart->items = ['k42' => ['product_id' => 42]];
check(FFL_Checkout_Vendor_Shortcode::render() === '', 'no eligible item renders nothing');
check(ob_get_level() === $level, 'no output buffer is left open when nothing renders');

WC()->cart->items = ['k41' => ['product_id' => 41, 'custom_product_option' => '9']];
$html = FFL_Checkout_Vendor_Shortcode::render();
check(ob_get_level() === $level, 'no output buffer is left open after rendering');
check(substr_count($html, "checked='checked'") === 1, 'exactly one vendor is checked');
check((bool) preg_match('/value="9"[^>]*checked=/s', $html), 'numeric vendor ID from the API matches the string in the session');
check(strpos($html, '<script') === false, 'no script pre-selects a vendor the cart does not have');
check(strpos($html, 'class="amount"') !== false, 'prices use the store currency format');
check(strpos($html, 'Vendor 9') !== false, 'vendor label is shown');

WC()->cart->items = ['k41' => ['product_id' => 41]];
$html = FFL_Checkout_Vendor_Shortcode::render();
check((bool) preg_match('/value="9"[^>]*checked=/s', $html) && substr_count($html, "checked='checked'") === 1, 'without a chosen vendor, the SKU distributor is pre-selected');

$GLOBALS['wc_products'][41]->sku = 'D5|ABC';
$html = FFL_Checkout_Vendor_Shortcode::render();
check(strpos($html, "checked='checked'") === false, 'nothing is checked when no option matches the cart');

$GLOBALS['options']['ffl_checkout_settings'] = ['vendor_selector_enabled' => '0'];
check(FFL_Checkout_Vendor_Shortcode::render() === '', 'shortcode renders nothing with the setting off');
$GLOBALS['options']['ffl_checkout_settings'] = ['vendor_selector_enabled' => '1'];

/* ── 3. Borrowed Mapbox token follows the g-FFL key ───────────────────── */

FFL_Checkout_Mapbox::init();
$GLOBALS['options']['ffl_checkout_settings'] = ['mapbox_public_token' => ''];
$GLOBALS['options']['ffl_api_key_option']    = 'key-A';
$GLOBALS['transients']['ffla_borrowed_mapbox_token'] = 'pk.OLD-STRING-CACHE';
$GLOBALS['remote'][] = ['code' => 200, 'body' => json_encode(['token' => 'pk.A'])];
check(FFL_Checkout_Mapbox::resolve_token() === 'pk.A', 'an old cache entry without a key is refreshed');
check($GLOBALS['requests'] === 1, 'one request for the first borrow');
check(FFL_Checkout_Mapbox::resolve_token() === 'pk.A' && $GLOBALS['requests'] === 1, 'borrowed token is cached');

// Key changed directly in the database (no option hook): never reuse pk.A.
$GLOBALS['options']['ffl_api_key_option'] = 'key-B';
$GLOBALS['remote'][] = ['code' => 200, 'body' => json_encode('pk.B')];
check(FFL_Checkout_Mapbox::resolve_token() === 'pk.B', 'a token borrowed with another key is not reused');
check($GLOBALS['requests'] === 2, 'new key triggers a new borrow');

// Failure back-off belongs to the key that failed.
$GLOBALS['options']['ffl_api_key_option'] = 'key-C';
$GLOBALS['remote'][] = ['code' => 500, 'body' => ''];
check(FFL_Checkout_Mapbox::resolve_token() === '' && $GLOBALS['requests'] === 3, 'failed borrow returns no token');
check(FFL_Checkout_Mapbox::resolve_token() === '' && $GLOBALS['requests'] === 3, 'failed borrow is not retried within the back-off');
$GLOBALS['remote'][] = ['code' => 200, 'body' => json_encode(['access_token' => 'pk.D'])];
update_option('ffl_api_key_option', 'key-D');
check(!isset($GLOBALS['transients']['ffla_borrowed_mapbox_token_fail']), 'saving the g-FFL key clears the back-off');
check(FFL_Checkout_Mapbox::resolve_token() === 'pk.D', 'a new key is tried right away');
check(strpos(json_encode($GLOBALS['transients']), 'key-D') === false, 'the g-FFL key itself is never cached');

$GLOBALS['options']['ffl_checkout_settings'] = ['mapbox_public_token' => 'pk.OWN', 'vendor_selector_enabled' => '1'];
check(FFL_Checkout_Mapbox::resolve_token() === 'pk.OWN', 'own token always wins');

/* ── 4. Dealer finder: once per page, no page-level `let` ─────────────── */

define('G_FFL_API_VERSION', 'test');
function order_requires_ffl_selector() { return true; }
$GLOBALS['options']['ffl_api_key_option'] = 'gffl-key';
$GLOBALS['options']['ffl_checkout_message'] = '<b>Ship to an FFL</b></script><script>alert(1)</script>';

$first  = FFL_Checkout_Dealer_Bridge::render();
$second = FFL_Checkout_Dealer_Bridge::render();
check(strpos($first, 'data-ffla-dealer-finder="1"') !== false, 'dealer finder renders its container');
check($second === '', 'a second dealer finder on the same page renders nothing');
check(!preg_match('/\blet\s+\w+\s*=/', $first), 'no page-level let declarations that clash with g-FFL Checkout');
check(strpos($first, 'window[name] = config[name]') !== false, 'settings are exposed as window properties');
check(strpos($first, '</script><script>alert(1)') === false, 'message HTML cannot close the script tag');
check(strpos($first, 'initFFLJs') !== false, 'g-FFL Checkout widget is started');

echo "FFL Checkout smoke checks passed ($checks checks).\n";
