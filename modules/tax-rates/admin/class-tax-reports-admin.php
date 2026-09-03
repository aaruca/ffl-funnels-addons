<?php
/**
 * Concise multi-state sales tax filing report UI.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Reports_Admin
{
    /** @var string */
    private static $tool_lock_token = '';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_woocommerce_menu'], 70);
        add_action('admin_post_ffla_tax_report_export', [__CLASS__, 'export']);
        add_action('admin_post_ffla_tax_report_email_save', [__CLASS__, 'save_email_settings']);
        add_action('admin_post_ffla_tax_report_email_send', [__CLASS__, 'queue_email']);
        add_action('admin_post_ffla_tax_report_combine', [__CLASS__, 'combine_reports']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Keep the filing workflow where store managers already work: WooCommerce.
     */
    public static function register_woocommerce_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Sales Tax Reports', 'ffl-funnels-addons'),
            __('Sales Tax Reports', 'ffl-funnels-addons'),
            'manage_woocommerce',
            'ffla-sales-tax-reports',
            [__CLASS__, 'render_page']
        );
    }

    public static function render_page(): void
    {
        echo '<div class="wrap ffla-admin ffla-tax-reports-page">';
        echo '<h1>' . esc_html__('Sales Tax Filing Reports', 'ffl-funnels-addons') . '</h1>';
        self::render(false);
        echo '</div>';
    }

    public static function enqueue_assets(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : '';
        $is_canonical_page = $page === 'ffla-sales-tax-reports';
        $is_legacy_page = $page === 'ffla-tax-rates' && $tab === 'reports';
        if (!$is_canonical_page && !$is_legacy_page) {
            return;
        }

        $report_css = 'modules/tax-rates/admin/css/tax-reports-admin.css';
        $report_js = 'modules/tax-rates/admin/js/tax-reports-admin.js';

        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_style('ffla-admin', FFLA_URL . 'admin/css/ffla-admin.css', [], FFLA_VERSION);
        wp_enqueue_style('ffla-tax-reports-admin', FFLA_URL . $report_css, ['ffla-admin'], self::asset_version($report_css));
        wp_enqueue_script('ffla-tax-reports-admin', FFLA_URL . $report_js, ['jquery', 'wc-enhanced-select'], self::asset_version($report_js), true);
        wp_localize_script('ffla-tax-reports-admin', 'fflaTaxReportsAdmin', [
            'i18n' => [
                'advancedFilters' => __('Advanced filters', 'ffl-funnels-addons'),
                'copied'          => __('Copied', 'ffl-funnels-addons'),
                'copyFailed'      => __('Could not copy', 'ffl-funnels-addons'),
                'searchTable'     => __('Search this table', 'ffl-funnels-addons'),
                'searchStates'    => __('Search states…', 'ffl-funnels-addons'),
                'noMatchingRows'  => __('No matching rows.', 'ffl-funnels-addons'),
            ],
        ]);
    }

    /**
     * Give report assets a content-based cache key, including same-version hotfixes.
     */
    private static function asset_version(string $relative_path): string
    {
        $path = FFLA_PATH . ltrim($relative_path, '/\\');
        if (!is_readable($path)) {
            return FFLA_VERSION;
        }

        $hash = hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            return FFLA_VERSION;
        }

        return FFLA_VERSION . '-' . substr($hash, 0, 12);
    }

    public static function export(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        check_admin_referer('ffla_tax_report_export');

        try {
            $input = is_array($_POST) ? wp_unslash($_POST) : [];
            $input = self::apply_period_preset($input);
            $service = new Tax_Report_Service();
            $report = $service->generate($input);
            Tax_Report_Exporter::download_package($report);
        } catch (Throwable $e) {
            wp_die(
                esc_html($e->getMessage()),
                esc_html__('Tax report could not be generated', 'ffl-funnels-addons'),
                ['response' => 400, 'back_link' => true]
            );
        }
    }

    public static function save_email_settings(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        check_admin_referer('ffla_tax_report_email_settings');
        try {
            $input = is_array($_POST) ? wp_unslash($_POST) : [];
            Tax_Report_Email::update_settings($input);
            self::redirect_to_reports(['email_saved' => '1']);
        } catch (Throwable $e) {
            self::redirect_to_reports(['email_error' => 'settings']);
        }
    }

    public static function queue_email(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        check_admin_referer('ffla_tax_report_email_send');
        $input = is_array($_POST) ? wp_unslash($_POST) : [];
        $mode = isset($input['mode']) && sanitize_key((string) $input['mode']) === 'test' ? 'test' : 'manual';
        if (Tax_Report_Email::queue_manual_send($mode)) {
            self::redirect_to_reports(['email_queued' => $mode]);
        }
        self::redirect_to_reports(['email_error' => 'queue']);
    }

    public static function combine_reports(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }
        check_admin_referer('ffla_tax_report_combine');

        if (get_transient(self::tool_rate_key())) {
            self::store_tool_diagnostics('blocked', [
                'errors' => [['code' => 'tool_rate_limited', 'message' => __('Please wait a few seconds before running another report tool request.', 'ffl-funnels-addons')]],
            ]);
            self::redirect_to_reports(['report_tab' => 'tools', 'tool_error' => 'rate']);
        }
        if (!self::acquire_tool_lock()) {
            self::store_tool_diagnostics('blocked', [
                'errors' => [['code' => 'tool_locked', 'message' => __('Another report tool operation is already running on this site.', 'ffl-funnels-addons')]],
            ]);
            self::redirect_to_reports(['report_tab' => 'tools', 'tool_error' => 'locked']);
        }
        set_transient(self::tool_rate_key(), 1, 10);

        $uploads = isset($_FILES['report_files']) && is_array($_FILES['report_files'])
            ? $_FILES['report_files'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by Tax_Report_Combiner.
            : [];
        $input = is_array($_POST) ? wp_unslash($_POST) : [];
        try {
            $combiner = new Tax_Report_Combiner();
            $combined = $combiner->combine_uploaded_files($uploads);
            $diagnostics = (array) ($combined['diagnostics'] ?? []);
            $rows = (array) ($combined['rows'] ?? []);

            if (empty($rows) || !empty($diagnostics['errors']) || !empty($diagnostics['truncated'])) {
                self::store_tool_diagnostics('combine', $diagnostics);
                self::release_tool_lock();
                self::redirect_to_reports(['report_tab' => 'tools', 'tool_error' => 'empty']);
            }

            $template = isset($_FILES['template_file']) && is_array($_FILES['template_file'])
                ? $_FILES['template_file'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by Tax_Report_Combiner.
                : [];
            $has_template = !empty($template)
                && isset($template['error'])
                && (int) $template['error'] === UPLOAD_ERR_OK;
            if (!empty($template) && isset($template['error']) && !in_array((int) $template['error'], [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE], true)) {
                $diagnostics['errors'][] = [
                    'code'    => 'template_upload_failed',
                    'message' => __('The optional template upload did not complete successfully.', 'ffl-funnels-addons'),
                ];
                self::store_tool_diagnostics('template_upload_failed', $diagnostics);
                self::release_tool_lock();
                self::redirect_to_reports(['report_tab' => 'tools', 'tool_error' => 'template']);
            }

            if ($has_template) {
                $mapping = self::normalize_template_mapping($input);
                $mapped = $combiner->map_state_template_stream($template, $rows, $mapping);
                $diagnostics['mapping'] = (array) ($mapped['diagnostics'] ?? []);
                $mapped_stream = $mapped['stream'] ?? null;
                self::store_tool_diagnostics('mapped', $diagnostics);
                if ((!is_resource($mapped_stream) && empty($mapped['csv']))
                    || !empty($diagnostics['mapping']['errors'])
                    || !empty($diagnostics['mapping']['unmatched'])
                    || !empty($diagnostics['mapping']['ambiguous'])
                    || !empty($diagnostics['mapping']['truncated'])) {
                    if (is_resource($mapped_stream)) {
                        fclose($mapped_stream);
                    }
                    self::release_tool_lock();
                    self::redirect_to_reports(['report_tab' => 'tools', 'tool_error' => 'mapping']);
                }
                $mapped_filename = (string) ($mapped['filename'] ?? 'mapped-state-tax-report.csv');
                if (is_resource($mapped_stream)) {
                    unset($mapped, $rows, $combined);
                    self::download_csv_stream($mapped_stream, $mapped_filename, $diagnostics);
                }
                $mapped_csv = (string) $mapped['csv'];
                unset($mapped, $rows, $combined);
                self::download_csv($mapped_csv, $mapped_filename, $diagnostics);
            }

            self::store_tool_diagnostics('combined', $diagnostics);
            $csv_stream = self::build_combined_csv_stream($rows);
            unset($rows, $combined);
            self::download_csv_stream(
                $csv_stream,
                'combined-sales-tax-jurisdictions-' . gmdate('Ymd-His') . '.csv',
                $diagnostics
            );
        } catch (Throwable $error) {
            self::store_tool_diagnostics('failed', [
                'errors' => [[
                    'code'    => 'tool_failed',
                    'message' => sanitize_text_field($error->getMessage()),
                ]],
            ]);
            self::release_tool_lock();
            self::redirect_to_reports(['report_tab' => 'tools', 'tool_error' => 'failed']);
        }
    }

    private static function redirect_to_reports(array $args): void
    {
        $url = add_query_arg(
            array_merge([
                'page' => 'ffla-sales-tax-reports',
            ], $args),
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    private static function normalize_template_mapping(array $input): array
    {
        $mapping = ['template_keys' => [], 'output_columns' => []];
        $keys = isset($input['map_keys']) && is_array($input['map_keys']) ? $input['map_keys'] : [];
        $outputs = isset($input['map_outputs']) && is_array($input['map_outputs']) ? $input['map_outputs'] : [];
        foreach ($keys as $report_field => $template_header) {
            $report_field = sanitize_key((string) $report_field);
            $template_header = sanitize_text_field((string) $template_header);
            if ($report_field !== '' && $template_header !== '') {
                $mapping['template_keys'][$report_field] = $template_header;
            }
        }
        foreach ($outputs as $report_field => $template_header) {
            $report_field = sanitize_key((string) $report_field);
            $template_header = sanitize_text_field((string) $template_header);
            if ($report_field !== '' && $template_header !== '') {
                $mapping['output_columns'][$report_field] = $template_header;
            }
        }
        return $mapping;
    }

    /** @return resource */
    private static function build_combined_csv_stream(array $rows)
    {
        $headers = [];
        foreach ($rows as $row) {
            foreach (array_keys((array) $row) as $header) {
                if (!in_array($header, $headers, true)) {
                    $headers[] = $header;
                }
            }
        }
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        if (!$stream) {
            throw new RuntimeException(__('The combined CSV could not be created.', 'ffl-funnels-addons'));
        }
        if (fputcsv($stream, $headers) === false) {
            fclose($stream);
            throw new RuntimeException(__('The combined CSV header could not be written.', 'ffl-funnels-addons'));
        }
        foreach ($rows as $row) {
            $values = [];
            foreach ($headers as $header) {
                $values[] = self::safe_spreadsheet_cell($row[$header] ?? '');
            }
            if (fputcsv($stream, $values) === false) {
                fclose($stream);
                throw new RuntimeException(__('The combined CSV could not be written completely.', 'ffl-funnels-addons'));
            }
        }
        rewind($stream);
        return $stream;
    }

    private static function safe_spreadsheet_cell($value): string
    {
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value);
        }
        $value = (string) $value;
        $trimmed = ltrim($value, "\x00..\x20");
        if ($trimmed !== ''
            && preg_match('/^[=+\-@]/', $trimmed)
            && !preg_match('/^[+-]?\d+(?:\.\d+)?$/', $trimmed)) {
            return "'" . $value;
        }
        return $value;
    }

    private static function download_csv(string $csv, string $filename, array $diagnostics): void
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+b');
        $written = is_resource($stream) ? fwrite($stream, $csv) : false;
        if (!$stream || $written === false || $written !== strlen($csv)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new RuntimeException(__('The CSV response could not be prepared.', 'ffl-funnels-addons'));
        }
        unset($csv);
        rewind($stream);
        self::download_csv_stream($stream, $filename, $diagnostics);
    }

    /** @param resource $stream */
    private static function download_csv_stream($stream, string $filename, array $diagnostics): void
    {
        if (!is_resource($stream)) {
            throw new RuntimeException(__('The CSV response stream is unavailable.', 'ffl-funnels-addons'));
        }
        $filename = sanitize_file_name($filename);
        if ($filename === '' || substr($filename, -4) !== '.csv') {
            $filename = 'sales-tax-report.csv';
        }
        if (headers_sent()) {
            fclose($stream);
            throw new RuntimeException(__('The CSV response could not start because output was already sent.', 'ffl-funnels-addons'));
        }
        $stats = fstat($stream);
        $length = is_array($stats) && isset($stats['size']) ? (int) $stats['size'] : 0;
        rewind($stream);
        while (ob_get_level()) {
            ob_end_clean();
        }
        self::release_tool_lock();
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        if ($length > 0) {
            header('Content-Length: ' . $length);
        }
        header('X-FFLA-Warnings: ' . count((array) ($diagnostics['warnings'] ?? [])));
        header('X-FFLA-Errors: ' . count((array) ($diagnostics['errors'] ?? [])));
        header('X-Content-Type-Options: nosniff');
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($stream);
                exit;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentional CSV download.
        }
        fclose($stream);
        exit;
    }

    private static function store_tool_diagnostics(string $mode, array $diagnostics): void
    {
        set_transient('ffla_tax_report_tool_diag_' . get_current_user_id(), [
            'created_at_utc' => gmdate('c'),
            'mode'           => sanitize_key($mode),
            'diagnostics'    => self::limit_diagnostics_for_storage($diagnostics),
        ], 10 * MINUTE_IN_SECONDS);
    }

    private static function tool_lock_key(): string
    {
        return 'ffla_tax_report_tool_lock';
    }

    private static function tool_rate_key(): string
    {
        return 'ffla_tax_report_tool_rate_' . get_current_user_id();
    }

    private static function acquire_tool_lock(): bool
    {
        $key = self::tool_lock_key();
        $token = wp_generate_uuid4();
        $value = [
            'token'   => $token,
            'owner'   => get_current_user_id(),
            'expires' => time() + 5 * MINUTE_IN_SECONDS,
        ];

        if (!add_option($key, $value, '', 'no')) {
            $existing = get_option($key, []);
            if (!is_array($existing) || (int) ($existing['expires'] ?? 0) >= time()) {
                return false;
            }
            if (!self::delete_tool_lock_if_unchanged($key, $existing)) {
                return false;
            }
            if (!add_option($key, $value, '', 'no')) {
                return false;
            }
        }

        self::$tool_lock_token = $token;
        return true;
    }

    private static function release_tool_lock(): void
    {
        if (self::$tool_lock_token === '') {
            return;
        }
        $key = self::tool_lock_key();
        $existing = get_option($key, []);
        if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), self::$tool_lock_token)) {
            self::delete_tool_lock_if_unchanged($key, $existing);
        }
        self::$tool_lock_token = '';
    }

    /**
     * Delete only the exact option value that was observed. This avoids an
     * expired-lock cleanup request removing a newer lock acquired by another
     * administrator between get_option() and deletion.
     */
    private static function delete_tool_lock_if_unchanged(string $key, array $expected): bool
    {
        global $wpdb;
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $key,
                maybe_serialize($expected)
            )
        );
        if ((int) $deleted !== 1) {
            return false;
        }

        wp_cache_delete($key, 'options');
        wp_cache_delete('notoptions', 'options');
        return true;
    }

    private static function limit_diagnostics_for_storage(array $diagnostics): array
    {
        foreach (['errors', 'warnings', 'duplicate_report_ids', 'unmatched', 'ambiguous'] as $key) {
            if (isset($diagnostics[$key]) && is_array($diagnostics[$key])) {
                $diagnostics[$key] = array_slice($diagnostics[$key], 0, 50);
            }
        }
        if (isset($diagnostics['mapping']) && is_array($diagnostics['mapping'])) {
            $diagnostics['mapping'] = self::limit_diagnostics_for_storage($diagnostics['mapping']);
        }
        return $diagnostics;
    }

    public static function render(bool $show_heading = true): void
    {
        if (!current_user_can('manage_woocommerce')) {
            echo '<p>' . esc_html__('You do not have permission to view tax reports.', 'ffl-funnels-addons') . '</p>';
            return;
        }

        $input = is_array($_GET) ? wp_unslash($_GET) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $active_tab = self::normalize_report_tab((string) ($input['report_tab'] ?? 'overview'));
        $has_preview = !empty($input['run_report']) && !in_array($active_tab, ['delivery', 'tools'], true);
        $filters = Tax_Report_Service::default_filters();
        $filters['_period_preset'] = 'year_to_date';
        $filters['include_pii'] = true;
        $report = null;
        $error = '';

        try {
            if (!empty($input['run_report'])) {
                if (!isset($input['_wpnonce']) || !wp_verify_nonce((string) $input['_wpnonce'], 'ffla_tax_report_export')) {
                    throw new RuntimeException(__('The report preview link expired. Submit the filters again.', 'ffl-funnels-addons'));
                }
                $input = self::apply_period_preset($input);
                $filters = Tax_Report_Service::normalize_filters($input);
                $filters['_period_preset'] = sanitize_key((string) ($input['period_preset'] ?? 'custom'));
                if (!$has_preview) {
                    // Delivery and Tools keep the visible report scope without
                    // running a potentially expensive report query.
                } elseif ($active_tab === 'nexus') {
                    $report = ['manifest' => ['filters' => $filters], 'stats' => [], 'summaries' => []];
                } else {
                    $service = new Tax_Report_Service();
                    $report = $service->generate($filters, [
                        'summary_only'    => $filters['report_detail'] !== 'advanced' && empty($filters['include_pii']),
                        'exception_limit' => 100,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        echo '<div class="ffla-tax-report-intro">';
        if ($show_heading) {
            echo '<h2>' . esc_html__('Sales Tax Filing Reports', 'ffl-funnels-addons') . '</h2>';
        }
        echo '<p>' . esc_html__('Prepare a concise multi-state filing summary from the final values stored in WooCommerce. Review statewide totals, the jurisdictions with activity, tax calculated from stored rates, and any over- or under-collection.', 'ffl-funnels-addons') . '</p>';
        echo '<p class="ffla-tax-report-callout"><strong>' . esc_html__('Before filing:', 'ffl-funnels-addons') . '</strong> ';
        echo esc_html__('Resolve rows marked Needs review and confirm the final amounts in each state filing portal. This report does not submit a return or determine nexus.', 'ffl-funnels-addons') . '</p>';
        echo '</div>';

        self::render_email_notices($input);

        if ($error !== '') {
            FFLA_Admin::render_notice('error', esc_html($error));
        }

        self::render_filter_form($filters, $active_tab);
        self::render_report_tabs($active_tab, !empty($input['run_report']));

        if (is_array($report)) {
            self::render_preview($report, $active_tab);
        } elseif (!in_array($active_tab, ['delivery', 'tools'], true) && $error === '') {
            echo '<div class="notice notice-info inline ffla-tax-report-empty-state"><p>'
                . esc_html__('Choose the reporting period and filters above, then select Preview filing report to load this section.', 'ffl-funnels-addons')
                . '</p></div>';
        }

        if ($active_tab === 'delivery') {
            self::render_email_schedule();
            self::render_history();
        } elseif ($active_tab === 'tools') {
            self::render_tools();
        }
    }

    private static function render_filter_form(array $filters, string $active_tab): void
    {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $states = self::get_us_states();

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="wb-card ffla-tax-report-filters">';
        echo '<input type="hidden" name="page" value="ffla-sales-tax-reports">';
        echo '<input type="hidden" name="report_tab" value="' . esc_attr($active_tab) . '">';
        echo '<input type="hidden" name="run_report" value="1">';
        echo '<input type="hidden" name="action" value="ffla_tax_report_export">';
        wp_nonce_field('ffla_tax_report_export');
        echo '<div class="wb-card__header"><h3>' . esc_html__('Filing period', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<div class="ffla-tax-report-filter-grid">';
        echo '<label><span>' . esc_html__('Period preset', 'ffl-funnels-addons') . '</span><select name="period_preset" data-ffla-period-preset>';
        foreach (self::period_presets() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected(($filters['_period_preset'] ?? 'custom'), $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label><span>' . esc_html__('Start date', 'ffl-funnels-addons') . '</span><input type="date" name="date_from" required value="' . esc_attr((string) $filters['date_from']) . '"></label>';
        echo '<label><span>' . esc_html__('End date', 'ffl-funnels-addons') . '</span><input type="date" name="date_to" required value="' . esc_attr((string) $filters['date_to']) . '"></label>';
        echo '<label class="ffla-tax-report-state-field"><span>' . esc_html__('States', 'ffl-funnels-addons') . '</span><select name="states[]" multiple data-ffla-state-filter data-placeholder="' . esc_attr__('Search states…', 'ffl-funnels-addons') . '">';
        foreach ($states as $code => $name) {
            echo '<option value="' . esc_attr($code) . '" ' . selected(in_array($code, (array) ($filters['states'] ?? []), true), true, false) . '>' . esc_html($code . ' — ' . $name) . '</option>';
        }
        echo '</select><small>' . esc_html__('Search and select one or more states. Leave empty for every destination state.', 'ffl-funnels-addons') . '</small></label>';
        echo '</div>';

        echo '<fieldset class="ffla-tax-report-statuses"><legend>' . esc_html__('Included order statuses', 'ffl-funnels-addons') . '</legend>';
        foreach ($statuses as $status_key => $label) {
            $status = preg_replace('/^wc-/', '', (string) $status_key);
            echo '<label><input type="checkbox" name="statuses[]" value="' . esc_attr($status) . '" ' . checked(in_array($status, $filters['statuses'], true), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</fieldset>';

        echo '<div class="ffla-tax-report-options">';
        echo '<label><span>' . esc_html__('Report detail', 'ffl-funnels-addons') . '</span><select name="report_detail">';
        echo '<option value="filing" ' . selected(($filters['report_detail'] ?? 'filing'), 'filing', false) . '>' . esc_html__('Filing summary', 'ffl-funnels-addons') . '</option>';
        echo '<option value="advanced" ' . selected(($filters['report_detail'] ?? 'filing'), 'advanced', false) . '>' . esc_html__('Advanced audit package', 'ffl-funnels-addons') . '</option>';
        echo '</select><small>' . esc_html__('Advanced mode adds orders, line items, tax lines, refunds, products, payments, and exceptions.', 'ffl-funnels-addons') . '</small></label>';
        echo '<label class="ffla-tax-report-pii"><input type="checkbox" name="include_negative_orders" value="1" ' . checked(!empty($filters['include_negative_orders']), true, false) . '> <span><strong>';
        echo esc_html__('Include negative-total orders', 'ffl-funnels-addons') . '</strong><small>';
        echo esc_html__('Useful for manual adjustments. Refund records are included by their refund date independently.', 'ffl-funnels-addons');
        echo '</small></span></label>';
        echo '</div>';

        echo '<label class="ffla-tax-report-pii"><input type="checkbox" name="include_pii" value="1" ' . checked(!empty($filters['include_pii']), true, false) . '> <span><strong>';
        echo esc_html__('Include optional order audit with shipping addresses', 'ffl-funnels-addons') . '</strong><small>';
        echo esc_html__('Adds an Order Audit worksheet and CSV with order totals and the formatted shipping address. Leave this off when only the filing totals are needed.', 'ffl-funnels-addons');
        echo '</small></span></label>';

        echo '<div class="ffla-tax-report-actions">';
        echo '<button type="submit" class="wb-btn wb-btn--subtle">' . esc_html__('Preview filing report', 'ffl-funnels-addons') . '</button>';
        echo '<button type="submit" class="wb-btn wb-btn--primary" formmethod="post" formaction="' . esc_url(admin_url('admin-post.php')) . '">' . esc_html__('Download filing report', 'ffl-funnels-addons') . '</button>';
        echo '</div>';
        echo '<p class="wb-field__desc">' . esc_html__('The download is generated on demand and is not retained on the server. Only a non-PII manifest and file checksums are kept in the generation history.', 'ffl-funnels-addons') . '</p>';
        echo '</div></form>';
    }

    private static function normalize_report_tab(string $tab): string
    {
        $tab = sanitize_key($tab);
        $allowed = ['overview', 'states', 'jurisdictions', 'orders', 'reconciliation', 'nexus', 'delivery', 'tools'];
        return in_array($tab, $allowed, true) ? $tab : 'overview';
    }

    private static function render_report_tabs(string $active_tab, bool $has_report): void
    {
        $tabs = [
            'overview'       => __('Overview', 'ffl-funnels-addons'),
            'states'         => __('States', 'ffl-funnels-addons'),
            'jurisdictions'  => __('Jurisdictions', 'ffl-funnels-addons'),
            'orders'         => __('Orders', 'ffl-funnels-addons'),
            'reconciliation' => __('Reconciliation', 'ffl-funnels-addons'),
            'nexus'          => __('Nexus Monitor', 'ffl-funnels-addons'),
            'delivery'       => __('Delivery & History', 'ffl-funnels-addons'),
            'tools'          => __('Tools', 'ffl-funnels-addons'),
        ];
        $base_args = ['page' => 'ffla-sales-tax-reports'];
        if ($has_report) {
            $base_args = array_merge($base_args, self::safe_preview_query_args());
        }

        echo '<nav class="ffla-tax-report-tabs" aria-label="' . esc_attr__('Sales tax report sections', 'ffl-funnels-addons') . '">';
        foreach ($tabs as $key => $label) {
            $args = array_merge($base_args, ['report_tab' => $key]);
            $class = $key === $active_tab ? ' is-active' : '';
            echo '<a class="ffla-tax-report-tab' . esc_attr($class) . '" href="' . esc_url(add_query_arg($args, admin_url('admin.php'))) . '" data-tax-report-tab="' . esc_attr($key) . '" aria-current="' . ($key === $active_tab ? 'page' : 'false') . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private static function safe_preview_query_args(): array
    {
        $input = is_array($_GET) ? wp_unslash($_GET) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $allowed = ['run_report', '_wpnonce', 'date_from', 'date_to', 'period_preset', 'report_detail', 'include_negative_orders', 'include_pii'];
        $args = [];
        foreach ($allowed as $key) {
            if (isset($input[$key]) && !is_array($input[$key])) {
                $args[$key] = sanitize_text_field((string) $input[$key]);
            }
        }
        foreach (['statuses', 'states'] as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $args[$key] = array_values(array_map('sanitize_text_field', $input[$key]));
            }
        }
        return $args;
    }

    private static function period_presets(): array
    {
        return [
            'custom'         => __('Custom', 'ffl-funnels-addons'),
            'previous_month' => __('Previous month', 'ffl-funnels-addons'),
            'previous_quarter' => __('Previous quarter', 'ffl-funnels-addons'),
            'year_to_date'   => __('Year to date', 'ffl-funnels-addons'),
            'previous_year'  => __('Previous year', 'ffl-funnels-addons'),
        ];
    }

    private static function apply_period_preset(array $input): array
    {
        $preset = sanitize_key((string) ($input['period_preset'] ?? 'custom'));
        if (!array_key_exists($preset, self::period_presets()) || $preset === 'custom') {
            return $input;
        }

        $now = current_datetime();
        if ($preset === 'previous_month') {
            $from = $now->modify('first day of previous month')->setTime(0, 0, 0);
            $to = $now->modify('last day of previous month')->setTime(23, 59, 59);
        } elseif ($preset === 'previous_quarter') {
            $current_quarter = (int) floor(((int) $now->format('n') - 1) / 3);
            $year = (int) $now->format('Y');
            if ($current_quarter === 0) {
                $year--;
                $start_month = 10;
            } else {
                $start_month = (($current_quarter - 1) * 3) + 1;
            }
            $from = $now->setDate($year, $start_month, 1)->setTime(0, 0, 0);
            $to = $from->modify('+3 months -1 day')->setTime(23, 59, 59);
        } elseif ($preset === 'previous_year') {
            $year = (int) $now->format('Y') - 1;
            $from = $now->setDate($year, 1, 1)->setTime(0, 0, 0);
            $to = $now->setDate($year, 12, 31)->setTime(23, 59, 59);
        } else {
            $from = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);
            $to = $now;
        }

        $input['date_from'] = $from->format('Y-m-d');
        $input['date_to'] = $to->format('Y-m-d');
        return $input;
    }

    private static function get_us_states(): array
    {
        if (function_exists('WC') && WC() && isset(WC()->countries)) {
            return (array) WC()->countries->get_states('US');
        }
        return [];
    }

    private static function render_email_notices(array $input): void
    {
        if (!empty($input['email_saved'])) {
            FFLA_Admin::render_notice('success', esc_html__('Monthly report email settings saved.', 'ffl-funnels-addons'));
        }
        if (!empty($input['email_queued'])) {
            $mode = sanitize_key((string) $input['email_queued']);
            $message = $mode === 'test'
                ? __('A test report was queued for the configured recipients.', 'ffl-funnels-addons')
                : __('The previous month report was queued for immediate delivery.', 'ffl-funnels-addons');
            FFLA_Admin::render_notice('success', esc_html($message));
        }
        if (!empty($input['email_error'])) {
            FFLA_Admin::render_notice('error', esc_html__('The report email action could not be queued or saved. Review the email history and site logs.', 'ffl-funnels-addons'));
        }
    }

    private static function render_email_schedule(): void
    {
        $settings = Tax_Report_Email::get_settings();
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];
        $next = Tax_Report_Email::get_next_run();
        $queue_name = function_exists('as_schedule_single_action')
            ? __('WooCommerce Action Scheduler', 'ffl-funnels-addons')
            : __('WordPress Cron fallback', 'ffl-funnels-addons');

        echo '<section class="wb-card ffla-tax-report-email">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Monthly email delivery', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__('Automatically generates the previous calendar month filing report and sends it to your accountant or bookkeeping team.', 'ffl-funnels-addons') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ffla_tax_report_email_save">';
        wp_nonce_field('ffla_tax_report_email_settings');

        echo '<label class="ffla-tax-report-pii"><input type="checkbox" name="enabled" value="1" ' . checked($settings['enabled'], '1', false) . '> <span><strong>';
        echo esc_html__('Enable monthly delivery', 'ffl-funnels-addons') . '</strong><small>';
        echo esc_html__('The report always covers the full previous calendar month in the WordPress site timezone.', 'ffl-funnels-addons');
        echo '</small></span></label>';

        echo '<div class="ffla-tax-report-email-grid">';
        echo '<label><span>' . esc_html__('Recipients', 'ffl-funnels-addons') . '</span><textarea name="recipients" rows="3">' . esc_textarea(implode("\n", (array) $settings['recipients'])) . '</textarea><small>' . esc_html__('Required only when monthly delivery is enabled. One email per line, or separate addresses with commas.', 'ffl-funnels-addons') . '</small></label>';
        echo '<label><span>' . esc_html__('Day of month', 'ffl-funnels-addons') . '</span><input type="number" name="send_day" min="1" max="28" required value="' . esc_attr((string) $settings['send_day']) . '"><small>' . esc_html__('Days 1–28 avoid invalid dates in shorter months.', 'ffl-funnels-addons') . '</small></label>';
        echo '<label><span>' . esc_html__('Send time', 'ffl-funnels-addons') . '</span><input type="time" name="send_time" required value="' . esc_attr((string) $settings['send_time']) . '"><small>' . esc_html(wp_timezone_string()) . '</small></label>';
        echo '<label><span>' . esc_html__('Maximum attachment size', 'ffl-funnels-addons') . '</span><input type="number" name="max_attachment_mb" min="1" max="50" required value="' . esc_attr((string) $settings['max_attachment_mb']) . '"><small>' . esc_html__('MB. Larger reports fall back to the PDF filing summary.', 'ffl-funnels-addons') . '</small></label>';
        echo '<label><span>' . esc_html__('States', 'ffl-funnels-addons') . '</span><input type="text" name="states" value="' . esc_attr(implode(', ', (array) ($settings['states'] ?? []))) . '" placeholder="' . esc_attr__('All states', 'ffl-funnels-addons') . '"><small>' . esc_html__('Optional comma-separated state codes, for example GA, FL, TX.', 'ffl-funnels-addons') . '</small></label>';
        echo '<label><span>' . esc_html__('Report detail', 'ffl-funnels-addons') . '</span><select name="report_detail">';
        echo '<option value="filing" ' . selected(($settings['report_detail'] ?? 'filing'), 'filing', false) . '>' . esc_html__('Filing summary', 'ffl-funnels-addons') . '</option>';
        echo '<option value="advanced" ' . selected(($settings['report_detail'] ?? 'filing'), 'advanced', false) . '>' . esc_html__('Advanced audit package', 'ffl-funnels-addons') . '</option>';
        echo '</select></label>';
        echo '</div>';

        echo '<fieldset class="ffla-tax-report-statuses"><legend>' . esc_html__('Included order statuses', 'ffl-funnels-addons') . '</legend>';
        foreach ($statuses as $status_key => $label) {
            $status = preg_replace('/^wc-/', '', (string) $status_key);
            echo '<label><input type="checkbox" name="statuses[]" value="' . esc_attr($status) . '" ' . checked(in_array($status, $settings['statuses'], true), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</fieldset>';

        echo '<label class="ffla-tax-report-pii"><input type="checkbox" name="include_negative_orders" value="1" ' . checked(($settings['include_negative_orders'] ?? '0'), '1', false) . '> <span><strong>';
        echo esc_html__('Include negative-total orders', 'ffl-funnels-addons') . '</strong><small>';
        echo esc_html__('Use when manual negative adjustments belong in the monthly package.', 'ffl-funnels-addons');
        echo '</small></span></label>';

        echo '<label class="ffla-tax-report-pii"><input type="checkbox" name="include_pii" value="1" ' . checked($settings['include_pii'], '1', false) . '> <span><strong>';
        echo esc_html__('Include the order audit with shipping addresses', 'ffl-funnels-addons') . '</strong><small>';
        echo esc_html__('Enable only when the recipient needs order-level support. The attachment will contain confidential customer information.', 'ffl-funnels-addons');
        echo '</small></span></label>';

        echo '<div class="ffla-tax-report-actions"><button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save email schedule', 'ffl-funnels-addons') . '</button></div>';
        echo '</form>';

        echo '<div class="ffla-tax-report-schedule-status">';
        echo '<strong>' . esc_html__('Queue:', 'ffl-funnels-addons') . '</strong> ' . esc_html($queue_name) . '<br>';
        echo '<strong>' . esc_html__('Next scheduled run:', 'ffl-funnels-addons') . '</strong> ';
        echo $next > 0
            ? esc_html(wp_date('Y-m-d H:i:s T', $next, wp_timezone()))
            : esc_html__('Not currently scheduled', 'ffl-funnels-addons');
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ffla-tax-report-actions">';
        echo '<input type="hidden" name="action" value="ffla_tax_report_email_send">';
        wp_nonce_field('ffla_tax_report_email_send');
        echo '<button type="submit" name="mode" value="test" class="wb-btn wb-btn--subtle">' . esc_html__('Send test report', 'ffl-funnels-addons') . '</button>';
        echo '<button type="submit" name="mode" value="manual" class="wb-btn wb-btn--subtle">' . esc_html__('Send previous month now', 'ffl-funnels-addons') . '</button>';
        echo '</form>';
        echo '<p class="wb-field__desc">' . esc_html__('Failed scheduled sends retry after 15 minutes, 1 hour and 6 hours. Temporary report files are deleted after each attempt. Configure a transactional SMTP provider and verify its delivery logs; a successful WordPress handoff does not prove inbox delivery.', 'ffl-funnels-addons') . '</p>';

        self::render_email_history();
        echo '</div></section>';
    }

    private static function render_email_history(): void
    {
        $history = Tax_Report_Email::get_history(10);
        echo '<h4>' . esc_html__('Recent email history', 'ffl-funnels-addons') . '</h4>';
        if (empty($history)) {
            echo '<p class="wb-field__desc">' . esc_html__('No monthly report emails have run yet.', 'ffl-funnels-addons') . '</p>';
            return;
        }

        echo '<div class="ffla-tax-report-table-wrap"><table class="widefat striped"><thead><tr>';
        foreach ([__('UTC time', 'ffl-funnels-addons'), __('Status', 'ffl-funnels-addons'), __('Mode', 'ffl-funnels-addons'), __('Period', 'ffl-funnels-addons'), __('Recipients', 'ffl-funnels-addons'), __('Attachment', 'ffl-funnels-addons'), __('Details', 'ffl-funnels-addons')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($history as $entry) {
            $period = trim((string) ($entry['date_from'] ?? '') . ' — ' . (string) ($entry['date_to'] ?? ''), ' —');
            echo '<tr><td>' . esc_html((string) ($entry['created_at_utc'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string) ($entry['status'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($entry['mode'] ?? '') . ' #' . (string) ($entry['attempt'] ?? 0)) . '</td>';
            echo '<td>' . esc_html($period) . '</td>';
            echo '<td>' . esc_html((string) count((array) ($entry['recipients'] ?? []))) . '</td>';
            echo '<td>' . esc_html((string) ($entry['attachment_name'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($entry['message'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function render_preview(array $report, string $active_tab): void
    {
        $stats = (array) ($report['stats'] ?? []);
        $states = (array) ($report['summaries']['states'] ?? []);
        $jurisdictions = (array) ($report['summaries']['jurisdictions'] ?? []);
        $states_to_review = count(array_filter($states, function ($state) {
            return ($state['filing_status'] ?? '') === __('Needs review', 'ffl-funnels-addons');
        }));
        $review_items = array_values(array_filter((array) ($report['summaries']['exceptions'] ?? []), function ($item) {
            return in_array($item['severity'] ?? '', ['warning', 'error'], true);
        }));

        echo '<section class="ffla-tax-report-preview" data-ffla-report-panel="' . esc_attr($active_tab) . '">';

        if ($active_tab === 'overview') {
            echo '<div class="ffla-tax-report-kpis">';
            self::render_kpi(__('Orders', 'ffl-funnels-addons'), (string) ($stats['orders'] ?? 0));
            self::render_kpi(__('States', 'ffl-funnels-addons'), (string) count($states));
            self::render_kpi(__('Tax jurisdictions', 'ffl-funnels-addons'), (string) count($jurisdictions));
            self::render_kpi(__('States to review', 'ffl-funnels-addons'), (string) $states_to_review);
            echo '</div>';
            self::render_dataset_card(__('Filing totals', 'ffl-funnels-addons'), 'filing-totals', (array) ($report['summaries']['filing_totals'] ?? []));
            if (!empty($review_items)) {
                self::render_dataset_card(__('Items to review', 'ffl-funnels-addons'), ['severity', 'code', 'count', 'message'], $review_items);
            }
            echo '<p class="ffla-tax-report-callout">' . esc_html__('Total taxable sales is the single filing base and already includes every product, fee, and shipping line that WooCommerce taxed. Calculated tax uses the effective rate stored with each order; the filing portal remains the final authority.', 'ffl-funnels-addons') . '</p>';
        } elseif ($active_tab === 'states') {
            self::render_dataset_card(__('State filing summary', 'ffl-funnels-addons'), 'state-summary', $states);
        } elseif ($active_tab === 'jurisdictions') {
            self::render_dataset_card(__('Jurisdictions with activity', 'ffl-funnels-addons'), 'jurisdiction-summary', $jurisdictions);
        } elseif ($active_tab === 'orders') {
            self::render_orders_panel($report);
        } elseif ($active_tab === 'reconciliation') {
            self::render_reconciliation_panel($report);
        } elseif ($active_tab === 'nexus') {
            self::render_nexus_panel($report);
        }

        echo '</section>';
    }

    /**
     * @param string|array $dataset Dataset key or explicit columns.
     */
    private static function render_dataset_card(string $title, $dataset, array $rows, string $description = ''): void
    {
        $columns = is_array($dataset) ? $dataset : Tax_Report_Service::get_columns((string) $dataset);
        echo '<div class="wb-card ffla-tax-report-dataset">';
        echo '<div class="wb-card__header"><h3>' . esc_html($title) . '</h3></div>';
        echo '<div class="wb-card__body">';
        if ($description !== '') {
            echo '<p class="wb-field__desc">' . esc_html($description) . '</p>';
        }
        echo '<div class="ffla-tax-report-table-wrap" tabindex="0" role="region" aria-label="' . esc_attr($title) . '">';
        self::render_table($columns, $rows, $title);
        echo '</div></div></div>';
    }

    private static function render_orders_panel(array $report): void
    {
        $orders = (array) ($report['orders'] ?? []);
        $filters = (array) ($report['manifest']['filters'] ?? []);
        if (empty($orders)) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Run the report in Advanced audit package mode or enable the shipping-address audit option to display order-level rows.', 'ffl-funnels-addons') . '</p></div>';
            return;
        }

        if (empty($filters['include_pii'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Customer names and the full shipping address are masked. Enable the shipping-address audit option when your accountant needs them.', 'ffl-funnels-addons') . '</p></div>';
        }
        self::render_dataset_card(__('Order audit and shipping destination', 'ffl-funnels-addons'), 'order-audit', $orders);

        if (($filters['report_detail'] ?? 'filing') !== 'advanced') {
            return;
        }
        echo '<details class="ffla-tax-report-advanced"><summary>' . esc_html__('Advanced audit datasets', 'ffl-funnels-addons') . '</summary>';
        self::render_dataset_card(__('Order line items', 'ffl-funnels-addons'), 'order_lines', (array) ($report['order_lines'] ?? []));
        self::render_dataset_card(__('Tax lines and shipping tax', 'ffl-funnels-addons'), 'tax_lines', (array) ($report['tax_lines'] ?? []));
        self::render_dataset_card(__('Refunds', 'ffl-funnels-addons'), 'refunds', (array) ($report['refunds'] ?? []));
        self::render_dataset_card(__('Product summary', 'ffl-funnels-addons'), 'product-summary', (array) ($report['summaries']['products'] ?? []));
        self::render_dataset_card(__('Payment summary', 'ffl-funnels-addons'), 'payment-summary', (array) ($report['summaries']['payments'] ?? []));
        self::render_dataset_card(__('Exceptions', 'ffl-funnels-addons'), 'exceptions', (array) ($report['exceptions'] ?? []));
        echo '</details>';
    }

    private static function render_kpi(string $label, string $value): void
    {
        echo '<div class="ffla-tax-report-kpi" data-copy-value="' . esc_attr($value) . '"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
    }

    private static function render_table(array $columns, array $rows, string $caption = ''): void
    {
        $labels = [
            'gross_sales' => __('Gross sales (net of refunds)', 'ffl-funnels-addons'),
            'taxable_sales' => __('Total taxable sales (including shipping)', 'ffl-funnels-addons'),
            'non_taxable_sales' => __('Exempt / non-taxable sales', 'ffl-funnels-addons'),
            'needs_review_sales' => __('Sales needing review', 'ffl-funnels-addons'),
            'net_tax' => __('Net tax collected', 'ffl-funnels-addons'),
            'calculated_tax' => __('Tax calculated / owed', 'ffl-funnels-addons'),
            'over_under' => __('Over / under collected', 'ffl-funnels-addons'),
            'jurisdictions' => __('Jurisdictions with activity', 'ffl-funnels-addons'),
            'conditional_tax_exempt_items' => __('Exempt product lines', 'ffl-funnels-addons'),
            'conditional_tax_exempt_sales' => __('Conditionally exempt sales', 'ffl-funnels-addons'),
            'tax_exemption_rules' => __('Exemption rules', 'ffl-funnels-addons'),
            'tax_exempt' => __('Conditional tax exempt', 'ffl-funnels-addons'),
            'tax_exemption_type' => __('Exemption type', 'ffl-funnels-addons'),
            'tax_holiday_exempt_items' => __('Tax-holiday product lines', 'ffl-funnels-addons'),
            'tax_holiday_exempt_sales' => __('Tax-holiday exempt sales', 'ffl-funnels-addons'),
            'tax_holiday_exempt_shipping' => __('Tax-holiday exempt shipping', 'ffl-funnels-addons'),
            'tax_holiday_exempt_amount' => __('Tax-holiday exempt amount', 'ffl-funnels-addons'),
            'tax_holiday_rules' => __('Tax holiday rules', 'ffl-funnels-addons'),
        ];
        echo '<table class="widefat striped ffla-tax-report-table">';
        if ($caption !== '') {
            echo '<caption class="screen-reader-text">' . esc_html($caption) . '</caption>';
        }
        echo '<thead><tr>';
        foreach ($columns as $column) {
            $label = $labels[$column] ?? ucwords(str_replace('_', ' ', $column));
            echo '<th scope="col" data-ffla-sort-column="' . esc_attr($column) . '">' . esc_html($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="' . esc_attr((string) max(1, count($columns))) . '">' . esc_html__('No records for this selection.', 'ffl-funnels-addons') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($columns as $column) {
                    echo '<td data-column="' . esc_attr($column) . '">' . esc_html((string) ($row[$column] ?? '')) . '</td>';
                }
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }

    private static function render_reconciliation_panel(array $report): void
    {
        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('WooCommerce Analytics reconciliation', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__('The reconciliation uses the same date, status, state, and negative-order filters as this report.', 'ffl-funnels-addons') . '</p>';
        if (!class_exists('Tax_Report_Reconciliation')) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('The reconciliation engine is unavailable.', 'ffl-funnels-addons') . '</p></div>';
        } else {
            self::render_reconciliation_result($report);
        }
        echo '</div></div>';
    }

    private static function render_reconciliation_result(array $report): void
    {
        $service = new Tax_Report_Reconciliation();
        $result = $service->reconcile($report);
        $status = (string) ($result['status'] ?? 'warn');
        $source = (array) ($result['sources'] ?? []);

        echo '<div class="ffla-tax-report-health-grid">';
        echo '<div class="ffla-tax-report-health-card" data-tax-health="' . esc_attr($status) . '"><span>' . esc_html__('Overall status', 'ffl-funnels-addons') . '</span><strong class="ffla-tax-report-status">' . esc_html($status === 'pass' ? __('Reconciled', 'ffl-funnels-addons') : __('Review needed', 'ffl-funnels-addons')) . '</strong></div>';
        echo '<div class="ffla-tax-report-health-card" data-tax-health="info"><span>' . esc_html__('WooCommerce source', 'ffl-funnels-addons') . '</span><strong>' . esc_html((string) ($source['woocommerce'] ?? __('Unavailable', 'ffl-funnels-addons'))) . '</strong></div>';
        echo '<div class="ffla-tax-report-health-card" data-tax-health="info"><span>' . esc_html__('Tolerance', 'ffl-funnels-addons') . '</span><strong>' . esc_html((string) ($result['meta']['tolerance_minor'] ?? 1) . ' ' . __('minor unit', 'ffl-funnels-addons')) . '</strong></div>';
        echo '</div>';

        $rows = [];
        foreach ((array) ($result['checks'] ?? []) as $check) {
            $rows[] = [
                'check'       => (string) ($check['label'] ?? ''),
                'status'      => (string) ($check['status'] ?? 'warn'),
                'ffla'        => $check['ffla'] ?? '',
                'woocommerce' => $check['woocommerce'] ?? '',
                'difference'  => $check['difference'] ?? '',
                'message'     => (string) ($check['message'] ?? ''),
            ];
        }
        self::render_dataset_card(
            __('Accuracy and completeness checks', 'ffl-funnels-addons'),
            ['check', 'status', 'ffla', 'woocommerce', 'difference', 'message'],
            $rows
        );

        self::render_message_list(__('Warnings', 'ffl-funnels-addons'), (array) ($result['warnings'] ?? []), 'warning');
        self::render_message_list(__('Recommended review steps', 'ffl-funnels-addons'), (array) ($result['recommendations'] ?? []), 'info');
    }

    private static function render_message_list(string $title, array $messages, string $tone): void
    {
        if (empty($messages)) {
            return;
        }
        echo '<div class="notice notice-' . esc_attr($tone) . ' inline"><p><strong>' . esc_html($title) . '</strong></p><ul>';
        foreach ($messages as $message) {
            echo '<li>' . esc_html((string) $message) . '</li>';
        }
        echo '</ul></div>';
    }

    private static function render_nexus_panel(array $report): void
    {
        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Economic nexus monitor', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body">';
        echo '<p class="ffla-tax-report-callout">' . esc_html__('Monitoring is an operational warning system, not a legal nexus determination. Confirm thresholds, exclusions, effective dates, and marketplace rules with a qualified sales-tax professional.', 'ffl-funnels-addons') . '</p>';
        if (!class_exists('Tax_Nexus_Monitor')) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('The nexus monitor is unavailable.', 'ffl-funnels-addons') . '</p></div>';
        } else {
            self::render_nexus_result($report);
        }
        echo '</div></div>';
    }

    private static function render_nexus_result(array $report): void
    {
        try {
            $monitor = new Tax_Nexus_Monitor();
            $filters = (array) ($report['manifest']['filters'] ?? []);
            $result = $monitor->generate($filters, ['forecast' => true]);
        } catch (Throwable $error) {
            echo '<div class="notice notice-error inline"><p>' . esc_html($error->getMessage()) . '</p></div>';
            return;
        }

        $summary = (array) ($result['summary'] ?? []);
        $dataset = (array) ($result['dataset'] ?? []);
        echo '<div class="ffla-tax-report-kpis">';
        self::render_kpi(__('States with sales', 'ffl-funnels-addons'), (string) ($summary['states_with_transactions'] ?? 0));
        self::render_kpi(__('Threshold exceeded', 'ffl-funnels-addons'), (string) ($summary['states_actual_threshold_exceeded'] ?? 0));
        self::render_kpi(__('Approaching threshold', 'ffl-funnels-addons'), (string) ($summary['states_actual_approaching'] ?? 0));
        self::render_kpi(__('Currency review', 'ffl-funnels-addons'), (string) ($summary['states_requiring_currency_conversion'] ?? 0));
        echo '</div>';

        $active_states = array_values(array_filter((array) ($result['states'] ?? []), function ($state) {
            return (int) ($state['actual_transactions'] ?? 0) > 0 || !empty($state['physical_home_state']);
        }));
        if (!empty($active_states)) {
            echo '<div class="ffla-tax-report-health-grid">';
            foreach ($active_states as $state) {
                $evaluation = (array) ($state['actual_evaluation'] ?? []);
                $progress = isset($evaluation['combined_progress_percent']) && $evaluation['combined_progress_percent'] !== null
                    ? max(0, (float) $evaluation['combined_progress_percent'])
                    : 0;
                echo '<div class="ffla-tax-report-health-card" data-tax-health="' . esc_attr((string) ($evaluation['status'] ?? 'info')) . '">';
                echo '<h4>' . esc_html((string) ($state['state'] ?? '')) . ' — ' . esc_html((string) ($state['state_name'] ?? '')) . '</h4>';
                echo '<p><strong>' . esc_html((string) ($state['actual_revenue'] ?? '0')) . '</strong> · ' . esc_html((string) ($state['actual_transactions'] ?? 0)) . ' ' . esc_html__('transactions', 'ffl-funnels-addons') . '</p>';
                echo '<progress class="ffla-tax-report-nexus-progress" data-nexus-progress="' . esc_attr((string) $progress) . '" max="100" value="' . esc_attr((string) min(100, $progress)) . '">' . esc_html((string) $progress . '%') . '</progress>';
                echo '<p class="ffla-tax-report-status">' . esc_html((string) ($evaluation['status'] ?? '')) . '</p>';
                echo '</div>';
            }
            echo '</div>';
        }

        $rows = [];
        foreach ((array) ($result['states'] ?? []) as $state) {
            $threshold = (array) ($state['threshold'] ?? []);
            $evaluation = (array) ($state['actual_evaluation'] ?? []);
            $forecast = is_array($state['forecast_evaluation'] ?? null) ? $state['forecast_evaluation'] : [];
            $revenue_threshold = $threshold['revenue_threshold'] ?? '';
            $transaction_threshold = $threshold['transaction_threshold'] ?? '';
            $rows[] = [
                'state'                => (string) ($state['state'] ?? ''),
                'home_state'           => !empty($state['physical_home_state']) ? __('Yes — review physical presence', 'ffl-funnels-addons') : __('No', 'ffl-funnels-addons'),
                'actual_revenue'       => (string) ($state['actual_revenue'] ?? ''),
                'actual_transactions'  => (string) ($state['actual_transactions'] ?? 0),
                'revenue_threshold'    => $revenue_threshold === null ? '' : (string) $revenue_threshold,
                'transaction_threshold'=> $transaction_threshold === null ? '' : (string) $transaction_threshold,
                'rule'                 => (string) ($threshold['evaluation_rule'] ?? ''),
                'progress_percent'     => isset($evaluation['combined_progress_percent']) && $evaluation['combined_progress_percent'] !== null
                    ? (string) $evaluation['combined_progress_percent'] . '%'
                    : '',
                'status'               => (string) ($evaluation['status'] ?? ''),
                'forecast_status'      => (string) ($forecast['status'] ?? ''),
                'advisory'             => (string) ($state['advisory_status'] ?? ''),
            ];
        }
        self::render_dataset_card(
            __('State threshold progress', 'ffl-funnels-addons'),
            ['state', 'home_state', 'actual_revenue', 'actual_transactions', 'revenue_threshold', 'transaction_threshold', 'rule', 'progress_percent', 'status', 'forecast_status', 'advisory'],
            $rows,
            __('Revenue excludes collected tax and remains net of recorded refunds; taxed and untaxed shipping and fees remain included in the monitor basis.', 'ffl-funnels-addons')
        );

        echo '<p class="wb-field__desc"><strong>' . esc_html__('Threshold dataset:', 'ffl-funnels-addons') . '</strong> '
            . esc_html((string) ($dataset['dataset_version'] ?? '')) . ' — '
            . esc_html((string) ($dataset['dataset_status'] ?? '')) . '. '
            . esc_html__('The bundled values are an unverified functional seed and have no confirmed effective date; replace or verify them before making compliance decisions.', 'ffl-funnels-addons') . '</p>';
    }

    private static function render_tools(): void
    {
        echo '<section class="wb-card ffla-tax-report-tools"><div class="wb-card__header"><h3>' . esc_html__('Report tools', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body">';
        echo '<p>' . esc_html__('Combine report packages from several WooCommerce sites or map a jurisdiction report into an accountant-provided CSV template.', 'ffl-funnels-addons') . '</p>';
        if (!class_exists('Tax_Report_Combiner')) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('The report tools engine is unavailable.', 'ffl-funnels-addons') . '</p></div>';
            echo '</div></section>';
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!empty($_GET['tool_error'])) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('The report tool could not produce a file. Review the diagnostics below, correct the upload or mapping, and try again.', 'ffl-funnels-addons') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data" class="ffla-tax-report-tool-form">';
        echo '<input type="hidden" name="action" value="ffla_tax_report_combine">';
        wp_nonce_field('ffla_tax_report_combine');

        echo '<div class="ffla-tax-report-combiner-grid">';
        echo '<label class="ffla-tax-report-dropzone" data-tax-report-dropzone><strong class="ffla-tax-report-dropzone__title">' . esc_html__('FFLA report packages or jurisdiction CSV files', 'ffl-funnels-addons') . '</strong>';
        echo '<span class="ffla-tax-report-dropzone__help">' . esc_html__('Choose up to 10 .zip or .csv files. Duplicate report IDs are ignored.', 'ffl-funnels-addons') . '</span>';
        echo '<input type="file" name="report_files[]" accept=".zip,.csv,application/zip,text/csv" multiple required>';
        echo '<span class="ffla-tax-report-file-status" data-file-status></span></label>';
        echo '<label class="ffla-tax-report-dropzone" data-tax-report-dropzone><strong class="ffla-tax-report-dropzone__title">' . esc_html__('Optional state filing template', 'ffl-funnels-addons') . '</strong>';
        echo '<span class="ffla-tax-report-dropzone__help">' . esc_html__('Add one CSV template to map the combined totals into its existing rows and columns.', 'ffl-funnels-addons') . '</span>';
        echo '<input type="file" name="template_file" accept=".csv,text/csv">';
        echo '<span class="ffla-tax-report-file-status" data-file-status></span></label>';
        echo '</div>';

        echo '<details class="ffla-tax-report-advanced"><summary>' . esc_html__('Optional template column mapping', 'ffl-funnels-addons') . '</summary>';
        echo '<p class="wb-field__desc">' . esc_html__('Leave blank to auto-detect common headers. Enter the exact header text from the uploaded template when its labels are custom.', 'ffl-funnels-addons') . '</p>';
        echo '<div class="ffla-tax-report-combiner-grid ffla-tax-report-mapping-grid">';
        $key_fields = [
            'jurisdiction_code' => __('Jurisdiction code key', 'ffl-funnels-addons'),
            'county'            => __('County key', 'ffl-funnels-addons'),
            'city'              => __('City key', 'ffl-funnels-addons'),
            'jurisdiction_name' => __('Jurisdiction name key', 'ffl-funnels-addons'),
            'state'             => __('State key', 'ffl-funnels-addons'),
            'currency'          => __('Currency key', 'ffl-funnels-addons'),
        ];
        foreach ($key_fields as $field => $label) {
            echo '<label><span>' . esc_html($label) . '</span><input type="text" name="map_keys[' . esc_attr($field) . ']" maxlength="128"></label>';
        }
        $output_fields = [
            'orders'             => __('Orders output', 'ffl-funnels-addons'),
            'taxable_sales'      => __('Total taxable sales output (including shipping)', 'ffl-funnels-addons'),
            'net_tax'            => __('Net tax output', 'ffl-funnels-addons'),
            'calculated_tax'     => __('Calculated tax output', 'ffl-funnels-addons'),
            'over_under'         => __('Over / under output', 'ffl-funnels-addons'),
        ];
        foreach ($output_fields as $field => $label) {
            echo '<label><span>' . esc_html($label) . '</span><input type="text" name="map_outputs[' . esc_attr($field) . ']" maxlength="128"></label>';
        }
        echo '</div></details>';

        echo '<div class="ffla-tax-report-actions"><button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Combine and download CSV', 'ffl-funnels-addons') . '</button></div>';
        echo '<p class="wb-field__desc">' . esc_html__('Uploads are validated and processed only for this request; they are not copied into the Media Library. Formula-like spreadsheet cells are neutralized.', 'ffl-funnels-addons') . '</p>';
        echo '</form>';

        self::render_tool_diagnostics();
        echo '</div></section>';
    }

    private static function render_tool_diagnostics(): void
    {
        $entry = get_transient('ffla_tax_report_tool_diag_' . get_current_user_id());
        if (!is_array($entry)) {
            return;
        }
        delete_transient('ffla_tax_report_tool_diag_' . get_current_user_id());
        $diagnostics = (array) ($entry['diagnostics'] ?? []);
        echo '<div class="ffla-tax-report-tool-diagnostics"><h4>' . esc_html__('Most recent tool diagnostics', 'ffl-funnels-addons') . '</h4>';
        echo '<p class="wb-field__desc">' . esc_html((string) ($entry['created_at_utc'] ?? '')) . ' UTC — ' . esc_html((string) ($entry['mode'] ?? '')) . '</p>';

        $summary_keys = ['files_received', 'files_processed', 'packages_processed', 'rows_read', 'rows_combined', 'unique_report_ids'];
        echo '<div class="ffla-tax-report-kpis">';
        foreach ($summary_keys as $key) {
            if (isset($diagnostics[$key])) {
                self::render_kpi(ucwords(str_replace('_', ' ', $key)), (string) $diagnostics[$key]);
            }
        }
        echo '</div>';
        self::render_diagnostic_issues(__('Errors', 'ffl-funnels-addons'), (array) ($diagnostics['errors'] ?? []), 'error');
        self::render_diagnostic_issues(__('Warnings', 'ffl-funnels-addons'), (array) ($diagnostics['warnings'] ?? []), 'warning');
        if (isset($diagnostics['mapping']) && is_array($diagnostics['mapping'])) {
            $mapping = $diagnostics['mapping'];
            echo '<p><strong>' . esc_html__('Template matches:', 'ffl-funnels-addons') . '</strong> ' . esc_html((string) ($mapping['matched'] ?? 0))
                . ' · <strong>' . esc_html__('Unmatched:', 'ffl-funnels-addons') . '</strong> ' . esc_html((string) count((array) ($mapping['unmatched'] ?? [])))
                . ' · <strong>' . esc_html__('Ambiguous:', 'ffl-funnels-addons') . '</strong> ' . esc_html((string) count((array) ($mapping['ambiguous'] ?? []))) . '</p>';
            self::render_diagnostic_issues(__('Mapping errors', 'ffl-funnels-addons'), (array) ($mapping['errors'] ?? []), 'error');
            self::render_diagnostic_issues(__('Mapping warnings', 'ffl-funnels-addons'), (array) ($mapping['warnings'] ?? []), 'warning');
        }
        echo '</div>';
    }

    private static function render_diagnostic_issues(string $title, array $issues, string $tone): void
    {
        if (empty($issues)) {
            return;
        }
        echo '<div class="notice notice-' . esc_attr($tone) . ' inline"><p><strong>' . esc_html($title) . '</strong></p><ul>';
        foreach (array_slice($issues, 0, 50) as $issue) {
            $issue = is_array($issue) ? $issue : ['message' => (string) $issue];
            $label = trim((string) ($issue['code'] ?? '') . ': ' . (string) ($issue['message'] ?? ''), ': ');
            if (!empty($issue['source'])) {
                $label .= ' (' . (string) $issue['source'] . ')';
            }
            echo '<li>' . esc_html($label) . '</li>';
        }
        echo '</ul></div>';
    }

    private static function render_history(): void
    {
        $runs = Tax_Report_Service::get_recent_runs(10);
        echo '<div class="wb-card ffla-tax-report-history"><div class="wb-card__header"><h3>' . esc_html__('Recent generation history', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body">';
        if (empty($runs)) {
            echo '<p class="wb-field__desc">' . esc_html__('No report packages have been generated yet.', 'ffl-funnels-addons') . '</p>';
            echo '</div></div>';
            return;
        }

        echo '<div class="ffla-tax-report-table-wrap"><table class="widefat striped"><thead><tr>';
        foreach ([__('Generated UTC', 'ffl-funnels-addons'), __('Period', 'ffl-funnels-addons'), __('Orders', 'ffl-funnels-addons'), __('Currencies', 'ffl-funnels-addons'), __('Report ID', 'ffl-funnels-addons')] as $heading) {
            echo '<th scope="col">' . esc_html($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($runs as $run) {
            $filters = (array) ($run['filters'] ?? []);
            $stats = (array) ($run['stats'] ?? []);
            echo '<tr><td>' . esc_html((string) ($run['generated_at_utc'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($filters['date_from'] ?? '') . ' - ' . (string) ($filters['date_to'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($stats['orders'] ?? 0)) . '</td>';
            echo '<td>' . esc_html(implode(', ', (array) ($run['currencies'] ?? []))) . '</td>';
            echo '<td><code>' . esc_html((string) ($run['report_id'] ?? '')) . '</code></td></tr>';
        }
        echo '</tbody></table></div></div></div>';
    }
}
