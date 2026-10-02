<?php
/**
 * Offline checks for Sales Tax Resolver helpers: source routing per state,
 * zero-tax states counted as supported, the sheet-only cache clear, and the
 * rolling 30-day USGeocoder call counter.
 * Run: php tests/smoke/tax-resolver-units-smoke.php
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['options'] = [];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_unslash($value) { return $value; }

/** Minimal $wpdb: answers coverage rows and records queries. */
class TestWpdb {
    public $prefix = 'wp_';
    public $queries = [];
    public $rows = [];
    public $audit_count = '0';
    public function prepare($query, ...$args) {
        $args = is_array($args[0] ?? null) ? $args[0] : $args;
        foreach ($args as $arg) {
            $query = preg_replace('/%[sd]/', is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'", $query, 1);
        }
        return $query;
    }
    public function esc_like($text) { return addcslashes($text, '_%\\'); }
    public function get_row($query, $output = null) {
        preg_match("/state_code = '([A-Z]{2})'/", $query, $m);
        return $this->rows[$m[1] ?? ''] ?? null;
    }
    public function get_var($query) { $this->queries[] = $query; return $this->audit_count; }
    public function query($query) { $this->queries[] = $query; return 3; }
}
$GLOBALS['wpdb'] = new TestWpdb();

require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-resolver-db.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-coverage.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-usgeocoder-usage.php';

$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }

// 1. Routing: a USGeocoder key keeps states on the API; the sheet sync uses this too.
check(Tax_Coverage::desired_route('GA', []) === 'sheet_zip_dataset', 'No key: sheet');
check(Tax_Coverage::desired_route('GA', ['usgeocoder_auth_key' => 'k']) === 'usgeocoder_api', 'Key, no limit: API');
$limited = ['usgeocoder_auth_key' => 'k', 'restrict_states' => '1', 'enabled_states' => ['GA']];
check(Tax_Coverage::desired_route('ga', $limited) === 'usgeocoder_api', 'Key + limit: checked state on API');
check(Tax_Coverage::desired_route('FL', $limited) === 'sheet_zip_dataset', 'Key + limit: other state on sheet');
$GLOBALS['options']['ffla_tax_resolver_settings'] = ['usgeocoder_auth_key' => ' k '];
check(Tax_Coverage::desired_route('TX') === 'usgeocoder_api', 'Stored settings are read when none are passed');

// 2. Zero-tax states are supported (quoted at 0%), unsupported ones are not.
$GLOBALS['wpdb']->rows = [
    'OR' => ['state_code' => 'OR', 'coverage_status' => 'NO_SALES_TAX', 'resolver_name' => 'sheet_zip_dataset'],
    'GA' => ['state_code' => 'GA', 'coverage_status' => 'SUPPORTED_ADDRESS_RATE', 'resolver_name' => 'sheet_zip_dataset'],
    'XX' => ['state_code' => 'XX', 'coverage_status' => 'UNSUPPORTED', 'resolver_name' => ''],
];
check(Tax_Coverage::is_supported('OR') && Tax_Coverage::is_supported('GA') && !Tax_Coverage::is_supported('XX'), 'NO_SALES_TAX counts as supported');

// 3. A sheet import clears only sheet-based cached quotes of that state.
$GLOBALS['wpdb']->queries = [];
Tax_Resolver_DB::clear_state_sheet_cache('ga');
$sql = $GLOBALS['wpdb']->queries[0];
check(strpos($sql, "state_code = 'GA'") !== false && strpos($sql, 'NOT LIKE') !== false
    && preg_match('/resolutionMode.*usgeocoder.*live.*api/', $sql), 'Cache clear keeps live USGeocoder answers');

// 4. Rolling 30-day API usage counts every recorded call, with daily buckets.
$GLOBALS['options'] = [];
$GLOBALS['wpdb']->audit_count = '0';
Tax_USGeocoder_Usage::record_call(true);
Tax_USGeocoder_Usage::record_call(false);
$stored = $GLOBALS['options'][Tax_USGeocoder_Usage::OPTION_KEY];
check($stored[gmdate('Y-m')]['total'] === 2 && $stored[gmdate('Y-m')]['failed'] === 1, 'Monthly history kept');
check($stored['_days'][gmdate('Y-m-d')] === 2 && $stored['_days_since'] === gmdate('Y-m-d'), 'Daily bucket kept in the same option');
check(Tax_USGeocoder_Usage::get_last_30d() === 2, 'Last 30 days counts recorded calls (fallback calls included)');
check(count(Tax_USGeocoder_Usage::get_monthly(12)) === 1, 'Monthly view ignores the daily keys');
$GLOBALS['wpdb']->audit_count = '40';
check(Tax_USGeocoder_Usage::get_last_30d() === 40, 'First 30 days of daily counts also consult the audit log');
$GLOBALS['options'][Tax_USGeocoder_Usage::OPTION_KEY]['_days_since'] = gmdate('Y-m-d', time() - 40 * DAY_IN_SECONDS);
$GLOBALS['options'][Tax_USGeocoder_Usage::OPTION_KEY]['_days'][gmdate('Y-m-d', time() - 35 * DAY_IN_SECONDS)] = 9;
check(Tax_USGeocoder_Usage::get_last_30d() === 2, 'After 30 days only the daily window counts');
Tax_USGeocoder_Usage::record_call(true);
check(isset($GLOBALS['options'][Tax_USGeocoder_Usage::OPTION_KEY]['_days_since']) && $GLOBALS['options'][Tax_USGeocoder_Usage::OPTION_KEY][gmdate('Y-m')]['total'] === 3, 'Recording keeps both views');

echo "$checks resolver unit checks passed.\n";
