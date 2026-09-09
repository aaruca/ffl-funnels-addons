<?php
/**
 * White Label — dashboard data.
 *
 * WooCommerce business metrics are loaded with bounded-memory pagination.
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
        $key = self::cache_key('v3|woo|' . $from . '|' . $to);

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

    /**
     * Clear the finite set of dashboard transients used by this module.
     */
    public static function flush(): void
    {
        $to   = gmdate('Y-m-d');
        $from = gmdate('Y-m-d', strtotime('-29 days'));
        delete_transient(self::cache_key('v3|woo|' . $from . '|' . $to));

        foreach (['google', 'snapfind'] as $source) {
            foreach ([7, 30, 90] as $days) {
                delete_transient(self::cache_key('v1|analytics|' . $source . '|' . $days));
            }
        }
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
            $from_ts = (int) strtotime($from . ' 00:00:00');
            $to_ts   = (int) strtotime($to . ' 23:59:59');

            $current = self::woo_period($from_ts, $to_ts);

            $days         = max(1, (int) round(($to_ts - $from_ts) / DAY_IN_SECONDS));
            $prev_to_ts   = $from_ts - 1;
            $prev_from_ts = $prev_to_ts - ($days * DAY_IN_SECONDS);
            $previous     = self::woo_period($prev_from_ts, $prev_to_ts);

            $series = [];
            for ($cursor = strtotime($from); $cursor <= strtotime($to); $cursor = strtotime('+1 day', $cursor)) {
                $day      = gmdate('Y-m-d', $cursor);
                $series[] = ['date' => $day, 'value' => (float) round($current['daily'][$day] ?? 0, 2)];
            }

            return [
                'sales'               => $current['sales'],
                'orders'              => $current['orders'],
                'average_order_value' => $current['orders'] > 0 ? round($current['sales'] / $current['orders'], 2) : 0.0,
                'sales_delta'         => self::delta($current['sales'], $previous['sales']),
                'orders_delta'        => self::delta((float) $current['orders'], (float) $previous['orders']),
                'series'              => $series,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Sum paid orders in fixed-size pages so large stores cannot exhaust PHP
     * memory by materialising every order object at once.
     *
     * @return array{sales: float, orders: int, daily: array<string, float>}
     */
    private static function woo_period(int $from_ts, int $to_ts): array
    {
        $sales     = 0.0;
        $count     = 0;
        $daily     = [];
        $page      = 1;
        $max_pages = 1;

        do {
            $result = wc_get_orders([
                'limit'        => self::ORDER_PAGE_SIZE,
                'paged'        => $page,
                'paginate'     => true,
                'type'         => 'shop_order',
                'status'       => wc_get_is_paid_statuses(),
                'date_created' => $from_ts . '...' . $to_ts,
                'return'       => 'objects',
            ]);

            if (is_object($result) && isset($result->orders) && is_array($result->orders)) {
                $orders     = $result->orders;
                $max_pages = max(1, (int) ($result->max_num_pages ?? 1));
            } else {
                // Compatibility fallback for stores whose order data store does
                // not return the standard paginated result object.
                $orders     = is_array($result) ? $result : [];
                $max_pages = count($orders) === self::ORDER_PAGE_SIZE ? $page + 1 : $page;
            }

            foreach ($orders as $order) {
                if (!is_object($order) || !method_exists($order, 'get_total')) {
                    continue;
                }

                $total  = (float) $order->get_total();
                $sales += $total;
                $count++;

                $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
                if ($created) {
                    $day         = $created->date('Y-m-d');
                    $daily[$day] = ($daily[$day] ?? 0) + $total;
                }
            }

            unset($orders, $result);
            $page++;
        } while ($page <= $max_pages);

        return ['sales' => $sales, 'orders' => $count, 'daily' => $daily];
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
            $penetration    = (float) ($overview['search_penetration'] ?? 0);

            $clicks          = (int) round($searches * $ctr / 100);
            $previous_clicks = (int) round($previous_searches * $previous_ctr / 100);
            $purchases       = (int) round($searches * $conversion / 100);
            $traffic         = $penetration > 0 ? (int) round($searches * 100 / $penetration) : 0;

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
                'traffic'   => $traffic,
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
