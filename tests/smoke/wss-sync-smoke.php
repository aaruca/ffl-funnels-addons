<?php
/**
 * Standalone regression harness for the Woo Sheets Sync module.
 *
 * Run: php tests/smoke/wss-sync-smoke.php
 *
 * Runs the real sync engine, orchestrator, tab groups and upsert services
 * against stubbed WooCommerce products and an in-memory Google Sheet that
 * parses "user entered" values like Google does (numeric text loses leading
 * zeros unless it is written as text).
 *
 * Covers: a product with managed stock in two tabs (an order must not be
 * undone, tabs must converge, a manual edit must spread), stock snapshots per
 * tab with the legacy fallback, leading-zero SKUs, empty sale price cells,
 * simple products created from a sheet row joining the tab group, status-only
 * edits, retries of transient Google errors, REST payload normalization and
 * the PHP 8-safe integer sanitizer, update by product_id, the real-time row
 * map shape and tab group helpers.
 */

define('ABSPATH', __DIR__);
define('FFLA_PATH', dirname(__DIR__, 2) . '/');

$checks = 0;
function check($test, $message)
{
    global $checks;
    $checks++;
    if (!$test) {
        throw new RuntimeException("FAIL: $message");
    }
}

/* ── WordPress stubs ───────────────────────────────────────────────────── */

$GLOBALS['options']  = [];
$GLOBALS['meta']     = [];   // post_id => [key => value]
$GLOBALS['products'] = [];   // id => WC_Product (stored copy)
$GLOBALS['next_id']  = 100;
$GLOBALS['log']      = [];

class WP_Error
{
    private $code;
    private $message;
    private $data;
    public function __construct($code = '', $message = '', $data = '')
    {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }
function __($text, $domain = null) { return $text; }
function apply_filters($tag, $value) { return $value; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value = '', $deprecated = '', $autoload = null)
{
    if (array_key_exists($key, $GLOBALS['options'])) {
        return false;
    }
    $GLOBALS['options'][$key] = $value;
    return true;
}
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][(int) $id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][(int) $id][$key] = $value; return true; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][(int) $id][$key]); return true; }
function get_posts($args = []) { return []; }
function get_post_type($id) { return isset($GLOBALS['products'][(int) $id]) ? 'product' : false; }
function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_strip_all_tags($text) { return trim(strip_tags((string) $text)); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_title($title) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-'); }
function taxonomy_exists($taxonomy) { return false; }
function get_term_by($field, $value, $taxonomy) { return false; }
function wc_attribute_label($name, $product = null) { return $name; }
function wc_get_attribute_taxonomies() { return []; }
function wc_attribute_taxonomy_name($name) { return 'pa_' . $name; }
function wp_generate_uuid4() { return bin2hex(random_bytes(8)); }

class StubWpdb
{
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public function insert($table, $row) { $GLOBALS['log'][] = $row; return 1; }
    public function prepare($query, ...$args) { return [$query, $args]; }
    public function get_col($prepared)
    {
        // Only used for "post IDs whose _wss_sync_enabled is 1".
        $ids = [];
        foreach ($GLOBALS['meta'] as $id => $meta) {
            if (($meta['_wss_sync_enabled'] ?? '') === '1') {
                $ids[] = (string) $id;
            }
        }
        return $ids;
    }
}
$GLOBALS['wpdb'] = new StubWpdb();

/* ── WooCommerce product stubs (copy on read, write on save) ──────────── */

class WC_Product
{
    public $id = 0;
    public $parent_id = 0;
    public $type = 'simple';
    public $name = '';
    public $sku = '';
    public $regular = '';
    public $sale = '';
    public $manage = false;
    public $qty = null;
    public $status = 'instock';
    public $attributes = [];
    public $children = [];
    public $post_status = 'publish';

    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent_id; }
    public function set_parent_id($id) { $this->parent_id = (int) $id; }
    public function is_type($type) { return $this->type === $type; }
    public function get_name() { return $this->name; }
    public function set_name($name) { $this->name = (string) $name; }
    public function get_sku() { return $this->sku; }
    public function set_sku($sku)
    {
        $owner = wc_get_product_id_by_sku((string) $sku);
        if ($sku !== '' && $owner && $owner !== $this->id) {
            throw new Exception('Invalid or duplicated SKU.');
        }
        $this->sku = (string) $sku;
    }
    public function get_regular_price() { return $this->regular; }
    public function set_regular_price($price) { $this->regular = (string) $price; }
    public function get_sale_price() { return $this->sale; }
    public function set_sale_price($price) { $this->sale = (string) $price; }
    public function get_manage_stock() { return $this->manage; }
    public function set_manage_stock($manage) { $this->manage = (bool) $manage; }
    public function get_stock_quantity() { return $this->qty; }
    public function set_stock_quantity($qty) { $this->qty = (int) $qty; }
    public function get_stock_status() { return $this->status; }
    public function set_stock_status($status) { $this->status = (string) $status; }
    public function get_attributes() { return $this->attributes; }
    public function set_attributes($attributes) { $this->attributes = $attributes; }
    public function get_children() { return $this->children; }
    public function set_status($status) { $this->post_status = (string) $status; }
    public function get_status() { return $this->post_status; }
    public function save()
    {
        if ($this->id === 0) {
            $this->id = $GLOBALS['next_id']++;
        }
        if ($this->manage) {
            // WooCommerce derives the status of managed stock from the quantity.
            $this->status = ((int) $this->qty) > 0 ? 'instock' : 'outofstock';
        }
        $GLOBALS['products'][$this->id] = clone $this;
        return $this->id;
    }
}
class WC_Product_Simple extends WC_Product {}
class WC_Product_Variation extends WC_Product
{
    public $type = 'variation';
}
class WC_Product_Attribute {}

