<?php
/**
 * Secure multi-site tax-report combining and generic state-template mapping.
 *
 * This class deliberately contains no admin or download handlers. Callers are
 * responsible for capability/nonce checks and for sending the returned CSV.
 */

defined('ABSPATH') || exit;

class Tax_Report_Combiner
{
    /** @var array<string,int|float> */
    private $limits;

    /** @var string[] */
    private $sum_fields = [
        'orders',
        'taxable_sales',
        'taxable_shipping',
        'non_taxable_sales',
        'needs_review_sales',
        'net_tax',
        'calculated_tax',
        'over_under',
    ];

    /**
     * @param array<string,int|float> $limits Optional per-instance overrides.
     */
    public function __construct(array $limits = [])
    {
        $defaults = [
            'max_files'                  => 10,
            'max_file_bytes'             => 10 * 1024 * 1024,
            'max_total_file_bytes'       => 50 * 1024 * 1024,
            'max_zip_entries'            => 250,
            'max_zip_uncompressed_bytes' => 50 * 1024 * 1024,
            'max_zip_ratio'              => 250,
            'max_manifest_bytes'          => 1024 * 1024,
            'max_rows'                   => 10000,
            'max_template_rows'          => 10000,
            'max_template_comparisons'   => 200000,
            'max_diagnostics'            => 250,
            'max_row_bytes'              => 256 * 1024,
            'max_cell_bytes'             => 16 * 1024,
            'max_columns'                => 100,
        ];

        if (function_exists('apply_filters')) {
            $defaults = (array) apply_filters('ffla_tax_report_combiner_limits', $defaults);
        }
        $this->limits = $this->merge_limits($defaults, $limits);
    }

