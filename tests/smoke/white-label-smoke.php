<?php
/**
 * White Label — offline regression checks (no WordPress, database or network).
 *
 * Covers: clients blocked from every FFL Funnels page and from the editor of a
 * hidden post type; old flat-format imports keep their colours; the self-exempt
 * notice flag; the client dashboard only taking over for users WordPress shows
 * it to; and the 30-day sales figures: window and daily buckets in the site's
 * time zone (DST included), paid statuses, and the same results from the HPOS
 * and legacy post SQL (run for real on SQLite) and the wc_get_orders() path.
 *
 * Run: php tests/smoke/white-label-smoke.php
 */

define('ABSPATH', __DIR__ . '/');
define('FFLA_PATH', dirname(__DIR__, 2) . '/');
define('FFLA_URL', 'https://shop.test/wp-content/plugins/ffl-funnels-addons/');
define('FFLA_VERSION', '0.0.0-test');
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

class Redirected extends Exception {}
class Died extends Exception {}

/* ── WordPress stand-ins ─────────────────────────────────────────────── */

$GLOBALS['options'] = [];
$GLOBALS['actions'] = [];
$GLOBALS['caps'] = ['manage_woocommerce' => true, 'edit_theme_options' => true];
$GLOBALS['post_types'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['orders_queries'] = [];
$GLOBALS['orders'] = [];

class WP_User
{
    public $ID;
    public $user_email;
    public function __construct(int $id, string $email) { $this->ID = $id; $this->user_email = $email; }
    public function exists(): bool { return $this->ID > 0; }
}
$GLOBALS['current_user'] = new WP_User(1, 'owner@client-store.test');

class WP_Admin_Bar
{
    public $nodes = [];
    public $removed = [];
    public function get_nodes() { return $this->nodes; }
    public function remove_node($id) { $this->removed[] = $id; }
}

function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['actions'][] = $hook; }
function add_filter($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['actions'][] = $hook; }
function apply_filters($hook, $value) { return $value; }
function remove_menu_page($slug) {}
function remove_submenu_page($parent, $child) {}
function admin_url($path = '') { return 'https://shop.test/wp-admin/' . $path; }
function wp_safe_redirect($url) { $GLOBALS['redirect'] = $url; throw new Redirected($url); }
function wp_die($message = '') { throw new Died((string) $message); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function absint($value) { return abs((int) $value); }
function esc_url_raw($url) { return (string) $url; }
function get_post_type($id) { return $GLOBALS['post_types'][(int) $id] ?? false; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function wp_get_current_user() { return $GLOBALS['current_user']; }
function current_user_can($cap) { return !empty($GLOBALS['caps'][$cap]); }
function check_admin_referer($action, $field = '') { return true; }
function is_multisite() { return false; }
function is_super_admin($id = 0) { return false; }
function add_query_arg($args, $url) { return $url . (false === strpos($url, '?') ? '?' : '&') . http_build_query($args); }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
$GLOBALS['tz'] = 'America/New_York';
function wp_timezone() { return new DateTimeZone($GLOBALS['tz']); }
function wp_date($format, $timestamp) { return (new DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone())->format($format); }
function wc_get_is_paid_statuses() { return ['processing', 'completed']; }
$GLOBALS['cache_flushes'] = 0;
function wp_cache_supports($feature) { return 'flush_runtime' === $feature; }
function wp_cache_flush_runtime() { $GLOBALS['cache_flushes']++; return true; }
function wc_get_orders($args)
{
    $GLOBALS['orders_queries'][] = $args;
    [$from, $to] = array_map('intval', explode('...', $args['date_created']));
    // WooCommerce accepts statuses with or without the wc- prefix.
    $statuses = array_map(static function ($status) { return preg_replace('/^wc-/', '', (string) $status); }, (array) $args['status']);
    $orders = array_values(array_filter($GLOBALS['orders'], static function ($order) use ($from, $to, $statuses, $args) {
        $ts = $order->get_date_created()->getTimestamp();
        return $ts >= $from && $ts <= $to && $order->type === $args['type'] && in_array($order->status, $statuses, true);
    }));
    $limit = (int) $args['limit'];
    $page  = (int) $args['paged'];
    return (object) ['orders' => array_slice($orders, ($page - 1) * $limit, $limit), 'max_num_pages' => max(1, (int) ceil(count($orders) / $limit))];
}
class WooCommerce {}
class FakeOrder
{
    private $total;
    private $created;
    public $status;
    public $type;
    public function __construct(float $total, string $utc, string $status = 'completed', string $type = 'shop_order') { $this->total = $total; $this->created = new DateTimeImmutable($utc, new DateTimeZone('UTC')); $this->status = $status; $this->type = $type; }
    public function get_total() { return $this->total; }
    public function get_date_created() { return $this->created; }
}

// Order storage switches: HPOS on/off and the order data store class.
eval('namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { public static $hpos = false; public static function custom_orders_table_usage_is_enabled() { return self::$hpos; } public static function get_table_for_orders() { return "wp_wc_orders"; } }');
class WC_Data_Store
{
    public static $class = 'Custom_Order_Data_Store';
    public static function load($type) { return new self(); }
    public function get_current_class_name() { return self::$class; }
}

/**
 * $wpdb over SQLite: the dashboard's own SQL runs for real against
 * wc_orders, posts and postmeta tables. prepare() supports %s, %d, %i and
 * fails on a placeholder/argument count mismatch.
 */
class Sqlite_Wpdb
{
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $last_error = '';
    public $queries = [];
    public $fail = false;
    /** @var SQLite3 */
    public $db;

    public function __construct()
    {
        $this->db = new SQLite3(':memory:');
        $this->db->exec('CREATE TABLE wp_wc_orders (id INTEGER PRIMARY KEY, type TEXT, status TEXT, total_amount NUMERIC, date_created_gmt TEXT)');
        $this->db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_date TEXT, post_date_gmt TEXT)');
        $this->db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
    }

    public function prepare($query, ...$args)
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        $sql = preg_replace_callback('/%(%|d|s|i)/', static function ($m) use (&$i, $args) {
            if ('%' === $m[1]) {
                return '%';
            }
            if (!array_key_exists($i, $args)) {
                throw new LogicException('prepare(): fewer arguments than placeholders');
            }
            $v = $args[$i++];
            if ('d' === $m[1]) {
                return (string) (int) $v;
            }
            if ('i' === $m[1]) {
                return '`' . str_replace('`', '``', (string) $v) . '`';
            }
            return "'" . SQLite3::escapeString((string) $v) . "'";
        }, $query);
        if ($i !== count($args)) {
            throw new LogicException('prepare(): more arguments than placeholders');
        }
        return $sql;
    }

    public function get_results($sql, $output = 'OBJECT')
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        $result = $this->fail ? false : @$this->db->query($sql);
        if (!$result) {
            $this->last_error = $this->fail ? 'Table is marked as crashed' : $this->db->lastErrorMsg();
            return null;
        }
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }
}
$GLOBALS['wpdb'] = new Sqlite_Wpdb();

