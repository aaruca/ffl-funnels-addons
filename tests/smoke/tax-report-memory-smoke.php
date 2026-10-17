<?php
/**
 * Bounded-memory report loops: the object cache is released after every page,
 * the second (date_paid) pass loads only orders the first pass did not see,
 * one query per page replaces line-item loading for orders that cannot be
 * Split Payment, results are unchanged by any of it, and the memory guard stops
 * a report before PHP's fatal out-of-memory error. No database, network or writes.
 * Run: php tests/smoke/tax-report-memory-smoke.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['ext_cache'] = false;
$GLOBALS['flushes'] = 0;
$GLOBALS['loaded'] = [];
$GLOBALS['queries'] = [];
$GLOBALS['ids_supported'] = true;
$GLOBALS['test_filters'] = [];

function __($s, $domain = null) { return $s; }
function apply_filters($hook, $value, ...$args) { return isset($GLOBALS['test_filters'][$hook]) ? $GLOBALS['test_filters'][$hook]($value, ...$args) : $value; }
function current_datetime() { return new DateTimeImmutable('2026-03-15', wp_timezone()); }
function wp_timezone() { return new DateTimeZone('America/New_York'); }
function wp_timezone_string() { return 'America/New_York'; }
function wp_date($f, $ts, $tz) { return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($f); }
function sanitize_text_field($s) { return strip_tags($s); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $s)); }
function get_bloginfo($key) { return 'Fixture'; }
function home_url($p) { return 'https://fixture.invalid' . $p; }
function wp_json_encode($v) { return json_encode($v); }
function wc_get_price_decimals() { return 2; }
function get_option($key, $default = false) { return $default; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_using_ext_object_cache() { return $GLOBALS['ext_cache']; }
function wp_cache_flush_runtime() { $GLOBALS['flushes']++; return true; }
function wc_get_order($id) { $GLOBALS['loaded'][] = (int) $id; return $GLOBALS['orders'][$id] ?? null; }
function wc_get_orders($query) {
    $GLOBALS['queries'][] = $query;
    $field = isset($query['date_paid']) ? 'date_paid' : 'date_created';
    [$start, $end] = array_map('intval', explode('...', $query[$field]));
    $rows = array_values(array_filter($GLOBALS['orders'], static function ($order) use ($query, $field, $start, $end) {
        $date = $field === 'date_paid' ? $order->get_date_paid() : $order->get_date_created();
        return $order->type === $query['type'] && (!isset($query['status']) || in_array($order->get_status(), $query['status'], true))
            && $date && $date->getTimestamp() >= $start && $date->getTimestamp() <= $end;
    }));
    usort($rows, static function ($a, $b) { return [$a->get_date_created(), $a->id] <=> [$b->get_date_created(), $b->id]; });
    $slice = array_slice($rows, ($query['page'] - 1) * $query['limit'], $query['limit']);
    if ($GLOBALS['ids_supported'] && ($query['return'] ?? 'objects') === 'ids') {
        $slice = array_map(static function ($order) { return $order->get_id(); }, $slice);
    }
    return (object) ['orders' => $slice, 'total' => count($rows), 'max_num_pages' => (int) ceil(count($rows) / $query['limit'])];
}

class WC_Order {
    public $id; public $meta = []; public $items = []; public $refunds = []; public $type = 'shop_order';
    public $created; public $paid; public $status = 'completed'; public $parent = 0; public $currency = 'USD';
    public $item_reads = 0;
    function __construct($id, $date, $paid = null) { $this->id = $id; $this->created = new DateTimeImmutable($date, wp_timezone()); $this->paid = $paid ? new DateTimeImmutable($paid, wp_timezone()) : null; }
    function get_id() { return $this->id; }
    function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    function get_parent_id() { return $this->parent; }
    function get_status() { return $this->status; }
    function is_paid() { return in_array($this->status, ['processing', 'completed'], true); }
    function get_date_paid() { return $this->paid; }
    function get_date_created() { return $this->created; }
    function get_currency() { return $this->currency; }
    function get_items($type = 'line_item') { if ($type === 'line_item') { $this->item_reads++; } return $this->items[$type] ?? []; }
    function get_item($id) { foreach ($this->items as $items) { if (isset($items[$id])) { return $items[$id]; } } return null; }
    function get_refunds() { return $this->refunds; }
    function get_total_tax() { return array_sum(array_map(static function ($items) { return array_sum(array_map(static function ($i) { return $i->get_total_tax(); }, $items)); }, array_diff_key($this->items, ['tax' => 1]))); }
    function get_total() { return array_sum(array_map(static function ($items) { return array_sum(array_map(static function ($i) { return $i->get_total(); }, $items)); }, array_diff_key($this->items, ['tax' => 1]))) + $this->get_total_tax(); }
    function get_shipping_total() { return 0; }
    function get_shipping_country() { return 'US'; }
    function get_shipping_state() { return 'GA'; }
    function get_billing_country() { return 'US'; }
    function get_billing_state() { return 'GA'; }
    function get_order_number() { return (string) $this->id; }
    function get_payment_method() { return 'fixture'; }
    function get_transaction_id() { return ''; }
    function __call($name, $args) {
        if (strpos($name, '_city') !== false) { return 'Newnan'; }
        if (strpos($name, '_postcode') !== false) { return '30263'; }
        return '';
    }
}
class TestLine {
    public $id; public $amount; public $tax; public $meta = [];
    function __construct($id, $amount, $tax) { $this->id = $id; $this->amount = $amount; $this->tax = $tax; }
    function get_id() { return $this->id; }
    function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    function get_product() { return null; }
    function get_product_id() { return 77; }
    function get_variation_id() { return 0; }
    function get_quantity() { return 1; }
    function get_total() { return $this->amount; }
    function get_total_tax() { return $this->tax; }
    function get_subtotal() { return $this->amount; }
    function get_subtotal_tax() { return $this->tax; }
    function get_taxes() { return ['total' => [1 => $this->tax], 'subtotal' => [1 => $this->tax]]; }
    function get_name() { return 'Fixture product'; }
    function __call($name, $args) { return ''; }
}
class TestTax {
    private $amount;
    function __construct($amount) { $this->amount = $amount; }
    function get_rate_id() { return 1; }
    function get_rate_percent() { return 8; }
    function get_label() { return 'COWETA COUNTY NEWNAN : County Tax'; }
    function get_tax_total() { return $this->amount; }
    function get_shipping_tax_total() { return 0; }
    function __call($name, $args) { return ''; }
}

/** Minimal $wpdb: answers the per-page line-item query and records it. */
class TestWpdb {
    public $prefix = 'wp_'; public $last_error = ''; public $flagged = []; public $fail = false; public $sql = []; public $args = [];
    function prepare($query, $args) { $this->args[] = $args; return $query; }
    function get_col($query) { $this->sql[] = $query; if ($this->fail) { $this->last_error = 'Table missing'; return []; } return $this->flagged; }
}

