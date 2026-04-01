<?php
if (!defined('ABSPATH')) {
    exit;
}

class Tax_REST_API
{
    const ROUTE_NAMESPACE = 'ffl-tax/v1';

    public static function register_routes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/quote', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_quote'],
            'permission_callback' => [__CLASS__, 'check_quote_permission'],
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, '/quote/batch', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_batch_quote'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, '/coverage', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_coverage'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, '/health', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_health'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, '/datasets', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_datasets'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, '/admin/sync', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_sync'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, '/admin/audit', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_audit'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);
    }

    public static function check_quote_permission(\WP_REST_Request $request): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public static function check_admin_permission(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public static function handle_quote(\WP_REST_Request $request): \WP_REST_Response
    {
        $ip = self::get_client_ip();
        if (!self::check_rate_limit($ip, 60, 60)) {
            return new \WP_REST_Response(['error' => 'Rate limit exceeded. Max 60 requests per minute.'], 429);
        }

        $result = Tax_Quote_Engine::quote($request->get_json_params());

        return new \WP_REST_Response($result->to_array(), $result->is_success() ? 200 : 422);
    }

    public static function handle_batch_quote(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();
        $addresses = $body['addresses'] ?? [];

        if (empty($addresses) || !is_array($addresses)) {
            return new \WP_REST_Response(['error' => 'Provide an "addresses" array.'], 400);
        }

        $results = Tax_Quote_Engine::quote_batch($addresses);

        return new \WP_REST_Response([
            'count' => count($results),
            'results' => array_map(static function ($r) { return $r->to_array(); }, $results),
        ], 200);
    }

    public static function handle_coverage(): \WP_REST_Response
    {
        return new \WP_REST_Response(Tax_Coverage::get_api_response(), 200);
    }

    public static function handle_health(): \WP_REST_Response
    {
        global $wpdb;

        $ds_table = Tax_Resolver_DB::table('dataset_versions');
        $datasets = $wpdb->get_results($wpdb->prepare(
            "SELECT source_code, state_code, version_label, effective_date, loaded_at, status, freshness_policy, row_count
             FROM {$ds_table}
             WHERE status = 'active' AND source_code = %s
             ORDER BY source_code, state_code",
            Tax_Dataset_Pipeline::SHEET_SOURCE_CODE
        ), ARRAY_A) ?: [];

        foreach ($datasets as &$ds) {
            $loaded = strtotime($ds['loaded_at']);
            $age_days = round((time() - $loaded) / DAY_IN_SECONDS, 1);
            $policy = (int) ($ds['freshness_policy'] ?: 90);
            $ds['ageDays'] = $age_days;
            $ds['isFresh'] = $age_days <= $policy;
        }
        unset($ds);

        $coverage = Tax_Coverage::get_api_response();
        $resolvers = Tax_Resolver_Router::get_health();
        $audit_table = Tax_Resolver_DB::table('quotes_audit');
        $since = wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $total_queries = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$audit_table} WHERE requested_at > %s", $since));

        $stats = [
            'totalQueries24h' => $total_queries,
            'successRate24h' => null,
            'avgDurationMs24h' => null,
            'cacheHitRatio24h' => null,
        ];

        if ($total_queries > 0) {
            $success_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$audit_table} WHERE requested_at > %s AND outcome_code IN ('SUCCESS','NO_SALES_TAX')", $since));
            $stats['successRate24h'] = round($success_count / $total_queries * 100, 1);
            $stats['avgDurationMs24h'] = (int) $wpdb->get_var($wpdb->prepare("SELECT AVG(duration_ms) FROM {$audit_table} WHERE requested_at > %s", $since));
            $cache_hits = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$audit_table} WHERE requested_at > %s AND cache_hit = 1", $since));
            $stats['cacheHitRatio24h'] = round($cache_hits / $total_queries * 100, 1);
        }

        return new \WP_REST_Response([
            'status' => 'operational',
            'version' => FFLA_VERSION,
            'timestamp' => current_time('c'),
            'coverage' => $coverage['summary'],
            'datasets' => $datasets,
            'sheetSource' => class_exists('Tax_Dataset_Pipeline') ? Tax_Dataset_Pipeline::get_source_status() : null,
            'resolvers' => $resolvers,
            'stats' => $stats,
        ], 200);
    }

    public static function handle_datasets(): \WP_REST_Response
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE source_code = %s
             ORDER BY source_code, state_code, loaded_at DESC
             LIMIT 50",
            Tax_Dataset_Pipeline::SHEET_SOURCE_CODE
        ), ARRAY_A) ?: [];

        return new \WP_REST_Response(['datasets' => $rows], 200);
    }

    public static function handle_sync(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!class_exists('Tax_Dataset_Pipeline')) {
            return new \WP_REST_Response(['error' => 'Dataset pipeline not available.'], 503);
        }

        return new \WP_REST_Response([
            'message' => 'Sheet sync completed.',
            'result' => Tax_Dataset_Pipeline::sync(Tax_Dataset_Pipeline::SHEET_SOURCE_CODE),
        ], 200);
    }

    public static function handle_audit(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $limit = min(100, max(1, (int) ($request->get_param('limit') ?? 25)));
        $state = $request->get_param('state');
        $table = Tax_Resolver_DB::table('quotes_audit');

        if ($state) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT query_id, requested_at, state_code, resolver_name, source_code, outcome_code, confidence, total_rate, duration_ms, cache_hit, matched_address
                 FROM {$table}
                 WHERE state_code = %s
                 ORDER BY requested_at DESC
                 LIMIT %d",
                strtoupper($state),
                $limit
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT query_id, requested_at, state_code, resolver_name, source_code, outcome_code, confidence, total_rate, duration_ms, cache_hit, matched_address
                 FROM {$table}
                 ORDER BY requested_at DESC
                 LIMIT %d",
                $limit
            ), ARRAY_A);
        }

        return new \WP_REST_Response(['entries' => $rows ?: []], 200);
    }

    private static function check_rate_limit(string $identifier, int $max_requests, int $window_seconds): bool
    {
        $key = 'ffla_tax_rl_' . md5($identifier);
        $count = (int) get_transient($key);

        if ($count >= $max_requests) {
            return false;
        }

        set_transient($key, $count + 1, $window_seconds);
        return true;
    }

    private static function get_client_ip(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])))[0];
                return trim($ip);
            }
        }

        return '0.0.0.0';
    }
}
