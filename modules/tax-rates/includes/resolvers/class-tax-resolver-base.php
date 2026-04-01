<?php
/**
 * Tax Resolver Base — Abstract base class for state resolvers.
 *
 * Every state resolver extends this class and implements the
 * resolve() method. The base class provides common utilities
 * for dataset access, freshness checking, and result building.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class Tax_Resolver_Base
{
    /**
     * Unique identifier for this resolver (e.g., 'sst', 'illinois_idor').
     */
    abstract public function get_id(): string;

    /**
     * Human-readable name (e.g., 'SST Rate Files', 'Illinois IDOR').
     */
    abstract public function get_name(): string;

    /**
     * Source code for audit trail (e.g., 'sst_rate_boundary', 'idor_address_specific').
     */
    abstract public function get_source_code(): string;

    /**
     * Which states this resolver can handle.
     *
     * @return string[] Array of 2-letter state codes.
     */
    abstract public function get_supported_states(): array;

    /**
     * Resolve a tax quote for the given address and geocode data.
     *
     * @param  array $normalized Normalized address components.
     * @param  array $geocode    Geocode result from Census Geocoder.
     * @return Tax_Quote_Result
     */
    abstract public function resolve(array $normalized, array $geocode): Tax_Quote_Result;

    /**
     * Whether this resolver needs a Census geocode before it can resolve.
     *
     * Resolvers that rely only on state/city input can override this to false
     * so the quote engine can skip an unnecessary network call.
     */
    public function requires_geocode(): bool
    {
        return true;
    }

    /**
     * Check if this resolver can handle a specific state.
     */
    public function supports_state(string $state_code): bool
    {
        return in_array(strtoupper($state_code), $this->get_supported_states(), true);
    }

    /**
     * Get the active dataset version for this resolver's source.
     *
     * @return array|null Dataset version row or null.
     */
    protected function get_active_dataset(?string $state_code = null): ?array
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');
        if ($state_code !== null) {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE source_code = %s AND state_code = %s AND status = 'active'
                 ORDER BY effective_date DESC LIMIT 1",
                $this->get_source_code(),
                strtoupper($state_code)
            ), ARRAY_A);
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE source_code = %s AND status = 'active'
                 ORDER BY effective_date DESC LIMIT 1",
                $this->get_source_code()
            ), ARRAY_A);
        }

        return $row ?: null;
    }

    /**
     * Check if the active dataset is still fresh.
     *
     * @return bool True if dataset is fresh, false if stale/missing.
     */
    protected function is_dataset_fresh(?string $state_code = null): bool
    {
        $dataset = $this->get_active_dataset($state_code);
        if (!$dataset) {
            return false;
        }

        $policy    = $dataset['freshness_policy'] ?? '90d';
        $loaded_at = strtotime($dataset['loaded_at']);

        // Parse freshness policy (e.g., '90d', '30d', '365d').
        $days = (int) $policy;
        if ($days <= 0) {
            $days = 90;
        }

        return (time() - $loaded_at) < ($days * DAY_IN_SECONDS);
    }

    /**
     * Get jurisdiction rates from the active dataset for a state.
     *
     * @param  string      $state_code
     * @param  string|null $fips Full FIPS code for more precise matching.
     * @return array[] Rate rows.
     */
    protected function get_rates_for_state(string $state_code, ?string $fips = null): array
    {
        global $wpdb;

        $dataset = $this->get_active_dataset($state_code);
        if (!$dataset) {
            return [];
        }

        $table = Tax_Resolver_DB::table('jurisdiction_rates');

        if ($fips) {
            // Try FIPS-based match first (county-level).
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE dataset_version_id = %d
                   AND state_code = %s
                   AND jurisdiction_fips = %s
                   AND (expires_at IS NULL OR expires_at >= CURDATE())
                 ORDER BY jurisdiction_type ASC",
                $dataset['id'],
                $state_code,
                $fips
            ), ARRAY_A);

            if (!empty($rows)) {
                return $rows;
            }
        }

        // Fallback: get all rates for the state.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE dataset_version_id = %d
               AND state_code = %s
               AND (expires_at IS NULL OR expires_at >= CURDATE())
             ORDER BY jurisdiction_type ASC",
            $dataset['id'],
            $state_code
        ), ARRAY_A) ?: [];
    }

    /**
     * Build a successful Tax_Quote_Result from rate rows.
     *
     * @param  array   $normalized Normalized address.
     * @param  array   $geocode    Geocode result.
     * @param  array[] $rates      Rate rows from jurisdiction_rates table.
     * @return Tax_Quote_Result
     */
    protected function build_result(array $normalized, array $geocode, array $rates): Tax_Quote_Result
    {
        $dataset = $this->get_active_dataset($normalized['state'] ?? null);
        $result  = new Tax_Quote_Result();

        $result->inputAddress     = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress   = $geocode['matchedAddress'] ?? null;
        $result->state            = $normalized['state'];
        $result->coverageStatus   = Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $result->resolutionMode   = 'dataset_match';
        $result->source           = $this->get_source_code();
        $result->sourceVersion    = $dataset ? $dataset['version_label'] : null;
        $result->effectiveDate    = $dataset ? $dataset['effective_date'] : null;
        $result->confidence       = $geocode['confidence'] ?? 'medium';

        $result->trace['resolver']    = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);

        foreach ($rates as $rate_row) {
            $result->add_breakdown(
                $rate_row['jurisdiction_type'],
                $rate_row['jurisdiction_name'],
                (float) $rate_row['rate']
            );
        }

        $result->calculate_total();

        return $result;
    }

    /**
     * Build a "dataset stale" error result.
     */
    protected function stale_result(array $normalized, array $geocode): Tax_Quote_Result
    {
        $result                   = new Tax_Quote_Result();
        $result->inputAddress     = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state            = $normalized['state'];
        $result->coverageStatus   = Tax_Coverage::DEGRADED;
        $result->trace['resolver'] = $this->get_id();
        $result->set_error(
            Tax_Quote_Result::OUTCOME_DATASET_STALE,
            "Dataset for source '{$this->get_source_code()}' is stale. Rate cannot be reliably determined."
        );

        return $result;
    }
}
