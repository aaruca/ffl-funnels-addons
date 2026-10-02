<?php
/**
 * Standalone regression harness for Loadout cart pricing, bonus and stock.
 *
 * Run: php tests/smoke/loadout-cart-smoke.php
 *
 * Covers the money/security rules of Loadout_Cart with in-memory stubs:
 *  - loadout data is never taken from the request ($_POST['loadout_context']);
 *  - "Add" requests are validated against the stored configuration
 *    (item/tier/product must match);
 *  - prices come from the configuration, keep a deeper sale price and are
 *    stable when the cart is recalculated;
 *  - the bonus is free (one unit) only while its threshold is met;
 *  - a tampered "entire tier" snapshot is rebuilt from the configuration;
 *  - bundle stock: the line's own product is not reduced twice, components
 *    follow the line quantity, refunds and restores give back exactly once.
 */

define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);

$checks = 0;
function check($test, $message)
{
    global $checks;
    $checks++;
    if (!$test) {
        throw new RuntimeException("FAIL: $message");
    }
}

function near($a, $b)
{
    return abs((float) $a - (float) $b) < 0.0001;
}

/* ── WordPress / WooCommerce stubs ─────────────────────────────────────── */

class WP_Error
{
    private $message;
    public function __construct($code = '', $message = '')
    {
        $this->message = $message;
    }
    public function get_error_message()
    {
        return $this->message;
    }
}

function __($text, $domain = '') { return $text; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function absint($v) { return abs((int) $v); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); }
function wp_json_encode($v) { return json_encode($v); }
function wp_hash($data) { return hash_hmac('md5', $data, 'smoke-salt'); }
function wp_generate_password($len = 12, $a = true, $b = true) { return substr(md5((string) mt_rand()), 0, $len); }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_wp_error($v) { return $v instanceof WP_Error; }

$GLOBALS['products'] = [];
$GLOBALS['stock_log'] = [];
function wc_get_product($id) { return $GLOBALS['products'][(int) $id] ?? null; }
function wc_update_product_stock($product, $qty, $op) {
    $product->stock += $op === 'decrease' ? -$qty : $qty;
    $GLOBALS['stock_log'][] = [$product->get_id(), $op, $qty];
    return $product->stock;
}

class StubProduct
{
    public $id; public $name; public $regular; public $price; public $stock; public $type = 'simple';
    public function __construct($id, $name, $regular, $sale = null, $stock = 50)
    {
        $this->id = $id; $this->name = $name; $this->regular = (string) $regular;
        $this->price = (string) ($sale ?? $regular); $this->stock = $stock;
        $GLOBALS['products'][$id] = $this;
    }
    public function get_id() { return $this->id; }
    public function get_name() { return $this->name; }
    public function get_regular_price() { return $this->regular; }
    public function get_price() { return $this->price; }
    public function set_price($p) { $this->price = (string) $p; }
    public function is_purchasable() { return true; }
    public function is_in_stock() { return $this->stock > 0; }
    public function has_enough_stock($q) { return $this->stock >= $q; }
    public function is_type($t) { return $this->type === $t; }
    public function managing_stock() { return true; }
    public function get_variation_attributes() { return []; }
}

