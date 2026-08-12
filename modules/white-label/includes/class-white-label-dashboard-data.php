<?php
/**
 * White Label — dashboard data.
 *
 * Gathers the numbers for the custom dashboard from WooCommerce (sales, orders)
 * and SnapFind (search conversion, funnel, top terms, site traffic). Everything
 * is wrapped in existence checks and try/catch so a missing or changed
 * dependency degrades gracefully (that section returns null) rather than fatals.
 * Results are cached in a short transient so the dashboard stays fast.
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

    /**
     * All dashboard data for a date range (cached).
     *
     * @param bool $force When true, skip the cache read and recompute from the
     *                     database, refreshing the stored cache with the result.
     *
     * @return array{woo: ?array, search: ?array}
     */
    public static function get(string $from, string $to, bool $force = false): array
    {
        // The version suffix lets us invalidate all cached ranges at once when
        // the data logic changes.
        $key = self::CACHE_PREFIX . md5('v2|' . $from . '|' . $to);

        if (!$force) {
            $cached = get_transient($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $data = [
            'woo'    => self::woocommerce($from, $to),
            'search' => self::snapfind($from, $to),
        ];

        // Don't cache a completely empty result (a transient dependency hiccup),
        // so it retries on the next load rather than showing "unavailable" for
        // the full TTL.
        if (null !== $data['woo'] || null !== $data['search']) {
            set_transient($key, $data, self::TTL);
        }

        return $data;
    }

    /**
     * Clear the cached dashboard data (all ranges is impractical; clear on save).
     */
    public static function flush(): void
    {
        // Ranges are date-keyed; simplest is to let them expire. Provided for
        // explicit invalidation hooks if needed later.
        delete_transient(self::CACHE_PREFIX . md5(gmdate('Y-m-d', strtotime('-29 days')) . '|' . gmdate('Y-m-d')));
    }

    /* =====================================================================
     * WooCommerce — sales & orders
     * ================================================================== */

    /**
     * @return array{sales: float, orders: int, sales_delta: ?float, orders_delta: ?float, series: array<int, array{date: string, value: float}>}|null
     */
    private static function woocommerce(string $from, string $to): ?array
    {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_orders')) {
            return null;
        }

        try {
            $from_ts = (int) strtotime($from . ' 00:00:00');
            $to_ts   = (int) strtotime($to . ' 23:59:59');

            $current = self::woo_period($from_ts, $to_ts);

            $days         = max(1, (int) round(($to_ts - $from_ts) / DAY_IN_SECONDS));
            $prev_to_ts   = $from_ts - 1;
            $prev_from_ts = $prev_to_ts - ($days * DAY_IN_SECONDS);
            $previous     = self::woo_period($prev_from_ts, $prev_to_ts);

            // Fill a daily series across the whole range (zeros where no sales).
            $series = [];
            for ($cursor = strtotime($from); $cursor <= strtotime($to); $cursor = strtotime('+1 day', $cursor)) {
                $day = gmdate('Y-m-d', $cursor);
                $series[] = ['date' => $day, 'value' => (float) round($current['daily'][$day] ?? 0, 2)];
            }

            return [
                'sales'        => $current['sales'],
                'orders'       => $current['orders'],
                'sales_delta'  => self::delta($current['sales'], $previous['sales']),
                'orders_delta' => self::delta((float) $current['orders'], (float) $previous['orders']),
                'series'       => $series,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Sum totals, count, and daily buckets for paid orders in a timestamp range.
     *
     * @return array{sales: float, orders: int, daily: array<string, float>}
     */
    private static function woo_period(int $from_ts, int $to_ts): array
    {
        $orders = wc_get_orders([
            'limit'        => -1,
            'type'         => 'shop_order',
            'status'       => wc_get_is_paid_statuses(),
            'date_created' => $from_ts . '...' . $to_ts,
            'return'       => 'objects',
        ]);

        $sales = 0.0;
        $count = 0;
        $daily = [];

        foreach ($orders as $order) {
            $total  = (float) $order->get_total();
            $sales += $total;
            $count++;

            $created = $order->get_date_created();
            if ($created) {
                $day         = $created->date('Y-m-d');
                $daily[$day] = ($daily[$day] ?? 0) + $total;
            }
        }

        return ['sales' => $sales, 'orders' => $count, 'daily' => $daily];
    }

    /* =====================================================================
     * SnapFind — search analytics
     * ================================================================== */

    /**
     * @return array{conversion: float, conversion_delta: ?float, traffic: int, ctr: float, funnel: array<int, array{label: string, value: int}>, top_terms: array<int, array{term: string, searches: int, clicks: int, ctr: float}>}|null
     */
    private static function snapfind(string $from, string $to): ?array
    {
        if (!class_exists('\SnapFind\Analytics\Stats')) {
            return null;
        }

        try {
            // SnapFind's Singleton trait exposes getInstance().
            if (!method_exists('\SnapFind\Analytics\Stats', 'getInstance')) {
                return null;
            }
            $stats = \SnapFind\Analytics\Stats::getInstance();
            if (!is_object($stats) || !method_exists($stats, 'getData')) {
                return null;
            }

            $data = $stats->getData($from, $to);
            if (!is_array($data)) {
                return null;
            }

            $overview = isset($data['overview']) && is_array($data['overview']) ? $data['overview'] : [];
            $previous = isset($data['overview_previous']) && is_array($data['overview_previous']) ? $data['overview_previous'] : [];

            $searches    = (int) ($overview['total_searches'] ?? 0);
            $ctr         = (float) ($overview['ctr'] ?? 0);
            $cr          = (float) ($overview['cr'] ?? 0);
            $penetration = (float) ($overview['search_penetration'] ?? 0);

            // Reconstruct funnel counts from the aggregated rates.
            $clicks    = (int) round($searches * $ctr / 100);
            $purchases = (int) round($searches * $cr / 100);

            // Traffic (total sessions) derived from the public overview:
            // penetration = searches / total * 100  →  total = searches * 100 / penetration.
            $traffic = $penetration > 0 ? (int) round($searches * 100 / $penetration) : 0;

            $top_terms = [];
            foreach ((array) ($data['top_queries'] ?? []) as $row) {
                $top_terms[] = [
                    'term'     => (string) ($row->query ?? ''),
                    'searches' => (int) ($row->searches ?? 0),
                    'clicks'   => (int) ($row->clicks ?? 0),
                    'ctr'      => (float) ($row->ctr ?? 0),
                ];
            }

            return [
                'conversion'       => $cr,
                'conversion_delta' => self::delta($cr, (float) ($previous['cr'] ?? 0)),
                'traffic'          => $traffic,
                'ctr'              => $ctr,
                'funnel'           => [
                    ['label' => __('Searches', 'ffl-funnels-addons'), 'value' => $searches],
                    ['label' => __('Product clicks', 'ffl-funnels-addons'), 'value' => $clicks],
                    ['label' => __('Purchases', 'ffl-funnels-addons'), 'value' => $purchases],
                ],
                'top_terms'        => array_slice($top_terms, 0, 5),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* =====================================================================
     * Helpers
     * ================================================================== */

    /**
     * Percentage change vs the previous period, or null when there's no baseline.
     */
    private static function delta(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
