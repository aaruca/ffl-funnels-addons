<?php
/**
 * Standalone regression harness for the Order Badges module.
 *
 * Run: php tests/smoke/order-badges-smoke.php
 *
 * Covers classification (badge tags, Online Only, deleted products), the
 * label de-duplication key (multibyte, symbols, non-Latin names), the order-level
 * union, and settings-save validation against crafted input.
 */

define('ABSPATH', __DIR__);
define('FFLA_PATH', dirname(__DIR__, 2) . '/');
define('FFLA_URL', 'https://example.test/wp-content/plugins/ffl-funnels-addons/');
define('FFLA_VERSION', 'test');

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

$GLOBALS['options']  = [];
$GLOBALS['tags']     = []; // term_id => WP_Term
$GLOBALS['products'] = []; // product_id => [term_id, ...] (absent = deleted)
$GLOBALS['enqueued'] = [];
$GLOBALS['redirect'] = null;

class WP_Term
{
    public $term_id;
    public $name;
    public function __construct(int $id, string $name)
    {
        $this->term_id = $id;
        $this->name    = $name;
    }
}
class WP_Screen
{
    public $id;
}
class WC_Order_Item
{
}
class WC_Order_Item_Product extends WC_Order_Item
{
    private $product_id;
    public function __construct(int $product_id)
    {
        $this->product_id = $product_id;
    }
    public function get_product_id()
    {
        return $this->product_id;
    }
}
class WC_Order_Item_Shipping extends WC_Order_Item
{
}
class WC_Order
{
    private $items;
    public function __construct(array $items)
    {
        $this->items = $items;
    }
    public function get_items()
    {
        return $this->items;
    }
}
class FFLA_Admin
{
    public static function render_notice(string $type, string $message): void
    {
    }
}
class Redirected extends Exception
{
}

function __($text, $domain = '') { return $text; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8', false); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8', false); }
function esc_html__($s, $d = '') { return esc_html($s); }
function esc_url($s) { return (string) $s; }
function is_admin() { return true; }
function add_action() {}
function add_filter() {}
function absint($v) { return abs((int) $v); }
function wp_unslash($v) { return $v; }
function current_user_can($cap) { return true; }
function check_admin_referer($a, $b) { return true; }
function wp_die($m) { throw new RuntimeException('wp_die: ' . $m); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function wp_safe_redirect($url) { $GLOBALS['redirect'] = $url; throw new Redirected($url); }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function sanitize_hex_color($c)
{
    return preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $c) ? (string) $c : null;
}
function get_terms($args)
{
    $tags = $GLOBALS['tags'];
    if (isset($args['include'])) {
        $tags = array_intersect_key($tags, array_flip($args['include']));
    }
    if (($args['fields'] ?? '') === 'ids') {
        return array_map('strval', array_keys($tags)); // WP returns numeric strings.
    }
    return array_values($tags);
}
function get_term($id, $tax) { return $GLOBALS['tags'][$id] ?? null; }
function get_post_type($id) { return array_key_exists($id, $GLOBALS['products']) ? 'product' : false; }
function get_the_terms($id, $tax)
{
    if (!array_key_exists($id, $GLOBALS['products'])) {
        return false;
    }
    $terms = [];
    foreach ($GLOBALS['products'][$id] as $tid) {
        $terms[] = $GLOBALS['tags'][$tid];
    }
    return $terms ?: false;
}
function _prime_post_caches($ids, $a = true, $b = true) { $GLOBALS['primed_posts'][] = $ids; }
function update_object_term_cache($ids, $type) { $GLOBALS['primed_terms'][] = $ids; }
function wc_get_order($id) { return false; }
function wp_enqueue_style($h) { $GLOBALS['enqueued'][] = ['style', $h]; }
function wp_enqueue_script($h, $src = '', $deps = [], $ver = false, $footer = false)
{
    $GLOBALS['enqueued'][] = ['script', $h, $deps];
}
function wp_script_is($h, $what) { return 'selectWoo' === $h; }
function wp_style_is($h, $what) { return true; }

