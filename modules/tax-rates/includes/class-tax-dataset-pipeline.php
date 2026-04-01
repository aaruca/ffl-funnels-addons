<?php
/**
 * Tax Dataset Pipeline.
 *
 * Rebuilds local per-state tax datasets from a shared Google Sheets CSV export
 * and stores normalized ZIP, city, and state-floor rows in
 * dataset_versions/jurisdiction_rates.
 *
 * Runtime checkout and quote lookups read only from these local datasets.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Dataset_Pipeline
{
    const SHEET_SOURCE_CODE = 'google_sheet_zip_rates';
    const FRESHNESS_POLICY  = '45d';
    const DEFAULT_SHEET_URL = 'https://docs.google.com/spreadsheets/d/1lhFA1vDtbCNt0WA_oasyyW4m4RsyLpFnXXPWDOzSSb4/edit?usp=sharing';

    /**
     * Canonical supported US states including DC.
     *
     * @var array<string,string>
     */
    const STATE_NAMES = [
        'AL' => 'Alabama',
        'AK' => 'Alaska',
        'AZ' => 'Arizona',
        'AR' => 'Arkansas',
        'CA' => 'California',
        'CO' => 'Colorado',
        'CT' => 'Connecticut',
        'DE' => 'Delaware',
        'DC' => 'District of Columbia',
        'FL' => 'Florida',
        'GA' => 'Georgia',
        'HI' => 'Hawaii',
        'ID' => 'Idaho',
        'IL' => 'Illinois',
        'IN' => 'Indiana',
        'IA' => 'Iowa',
        'KS' => 'Kansas',
        'KY' => 'Kentucky',
        'LA' => 'Louisiana',
        'ME' => 'Maine',
        'MD' => 'Maryland',
        'MA' => 'Massachusetts',
        'MI' => 'Michigan',
        'MN' => 'Minnesota',
        'MS' => 'Mississippi',
        'MO' => 'Missouri',
        'MT' => 'Montana',
        'NE' => 'Nebraska',
        'NV' => 'Nevada',
        'NH' => 'New Hampshire',
        'NJ' => 'New Jersey',
        'NM' => 'New Mexico',
        'NY' => 'New York',
        'NC' => 'North Carolina',
        'ND' => 'North Dakota',
        'OH' => 'Ohio',
        'OK' => 'Oklahoma',
        'OR' => 'Oregon',
        'PA' => 'Pennsylvania',
        'RI' => 'Rhode Island',
        'SC' => 'South Carolina',
        'SD' => 'South Dakota',
        'TN' => 'Tennessee',
        'TX' => 'Texas',
        'UT' => 'Utah',
        'VT' => 'Vermont',
        'VA' => 'Virginia',
        'WA' => 'Washington',
        'WV' => 'West Virginia',
        'WI' => 'Wisconsin',
        'WY' => 'Wyoming',
    ];

    /**
     * Sync datasets from the configured sheet source.
     *
     * @param  string $source 'all' or specific source code.
     * @return array<string,array<string,mixed>>
     */
    public static function sync(string $source = 'all'): array
    {
        $results = [];

        if ($source === 'all' || $source === self::SHEET_SOURCE_CODE) {
            $results['sheet'] = self::sync_sheet_datasets();
        }

        return $results;
    }

    /**
     * Sync a single state.
     *
     * @return array<string,mixed>
     */
    public static function sync_state(string $state_code): array
    {
        $rows = self::fetch_sheet_rows(self::get_sheet_export_url());
        if (is_wp_error($rows)) {
            return [
                'success'             => false,
                'skipped'             => false,
                'state'               => strtoupper($state_code),
                'rows'                => 0,
                'version_id'          => null,
                'city_rows'           => 0,
                'zip_rows'            => 0,
                'updated'             => '',
                'source_url'          => self::get_sheet_export_url(),
                'error'               => $rows->get_error_message(),
            ];
        }

        $grouped = self::group_rows_by_state($rows);

        return self::import_sheet_state(
            strtoupper($state_code),
            $grouped[strtoupper($state_code)] ?? [],
            self::get_sheet_export_url()
        );
    }

    /**
     * Return the states whose sheet datasets should be imported.
     *
     * @return string[]
     */
    public static function get_target_sheet_states(): array
    {
        $states = array_keys(self::STATE_NAMES);

        if (!class_exists('Tax_Coverage') || !Tax_Coverage::has_state_filter()) {
            return $states;
        }

        return array_values(array_intersect($states, Tax_Coverage::get_enabled_states()));
    }

    /**
     * Check whether a state already has an active imported sheet dataset.
     */
    public static function has_active_sheet_dataset(string $state_code): bool
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE source_code = %s
               AND state_code = %s
               AND status = 'active'",
            self::SHEET_SOURCE_CODE,
            strtoupper($state_code)
        ));
    }

    /**
     * Return the effective coverage status implied by the active local dataset.
     */
    public static function get_active_sheet_coverage_status(string $state_code): string
    {
        global $wpdb;

        $dataset_table = Tax_Resolver_DB::table('dataset_versions');
        $rates_table   = Tax_Resolver_DB::table('jurisdiction_rates');
        $state_code    = strtoupper($state_code);

        $dataset_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id
             FROM {$dataset_table}
             WHERE source_code = %s
               AND state_code = %s
               AND status = 'active'
             ORDER BY effective_date DESC, id DESC
             LIMIT 1",
            self::SHEET_SOURCE_CODE,
            $state_code
        ));

        if ($dataset_id <= 0) {
            return Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
        }

        $positive_rows = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$rates_table}
             WHERE dataset_version_id = %d
               AND rate > 0",
            $dataset_id
        ));

        return $positive_rows > 0
            ? Tax_Coverage::SUPPORTED_ADDRESS_RATE
            : Tax_Coverage::NO_SALES_TAX;
    }

    /**
     * Get all active sheet datasets.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_active_versions(): array
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE status = 'active'
               AND source_code = %s
             ORDER BY state_code ASC, effective_date DESC",
            self::SHEET_SOURCE_CODE
        ), ARRAY_A) ?: [];
    }

    /**
     * Delete all local sheet dataset rows for a deselected state.
     *
     * @return array<string,int|string>
     */
    public static function purge_state_dataset(string $state_code): array
    {
        global $wpdb;

        $state_code   = strtoupper($state_code);
        $dataset_table = Tax_Resolver_DB::table('dataset_versions');
        $rates_table   = Tax_Resolver_DB::table('jurisdiction_rates');

        $versions = $wpdb->get_results($wpdb->prepare(
            "SELECT id, row_count
             FROM {$dataset_table}
             WHERE source_code = %s
               AND state_code = %s",
            self::SHEET_SOURCE_CODE,
            $state_code
        ), ARRAY_A) ?: [];

        $deleted_versions = 0;
        $deleted_rows     = 0;

        foreach ($versions as $version) {
            $version_id = (int) ($version['id'] ?? 0);
            if ($version_id <= 0) {
                continue;
            }

            $deleted_rows += (int) ($version['row_count'] ?? 0);

            $wpdb->delete($rates_table, ['dataset_version_id' => $version_id], ['%d']);
            $wpdb->delete($dataset_table, ['id' => $version_id], ['%d']);

            $deleted_versions++;
        }

        Tax_Resolver_DB::clear_state_cache($state_code);
        Tax_Coverage::update_state(
            $state_code,
            Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED,
            'sheet_zip_dataset',
            'State removed from store selection; local sheet dataset was deleted.'
        );

        return [
            'state'            => $state_code,
            'deleted_versions' => $deleted_versions,
            'deleted_rows'     => $deleted_rows,
        ];
    }

    /**
     * Get the configured sheet URL from settings.
     */
    public static function get_configured_sheet_url(): string
    {
        $settings = get_option('ffla_tax_resolver_settings', []);
        $raw = trim((string) ($settings['sheet_source_url'] ?? ''));

        return $raw !== '' ? $raw : self::DEFAULT_SHEET_URL;
    }

    /**
     * Convert a shared Google Sheets URL into a CSV export URL when needed.
     */
    public static function get_sheet_export_url(?string $url = null): string
    {
        $url = trim((string) ($url ?? self::get_configured_sheet_url()));
        if ($url === '') {
            $url = self::DEFAULT_SHEET_URL;
        }

        if (stripos($url, 'docs.google.com/spreadsheets') === false) {
            return $url;
        }

        if (stripos($url, '/export?format=csv') !== false) {
            return $url;
        }

        if (preg_match('#/d/([a-zA-Z0-9-_]+)#', $url, $matches)) {
            return 'https://docs.google.com/spreadsheets/d/' . $matches[1] . '/export?format=csv';
        }

        return $url;
    }

    /**
     * Build one-line source status for admin and health responses.
     *
     * @return array<string,mixed>
     */
    public static function get_source_status(): array
    {
        $share_url  = self::get_configured_sheet_url();
        $export_url = self::get_sheet_export_url($share_url);

        return [
            'configured' => trim($share_url) !== '',
            'shareUrl'   => $share_url,
            'exportUrl'  => $export_url,
        ];
    }

    /**
     * Import the configured sheet for all target states.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function sync_sheet_datasets(): array
    {
        $results     = [];
        $target_keys = self::get_target_sheet_states();
        $source_url  = self::get_sheet_export_url();
        $sheet_rows  = self::fetch_sheet_rows($source_url);

        if (is_wp_error($sheet_rows)) {
            foreach ($target_keys as $state_code) {
                $results[$state_code] = [
                    'success'             => false,
                    'skipped'             => false,
                    'state'               => $state_code,
                    'rows'                => 0,
                    'version_id'          => null,
                    'city_rows'           => 0,
                    'zip_rows'            => 0,
                    'updated'             => '',
                    'source_url'          => $source_url,
                    'error'               => $sheet_rows->get_error_message(),
                ];
            }

            return $results;
        }

        $grouped = self::group_rows_by_state($sheet_rows);

        foreach ($target_keys as $state_code) {
            $results[$state_code] = self::import_sheet_state(
                $state_code,
                $grouped[$state_code] ?? [],
                $source_url
            );
        }

        return $results;
    }

    /**
     * Build one state's dataset from grouped CSV rows and persist it locally.
     *
     * @param  array<int,array<string,string>> $state_rows
     * @return array<string,mixed>
     */
    private static function import_sheet_state(string $state_code, array $state_rows, string $source_url): array
    {
        $state_code = strtoupper($state_code);
        $state_name = self::STATE_NAMES[$state_code] ?? $state_code;
        $result = [
            'success'             => false,
            'skipped'             => false,
            'state'               => $state_code,
            'rows'                => 0,
            'version_id'          => null,
            'city_rows'           => 0,
            'zip_rows'            => 0,
            'updated'             => '',
            'source_url'          => $source_url,
            'error'               => null,
        ];

        $dataset = self::build_state_dataset($state_code, $state_name, $state_rows, $source_url);
        if (is_wp_error($dataset)) {
            $result['error'] = $dataset->get_error_message();
            return $result;
        }

        $result['updated']   = (string) ($dataset['updated'] ?? '');
        $result['city_rows'] = (int) ($dataset['cityRowCount'] ?? 0);
        $result['zip_rows']  = (int) ($dataset['zipRowCount'] ?? 0);

        $rates = self::build_rate_rows($dataset);
        if (empty($rates)) {
            $result['error'] = sprintf('The Google Sheet did not produce importable tax rows for %s.', $state_code);
            return $result;
        }

        $checksum = self::build_checksum($dataset);

        if (self::active_checksum_exists(self::SHEET_SOURCE_CODE, $state_code, $checksum)) {
            Tax_Resolver_DB::clear_state_cache($state_code);
            self::mark_state_supported($state_code, $dataset, true);

            $result['success'] = true;
            $result['skipped'] = true;
            $result['rows']    = count($rates);

            return $result;
        }

        $version_label = !empty($dataset['updated'])
            ? 'Sheet ' . $dataset['updated']
            : 'Sheet ' . wp_date('Y-m-d');

        $version_id = self::create_version(
            self::SHEET_SOURCE_CODE,
            $state_code,
            $version_label,
            (string) ($dataset['effectiveDate'] ?? wp_date('Y-m-d')),
            $checksum,
            $source_url,
            count($rates),
            self::FRESHNESS_POLICY,
            self::build_version_notes($dataset)
        );

        if (!$version_id) {
            $result['error'] = 'Failed to create dataset version.';
            return $result;
        }

        $inserted = self::insert_rates($version_id, $rates);
        if ($inserted !== count($rates)) {
            self::delete_version($version_id);
            $result['error'] = sprintf(
                'Only %1$d of %2$d jurisdiction rows were stored for %3$s.',
                $inserted,
                count($rates),
                $state_code
            );
            return $result;
        }

        self::promote_version($version_id, self::SHEET_SOURCE_CODE, $state_code);
        Tax_Resolver_DB::clear_state_cache($state_code);
        self::mark_state_supported($state_code, $dataset);

        $result['success']    = true;
        $result['rows']       = $inserted;
        $result['version_id'] = $version_id;

        return $result;
    }

    /**
     * Convert grouped sheet rows into the normalized dataset payload.
     *
     * @param  array<int,array<string,string>> $state_rows
     * @return array<string,mixed>|\WP_Error
     */
    private static function build_state_dataset(string $state_code, string $state_name, array $state_rows, string $source_url)
    {
        $prepared_rows = [];
        $latest_year   = 0;
        $latest_month  = 0;

        foreach ($state_rows as $raw_row) {
            $prepared = self::prepare_sheet_row($state_code, $raw_row);
            if ($prepared === null) {
                continue;
            }

            $prepared_rows[] = $prepared;

            if (
                $prepared['year'] > $latest_year ||
                ($prepared['year'] === $latest_year && $prepared['month'] > $latest_month)
            ) {
                $latest_year  = (int) $prepared['year'];
                $latest_month = (int) $prepared['month'];
            }
        }

        if (empty($prepared_rows)) {
            return new \WP_Error(
                'sheet_state_empty',
                sprintf('The shared tax sheet has no usable ZIP rows for %s.', $state_code)
            );
        }

        $zip_rows   = self::collapse_zip_rows($prepared_rows);
        $city_rows  = self::build_city_rows($zip_rows);
        $state_rate = self::determine_state_floor_rate($zip_rows);

        $updated = ($latest_year > 0 && $latest_month > 0)
            ? sprintf('%04d-%02d', $latest_year, $latest_month)
            : wp_date('Y-m');

        $effective_date = ($latest_year > 0 && $latest_month > 0)
            ? sprintf('%04d-%02d-01', $latest_year, $latest_month)
            : wp_date('Y-m-01');

        return [
            'stateCode'      => $state_code,
            'stateName'      => $state_name,
            'sourceUrl'      => $source_url,
            'updated'        => $updated,
            'effectiveDate'  => $effective_date,
            'stateRate'      => $state_rate,
            'cityRowCount'   => count($city_rows),
            'zipRowCount'    => count($zip_rows),
            'cities'         => array_values($city_rows),
            'zipRows'        => array_values($zip_rows),
        ];
    }

    /**
     * Prepare one CSV row for import.
     *
     * @param  array<string,string> $raw_row
     * @return array<string,mixed>|null
     */
    private static function prepare_sheet_row(string $state_code, array $raw_row): ?array
    {
        $row_state = strtoupper(trim((string) ($raw_row['State'] ?? '')));
        if ($row_state !== $state_code) {
            return null;
        }

        $zip_code = preg_replace('/[^0-9]/', '', (string) ($raw_row['ZipCode'] ?? ''));
        $zip_code = strlen($zip_code) >= 5 ? substr($zip_code, 0, 5) : '';
        if ($zip_code === '') {
            return null;
        }

        $city_source = trim((string) ($raw_row['City'] ?? ''));
        $city_label = Sheet_ZIP_Dataset_Resolver::normalize_city_label($city_source);
        if ($city_label === '') {
            $city_label = self::normalize_display_label((string) ($raw_row['TaxRegionName'] ?? ''));
        }

        $city_key_source = trim((string) ($raw_row['NormalizedCity'] ?? ''));
        $city_key = Sheet_ZIP_Dataset_Resolver::normalize_city_key(
            $city_key_source !== '' ? $city_key_source : $city_label
        );

        if ($city_key === '') {
            return null;
        }

        $combined_rate = self::parse_percent_value(
            self::first_non_empty([
                (string) ($raw_row['AdjustedCombinedRate'] ?? ''),
                (string) ($raw_row['CombinedRate'] ?? ''),
            ])
        );

        if ($combined_rate === null) {
            return null;
        }

        return [
            'zip'              => $zip_code,
            'state'            => $state_code,
            'city_key'         => $city_key,
            'city_label'       => $city_label,
            'county_label'     => self::normalize_display_label((string) ($raw_row['County'] ?? '')),
            'tax_region_label' => self::normalize_display_label((string) ($raw_row['TaxRegionName'] ?? '')),
            'combined_rate'    => $combined_rate,
            'state_rate'       => self::parse_percent_value((string) ($raw_row['StateRate'] ?? '')) ?? 0.0,
            'county_rate'      => self::parse_percent_value((string) ($raw_row['CountyRate'] ?? '')) ?? 0.0,
            'city_rate'        => self::parse_percent_value((string) ($raw_row['CityRate'] ?? '')) ?? 0.0,
            'special_rate'     => self::parse_percent_value((string) ($raw_row['SpecialRate'] ?? '')) ?? 0.0,
            'year'             => max(0, (int) ($raw_row['Year'] ?? 0)),
            'month'            => max(0, (int) ($raw_row['Month'] ?? 0)),
        ];
    }

    /**
     * Reduce duplicate ZIP rows down to the strongest row per ZIP.
     *
     * @param  array<int,array<string,mixed>> $prepared_rows
     * @return array<string,array<string,mixed>>
     */
    private static function collapse_zip_rows(array $prepared_rows): array
    {
        $collapsed = [];
        $variants  = [];

        foreach ($prepared_rows as $row) {
            $zip = (string) $row['zip'];
            $signature = implode('|', [
                number_format((float) $row['combined_rate'], 6, '.', ''),
                number_format((float) $row['state_rate'], 6, '.', ''),
                number_format((float) $row['county_rate'], 6, '.', ''),
                number_format((float) $row['city_rate'], 6, '.', ''),
                number_format((float) $row['special_rate'], 6, '.', ''),
                (string) $row['tax_region_label'],
                (string) $row['city_key'],
            ]);
            $variants[$zip][$signature] = true;

            if (
                !isset($collapsed[$zip]) ||
                (float) $row['combined_rate'] > (float) $collapsed[$zip]['combined_rate']
            ) {
                $collapsed[$zip] = $row;
            }
        }

        foreach ($collapsed as $zip => &$row) {
            $variant_count = count($variants[$zip] ?? []);
            $row['notes'] = $variant_count > 1
                ? 'Multiple rows were present for this ZIP in the shared sheet; the highest combined rate was stored.'
                : '';
        }
        unset($row);

        ksort($collapsed);

        return $collapsed;
    }

    /**
     * Build city fallback rows from ZIP rows.
     *
     * @param  array<string,array<string,mixed>> $zip_rows
     * @return array<string,array<string,mixed>>
     */
    private static function build_city_rows(array $zip_rows): array
    {
        $groups = [];

        foreach ($zip_rows as $row) {
            $city_key = (string) $row['city_key'];
            if ($city_key === '') {
                continue;
            }

            $groups[$city_key][] = $row;
        }

        $cities = [];

        foreach ($groups as $city_key => $rows) {
            $top = null;
            $unique_rates = [];

            foreach ($rows as $row) {
                $rate_key = number_format((float) $row['combined_rate'], 6, '.', '');
                $unique_rates[$rate_key] = true;

                if ($top === null || (float) $row['combined_rate'] > (float) $top['combined_rate']) {
                    $top = $row;
                }
            }

            if ($top === null) {
                continue;
            }

            $notes = [];
            if (count($unique_rates) > 1) {
                $notes[] = 'Multiple ZIP rates exist for this city in the shared sheet; ZIP input will return a more precise result.';
            }
            if (!empty($top['notes'])) {
                $notes[] = (string) $top['notes'];
            }

            $cities[$city_key] = [
                'city_key'  => $city_key,
                'label'     => (string) $top['city_label'],
                'rate'      => (float) $top['combined_rate'],
                'ambiguous' => count($unique_rates) > 1,
                'notes'     => trim(implode(' ', array_filter($notes))),
            ];
        }

        ksort($cities);

        return $cities;
    }

    /**
     * Determine the most representative state floor from the grouped rows.
     *
     * @param  array<string,array<string,mixed>> $zip_rows
     */
    private static function determine_state_floor_rate(array $zip_rows): float
    {
        $counts = [];

        foreach ($zip_rows as $row) {
            $rate = (float) ($row['state_rate'] ?? 0);
            $key = number_format($rate, 6, '.', '');
            if (!isset($counts[$key])) {
                $counts[$key] = 0;
            }
            $counts[$key]++;
        }

        if (empty($counts)) {
            return 0.0;
        }

        arsort($counts);
        $top_key = (string) array_key_first($counts);

        return (float) $top_key;
    }

    /**
     * Convert the normalized dataset into jurisdiction_rates rows.
     *
     * @param  array<string,mixed> $dataset
     * @return array<int,array<string,mixed>>
     */
    private static function build_rate_rows(array $dataset): array
    {
        $state_code     = strtoupper((string) ($dataset['stateCode'] ?? ''));
        $state_name     = trim((string) ($dataset['stateName'] ?? $state_code));
        $effective_date = (string) ($dataset['effectiveDate'] ?? wp_date('Y-m-d'));
        $rates          = [];

        $rates[] = [
            'state_code'        => $state_code,
            'jurisdiction_fips' => null,
            'jurisdiction_code' => 'STATE_FLOOR',
            'jurisdiction_type' => 'state',
            'jurisdiction_name' => $state_name . ' State Floor',
            'rate'              => max(0.0, (float) ($dataset['stateRate'] ?? 0)),
            'rate_type'         => 'general',
            'effective_date'    => $effective_date,
            'expires_at'        => null,
            'zip_codes'         => null,
            'city_names'        => null,
            'notes'             => 'Imported from the shared Google Sheets ZIP dataset.',
        ];

        foreach (($dataset['cities'] ?? []) as $city_row) {
            if (!is_array($city_row)) {
                continue;
            }

            $city_key   = trim((string) ($city_row['city_key'] ?? ''));
            $city_label = trim((string) ($city_row['label'] ?? ''));
            $rate       = max(0.0, (float) ($city_row['rate'] ?? 0));

            if ($city_key === '' || $city_label === '') {
                continue;
            }

            $rates[] = [
                'state_code'        => $state_code,
                'jurisdiction_fips' => null,
                'jurisdiction_code' => 'CITY_' . $city_key,
                'jurisdiction_type' => 'city',
                'jurisdiction_name' => $city_label . ' Total',
                'rate'              => $rate,
                'rate_type'         => 'general',
                'effective_date'    => $effective_date,
                'expires_at'        => null,
                'zip_codes'         => null,
                'city_names'        => $city_key,
                'notes'             => !empty($city_row['notes']) ? (string) $city_row['notes'] : null,
            ];
        }

        foreach (($dataset['zipRows'] ?? []) as $zip_row) {
            if (!is_array($zip_row)) {
                continue;
            }

            $zip_code         = (string) ($zip_row['zip'] ?? '');
            $city_key         = (string) ($zip_row['city_key'] ?? '');
            $city_label       = (string) ($zip_row['city_label'] ?? '');
            $county_label     = (string) ($zip_row['county_label'] ?? '');
            $tax_region_label = (string) ($zip_row['tax_region_label'] ?? '');
            $combined_rate    = max(0.0, (float) ($zip_row['combined_rate'] ?? 0));
            $state_rate       = max(0.0, (float) ($zip_row['state_rate'] ?? 0));
            $county_rate      = max(0.0, (float) ($zip_row['county_rate'] ?? 0));
            $city_rate        = max(0.0, (float) ($zip_row['city_rate'] ?? 0));
            $special_rate     = max(0.0, (float) ($zip_row['special_rate'] ?? 0));
            $notes            = trim((string) ($zip_row['notes'] ?? ''));

            if ($zip_code === '') {
                continue;
            }

            $base_name = $tax_region_label !== ''
                ? $tax_region_label
                : ($city_label !== '' ? $city_label : ($state_code . ' ' . $zip_code));

            $rates[] = [
                'state_code'        => $state_code,
                'jurisdiction_fips' => null,
                'jurisdiction_code' => 'ZIP_TOTAL_' . $zip_code,
                'jurisdiction_type' => 'zip_total',
                'jurisdiction_name' => $base_name . ' ' . $zip_code . ' Total',
                'rate'              => $combined_rate,
                'rate_type'         => 'general',
                'effective_date'    => $effective_date,
                'expires_at'        => null,
                'zip_codes'         => $zip_code,
                'city_names'        => $city_key !== '' ? $city_key : null,
                'notes'             => $notes !== '' ? $notes : 'Imported from the shared Google Sheets ZIP dataset.',
            ];

            if ($state_rate > 0 || ($combined_rate === 0.0 && $county_rate === 0.0 && $city_rate === 0.0 && $special_rate === 0.0)) {
                $rates[] = [
                    'state_code'        => $state_code,
                    'jurisdiction_fips' => null,
                    'jurisdiction_code' => 'ZIP_STATE_' . $zip_code,
                    'jurisdiction_type' => 'zip_state',
                    'jurisdiction_name' => $state_name . ' State Tax',
                    'rate'              => $state_rate,
                    'rate_type'         => 'general',
                    'effective_date'    => $effective_date,
                    'expires_at'        => null,
                    'zip_codes'         => $zip_code,
                    'city_names'        => $city_key !== '' ? $city_key : null,
                    'notes'             => null,
                ];
            }

            if ($county_rate > 0) {
                $rates[] = [
                    'state_code'        => $state_code,
                    'jurisdiction_fips' => null,
                    'jurisdiction_code' => 'ZIP_COUNTY_' . $zip_code,
                    'jurisdiction_type' => 'zip_county',
                    'jurisdiction_name' => $county_label !== '' ? $county_label : ($state_name . ' County Tax'),
                    'rate'              => $county_rate,
                    'rate_type'         => 'general',
                    'effective_date'    => $effective_date,
                    'expires_at'        => null,
                    'zip_codes'         => $zip_code,
                    'city_names'        => $city_key !== '' ? $city_key : null,
                    'notes'             => null,
                ];
            }

            if ($city_rate > 0) {
                $rates[] = [
                    'state_code'        => $state_code,
                    'jurisdiction_fips' => null,
                    'jurisdiction_code' => 'ZIP_CITY_' . $zip_code,
                    'jurisdiction_type' => 'zip_city',
                    'jurisdiction_name' => $city_label !== '' ? $city_label : ($state_name . ' City Tax'),
                    'rate'              => $city_rate,
                    'rate_type'         => 'general',
                    'effective_date'    => $effective_date,
                    'expires_at'        => null,
                    'zip_codes'         => $zip_code,
                    'city_names'        => $city_key !== '' ? $city_key : null,
                    'notes'             => null,
                ];
            }

            if ($special_rate > 0) {
                $rates[] = [
                    'state_code'        => $state_code,
                    'jurisdiction_fips' => null,
                    'jurisdiction_code' => 'ZIP_SPECIAL_' . $zip_code,
                    'jurisdiction_type' => 'zip_special',
                    'jurisdiction_name' => $tax_region_label !== '' ? $tax_region_label : 'Special District',
                    'rate'              => $special_rate,
                    'rate_type'         => 'general',
                    'effective_date'    => $effective_date,
                    'expires_at'        => null,
                    'zip_codes'         => $zip_code,
                    'city_names'        => $city_key !== '' ? $city_key : null,
                    'notes'             => null,
                ];
            }
        }

        return $rates;
    }

    /**
     * Check whether the normalized dataset is an all-zero-tax state.
     */
    private static function dataset_is_zero_tax(array $dataset): bool
    {
        if ((float) ($dataset['stateRate'] ?? 0) > 0) {
            return false;
        }

        foreach (($dataset['cities'] ?? []) as $city_row) {
            if (is_array($city_row) && (float) ($city_row['rate'] ?? 0) > 0) {
                return false;
            }
        }

        foreach (($dataset['zipRows'] ?? []) as $zip_row) {
            if (!is_array($zip_row)) {
                continue;
            }

            foreach (['combined_rate', 'state_rate', 'county_rate', 'city_rate', 'special_rate'] as $key) {
                if ((float) ($zip_row[$key] ?? 0) > 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Generate a stable checksum for a normalized sheet dataset payload.
     */
    private static function build_checksum(array $dataset): string
    {
        return hash('sha256', wp_json_encode([
            'stateCode' => $dataset['stateCode'] ?? '',
            'updated'   => (string) ($dataset['updated'] ?? ''),
            'stateRate' => (float) ($dataset['stateRate'] ?? 0),
            'cities'    => array_map(static function (array $row): array {
                return [
                    'city_key'  => (string) ($row['city_key'] ?? ''),
                    'label'     => (string) ($row['label'] ?? ''),
                    'rate'      => (float) ($row['rate'] ?? 0),
                    'ambiguous' => !empty($row['ambiguous']),
                    'notes'     => (string) ($row['notes'] ?? ''),
                ];
            }, is_array($dataset['cities'] ?? null) ? $dataset['cities'] : []),
            'zipRows' => array_map(static function (array $row): array {
                return [
                    'zip'              => (string) ($row['zip'] ?? ''),
                    'city_key'         => (string) ($row['city_key'] ?? ''),
                    'combined_rate'    => (float) ($row['combined_rate'] ?? 0),
                    'state_rate'       => (float) ($row['state_rate'] ?? 0),
                    'county_rate'      => (float) ($row['county_rate'] ?? 0),
                    'city_rate'        => (float) ($row['city_rate'] ?? 0),
                    'special_rate'     => (float) ($row['special_rate'] ?? 0),
                    'tax_region_label' => (string) ($row['tax_region_label'] ?? ''),
                    'notes'            => (string) ($row['notes'] ?? ''),
                ];
            }, is_array($dataset['zipRows'] ?? null) ? $dataset['zipRows'] : []),
        ]));
    }

    /**
     * Build dataset version notes for auditing.
     */
    private static function build_version_notes(array $dataset): string
    {
        $parts = [];
        $parts[] = 'Local dataset imported from the shared Google Sheets CSV source.';
        $parts[] = sprintf('%d ZIP rows imported.', (int) ($dataset['zipRowCount'] ?? 0));
        $parts[] = sprintf('%d city fallback rows imported.', (int) ($dataset['cityRowCount'] ?? 0));

        if (!empty($dataset['updated'])) {
            $parts[] = 'Source label: ' . trim((string) $dataset['updated']) . '.';
        }

        return trim(implode(' ', array_filter($parts)));
    }

    /**
     * Update coverage notes after a successful or unchanged sheet import.
     *
     * @param array<string,mixed> $dataset
     */
    private static function mark_state_supported(string $state_code, array $dataset, bool $unchanged = false): void
    {
        $is_zero_tax = self::dataset_is_zero_tax($dataset);
        $prefix = $unchanged
            ? 'Google Sheet ZIP dataset already current.'
            : 'Google Sheet ZIP dataset rebuilt locally.';
        $suffix = !empty($dataset['updated']) ? ' Source label: ' . $dataset['updated'] . '.' : '';
        $status = $is_zero_tax ? Tax_Coverage::NO_SALES_TAX : Tax_Coverage::SUPPORTED_ADDRESS_RATE;
        $summary = $is_zero_tax
            ? sprintf(
                '%1$s Imported data for this state is zero tax across %2$d ZIP rows and %3$d city fallback rows.%4$s',
                $prefix,
                (int) ($dataset['zipRowCount'] ?? 0),
                (int) ($dataset['cityRowCount'] ?? 0),
                $suffix
            )
            : sprintf(
                '%1$s %2$d ZIP rows and %3$d city fallback rows available.%4$s',
                $prefix,
                (int) ($dataset['zipRowCount'] ?? 0),
                (int) ($dataset['cityRowCount'] ?? 0),
                $suffix
            );

        Tax_Coverage::update_state(
            $state_code,
            $status,
            'sheet_zip_dataset',
            $summary
        );
    }

    /**
     * Fetch and parse the public Google Sheets CSV.
     *
     * @return array<int,array<string,string>>|\WP_Error
     */
    private static function fetch_sheet_rows(string $source_url)
    {
        $response = wp_remote_get($source_url, [
            'timeout'     => 60,
            'redirection' => 5,
            'decompress'  => true,
            'headers'     => [
                'Accept'          => 'text/csv,text/plain;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Cache-Control'   => 'no-cache',
                'Pragma'          => 'no-cache',
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36',
            ],
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error(
                'sheet_fetch_failed',
                'Could not fetch the shared tax sheet: ' . $response->get_error_message()
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if ($code !== 200) {
            return new \WP_Error(
                'sheet_fetch_http_error',
                sprintf('The shared tax sheet returned HTTP %d.', $code)
            );
        }

        if (trim($body) === '') {
            return new \WP_Error(
                'sheet_fetch_empty',
                'The shared tax sheet returned an empty CSV.'
            );
        }

        $rows = self::parse_csv_body($body);

        if (empty($rows)) {
            return new \WP_Error(
                'sheet_csv_empty',
                'The shared tax sheet CSV did not contain any data rows.'
            );
        }

        return $rows;
    }

    /**
     * Parse CSV text into associative rows.
     *
     * @return array<int,array<string,string>>
     */
    private static function parse_csv_body(string $csv): array
    {
        $rows = [];
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $csv);
        rewind($handle);

        $headers = fgetcsv($handle);
        if (!is_array($headers)) {
            fclose($handle);
            return [];
        }

        $headers = array_map(static function ($header): string {
            $header = (string) $header;
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
            return trim($header);
        }, $headers);

        while (($row = fgetcsv($handle)) !== false) {
            if (!is_array($row) || count($row) === 0) {
                continue;
            }

            $assoc = [];
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $assoc[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
            }

            if (!empty($assoc)) {
                $rows[] = $assoc;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Group parsed CSV rows by supported state code.
     *
     * @param  array<int,array<string,string>> $rows
     * @return array<string,array<int,array<string,string>>>
     */
    private static function group_rows_by_state(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $state_code = strtoupper(trim((string) ($row['State'] ?? '')));
            if (!isset(self::STATE_NAMES[$state_code])) {
                continue;
            }

            if (!isset($grouped[$state_code])) {
                $grouped[$state_code] = [];
            }

            $grouped[$state_code][] = $row;
        }

        return $grouped;
    }

    /**
     * Normalize display labels like "HILLSBOROUGH COUNTY" for admin and checkout.
     */
    private static function normalize_display_label(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($value)));
        if ($value === '') {
            return '';
        }

        $lower = strtolower($value);
        return function_exists('mb_convert_case')
            ? (string) mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8')
            : ucwords($lower);
    }

    /**
     * Parse a numeric percent value like "7.5" into a decimal rate.
     */
    private static function parse_percent_value(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $number = preg_replace('/[^0-9.\-]+/', '', $value);
        if ($number === '' || !is_numeric($number)) {
            return null;
        }

        return ((float) $number) / 100;
    }

    /**
     * Return the first non-empty value from a list.
     *
     * @param  array<int,string> $values
     */
    private static function first_non_empty(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Check if an identical active checksum already exists for a state.
     */
    private static function active_checksum_exists(string $source_code, string $state_code, string $checksum): bool
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE source_code = %s
               AND state_code = %s
               AND checksum = %s
               AND status = 'active'",
            $source_code,
            strtoupper($state_code),
            $checksum
        ));
    }

    /**
     * Create a dataset version record.
     *
     * @return int|null Version ID or null on failure.
     */
    private static function create_version(
        string $source_code,
        string $state_code,
        string $version_label,
        string $effective_date,
        string $checksum,
        ?string $storage_uri,
        int $row_count,
        string $freshness_policy = '90d',
        ?string $notes = null
    ): ?int {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');

        $inserted = $wpdb->insert($table, [
            'source_code'      => $source_code,
            'state_code'       => strtoupper($state_code),
            'version_label'    => $version_label,
            'effective_date'   => $effective_date,
            'loaded_at'        => current_time('mysql'),
            'checksum'         => $checksum,
            'freshness_policy' => $freshness_policy,
            'status'           => 'pending',
            'storage_uri'      => $storage_uri,
            'row_count'        => $row_count,
            'notes'            => $notes,
        ]);

        return $inserted ? (int) $wpdb->insert_id : null;
    }

    /**
     * Insert rate entries for a dataset version.
     *
     * @param  int                            $version_id
     * @param  array<int,array<string,mixed>> $rates
     * @return int Number of rows inserted.
     */
    private static function insert_rates(int $version_id, array $rates): int
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('jurisdiction_rates');
        $count = 0;

        foreach ($rates as $rate) {
            $inserted = $wpdb->insert($table, [
                'dataset_version_id' => $version_id,
                'state_code'         => $rate['state_code'],
                'jurisdiction_fips'  => $rate['jurisdiction_fips'],
                'jurisdiction_code'  => $rate['jurisdiction_code'],
                'jurisdiction_type'  => $rate['jurisdiction_type'],
                'jurisdiction_name'  => $rate['jurisdiction_name'],
                'rate'               => $rate['rate'],
                'rate_type'          => $rate['rate_type'] ?? 'general',
                'effective_date'     => $rate['effective_date'],
                'expires_at'         => $rate['expires_at'],
                'zip_codes'          => $rate['zip_codes'],
                'city_names'         => $rate['city_names'],
                'notes'              => $rate['notes'] ?? null,
            ]);

            if ($inserted) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Promote a version to active and deactivate previous versions.
     */
    private static function promote_version(int $version_id, string $source_code, string $state_code): void
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'superseded'
             WHERE source_code = %s
               AND state_code = %s
               AND id != %d
               AND status = 'active'",
            $source_code,
            strtoupper($state_code),
            $version_id
        ));

        $wpdb->update(
            $table,
            ['status' => 'active'],
            ['id' => $version_id]
        );
    }

    /**
     * Delete a failed pending version and its inserted rates.
     */
    private static function delete_version(int $version_id): void
    {
        global $wpdb;

        $rates_table    = Tax_Resolver_DB::table('jurisdiction_rates');
        $datasets_table = Tax_Resolver_DB::table('dataset_versions');

        $wpdb->delete($rates_table, ['dataset_version_id' => $version_id]);
        $wpdb->delete($datasets_table, ['id' => $version_id]);
    }
}