    /**
     * Combine uploaded FFLA jurisdiction summaries.
     *
     * Accepts a normal $_FILES entry, a list of $_FILES entries, or local file
     * paths when require_uploaded_file is explicitly disabled (useful for CLI,
     * trusted server-side jobs, and tests).
     *
     * @param array<int|string,mixed> $uploads
     * @param array<string,mixed>     $options
     * @return array<string,mixed>
     */
    public function combine_uploaded_files(array $uploads, array $options = []): array
    {
        $limits = $this->merge_limits($this->limits, isset($options['limits']) && is_array($options['limits']) ? $options['limits'] : []);
        $this->limits = $limits;
        $require_uploaded = !isset($options['require_uploaded_file']) || (bool) $options['require_uploaded_file'];
        $files = $this->normalize_uploads($uploads);
        $diagnostics = $this->new_diagnostics();
        $diagnostics['files_received'] = count($files);

        $total_file_bytes = 0;
        foreach ($files as $file) {
            $path = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
            $size = $path !== '' && is_file($path) ? (int) filesize($path) : (int) ($file['size'] ?? 0);
            $total_file_bytes += max(0, $size);
        }
        if ($total_file_bytes > $limits['max_total_file_bytes']) {
            $this->add_issue($diagnostics, 'errors', 'total_upload_size_exceeded', 'The combined upload exceeds the total request size limit.', '', 0, [
                'limit'    => $limits['max_total_file_bytes'],
                'received' => $total_file_bytes,
            ]);
            return ['rows' => [], 'diagnostics' => $diagnostics];
        }

        if (count($files) > $limits['max_files']) {
            $this->add_issue($diagnostics, 'errors', 'file_limit_exceeded', 'The number of uploaded report files exceeds the configured limit.', '', 0, [
                'limit'    => $limits['max_files'],
                'received' => count($files),
            ]);
            $files = array_slice($files, 0, (int) $limits['max_files']);
        }

        $groups = [];
        $identity_index = [];
        $seen_report_ids = [];
        $seen_content_hashes = [];

        foreach ($files as $file_index => $file) {
            $validated = $this->validate_input_file($file, ['csv', 'zip'], $limits, $require_uploaded, $diagnostics);
            if (!$validated) {
                continue;
            }

            if ($validated['extension'] === 'zip') {
                $this->consume_zip_package($validated, $groups, $identity_index, $seen_report_ids, $seen_content_hashes, $diagnostics, $limits, $require_uploaded);
            } else {
                $content = $this->read_file_limited($validated['path'], (int) $limits['max_file_bytes']);
                if ($content === false) {
                    $this->add_issue($diagnostics, 'errors', 'file_read_failed', 'The CSV file could not be read within the configured size limit.', $validated['name']);
                    continue;
                }
                $meta = [
                    'report_id' => '',
                    'site'      => $this->site_label([], $validated['name']),
                    'source'    => $validated['name'],
                ];
                $content_hash = hash('sha256', $content);
                if (isset($seen_content_hashes[$content_hash])) {
                    $diagnostics['duplicate_report_ids'][] = [
                        'report_id'       => '',
                        'source'          => $validated['name'],
                        'original_source' => $seen_content_hashes[$content_hash],
                        'reason'          => 'identical_content',
                    ];
                    continue;
                }
                $this->add_issue($diagnostics, 'warnings', 'manifest_missing', 'This CSV has no manifest; duplicate report-ID detection is unavailable for it.', $validated['name']);
                if ($this->consume_summary_csv($content, $meta, $groups, $identity_index, $diagnostics, $limits)) {
                    $seen_content_hashes[$content_hash] = $validated['name'];
                    $diagnostics['files_processed']++;
                    $diagnostics['packages_processed']++;
                }
            }

            if ($diagnostics['rows_read'] >= $limits['max_rows']) {
                if ($file_index < count($files) - 1) {
                    $diagnostics['truncated'] = true;
                    $this->add_issue($diagnostics, 'errors', 'row_limit_exceeded', 'The combined report row limit was reached before all uploaded files were processed.', $validated['name'] ?? '', 0, [
                        'limit'           => $limits['max_rows'],
                        'files_received'  => count($files),
                        'files_processed' => $diagnostics['files_processed'],
                    ]);
                }
                break;
            }
        }

        unset($identity_index);
        $this->finalize_groups($groups);
        $diagnostics['rows_combined'] = count($groups);
        $diagnostics['unique_report_ids'] = count($seen_report_ids);

        return [
            'rows'        => $groups,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Alias retained for trusted server-side integrations.
     *
     * @param array<int|string,mixed> $files
     * @param array<string,mixed>     $options
     * @return array<string,mixed>
     */
    public function combine(array $files, array $options = []): array
    {
        return $this->combine_uploaded_files($files, $options);
    }

    /**
     * Populate a generic state filing template with combined jurisdiction rows.
     *
     * Mapping format (all keys optional):
     *   template_keys:  report field => template header
     *   output_columns: report field => template header
     *
     * Headers are auto-detected when explicit mappings are omitted. Matching
     * precedence is jurisdiction_code, county, city, then jurisdiction_name;
     * state and currency narrow a match whenever those columns are available.
     *
     * @param mixed                 $template A $_FILES entry or trusted path.
     * @param array<int,array>      $combined_rows
     * @param array<string,mixed>   $mapping
     * @param array<string,mixed>   $options
     * @return array<string,mixed>
     */
    public function map_state_template($template, array $combined_rows, array $mapping = [], array $options = []): array
    {
        $limits = $this->merge_limits($this->limits, isset($options['limits']) && is_array($options['limits']) ? $options['limits'] : []);
        $this->limits = $limits;
        $require_uploaded = !isset($options['require_uploaded_file']) || (bool) $options['require_uploaded_file'];
        $diagnostics = [
            'matched'   => 0,
            'unmatched' => [],
            'ambiguous' => [],
            'warnings'  => [],
            'errors'    => [],
            'truncated' => false,
        ];

        $files = $this->normalize_uploads(['template' => $template]);
        if (count($files) !== 1) {
            $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'invalid_template', 'message' => 'Exactly one CSV template is required.']);
            return $this->empty_mapping_result($diagnostics, $options);
        }

        $combine_diagnostics = $this->new_diagnostics();
        $validated = $this->validate_input_file($files[0], ['csv'], $limits, $require_uploaded, $combine_diagnostics);
        if (!$validated) {
            $diagnostics['errors'] = $combine_diagnostics['errors'];
            return $this->empty_mapping_result($diagnostics, $options);
        }

        $table = $this->parse_template_csv($validated['path'], $validated['name'], $diagnostics, $limits);
        if (!$table) {
            return $this->empty_mapping_result($diagnostics, $options);
        }

        $column_maps = $this->resolve_template_mapping($table['headers'], $mapping, $diagnostics);
        if (!empty($diagnostics['errors'])) {
            fclose($table['stream']);
            return $this->empty_mapping_result($diagnostics, $options);
        }

        $output = $this->open_csv_output_stream($table['headers']);
        if (!is_resource($output)) {
            fclose($table['stream']);
            $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'mapped_csv_stream_failed', 'message' => 'The mapped CSV output stream could not be created.']);
            return $this->empty_mapping_result($diagnostics, $options);
        }

        $index = null;
        $comparison_count = 0;
        $comparison_limit_exceeded = false;
        $template_rows = 0;
        $row_number = 1;
        while (true) {
            $line_too_long = false;
            $line = $this->read_csv_line_limited($table['stream'], (int) $limits['max_row_bytes'], $line_too_long);
            if ($line === null) {
                break;
            }
            $row_number++;
            if ($line_too_long || strpos($line, "\0") !== false) {
                $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'template_row_too_large', 'message' => 'A template row is binary or exceeds the configured size limit.', 'row' => $row_number]);
                continue;
            }

            $row_error = '';
            $values = $this->parse_csv_line_with_limits($line, $table['delimiter'], $limits, $row_error);
            if (!is_array($values)) {
                $this->add_mapping_issue($diagnostics, 'errors', [
                    'code'    => $row_error === 'columns' ? 'template_column_overflow' : 'template_row_too_large',
                    'message' => $row_error === 'columns' ? 'A template row exceeds the configured column limit.' : 'A template row is malformed or exceeds the configured size limit.',
                    'row'     => $row_number,
                ]);
                continue;
            }
            if ($this->is_empty_csv_row($values)) {
                continue;
            }
            if ($template_rows >= $limits['max_template_rows']) {
                $diagnostics['truncated'] = true;
                $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'template_row_limit_exceeded', 'message' => 'The template row limit was reached.', 'row' => $row_number]);
                break;
            }
            if (count($values) > count($table['headers'])) {
                $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'template_column_overflow', 'message' => 'A template row contains more values than its header.', 'row' => $row_number]);
                continue;
            }

            $template_row = [];
            foreach ($table['headers'] as $index_number => $header) {
                $template_row[$header] = isset($values[$index_number]) ? (string) $values[$index_number] : '';
            }
            $template_rows++;
            if ($index === null) {
                $index = $this->build_template_match_index($combined_rows, $column_maps['keys']);
            }
            $matches = $this->find_template_matches(
                $template_row,
                $combined_rows,
                (array) $index,
                $column_maps['keys'],
                $comparison_count,
                (int) $limits['max_template_comparisons'],
                $comparison_limit_exceeded
            );
            if ($comparison_limit_exceeded) {
                $diagnostics['truncated'] = true;
                $this->add_mapping_issue($diagnostics, 'errors', [
                    'code'    => 'template_comparison_limit_exceeded',
                    'message' => 'Template matching exceeded its bounded comparison limit.',
                    'row'     => $row_number,
                    'limit'   => $limits['max_template_comparisons'],
                ]);
                break;
            }
            if (count($matches) === 1) {
                $template_row = $this->apply_output_values($template_row, (array) $combined_rows[$matches[0]], $column_maps);
                $diagnostics['matched']++;
            } elseif (empty($matches)) {
                if (count($diagnostics['unmatched']) < $limits['max_diagnostics']) {
                    $this->add_mapping_issue($diagnostics, 'unmatched', [
                        'row'         => $row_number,
                        'identifiers' => $this->template_identifiers($template_row, $column_maps['keys']),
                    ]);
                } else {
                    $diagnostics['truncated'] = true;
                }
            } else {
                if (count($diagnostics['ambiguous']) < $limits['max_diagnostics']) {
                    $this->add_mapping_issue($diagnostics, 'ambiguous', [
                        'row'             => $row_number,
                        'identifiers'     => $this->template_identifiers($template_row, $column_maps['keys']),
                        'candidate_count' => count($matches),
                    ]);
                } else {
                    $diagnostics['truncated'] = true;
                }
            }
            if (!$this->write_csv_output_row($output, $table['headers'], $template_row)) {
                $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'mapped_csv_write_failed', 'message' => 'The mapped CSV could not be written completely.', 'row' => $row_number]);
                break;
            }
        }
        fclose($table['stream']);
        unset($index);

        $generated_from_header_only = $template_rows === 0;
        if ($generated_from_header_only && empty($diagnostics['errors'])) {
            if (count($combined_rows) > $limits['max_template_rows']) {
                $diagnostics['truncated'] = true;
                $this->add_mapping_issue($diagnostics, 'errors', [
                    'code'    => 'generated_template_row_limit_exceeded',
                    'message' => 'The combined report exceeds the template generation row limit.',
                    'limit'   => $limits['max_template_rows'],
                ]);
            } else {
                foreach ($combined_rows as $combined_row) {
                    $blank = array_fill_keys($table['headers'], '');
                    $mapped_row = $this->apply_output_values($blank, (array) $combined_row, $column_maps);
                    if (!$this->write_csv_output_row($output, $table['headers'], $mapped_row)) {
                        $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'mapped_csv_write_failed', 'message' => 'The mapped CSV could not be written completely.']);
                        break;
                    }
                    $diagnostics['matched']++;
                }
            }
        }

        if ($generated_from_header_only && empty($combined_rows)) {
            $this->add_mapping_issue($diagnostics, 'warnings', ['code' => 'no_combined_rows', 'message' => 'The template and combined report contain no data rows.']);
        }

        if (!empty($diagnostics['errors']) || !empty($diagnostics['unmatched']) || !empty($diagnostics['ambiguous']) || !empty($diagnostics['truncated'])) {
            fclose($output);
            return $this->empty_mapping_result($diagnostics, $options);
        }
        rewind($output);

        if (empty($options['stream_output'])) {
            $csv = stream_get_contents($output);
            fclose($output);
            if (!is_string($csv)) {
                $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'mapped_csv_read_failed', 'message' => 'The mapped CSV could not be read from its temporary stream.']);
                return $this->empty_mapping_result($diagnostics, $options);
            }
            return [
                'csv'         => $csv,
                'stream'      => null,
                'filename'    => $this->safe_filename(isset($options['filename']) ? (string) $options['filename'] : 'mapped-state-tax-report.csv'),
                'diagnostics' => $diagnostics,
            ];
        }

        return [
            'csv'         => '',
            'stream'      => $output,
            'filename'    => $this->safe_filename(isset($options['filename']) ? (string) $options['filename'] : 'mapped-state-tax-report.csv'),
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * More explicit alias for callers that use generic template terminology.
     *
     * @param mixed               $template
     * @param array<int,array>    $combined_rows
     * @param array<string,mixed> $mapping
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function map_template($template, array $combined_rows, array $mapping = [], array $options = []): array
    {
        return $this->map_state_template($template, $combined_rows, $mapping, $options);
    }

    /**
     * Streaming variant for admin downloads and other memory-bounded callers.
     * The successful result contains a readable `stream` resource that the
     * caller must close after use.
     *
     * @param mixed                 $template
     * @param array<int,array>      $combined_rows
     * @param array<string,mixed>   $mapping
     * @param array<string,mixed>   $options
     * @return array<string,mixed>
     */
    public function map_state_template_stream($template, array $combined_rows, array $mapping = [], array $options = []): array
    {
        $options['stream_output'] = true;
        return $this->map_state_template($template, $combined_rows, $mapping, $options);
    }

    /** @return array<string,mixed> */
    private function new_diagnostics(): array
    {
        return [
            'files_received'      => 0,
            'files_processed'     => 0,
            'packages_processed'  => 0,
            'rows_read'           => 0,
            'rows_combined'       => 0,
            'unique_report_ids'   => 0,
            'duplicate_report_ids'=> [],
            'warnings'            => [],
            'errors'              => [],
            'truncated'           => false,
        ];
    }

    /**
     * @param array<string,int|float> $base
     * @param array<string,mixed>     $overrides
     * @return array<string,int|float>
     */
    private function merge_limits(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, $base) && is_numeric($value) && (float) $value > 0) {
                $base[$key] = $key === 'max_zip_ratio' ? (float) $value : (int) $value;
            }
        }
        return $base;
    }

    /**
     * @param mixed $uploads
     * @return array<int,array<string,mixed>>
     */
    private function normalize_uploads($uploads): array
    {
        $normalized = [];
        $this->flatten_uploads($uploads, $normalized);
        return $normalized;
    }

    /**
     * @param mixed                                $value
     * @param array<int,array<string,mixed>>       $normalized
     */
    private function flatten_uploads($value, array &$normalized): void
    {
        if (is_string($value)) {
            $normalized[] = [
                'name'     => basename($value),
                'tmp_name' => $value,
                'error'    => UPLOAD_ERR_OK,
                'size'     => is_file($value) ? (int) filesize($value) : 0,
                'type'     => '',
            ];
            return;
        }
        if (!is_array($value)) {
            return;
        }
        if (array_key_exists('tmp_name', $value)) {
            if (is_array($value['tmp_name'])) {
                foreach ($value['tmp_name'] as $key => $tmp_name) {
                    $this->flatten_uploads([
                        'name'     => $this->nested_upload_value($value, 'name', $key, basename((string) $tmp_name)),
                        'tmp_name' => $tmp_name,
                        'error'    => $this->nested_upload_value($value, 'error', $key, UPLOAD_ERR_OK),
                        'size'     => $this->nested_upload_value($value, 'size', $key, 0),
                        'type'     => $this->nested_upload_value($value, 'type', $key, ''),
                    ], $normalized);
                }
                return;
            }
            $normalized[] = [
                'name'     => isset($value['name']) ? (string) $value['name'] : basename((string) $value['tmp_name']),
                'tmp_name' => (string) $value['tmp_name'],
                'error'    => isset($value['error']) ? (int) $value['error'] : UPLOAD_ERR_OK,
                'size'     => isset($value['size']) ? (int) $value['size'] : 0,
                'type'     => isset($value['type']) ? (string) $value['type'] : '',
            ];
            return;
        }
        foreach ($value as $child) {
            $this->flatten_uploads($child, $normalized);
        }
    }

    /** @return mixed */
    private function nested_upload_value(array $upload, string $field, $key, $default)
    {
        return isset($upload[$field]) && is_array($upload[$field]) && array_key_exists($key, $upload[$field])
            ? $upload[$field][$key]
            : $default;
    }

    /**
     * @param array<string,mixed>     $file
     * @param string[]                $allowed_extensions
     * @param array<string,int|float> $limits
     * @param array<string,mixed>     $diagnostics
     * @return array<string,mixed>|false
     */
    private function validate_input_file(array $file, array $allowed_extensions, array $limits, bool $require_uploaded, array &$diagnostics)
    {
        $name = $this->safe_filename(isset($file['name']) ? (string) $file['name'] : 'upload');
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->add_issue($diagnostics, 'errors', 'upload_error', 'The uploaded file could not be accepted.', $name, 0, ['upload_error' => (int) $file['error']]);
            return false;
        }

        $path = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            $this->add_issue($diagnostics, 'errors', 'invalid_upload_path', 'The uploaded temporary file is missing or unreadable.', $name);
            return false;
        }
        if ($require_uploaded && !is_uploaded_file($path)) {
            $this->add_issue($diagnostics, 'errors', 'not_uploaded_file', 'The file was not received through a valid HTTP upload.', $name);
            return false;
        }

        $size = (int) filesize($path);
        if ($size < 1 || $size > $limits['max_file_bytes']) {
            $this->add_issue($diagnostics, 'errors', 'invalid_file_size', 'The uploaded file is empty or exceeds the configured size limit.', $name, 0, ['bytes' => $size, 'limit' => $limits['max_file_bytes']]);
            return false;
        }

        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_extensions, true)) {
            $this->add_issue($diagnostics, 'errors', 'unsupported_file_type', 'Only the configured CSV/ZIP report file types are accepted.', $name);
            return false;
        }

        $signature = file_get_contents($path, false, null, 0, 4);
        if ($extension === 'zip' && !in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
            $this->add_issue($diagnostics, 'errors', 'invalid_zip_signature', 'The uploaded ZIP does not have a valid ZIP signature.', $name);
            return false;
        }
        if ($extension === 'csv' && is_string($signature) && strpos($signature, "\0") !== false) {
            $this->add_issue($diagnostics, 'errors', 'binary_csv_rejected', 'The uploaded CSV appears to contain binary data.', $name);
            return false;
        }

        return ['name' => $name, 'path' => $path, 'extension' => $extension, 'size' => $size];
    }

    /**
     * @param array<string,mixed>     $file
     * @param array<int,array>        $groups
     * @param array<string,array>     $identity_index
     * @param array<string,array>     $seen_report_ids
     * @param array<string,string>    $seen_content_hashes
     * @param array<string,mixed>     $diagnostics
     * @param array<string,int|float> $limits
     */
    private function consume_zip_package(array $file, array &$groups, array &$identity_index, array &$seen_report_ids, array &$seen_content_hashes, array &$diagnostics, array $limits, bool $untrusted_upload): void
    {
        $package = $this->open_zip_package($file, $diagnostics, !$untrusted_upload);
        if ($package === false) {
            return;
        }
        $entries = $package['entries'];

        if (count($entries) > $limits['max_zip_entries']) {
            $this->close_zip_package($package);
            $this->add_issue($diagnostics, 'errors', 'zip_entry_limit_exceeded', 'The ZIP contains too many entries.', $file['name'], 0, ['limit' => $limits['max_zip_entries']]);
            return;
        }

        $manifest_entries = [];
        $summary_entries = [];
        $total_uncompressed = 0;
        $invalid = false;

        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !$this->is_safe_zip_entry((string) $entry['name'])) {
                $this->add_issue($diagnostics, 'errors', 'unsafe_zip_entry', 'The ZIP contains an unsafe path and was rejected.', $file['name'], 0, ['entry' => is_array($entry) ? (string) ($entry['name'] ?? '') : '']);
                $invalid = true;
                break;
            }

            $entry_name = str_replace('\\', '/', (string) $entry['name']);
            if (substr($entry_name, -1) === '/' || !empty($entry['is_dir'])) {
                continue;
            }
            $size = isset($entry['size']) ? (int) $entry['size'] : 0;
            $compressed_size = isset($entry['comp_size']) ? (int) $entry['comp_size'] : 0;
            $total_uncompressed += $size;
            if ($total_uncompressed > $limits['max_zip_uncompressed_bytes']) {
                $this->add_issue($diagnostics, 'errors', 'zip_uncompressed_limit_exceeded', 'The ZIP uncompressed size exceeds the configured limit.', $file['name']);
                $invalid = true;
                break;
            }
            if ($size > 0 && $compressed_size > 0 && ($size / $compressed_size) > $limits['max_zip_ratio']) {
                $this->add_issue($diagnostics, 'errors', 'zip_ratio_exceeded', 'The ZIP compression ratio is suspiciously high.', $file['name'], 0, ['entry' => $entry_name]);
                $invalid = true;
                break;
            }

            $basename = strtolower(basename($entry_name));
            if ($basename === 'jurisdiction-summary.csv') {
                $summary_entries[] = $entry;
            } elseif (substr($basename, -5) === '.json' && strpos($basename, 'manifest') !== false) {
                $manifest_entries[] = $entry;
            }
        }

        if ($invalid || count($summary_entries) !== 1 || count($manifest_entries) > 1) {
            if (!$invalid && count($summary_entries) !== 1) {
                $this->add_issue($diagnostics, 'errors', 'jurisdiction_summary_count', 'A ZIP package must contain exactly one jurisdiction-summary.csv file.', $file['name'], 0, ['found' => count($summary_entries)]);
            }
            if (!$invalid && count($manifest_entries) > 1) {
                $this->add_issue($diagnostics, 'errors', 'multiple_manifests', 'A ZIP package may contain at most one report manifest.', $file['name']);
            }
            $this->close_zip_package($package);
            return;
        }

        $manifest = [];
        if (!empty($manifest_entries)) {
            $manifest_json = $this->read_zip_package_entry_limited($package, $manifest_entries[0], (int) $limits['max_manifest_bytes']);
            if ($manifest_json === false) {
                $this->add_issue($diagnostics, 'errors', 'manifest_read_failed', 'The report manifest exceeds its size limit or could not be read.', $file['name']);
                $this->close_zip_package($package);
                return;
            }
            $manifest = json_decode($manifest_json, true);
            if (!is_array($manifest)) {
                $this->add_issue($diagnostics, 'errors', 'manifest_invalid_json', 'The report manifest is not valid JSON.', $file['name']);
                $this->close_zip_package($package);
                return;
            }
            if (isset($manifest['manifest']) && is_array($manifest['manifest'])) {
                $manifest = $manifest['manifest'];
            }
        } else {
            $this->add_issue($diagnostics, 'warnings', 'manifest_missing', 'This ZIP has no manifest; duplicate report-ID detection is unavailable for it.', $file['name']);
        }

        $report_id = $this->valid_manifest_identifier($manifest['report_id'] ?? '');
        if (isset($manifest['report_id']) && $report_id === '') {
            $this->add_issue($diagnostics, 'errors', 'manifest_report_id_invalid', 'The manifest report_id is invalid.', $file['name']);
            $this->close_zip_package($package);
            return;
        }
        if ($report_id === '' && !empty($manifest_entries)) {
            $this->add_issue($diagnostics, 'warnings', 'manifest_report_id_missing', 'The manifest has no report_id, so duplicate detection is unavailable for this package.', $file['name']);
        }

        $summary = $this->read_zip_package_entry_limited($package, $summary_entries[0], (int) $limits['max_file_bytes']);
        $this->close_zip_package($package);
        if ($summary === false) {
            $this->add_issue($diagnostics, 'errors', 'summary_read_failed', 'The jurisdiction summary exceeds its size limit or could not be read.', $file['name']);
            return;
        }

        $summary_hash = hash('sha256', $summary);
        $checksums = isset($manifest['file_checksums_sha256']) && is_array($manifest['file_checksums_sha256'])
            ? $manifest['file_checksums_sha256']
            : [];
        $expected_hash = '';
        foreach ($checksums as $checksum_name => $checksum_value) {
            if (strtolower(basename((string) $checksum_name)) === 'jurisdiction-summary.csv') {
                $expected_hash = strtolower(trim((string) $checksum_value));
                break;
            }
        }
        if ($expected_hash !== '' && (!preg_match('/^[a-f0-9]{64}$/', $expected_hash) || !hash_equals($expected_hash, $summary_hash))) {
            $this->add_issue($diagnostics, 'errors', 'manifest_checksum_mismatch', 'The jurisdiction summary checksum does not match the report manifest.', $file['name']);
            return;
        }

        if ($report_id !== '') {
            $lookup = strtolower($report_id);
            if (isset($seen_report_ids[$lookup])) {
                $original = (array) $seen_report_ids[$lookup];
                if (($original['hash'] ?? '') !== $summary_hash) {
                    $this->add_issue($diagnostics, 'errors', 'duplicate_report_id_content_mismatch', 'The same report ID was supplied with different jurisdiction data.', $file['name'], 0, [
                        'report_id'       => $report_id,
                        'original_source' => (string) ($original['source'] ?? ''),
                    ]);
                } else {
                    $diagnostics['duplicate_report_ids'][] = [
                        'report_id'       => $report_id,
                        'source'          => $file['name'],
                        'original_source' => (string) ($original['source'] ?? ''),
                        'reason'          => 'duplicate_report_id',
                    ];
                }
                return;
            }
        }
        if (isset($seen_content_hashes[$summary_hash])) {
            $diagnostics['duplicate_report_ids'][] = [
                'report_id'       => $report_id,
                'source'          => $file['name'],
                'original_source' => $seen_content_hashes[$summary_hash],
                'reason'          => 'identical_content',
            ];
            return;
        }

        $meta = [
            'report_id' => $report_id,
            'site'      => $this->site_label($manifest, $file['name']),
            'source'    => $file['name'],
        ];
        if ($this->consume_summary_csv($summary, $meta, $groups, $identity_index, $diagnostics, $limits)) {
            if ($report_id !== '') {
                $seen_report_ids[strtolower($report_id)] = ['source' => $file['name'], 'hash' => $summary_hash];
            }
            $seen_content_hashes[$summary_hash] = $file['name'];
            $diagnostics['files_processed']++;
            $diagnostics['packages_processed']++;
        }
    }

    /**
     * Open a ZIP with ext-zip when available, otherwise use WordPress PclZip.
     * Entries are normalized so both backends receive identical validation.
     *
     * @return array<string,mixed>|false
     */
    private function open_zip_package(array $file, array &$diagnostics, bool $allow_pclzip)
    {
        if (class_exists('ZipArchive')) {
            $archive = new ZipArchive();
            if ($archive->open($file['path']) !== true) {
                $this->add_issue($diagnostics, 'errors', 'zip_open_failed', 'The ZIP report package could not be opened.', $file['name']);
                return false;
            }

            $entries = [];
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                if (!is_array($stat)) {
                    $entries[] = ['name' => '', 'size' => 0, 'comp_size' => 0, 'index' => $index, 'is_dir' => false];
                    continue;
                }
                $entries[] = [
                    'name'      => (string) ($stat['name'] ?? ''),
                    'size'      => (int) ($stat['size'] ?? 0),
                    'comp_size' => (int) ($stat['comp_size'] ?? 0),
                    'index'     => $index,
                    'is_dir'    => substr((string) ($stat['name'] ?? ''), -1) === '/',
                ];
            }

            return ['backend' => 'ziparchive', 'archive' => $archive, 'entries' => $entries];
        }

        if (!$allow_pclzip) {
            $this->add_issue($diagnostics, 'errors', 'zip_streaming_backend_missing', 'Secure ZIP uploads require PHP ZipArchive on this server. Upload jurisdiction-summary.csv directly instead.', $file['name']);
            return false;
        }

        if (!class_exists('PclZip')) {
            $pclzip_path = ABSPATH . 'wp-admin/includes/class-pclzip.php';
            if (is_file($pclzip_path)) {
                require_once $pclzip_path;
            }
        }
        if (!class_exists('PclZip') || !function_exists('gzopen')) {
            $this->add_issue($diagnostics, 'errors', 'zip_backend_missing', 'This trusted integration cannot read ZIP report packages. Enable PHP ZipArchive or the zlib-backed WordPress PclZip reader.', $file['name']);
            return false;
        }

        $archive = new PclZip($file['path']);
        $listed = $archive->listContent();
        if (!is_array($listed)) {
            $this->add_issue($diagnostics, 'errors', 'zip_open_failed', 'The ZIP report package could not be opened.', $file['name']);
            return false;
        }

        $entries = [];
        foreach ($listed as $offset => $stat) {
            $entries[] = [
                'name'      => (string) ($stat['filename'] ?? ''),
                'size'      => (int) ($stat['size'] ?? 0),
                'comp_size' => (int) ($stat['compressed_size'] ?? 0),
                'index'     => (int) ($stat['index'] ?? $offset),
                'is_dir'    => !empty($stat['folder']),
            ];
        }

        return ['backend' => 'pclzip', 'archive' => $archive, 'entries' => $entries];
    }

    /** @return string|false */
    private function read_zip_package_entry_limited(array $package, array $entry, int $limit)
    {
        if ((int) ($entry['size'] ?? 0) > $limit) {
            return false;
        }

        if (($package['backend'] ?? '') === 'ziparchive') {
            return $this->read_zip_entry_limited($package['archive'], (string) $entry['name'], $limit);
        }
        if (($package['backend'] ?? '') !== 'pclzip' || !defined('PCLZIP_OPT_EXTRACT_AS_STRING')) {
            return false;
        }

        $extracted = $package['archive']->extractByIndex((int) $entry['index'], PCLZIP_OPT_EXTRACT_AS_STRING);
        if (!is_array($extracted) || empty($extracted) || !array_key_exists('content', $extracted[0])) {
            return false;
        }
        $content = (string) $extracted[0]['content'];
        return strlen($content) <= $limit ? $content : false;
    }

    private function close_zip_package(array $package): void
    {
        if (($package['backend'] ?? '') === 'ziparchive' && isset($package['archive'])) {
            $package['archive']->close();
        }
    }

    private function is_safe_zip_entry(string $entry): bool
    {
        if ($entry === '' || strpos($entry, "\0") !== false) {
            return false;
        }
        $normalized = str_replace('\\', '/', $entry);
        if ($normalized[0] === '/' || preg_match('/^[A-Za-z]:\//', $normalized)) {
            return false;
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** @return string|false */
    private function read_zip_entry_limited(ZipArchive $zip, string $entry, int $limit)
    {
        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            return false;
        }
        $contents = $this->read_stream_limited($stream, $limit);
        fclose($stream);
        return $contents;
    }

    /**
     * @param array<string,mixed>     $meta
     * @param array<int,array>        $groups
     * @param array<string,array>     $identity_index
     * @param array<string,mixed>     $diagnostics
     * @param array<string,int|float> $limits
     */
    private function consume_summary_csv(string $content, array $meta, array &$groups, array &$identity_index, array &$diagnostics, array $limits): bool
    {
        if (strpos($content, "\0") !== false) {
            $this->add_issue($diagnostics, 'errors', 'binary_csv_rejected', 'The jurisdiction summary contains binary data.', $meta['source']);
            return false;
        }

        $stream = fopen('php://temp', 'w+b');
        if (!$stream) {
            $this->add_issue($diagnostics, 'errors', 'csv_stream_failed', 'A temporary CSV stream could not be created.', $meta['source']);
            return false;
        }
        fwrite($stream, $content);
        rewind($stream);
        $header_too_long = false;
        $header_line = $this->read_csv_line_limited($stream, (int) $limits['max_row_bytes'], $header_too_long);
        if ($header_too_long) {
            fclose($stream);
            $this->add_issue($diagnostics, 'errors', 'csv_header_too_large', 'The jurisdiction summary header exceeds the configured row-size limit.', $meta['source'], 1);
            return false;
        }
        $delimiter = $this->detect_delimiter(is_string($header_line) ? $header_line : '', (int) $limits['max_columns']);
        $header_error = '';
        $header = is_string($header_line) ? $this->parse_csv_line_with_limits($header_line, $delimiter, $limits, $header_error) : false;
        if (!is_array($header) || empty($header)) {
            fclose($stream);
            $code = $header_error === '' ? 'csv_header_missing' : 'csv_header_limits_exceeded';
            $message = $header_error === ''
                ? 'The jurisdiction summary has no readable header row.'
                : 'The jurisdiction summary header is malformed or exceeds the configured cell or column limit.';
            $this->add_issue($diagnostics, 'errors', $code, $message, $meta['source'], 1, ['column_limit' => $limits['max_columns']]);
            return false;
        }

        $columns = [];
        foreach ($header as $index => $heading) {
            $heading = $index === 0 ? $this->strip_bom((string) $heading) : (string) $heading;
            $canonical = $this->canonical_header($heading);
            if ($canonical === '' || in_array($canonical, $columns, true)) {
                fclose($stream);
                $this->add_issue($diagnostics, 'errors', 'csv_header_invalid', 'The jurisdiction summary contains an empty or duplicate header.', $meta['source'], 1, ['header' => $heading]);
                return false;
            }
            $columns[] = $canonical;
        }

        foreach (['state', 'currency'] as $required) {
            if (!in_array($required, $columns, true)) {
                fclose($stream);
                $this->add_issue($diagnostics, 'errors', 'required_column_missing', 'The jurisdiction summary is missing a required column.', $meta['source'], 1, ['column' => $required]);
                return false;
            }
        }
        if (empty(array_intersect(['jurisdiction_code', 'county', 'city', 'jurisdiction_name'], $columns))) {
            fclose($stream);
            $this->add_issue($diagnostics, 'errors', 'jurisdiction_key_missing', 'The jurisdiction summary needs a jurisdiction code, county, city, or jurisdiction name column.', $meta['source'], 1);
            return false;
        }

        $row_number = 1;
        $accepted = 0;
        while (true) {
            $line_too_long = false;
            $line = $this->read_csv_line_limited($stream, (int) $limits['max_row_bytes'], $line_too_long);
            if ($line === null) {
                break;
            }
            $row_number++;
            if ($line_too_long) {
                $this->add_issue($diagnostics, 'errors', 'csv_row_too_large', 'A CSV row exceeds the configured size limit.', $meta['source'], $row_number);
                continue;
            }
            $row_error = '';
            $values = $this->parse_csv_line_with_limits($line, $delimiter, $limits, $row_error);
            if (!is_array($values)) {
                $code = $row_error === 'columns' ? 'csv_column_overflow' : 'csv_row_too_large';
                $message = $row_error === 'columns'
                    ? 'A CSV row exceeds the configured column limit.'
                    : 'A CSV row is malformed or exceeds the configured size limit.';
                $this->add_issue($diagnostics, 'errors', $code, $message, $meta['source'], $row_number, ['column_limit' => $limits['max_columns']]);
                continue;
            }
            if ($this->is_empty_csv_row($values)) {
                continue;
            }
            if ($diagnostics['rows_read'] >= $limits['max_rows']) {
                $diagnostics['truncated'] = true;
                $this->add_issue($diagnostics, 'errors', 'row_limit_exceeded', 'The combined report row limit was reached; remaining rows were not processed.', $meta['source'], $row_number, ['limit' => $limits['max_rows']]);
                break;
            }
            if (count($values) > count($columns)) {
                $this->add_issue($diagnostics, 'errors', 'csv_column_overflow', 'A CSV row contains more values than its header.', $meta['source'], $row_number);
                continue;
            }

            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }
            $diagnostics['rows_read']++;
            $normalized = $this->normalize_summary_row($row, $meta, $diagnostics, $row_number);
            if (!$normalized) {
                continue;
            }
            $this->aggregate_summary_row($normalized, $groups, $identity_index, $diagnostics, $row_number);
            $accepted++;
        }

        fclose($stream);
        if ($accepted === 0) {
            $this->add_issue($diagnostics, 'warnings', 'no_valid_rows', 'No valid jurisdiction rows were found in this report.', $meta['source']);
        }
        return true;
    }

    /**
     * @param array<string,string> $row
     * @param array<string,mixed>  $meta
     * @param array<string,mixed>  $diagnostics
     * @return array<string,mixed>|false
     */
    private function normalize_summary_row(array $row, array $meta, array &$diagnostics, int $row_number)
    {
        $normalized = [
            'state'                 => $this->clean_label($row['state'] ?? '', 64),
            'jurisdiction_type'     => $this->clean_label($row['jurisdiction_type'] ?? '', 64),
            'jurisdiction_name'     => $this->clean_label($row['jurisdiction_name'] ?? '', 255),
            'jurisdiction_code'     => $this->clean_label($row['jurisdiction_code'] ?? '', 128),
            'county'                => $this->clean_label($row['county'] ?? '', 255),
            'city'                  => $this->clean_label($row['city'] ?? '', 255),
            'rate_percent'          => trim((string) ($row['rate_percent'] ?? '')),
            'currency'              => strtoupper($this->clean_label($row['currency'] ?? '', 12)),
            'filing_status'         => $this->clean_label($row['filing_status'] ?? '', 64),
            '_source'               => (string) $meta['source'],
            '_site'                 => (string) $meta['site'],
            '_report_id'            => (string) $meta['report_id'],
        ];

        if ($normalized['county'] === '' && $this->normalize_key($normalized['jurisdiction_type']) === 'county') {
            $normalized['county'] = $normalized['jurisdiction_name'];
        }
        if ($normalized['city'] === '' && in_array($this->normalize_key($normalized['jurisdiction_type']), ['city', 'municipality', 'town'], true)) {
            $normalized['city'] = $normalized['jurisdiction_name'];
        }
        if ($normalized['state'] === '' || $normalized['currency'] === '' || !$this->has_jurisdiction_identifier($normalized)) {
            $this->add_issue($diagnostics, 'errors', 'invalid_jurisdiction_row', 'The row is missing state, currency, or a jurisdiction identifier.', $meta['source'], $row_number);
            return false;
        }
        if (!preg_match('/^[A-Z0-9()_.-]{1,12}$/', $normalized['currency'])) {
            $this->add_issue($diagnostics, 'errors', 'invalid_currency', 'The row contains an invalid currency identifier.', $meta['source'], $row_number);
            return false;
        }
        if ($normalized['rate_percent'] !== '' && !$this->is_decimal($normalized['rate_percent'])) {
            $this->add_issue($diagnostics, 'errors', 'invalid_rate', 'The row contains a non-numeric tax rate.', $meta['source'], $row_number);
            return false;
        }

        foreach ($this->sum_fields as $field) {
            $value = isset($row[$field]) && $row[$field] !== '' ? trim((string) $row[$field]) : '0';
            if (!$this->is_decimal($value) || ($field === 'orders' && !preg_match('/^\+?\d+$/', $value))) {
                $this->add_issue($diagnostics, 'errors', 'invalid_numeric_value', 'The row contains a non-numeric report total.', $meta['source'], $row_number, ['column' => $field]);
                return false;
            }
            $normalized[$field] = $this->canonical_decimal($value);
        }
        return $normalized;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array>    $groups
     * @param array<string,array> $identity_index
     * @param array<string,mixed> $diagnostics
     */
    private function aggregate_summary_row(array $row, array &$groups, array &$identity_index, array &$diagnostics, int $row_number): void
    {
        $prefix = $this->normalize_key($row['currency']) . '|' . $this->normalize_key($row['state']) . '|';
        $aliases = $this->identity_aliases($row);
        $candidate_sets = [];
        foreach ($aliases as $alias) {
            $index_key = $prefix . $alias;
            if (!empty($identity_index[$index_key])) {
                $candidate_sets[] = array_keys($identity_index[$index_key]);
            }
        }

        $candidates = [];
        foreach ($candidate_sets as $set) {
            $candidates = array_values(array_unique(array_merge($candidates, $set)));
        }
        if (count($candidates) > 1 && count($candidate_sets) > 1) {
            $intersection = array_shift($candidate_sets);
            foreach ($candidate_sets as $set) {
                $intersection = array_values(array_intersect($intersection, $set));
            }
            if (count($intersection) === 1) {
                $candidates = $intersection;
            }
        }

        if (count($candidates) > 1) {
            $this->add_issue($diagnostics, 'warnings', 'ambiguous_jurisdiction_identity', 'The row matched multiple existing jurisdiction groups and was kept separate.', $row['_source'], $row_number);
            $candidates = [];
        }

        if (empty($candidates)) {
            $group_id = count($groups);
            $groups[$group_id] = $row;
            foreach ($this->sum_fields as $field) {
                $groups[$group_id][$field] = '0';
            }
            $groups[$group_id]['_rates'] = [];
            $groups[$group_id]['_sites'] = [];
            $groups[$group_id]['_reports'] = [];
            $groups[$group_id]['_sources'] = [];
        } else {
            $group_id = (int) $candidates[0];
            foreach (['jurisdiction_type', 'jurisdiction_name', 'jurisdiction_code', 'county', 'city'] as $field) {
                if ($groups[$group_id][$field] === '' && $row[$field] !== '') {
                    $groups[$group_id][$field] = $row[$field];
                }
            }
        }

        foreach ($this->sum_fields as $field) {
            $groups[$group_id][$field] = $this->decimal_add((string) $groups[$group_id][$field], (string) $row[$field]);
        }
        if ($row['rate_percent'] !== '') {
            $groups[$group_id]['_rates'][$this->canonical_decimal($row['rate_percent'])] = true;
        }
        if ($row['_site'] !== '') {
            $groups[$group_id]['_sites'][$row['_site']] = true;
        }
        if ($row['_report_id'] !== '') {
            $groups[$group_id]['_reports'][$row['_report_id']] = true;
        }
        $groups[$group_id]['_sources'][$row['_source']] = true;
        $groups[$group_id]['filing_status'] = $this->merge_filing_status((string) $groups[$group_id]['filing_status'], (string) $row['filing_status']);

        foreach ($this->identity_aliases($groups[$group_id]) as $alias) {
            $identity_index[$prefix . $alias][$group_id] = true;
        }
    }

    /** @param array<string,mixed> $row */
    private function has_jurisdiction_identifier(array $row): bool
    {
        foreach (['jurisdiction_code', 'county', 'city', 'jurisdiction_name'] as $field) {
            if (!empty($row[$field])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $row @return string[] */
    private function identity_aliases(array $row): array
    {
        if (!empty($row['jurisdiction_code'])) {
            return ['jurisdiction_code:' . $this->normalize_key((string) $row['jurisdiction_code'])];
        }

        // Without a stable jurisdiction code, only an exact hierarchical
        // identity may be merged. County/city aliases must never merge rows of
        // different jurisdiction types or names.
        return ['hierarchy:' . implode('|', [
            $this->normalize_key((string) ($row['jurisdiction_type'] ?? '')),
            $this->normalize_key((string) ($row['jurisdiction_name'] ?? '')),
            $this->normalize_key((string) ($row['county'] ?? '')),
            $this->normalize_key((string) ($row['city'] ?? '')),
        ])];
    }

    /** @param array<int,array> $groups */
    private function finalize_groups(array &$groups): void
    {
        foreach ($groups as &$group) {
            $rates = array_keys((array) $group['_rates']);
            sort($rates, SORT_NATURAL);
            $group['rate_percent'] = count($rates) === 1 ? $rates[0] : (count($rates) > 1 ? 'mixed' : '');
            $group['rate_percent_values'] = $rates;
            $group['site_count'] = count((array) $group['_sites']);
            $group['report_count'] = count((array) $group['_reports']);
            $group['source_sites'] = implode(' | ', array_keys((array) $group['_sites']));
            $group['report_ids'] = implode(' | ', array_keys((array) $group['_reports']));
            $group['source_files'] = implode(' | ', array_keys((array) $group['_sources']));
            unset($group['_rates'], $group['_sites'], $group['_reports'], $group['_sources'], $group['_source'], $group['_site'], $group['_report_id']);
        }
        unset($group);

        usort($groups, function ($a, $b) {
            foreach (['state', 'currency', 'jurisdiction_type', 'jurisdiction_name', 'jurisdiction_code'] as $field) {
                $comparison = strcasecmp((string) ($a[$field] ?? ''), (string) ($b[$field] ?? ''));
                if ($comparison !== 0) {
                    return $comparison;
                }
            }
            return 0;
        });
    }

    private function merge_filing_status(string $current, string $incoming): string
    {
        $rank = ['' => 0, 'ready' => 1, 'no_tax_due' => 2, 'estimate' => 3, 'needs_review' => 4];
        $a = $this->normalize_key($current);
        $b = $this->normalize_key($incoming);
        if ($b === '') {
            return $current;
        }
        if ($a === '') {
            return $incoming;
        }
        return ($rank[$b] ?? 5) > ($rank[$a] ?? 5) ? $incoming : $current;
    }

    /**
     * @param array<string,mixed>     $diagnostics
     * @param array<string,mixed>     $context
     */
    private function add_issue(array &$diagnostics, string $level, string $code, string $message, string $source = '', int $row = 0, array $context = []): void
    {
        if (isset($diagnostics[$level]) && is_array($diagnostics[$level]) && count($diagnostics[$level]) >= $this->limits['max_diagnostics']) {
            $diagnostics['truncated'] = true;
            return;
        }
        $issue = ['code' => $code, 'message' => $message];
        if ($source !== '') {
            $issue['source'] = $source;
        }
        if ($row > 0) {
            $issue['row'] = $row;
        }
        if (!empty($context)) {
            $issue['context'] = $context;
        }
        $diagnostics[$level][] = $issue;
    }

    /**
     * Keep template diagnostics bounded before they reach a transient or UI.
     *
     * @param array<string,mixed> $diagnostics
     * @param array<string,mixed> $issue
     */
    private function add_mapping_issue(array &$diagnostics, string $level, array $issue): void
    {
        if (!isset($diagnostics[$level]) || !is_array($diagnostics[$level])) {
            $diagnostics[$level] = [];
        }
        if (count($diagnostics[$level]) >= (int) $this->limits['max_diagnostics']) {
            $diagnostics['truncated'] = true;
            $diagnostics['diagnostics_omitted'] = (int) ($diagnostics['diagnostics_omitted'] ?? 0) + 1;
            return;
        }
        $diagnostics[$level][] = $issue;
    }

    /** @return string|false */
    private function read_file_limited(string $path, int $limit)
    {
        $stream = fopen($path, 'rb');
        if (!$stream) {
            return false;
        }
        $contents = $this->read_stream_limited($stream, $limit);
        fclose($stream);
        return $contents;
    }

    /** @param resource $stream @return string|false */
    private function read_stream_limited($stream, int $limit)
    {
        $contents = '';
        while (!feof($stream)) {
            $remaining = $limit + 1 - strlen($contents);
            if ($remaining <= 0) {
                return false;
            }
            $chunk = fread($stream, min(8192, $remaining));
            if ($chunk === false) {
                return false;
            }
            $contents .= $chunk;
        }
        return strlen($contents) <= $limit ? $contents : false;
    }

    /**
     * Read one physical CSV record without allowing an oversized line to be
     * materialized by fgetcsv(). Multiline CSV fields are intentionally not
     * supported for filing summaries or mapping templates.
     *
     * @param resource $stream
     * @return string|null Null at EOF.
     */
    private function read_csv_line_limited($stream, int $limit, bool &$too_long)
    {
        $too_long = false;
        $line = fgets($stream, $limit + 3);
        if ($line === false) {
            return null;
        }

        $complete = substr($line, -1) === "\n" || feof($stream);
        if (!$complete) {
            $too_long = true;
            while (($remainder = fgets($stream, 8192)) !== false) {
                if (substr($remainder, -1) === "\n") {
                    break;
                }
            }
            return '';
        }

        $line = rtrim($line, "\r\n");
        if (strlen($line) > $limit) {
            $too_long = true;
            return '';
        }
        return $line;
    }

    private function detect_delimiter(string $content, int $max_columns): string
    {
        $line = strtok($content, "\r\n");
        $line = is_string($line) ? $line : '';
        $best = ',';
        $best_count = 0;
        foreach ([',', ';', "\t"] as $delimiter) {
            $malformed = false;
            $count = $this->count_csv_columns_limited($line, $delimiter, $max_columns, $malformed);
            if ($count > $best_count) {
                $best_count = $count;
                $best = $delimiter;
            }
        }
        return $best;
    }

    /**
     * Count fields without materializing them. The scan stops as soon as the
     * configured limit is exceeded, preventing delimiter-heavy rows from
     * expanding into very large PHP arrays inside str_getcsv().
     */
    private function count_csv_columns_limited(string $line, string $delimiter, int $max_columns, bool &$malformed): int
    {
        $malformed = false;
        $columns = 1;
        $quoted = false;
        $length = strlen($line);
        for ($index = 0; $index < $length; $index++) {
            $character = $line[$index];
            if ($character === '"') {
                if ($quoted && $index + 1 < $length && $line[$index + 1] === '"') {
                    $index++;
                    continue;
                }
                $quoted = !$quoted;
                continue;
            }
            if (!$quoted && $character === $delimiter) {
                $columns++;
                if ($columns > $max_columns) {
                    return $columns;
                }
            }
        }
        $malformed = $quoted;
        return $columns;
    }

    /**
     * @param array<string,int|float> $limits
     * @return array<int,string>|false
     */
    private function parse_csv_line_with_limits(string $line, string $delimiter, array $limits, string &$error)
    {
        $error = '';
        $malformed = false;
        $columns = $this->count_csv_columns_limited($line, $delimiter, (int) $limits['max_columns'], $malformed);
        if ($columns > (int) $limits['max_columns']) {
            $error = 'columns';
            return false;
        }
        if ($malformed) {
            $error = 'malformed';
            return false;
        }

        $values = str_getcsv($line, $delimiter, '"', '');
        if (!is_array($values) || count($values) > (int) $limits['max_columns']) {
            $error = 'columns';
            return false;
        }
        if (!$this->csv_row_within_limits($values, $limits)) {
            $error = 'size';
            return false;
        }
        return $values;
    }

    private function canonical_header(string $header): string
    {
        $key = $this->normalize_key($this->strip_bom($header));
        $aliases = [
            'state_code'              => 'state',
            'jurisdiction'            => 'jurisdiction_name',
            'jurisdiction_id'         => 'jurisdiction_code',
            'jurisdiction_fips'       => 'jurisdiction_code',
            'county_name'             => 'county',
            'city_name'               => 'city',
            'municipality'            => 'city',
            'rate'                    => 'rate_percent',
            'tax_rate'                => 'rate_percent',
            'taxable_revenue'         => 'taxable_sales',
            'taxable_shipping_amount' => 'taxable_shipping',
            'tax_collected'           => 'net_tax',
            'collected_tax'           => 'net_tax',
            'tax_owed'                => 'calculated_tax',
            'owed_tax'                => 'calculated_tax',
            'difference'              => 'over_under',
        ];
        return isset($aliases[$key]) ? $aliases[$key] : $key;
    }

    private function normalize_key(string $value): string
    {
        $value = trim($this->strip_bom($value));
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        } elseif (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) {
                $value = $converted;
            }
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value);
        return trim((string) $value, '_');
    }

    private function strip_bom(string $value): string
    {
        return substr($value, 0, 3) === "\xEF\xBB\xBF" ? substr($value, 3) : $value;
    }

    private function clean_label(string $value, int $max_bytes): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        $value = trim((string) $value);
        if (strlen($value) > $max_bytes) {
            $value = substr($value, 0, $max_bytes);
        }
        return $value;
    }

    private function valid_manifest_identifier($value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);
        return preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $value) ? $value : '';
    }

    private function site_label(array $manifest, string $fallback): string
    {
        foreach (['site_url', 'site_name', 'site_id'] as $field) {
            if (isset($manifest[$field]) && is_scalar($manifest[$field])) {
                $value = $this->clean_label((string) $manifest[$field], 255);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return $fallback;
    }

    private function is_decimal(string $value): bool
    {
        $value = trim($value);
        return strlen($value) <= 64 && (bool) preg_match('/^[+-]?(?:\d+)(?:\.\d+)?$/', $value);
    }

    private function canonical_decimal(string $value): string
    {
        $parts = $this->decimal_parts($value);
        return $this->decimal_from_parts($parts['sign'], $parts['digits'], $parts['scale']);
    }

    private function decimal_add(string $left, string $right): string
    {
        $a = $this->decimal_parts($left);
        $b = $this->decimal_parts($right);
        $scale = max($a['scale'], $b['scale']);
        $a_digits = $a['digits'] . str_repeat('0', $scale - $a['scale']);
        $b_digits = $b['digits'] . str_repeat('0', $scale - $b['scale']);

        if ($a['sign'] === $b['sign']) {
            return $this->decimal_from_parts($a['sign'], $this->unsigned_add($a_digits, $b_digits), $scale);
        }
        $comparison = $this->unsigned_compare($a_digits, $b_digits);
        if ($comparison === 0) {
            return '0';
        }
        if ($comparison > 0) {
            return $this->decimal_from_parts($a['sign'], $this->unsigned_subtract($a_digits, $b_digits), $scale);
        }
        return $this->decimal_from_parts($b['sign'], $this->unsigned_subtract($b_digits, $a_digits), $scale);
    }

    /** @return array{sign:int,digits:string,scale:int} */
    private function decimal_parts(string $value): array
    {
        $value = trim($value);
        $sign = 1;
        if ($value !== '' && ($value[0] === '-' || $value[0] === '+')) {
            $sign = $value[0] === '-' ? -1 : 1;
            $value = substr($value, 1);
        }
        $pieces = explode('.', $value, 2);
        $whole = ltrim($pieces[0], '0');
        $fraction = isset($pieces[1]) ? rtrim($pieces[1], '0') : '';
        $digits = ltrim(($whole === '' ? '0' : $whole) . $fraction, '0');
        return ['sign' => $sign, 'digits' => $digits === '' ? '0' : $digits, 'scale' => strlen($fraction)];
    }

    private function decimal_from_parts(int $sign, string $digits, int $scale): string
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '0';
        }
        if ($scale > 0) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $value = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
            $value = rtrim(rtrim($value, '0'), '.');
        } else {
            $value = $digits;
        }
        return $sign < 0 ? '-' . $value : $value;
    }

    private function unsigned_compare(string $left, string $right): int
    {
        $left = ltrim($left, '0');
        $right = ltrim($right, '0');
        if (strlen($left) !== strlen($right)) {
            return strlen($left) > strlen($right) ? 1 : -1;
        }
        return strcmp($left, $right) <=> 0;
    }

    private function unsigned_add(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $left_index = strlen($left) - 1;
        $right_index = strlen($right) - 1;
        while ($left_index >= 0 || $right_index >= 0 || $carry > 0) {
            $sum = $carry;
            if ($left_index >= 0) {
                $sum += (int) $left[$left_index--];
            }
            if ($right_index >= 0) {
                $sum += (int) $right[$right_index--];
            }
            $result = (string) ($sum % 10) . $result;
            $carry = (int) floor($sum / 10);
        }
        return $result;
    }

    /** Left must be greater than or equal to right. */
    private function unsigned_subtract(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        $right = str_pad($right, strlen($left), '0', STR_PAD_LEFT);
        for ($index = strlen($left) - 1; $index >= 0; $index--) {
            $digit = (int) $left[$index] - $borrow - (int) $right[$index];
            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) $digit . $result;
        }
        $result = ltrim($result, '0');
        return $result === '' ? '0' : $result;
    }

    /** @param array<int,mixed> $values */
    private function is_empty_csv_row(array $values): bool
    {
        return !$this->has_nonempty_values($values);
    }

    /** @param array<int,mixed> $values */
    private function has_nonempty_values(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }

    /** @param array<int,mixed> $values @param array<string,int|float> $limits */
    private function csv_row_within_limits(array $values, array $limits): bool
    {
        $bytes = 0;
        foreach ($values as $value) {
            $length = strlen((string) $value);
            if ($length > $limits['max_cell_bytes']) {
                return false;
            }
            $bytes += $length;
        }
        return $bytes <= $limits['max_row_bytes'];
    }

    /**
     * @param array<string,mixed>     $diagnostics
     * @param array<string,int|float> $limits
     * @return array<string,mixed>|false
     */
    private function parse_template_csv(string $path, string $source, array &$diagnostics, array $limits)
    {
        $stream = fopen($path, 'rb');
        if (!$stream) {
            $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'template_stream_failed', 'message' => 'The template stream could not be opened.']);
            return false;
        }
        $header_too_long = false;
        $header_line = $this->read_csv_line_limited($stream, (int) $limits['max_row_bytes'], $header_too_long);
        if ($header_too_long || (is_string($header_line) && strpos($header_line, "\0") !== false)) {
            fclose($stream);
            $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'template_header_too_large', 'message' => 'The template header is binary or exceeds the configured row-size limit.']);
            return false;
        }
        $delimiter = $this->detect_delimiter(is_string($header_line) ? $header_line : '', (int) $limits['max_columns']);
        $header_error = '';
        $header_values = is_string($header_line) ? $this->parse_csv_line_with_limits($header_line, $delimiter, $limits, $header_error) : false;
        if (!is_array($header_values) || empty($header_values)) {
            fclose($stream);
            $this->add_mapping_issue($diagnostics, 'errors', [
                'code'    => $header_error === '' ? 'template_header_missing' : 'template_header_limits_exceeded',
                'message' => $header_error === '' ? 'The template has no readable header row.' : 'The template header is malformed or exceeds the configured cell or column limit.',
            ]);
            return false;
        }

        $headers = [];
        $seen = [];
        foreach ($header_values as $index => $header) {
            $header = trim($index === 0 ? $this->strip_bom((string) $header) : (string) $header);
            $lookup = $this->normalize_key($header);
            if ($header === '' || isset($seen[$lookup])) {
                fclose($stream);
                $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'template_header_invalid', 'message' => 'The template contains an empty or duplicate header.']);
                return false;
            }
            $seen[$lookup] = true;
            $headers[] = $header;
        }
        return ['headers' => $headers, 'stream' => $stream, 'delimiter' => $delimiter, 'source' => $source];
    }

    /**
     * @param string[]              $headers
     * @param array<string,mixed>   $mapping
     * @param array<string,mixed>   $diagnostics
     * @return array<string,array>
     */
    private function resolve_template_mapping(array $headers, array $mapping, array &$diagnostics): array
    {
        $header_lookup = [];
        foreach ($headers as $header) {
            $header_lookup[$this->normalize_key($header)] = $header;
        }

        $keys = [];
        $outputs = [];
        $explicit_keys = isset($mapping['template_keys']) && is_array($mapping['template_keys']) ? $mapping['template_keys'] : [];
        $explicit_outputs = isset($mapping['output_columns']) && is_array($mapping['output_columns']) ? $mapping['output_columns'] : [];

        if (!empty($explicit_keys)) {
            foreach ($explicit_keys as $field => $header) {
                $resolved = $this->resolve_header_name((string) $header, $header_lookup);
                if ($resolved === '') {
                    $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'mapped_key_header_missing', 'message' => 'A mapped template key header was not found.', 'field' => (string) $field, 'header' => (string) $header]);
                } else {
                    $keys[$this->canonical_header((string) $field)] = $resolved;
                }
            }
        } else {
            foreach ($headers as $header) {
                $field = $this->canonical_header($header);
                if (in_array($field, ['state', 'currency', 'jurisdiction_code', 'county', 'city', 'jurisdiction_name', 'jurisdiction_type'], true)) {
                    $keys[$field] = $header;
                }
            }
        }

        if (!empty($explicit_outputs)) {
            foreach ($explicit_outputs as $field => $header) {
                $resolved = $this->resolve_header_name((string) $header, $header_lookup);
                if ($resolved === '') {
                    $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'mapped_output_header_missing', 'message' => 'A mapped output header was not found.', 'field' => (string) $field, 'header' => (string) $header]);
                } else {
                    $outputs[$this->canonical_header((string) $field)] = $resolved;
                }
            }
        } else {
            $allowed = array_merge(
                ['state', 'currency', 'jurisdiction_type', 'jurisdiction_name', 'jurisdiction_code', 'county', 'city', 'rate_percent', 'filing_status'],
                $this->sum_fields
            );
            foreach ($headers as $header) {
                $field = $this->canonical_header($header);
                if (in_array($field, $allowed, true)) {
                    $outputs[$field] = $header;
                }
            }
        }

        if (empty(array_intersect(['jurisdiction_code', 'county', 'city', 'jurisdiction_name'], array_keys($keys)))) {
            $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'template_match_key_missing', 'message' => 'The template needs a mapped jurisdiction_code, county, city, or jurisdiction_name column.']);
        }
        if (empty($outputs)) {
            $this->add_mapping_issue($diagnostics, 'errors', ['code' => 'template_output_columns_missing', 'message' => 'No report output columns could be mapped to the template.']);
        }
        return ['keys' => $keys, 'outputs' => $outputs];
    }

    private function resolve_header_name(string $requested, array $header_lookup): string
    {
        $lookup = $this->normalize_key($requested);
        return isset($header_lookup[$lookup]) ? $header_lookup[$lookup] : '';
    }

    /**
     * @param array<int,array> $combined_rows
     * @param array<string,string> $key_map
     * @return array<string,array<int,int>>
     */
    private function build_template_match_index(array $combined_rows, array $key_map): array
    {
        $index = [];
        $fields = array_values(array_intersect(
            ['jurisdiction_code', 'county', 'city', 'jurisdiction_name'],
            array_keys($key_map)
        ));
        foreach ($combined_rows as $row_index => $row) {
            foreach ($fields as $field) {
                $value = $this->combined_match_value((array) $row, $field);
                if ($value !== '') {
                    $index[$field . '|' . $value][] = $row_index;
                }
            }
        }
        return $index;
    }

    /**
     * @param array<string,string>       $template_row
     * @param array<int,array>           $combined_rows
     * @param array<string,array<int,int>> $index
     * @param array<string,string>       $key_map
     * @return int[]
     */
    private function find_template_matches(
        array $template_row,
        array $combined_rows,
        array $index,
        array $key_map,
        int &$comparison_count,
        int $comparison_limit,
        bool &$limit_exceeded
    ): array
    {
        $candidates = null;
        foreach (['jurisdiction_code', 'city', 'jurisdiction_name', 'county'] as $field) {
            if (isset($key_map[$field])) {
                $value = $this->normalize_key((string) ($template_row[$key_map[$field]] ?? ''));
                if ($value !== '') {
                    $field_candidates = isset($index[$field . '|' . $value]) ? $index[$field . '|' . $value] : [];
                    if ($candidates === null || count($field_candidates) < count($candidates)) {
                        $candidates = $field_candidates;
                    }
                    if (empty($candidates)) {
                        break;
                    }
                }
            }
        }
        if ($candidates === null) {
            return [];
        }

        foreach ($candidates as $offset => $row_index) {
            $combined = (array) $combined_rows[$row_index];
            foreach ($key_map as $field => $header) {
                $comparison_count++;
                if ($comparison_count > $comparison_limit) {
                    $limit_exceeded = true;
                    return [];
                }
                $expected = $this->normalize_key((string) ($template_row[$header] ?? ''));
                if ($expected !== '' && $this->combined_match_value($combined, $field) !== $expected) {
                    unset($candidates[$offset]);
                    break;
                }
            }
        }
        return array_values($candidates);
    }

    /** @param array<string,mixed> $row */
    private function combined_match_value(array $row, string $field): string
    {
        if ($field === 'county' && empty($row['county']) && $this->normalize_key((string) ($row['jurisdiction_type'] ?? '')) === 'county') {
            return $this->normalize_key((string) ($row['jurisdiction_name'] ?? ''));
        }
        if ($field === 'city' && empty($row['city']) && in_array($this->normalize_key((string) ($row['jurisdiction_type'] ?? '')), ['city', 'municipality', 'town'], true)) {
            return $this->normalize_key((string) ($row['jurisdiction_name'] ?? ''));
        }
        return $this->normalize_key((string) ($row[$field] ?? ''));
    }

    /** @param array<string,string> $template_row @param array<string,string> $key_map @return array<string,string> */
    private function template_identifiers(array $template_row, array $key_map): array
    {
        $identifiers = [];
        foreach ($key_map as $field => $header) {
            $value = trim((string) ($template_row[$header] ?? ''));
            if ($value !== '') {
                $identifiers[$field] = $value;
            }
        }
        return $identifiers;
    }

    /**
     * @param array<string,string> $template_row
     * @param array<string,mixed>  $combined_row
     * @param array<string,array>  $column_maps
     * @return array<string,string>
     */
    private function apply_output_values(array $template_row, array $combined_row, array $column_maps): array
    {
        foreach ($column_maps['outputs'] as $field => $header) {
            if (array_key_exists($field, $combined_row)) {
                $value = $combined_row[$field];
                if (is_array($value) || is_object($value)) {
                    $value = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
                }
                $template_row[$header] = (string) $value;
            }
        }
        return $template_row;
    }

    /** @param string[] $headers @return resource|false */
    private function open_csv_output_stream(array $headers)
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (!$stream) {
            return false;
        }
        if (fwrite($stream, "\xEF\xBB\xBF") !== 3
            || fputcsv($stream, array_map([$this, 'safe_spreadsheet_value'], $headers), ',', '"', '') === false) {
            fclose($stream);
            return false;
        }
        return $stream;
    }

    /** @param resource $stream @param string[] $headers @param array<string,mixed> $row */
    private function write_csv_output_row($stream, array $headers, array $row): bool
    {
        $values = [];
        foreach ($headers as $header) {
            $values[] = $this->safe_spreadsheet_value($row[$header] ?? '');
        }
        return fputcsv($stream, $values, ',', '"', '') !== false;
    }

    /** @param mixed $value */
    private function safe_spreadsheet_value($value): string
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value) || is_object($value)) {
            $value = function_exists('wp_json_encode') ? wp_json_encode($value) : json_encode($value);
        }
        $value = (string) $value;
        $trimmed = ltrim($value, "\x00..\x20");
        if ($trimmed !== '' && preg_match('/^[=+\-@]/', $trimmed) && !preg_match('/^[+-]?\d+(?:\.\d+)?$/', $trimmed)) {
            return "'" . $value;
        }
        return $value;
    }

    /** @param array<string,mixed> $diagnostics @param array<string,mixed> $options @return array<string,mixed> */
    private function empty_mapping_result(array $diagnostics, array $options): array
    {
        return [
            'csv'         => '',
            'stream'      => null,
            'filename'    => $this->safe_filename(isset($options['filename']) ? (string) $options['filename'] : 'mapped-state-tax-report.csv'),
            'diagnostics' => $diagnostics,
        ];
    }

    private function safe_filename(string $filename): string
    {
        if (function_exists('sanitize_file_name')) {
            $filename = sanitize_file_name($filename);
        } else {
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($filename));
        }
        $filename = trim((string) $filename, '.-');
        return $filename !== '' ? $filename : 'tax-report.csv';
    }
}
