<?php
/**
 * Sheet ZIP Dataset Resolver.
 *
 * Resolves ZIP, city, and state fallback tax rates from the shared Google
 * Sheet datasets stored locally in dataset_versions/jurisdiction_rates.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Sheet_ZIP_Dataset_Resolver extends Tax_Resolver_Base
{
    const SOURCE_CODE = 'google_sheet_zip_rates';

    public function get_id(): string
    {
        return 'sheet_zip_dataset';
    }

    public function get_name(): string
    {
        return __('Google Sheet ZIP Dataset', 'ffl-funnels-addons');
    }

    public function get_source_code(): string
    {
        return self::SOURCE_CODE;
    }

    public function get_supported_states(): array
    {
        return class_exists('Tax_Coverage')
            ? Tax_Coverage::ALL_STATES
            : array_keys(Tax_Dataset_Pipeline::STATE_NAMES);
    }

    public function requires_geocode(): bool
    {
        return false;
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $state_code = strtoupper((string) ($normalized['state'] ?? ''));
        $city_key   = self::normalize_city_key((string) ($normalized['city'] ?? ''));
        $zip_code   = preg_replace('/[^0-9]/', '', (string) ($normalized['zip'] ?? ''));
        $zip_code   = strlen($zip_code) >= 5 ? substr($zip_code, 0, 5) : '';
        $dataset    = $this->get_active_dataset($state_code);

        if ($dataset && $this->is_dataset_fresh($state_code)) {
            $zip_rows = $zip_code !== ''
                ? $this->get_zip_rate_rows((int) $dataset['id'], $state_code, $zip_code)
                : [];

            if (!empty($zip_rows)) {
                return $this->build_zip_result($normalized, $dataset, $zip_rows);
            }

            $city_rate = $city_key !== ''
                ? $this->get_city_rate_row((int) $dataset['id'], $state_code, $city_key)
                : null;

            if ($city_rate) {
                return $this->build_city_result($normalized, $dataset, $city_rate);
            }
        }

        if (!$dataset) {
            $result = new Tax_Quote_Result();
            $result->inputAddress      = $normalized;
            $result->normalizedAddress = $normalized;
            $result->state             = $state_code;
            $result->coverageStatus    = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
            $result->trace['resolver'] = $this->get_id();
            $result->trace['geocodeUsed'] = false;
            $result->source = $this->get_source_code();
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                sprintf(
                    'No local Google Sheet dataset is active for state: %s. Run sheet sync first.',
                    $state_code
                )
            );

            return $result;
        }

        if (!$this->is_dataset_fresh($state_code)) {
            $result = $this->stale_result($normalized, $geocode);
            $result->source         = $this->get_source_code();
            $result->sourceVersion  = $dataset['version_label'] ?? null;
            $result->effectiveDate  = $dataset['effective_date'] ?? null;
            $result->trace['sourceUrl'] = $dataset['storage_uri'] ?? null;

            return $result;
        }

        $state_floor = $this->get_state_floor_row((int) $dataset['id'], $state_code);
        if ($state_floor) {
            return $this->build_state_floor_result($normalized, $dataset, $state_floor, $city_key !== '' || $zip_code !== '');
        }

        $result = new Tax_Quote_Result();
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
                'A sheet-backed tax rate could not be matched for %s, %s and no state-level fallback was imported for that state.',
                (string) ($normalized['city'] ?? 'the requested location'),
                $state_code
            )
        );

        return $result;
    }

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

    public static function normalize_city_label(string $value): string
    {
        $value = trim(wp_strip_all_tags(html_entity_decode($value, ENT_QUOTES, 'UTF-8')));
        $value = preg_replace('/,\s*[A-Z]{2}$/', '', (string) $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }

    public static function parse_rate_value(string $value): ?float
    {
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $text = trim(wp_strip_all_tags($value));

        if ($text === '' || stripos($text, 'N/A') !== false) {
            return 0.0;
        }

        $number = preg_replace('/[^0-9.]+/', '', $text);
        if ($number === '' || !is_numeric($number)) {
            return null;
        }

        return ((float) $number) / 100;
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array<int,array<string,mixed>> $zip_rows
     */
    private function build_zip_result(array $normalized, array $dataset, array $zip_rows): Tax_Quote_Result
    {
        $result = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state             = strtoupper((string) ($normalized['state'] ?? ''));
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $result->determinationScope = 'zip_rate_only';
        $result->resolutionMode     = 'sheet_zip_dataset';
        $result->source             = $this->get_source_code();
        $result->sourceVersion      = $dataset['version_label'] ?? null;
        $result->effectiveDate      = $dataset['effective_date'] ?? null;
        $result->confidence         = Tax_Quote_Result::CONFIDENCE_HIGH;
        $result->trace['resolver']  = $this->get_id();
        $result->trace['geocodeUsed'] = false;
        $result->trace['sourceUrl'] = $dataset['storage_uri'] ?? null;
        $result->limitations[] = 'Resolved from the local Google Sheet ZIP dataset.';

        $added_breakdown = false;
        foreach ($zip_rows as $row) {
            $type = (string) ($row['jurisdiction_type'] ?? '');
            $rate = (float) ($row['rate'] ?? 0);

            switch ($type) {
                case 'zip_state':
                    $result->add_breakdown('state', (string) $row['jurisdiction_name'], $rate);
                    $added_breakdown = true;
                    break;
                case 'zip_county':
                    $result->add_breakdown('county', (string) $row['jurisdiction_name'], $rate);
                    $added_breakdown = true;
                    break;
                case 'zip_city':
                    $result->add_breakdown('city', (string) $row['jurisdiction_name'], $rate);
                    $added_breakdown = true;
                    break;
                case 'zip_special':
                    $result->add_breakdown('special', (string) $row['jurisdiction_name'], $rate);
                    $added_breakdown = true;
                    break;
            }
        }

        if (!$added_breakdown) {
            foreach ($zip_rows as $row) {
                if (($row['jurisdiction_type'] ?? '') === 'zip_total') {
                    $result->add_breakdown('special', (string) $row['jurisdiction_name'], (float) $row['rate']);
                    break;
                }
            }
        }

        foreach ($zip_rows as $row) {
            if (($row['jurisdiction_type'] ?? '') === 'zip_total' && !empty($row['notes'])) {
                $result->limitations[] = (string) $row['notes'];
                break;
            }
        }

        $result->calculate_total();

        return $this->finalize_zero_tax_result($result);
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array<string,mixed> $city_rate
     */
    private function build_city_result(array $normalized, array $dataset, array $city_rate): Tax_Quote_Result
    {
        $result = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state             = strtoupper((string) ($normalized['state'] ?? ''));
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $result->determinationScope = 'city_rate_only';
        $result->resolutionMode     = 'sheet_city_fallback';
        $result->source             = $this->get_source_code();
        $result->sourceVersion      = $dataset['version_label'] ?? null;
        $result->effectiveDate      = $dataset['effective_date'] ?? null;
        $result->confidence         = !empty($city_rate['notes']) ? Tax_Quote_Result::CONFIDENCE_MEDIUM : Tax_Quote_Result::CONFIDENCE_HIGH;
        $result->trace['resolver']  = $this->get_id();
        $result->trace['geocodeUsed'] = false;
        $result->trace['sourceUrl'] = $dataset['storage_uri'] ?? null;
        $result->limitations[] = 'Resolved from the local Google Sheet city fallback dataset.';

        if (!empty($city_rate['notes'])) {
            $result->limitations[] = (string) $city_rate['notes'];
        }

        $result->add_breakdown('city', (string) $city_rate['jurisdiction_name'], (float) $city_rate['rate']);
        $result->calculate_total();

        return $this->finalize_zero_tax_result($result);
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array<string,mixed> $state_floor
     */
    private function build_state_floor_result(array $normalized, array $dataset, array $state_floor, bool $city_or_zip_was_requested): Tax_Quote_Result
    {
        $result = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state             = strtoupper((string) ($normalized['state'] ?? ''));
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
        $result->determinationScope = 'state_rate_only';
        $result->resolutionMode     = 'sheet_state_floor';
        $result->source             = $this->get_source_code();
        $result->sourceVersion      = $dataset['version_label'] ?? null;
        $result->effectiveDate      = $dataset['effective_date'] ?? null;
        $result->confidence         = Tax_Quote_Result::CONFIDENCE_MEDIUM;
        $result->trace['resolver']  = $this->get_id();
        $result->trace['geocodeUsed'] = false;
        $result->trace['sourceUrl'] = $dataset['storage_uri'] ?? null;
        $result->limitations[] = 'Returned the state sales tax floor imported from the shared Google Sheet because the requested ZIP or city was not found in the local dataset.';

        if ($city_or_zip_was_requested) {
            $result->limitations[] = 'ZIP input is the most precise path. When a ZIP is missing from the local dataset, the resolver falls back to city and then to the state floor.';
        }

        $result->add_breakdown('state', (string) $state_floor['jurisdiction_name'], (float) $state_floor['rate']);
        $result->calculate_total();

        return $this->finalize_zero_tax_result($result);
    }

    private function finalize_zero_tax_result(Tax_Quote_Result $result): Tax_Quote_Result
    {
        if ((float) $result->totalRate === 0.0) {
            $result->coverageStatus = Tax_Coverage::NO_SALES_TAX;
            $result->outcomeCode    = Tax_Quote_Result::OUTCOME_NO_SALES_TAX;
        }

        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function get_city_rate_row(int $dataset_id, string $state_code, string $city_key): ?array
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('jurisdiction_rates');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function get_zip_rate_rows(int $dataset_id, string $state_code, string $zip_code): array
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('jurisdiction_rates');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE dataset_version_id = %d
               AND state_code = %s
               AND zip_codes = %s
               AND jurisdiction_type IN ('zip_total', 'zip_state', 'zip_county', 'zip_city', 'zip_special')
               AND (expires_at IS NULL OR expires_at >= CURDATE())
             ORDER BY FIELD(jurisdiction_type, 'zip_total', 'zip_state', 'zip_county', 'zip_city', 'zip_special'), id ASC",
            $dataset_id,
            $state_code,
            $zip_code
        ), ARRAY_A) ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function get_state_floor_row(int $dataset_id, string $state_code): ?array
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('jurisdiction_rates');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
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
