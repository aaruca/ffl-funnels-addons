<?php
/**
 * Tax Quote Engine - Main orchestrator.
 *
 * Runtime flow:
 *   1. Validate input
 *   2. Normalize address
 *   3. Check cache
 *   4. Check coverage
 *   5. Route to the state resolver
 *   6. Geocode only when that resolver requires it
 *   7. Execute resolver
 *   8. Normalize result
 *   9. Cache and audit
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Quote_Engine
{
    /** @var int Cache TTL in seconds (default 24 hours). */
    private static $cache_ttl = 86400;

    private const CACHE_SCHEMA_VERSION = '2026-04-01-sheet-dataset-v1';

    /**
     * Execute a tax quote for an address.
     *
     * @param  array $input Raw address input.
     * @return Tax_Quote_Result
     */
    public static function quote(array $input): Tax_Quote_Result
    {
        $start_time = microtime(true);

        if (empty($input) || (!isset($input['address']) && !isset($input['street']))) {
            return self::audit(
                Tax_Quote_Result::validation_error($input, ['No address provided.']),
                $start_time
            );
        }

        $normalized = Tax_Address_Normalizer::normalize($input);

        if (!$normalized['valid']) {
            return self::audit(
                Tax_Quote_Result::validation_error($input, $normalized['errors']),
                $start_time
            );
        }

        $state_code = $normalized['state'];

        if (!Tax_Coverage::is_enabled_for_store($state_code)) {
            $result = Tax_Quote_Result::disabled_state($state_code, $input, $normalized);
            $result->trace['durationMs'] = self::elapsed($start_time);
            return self::audit($result, $start_time);
        }

        $cached = self::get_cached($normalized['key']);
        if ($cached !== null) {
            $cached->queryId = wp_generate_uuid4();
            $cached->inputAddress = $input;
            $cached->normalizedAddress = $normalized;
            $cached->trace['cacheHit'] = true;
            $cached->trace['durationMs'] = self::elapsed($start_time);

            return self::audit($cached, $start_time);
        }

        if (!Tax_Coverage::is_supported($state_code)) {
            $result = Tax_Quote_Result::unsupported($state_code, $input, $normalized);
            $result->trace['durationMs'] = self::elapsed($start_time);
            return self::audit($result, $start_time);
        }

        $resolver = Tax_Resolver_Router::route($state_code);
        if (!$resolver) {
            $result = Tax_Quote_Result::unsupported($state_code, $input, $normalized);
            $result->trace['durationMs'] = self::elapsed($start_time);
            return self::audit($result, $start_time);
        }

        $geocode = $resolver->requires_geocode()
            ? Tax_Geocoder::geocode($normalized)
            : Tax_Geocoder::empty_result();

        try {
            $result = $resolver->resolve($normalized, $geocode);
        } catch (\Throwable $e) {
            $result = new Tax_Quote_Result();
            $result->inputAddress = $input;
            $result->normalizedAddress = $normalized;
            $result->state = $state_code;
            $result->set_error(
                Tax_Quote_Result::OUTCOME_INTERNAL_ERROR,
                'Resolver error: ' . $e->getMessage()
            );
        }

        $result->trace['durationMs'] = self::elapsed($start_time);

        if ($result->is_success() && !empty($result->breakdown)) {
            $sum = 0.0;
            foreach ($result->breakdown as $item) {
                $sum += $item['rate'];
            }

            $sum = round($sum, 6);
            if (abs($sum - (float) $result->totalRate) > 0.000001) {
                $result->totalRate = $sum;
            }
        }

        if ($result->is_success()) {
            self::cache_result($normalized['key'], $state_code, $result);
        }

        return self::audit($result, $start_time);
    }

    /**
     * Execute a batch of tax quotes.
     *
     * @param  array[] $addresses Array of address inputs.
     * @param  int     $max       Maximum addresses per batch.
     * @return Tax_Quote_Result[]
     */
    public static function quote_batch(array $addresses, int $max = 25): array
    {
        $results = [];

        foreach (array_slice($addresses, 0, $max) as $input) {
            $results[] = self::quote($input);
        }

        return $results;
    }

    /* Cache Methods */

    /**
     * Get cached quote result.
     */
    private static function get_cached(string $cache_key): ?Tax_Quote_Result
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('address_cache');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE cache_key = %s AND expires_at > %s",
            $cache_key,
            current_time('mysql')
        ), ARRAY_A);

        if (!$row || empty($row['quote_json'])) {
            return null;
        }

        $data = json_decode($row['quote_json'], true);
        if (!$data) {
            return null;
        }

        $result = new Tax_Quote_Result();
        foreach ($data as $key => $value) {
            if (property_exists($result, $key)) {
                $result->$key = $value;
            }
        }

        $cached_version = $result->trace['cacheSchemaVersion'] ?? null;
        if ($cached_version !== self::get_cache_schema_version()) {
            return null;
        }

        return $result;
    }

    /**
     * Cache a quote result.
     */
    private static function cache_result(string $cache_key, string $state_code, Tax_Quote_Result $result): void
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('address_cache');
        $expires_at = wp_date('Y-m-d H:i:s', time() + self::$cache_ttl);
        $result->trace['cacheSchemaVersion'] = self::get_cache_schema_version();

        $wpdb->replace($table, [
            'cache_key'       => $cache_key,
            'state_code'      => $state_code,
            'normalized_json' => wp_json_encode($result->normalizedAddress),
            'quote_json'      => wp_json_encode($result->to_array()),
            'created_at'      => current_time('mysql'),
            'expires_at'      => $expires_at,
        ]);
    }

    /* Audit Methods */

    /**
     * Persist audit record and return the result.
     */
    private static function audit(Tax_Quote_Result $result, float $start_time): Tax_Quote_Result
    {
        global $wpdb;

        $result->trace['durationMs'] = self::elapsed($start_time);

        $table = Tax_Resolver_DB::table('quotes_audit');

        $wpdb->insert($table, [
            'query_id'        => $result->queryId,
            'requested_at'    => current_time('mysql'),
            'input_json'      => wp_json_encode($result->inputAddress),
            'normalized_json' => wp_json_encode($result->normalizedAddress),
            'matched_address' => $result->matchedAddress,
            'state_code'      => $result->state,
            'resolver_name'   => $result->trace['resolver'] ?? null,
            'source_code'     => $result->source,
            'source_version'  => $result->sourceVersion,
            'coverage_status' => $result->coverageStatus,
            'outcome_code'    => $result->outcomeCode,
            'confidence'      => $result->confidence,
            'total_rate'      => $result->totalRate,
            'response_json'   => wp_json_encode($result->to_array()),
            'duration_ms'     => $result->trace['durationMs'],
            'cache_hit'       => !empty($result->trace['cacheHit']) ? 1 : 0,
        ]);

        return $result;
    }

    /**
     * Calculate elapsed milliseconds.
     */
    private static function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /**
     * Set cache TTL from saved settings.
     */
    public static function set_cache_ttl(int $seconds): void
    {
        self::$cache_ttl = max(60, $seconds);
    }

    /**
     * Return the cache schema version used to invalidate stale quote payloads.
     */
    private static function get_cache_schema_version(): string
    {
        return (defined('FFLA_VERSION') ? FFLA_VERSION : 'dev') . ':' . self::CACHE_SCHEMA_VERSION;
    }
}