require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-service.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-jurisdiction-registry.php';

$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }

function order_with_line($id, $created, $paid, $amount = 100) {
    $order = new WC_Order($id, $created, $paid);
    $order->items = ['line_item' => [$id * 10 => new TestLine($id * 10, $amount, $amount * .08)], 'tax' => [1 => new TestTax($amount * .08)]];
    return $order;
}

function build_fixtures(): void {
    $orders = [];
    // Five ordinary February sales.
    foreach ([1, 2, 3, 4, 5] as $n) {
        $orders[$n] = order_with_line($n, '2026-02-0' . $n, '2026-02-0' . $n);
    }
    // An abandoned (pending) February order: read for Split Payment, never reported.
    $orders[6] = order_with_line(6, '2026-02-07', null);
    $orders[6]->status = 'pending';
    // A Split Payment sale: January deposit, February installment created in January.
    $orders[100] = order_with_line(100, '2026-01-02', '2026-01-03');
    $orders[100]->meta = ['_fppc_managed_plan' => 'yes'];
    $orders[100]->status = 'fppc-plan-hold';
    $orders[200] = new WC_Order(200, '2026-01-02');
    $orders[200]->type = 'shop_subscription';
    $orders[200]->parent = 100;
    $orders[200]->meta = ['_fppc_managed_plan' => 'yes'];
    $orders[101] = order_with_line(101, '2026-01-20', '2026-02-03');
    $orders[101]->meta = ['_fppc_plan_subscription_id' => 200];
    $GLOBALS['orders'] = $orders;
}

