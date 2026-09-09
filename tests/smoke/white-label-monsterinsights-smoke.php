<?php
/**
 * Offline provider-contract regression checks. No WordPress/network/credentials.
 * php tests/smoke/white-label-monsterinsights-smoke.php [legacy|missing|no-addon]
 */
define('ABSPATH', __DIR__);
$mode = $argv[1] ?? 'modern';
if ($mode !== 'missing') { define('MONSTERINSIGHTS_VERSION', $mode === 'legacy' ? '10.0.0' : '11.2.0'); }
$checks = 0;
$cache = $ttls = $calls = [];
$caps = ['monsterinsights_view_dashboard' => true, 'monsterinsights_save_settings' => true, 'read' => true];
$pro = $authed = $licensed = $commerce_license = true;
$disabled = $license_error = $network = false;
$property = 'G-SYNTHETIC-A';
$user = $blog = 1;
$now = '2026-09-08 03:00:00';
$failure = '';
$legacy_data = [];
function check($condition, $label) {
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException("FAIL: $label"); }
}
function __( $text, $domain = '' ) { return $text; }
function current_user_can($cap) { return $GLOBALS['caps'][$cap] ?? false; }
function admin_url($path) { return 'https://fixture.invalid/wp-admin/' . $path; }
function get_current_user_id() { return $GLOBALS['user']; }
function get_current_blog_id() { return $GLOBALS['blog']; }
function wp_timezone() { return new DateTimeZone('America/New_York'); }
function current_datetime() { return new DateTimeImmutable($GLOBALS['now'], wp_timezone()); }
function wp_json_encode($data) { return json_encode($data); }
function sanitize_text_field($text) { return trim(strip_tags($text)); }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; $GLOBALS['ttls'][$key] = $ttl; }
function delete_transient($key) { unset($GLOBALS['cache'][$key]); }
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }

class FixtureAuth {
    public function is_authed() { return $GLOBALS['authed']; }
    public function is_network_authed() { return $GLOBALS['network']; }
    public function get_viewid() { return ''; } // GA4 often has no old UA view ID.
    public function get_network_viewid() { return 'network-fixture'; }
}
class FixtureLicense {
    public function has_license() { return $GLOBALS['licensed']; }
    public function license_has_error() { return $GLOBALS['license_error']; }
    public function license_can($level) { return $GLOBALS['commerce_license']; }
}
class FixtureReport {
    public function get_data($args) { $GLOBALS['calls'][] = ['legacy', $args]; return ['data' => $GLOBALS['legacy_data']]; }
}
class FixtureManager {
    public function get_report($name) { check($name === 'overview', 'legacy report is overview'); return new FixtureReport(); }
}
$mi = (object) ['auth' => new FixtureAuth(), 'license' => new FixtureLicense(), 'reporting' => new FixtureManager()];
if ($mode !== 'missing') {
    function MonsterInsights() { return $GLOBALS['mi']; }
    function monsterinsights_is_pro_version() { return $GLOBALS['pro']; }
    function monsterinsights_get_option($key, $default = false) { return $key === 'dashboard_disabled' ? $GLOBALS['disabled'] : $default; }
    function monsterinsights_get_v4_id() { return $GLOBALS['property']; }
}
if ($mode !== 'no-addon') { class MonsterInsights_eCommerce {} }
class MonsterInsights_API_Reports {
    protected $timeout = 3;
    protected function request($endpoint, $body = [], $method = 'POST') {
        $GLOBALS['calls'][] = [$endpoint, $body];
        check($endpoint === 'reporting/query' && $method === 'POST', 'native reporting endpoint and method');
        check($this->timeout === 10, 'provider timeout bounded');
        if ($GLOBALS['failure'] === 'exception') { throw new RuntimeException('SECRET_PROVIDER_TOKEN'); }
        if ($GLOBALS['failure'] === 'error') { return new WP_Error(); }
        $commerce = $body['queries'][0]['id'] === 'ecommerce_key_metrics';
        if ($GLOBALS['failure'] === 'commerce' && $commerce) { return ['success' => false, 'error' => 'SECRET_PROVIDER_TOKEN']; }
        $data = $commerce ? commerce_fixture($body['end']) : traffic_fixture($body['end']);
        if ($GLOBALS['failure'] === 'sample') { $data['overview']['is_sample'] = true; }
        if ($GLOBALS['failure'] === 'wrapped-error') { return ['data' => ['success' => false, 'data' => $data]]; }
        return ['success' => true, 'data' => ['data' => $data]];
    }
}
function traffic_fixture($end) {
    $prior = (new DateTimeImmutable($end))->modify('-1 day')->format('Ymd');
    return [
        'overview' => ['rows' => [
            ['d' => [str_replace('-', '', $end)], 'm' => [['5','10'], ['10','20'], ['2','5'], ['2','4']]],
            ['d' => [$prior], 'm' => [[['15','30'], ['30','80'], ['9','15'], ['3','6']]]],
            ['d' => ['20990101'], 'm' => [['999','999'], ['999','999'], ['999','999'], ['999','999']]],
        ]],
        'pages' => ['rows' => [['d' => ['/sample-page/'], 'm' => [['100']]], ['d' => ['/second/'], 'm' => [50]]]],
        'sources' => ['rows' => [['d' => ['example / referral'], 'm' => [['20']]]]],
    ];
}
function commerce_fixture($end) {
    return ['ecommerce_key_metrics' => ['rows' => [
        ['d' => [$end], 'm' => [['20','40'], ['2','4'], ['120','300']]],
        ['d' => ['2099-01-01'], 'm' => [['999','999'], ['999','999'], ['999','999']]],
    ]], 'products_table' => ['rows' => [['d' => ['Synthetic item'], 'm' => [['4', '300']]]]]];
}
require dirname(__DIR__, 2) . '/modules/white-label/includes/class-white-label-monsterinsights.php';
require dirname(__DIR__, 2) . '/modules/white-label/includes/class-white-label-dashboard-data.php';
$dates = White_Label_MonsterInsights::dates(7);
check($dates === ['start'=>'2026-09-01', 'end'=>'2026-09-07', 'compare_start'=>'2026-08-25', 'compare_end'=>'2026-08-31'], 'seven complete days with nonoverlapping comparison');
$now = '2026-03-10 00:30:00';
$dst = White_Label_MonsterInsights::dates(7);
check($dst['start'] === '2026-03-03' && $dst['end'] === '2026-03-09', 'DST uses calendar days');
$now = '2026-09-08 03:00:00';
if ($mode === 'missing') {
    $data = White_Label_Dashboard_Data::analytics('google', 30);
    check($data['status'] === 'unavailable' && $data['metrics'] === [], 'missing plugin is safe');
    check(count($calls) === 0, 'missing plugin makes no API request');
    echo "$checks checks passed (missing).\n"; exit;
}
$legacy_data = ['infobox' => ['sessions'=>['value'=>'1,234'], 'pageviews'=>['value'=>'2,500'], 'bounce'=>['value'=>'40%']],
    'overviewgraph'=>['sessions'=>['datapoints'=>[10,20]], 'pageviews'=>['datapoints'=>[20,40]]],
    'toppages'=>[['title'=>'Synthetic page', 'value'=>30]]];