/* Loadout data model stubs (the real classes talk to the database). */
class Loadout
{
    public static $rows = [];
    public $id; public $status; public $anchor;
    public static function make($id, $anchor, $status = 1) { $l = new self(); $l->id = $id; $l->anchor = $anchor; $l->status = $status; self::$rows[$id] = $l; return $l; }
    public static function get($id) { return self::$rows[(int) $id] ?? null; }
    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function get_anchor_product_id() { return $this->anchor; }
    public function get_name() { return 'Loadout ' . $this->id; }
}
class Loadout_Tier
{
    public static $rows = [];
    public $id; public $loadout_id; public $acc; public $set; public $threshold; public $bonus; public $items = [];
    public static function make($id, $loadout_id, $acc, $set, $threshold, $bonus) { $t = new self(); $t->id = $id; $t->loadout_id = $loadout_id; $t->acc = $acc; $t->set = $set; $t->threshold = $threshold; $t->bonus = $bonus; self::$rows[$id] = $t; return $t; }
    public static function get($id) { return self::$rows[(int) $id] ?? null; }
    public function get_id() { return $this->id; }
    public function get_loadout_id() { return $this->loadout_id; }
    public function get_slug() { return 'tier-' . $this->id; }
    public function get_name() { return 'Tier ' . $this->id; }
    public function get_accessory_discount() { return $this->acc; }
    public function get_set_discount_pct() { return $this->set; }
    public function get_threshold_items() { return $this->threshold; }
    public function get_bonus_product_id() { return $this->bonus; }
    public function get_items() { return $this->items; }
}
class Loadout_Tier_Item
{
    public static $rows = [];
    public $id; public $tier_id; public $product_id; public $qty; public $pct;
    public static function make($id, $tier, $product_id, $qty, $pct) { $i = new self(); $i->id = $id; $i->tier_id = $tier->id; $i->product_id = $product_id; $i->qty = $qty; $i->pct = $pct; self::$rows[$id] = $i; $tier->items[] = $i; return $i; }
    public static function get($id) { return self::$rows[(int) $id] ?? null; }
    public function get_id() { return $this->id; }
    public function get_tier_id() { return $this->tier_id; }
    public function get_product_id() { return $this->product_id; }
    public function get_quantity() { return $this->qty; }
    public function get_discount_pct() { return $this->pct; }
}
class Loadout_Product_Admin
{
    public static $custom = []; // product id => tiers
    public static $links = [];  // product id => loadout id
    public static function get_product_config(int $pid): array
    {
        if (!empty(self::$links[$pid]) && Loadout::get(self::$links[$pid]) && Loadout::get(self::$links[$pid])->get_status()) {
            return ['type' => 'global', 'loadout' => Loadout::get(self::$links[$pid]), 'tiers' => []];
        }
        $t = self::$custom[$pid] ?? [];
        return ['type' => $t ? 'custom' : 'disabled', 'loadout' => null, 'tiers' => $t];
    }
    public static function find_custom_tier(int $pid, string $slug): ?array
    {
        $c = self::get_product_config($pid);
        if ($c['type'] !== 'custom') return null;
        foreach ($c['tiers'] as $t) { if (($t['slug'] ?? '') === $slug) return $t; }
        return null;
    }
}

/* A minimal cart: lines keyed like WooCommerce, add_to_cart runs the filter. */
class StubCart
{
    public $cart_contents = [];
    private $n = 0;
    public function get_cart() { return $this->cart_contents; }
    public function add_to_cart($pid, $qty = 1)
    {
        $data = Loadout_Cart::add_cart_item_data([], $pid, 0);
        $key = 'k' . (++$this->n);
        $this->cart_contents[$key] = $data + ['product_id' => $pid, 'variation_id' => 0, 'quantity' => $qty, 'data' => clone wc_get_product($pid)];
        return $key;
    }
    public function remove_cart_item($key) { unset($this->cart_contents[$key]); return true; }
}

require dirname(__DIR__, 2) . '/modules/loadout/includes/class-loadout-pricing.php';
require dirname(__DIR__, 2) . '/modules/loadout/includes/class-loadout-cart.php';

$pending = new ReflectionProperty('Loadout_Cart', 'pending_context');
$pending->setAccessible(true);

/* ── Pricing rules ─────────────────────────────────────────────────────── */

check(near(Loadout_Pricing::unit_price(100.0, 100.0, 15), 85), '15% off $100');
check(near(Loadout_Pricing::unit_price(50.0, 40.0, 5), 40), 'a deeper sale price is kept');
check(near(Loadout_Pricing::unit_price(50.0, 45.0, 20), 40), 'loadout price wins when lower than the sale');
check(near(Loadout_Pricing::unit_price(100.0, 85.0, 15), 85), 'recalculating an already discounted line is stable');
check(near(Loadout_Pricing::unit_price(null, 30.0, 10), 27), 'no regular price: discount from the current price');
check(near(Loadout_Pricing::unit_price(100.0, 100.0, 0), 100), '0% keeps the price');
check(near(Loadout_Pricing::combine(60, 70), 100) && near(Loadout_Pricing::combine(-5, 10), 10), 'percentages add up, capped at 100, negatives ignored');
check(Loadout_Pricing::saving_percent(50.0, 40.0) === 20, 'badge shows the real saving');

