<?php
/**
 * Standalone regression harness for WooBooster bundle cart lines.
 *
 * Run: php tests/smoke/woobooster-bundle-cart-smoke.php
 *
 * Drives the real add-to-cart AJAX handler, cart hooks and Bundle element
 * against in-memory WordPress / WooCommerce stand-ins (no database). Covers:
 *  - one cart line per bundle item, each at its discounted unit price and
 *    quantity; a firearm that is not the first item still gets its own line
 *    (so the FFL dealer step sees it);
 *  - woocommerce_add_to_cart_validation runs per item; a refused or failing
 *    required item adds nothing (lines already added are rolled back);
 *  - Conditions (User Role included) and the source product are checked on
 *    the server; source 0 keeps every item;
 *  - the viewed product stays in the bundle when it is a static item, and is
 *    required unless Optional;
 *  - the widget's signed item list pins dynamic items; a tampered list is
 *    rejected and the items are worked out again;
 *  - guests on cached pages: an expired nonce is accepted with a valid signed
 *    list; logged-in shoppers still need the nonce;
 *  - variable products priced at the variation that is added;
 *  - percentage capped at 100;
 *  - removing one line removes the group, undo restores it, quantities are
 *    locked, coupons excluding sale items skip bundle lines;
 *  - carts saved by the old single-line format keep their price.
 *
 * Set WB_SMOKE_SOFT=1 to run every scenario and list failures instead of
 * stopping at the first; WB_MODULE_DIR points at another checkout's
 * modules/woobooster (e.g. to see which checks the previous code fails).
 */

define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('DOING_AJAX', true);
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');

$checks   = 0;
$failures = array();
$soft     = '1' === getenv('WB_SMOKE_SOFT');

function check($test, $message)
{
    global $checks, $failures, $soft;
    $checks++;
    if (!$test) {
        if ($soft) {
            $failures[] = $message;
            return;
        }
        throw new RuntimeException("FAIL: $message");
    }
}

function near($a, $b, $tolerance = 0.011)
{
    return abs((float) $a - (float) $b) <= $tolerance;
}

/* ── Hooks ─────────────────────────────────────────────────────────────── */

$GLOBALS['wb_hooks'] = array();
function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['wb_hooks'][$hook][$priority][] = array($callback, $args);
    return true;
}
function add_action($hook, $callback, $priority = 10, $args = 1)
{
    return add_filter($hook, $callback, $priority, $args);
}
function remove_all_filters($hook)
{
    unset($GLOBALS['wb_hooks'][$hook]);
}
function apply_filters($hook, $value, ...$args)
{
    $callbacks = isset($GLOBALS['wb_hooks'][$hook]) ? $GLOBALS['wb_hooks'][$hook] : array();
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
    $callbacks = isset($GLOBALS['wb_hooks'][$hook]) ? $GLOBALS['wb_hooks'][$hook] : array();
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as $entry) {
            call_user_func_array($entry[0], array_slice($args, 0, $entry[1]));
        }
    }
}

/* ── WordPress stand-ins ───────────────────────────────────────────────── */