function receipts(int $page_size = 2): array {
    $tz = wp_timezone();
    $from = (new DateTimeImmutable('2026-02-01 00:00:00', $tz))->getTimestamp();
    $to = (new DateTimeImmutable('2026-02-28 23:59:59', $tz))->getTimestamp();
    $adapter = new Tax_Report_Sale_Identity();
    $ids = [];
    foreach ($adapter->orders(['completed', 'processing', 'on-hold', 'refunded'], $from, $to, $page_size, 1000) as $receipt) {
        $ids[] = (int) $receipt['order']->get_id();
    }
    return ['ids' => $ids, 'pages' => $adapter->pages_processed];
}

function february_report(): array {
    $report = (new Tax_Report_Service())->generate(['date_from' => '2026-02-01', 'date_to' => '2026-02-28', 'report_detail' => 'advanced']);
    unset($report['manifest']['report_id'], $report['manifest']['generated_at_utc']);
    return $report;
}

// 1. Iterator: same receipts, cache released once per page, second pass loads only unseen orders.
build_fixtures();
$GLOBALS['flushes'] = 0; $GLOBALS['loaded'] = []; $GLOBALS['queries'] = [];
$run = receipts();
check($run['ids'] === [1, 2, 3, 4, 5, 101], 'Ordinary February sales plus the February installment, pending order excluded');
check($GLOBALS['flushes'] === $run['pages'] && $run['pages'] >= 6, 'Object cache released after every page of both passes');
$paid_queries = array_values(array_filter($GLOBALS['queries'], static function ($q) { return isset($q['date_paid']); }));
check(!empty($paid_queries) && count(array_filter($paid_queries, static function ($q) { return $q['return'] === 'ids'; })) === count($paid_queries), 'Second pass asks for IDs only');
check(!array_intersect($GLOBALS['loaded'], [1, 2, 3, 4, 5]), 'Orders already read in the first pass are not loaded again');
check(in_array(101, $GLOBALS['loaded'], true), 'An installment created before the period is loaded by ID');

// 2. Line-item query: orders it rules out are never asked for their items.
build_fixtures();
$GLOBALS['wpdb'] = new TestWpdb();
$GLOBALS['wpdb']->flagged = ['3'];
$run = receipts();
check($run['ids'] === [1, 2, 3, 4, 5, 101], 'Line-item query does not change the receipts');
foreach ([1, 2, 4, 5, 6] as $n) {
    check($GLOBALS['orders'][$n]->item_reads === 0, 'Order ' . $n . ' without Split Payment item meta is not read item by item');
}
check($GLOBALS['orders'][3]->item_reads === 1, 'An order with Split Payment item meta is still checked item by item');
check(strpos($GLOBALS['wpdb']->sql[0], "order_item_type = 'line_item'") !== false, 'Query is limited to line items');
check(in_array('_fppc_managed_plan', $GLOBALS['wpdb']->args[0], true) && in_array('_fppc_plan_snapshot_v2', $GLOBALS['wpdb']->args[0], true), 'Query covers both Split Payment item keys');
check(in_array('b:0;', $GLOBALS['wpdb']->args[0], true) && in_array('a:0:{}', $GLOBALS['wpdb']->args[0], true), 'Only stored values that read back as false are excluded');

// 3. A failed line-item query falls back to reading items.
build_fixtures();
$GLOBALS['wpdb'] = new TestWpdb();
$GLOBALS['wpdb']->fail = true;
$run = receipts();
check($run['ids'] === [1, 2, 3, 4, 5, 101], 'Database error leaves receipts unchanged');
foreach ([1, 2, 3, 4, 5, 6] as $n) {
    check($GLOBALS['orders'][$n]->item_reads >= 1, 'Order ' . $n . ' is read item by item when the query failed');
}

