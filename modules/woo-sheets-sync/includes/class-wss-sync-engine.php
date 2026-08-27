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

    /** @var string Fatal Phase 1 Sheet write error; prevents speculative Phase 2 work. */
    private $phase_one_sheet_write_error = '';

    /** @var array<int,int> Stock snapshots safe after the Phase 1 Google write. */
    private $phase_one_stock_snapshots = [];

    /** @var array<int,int> Stock snapshots that require a successful Phase 2 row update. */
    private $phase_two_stock_snapshots = [];

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

        // Remove mappings that used to belong to this tab but are no longer
        // present. This is especially important after repairing a stale Sheet
        // variation ID: the real-time push must not keep targeting the old ID.
        $current_ids = [];
        foreach (array_keys($row_map) as $vid) {
            $vid = (int) $vid;
            if ($vid > 0) {
                $current_ids[$vid] = true;
            }
        }
        foreach ($persisted as $vid => $entry) {
            $same_tab = is_array($entry)
                && isset($entry['tab'])
                && (string) $entry['tab'] === $this->tab_name;
            if ($same_tab && !isset($current_ids[(int) $vid])) {
                unset($persisted[$vid]);
            }
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
     * Build a real-time-safe map using only IDs that the current Sheet already
     * confirms and that still resolve to the product in column A.
     *
     * Duplicate IDs are intentionally omitted: a real-time stock push cannot
     * safely choose between two Sheet rows for the same WooCommerce object.
     *
     * @param array<int,array<int,mixed>> $sheet_data Rows without the header.
     * @return array<int,int> variation/product ID => 0-based data index.
     */
    private function build_confirmed_row_map(array $sheet_data): array
    {
        $row_map   = [];
        $ambiguous = [];

        foreach ($sheet_data as $index => $row) {
            $variation_id = (int) ($row[self::COL_VARIATION_ID] ?? 0);
            $product_id   = (int) ($row[self::COL_PRODUCT_ID] ?? 0);
            if ($variation_id <= 0 || isset($ambiguous[$variation_id])) {
                continue;
            }

            $product = wc_get_product($variation_id);
            if (!$this->sheet_reference_matches_product($product, $product_id)) {
                continue;
            }

            if (isset($row_map[$variation_id])) {
                unset($row_map[$variation_id]);
                $ambiguous[$variation_id] = true;
                continue;
            }

            $row_map[$variation_id] = (int) $index;
        }

        return $row_map;
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
        $this->phase_one_sheet_write_error = '';
        $this->phase_one_stock_snapshots   = [];
        $this->phase_two_stock_snapshots   = [];

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

        // Phase 1: Sheet → Woo (sheet edits take priority).
        $stats_sheet = $this->sync_sheet_to_woo($sheet_data, $row_map);

        // Never run Woo→Sheet or persist speculative IDs when the Google batch
        // write failed. The next run can safely recover products created for a
        // still-empty row through the temporary source-row marker.
        if ($this->phase_one_sheet_write_error !== '') {
            if ($this->persist_last_sync) {
                update_option('wss_last_sync', [
                    'time'           => current_time('mysql'),
                    'error'          => $this->phase_one_sheet_write_error,
                    'sheet_to_woo'   => $stats_sheet,
                ], false);
            }
            return [
                'error'         => $this->phase_one_sheet_write_error,
                'sheet_to_woo'  => $stats_sheet,
            ];
        }

        // Phase 2: Woo → Sheet (write current WooCommerce state back).
        $stats_woo = $this->sync_woo_to_sheet($sheet_data, $row_map);

        // Both phases can change the map: Phase 1 repairs/creates IDs and Phase
        // 2 appends rows. Persist only the final, confirmed Sheet locations.
        $this->persist_row_map($row_map);

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
     * @param array $sheet_data Existing sheet rows (without header), updated in
     *                          memory when an ID is created or repaired.
     * @param array $row_map   variation_id → row index in $sheet_data, updated
     *                          in place so Phase 2 does not append a duplicate row.
     * @return array Stats.
     */
    private function sync_sheet_to_woo(array &$sheet_data, array &$row_map): array
    {
        $stats = ['updated' => 0, 'created' => 0, 'skipped' => 0, 'errors' => 0];

        // These copies are cheap until a write occurs (PHP copy-on-write). They
        // let us discard speculative in-memory IDs if Google rejects the batch.
        $original_sheet_data = $sheet_data;
        $original_row_map    = $row_map;

        $timestamp_updates = [];
        $id_updates        = [];
        $source_marker_ids = [];
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
                if ($product_id > 0 && !$this->is_parent_allowed($product_id)) {
                    $stats['skipped']++;
                    continue;
                }

                $product_name = trim($row[self::COL_PRODUCT_NAME] ?? '');
                if ($product_name === '') {
                    continue; // No name → skip.
                }

                // Resolve any existing SKU/attribute target before an upsert can
                // mutate it. This prevents a duplicate Sheet row from changing
                // the product already owned by another row.
                $preflight_target = $this->find_existing_target_for_new_row($row, $product_id, $row_number);
                if (is_wp_error($preflight_target)) {
                    $stats['errors']++;
                    $this->logger->log('sheet_to_woo', $product_id, 0, 'error', $preflight_target->get_error_message());
                    continue;
                }
                if (
                    $preflight_target > 0
                    && isset($row_map[$preflight_target])
                    && $row_map[$preflight_target] !== $index
                ) {
                    $stats['errors']++;
                    $this->logger->log(
                        'sheet_to_woo',
                        $product_id,
                        $preflight_target,
                        'error',
                        sprintf(
                            'Cannot link row %d to #%d because that object is already mapped to row %d.',
                            $row_number,
                            $preflight_target,
                            ((int) $row_map[$preflight_target]) + 2
                        )
                    );
                    continue;
                }

                $result = $this->recover_product_for_unconfirmed_sheet_row($row, $product_id, $row_number);
                if ($result === null) {
                    $result = $this->create_product_from_row($row, $product_id, $row_number);
                    if (!is_wp_error($result) && (($result['action'] ?? 'created') === 'created')) {
                        $new_source_id = (int) ($result['variation_id'] ?? 0);
                        if ($new_source_id > 0) {
                            $this->mark_product_for_unconfirmed_sheet_row($new_source_id, $row_number, $row);
                        }
                    }
                }
                if (is_wp_error($result)) {
                    $stats['errors']++;
                    $this->logger->log('sheet_to_woo', $product_id, 0, 'error', $result->get_error_message());
                } else {
                    $this->add_parent_to_scope((int) ($result['product_id'] ?? 0));

                    // Seed snapshots for the freshly created variation so the
                    // next run's change detection has an agreed baseline.
                    $new_vid       = (int) ($result['variation_id'] ?? 0);
                    if ($new_vid > 0 && isset($row_map[$new_vid]) && $row_map[$new_vid] !== $index) {
                        $stats['errors']++;
                        $this->logger->log(
                            'sheet_to_woo',
                            $product_id,
                            $new_vid,
                            'error',
                            sprintf('Cannot link row %d to #%d because that object is already mapped to row %d.', $row_number, $new_vid, ((int) $row_map[$new_vid]) + 2)
                        );
                        continue;
                    }
                    if (($result['action'] ?? 'created') === 'created') {
                        $stats['created']++;
                    } else {
                        $stats['updated']++;
                    }
                    $sheet_qty_raw = trim((string) ($row[self::COL_STOCK_QTY] ?? ''));
                    if ($new_vid > 0 && $sheet_qty_raw !== '') {
                        $this->queue_stock_snapshot($new_vid, (int) $sheet_qty_raw, false);
                    }

                    if ($new_vid > 0) {
                        $source_marker_ids[$new_vid] = $row_number;
                        $row_map[$new_vid] = $index;
                        $sheet_data[$index][self::COL_PRODUCT_ID] = (string) ($result['product_id'] ?? 0);
                        $sheet_data[$index][self::COL_VARIATION_ID] = (string) $new_vid;
                        $sheet_data[$index][self::COL_WOO_UPDATED_AT] = $now;
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

            if (!$this->is_parent_allowed($product_id)) {
                $stats['skipped']++;
                continue;
            }

            $variation = wc_get_product($variation_id);
            if (
                $this->sheet_reference_matches_product($variation, $product_id)
                && get_post_meta($variation_id, '_wss_unconfirmed_sheet_context', true) === $this->unconfirmed_sheet_context_key()
                && get_post_meta($variation_id, '_wss_unconfirmed_sheet_fingerprint', true) === $this->sheet_row_fingerprint($row)
            ) {
                // Covers an uncertain network response where Google committed
                // the ID but the client received an error and kept the marker.
                delete_post_meta($variation_id, '_wss_unconfirmed_sheet_row');
                delete_post_meta($variation_id, '_wss_unconfirmed_sheet_fingerprint');
                delete_post_meta($variation_id, '_wss_unconfirmed_sheet_context');
            }
            if (!$this->sheet_reference_matches_product($variation, $product_id)) {
                $stale_id = $variation_id;
                $repair   = $this->repair_invalid_sheet_reference($row, $product_id, $stale_id, $row_number);

                if (is_wp_error($repair)) {
                    if (($row_map[$stale_id] ?? null) === $index) {
                        unset($row_map[$stale_id]);
                        $this->restore_other_valid_row_map_entry($sheet_data, $row_map, $stale_id, $index);
                    }
                    $stats['errors']++;
                    $this->logger->log(
                        'sheet_to_woo',
                        $product_id,
                        $stale_id,
                        'error',
                        sprintf(
                            'Invalid Sheet variation reference on tab "%s", row %d: %s',
                            $this->tab_name,
                            $row_number,
                            $repair->get_error_message()
                        )
                    );
                    continue;
                }

                $variation_id = (int) ($repair['variation_id'] ?? 0);
                $variation    = $variation_id > 0 ? wc_get_product($variation_id) : false;
                if (!$this->sheet_reference_matches_product($variation, $product_id)) {
                    if (($row_map[$stale_id] ?? null) === $index) {
                        unset($row_map[$stale_id]);
                        $this->restore_other_valid_row_map_entry($sheet_data, $row_map, $stale_id, $index);
                    }
                    $stats['errors']++;
                    $this->logger->log(
                        'sheet_to_woo',
                        $product_id,
                        $stale_id,
                        'error',
                        sprintf(
                            'Repair returned invalid variation #%d for tab "%s", row %d.',
                            $variation_id,
                            $this->tab_name,
                            $row_number
                        )
                    );
                    continue;
                }

                $mapped_index = $row_map[$variation_id] ?? null;
                if ($mapped_index !== null && $mapped_index !== $index) {
                    $mapped_parent_id = (int) ($sheet_data[$mapped_index][self::COL_PRODUCT_ID] ?? 0);
                    if ($this->sheet_reference_matches_product($variation, $mapped_parent_id)) {
                        if (($row_map[$stale_id] ?? null) === $index) {
                            unset($row_map[$stale_id]);
                            $this->restore_other_valid_row_map_entry($sheet_data, $row_map, $stale_id, $index);
                        }
                        $stats['errors']++;
                        $this->logger->log(
                            'sheet_to_woo',
                            $product_id,
                            $stale_id,
                            'error',
                            sprintf(
                                'Cannot repair tab "%s", row %d to variation #%d because that variation is already mapped to row %d.',
                                $this->tab_name,
                                $row_number,
                                $variation_id,
                                ((int) $mapped_index) + 2
                            )
                        );
                        continue;
                    }
                }

                unset($row_map[$stale_id]);
                $this->restore_other_valid_row_map_entry($sheet_data, $row_map, $stale_id, $index);
                $row_map[$variation_id] = $index;
                $sheet_data[$index][self::COL_VARIATION_ID] = (string) $variation_id;
                $sheet_data[$index][self::COL_WOO_UPDATED_AT] = $now;

                $id_updates[] = [
                    'range'  => $this->a1_range(sprintf('B%d', $row_number)),
                    'values' => [[(string) $variation_id]],
                ];
                $timestamp_updates[] = [
                    'range'  => $this->a1_range(sprintf('K%d', $row_number)),
                    'values' => [[$now]],
                ];

                $action = (string) ($repair['action'] ?? 'linked');
                $this->logger->log(
                    'sheet_to_woo',
                    $product_id,
                    $variation_id,
                    'success',
                    sprintf(
                        'Repaired invalid Sheet reference #%d on tab "%s", row %d; %s variation #%d.',
                        $stale_id,
                        $this->tab_name,
                        $row_number,
                        $action === 'created' ? 'created' : 'linked to',
                        $variation_id
                    )
                );

                if ($action === 'created') {
                    $stats['created']++;
                    $this->add_parent_to_scope($product_id);
                    $sheet_qty_raw = trim((string) ($row[self::COL_STOCK_QTY] ?? ''));
                    if ($sheet_qty_raw !== '') {
                        $this->queue_stock_snapshot($variation_id, (int) $sheet_qty_raw, false);
                    }
                    update_post_meta($variation_id, '_wss_last_synced', $now);
                    continue;
                }
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
                $sheet_qty_raw = trim((string) ($row[self::COL_STOCK_QTY] ?? ''));
                $sheet_qty     = $sheet_qty_raw === '' ? null : (int) $sheet_qty_raw;
                $needs_phase_two = $sheet_qty !== (int) $stock['final_qty'];
                $this->queue_stock_snapshot($variation_id, (int) $stock['final_qty'], $needs_phase_two);
            }
        }

        $sheet_updates = array_merge($id_updates, $timestamp_updates);
        if (!empty($sheet_updates)) {
            $result = $this->batch_update_with_retry($this->sheet_id, $sheet_updates);
            if (is_wp_error($result)) {
                $this->logger->log('sheet_to_woo', 0, 0, 'error', 'Sheet batch update failed: ' . $result->get_error_message());
                $stats['errors']++;
                $sheet_data = $original_sheet_data;
                $row_map    = $original_row_map;

                // Although speculative repaired IDs must not be persisted, IDs
                // already proven invalid must also not remain active for the
                // real-time stock writer. Rebuild from the unchanged Sheet and
                // persist only references that are currently valid and unique.
                $this->persist_row_map($this->build_confirmed_row_map($original_sheet_data));
                $this->phase_one_sheet_write_error = sprintf(
                    __('Sheet→Woo completed, but Google Sheets rejected the ID/timestamp write: %s', 'ffl-funnels-addons'),
                    $result->get_error_message()
                );
                $this->phase_one_stock_snapshots = [];
                $this->phase_two_stock_snapshots = [];
            } else {
                foreach ($source_marker_ids as $source_id => $source_row_number) {
                    $expected_marker = $this->unconfirmed_sheet_row_key((int) $source_row_number);
                    if (get_post_meta((int) $source_id, '_wss_unconfirmed_sheet_row', true) === $expected_marker) {
                        delete_post_meta((int) $source_id, '_wss_unconfirmed_sheet_row');
                        delete_post_meta((int) $source_id, '_wss_unconfirmed_sheet_fingerprint');
                        delete_post_meta((int) $source_id, '_wss_unconfirmed_sheet_context');
                    }
                }
                $this->commit_phase_one_stock_snapshots();
            }
        } else {
            $this->commit_phase_one_stock_snapshots();
        }

        return $stats;
    }

    private function queue_stock_snapshot(int $variation_id, int $quantity, bool $requires_phase_two): void
    {
        if ($variation_id <= 0) {
            return;
        }

        if ($requires_phase_two) {
            unset($this->phase_one_stock_snapshots[$variation_id]);
            $this->phase_two_stock_snapshots[$variation_id] = $quantity;
            return;
        }

        if (!isset($this->phase_two_stock_snapshots[$variation_id])) {
            $this->phase_one_stock_snapshots[$variation_id] = $quantity;
        }
    }

    private function commit_phase_one_stock_snapshots(): void
    {
        foreach ($this->phase_one_stock_snapshots as $variation_id => $quantity) {
            update_post_meta((int) $variation_id, self::META_SNAP_WOO, (int) $quantity);
            update_post_meta((int) $variation_id, self::META_SNAP_SHEET, (int) $quantity);
        }
        $this->phase_one_stock_snapshots = [];
    }

    /** @param int[] $confirmed_variation_ids */
    private function commit_phase_two_stock_snapshots(array $confirmed_variation_ids): void
    {
        foreach (array_unique(array_map('intval', $confirmed_variation_ids)) as $variation_id) {
            if (!isset($this->phase_two_stock_snapshots[$variation_id])) {
                continue;
            }
            $quantity = (int) $this->phase_two_stock_snapshots[$variation_id];
            update_post_meta($variation_id, self::META_SNAP_WOO, $quantity);
            update_post_meta($variation_id, self::META_SNAP_SHEET, $quantity);
            unset($this->phase_two_stock_snapshots[$variation_id]);
        }
    }

    /**
     * Stable source marker for a Sheet row whose new Woo ID has not yet been
     * confirmed back to Google. This makes a failed API retry idempotent even
     * for simple products without a SKU.
     */
    private function unconfirmed_sheet_row_key(int $row_number): string
    {
        return hash('sha256', $this->sheet_id . '|' . $this->tab_name . '|' . $row_number);
    }

    private function unconfirmed_sheet_context_key(): string
    {
        return hash('sha256', $this->sheet_id . '|' . $this->tab_name);
    }

    private function sheet_row_fingerprint(array $row): string
    {
        $stable_fields = [
            (string) ($row[self::COL_PRODUCT_ID] ?? ''),
            (string) ($row[self::COL_PRODUCT_NAME] ?? ''),
            (string) ($row[self::COL_ATTRIBUTES] ?? ''),
            (string) ($row[self::COL_SKU] ?? ''),
            (string) ($row[self::COL_REGULAR_PRICE] ?? ''),
            (string) ($row[self::COL_SALE_PRICE] ?? ''),
            (string) ($row[self::COL_STOCK_QTY] ?? ''),
            (string) ($row[self::COL_STOCK_STATUS] ?? ''),
            (string) ($row[self::COL_MANAGE_STOCK] ?? ''),
        ];
        return hash('sha256', wp_json_encode($stable_fields));
    }

    private function mark_product_for_unconfirmed_sheet_row(int $product_id, int $row_number, array $row): void
    {
        update_post_meta($product_id, '_wss_unconfirmed_sheet_row', $this->unconfirmed_sheet_row_key($row_number));
        update_post_meta($product_id, '_wss_unconfirmed_sheet_fingerprint', $this->sheet_row_fingerprint($row));
        update_post_meta($product_id, '_wss_unconfirmed_sheet_context', $this->unconfirmed_sheet_context_key());
    }

    /**
     * Recover an object created by an earlier run whose Google batch write
     * failed after WooCommerce had already saved the object.
     *
     * @return array{product_id:int,variation_id:int,action:string}|WP_Error|null
     */
    private function recover_product_for_unconfirmed_sheet_row(array $row, int $sheet_product_id, int $row_number)
    {
        $ids = get_posts([
            'post_type'      => ['product', 'product_variation'],
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => 2,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => '_wss_unconfirmed_sheet_context',
                    'value' => $this->unconfirmed_sheet_context_key(),
                ],
                [
                    'key'   => '_wss_unconfirmed_sheet_fingerprint',
                    'value' => $this->sheet_row_fingerprint($row),
                ],
            ],
        ]);

        if (count($ids) > 1) {
            return new WP_Error(
                'wss_unconfirmed_row',
                sprintf(__('Multiple WooCommerce objects claim unconfirmed Sheet row %d; manual review is required.', 'ffl-funnels-addons'), $row_number)
            );
        }
        if ($ids === []) {
            return null;
        }

        $object_id = (int) $ids[0];
        $product   = wc_get_product($object_id);
        $stored_fingerprint = (string) get_post_meta($object_id, '_wss_unconfirmed_sheet_fingerprint', true);
        $current_fingerprint = $this->sheet_row_fingerprint($row);
        if ($stored_fingerprint === '' || !hash_equals($stored_fingerprint, $current_fingerprint)) {
            // The row moved or was replaced after the failed write. Never bind
            // the old object to different data merely because the row number
            // is now the same.
            delete_post_meta($object_id, '_wss_unconfirmed_sheet_row');
            delete_post_meta($object_id, '_wss_unconfirmed_sheet_fingerprint');
            delete_post_meta($object_id, '_wss_unconfirmed_sheet_context');
            return null;
        }
        $valid     = $product && (
            ($sheet_product_id > 0 && $product->is_type('variation') && (int) $product->get_parent_id() === $sheet_product_id)
            || ($sheet_product_id === 0 && !$product->is_type('variation') && !$product->is_type('variable'))
        );

        if (!$valid) {
            delete_post_meta($object_id, '_wss_unconfirmed_sheet_row');
            delete_post_meta($object_id, '_wss_unconfirmed_sheet_fingerprint');
            delete_post_meta($object_id, '_wss_unconfirmed_sheet_context');
            return null;
        }

        return [
            'product_id'   => $product->is_type('variation') ? (int) $product->get_parent_id() : $object_id,
            'variation_id' => $object_id,
            'action'       => 'recovered',
        ];
    }

    /**
     * If a bad row reused an ID that is also valid elsewhere, preserve the
     * legitimate row's map entry instead of causing Phase 2 to append it.
     */
    private function restore_other_valid_row_map_entry(array $sheet_data, array &$row_map, int $variation_id, int $excluded_index): void
    {
        $product = wc_get_product($variation_id);
        if (!$product) {
            return;
        }

        foreach ($sheet_data as $index => $candidate_row) {
            if ($index === $excluded_index || (int) ($candidate_row[self::COL_VARIATION_ID] ?? 0) !== $variation_id) {
                continue;
            }

            $candidate_parent_id = (int) ($candidate_row[self::COL_PRODUCT_ID] ?? 0);
            if ($this->sheet_reference_matches_product($product, $candidate_parent_id)) {
                $row_map[$variation_id] = $index;
                return;
            }
        }
    }

    /**
     * Validate that a Sheet variation ID resolves to the product identified in
     * column A. This rejects attachments, deleted posts, a variable parent used
     * as its own variation, and variations belonging to another parent.
     *
     * @param WC_Product|false $product    Product resolved from column B.
     * @param int              $product_id Product/parent ID from column A.
     */
    private function sheet_reference_matches_product($product, int $product_id): bool
    {
        if (!$product || $product_id <= 0) {
            return false;
        }

        if ($product->is_type('variation')) {
            return (int) $product->get_parent_id() === $product_id;
        }

        return !$product->is_type('variable') && (int) $product->get_id() === $product_id;
    }

    /**
     * Repair a stale/non-product Sheet reference without guessing an ID.
     *
     * Resolution order:
     * 1. Relink a simple product to the valid product ID in column A.
     * 2. Relink a unique variation by SKU.
     * 3. Relink a unique variation by its complete normalized attribute set.
     * 4. Create the missing variation only when the row supplies a SKU or a
     *    complete attribute set and column A is a valid variable parent.
     *
     * @return array{product_id:int,variation_id:int,action:string}|WP_Error
     */
    private function repair_invalid_sheet_reference(array $row, int $product_id, int $stale_id, int $row_number)
    {
        $parent = $product_id > 0 ? wc_get_product($product_id) : false;
        if (!$parent) {
            return new WP_Error(
                'wss_invalid_reference',
                sprintf(
                    __('ID #%1$d does not resolve to a WooCommerce product and parent #%2$d is unavailable.', 'ffl-funnels-addons'),
                    $stale_id,
                    $product_id
                )
            );
        }

        if (!$parent->is_type('variable')) {
            return [
                'product_id'   => $product_id,
                'variation_id' => (int) $parent->get_id(),
                'action'       => 'linked',
            ];
        }

        $sku          = trim((string) ($row[self::COL_SKU] ?? ''));
        $sku_match_id = 0;
        if ($sku !== '') {
            $sku_id = (int) wc_get_product_id_by_sku($sku);
            if ($sku_id > 0) {
                $sku_product = wc_get_product($sku_id);
                if ($sku_product && $sku_product->is_type('variation') && (int) $sku_product->get_parent_id() === $product_id) {
                    $sku_match_id = $sku_id;
                } else {
                    return new WP_Error(
                        'wss_invalid_reference',
                        sprintf(
                            __('SKU "%1$s" belongs to product #%2$d, not parent #%3$d.', 'ffl-funnels-addons'),
                            $sku,
                            $sku_id,
                            $product_id
                        )
                    );
                }
            }
        }

        $attribute_signature = $this->sheet_attribute_signature((string) ($row[self::COL_ATTRIBUTES] ?? ''), $parent);
        $matches             = $this->find_variations_by_attribute_signature($parent, $attribute_signature);
        $attribute_match_id  = count($matches) === 1 ? (int) $matches[0] : 0;

        if ($sku_match_id > 0 && $attribute_match_id > 0 && $sku_match_id !== $attribute_match_id) {
            return new WP_Error(
                'wss_invalid_reference',
                sprintf(
                    __('Cannot repair row %1$d: SKU "%2$s" identifies variation #%3$d, while the supplied attributes identify variation #%4$d.', 'ffl-funnels-addons'),
                    $row_number,
                    $sku,
                    $sku_match_id,
                    $attribute_match_id
                )
            );
        }

        if ($sku_match_id > 0) {
            return [
                'product_id'   => $product_id,
                'variation_id' => $sku_match_id,
                'action'       => 'linked',
            ];
        }

        if (count($matches) === 1) {
            return [
                'product_id'   => $product_id,
                'variation_id' => $attribute_match_id,
                'action'       => 'linked',
            ];
        }

        if (count($matches) > 1) {
            return new WP_Error(
                'wss_invalid_reference',
                sprintf(
                    __('Cannot repair row %1$d automatically: %2$d variations under product #%3$d have the same attributes.', 'ffl-funnels-addons'),
                    $row_number,
                    count($matches),
                    $product_id
                )
            );
        }

        if ($sku === '' && $attribute_signature === []) {
            $post_type = get_post_type($stale_id);
            return new WP_Error(
                'wss_invalid_reference',
                sprintf(
                    __('ID #%1$d is %2$s, and the row has no SKU or attributes that can be used for a safe repair.', 'ffl-funnels-addons'),
                    $stale_id,
                    $post_type ? $post_type : __('missing', 'ffl-funnels-addons')
                )
            );
        }

        $expected_attribute_keys = $this->parent_variation_attribute_keys($parent);
        $provided_attribute_keys = array_keys($attribute_signature);
        sort($provided_attribute_keys);
        if ($expected_attribute_keys !== $provided_attribute_keys && ($expected_attribute_keys !== [] || $sku === '')) {
            return new WP_Error(
                'wss_invalid_reference',
                sprintf(
                    __('Cannot recreate row %1$d safely: its attribute set does not match product #%4$d (%2$d provided; %3$d required).', 'ffl-funnels-addons'),
                    $row_number,
                    count($attribute_signature),
                    count($expected_attribute_keys),
                    $product_id
                )
            );
        }

        $created = $this->create_product_from_row($row, $product_id, $row_number);
        if (is_wp_error($created)) {
            return $created;
        }

        $created['action'] = (($created['action'] ?? '') === 'existing') ? 'linked' : 'created';
        return $created;
    }

    /**
     * Resolve an existing target for a row whose column B is empty without
     * changing WooCommerce. The caller uses this to reject duplicate Sheet rows
     * before the product/variation upsert applies prices, stock, or attributes.
     *
     * @return int|WP_Error Existing target ID, zero when the upsert would create.
     */
    private function find_existing_target_for_new_row(array $row, int $product_id, int $row_number)
    {
        $sku = trim((string) ($row[self::COL_SKU] ?? ''));

        if ($product_id <= 0) {
            if ($sku === '') {
                return 0;
            }

            $existing_id = (int) wc_get_product_id_by_sku($sku);
            if ($existing_id <= 0) {
                return 0;
            }

            $existing = wc_get_product($existing_id);
            if (!$existing || !$existing->is_type('simple')) {
                return new WP_Error(
                    'wss_product',
                    sprintf(
                        __('SKU "%1$s" belongs to WooCommerce object #%2$d, which is not a simple product.', 'ffl-funnels-addons'),
                        $sku,
                        $existing_id
                    )
                );
            }

            return $existing_id;
        }

        $parent = wc_get_product($product_id);
        if (!$parent || !$parent->is_type('variable')) {
            return new WP_Error(
                'wss_variation',
                sprintf(__('Cannot create variation for row %1$d: product #%2$d is unavailable or is not variable.', 'ffl-funnels-addons'), $row_number, $product_id)
            );
        }

        if ($sku !== '') {
            $sku_id = (int) wc_get_product_id_by_sku($sku);
            if ($sku_id > 0) {
                $sku_product = wc_get_product($sku_id);
                if (!$sku_product || !$sku_product->is_type('variation') || (int) $sku_product->get_parent_id() !== $product_id) {
                    return new WP_Error(
                        'wss_variation',
                        sprintf(__('SKU "%1$s" already belongs to WooCommerce object #%2$d outside product #%3$d.', 'ffl-funnels-addons'), $sku, $sku_id, $product_id)
                    );
                }
                return $sku_id;
            }
        }

        $signature = $this->sheet_attribute_signature((string) ($row[self::COL_ATTRIBUTES] ?? ''), $parent);
        $matches   = $this->find_variations_by_attribute_signature($parent, $signature);
        if (count($matches) > 1) {
            return new WP_Error(
                'wss_variation',
                sprintf(__('Cannot process row %1$d: %2$d variations under product #%3$d share the supplied attributes.', 'ffl-funnels-addons'), $row_number, count($matches), $product_id)
            );
        }

        return isset($matches[0]) ? (int) $matches[0] : 0;
    }

    /**
     * Normalize the Sheet's "Label: Value | ..." attributes for exact matching.
     *
     * @return array<string,string>
     */
    private function sheet_attribute_signature(string $attribute_string, $parent = null): array
    {
        if (trim($attribute_string) === '') {
            return [];
        }

        if ($this->attribute_upsert_service) {
            $pairs = $this->attribute_upsert_service->parse_pairs($attribute_string);
        } else {
            $pairs = [];
            foreach (array_map('trim', explode('|', $attribute_string)) as $pair) {
                if ($pair === '' || strpos($pair, ':') === false) {
                    continue;
                }
                [$label, $value] = array_map('trim', explode(':', $pair, 2));
                if ($label !== '') {
                    $pairs[] = ['label' => $label, 'value' => $value];
                }
            }
        }
        // Map human-facing labels back to the parent's actual attribute key.
        // For example, "Max Finish Length" can legitimately represent
        // pa_finish_length. Matching only sanitize_title(label) would miss it.
        $parent_aliases     = [];
        $parent_definitions = [];
        if ($parent && $parent->is_type('variable')) {
            foreach ($parent->get_attributes() as $parent_key => $attribute) {
                if (!is_object($attribute) || !method_exists($attribute, 'get_variation') || !$attribute->get_variation()) {
                    continue;
                }

                $attribute_name = method_exists($attribute, 'get_name') ? (string) $attribute->get_name() : (string) $parent_key;
                $canonical_key  = $this->normalize_attribute_signature_part(
                    method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy() ? $attribute_name : (string) $parent_key,
                    true
                );
                $is_taxonomy = method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy();
                if ($canonical_key !== '') {
                    $parent_definitions[$canonical_key] = [
                        'taxonomy' => $is_taxonomy,
                        'name'     => $is_taxonomy ? $attribute_name : '',
                        'options'  => method_exists($attribute, 'get_options') ? $attribute->get_options() : [],
                    ];
                }
                $aliases = [(string) $parent_key, $attribute_name, preg_replace('/^pa_/', '', $attribute_name)];
                if (function_exists('wc_attribute_label')) {
                    $aliases[] = (string) wc_attribute_label($attribute_name, $parent);
                }
                foreach ($aliases as $alias) {
                    $normalized_alias = $this->normalize_attribute_signature_part((string) $alias, true);
                    if ($normalized_alias !== '' && $canonical_key !== '') {
                        $parent_aliases[$normalized_alias] = $canonical_key;
                    }
                }
            }
        }

        $signature = [];
        foreach ($pairs as $pair) {
            $raw_key = $this->normalize_attribute_signature_part((string) ($pair['label'] ?? ''), true);
            $key     = $parent_aliases[$raw_key] ?? $raw_key;
            $definition = $parent_definitions[$key] ?? ['taxonomy' => false, 'name' => '', 'options' => []];
            $raw_value  = (string) ($pair['value'] ?? '');
            if (empty($definition['taxonomy'])) {
                foreach (array_map('strval', (array) ($definition['options'] ?? [])) as $option) {
                    if (strcasecmp(trim($option), trim($raw_value)) === 0) {
                        $raw_value = $option;
                        break;
                    }
                }
            }
            $value = $this->normalize_attribute_signature_value(
                $raw_value,
                !empty($definition['taxonomy']),
                (string) ($definition['name'] ?? '')
            );
            if ($key !== '') {
                $signature[$key] = $value;
            }
        }

        ksort($signature);
        return $signature;
    }

    /**
     * Find child variations whose complete attribute set matches the Sheet row.
     *
     * @param WC_Product $parent Variable parent product.
     * @param array<string,string> $signature Normalized Sheet attributes.
     * @return int[]
     */
    private function find_variations_by_attribute_signature($parent, array $signature): array
    {
        if ($signature === [] || !$parent || !$parent->is_type('variable')) {
            return [];
        }

        $matches = [];
        $definitions = [];
        foreach ($parent->get_attributes() as $parent_key => $attribute) {
            if (!is_object($attribute) || !method_exists($attribute, 'get_variation') || !$attribute->get_variation()) {
                continue;
            }
            $is_taxonomy   = method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy();
            $attribute_name = method_exists($attribute, 'get_name') ? (string) $attribute->get_name() : (string) $parent_key;
            $canonical_key = $this->normalize_attribute_signature_part($is_taxonomy ? $attribute_name : (string) $parent_key, true);
            if ($canonical_key !== '') {
                $definitions[$canonical_key] = [
                    'taxonomy' => $is_taxonomy,
                    'name'     => $is_taxonomy ? $attribute_name : '',
                    'options'  => method_exists($attribute, 'get_options') ? $attribute->get_options() : [],
                ];
            }
        }
        foreach ($parent->get_children() as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation || !$variation->is_type('variation')) {
                continue;
            }

            $candidate = [];
            foreach ($variation->get_attributes() as $key => $value) {
                $normalized_key   = $this->normalize_attribute_signature_part((string) $key, true);
                $definition       = $definitions[$normalized_key] ?? ['taxonomy' => strpos((string) $key, 'pa_') === 0, 'name' => (string) $key, 'options' => []];
                $normalized_value = $this->normalize_attribute_signature_value(
                    (string) $value,
                    !empty($definition['taxonomy']),
                    (string) ($definition['name'] ?? '')
                );
                if ($normalized_key !== '') {
                    $candidate[$normalized_key] = $normalized_value;
                }
            }
            ksort($candidate);

            if ($candidate === $signature) {
                $matches[] = (int) $variation->get_id();
            }
        }

        return $matches;
    }

    /**
     * Return the normalized attribute keys WooCommerce requires on each child
     * variation. Exact keys are safer than a count because two unrelated
     * attributes could otherwise look complete.
     *
     * @return string[]
     */
    private function parent_variation_attribute_keys($parent): array
    {
        if (!$parent || !$parent->is_type('variable')) {
            return [];
        }

        $keys = [];
        foreach ($parent->get_attributes() as $parent_key => $attribute) {
            if (is_object($attribute) && method_exists($attribute, 'get_variation') && $attribute->get_variation()) {
                $is_taxonomy = method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy();
                $name = $is_taxonomy && method_exists($attribute, 'get_name')
                    ? (string) $attribute->get_name()
                    : (string) $parent_key;
                $key  = $this->normalize_attribute_signature_part($name, true);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        sort($keys);
        return array_values(array_unique($keys));
    }

    /**
     * Normalize attribute labels, taxonomy slugs, term slugs, and free-form
     * option values to one comparison-safe representation.
     */
    private function normalize_attribute_signature_part(string $value, bool $is_key = false): string
    {
        $value = trim($value);
        if ($is_key && strpos($value, 'pa_') === 0) {
            $value = substr($value, 3);
        }

        return sanitize_title($value);
    }

    /**
     * Taxonomy terms compare by slug; custom options compare by their exact
     * punctuation-preserving canonical text from the parent options.
     */
    private function normalize_attribute_signature_value(string $value, bool $is_taxonomy, string $taxonomy = ''): string
    {
        $value = trim(wp_strip_all_tags($value));
        if ($value === '') {
            return '';
        }

        if ($is_taxonomy) {
            if ($taxonomy !== '' && taxonomy_exists($taxonomy)) {
                $term = get_term_by('name', $value, $taxonomy);
                if (!$term || is_wp_error($term)) {
                    $term = get_term_by('slug', sanitize_title($value), $taxonomy);
                }
                if ($term && !is_wp_error($term)) {
                    return strtolower((string) $term->slug);
                }
            }
            return sanitize_title($value);
        }

        return $value;
    }

    /**
     * Check whether the sheet differs from Woo on the SHEET-AUTHORITATIVE
     * fields only: SKU, regular price, sale price, manage_stock. Stock quantity and
     * stock status are handled separately by resolve_stock_direction() so an
     * order that reduced Woo isn't reverted by a stale sheet value.
     *
     * @param array      $row       Sheet row.
     * @param WC_Product $variation WooCommerce variation.
     * @return bool True if a sheet-authoritative field differs.
     */
    private function sheet_nonstock_fields_differ(array $row, $variation): bool
    {
        // SKU. An empty Sheet cell remains "no change" for compatibility.
        $sheet_sku = trim((string) ($row[self::COL_SKU] ?? ''));
        if ($sheet_sku !== '' && $sheet_sku !== (string) $variation->get_sku()) {
            return true;
        }

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
        $batch_update_ids = [];
        $append_rows   = [];
        $append_ids    = [];
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
                    $batch_update_ids[] = (int) $vid;
                    $stats['updated']++;
                    $this->logger->log('woo_to_sheet', $product_id, $vid, 'success', 'Row updated.');
                } else {
                    // New row — append.
                    $append_rows[] = $row;
                    $append_ids[]  = ['product_id' => (int) $product_id, 'variation_id' => (int) $vid];
                }
            }
        }

        if (!empty($batch_updates)) {
            $result = $this->batch_update_with_retry($this->sheet_id, $batch_updates);
            if (is_wp_error($result)) {
                $this->logger->log('woo_to_sheet', 0, 0, 'error', 'Batch update failed: ' . $result->get_error_message());
                $stats['errors']++;
            } else {
                $this->commit_phase_two_stock_snapshots($batch_update_ids);
            }
        }

        // Append new rows.
        if (!empty($append_rows)) {
            $result = $this->sheets->append_rows($this->sheet_id, $this->a1_range('A:L'), $append_rows);
            if (is_wp_error($result)) {
                $this->logger->log('woo_to_sheet', 0, 0, 'error', 'Append failed: ' . $result->get_error_message());
                $stats['errors']++;
            } else {
                $updated_range = (string) ($result['updates']['updatedRange'] ?? '');
                $start_row     = 0;
                if (preg_match('/!A(\d+):/i', $updated_range, $matches)) {
                    $start_row = (int) $matches[1];
                }

                foreach ($append_ids as $offset => $entry) {
                    $vid = (int) $entry['variation_id'];
                    if ($start_row > 1 && $vid > 0) {
                        $row_map[$vid] = ($start_row - 2) + $offset;
                    }
                    $stats['appended']++;
                    $this->logger->log('woo_to_sheet', (int) $entry['product_id'], $vid, 'success', 'Row appended.');
                }

                if ($start_row <= 1) {
                    $this->logger->log('woo_to_sheet', 0, 0, 'error', 'Rows appended, but Google did not return their updated range; real-time row mapping will refresh on the next full sync.');
                    $stats['errors']++;
                }
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

            // Keep empty values as "Label: " so WooCommerce "Any value"
            // wildcard attributes can round-trip through the Sheet.
            $parts[] = $label . ': ' . $value;
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
        // SKU. Validate ownership before calling WooCommerce CRUD so a relink
        // by attributes cannot silently discard a newly supplied SKU.
        $sku = trim((string) ($row[self::COL_SKU] ?? ''));
        if ($sku !== '' && $sku !== (string) $variation->get_sku()) {
            $sku_owner_id = (int) wc_get_product_id_by_sku($sku);
            if ($sku_owner_id > 0 && $sku_owner_id !== (int) $variation->get_id()) {
                return new WP_Error(
                    'wss_validation',
                    sprintf(__('SKU "%1$s" already belongs to WooCommerce object #%2$d.', 'ffl-funnels-addons'), $sku, $sku_owner_id)
                );
            }
            try {
                $variation->set_sku($sku);
            } catch (Exception $exception) {
                return new WP_Error('wss_validation', $exception->getMessage());
            }
        }

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
            $variation_attrs = $variation->get_attributes();
            foreach ($meta_attrs as $meta_key => $meta_value) {
                if (strpos((string) $meta_key, 'attribute_') !== 0) {
                    continue;
                }
                $attribute_key = substr((string) $meta_key, strlen('attribute_'));
                if ($attribute_key !== '') {
                    $variation_attrs[$attribute_key] = (string) $meta_value;
                }
            }
            if ($variation_attrs !== []) {
                // Save through the CRUD object so its in-memory attributes and
                // the post meta cannot overwrite each other later in this run.
                $variation->set_attributes($variation_attrs);
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