require FFLA_PATH . 'includes/class-ffla-module.php';
require FFLA_PATH . 'modules/order-badges/class-order-badges-module.php';

/* ── Helpers ──────────────────────────────────────────────────────────── */

function module(): Order_Badges_Module
{
    return new Order_Badges_Module(); // Fresh instance = fresh per-request memo.
}
function badges(string $html): array
{
    preg_match_all('/<span class="ffla-ob-badge"[^>]*>([^<]*)<\/span>/', $html, $m);
    return $m[1];
}
function call(object $obj, string $method, ...$args)
{
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invoke($obj, ...$args);
}
function save(array $post): array
{
    $_POST = ['ffla_ob' => $post];
    try {
        module()->handle_save();
    } catch (Redirected $e) {
        // Expected: handle_save redirects and exits.
    }
    return $GLOBALS['options']['ffla_order_badges_settings'];
}

/* ── Fixtures ─────────────────────────────────────────────────────────── */

foreach ([
    10 => 'In Store',
    11 => 'Firearm',
    12 => '🔥 Hot',
    13 => '⭐ Hot',
    14 => '日本',
    15 => '中国',
    16 => 'Online only',
    17 => 'Tienda Física',
    18 => 'Ammo',
] as $id => $name) {
    $GLOBALS['tags'][$id] = new WP_Term($id, $name);
}
$GLOBALS['products'] = [
    100 => [10, 11],          // In-store firearm.
    101 => [11, 12, 13],      // Online firearm with two distinct "Hot" tags.
    102 => [14, 15],          // Non-Latin tags.
    103 => [16],              // Has an "Online only" TAG and no in-store tag.
    104 => [],                // No tags at all.
    // 999 is deliberately absent: a product deleted after it was ordered.
];

/* ── Module identity ──────────────────────────────────────────────────── */

$m = module();
check('order-badges' === $m->get_id(), 'module id is order-badges');
check($m->get_path() === FFLA_PATH . 'modules/order-badges/', 'module path matches the renamed folder');
check(file_exists($m->get_path() . 'admin/css/order-badges-module.css'), 'module stylesheet is where FFLA_Admin auto-loads it');
check(!file_exists($m->get_path() . 'admin/js/order-badges-module.js'), 'settings script is not auto-loaded without its dependencies');
check(file_exists($m->get_path() . 'admin/js/order-badges-settings.js'), 'settings script exists');

/* ── Settings save validation ─────────────────────────────────────────── */

$saved = save([
    'badge_tags'   => ['11', '12', '13', '14', '15', '16', '999', ['nested'], '12'],
    'instore_tags' => ['10', 'abc', '-5', '0'],
    'colors'       => ['11' => '#ff0000', '12' => 'not-a-colour', '999' => '#00ff00', '13' => ['#123456'], '10' => '#abcdef'],
    'online_color' => ['#000000'],
]);
check([11, 12, 13, 14, 15, 16] === $saved['badge_tags'], 'only real, unique badge tag IDs are saved, in submitted order');
check([10] === $saved['instore_tags'], 'invalid in-store IDs are dropped');
check(['11' => '#ff0000'] == $saved['colors'], 'only valid colours for selected badge tags are saved');
check('#0f766e' === $saved['online_color'], 'a non-scalar online colour falls back to the default');
check(false !== strpos((string) $GLOBALS['redirect'], 'page=ffla-order-badges'), 'save redirects back to the settings page');

$empty = save(['badge_tags' => [], 'instore_tags' => []]);
check([] === $empty['badge_tags'] && [] === $empty['instore_tags'], 'an empty selection never matches every tag');

save([
    'badge_tags'   => ['11', '12', '13', '14', '15', '16', '17'],
    'instore_tags' => ['10'],
    'colors'       => ['11' => '#ff0000'],
    'online_color' => '#123abc',
]);

/* ── Product classification ───────────────────────────────────────────── */

$set = call(module(), 'product_badge_set', 100);
check(['Firearm'] === array_column($set['tags'], 'name') && false === $set['online'], 'in-store product: its badge tags, not Online Only');