function wc_get_product($id)
{
    $id = (int) $id;
    return isset($GLOBALS['products'][$id]) ? clone $GLOBALS['products'][$id] : false;
}
function wc_get_product_id_by_sku($sku)
{
    foreach ($GLOBALS['products'] as $id => $product) {
        if ($sku !== '' && $product->sku === $sku) {
            return $id;
        }
    }
    return 0;
}

function make_simple(int $id, array $props): WC_Product
{
    $product = new WC_Product_Simple();
    $product->id = $id;
    foreach ($props as $key => $value) {
        $product->$key = $value;
    }
    $product->save();
    return $product;
}

function set_stock(int $id, int $qty): void
{
    $product = wc_get_product($id);
    $product->set_stock_quantity($qty);
    $product->save();
}

/* ── In-memory Google Sheet ────────────────────────────────────────────── */

interface WSS_Token_Provider
{
    public function get_access_token();
    public function is_connected(): bool;
    public function get_user_email(): string;
}

require FFLA_PATH . 'modules/woo-sheets-sync/includes/class-wss-google-sheets.php';
require FFLA_PATH . 'modules/woo-sheets-sync/includes/class-wss-logger.php';
require FFLA_PATH . 'modules/woo-sheets-sync/includes/class-wss-sync-groups.php';
require FFLA_PATH . 'modules/woo-sheets-sync/includes/services/class-wss-attribute-upsert-service.php';
require FFLA_PATH . 'modules/woo-sheets-sync/includes/services/class-wss-product-upsert-service.php';
require FFLA_PATH . 'modules/woo-sheets-sync/includes/services/class-wss-variation-upsert-service.php';
require FFLA_PATH . 'modules/woo-sheets-sync/includes/class-wss-sync-engine.php';
require FFLA_PATH . 'modules/woo-sheets-sync/includes/class-wss-sync-orchestrator.php';
require FFLA_PATH . 'modules/woo-sheets-sync/includes/api/class-wss-rest-routes.php';

class FakeProvider implements WSS_Token_Provider
{
    public function get_access_token() { return 'token'; }
    public function is_connected(): bool { return true; }
    public function get_user_email(): string { return 'sa@example.test'; }
}

class FakeSheets extends WSS_Google_Sheets
{
    /** @var array<string,array<int,array<int,string>>> tab => rows (row 0 = sheet row 1) */
    public $tabs = [];
    /** @var WP_Error[] Errors to return from the next batch_update calls. */
    public $fail_next = [];
    public $batch_calls = 0;

    public function __construct()
    {
        parent::__construct(new FakeProvider());
    }

