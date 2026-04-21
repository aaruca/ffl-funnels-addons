<?php
/**
 * WooBooster Trending Builder.
 *
 * Calculates trending/bestselling products per category and stores
 * results in transients. Zero new database tables.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Trending
{

    /**
     * Build the trending index.
     *
     * Aggregates recent sales by product, grouped by category,
     * and stores ranked product ID arrays in transients.
     *
     * @return array Build stats.
     */
    public function build()
    {
        global $wpdb;

        $start = microtime(true);
        $options = get_option('woobooster_settings', array());
        $days = isset($options['smart_days']) ? absint($options['smart_days']) : 90;

        if ($days < 1) {
            $days = 90;
        }

        $date_cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
        $has_lookup = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup_table)) === $lookup_table;

        $statuses = self::get_order_statuses();
        $statuses_hpos = self::expand_statuses_for_hpos($statuses);
        $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        $status_placeholders_hpos = implode(', ', array_fill(0, count($statuses_hpos), '%s'));

        $product_sales = array();

        if ($has_lookup) {
            $use_hpos_lookup = WooBooster_Copurchase::is_custom_orders_table_in_use();

            if ($use_hpos_lookup) {
                $hpos_table = $wpdb->prefix . 'wc_orders';
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT l.product_id, SUM(l.product_qty) AS total_qty
                        FROM {$lookup_table} l
                        JOIN {$hpos_table} o ON o.id = l.order_id
                        WHERE l.date_created >= %s
                        AND o.type = 'shop_order'
                        AND o.status IN ({$status_placeholders_hpos})
                        GROUP BY l.product_id
                        ORDER BY total_qty DESC",
                        array_merge(array($date_cutoff), $statuses_hpos)
                    )
                );
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT l.product_id, SUM(l.product_qty) AS total_qty
                        FROM {$lookup_table} l
                        JOIN {$wpdb->posts} p ON p.ID = l.order_id
                        WHERE l.date_created >= %s
                        AND p.post_type = 'shop_order'
                        AND p.post_status IN ({$status_placeholders})
                        GROUP BY l.product_id
                        ORDER BY total_qty DESC",
                        array_merge(array($date_cutoff), $statuses)
                    )
                );
            }

            foreach ($results as $row) {
                $product_sales[absint($row->product_id)] = absint($row->total_qty);
            }
        } else {
            // Fallback: scan order items.
            $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
            $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

            $hpos_table = $wpdb->prefix . 'wc_orders';
            $use_hpos = WooBooster_Copurchase::is_custom_orders_table_in_use();

            if ($use_hpos) {
                // HPOS stores status without the `wc-` prefix; match both forms.
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- status_placeholders_hpos built from a trusted whitelist.
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT oim_pid.meta_value AS product_id, SUM(oim_qty.meta_value) AS total_qty
                        FROM {$order_items_table} oi
                        JOIN {$hpos_table} o ON oi.order_id = o.id
                        JOIN {$order_itemmeta_table} oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
                        JOIN {$order_itemmeta_table} oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
                        WHERE o.status IN ({$status_placeholders_hpos})
                        AND o.date_created_gmt >= %s
                        AND oi.order_item_type = 'line_item'
                        GROUP BY oim_pid.meta_value
                        ORDER BY total_qty DESC",
                        array_merge($statuses_hpos, array($date_cutoff))
                    )
                );
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT oim_pid.meta_value AS product_id, SUM(oim_qty.meta_value) AS total_qty
                        FROM {$order_items_table} oi
                        JOIN {$wpdb->posts} p ON oi.order_id = p.ID
                        JOIN {$order_itemmeta_table} oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
                        JOIN {$order_itemmeta_table} oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
                        WHERE p.post_status IN ({$status_placeholders})
                        AND p.post_date_gmt >= %s
                        AND oi.order_item_type = 'line_item'
                        GROUP BY oim_pid.meta_value
                        ORDER BY total_qty DESC",
                        array_merge($statuses, array($date_cutoff))
                    )
                );
            }

            foreach ($results as $row) {
                $product_sales[absint($row->product_id)] = absint($row->total_qty);
            }
        }

        // Group products by category using a single SQL JOIN (avoids N+1).
        $categories_indexed = 0;
        $category_products = array();

        $product_ids = array_keys($product_sales);
        if (!empty($product_ids)) {
            $placeholders = implode(', ', array_fill(0, count($product_ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $cat_relationships = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tt.term_id, p.ID as product_id
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE p.ID IN ({$placeholders}) AND tt.taxonomy = %s",
                    array_merge($product_ids, array('product_cat'))
                )
            );

            foreach ($cat_relationships as $row) {
                $cat_id = absint($row->term_id);
                $pid = absint($row->product_id);
                if (!isset($category_products[$cat_id])) {
                    $category_products[$cat_id] = array();
                }
                if (isset($product_sales[$pid])) {
                    $category_products[$cat_id][$pid] = $product_sales[$pid];
                }
            }
        }

        // Store top 50 per category as transient.
        foreach ($category_products as $cat_id => $products) {
            arsort($products);
            $top = array_slice(array_keys($products), 0, 50, true);
            set_transient('wb_trending_cat_' . $cat_id, $top, 12 * HOUR_IN_SECONDS);
            $categories_indexed++;
        }

        // Also store a global trending list (all categories combined).
        arsort($product_sales);
        $global_top = array_slice(array_keys($product_sales), 0, 50, true);
        set_transient('wb_trending_global', $global_top, 12 * HOUR_IN_SECONDS);

        $elapsed = round(microtime(true) - $start, 2);
        $product_count = count($product_sales);

        $reason = '';
        if (0 === $product_count) {
            $reason = sprintf(
                /* translators: 1: days window, 2: comma-separated statuses */
                __('No sales in the last %1$d days with status %2$s. Increase "Days to Analyze" or adjust the status filter.', 'ffl-funnels-addons'),
                $days,
                implode(', ', $statuses)
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG && 0 === $product_count) {
            error_log('WooBooster Trending: 0 products indexed. ' . $reason);
        }

        $stats = array(
            'type'       => 'trending',
            'products'   => $product_count,
            'categories' => $categories_indexed,
            'days'       => $days,
            'statuses'   => $statuses,
            'reason'     => $reason,
            'time'       => $elapsed,
            'date'       => current_time('mysql'),
        );

        $last_build = get_option('woobooster_last_build', array());
        $last_build['trending'] = $stats;
        update_option('woobooster_last_build', $last_build);

        return $stats;
    }

    /**
     * Order statuses considered when building the trending index.
     *
     * Filterable via `woobooster_trending_order_statuses`.
     *
     * @return string[] List of statuses with the `wc-` prefix.
     */
    public static function get_order_statuses(): array
    {
        $default = array('wc-completed', 'wc-processing');
        $statuses = apply_filters('woobooster_trending_order_statuses', $default);

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
     * Expand statuses for queries against the HPOS wp_wc_orders.status column.
     *
     * HPOS stores the status without the `wc-` prefix (e.g. `completed`), while
     * wp_posts.post_status keeps it (`wc-completed`). Returning both forms lets
     * the IN-clause match either storage format and is idempotent for callers
     * that already pass unprefixed statuses.
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
}