$set = call(module(), 'product_badge_set', 101);
check(true === $set['online'], 'product without an in-store tag is Online Only');

$set = call(module(), 'product_badge_set', 999);
check([] === $set['tags'] && false === $set['online'], 'deleted product gets no badges (not Online Only)');

$set = call(module(), 'product_badge_set', 0);
check([] === $set['tags'] && false === $set['online'], 'product id 0 gets no badges');

/* ── De-duplication key ───────────────────────────────────────────────── */

$html = call(module(), 'badges_html', call(module(), 'product_badge_set', 101));
check(['Firearm', '🔥 Hot', '⭐ Hot', 'Online Only'] === badges($html), 'emoji-distinct tags stay separate; Online Only appended');

$html = call(module(), 'badges_html', call(module(), 'product_badge_set', 102));
check(['日本', '中国', 'Online Only'] === badges($html), 'non-Latin tag names never collapse into one badge');

$html = call(module(), 'badges_html', call(module(), 'product_badge_set', 103));
check(['Online only'] === badges($html), 'an "Online only" tag and the Online Only status badge collapse into one');

$k = module();
check(call($k, 'badge_key', 'Online-Only') === call($k, 'badge_key', 'online only'), 'case and punctuation are ignored');
check(call($k, 'badge_key', 'Tienda Física') !== call($k, 'badge_key', 'Tienda Fisica'), 'accented letters are kept');
check('' !== call($k, 'badge_key', '---'), 'a punctuation-only label still has a non-empty key');
check(call($k, 'badge_key', '---') !== call($k, 'badge_key', '***'), 'different punctuation-only labels stay distinct');

/* ── Order-level union and output escaping ────────────────────────────── */

$GLOBALS['primed_posts'] = $GLOBALS['primed_terms'] = [];
$order = new WC_Order([
    new WC_Order_Item_Product(100),
    new WC_Order_Item_Product(101),
    new WC_Order_Item_Product(999),
    new WC_Order_Item_Shipping(),
]);
$om  = module();
$set = call($om, 'order_badge_set', $order);
check([11, 12, 13] === array_keys($set['tags']) && true === $set['online'], 'order set is the union of its product lines');
check([[100, 101, 999]] === $GLOBALS['primed_posts'] && [[100, 101, 999]] === $GLOBALS['primed_terms'], 'post and term caches are primed once per order');

ob_start();
$om->render_column('ffla_order_badges', $order);
$cell = ob_get_clean();
check(['Firearm', '🔥 Hot', '⭐ Hot', 'Online Only'] === badges($cell), 'orders-list cell renders the order badges');
check(false !== strpos($cell, '--ffla-c:#ff0000'), 'configured colour reaches the badge');

ob_start();
$om->render_column('order_status', $order);
check('' === ob_get_clean(), 'other columns are left alone');

$GLOBALS['tags'][19] = new WP_Term(19, '<script>alert(1)</script>');
$GLOBALS['products'][105] = [19];
save(['badge_tags' => ['19'], 'instore_tags' => []]);
$html = call(module(), 'badges_html', call(module(), 'product_badge_set', 105));
check(false === strpos($html, '<script>') && false !== strpos($html, '&lt;script&gt;'), 'tag names are escaped');

/* ── Settings assets ──────────────────────────────────────────────────── */

$GLOBALS['enqueued'] = [];
module()->enqueue_settings_assets('edit.php');
check([] === $GLOBALS['enqueued'], 'nothing is enqueued off the settings page');

module()->enqueue_settings_assets('ffl-funnels_page_ffla-order-badges');
$script = array_values(array_filter($GLOBALS['enqueued'], function ($e) { return 'script' === $e[0]; }));
check(1 === count($script) && 'ffla-order-badges-settings' === $script[0][1], 'settings script enqueued on its page');
check(['jquery', 'wp-color-picker', 'selectWoo'] === $script[0][2], 'settings script declares its real dependencies');

echo $checks . " checks passed (order badges).\n";
