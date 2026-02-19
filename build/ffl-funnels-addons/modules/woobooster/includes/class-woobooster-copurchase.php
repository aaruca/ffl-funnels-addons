<?php
/**
 * WooBooster Co-Purchase Builder.
 *
 * Scans completed orders and builds a "frequently bought together" index
 * stored in product postmeta. Zero new database tables.
 *
 * @package WooBooster
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

        $pairs = array();
        $offset = 0;

        do {
            if ($use_hpos) {
                // HPOS: get order IDs from wc_orders table.
                $order_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT id FROM {$hpos_table}
                        WHERE type = 'shop_order' AND status IN ('wc-completed', 'wc-processing')
                        AND date_created_gmt >= %s
                        ORDER BY id ASC
                        LIMIT %d OFFSET %d",
                        $date_cutoff,
                        $batch_size,
                        $offset
                    )
                );
            } else {
                // Legacy: get order IDs from wp_posts.
                $order_ids = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts}
                        WHERE post_type = 'shop_order' AND post_status IN ('wc-completed', 'wc-processing')
                        AND post_date_gmt >= %s
                        ORDER BY ID ASC
                        LIMIT %d OFFSET %d",
                        $date_cutoff,
                        $batch_size,
                        $offset
                    )
                );
            }

            if (empty($order_ids)) {
                break;
            }

            foreach ($order_ids as $order_id) {
                $product_ids = $this->get_order_product_ids($order_id);

                if (count($product_ids) < 2) {
                    continue;
                }

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

        // Save stats.
        $stats = array(
            'type' => 'copurchase',
            'products' => $products_indexed,
            'orders_scanned' => $offset,
            'time' => $elapsed,
            'date' => current_time('mysql'),
        );

        $last_build = get_option('woobooster_last_build', array());
        $last_build['copurchase'] = $stats;
        update_option('woobooster_last_build', $last_build);

        return $stats;
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