    /** Store a value the way Google's USER_ENTERED parsing would. */
    public static function google_value($value): string
    {
        $value = (string) $value;
        if ($value !== '' && $value[0] === "'") {
            return substr($value, 1); // forced text
        }
        if (preg_match('/^0+\d+$/', $value)) {
            return ltrim($value, '0'); // parsed as a number
        }
        return $value;
    }

    public function ensure_headers(string $spreadsheet_id, string $tab_name)
    {
        if (!isset($this->tabs[$tab_name])) {
            $this->tabs[$tab_name] = [];
        }
        $this->tabs[$tab_name][0] = ['product_id', 'variation_id', 'product_name', 'attributes', 'sku', 'regular_price', 'sale_price', 'stock_qty', 'stock_status', 'manage_stock', 'woo_updated_at', 'sheet_updated_at'];
        return true;
    }

    public function read_range_paginated(string $spreadsheet_id, string $tab_name, string $columns = 'A:L', int $chunk_size = 2000)
    {
        $rows = $this->tabs[$tab_name] ?? [];
        ksort($rows);
        $out = [];
        foreach ($rows as $row) {
            $out[] = array_map('strval', $row);
        }
        return $out;
    }

    public function read_range(string $spreadsheet_id, string $range)
    {
        [$tab, $col, $row] = self::parse_cell($range);
        return [[(string) ($this->tabs[$tab][$row - 1][$col] ?? '')]];
    }

    private static function col_index(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }
        return $n - 1;
    }

    private static function parse_cell(string $range): array
    {
        if (!preg_match("/^'(.*)'!([A-Z]+)(\\d+)(?::([A-Z]+)(\\d+))?$/", $range, $m)) {
            throw new RuntimeException("Unparsable range $range");
        }
        return [str_replace("''", "'", $m[1]), self::col_index($m[2]), (int) $m[3]];
    }

    public function batch_update(string $spreadsheet_id, array $data)
    {
        $this->batch_calls++;
        if ($this->fail_next) {
            return array_shift($this->fail_next);
        }
        foreach ($data as $update) {
            [$tab, $col, $row] = self::parse_cell($update['range']);
            foreach ($update['values'] as $r => $values) {
                foreach (array_values($values) as $c => $value) {
                    $this->tabs[$tab][$row - 1 + $r][$col + $c] = self::google_value($value);
                }
            }
        }
        return ['ok' => true];
    }

    public function append_rows(string $spreadsheet_id, string $range, array $values)
    {
        [$tab] = self::parse_cell(preg_replace('/!A:L$/', '!A1', $range));
        $start = count($this->tabs[$tab] ?? []) + 1;
        foreach ($values as $i => $row) {
            $this->tabs[$tab][$start - 1 + $i] = array_map([__CLASS__, 'google_value'], array_values($row));
        }
        $end = $start + count($values) - 1;
        return ['updates' => ['updatedRange' => "'" . $tab . "'!A{$start}:L{$end}"]];
    }

    /** 1-based sheet row of a variation in a tab, or 0. */
    public function row_of(string $tab, int $vid): int
    {
        foreach ($this->tabs[$tab] ?? [] as $index => $row) {
            if ($index > 0 && (int) ($row[1] ?? 0) === $vid) {
                return $index + 1;
            }
        }
        return 0;
    }

    public function cell(string $tab, int $vid, int $col): string
    {
        $row = $this->row_of($tab, $vid);
        return $row ? (string) ($this->tabs[$tab][$row - 1][$col] ?? '') : '';
    }

    public function set_cell(string $tab, int $vid, int $col, string $value): void
    {
        $row = $this->row_of($tab, $vid);
        if (!$row) {
            throw new RuntimeException("No row for #$vid in $tab");
        }
        $this->tabs[$tab][$row - 1][$col] = $value;
    }
}

const COL_SKU = 4;
const COL_SALE = 6;
const COL_QTY = 7;
const COL_STATUS = 8;

$sheets = new FakeSheets();
$logger = new WSS_Logger();

function run_sync(FakeSheets $sheets, WSS_Logger $logger): array
{
    $result = WSS_Sync_Orchestrator::run_all($sheets, $logger);
    check(!isset($result['error']), 'sync run without error: ' . ($result['error'] ?? ''));
    return $result;
}

