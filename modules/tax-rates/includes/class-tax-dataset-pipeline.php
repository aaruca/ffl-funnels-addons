<?php
/**
 * Tax Dataset Pipeline.
 *
 * Downloads, validates, transforms, and imports official tax rate
 * datasets into the jurisdiction_rates table. Supports SST CSV
 * format and individual state file formats.
 *
 * Pipeline stages:
 *   1. Download — fetch file from official source or local upload
 *   2. Checksum — SHA-256 verification
 *   3. Validate — schema validation (columns, types, dates)
 *   4. Transform — parse to internal rate model
 *   5. Import — write to jurisdiction_rates with versioning
 *   6. Promote — activate version after validation
 *   7. Rollback — revert to previous version if needed
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Dataset_Pipeline
{
    const SST_RATES_INDEX = 'https://www.streamlinedsalestax.org/ratesandboundry/Rates/';
    const DOWNLOAD_TIMEOUT = 45;

    /**
     * Directory for storing downloaded dataset files.
     */
    public static function get_storage_dir(): string
    {
        $dir = WP_CONTENT_DIR . '/ffla-tax-datasets/';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
            // Protect with .htaccess.
            file_put_contents($dir . '.htaccess', "Deny from all\n");
        }
        return $dir;
    }

    /**
     * Sync datasets from all configured sources.
     *
     * @param  string $source 'all' or specific source code.
     * @return array  Sync results per source.
     */
    public static function sync(string $source = 'all'): array
    {
        $results = [];

        if ($source === 'all' || $source === 'sst_rate_boundary') {
            $results['sst'] = self::sync_sst();
        }

        return $results;
    }

    /**
     * Import an SST-format CSV file for a specific state.
     *
     * This method accepts a CSV file (either as a path or uploaded file)
     * and imports it into the jurisdiction_rates table.
     *
     * SST CSV format typically contains:
     *   State, JurisdictionType, JurisdictionFIPS, JurisdictionName,
     *   GeneralRateIntrastate, GeneralRateInterstate, ...
     *
     * @param  string $file_path    Path to CSV file.
     * @param  string $state_code   Two-letter state code.
     * @param  string $source_label Human-readable source label.
     * @return array  Import result.
     */
    public static function import_csv(
        string $file_path,
        string $state_code,
        string $source_label = 'manual_upload',
        ?string $version_label = null,
        ?string $storage_uri = null
    ): array
    {
        $result = [
            'success'     => false,
            'skipped'     => false,
            'state'       => $state_code,
            'rows'        => 0,
            'version_id'  => null,
            'error'       => null,
        ];

        if (!file_exists($file_path)) {
            $result['error'] = 'File not found.';
            return $result;
        }

        // Calculate checksum.
        $checksum = hash_file('sha256', $file_path);

        // Check for duplicate import.
        if (self::checksum_exists($checksum)) {
            $result['error'] = 'This exact file has already been imported.';
            return $result;
        }

        // Parse CSV.
        $rates = self::parse_sst_csv($file_path, $state_code);

        if (empty($rates)) {
            $result['error'] = 'No valid rate entries found in CSV file.';
            return $result;
        }

        // Create dataset version.
        $version_id = self::create_version(
            'sst_rate_boundary',
            $state_code,
            $version_label ?: pathinfo($file_path, PATHINFO_FILENAME),
            wp_date('Y-m-d'),
            $checksum,
            $storage_uri ?: $file_path,
            count($rates)
        );

        if (!$version_id) {
            $result['error'] = 'Failed to create dataset version record.';
            return $result;
        }

        // Import rates.
        $imported = self::insert_rates($version_id, $rates);

        // Promote this version (deactivate previous for same source+state).
        self::promote_version($version_id, 'sst_rate_boundary', $state_code);

        // Update coverage rule.
        Tax_Coverage::update_state(
            $state_code,
            Tax_Coverage::SUPPORTED_ADDRESS_RATE,
            'sst',
            "Imported from SST CSV. {$imported} rates. Version: {$state_code}-" . wp_date('Y-m-d')
        );

        $result['success']    = true;
        $result['rows']       = $imported;
        $result['version_id'] = $version_id;

        return $result;
    }

    /**
     * Import a simplified state rate table.
     *
     * For states where full SST files aren't available, accept a simple
     * format: state_code, jurisdiction_type, jurisdiction_name, rate, fips, zip_codes
     *
     * @param  array  $rates      Array of rate entries.
     * @param  string $state_code Two-letter state code.
     * @param  string $source     Source identifier.
     * @return array  Import result.
     */
    public static function import_rates(array $rates, string $state_code, string $source = 'manual'): array
    {
        $result = [
            'success'     => false,
            'state'       => $state_code,
            'rows'        => 0,
            'version_id'  => null,
            'error'       => null,
        ];

        if (empty($rates)) {
            $result['error'] = 'No rates provided.';
            return $result;
        }

        // Create dataset version.
        $version_label = $state_code . '-' . $source . '-' . wp_date('Y-m-d');
        $checksum      = hash('sha256', wp_json_encode($rates));

        $version_id = self::create_version(
            $source,
            $state_code,
            $version_label,
            wp_date('Y-m-d'),
            $checksum,
            null,
            count($rates)
        );

        if (!$version_id) {
            $result['error'] = 'Failed to create dataset version.';
            return $result;
        }

        // Normalize and insert rates.
        $normalized_rates = [];
        foreach ($rates as $r) {
            $normalized_rates[] = [
                'state_code'         => $state_code,
                'jurisdiction_fips'  => $r['fips'] ?? null,
                'jurisdiction_code'  => $r['code'] ?? ($r['fips'] ?? $r['name'] ?? ''),
                'jurisdiction_type'  => $r['type'] ?? 'state',
                'jurisdiction_name'  => $r['name'] ?? 'Statewide',
                'rate'               => (float) ($r['rate'] ?? 0),
                'rate_type'          => 'general',
                'effective_date'     => wp_date('Y-m-d'),
                'expires_at'         => null,
                'zip_codes'          => $r['zip_codes'] ?? null,
                'city_names'         => $r['city_names'] ?? null,
            ];
        }

        $imported = self::insert_rates($version_id, $normalized_rates);

        // Promote.
        self::promote_version($version_id, $source, $state_code);

        $result['success']    = true;
        $result['rows']       = $imported;
        $result['version_id'] = $version_id;

        return $result;
    }

    /* ── CSV Parsing ──────────────────────────────────────────────── */

    /**
     * Parse an SST-format CSV file.
     *
     * Handles multiple SST CSV formats:
     *   - Standard SST rate file (with JurisdictionType, FIPS, Rate columns)
     *   - State-specific variations
     *
     * @param  string $file_path  Path to CSV.
     * @param  string $state_code Expected state.
     * @return array[] Normalized rate entries.
     */
    public static function parse_sst_csv(string $file_path, string $state_code): array
    {
        $handle = fopen($file_path, 'r');
        if (!$handle) {
            return [];
        }

        $rates   = [];
        $headers = null;
        $row_num = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;

            // First row = headers.
            if (!$headers) {
                $headers = array_map(function ($h) {
                    return strtolower(trim(str_replace(['"', ' '], ['', '_'], $h)));
                }, $row);
                continue;
            }

            // Map row to associative array.
            if (count($row) < count($headers)) {
                continue;
            }
            $entry = array_combine($headers, array_slice($row, 0, count($headers)));

            // Extract rate data based on column naming patterns.
            $rate_data = self::extract_rate_from_row($entry, $state_code);

            if ($rate_data) {
                $rates[] = $rate_data;
            }
        }

        fclose($handle);

        return $rates;
    }

    /**
     * Extract a normalized rate entry from a CSV row.
     *
     * Handles various column naming conventions found in SST files.
     */
    private static function extract_rate_from_row(array $row, string $state_code): ?array
    {
        // Try common column name patterns for rate.
        $rate = null;
        $rate_cols = [
            'general_rate_intrastate', 'generalrateintrastate',
            'general_rate', 'rate', 'tax_rate', 'combined_rate',
            'total_rate', 'general_rate_interstate',
        ];

        foreach ($rate_cols as $col) {
            if (isset($row[$col]) && is_numeric($row[$col])) {
                $rate = (float) $row[$col];
                break;
            }
        }

        if ($rate === null || $rate <= 0) {
            return null;
        }

        // If rate looks like a percentage (e.g., 6.25), convert to decimal.
        if ($rate > 1) {
            $rate = $rate / 100;
        }

        // Jurisdiction type.
        $type_cols = ['jurisdiction_type', 'jurisdictiontype', 'type', 'level'];
        $type = 'state';
        foreach ($type_cols as $col) {
            if (!empty($row[$col])) {
                $raw_type = strtolower(trim($row[$col]));
                if (strpos($raw_type, 'state') !== false) {
                    $type = 'state';
                } elseif (strpos($raw_type, 'county') !== false) {
                    $type = 'county';
                } elseif (strpos($raw_type, 'city') !== false || strpos($raw_type, 'municipal') !== false) {
                    $type = 'city';
                } elseif (strpos($raw_type, 'special') !== false || strpos($raw_type, 'district') !== false) {
                    $type = 'special';
                }
                break;
            }
        }

        // FIPS code.
        $fips_cols = ['jurisdiction_fips', 'jurisdictionfips', 'fips', 'fips_code', 'county_fips'];
        $fips = null;
        foreach ($fips_cols as $col) {
            if (!empty($row[$col])) {
                $fips = trim($row[$col]);
                break;
            }
        }

        // Jurisdiction name.
        $name_cols = ['jurisdiction_name', 'jurisdictionname', 'name', 'county', 'city', 'jurisdiction'];
        $name = 'Unknown';
        foreach ($name_cols as $col) {
            if (!empty($row[$col])) {
                $name = trim($row[$col]);
                break;
            }
        }

        // Jurisdiction code.
        $code_cols = ['jurisdiction_code', 'jurisdictioncode', 'code'];
        $code = $fips ?? $name;
        foreach ($code_cols as $col) {
            if (!empty($row[$col])) {
                $code = trim($row[$col]);
                break;
            }
        }

        // Effective date.
        $date_cols = ['effective_date', 'effectivedate', 'eff_date', 'start_date'];
        $eff_date = wp_date('Y-m-d');
        foreach ($date_cols as $col) {
            if (!empty($row[$col])) {
                $parsed = date('Y-m-d', strtotime($row[$col]));
                if ($parsed) {
                    $eff_date = $parsed;
                }
                break;
            }
        }

        // ZIP codes (if present).
        $zip_cols = ['zip_code', 'zipcode', 'zip', 'zip_codes', 'postcodes'];
        $zip = null;
        foreach ($zip_cols as $col) {
            if (!empty($row[$col])) {
                $zip = trim($row[$col]);
                break;
            }
        }

        return [
            'state_code'         => $state_code,
            'jurisdiction_fips'  => $fips,
            'jurisdiction_code'  => $code,
            'jurisdiction_type'  => $type,
            'jurisdiction_name'  => $name,
            'rate'               => $rate,
            'rate_type'          => 'general',
            'effective_date'     => $eff_date,
            'expires_at'         => null,
            'zip_codes'          => $zip,
            'city_names'         => null,
        ];
    }

    /* ── Database Operations ──────────────────────────────────────── */

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
        int $row_count
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
            'freshness_policy' => '90d',
            'status'           => 'pending',
            'storage_uri'      => $storage_uri,
            'row_count'        => $row_count,
        ]);

        return $inserted ? (int) $wpdb->insert_id : null;
    }

    /**
     * Insert rate entries for a dataset version.
     *
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

        // Deactivate previous active versions for this source.
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

        // Activate the new version.
        $wpdb->update(
            $table,
            ['status' => 'active'],
            ['id' => $version_id]
        );
    }

    /**
     * Rollback to the previous version for a source.
     */
    public static function rollback(string $source_code, ?string $state_code = null): bool
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');

        // Find the current active version.
        if ($state_code) {
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE source_code = %s AND state_code = %s AND status = 'active'
                 LIMIT 1",
                $source_code,
                strtoupper($state_code)
            ));
        } else {
            $current = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table} WHERE source_code = %s AND status = 'active' LIMIT 1",
                $source_code
            ));
        }

        if (!$current) {
            return false;
        }

        // Find the most recent superseded version.
        if ($state_code) {
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE source_code = %s AND state_code = %s AND status = 'superseded'
                 ORDER BY loaded_at DESC LIMIT 1",
                $source_code,
                strtoupper($state_code)
            ));
        } else {
            $previous = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE source_code = %s AND status = 'superseded'
                 ORDER BY loaded_at DESC LIMIT 1",
                $source_code
            ));
        }

        if (!$previous) {
            return false;
        }

        // Swap statuses.
        $wpdb->update($table, ['status' => 'rolled_back'], ['id' => $current->id]);
        $wpdb->update($table, ['status' => 'active'], ['id' => $previous->id]);

        return true;
    }

    /**
     * Check if a checksum already exists in dataset_versions.
     */
    private static function checksum_exists(string $checksum): bool
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE checksum = %s AND status IN ('active', 'pending')",
            $checksum
        ));
    }

    /**
     * Sync SST data (placeholder for auto-download).
     *
     * For now, this checks for manually placed CSV files in the
     * datasets directory and imports them.
     */
    private static function sync_sst(): array
    {
        $official_results = self::sync_sst_from_official_source();
        if (!empty($official_results)) {
            return $official_results;
        }

        $dir     = self::get_storage_dir();
        $results = [];

        // Look for SST CSV files named like: SST_{STATE}.csv.
        $files = glob($dir . 'SST_*.csv');

        if (empty($files)) {
            // Also check for generic rate files.
            $files = glob($dir . '*.csv');
        }

        foreach ($files as $file) {
            $basename = basename($file, '.csv');

            // Try to extract state code from filename.
            if (preg_match('/^SST_([A-Z]{2})$/i', $basename, $m)) {
                $state = strtoupper($m[1]);
            } elseif (preg_match('/^([A-Z]{2})_rates?$/i', $basename, $m)) {
                $state = strtoupper($m[1]);
            } else {
                continue;
            }

            $results[$state] = self::import_csv($file, $state, 'sst_auto_sync');
        }

        return $results;
    }

    /**
     * Sync SST datasets from the official public directory.
     *
     * @return array<string, array>
     */
    private static function sync_sst_from_official_source(): array
    {
        $files = self::discover_sst_rate_files();
        if (empty($files)) {
            return [];
        }

        $results = [];
        foreach ($files as $state_code => $file) {
            $results[$state_code] = self::download_and_import_sst_file(
                $state_code,
                $file['url'],
                $file['filename']
            );
        }

        return $results;
    }

    /**
     * Discover official SST rate files from the public directory listing.
     *
     * @return array<string, array{filename:string,url:string}>
     */
    private static function discover_sst_rate_files(): array
    {
        $response = wp_remote_get(self::SST_RATES_INDEX, [
            'timeout' => self::DOWNLOAD_TIMEOUT,
            'headers' => ['Accept' => 'text/html'],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return [];
        }

        $html = wp_remote_retrieve_body($response);
        if (!is_string($html) || $html === '') {
            return [];
        }

        preg_match_all('/href="([^"]+)"|href=([^\s>]+)/i', $html, $matches);

        $files = [];
        foreach (($matches[1] ?? []) as $index => $href_a) {
            $href = $href_a ?: ($matches[2][$index] ?? '');
            $href = trim($href, "\"' ");
            $filename = basename($href);

            if (!preg_match('/^([A-Z]{2})R.+\.(csv|zip)$/i', $filename, $file_match)) {
                continue;
            }

            $state_code = strtoupper($file_match[1]);
            $files[$state_code] = [
                'filename' => $filename,
                'url'      => self::build_official_file_url($href),
            ];
        }

        return $files;
    }

    /**
     * Download and import a single official SST file.
     *
     * @return array
     */
    private static function download_and_import_sst_file(string $state_code, string $url, string $filename): array
    {
        $result = [
            'success'    => false,
            'skipped'    => false,
            'state'      => $state_code,
            'rows'       => 0,
            'version_id' => null,
            'error'      => null,
        ];

        $downloaded = self::download_remote_file($url, $filename);
        if (is_wp_error($downloaded)) {
            $result['error'] = $downloaded->get_error_message();
            return $result;
        }

        $source_path = $downloaded['path'];
        $csv_path    = $source_path;

        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zip') {
            $csv_path = self::extract_csv_from_zip($source_path, $state_code, $filename);
            if (is_wp_error($csv_path)) {
                $result['error'] = $csv_path->get_error_message();
                return $result;
            }
        }

        $checksum = hash_file('sha256', $csv_path);
        if ($checksum && self::checksum_exists($checksum)) {
            $result['success'] = true;
            $result['skipped'] = true;
            $result['error']   = 'No dataset changes detected.';
            return $result;
        }

        return self::import_csv(
            $csv_path,
            $state_code,
            'sst_auto_sync',
            pathinfo($filename, PATHINFO_FILENAME),
            $url
        );
    }

    /**
     * Download a remote file into the local dataset storage directory.
     *
     * @return array|WP_Error
     */
    private static function download_remote_file(string $url, string $filename)
    {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = download_url($url, self::DOWNLOAD_TIMEOUT);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $storage_dir = self::get_storage_dir();
        $target_path = $storage_dir . sanitize_file_name($filename);

        if (!@copy($tmp, $target_path)) {
            @unlink($tmp);
            return new WP_Error('sst_download_copy_failed', 'Downloaded SST file could not be stored locally.');
        }

        @unlink($tmp);

        return [
            'path' => $target_path,
            'url'  => $url,
        ];
    }

    /**
     * Extract the first CSV file from an SST zip archive.
     *
     * @return string|WP_Error
     */
    private static function extract_csv_from_zip(string $zip_path, string $state_code, string $filename)
    {
        $extract_dir = trailingslashit(self::get_storage_dir() . 'extract-' . strtolower($state_code));
        self::delete_path($extract_dir);
        wp_mkdir_p($extract_dir);

        $extracted = false;

        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($zip_path) === true) {
                $extracted = $zip->extractTo($extract_dir);
                $zip->close();
            }
        }

        if (!$extracted) {
            if (!function_exists('unzip_file')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $unzipped = unzip_file($zip_path, $extract_dir);
            if (is_wp_error($unzipped) || !$unzipped) {
                self::delete_path($extract_dir);
                return new WP_Error('sst_zip_extract_failed', 'Official SST zip archive could not be extracted.');
            }
        }

        $csv_path = self::find_first_csv($extract_dir);
        if (!$csv_path) {
            self::delete_path($extract_dir);
            return new WP_Error(
                'sst_zip_no_csv',
                sprintf('No CSV file was found inside %s.', $filename)
            );
        }

        $target_csv = self::get_storage_dir() . 'SST_' . strtoupper($state_code) . '.csv';
        if (!@copy($csv_path, $target_csv)) {
            self::delete_path($extract_dir);
            return new WP_Error('sst_zip_copy_failed', 'Extracted SST CSV could not be stored locally.');
        }

        self::delete_path($extract_dir);

        return $target_csv;
    }

    /**
     * Build an absolute official SST file URL from a directory href.
     */
    private static function build_official_file_url(string $href): string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }

        return trailingslashit(self::SST_RATES_INDEX) . ltrim($href, '/');
    }

    /**
     * Find the first CSV file inside a directory tree.
     */
    private static function find_first_csv(string $directory): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'csv') {
                return $file->getPathname();
            }
        }

        return null;
    }

    /**
     * Delete a file or directory tree.
     */
    private static function delete_path(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            self::delete_path(trailingslashit($path) . $item);
        }

        @rmdir($path);
    }

    /**
     * Get all active dataset versions.
     */
    public static function get_active_versions(): array
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('dataset_versions');
        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'active' ORDER BY source_code, effective_date DESC",
            ARRAY_A
        ) ?: [];
    }
}