require FFLA_PATH . 'modules/white-label/includes/class-white-label-settings.php';
require FFLA_PATH . 'modules/white-label/includes/class-white-label-access.php';
require FFLA_PATH . 'modules/white-label/includes/class-white-label-restrictions.php';
require FFLA_PATH . 'modules/white-label/includes/class-white-label-dashboard.php';
require FFLA_PATH . 'modules/white-label/includes/class-white-label-dashboard-data.php';
require FFLA_PATH . 'modules/white-label/admin/class-white-label-admin.php';

/* ── Restrictions: FFL Funnels pages and hidden post types ───────────── */

function request(string $pagenow, array $get = [], array $post = []): string
{
    $GLOBALS['pagenow'] = $pagenow;
    $_GET = $get;
    $_POST = $post;
    $GLOBALS['redirect'] = '';
    $restrictions = new White_Label_Restrictions([
        'hidden_menu' => ['edit.php?post_type=page', 'upload.php', 'woocommerce::wc-settings'],
    ]);
    try {
        $restrictions->block_pages();
    } catch (Redirected $e) {
        return 'blocked';
    }
    return 'open';
}

// The FFL Funnels menu as registered by the admin shell: dashboard + module pages.
$GLOBALS['submenu'] = ['ffl-funnels-addons' => [
    ['Dashboard', 'manage_woocommerce', 'ffl-funnels-addons'],
    ['White Label', 'manage_woocommerce', 'ffla-white-label'],
    ['Settings', 'manage_woocommerce', 'ffla-order-badges'],
]];

