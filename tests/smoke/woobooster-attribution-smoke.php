<?php
/**
 * Standalone cart identity / attribution regression harness.
 * Run: php tests/smoke/woobooster-attribution-smoke.php
 *
 * Models WC's filter -> cart key -> quantity update -> successful-add action
 * sequence, including a priority-10 cart persistence listener. No live store
 * or database is used. Staging should additionally exercise actual WC checkout.
 */
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);

$checks = 0;
$hooks = array();
$options = array();
$orders = array();
function check($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}
function near($a, $b) { return abs($a - $b) < 0.000001; }
function add_filter($hook, $callback, $priority = 10, $args = 1) {
    global $hooks;
    $hooks[$hook][$priority][] = array($callback, $args);
}
function add_action($hook, $callback, $priority = 10, $args = 1) { add_filter($hook, $callback, $priority, $args); }
function apply_filters($hook, $value, ...$args) {
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
function do_action($hook, ...$args) {
    global $hooks;
    $callbacks = isset($hooks[$hook]) ? $hooks[$hook] : array();
    ksort($callbacks);
    foreach ($callbacks as $group) {
        foreach ($group as $entry) {
            call_user_func_array($entry[0], array_slice($args, 0, $entry[1]));
        }
    }
}
function WC() { return $GLOBALS['wc']; }
function absint($value) { return abs((int) $value); }
function wp_date($format, $timestamp = null) { return gmdate($format, $timestamp === null ? strtotime('2026-10-06 12:00:00 UTC') : $timestamp); }
function get_option($key, $default = false) { return isset($GLOBALS['options'][$key]) ? $GLOBALS['options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
function __($text, $domain = '') { return $text; }
function number_format_i18n($number, $decimals = 0) { return number_format($number, $decimals); }
function wc_get_orders($args) { return $args['offset'] ? array() : $GLOBALS['orders']; }
function wc_get_product($id) {
    return new class($id) {
        private $id;
        public function __construct($id) { $this->id = $id; }
        public function get_name() { return 'Product ' . $this->id; }
        public function get_image($size) { return ''; }
    };
}

class AttributionSession {
    public $data = array();
    public function get($key, $default = null) { return isset($this->data[$key]) ? $this->data[$key] : $default; }
    public function set($key, $value) { $this->data[$key] = $value; }
}
class AttributionCart {
    public $cart_contents = array();
    public $saved = array();
    public $removed = array();
    // WC_Cart::generate_cart_id algorithm: only incoming data determines identity.
    public function generate_cart_id($product_id, $variation_id, $variation, $data) {
        $parts = array($product_id);
        if ($variation_id) { $parts[] = $variation_id; }
        if ($variation) {
            $key = '';
            foreach ($variation as $name => $value) { $key .= trim($name) . trim($value); }
            $parts[] = $key;
        }
        if ($data) {
            $key = '';
            foreach ($data as $name => $value) {
                if (is_array($value) || is_object($value)) { $value = http_build_query($value); }
                $key .= trim($name) . trim($value);
            }
            $parts[] = $key;
        }
        return md5(implode('_', $parts));
    }
    public function add_to_cart($pid, $qty = 1, $vid = 0, $variation = array(), $data = array(), $succeeds = true) {
        $data = apply_filters('woocommerce_add_cart_item_data', $data, $pid, $vid, $qty);
        $key = $this->generate_cart_id($pid, $vid, $variation, $data);
        if (!$succeeds) { return false; }
        if (isset($this->cart_contents[$key])) {
            $this->set_quantity($key, $this->cart_contents[$key]['quantity'] + $qty);
        } else {
            $this->cart_contents[$key] = array_merge($data, array('product_id' => $pid, 'variation_id' => $vid, 'variation' => $variation, 'quantity' => $qty));
        }
        do_action('woocommerce_add_to_cart', $key, $pid, $qty, $vid, $variation, $data);
        return $key;
    }
    public function set_quantity($key, $qty) {
        $old = $this->cart_contents[$key]['quantity'];
        $this->cart_contents[$key]['quantity'] = $qty;
        do_action('woocommerce_after_cart_item_quantity_update', $key, $qty, $old, $this);
    }
}
class AttributionItem {
    public $meta = array();
    private $qty;
    private $subtotal;
    private $tax;
    public function __construct($qty, $subtotal = 0, $tax = 0) { $this->qty = $qty; $this->subtotal = $subtotal; $this->tax = $tax; }
    public function add_meta_data($key, $value, $unique) { $this->meta[$key] = $value; }
    public function get_meta($key) { return isset($this->meta[$key]) ? $this->meta[$key] : ''; }
    public function meta_exists($key) { return array_key_exists($key, $this->meta); }
    public function get_quantity() { return $this->qty; }
    public function get_subtotal() { return $this->subtotal; }
    public function get_subtotal_tax() { return $this->tax; }
    public function get_product_id() { return 44; }
}
class AttributionOrder {
    private $items;
    public function __construct($items) { $this->items = $items; }
    public function get_items() { return $this->items; }
    public function get_date_created() { return new DateTime('2026-10-06'); }
    public function get_subtotal() { return array_sum(array_map(function ($item) { return $item->get_subtotal(); }, $this->items)); }
}

require dirname(__DIR__, 2) . '/modules/woobooster/analytics/class-woobooster-tracker.php';
require dirname(__DIR__, 2) . '/modules/woobooster/analytics/class-woobooster-analytics.php';
require dirname(__DIR__, 2) . '/modules/woobooster/includes/class-woobooster-bundle-cart.php';

function fresh_cart() {
    $GLOBALS['hooks'] = array();
    $GLOBALS['options'] = array();
    $GLOBALS['wc'] = (object) array('session' => new AttributionSession(), 'cart' => new AttributionCart());
    // Core's priority-10 persistence hook is registered before the tracker.
    add_action('woocommerce_add_to_cart', function () { WC()->cart->saved = WC()->cart->cart_contents; }, 10, 0);
    $tracker = new WooBooster_Tracker();
    $tracker->init();
    add_filter('woocommerce_add_cart_item_data', array('WooBooster_Bundle_Cart', 'preserve_bundle_meta'), 10, 2);
    return $tracker;
}
function quantities($key) {
    $values = WC()->cart->cart_contents[$key];
    return WooBooster_Tracker::get_attribution_quantities($values, $values['quantity']);
}
function checkout_item($tracker, $key, $price = 36.21, $tax = 0) {
    $values = WC()->cart->cart_contents[$key];
    $item = new AttributionItem($values['quantity'], $price * $values['quantity'], $tax * $values['quantity']);
    $tracker->persist_order_item_meta($item, $key, $values, null);
    return $item;
}
function analytics($items) {
    $GLOBALS['orders'] = array(new AttributionOrder($items));
    $method = new ReflectionMethod('WooBooster_Analytics', 'compute_all_data');
    $method->setAccessible(true);
    return $method->invoke(new WooBooster_Analytics(), '2026-10-06', '2026-10-06');
}

// Screenshot regression: ordinary add followed by Smart add, one line / two boxes.
$tracker = fresh_cart();
$key = WC()->cart->add_to_cart(44);
WooBooster_Tracker::register_recommendation(-1, array(44));
check(array() === apply_filters('woocommerce_add_cart_item_data', array(), 44, 0, 1), 'analytics adds no cart-key data');
check($key === WC()->cart->add_to_cart(44), 'normal then Smart uses the same cart key');
check(count(WC()->cart->cart_contents) === 1 && WC()->cart->cart_contents[$key]['quantity'] === 2, 'two boxes consolidate');
check(quantities($key) === array(-1 => 1.0), 'only the recommended box is attributed');
check(WC()->cart->saved[$key][WooBooster_Tracker::META_ATTRIBUTION] === array(-1 => 1.0), 'attribution exists before priority-10 persistence');
$item = checkout_item($tracker, $key, 36.21, 2);
check($item->get_meta('_wb_source_rule') === -1, 'legacy Smart tag remains available');
$data = analytics(array($item));
check(near($data['stats']['net_revenue'], 36.21) && near($data['stats']['tax_revenue'], 2), 'revenue and tax credit one box, not both');
check(near($data['stats']['items_sold'], 1) && $data['stats']['wb_orders'] === 1, 'one attributed unit and one order');
check(near($data['daily']['wb'][0], 36.21) && near($data['top_products'][0]['count'], 1), 'daily and product totals use attributed shares');
check($data['conversion']['add_to_cart'] === 1, 'one successful recommended add event');

// Reverse order; session loss must not tag a later organic addition.
$tracker = fresh_cart();
WooBooster_Tracker::register_recommendation(-1, array(44));
$key = WC()->cart->add_to_cart(44);
WC()->session->set(WooBooster_Tracker::SESSION_KEY, array());
check($key === WC()->cart->add_to_cart(44), 'Smart then ordinary merges');
check(quantities($key) === array(-1 => 1.0), 'organic add does not inherit existing line attribution');
WC()->cart->cart_contents = unserialize(serialize(WC()->cart->saved));
WooBooster_Tracker::register_recommendation(7, array(44));
check($key === WC()->cart->add_to_cart(44, 2), 'session-restored line merges under a different rule');
check(quantities($key) === array(-1 => 1.0, 7 => 2.0), 'each rule keeps its own units');
$data = analytics(array(checkout_item($tracker, $key, 10, 1)));
$rules = array_column($data['top_rules'], null, 'rule_id');
check(near($rules[-1]['revenue'], 10) && near($rules[7]['revenue'], 20), 'multiple rules split the value without double counting');
check(near($data['stats']['net_revenue'], 30) && near($data['stats']['items_sold'], 3), 'organic unit excluded from totals');

// Quantity edits: proportionally reduce; manual increases are not add events.
WC()->cart->set_quantity($key, 2);
check(quantities($key) === array(-1 => 0.5, 7 => 1.0), 'reduction retains attribution proportions');
WC()->cart->set_quantity($key, 4);
check(quantities($key) === array(-1 => 0.5, 7 => 1.0), 'increase does not resurrect removed attribution');
WC()->session->set(WooBooster_Tracker::SESSION_KEY, array());
WC()->cart->add_to_cart(44);
check(quantities($key) === array(-1 => 0.5, 7 => 1.0), 'ordinary addition after quantity edit stays organic');
WC()->cart->removed[$key] = WC()->cart->cart_contents[$key];
unset(WC()->cart->cart_contents[$key]);
WC()->cart->cart_contents[$key] = WC()->cart->removed[$key];
check(quantities($key) === array(-1 => 0.5, 7 => 1.0), 'remove/undo preserves the attribution snapshot');

// Existing cart metadata is honoured without crediting newly increased units.
$tracker = fresh_cart();
$key = WC()->cart->add_to_cart(44, 2);
WC()->cart->cart_contents[$key]['_wb_source_rule'] = -1;
WC()->cart->set_quantity($key, 4);
check(quantities($key) === array(-1 => 2.0), 'legacy cart increase migrates only original units');
WooBooster_Tracker::register_recommendation(9, array(44));
WC()->cart->add_to_cart(44);
check(quantities($key) === array(-1 => 2.0, 9 => 1.0), 'legacy cart supports a new rule without assigning organic units');
$tracker = fresh_cart();
$key = WC()->cart->add_to_cart(44, 2);
WC()->cart->cart_contents[$key]['_wb_source_rule'] = -1;
WC()->cart->add_to_cart(44);
check(quantities($key) === array(-1 => 2.0), 'legacy cart ordinary add preserves only old attribution');

// Genuine configuration differences and bundle unique keys still split lines.
$tracker = fresh_cart();
WooBooster_Tracker::register_recommendation(-1, array(44));
$a = WC()->cart->add_to_cart(44, 1, 441, array('attribute_size' => 'a'));
$b = WC()->cart->add_to_cart(44, 1, 442, array('attribute_size' => 'b'));
check($a !== $b, 'distinct variations stay distinct');
check($a === WC()->cart->add_to_cart(44, 1, 441, array('attribute_size' => 'a')), 'same variation merges');
$c = WC()->cart->add_to_cart(44, 1, 0, array(), array('fulfillment_source' => 'store'));
$d = WC()->cart->add_to_cart(44, 1, 0, array(), array('fulfillment_source' => 'dropship'));
check($c !== $d, 'real fulfillment data stays in cart identity');
$plain = WC()->cart->add_to_cart(44);
$bundle = WC()->cart->add_to_cart(44, 1, 0, array(), array('_woobooster_bundle_hash' => 'one', '_woobooster_bundle_id' => 1));
$bundle2 = WC()->cart->add_to_cart(44, 1, 0, array(), array('_woobooster_bundle_hash' => 'two', '_woobooster_bundle_id' => 1));
check($plain !== $bundle && $bundle !== $bundle2, 'bundles remain separate from plain items and other bundles');
WooBooster_Tracker::register_recommendation(12, array(441));
WC()->cart->add_to_cart(44, 1, 441, array('attribute_size' => 'a'));
check(quantities($a) === array(-1 => 2.0, 12 => 1.0), 'variation-specific recommendation takes precedence over parent');

// Failed additions and missing sessions cannot create credits or counter events.
$tracker = fresh_cart();
WooBooster_Tracker::register_recommendation(-1, array(44));
check(false === WC()->cart->add_to_cart(44, 1, 0, array(), array(), false), 'failed add returns false');
check(array() === get_option(WooBooster_Tracker::COUNTER_OPTION, array()), 'failed add does not increment tracking');
WC()->session = null;
$key = WC()->cart->add_to_cart(44);
check(array() === quantities($key), 'missing session safely adds an organic product');
check(array() === checkout_item($tracker, $key)->meta, 'organic checkout has no tracking metadata');
WC()->session = new AttributionSession();
WC()->session->set(WooBooster_Tracker::SESSION_KEY, 'invalid');
WC()->cart->add_to_cart(44);
check(array() === quantities($key), 'malformed session is ignored');

// Historic orders use whole-line attribution; new empty/malformed maps do not.
$legacy = new AttributionItem(2, 72.42, 4);
$legacy->meta['_wb_source_rule'] = -1;
$data = analytics(array($legacy));
check(near($data['stats']['net_revenue'], 72.42) && near($data['stats']['items_sold'], 2), 'legacy order totals remain unchanged');
$legacy->meta[WooBooster_Tracker::META_ATTRIBUTION] = array();
check(analytics(array($legacy))['stats']['wb_orders'] === 0, 'explicit empty map never falls back to whole-line credit');
$legacy->meta[WooBooster_Tracker::META_ATTRIBUTION] = 'invalid';
check(analytics(array($legacy))['stats']['wb_orders'] === 0, 'malformed new map does not inflate legacy credit');
$bad = array(WooBooster_Tracker::META_ATTRIBUTION => array(-1 => 4, 7 => 4, 0 => 8, 'bad' => 10, 8 => -1, 9 => INF, 10 => 'bad'));
check(WooBooster_Tracker::get_attribution_quantities($bad, 2) === array(-1 => 1.0, 7 => 1.0), 'invalid values removed and over-attribution bounded');
check(array() === WooBooster_Tracker::get_attribution_quantities($bad, 0), 'zero-quantity lines cannot divide by zero');
$legacy->meta[WooBooster_Tracker::META_ATTRIBUTION] = array(-1 => 99, 7 => 99);
$data = analytics(array($legacy));
check(near($data['stats']['net_revenue'], 72.42) && near($data['stats']['items_sold'], 2), 'invalid oversized metadata cannot double-count line value');

// Fractional product quantities remain bounded as well.
$tracker = fresh_cart();
$key = WC()->cart->add_to_cart(44, 0.5);
WooBooster_Tracker::register_recommendation(-1, array(44));
WC()->cart->add_to_cart(44, 0.5);
check(quantities($key) === array(-1 => 0.5), 'fractional adds track only their actual units');
WC()->cart->set_quantity($key, 0.5);
check(quantities($key) === array(-1 => 0.25), 'fractional quantity reduction is proportional');

// Proportional reductions are rounded; the legacy tag names the largest rule.
$tracker = fresh_cart();
WooBooster_Tracker::register_recommendation(-1, array(44));
$key = WC()->cart->add_to_cart(44);
WooBooster_Tracker::register_recommendation(7, array(44));
WC()->cart->add_to_cart(44, 2);
check(WC()->cart->cart_contents[$key]['_wb_source_rule'] === 7, 'cart legacy tag names the rule with the most units');
WC()->cart->set_quantity($key, 2);
check(quantities($key) === array(-1 => 0.6667, 7 => 1.3333), 'repeating decimals are rounded to four places');
$item = checkout_item($tracker, $key, 10, 1);
check($item->get_meta('_wb_source_rule') === 7 && $item->get_meta(WooBooster_Tracker::META_ATTRIBUTION) === array(-1 => 0.6667, 7 => 1.3333), 'order meta stores the rounded map and the largest rule');
check(WooBooster_Tracker::get_order_item_attribution($item) === array(-1 => 0.6667, 7 => 1.3333), 'order item helper reads the stored map');
$data = analytics(array($item));
check(near($data['stats']['net_revenue'], 20) && near($data['stats']['items_sold'], 2), 'rounded credits still add up to the line');
check(array() === WooBooster_Tracker::normalize_quantities(array(-1 => 0.00001), 1), 'credits that round to zero are dropped');
$format = new ReflectionMethod('WooBooster_Analytics', 'format_quantity');
$format->setAccessible(true);
$analytics = new WooBooster_Analytics();
check('1.5' === $format->invoke($analytics, 1.5) && '2' === $format->invoke($analytics, 2.0) && '0.7' === $format->invoke($analytics, 0.6667), 'dashboard shows fractional units with one decimal');

echo $checks . " checks passed (woobooster attribution).\n";
