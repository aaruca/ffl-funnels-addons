<?php
/**
 * Independent Sales Tax Reports module.
 *
 * Report implementation files remain in their established tax-rates paths to
 * keep this update small and preserve every stored option, hook, and asset URL.
 * The module boundary is independent even though those legacy paths remain.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Reports_Module extends FFLA_Module
{
    public function get_id(): string
    {
        return 'tax-reports';
    }

    public function get_name(): string
    {
        return __('Sales Tax Reports', 'ffl-funnels-addons');
    }

    public function get_description(): string
    {
        return __('Generate filing-ready WooCommerce sales tax reports, reconciliation, nexus monitoring, exports, fiscal snapshots, and monthly email delivery without requiring the Sales Tax Resolver.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>';
    }

    public function boot(): void
    {
        $report_base = FFLA_PATH . 'modules/tax-rates/';

        require_once $report_base . 'includes/class-tax-report-service.php';
        require_once $report_base . 'includes/class-tax-report-exporter.php';
        require_once $report_base . 'includes/class-tax-report-reconciliation.php';
        require_once $report_base . 'includes/class-tax-nexus-monitor.php';
        require_once $report_base . 'includes/class-tax-report-combiner.php';
        require_once $report_base . 'includes/class-tax-report-snapshot.php';
        require_once $report_base . 'includes/class-tax-report-email.php';

        Tax_Report_Snapshot::init();
        Tax_Report_Email::init();

        if (is_admin()) {
            require_once $report_base . 'admin/class-tax-reports-admin.php';
            Tax_Reports_Admin::init();
        }
    }

    public function activate(): void
    {
        // Scheduling is reconciled on init after WooCommerce loads Action Scheduler.
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook('ffla_tax_report_monthly_email');
        wp_clear_scheduled_hook('ffla_tax_report_email_send');

        if (!class_exists('Tax_Report_Email')) {
            require_once FFLA_PATH . 'modules/tax-rates/includes/class-tax-report-email.php';
        }
        Tax_Report_Email::clear_all_schedules();
    }

    public function get_admin_pages(): array
    {
        // The report keeps its existing canonical WooCommerce submenu.
        return [];
    }

    public function render_admin_page(string $page_slug): void
    {
        // The canonical report page is rendered by Tax_Reports_Admin.
    }
}