function stock_of(int $id): int
{
    return (int) wc_get_product($id)->get_stock_quantity();
}

/* ── 1. Managed stock in two tabs: an order is never undone ───────────── */

update_option('wss_settings', [
    'sheet_id'    => 'sheet-1',
    'sync_groups' => [
        ['id' => 'g1', 'tab_name' => 'T1', 'product_ids' => [10], 'category_ids' => [], 'tag_ids' => []],
        ['id' => 'g2', 'tab_name' => 'T2', 'product_ids' => [10], 'category_ids' => [], 'tag_ids' => []],
    ],
]);
make_simple(10, ['name' => 'Rifle', 'sku' => 'R-10', 'regular' => '500', 'manage' => true, 'qty' => 5]);

run_sync($sheets, $logger);
check($sheets->cell('T1', 10, COL_QTY) === '5' && $sheets->cell('T2', 10, COL_QTY) === '5', 'first sync writes qty 5 to both tabs');

// Pre-fix data: the old single snapshot exists alongside the new per-tab ones.
update_post_meta(10, WSS_Sync_Engine::META_SNAP_WOO, 5);
update_post_meta(10, WSS_Sync_Engine::META_SNAP_SHEET, 5);

// An order sells one unit; there is no real-time push.
set_stock(10, 4);
run_sync($sheets, $logger);
check(stock_of(10) === 4, 'order in WooCommerce is not undone by two stale tabs (got ' . stock_of(10) . ')');
check($sheets->cell('T1', 10, COL_QTY) === '4' && $sheets->cell('T2', 10, COL_QTY) === '4', 'both tabs show the sold-down quantity');

run_sync($sheets, $logger);
check(stock_of(10) === 4, 'a further run keeps the quantity (no oscillation)');

// Real-time push wrote only T2 (and its own snapshot) after another sale.
set_stock(10, 3);
$sheets->set_cell('T2', 10, COL_QTY, '3');
WSS_Sync_Engine::write_stock_snapshot(10, 3, WSS_Sync_Engine::tab_snapshot_key('sheet-1', 'T2'));
run_sync($sheets, $logger);
check(stock_of(10) === 3, 'tab missed by the real-time push does not undo the sale');
check($sheets->cell('T1', 10, COL_QTY) === '3', 'tab missed by the real-time push is corrected');

// A manual edit in one tab reaches WooCommerce and the other tab.
$sheets->set_cell('T1', 10, COL_QTY, '9');
run_sync($sheets, $logger);
check(stock_of(10) === 9, 'manual stock edit in the first tab is applied');
check($sheets->cell('T2', 10, COL_QTY) === '9', 'manual stock edit spreads to the second tab');
run_sync($sheets, $logger);
check(stock_of(10) === 9 && $sheets->cell('T1', 10, COL_QTY) === '9', 'tabs stay converged after the manual edit');

// Both sides changed: WooCommerce wins and the conflict is logged.
set_stock(10, 8);
$sheets->set_cell('T1', 10, COL_QTY, '20');
$GLOBALS['log'] = [];
run_sync($sheets, $logger);
check(stock_of(10) === 8, 'conflict: WooCommerce wins');
$conflict_logged = false;
foreach ($GLOBALS['log'] as $row) {
    if (strpos((string) $row['message'], 'Stock conflict') === 0) {
        $conflict_logged = true;
    }
}
check($conflict_logged, 'conflict is logged');

/* ── 2. Per-tab snapshots and the legacy fallback ──────────────────────── */