check('blocked' === request('admin.php', ['page' => 'ffl-funnels-addons']), 'FFL Funnels dashboard blocked for clients');
check('blocked' === request('admin.php', ['page' => 'ffla-order-badges']), 'another FFL module page is blocked by URL');
check(false !== strpos($GLOBALS['redirect'], 'wp-admin/'), 'blocked page redirects to the dashboard');
check('open' === request('admin.php', ['page' => 'wc-orders']), 'pages outside FFL Funnels stay open');
check('blocked' === request('admin.php', ['page' => 'wc-settings']), 'a hidden submenu page is still blocked');

$GLOBALS['post_types'] = [10 => 'page', 11 => 'post', 12 => 'attachment', 13 => 'product'];
check('blocked' === request('edit.php', ['post_type' => 'page']), 'hidden list itself is blocked');
check('blocked' === request('post-new.php', ['post_type' => 'page']), 'adding a hidden post type is blocked');
check('blocked' === request('post.php', ['post' => '10', 'action' => 'edit']), 'editing a hidden post type is blocked');
check('blocked' === request('post.php', [], ['post_ID' => '10']), 'saving a hidden post type through post.php is blocked');
check('blocked' === request('post.php', ['post' => '12', 'action' => 'edit']), 'hiding Media blocks editing attachments');
check('open' === request('post.php', ['post' => '11', 'action' => 'edit']), 'posts stay editable when only Pages are hidden');
check('open' === request('post-new.php'), 'new post (type post) stays open');
check('open' === request('post.php', ['post' => '13', 'action' => 'edit']), 'products stay editable');

// Admin bar: nodes linking to an FFL Funnels page are removed automatically.
$bar = new WP_Admin_Bar();
$bar->nodes = [
    'ffla-node'  => (object) ['id' => 'ffla-node', 'href' => 'https://shop.test/wp-admin/admin.php?page=ffla-white-label'],
    'orders'     => (object) ['id' => 'orders', 'href' => 'https://shop.test/wp-admin/admin.php?page=wc-orders'],
    'pages-new'  => (object) ['id' => 'pages-new', 'href' => 'https://shop.test/wp-admin/edit.php?post_type=page'],
];
(new White_Label_Restrictions(['hidden_menu' => ['edit.php?post_type=page']]))->remove_admin_bar_nodes($bar);
check(['ffla-node', 'pages-new'] === $bar->removed, 'admin bar drops FFL Funnels and hidden-page nodes only');

/* ── Import: old flat colours survive; self-exempt flag ──────────────── */

function import_json(array $payload): string
{
    $_POST = ['ffla_wl_import_json' => json_encode($payload)];
    $_FILES = [];
    White_Label_Settings::flush_cache();
    White_Label_Access::flush_cache();
    try {
        (new White_Label_Admin())->handle_import();
    } catch (Redirected $e) {
        return $e->getMessage();
    }
    return '';
}

$url = import_json(['marker' => 'ffla_white_label', 'settings' => [
    'styles' => ['primaryColor' => '#ff6600', 'sidebarBg' => '#101010', 'dashRadius' => 8, 'bogus' => 'red'],
]]);
$saved = $GLOBALS['options']['ffla_white_label_settings'];
check('#ff6600' === ($saved['styles']['dark']['primaryColor'] ?? ''), 'flat-format import keeps colours as the dark palette');
check('#101010' === ($saved['styles']['dark']['sidebarBg'] ?? ''), 'every flat colour is kept');
check([] === $saved['styles']['light'], 'light palette starts empty, as when reading old settings');
check(8 === ($saved['styles']['dashRadius'] ?? null), 'flat-format radius is kept');
check(!isset($saved['styles']['dark']['bogus']), 'unknown keys are still dropped');
check(false !== strpos($url, 'ffla_wl_import=success') && false === strpos($url, 'ffla_wl_self_exempt'), 'no self-exempt flag when restrictions stay off');

