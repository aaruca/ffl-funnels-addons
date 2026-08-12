<?php
/**
 * White Label — dashboard data.
 *
 * WooCommerce business metrics are loaded with bounded-memory pagination.
 * Analytics providers are fetched separately so the dashboard can lazy-load
 * only the selected Google (Rank Math PRO) or SnapFind tab.
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
        } else {
            $data = self::rank_math_google($days);
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
     * Rank Math PRO — Google Analytics + Search Console
     * ================================================================== */

    /**
     * Read Rank Math's locally synced data. Rank Math remains responsible for
     * Google authentication and its daily sync; this module never stores or
     * handles Google credentials.
     *
     * @return array<string, mixed>
     */
    private static function rank_math_google(int $days): array
    {
        if (!class_exists('\RankMath\Analytics\Stats')) {
            return self::unavailable(
                'google',
                __('Rank Math Analytics is not active on this site.', 'ffl-funnels-addons')
            );
        }

        if (!defined('RANK_MATH_PRO_VERSION') && !class_exists('\RankMathPro\Analytics\Pageviews')) {
            return self::unavailable(
                'google',
                __('Rank Math PRO is required to show Google Analytics traffic.', 'ffl-funnels-addons')
            );
        }

        try {
            $analytics_connected = class_exists('\RankMath\Google\Analytics')
                && method_exists('\RankMath\Google\Analytics', 'is_analytics_connected')
                && \RankMath\Google\Analytics::is_analytics_connected();
        } catch (\Throwable $e) {
            $analytics_connected = false;
        }

        if (!$analytics_connected) {
            $data = self::unavailable(
                'google',
                __('Connect Google Analytics in Rank Math to populate this tab.', 'ffl-funnels-addons')
            );
            $data['action_url'] = current_user_can('manage_options')
                ? admin_url('admin.php?page=rank-math-options-general#setting-panel-analytics')
                : '';
            return $data;
        }

        $ranges = [
            7  => '-7 days',
            30 => '-30 days',
            90 => '-3 months',
        ];
        $rank_math_range = $ranges[$days];

        // Rank Math's graph helper reads this cookie even after set_date_range()
        // is called. Override it for this request only, then restore it.
        $cookie_key      = 'rank_math_analytics_date_range';
        $had_cookie      = array_key_exists($cookie_key, $_COOKIE);
        $original_cookie = $had_cookie ? $_COOKIE[$cookie_key] : null;
        $_COOKIE[$cookie_key] = $rank_math_range;

        try {
            $stats = \RankMath\Analytics\Stats::get();
            $stats->set_date_range($rank_math_range);

            $summary = self::as_array($stats->get_analytics_summary());
            $series  = self::rank_math_series($summary);

            $pageviews  = self::rank_math_summary_metric($summary, 'pageviews');
            $clicks     = self::rank_math_summary_metric($summary, 'clicks');
            $impressions = self::rank_math_summary_metric($summary, 'impressions');
            $ctr        = self::rank_math_summary_metric($summary, 'ctr', true);

            // Some Rank Math PRO versions add pageviews to the graph but omit a
            // standalone summary card. Preserve the useful total in that case.
            if (null === $pageviews['total']) {
                $graph_total = 0.0;
                $has_graph_pageviews = false;
                foreach ($series as $point) {
                    if (null !== $point['pageviews']) {
                        $graph_total += (float) $point['pageviews'];
                        $has_graph_pageviews = true;
                    }
                }
                if ($has_graph_pageviews) {
                    $pageviews['total'] = $graph_total;
                }
            }

            $top_rows = self::rank_math_rows($stats, 'clicks', 'DESC', 5);
            $winners  = self::rank_math_rows($stats, 'diffClicks', 'DESC', 3, true);
            $losers   = self::rank_math_rows($stats, 'diffClicks', 'ASC', 3, true);

            $has_data = null !== $pageviews['total']
                || null !== $clicks['total']
                || null !== $impressions['total'];

            $note = __('Data is synced by Rank Math and may lag behind Google by a few days.', 'ffl-funnels-addons');
            if (null === $pageviews['total'] && $has_data) {
                $note = __('Search Console data is ready, but Rank Math has not synced Google Analytics traffic yet.', 'ffl-funnels-addons');
            }

            return [
                'source'        => 'google',
                'status'        => $has_data ? 'ready' : 'no_data',
                'message'       => $has_data ? '' : __('Rank Math has not synced analytics data for this period yet.', 'ffl-funnels-addons'),
                'note'          => $note,
                'range'         => $days,
                'metrics'       => [
                    self::metric(__('Organic search traffic', 'ffl-funnels-addons'), $pageviews, 'number', __('Google Analytics pageviews attributed to organic search.', 'ffl-funnels-addons')),
                    self::metric(__('Organic clicks', 'ffl-funnels-addons'), $clicks, 'number', __('Clicks reported by Google Search Console.', 'ffl-funnels-addons')),
                    self::metric(__('Search impressions', 'ffl-funnels-addons'), $impressions, 'number', __('Impressions reported by Google Search Console.', 'ffl-funnels-addons')),
                    self::metric(__('Search CTR', 'ffl-funnels-addons'), $ctr, 'percent', __('Organic clicks divided by search impressions.', 'ffl-funnels-addons')),
                ],
                'series'        => $series,
                'landing_pages' => $top_rows,
                'winners'       => $winners,
                'losers'        => $losers,
                'report_url'    => current_user_can('rank_math_analytics')
                    ? admin_url('admin.php?page=rank-math-analytics#/dashboard')
                    : '',
            ];
        } catch (\Throwable $e) {
            return self::unavailable(
                'google',
                __('Rank Math analytics data could not be loaded right now.', 'ffl-funnels-addons')
            );
        } finally {
            if ($had_cookie) {
                $_COOKIE[$cookie_key] = $original_cookie;
            } else {
                unset($_COOKIE[$cookie_key]);
            }
        }
    }

    /**
     * Convert Rank Math summary graph points to a small provider-neutral shape.
     *
     * @param array<string, mixed> $summary
     * @return array<int, array{label: string, pageviews: ?float, clicks: ?float}>
     */
    private static function rank_math_series(array $summary): array
    {
        $graph  = self::as_array($summary['graph'] ?? []);
        $merged = isset($graph['merged']) && is_array($graph['merged']) ? $graph['merged'] : [];
        $series = [];

        foreach ($merged as $raw_point) {
            $point = self::as_array($raw_point);
            $label = (string) ($point['formattedDate'] ?? $point['dateFormatted'] ?? $point['date'] ?? '');
            if ('' === $label) {
                continue;
            }

            $series[] = [
                'label'     => $label,
                'pageviews' => self::numeric($point['pageviews'] ?? null),
                'clicks'    => self::numeric($point['clicks'] ?? null),
            ];
        }

        return $series;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{total: ?float, previous: ?float, delta: ?float}
     */
    private static function rank_math_summary_metric(array $summary, string $key, bool $difference_is_delta = false): array
    {
        $raw        = self::as_array($summary[$key] ?? []);
        $total      = self::numeric($raw['total'] ?? null);
        $previous   = self::numeric($raw['previous'] ?? null);
        $difference = self::numeric($raw['difference'] ?? null);

        if ($difference_is_delta && null !== $difference) {
            $delta = $difference;
        } elseif (null !== $total && null !== $previous && $previous > 0) {
            $delta = self::delta($total, $previous);
        } else {
            $delta = null;
        }

        return ['total' => $total, 'previous' => $previous, 'delta' => $delta];
    }

    /**
     * Fetch and normalize Rank Math landing-page rows.
     *
     * @param object $stats Rank Math Stats singleton.
     * @return array<int, array<string, mixed>>
     */
    private static function rank_math_rows($stats, string $order_by, string $order, int $limit, bool $only_changed = false): array
    {
        $raw_rows = $stats->get_analytics_data([
            'dimension' => 'page',
            'orderBy'   => $order_by,
            'order'     => $order,
            'objects'   => true,
            'pageview'  => true,
            'offset'    => 0,
            'perpage'   => $limit,
        ]);

        if (!is_array($raw_rows)) {
            return [];
        }

        $rows = [];
        foreach ($raw_rows as $row_key => $raw_row) {
            $row = self::as_array($raw_row);
            $url = (string) ($row['page'] ?? (is_string($row_key) ? $row_key : ''));
            if ('' === $url || 'response' === $row_key) {
                continue;
            }

            $click_change = self::rank_math_row_value($row, 'clicks', 'difference');
            if ($only_changed && (null === $click_change || 0.0 === $click_change)) {
                continue;
            }

            $title = trim(wp_strip_all_tags((string) ($row['title'] ?? '')));
            if ('' === $title) {
                $path  = (string) wp_parse_url($url, PHP_URL_PATH);
                $title = '/' !== $path && '' !== $path ? rawurldecode(trim($path, '/')) : $url;
            }

            $rows[] = [
                'title'       => $title,
                'url'         => esc_url_raw($url),
                'pageviews'   => self::rank_math_row_value($row, 'pageviews'),
                'clicks'      => self::rank_math_row_value($row, 'clicks'),
                'impressions' => self::rank_math_row_value($row, 'impressions'),
                'ctr'         => self::rank_math_row_value($row, 'ctr'),
                'change'      => $click_change,
            ];
        }

        return array_slice($rows, 0, $limit);
    }

    /**
     * Read a scalar or a {total,difference} Rank Math metric from a row.
     *
     * @param array<string, mixed> $row
     */
    private static function rank_math_row_value(array $row, string $key, string $part = 'total'): ?float
    {
        if (!array_key_exists($key, $row)) {
            return null;
        }

        $metric = $row[$key];
        if (is_array($metric) || is_object($metric)) {
            $metric = self::as_array($metric);
            return self::numeric($metric[$part] ?? null);
        }

        return 'total' === $part ? self::numeric($metric) : null;
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