update_post_meta(77, WSS_Sync_Engine::META_SNAP_WOO, 6);
update_post_meta(77, WSS_Sync_Engine::META_SNAP_SHEET, 7);
check(WSS_Sync_Engine::read_stock_snapshot(77, 'tab-a') === [6, 7], 'tab without its own snapshot starts from the legacy snapshot');
WSS_Sync_Engine::write_stock_snapshot(77, 2, 'tab-a');
check(WSS_Sync_Engine::read_stock_snapshot(77, 'tab-a') === [2, 2], 'per-tab snapshot is read back');
check(WSS_Sync_Engine::read_stock_snapshot(77, 'tab-b') === [6, 7], 'another tab is unaffected by tab-a');
check((int) get_post_meta(77, WSS_Sync_Engine::META_SNAP_WOO, true) === 6, 'legacy snapshot is no longer overwritten');
check(WSS_Sync_Engine::read_stock_snapshot(78, 'tab-a') === [null, null], 'no snapshot at all reads as unknown');
check(WSS_Sync_Engine::tab_snapshot_key('s', 'T1') !== WSS_Sync_Engine::tab_snapshot_key('s', 'T2'), 'tab keys differ per tab');

/* ── 3. Leading-zero SKUs ─────────────────────────────────────────────── */

update_option('wss_settings', [
    'sheet_id'    => 'sheet-1',
    'sync_groups' => [
        ['id' => 'g1', 'tab_name' => 'T1', 'product_ids' => [10, 20, 30], 'category_ids' => [], 'tag_ids' => []],
        ['id' => 'g2', 'tab_name' => 'T2', 'product_ids' => [10], 'category_ids' => [], 'tag_ids' => []],
    ],
]);
make_simple(20, ['name' => 'Ammo', 'sku' => '00123', 'regular' => '20', 'manage' => false]);
run_sync($sheets, $logger);
check($sheets->cell('T1', 20, COL_SKU) === '00123', 'SKU with leading zeros is written as text and keeps its zeros');

// A sheet written by an older version lost the zeros.
$sheets->set_cell('T1', 20, COL_SKU, '123');
run_sync($sheets, $logger);
check(wc_get_product(20)->get_sku() === '00123', 'SKU without its leading zeros does not overwrite the WooCommerce SKU');
check($sheets->cell('T1', 20, COL_SKU) === '00123', 'the damaged SKU cell is rewritten as text');

// A real SKU edit still applies.
$sheets->set_cell('T1', 20, COL_SKU, 'AMMO-9');
run_sync($sheets, $logger);
check(wc_get_product(20)->get_sku() === 'AMMO-9', 'a real SKU edit is applied');

/* ── 4. Empty sale price cell ─────────────────────────────────────────── */

make_simple(30, ['name' => 'Scope', 'sku' => 'S-30', 'regular' => '300', 'sale' => '250', 'manage' => false]);
run_sync($sheets, $logger);
$sheets->set_cell('T1', 30, COL_SALE, '');
$result = run_sync($sheets, $logger);
check(wc_get_product(30)->get_sale_price() === '250', 'empty sale price cell keeps the sale price');
check((int) $result['sheet_to_woo']['updated'] === 0, 'empty sale price cell is not counted as an update');
check($sheets->cell('T1', 30, COL_SALE) === '250', 'empty sale price cell is refilled');

$sheets->set_cell('T1', 30, COL_SALE, '0');
run_sync($sheets, $logger);
check(wc_get_product(30)->get_sale_price() === '', '0 removes the sale price');
$result = run_sync($sheets, $logger);
check((int) $result['sheet_to_woo']['updated'] === 0, 'a removed sale price is not re-counted on later runs');

/* ── 5. Simple product created from a sheet row joins the tab group ───── */

$sheets->tabs['T1'][] = ['', '', 'New Holster', '', 'NEW-1', '35', '', '3', 'instock', 'TRUE', '', ''];
$result = run_sync($sheets, $logger);
$new_id = wc_get_product_id_by_sku('NEW-1');
check($new_id > 0, 'new row creates a simple product');
check((int) $result['sheet_to_woo']['created'] === 1, 'created count is reported');
$groups = WSS_Sync_Groups::get_groups();
check(in_array($new_id, $groups[0]['product_ids'], true), 'created product is added to its tab group');
check(!in_array($new_id, $groups[1]['product_ids'], true), 'created product is not added to other groups');
check((int) $sheets->cell('T1', $new_id, 1) === $new_id, 'IDs are written back to the row');
$sheets->set_cell('T1', $new_id, 5, '39');
run_sync($sheets, $logger);
check(wc_get_product($new_id)->get_regular_price() === '39', 'created product keeps syncing on later runs');

/* ── 6. Status-only edit on managed stock is explained, not applied ──── */

