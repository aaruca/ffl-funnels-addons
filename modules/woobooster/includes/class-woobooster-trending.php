<?php
/**
 * WooBooster Trending Builder.
 *
 * Ranks products by recent, time-decayed order momentum per category and
 * stores the results in transients. Zero new database tables.
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
     * Since 1.51.0 "trending" means recent momentum, not lifetime quantity:
     * every distinct order that contains a product adds a weight that halves
     * every `woobooster_trending_half_life_days` days (default 14), so a sale
     * yesterday counts about twice one from two weeks ago and quantity does not
     * matter (20 boxes of ammo in one order count once). Products are ranked
     * per category, and each category's list also includes sales from its
     * child categories. Virtual products are left out (see
     * WooBooster_Copurchase::excluded_product_ids()).
     *
     * Results are stored as transients (top 50 per category plus a global
     * list), kept for two days so a late cron run never leaves them empty.
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

        $half_life = max(1.0, (float) apply_filters('woobooster_trending_half_life_days', 14));
        $date_cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $statuses = self::get_order_statuses();
        $statuses_hpos = self::expand_statuses_for_hpos($statuses);
        $use_hpos = WooBooster_Copurchase::is_custom_orders_table_in_use();

        $rows = $this->daily_order_counts($use_hpos, $use_hpos ? $statuses_hpos : $statuses, $date_cutoff);
        $product_scores = self::score_rows($rows, time(), $half_life, WooBooster_Copurchase::excluded_product_ids());

        // Product → categories (one query), then roll each score up to every
        // ancestor category so parent pages see their children's sales.
        $categories_indexed = 0;
        $category_products = array();

        $product_ids = array_keys($product_scores);
        if (!empty($product_ids)) {
            $placeholders = implode(', ', array_fill(0, count($product_ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $cat_relationships = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tt.term_id, tr.object_id AS product_id
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE tr.object_id IN ({$placeholders}) AND tt.taxonomy = %s",
                    array_merge($product_ids, array('product_cat'))
                )
            );

            $ancestors = array();
            foreach ($cat_relationships as $row) {
                $cat_id = absint($row->term_id);
                $pid = absint($row->product_id);
                if (!isset($product_scores[$pid])) {
                    continue;
                }
                if (!isset($ancestors[$cat_id])) {
                    $ancestors[$cat_id] = array_map('absint', (array) get_ancestors($cat_id, 'product_cat', 'taxonomy'));
                }
                foreach (array_merge(array($cat_id), $ancestors[$cat_id]) as $target) {
                    // A product sits in a category once, even when several of
                    // its categories share that ancestor.
                    $category_products[$target][$pid] = $product_scores[$pid];
                }
            }
        }

        $ttl = 2 * DAY_IN_SECONDS;
        foreach ($category_products as $cat_id => $products) {
            set_transient('wb_trending_cat_' . $cat_id, self::top_ids($products, 50), $ttl);
            $categories_indexed++;
        }

        set_transient('wb_trending_global', self::top_ids($product_scores, 50), $ttl);

        $elapsed = round(microtime(true) - $start, 2);
        $product_count = count($product_scores);

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
            'half_life'  => $half_life,
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
     * Time-decayed popularity per product (pure function; unit-testable).
     *
     * @param array<int, array{product_id:int|string, day:string, orders:int|string}> $rows
     *        Distinct orders per product per day (UTC dates, Y-m-d).
     * @param int   $now        Current Unix time.
     * @param float $half_life  Days for a sale's weight to halve.
     * @param int[] $excluded   Product IDs to leave out.
     * @return array<int, float> product_id => score.
     */
    public static function score_rows(array $rows, int $now, float $half_life, array $excluded = array()): array
    {
        $excluded = array_flip(array_map('absint', $excluded));
        $scores = array();

        foreach ($rows as $row) {
            $row = (array) $row;
            $pid = absint($row['product_id'] ?? 0);
            $orders = (int) ($row['orders'] ?? 0);
            $day = strtotime((string) ($row['day'] ?? '') . ' 12:00:00 UTC');
            if (!$pid || $orders < 1 || false === $day || isset($excluded[$pid])) {
                continue;
            }
            $age_days = max(0.0, ($now - $day) / DAY_IN_SECONDS);
            $scores[$pid] = ($scores[$pid] ?? 0.0) + $orders * pow(0.5, $age_days / $half_life);
        }

        return $scores;
    }

    /**
     * IDs of the $limit highest scores; ties broken by product ID.
     *
     * @param array<int, float> $scores
     * @return int[]
     */
    private static function top_ids(array $scores, int $limit): array
    {
        uksort($scores, static function ($a, $b) use ($scores) {
            if ($scores[$a] === $scores[$b]) {
                return $a <=> $b;
            }
            return $scores[$b] <=> $scores[$a];
        });

        return array_map('intval', array_slice(array_keys($scores), 0, $limit));
    }

    /**
     * Distinct orders per product per day in the window.
     *
     * Uses wc_order_product_lookup when present (parent product IDs), or order
     * item meta otherwise.
     *
     * @param bool     $use_hpos    Whether orders live in wc_orders.
     * @param string[] $statuses    Statuses in the storage's format.
     * @param string   $date_cutoff GMT lower bound.
     * @return array<int, array{product_id:string, day:string, orders:string}>
     */
    private function daily_order_counts(bool $use_hpos, array $statuses, string $date_cutoff): array
    {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        $args = array_merge($statuses, array($date_cutoff));

        if ($use_hpos) {
            $orders_table = $wpdb->prefix . 'wc_orders';
            $order_join = "JOIN {$orders_table} o ON o.id = %s";
            $where = "o.type = 'shop_order' AND o.status IN ({$placeholders}) AND o.date_created_gmt >= %s";
            $date_col = 'o.date_created_gmt';
        } else {
            $order_join = "JOIN {$wpdb->posts} o ON o.ID = %s";
            $where = "o.post_type = 'shop_order' AND o.post_status IN ({$placeholders}) AND o.post_date_gmt >= %s";
            $date_col = 'o.post_date_gmt';
        }

        if (WooBooster_Copurchase::lookup_table_exists()) {
            $lookup = $wpdb->prefix . 'wc_order_product_lookup';
            $join = sprintf($order_join, 'l.order_id');
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT l.product_id AS product_id, DATE({$date_col}) AS day, COUNT(DISTINCT l.order_id) AS orders
                    FROM {$lookup} l
                    {$join}
                    WHERE {$where} AND l.product_id > 0
                    GROUP BY l.product_id, DATE({$date_col})",
                    $args
                ),
                ARRAY_A
            );

            return (array) $rows;
        }

        $items = $wpdb->prefix . 'woocommerce_order_items';
        $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
        $join = sprintf($order_join, 'oi.order_id');
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT oim.meta_value AS product_id, DATE({$date_col}) AS day, COUNT(DISTINCT oi.order_id) AS orders
                FROM {$items} oi
                {$join}
                JOIN {$itemmeta} oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
                WHERE {$where} AND oi.order_item_type = 'line_item' AND oim.meta_value > 0
                GROUP BY oim.meta_value, DATE({$date_col})",
                $args
            ),
            ARRAY_A
        );

        return (array) $rows;
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
