<?php
/**
 * White Label — offline regression checks (no WordPress, database or network).
 *
 * Covers: clients blocked from every FFL Funnels page and from the editor of a
 * hidden post type; old flat-format imports keep their colours; the self-exempt
 * notice flag; the client dashboard only taking over for users WordPress shows
 * it to; and the 30-day sales window in the site's time zone.
 *
 * Run: php tests/smoke/white-label-smoke.php
 */

define('ABSPATH', __DIR__ . '/');
define('FFLA_PATH', dirname(__DIR__, 2) . '/');
define('FFLA_URL', 'https://shop.test/wp-content/plugins/ffl-funnels-addons/');
define('FFLA_VERSION', '0.0.0-test');
define('DAY_IN_SECONDS', 86400);

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
function wp_timezone() { return new DateTimeZone('America/New_York'); }
function wp_date($format, $timestamp) { return (new DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone())->format($format); }
function wc_get_is_paid_statuses() { return ['processing', 'completed']; }
function wc_get_orders($args)
{
    $GLOBALS['orders_queries'][] = $args;
    [$from, $to] = array_map('intval', explode('...', $args['date_created']));
    $orders = array_values(array_filter($GLOBALS['orders'], static function ($order) use ($from, $to) {
        $ts = $order->get_date_created()->getTimestamp();
        return $ts >= $from && $ts <= $to;
    }));
    return (object) ['orders' => $orders, 'max_num_pages' => 1];
}
class WooCommerce {}
class FakeOrder
{
    private $total;
    private $created;
    public function __construct(float $total, string $utc) { $this->total = $total; $this->created = new DateTimeImmutable($utc, new DateTimeZone('UTC')); }
    public function get_total() { return $this->total; }
    public function get_date_created() { return $this->created; }
}

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

/* ── Sales window in the site time zone ──────────────────────────────── */

$GLOBALS['orders'] = [
    new FakeOrder(100.0, '2026-09-08 03:30:00'), // 23:30 on Sep 7 in New York.
    new FakeOrder(40.0, '2026-08-09 04:30:00'),  // 00:30 on Aug 9 in New York (first day).
    new FakeOrder(7.0, '2026-08-09 03:30:00'),   // 23:30 on Aug 8 in New York: previous period.
];
$data = White_Label_Dashboard_Data::get('2026-08-09', '2026-09-07', true)['woo'];
[$from_ts, $to_ts] = array_map('intval', explode('...', $GLOBALS['orders_queries'][0]['date_created']));
check((new DateTimeImmutable('2026-08-09 00:00:00', wp_timezone()))->getTimestamp() === $from_ts, 'window starts at site-local midnight');
check((new DateTimeImmutable('2026-09-07 23:59:59', wp_timezone()))->getTimestamp() === $to_ts, 'window ends at site-local end of today');
check(140.0 === $data['sales'] && 2 === $data['orders'], 'late-evening local orders count in the window');
check(30 === count($data['series']) && '2026-08-09' === $data['series'][0]['date'] && '2026-09-07' === end($data['series'])['date'], '30 daily points in local dates');
check(100.0 === end($data['series'])['value'] && 40.0 === $data['series'][0]['value'], 'orders land on their local day in the chart');
check(abs($data['sales_delta'] - ((140 - 7) / 7 * 100)) < 0.1, 'previous period is the 30 local days before');

echo $checks . " checks passed (white label).\n";