/* ── Fixture ───────────────────────────────────────────────────────────── */

new StubProduct(1, 'Rifle', 1000);
new StubProduct(2, 'Optic', 100);
new StubProduct(3, 'Sling', 50, 40);
new StubProduct(4, 'Gift', 30);
new StubProduct(5, 'Unrelated', 500);
$loadout = Loadout::make(10, 1);
$tier    = Loadout_Tier::make(20, 10, 5, 10, 2, 4);
$item_a  = Loadout_Tier_Item::make(30, $tier, 2, 1, 10);
$item_b  = Loadout_Tier_Item::make(31, $tier, 3, 2, 0);
Loadout_Product_Admin::$custom[6] = [[
    'name' => 'Basic', 'slug' => 'basic', 'accessory_discount' => 5, 'set_discount_pct' => 0,
    'threshold_items' => 1, 'bonus_product_id' => 4,
    'items' => [['product_id' => 2, 'quantity' => 1, 'discount_pct' => 20]],
]];
new StubProduct(6, 'Rifle2', 800);

/* ── Request data is never trusted ─────────────────────────────────────── */

$_POST['loadout_context'] = ['loadout_id' => 10, 'tier_bundle' => 1, 'bundle_items' => [['product_id' => 5, 'quantity' => 1, 'final' => 0.01]], 'is_bonus' => 1];
$data = Loadout_Cart::add_cart_item_data(['x' => 1], 5, 0);
unset($_POST['loadout_context']);
check($data === ['x' => 1], 'a plain add-to-cart with loadout_context in the request gets no loadout data');

check(is_wp_error(Loadout_Cart::validate_item_request(['item_id' => 30], 5)), 'item ID of another product is rejected');
check(is_wp_error(Loadout_Cart::validate_item_request(['tier_id' => 20, 'loadout_id' => 10], 5)), 'a tier ID alone cannot discount any product');
check(is_wp_error(Loadout_Cart::validate_item_request(['item_id' => 30, 'tier_id' => 99], 2)), 'item with a mismatched tier is rejected');
check(is_wp_error(Loadout_Cart::validate_item_request(['loadout_id' => 10], 5)), 'only the hero product can be added with just a loadout ID');
check(is_wp_error(Loadout_Cart::validate_item_request(['tier_slug' => 'basic', 'product_loadout_id' => 6], 5)), 'per-product tier rejects products it does not list');
$ctx = Loadout_Cart::validate_item_request(['item_id' => 30], 2);
check(is_array($ctx) && $ctx['tier_id'] === 20 && $ctx['loadout_id'] === 10, 'valid item: tier and loadout come from the server');
$loadout->status = 0;
check(is_wp_error(Loadout_Cart::validate_item_request(['item_id' => 30], 2)), 'items of an inactive loadout are rejected');
$loadout->status = 1;

/* ── Pricing in the cart and the bonus ─────────────────────────────────── */

$cart = new StubCart();
$pending->setValue(null, $ctx);
$ka = $cart->add_to_cart(2, 1);
$pending->setValue(null, Loadout_Cart::validate_item_request(['item_id' => 31], 3));
$kb = $cart->add_to_cart(3, 1);
$pending->setValue(null, null);

// A forged discount in the line data is ignored.
$cart->cart_contents[$ka][Loadout_Cart::META_DISCOUNT_PCT] = 99;
Loadout_Cart::apply_loadout_pricing($cart);
Loadout_Cart::apply_loadout_pricing($cart);
check(near($cart->cart_contents[$ka]['data']->get_price(), 85), 'Optic $100 - (10% + 5%) = $85, stable on recalculation');
check(near($cart->cart_contents[$kb]['data']->get_price(), 40), 'Sling keeps its $40 sale price');