$url = import_json(['styles' => ['light' => ['primaryColor' => '#123456'], 'dark' => []], 'restrictions' => ['exempt_emails' => ['*@agency.test']]]);
$saved = $GLOBALS['options']['ffla_white_label_settings'];
check('#123456' === $saved['styles']['light']['primaryColor'], 'current light/dark format imports unchanged');
check(in_array('owner@client-store.test', $saved['restrictions']['exempt_emails'], true), 'importer added to the exempt list');
check(false !== strpos($url, 'ffla_wl_self_exempt=1'), 'import redirect carries the self-exempt notice flag');

/* ── Client dashboard: only for users who get the welcome panel ──────── */

$GLOBALS['actions'] = [];
$GLOBALS['caps']['edit_theme_options'] = false; // e.g. Shop Manager.
(new White_Label_Dashboard(['enabled' => true]))->register_hooks();
check(['wp_ajax_ffla_wl_dashboard_analytics'] === $GLOBALS['actions'], 'Shop Manager keeps the standard dashboard (widgets not stripped)');

$GLOBALS['actions'] = [];
$GLOBALS['caps']['edit_theme_options'] = true;
(new White_Label_Dashboard(['enabled' => true]))->register_hooks();
check(in_array('welcome_panel', $GLOBALS['actions'], true) && in_array('wp_dashboard_setup', $GLOBALS['actions'], true), 'administrators get the client dashboard');

$GLOBALS['actions'] = [];
(new White_Label_Dashboard(['enabled' => false]))->register_hooks();
check([] === $GLOBALS['actions'], 'nothing registers while the dashboard is off');

/* ── Sales: window, buckets, statuses and order storages ─────────────── */

/**
 * Put the same orders in the fake wc_get_orders() list, the HPOS table and
 * the legacy posts/postmeta tables.
 *
 * @param array<int, array{0: float, 1: string, 2?: string, 3?: string}> $specs total, UTC created, status, type
 */
function seed_orders(array $specs): void
{
    $db = $GLOBALS['wpdb']->db;
    foreach (['wp_wc_orders', 'wp_posts', 'wp_postmeta'] as $table) {
        $db->exec("DELETE FROM $table");
    }
    $GLOBALS['orders'] = [];
    foreach ($specs as $i => $spec) {
        [$total, $utc] = $spec;
        $status = $spec[2] ?? 'completed';
        $type   = $spec[3] ?? 'shop_order';
        $id     = $i + 1;
        $GLOBALS['orders'][] = new FakeOrder($total, $utc, $status, $type);
        $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
        $db->exec(sprintf("INSERT INTO wp_wc_orders VALUES (%d, '%s', 'wc-%s', %F, '%s')", $id, $type, $status, $total, $utc));
        $db->exec(sprintf("INSERT INTO wp_posts VALUES (%d, '%s', 'wc-%s', '%s', '%s')", $id, $type, $status, $local, $utc));
        $db->exec(sprintf("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (%d, '_order_total', '%s')", $id, (string) $total));
    }
}

function use_storage(string $storage): void
{
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = 'hpos' === $storage;
    WC_Data_Store::$class = 'posts' === $storage ? 'WC_Order_Data_Store_CPT' : 'Custom_Order_Data_Store';
    $GLOBALS['orders_queries'] = [];
    $GLOBALS['cache_flushes'] = 0;
    $GLOBALS['wpdb']->queries = [];
    $GLOBALS['wpdb']->fail = false;
    $GLOBALS['transients'] = [];
}

function series_value(array $data, string $day): float
{
    foreach ($data['series'] as $point) {
        if ($day === $point['date']) {
            return $point['value'];
        }
    }
    return -1.0;
}

