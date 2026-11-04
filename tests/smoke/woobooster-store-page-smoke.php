<?php
/**
 * WooBooster client page (WooCommerce → Bundles & Recommendations): offline
 * regression checks, no WordPress or database.
 *
 * Covers: the page is registered under WooCommerce, so White Label does not
 * block it while FFL Funnels pages stay blocked; links, redirects and GET
 * forms stay on the client page there and on FFL Funnels pages elsewhere;
 * the client nonce only opens the client-safe AJAX calls; the client
 * settings save changes only its own keys; the analytics previous period is
 * as long as the range.
 *
 * Run: php tests/smoke/woobooster-store-page-smoke.php
 */

define('ABSPATH', __DIR__ . '/');
define('FFLA_PATH', dirname(__DIR__, 2) . '/');
define('FFLA_URL', 'https://shop.test/wp-content/plugins/ffl-funnels-addons/');
define('FFLA_VERSION', '0.0.0-test');
define('WOOBOOSTER_VERSION', FFLA_VERSION);

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
$GLOBALS['submenu'] = [];
$GLOBALS['caps'] = ['manage_woocommerce' => true];
$GLOBALS['cache_cleared'] = 0;
$GLOBALS['cron_scheduled'] = 0;

function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return $text; }
function esc_attr__($text, $domain = '') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function add_action($hook, $callback, $priority = 10, $args = 1) { $GLOBALS['actions'][$hook][] = $callback; }
function add_filter($hook, $callback, $priority = 10, $args = 1) { add_action($hook, $callback, $priority, $args); }
function admin_url($path = '') { return 'https://shop.test/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . (false === strpos($url, '?') ? '?' : '&') . http_build_query($args); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function html_entity_decode_stub($s) { return $s; }
function current_user_can($cap) { return !empty($GLOBALS['caps'][$cap]); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; $GLOBALS['autoload'][$key] = $autoload; return true; }
function wp_create_nonce($action) { return 'n-' . substr(md5($action), 0, 10); }
function wp_verify_nonce($nonce, $action) { return $nonce === wp_create_nonce($action) ? 1 : false; }
function wp_nonce_field($action, $name) { echo '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '">'; }
function wp_safe_redirect($url) { $GLOBALS['redirect'] = $url; throw new Redirected($url); }
function wp_die($message = '', $code = 0) { throw new Died((string) $message . '|' . $code); }
function add_submenu_page($parent, $page_title, $menu_title, $cap, $slug, $callback = null)
{
    $GLOBALS['submenu'][$parent][] = [$menu_title, $cap, $slug, $page_title];
    return 'woocommerce' === $parent ? 'woocommerce_page_' . $slug : $parent . '_page_' . $slug;
}
function wp_enqueue_style(...$args) { $GLOBALS['styles'][] = $args[0]; }
function wp_enqueue_script(...$args) { $GLOBALS['scripts'][] = $args[0]; }
function wp_add_inline_style($handle, $css) { $GLOBALS['inline_css'] = $css; }

class FFLA_Admin
{
    public static function render_notice(string $type, string $message): void { echo '<div class="notice-' . $type . '">' . $message . '</div>'; }
    public static function render_toggle_field(string $label, string $name, string $value, string $desc): void { echo '<input type="checkbox" name="' . $name . '"' . ('1' === $value ? ' checked' : '') . '><p>' . esc_html($desc) . '</p>'; }
    public static function render_text_field(string $label, string $name, string $value, string $desc): void { echo '<input type="text" name="' . $name . '" value="' . esc_attr($value) . '">'; }
}
class WooBooster_Matcher { public static function invalidate_recommendation_cache() { $GLOBALS['cache_cleared']++; } }
$GLOBALS['counts'] = ['bundles' => 3, 'rules' => 0];
class WooBooster_Bundle { public static function count($args = []) { return $GLOBALS['counts']['bundles']; } }
class WooBooster_Rule { public static function count($args = []) { return $GLOBALS['counts']['rules']; } }
function number_format_i18n($number, $decimals = 0) { return number_format((float) $number, $decimals); }
class WooBooster_Cron { public static function schedule() { $GLOBALS['cron_scheduled']++; } }

require FFLA_PATH . 'modules/woobooster/admin/class-woobooster-admin.php';
require FFLA_PATH . 'modules/woobooster/admin/class-woobooster-store-page.php';
require FFLA_PATH . 'modules/white-label/includes/class-white-label-restrictions.php';

/* ── Menu: under WooCommerce, not under FFL Funnels ──────────────────── */

$store = new WooBooster_Store_Page(new WooBooster_Admin());
$store->init();
check(isset($GLOBALS['actions']['admin_menu']), 'client page hooks admin_menu');
$store->register_menu();
$slugs = array_column($GLOBALS['submenu']['woocommerce'] ?? [], 2);
check(in_array('ffla-woobooster-store', $slugs, true), 'Bundles & Recommendations is a WooCommerce submenu page');
check(isset($GLOBALS['actions']['load-woocommerce_page_ffla-woobooster-store']), 'tab renders on load-{hook}, before output');

// FFL Funnels pages as the admin shell registers them.
$GLOBALS['submenu']['ffl-funnels-addons'] = [
    ['Dashboard', 'manage_woocommerce', 'ffl-funnels-addons'],
    ['WB Settings', 'manage_woocommerce', 'ffla-woobooster'],
    ['Products Bundles', 'manage_woocommerce', 'ffla-woobooster-bundles'],
];

function wl_blocks(array $get): bool
{
    $GLOBALS['pagenow'] = 'admin.php';
    $_GET = $get;
    try {
        (new White_Label_Restrictions(['hidden_menu' => []]))->block_pages();
    } catch (Redirected $e) {
        return true;
    }
    return false;
}

check(!wl_blocks(['page' => 'ffla-woobooster-store', 'tab' => 'bundles']), 'White Label does not block the client page');
check(wl_blocks(['page' => 'ffla-woobooster-bundles']), 'FFL Funnels bundles page stays blocked for clients');
check(wl_blocks(['page' => 'ffla-woobooster']), 'FFL Funnels WooBooster settings stay blocked for clients');

/* ── URLs and GET forms follow the context ───────────────────────────── */

$_GET = ['page' => 'ffla-woobooster-bundles'];
check(!WooBooster_Admin::is_store_context(), 'FFL page is not the client context');
check(WooBooster_Admin::url('bundles', ['action' => 'edit', 'bundle_id' => 7]) === 'https://shop.test/wp-admin/admin.php?page=ffla-woobooster-bundles&action=edit&bundle_id=7', 'FFL context: bundle edit link on the FFL page');
check(WooBooster_Admin::url('settings') === 'https://shop.test/wp-admin/admin.php?page=ffla-woobooster', 'FFL context: settings is the WB Settings page');
check(false !== strpos(WooBooster_Admin::view_hidden_fields('rules'), 'value="ffla-woobooster-rules"') && false === strpos(WooBooster_Admin::view_hidden_fields('rules'), 'name="tab"'), 'FFL context: search form keeps the rules page');

$_GET = ['page' => 'ffla-woobooster-store', 'tab' => 'rules'];
check(WooBooster_Admin::is_store_context(), 'client page is the client context');
check(WooBooster_Admin::url('rules', ['action' => 'edit', 'rule_id' => 3, 'saved' => 1]) === 'https://shop.test/wp-admin/admin.php?page=ffla-woobooster-store&tab=rules&action=edit&rule_id=3&saved=1', 'client context: rule edit link stays on the client page');
check(false !== strpos(WooBooster_Admin::view_hidden_fields('analytics'), 'name="tab" value="analytics"'), 'client context: analytics date form keeps page and tab');
check('rules' === WooBooster_Store_Page::current_tab(), 'tab from the query string');
$_GET['tab'] = 'diagnostics';
check('bundles' === WooBooster_Store_Page::current_tab(), 'agency-only views are not client tabs');

/* ── AJAX nonces ─────────────────────────────────────────────────────── */

function nonce_ok(string $nonce, bool $client_allowed): bool
{
    $_REQUEST['nonce'] = $nonce;
    try {
        WooBooster_Admin::check_ajax_nonce($client_allowed);
        return true;
    } catch (Died $e) {
        return false;
    }
}

$agency = wp_create_nonce('woobooster_admin');
$client = wp_create_nonce(WooBooster_Admin::STORE_NONCE);
check(nonce_ok($agency, true) && nonce_ok($agency, false), 'FFL Funnels nonce opens every endpoint');
check(nonce_ok($client, true), 'client nonce opens client-safe endpoints');
check(!nonce_ok($client, false), 'client nonce is refused by agency endpoints');
check(!nonce_ok('forged', true), 'unknown nonce is refused');

$ajax_src = file_get_contents(FFLA_PATH . 'modules/woobooster/admin/class-woobooster-ajax.php');
foreach (['search_terms', 'search_products', 'search_coupons', 'resolve_product_names', 'toggle_rule', 'toggle_bundle'] as $fn) {
    check((bool) preg_match('/function ' . $fn . '\(\)\s*\{\s*WooBooster_Admin::check_ajax_nonce\(true\);/', $ajax_src), "$fn accepts the client nonce");
}
check((bool) preg_match("/function test_rule\(\)\s*\{\s*check_ajax_referer\('woobooster_admin', 'nonce'\);/", $ajax_src), 'rule tester stays agency-only');
$admin_src = file_get_contents(FFLA_PATH . 'modules/woobooster/admin/class-woobooster-admin.php');
check(substr_count($admin_src, "check_ajax_referer('woobooster_admin', 'nonce');") >= 8, 'import, export, delete all, rebuild, purge and AI keep the agency nonce');

/* ── Client settings: only client keys change ────────────────────────── */

$GLOBALS['options']['woobooster_settings'] = [
    'enabled' => '1', 'section_title' => 'You May Also Like', 'exclude_outofstock' => '1',
    'smart_copurchase' => '1', 'smart_trending' => '0', 'smart_recently_viewed' => '0',
    'ai_provider' => 'deepseek', 'ai_api_key' => 'sk-agency', 'ai_model' => 'deepseek-chat', 'ai_thinking' => '1',
    'tavily_key' => 'tvly-agency', 'debug_mode' => '1', 'delete_data_uninstall' => '1',
    'smart_days' => 120, 'smart_max_relations' => 30, 'similar_key_attributes' => 'pa_caliber',
];
$_GET = ['page' => 'ffla-woobooster-store', 'tab' => 'settings'];
$_POST = [
    'woobooster_store_settings_nonce' => wp_create_nonce(WooBooster_Store_Page::SETTINGS_NONCE),
    'woobooster_enabled' => '1',
    'woobooster_section_title' => '  Pairs <b>well</b> with ',
    'woobooster_smart_trending' => '1',
    'woobooster_smart_recently_viewed' => '1',
    // Agency fields posted by a crafted form are ignored.
    'woobooster_ai_api_key' => 'sk-client', 'woobooster_debug_mode' => '0', 'woobooster_smart_days' => '7',
];
try {
    $store->load();
    check(false, 'settings save redirects');
} catch (Redirected $e) {
    check($e->getMessage() === 'https://shop.test/wp-admin/admin.php?page=ffla-woobooster-store&tab=settings&settings-updated=true', 'settings save redirects back to the client Settings tab');
}
$saved = $GLOBALS['options']['woobooster_settings'];
check('Pairs well with' === $saved['section_title'] && '1' === $saved['enabled'], 'client values saved and sanitized');
check('0' === $saved['exclude_outofstock'] && '0' === $saved['smart_copurchase'] && '1' === $saved['smart_trending'] && '1' === $saved['smart_recently_viewed'], 'unticked boxes save as off');
foreach (['ai_provider' => 'deepseek', 'ai_api_key' => 'sk-agency', 'ai_model' => 'deepseek-chat', 'ai_thinking' => '1', 'tavily_key' => 'tvly-agency', 'debug_mode' => '1', 'delete_data_uninstall' => '1', 'smart_days' => 120, 'smart_max_relations' => 30, 'similar_key_attributes' => 'pa_caliber'] as $key => $value) {
    check($value === $saved[$key], "agency setting $key is kept");
}
check(false === $GLOBALS['autoload']['woobooster_settings'], 'settings stay out of autoload (API keys)');
check(1 === $GLOBALS['cache_cleared'] && 1 === $GLOBALS['cron_scheduled'], 'save clears cached recommendations and reschedules Smart builds');

$_POST = ['woobooster_store_settings_nonce' => 'forged', 'woobooster_enabled' => '0'];
try {
    $store->load();
    check(false, 'forged settings save is refused');
} catch (Died $e) {
    check('1' === $GLOBALS['options']['woobooster_settings']['enabled'], 'forged nonce changes nothing');
}

$_POST = [];
$GLOBALS['caps']['manage_woocommerce'] = false;
try {
    $store->load();
    check(false, 'page needs manage_woocommerce');
} catch (Died $e) {
    check(false !== strpos($e->getMessage(), '|403'), 'users without manage_woocommerce get a 403');
}
$GLOBALS['caps']['manage_woocommerce'] = true;

/* ── Settings tab markup: client fields only ─────────────────────────── */

$_GET = ['page' => 'ffla-woobooster-store', 'tab' => 'settings'];
$store->load();
ob_start();
$store->render();
$html = ob_get_clean();
check(false !== strpos($html, 'name="woobooster_section_title"') && false !== strpos($html, 'name="woobooster_smart_trending"'), 'settings tab shows the client fields');
foreach (['woobooster_ai_provider', 'woobooster_ai_api_key', 'woobooster_tavily_key', 'woobooster_debug_mode', 'woobooster_delete_data', 'woobooster_smart_days', 'wb-rebuild-index', 'wb-purge-index', 'woobooster_settings_nonce'] as $agency_field) {
    check(false === strpos($html, $agency_field), "settings tab has no $agency_field");
}
check(false === strpos($html, 'sk-agency'), 'API key never printed on the client page');
check((bool) preg_match('#class="ffla-wbs-tab is-active" aria-current="page">.*?<span>Settings</span>#s', $html), 'Settings tab marked current');
check(4 === preg_match_all('/class="ffla-wbs-tab( is-active)?"/', $html), 'four tabs: Bundles, Recommendation rules, Settings, Analytics');
check((bool) preg_match('#<span>Bundles</span><span class="ffla-wbs-tab__count">3<#', $html) && (bool) preg_match('#<span>Recommendation rules</span><span class="ffla-wbs-tab__count">0<#', $html), 'tabs show how many bundles and rules exist');
check(false !== strpos($html, 'ffla-wbs-status is-on') && false !== strpos($html, 'Recommendations on'), 'header shows recommendations are on');
check(false !== strpos($html, 'What these settings change') && false === strpos($html, '<details class="ffla-wbs-guide" open'), 'settings tab explains itself, closed by default');
check(false !== strpos($html, 'Status: waiting for the first update.'), 'Trending switched on: status says the first update is pending');
check(false === strpos($html, 'Get help'), 'no help button without a White Label support link');

// Rules tab with no rules: the guide starts open.
$GLOBALS['options']['woobooster_settings']['enabled'] = '0';
$_GET = ['page' => 'ffla-woobooster-store', 'tab' => 'rules'];
$store_reflection = new ReflectionMethod('WooBooster_Store_Page', 'render_guide');
$store_reflection->setAccessible(true);
ob_start();
$store_reflection->invoke($store, 'rules', true);
$guide = ob_get_clean();
check(false !== strpos($guide, '<details class="ffla-wbs-guide" open>') && 3 === substr_count($guide, 'class="ffla-wbs-step"'), 'empty rules tab: three-step guide open');
check(false !== strpos($guide, 'Example: when a shopper views a 9mm handgun'), 'rules guide gives an example');
$header = (function () use ($store) {
    $content = new ReflectionProperty('WooBooster_Store_Page', 'content');
    $content->setAccessible(true);
    $content->setValue($store, '');
    ob_start();
    $store->render();
    return ob_get_clean();
})();
check(false !== strpos($header, 'ffla-wbs-status is-off') && false !== strpos($header, 'Recommendations off'), 'header shows recommendations are off');
check(false !== strpos($header, '<details class="ffla-wbs-guide" open>'), 'tab with nothing in it opens its guide');

/* ── Analytics: previous period as long as the range ─────────────────── */

$analytics_src = file_get_contents(FFLA_PATH . 'modules/woobooster/analytics/class-woobooster-analytics.php');
check((bool) preg_match('/\$days_span = max\(1, \(int\) round\(\(strtotime\(\$range\[\'to\'\]\) - strtotime\(\$range\[\'from\'\]\)\) \/ 86400\) \+ 1\);/', $analytics_src), 'previous period counts both ends of the range');
$from = '2026-10-01';
$to   = '2026-10-07';
$days_span = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
$prev_from = gmdate('Y-m-d', strtotime($from . " -{$days_span} days"));
$prev_to   = gmdate('Y-m-d', strtotime($from . ' -1 day'));
check('2026-09-24' === $prev_from && '2026-09-30' === $prev_to, 'a 7-day range compares with the 7 days before');
check(false !== strpos($analytics_src, 'self::release_memory();') && false !== strpos($analytics_src, "'wc-ffla-ready'"), 'analytics releases the object cache per page and counts Ready for Pickup');

echo $checks . " checks passed (woobooster client page).\n";