$GLOBALS['log'] = [];
$sheets->set_cell('T1', 10, COL_STATUS, 'onbackorder');
run_sync($sheets, $logger);
check(wc_get_product(10)->get_stock_status() === 'instock', 'status-only edit does not change managed stock status');
$explained = false;
foreach ($GLOBALS['log'] as $row) {
    if (strpos((string) $row['message'], 'Stock status not applied') === 0) {
        $explained = true;
    }
}
check($explained, 'status-only edit is explained in the log');
check($sheets->cell('T1', 10, COL_STATUS) === 'instock', 'status cell is corrected');

/* ── 7. Transient Google errors are retried ───────────────────────────── */

check(WSS_Google_Sheets::is_retryable_error(new WP_Error('wss_sheets_api', 'x', ['status' => 503])), '503 is retryable');
check(WSS_Google_Sheets::is_retryable_error(new WP_Error('wss_sheets_api', 'x', ['status' => 429])), '429 is retryable');
check(WSS_Google_Sheets::is_retryable_error(new WP_Error('http_request_failed', 'timeout')), 'network error is retryable');
check(!WSS_Google_Sheets::is_retryable_error(new WP_Error('wss_sheets_api', 'x', ['status' => 403])), '403 is not retryable');
check(!WSS_Google_Sheets::is_retryable_error(new WP_Error('wss_sheets_api', 'x')), 'error without status is not retryable');

$engine = new WSS_Sync_Engine($sheets, $logger, ['sheet_id' => 'sheet-1'], ['tab_name' => 'T1']);
$retry  = new ReflectionMethod(WSS_Sync_Engine::class, 'batch_update_with_retry');
$retry->setAccessible(true);
$sheets->fail_next   = [new WP_Error('wss_sheets_api', 'busy', ['status' => 503])];
$sheets->batch_calls = 0;
$ok = $retry->invoke($engine, 'sheet-1', [['range' => "'T1'!K2", 'values' => [['x']]]]);
check(!is_wp_error($ok) && $sheets->batch_calls === 2, 'a 503 batch write is retried and succeeds');
$sheets->fail_next   = [new WP_Error('wss_sheets_api', 'denied', ['status' => 403])];
$sheets->batch_calls = 0;
$denied = $retry->invoke($engine, 'sheet-1', [['range' => "'T1'!K2", 'values' => [['x']]]]);
check(is_wp_error($denied) && $sheets->batch_calls === 1, 'a 403 batch write is not retried');

/* ── 8. REST / sheet payload normalization ────────────────────────────── */

check(WSS_Sync_Engine::normalize_manage_stock(true) === 'TRUE', 'boolean true manages stock');
check(WSS_Sync_Engine::normalize_manage_stock(false) === 'FALSE', 'boolean false does not');
check(WSS_Sync_Engine::normalize_manage_stock('yes') === 'TRUE' && WSS_Sync_Engine::normalize_manage_stock('FALSE') === 'FALSE', 'text values are understood');
check(WSS_Sync_Engine::normalize_manage_stock('') === '' && WSS_Sync_Engine::normalize_manage_stock('maybe') === '', 'unknown values change nothing');
check(WSS_Product_Upsert_Service::attributes_to_string([['label' => 'Color', 'value' => 'Red'], ['name' => 'Size', 'option' => 'L']]) === 'Color: Red | Size: L', 'attribute list becomes the sheet string');
check(WSS_Product_Upsert_Service::attributes_to_string(['Caliber' => '9mm']) === 'Caliber: 9mm', 'attribute map becomes the sheet string');
check(WSS_Product_Upsert_Service::attributes_to_string('Color: Red') === 'Color: Red', 'attribute string is kept');
$normalized = WSS_Product_Upsert_Service::normalize_payload(['manage_stock' => true, 'attributes' => ['A' => 'B'], 'sku' => ['bad']]);
check($normalized['manage_stock'] === 'TRUE' && $normalized['attributes'] === 'A: B' && $normalized['sku'] === '', 'payload is normalized');