// The original window check, on the wc_get_orders() path.
use_storage('api');
seed_orders([
    [100.0, '2026-09-08 03:30:00'], // 23:30 on Sep 7 in New York.
    [40.0, '2026-08-09 04:30:00'],  // 00:30 on Aug 9 in New York (first day).
    [7.0, '2026-08-09 03:30:00'],   // 23:30 on Aug 8 in New York: previous period.
]);
$data = White_Label_Dashboard_Data::get('2026-08-09', '2026-09-07', true)['woo'];
check(1 === count($GLOBALS['orders_queries']), 'one paginated pass covers both periods');
[$from_ts, $to_ts] = array_map('intval', explode('...', $GLOBALS['orders_queries'][0]['date_created']));
check((new DateTimeImmutable('2026-07-10 00:00:00', wp_timezone()))->getTimestamp() === $from_ts, 'window starts at site-local midnight of the previous period');
check((new DateTimeImmutable('2026-09-07 23:59:59', wp_timezone()))->getTimestamp() === $to_ts, 'window ends at site-local end of today');
check(140.0 === $data['sales'] && 2 === $data['orders'], 'late-evening local orders count in the window');
check(30 === count($data['series']) && '2026-08-09' === $data['series'][0]['date'] && '2026-09-07' === end($data['series'])['date'], '30 daily points in local dates');
check(100.0 === end($data['series'])['value'] && 40.0 === $data['series'][0]['value'], 'orders land on their local day in the chart');
check(abs($data['sales_delta'] - ((140 - 7) / 7 * 100)) < 0.1, 'previous period is the 30 local days before');

// One fixture through all three paths. Window Oct 6 – Nov 4, 2026 in New
// York (DST ends Nov 1); previous period Sep 6 – Oct 5.
$fixture = [
    [100.0, '2026-11-02 04:30:00'],                      // 23:30 Nov 1 (EST): Nov 1.
    [40.0, '2026-11-01 04:30:00', 'processing'],         // 00:30 Nov 1 (EDT): Nov 1.
    [7.0, '2026-11-01 03:30:00'],                        // 23:30 Oct 31 (EDT): Oct 31.
    [25.5, '2026-10-06 04:00:00'],                       // First second of the window.
    [3.25, '2026-10-06 03:59:59'],                       // Last second of the previous period.
    [60.0, '2026-11-05 04:59:59', 'ffla-ready'],         // Ready for Pickup, last second of the window.
    [999.0, '2026-11-05 05:00:00'],                      // After the window.
    [500.0, '2026-10-20 12:00:00', 'pending'],           // Not paid.
    [80.0, '2026-10-20 12:00:00', 'cancelled'],          // Not paid.
    [15.75, '2026-10-15 16:00:00', 'on-hold'],           // Not paid.
    [-10.0, '2026-10-21 12:00:00', 'completed', 'shop_order_refund'],
    [70.0, '2026-10-22 12:00:00', 'active', 'shop_subscription'],
    [12.0, '2026-09-06 04:00:00', 'processing'],         // First second of the previous period.
    [2.0, '2026-09-06 03:59:59'],                        // Before the previous period.
    [33.33, '2026-10-15 16:00:00'],
];
seed_orders($fixture);

$results = [];
foreach (['hpos', 'posts', 'api'] as $storage) {
    use_storage($storage);
    $results[$storage] = White_Label_Dashboard_Data::get('2026-10-06', '2026-11-04', true)['woo'];
    $woo = $results[$storage];
    check(is_array($woo), "$storage: sales load");
    check(abs($woo['sales'] - 265.83) < 0.001 && 6 === $woo['orders'], "$storage: paid shop orders in the window, Ready for Pickup included");
    check(abs($woo['average_order_value'] - 44.31) < 0.001, "$storage: average order value");
    check(200.0 === $woo['orders_delta'] && abs($woo['sales_delta'] - 1643.1) < 0.001, "$storage: previous period from its first local second");
    check(30 === count($woo['series']) && 140.0 === series_value($woo, '2026-11-01') && 7.0 === series_value($woo, '2026-10-31'), "$storage: orders on both sides of the DST change land on their local day");
    check(25.5 === series_value($woo, '2026-10-06') && 60.0 === series_value($woo, '2026-11-04') && 33.33 === series_value($woo, '2026-10-15'), "$storage: first and last local second of the window");
    check(0.0 === series_value($woo, '2026-10-20') && 0.0 === series_value($woo, '2026-10-21') && 0.0 === series_value($woo, '2026-10-22'), "$storage: pending, cancelled, refunds and subscriptions are left out");
}
check($results['hpos'] === $results['posts'] && $results['posts'] === $results['api'], 'HPOS, legacy posts and wc_get_orders() give identical figures');

