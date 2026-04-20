<?php
/**
 * WooBooster Co-Purchase Builder.
 *
 * Scans completed orders and builds a "frequently bought together" index
 * stored in product postmeta. Zero new database tables.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Copurchase
{

    /**
     * Build the co-purchase index.
     *
     * Scans orders from the last N days, counts product co-occurrences,
     * and writes the top M related products to each product's postmeta.
     *
     * @return array Build stats.
     */
    public function build()
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

        $date_cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Use HPOS table if available, otherwise fall back to posts.
        $hpos_table = $wpdb->prefix . 'wc_orders';
        $use_hpos = $wpdb->get_var("SHOW TABLES LIKE '{$hpos_table}'") === $hpos_table;

        $statuses = self::get_order_statuses();
        $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));

        $pairs = array();
        $offset = 0;
        $orders_scanned = 0;
        $multi_item_orders = 0;
        $single_item_orders = 0;

        do {
            if ($use_hpos) {
                // HPOS: get order IDs from wc_orders table.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- status_placeholders built from a trusted whitelist.
                $order_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT id FROM {$hpos_table}
                        WHERE type = 'shop_order' AND status IN ({$status_placeholders})
                        AND date_created_gmt >= %s
                        ORDER BY id ASC
                        LIMIT %d OFFSET %d",
                        array_merge($statuses, array($date_cutoff, $batch_size, $offset))
                    )
                );
            } else {
                // Legacy: get order IDs from wp_posts.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $order_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts}
                        WHERE post_type = 'shop_order' AND post_status IN ({$status_placeholders})
                        AND post_date_gmt >= %s
                        ORDER BY ID ASC
                        LIMIT %d OFFSET %d",
                        array_merge($statuses, array($date_cutoff, $batch_size, $offset))
                    )
                );
            }

            if (empty($order_ids)) {
                break;
            }

            foreach ($order_ids as $order_id) {
                $orders_scanned++;
                $product_ids = $this->get_order_product_ids($order_id);

                if (count($product_ids) < 2) {
                    $single_item_orders++;
                    continue;
                }

                $multi_item_orders++;

                // Generate all unique pairs.
                $count = count($product_ids);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $a = $product_ids[$i];
                        $b = $product_ids[$j];

                        if (!isset($pairs[$a])) {
                            $pairs[$a] = array();
                        }
                        if (!isset($pairs[$b])) {
                            $pairs[$b] = array();
                        }

                        $pairs[$a][$b] = isset($pairs[$a][$b]) ? $pairs[$a][$b] + 1 : 1;
                        $pairs[$b][$a] = isset($pairs[$b][$a]) ? $pairs[$b][$a] + 1 : 1;
                    }
                }
            }

            $offset += $batch_size;
        } while (count($order_ids) === $batch_size);

        // Write to postmeta: top N related products per product.
        $products_indexed = 0;
        foreach ($pairs as $product_id => $related) {
            arsort($related);
            $top = array_slice(array_keys($related), 0, $max_relations, true);

            if (!empty($top)) {
                update_post_meta($product_id, '_woobooster_copurchased', $top);
                $products_indexed++;
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
            'orders_scanned'     => $orders_scanned,
            'multi_item_orders'  => $multi_item_orders,
            'single_item_orders' => $single_item_orders,
            'days'               => $days,
            'statuses'           => $statuses,
            'reason'             => $reason,
            'time'               => $elapsed,
            'date'               => current_time('mysql'),
        );

        $last_build = get_option('woobooster_last_build', array());
        $last_build['copurchase'] = $stats;
        update_option('woobooster_last_build', $last_build);

        return $stats;
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

        $clean = array();
        foreach ((array) $statuses as $status) {
            $status = (string) $status;
            if ('' === $status) {
                continue;
            }
            if (0 !== strpos($status, 'wc-')) {
                $status = 'wc-' . ltrim($status, '-');
            }
            $clean[] = $status;
        }

        if (empty($clean)) {
            return $default;
        }

        return array_values(array_unique($clean));
    }

    /**
     * Return diagnostic counts so the admin UI can explain an empty index.
     *
     * @param int $days Lookback window in days.
     * @return array{orders_in_window:int, multi_item_orders:int, single_item_orders:int, days:int, statuses:string[]}
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
        $use_hpos = $wpdb->get_var("SHOW TABLES LIKE '{$hpos_table}'") === $hpos_table;

        $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));

        if ($use_hpos) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $orders_in_window = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$hpos_table}
                    WHERE type = 'shop_order' AND status IN ({$status_placeholders})
                    AND date_created_gmt >= %s",
                    array_merge($statuses, array($date_cutoff))
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
                    array_merge($statuses, array($date_cutoff))
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $orders_in_window = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts}
                    WHERE post_type = 'shop_order' AND post_status IN ({$status_placeholders})
                    AND post_date_gmt >= %s",
                    array_merge($statuses, array($date_cutoff))
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
                    array_merge($statuses, array($date_cutoff))
                )
            );
        }

        return array(
            'orders_in_window'   => $orders_in_window,
            'multi_item_orders'  => $multi_item_orders,
            'single_item_orders' => max(0, $orders_in_window - $multi_item_orders),
            'days'               => $days,
            'statuses'           => $statuses,
        );
    }

    /**
     * Get product IDs from an order.
     *
     * @param int $order_id Order ID.
     * @return array Unique product IDs.
     */
    private function get_order_product_ids($order_id)
    {
        global $wpdb;

        $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
        $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

        $product_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT oim.meta_value
                FROM {$order_items_table} oi
                JOIN {$order_itemmeta_table} oim ON oi.order_item_id = oim.order_item_id
                WHERE oi.order_id = %d
                AND oi.order_item_type = 'line_item'
                AND oim.meta_key = '_product_id'
                AND oim.meta_value > 0",
                $order_id
            )
        );

        return array_map('absint', $product_ids);
    }
}