function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_attr__($text, $domain = '') { return esc_attr($text); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function wp_kses_post($s) { return (string) $s; }
function absint($v) { return abs((int) $v); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function wp_salt($scheme = 'auth') { return 'smoke-salt-' . $scheme; }
function wp_generate_password($length = 12, $special = true, $extra = false) { return substr(bin2hex(random_bytes($length)), 0, $length); }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function is_admin() { return false; }
function wp_doing_ajax() { return true; }
function nocache_headers() {}
function get_option($key, $default = false) { return $default; }
function wp_cache_get($key, $group = '') { return false; }
function wp_cache_set($key, $value, $group = '', $ttl = 0) { return true; }
function get_term_by($field, $value, $taxonomy) { return false; }
function term_is_ancestor_of($a, $b, $tax) { return false; }
function is_wp_error($v) { return false; }
function is_singular($type = '') { return false; }
function get_the_ID() { return 0; }
function wp_get_attachment_image($id, $size = '', $icon = false, $attr = array()) { return '<img class="cover" data-id="' . (int) $id . '">'; }

$GLOBALS['wb_user'] = null; // null = guest, or array of roles.
function is_user_logged_in() { return null !== $GLOBALS['wb_user']; }
function wp_get_current_user() { return (object) array('ID' => $GLOBALS['wb_user'] ? 7 : 0, 'roles' => (array) $GLOBALS['wb_user']); }
function wp_create_nonce($action = -1) { return 'nonce-' . $action . '-' . ($GLOBALS['wb_user'] ? 7 : 0); }
function wp_verify_nonce($nonce, $action = -1) { return hash_equals(wp_create_nonce($action), (string) $nonce) ? 1 : false; }

class JsonResponse extends Exception
{
    public $success;
    public $data;
    public $status;
    public function __construct($success, $data, $status)
    {
        parent::__construct('json');
        $this->success = $success;
        $this->data    = $data;
        $this->status  = $status;
    }
}
function wp_send_json_success($data = null, $status = null) { throw new JsonResponse(true, $data, $status ?: 200); }
function wp_send_json_error($data = null, $status = null) { throw new JsonResponse(false, $data, $status ?: 200); }
// Used by the previous implementation (dies with -1 / HTTP 403).
function check_ajax_referer($action, $arg = false)
{
    if (!wp_verify_nonce(isset($_REQUEST[$arg]) ? $_REQUEST[$arg] : '', $action)) {
        throw new JsonResponse(false, -1, 403);
    }
    return 1;
}

/* ── Database stand-in (bundle tables + term lookups) ─────────────────── */

class FakeWpdb
{
    public $prefix             = 'wp_';
    public $term_relationships = 'wp_term_relationships';
    public $term_taxonomy      = 'wp_term_taxonomy';
    public $terms              = 'wp_terms';
    public $insert_id          = 0;
    public $last_error         = '';
    public $inserted           = array();

    public function prepare($sql, ...$args)
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return array('sql' => $sql, 'args' => $args);
    }

    public function get_row($q)
    {
        if (is_array($q) && 'wp_woobooster_bundles' === $q['args'][0]) {
            $id = (int) $q['args'][1];
            return isset($GLOBALS['wb_bundles'][$id]) ? clone $GLOBALS['wb_bundles'][$id] : null;
        }
        return null;
    }

    public function get_results($q, $output = OBJECT)
    {
        if (!is_array($q)) {
            return array();
        }
        if (false !== strpos($q['sql'], 'term_relationships')) {
            $pid = (int) $q['args'][0];
            return isset($GLOBALS['wb_terms'][$pid]) ? $GLOBALS['wb_terms'][$pid] : array();
        }
        $table = isset($q['args'][0]) ? $q['args'][0] : '';
        $bid   = isset($q['args'][1]) ? (int) $q['args'][1] : 0;
        $map   = array(
            'wp_woobooster_bundle_items'      => 'wb_items',
            'wp_woobooster_bundle_actions'    => 'wb_actions',
            'wp_woobooster_bundle_conditions' => 'wb_conditions',
        );
        if (isset($map[$table])) {
            $rows = isset($GLOBALS[$map[$table]][$bid]) ? $GLOBALS[$map[$table]][$bid] : array();
            return array_map(function ($row) { return (object) $row; }, $rows);
        }
        return array();
    }

    public function insert($table, $data, $format = null)
    {
        $this->inserted[] = array($table, $data);
        $this->insert_id++;
        return 1;
    }

    public function get_col($q) { return array(); }
    public function query($q) { return 0; }
    public function delete($table, $where, $format = null) { return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

class WP_Query
{
    public $posts = array();
    public function __construct($args)
    {
        foreach ((array) $args['post__in'] as $id) {
            $p = isset($GLOBALS['wb_products'][$id]) ? $GLOBALS['wb_products'][$id] : null;
            if ($p && 'publish' === $p->status && 'variation' !== $p->type) {
                $this->posts[] = (int) $id;
            }
        }
    }
}

/* ── WooCommerce stand-ins ─────────────────────────────────────────────── */

class FakeProduct
{
    public $id;
    public $name;
    public $price;
    public $type        = 'simple';
    public $status      = 'publish';
    public $parent_id   = 0;
    public $meta        = array();
    public $defaults    = array();
    public $children    = array();
    public $attributes  = array();
    public $purchasable = true;
    public $in_stock    = true;
    public $stock       = null; // null = stock not managed.

    public function __construct($id, $name, $price, array $props = array())
    {
        $this->id    = $id;
        $this->name  = $name;
        $this->price = $price;
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }
    public function get_id() { return $this->id; }
    public function get_name() { return $this->name; }
    public function get_status() { return $this->status; }
    public function is_type($type) { return in_array($this->type, (array) $type, true); }
    public function get_price() { return $this->price; }
    public function get_regular_price() { return $this->price; }
    public function set_price($price) { $this->price = $price; }
    public function get_default_attributes() { return $this->defaults; }
    public function is_purchasable() { return $this->purchasable; }
    public function is_in_stock() { return $this->in_stock; }
    public function managing_stock() { return null !== $this->stock; }
    public function has_enough_stock($qty) { return null === $this->stock || $this->stock >= $qty; }
    public function get_parent_id() { return $this->parent_id; }
    public function get_meta($key) { return isset($this->meta[$key]) ? $this->meta[$key] : ''; }
    public function get_image($size = '') { return '<img alt="' . esc_attr($this->name) . '">'; }
    public function is_sold_individually() { return false; }
    public function is_on_sale() { return false; }
}

function wc_get_product($id)
{
    // Like WooCommerce, every call returns its own object (a cart line's
    // set_price() must not change the catalog).
    return isset($GLOBALS['wb_products'][$id]) ? clone $GLOBALS['wb_products'][$id] : false;
}

class WC_Data_Store
{
    public static function load($type) { return new self(); }
    public function find_matching_product_variation($product, $attributes)
    {
        foreach ($product->children as $vid) {
            $v = isset($GLOBALS['wb_products'][$vid]) ? $GLOBALS['wb_products'][$vid] : null;
            if ($v && $v->attributes == $attributes) {
                return $vid;
            }
        }
        return 0;
    }
}

$GLOBALS['wb_notices'] = array();
function wc_add_notice($message, $type = 'success', $data = array()) { $GLOBALS['wb_notices'][$type][] = array('notice' => $message, 'data' => $data); }
function wc_get_notices($type = '') { return '' === $type ? $GLOBALS['wb_notices'] : (isset($GLOBALS['wb_notices'][$type]) ? $GLOBALS['wb_notices'][$type] : array()); }
function wc_set_notices($notices) { $GLOBALS['wb_notices'] = empty($notices) ? array() : $notices; }
function wc_price($amount) { return '$' . number_format((float) $amount, 2); }
function wc_format_sale_price($regular, $sale) { return '<del>' . (is_numeric($regular) ? wc_price($regular) : $regular) . '</del> <ins>' . (is_numeric($sale) ? wc_price($sale) : $sale) . '</ins>'; }
function wc_get_price_excluding_tax($product, $args = array()) { return (float) $args['price'] * (isset($args['qty']) ? $args['qty'] : 1); }
function wc_get_price_including_tax($product, $args = array()) { return wc_get_price_excluding_tax($product, $args); }
function wc_get_price_decimals() { return 2; }
function wc_get_price_decimal_separator() { return '.'; }
function wc_get_price_thousand_separator() { return ','; }
function get_woocommerce_currency_symbol() { return '$'; }
function wc_get_product_coupon_types() { return array('fixed_product', 'percent'); }
function wc_get_formatted_variation($variation, $flat = false, $include_names = true, $skip_in_name = false)
{
    $out = array();
    foreach ($variation->attributes as $key => $value) {
        if (!$skip_in_name || false === stripos($variation->name, $value)) {
            $out[] = ucfirst(str_replace(array('attribute_pa_', 'attribute_'), '', $key)) . ': ' . $value;
        }
    }
    return implode(', ', $out);
}
function woocommerce_mini_cart() { echo ''; }

class FakeCart
{
    public $cart_contents         = array();
    public $removed_cart_contents = array();
    public $total                 = 0.0;

    public function get_cart() { return array_filter($this->cart_contents); }
    public function get_cart_contents() { return $this->cart_contents; }
    public function set_cart_contents($value) { $this->cart_contents = (array) $value; }
    public function get_removed_cart_contents() { return $this->removed_cart_contents; }
    public function set_removed_cart_contents($value = array()) { $this->removed_cart_contents = (array) $value; }
    public function get_cart_item($key) { return isset($this->cart_contents[$key]) ? $this->cart_contents[$key] : array(); }
    public function get_cart_hash() { return md5(json_encode(array_keys($this->cart_contents))); }
    public function display_prices_including_tax() { return false; }

    // WC_Cart::generate_cart_id(): identity from product, variation and cart item data.
    public function generate_cart_id($product_id, $variation_id = 0, $variation = array(), $data = array())
    {
        $parts = array($product_id);
        if ($variation_id) {
            $parts[] = $variation_id;
        }
        if ($variation) {
            $k = '';
            foreach ($variation as $n => $v) {
                $k .= trim($n) . trim($v);
            }
            $parts[] = $k;
        }
        if ($data) {
            $k = '';
            foreach ($data as $n => $v) {
                $k .= trim($n) . trim(is_array($v) || is_object($v) ? http_build_query($v) : (string) $v);
            }
            $parts[] = $k;
        }
        return md5(implode('_', $parts));
    }

    // The parts of WC_Cart::add_to_cart() that matter here.
    public function add_to_cart($product_id = 0, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array())
    {
        try {
            $product = wc_get_product($variation_id ? $variation_id : $product_id);
            if ($quantity <= 0 || !$product) {
                return false;
            }
            if (!$variation_id && $product->is_type('variable')) {
                throw new Exception('Please choose product options.');
            }
            $cart_item_data = (array) apply_filters('woocommerce_add_cart_item_data', $cart_item_data, $product_id, $variation_id, $quantity);
            $key            = $this->generate_cart_id($product_id, $variation_id, $variation, $cart_item_data);
            if (!$product->is_purchasable()) {
                throw new Exception('Sorry, this product cannot be purchased.');
            }
            if (!$product->is_in_stock()) {
                throw new Exception(sprintf('You cannot add &quot;%s&quot; to the cart because the product is out of stock.', $product->get_name()));
            }
            if (!$product->has_enough_stock($quantity)) {
                throw new Exception(sprintf('You cannot add that amount of &quot;%s&quot; to the cart.', $product->get_name()));
            }
            if ($product->managing_stock()) {
                $in_cart = 0;
                foreach ($this->get_cart() as $item) {
                    if ((int) $item['data']->get_id() === (int) $product->get_id()) {
                        $in_cart += $item['quantity'];
                    }
                }
                if (!$product->has_enough_stock($in_cart + $quantity)) {
                    throw new Exception(sprintf('You cannot add that amount to the cart &mdash; we have %d in stock and you already have %d in your cart.', $product->stock, $in_cart));
                }
            }
            if (isset($this->cart_contents[$key])) {
                $this->cart_contents[$key]['quantity'] += $quantity;
            } else {
                $this->cart_contents[$key] = array_merge($cart_item_data, array(
                    'key'          => $key,
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'variation'    => $variation,
                    'quantity'     => $quantity,
                    'data'         => $product,
                ));
            }
            do_action('woocommerce_add_to_cart', $key, $product_id, $quantity, $variation_id, $variation, $cart_item_data);
            $this->calculate_totals();
            return $key;
        } catch (Exception $e) {
            wc_add_notice($e->getMessage(), 'error');
            return false;
        }
    }

    public function remove_cart_item($key)
    {
        if (!isset($this->cart_contents[$key])) {
            return false;
        }
        $this->removed_cart_contents[$key] = $this->cart_contents[$key];
        unset($this->removed_cart_contents[$key]['data']);
        do_action('woocommerce_remove_cart_item', $key, $this);
        unset($this->cart_contents[$key]);
        do_action('woocommerce_cart_item_removed', $key, $this);
        $this->calculate_totals();
        return true;
    }

    public function restore_cart_item($key)
    {
        if (!isset($this->removed_cart_contents[$key])) {
            return false;
        }
        $item                              = $this->removed_cart_contents[$key];
        $this->cart_contents[$key]         = $item;
        $this->cart_contents[$key]['data'] = wc_get_product($item['variation_id'] ? $item['variation_id'] : $item['product_id']);
        do_action('woocommerce_restore_cart_item', $key, $this);
        unset($this->removed_cart_contents[$key]);
        do_action('woocommerce_cart_item_restored', $key, $this);
        $this->calculate_totals();
        return true;
    }

    public function set_quantity($key, $quantity = 1)
    {
        if ($quantity <= 0) {
            return $this->remove_cart_item($key);
        }
        $old                                   = $this->cart_contents[$key]['quantity'];
        $this->cart_contents[$key]['quantity'] = $quantity;
        do_action('woocommerce_after_cart_item_quantity_update', $key, $quantity, $old, $this);
        $this->calculate_totals();
        return true;
    }

    public function calculate_totals()
    {
        do_action('woocommerce_before_calculate_totals', $this);
        $total = 0.0;
        foreach ($this->get_cart() as $item) {
            $total += (float) $item['data']->get_price() * $item['quantity'];
        }
        $this->total = round($total, 2);
    }

    /**
     * Next page load: lines come back from the session with fresh product
     * objects (their own prices); WooCommerce silently drops lines whose
     * product can no longer be bought.
     */
    public function reload_from_session()
    {
        foreach ($this->cart_contents as $key => $item) {
            $product = wc_get_product($item['variation_id'] ? $item['variation_id'] : $item['product_id']);
            if (!$product || 'publish' !== $product->status || !$product->is_purchasable()) {
                unset($this->cart_contents[$key]);
                continue;
            }
            $this->cart_contents[$key]['data'] = $product;
        }
        do_action('woocommerce_cart_loaded_from_session', $this);
        $this->calculate_totals();
    }
}

class FakeWC
{
    public $cart;
    public $session;
    public function __construct()
    {
        $this->cart    = new FakeCart();
        $this->session = new stdClass();
    }
}
$GLOBALS['wc'] = new FakeWC();
function WC() { return $GLOBALS['wc']; }

class FakeOrderItem
{
    public $meta = array();
    public function add_meta_data($key, $value, $unique = false) { $this->meta[$key] = $value; }
}

class FakeCoupon
{
    public $type;
    public $exclude_sale;
    public function __construct($type, $exclude_sale) { $this->type = $type; $this->exclude_sale = $exclude_sale; }
    public function get_exclude_sale_items() { return $this->exclude_sale; }
    public function is_type($type) { return in_array($this->type, (array) $type, true); }
    public function get_code() { return 'save10'; }
    public function get_coupon_error($code) { return 'Sorry, this coupon is not valid for sale items.'; }
}

class FakeDiscounts
{
    private $items;
    public function __construct(array $cart_items)
    {
        $this->items = array_map(function ($ci) { return (object) array('object' => $ci, 'product' => $ci['data']); }, array_values($cart_items));
    }
    public function get_items() { return $this->items; }
}

/* ── Other WooBooster classes the bundle code calls ────────────────────── */

class WooBooster_Matcher
{
    const CACHE_GROUP   = 'ffl-funnels-addons';
    const CACHE_VERSION = 'woobooster_cache_version';
    public static function invalidate_recommendation_cache(): void {}
    // Dynamic sources: results come from $GLOBALS['wb_dynamic'][action_value]
    // (change them to simulate random order / a different pick at click time).
    public function execute_query($product_id, $action, $args, $terms)
    {
        return isset($GLOBALS['wb_dynamic'][$action->action_value]) ? $GLOBALS['wb_dynamic'][$action->action_value] : array();
    }
}

// Minimal Bricks element base for the Bundle element.
eval('namespace Bricks; class Element {
    public $settings = array();
    public $attributes = array();
    public function set_attribute($key, $attribute, $value) { $this->attributes[$key][$attribute][] = $value; }
    public function render_attributes($key) {
        $out = array();
        foreach (isset($this->attributes[$key]) ? $this->attributes[$key] : array() as $attribute => $values) {
            $out[] = $attribute . "=\"" . esc_attr(implode(" ", $values)) . "\"";
        }
        return implode(" ", $out);
    }
}');

$module = getenv('WB_MODULE_DIR') ?: dirname(__DIR__, 2) . '/modules/woobooster';
require $module . '/includes/class-woobooster-bundle.php';
require $module . '/includes/class-woobooster-bundle-matcher.php';
require $module . '/includes/class-woobooster-bundle-cart.php';
require $module . '/frontend/class-woobooster-bundle-element.php';

WooBooster_Bundle_Cart::init();

/* ── Catalog and bundles ───────────────────────────────────────────────── */

$firearm = array('meta' => array('_firearm_product' => 'yes'));
$GLOBALS['wb_products'] = array(
    101 => new FakeProduct(101, 'Rifle', 1000.0, $firearm),
    102 => new FakeProduct(102, 'Scope', 300.0),
    103 => new FakeProduct(103, 'Ammo', 50.0),
    104 => new FakeProduct(104, 'Handgun', 600.0, $firearm),
    105 => new FakeProduct(105, 'Holster', 60.0),
    106 => new FakeProduct(106, 'Compact Handgun', 550.0, $firearm),
    200 => new FakeProduct(200, 'Sling', 20.0, array('type' => 'variable', 'defaults' => array('pa_color' => 'black'), 'children' => array(201, 202))),
    201 => new FakeProduct(201, 'Sling - black', 35.0, array('type' => 'variation', 'parent_id' => 200, 'attributes' => array('attribute_pa_color' => 'black'))),
    202 => new FakeProduct(202, 'Sling - tan', 20.0, array('type' => 'variation', 'parent_id' => 200, 'attributes' => array('attribute_pa_color' => 'tan'))),
    300 => new FakeProduct(300, 'Hard Case', 80.0, array('in_stock' => false)),
    301 => new FakeProduct(301, 'Cleaning Kit', 40.0),
    302 => new FakeProduct(302, 'Targets', 15.0),
    303 => new FakeProduct(303, 'Ear Protection', 25.0),
);
$GLOBALS['wb_terms'] = array(
    101 => array(array('taxonomy' => 'product_cat', 'slug' => 'rifles', 'term_id' => 1)),
    104 => array(array('taxonomy' => 'product_cat', 'slug' => 'handguns', 'term_id' => 2)),
    106 => array(array('taxonomy' => 'product_cat', 'slug' => 'handguns', 'term_id' => 2)),
);

function bundle_row($id, $name, $type, $value, array $extra = array())
{
    return (object) array_merge(array(
        'id'                => $id,
        'name'              => $name,
        'image_id'          => null,
        'priority'          => 10,
        'status'            => 1,
        'discount_type'     => $type,
        'discount_value'    => $value,
        'bundle_price_type' => 'discount',
        'bundle_price'      => null,
        'start_date'        => null,
        'end_date'          => null,
    ), $extra);
}
function item_row($pid, $qty = 1, $optional = 0, $sort = 0)
{
    return array('product_id' => $pid, 'quantity' => $qty, 'sort_order' => $sort, 'is_optional' => $optional);
}
function cond_row($attr, $value, $group = 0, $op = 'equals')
{
    return array('group_id' => $group, 'condition_attribute' => $attr, 'condition_operator' => $op, 'condition_value' => $value, 'include_children' => 0);
}

$GLOBALS['wb_bundles'] = array(
    1 => bundle_row(1, 'Range Kit', 'percentage', 10, array('image_id' => 55)),
    2 => bundle_row(2, 'Handgun Kit', 'fixed', 66),
    3 => bundle_row(3, 'LE Kit', 'percentage', 15),
    4 => bundle_row(4, 'Landing Kit', 'fixed', 35),
    5 => bundle_row(5, 'Cleaning Bundle', 'percentage', 10),
    6 => bundle_row(6, 'Sling Combo', 'percentage', 20),
    7 => bundle_row(7, 'Old Kit', 'fixed', 100),
);
$GLOBALS['wb_items'] = array(
    // Ammo first, so the rifle (a firearm) is not the first item.
    1 => array(item_row(103, 2, 0, 0), item_row(101, 1, 0, 1), item_row(102, 1, 1, 2), item_row(300, 1, 1, 3)),
    2 => array(item_row(105, 1, 0, 0), item_row(104, 1, 0, 1), item_row(103, 1, 1, 2)),
    3 => array(item_row(102), item_row(103, 1, 0, 1)),
    4 => array(item_row(102), item_row(103, 1, 0, 1)),
    5 => array(item_row(102)),
    6 => array(item_row(200), item_row(103, 1, 0, 1)),
    7 => array(item_row(101), item_row(102, 1, 0, 1)),
);
$GLOBALS['wb_actions'] = array(
    5 => array(array('group_id' => 0, 'action_source' => 'copurchase', 'action_value' => 'cleaning', 'action_limit' => 2, 'action_orderby' => 'rand')),
);
$GLOBALS['wb_conditions'] = array(
    1 => array(cond_row('product_cat', 'rifles')),
    2 => array(cond_row('product_cat', 'handguns')),
    3 => array(cond_row('user_role', 'le_officer')),
);

/* ── Helpers ───────────────────────────────────────────────────────────── */

function fresh_cart()
{
    $GLOBALS['wc']->cart    = new FakeCart();
    $GLOBALS['wb_notices']  = array();
    $GLOBALS['wb_user']     = null;
    $GLOBALS['wb_dynamic']  = array('cleaning' => array(301, 302));
    remove_all_filters('woocommerce_add_to_cart_validation');
    return $GLOBALS['wc']->cart;
}

/** POST to the real AJAX handler; returns the JSON response. */
function post_bundle(array $post)
{
    if (!array_key_exists('nonce', $post)) {
        $post['nonce'] = wp_create_nonce('woobooster_bundle_cart');
    }
    $_POST = $_REQUEST = $post;
    try {
        WooBooster_Bundle_Cart::ajax_add_bundle_to_cart();
    } catch (JsonResponse $response) {
        return $response;
    }
    throw new RuntimeException('handler sent no JSON response');
}

/** Lines of the cart: product/variation => [qty, unit price, item]. */
function cart_lines(FakeCart $cart)
{
    $out = array();
    foreach ($cart->get_cart() as $key => $item) {
        $id       = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
        $out[$id] = array('qty' => $item['quantity'], 'price' => (float) $item['data']->get_price(), 'key' => $key, 'item' => $item);
    }
    return $out;
}

/** The FFL dealer check (ffl-checkout dealer bridge fallback): per cart line. */
function cart_requires_ffl(FakeCart $cart)
{
    foreach ($cart->get_cart() as $cart_item) {
        $product = wc_get_product($cart_item['data']->get_id());
        if (!$product) {
            continue;
        }
        $parent_id = $product->get_parent_id();
        $firearm   = $parent_id ? wc_get_product($parent_id)->get_meta('_firearm_product') : $product->get_meta('_firearm_product');
        if ('yes' === $firearm) {
            return true;
        }
    }
    return false;
}

/** Render the Bundle element for a pinned bundle and product. */
function render_widget($bundle_id, $product_id, array $settings = array())
{
    $element           = new \Bricks\WooBooster_Bundle_Element();
    $element->settings = array_merge(array(
        'wb_bundle_id'         => $bundle_id,
        'wb_bundle_source'     => $product_id ? 'manual' : 'none',
        'wb_bundle_product_id' => $product_id,
    ), $settings);
    ob_start();
    $element->render();
    return ob_get_clean();
}

function widget_token($html)
{
    return preg_match('/data-bundle-token="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}

function scenario($name, callable $fn)
{
    global $failures, $soft;
    try {
        $fn();
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Throwable $e) {
        if (!$soft) {
            throw new RuntimeException("FAIL: $name: " . get_class($e) . ': ' . $e->getMessage(), 0, $e);
        }
        $failures[] = "$name crashed: " . $e->getMessage();
    }
}

/* ── 1. One real line per item; firearm not first; anchor kept ─────────── */

scenario('range kit on the rifle page', function () {
    $cart = fresh_cart();

    $html = render_widget(1, 101);
    check(false !== strpos($html, 'data-bundle-token="'), 'widget carries a signed item list');
    check(preg_match('/data-product-id="(\d+)"/', $html, $m) && '101' === $m[1], 'the viewed rifle (a static item) is shown first');
    check(false !== strpos($html, 'This item'), 'the viewed product is labelled "This item"');
    check(1 === preg_match('/value="101"[^>]*disabled data-required="1"/', $html), 'the viewed rifle is required (ticked and locked)');
    check(false === strpos($html, 'Hard Case'), 'an out-of-stock optional item is not shown');
    check(false === strpos($html, 'wb-bundle-cover'), 'the bundle image is off by default');
    check(false !== strpos(render_widget(1, 101, array('wb_bundle_show_bundle_image' => true)), 'class="wb-bundle-cover"'), '"Show Bundle Image" shows the bundle image in the widget');

    $r = post_bundle(array('bundle_id' => 1, 'source_product_id' => 101, 'product_ids' => array(103, 101, 102), 'token' => widget_token($html)));
    check($r->success, 'range kit added');

    $lines = cart_lines($cart);
    check(3 === count($lines), 'one cart line per bundle item (3 lines)');
    check(isset($lines[101], $lines[102], $lines[103]), 'rifle, scope and ammo are each a cart line');
    check(isset($lines[103]) && 2 === $lines[103]['qty'], 'ammo line carries the item quantity (2)');
    check(isset($lines[101]) && near($lines[101]['price'], 900.0), 'rifle priced at its own discounted unit price ($900)');
    check(isset($lines[102]) && near($lines[102]['price'], 270.0), 'scope priced at $270');
    check(isset($lines[103]) && near($lines[103]['price'], 45.0), 'ammo priced at $45 per unit');
    check(near($cart->total, 1260.0), 'cart total equals the bundle total ($1,260)');
    check(cart_requires_ffl($cart), 'the rifle is its own line, so checkout asks for an FFL dealer');

    $hashes = array_unique(array_map(function ($l) { return $l['item']['_woobooster_bundle_hash'] ?? ''; }, $lines));
    check(1 === count($hashes) && '' !== reset($hashes), 'all lines share one bundle group hash');

    // Item data, order meta.
    $data = apply_filters('woocommerce_get_item_data', array(), $lines[102]['item'] ?? array());
    check(array('key' => 'Bundle', 'value' => 'Range Kit') === ($data[0] ?? null), 'each line shows "Bundle: Range Kit"');
    $order_item = new FakeOrderItem();
    do_action('woocommerce_checkout_create_order_line_item', $order_item, $lines[101]['key'] ?? '', $lines[101]['item'] ?? array(), null);
    check(1 === ($order_item->meta['_woobooster_bundle_id'] ?? 0), 'order line keeps _woobooster_bundle_id');
    check(!empty($order_item->meta['_woobooster_bundle_hash']), 'order line keeps the group hash');
    check('Range Kit' === ($order_item->meta['Bundle'] ?? ''), 'order line shows the bundle name');
    check('Scope' === apply_filters('woocommerce_cart_item_name', 'Scope', $lines[102]['item'] ?? array()), 'a bundle line keeps its product name');
    check('<img a>' === apply_filters('woocommerce_cart_item_thumbnail', '<img a>', $lines[102]['item'] ?? array(), ''), 'a bundle line keeps its product image');

    // Price display (classic cart): the item's own price struck through.
    $price_html = apply_filters('woocommerce_cart_item_price', '$270.00', $lines[102]['item'] ?? array(), '');
    check(false !== strpos($price_html, '<del>$300.00</del>'), 'classic cart shows the regular price struck through');

    // Quantities are locked.
    $qty_html = apply_filters('woocommerce_cart_item_quantity', '<input qty>', $lines[103]['key'] ?? '', $lines[103]['item'] ?? array());
    check(false === strpos($qty_html, '<input qty>') && false !== strpos($qty_html, 'type="hidden"'), 'classic cart shows the quantity as text');
    check(false === apply_filters('woocommerce_update_cart_validation', true, $lines[103]['key'] ?? '', $lines[103]['item'] ?? array(), 5), 'changing a bundle line quantity is refused');
    check(false === apply_filters('woocommerce_store_api_product_quantity_editable', true, null, $lines[103]['item'] ?? array()), 'block cart: quantity not editable');

    // Coupons.
    $exclude = new FakeCoupon('percent', true);
    $plain   = new FakeCoupon('percent', false);
    check(false === apply_filters('woocommerce_coupon_is_valid_for_product', true, null, $exclude, $lines[102]['item'] ?? array()), 'a coupon excluding sale items skips discounted bundle lines');
    check(true === apply_filters('woocommerce_coupon_is_valid_for_product', true, null, $plain, $lines[102]['item'] ?? array()), 'other coupons still apply to bundle lines');
    $threw = false;
    try {
        apply_filters('woocommerce_coupon_is_valid', true, new FakeCoupon('fixed_cart', true), new FakeDiscounts($cart->get_cart()));
    } catch (Exception $e) {
        $threw = false !== strpos($e->getMessage(), 'sale items');
    }
    check($threw, 'a fixed-cart coupon excluding sale items is refused with a bundle in the cart (as for sale items)');

    // Removing one line removes the whole group; undo restores it.
    $cart->remove_cart_item($lines[102]['key'] ?? '');
    check(0 === count($cart->get_cart()), 'removing one line removes the whole bundle');
    $cart->restore_cart_item($lines[102]['key'] ?? '');
    $restored = cart_lines($cart);
    check(3 === count($restored), 'undo restores the whole bundle');
    check(near($cart->total, 1260.0), 'the restored bundle keeps its price');

    // A changed quantity (by any route) loses the bundle price on that line.
    $cart->set_quantity($restored[103]['key'] ?? '', 5);
    $after = cart_lines($cart);
    check(isset($after[103]) && near($after[103]['price'], 50.0), 'a line whose quantity changed is charged its own price');
});

scenario('required viewed product cannot be dropped', function () {
    $cart = fresh_cart();
    $r    = post_bundle(array('bundle_id' => 1, 'source_product_id' => 101, 'product_ids' => array(102, 103)));
    check(!$r->success, 'scope + ammo without the (required, viewed) rifle are refused');
    check(0 === count($cart->get_cart()), 'nothing added without the rifle');
});

/* ── 2. Validation per item, rollback ───────────────────────────────────── */

scenario('handgun kit: firearm not first, validation per item', function () {
    $cart  = fresh_cart();
    $calls = array();
    add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id, $qty = 1, $variation_id = 0, $variation = array()) use (&$calls) {
        $calls[] = $product_id;
        return $passed;
    }, 10, 5);

    $r = post_bundle(array('bundle_id' => 2, 'source_product_id' => 106, 'product_ids' => array(105, 104, 103)));
    check($r->success, 'handgun kit added from another handgun page');
    $lines = cart_lines($cart);
    check(isset($lines[104]) && 3 === count($lines), 'the handgun (second item) is its own cart line');
    check(cart_requires_ffl($cart), 'checkout asks for an FFL dealer for the handgun');
    check(near($cart->total, 644.0, 0.02), 'fixed $66 off a $710 set charges $644');
    sort($calls);
    check(array(103, 104, 105) === $calls, 'add-to-cart validation ran once for every item');
});

scenario('validation refuses a required item', function () {
    $cart = fresh_cart();
    add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id) {
        if (104 === (int) $product_id) {
            wc_add_notice('A transfer is required for this firearm.', 'error');
            return false;
        }
        return $passed;
    }, 10, 2);
    $r = post_bundle(array('bundle_id' => 2, 'source_product_id' => 106, 'product_ids' => array(105, 104, 103)));
    check(!$r->success, 'a refused required item refuses the bundle');
    check(0 === count($cart->get_cart()), 'nothing added when a required item is refused');
    check(isset($r->data['message']) && false !== strpos((string) $r->data['message'], 'transfer is required'), 'the refusal reason is returned');
    check(empty($GLOBALS['wb_notices']['error']), 'the refusal notice is not left for the next page');
});

scenario('validation refuses an optional item', function () {
    $cart = fresh_cart();
    add_filter('woocommerce_add_to_cart_validation', function ($passed, $product_id) {
        return 103 === (int) $product_id ? false : $passed;
    }, 10, 2);
    $r = post_bundle(array('bundle_id' => 2, 'source_product_id' => 106, 'product_ids' => array(105, 104, 103)));
    check($r->success && 2 === count($cart->get_cart()), 'a refused optional item is skipped, the rest is added');
    check(!empty($r->data['errors']), 'the skipped item is reported');
});

scenario('required item fails at add_to_cart: rollback', function () {
    $cart = fresh_cart();
    // One handgun left and it is already in the cart: the bundle's handgun
    // passes the item check but WooCommerce refuses it (stock in cart).
    $GLOBALS['wb_products'][104]->stock = 1;
    $plain = $cart->add_to_cart(104, 1);
    $r     = post_bundle(array('bundle_id' => 2, 'source_product_id' => 106, 'product_ids' => array(105, 104, 103)));
    $GLOBALS['wb_products'][104]->stock = null;

    check(!$r->success, 'bundle refused when a required item cannot be added');
    check(array($plain) === array_keys($cart->get_cart()), 'the holster line added before the handgun was rolled back');
    check(empty($cart->get_removed_cart_contents()), 'rolled-back lines are not offered for undo');
});

/* ── 3. Conditions, source product ─────────────────────────────────────── */

scenario('role conditions', function () {
    $cart = fresh_cart();
    $r    = post_bundle(array('bundle_id' => 3, 'source_product_id' => 0, 'product_ids' => array(102, 103)));
    check(!$r->success && 0 === count($cart->get_cart()), 'an LE-only bundle is refused for a guest');

    $cart = fresh_cart();
    $GLOBALS['wb_user'] = array('le_officer');
    $r = post_bundle(array('bundle_id' => 3, 'source_product_id' => 0, 'product_ids' => array(102, 103)));
    check($r->success && 2 === count($cart->get_cart()), 'an LE-only bundle is added for an LE officer');

    $GLOBALS['wb_user'] = null;
    check('' === trim(render_widget(3, 0)), 'the pinned LE-only bundle is not shown to a guest');
});

scenario('source product must match the conditions', function () {
    $cart = fresh_cart();
    $r    = post_bundle(array('bundle_id' => 1, 'source_product_id' => 104, 'product_ids' => array(103, 101, 102)));
    check(!$r->success && 0 === count($cart->get_cart()), 'a rifles bundle is refused with a handgun as source');
    $r = post_bundle(array('bundle_id' => 1, 'source_product_id' => 0, 'product_ids' => array(103, 101, 102)));
    check(!$r->success && 0 === count($cart->get_cart()), 'a bundle with product conditions is refused without a source');
    // A pinned bundle still shows where it is placed (landing pages, other
    // products); its signed list then lets that exact set be added.
    $html = render_widget(1, 104);
    check('' !== trim($html), 'a pinned rifles bundle still shows where it is placed, e.g. a handgun page');
    $r = post_bundle(array('bundle_id' => 1, 'source_product_id' => 104, 'product_ids' => array(103, 101, 102), 'token' => widget_token($html)));
    check($r->success && 3 === count($cart->get_cart()), 'the pinned bundle is added with its signed list');

    // Editing the bundle invalidates lists signed before the edit.
    $cart = fresh_cart();
    $token = widget_token(render_widget(1, 104));
    $GLOBALS['wb_bundles'][1]->discount_value = (float) $GLOBALS['wb_bundles'][1]->discount_value + 5;
    WooBooster_Bundle_Cart::flush_fingerprints();
    check(null === WooBooster_Bundle_Cart::verify_snapshot_token($token, 1, 104), 'a list signed before an edit of the bundle no longer verifies');
    $r = post_bundle(array('bundle_id' => 1, 'source_product_id' => 104, 'product_ids' => array(103, 101, 102), 'token' => $token));
    check(!$r->success && 0 === count($cart->get_cart()), 'and the request falls back to the product conditions (refused here)');
    $GLOBALS['wb_bundles'][1]->discount_value = (float) $GLOBALS['wb_bundles'][1]->discount_value - 5;
    WooBooster_Bundle_Cart::flush_fingerprints();
});

scenario('source 0 keeps every item', function () {
    $cart = fresh_cart();
    $r    = post_bundle(array('bundle_id' => 4, 'source_product_id' => 0, 'product_ids' => array(102, 103)));
    $lines = cart_lines($cart);
    check($r->success && isset($lines[102], $lines[103]), 'a pinned bundle without conditions keeps every item with source 0');
    check(near($cart->total, 315.0), 'fixed $35 off stays spread over the whole set ($315)');
});

/* ── 4. Signed item list ───────────────────────────────────────────────── */

scenario('signed item list pins dynamic items', function () {
    $cart  = fresh_cart();
    $html  = render_widget(5, 0);
    $token = widget_token($html);
    check('' !== $token, 'widget signs the items it shows');

    // At click time the automatic pick differs (random order, new results).
    $GLOBALS['wb_dynamic']['cleaning'] = array(303);
    $r     = post_bundle(array('bundle_id' => 5, 'source_product_id' => 0, 'product_ids' => array(102, 301, 302), 'token' => $token));
    $lines = cart_lines($cart);
    check($r->success && isset($lines[301], $lines[302]) && 3 === count($lines), 'the cart gets exactly the items the widget showed');
    check(isset($lines[301]) && near($lines[301]['price'], 36.0), 'a pinned dynamic item is priced server-side ($36)');

    // Tampered list: rejected, items worked out again.
    $cart     = fresh_cart();
    $GLOBALS['wb_dynamic']['cleaning'] = array(303);
    $tampered = preg_replace('/^(\d+)\.([\d-]+)\./', '$1.$2-303.', $token);
    $r        = post_bundle(array('bundle_id' => 5, 'source_product_id' => 0, 'product_ids' => array(102, 301, 302, 303), 'token' => $tampered));
    $lines    = cart_lines($cart);
    check($r->success && isset($lines[102], $lines[303]) && !isset($lines[301]) && !isset($lines[302]), 'a tampered list is ignored and the items are worked out again');

    // Another bundle or source cannot reuse the list.
    check(null === WooBooster_Bundle_Cart::verify_snapshot_token($token, 4, 0), 'the list is bound to its bundle');
    check(null === WooBooster_Bundle_Cart::verify_snapshot_token($token, 5, 101), 'the list is bound to its source product');
    $expired = WooBooster_Bundle_Cart::create_snapshot_token(5, 0, array(102, 301), time() - 1);
    check(null === WooBooster_Bundle_Cart::verify_snapshot_token($expired, 5, 0), 'an expired list is rejected');
});

scenario('cached page nonce', function () {
    $cart  = fresh_cart();
    $token = widget_token(render_widget(4, 0));
    $r     = post_bundle(array('bundle_id' => 4, 'source_product_id' => 0, 'product_ids' => array(102, 103), 'token' => $token, 'nonce' => 'expired'));
    check($r->success && 2 === count($cart->get_cart()), 'a guest with an expired nonce and a valid signed list can add the bundle');

    $cart = fresh_cart();
    $r    = post_bundle(array('bundle_id' => 4, 'source_product_id' => 0, 'product_ids' => array(102, 103), 'token' => 'bogus', 'nonce' => 'expired'));
    check(!$r->success && 403 === $r->status && 'invalid_nonce' === ($r->data['code'] ?? ''), 'without a valid list a bad nonce is refused (403, the widget fetches a new one)');

    $cart = fresh_cart();
    $GLOBALS['wb_user'] = array('customer');
    $r = post_bundle(array('bundle_id' => 4, 'source_product_id' => 0, 'product_ids' => array(102, 103), 'token' => $token, 'nonce' => 'expired'));
    check(!$r->success && 403 === $r->status && 0 === count($cart->get_cart()), 'a logged-in shopper still needs a valid nonce');

    try {
        WooBooster_Bundle_Cart::ajax_refresh_nonce();
        $fresh = null;
    } catch (JsonResponse $response) {
        $fresh = $response->data['nonce'] ?? null;
    }
    check(wp_create_nonce('woobooster_bundle_cart') === $fresh, 'the nonce refresh endpoint returns a fresh nonce');
});

/* ── 5. Variable products ──────────────────────────────────────────────── */

scenario('variable product priced at the added variation', function () {
    $cart = fresh_cart();
    $html = render_widget(6, 0);
    check(1 === preg_match('/data-product-id="200" data-price="28"/', $html), 'widget prices the sling at its default variation ($35 less 20% = $28)');
    check(false !== strpos($html, 'Sling - black'), 'widget names the variation that is added');

    $r     = post_bundle(array('bundle_id' => 6, 'source_product_id' => 0, 'product_ids' => array(200, 103), 'token' => widget_token($html)));
    $lines = cart_lines($cart);
    check($r->success && isset($lines[201]), 'the default variation is the cart line');
    check(isset($lines[201]) && near($lines[201]['price'], 28.0), 'the variation line is charged $28, not the parent price less 20% ($16)');
    check(isset($lines[201]) && array('attribute_pa_color' => 'black') === $lines[201]['item']['variation'], 'the line carries the variation attributes');
});

/* ── 6. Percentage cap ─────────────────────────────────────────────────── */

scenario('percentage capped at 100', function () {
    $GLOBALS['wpdb']->inserted = array();
    WooBooster_Bundle::create(array('name' => 'Too Much', 'discount_type' => 'percentage', 'discount_value' => 150));
    $saved = end($GLOBALS['wpdb']->inserted);
    check(isset($saved[1]['discount_value']) && 100.0 === (float) $saved[1]['discount_value'], 'a percentage above 100 is saved as 100');

    WooBooster_Bundle::create(array('name' => 'Fixed', 'discount_type' => 'fixed', 'discount_value' => 150));
    $saved = end($GLOBALS['wpdb']->inserted);
    check(150.0 === (float) $saved[1]['discount_value'], 'a fixed amount above 100 is kept');

    $out = WooBooster_Bundle::apply_discount_to_prices(array(1 => 50.0), 'percentage', 250, 2);
    check(0.0 === $out[1]['discounted'], 'pricing caps a stored percentage at 100 (never below $0)');
    $out = WooBooster_Bundle::apply_discount_to_prices(array(1 => 50.0, 2 => 30.0), 'fixed', 500, 2);
    check(0.0 === $out[1]['discounted'] && 0.0 === $out[2]['discounted'], 'a fixed amount cannot make the total negative');
});

/* ── 7. Bundle ends / incomplete groups ────────────────────────────────── */

scenario('bundle switched off while in the cart', function () {
    $cart = fresh_cart();
    post_bundle(array('bundle_id' => 4, 'source_product_id' => 0, 'product_ids' => array(102, 103)));
    $GLOBALS['wb_bundles'][4]->status = 0;
    $cart->reload_from_session();
    $GLOBALS['wb_bundles'][4]->status = 1;
    check(0 === count($cart->get_cart()), 'the whole group is removed when its bundle is switched off');
    check(1 === count($GLOBALS['wb_notices']['notice'] ?? array()), 'one notice for the removed bundle');
});

scenario('group loses a line on reload', function () {
    $cart = fresh_cart();
    post_bundle(array('bundle_id' => 4, 'source_product_id' => 0, 'product_ids' => array(102, 103)));
    $GLOBALS['wb_products'][103]->purchasable = false; // WooCommerce drops the ammo line.
    $cart->reload_from_session();
    $GLOBALS['wb_products'][103]->purchasable = true;
    check(0 === count($cart->get_cart()), 'no discounted orphan: the rest of an incomplete group is removed');
});

/* ── 8. Old single-line carts ──────────────────────────────────────────── */

scenario('old-format cart line', function () {
    $cart = fresh_cart();
    $key  = 'legacy-line';
    $cart->cart_contents[$key] = array(
        'key'                                 => $key,
        'product_id'                          => 101,
        'variation_id'                        => 0,
        'variation'                           => array(),
        'quantity'                            => 1,
        'data'                                => wc_get_product(101),
        '_woobooster_bundle_id'               => 7,
        '_woobooster_bundle_hash'             => 'oldhash',
        '_woobooster_bundle_items'            => array(
            array('product_id' => 101, 'quantity' => 1, 'name' => 'Rifle', 'original' => 1000.0, 'discounted' => 923.08),
            array('product_id' => 102, 'quantity' => 1, 'name' => 'Scope', 'original' => 300.0, 'discounted' => 276.92),
        ),
        '_woobooster_bundle_total'            => 1200.0,
        '_woobooster_bundle_source_product_id' => 0,
    );
    $cart->reload_from_session();
    $item = $cart->get_cart_item($key);
    check(1 === count($cart->get_cart()) && near($cart->total, 1200.0), 'an old single bundle line keeps the bundle total');
    check('Old Kit' === apply_filters('woocommerce_cart_item_name', 'Rifle', $item), 'an old line still shows the bundle name');
    $data = apply_filters('woocommerce_get_item_data', array(), $item);
    check(isset($data[0]['key']) && 'Includes' === $data[0]['key'] && 'Rifle, Scope' === $data[0]['value'], 'an old line still lists its contents');
    $order_item = new FakeOrderItem();
    do_action('woocommerce_checkout_create_order_line_item', $order_item, $key, $item, null);
    check(7 === ($order_item->meta['_woobooster_bundle_id'] ?? 0) && 'Rifle, Scope' === ($order_item->meta['Bundle contents'] ?? ''), 'an old line keeps its order meta');
    check('<input qty>' === apply_filters('woocommerce_cart_item_quantity', '<input qty>', $key, $item), 'an old line keeps its quantity box (buys whole bundles)');
});

if ($failures) {
    fwrite(STDERR, count($failures) . " of $checks checks FAILED (woobooster bundle cart):\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo $checks . " checks passed (woobooster bundle cart).\n";
