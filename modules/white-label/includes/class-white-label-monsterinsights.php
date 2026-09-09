<?php
/** MonsterInsights report adapter for the client dashboard (no tracking hooks). */
if (!defined('ABSPATH')) {
    exit;
}

class White_Label_MonsterInsights
{
    const CACHE_VERSION = 'mi-dashboard-v1';

    public static function get(int $days, bool $force = false): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        try {
            $blocked = self::access();
            if ($blocked !== null) {
                return $blocked;
            }
            $mi = MonsterInsights();
            $pro = monsterinsights_is_pro_version();
            if (!$pro && $days !== 30) {
                return self::state('unavailable', __('Select 30 days, or use MonsterInsights Pro for other date ranges.', 'ffl-funnels-addons'));
            }
            $dates = self::dates($days);
            $ecommerce = $pro && $mi->license->license_can('pro') && class_exists('MonsterInsights_eCommerce');
            // User-scoped cache: settings/report links never leak between roles.
            // Auth/permissions/license are checked ABOVE, even on a cache hit.
            $identity = [self::CACHE_VERSION, get_current_blog_id(), get_current_user_id(), MONSTERINSIGHTS_VERSION,
                $mi->auth->get_viewid(), method_exists($mi->auth, 'get_network_viewid') ? $mi->auth->get_network_viewid() : '',
                function_exists('monsterinsights_get_v4_id') ? monsterinsights_get_v4_id() : '',
                $dates, $pro, $ecommerce, current_user_can('monsterinsights_save_settings')];
            $key = 'ffla_wl_mi_' . md5(wp_json_encode($identity));
            $cached = get_transient($key);
            // Refresh bypasses our normal cache, but not a short anti-stampede
            // interval or MonsterInsights' own cache. It never deletes MI data.
            if (is_array($cached) && (!$force || time() - ($cached['fetched_at'] ?? 0) < 30)) {
                return $cached;
            }
            $modern = version_compare(MONSTERINSIGHTS_VERSION, '11.2.0', '>=')
                && class_exists('MonsterInsights_API_Reports') && $mi->auth->is_authed();
            try {
                $data = $modern ? self::modern($dates, $ecommerce) : self::legacy($dates, $ecommerce);
            } catch (Throwable $e) {
                $data = self::state('unavailable', __('MonsterInsights reports could not be loaded. Check its connection and try again.', 'ffl-funnels-addons'));
            }
            $data['source'] = 'google'; // Retain saved tab preferences and AJAX contracts.
            $data['provider'] = 'monsterinsights';
            $data['range'] = $days;
            $data['dates'] = $dates;
            $data['fetched_at'] = time();
            $data['allowed_ranges'] = $pro ? [7, 30, 90] : [30];
            $data['report_url'] = admin_url('admin.php?page=monsterinsights_reports');
            $data['note'] = __('Google Analytics data supplied through MonsterInsights. These are all-channel metrics, not Search Console clicks. Analytics can lag and differ from WooCommerce order totals because of consent, attribution and reporting rules.', 'ffl-funnels-addons');
            set_transient($key, $data, $data['status'] === 'unavailable' ? 30 : 600);
            return $data;
        } catch (Throwable $e) {
            // Provider exception text may contain URLs/tokens. Do not serialize it.
            return self::state('unavailable', __('MonsterInsights reports could not be loaded. Check its connection and try again.', 'ffl-funnels-addons'));
        }
    }

    private static function access(): ?array
    {
        if (!function_exists('MonsterInsights') || !function_exists('monsterinsights_is_pro_version') || !defined('MONSTERINSIGHTS_VERSION')) {
            return self::state('unavailable', __('Activate and connect MonsterInsights to display Google Analytics here.', 'ffl-funnels-addons'));
        }
        if (!current_user_can('monsterinsights_view_dashboard')) {
            return self::state('unavailable', __('Your role does not have permission to view MonsterInsights reports. Ask an administrator to enable report access in MonsterInsights.', 'ffl-funnels-addons'));
        }
        if (monsterinsights_get_option('dashboard_disabled', false)) {
            return self::state('unavailable', __('Reporting is disabled in MonsterInsights settings.', 'ffl-funnels-addons'));
        }
        $mi = MonsterInsights();
        if (!is_object($mi) || !isset($mi->auth) || !($mi->auth->is_authed() || $mi->auth->is_network_authed())) {
            return self::state('unavailable', __('Connect Google Analytics in MonsterInsights to populate this dashboard.', 'ffl-funnels-addons'));
        }
        if (monsterinsights_is_pro_version() && (!isset($mi->license) || !$mi->license->has_license() || $mi->license->license_has_error())) {
            return self::state('unavailable', __('Resolve the MonsterInsights license issue to load reports.', 'ffl-funnels-addons'));
        }
        return null;
    }

    public static function dates(int $days): array
    {
        $end = current_datetime()->setTime(0, 0)->modify('-1 day');
        $start = $end->modify('-' . ($days - 1) . ' days');
        return [
            'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
            'compare_start' => $start->modify('-' . $days . ' days')->format('Y-m-d'),
            'compare_end' => $start->modify('-1 day')->format('Y-m-d'),
        ];
    }

    private static function state(string $status, string $message): array
    {
        return ['source' => 'google', 'provider' => 'monsterinsights', 'status' => $status, 'message' => $message,
            'action_url' => current_user_can('monsterinsights_save_settings') ? admin_url('admin.php?page=monsterinsights_settings') : '',
            'metrics' => [], 'series' => [], 'tables' => []];
    }

    /** Same reporting/query contract used by MonsterInsights 11.2's report UI. */
    public static function queries(array $dates, bool $ecommerce = false): array
    {
        $series = [
            'id' => $ecommerce ? 'ecommerce_key_metrics' : 'overview', 'dimensions' => ['date'],
            'metrics' => $ecommerce ? ['sessions', 'ecommercePurchases', 'totalRevenue'] : ['sessions', 'screenPageViews', 'engagedSessions', 'newUsers'],
            'groupBy' => 'date', 'limit' => 200, 'compare' => true,
            'compare_start' => $dates['compare_start'], 'compare_end' => $dates['compare_end'],
        ];
        $queries = [$series];
        if ($ecommerce) {
            $queries[] = ['id' => 'products_table', 'dimensions' => ['itemName'], 'metrics' => ['itemsPurchased', 'itemRevenue'], 'orderBy' => [['field' => 'itemsPurchased', 'desc' => true]], 'limit' => 10];
        } else {
            $queries[] = ['id' => 'pages', 'dimensions' => ['pagePathPlusQueryString'], 'metrics' => ['screenPageViews'], 'orderBy' => [['field' => 'screenPageViews', 'desc' => true]], 'limit' => 10];
            $queries[] = ['id' => 'sources', 'dimensions' => ['sessionSourceMedium'], 'metrics' => ['sessions'], 'orderBy' => [['field' => 'sessions', 'desc' => true]], 'limit' => 5];
        }
        return ['start' => $dates['start'], 'end' => $dates['end'], 'compareStart' => $dates['compare_start'], 'compareEnd' => $dates['compare_end'], 'queries' => $queries];
    }

    private static function modern(array $dates, bool $ecommerce): array
    {
        require_once __DIR__ . '/class-white-label-monsterinsights-client.php';
        $client = new White_Label_MonsterInsights_Client();
        $raw = self::unwrap($client->query(self::queries($dates)));
        $data = self::normalize_modern($raw, $dates);
        $data['ecommerce'] = self::state('unavailable', __('eCommerce reporting requires an eligible MonsterInsights license and its active eCommerce Addon.', 'ffl-funnels-addons'));
        if ($ecommerce) {
            // Optional commerce failure must not hide successful traffic data.
            try {
                $commerce = self::unwrap($client->query(self::queries($dates, true)));
                $data['ecommerce'] = self::normalize_commerce($commerce, $dates);
            } catch (Throwable $e) {
                $data['ecommerce'] = self::state('unavailable', __('MonsterInsights eCommerce data is temporarily unavailable.', 'ffl-funnels-addons'));
            }
        }
        return $data;
    }

    private static function unwrap($response): array
    {
        if (!is_array($response) || is_wp_error($response) || !empty($response['error']) || (isset($response['success']) && !$response['success'])) {
            throw new RuntimeException('Provider response unavailable');
        }
        for ($i = 0; $i < 3; $i++) {
            if (!empty($response['error']) || (isset($response['success']) && !$response['success']) || !empty($response['sample']) || !empty($response['is_sample']) || !empty($response['show_chart_overlay'])) {
                throw new RuntimeException('Provider data is not a live report');
            }
            if ($i === 2 || !isset($response['data']) || !is_array($response['data'])) { break; }
            $response = $response['data'];
        }
        return $response;
    }

    /** Parse the scalar and [previous,current] cell formats used by MI. */
    public static function cells(array $row, int $width): array
    {
        $cells = isset($row['m']) && is_array($row['m']) ? $row['m'] : [];
        if (count($cells) === 1 && is_array($cells[0]) && ($width > 1 || is_array($cells[0][0] ?? null))) {
            $cells = $cells[0];
        }
        // Single non-comparison metric is also wrapped once: m: [["123"]].
        if ($width === 1 && count($cells) === 1 && is_array($cells[0]) && count($cells[0]) === 1) {
            $cells = $cells[0];
        }
        $current = $previous = [];
        for ($i = 0; $i < $width; $i++) {
            $cell = $cells[$i] ?? null;
            $current[] = self::number(is_array($cell) ? ($cell[1] ?? null) : $cell);
            $previous[] = is_array($cell) ? self::number($cell[0] ?? null) : null;
        }
        return [$current, $previous];
    }

    private static function rows(array $data, string $key): array
    {
        $report = $data[$key] ?? null;
        if (!is_array($report) || !empty($report['error']) || !empty($report['sample']) || !empty($report['is_sample']) || !empty($report['show_chart_overlay']) || (isset($report['success']) && !$report['success']) || !isset($report['rows']) || !is_array($report['rows'])) {
            throw new RuntimeException('Missing report rows');
        }
        return $report['rows'];
    }

    private static function table_rows(array $data, string $key): array
    {
        try { return array_slice(self::rows($data, $key), 0, 10); }
        catch (Throwable $e) { return []; }
    }

    private static function row_date(array $row, array $dates): ?string
    {
        $dimension = $row['d'][0] ?? '';
        if (!is_scalar($dimension)) { return null; }
        $digits = preg_replace('/[^0-9]/', '', (string) $dimension);
        if (strlen($digits) !== 8 || !checkdate((int) substr($digits, 4, 2), (int) substr($digits, 6, 2), (int) substr($digits, 0, 4))) { return null; }
        $date = substr($digits, 0, 4) . '-' . substr($digits, 4, 2) . '-' . substr($digits, 6, 2);
        return $date >= $dates['start'] && $date <= $dates['end'] ? $date : null;
    }

    public static function normalize_modern(array $raw, array $dates): array
    {
        $rows = self::rows($raw, 'overview');
        $totals = $previous = array_fill(0, 4, null);
        $missing = $missing_prior = array_fill(0, 4, false);
        $series = [];
        foreach (array_slice($rows, 0, 200) as $row) {
            if (!is_array($row)) { continue; }
            $date = self::row_date($row, $dates);
            if ($date === null) { continue; }
            [$values, $prior] = self::cells($row, 4);
            foreach ($values as $i => $value) {
                if ($value !== null) { $totals[$i] = ($totals[$i] ?? 0) + $value; }
                if ($prior[$i] !== null) { $previous[$i] = ($previous[$i] ?? 0) + $prior[$i]; }
                $missing[$i] = $missing[$i] || $value === null;
                $missing_prior[$i] = $missing_prior[$i] || $prior[$i] === null;
            }
            $series[] = ['label' => $date, 'sessions' => $values[0], 'pageviews' => $values[1]];
        }
        usort($series, static function ($a, $b) { return strcmp($a['label'], $b['label']); });
        foreach ($totals as $i => $value) {
            if ($missing[$i]) { $totals[$i] = null; }
            if ($missing_prior[$i]) { $previous[$i] = null; }
        }
        $metrics = [
            self::metric(__('Sessions', 'ffl-funnels-addons'), $totals[0], $previous[0]),
            self::metric(__('Pageviews', 'ffl-funnels-addons'), $totals[1], $previous[1]),
            self::metric(__('New users', 'ffl-funnels-addons'), $totals[3], $previous[3]),
            self::metric(__('Engagement rate', 'ffl-funnels-addons'), self::ratio($totals[2], $totals[0]), self::ratio($previous[2], $previous[0]), 'percent'),
        ];
        $tables = [];
        foreach ([['pages', __('Top pages', 'ffl-funnels-addons'), __('Page', 'ffl-funnels-addons'), __('Pageviews', 'ffl-funnels-addons')], ['sources', __('Traffic sources', 'ffl-funnels-addons'), __('Source / medium', 'ffl-funnels-addons'), __('Sessions', 'ffl-funnels-addons')]] as $spec) {
            $table_rows = [];
            foreach (self::table_rows($raw, $spec[0]) as $row) {
                if (!is_array($row)) { continue; }
                [$values] = self::cells($row, 1);
                $table_rows[] = [self::text($row['d'][0] ?? ''), $values[0]];
            }
            $tables[] = ['title' => $spec[1], 'columns' => [$spec[2], $spec[3]], 'formats' => ['text', 'number'], 'rows' => $table_rows];
        }
        return ['status' => $totals[0] !== null ? 'ready' : 'no_data', 'message' => __('MonsterInsights has no traffic data for this period yet.', 'ffl-funnels-addons'), 'metrics' => $metrics, 'series' => $series, 'tables' => $tables];
    }

    public static function normalize_commerce(array $raw, array $dates): array
    {
        $rows = self::rows($raw, 'ecommerce_key_metrics');
        $totals = $previous = array_fill(0, 3, null);
        $missing = $missing_prior = array_fill(0, 3, false);
        foreach (array_slice($rows, 0, 200) as $row) {
            if (!is_array($row) || self::row_date($row, $dates) === null) { continue; }
            [$values, $prior] = self::cells($row, 3);
            foreach ($values as $i => $value) {
                if ($value !== null) { $totals[$i] = ($totals[$i] ?? 0) + $value; }
                if ($prior[$i] !== null) { $previous[$i] = ($previous[$i] ?? 0) + $prior[$i]; }
                $missing[$i] = $missing[$i] || $value === null;
                $missing_prior[$i] = $missing_prior[$i] || $prior[$i] === null;
            }
        }
        foreach ($totals as $i => $value) {
            if ($missing[$i]) { $totals[$i] = null; }
            if ($missing_prior[$i]) { $previous[$i] = null; }
        }
        // Do not guess GA's property currency from the WooCommerce store currency.
        $currency = isset($raw['currency']) && preg_match('/^[A-Z]{3}$/', (string) $raw['currency']) ? $raw['currency'] : '';
        $metrics = [
            self::metric(__('Purchases', 'ffl-funnels-addons'), $totals[1], $previous[1]),
            self::metric(__('Analytics revenue', 'ffl-funnels-addons'), $totals[2], $previous[2], 'decimal'),
            self::metric(__('Average order value', 'ffl-funnels-addons'), self::divide($totals[2], $totals[1]), self::divide($previous[2], $previous[1]), 'decimal'),
            self::metric(__('Purchases / sessions', 'ffl-funnels-addons'), self::ratio($totals[1], $totals[0]), self::ratio($previous[1], $previous[0]), 'percent'),
        ];
        $table_rows = [];
        foreach (self::table_rows($raw, 'products_table') as $row) {
            if (!is_array($row)) { continue; }
            [$values] = self::cells($row, 2);
            $table_rows[] = [self::text($row['d'][0] ?? ''), $values[0], $values[1]];
        }
        return ['status' => $totals[1] !== null ? 'ready' : 'no_data', 'message' => __('No eCommerce data was returned for this period.', 'ffl-funnels-addons'), 'currency' => $currency,
            'metrics' => $metrics, 'tables' => [['title' => __('Top products', 'ffl-funnels-addons'), 'columns' => [__('Product', 'ffl-funnels-addons'), __('Quantity', 'ffl-funnels-addons'), __('Analytics revenue', 'ffl-funnels-addons')], 'formats' => ['text', 'number', 'decimal'], 'rows' => $table_rows]]];
    }

    /** Older MI releases retain the public registered-report interface. */
    private static function legacy(array $dates, bool $ecommerce): array
    {
        $manager = MonsterInsights()->reporting ?? null;
        if (!is_object($manager) || !is_callable([$manager, 'get_report'])) {
            throw new RuntimeException('Reports not initialized');
        }
        $report = $manager->get_report('overview');
        if (!is_object($report) || !is_callable([$report, 'get_data'])) {
            throw new RuntimeException('Overview unavailable');
        }
        $args = ['start' => $dates['start'], 'end' => $dates['end'], 'compare_start' => $dates['compare_start'], 'compare_end' => $dates['compare_end'], 'included_metrics' => 'sessions,pageviews'];
        $raw = self::unwrap($report->get_data($args));
        $data = self::normalize_legacy($raw, $dates);
        // Do not infer commerce totals from another report or WooCommerce.
        $data['ecommerce'] = self::state('unavailable', __('Open the MonsterInsights eCommerce report for commerce details on this version.', 'ffl-funnels-addons'));
        return $data;
    }

    public static function normalize_legacy(array $raw, array $dates): array
    {
        if (!empty($raw['show_chart_overlay'])) {
            return self::state('no_data', __('MonsterInsights is still collecting data. Demo charts are not displayed here.', 'ffl-funnels-addons'));
        }
        $metrics = [];
        foreach (['sessions' => __('Sessions', 'ffl-funnels-addons'), 'pageviews' => __('Pageviews', 'ffl-funnels-addons'), 'bounce' => __('Bounce rate', 'ffl-funnels-addons')] as $key => $label) {
            $value = self::number($raw['infobox'][$key]['value'] ?? null);
            $metrics[] = self::metric($label, $value, null, $key === 'bounce' ? 'percent' : 'number');
        }
        $series = [];
        $graph = $raw['overviewgraph'] ?? [];
        $sessions = (array) ($graph['sessions']['datapoints'] ?? []);
        $views = (array) ($graph['pageviews']['datapoints'] ?? []);
        // Date each point explicitly from the requested range, not MM/DD locale labels.
        $start = new DateTimeImmutable($dates['start'], wp_timezone());
        for ($i = 0; $i < min(90, max(count($sessions), count($views))); $i++) {
            $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
            if ($date > $dates['end']) { break; }
            $series[] = ['label' => $date, 'sessions' => self::number($sessions[$i] ?? null), 'pageviews' => self::number($views[$i] ?? null)];
        }
        $rows = [];
        foreach (array_slice((array) ($raw['toppages'] ?? []), 0, 10) as $row) {
            if (is_array($row)) { $rows[] = [self::text($row['title'] ?? ''), self::number($row['value'] ?? null)]; }
        }
        return ['status' => $metrics[0]['value'] !== null ? 'ready' : 'no_data', 'message' => __('MonsterInsights has no traffic data for this period yet.', 'ffl-funnels-addons'), 'metrics' => $metrics, 'series' => $series,
            'tables' => [['title' => __('Top pages', 'ffl-funnels-addons'), 'columns' => [__('Page', 'ffl-funnels-addons'), __('Pageviews', 'ffl-funnels-addons')], 'formats' => ['text', 'number'], 'rows' => $rows]]];
    }

    private static function metric(string $label, ?float $value, ?float $previous, string $format = 'number'): array
    {
        $delta = $value !== null && $previous !== null && $previous > 0 ? ($value - $previous) / $previous * 100 : null;
        if ($format === 'percent') { $delta = $value !== null && $previous !== null ? $value - $previous : null; }
        return ['label' => $label, 'value' => $value, 'delta' => $delta, 'format' => $format, 'deltaFormat' => $format === 'percent' ? 'points' : 'percent', 'lowerIsBetter' => $label === __('Bounce rate', 'ffl-funnels-addons')];
    }

    private static function number($value): ?float
    {
        if (is_string($value)) { $value = str_replace([',', '%'], '', trim($value)); }
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private static function divide(?float $numerator, ?float $denominator): ?float
    {
        return $numerator !== null && $denominator !== null && $denominator > 0 ? $numerator / $denominator : null;
    }

    private static function ratio(?float $numerator, ?float $denominator): ?float
    {
        $value = self::divide($numerator, $denominator);
        return $value === null ? null : $value * 100;
    }

    private static function text($value): string
    {
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }
}
