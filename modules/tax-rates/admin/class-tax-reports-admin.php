<?php
/**
 * Accountant workpaper UI for WooCommerce tax reports.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Reports_Admin
{
    public static function init(): void
    {
        add_action('admin_post_ffla_tax_report_export', [__CLASS__, 'export']);
        add_action('admin_post_ffla_tax_report_email_save', [__CLASS__, 'save_email_settings']);
        add_action('admin_post_ffla_tax_report_email_send', [__CLASS__, 'queue_email']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key((string) wp_unslash($_GET['tab'])) : '';
        if ($page !== 'ffla-tax-rates' || $tab !== 'reports') {
            return;
        }

        wp_enqueue_style('ffla-tax-reports-admin', FFLA_URL . 'modules/tax-rates/admin/css/tax-reports-admin.css', [], FFLA_VERSION);
    }

    public static function export(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        check_admin_referer('ffla_tax_report_export');

        try {
            $input = is_array($_POST) ? wp_unslash($_POST) : [];
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

    private static function redirect_to_reports(array $args): void
    {
        $url = add_query_arg(
            array_merge([
                'page' => 'ffla-tax-rates',
                'tab'  => 'reports',
            ], $args),
            admin_url('admin.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            echo '<p>' . esc_html__('You do not have permission to view tax reports.', 'ffl-funnels-addons') . '</p>';
            return;
        }

        $input = is_array($_GET) ? wp_unslash($_GET) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $has_preview = !empty($input['run_report']);
        $filters = Tax_Report_Service::default_filters();
        $report = null;
        $error = '';

        try {
            if ($has_preview) {
                if (!isset($input['_wpnonce']) || !wp_verify_nonce((string) $input['_wpnonce'], 'ffla_tax_report_export')) {
                    throw new RuntimeException(__('The report preview link expired. Submit the filters again.', 'ffl-funnels-addons'));
                }
                $filters = Tax_Report_Service::normalize_filters($input);
                $service = new Tax_Report_Service();
                $report = $service->generate($filters, [
                    'summary_only'    => true,
                    'exception_limit' => 100,
                ]);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        echo '<div class="ffla-tax-report-intro">';
        echo '<h2>' . esc_html__('WooCommerce Tax Reports', 'ffl-funnels-addons') . '</h2>';
        echo '<p>' . esc_html__('Generate an accountant-ready workpaper from the final values stored in WooCommerce. The package includes order, line, tax and refund detail; state, jurisdiction, product and payment summaries; exception checks; file checksums; XLSX; CSV; PDF; HTML; and JSON.', 'ffl-funnels-addons') . '</p>';
        echo '<p class="ffla-tax-report-callout"><strong>' . esc_html__('Scope:', 'ffl-funnels-addons') . '</strong> ';
        echo esc_html__('This is supporting evidence for your accountant. It does not file returns, decide nexus, or replace accounting and legal review.', 'ffl-funnels-addons') . '</p>';
        echo '</div>';

        self::render_email_notices($input);

        if ($error !== '') {
            FFLA_Admin::render_notice('error', esc_html($error));
        }

        self::render_filter_form($filters);
        self::render_email_schedule();

        if (is_array($report)) {
            self::render_preview($report);
        }

        self::render_history();
    }

    private static function render_filter_form(array $filters): void
    {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="wb-card ffla-tax-report-filters">';
        echo '<input type="hidden" name="page" value="ffla-tax-rates">';
        echo '<input type="hidden" name="tab" value="reports">';
        echo '<input type="hidden" name="run_report" value="1">';
        echo '<input type="hidden" name="action" value="ffla_tax_report_export">';
        wp_nonce_field('ffla_tax_report_export');
        echo '<div class="wb-card__header"><h3>' . esc_html__('Report scope', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<div class="ffla-tax-report-filter-grid">';
        echo '<label><span>' . esc_html__('Start date', 'ffl-funnels-addons') . '</span><input type="date" name="date_from" required value="' . esc_attr((string) $filters['date_from']) . '"></label>';
        echo '<label><span>' . esc_html__('End date', 'ffl-funnels-addons') . '</span><input type="date" name="date_to" required value="' . esc_attr((string) $filters['date_to']) . '"></label>';
        echo '</div>';

        echo '<fieldset class="ffla-tax-report-statuses"><legend>' . esc_html__('Included order statuses', 'ffl-funnels-addons') . '</legend>';
        foreach ($statuses as $status_key => $label) {
            $status = preg_replace('/^wc-/', '', (string) $status_key);
            echo '<label><input type="checkbox" name="statuses[]" value="' . esc_attr($status) . '" ' . checked(in_array($status, $filters['statuses'], true), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</fieldset>';

        echo '<label class="ffla-tax-report-pii"><input type="checkbox" name="include_pii" value="1" ' . checked(!empty($filters['include_pii']), true, false) . '> <span><strong>';
        echo esc_html__('Include customer-identifying details in the package', 'ffl-funnels-addons') . '</strong><small>';
        echo esc_html__('Adds names, email, phone, billing and shipping addresses, a formatted full shipping address, transaction IDs and customer notes to orders.csv/XLSX. State and postal-code fields are always included because they support tax review.', 'ffl-funnels-addons');
        echo '</small></span></label>';

        echo '<div class="ffla-tax-report-actions">';
        echo '<button type="submit" class="wb-btn wb-btn--secondary">' . esc_html__('Preview summary', 'ffl-funnels-addons') . '</button>';
        echo '<button type="submit" class="wb-btn wb-btn--primary" formmethod="post" formaction="' . esc_url(admin_url('admin-post.php')) . '">' . esc_html__('Download complete package', 'ffl-funnels-addons') . '</button>';
        echo '</div>';
        echo '<p class="wb-field__desc">' . esc_html__('The download is generated on demand and is not retained on the server. Only a non-PII manifest and file checksums are kept in the generation history.', 'ffl-funnels-addons') . '</p>';
        echo '</div></form>';
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
        echo '<p class="wb-field__desc">' . esc_html__('Automatically generates the complete previous-calendar-month workpaper and sends it to your accountant or bookkeeping team.', 'ffl-funnels-addons') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ffla_tax_report_email_save">';
        wp_nonce_field('ffla_tax_report_email_settings');

        echo '<label class="ffla-tax-report-pii"><input type="checkbox" name="enabled" value="1" ' . checked($settings['enabled'], '1', false) . '> <span><strong>';
        echo esc_html__('Enable monthly delivery', 'ffl-funnels-addons') . '</strong><small>';
        echo esc_html__('The report always covers the full previous calendar month in the WordPress site timezone.', 'ffl-funnels-addons');
        echo '</small></span></label>';

        echo '<div class="ffla-tax-report-email-grid">';
        echo '<label><span>' . esc_html__('Recipients', 'ffl-funnels-addons') . '</span><textarea name="recipients" rows="3" required>' . esc_textarea(implode("\n", (array) $settings['recipients'])) . '</textarea><small>' . esc_html__('One email per line, or separate addresses with commas.', 'ffl-funnels-addons') . '</small></label>';
        echo '<label><span>' . esc_html__('Day of month', 'ffl-funnels-addons') . '</span><input type="number" name="send_day" min="1" max="28" required value="' . esc_attr((string) $settings['send_day']) . '"><small>' . esc_html__('Days 1–28 avoid invalid dates in shorter months.', 'ffl-funnels-addons') . '</small></label>';
        echo '<label><span>' . esc_html__('Send time', 'ffl-funnels-addons') . '</span><input type="time" name="send_time" required value="' . esc_attr((string) $settings['send_time']) . '"><small>' . esc_html(wp_timezone_string()) . '</small></label>';
        echo '<label><span>' . esc_html__('Maximum ZIP attachment', 'ffl-funnels-addons') . '</span><input type="number" name="max_attachment_mb" min="1" max="50" required value="' . esc_attr((string) $settings['max_attachment_mb']) . '"><small>' . esc_html__('MB. Larger packages fall back to the PDF summary.', 'ffl-funnels-addons') . '</small></label>';
        echo '</div>';

        echo '<fieldset class="ffla-tax-report-statuses"><legend>' . esc_html__('Included order statuses', 'ffl-funnels-addons') . '</legend>';
        foreach ($statuses as $status_key => $label) {
            $status = preg_replace('/^wc-/', '', (string) $status_key);
            echo '<label><input type="checkbox" name="statuses[]" value="' . esc_attr($status) . '" ' . checked(in_array($status, $settings['statuses'], true), true, false) . '> ' . esc_html($label) . '</label>';
        }
        echo '</fieldset>';

        echo '<label class="ffla-tax-report-pii"><input type="checkbox" name="include_pii" value="1" ' . checked($settings['include_pii'], '1', false) . '> <span><strong>';
        echo esc_html__('Include customer and shipping-address data in emailed packages', 'ffl-funnels-addons') . '</strong><small>';
        echo esc_html__('Required if the accountant needs order-level shipping addresses. The ZIP will contain confidential personal information.', 'ffl-funnels-addons');
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
        echo '<button type="submit" name="mode" value="test" class="wb-btn wb-btn--secondary">' . esc_html__('Send test report', 'ffl-funnels-addons') . '</button>';
        echo '<button type="submit" name="mode" value="manual" class="wb-btn wb-btn--secondary">' . esc_html__('Send previous month now', 'ffl-funnels-addons') . '</button>';
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
            echo '<th>' . esc_html($heading) . '</th>';
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

    private static function render_preview(array $report): void
    {
        $stats = (array) ($report['stats'] ?? []);
        $quality = (array) ($report['manifest']['data_quality'] ?? []);

        echo '<section class="ffla-tax-report-preview">';
        echo '<div class="ffla-tax-report-kpis">';
        self::render_kpi(__('Orders', 'ffl-funnels-addons'), (string) ($stats['orders'] ?? 0));
        self::render_kpi(__('Refunds', 'ffl-funnels-addons'), (string) ($stats['refunds'] ?? 0));
        self::render_kpi(__('Exceptions', 'ffl-funnels-addons'), (string) ($stats['exceptions'] ?? 0));
        self::render_kpi(__('Snapshot coverage', 'ffl-funnels-addons'), (string) ($quality['snapshot_coverage_percent'] ?? 0) . '%');
        echo '</div>';

        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Totals by currency', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body ffla-tax-report-table-wrap">';
        self::render_table(
            ['currency', 'orders', 'gross_product_sales', 'discounts', 'net_product_sales', 'shipping', 'fees', 'tax_collected', 'tax_refunded', 'net_tax', 'refunds', 'order_total', 'net_collected'],
            (array) ($report['totals_by_currency'] ?? [])
        );
        echo '</div></div>';

        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('State workpaper', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body ffla-tax-report-table-wrap">';
        self::render_table(Tax_Report_Service::get_columns('state-summary'), (array) ($report['summaries']['states'] ?? []));
        echo '</div></div>';

        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Exception summary', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body ffla-tax-report-table-wrap">';
        self::render_table(['severity', 'code', 'count', 'message'], (array) ($report['summaries']['exceptions'] ?? []));
        if (($stats['exceptions'] ?? 0) > count((array) ($report['exceptions'] ?? []))) {
            echo '<p class="wb-field__desc">' . esc_html__('The preview shows the first 100 order-level exceptions. The downloaded exceptions.csv contains every exception.', 'ffl-funnels-addons') . '</p>';
        }
        echo '</div></div>';

        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Detailed checks to review', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body ffla-tax-report-table-wrap">';
        self::render_table(Tax_Report_Service::get_columns('exceptions'), (array) ($report['exceptions'] ?? []));
        echo '</div></div>';

        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Known data gaps', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body"><ul class="ffla-tax-report-limitations">';
        foreach ((array) ($report['manifest']['limitations'] ?? []) as $limitation) {
            echo '<li>' . esc_html((string) $limitation) . '</li>';
        }
        echo '</ul></div></div>';
        echo '</section>';
    }

    private static function render_kpi(string $label, string $value): void
    {
        echo '<div class="ffla-tax-report-kpi"><span>' . esc_html($label) . '</span><strong>' . esc_html($value) . '</strong></div>';
    }

    private static function render_table(array $columns, array $rows): void
    {
        echo '<table class="widefat striped ffla-tax-report-table"><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . esc_html(ucwords(str_replace('_', ' ', $column))) . '</th>';
        }
        echo '</tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="' . esc_attr((string) max(1, count($columns))) . '">' . esc_html__('No records for this selection.', 'ffl-funnels-addons') . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($columns as $column) {
                    echo '<td>' . esc_html((string) ($row[$column] ?? '')) . '</td>';
                }
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
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
            echo '<th>' . esc_html($heading) . '</th>';
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
