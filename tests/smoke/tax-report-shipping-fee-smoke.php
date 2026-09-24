<?php
/**
 * Isolated reporting fixtures for fee items reported as shipping (FPPC
 * "Final shipping" and opt-in layaway fees) and synthetic resolver tax lines.
 * No database, network, charges or writes.
 * Run: php tests/smoke/tax-report-shipping-fee-smoke.php
 */
define('ABSPATH', __DIR__ . '/');
function __($s, $domain = null) { return $s; }
function apply_filters($hook, $value, ...$args) { return isset($GLOBALS['test_filters'][$hook]) ? $GLOBALS['test_filters'][$hook]($value, ...$args) : $value; }
function current_datetime() { return new DateTimeImmutable('2026-09-24', wp_timezone()); }
function wp_timezone() { return new DateTimeZone('America/New_York'); }
function wp_timezone_string() { return 'America/New_York'; }
function wp_date($f, $ts, $tz) { return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($f); }
function sanitize_text_field($s) { return strip_tags($s); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $s)); }
function get_bloginfo($key) { return 'Fixture'; }
function home_url($p) { return 'https://fixture.invalid' . $p; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function wc_get_price_decimals() { return 2; }
function get_option($key, $default = false) { return $default; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? null; }
function wc_get_orders($query) {
    $field = isset($query['date_paid']) ? 'date_paid' : 'date_created';
    [$start, $end] = array_map('intval', explode('...', $query[$field]));
    $rows = array_values(array_filter($GLOBALS['orders'], static function ($order) use ($query, $field, $start, $end) {
        $date = $field === 'date_paid' ? $order->get_date_paid() : $order->get_date_created();
        return $order->type === $query['type'] && (!isset($query['status']) || in_array($order->get_status(), $query['status'], true))
            && $date && $date->getTimestamp() >= $start && $date->getTimestamp() <= $end;
    }));
    usort($rows, static function ($a, $b) { return $a->get_date_created() <=> $b->get_date_created(); });
    return (object) ['orders' => array_slice($rows, ($query['page'] - 1) * $query['limit'], $query['limit']), 'max_num_pages' => (int) ceil(count($rows) / $query['limit'])];
}

class WC_Order {
    public $id; public $meta = []; public $items = []; public $refunds = []; public $type = 'shop_order';
    public $created; public $paid; public $status = 'completed'; public $parent = 0; public $currency = 'USD';
    function __construct($id, $date, $paid = null) { $this->id = $id; $this->created = new DateTimeImmutable($date, wp_timezone()); $this->paid = $paid ? new DateTimeImmutable($paid, wp_timezone()) : null; }
    function get_id() { return $this->id; }
    function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    function get_parent_id() { return $this->parent; }
    function get_status() { return $this->status; }
    function is_paid() { return in_array($this->status, ['processing', 'completed'], true); }
    function get_date_paid() { return $this->paid; }
    function get_date_created() { return $this->created; }
    function get_currency() { return $this->currency; }
    function get_items($type = 'line_item') { return $this->items[$type] ?? []; }
    function get_item($id) { foreach ($this->items as $items) { if (isset($items[$id])) { return $items[$id]; } } return false; }
    function get_refunds() { return $this->refunds; }
    function get_total_tax() { return array_sum(array_map(static function ($items) { return array_sum(array_map(static function ($i) { return $i->get_total_tax(); }, $items)); }, array_diff_key($this->items, ['tax' => 1]))); }
    function get_total() { return array_sum(array_map(static function ($items) { return array_sum(array_map(static function ($i) { return $i->get_total(); }, $items)); }, array_diff_key($this->items, ['tax' => 1]))) + $this->get_total_tax(); }
    function get_shipping_total() { return array_sum(array_map(static function ($i) { return $i->get_total(); }, $this->get_items('shipping'))); }
    function get_shipping_country() { return 'US'; }
    function get_shipping_state() { return 'GA'; }
    function get_billing_country() { return 'US'; }
    function get_billing_state() { return 'GA'; }
    function get_order_number() { return (string) $this->id; }
    function get_payment_method() { return 'fixture'; }
    function get_transaction_id() { return ''; }
    function __call($name, $args) {
        if (strpos($name, '_city') !== false) { return 'Pine Mountain'; }
        if (strpos($name, '_postcode') !== false) { return '31822'; }
        return '';
    }
}
class TestLine {
    public $id; public $amount; public $tax; public $qty; public $meta = []; public $rate_id = 990000; public $name = 'Fixture product';
    function __construct($id, $amount, $tax, $qty = 1) { $this->id = $id; $this->amount = $amount; $this->tax = $tax; $this->qty = $qty; }
    function get_id() { return $this->id; }
    function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    function get_product() { return null; }
    function get_product_id() { return 77; }
    function get_variation_id() { return 0; }
    function get_quantity() { return $this->qty; }
    function get_total() { return $this->amount; }
    function get_total_tax() { return $this->tax; }
    function get_subtotal() { return $this->amount; }
    function get_subtotal_tax() { return $this->tax; }
    function get_taxes() { return $this->tax != 0 ? ['total' => [$this->rate_id => $this->tax], 'subtotal' => [$this->rate_id => $this->tax]] : ['total' => [], 'subtotal' => []]; }
    function get_name() { return $this->name; }
    function __call($name, $args) { return ''; }
}
class TestTax {
    public $rate_id = 990000; public $percent = 8.0; public $code = 'US-GA-FFLA-TOTAL'; private $amount; private $shipping;
    function __construct($amount, $shipping = 0) { $this->amount = $amount; $this->shipping = $shipping; }
    function get_rate_id() { return $this->rate_id; }
    function get_rate_percent() { return $this->percent; }
    function get_rate_code() { return $this->code; }
    function get_label() { return 'Sales Tax'; }
    function get_tax_total() { return $this->amount; }
    function get_shipping_tax_total() { return $this->shipping; }
    function __call($name, $args) { return ''; }
}
class TestRefund extends WC_Order {
    public $type = 'shop_order_refund';
    function get_amount() { return abs($this->get_total()); }
    function get_reason() { return 'Fixture refund'; }
}
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-service.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-jurisdiction-registry.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-exporter.php';

$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function report($from = '2026-05-01', $to = '2026-05-31') {
    return (new Tax_Report_Service())->generate(['date_from' => $from, 'date_to' => $to, 'report_detail' => 'advanced']);
}
function harris(array $report) {
    foreach ($report['summaries']['jurisdictions'] as $row) { if ($row['jurisdiction_code'] === '072') { return $row; } }
    throw new RuntimeException('Harris jurisdiction missing');
}
function line_by_id(array $report, int $id) {
    foreach ($report['order_lines'] as $line) { if ((int) $line['item_id'] === $id) { return $line; } }
    throw new RuntimeException("Line $id missing");
}
function quote_json() {
    return json_encode(['queryId' => 'q-1', 'state' => 'GA', 'outcomeCode' => 'SUCCESS', 'source' => 'usgeocoder_api',
        'normalizedAddress' => ['city' => 'PINE MOUNTAIN', 'state' => 'GA', 'zip' => '31822'], 'totalRate' => 0.08,
        'breakdown' => [['type' => 'state', 'jurisdiction' => 'Georgia', 'rate' => 0.04], ['type' => 'county', 'jurisdiction' => 'Harris', 'rate' => 0.04]]]);
}
/** $100 product, $10 shipping, $25 FPPC final shipping fee (all taxed 8%), $5 untaxed service fee. */
function fixture_order($id, $created = '2026-05-02', $paid = '2026-05-02') {
    $order = new WC_Order($id, $created, $paid);
    $final = new TestLine($id * 10 + 3, 25.00, 2.00); $final->name = 'Final shipping'; $final->meta['_fppc_final_shipping_fee'] = 'yes';
    $service = new TestLine($id * 10 + 4, 5.00, 0); $service->name = 'Layaway Shipping (Non-Refundable)'; $service->meta['_fppc_layaway_fee'] = 'yes';
    $shipping = new TestLine($id * 10 + 2, 10.00, 0.80); $shipping->name = 'Flat rate';
    $order->items = [
        'line_item' => [$id * 10 + 1 => new TestLine($id * 10 + 1, 100.00, 8.00)],
        'shipping'  => [$id * 10 + 2 => $shipping],
        'fee'       => [$id * 10 + 3 => $final, $id * 10 + 4 => $service],
        'tax'       => [1 => new TestTax(10.00, 0.80)],
    ];
    $order->meta = ['_ffla_tax_quote' => quote_json(), '_ffla_tax_query_id' => 'q-1', '_ffla_tax_source' => 'usgeocoder_api'];
    return $order;
}

$GLOBALS['orders'] = [1000 => fixture_order(1000)];

// Baseline: the same data with no marker keys reproduces the previous (2.7.0) classification.
$GLOBALS['test_filters'] = ['ffla_tax_report_shipping_fee_meta_keys' => function () { return []; }];
$before = report();
$GLOBALS['test_filters'] = [];
$after = report();

check($after['manifest']['schema_version'] === '2.8.0', 'Report schema bumped');
check($before['orders'][0]['shipping'] === '10.00' && $before['orders'][0]['fees'] === '30.00', 'Baseline keeps the fee under fees');
check($after['orders'][0]['shipping'] === '35.00' && $after['orders'][0]['fees'] === '5.00', 'Final shipping fee moves from fees to shipping');
foreach (['order_total', 'net_collected', 'tax_collected', 'net_tax', 'net_product_sales', 'refunds'] as $field) {
    check($after['orders'][0][$field] === $before['orders'][0][$field], "Order $field unchanged by reclassification");
    check($after['totals_by_currency'][0][$field] === $before['totals_by_currency'][0][$field], "Currency $field unchanged by reclassification");
}
check($after['totals_by_currency'][0]['shipping'] === '35.00' && $after['totals_by_currency'][0]['fees'] === '5.00', 'Currency totals split moves');
check(line_by_id($after, 10003)['item_type'] === 'fee' && line_by_id($after, 10003)['reporting_category'] === 'shipping', 'Marked fee keeps item_type fee for audit');
check(line_by_id($after, 10004)['reporting_category'] === 'fee', 'Layaway fee is not shipping unless opted in');
check(line_by_id($after, 10001)['reporting_category'] === 'product' && line_by_id($after, 10002)['reporting_category'] === 'shipping', 'Every line row carries a category');
$state = $after['summaries']['states'][0];
check($before['summaries']['states'][0]['taxable_shipping'] === '10.00' && $state['taxable_shipping'] === '35.00', 'State taxable shipping includes the taxed fee');
check($state['taxable_sales'] === $before['summaries']['states'][0]['taxable_sales'] && $state['taxable_sales'] === '135.00', 'Taxable sales unchanged');
check($state['gross_sales'] === $before['summaries']['states'][0]['gross_sales'], 'Gross sales unchanged');
check(harris($after)['taxable_shipping'] === '35.00' && harris($before)['taxable_shipping'] === '10.00', 'Jurisdiction taxable shipping includes the fee');
check(harris($after)['calculated_tax'] === '10.80' && harris($after)['over_under'] === '0.00', 'Jurisdiction math unchanged');
check($after['summaries']['filing_totals'][0]['taxable_shipping'] === '35.00', 'Filing totals include the fee as shipping');
$master_state = array_values(array_filter($after['summaries']['filing_master'], function ($row) { return $row['row_type'] === 'State total'; }))[0];
check($master_state['taxable_shipping'] === '35.00', 'Filing master state row includes the fee');
check(strpos(implode(' ', $after['manifest']['limitations']), 'ffla_tax_report_shipping_fee_meta_keys') !== false, 'Limitation documents the marker filter');

// Opt-in: the layaway fee reported as shipping; untaxed shipping never becomes taxable shipping.
$GLOBALS['test_filters'] = ['ffla_tax_report_shipping_fee_meta_keys' => function ($keys) { $keys[] = '_fppc_layaway_fee'; return $keys; }];
$optin = report();
check($optin['orders'][0]['shipping'] === '40.00' && $optin['orders'][0]['fees'] === '0.00', 'Opted-in layaway fee reported as shipping');
check(line_by_id($optin, 10004)['reporting_category'] === 'shipping', 'Opted-in fee row category');
check($optin['summaries']['states'][0]['taxable_shipping'] === '35.00', 'Untaxed shipping fee is not taxable shipping');
check($optin['orders'][0]['order_total'] === $after['orders'][0]['order_total'], 'Opt-in leaves order total unchanged');
$GLOBALS['test_filters'] = [];

// Columns and exports carry the category next to item_type.
$columns = Tax_Report_Service::get_columns('order_lines');
check(array_search('reporting_category', $columns, true) === array_search('item_type', $columns, true) + 1, 'Export column follows item_type');
$csv = new ReflectionMethod(Tax_Report_Exporter::class, 'build_csv');
$csv->setAccessible(true);
$bytes = $csv->invoke(null, $columns, $after['order_lines']);
check(strpos($bytes, 'reporting_category') !== false && strpos($bytes, 'Final shipping') !== false, 'CSV contains the new column');

// Fiscal snapshots: new schema and category; only the shipping/fees split differs.
$snapshot = (new Tax_Report_Service())->build_fiscal_snapshot($GLOBALS['orders'][1000]);
check($snapshot['schema_version'] === '2.8.0' && $snapshot['totals']['shipping'] === '35.00' && $snapshot['totals']['fees'] === '5.00', 'Snapshot totals use the new split');
check(in_array('shipping', array_column($snapshot['lines'], 'reporting_category'), true), 'Snapshot lines carry the category');
check($snapshot['totals']['order_total'] === '150.80' && $snapshot['totals']['tax_collected'] === '10.80', 'Snapshot money unchanged');

// Itemized in-period refund of the final shipping fee.
$order = $GLOBALS['orders'][1000];
$refund = new TestRefund(5001, '2026-05-10');
$refund->parent = 1000;
$refunded = new TestLine(50011, -25.00, -2.00); $refunded->meta['_refunded_item_id'] = 10003;
$refund->items = ['fee' => [50011 => $refunded]];
$order->refunds = [$refund];
$GLOBALS['orders'][5001] = $refund;
$r = report();
check($r['refunds'][0]['shipping_refund'] === '25.00' && $r['refunds'][0]['fee_refund'] === '0.00', 'Refunded shipping fee reported as shipping refund');
check(json_decode($r['refunds'][0]['line_items_json'], true)[0]['reporting_category'] === 'shipping', 'Refund detail keeps type fee and category shipping');
check($r['summaries']['states'][0]['taxable_shipping'] === '10.00', 'Refunded shipping fee leaves taxable shipping');
check(harris($r)['taxable_shipping'] === '10.00' && harris($r)['filing_status'] === 'Ready', 'Jurisdiction reflects the shipping refund');

// The same refund in a later period (original order outside the population).
$refund->created = new DateTimeImmutable('2026-06-05', wp_timezone());
$june = report('2026-06-01', '2026-06-30');
check($june['summaries']['states'][0]['taxable_shipping'] === '-25.00', 'Later-period fee refund reduces taxable shipping');

// Unallocated manual refund ($20 + $1.60 tax, no items): the shipping estimate follows the categories.
$GLOBALS['orders'] = [1000 => $order, 5002 => new class(5002, '2026-06-06') extends TestRefund {
    public $parent = 1000;
    function get_total() { return -21.60; }
    function get_total_tax() { return -1.60; }
}];
$order->refunds = [$GLOBALS['orders'][5002]];
$GLOBALS['test_filters'] = ['ffla_tax_report_shipping_fee_meta_keys' => function () { return []; }];
$manual_before = report('2026-06-01', '2026-06-30');
$GLOBALS['test_filters'] = [];
$manual_after = report('2026-06-01', '2026-06-30');
check($manual_before['summaries']['states'][0]['taxable_shipping'] === '-1.43', 'Baseline estimate counts only the shipping line');
check($manual_after['summaries']['states'][0]['taxable_shipping'] === '-5.00', 'Unallocated refund estimate includes the shipping fee');
check($manual_after['summaries']['states'][0]['net_tax'] === $manual_before['summaries']['states'][0]['net_tax'], 'Manual refund tax unchanged');

// Resolver lines saved at 0% (Store API recalculation) use the stored quote rate.
$GLOBALS['orders'] = [2000 => fixture_order(2000)];
$GLOBALS['orders'][2000]->items['tax'][1]->percent = 0.0;
$zero = report();
check(harris($zero)['filing_status'] === 'Ready', 'Zero-percent FFLA line no longer forces Needs review');
check(harris($zero)['calculated_tax'] === '10.80' && harris($zero)['allocation_method'] === 'stored_quote_rate_for_unrated_resolver_line', 'Stored quote rate used and disclosed');
$GLOBALS['orders'][2000]->items['tax'][1]->rate_id = 7;
$GLOBALS['orders'][2000]->items['tax'][1]->code = 'US-GA-TAX-1';
foreach ($GLOBALS['orders'][2000]->items as $type => $items) { if ($type !== 'tax') { foreach ($items as $item) { $item->rate_id = 7; } } }
check(harris(report())['filing_status'] === 'Needs review', 'Zero-percent native WooCommerce line still needs review');

// Split Payment: deposit (Store API, 0%), renewal, final renewal with the fee — one Ready sale.
$deposit = fixture_order(3000, '2026-07-01', '2026-07-01');
unset($deposit->items['fee']);
$deposit->items['tax'] = [1 => new TestTax(8.00, 0.80)];
$deposit->items['tax'][1]->percent = 0.0;
$deposit->meta['_fppc_managed_plan'] = 'yes';
$subscription = new WC_Order(3100, '2026-07-01');
$subscription->type = 'shop_subscription'; $subscription->parent = 3000; $subscription->meta = ['_fppc_managed_plan' => 'yes'];
$renewal = new WC_Order(3001, '2026-07-15', '2026-07-15');
$renewal->items = ['line_item' => [30011 => new TestLine(30011, 100.00, 8.00)], 'tax' => [1 => new TestTax(8.00)]];
$renewal->meta = ['_fppc_plan_subscription_id' => 3100, '_ffla_tax_quote' => quote_json()];
$final = new WC_Order(3002, '2026-07-29', '2026-07-29');
$final_fee = new TestLine(30023, 25.00, 2.00); $final_fee->name = 'Final shipping'; $final_fee->meta['_fppc_final_shipping_fee'] = 'yes';
$final->items = ['line_item' => [30021 => new TestLine(30021, 100.00, 8.00)], 'fee' => [30023 => $final_fee], 'tax' => [1 => new TestTax(10.00)]];
$final->meta = ['_fppc_plan_subscription_id' => 3100, '_ffla_tax_quote' => quote_json()];
$GLOBALS['orders'] = [3000 => $deposit, 3100 => $subscription, 3001 => $renewal, 3002 => $final];
$july = report('2026-07-01', '2026-07-31');
check($july['stats']['orders'] === 1 && $july['stats']['receipt_orders'] === 3, 'One sale, three receipts');
check(harris($july)['filing_status'] === 'Ready' && harris($july)['orders'] === 1, 'Harris row Ready for the grouped sale');
check($july['split_payment_sales'][0]['payments'] === 3 && $july['split_payment_sales'][0]['tax_collected'] === '26.80', 'Split sale sums every receipt');
check($july['split_payment_sales'][0]['order_total'] === '361.80', 'Split sale collected total includes the final shipping fee');
check($july['summaries']['states'][0]['taxable_shipping'] === '35.00' && $july['totals_by_currency'][0]['shipping'] === '35.00', 'Final shipping fee in the shipping column');
check($july['totals_by_currency'][0]['fees'] === '0.00', 'No residual fee for the final shipping');
check(harris($july)['calculated_tax'] === '26.80' && harris($july)['over_under'] === '0.00', 'Plan tax reconciles to the stored rate');

echo "$checks shipping-fee reporting checks passed.\n";
