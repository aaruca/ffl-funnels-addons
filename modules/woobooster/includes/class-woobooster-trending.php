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

    /** Products kept per category list and in the store-wide list. */
    const LIST_SIZE = 50;

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
     * Memory: the decayed weights are summed per product in the database
     * (one row per product instead of one per product and day; see
     * decayed_scores()), categories are read in chunks, each category keeps
     * only its best candidates, and the in-memory object cache is emptied
     * between chunks. Only one build runs at a time, the time limit is
     * raised, and a build that reaches 85% of the PHP memory limit stops
     * before writing anything and records why (`stopped`, `stopped_reason`).
     *
     * @return array Build stats.
     */
    public function build()
    {
        $start = microtime(true);

        $lock = WooBooster_Cron::acquire_lock('trending');
        if (false === $lock) {
            return WooBooster_Cron::locked_stats('trending', $start);
        }

        try {
            return $this->run_build($start, $lock);
        } finally {
            WooBooster_Cron::release_lock('trending', $lock);
        }
    }

    /**
     * The build itself, under the lock taken by build().
     *
     * @param float  $start microtime(true) at the start.
     * @param string $lock  Lock token.
     * @return array Build stats.
     */
    private function run_build(float $start, string $lock): array
    {
        global $wpdb;

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

        WooBooster_Cron::prepare_long_run();
        $memory_cap = WooBooster_Cron::memory_threshold();

        $product_scores = $this->decayed_scores(
            $use_hpos,
            $use_hpos ? $statuses_hpos : $statuses,
            $date_cutoff,
            time(),
            $half_life,
            WooBooster_Copurchase::excluded_product_ids()
        );
        WooBooster_Cron::release_memory();

        $stopped = $this->guard($memory_cap, $lock);

        // Product → categories (in chunks), then roll each score up to every
        // ancestor category so parent pages see their children's sales.
        $categories_indexed = 0;
        $category_products = array();
        $chunk_size = max(1, (int) apply_filters('woobooster_trending_chunk_size', 1000));
        $prune_at = 4 * self::LIST_SIZE;
        $ancestors = array();

        $product_ids = '' === $stopped ? array_keys($product_scores) : array();
        foreach (array_chunk($product_ids, $chunk_size) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $cat_relationships = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT tt.term_id, tr.object_id AS product_id
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                    WHERE tr.object_id IN ({$placeholders}) AND tt.taxonomy = %s",
                    array_merge($chunk, array('product_cat'))
                ),
                ARRAY_A
            );

            foreach ((array) $cat_relationships as $row) {
                $cat_id = absint($row['term_id']);
                $pid = absint($row['product_id']);
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
                    // Keep big categories small: cutting to the top 50 when
                    // the list reaches 200 cannot change the final top 50.
                    if (count($category_products[$target]) >= $prune_at) {
                        $category_products[$target] = self::top_scores($category_products[$target], self::LIST_SIZE);
                    }
                }
            }
            unset($cat_relationships);
            WooBooster_Cron::release_memory();

            $stopped = $this->guard($memory_cap, $lock);
            if ('' !== $stopped) {
                break;
            }
        }
        unset($product_ids, $ancestors);

        if ('' !== $stopped) {
            return WooBooster_Cron::stopped_build(
                'trending',
                $stopped,
                'lock_lost' === $stopped
                    ? __('Stopped because another update of Trending started at the same time; that update finishes the job.', 'ffl-funnels-addons')
                    : sprintf(
                        /* translators: %s: memory in use and limit (e.g. "220 MB of 256 MB") */
                        __('Stopped to stay within the server memory limit (%s in use). The previous Trending lists are kept until they expire. Raise the PHP memory limit or lower "Days to Analyze".', 'ffl-funnels-addons'),
                        WooBooster_Cron::memory_usage_text()
                    ),
                $start,
                array('days' => $days)
            );
        }

        $ttl = 2 * DAY_IN_SECONDS;
        foreach ($category_products as $cat_id => $products) {
            set_transient('wb_trending_cat_' . $cat_id, self::top_ids($products, self::LIST_SIZE), $ttl);
            $categories_indexed++;
            if (0 === $categories_indexed % 200) {
                WooBooster_Cron::release_memory();
            }
        }
        unset($category_products);

        set_transient('wb_trending_global', self::top_ids($product_scores, self::LIST_SIZE), $ttl);

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
            'type'        => 'trending',
            'products'    => $product_count,
            'categories'  => $categories_indexed,
            'days'        => $days,
            'half_life'   => $half_life,
            'statuses'    => $statuses,
            'reason'      => $reason,
            'memory_peak' => WooBooster_Cron::memory_peak_mb(),
            'time'        => $elapsed,
            'date'        => current_time('mysql'),
        );

        $last_build = get_option('woobooster_last_build', array());
        $last_build = is_array($last_build) ? $last_build : array();
        $last_build['trending'] = $stats;
        update_option('woobooster_last_build', $last_build);

        return $stats;
    }

    /**
     * '' while the build may go on, else why it must stop (memory, lock_lost).
     */
    private function guard(int $memory_cap, string $lock): string
    {
        if (WooBooster_Cron::memory_exceeded($memory_cap)) {
            return 'memory';
        }
        if (!WooBooster_Cron::refresh_lock('trending', $lock)) {
            return 'lock_lost';
        }
        return '';
    }

    /**
     * Time-decayed popularity per product (pure function; unit-testable).
     *
     * The reference for decayed_scores(), which computes the same sums in
     * SQL.
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
        return array_map('intval', array_keys(self::top_scores($scores, $limit)));
    }

    /**
     * The $limit highest scores (keys kept); ties broken by product ID.
     *
     * @param array<int, float> $scores
     * @return array<int, float>
     */
    private static function top_scores(array $scores, int $limit): array
    {
        uksort($scores, static function ($a, $b) use ($scores) {
            if ($scores[$a] === $scores[$b]) {
                return $a <=> $b;
            }
            return $scores[$b] <=> $scores[$a];
        });

        return array_slice($scores, 0, $limit, true);
    }

    /**
     * Decayed score per product, summed in the database.
     *
     * The inner query is the one the build used to return to PHP: distinct
     * orders per product per UTC day, from wc_order_product_lookup when
     * present (parent product IDs), or order item meta otherwise. The outer
     * query adds up, per product, exactly what score_rows() adds up for those
     * rows (same formula and double arithmetic, so the same ranking):
     *
     *   orders × 0.5 ^ (max(0, (now − day 12:00 UTC) / 86400) / half_life)
     *
     * with (now − day 12:00 UTC) written as 86400 × DATEDIFF(today, day) +
     * (now − today 12:00 UTC), so no time zone conversion is involved.
     *
     * @param bool     $use_hpos    Whether orders live in wc_orders.
     * @param string[] $statuses    Statuses in the storage's format.
     * @param string   $date_cutoff GMT lower bound.
     * @param int      $now         Current Unix time.
     * @param float    $half_life   Days for a sale's weight to halve.
     * @param int[]    $excluded    Product IDs to leave out.
     * @return array<int, float> product_id => score.
     */
    private function decayed_scores(bool $use_hpos, array $statuses, string $date_cutoff, int $now, float $half_life, array $excluded): array
    {
        global $wpdb;

        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));

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
            $daily = "SELECT l.product_id AS product_id, DATE({$date_col}) AS day, COUNT(DISTINCT l.order_id) AS orders
                FROM {$lookup} l
                {$join}
                WHERE {$where} AND l.product_id > 0
                GROUP BY l.product_id, DATE({$date_col})";
        } else {
            $items = $wpdb->prefix . 'woocommerce_order_items';
            $itemmeta = $wpdb->prefix . 'woocommerce_order_itemmeta';
            $join = sprintf($order_join, 'oi.order_id');
            $daily = "SELECT oim.meta_value AS product_id, DATE({$date_col}) AS day, COUNT(DISTINCT oi.order_id) AS orders
                FROM {$items} oi
                {$join}
                JOIN {$itemmeta} oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
                WHERE {$where} AND oi.order_item_type = 'line_item' AND oim.meta_value > 0
                GROUP BY oim.meta_value, DATE({$date_col})";
        }

        $today = gmdate('Y-m-d', $now);
        $since_noon = $now - (int) strtotime($today . ' 12:00:00 UTC');
        // Shortest exact, locale-independent form of the half-life (a float,
        // so safe to inline); the 86400e0 literal keeps the division in
        // double precision, as in PHP.
        $half_life_sql = is_finite($half_life) ? (string) json_encode($half_life) : '1.7976931348623157e+308';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.product_id AS product_id,
                    SUM(d.orders * POW(0.5, GREATEST(0, (86400 * DATEDIFF(%s, d.day) + %d) / 86400e0) / {$half_life_sql})) AS score
                FROM ({$daily}) d
                GROUP BY d.product_id",
                array_merge(array($today, $since_noon), $statuses, array($date_cutoff))
            ),
            ARRAY_A
        );

        $excluded = array_flip(array_map('absint', $excluded));
        $scores = array();
        foreach ((array) $rows as $row) {
            $pid = absint($row['product_id'] ?? 0);
            if (!$pid || isset($excluded[$pid]) || !isset($row['score'])) {
                continue;
            }
            $scores[$pid] = ($scores[$pid] ?? 0.0) + (float) $row['score'];
        }
        unset($rows);
        $wpdb->flush();

        return $scores;
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