use_storage('hpos');
White_Label_Dashboard_Data::get('2026-10-06', '2026-11-04', true);
check([] === $GLOBALS['orders_queries'] && 1 === count($GLOBALS['wpdb']->queries), 'HPOS: one SQL query, no order objects');
check(false !== strpos($GLOBALS['wpdb']->queries[0], '`wp_wc_orders`') && false !== strpos($GLOBALS['wpdb']->queries[0], "'wc-ffla-ready'"), 'HPOS: reads wc_orders with prefixed statuses');

use_storage('posts');
White_Label_Dashboard_Data::get('2026-10-06', '2026-11-04', true);
check([] === $GLOBALS['orders_queries'] && 1 === count($GLOBALS['wpdb']->queries), 'legacy posts: one SQL query, no order objects');
check(false !== strpos($GLOBALS['wpdb']->queries[0], "p.post_date >= '2026-09-05 04:00:00'"), 'legacy posts: post_date range widened by a day for the index');

// Legacy: get_total() reads the first _order_total; a missing one is 0.
$db = $GLOBALS['wpdb']->db;
$db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (15, '_order_total', '9999')");
$db->exec("INSERT INTO wp_posts VALUES (99, 'shop_order', 'wc-completed', '2026-10-16 08:00:00', '2026-10-16 12:00:00')");
use_storage('posts');
$woo = White_Label_Dashboard_Data::get('2026-10-06', '2026-11-04', true)['woo'];
check(abs($woo['sales'] - 265.83) < 0.001 && 7 === $woo['orders'], 'legacy posts: duplicate _order_total ignored, order without a total counts as 0');

// A failed query is not a zero-sales result and is not cached.
foreach (['hpos', 'posts'] as $storage) {
    use_storage($storage);
    $GLOBALS['wpdb']->fail = true;
    $data = White_Label_Dashboard_Data::get('2026-10-06', '2026-11-04', true);
    check(null === $data['woo'] && [] === $GLOBALS['transients'], "$storage: failed query shows unavailable and is not cached");
}

// wc_get_orders() path: pages oldest first, object cache released between pages.
$many = [];
for ($i = 0; $i < 450; $i++) {
    $many[] = [1.0, gmdate('Y-m-d H:i:s', strtotime('2026-10-10 12:00:00 UTC') + $i * 60)];
}
seed_orders($many);
use_storage('api');
$woo = White_Label_Dashboard_Data::get('2026-10-06', '2026-11-04', true)['woo'];
check(450 === $woo['orders'] && 450.0 === $woo['sales'], 'wc_get_orders(): every page counted');
check(3 === count($GLOBALS['orders_queries']) && 2 === $GLOBALS['cache_flushes'], 'wc_get_orders(): runtime cache released between pages only');
check('ID' === $GLOBALS['orders_queries'][0]['orderby'] && 'ASC' === $GLOBALS['orders_queries'][0]['order'], 'wc_get_orders(): stable oldest-first paging');

// Where DST skips midnight (Chile, Sep 6 2026: 00:00 -> 01:00), later days
// still start at midnight.
$GLOBALS['tz'] = 'America/Santiago';
seed_orders([
    [5.0, '2026-09-07 03:30:00'],  // 00:30 Sep 7 local (-03).
    [9.0, '2026-09-06 05:30:00'],  // 02:30 Sep 6 local (-03).
]);
foreach (['hpos', 'posts', 'api'] as $storage) {
    use_storage($storage);
    $woo = White_Label_Dashboard_Data::get('2026-09-01', '2026-09-10', true)['woo'];
    check(5.0 === series_value($woo, '2026-09-07') && 9.0 === series_value($woo, '2026-09-06'), "$storage: days after a skipped midnight still start at 00:00");
}
$GLOBALS['tz'] = 'America/New_York';

echo $checks . " checks passed (white label).\n";
