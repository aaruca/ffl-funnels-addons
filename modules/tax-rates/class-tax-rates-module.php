<?php
/**
 * Tax Rates Module — Entry Point.
 *
 * Imports US state/county sales tax rates into WooCommerce using
 * Tavily (web search) + OpenAI (data structuring). Rates are stored
 * natively in WooCommerce and refreshed monthly via WP-Cron.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Rates_Module extends FFLA_Module
{
    /** @var Tax_Rates_Admin|null */
    private $admin;

    public function get_id(): string
    {
        return 'tax-rates';
    }

    public function get_name(): string
    {
        return 'US Tax Rates';
    }

    public function get_description(): string
    {
        return __('Automatically imports US sales tax rates into WooCommerce by state/county using AI-powered web research. Refreshes monthly.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
    }

    /* ── Boot ──────────────────────────────────────────────────────── */

    public function boot(): void
    {
        $base = $this->get_path();

        // Importer (handles AJAX + WC rate insertion).
        require_once $base . 'includes/class-tax-rates-importer.php';
        Tax_Rates_Importer::init();

        // Monthly cron refresh.
        require_once $base . 'includes/class-tax-rates-cron.php';
        Tax_Rates_Cron::init();

        // Admin settings page.
        if (is_admin()) {
            require_once $base . 'admin/class-tax-rates-admin.php';
            $this->admin = new Tax_Rates_Admin();
            $this->admin->init();
        }
    }

    /* ── Activation / Deactivation ─────────────────────────────────── */

    public function activate(): void
    {
        if (false === get_option('ffl_tax_rates_settings')) {
            update_option('ffl_tax_rates_settings', [
                'rate_depth'    => 'county',
                'auto_refresh'  => '1',
            ]);
        }
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook('ffla_tax_rates_refresh');
    }

    /* ── Admin Pages ───────────────────────────────────────────────── */

    public function get_admin_pages(): array
    {
        return [
            [
                'slug'  => 'ffla-tax-rates',
                'title' => __('US Tax Rates', 'ffl-funnels-addons'),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            ],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        if ($this->admin) {
            $this->admin->render_settings_page();
        }
    }
}
