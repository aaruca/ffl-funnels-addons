<?php
/**
 * Texas Rate File Resolver.
 *
 * Uses the official Texas Comptroller sales tax EDI rate file.
 * This source is authoritative for state, city, county, and many
 * special district rates, but it does not provide parcel boundaries,
 * so ambiguous special district matches are intentionally degraded.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texas_Rate_File_Resolver extends Tax_Resolver_Base
{
    const RATE_FILE_URL = 'https://comptroller.texas.gov/data/edi/sales-tax/taxrates.txt';
    const CACHE_TTL = 43200; // 12 hours.
    const DOWNLOAD_TTL = 86400; // 24 hours.
    const REQUEST_TIMEOUT = 20;

    public function get_id(): string
    {
        return 'tx_rate_file';
    }

    public function get_name(): string
    {
        return 'Texas Comptroller Sales Tax Rate File';
    }

    public function get_source_code(): string
    {
        return 'tx_sales_tax_rate_file';
    }

    public function get_supported_states(): array
    {
        return ['TX'];
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $result                   = new Tax_Quote_Result();
        $result->inputAddress     = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress   = $geocode['matchedAddress'] ?? null;
        $result->state            = 'TX';
        $result->coverageStatus   = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
        $result->resolutionMode   = 'official_rate_file_match';
        $result->source           = $this->get_source_code();
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);

        $dataset = $this->load_rate_file();
        if (is_wp_error($dataset)) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                'Texas official rate file is currently unavailable: ' . $dataset->get_error_message()
            );
            return $result;
        }

        $city = $this->normalize_name($normalized['city'] ?? '');
        $county = $this->normalize_name($geocode['countyName'] ?? '');

        if ($county === '') {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
                'Texas county could not be determined from the geocoded address.'
            );
            return $result;
        }

        $selection = $this->select_bundle($dataset['rows'], $city, $county);
        if ($selection === null) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
                "Texas rate could not be matched for city '{$normalized['city']}' in county '{$geocode['countyName']}'."
            );
            return $result;
        }

        $result->sourceVersion = $dataset['version_label'];
        $result->effectiveDate = $dataset['effective_date'];
        $result->confidence    = $selection['confidence'];
        $result->trace['county'] = $county;
        $result->trace['txMatchType'] = $selection['match_type'];

        $result->add_breakdown('state', 'Texas State', $dataset['state_rate']);

        if ($selection['county_rate'] > 0) {
            $result->add_breakdown('county', $selection['county_name'], $selection['county_rate']);
        }

        if ($selection['city_rate'] > 0) {
            $result->add_breakdown('city', $selection['city_name'], $selection['city_rate']);
        }

        foreach ($selection['specials'] as $special) {
            if ($special['rate'] > 0) {
                $result->add_breakdown('special', $special['name'], $special['rate']);
            }
        }

        $result->calculate_total();

        $result->limitations[] = 'Texas rate resolved from the official Comptroller EDI sales tax rate file.';
        $result->limitations[] = 'Special purpose district boundaries are not parcel-level in this source and may require manual review.';

        if ($selection['match_type'] === 'exact') {
            $result->determinationScope = 'address_rate_only';
        } elseif ($selection['match_type'] === 'base_city_county') {
            $result->determinationScope = 'city_county_rate_only';
            $result->limitations[] = 'Only the common city and county components could be determined confidently for this location.';
        } else {
            $result->determinationScope = 'county_rate_only';
            $result->limitations[] = 'Location appears outside an incorporated city or could only be matched at the county level.';
        }

        return $result;
    }

    /**
     * Load and parse the current Texas official rate file.
     *
     * @return array|WP_Error
     */
    private function load_rate_file()
    {
        $cached = get_transient('ffla_tax_tx_rate_file');
        if (is_array($cached) && !empty($cached['rows'])) {
            return $cached;
        }

        $storage_dir = Tax_Dataset_Pipeline::get_storage_dir();
        $file_path   = $storage_dir . 'TX_taxrates.txt';

        if (!file_exists($file_path) || (time() - filemtime($file_path)) > self::DOWNLOAD_TTL) {
            $response = wp_remote_get(self::RATE_FILE_URL, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => ['Accept' => 'text/plain'],
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            if (wp_remote_retrieve_response_code($response) !== 200) {
                return new WP_Error('tx_rate_http', 'Official Texas rate file returned HTTP ' . wp_remote_retrieve_response_code($response) . '.');
            }

            $body = wp_remote_retrieve_body($response);
            if (!is_string($body) || trim($body) === '') {
                return new WP_Error('tx_rate_empty', 'Official Texas rate file returned empty content.');
            }

            file_put_contents($file_path, $body);
        }

        $content = file_get_contents($file_path);
        if (!is_string($content) || trim($content) === '') {
            return new WP_Error('tx_rate_read', 'Texas rate file could not be read from local storage.');
        }

        $parsed = $this->parse_rate_file($content);
        if (empty($parsed['rows'])) {
            return new WP_Error('tx_rate_parse', 'Texas rate file could not be parsed.');
        }

        set_transient('ffla_tax_tx_rate_file', $parsed, self::CACHE_TTL);

        return $parsed;
    }

    /**
     * Parse the official TXT file into bundles keyed by city/county/special rates.
     */
    private function parse_rate_file(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $rows  = [];

        $version_label = null;
        $effective_date = null;
        $state_rate = 0.0625;

        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t/', $line);
            $parts = array_map('trim', $parts);

            if ($index === 0 && count($parts) >= 5 && preg_match('/^\d{5}$/', $parts[0])) {
                $version_label = $parts[0];
                if (is_numeric($parts[4])) {
                    $state_rate = (float) $parts[4];
                }

                if (preg_match('/^(\d{4})(\d)$/', $parts[0], $match)) {
                    $quarter_month = [
                        '1' => '01',
                        '2' => '04',
                        '3' => '07',
                        '4' => '10',
                    ];
                    $effective_date = $match[1] . '-' . ($quarter_month[$match[2]] ?? '01') . '-01';
                }
                continue;
            }

            if (count($parts) < 12) {
                continue;
            }

            if (!is_numeric($parts[2]) || !is_numeric($parts[5]) || !is_numeric($parts[8]) || !is_numeric($parts[11])) {
                continue;
            }

            $rows[] = [
                'city_name'    => $parts[0],
                'city_name_key'=> $this->normalize_name($parts[0]),
                'city_rate'    => (float) $parts[2],
                'county_name'  => $parts[3],
                'county_name_key' => $this->normalize_name($parts[3]),
                'county_rate'  => (float) $parts[5],
                'specials'     => $this->build_specials([
                    ['name' => $parts[6], 'rate' => (float) $parts[8]],
                    ['name' => $parts[9], 'rate' => (float) $parts[11]],
                ]),
            ];
        }

        return [
            'version_label' => $version_label ?: 'tx-current',
            'effective_date'=> $effective_date ?: wp_date('Y-m-d'),
            'state_rate'    => $state_rate,
            'rows'          => $rows,
        ];
    }

    /**
     * Normalize and filter special district entries.
     */
    private function build_specials(array $specials): array
    {
        $result = [];

        foreach ($specials as $special) {
            $name = trim($special['name']);
            $rate = (float) $special['rate'];

            if ($name === '' || strtolower($name) === 'n/a' || $rate <= 0) {
                continue;
            }

            $result[] = [
                'name' => $name,
                'rate' => $rate,
            ];
        }

        return $result;
    }

    /**
     * Select the best city/county bundle from the official file.
     */
    private function select_bundle(array $rows, string $city, string $county): ?array
    {
        $county_rows = array_values(array_filter($rows, function ($row) use ($county) {
            return $row['county_name_key'] === $county;
        }));

        if (empty($county_rows)) {
            return null;
        }

        $exact_city_rows = array_values(array_filter($county_rows, function ($row) use ($city) {
            return $city !== '' && $row['city_name_key'] === $city;
        }));

        if (count($exact_city_rows) === 1) {
            return $this->finalize_bundle($exact_city_rows[0], 'exact');
        }

        if (count($exact_city_rows) > 1) {
            $base = array_values(array_filter($exact_city_rows, function ($row) {
                return empty($row['specials']);
            }));

            if (count($base) === 1) {
                return $this->finalize_bundle($base[0], 'exact');
            }

            return $this->build_common_bundle($exact_city_rows, 'base_city_county');
        }

        $county_only_rows = array_values(array_filter($county_rows, function ($row) {
            return $row['city_name_key'] === 'N A';
        }));

        if (count($county_only_rows) === 1) {
            return $this->finalize_bundle($county_only_rows[0], 'county_only');
        }

        if (count($county_only_rows) > 1) {
            return $this->build_common_bundle($county_only_rows, 'county_only');
        }

        $partial_rows = array_values(array_filter($county_rows, function ($row) use ($city) {
            return $city !== '' && strpos($row['city_name_key'], $city) !== false;
        }));

        if (count($partial_rows) === 1) {
            return $this->finalize_bundle($partial_rows[0], 'base_city_county');
        }

        if (count($partial_rows) > 1) {
            return $this->build_common_bundle($partial_rows, 'base_city_county');
        }

        return null;
    }

    /**
     * Build a final bundle response.
     */
    private function finalize_bundle(array $row, string $match_type): array
    {
        return [
            'city_name'   => $row['city_name'],
            'city_rate'   => $row['city_rate'],
            'county_name' => $row['county_name'],
            'county_rate' => $row['county_rate'],
            'specials'    => $row['specials'],
            'match_type'  => $match_type,
            'confidence'  => empty($row['specials'])
                ? Tax_Quote_Result::CONFIDENCE_HIGH
                : Tax_Quote_Result::CONFIDENCE_MEDIUM,
        ];
    }

    /**
     * Build a conservative bundle from common city/county components only.
     */
    private function build_common_bundle(array $rows, string $match_type): ?array
    {
        if (empty($rows)) {
            return null;
        }

        $first = $rows[0];
        $same_city_rate = true;
        $same_county_rate = true;

        foreach ($rows as $row) {
            if (abs($row['city_rate'] - $first['city_rate']) > 0.000001) {
                $same_city_rate = false;
            }
            if (abs($row['county_rate'] - $first['county_rate']) > 0.000001) {
                $same_county_rate = false;
            }
        }

        if (!$same_city_rate || !$same_county_rate) {
            return null;
        }

        return [
            'city_name'   => $first['city_name'],
            'city_rate'   => $first['city_rate'],
            'county_name' => $first['county_name'],
            'county_rate' => $first['county_rate'],
            'specials'    => [],
            'match_type'  => $match_type,
            'confidence'  => Tax_Quote_Result::CONFIDENCE_MEDIUM,
        ];
    }

    /**
     * Normalize city/county labels for matching.
     */
    private function normalize_name(string $value): string
    {
        $value = strtoupper($value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = str_replace(['.', ',', '&', '/'], [' ', ' ', ' AND ', ' '], $value);
        $value = preg_replace('/\bCOUNTY\b/', '', $value);
        $value = preg_replace('/\bCO\b/', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }
}