$service = new WSS_Product_Upsert_Service(new WSS_Attribute_Upsert_Service());
$res = $service->upsert_simple(['name' => 'API Knife', 'sku' => 'API-1', 'regular_price' => '15', 'manage_stock' => true, 'stock_qty' => 4, 'status' => 'draft']);
check(!is_wp_error($res) && $res['action'] === 'created', 'API create works');
$api = wc_get_product($res['product_id']);
check($api->get_manage_stock() === true && $api->get_stock_quantity() === 4, 'API boolean manage_stock is honoured');
check($api->get_status() === 'draft', 'API status is honoured');
$res2 = $service->upsert_simple(['product_id' => $res['product_id'], 'regular_price' => '18', 'manage_stock' => false]);
check(!is_wp_error($res2) && wc_get_product($res['product_id'])->get_regular_price() === '18', 'update by product_id works without a name');
check(wc_get_product($res['product_id'])->get_manage_stock() === false, 'API boolean false turns stock management off');
check(wc_get_product($res['product_id'])->get_name() === 'API Knife', 'update by product_id keeps the name when none is given');
check(is_wp_error($service->upsert_simple(['product_id' => 99999, 'name' => 'x'])), 'unknown product_id is refused');
check(is_wp_error($service->upsert_simple(['name' => 'x', 'type' => 'variable'])), 'type other than simple is refused');
check(is_wp_error($service->upsert_simple(['name' => 'x', 'status' => 'trash'])), 'unsupported status is refused');
check(is_wp_error($service->upsert_simple(['product_id' => $res['product_id'], 'sku' => 'R-10'])), 'update by product_id refuses another product\'s SKU');

// WordPress calls sanitize callbacks with three arguments; a PHP built-in
// such as intval() throws ArgumentCountError on PHP 8 when given more.
check(call_user_func([WSS_REST_Routes::class, 'to_int'], '7', null, 'stock_qty') === 7, 'REST integer sanitizer accepts the three callback arguments');
check(strpos((string) file_get_contents(FFLA_PATH . 'modules/woo-sheets-sync/includes/api/class-wss-rest-routes.php'), "'sanitize_callback' => 'intval'") === false, 'no REST arg uses intval() as its sanitize callback');

/* ── 9. Real-time row map shape ───────────────────────────────────────── */

check(WSS_Sync_Engine::row_map_locations(['tab' => 'T1', 'row' => 5]) === ['T1' => 5], 'old single-location entries are read');
check(WSS_Sync_Engine::row_map_locations(['tab' => 'T2', 'row' => 3, 'locations' => ['T1' => 5, 'T2' => 3]]) === ['T1' => 5, 'T2' => 3], 'new entries list every tab');
$map = get_option('wss_row_map', []);
$locations = WSS_Sync_Engine::row_map_locations($map[10] ?? []);
check(isset($locations['T1'], $locations['T2']), 'row map remembers a product in both tabs');
check($locations['T1'] === $sheets->row_of('T1', 10) && $locations['T2'] === $sheets->row_of('T2', 10), 'row map rows are correct');

/* ── 10. Tab group helpers ────────────────────────────────────────────── */

$groups = WSS_Sync_Groups::get_groups();
check(WSS_Sync_Groups::tab_in_use($groups, 't2'), 'tab in use is found case-insensitively');
check(!WSS_Sync_Groups::tab_in_use($groups, 'T9'), 'unused tab is not in use');
check(WSS_Sync_Groups::find_group_by_tab('T2')['id'] === 'g2', 'group found by tab name');
WSS_Sync_Groups::set_product_groups(30, ['g2']);
$groups = WSS_Sync_Groups::get_groups();
check(!in_array(30, $groups[0]['product_ids'], true) && in_array(30, $groups[1]['product_ids'], true), 'product edit screen moves a product between tabs');
check(get_post_meta(30, '_wss_sync_enabled', true) === '1', 'sync flag follows group membership');
WSS_Sync_Groups::set_product_groups(30, []);
check(get_post_meta(30, '_wss_sync_enabled', true) === '', 'flag is removed when the product leaves every tab');
check(WSS_Google_Sheets::as_text('00123') === "'00123" && WSS_Google_Sheets::as_text('') === '', 'text marker for user-entered writes');

echo "Woo Sheets Sync smoke checks passed ($checks checks).\n";
