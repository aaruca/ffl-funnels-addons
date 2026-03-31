<?php
/**
 * SST Resolver — Streamlined Sales Tax rate/boundary file resolver.
 *
 * Reads official SST rate and boundary CSV data to resolve
 * tax rates for SST member states. This is the primary bulk
 * data resolver covering ~24 member states.
 *
 * SST member states (as of 2025):
 *   AR, GA, IN, IA, KS, KY, MI, MN, NE, NV, NJ, NC, ND,
 *   OH, OK, RI, SD, TN, UT, VT, WA, WV, WI, WY
 *
 * This resolver reads from the jurisdiction_rates table (populated
 * by the dataset pipeline) and matches based on FIPS codes from
 * geocoding, or by state+county+city fallback.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class SST_Resolver extends Tax_Resolver_Base
{
    /* SST member states. */
    const MEMBER_STATES = [
        'AR', 'GA', 'IN', 'IA', 'KS', 'KY', 'MI', 'MN',
        'NE', 'NV', 'NJ', 'NC', 'ND', 'OH', 'OK', 'RI',
        'SD', 'TN', 'UT', 'VT', 'WA', 'WV', 'WI', 'WY',
    ];

    public function get_id(): string
    {
        return 'sst';
    }

    public function get_name(): string
    {
        return 'SST Rate/Boundary Files';
    }

    public function get_source_code(): string
    {
        return 'sst_rate_boundary';
    }

    public function get_supported_states(): array
    {
        return self::MEMBER_STATES;
    }

    /**
     * Resolve tax rate for an address in an SST member state.
     *
     * Strategy:
     *   1. If geocode has FIPS → match jurisdiction_rates by (state + county FIPS)
     *   2. If match by FIPS → aggregate state + county + city + special rates
     *   3. If no FIPS match → try city name match
     *   4. Fallback → return state-level rate only with medium confidence
     *
     * @param  array $normalized Normalized address.
     * @param  array $geocode    Geocode result.
     * @return Tax_Quote_Result
     */
    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $state_code = $normalized['state'];

        // Check dataset freshness.
        if (!$this->is_dataset_fresh($state_code)) {
            return $this->stale_result($normalized, $geocode);
        }

        // Get FIPS from geocode.
        $fips = Tax_Geocoder::get_fips($geocode);

        // Strategy 1: FIPS-based match.
        if ($fips) {
            $rates = $this->get_rates_by_fips($state_code, $fips);
            if (!empty($rates)) {
                $result = $this->build_result($normalized, $geocode, $rates);
                $result->confidence = 'high';
                return $result;
            }
        }

        // Strategy 2: City-based match.
        $city = $normalized['city'] ?? null;
        if ($city) {
            $city = strtoupper($city);
            $rates = $this->get_rates_by_city($state_code, $city);
            if (!empty($rates)) {
                $result = $this->build_result($normalized, $geocode, $rates);
                $result->confidence = 'medium';
                $result->limitations[] = 'Rate resolved by city name match (no FIPS confirmation).';
                return $result;
            }
        }

        // Strategy 3: County-based match from geocode.
        $county_name = strtoupper($geocode['countyName'] ?? '');
        if ($county_name) {
            $rates = $this->get_rates_by_county($state_code, $county_name);
            if (!empty($rates)) {
                $result = $this->build_result($normalized, $geocode, $rates);
                $result->confidence = 'medium';
                $result->limitations[] = 'Rate resolved by county name match.';
                return $result;
            }
        }

        // Strategy 4: State-level rate only.
        $state_rates = $this->get_state_rate($state_code);
        if (!empty($state_rates)) {
            $result = $this->build_result($normalized, $geocode, $state_rates);
            $result->confidence = 'medium';
            $result->limitations[] = 'Only state-level rate available. Local rates could not be determined.';
            $result->determinationScope = 'state_rate_only';
            return $result;
        }

        // No rates found at all.
        $result                   = new Tax_Quote_Result();
        $result->inputAddress     = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state            = $state_code;
        $result->coverageStatus   = Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $result->trace['resolver'] = $this->get_id();
        $result->set_error(
            Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
            "Could not determine tax rate for this address in {$state_code}."
        );

        return $result;
    }

    /* ── Query Helpers ────────────────────────────────────────────── */

    /**
     * Get rates matching a FIPS code (state + county level).
     */
    private function get_rates_by_fips(string $state_code, string $fips): array
    {
        global $wpdb;

        $dataset = $this->get_active_dataset($state_code);
        if (!$dataset) {
            return [];
        }

        $table = Tax_Resolver_DB::table('jurisdiction_rates');

        // Get state rate + any rates matching this FIPS.
        $county_fips = substr($fips, 2); // Remove state FIPS prefix.

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE dataset_version_id = %d
               AND state_code = %s
               AND (
                   jurisdiction_type = 'state'
                   OR jurisdiction_fips = %s
                   OR jurisdiction_fips = %s
               )
               AND (expires_at IS NULL OR expires_at >= CURDATE())
             ORDER BY FIELD(jurisdiction_type, 'state', 'county', 'city', 'special')",
            $dataset['id'],
            $state_code,
            $fips,
            $county_fips
        ), ARRAY_A) ?: [];
    }

    /**
     * Get rates matching a city name.
     */
    private function get_rates_by_city(string $state_code, string $city): array
    {
        global $wpdb;

        $dataset = $this->get_active_dataset($state_code);
        if (!$dataset) {
            return [];
        }

        $table = Tax_Resolver_DB::table('jurisdiction_rates');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE dataset_version_id = %d
               AND state_code = %s
               AND (
                   jurisdiction_type = 'state'
                   OR (jurisdiction_type IN ('city', 'county', 'special')
                       AND UPPER(jurisdiction_name) = %s)
                   OR (city_names IS NOT NULL AND UPPER(city_names) LIKE %s)
               )
               AND (expires_at IS NULL OR expires_at >= CURDATE())
             ORDER BY FIELD(jurisdiction_type, 'state', 'county', 'city', 'special')",
            $dataset['id'],
            $state_code,
            $city,
            '%' . $wpdb->esc_like($city) . '%'
        ), ARRAY_A) ?: [];
    }

    /**
     * Get rates matching a county name.
     */
    private function get_rates_by_county(string $state_code, string $county): array
    {
        global $wpdb;

        $dataset = $this->get_active_dataset($state_code);
        if (!$dataset) {
            return [];
        }

        $table = Tax_Resolver_DB::table('jurisdiction_rates');

        // Remove " COUNTY" suffix for matching.
        $county_clean = preg_replace('/\s+COUNTY$/i', '', $county);

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE dataset_version_id = %d
               AND state_code = %s
               AND (
                   jurisdiction_type = 'state'
                   OR (jurisdiction_type = 'county'
                       AND (UPPER(jurisdiction_name) = %s
                            OR UPPER(jurisdiction_name) = %s))
               )
               AND (expires_at IS NULL OR expires_at >= CURDATE())
             ORDER BY FIELD(jurisdiction_type, 'state', 'county', 'city', 'special')",
            $dataset['id'],
            $state_code,
            $county,
            $county_clean
        ), ARRAY_A) ?: [];
    }

    /**
     * Get state-level rate only.
     */
    private function get_state_rate(string $state_code): array
    {
        global $wpdb;

        $dataset = $this->get_active_dataset($state_code);
        if (!$dataset) {
            return [];
        }

        $table = Tax_Resolver_DB::table('jurisdiction_rates');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE dataset_version_id = %d
               AND state_code = %s
               AND jurisdiction_type = 'state'
               AND (expires_at IS NULL OR expires_at >= CURDATE())
             LIMIT 1",
            $dataset['id'],
            $state_code
        ), ARRAY_A) ?: [];
    }
}