// 4. Whole report is identical with and without the optimizations.
build_fixtures();
unset($GLOBALS['wpdb']);
$GLOBALS['ids_supported'] = false;
$GLOBALS['test_filters']['ffla_tax_report_flush_runtime_cache'] = static function () { return false; };
$baseline = february_report();
build_fixtures();
$GLOBALS['ids_supported'] = true;
$GLOBALS['test_filters'] = [];
$GLOBALS['wpdb'] = new TestWpdb();
$GLOBALS['wpdb']->flagged = [];
$optimized = february_report();
check($baseline === $optimized, 'Report datasets and summaries are identical with ID loading, item query and cache release');
check($optimized['stats']['receipt_orders'] === 6 && $optimized['stats']['orders'] === 5, 'Five new sales and six receipts in February');

// 5. The filter can switch cache release off.
build_fixtures();
unset($GLOBALS['wpdb']);
$GLOBALS['test_filters']['ffla_tax_report_flush_runtime_cache'] = static function () { return false; };
$GLOBALS['flushes'] = 0;
receipts();
check($GLOBALS['flushes'] === 0, 'ffla_tax_report_flush_runtime_cache can disable the release');
$GLOBALS['test_filters'] = [];

// 6. Persistent caches: a drop-in's own runtime flush is used, the compat fallback never.
$GLOBALS['ext_cache'] = true;
$GLOBALS['flushes'] = 0;
check(Tax_Report_Memory::flush_runtime_cache() === true && $GLOBALS['flushes'] === 1, 'Drop-in runtime flush is used with a persistent cache');
$GLOBALS['ext_cache'] = false;
// In a separate process: wp_cache_flush_runtime() from WordPress' cache-compat.php
// would call wp_cache_flush() and empty the shared store for every visitor.
$dir = sys_get_temp_dir() . '/ffla-compat-' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/cache-compat.php', '<?php function wp_cache_flush_runtime() { $GLOBALS["flushed"] = true; return true; }');
file_put_contents($dir . '/run.php', '<?php define("ABSPATH", __DIR__ . "/"); function wp_using_ext_object_cache() { return true; } function apply_filters($h, $v) { return $v; }'
    . ' require __DIR__ . "/cache-compat.php"; require ' . var_export(realpath(__DIR__ . '/../../modules/tax-rates/includes/class-tax-report-memory.php'), true) . ';'
    . ' $r = Tax_Report_Memory::flush_runtime_cache(); echo (!$r && empty($GLOBALS["flushed"])) ? "compat-safe" : "compat-flushed";');
$child = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($dir . '/run.php'));
@unlink($dir . '/cache-compat.php');
@unlink($dir . '/run.php');
@rmdir($dir);
check(trim((string) $child) === 'compat-safe', 'WordPress compat fallback (full flush of the shared cache) is never called');

// 7. Memory limit parsing and the guard.
$previous_limit = ini_get('memory_limit');
ini_set('memory_limit', '-1');
check(Tax_Report_Memory::limit_bytes() === 0, 'Unlimited memory has no ceiling');
$threw = false;
try { Tax_Report_Memory::guard(); } catch (RuntimeException $e) { $threw = true; }
check(!$threw, 'Guard is silent without a limit');
ini_set('memory_limit', (string) (memory_get_usage(true) + 4 * 1024 * 1024));
check(Tax_Report_Memory::limit_bytes() > memory_get_usage(true), 'Byte limit parsed');
$GLOBALS['test_filters']['ffla_tax_report_memory_ceiling'] = static function () { return 0.5; };
$message = '';
try { Tax_Report_Memory::guard(); } catch (RuntimeException $e) { $message = $e->getMessage(); }
check(strpos($message, 'Narrow the date range') !== false, 'Guard stops with a readable message near the limit');
$GLOBALS['test_filters'] = [];
ini_set('memory_limit', '512M');
check(Tax_Report_Memory::limit_bytes() === 536870912, '512M parsed without WordPress helpers');
ini_set('memory_limit', (string) $previous_limit);

echo $checks . " bounded-memory report checks passed.\n";
