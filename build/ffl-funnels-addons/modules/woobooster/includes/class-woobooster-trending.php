<?php
/**
 * WooBooster Trending Builder.
 *
 * Calculates trending/bestselling products per category and stores
 * results in transients. Zero new database tables.
 *
 * @package WooBooster
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

        // Use the WooCommerce order product lookup table if available.
        $lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
        $has_lookup = $wpdb->get_var("SHOW TABLES LIKE '{$lookup_table}'") === $lookup_table;

        $product_sales = array();

        if ($has_lookup) {
            // Fast path: aggregate from lookup table.
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT product_id, SUM(product_qty) as total_qty
                    FROM {$lookup_table}
                    WHERE date_created >= %s
                    GROUP BY product_id
                    ORDER BY total_qty DESC",
                    $date_cutoff
                )
            );

            foreach ($results as $row) {
                $product_sales[absint($row->product_id)] = absint($row->total_qty);
            }
        } else {
            // Fallback: scan order items.
            $order_items_table = $wpdb->prefix . 'woocommerce_order_items';
            $order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

            $hpos_table = $wpdb->prefix . 'wc_orders';
            $use_hpos = $wpdb->get_var("SHOW TABLES LIKE '{$hpos_table}'") === $hpos_table;

            if ($use_hpos) {
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT oim_pid.meta_value AS product_id, SUM(oim_qty.meta_value) AS total_qty
                        FROM {$order_items_table} oi
                        JOIN {$hpos_table} o ON oi.order_id = o.id
                        JOIN {$order_itemmeta_table} oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
                        JOIN {$order_itemmeta_table} oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
                        WHERE o.status IN ('wc-completed', 'wc-processing')
                        AND o.date_created_gmt >= %s
                        AND oi.order_item_type = 'line_item'
                        GROUP BY oim_pid.meta_value
                        ORDER BY total_qty DESC",
                        $date_cutoff
                    )
                );
            } else {
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT oim_pid.meta_value AS product_id, SUM(oim_qty.meta_value) AS total_qty
                        FROM {$order_items_table} oi
                        JOIN {$wpdb->posts} p ON oi.order_id = p.ID
                        JOIN {$order_itemmeta_table} oim_pid ON oi.order_item_id = oim_pid.order_item_id AND oim_pid.meta_key = '_product_id'
                        JOIN {$order_itemmeta_table} oim_qty ON oi.order_item_id = oim_qty.order_item_id AND oim_qty.meta_key = '_qty'
                        WHERE p.post_status IN ('wc-completed', 'wc-processing')
                        AND p.post_date_gmt >= %s
                        AND oi.order_item_type = 'line_item'
                        GROUP BY oim_pid.meta_value
                        ORDER BY total_qty DESC",
                        $date_cutoff
                    )
                );
            }

            foreach ($results as $row) {
                $product_sales[absint($row->product_id)] = absint($row->total_qty);
            }
        }

        // Group products by category and store as transients.
        $categories_indexed = 0;
        $category_products = array();

        foreach ($product_sales as $product_id => $qty) {
            $cats = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
            if (is_wp_error($cats) || empty($cats)) {
                continue;
            }
            foreach ($cats as $cat_id) {
                if (!isset($category_products[$cat_id])) {
                    $category_products[$cat_id] = array();
                }
                $category_products[$cat_id][$product_id] = $qty;
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

        $stats = array(
            'type' => 'trending',
            'products' => count($product_sales),
            'categories' => $categories_indexed,
            'time' => $elapsed,
            'date' => current_time('mysql'),
        );

        $last_build = get_option('woobooster_last_build', array());
        $last_build['trending'] = $stats;
        update_option('woobooster_last_build', $last_build);

        return $stats;
    }
}
