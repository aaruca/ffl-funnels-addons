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

    public function __construct(WSS_Google_Sheets $sheets, WSS_Logger $logger, array $settings)
    {
        $this->sheets   = $sheets;
        $this->logger   = $logger;
        $this->sheet_id = $settings['sheet_id'] ?? '';
        $this->tab_name = $settings['tab_name'] ?? 'Inventory';
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

        // Read the entire sheet once (reused by both phases).
        $range      = $this->tab_name . '!A:L';
        $sheet_data = $this->sheets->read_range($this->sheet_id, $range);

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

        // Phase 2: Woo → Sheet (write current WooCommerce state back).
        $stats_woo = $this->sync_woo_to_sheet($sheet_data, $row_map);

        // Store last sync info.
        update_option('wss_last_sync', [
            'time'         => current_time('mysql'),
            'woo_to_sheet' => $stats_woo,
            'sheet_to_woo' => $stats_sheet,
        ], false);

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
                    // $result = ['product_id' => int, 'variation_id' => int]
                    $id_updates[] = [
                        'range'  => sprintf('%s!A%d:B%d', $this->tab_name, $row_number, $row_number),
                        'values' => [[(string) $result['product_id'], (string) $result['variation_id']]],
                    ];
                    $timestamp_updates[] = [
                        'range'  => sprintf('%s!K%d', $this->tab_name, $row_number),
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

            // Compare sheet data vs WooCommerce data.
            if (!$this->sheet_row_differs_from_woo($row, $variation)) {
                $stats['skipped']++;
                continue;
            }

            // Sheet has different data — apply it to WooCommerce.
            $applied = $this->apply_sheet_data_to_variation($variation, $row);
            if (is_wp_error($applied)) {
                $stats['errors']++;
                $this->logger->log('sheet_to_woo', $product_id, $variation_id, 'error', $applied->get_error_message());
                continue;
            }

            $variation->save();
            $stats['updated']++;
            $this->logger->log('sheet_to_woo', $product_id, $variation_id, 'success', 'Variation updated from sheet.');

            // Update last synced meta.
            update_post_meta($variation_id, '_wss_last_synced', $now);

            // Prepare timestamp update for this row.
            $row_number = $index + 2; // +2: 0-based index + header row
            $timestamp_updates[] = [
                'range'  => sprintf('%s!K%d', $this->tab_name, $row_number),
                'values' => [[$now]],
            ];
        }

        // Batch-update IDs for newly created products + timestamps.
        $sheet_updates = array_merge($id_updates, $timestamp_updates);
        if (!empty($sheet_updates)) {
            $result = $this->sheets->batch_update($this->sheet_id, $sheet_updates);
            if (is_wp_error($result)) {
                $this->logger->log('sheet_to_woo', 0, 0, 'error', 'Sheet batch update failed: ' . $result->get_error_message());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /**
     * Check if a sheet row has different syncable data than the WooCommerce variation.
     *
     * Compares: regular_price, sale_price, stock_qty, stock_status, manage_stock.
     *
     * @param array      $row       Sheet row.
     * @param WC_Product $variation WooCommerce variation.
     * @return bool True if sheet data differs from WooCommerce.
     */
    private function sheet_row_differs_from_woo(array $row, $variation): bool
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

        // Stock status.
        $sheet_status = strtolower(trim($row[self::COL_STOCK_STATUS] ?? ''));
        $woo_status   = $variation->get_stock_status();
        if ($sheet_status !== '' && in_array($sheet_status, self::VALID_STOCK_STATUSES, true) && $sheet_status !== $woo_status) {
            return true;
        }

        // Manage stock.
        $sheet_manage = strtoupper(trim($row[self::COL_MANAGE_STOCK] ?? ''));
        $woo_manage   = $variation->get_manage_stock() ? 'TRUE' : 'FALSE';
        if (($sheet_manage === 'TRUE' || $sheet_manage === 'FALSE') && $sheet_manage !== $woo_manage) {
            return true;
        }

        // Stock quantity (only if manage_stock is on).
        if ($variation->get_manage_stock()) {
            $sheet_qty = trim($row[self::COL_STOCK_QTY] ?? '');
            $woo_qty   = (string) $variation->get_stock_quantity();
            if ($sheet_qty !== '' && (int) $sheet_qty !== (int) $woo_qty) {
                return true;
            }
        }

        return false;
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

        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'meta_key'       => '_wss_sync_enabled',
            'meta_value'     => '1',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);

        if (empty($product_ids)) {
            return $stats;
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
                        'range'  => sprintf('%s!A%d:L%d', $this->tab_name, $row_number, $row_number),
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

        // Send batch update for existing rows.
        if (!empty($batch_updates)) {
            $result = $this->sheets->batch_update($this->sheet_id, $batch_updates);
            if (is_wp_error($result)) {
                $this->logger->log('woo_to_sheet', 0, 0, 'error', 'Batch update failed: ' . $result->get_error_message());
                $stats['errors']++;
            }
        }

        // Append new rows.
        if (!empty($append_rows)) {
            $result = $this->sheets->append_rows($this->sheet_id, $this->tab_name . '!A:L', $append_rows);
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
     * @param WC_Product $variation The WC variation to update.
     * @param array      $row       Sheet row data.
     * @return true|WP_Error
     */
    private function apply_sheet_data_to_variation($variation, array $row)
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
        $name = trim($row[self::COL_PRODUCT_NAME] ?? '');
        $sku  = trim($row[self::COL_SKU] ?? '');

        // Duplicate SKU check.
        if ($sku !== '') {
            $existing_id = wc_get_product_id_by_sku($sku);
            if ($existing_id) {
                $existing = wc_get_product($existing_id);
                if ($existing) {
                    $pid = $existing->get_parent_id() ?: $existing_id;
                    $vid = $existing_id;
                    $this->logger->log('sheet_to_woo', $pid, $vid, 'skipped', sprintf('SKU "%s" already exists (product #%d). Updated sheet row with existing IDs.', $sku, $existing_id));
                    update_post_meta($existing_id, '_wss_sync_enabled', '1');
                    return ['product_id' => $pid, 'variation_id' => $vid];
                }
            }
        }

        if ($product_id === 0) {
            // Create new simple product.
            return $this->create_simple_product($row, $name, $sku, $row_number);
        }

        // Create variation under existing variable product.
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
