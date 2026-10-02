<?php
/**
 * Offline regression checks for Sales Tax Reports: reconciliation status with
 * the default filters, combining FFLA's own jurisdiction summaries, state
 * template mapping with jurisdictions that had no sales, and email history
 * without recipient addresses.
 * Run: php tests/smoke/tax-report-fixes-smoke.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['options'] = ['woocommerce_tax_based_on' => 'shipping', 'woocommerce_date_type' => 'date_created'];
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function apply_filters($hook, $value) { return $value; }
function __($text, $domain = null) { return $text; }
function wp_timezone() { return new DateTimeZone('UTC'); }
function wc_get_price_decimals() { return 2; }
function sanitize_email($value) { return (string) $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }

/** WooCommerce order objects for the bounded order query. */
class TestTaxItem {
    private $tax; private $shipping;
    public function __construct($tax, $shipping) { $this->tax = $tax; $this->shipping = $shipping; }
    public function get_tax_total() { return $this->tax; }
    public function get_shipping_tax_total() { return $this->shipping; }
}
class TestOrder {
    public $id; public $state; public $tax; public $shipping_tax; public $meta = [];
    public function __construct($id, $state, $tax, $shipping_tax) { $this->id = $id; $this->state = $state; $this->tax = $tax; $this->shipping_tax = $shipping_tax; }
    public function get_id() { return $this->id; }
    public function get_items($type) { return $type === 'tax' ? [new TestTaxItem($this->tax, $this->shipping_tax)] : []; }
    public function get_total_tax() { return $this->tax + $this->shipping_tax; }
    public function get_total() { return 100; }
    public function get_currency() { return 'USD'; }
    public function get_shipping_state() { return $this->state; }
    public function get_shipping_country() { return 'US'; }
    public function get_billing_state() { return $this->state; }
    public function get_billing_country() { return 'US'; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
}
$GLOBALS['orders'] = [new TestOrder(1, 'GA', 6.00, 1.00), new TestOrder(2, 'GA', 3.00, 0.00)];
function wc_get_orders(array $query) {
    $orders = $query['type'] === 'shop_order' ? $GLOBALS['orders'] : [];
    return (object) ['orders' => $orders, 'max_num_pages' => 1];
}

require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-reconciliation.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-combiner.php';

$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }

/** A filing-detail FFLA report with the default filters (negative orders excluded). */
function filing_report(string $detail = 'filing', array $extra = []): array {
    return array_merge([
        'manifest'  => ['filters' => ['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'statuses' => ['completed'], 'include_negative_orders' => false, 'report_detail' => $detail], 'report_detail' => $detail],
        'stats'     => ['orders' => 2, 'refunds' => 0],
        'summaries' => ['filing_totals' => [['currency' => 'USD', 'net_tax' => '10.00', 'orders' => 2]]],
        'totals_by_currency' => [['currency' => 'USD', 'net_tax' => '10.00', 'orders' => 2]],
        // PII audit rows can exist in filing mode without tax lines.
        'orders'    => [['order_id' => 1], ['order_id' => 2]],
        'tax_lines' => [],
        'refunds'   => [],
    ], $extra);
}

// 1. Reconciliation: matching totals with the default filters reach "pass".
$result = (new Tax_Report_Reconciliation())->reconcile(filing_report());
check($result['status'] === 'pass', 'Default-filter reconciliation can be Reconciled');
check($result['sources']['woocommerce'] === 'woocommerce_orders_scoped' && !empty($result['notes']) && empty($result['warnings']), 'Order-based comparison is a note, not a warning');
check($result['checks']['product_tax']['comparable'] === false && $result['checks']['order_count']['comparable'] === false, 'Filing report does not invent a 0 product/shipping split');

// 2. A real difference still needs review.
$GLOBALS['orders'][] = new TestOrder(3, 'GA', 5.00, 0.00);
$result = (new Tax_Report_Reconciliation())->reconcile(filing_report());
check($result['status'] === 'warn' && $result['checks']['tax_total']['status'] === 'warn', 'Tax total mismatch needs review');
array_pop($GLOBALS['orders']);

// 3. Advanced report compares the components and the taxed order count.
$advanced = filing_report('advanced', ['tax_lines' => [
    ['order_id' => 1, 'currency' => 'USD', 'product_tax' => '6.00', 'shipping_tax' => '1.00'],
    ['order_id' => 2, 'currency' => 'USD', 'product_tax' => '3.00', 'shipping_tax' => '0.00'],
]]);
$result = (new Tax_Report_Reconciliation())->reconcile($advanced);
check($result['status'] === 'pass' && $result['checks']['shipping_tax']['status'] === 'pass' && $result['checks']['order_count']['status'] === 'pass', 'Advanced report reconciles every component');

// 4. Combining FFLA's own jurisdiction-summary.csv (tax_collected and net_tax columns).
$dir = sys_get_temp_dir() . '/ffla-tax-report-fixes-' . getmypid();
@mkdir($dir);
$header = "state,jurisdiction_code,jurisdiction_type,jurisdiction_name,rate_percent,currency,orders,gross_sales,taxable_sales,taxable_shipping,tax_collected,tax_refunded,net_tax,calculated_tax,over_under,filing_status\n";
file_put_contents("$dir/site-a.csv", $header . "GA,077,county,Coweta,3.0000,USD,2,200.00,180.00,20.00,6.00,0.00,6.00,6.00,0.00,ready\nGA,121,county,Fulton,3.0000,USD,1,100.00,100.00,0.00,3.00,0.00,3.00,3.00,0.00,ready\nFL,086,county,Miami-Dade,1.0000,USD,1,10.00,10.00,0.00,0.10,0.00,0.10,0.10,0.00,ready\n");
file_put_contents("$dir/site-b.csv", $header . "GA,077,county,Coweta,3.0000,USD,1,50.00,50.00,0.00,1.50,0.50,1.00,1.00,0.00,ready\n");
$combiner = new Tax_Report_Combiner();
$files = [];
foreach (['site-a.csv', 'site-b.csv'] as $name) {
    $files[] = ['name' => $name, 'tmp_name' => "$dir/$name", 'size' => filesize("$dir/$name"), 'error' => 0];
}
$combined = $combiner->combine_uploaded_files($files, ['require_uploaded_file' => false]);
check(empty($combined['diagnostics']['errors']) && count($combined['rows']) === 3, 'FFLA summaries combine (no duplicate net_tax header)');
$coweta = array_values(array_filter($combined['rows'], function ($row) { return $row['jurisdiction_name'] === 'Coweta'; }))[0];
check($coweta['net_tax'] === '7' && $coweta['tax_collected'] === '7.5' && $coweta['tax_refunded'] === '0.5' && $coweta['gross_sales'] === '250', 'Collected, refunded and gross sales are summed');
check(!array_key_exists('non_taxable_sales', $coweta) && !array_key_exists('needs_review_sales', $coweta), 'Columns no input carried are left out instead of reported as 0');

// 5. A third-party summary keeps the tax_collected -> net_tax alias.
file_put_contents("$dir/other.csv", "state,county,currency,orders,taxable_sales,tax_collected\nGA,Coweta,USD,1,10,0.3\n");
$other = $combiner->combine_uploaded_files([['name' => 'other.csv', 'tmp_name' => "$dir/other.csv", 'size' => filesize("$dir/other.csv"), 'error' => 0]], ['require_uploaded_file' => false]);
check(empty($other['diagnostics']['errors']) && $other['rows'][0]['net_tax'] === '0.3', 'Alias still applies when there is no net_tax column');

// 6. State template: jurisdictions without sales get 0, the file is produced.
function map_template($combiner, $dir, $rows, $csv) {
    file_put_contents("$dir/template.csv", $csv);
    return $combiner->map_state_template(['name' => 'template.csv', 'tmp_name' => "$dir/template.csv", 'size' => strlen($csv), 'error' => 0], $rows, [], ['require_uploaded_file' => false]);
}
$mapped = map_template($combiner, $dir, $combined['rows'], "State,County,Taxable Sales,Tax Collected\nGA,Coweta,,\nGA,Fulton,,\nGA,Carroll,,\n");
check($mapped['csv'] !== '' && strpos($mapped['csv'], "GA,Carroll,0,0") !== false && strpos($mapped['csv'], 'GA,Coweta,230,7') !== false, 'Template rows without sales are written as 0');
check($mapped['diagnostics']['no_activity_count'] === 1 && empty($mapped['diagnostics']['unmatched']), 'No-sales rows are listed, not blocking');

// 7. A jurisdiction with sales but no template row blocks the file (sales would be missing).
$mapped = map_template($combiner, $dir, $combined['rows'], "State,County,Taxable Sales,Tax Collected\nGA,Coweta,,\nGA,Carroll,,\n");
check($mapped['csv'] === '' && count($mapped['diagnostics']['unmatched']) === 1 && $mapped['diagnostics']['unmatched'][0]['identifiers']['jurisdiction_name'] === 'Fulton', 'Missing template row for a jurisdiction with sales blocks the file');

// 8. Two template rows taking the same jurisdiction would double count.
$mapped = map_template($combiner, $dir, $combined['rows'], "State,County,Taxable Sales\nGA,Coweta,\nGA,Coweta,\nGA,Fulton,\n");
check($mapped['csv'] === '' && $mapped['diagnostics']['errors'][0]['code'] === 'combined_row_matched_twice', 'Double-matched jurisdiction blocks the file');

// 9. A template that matches nothing (wrong keys, no state column) is rejected.
$mapped = map_template($combiner, $dir, $combined['rows'], "County,Taxable Sales\nNowhere,\n");
check($mapped['csv'] === '' && $mapped['diagnostics']['errors'][0]['code'] === 'no_template_matches', 'All-zero filing from a key mistake is rejected');

foreach (glob("$dir/*") as $file) { unlink($file); }
rmdir($dir);

// 10. Email delivery history keeps a recipient count, never the addresses.
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-email.php';
$GLOBALS['options'][Tax_Report_Email::HISTORY_OPTION] = [['status' => 'sent', 'recipients' => ['a@example.com', 'b@example.com']]];
$record = new ReflectionMethod('Tax_Report_Email', 'record_history');
$record->setAccessible(true);
$record->invoke(null, ['status' => 'failed', 'recipients' => ['c@example.com']]);
$history = $GLOBALS['options'][Tax_Report_Email::HISTORY_OPTION];
check(strpos(json_encode($history), '@example.com') === false, 'Stored history holds no email address (old entries scrubbed too)');
check($history[0]['recipients_count'] === 1 && $history[1]['recipients_count'] === 2, 'Recipient counts kept');

echo "$checks report regression checks passed.\n";