Loadout_Cart::sync_bonus_items($cart);
$bonus = null;
foreach ($cart->cart_contents as $k => $l) { if (!empty($l[Loadout_Cart::META_IS_BONUS])) { $bonus = $k; } }
check($bonus !== null, 'bonus added at the threshold (2 units)');
$cart->cart_contents[$bonus]['quantity'] = 4;
Loadout_Cart::apply_loadout_pricing($cart);
check(near($cart->cart_contents[$bonus]['data']->get_price(), 0) && $cart->cart_contents[$bonus]['quantity'] === 1, 'bonus is free and limited to one unit');

$cart->remove_cart_item($ka);
$cart->cart_contents[$bonus]['data']->set_price(30);
Loadout_Cart::apply_loadout_pricing($cart);
check(near($cart->cart_contents[$bonus]['data']->get_price(), 30), 'a bonus no longer earned is not free');
Loadout_Cart::sync_bonus_items($cart);
check(!isset($cart->cart_contents[$bonus]), 'and it is removed by the sync');

// Per-product tier: discount and bonus from the product's own tiers.
$cart = new StubCart();
$pending->setValue(null, Loadout_Cart::validate_item_request(['tier_slug' => 'basic', 'product_loadout_id' => 6], 2));
$kp = $cart->add_to_cart(2, 1);
$pending->setValue(null, null);
Loadout_Cart::sync_bonus_items($cart);
Loadout_Cart::apply_loadout_pricing($cart);
check(near($cart->cart_contents[$kp]['data']->get_price(), 75), 'per-product item $100 - (20% + 5%) = $75');
$free = false;
foreach ($cart->cart_contents as $l) { if (!empty($l[Loadout_Cart::META_IS_BONUS]) && near($l['data']->get_price(), 0)) { $free = true; } }
check($free, 'per-product bonus added and free');

/* ── Entire tier bundle ────────────────────────────────────────────────── */

$tctx  = Loadout_Cart::resolve_tier_request(['tier_id' => 20, 'loadout_id' => 10]);
$built = Loadout_Cart::build_tier_bundle($tctx);
// Rifle $1000 + Optic 100 x (1 - 25%) = $75 + 2 x Sling min($40 sale, $42.50) = $80.
check(near($built['total'], 1155) && $built['complete'], 'bundle total $1155 with set discount');
check($built['items'][0]['product_id'] === 1 && $built['items'][0]['is_anchor'] === 1, 'hero product first, as the main item');
check(Loadout_Cart::resolve_tier_request(['tier_id' => 20, 'product_loadout_id' => 5])['anchor_id'] === 1, 'an unrelated product page cannot become the main item');
Loadout_Product_Admin::$links[5] = 10;
check(Loadout_Cart::resolve_tier_request(['tier_id' => 20, 'product_loadout_id' => 5])['anchor_id'] === 5, 'a product page linked to the loadout is the main item');
unset(Loadout_Product_Admin::$links[5]);

$GLOBALS['products'][3]->stock = 0;
$partial = Loadout_Cart::build_tier_bundle($tctx);
check(!$partial['complete'] && $partial['skipped'] === ['Sling'], 'out-of-stock item left out and reported');
check(near($partial['total'], 1000 + 85), 'no set discount on an incomplete tier');
$GLOBALS['products'][3]->stock = 50;

$cart = new StubCart();
$pending->setValue(null, ['loadout_id' => 10, 'tier_id' => 20, 'tier_slug' => 'tier-20', 'product_loadout_id' => 0, 'tier_bundle' => 1, 'bundle_items' => $built['items']]);
$kt = $cart->add_to_cart(1, 1);
$pending->setValue(null, null);
Loadout_Cart::apply_loadout_pricing($cart);
check(near($cart->cart_contents[$kt]['data']->get_price(), 1155), 'bundle line charged the snapshot total');
$cart->cart_contents[$kt][Loadout_Cart::META_TIER_BUNDLE_ITEMS][1]['final'] = 0.01;
Loadout_Cart::apply_loadout_pricing($cart);
check(near($cart->cart_contents[$kt]['data']->get_price(), 1155), 'a tampered snapshot is rebuilt from the configuration');
$units = Loadout_Cart::tier_units($cart);
check(($units['g:20'] ?? 0) === 3, 'bundle counts its tier units (1 Optic + 2 Slings) toward the bonus');

