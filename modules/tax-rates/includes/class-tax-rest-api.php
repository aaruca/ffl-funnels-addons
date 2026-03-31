<?php
/**
 * Tax REST API — WP REST API endpoints.
 *
 * Exposes the Tax Address Resolver as WP REST API endpoints:
 *   POST /ffl-tax/v1/quote        — single address quote
 *   POST /ffl-tax/v1/quote/batch  — batch quotes (admin only)
 *   GET  /ffl-tax/v1/coverage     — coverage matrix
 *   GET  /ffl-tax/v1/health       — service health
 *   GET  /ffl-tax/v1/datasets     — active dataset versions (admin)
 *   POST /ffl-tax/v1/admin/sync   — trigger dataset sync (admin)
 *   POST /ffl-tax/v1/admin/wc-sync — sync rates to WooCommerce (admin)
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_REST_API
{
    const ROUTE_NAMESPACE = 'ffl-tax/v1';

    /**
     * Register all REST routes.
     */
    public static function register_routes(): void
    {
        // POST /quote — single address quote.
        register_rest_route(self::ROUTE_NAMESPACE, '/quote', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_quote'],
            'permission_callback' => [__CLASS__, 'check_quote_permission'],
        ]);

        // POST /quote/batch — batch quotes (admin only).
        register_rest_route(self::ROUTE_NAMESPACE, '/quote/batch', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_batch_quote'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // GET /coverage — coverage matrix (public).
        register_rest_route(self::ROUTE_NAMESPACE, '/coverage', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_coverage'],
            'permission_callback' => '__return_true',
        ]);

        // GET /health — service health (public).
        register_rest_route(self::ROUTE_NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_health'],
            'permission_callback' => '__return_true',
        ]);

        // GET /datasets — active dataset versions (admin).
        register_rest_route(self::ROUTE_NAMESPACE, '/datasets', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_datasets'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // POST /admin/sync — trigger dataset sync (admin).
        register_rest_route(self::ROUTE_NAMESPACE, '/admin/sync', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_sync'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // POST /admin/wc-sync — sync to WooCommerce (admin).
        register_rest_route(self::ROUTE_NAMESPACE, '/admin/wc-sync', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle_wc_sync'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);

        // GET /admin/audit — recent audit entries (admin).
        register_rest_route(self::ROUTE_NAMESPACE, '/admin/audit', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handle_audit'],
            'permission_callback' => [__CLASS__, 'check_admin_permission'],
        ]);
    }

    /* ── Permission Callbacks ─────────────────────────────────────── */

    /**
     * Check quote endpoint permission.
     * For V1: WP nonce (logged-in) + optional API key.
     */
    public static function check_quote_permission(\WP_REST_Request $request): bool
    {
        // Allow logged-in users with manage_woocommerce.
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        $settings = get_option('ffla_tax_resolver_settings', []);
        if (empty($settings['api_key_enabled'])) {
            return false;
        }

        // Check API key header.
        $api_key = $request->get_header('X-Tax-API-Key');
        if ($api_key) {
            $stored_key = get_option('ffla_tax_api_key', '');
            if (!empty($stored_key) && hash_equals($stored_key, $api_key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin-only endpoints.
     */
    public static function check_admin_permission(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    /* ── Endpoint Handlers ────────────────────────────────────────── */

    /**
     * POST /quote — single address tax quote.
     */
    public static function handle_quote(\WP_REST_Request $request): \WP_REST_Response
    {
        $input = $request->get_json_params();

        // Rate limiting: 60 requests per minute per IP.
        $ip = self::get_client_ip();
        if (!self::check_rate_limit($ip, 60, 60)) {
            return new \WP_REST_Response([
                'error' => 'Rate limit exceeded. Max 60 requests per minute.',
            ], 429);
        }

        $result = Tax_Quote_Engine::quote($input);

        $status = $result->is_success() ? 200 : 422;

        return new \WP_REST_Response($result->to_array(), $status);
    }

    /**
     * POST /quote/batch — batch tax quotes.
     */
    public static function handle_batch_quote(\WP_REST_Request $request): \WP_REST_Response
    {
        $body      = $request->get_json_params();
        $addresses = $body['addresses'] ?? [];

        if (empty($addresses) || !is_array($addresses)) {
            return new \WP_REST_Response([
                'error' => 'Provide an "addresses" array.',
            ], 400);
        }

        $results = Tax_Quote_Engine::quote_batch($addresses);

        return new \WP_REST_Response([
            'count'   => count($results),
            'results' => array_map(function ($r) { return $r->to_array(); }, $results),
        ], 200);
    }

    /**
     * GET /coverage — coverage matrix.
     */
    public static function handle_coverage(): \WP_REST_Response
    {
        return new \WP_REST_Response(Tax_Coverage::get_api_response(), 200);
    }

    /**
     * GET /health — service health.
     */
    public static function handle_health(): \WP_REST_Response
    {
        global $wpdb;

        $ds_table = Tax_Resolver_DB::table('dataset_versions');
        $datasets = $wpdb->get_results(
            "SELECT source_code, state_code, version_label, effective_date, loaded_at, status,
                    freshness_policy, row_count
             FROM {$ds_table}
             WHERE status = 'active'
             ORDER BY source_code, state_code",
            ARRAY_A
        ) ?: [];

        // Calculate freshness for each active dataset.
        foreach ($datasets as &$ds) {
            $loaded    = strtotime($ds['loaded_at']);
            $age_days  = round((time() - $loaded) / DAY_IN_SECONDS, 1);
            $policy    = (int) ($ds['freshness_policy'] ?: 90);
            $ds['ageDays']  = $age_days;
            $ds['isFresh']  = $age_days <= $policy;
        }

        // Coverage summary.
        $coverage = Tax_Coverage::get_api_response();

        // Resolver health.
        $resolvers = Tax_Resolver_Router::get_health();

        // Audit stats (last 24h).
        $audit_table = Tax_Resolver_DB::table('quotes_audit');
        $stats = [
            'totalQueries24h'   => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$audit_table} WHERE requested_at > %s",
                wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
            )),
            'successRate24h'    => null,
            'avgDurationMs24h'  => null,
            'cacheHitRatio24h'  => null,
        ];

        if ($stats['totalQueries24h'] > 0) {
            $success_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$audit_table}
                 WHERE requested_at > %s AND outcome_code IN ('SUCCESS','NO_SALES_TAX')",
                wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
            ));
            $stats['successRate24h'] = round($success_count / $stats['totalQueries24h'] * 100, 1);

            $stats['avgDurationMs24h'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(duration_ms) FROM {$audit_table} WHERE requested_at > %s",
                wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
            ));

            $cache_hits = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$audit_table}
                 WHERE requested_at > %s AND cache_hit = 1",
                wp_date('Y-m-d H:i:s', time() - DAY_IN_SECONDS)
            ));
            $stats['cacheHitRatio24h'] = round($cache_hits / $stats['totalQueries24h'] * 100, 1);
        }

        return new \WP_REST_Response([
            'status'     => 'operational',
            'version'    => FFLA_VERSION,
            'timestamp'  => current_time('c'),
            'coverage'   => $coverage['summary'],
            'datasets'   => $datasets,
            'resolvers'  => $resolvers,
            'stats'      => $stats,
        ], 200);
    }

    /**
     * GET /datasets — active dataset versions.
     */
    public static function handle_datasets(): \WP_REST_Response
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');
        $rows  = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY source_code, state_code, loaded_at DESC LIMIT 50",
            ARRAY_A
        ) ?: [];

        return new \WP_REST_Response(['datasets' => $rows], 200);
    }

    /**
     * POST /admin/sync — trigger dataset sync.
     */
    public static function handle_sync(\WP_REST_Request $request): \WP_REST_Response
    {
        $source = $request->get_param('source') ?? 'all';

        // Trigger sync via pipeline.
        if (class_exists('Tax_Dataset_Pipeline')) {
            $result = Tax_Dataset_Pipeline::sync($source);
            return new \WP_REST_Response([
                'message' => 'Sync completed.',
                'result'  => $result,
            ], 200);
        }

        return new \WP_REST_Response([
            'error' => 'Dataset pipeline not available.',
        ], 503);
    }

    /**
     * POST /admin/wc-sync — sync rates to WooCommerce.
     */
    public static function handle_wc_sync(\WP_REST_Request $request): \WP_REST_Response
    {
        $state = $request->get_param('state');

        if ($state) {
            $count = Tax_Quote_Engine::sync_to_woocommerce(strtoupper($state));
            return new \WP_REST_Response([
                'message' => "Synced {$count} rates to WooCommerce for {$state}.",
                'state'   => $state,
                'count'   => $count,
            ], 200);
        }

        // Sync all supported states.
        $results = Tax_Quote_Engine::sync_all_to_woocommerce();

        return new \WP_REST_Response([
            'message' => 'Synced all supported states to WooCommerce.',
            'results' => $results,
            'total'   => array_sum($results),
        ], 200);
    }

    /**
     * GET /admin/audit — recent audit entries.
     */
    public static function handle_audit(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $limit = min(100, max(1, (int) ($request->get_param('limit') ?? 25)));
        $state = $request->get_param('state');

        $table = Tax_Resolver_DB::table('quotes_audit');

        if ($state) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT query_id, requested_at, state_code, resolver_name, source_code,
                        outcome_code, confidence, total_rate, duration_ms, cache_hit, matched_address
                 FROM {$table}
                 WHERE state_code = %s
                 ORDER BY requested_at DESC LIMIT %d",
                strtoupper($state),
                $limit
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT query_id, requested_at, state_code, resolver_name, source_code,
                        outcome_code, confidence, total_rate, duration_ms, cache_hit, matched_address
                 FROM {$table}
                 ORDER BY requested_at DESC LIMIT %d",
                $limit
            ), ARRAY_A);
        }

        return new \WP_REST_Response(['entries' => $rows ?: []], 200);
    }

    /* ── Rate Limiting ────────────────────────────────────────────── */

    /**
     * Simple transient-based rate limiter.
     */
    private static function check_rate_limit(string $identifier, int $max_requests, int $window_seconds): bool
    {
        $key   = 'ffla_tax_rl_' . md5($identifier);
        $count = (int) get_transient($key);

        if ($count >= $max_requests) {
            return false;
        }

        set_transient($key, $count + 1, $window_seconds);
        return true;
    }

    /**
     * Get client IP address.
     */
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
