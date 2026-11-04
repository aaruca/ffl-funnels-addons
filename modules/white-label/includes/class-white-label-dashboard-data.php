<?php
/**
 * White Label — dashboard data.
 *
 * WooCommerce sales are summed by the database (HPOS or legacy post storage)
 * without loading order objects; other order data stores use paginated
 * wc_get_orders() calls with the object cache released between pages.
 * Analytics providers are fetched separately so the dashboard can lazy-load
 * only the selected MonsterInsights or SnapFind tab.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Dashboard_Data
{
    const CACHE_PREFIX = 'ffla_wl_dash_';
    const TTL          = 600; // 10 minutes.
    const ORDER_PAGE_SIZE = 200;

    /**
     * Business data for the dashboard date range (cached).
     *
     * @param bool $force Skip the cache and recompute.
     * @return array{woo: ?array}
     */
    public static function get(string $from, string $to, bool $force = false): array
    {
        // v5: totals are aggregated in SQL and include Ready for Pickup orders.
        $key = self::cache_key('v5|woo|' . $from . '|' . $to);

        if (!$force) {
            $cached = get_transient($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = ['woo' => self::woocommerce($from, $to)];

        if (null !== $data['woo']) {
            set_transient($key, $data, self::TTL);
        }

        return $data;
    }

    /**
     * Get one analytics provider for a supported range.
     *
     * @return array<string, mixed>
     */
    public static function analytics(string $source, int $days, bool $force = false): array
    {
        $source = in_array($source, ['google', 'snapfind'], true) ? $source : 'google';
        $days   = in_array($days, [7, 30, 90], true) ? $days : 30;
        if ($source === 'google') {
            require_once __DIR__ . '/class-white-label-monsterinsights.php';
            return White_Label_MonsterInsights::get($days, $force);
        }
        $key    = self::cache_key('v1|analytics|' . $source . '|' . $days);

        if (!$force) {
            $cached = get_transient($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        if ('snapfind' === $source) {
            $to   = gmdate('Y-m-d');
            $from = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
            $data = self::snapfind($from, $to, $days);
        }

        // Missing dependencies and temporary connection failures should retry
        // on the next request instead of being hidden for the full TTL.
        if ('unavailable' !== ($data['status'] ?? '')) {
            set_transient($key, $data, self::TTL);
        }

        return $data;
    }

    private static function cache_key(string $suffix): string
    {
        return self::CACHE_PREFIX . md5($suffix);
    }

    /* =====================================================================
     * WooCommerce — sales & orders
     * ================================================================== */

    /**
     * @return array{sales: float, orders: int, average_order_value: float, sales_delta: ?float, orders_delta: ?float, series: array<int, array{date: string, value: float}>}|null
     */
    private static function woocommerce(string $from, string $to): ?array
    {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_orders')) {
            return null;
        }

        try {
            // $from / $to are site-local dates; order days are bucketed in the
            // site's time zone too, so the window, the daily series and the
            // totals all describe the same calendar days.
            $tz    = wp_timezone();
            $start = new DateTimeImmutable($from . ' 00:00:00', $tz);
            $end   = new DateTimeImmutable($to . ' 23:59:59', $tz);
            if ($end < $start) {
                return null;
            }

            // Previous period: the same number of calendar days right before.
            // One aggregation covers both periods.
            $days           = (int) $start->diff($end)->days + 1;
            $previous_start = $start->modify('-' . $days . ' days');
            $daily          = self::woo_daily($previous_start, $end);

            $current  = ['sales' => 0.0, 'orders' => 0];
            $previous = ['sales' => 0.0, 'orders' => 0];
            $series   = [];
            for ($cursor = $previous_start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
                $day    = $cursor->format('Y-m-d');
                $bucket = $daily[$day] ?? ['sales' => 0.0, 'orders' => 0];

                if ($cursor < $start) {
                    $previous['sales']  += $bucket['sales'];
                    $previous['orders'] += $bucket['orders'];
                    continue;
                }

                $current['sales']  += $bucket['sales'];
                $current['orders'] += $bucket['orders'];
                $series[] = ['date' => $day, 'value' => (float) round($bucket['sales'], 2)];
            }

            $sales = (float) round($current['sales'], 2);

            return [
                'sales'               => $sales,
                'orders'              => $current['orders'],
                'average_order_value' => $current['orders'] > 0 ? round($sales / $current['orders'], 2) : 0.0,
                'sales_delta'         => self::delta($sales, (float) round($previous['sales'], 2)),
                'orders_delta'        => self::delta((float) $current['orders'], (float) $previous['orders']),
                'series'              => $series,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Paid orders per site-local day between two site-local times.
     *
     * Sales are stored order totals (tax and shipping included, refunds not
     * subtracted) of shop orders created in the window, in the statuses from
     * self::order_statuses(). On HPOS and on WooCommerce's own post storage
     * the database sums them, so memory does not grow with the number of
     * orders. Any other order data store goes through wc_get_orders().
     *
     * @return array<string, array{sales: float, orders: int}> Keyed by Y-m-d.
     */
    private static function woo_daily(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $statuses = self::order_statuses();
        if ($to < $from || empty($statuses)) {
            return [];
        }

        switch (self::order_storage()) {
            case 'hpos':
                return self::woo_daily_hpos($from, $to, $statuses);
            case 'posts':
                return self::woo_daily_posts($from, $to, $statuses);
            default:
                return self::woo_daily_api($from, $to, $statuses);
        }
    }

    /**
     * Where WooCommerce reads orders from: 'hpos', 'posts' (its own legacy
     * post data store) or 'api' (another data store, read through the API).
     */
    private static function order_storage(): string
    {
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return 'hpos';
        }

        if (class_exists('WC_Data_Store')) {
            try {
                // A subclass or a replacement may keep totals elsewhere, so
                // only WooCommerce's own post data store is read directly.
                if ('WC_Order_Data_Store_CPT' === \WC_Data_Store::load('order')->get_current_class_name()) {
                    return 'posts';
                }
            } catch (\Throwable $e) {
                return 'api';
            }
        }

        return 'api';
    }

    /**
     * Paid order statuses, with the wc- prefix the database stores.
     *
     * WooCommerce's paid statuses (filter woocommerce_order_is_paid_statuses)
     * plus Ready for Pickup: an order only gets there from Processing, so it
     * is paid and waiting at the counter. Filter
     * ffla_wl_dashboard_order_statuses changes the list.
     *
     * @return string[]
     */
    private static function order_statuses(): array
    {
        $statuses = function_exists('wc_get_is_paid_statuses') ? (array) wc_get_is_paid_statuses() : ['processing', 'completed'];
        $statuses[] = 'ffla-ready';
        $statuses = (array) apply_filters('ffla_wl_dashboard_order_statuses', $statuses);

        $prefixed = [];
        foreach ($statuses as $status) {
            $status = is_string($status) ? trim($status) : '';
            if ('' === $status) {
                continue;
            }
            $prefixed[] = 0 === strpos($status, 'wc-') ? $status : 'wc-' . $status;
        }

        return array_values(array_unique($prefixed));
    }

    /**
     * HPOS: one GROUP BY over wc_orders, no order objects or meta.
     *
     * @param string[] $statuses
     * @return array<string, array{sales: float, orders: int}>
     */
    private static function woo_daily_hpos(DateTimeImmutable $from, DateTimeImmutable $to, array $statuses): array
    {
        global $wpdb;

        $table = method_exists('\\Automattic\\WooCommerce\\Utilities\\OrderUtil', 'get_table_for_orders')
            ? \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_orders()
            : $wpdb->prefix . 'wc_orders';

        [$day_sql, $day_args] = self::day_case('o.date_created_gmt', $from, $to);
        $in = implode(', ', array_fill(0, count($statuses), '%s'));

        // The (type, status, date_created_gmt) index covers the WHERE clause.
        $sql = "SELECT {$day_sql} AS order_day, COUNT(*) AS order_count, COALESCE(SUM(o.total_amount), 0) AS sales
            FROM %i AS o
            WHERE o.type = 'shop_order' AND o.status IN ({$in})
              AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
            GROUP BY order_day";

        return self::daily_rows($sql, array_merge(
            $day_args,
            [$table],
            $statuses,
            [self::gmt($from), self::gmt($to)]
        ));
    }

    /**
     * Legacy post storage: one GROUP BY over shop_order posts and their
     * _order_total, no order objects.
     *
     * The order total is the first _order_total row, as get_post_meta(…, true)
     * and therefore WC_Order::get_total() read it. Orders without one count
     * with a total of 0, as get_total() returns.
     *
     * @param string[] $statuses
     * @return array<string, array{sales: float, orders: int}>
     */
    private static function woo_daily_posts(DateTimeImmutable $from, DateTimeImmutable $to, array $statuses): array
    {
        global $wpdb;

        [$day_sql, $day_args] = self::day_case('p.post_date_gmt', $from, $to);
        $in = implode(', ', array_fill(0, count($statuses), '%s'));

        // post_date_gmt decides, as in wc_get_orders(). The wider post_date
        // range (local time, never more than 14 hours from UTC) only lets
        // MySQL use the (post_type, post_status, post_date) index.
        $sql = "SELECT order_day, COUNT(*) AS order_count, COALESCE(SUM(order_total), 0) AS sales
            FROM (
                SELECT {$day_sql} AS order_day,
                    (SELECT CAST(m.meta_value AS DECIMAL(26,8)) FROM %i AS m
                     WHERE m.post_id = p.ID AND m.meta_key = '_order_total'
                     ORDER BY m.meta_id LIMIT 1) AS order_total
                FROM %i AS p
                WHERE p.post_type = 'shop_order' AND p.post_status IN ({$in})
                  AND p.post_date_gmt >= %s AND p.post_date_gmt <= %s
                  AND p.post_date >= %s AND p.post_date <= %s
            ) AS paid_orders
            GROUP BY order_day";

        return self::daily_rows($sql, array_merge(
            $day_args,
            [$wpdb->postmeta, $wpdb->posts],
            $statuses,
            [
                self::gmt($from),
                self::gmt($to),
                self::gmt($from->modify('-1 day')),
                self::gmt($to->modify('+1 day')),
            ]
        ));
    }

    /**
     * Other order data stores: wc_get_orders() in pages, releasing WordPress's
     * in-memory object cache between pages so memory stays bounded.
     *
     * @param string[] $statuses
     * @return array<string, array{sales: float, orders: int}>
     */
    private static function woo_daily_api(DateTimeImmutable $from, DateTimeImmutable $to, array $statuses): array
    {
        $daily     = [];
        $page      = 1;
        $max_pages = 1;

        do {
            $result = wc_get_orders([
                'limit'        => self::ORDER_PAGE_SIZE,
                'paged'        => $page,
                'paginate'     => true,
                'type'         => 'shop_order',
                'status'       => $statuses,
                'date_created' => $from->getTimestamp() . '...' . $to->getTimestamp(),
                // Oldest first: orders placed while paging land on the last
                // page instead of shifting earlier pages.
                'orderby'      => 'ID',
                'order'        => 'ASC',
                'return'       => 'objects',
            ]);

            if (is_object($result) && isset($result->orders) && is_array($result->orders)) {
                $orders    = $result->orders;
                $max_pages = max(1, (int) ($result->max_num_pages ?? 1));
            } else {
                // Compatibility fallback for stores whose order data store does
                // not return the standard paginated result object.
                $orders    = is_array($result) ? $result : [];
                $max_pages = count($orders) === self::ORDER_PAGE_SIZE ? $page + 1 : $page;
            }

            foreach ($orders as $order) {
                if (!is_object($order) || !method_exists($order, 'get_total')) {
                    continue;
                }

                $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
                if (!$created) {
                    continue;
                }

                $day = wp_date('Y-m-d', $created->getTimestamp()); // Site-local day.
                $daily[$day] = $daily[$day] ?? ['sales' => 0.0, 'orders' => 0];
                $daily[$day]['sales'] += (float) $order->get_total();
                $daily[$day]['orders']++;
            }

            unset($orders, $result);
            $page++;

            if ($page <= $max_pages) {
                self::release_memory();
            }
        } while ($page <= $max_pages);

        return $daily;
    }

    /**
     * A CASE expression that turns a UTC datetime column into its site-local
     * day. Each boundary is a local midnight computed in PHP, so 23- and
     * 25-hour DST days are right and MySQL needs no time zone tables.
     *
     * @return array{0: string, 1: string[]} SQL and its prepare() arguments.
     */
    private static function day_case(string $column, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $cases  = [];
        $args   = [];
        $cursor = $from->setTime(0, 0);

        while ($cursor <= $to) {
            // setTime() again: where a DST change skips midnight, modify()
            // lands on 01:00 and later days would keep that hour.
            $next    = $cursor->modify('+1 day')->setTime(0, 0);
            $cases[] = "WHEN {$column} < %s THEN %s";
            $args[]  = self::gmt($next);
            $args[]  = $cursor->format('Y-m-d');
            $cursor  = $next;
        }

        return ['CASE ' . implode(' ', $cases) . ' END', $args];
    }

    /**
     * Run a per-day aggregation. A failed query throws, so woocommerce()
     * returns null and the failure is never cached as a zero-sales result.
     *
     * @param array<int, string> $args
     * @return array<string, array{sales: float, orders: int}>
     */
    private static function daily_rows(string $sql, array $args): array
    {
        global $wpdb;

        $prepared = $wpdb->prepare($sql, $args);
        $rows     = $prepared ? $wpdb->get_results($prepared, ARRAY_A) : null;

        if (!is_array($rows) || '' !== (string) $wpdb->last_error) {
            throw new \RuntimeException('Unable to aggregate dashboard order totals.');
        }

        $daily = [];
        foreach ($rows as $row) {
            if (empty($row['order_day'])) {
                continue;
            }
            $daily[(string) $row['order_day']] = [
                'sales'  => (float) $row['sales'],
                'orders' => (int) $row['order_count'],
            ];
        }

        return $daily;
    }

    private static function gmt(DateTimeImmutable $time): string
    {
        return gmdate('Y-m-d H:i:s', $time->getTimestamp());
    }

    /**
     * Drop the orders, items and meta the last page put in WordPress's
     * in-memory object cache. A persistent object cache keeps its data.
     */
    private static function release_memory(): void
    {
        if (function_exists('wp_cache_supports') && function_exists('wp_cache_flush_runtime') && wp_cache_supports('flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    /* =====================================================================
     * SnapFind — on-site search analytics
     * ================================================================== */

    /**
     * @return array<string, mixed>
     */
    private static function snapfind(string $from, string $to, int $days): array
    {
        if (!class_exists('\SnapFind\Analytics\Stats')) {
            return self::unavailable(
                'snapfind',
                __('SnapFind analytics is not active on this site.', 'ffl-funnels-addons')
            );
        }

        try {
            if (!method_exists('\SnapFind\Analytics\Stats', 'getInstance')) {
                return self::unavailable('snapfind', __('This SnapFind version does not expose analytics data.', 'ffl-funnels-addons'));
            }

            $stats = \SnapFind\Analytics\Stats::getInstance();
            if (!is_object($stats) || !method_exists($stats, 'getData')) {
                return self::unavailable('snapfind', __('This SnapFind version does not expose analytics data.', 'ffl-funnels-addons'));
            }

            $data = $stats->getData($from, $to);
            if (!is_array($data)) {
                return self::unavailable('snapfind', __('SnapFind analytics data could not be loaded right now.', 'ffl-funnels-addons'));
            }

            $overview = isset($data['overview']) && is_array($data['overview']) ? $data['overview'] : [];
            $previous = isset($data['overview_previous']) && is_array($data['overview_previous']) ? $data['overview_previous'] : [];

            $searches       = (int) ($overview['total_searches'] ?? 0);
            $previous_searches = (int) ($previous['total_searches'] ?? 0);
            $ctr            = (float) ($overview['ctr'] ?? 0);
            $previous_ctr   = (float) ($previous['ctr'] ?? 0);
            $conversion     = (float) ($overview['cr'] ?? 0);
            $previous_conversion = (float) ($previous['cr'] ?? 0);

            $clicks          = (int) round($searches * $ctr / 100);
            $previous_clicks = (int) round($previous_searches * $previous_ctr / 100);
            $purchases       = (int) round($searches * $conversion / 100);

            $top_terms = [];
            foreach ((array) ($data['top_queries'] ?? []) as $raw_row) {
                $row = self::as_array($raw_row);
                $top_terms[] = [
                    'term'     => sanitize_text_field((string) ($row['query'] ?? '')),
                    'searches' => (int) ($row['searches'] ?? 0),
                    'clicks'   => (int) ($row['clicks'] ?? 0),
                    'ctr'      => (float) ($row['ctr'] ?? 0),
                ];
            }

            $has_data = $searches > 0 || !empty($top_terms);

            return [
                'source'    => 'snapfind',
                'status'    => $has_data ? 'ready' : 'no_data',
                'message'   => $has_data ? '' : __('SnapFind has no search activity for this period.', 'ffl-funnels-addons'),
                'note'      => __('On-site search behavior reported by SnapFind.', 'ffl-funnels-addons'),
                'range'     => $days,
                'metrics'   => [
                    self::metric(__('Searches', 'ffl-funnels-addons'), ['total' => $searches, 'delta' => self::delta((float) $searches, (float) $previous_searches)], 'number', __('Searches performed on the store.', 'ffl-funnels-addons')),
                    self::metric(__('Product clicks', 'ffl-funnels-addons'), ['total' => $clicks, 'delta' => self::delta((float) $clicks, (float) $previous_clicks)], 'number', __('Searches that led to a product click.', 'ffl-funnels-addons')),
                    self::metric(__('Search CTR', 'ffl-funnels-addons'), ['total' => $ctr, 'delta' => array_key_exists('ctr', $previous) ? $ctr - $previous_ctr : null], 'percent', __('Product clicks divided by searches.', 'ffl-funnels-addons')),
                    self::metric(__('Search conversion', 'ffl-funnels-addons'), ['total' => $conversion, 'delta' => array_key_exists('cr', $previous) ? $conversion - $previous_conversion : null], 'percent', __('Searches that resulted in a purchase.', 'ffl-funnels-addons')),
                ],
                'funnel'    => [
                    ['label' => __('Searches', 'ffl-funnels-addons'), 'value' => $searches],
                    ['label' => __('Product clicks', 'ffl-funnels-addons'), 'value' => $clicks],
                    ['label' => __('Purchases', 'ffl-funnels-addons'), 'value' => $purchases],
                ],
                'top_terms' => array_slice($top_terms, 0, 10),
            ];
        } catch (\Throwable $e) {
            return self::unavailable(
                'snapfind',
                __('SnapFind analytics data could not be loaded right now.', 'ffl-funnels-addons')
            );
        }
    }

    /* =====================================================================
     * Helpers
     * ================================================================== */

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function metric(string $label, array $values, string $format, string $hint): array
    {
        return [
            'label'       => $label,
            'value'       => self::numeric($values['total'] ?? null),
            'delta'       => self::numeric($values['delta'] ?? null),
            'deltaFormat' => 'percent' === $format ? 'points' : 'percent',
            'format'      => $format,
            'hint'        => $hint,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function unavailable(string $source, string $message): array
    {
        return [
            'source'  => $source,
            'status'  => 'unavailable',
            'message' => $message,
            'metrics' => [],
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private static function as_array($value): array
    {
        if (is_object($value)) {
            return get_object_vars($value);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed $value
     */
    private static function numeric($value): ?float
    {
        if ('n/a' === $value || '' === $value || null === $value || !is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Percentage change vs the previous period, or null without a baseline.
     */
    private static function delta(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
