<?php
/**
 * WSS Sync Engine — Bidirectional sync between WooCommerce and Google Sheets.
 *
 * Sync order: Sheet→Woo runs FIRST so edits in the sheet take priority,
 * then Woo→Sheet writes current WooCommerce data back to the sheet.
 *
 * Change detection uses DATA COMPARISON (not timestamps) so the user
 * never needs to manually touch a timestamp column.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Sync_Engine
{
    /** @var WSS_Google_Sheets */
    private $sheets;

    /** @var WSS_Logger */
    private $logger;

    /** @var string */
    private $sheet_id;

    /** @var string */
    private $tab_name;

    /**
     * When null, sync all products with _wss_sync_enabled (legacy).
     * When int[], only these parent product IDs (simple or variable parent).
     *
     * @var int[]|null
     */
    private $allowed_parent_product_ids;

    /** @var string */
    private $group_id;

    /** @var bool */
    private $persist_last_sync;

    /** @var WSS_Attribute_Upsert_Service|null */
    private $attribute_upsert_service;

    /** @var WSS_Product_Upsert_Service|null */
    private $product_upsert_service;

    /** @var WSS_Variation_Upsert_Service|null */
    private $variation_upsert_service;

    /** Column indices (0-based) matching the header layout. */
    private const COL_PRODUCT_ID      = 0;  // A
    private const COL_VARIATION_ID    = 1;  // B
    private const COL_PRODUCT_NAME    = 2;  // C
    private const COL_ATTRIBUTES      = 3;  // D
    private const COL_SKU             = 4;  // E
    private const COL_REGULAR_PRICE   = 5;  // F
    private const COL_SALE_PRICE      = 6;  // G
    private const COL_STOCK_QTY       = 7;  // H
    private const COL_STOCK_STATUS    = 8;  // I
    private const COL_MANAGE_STOCK    = 9;  // J
    private const COL_WOO_UPDATED_AT  = 10; // K
    private const COL_SHEET_UPDATED   = 11; // L

    /** Syncable data columns (used for diff comparison). */
    private const DATA_COLS = [
        self::COL_SKU,
        self::COL_REGULAR_PRICE,
        self::COL_SALE_PRICE,
        self::COL_STOCK_QTY,
        self::COL_STOCK_STATUS,
        self::COL_MANAGE_STOCK,
    ];

    private const VALID_STOCK_STATUSES = ['instock', 'outofstock', 'onbackorder'];

    /**
     * Per-variation snapshot meta: the stock qty that Woo and the Sheet last
     * AGREED on at the end of a sync. Change detection compares the live value
     * to these to decide which side actually moved since then.
     */
    public const META_SNAP_WOO   = '_wss_snap_woo';
    public const META_SNAP_SHEET = '_wss_snap_sheet';

    /**
     * True while the engine is writing Sheet→Woo (apply + save). The real-time
     * push hook checks this to avoid reacting to our own stock writes.
     *
     * @var bool
     */
    private static $applying = false;

    /**
     * Whether a Sheet→Woo apply is currently in progress.
     */
    public static function is_applying(): bool
    {
        return self::$applying;
    }

    /**
     * @param array<string,mixed> $settings   wss_settings option.
     * @param array<string,mixed> $context    Optional: tab_name, allowed_parent_product_ids, group_id, persist_last_sync.
     */
    private function batch_update_with_retry($sheet_id, array $updates)
    {
        $attempts = 0;
        $max = 3;
        $delay = 1;
        while ($attempts < $max) {
            $result = $this->sheets->batch_update($sheet_id, $updates);
            if (!is_wp_error($result)) {
                return $result;
            }
            $code = (string) $result->get_error_code();
            if (!in_array($code, array('sheets_http_429', 'sheets_http_500', 'sheets_http_502', 'sheets_http_503', 'sheets_http_504'), true)) {
                return $result;
            }
            $attempts++;
            if ($attempts < $max) {
                sleep($delay);
                $delay *= 2;
            }
        }
        return $result;
    }

    public function __construct(WSS_Google_Sheets $sheets, WSS_Logger $logger, array $settings, array $context = [])
    {
        $this->sheets   = $sheets;
        $this->logger   = $logger;
        $this->sheet_id = $settings['sheet_id'] ?? '';
        $this->tab_name = isset($context['tab_name']) && (string) $context['tab_name'] !== ''
            ? (string) $context['tab_name']
            : ($settings['tab_name'] ?? 'Inventory');

        if (array_key_exists('allowed_parent_product_ids', $context)) {
            $raw = $context['allowed_parent_product_ids'];
            $this->allowed_parent_product_ids = is_array($raw)
                ? array_values(array_unique(array_map('intval', $raw)))
                : null;
        } else {
            $this->allowed_parent_product_ids = null;
        }

        $this->group_id           = isset($context['group_id']) ? (string) $context['group_id'] : '';
        $this->persist_last_sync  = !isset($context['persist_last_sync']) || !empty($context['persist_last_sync']);

        $this->attribute_upsert_service = class_exists('WSS_Attribute_Upsert_Service')
            ? new WSS_Attribute_Upsert_Service()
            : null;
        $this->product_upsert_service = $this->attribute_upsert_service && class_exists('WSS_Product_Upsert_Service')
            ? new WSS_Product_Upsert_Service($this->attribute_upsert_service)
            : null;
        $this->variation_upsert_service = $this->attribute_upsert_service && $this->product_upsert_service && class_exists('WSS_Variation_Upsert_Service')
            ? new WSS_Variation_Upsert_Service($this->attribute_upsert_service, $this->product_upsert_service)
            : null;
    }

    /**
     * Whether this parent product ID is in scope for the current sync run.
     */
    private function is_parent_allowed(int $parent_id): bool
    {
        if ($this->allowed_parent_product_ids === null) {
            return true;
        }

        return $parent_id > 0 && in_array($parent_id, $this->allowed_parent_product_ids, true);
    }

    /**
     * Add a parent product to in-memory sync scope for this run.
     *
     * This allows products/variations created from the sheet during Phase 1
     * to be included in Phase 2 (Woo→Sheet) of the same execution.
     */
    private function add_parent_to_scope(int $parent_id): void
    {
        if ($parent_id <= 0 || $this->allowed_parent_product_ids === null) {
            return;
        }

        if (!in_array($parent_id, $this->allowed_parent_product_ids, true)) {
            $this->allowed_parent_product_ids[] = $parent_id;
        }
    }

    /**
     * Parent post ID for a variation or simple product object.
     *
     * @param WC_Product $product Variation or simple.
     */
    private static function get_parent_product_id($product): int
    {
        $pid = (int) $product->get_parent_id();

        return $pid > 0 ? $pid : (int) $product->get_id();
    }

    /**
     * Build an A1 range using a safely quoted tab name.
     */
    private function a1_range(string $cells): string
    {
        $tab = str_replace("'", "''", (string) $this->tab_name);
        return "'" . $tab . "'!" . $cells;
    }

    /**
     * Merge this tab's variation_id → row mapping into the persisted option
     * used by the real-time push (WSS_Realtime_Push).
     *
     * Stored shape: [ (int)variation_id => ['tab' => string, 'row' => int] ]
     * where row is the 1-based sheet row number.
     *
     * @param array<int,int> $row_map variation_id → 0-based data index.
     */
    private function persist_row_map(array $row_map): void
    {
        $persisted = get_option('wss_row_map', []);
        if (!is_array($persisted)) {
            $persisted = [];
        }

        foreach ($row_map as $vid => $index) {
            $vid = (int) $vid;
            if ($vid <= 0) {
                continue;
            }
            $persisted[$vid] = [
                'tab' => $this->tab_name,
                'row' => (int) $index + 2, // +2: 0-based index + header row
            ];
        }

        update_option('wss_row_map', $persisted, false);
    }

    /**
     * Run a full bidirectional sync.
     *
     * Order: Sheet→Woo first (so sheet edits are applied), then Woo→Sheet.
     *
     * @return array Summary stats or error info.
     */
    public function run(): array
    {
        if (empty($this->sheet_id)) {
            return ['error' => __('No Google Sheet ID configured.', 'ffl-funnels-addons')];
        }

        // Ensure headers exist.
        $header_result = $this->sheets->ensure_headers($this->sheet_id, $this->tab_name);
        if (is_wp_error($header_result)) {
            return ['error' => $header_result->get_error_message()];
        }

        // Read the entire sheet once (reused by both phases). Paginated in
        // 2000-row chunks so we stay well under the Sheets API ~10MB cap on
        // single-range reads even for very large tabs.
        $chunk_size = (int) apply_filters('wss_sheet_read_chunk_size', 2000, $this->tab_name);
        $sheet_data = $this->sheets->read_range_paginated($this->sheet_id, $this->tab_name, 'A:L', $chunk_size);

        if (is_wp_error($sheet_data)) {
            return ['error' => $sheet_data->get_error_message()];
        }

        // Remove header row.
        $header = !empty($sheet_data) ? array_shift($sheet_data) : [];

        // Build variation_id → row index map.
        $row_map = [];
        foreach ($sheet_data as $index => $row) {
            $vid = $row[self::COL_VARIATION_ID] ?? '';
            if ($vid !== '') {
                $row_map[(int) $vid] = $index;
            }
        }

        // Persist variation_id → {tab,row} so the real-time push can locate a
        // variation's sheet row without re-reading the whole tab. Merge so that
        // entries from other tabs survive; later tabs win for shared variations
        // (matching the orchestrator's last-tab-wins ordering).
        $this->persist_row_map($row_map);

        // Phase 1: Sheet → Woo (sheet edits take priority).
        $stats_sheet = $this->sync_sheet_to_woo($sheet_data, $row_map);

        // Phase 2: Woo → Sheet (write current WooCommerce state back).
        $stats_woo = $this->sync_woo_to_sheet($sheet_data, $row_map);

        if ($this->persist_last_sync) {
            update_option('wss_last_sync', [
                'time'         => current_time('mysql'),
                'woo_to_sheet' => $stats_woo,
                'sheet_to_woo' => $stats_sheet,
            ], false);
        }

        return [
            'woo_to_sheet' => $stats_woo,
            'sheet_to_woo' => $stats_sheet,
        ];
    }

    /**
     * Phase 1: Sheet → Woo.
     *
     * Uses DATA COMPARISON to detect changes: if the sheet values differ
     * from WooCommerce values, the sheet wins and WooCommerce is updated.
     *
     * @param array $sheet_data Existing sheet rows (without header).
     * @param array $row_map   variation_id → row index in $sheet_data.
     * @return array Stats.
     */
    private function sync_sheet_to_woo(array $sheet_data, array $row_map): array
    {
        $stats = ['updated' => 0, 'created' => 0, 'skipped' => 0, 'errors' => 0];

        $timestamp_updates = [];
        $id_updates        = [];
        $now = gmdate('c');

        // Prime caches for every variation/simple product the sheet references
        // so we avoid N individual `get_post` + meta fetches inside the loop.
        $variation_ids = [];
        foreach ($sheet_data as $row) {
            $vid = (int) ($row[self::COL_VARIATION_ID] ?? 0);
            if ($vid > 0) {
                $variation_ids[$vid] = true;
            }
        }
        if (!empty($variation_ids) && function_exists('_prime_post_caches')) {
            _prime_post_caches(array_keys($variation_ids), false, true);
        }

        foreach ($sheet_data as $index => $row) {
            $variation_id = (int) ($row[self::COL_VARIATION_ID] ?? 0);
            $product_id   = (int) ($row[self::COL_PRODUCT_ID] ?? 0);
            $row_number   = $index + 2; // +2: 0-based index + header row

            // New product row: variation_id=0 and has a product name.
            if ($variation_id === 0) {
                $product_name = trim($row[self::COL_PRODUCT_NAME] ?? '');
                if ($product_name === '') {
                    continue; // No name → skip.
                }

                $result = $this->create_product_from_row($row, $product_id, $row_number);
                if (is_wp_error($result)) {
                    $stats['errors']++;
                    $this->logger->log('sheet_to_woo', $product_id, 0, 'error', $result->get_error_message());
                } else {
                    $stats['created']++;
                    $this->add_parent_to_scope((int) ($result['product_id'] ?? 0));

                    // Seed snapshots for the freshly created variation so the
                    // next run's change detection has an agreed baseline.
                    $new_vid       = (int) ($result['variation_id'] ?? 0);
                    $sheet_qty_raw = trim((string) ($row[self::COL_STOCK_QTY] ?? ''));
                    if ($new_vid > 0 && $sheet_qty_raw !== '') {
                        update_post_meta($new_vid, self::META_SNAP_WOO, (int) $sheet_qty_raw);
                        update_post_meta($new_vid, self::META_SNAP_SHEET, (int) $sheet_qty_raw);
                    }

                    // $result = ['product_id' => int, 'variation_id' => int]
                    $id_updates[] = [
                        'range'  => $this->a1_range(sprintf('A%d:B%d', $row_number, $row_number)),
                        'values' => [[(string) $result['product_id'], (string) $result['variation_id']]],
                    ];
                    $timestamp_updates[] = [
                        'range'  => $this->a1_range(sprintf('K%d', $row_number)),
                        'values' => [[$now]],
                    ];
                }
                continue;
            }

            $variation = wc_get_product($variation_id);
            if (!$variation) {
                $stats['errors']++;
                $this->logger->log('sheet_to_woo', $product_id, $variation_id, 'error', 'Variation not found in WooCommerce.');
                continue;
            }

            $parent_scope_id = self::get_parent_product_id($variation);
            if (!$this->is_parent_allowed($parent_scope_id)) {
                $stats['skipped']++;
                continue;
            }

            // Resolve how stock (qty + status) should move, using snapshots so
            // an order that reduced Woo isn't reverted by a stale sheet value.
            $stock = $this->resolve_stock_direction($row, $variation);

            // Price / sale price / manage_stock stay sheet-authoritative (the
            // sheet always wins on those, as before).
            $nonstock_differs = $this->sheet_nonstock_fields_differ($row, $variation);

            $need_apply = $nonstock_differs || $stock['apply_stock'];

            if ($need_apply) {
                $parent_for_attrs = null;
                if ($variation->is_type('variation')) {
                    $parent_for_attrs = wc_get_product((int) $variation->get_parent_id());
                }

                // Guard the apply+save so the real-time push ignores our own write.
                self::$applying = true;
                try {
                    $applied = $this->apply_sheet_data_to_variation($variation, $row, $parent_for_attrs, $stock['apply_stock']);
                    if (is_wp_error($applied)) {
                        $stats['errors']++;
                        $this->logger->log('sheet_to_woo', $product_id, $variation_id, 'error', $applied->get_error_message());
                        continue;
                    }
                    $variation->save();
                } finally {
                    self::$applying = false;
                }

                $stats['updated']++;
                $this->logger->log('sheet_to_woo', $product_id, $variation_id, 'success', 'Variation updated from sheet.');

                // Update last synced meta.
                update_post_meta($variation_id, '_wss_last_synced', $now);

                // Prepare timestamp update for this row.
                $timestamp_updates[] = [
                    'range'  => $this->a1_range(sprintf('K%d', $row_number)),
                    'values' => [[$now]],
                ];
            } else {
                $stats['skipped']++;
            }

            if ($stock['conflict']) {
                $this->logger->log(
                    'sheet_to_woo',
                    $product_id,
                    $variation_id,
                    'skipped',
                    sprintf(
                        'Stock conflict: Sheet and Woo both changed since last sync; Woo wins (qty=%s). Sheet will be updated to match.',
                        $stock['has_qty'] && $stock['final_qty'] !== null ? (string) $stock['final_qty'] : 'n/a'
                    )
                );
            }

            // Record the agreed stock snapshot for next run's change detection.
            // For "Woo wins" / "conflict", Phase 2 pushes Woo→Sheet, so both
            // sides converge on the Woo qty captured here.
            if ($stock['has_qty'] && $stock['final_qty'] !== null) {
                update_post_meta($variation_id, self::META_SNAP_WOO, (int) $stock['final_qty']);
                update_post_meta($variation_id, self::META_SNAP_SHEET, (int) $stock['final_qty']);
            }
        }

        $sheet_updates = array_merge($id_updates, $timestamp_updates);
        if (!empty($sheet_updates)) {
            $result = $this->batch_update_with_retry($this->sheet_id, $sheet_updates);
            if (is_wp_error($result)) {
                $this->logger->log('sheet_to_woo', 0, 0, 'error', 'Sheet batch update failed: ' . $result->get_error_message());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Check whether the sheet differs from Woo on the SHEET-AUTHORITATIVE
     * fields only: regular price, sale price, manage_stock. Stock quantity and
     * stock status are handled separately by resolve_stock_direction() so an
     * order that reduced Woo isn't reverted by a stale sheet value.
     *
     * @param array      $row       Sheet row.
     * @param WC_Product $variation WooCommerce variation.
     * @return bool True if a sheet-authoritative field differs.
     */
    private function sheet_nonstock_fields_differ(array $row, $variation): bool
    {
        // Regular price.
        $sheet_regular = trim($row[self::COL_REGULAR_PRICE] ?? '');
        $woo_regular   = $variation->get_regular_price() ?: '';
        if ($sheet_regular !== '' && $this->normalize_price($sheet_regular) !== $this->normalize_price($woo_regular)) {
            return true;
        }

        // Sale price.
        $sheet_sale = trim($row[self::COL_SALE_PRICE] ?? '');
        $woo_sale   = $variation->get_sale_price() ?: '';
        if ($this->normalize_price($sheet_sale) !== $this->normalize_price($woo_sale)) {
            return true;
        }

        // Manage stock.
        $sheet_manage = strtoupper(trim($row[self::COL_MANAGE_STOCK] ?? ''));
        $woo_manage   = $variation->get_manage_stock() ? 'TRUE' : 'FALSE';
        if (($sheet_manage === 'TRUE' || $sheet_manage === 'FALSE') && $sheet_manage !== $woo_manage) {
            return true;
        }

        return false;
    }

    /**
     * Resolve which side wins for STOCK (qty + status), using the agreed
     * snapshots so we can tell "human edited the sheet" from "an order changed
     * Woo":
     *
     *  - Sheet moved, Woo didn't  → apply Sheet→Woo.
     *  - Woo moved, Sheet didn't  → keep Woo; Phase 2 pushes Woo→Sheet.
     *  - Both moved               → conflict: WOO WINS for qty (logged).
     *  - Neither                  → skip.
     *
     * Missing snapshots (first run after deploy) fall back to LEGACY behavior
     * (Sheet→Woo on diff) for that row and seed the snapshots, so a pending
     * sheet edit is not lost on the migration run.
     *
     * @param array      $row       Sheet row.
     * @param WC_Product $variation WooCommerce variation.
     * @return array{apply_stock:bool,final_qty:?int,conflict:bool,has_qty:bool}
     */
    private function resolve_stock_direction(array $row, $variation): array
    {
        $out = ['apply_stock' => false, 'final_qty' => null, 'conflict' => false, 'has_qty' => false];

        $sheet_status   = strtolower(trim($row[self::COL_STOCK_STATUS] ?? ''));
        $status_valid   = $sheet_status !== '' && in_array($sheet_status, self::VALID_STOCK_STATUSES, true);
        $status_differs = $status_valid && $sheet_status !== $variation->get_stock_status();

        // Variations not managing their own stock have no qty to reconcile;
        // fall back to legacy "sheet wins" for stock status only.
        if (!$variation->get_manage_stock()) {
            $out['apply_stock'] = $status_differs;
            return $out;
        }

        $out['has_qty'] = true;
        $vid     = (int) $variation->get_id();
        $woo_qty = (int) $variation->get_stock_quantity();

        $sheet_qty_raw = trim((string) ($row[self::COL_STOCK_QTY] ?? ''));
        $sheet_qty     = ($sheet_qty_raw !== '') ? (int) $sheet_qty_raw : null;

        $snap_woo_raw   = get_post_meta($vid, self::META_SNAP_WOO, true);
        $snap_sheet_raw = get_post_meta($vid, self::META_SNAP_SHEET, true);
        $snap_woo       = ($snap_woo_raw !== '') ? (int) $snap_woo_raw : null;
        $snap_sheet     = ($snap_sheet_raw !== '') ? (int) $snap_sheet_raw : null;

        $decision = self::decide_stock_direction($woo_qty, $sheet_qty, $snap_woo, $snap_sheet, $status_differs);

        $out['apply_stock'] = $decision['apply_stock'];
        $out['final_qty']   = $decision['final_qty'];
        $out['conflict']    = $decision['conflict'];

        return $out;
    }

    /**
     * Pure decision matrix for stock reconciliation (no WP/WC dependencies, so
     * it is unit-testable). Decides which side wins for stock quantity given
     * the live values and the last-agreed snapshots.
     *
     *  - No snapshots yet → LEGACY: Sheet wins on any diff (and the caller
     *    seeds snapshots afterward).
     *  - Sheet moved, Woo didn't → apply Sheet→Woo.
     *  - Woo moved, Sheet didn't → keep Woo (Phase 2 pushes Woo→Sheet).
     *  - Both moved              → conflict, Woo wins for qty.
     *  - Neither                 → no-op.
     *
     * @param int      $woo_qty       Live Woo stock quantity (managed stock).
     * @param int|null $sheet_qty     Sheet stock qty, or null when the cell is blank.
     * @param int|null $snap_woo      Last-agreed Woo snapshot, or null if unseeded.
     * @param int|null $snap_sheet    Last-agreed Sheet snapshot, or null if unseeded.
     * @param bool     $status_differs Whether the sheet's stock_status differs from Woo.
     * @return array{apply_stock:bool,final_qty:int,conflict:bool}
     */
    public static function decide_stock_direction(
        int $woo_qty,
        ?int $sheet_qty,
        ?int $snap_woo,
        ?int $snap_sheet,
        bool $status_differs
    ): array {
        $has_snaps = ($snap_woo !== null && $snap_sheet !== null);

        if (!$has_snaps) {
            // Migration run for this row: legacy Sheet→Woo on diff, then seed.
            $qty_differs = ($sheet_qty !== null && $sheet_qty !== $woo_qty);
            if ($qty_differs || $status_differs) {
                return [
                    'apply_stock' => true,
                    'final_qty'   => ($sheet_qty !== null) ? $sheet_qty : $woo_qty,
                    'conflict'    => false,
                ];
            }
            return ['apply_stock' => false, 'final_qty' => $woo_qty, 'conflict' => false];
        }

        $sheet_moved = ($sheet_qty !== null && $sheet_qty !== $snap_sheet);
        $woo_moved   = ($woo_qty !== $snap_woo);

        if ($sheet_moved && !$woo_moved) {
            // Human edited the sheet — apply it to Woo.
            return ['apply_stock' => true, 'final_qty' => $sheet_qty, 'conflict' => false];
        }
        if ($woo_moved && !$sheet_moved) {
            // An order/refund changed Woo — keep it; Phase 2 pushes to the sheet.
            return ['apply_stock' => false, 'final_qty' => $woo_qty, 'conflict' => false];
        }
        if ($sheet_moved && $woo_moved) {
            // Both changed — Woo wins for qty; Phase 2 pushes to the sheet.
            return ['apply_stock' => false, 'final_qty' => $woo_qty, 'conflict' => true];
        }

        // Neither moved.
        return ['apply_stock' => false, 'final_qty' => $woo_qty, 'conflict' => false];
    }

    /**
     * Phase 2: Woo → Sheet.
     *
     * Only updates rows where WooCommerce data differs from the sheet,
     * avoiding unnecessary API calls.
     *
     * @param array $sheet_data Existing sheet rows (without header).
     * @param array $row_map   variation_id → row index in $sheet_data.
     * @return array Stats.
     */
    private function sync_woo_to_sheet(array $sheet_data, array &$row_map): array
    {
        $stats = ['updated' => 0, 'appended' => 0, 'skipped' => 0, 'errors' => 0];

        if ($this->allowed_parent_product_ids === null) {
            global $wpdb;
            // Direct SQL avoids posts_per_page=-1 + all the WP_Query overhead
            // for sites with hundreds of synced parents.
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'product'
                   AND p.post_status = 'publish'
                   AND pm.meta_key = %s
                   AND pm.meta_value = %s",
                '_wss_sync_enabled',
                '1'
            ));
            $product_ids = array_map('intval', (array) $product_ids);
        } else {
            $product_ids = $this->allowed_parent_product_ids;
        }

        if (empty($product_ids)) {
            return $stats;
        }

        // Warm the post/meta caches for all parent IDs in a single round-trip
        // so each wc_get_product() call below does not hit the DB one at a time.
        if (function_exists('_prime_post_caches')) {
            _prime_post_caches($product_ids, false, true);
        }

        $batch_updates = [];
        $append_rows   = [];
        $now           = gmdate('c'); // ISO 8601

        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                $stats['errors']++;
                $this->logger->log('woo_to_sheet', $product_id, $product_id, 'error', 'Product not found.');
                continue;
            }

            $variations = $this->get_syncable_variations($product);

            foreach ($variations as $variation) {
                $vid = $variation->get_id();
                $row = $this->build_row($product, $variation, $now);

                if (isset($row_map[$vid])) {
                    // Row exists — only update if data actually changed.
                    $sheet_row = $sheet_data[$row_map[$vid]] ?? [];

                    if (!$this->woo_row_differs_from_sheet($row, $sheet_row)) {
                        $stats['skipped']++;
                        continue;
                    }

                    $row_number = $row_map[$vid] + 2; // +2: 0-based index + header row
                    $batch_updates[] = [
                        'range'  => $this->a1_range(sprintf('A%d:L%d', $row_number, $row_number)),
                        'values' => [$row],
                    ];
                    $stats['updated']++;
                    $this->logger->log('woo_to_sheet', $product_id, $vid, 'success', 'Row updated.');
                } else {
                    // New row — append.
                    $append_rows[] = $row;
                    $stats['appended']++;
                    $this->logger->log('woo_to_sheet', $product_id, $vid, 'success', 'Row appended.');
                }
            }
        }

        if (!empty($batch_updates)) {
            $result = $this->batch_update_with_retry($this->sheet_id, $batch_updates);
            if (is_wp_error($result)) {
                $this->logger->log('woo_to_sheet', 0, 0, 'error', 'Batch update failed: ' . $result->get_error_message());
                $stats['errors']++;
            }
        }

        // Append new rows.
        if (!empty($append_rows)) {
            $result = $this->sheets->append_rows($this->sheet_id, $this->a1_range('A:L'), $append_rows);
            if (is_wp_error($result)) {
                $this->logger->log('woo_to_sheet', 0, 0, 'error', 'Append failed: ' . $result->get_error_message());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Check if the built Woo row differs from the existing sheet row.
     *
     * Compares data columns only (ignores timestamps).
     *
     * @param array $woo_row   Row built from WooCommerce data.
     * @param array $sheet_row Existing sheet row.
     * @return bool True if data differs.
     */
    private function woo_row_differs_from_sheet(array $woo_row, array $sheet_row): bool
    {
        foreach (self::DATA_COLS as $col) {
            $woo_val   = trim($woo_row[$col] ?? '');
            $sheet_val = trim($sheet_row[$col] ?? '');

            // Normalize numeric comparisons to avoid "29.99" vs "29.990000" mismatches.
            if (is_numeric($woo_val) && is_numeric($sheet_val)) {
                if ((float) $woo_val !== (float) $sheet_val) {
                    return true;
                }
            } elseif ($woo_val !== $sheet_val) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a price string for comparison.
     *
     * @param string $price Price value.
     * @return string Normalized price or empty string.
     */
    private function normalize_price(string $price): string
    {
        $price = trim($price);
        if ($price === '') {
            return '';
        }
        return rtrim(rtrim(number_format((float) $price, 6, '.', ''), '0'), '.');
    }

    /**
     * Get syncable variations (or the product itself for simple products).
     *
     * @param WC_Product $product The parent product.
     * @return WC_Product[] Array of product/variation objects.
     */
    private function get_syncable_variations($product): array
    {
        if ($product->is_type('variable')) {
            $children   = $product->get_children();
            $variations = [];
            foreach ($children as $child_id) {
                $child = wc_get_product($child_id);
                if ($child) {
                    $variations[] = $child;
                }
            }
            return $variations;
        }

        // Simple product — sync as its own "variation".
        return [$product];
    }

    /**
     * Build a sheet row array from a WC product/variation.
     *
     * @param WC_Product $parent    Parent product (for the name).
     * @param WC_Product $variation Variation (or simple product).
     * @param string     $now       Current ISO 8601 timestamp.
     * @return array 12-column row.
     */
    private function build_row($parent, $variation, string $now): array
    {
        $parent_id = $parent->get_id();
        $vid       = $variation->get_id();

        return [
            (string) $parent_id,                                        // A: product_id
            (string) $vid,                                              // B: variation_id
            $parent->get_name(),                                        // C: product_name
            self::get_variation_attributes($variation),                  // D: attributes
            $variation->get_sku() ?: '',                                // E: sku
            $variation->get_regular_price() ?: '',                      // F: regular_price
            $variation->get_sale_price() ?: '',                         // G: sale_price
            $variation->get_manage_stock() ? (string) $variation->get_stock_quantity() : '', // H: stock_qty
            $variation->get_stock_status(),                             // I: stock_status
            $variation->get_manage_stock() ? 'TRUE' : 'FALSE',         // J: manage_stock
            $now,                                                       // K: woo_updated_at
            '',                                                         // L: sheet_updated_at
        ];
    }

    /**
     * Get a human-readable attribute string for a variation.
     *
     * @param WC_Product $variation The variation product.
     * @return string e.g. "Color: Red | Size: L" or "" for simple products.
     */
    private static function get_variation_attributes($variation): string
    {
        if (!$variation->is_type('variation')) {
            return '';
        }

        $attributes = $variation->get_attributes();
        if (empty($attributes)) {
            return '';
        }

        $parts = [];
        foreach ($attributes as $taxonomy => $value) {
            // Get the human-readable attribute name.
            $label = wc_attribute_label(str_replace('attribute_', '', $taxonomy), $variation);

            // Get the human-readable term name (value may be a slug).
            if (taxonomy_exists(str_replace('attribute_', '', $taxonomy))) {
                $term = get_term_by('slug', $value, str_replace('attribute_', '', $taxonomy));
                if ($term && !is_wp_error($term)) {
                    $value = $term->name;
                }
            }

            if ($value !== '') {
                $parts[] = $label . ': ' . $value;
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * Apply sheet row data to a WC variation.
     *
     * Only modifies fields that have non-empty values in the sheet.
     * Empty cells are treated as "no change" (not "clear the value").
     *
     * @param WC_Product $variation          The WC variation to update.
     * @param array      $row                Sheet row data.
     * @param WC_Product $parent             Parent product (for attribute sync).
     * @param bool       $apply_stock_levels When false, stock_quantity and
     *                                       stock_status are NOT applied (the
     *                                       sheet lost the stock reconciliation
     *                                       for this row); prices/manage_stock/
     *                                       attributes still apply as usual.
     * @return true|WP_Error
     */
    private function apply_sheet_data_to_variation($variation, array $row, $parent = null, bool $apply_stock_levels = true)
    {
        // Regular price.
        $regular_price = trim($row[self::COL_REGULAR_PRICE] ?? '');
        if ($regular_price !== '') {
            $regular_price = (float) $regular_price;
            if ($regular_price < 0) {
                return new WP_Error('wss_validation', __('Regular price cannot be negative.', 'ffl-funnels-addons'));
            }
            $variation->set_regular_price((string) $regular_price);
        }

        // Sale price — only update if the cell has an explicit value.
        // "0" clears the sale price, empty cell means "don't change".
        $sale_price = trim($row[self::COL_SALE_PRICE] ?? '');
        if ($sale_price !== '') {
            $sale_price_f = (float) $sale_price;
            if ($sale_price_f < 0) {
                return new WP_Error('wss_validation', __('Sale price cannot be negative.', 'ffl-funnels-addons'));
            }
            if ($sale_price_f == 0) {
                $variation->set_sale_price(''); // "0" = clear sale price
            } else {
                $variation->set_sale_price((string) $sale_price_f);
            }
        }
        // Empty cell = don't touch sale price.

        // Manage stock.
        $manage_stock = strtoupper(trim($row[self::COL_MANAGE_STOCK] ?? ''));
        if ($manage_stock === 'TRUE' || $manage_stock === 'FALSE') {
            $variation->set_manage_stock($manage_stock === 'TRUE');
        }

        // Stock quantity + status — only when this row's stock reconciliation
        // resolved in the sheet's favor (or on the legacy/creation path).
        if ($apply_stock_levels) {
            // Stock quantity.
            if ($variation->get_manage_stock()) {
                $stock_qty = trim($row[self::COL_STOCK_QTY] ?? '');
                if ($stock_qty !== '') {
                    $variation->set_stock_quantity((int) $stock_qty);
                }
            }

            // Stock status.
            $stock_status = strtolower(trim($row[self::COL_STOCK_STATUS] ?? ''));
            if (in_array($stock_status, self::VALID_STOCK_STATUSES, true)) {
                $variation->set_stock_status($stock_status);
            }
        }

        // Attributes from Sheet (global pa_*), primarily for existing variations.
        $attr_string = trim((string) ($row[self::COL_ATTRIBUTES] ?? ''));
        if (
            $attr_string !== ''
            && $variation->is_type('variation')
            && $this->attribute_upsert_service
            && $parent instanceof WC_Product
            && $parent->is_type('variable')
        ) {
            $meta_attrs = $this->attribute_upsert_service->build_variation_attributes_and_sync_parent($parent, $attr_string);
            foreach ($meta_attrs as $meta_key => $meta_value) {
                update_post_meta((int) $variation->get_id(), $meta_key, $meta_value);
            }
        }

        return true;
    }

    /**
     * Create a new WooCommerce product from a sheet row.
     *
     * Supports two cases:
     * - product_id=0: create a new simple product.
     * - product_id>0 (existing variable product): create a new variation under it.
     *
     * @param array $row        Sheet row data.
     * @param int   $product_id Parent product ID (0 for new simple product).
     * @param int   $row_number 1-based sheet row number (for logging).
     * @return array|WP_Error ['product_id' => int, 'variation_id' => int] on success.
     */
    private function create_product_from_row(array $row, int $product_id, int $row_number)
    {
        $payload = [
            'name'          => (string) ($row[self::COL_PRODUCT_NAME] ?? ''),
            'sku'           => (string) ($row[self::COL_SKU] ?? ''),
            'regular_price' => (string) ($row[self::COL_REGULAR_PRICE] ?? ''),
            'sale_price'    => (string) ($row[self::COL_SALE_PRICE] ?? ''),
            'stock_qty'     => (string) ($row[self::COL_STOCK_QTY] ?? ''),
            'stock_status'  => (string) ($row[self::COL_STOCK_STATUS] ?? ''),
            'manage_stock'  => (string) ($row[self::COL_MANAGE_STOCK] ?? ''),
            'attributes'    => (string) ($row[self::COL_ATTRIBUTES] ?? ''),
        ];

        if ($product_id === 0 && $this->product_upsert_service) {
            return $this->product_upsert_service->upsert_simple($payload);
        }

        if ($product_id > 0 && $this->variation_upsert_service) {
            return $this->variation_upsert_service->upsert_variation($product_id, $payload);
        }

        // Legacy fallback when services are not available.
        $name = trim($payload['name']);
        $sku  = trim($payload['sku']);
        if ($product_id === 0) {
            return $this->create_simple_product($row, $name, $sku, $row_number);
        }

        $parent = wc_get_product($product_id);
        if (!$parent || !$parent->is_type('variable')) {
            return new WP_Error('wss_create', sprintf(
                __('Cannot create variation: product #%d does not exist or is not a variable product.', 'ffl-funnels-addons'),
                $product_id
            ));
        }

        return $this->create_variation($row, $parent, $sku, $row_number);
    }

    /**
     * Create a new WC_Product_Simple from sheet data.
     *
     * @param array  $row        Sheet row.
     * @param string $name       Product name.
     * @param string $sku        SKU (may be empty).
     * @param int    $row_number Sheet row number for logging.
     * @return array|WP_Error
     */
    private function create_simple_product(array $row, string $name, string $sku, int $row_number)
    {
        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_status('publish');

        if ($sku !== '') {
            $product->set_sku($sku);
        }

        // Apply prices, stock, etc.
        $applied = $this->apply_sheet_data_to_variation($product, $row);
        if (is_wp_error($applied)) {
            return $applied;
        }

        $new_id = $product->save();

        if (!$new_id) {
            return new WP_Error('wss_create', __('Failed to save new simple product.', 'ffl-funnels-addons'));
        }

        update_post_meta($new_id, '_wss_sync_enabled', '1');

        $this->logger->log('sheet_to_woo', $new_id, $new_id, 'success', sprintf('Simple product "%s" created from sheet row %d.', $name, $row_number));

        return ['product_id' => $new_id, 'variation_id' => $new_id];
    }

    /**
     * Create a new WC_Product_Variation under an existing variable product.
     *
     * @param array      $row        Sheet row.
     * @param WC_Product $parent     Parent variable product.
     * @param string     $sku        SKU (may be empty).
     * @param int        $row_number Sheet row number for logging.
     * @return array|WP_Error
     */
    private function create_variation(array $row, $parent, string $sku, int $row_number)
    {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent->get_id());

        if ($sku !== '') {
            $variation->set_sku($sku);
        }

        // Parse attributes from column D (e.g. "Color: Red | Size: L").
        // Registers new terms/options on the parent product first.
        $attr_string  = trim($row[self::COL_ATTRIBUTES] ?? '');
        $parsed_attrs = [];
        if ($attr_string !== '') {
            $parsed_attrs = $this->parse_and_register_attributes($attr_string, $parent);
        }

        // Apply prices, stock, etc.
        $applied = $this->apply_sheet_data_to_variation($variation, $row);
        if (is_wp_error($applied)) {
            return $applied;
        }

        // Save FIRST to create the variation post in the database.
        $new_vid = $variation->save();

        if (!$new_vid) {
            return new WP_Error('wss_create', __('Failed to save new variation.', 'ffl-funnels-addons'));
        }

        // Write attributes directly to post_meta AFTER the post exists.
        // WC_Product_Variation::set_attributes() silently fails before save().
        if (!empty($parsed_attrs)) {
            foreach ($parsed_attrs as $meta_key => $meta_value) {
                update_post_meta($new_vid, $meta_key, $meta_value);
            }
        }

        update_post_meta($new_vid, '_wss_sync_enabled', '1');

        $this->logger->log('sheet_to_woo', $parent->get_id(), $new_vid, 'success', sprintf('Variation created under product #%d from sheet row %d.', $parent->get_id(), $row_number));

        return ['product_id' => $parent->get_id(), 'variation_id' => $new_vid];
    }

    /**
     * Parse an attributes string and register term values on the parent product.
     *
     * Input:  "Color: Red | Size: L"
     * Output: ['attribute_pa_color' => 'red', 'attribute_pa_size' => 'l']
     *
     * For global (taxonomy) attributes:
     * - Creates the term if it doesn't exist.
     * - Assigns the term to the parent product via wp_set_object_terms().
     * - Adds the term to the parent's WC_Product_Attribute options if missing.
     *
     * For custom (local) attributes:
     * - Adds the value to the parent's attribute options if missing.
     *
     * @param string     $attr_string The attributes string from column D.
     * @param WC_Product $parent      The parent variable product.
     * @return array Keyed array suitable for WC_Product_Variation::set_attributes().
     */
    private function parse_and_register_attributes(string $attr_string, $parent): array
    {
        $pairs = array_map('trim', explode('|', $attr_string));
        if (empty($pairs)) {
            return [];
        }

        // Build a lookup: lowercase label → attribute key + taxonomy info + attribute object.
        $parent_attrs = $parent->get_attributes();
        $label_map    = [];

        foreach ($parent_attrs as $attr_key => $attr_obj) {
            /** @var WC_Product_Attribute $attr_obj */
            if ($attr_obj->is_taxonomy()) {
                $taxonomy = $attr_obj->get_name();
                $label    = wc_attribute_label($taxonomy);
                $label_map[strtolower($label)] = [
                    'key'         => $taxonomy,
                    'is_taxonomy' => true,
                    'attr_obj'    => $attr_obj,
                ];
            } else {
                $label = $attr_obj->get_name();
                $label_map[strtolower($label)] = [
                    'key'         => sanitize_title($label),
                    'is_taxonomy' => false,
                    'attr_obj'    => $attr_obj,
                ];
            }
        }

        $result         = [];
        $parent_changed = false;

        foreach ($pairs as $pair) {
            if (strpos($pair, ':') === false) {
                continue;
            }

            [$label, $value] = array_map('trim', explode(':', $pair, 2));
            if ($value === '') {
                continue;
            }
            $label_lower = strtolower($label);

            if (!isset($label_map[$label_lower])) {
                continue;
            }

            $attr_info = $label_map[$label_lower];
            $attr_obj  = $attr_info['attr_obj'];

            if ($attr_info['is_taxonomy']) {
                $taxonomy = $attr_info['key'];

                // Find or create the term.
                $term = get_term_by('name', $value, $taxonomy);
                if (!$term || is_wp_error($term)) {
                    $new_term = wp_insert_term($value, $taxonomy);
                    if (is_wp_error($new_term)) {
                        continue;
                    }
                    $term = get_term($new_term['term_id'], $taxonomy);
                }

                $term_slug = $term->slug;
                $term_id   = (int) $term->term_id;

                // Assign term to the parent product so WooCommerce recognizes it.
                wp_set_object_terms($parent->get_id(), $term_slug, $taxonomy, true);

                // Add term to the attribute's option list on the parent if missing.
                $current_options = $attr_obj->get_options();
                if (!in_array($term_id, $current_options, true)) {
                    $current_options[] = $term_id;
                    $attr_obj->set_options($current_options);
                    $parent_attrs[$taxonomy] = $attr_obj;
                    $parent_changed = true;
                }

                $result['attribute_' . $taxonomy] = $term_slug;
            } else {
                $slug            = sanitize_title($value);
                $current_options = $attr_obj->get_options();

                if (!in_array($value, $current_options, true)) {
                    $current_options[] = $value;
                    $attr_obj->set_options($current_options);
                    $parent_attrs[$attr_info['key']] = $attr_obj;
                    $parent_changed = true;
                }

                $result['attribute_' . $attr_info['key']] = $slug;
            }
        }

        // Save the parent product if we added new attribute options.
        if ($parent_changed) {
            $parent->set_attributes($parent_attrs);
            $parent->save();
        }

        return $result;
    }
}
