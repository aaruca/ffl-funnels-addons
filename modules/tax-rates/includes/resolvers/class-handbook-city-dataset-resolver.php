<?php
/**
 * SalesTaxHandbook City Dataset Resolver.
 *
 * Resolves state and city tax rates from imported SalesTaxHandbook city-table
 * datasets stored locally in dataset_versions/jurisdiction_rates.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Handbook_City_Dataset_Resolver extends Tax_Resolver_Base
{
    const SOURCE_CODE = 'salestaxhandbook_city_table';

    public function get_id(): string
    {
        return 'handbook_city_dataset';
    }

    public function get_name(): string
    {
        return 'SalesTaxHandbook City Dataset';
    }

    public function get_source_code(): string
    {
        return self::SOURCE_CODE;
    }

    public function get_supported_states(): array
    {
        return class_exists('Tax_Coverage')
            ? Tax_Coverage::ALL_STATES
            : [
                'AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL',
                'GA','HI','ID','IL','IN','IA','KS','KY','LA','ME',
                'MD','MA','MI','MN','MS','MO','MT','NE','NV','NH',
                'NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI',
                'SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
            ];
    }

    public function requires_geocode(): bool
    {
        return false;
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $state_code = strtoupper((string) ($normalized['state'] ?? ''));
        $dataset    = $this->get_active_dataset($state_code);

        if (!$dataset) {
            $result                    = new Tax_Quote_Result();
            $result->inputAddress      = $normalized;
            $result->normalizedAddress = $normalized;
            $result->state             = $state_code;
            $result->coverageStatus    = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
            $result->trace['resolver'] = $this->get_id();
            $result->trace['geocodeUsed'] = false;
            $result->source            = $this->get_source_code();
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                sprintf(
                    'No imported SalesTaxHandbook city dataset is active for state: %s. Run the SalesTaxHandbook sync first.',
                    $state_code
                )
            );

            return $result;
        }

        if (!$this->is_dataset_fresh($state_code)) {
            $result = $this->stale_result($normalized, $geocode);
            $result->source = $this->get_source_code();
            $result->sourceVersion = $dataset['version_label'] ?? null;
            $result->effectiveDate = $dataset['effective_date'] ?? null;
            $result->trace['sourceUrl'] = $dataset['storage_uri'] ?? null;
            return $result;
        }

        $city_key  = self::normalize_city_key((string) ($normalized['city'] ?? ''));
        $city_rate = $city_key !== ''
            ? $this->get_city_rate_row((int) $dataset['id'], $state_code, $city_key)
            : null;

        if ($city_rate) {
            return $this->build_city_result($normalized, $dataset, $city_rate);
        }

        $state_floor = $this->get_state_floor_row((int) $dataset['id'], $state_code);
        if ($state_floor) {
            return $this->build_state_floor_result($normalized, $dataset, $state_floor, $city_key !== '');
        }

        $result                    = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state             = $state_code;
        $result->coverageStatus    = Tax_Coverage::DEGRADED;
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = false;
        $result->source            = $this->get_source_code();
        $result->sourceVersion     = $dataset['version_label'] ?? null;
        $result->effectiveDate     = $dataset['effective_date'] ?? null;
        $result->trace['sourceUrl'] = $dataset['storage_uri'] ?? null;
        $result->set_error(
            Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
            sprintf(
                'A SalesTaxHandbook city rate could not be matched for %s, %s and no state-level fallback was imported for that state.',
                (string) ($normalized['city'] ?? 'the requested city'),
                $state_code
            )
        );

        return $result;
    }

    /**
     * Normalize a city name to a deterministic lookup key.
     */
    public static function normalize_city_key(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = strtoupper(trim(wp_strip_all_tags($value)));
        $value = str_replace(['.', "'", '&'], ['', '', ' AND '], $value);
        $value = preg_replace('/,\s*[A-Z]{2}$/', '', (string) $value);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', (string) $value);
        $value = preg_replace('/\bSAINT\b/', 'ST', (string) $value);
        $value = preg_replace('/\bSAINTE\b/', 'STE', (string) $value);
        $value = preg_replace('/\bFORT\b/', 'FT', (string) $value);
        $value = preg_replace('/\bMOUNT\b/', 'MT', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }

    /**
     * Remove the trailing state suffix from labels like "Tampa, FL".
     */
    public static function normalize_city_label(string $value): string
    {
        $value = trim(wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES, 'UTF-8')));
        $value = preg_replace('/,\s*[A-Z]{2}$/', '', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }

    /**
     * Parse a percent string, treating N/A as zero for no-tax states.
     */
    public static function parse_rate_value(string $value): ?float
    {
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $text  = trim(wp_strip_all_tags($value));

        if ($text === '' || stripos($text, 'N/A') !== false) {
            return 0.0;
        }

        $number = preg_replace('/[^0-9.]+/', '', $text);
        if ($number === '' || !is_numeric($number)) {
            return null;
        }

        return ((float) $number) / 100;
    }

    private function build_city_result(array $normalized, array $dataset, array $city_rate): Tax_Quote_Result
    {
        $result                    = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state             = strtoupper((string) ($normalized['state'] ?? ''));
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $result->determinationScope = 'city_rate_only';
        $result->resolutionMode    = 'handbook_city_dataset';
        $result->source            = $this->get_source_code();
        $result->sourceVersion     = $dataset['version_label'] ?? null;
        $result->effectiveDate     = $dataset['effective_date'] ?? null;
        $result->confidence        = !empty($city_rate['notes'])
            ? Tax_Quote_Result::CONFIDENCE_MEDIUM
            : Tax_Quote_Result::CONFIDENCE_HIGH;
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = false;
        $result->trace['sourceUrl'] = $dataset['storage_uri'] ?? null;
        $result->limitations[] = 'Resolved from the imported SalesTaxHandbook state city table.';

        if (!empty($city_rate['notes'])) {
            $result->limitations[] = $city_rate['notes'];
        }

        $result->add_breakdown(
            'city',
            (string) $city_rate['jurisdiction_name'],
            (float) $city_rate['rate']
        );
        $result->calculate_total();

        return $result;
    }

    private function build_state_floor_result(array $normalized, array $dataset, array $state_floor, bool $city_was_requested): Tax_Quote_Result
    {
        $result                    = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state             = strtoupper((string) ($normalized['state'] ?? ''));
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
        $result->determinationScope = 'state_rate_only';
        $result->resolutionMode    = 'handbook_state_floor';
        $result->source            = $this->get_source_code();
        $result->sourceVersion     = $dataset['version_label'] ?? null;
        $result->effectiveDate     = $dataset['effective_date'] ?? null;
        $result->confidence        = Tax_Quote_Result::CONFIDENCE_MEDIUM;
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = false;
        $result->trace['sourceUrl'] = $dataset['storage_uri'] ?? null;
        $result->limitations[] = 'Returned the imported SalesTaxHandbook state sales tax floor because the requested city was not found in the city table.';

        if ($city_was_requested) {
            $result->limitations[] = 'SalesTaxHandbook city tables do not cover every municipality uniquely, so unmatched cities fall back to the imported state-level rate from the same page.';
        }

        $result->add_breakdown(
            'state',
            (string) $state_floor['jurisdiction_name'],
            (float) $state_floor['rate']
        );
        $result->calculate_total();

        return $result;
    }

    private function get_city_rate_row(int $dataset_id, string $state_code, string $city_key): ?array
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('jurisdiction_rates');
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE dataset_version_id = %d
               AND state_code = %s
               AND jurisdiction_type = 'city'
               AND city_names = %s
               AND (expires_at IS NULL OR expires_at >= CURDATE())
             ORDER BY rate DESC, id ASC
             LIMIT 1",
            $dataset_id,
            $state_code,
            $city_key
        ), ARRAY_A);

        return $row ?: null;
    }

    private function get_state_floor_row(int $dataset_id, string $state_code): ?array
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('jurisdiction_rates');
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE dataset_version_id = %d
               AND state_code = %s
               AND jurisdiction_type = 'state'
               AND jurisdiction_code = 'STATE_FLOOR'
               AND (expires_at IS NULL OR expires_at >= CURDATE())
             ORDER BY id ASC
             LIMIT 1",
            $dataset_id,
            $state_code
        ), ARRAY_A);

        return $row ?: null;
    }
}