if ($mode === 'fixture') {
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr($text) { return esc_html($text); }
    function esc_url($text) { return esc_html($text); }
    function esc_html_e($text, $domain) { echo esc_html($text); }
    function esc_attr_e($text, $domain) { echo esc_attr($text); }
    function number_format_i18n($n, $decimals = 0) { return number_format($n, $decimals); }
    function current_time($format) { return '10'; }
    function date_i18n($format, $timestamp) { return date($format, $timestamp); }
    function selected($a, $b) { if ($a == $b) { echo 'selected'; } }
    function _n($one, $many, $n, $domain) { return $n === 1 ? $one : $many; }
    $report = White_Label_MonsterInsights::get(30);
    $data = ['woo' => ['sales'=>500, 'orders'=>5, 'average_order_value'=>100, 'sales_delta'=>10, 'orders_delta'=>5, 'series'=>[['date'=>'2026-09-07','value'=>500]]]];
    $links = array_fill_keys(['support','knowledge_base','cockpit','command_center'], '#fixture');
    $user = (object) ['display_name'=>'Demo client', 'first_name'=>'Demo'];
    $from = '2026-08-10'; $to = '2026-09-08'; $analytics_source = 'google'; $analytics_range = 30; $refresh_url = '#fixture';
    ob_start();
    require dirname(__DIR__, 2) . '/modules/white-label/admin/views/dashboard.php';
    echo ''; $html = ob_get_clean();
    echo json_encode(['report'=>$report, 'html'=>$html]); exit;
}
if ($mode === 'legacy') {
    $data = White_Label_Dashboard_Data::analytics('google', 30);
    check($data['status'] === 'ready' && $data['metrics'][0]['value'] === 1234.0, 'legacy number parsing');
    check($data['metrics'][2]['value'] === 40.0 && $data['metrics'][2]['lowerIsBetter'], 'bounce parsed and direction labeled');
    check($data['series'][0]['label'] === '2026-08-09', 'legacy series dates');
    check($data['ecommerce']['status'] === 'unavailable', 'legacy does not invent commerce metrics');
    $legacy_data['show_chart_overlay'] = true; $cache = [];
    check(White_Label_MonsterInsights::get(30)['status'] === 'unavailable', 'legacy randomized demo chart rejected');
    echo "$checks checks passed (legacy).\n"; exit;
}
$data = White_Label_Dashboard_Data::analytics('google', 30);
check($data['provider'] === 'monsterinsights' && $data['source'] === 'google', 'saved Google tab routes to MI');
check($data['status'] === 'ready', 'modern response envelope parsed');
check($data['metrics'][0]['value'] === 40.0 && $data['metrics'][0]['delta'] === 100.0, 'current/previous ordering correct');
check($data['metrics'][1]['value'] === 100.0 && $data['metrics'][2]['value'] === 10.0, 'pageviews and new users totals');
check($data['metrics'][3]['value'] === 50.0 && abs($data['metrics'][3]['delta'] + 5.0) < 0.000001, 'engagement uses weighted totals, percentage-point change');
check($data['series'][0]['label'] < $data['series'][1]['label'] && count($data['series']) === 2, 'sorted in-range series only');
check($data['tables'][0]['rows'][0][1] === 100.0, 'single-cell table format');
check($data['allowed_ranges'] === [7,30,90], 'Pro ranges');
if ($mode === 'no-addon') {
    check(count($calls) === 1 && $data['ecommerce']['status'] === 'unavailable', 'no commerce API request without addon');
    echo "$checks checks passed (no-addon).\n"; exit;
}
check($data['ecommerce']['metrics'][0]['value'] === 4.0, 'purchases included');
check($data['ecommerce']['metrics'][1]['value'] === 300.0, 'revenue ignores out-of-range dates');
check($data['ecommerce']['metrics'][2]['value'] === 75.0 && $data['ecommerce']['metrics'][3]['value'] === 10.0, 'AOV and purchases/session formulas');
check($data['ecommerce']['currency'] === '', 'does not guess store currency');
check($data['ecommerce']['tables'][0]['rows'][0][2] === 300.0, 'product scalar wrapped format');
$n = count($calls);
White_Label_MonsterInsights::get(30);
White_Label_MonsterInsights::get(30, true);
check(count($calls) === $n, 'cache and refresh cooldown avoid repeated queries');
foreach ($cache as &$entry) { $entry['fetched_at'] -= 31; } unset($entry);
White_Label_MonsterInsights::get(30, true);
check(count($calls) === $n + 2, 'explicit refresh after cooldown');
$n = count($calls); $property = 'G-SYNTHETIC-B'; White_Label_MonsterInsights::get(30);
check(count($calls) === $n + 2, 'GA4 property change invalidates cache');
$n = count($calls); $user++; White_Label_MonsterInsights::get(30);
check(count($calls) === $n + 2, 'cache isolated per user');
$n = count($calls); $blog++; White_Label_MonsterInsights::get(30);
check(count($calls) === $n + 2, 'cache isolated per site');
$n = count($calls); $caps['monsterinsights_view_dashboard'] = false;
check(White_Label_MonsterInsights::get(30)['status'] === 'unavailable', 'capability checked before cached metrics');
check(count($calls) === $n, 'unauthorized role never calls provider');
$caps['monsterinsights_view_dashboard'] = true;
foreach (['disabled', 'license_error'] as $flag) {
    $GLOBALS[$flag] = true;
    check(White_Label_MonsterInsights::get(30)['status'] === 'unavailable', "$flag checked before cache");
    $GLOBALS[$flag] = false;
}
$licensed = false;
check(White_Label_MonsterInsights::get(30)['status'] === 'unavailable', 'missing license checked');
$licensed = true; $authed = false;
check(White_Label_MonsterInsights::get(30)['status'] === 'unavailable', 'disconnect hides cache');
$network = true; $cache = [];
check(White_Label_MonsterInsights::get(30)['metrics'][0]['value'] === 1234.0, 'network-only connection uses registered report');
$network = false; $authed = true;
$caps['monsterinsights_save_settings'] = false; $caps['monsterinsights_view_dashboard'] = false;
check(White_Label_MonsterInsights::get(30)['action_url'] === '', 'settings link not exposed to restricted role');
$caps['monsterinsights_view_dashboard'] = true;
$pro = false; $cache = [];
check(White_Label_MonsterInsights::get(7)['status'] === 'unavailable', 'Lite custom range not bypassed');
check(White_Label_MonsterInsights::get(30)['allowed_ranges'] === [30], 'Lite overview supported');
$pro = true;
foreach (['exception','error','sample','wrapped-error'] as $case) {
    $failure = $case; $cache = [];
    $result = White_Label_MonsterInsights::get(30);
    check($result['status'] === 'unavailable', "$case safely unavailable");
    check(strpos(json_encode($result), 'SECRET_PROVIDER_TOKEN') === false, "$case does not leak provider errors");
    $n = count($calls); White_Label_MonsterInsights::get(30);
    check(count($calls) === $n && end($ttls) === 30, "$case failures briefly cached");
}
$failure = 'commerce'; $cache = [];
$result = White_Label_MonsterInsights::get(30);
check($result['status'] === 'ready' && $result['ecommerce']['status'] === 'unavailable', 'commerce error does not hide traffic');
$failure = '';
$raw = traffic_fixture($dates['end']);
$raw['overview']['rows'][0]['m'][0][0] = null;
$result = White_Label_MonsterInsights::normalize_modern($raw, $dates);
check($result['metrics'][0]['value'] === 40.0 && $result['metrics'][0]['delta'] === null, 'incomplete comparison never becomes misleading delta');
$raw['overview']['rows'] = [['d'=>['20260907'], 'm'=>[['0','0','0','0']]]];
$result = White_Label_MonsterInsights::normalize_modern($raw, $dates);
check($result['status'] === 'ready' && $result['metrics'][0]['value'] === 0.0, 'real zero remains valid zero');
check($result['metrics'][3]['value'] === null, 'zero denominator is unavailable rate');
$raw['overview']['rows'] = [];
check(White_Label_MonsterInsights::normalize_modern($raw, $dates)['status'] === 'no_data', 'empty is not fabricated zero');
$raw = traffic_fixture($dates['end']); $raw['pages']['is_sample'] = true;
check(White_Label_MonsterInsights::normalize_modern($raw, $dates)['tables'][0]['rows'] === [], 'optional demo table rejected independently');
$raw = traffic_fixture($dates['end']); $raw['pages']['rows'][0]['d'][0] = '<b>safe</b>';
check(White_Label_MonsterInsights::normalize_modern($raw, $dates)['tables'][0]['rows'][0][0] === 'safe', 'provider text sanitized');
check(White_Label_Dashboard_Data::get('2026-09-01','2026-09-07')['woo'] === null, 'Woo remains independent of MI');
$query = White_Label_MonsterInsights::queries($dates);
check($query['queries'][0]['limit'] === 200 && count($query['queries']) === 3, 'bounded fixed reporting queries');
check(!in_array('totalUsers', $query['queries'][0]['metrics'], true), 'does not sum daily unique-user counts');
echo "$checks checks passed (modern).\n";
