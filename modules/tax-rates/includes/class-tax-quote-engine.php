<?php
/**
 * Tax Quote Engine — Main orchestrator.
 *
 * Implements the 11-step resolution logic:
 *   1. Validate input
 *   2. Normalize address
 *   3. Check cache
 *   4. Geocode with Census
 *   5. Determine state
 *   6. Check coverage
 *   7. Check dataset freshness
 *   8. Execute resolver
 *   9. Normalize result
 *  10. Persist audit
 *  11. Respond or degrade
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

    /**
     * Execute a tax quote for an address.
     *
     * @param  array $input Raw address input.
     * @return Tax_Quote_Result
     */
    public static function quote(array $input): Tax_Quote_Result
    {
        $start_time = microtime(true);

        // Step 1: Validate input.
        if (empty($input) || (!isset($input['address']) && !isset($input['street']))) {
            return self::audit(
                Tax_Quote_Result::validation_error($input, ['No address provided.']),
                $start_time
            );
        }

        // Step 2: Normalize address.
        $normalized = Tax_Address_Normalizer::normalize($input);

        if (!$normalized['valid']) {
            return self::audit(
                Tax_Quote_Result::validation_error($input, $normalized['errors']),
                $start_time
            );
        }

        $state_code = $normalized['state'];

        // Step 3: Check cache.
        $cached = self::get_cached($normalized['key']);
        if ($cached !== null) {
            // Cached payloads keep the original query UUID, but every request
            // still needs its own audit record and trace.
            $cached->queryId                = wp_generate_uuid4();
            $cached->inputAddress           = $input;
            $cached->normalizedAddress      = $normalized;
            $cached->trace['cacheHit']    = true;
            $cached->trace['durationMs']  = self::elapsed($start_time);
            return self::audit($cached, $start_time);
        }

        // Step 4: Check for no-sales-tax states early.
        if (Tax_Coverage::has_no_tax($state_code)) {
            $result = Tax_Quote_Result::no_sales_tax($state_code, $input, $normalized);
            $result->trace['durationMs'] = self::elapsed($start_time);
            self::cache_result($normalized['key'], $state_code, $result);
            return self::audit($result, $start_time);
        }

        // Step 5: Check coverage.
        if (!Tax_Coverage::is_supported($state_code)) {
            $result = Tax_Quote_Result::unsupported($state_code, $input, $normalized);
            $result->trace['durationMs'] = self::elapsed($start_time);
            return self::audit($result, $start_time);
        }

        // Step 6: Geocode with Census.
        $geocode = Tax_Geocoder::geocode($normalized);

        // Step 7: Route to resolver.
        $resolver = Tax_Resolver_Router::route($state_code);

        if (!$resolver) {
            $result = Tax_Quote_Result::unsupported($state_code, $input, $normalized);
            $result->trace['durationMs'] = self::elapsed($start_time);
            return self::audit($result, $start_time);
        }

        // Step 8: Execute resolver.
        try {
            $result = $resolver->resolve($normalized, $geocode);
        } catch (\Exception $e) {
            $result                   = new Tax_Quote_Result();
            $result->inputAddress     = $input;
            $result->normalizedAddress = $normalized;
            $result->state            = $state_code;
            $result->set_error(
                Tax_Quote_Result::OUTCOME_INTERNAL_ERROR,
                'Resolver error: ' . $e->getMessage()
            );
        }

        // Step 9: Finalize trace.
        $result->trace['durationMs'] = self::elapsed($start_time);

        // Step 10: Validate result consistency.
        if ($result->is_success() && !empty($result->breakdown)) {
            $sum = 0.0;
            foreach ($result->breakdown as $item) {
                $sum += $item['rate'];
            }
            $sum = round($sum, 6);
            if (abs($sum - $result->totalRate) > 0.000001) {
                $result->totalRate = $sum;
            }
        }

        // Step 11: Cache and audit.
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

    /**
     * Sync resolved rates to WooCommerce tax tables for a state.
     *
     * This is the primary integration point with WooCommerce — takes
     * jurisdiction_rates data and writes it to woocommerce_tax_rates.
     *
     * @param  string $state_code Two-letter state code.
     * @return int Number of WC rates written.
     */
    public static function sync_to_woocommerce(string $state_code): int
    {
        global $wpdb;

        $state_code = strtoupper($state_code);

        // Get the active resolver for this state.
        $resolver = Tax_Resolver_Router::route($state_code);
        if (!$resolver) {
            return 0;
        }

        // Get all jurisdiction rates for this state from the active dataset.
        $rates_table = Tax_Resolver_DB::table('jurisdiction_rates');
        $ds_table    = Tax_Resolver_DB::table('dataset_versions');

        $rates = $wpdb->get_results($wpdb->prepare(
            "SELECT jr.* FROM {$rates_table} jr
             INNER JOIN {$ds_table} dv ON jr.dataset_version_id = dv.id
             WHERE jr.state_code = %s
               AND dv.source_code = %s
               AND dv.state_code = %s
               AND dv.status = 'active'
               AND (jr.expires_at IS NULL OR jr.expires_at >= CURDATE())
             ORDER BY jr.jurisdiction_type ASC",
            $state_code,
            $resolver->get_source_code(),
            $state_code
        ), ARRAY_A);

        if (empty($rates)) {
            return 0;
        }

        // Delete existing FFLA rates for this state.
        self::delete_wc_rates($state_code);

        // Group rates by unique jurisdiction combinations for WooCommerce.
        // WC needs one row per location combo (state + county + city + postcode).
        $count = 0;

        foreach ($rates as $order => $rate) {
            $rate_val = (float) $rate['rate'];
            if ($rate_val <= 0) {
                continue;
            }

            // Convert decimal rate to percentage (WC stores as percentage).
            $rate_pct = $rate_val * 100;

            $wc_rate_id = \WC_Tax::_insert_tax_rate([
                'tax_rate_country'  => 'US',
                'tax_rate_state'    => $state_code,
                'tax_rate'          => number_format($rate_pct, 4, '.', ''),
                'tax_rate_name'     => 'FFLA_' . $rate['jurisdiction_type'] . '_' . $rate['jurisdiction_name'],
                'tax_rate_priority' => ($rate['jurisdiction_type'] === 'state') ? 1 : 2,
                'tax_rate_compound' => ($rate['jurisdiction_type'] !== 'state') ? 1 : 0,
                'tax_rate_shipping' => 1,
                'tax_rate_order'    => $order,
                'tax_rate_class'    => '',
            ]);

            if ($wc_rate_id) {
                // Set postcodes if available.
                if (!empty($rate['zip_codes'])) {
                    \WC_Tax::_update_tax_rate_postcodes($wc_rate_id, $rate['zip_codes']);
                }

                // Set cities if available.
                if (!empty($rate['city_names'])) {
                    \WC_Tax::_update_tax_rate_cities($wc_rate_id, $rate['city_names']);
                }

                $count++;
            }
        }

        return $count;
    }

    /**
     * Sync all supported states to WooCommerce.
     *
     * @return array<string, int> Map of state_code => rates_imported.
     */
    public static function sync_all_to_woocommerce(): array
    {
        $results = [];
        $matrix  = Tax_Coverage::get_matrix();

        foreach ($matrix as $rule) {
            if (in_array($rule['coverage_status'], [
                Tax_Coverage::SUPPORTED_ADDRESS_RATE,
                Tax_Coverage::SUPPORTED_WITH_REMOTE,
            ], true)) {
                $results[$rule['state_code']] = self::sync_to_woocommerce($rule['state_code']);
            }
        }

        return $results;
    }

    /**
     * Delete all FFLA-prefixed WC tax rates for a state.
     */
    private static function delete_wc_rates(string $state_code): void
    {
        global $wpdb;

        $rate_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
             WHERE tax_rate_country = 'US'
               AND tax_rate_state = %s
               AND tax_rate_name LIKE 'FFLA\\_%'",
            $state_code
        ));

        foreach ($rate_ids as $rate_id) {
            \WC_Tax::_delete_tax_rate(absint($rate_id));
        }
    }

    /* ── Cache Methods ──────────────────────────────────────────────── */

    /**
     * Get cached quote result.
     */
    private static function get_cached(string $cache_key): ?Tax_Quote_Result
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('address_cache');
        $row   = $wpdb->get_row($wpdb->prepare(
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

        // Reconstruct Tax_Quote_Result from cached data.
        $result = new Tax_Quote_Result();
        foreach ($data as $key => $value) {
            if (property_exists($result, $key)) {
                $result->$key = $value;
            }
        }

        return $result;
    }

    /**
     * Cache a quote result.
     */
    private static function cache_result(string $cache_key, string $state_code, Tax_Quote_Result $result): void
    {
        global $wpdb;

        $table      = Tax_Resolver_DB::table('address_cache');
        $expires_at = wp_date('Y-m-d H:i:s', time() + self::$cache_ttl);

        $wpdb->replace($table, [
            'cache_key'       => $cache_key,
            'state_code'      => $state_code,
            'normalized_json' => wp_json_encode($result->normalizedAddress),
            'quote_json'      => wp_json_encode($result->to_array()),
            'created_at'      => current_time('mysql'),
            'expires_at'      => $expires_at,
        ]);
    }

    /* ── Audit Methods ──────────────────────────────────────────────── */

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
            'cache_hit'       => $result->trace['cacheHit'] ? 1 : 0,
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
     * Set cache TTL (for configuration).
     */
    public static function set_cache_ttl(int $seconds): void
    {
        self::$cache_ttl = max(60, $seconds);
    }
}
