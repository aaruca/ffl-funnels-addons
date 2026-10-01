<?php
/**
 * WooBooster Co-Purchase Builder.
 *
 * Scans completed orders and builds a "frequently bought together" index
 * stored in product postmeta. Zero new database tables. See build() for how
 * pairs are ranked.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Copurchase
{

    /**
     * Whether WooCommerce stores orders in the HPOS table as the authoritative source.
     *
     * Do not infer this from whether `wp_wc_orders` exists — the table may exist while
     * orders still live in `wp_posts` (migration, compatibility, or sync).
     *
     * @return bool
     */
    public static function is_custom_orders_table_in_use(): bool
    {
        if (!class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return false;
        }

        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Build the co-purchase index.
     *
     * Scans orders from the last N days and writes, for each product, the top M
     * products bought together with it to its `_woobooster_copurchased` meta.
     *
     * Ranking (since 1.51.0) is by how much MORE often two products sell
     * together than chance, not by raw pair counts, so items that are in many
     * orders anyway (ammo, cleaning kits, fee products) no longer top every
     * list; pairs that sell together no more often than chance are dropped:
     *
     *   confidence(a→b) = weighted co-orders(a,b) / orders(a)
     *   lift(a→b)       = confidence(a→b) / (orders(b) / all orders)
     *   score(a→b)      = confidence(a→b) × log2(lift(a→b)), only when lift > 1
     *
     * Each order adds 1/(items−1) to its pairs, so a 30-item bulk order does not
     * outweigh dozens of normal ones; orders above the bulk cap are skipped. A
     * pair needs a minimum number of shared orders (2 on stores with 200+
     * multi-item orders, 1 below that). Virtual products (fees, gift cards,
     * protection plans) are left out. Products that no longer have any pairs
     * have their old list removed, so the index never goes stale.
     *
     * Reads order lines from wc_order_product_lookup in batches (keyset
     * pagination), falling back to order item meta for orders the lookup table
     * has not synced. Skips the rebuild when the set of orders in the window is
     * unchanged since the last build.
     *
     * @param bool $force Rebuild even when the order window is unchanged.
     * @return array Build stats.
     */
    public function build($force = false)
    {
        global $wpdb;

        $start = microtime(true);
        $options = get_option('woobooster_settings', array());
        $days = isset($options['smart_days']) ? absint($options['smart_days']) : 90;
        $max_relations = isset($options['smart_max_relations']) ? absint($options['smart_max_relations']) : 20;
        $batch_size = 500;

        if ($days < 1) {
            $days = 90;
        }
        if ($max_relations < 1) {
            $max_relations = 20;
        }

        /** Orders with more distinct products than this are treated as bulk/dealer orders and skipped. */
        $bulk_cap = max(2, (int) apply_filters('woobooster_copurchase_bulk_order_cap', 40));

        $date_cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $use_hpos = self::is_custom_orders_table_in_use();
        $statuses = self::get_order_statuses();
        $statuses_hpos = self::expand_statuses_for_hpos($statuses);

        // Skip the rebuild when nothing in the window changed (same first/last
        // order and count) and the index was built with the same settings.
        $signature = $this->window_signature($use_hpos, $use_hpos ? $statuses_hpos : $statuses, $date_cutoff, $days, $max_relations, $bulk_cap);
        $last_build = get_option('woobooster_last_build', array());
        if (
            !$force
            && !empty($last_build['copurchase']['signature'])
            && $last_build['copurchase']['signature'] === $signature
        ) {
            $stats = $last_build['copurchase'];
            $stats['skipped'] = true;
            $stats['time'] = round(microtime(true) - $start, 2);
            return $stats;
        }

        $excluded = self::excluded_product_ids();

        $orders_with = array();   // product_id => number of orders containing it.
        $pair_weight = array();   // a => [b => weighted co-orders].
        $pair_count = array();    // a => [b => raw shared orders].
        $orders_scanned = 0;
        $multi_item_orders = 0;
        $single_item_orders = 0;
        $bulk_orders = 0;
        $last_id = 0;

        do {
            $order_ids = $this->next_order_ids($use_hpos, $use_hpos ? $statuses_hpos : $statuses, $date_cutoff, $last_id, $batch_size);
            if (empty($order_ids)) {
                break;
            }
            $last_id = (int) end($order_ids);

            $lines = $this->fetch_order_products($order_ids);

            foreach ($order_ids as $order_id) {
                $orders_scanned++;
                $product_ids = isset($lines[$order_id]) ? $lines[$order_id] : array();
                if ($excluded) {
                    $product_ids = array_values(array_diff($product_ids, $excluded));
                }

                foreach ($product_ids as $pid) {
                    $orders_with[$pid] = isset($orders_with[$pid]) ? $orders_with[$pid] + 1 : 1;
                }

                $count = count($product_ids);
                if ($count < 2) {
                    $single_item_orders++;
                    continue;
                }
                if ($count > $bulk_cap) {
                    $bulk_orders++;
                    continue;
                }

                $multi_item_orders++;
                $weight = 1 / ($count - 1);

                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $a = $product_ids[$i];
                        $b = $product_ids[$j];
                        $pair_weight[$a][$b] = (isset($pair_weight[$a][$b]) ? $pair_weight[$a][$b] : 0) + $weight;
                        $pair_weight[$b][$a] = (isset($pair_weight[$b][$a]) ? $pair_weight[$b][$a] : 0) + $weight;
                        $pair_count[$a][$b] = (isset($pair_count[$a][$b]) ? $pair_count[$a][$b] : 0) + 1;
                        $pair_count[$b][$a] = (isset($pair_count[$b][$a]) ? $pair_count[$b][$a] : 0) + 1;
                    }
                }
            }
        } while (count($order_ids) === $batch_size);

        $min_support = (int) apply_filters(
            'woobooster_copurchase_min_support',
            $multi_item_orders >= 200 ? 2 : 1,
            $multi_item_orders
        );
        $min_support = max(1, $min_support);

        $index = self::rank_pairs($pair_weight, $pair_count, $orders_with, $orders_scanned, $min_support, $max_relations);

        // Write only lists that changed; remove lists for products that no
        // longer have any pairs (they used to stay forever).
        $products_indexed = 0;
        $products_updated = 0;
        foreach ($index as $product_id => $top) {
            $products_indexed++;
            $current = get_post_meta($product_id, '_woobooster_copurchased', true);
            if (!is_array($current) || array_map('absint', $current) !== $top) {
                update_post_meta($product_id, '_woobooster_copurchased', $top);
                $products_updated++;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
                '_woobooster_copurchased'
            )
        );
        $products_cleared = 0;
        foreach ($existing_ids as $existing_id) {
            $existing_id = absint($existing_id);
            if ($existing_id && !isset($index[$existing_id])) {
                delete_post_meta($existing_id, '_woobooster_copurchased');
                $products_cleared++;
            }
        }

        $elapsed = round(microtime(true) - $start, 2);

        $reason = '';
        if (0 === $products_indexed) {
            if (0 === $orders_scanned) {
                $reason = sprintf(
                    /* translators: 1: days window, 2: comma-separated statuses */
                    __('No orders in the last %1$d days with status %2$s. Increase "Days to Analyze" or adjust the status filter.', 'ffl-funnels-addons'),
                    $days,
                    implode(', ', $statuses)
                );
            } elseif (0 === $multi_item_orders) {
                $reason = sprintf(
                    /* translators: %d: orders scanned count */
                    __('Scanned %d orders but all contained a single line item. Co-purchase requires at least two products per order.', 'ffl-funnels-addons'),
                    $orders_scanned
                );
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG && 0 === $products_indexed) {
            error_log('WooBooster Copurchase: 0 products indexed. ' . $reason);
        }

        $stats = array(
            'type'               => 'copurchase',
            'products'           => $products_indexed,
            'products_updated'   => $products_updated,
            'products_cleared'   => $products_cleared,
            'orders_scanned'     => $orders_scanned,
            'multi_item_orders'  => $multi_item_orders,
            'single_item_orders' => $single_item_orders,
            'bulk_orders'        => $bulk_orders,
            'min_support'        => $min_support,
            'days'               => $days,
            'statuses'           => $statuses,
            'statuses_queried'   => $use_hpos ? $statuses_hpos : $statuses,
            'storage'            => $use_hpos ? 'hpos' : 'posts',
            'reason'             => $reason,
            'signature'          => $signature,
            'time'               => $elapsed,
            'date'               => current_time('mysql'),
        );

        $last_build = get_option('woobooster_last_build', array());
        $last_build['copurchase'] = $stats;
        update_option('woobooster_last_build', $last_build);

        return $stats;
    }

    /**
     * Rank related products for every product (pure function; unit-testable).
     *
     * @param array<int, array<int, float>> $pair_weight  a => [b => weighted co-orders].
     * @param array<int, array<int, int>>   $pair_count   a => [b => raw shared orders].
     * @param array<int, int>               $orders_with  product => orders containing it.
     * @param int                           $total_orders All orders scanned.
     * @param int                           $min_support  Minimum raw shared orders for a pair.
     * @param int                           $max_relations Products kept per list.
     * @return array<int, int[]> product => ranked related product IDs.
     */
    public static function rank_pairs(array $pair_weight, array $pair_count, array $orders_with, int $total_orders, int $min_support, int $max_relations): array
    {
        $index = array();
        if ($total_orders < 1) {
            return $index;
        }

        foreach ($pair_weight as $a => $related) {
            $orders_a = isset($orders_with[$a]) ? max(1, $orders_with[$a]) : 1;
            $scores = array();

            foreach ($related as $b => $weight) {
                if ((isset($pair_count[$a][$b]) ? $pair_count[$a][$b] : 0) < $min_support) {
                    continue;
                }
                $orders_b = isset($orders_with[$b]) ? max(1, $orders_with[$b]) : 1;
                $confidence = $weight / $orders_a;
                $lift = $confidence / ($orders_b / $total_orders);
                // Not bought together more often than chance: it is just
                // popular everywhere (or in every cart), so it is not a
                // "bought together" signal.
                if ($lift <= 1) {
                    continue;
                }
                $scores[$b] = $confidence * log($lift, 2);
            }

            if (empty($scores)) {
                continue;
            }

            // Highest score first; ties broken by product ID for stable output.
            uksort($scores, static function ($x, $y) use ($scores) {
                if ($scores[$x] === $scores[$y]) {
                    return $x <=> $y;
                }
                return $scores[$y] <=> $scores[$x];
            });

            $index[(int) $a] = array_map('intval', array_slice(array_keys($scores), 0, $max_relations));
        }

        return $index;
    }

    /**
     * Products never used as Smart signals (co-purchase and trending): virtual
     * products (transfer fees, gift cards, protection plans) unless the
     * `woobooster_smart_exclude_virtual` filter returns false, plus anything
     * the `woobooster_smart_excluded_products` filter adds.
     *
     * @return int[]
     */
    public static function excluded_product_ids(): array
    {
        global $wpdb;

        $ids = array();
        if (apply_filters('woobooster_smart_exclude_virtual', true)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s
                    WHERE p.post_type = %s",
                    '_virtual',
                    'yes',
                    'product'
                )
            );
        }

        $ids = apply_filters('woobooster_smart_excluded_products', array_map('absint', (array) $ids));

        return array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
    }

    /**
     * Next batch of order IDs in the window, after $after_id (keyset pagination:
     * every batch costs the same, unlike LIMIT/OFFSET).
     *
     * @param bool     $use_hpos    Whether orders live in wc_orders.
     * @param string[] $statuses    Statuses in the storage's format.
     * @param string   $date_cutoff GMT datetime lower bound.
     * @param int      $after_id    Last order ID of the previous batch.
     * @param int      $limit       Batch size.
     * @return int[]
     */
    private function next_order_ids(bool $use_hpos, array $statuses, string $date_cutoff, int $after_id, int $limit): array
    {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));

        if ($use_hpos) {
            $table = $wpdb->prefix . 'wc_orders';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT id FROM {$table}
                    WHERE type = 'shop_order' AND status IN ({$placeholders})
                    AND date_created_gmt >= %s AND id > %d
                    ORDER BY id ASC
                    LIMIT %d",
                    array_merge($statuses, array($date_cutoff, $after_id, $limit))
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                    WHERE post_type = 'shop_order' AND post_status IN ({$placeholders})
                    AND post_date_gmt >= %s AND ID > %d
                    ORDER BY ID ASC
                    LIMIT %d",
                    array_merge($statuses, array($date_cutoff, $after_id, $limit))
                )
            );
        }

        return array_map('absint', (array) $ids);
    }

    /**
     * A cheap fingerprint of the order window (first and last order ID and the
     * count) plus the settings that shape the index. Equal fingerprints mean a
     * rebuild would produce the same index.
     */
    private function window_signature(bool $use_hpos, array $statuses, string $date_cutoff, int $days, int $max_relations, int $bulk_cap): string
    {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));

        if ($use_hpos) {
            $table = $wpdb->prefix . 'wc_orders';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT MIN(id) AS first_id, MAX(id) AS last_id, COUNT(*) AS total FROM {$table}
                    WHERE type = 'shop_order' AND status IN ({$placeholders}) AND date_created_gmt >= %s",
                    array_merge($statuses, array($date_cutoff))
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT MIN(ID) AS first_id, MAX(ID) AS last_id, COUNT(*) AS total FROM {$wpdb->posts}
                    WHERE post_type = 'shop_order' AND post_status IN ({$placeholders}) AND post_date_gmt >= %s",
                    array_merge($statuses, array($date_cutoff))
                ),
                ARRAY_A
            );
        }

        return md5(wp_json_encode(array(
            'v'        => 2,
            'window'   => is_array($row) ? array_values($row) : array(),
            'statuses' => $statuses,
            'days'     => $days,
            'max'      => $max_relations,
            'bulk'     => $bulk_cap,
            'excluded' => self::excluded_product_ids(),
            'support'  => has_filter('woobooster_copurchase_min_support'),
        )));
    }

    /**
     * Distinct parent product IDs per order, for a batch of orders.
     *
     * One query against wc_order_product_lookup for the whole batch; orders the
     * lookup table has not synced (WooCommerce fills it asynchronously) are read
     * from order item meta in one more query.
     *
     * @param int[] $order_ids
     * @return array<int, int[]> order_id => product IDs.
     */
    private function fetch_order_products(array $order_ids): array
    {
        global $wpdb;

        $order_ids = array_values(array_filter(array_map('absint', $order_ids)));
        if (empty($order_ids)) {
            return array();
        }

        $lines = array();
        $placeholders = implode(', ', array_fill(0, count($order_ids), '%d'));

        $lookup = $wpdb->prefix . 'wc_order_product_lookup';
        if (self::lookup_table_exists()) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT order_id, product_id FROM {$lookup}
                    WHERE order_id IN ({$placeholders}) AND product_id > 0",
                    $order_ids
                ),
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                $lines[(int) $row['order_id']][] = (int) $row['product_id'];
            }
        }

        $missing = array_values(array_diff($order_ids, array_keys($lines)));
        if (!empty($missing)) {
            $items = $wpdb->prefix . 'woocommerce_order_items';
            $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
            $missing_placeholders = implode(', ', array_fill(0, count($missing), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT oi.order_id, oim.meta_value AS product_id
                    FROM {$items} oi
                    JOIN {$itemmeta} oim ON oi.order_item_id = oim.order_item_id
                    WHERE oi.order_id IN ({$missing_placeholders})
                    AND oi.order_item_type = 'line_item'
                    AND oim.meta_key = '_product_id'
                    AND oim.meta_value > 0",
                    $missing
                ),
                ARRAY_A
            );
            foreach ((array) $rows as $row) {
                $lines[(int) $row['order_id']][] = (int) $row['product_id'];
            }
        }

        foreach ($lines as $order_id => $ids) {
            $lines[$order_id] = array_values(array_unique(array_filter(array_map('absint', $ids))));
        }

        return $lines;
    }

    /**
     * Whether WooCommerce's analytics order/product lookup table exists.
     */
    public static function lookup_table_exists(): bool
    {
        static $exists = null;
        if (null === $exists) {
            global $wpdb;
            $table = $wpdb->prefix . 'wc_order_product_lookup';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        }

        return $exists;
    }

    /**
     * Order statuses considered when building the co-purchase index.
     *
     * Filterable via `woobooster_copurchase_order_statuses`.
     *
     * @return string[] List of statuses with the `wc-` prefix.
     */
    public static function get_order_statuses(): array
    {
        $default = array('wc-completed', 'wc-processing');
        $statuses = apply_filters('woobooster_copurchase_order_statuses', $default);

        $allowed = function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : $default;
        $allowed_map = array();
        foreach ($allowed as $slug) {
            $allowed_map[$slug] = true;
        }

        $clean = array();
        foreach ((array) $statuses as $status) {
            $status = sanitize_key((string) $status);
            if ('' === $status) {
                continue;
            }
            if (0 !== strpos($status, 'wc-')) {
                $status = 'wc-' . ltrim($status, '-');
            }
            if (isset($allowed_map[$status])) {
                $clean[] = $status;
            }
        }

        if (empty($clean)) {
            return $default;
        }

        return array_values(array_unique($clean));
    }

    /**
     * Expand statuses for queries against the HPOS wp_wc_orders.status column.
     *
     * HPOS stores the status without the `wc-` prefix (e.g. `completed`), while
     * the legacy wp_posts.post_status keeps it (`wc-completed`). Returning both
     * forms lets the IN-clause match either storage format and is idempotent
     * when callers pass already-unprefixed statuses.
     *
     * @param string[] $statuses wc-* prefixed statuses from get_order_statuses().
     * @return string[]
     */
    public static function expand_statuses_for_hpos(array $statuses): array
    {
        $expanded = array();
        foreach ($statuses as $status) {
            $status = (string) $status;
            if ('' === $status) {
                continue;
            }
            $expanded[] = $status;
            if (0 === strpos($status, 'wc-')) {
                $expanded[] = substr($status, 3);
            }
        }

        return array_values(array_unique($expanded));
    }

    /**
     * Return diagnostic counts so the admin UI can explain an empty index.
     *
     * @param int $days Lookback window in days.
     * @return array{orders_in_window:int, multi_item_orders:int, single_item_orders:int, days:int, statuses:string[], statuses_queried:string[], storage:string}
     */
    public static function get_diagnostics(int $days = 0): array
    {
        global $wpdb;

        $options = get_option('woobooster_settings', array());
        if ($days < 1) {
            $days = isset($options['smart_days']) ? absint($options['smart_days']) : 90;
        }
        if ($days < 1) {
            $days = 90;
        }

        $statuses = self::get_order_statuses();
        $date_cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $hpos_table = $wpdb->prefix . 'wc_orders';
        $use_hpos = self::is_custom_orders_table_in_use();

        // HPOS stores status without the `wc-` prefix; include both forms.
        $statuses_queried = $use_hpos ? self::expand_statuses_for_hpos($statuses) : $statuses;
        $status_placeholders = implode(', ', array_fill(0, count($statuses_queried), '%s'));

        if ($use_hpos) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $orders_in_window = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$hpos_table}
                    WHERE type = 'shop_order' AND status IN ({$status_placeholders})
                    AND date_created_gmt >= %s",
                    array_merge($statuses_queried, array($date_cutoff))
                )
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $multi_item_orders = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM (
                        SELECT oi.order_id
                        FROM {$wpdb->prefix}woocommerce_order_items oi
                        JOIN {$hpos_table} o ON oi.order_id = o.id
                        WHERE o.type = 'shop_order'
                        AND o.status IN ({$status_placeholders})
                        AND o.date_created_gmt >= %s
                        AND oi.order_item_type = 'line_item'
                        GROUP BY oi.order_id
                        HAVING COUNT(*) >= 2
                    ) multi",
                    array_merge($statuses_queried, array($date_cutoff))
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $orders_in_window = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts}
                    WHERE post_type = 'shop_order' AND post_status IN ({$status_placeholders})
                    AND post_date_gmt >= %s",
                    array_merge($statuses_queried, array($date_cutoff))
                )
            );

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $multi_item_orders = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM (
                        SELECT oi.order_id
                        FROM {$wpdb->prefix}woocommerce_order_items oi
                        JOIN {$wpdb->posts} p ON oi.order_id = p.ID
                        WHERE p.post_type = 'shop_order'
                        AND p.post_status IN ({$status_placeholders})
                        AND p.post_date_gmt >= %s
                        AND oi.order_item_type = 'line_item'
                        GROUP BY oi.order_id
                        HAVING COUNT(*) >= 2
                    ) multi",
                    array_merge($statuses_queried, array($date_cutoff))
                )
            );
        }

        return array(
            'orders_in_window'   => $orders_in_window,
            'multi_item_orders'  => $multi_item_orders,
            'single_item_orders' => max(0, $orders_in_window - $multi_item_orders),
            'days'               => $days,
            'statuses'           => $statuses,
            'statuses_queried'   => $statuses_queried,
            'storage'            => $use_hpos ? 'hpos' : 'posts',
        );
    }
}