/* ── Bundle stock ──────────────────────────────────────────────────────── */

$plan = Loadout_Cart::bundle_stock_plan($built['items'], 1, 2);
check($plan === [2 => 2, 3 => 4], 'line\'s own product left to WooCommerce; components follow the line quantity');
$plan = Loadout_Cart::bundle_stock_plan([['product_id' => 3, 'quantity' => 2], ['product_id' => 2, 'quantity' => 1]], 3, 1);
check($plan === [3 => 1, 2 => 1], 'without a hero, the first item\'s extra units are still reduced');

class WC_Order
{
    public $id; public $items = []; public $notes = [];
    public function get_id() { return $this->id; }
    public function get_items() { return $this->items; }
    public function get_item($id) { return $this->items[$id] ?? false; }
    public function add_order_note($n) { $this->notes[] = $n; }
    public function get_data_store() { return new class { public function get_stock_reduced($id) { return true; } }; }
}
class StubOrderItem
{
    public $meta = []; public $qty; public $pid;
    public function __construct($pid, $qty, $bundle) { $this->pid = $pid; $this->qty = $qty; $this->meta[Loadout_Cart::META_TIER_BUNDLE_ITEMS] = $bundle; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function delete_meta_data($k) { unset($this->meta[$k]); }
    public function save() {}
    public function get_quantity() { return $this->qty; }
    public function get_product_id() { return $this->pid; }
    public function get_variation_id() { return 0; }
}
class StubRefund { public function get_parent_id() { return 900; } }
$order = new WC_Order();
$order->id = 900;
$order->items[1] = new StubOrderItem(1, 2, $built['items']);
function wc_get_order($id) { global $order; return $id === 901 ? new StubRefund() : $order; }

$stock = function () { return [wc_get_product(1)->stock, wc_get_product(2)->stock, wc_get_product(3)->stock]; };
$start = $stock();
Loadout_Cart::reduce_component_stock($order);
check($stock() === [$start[0], $start[1] - 2, $start[2] - 4], 'reduce: rifle untouched (WooCommerce does it), optic -2, sling -4');
Loadout_Cart::reduce_component_stock($order);
check($stock() === [$start[0], $start[1] - 2, $start[2] - 4], 'a second reduce does nothing');

Loadout_Cart::restock_refunded_components(901, ['restock_items' => true, 'line_items' => [1 => ['qty' => 1]]]);
check($stock() === [$start[0], $start[1] - 1, $start[2] - 2], 'refund of 1 of 2 bundles gives back half');
Loadout_Cart::restore_component_stock($order);
check($stock() === $start, 'restore gives back the rest');
Loadout_Cart::restore_component_stock($order);
check($stock() === $start, 'a second restore does nothing');
Loadout_Cart::restock_refunded_components(901, ['restock_items' => true, 'line_items' => [1 => ['qty' => 1]]]);
check($stock() === $start, 'a refund after a restore does nothing');

// Order placed before this fix (no record): undo the old reduction exactly.
$legacy = new WC_Order();
$legacy->id = 902;
$legacy->items[1] = new StubOrderItem(1, 1, $built['items']);
$before = $stock();
Loadout_Cart::restore_component_stock($legacy);
check($stock() === [$before[0] + 1, $before[1] + 1, $before[2] + 2], 'legacy order: every component given back once');
Loadout_Cart::restore_component_stock($legacy);
check($stock() === [$before[0] + 1, $before[1] + 1, $before[2] + 2], 'legacy order: not twice');

echo "loadout-cart-smoke: $checks checks passed\n";
