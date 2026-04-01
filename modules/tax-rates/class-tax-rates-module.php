<?php
/**
 * Tax Rates Module — Entry Point.
 *
 * Tax Address Resolver for US sales tax by address.
 *
 * Uses imported SalesTaxHandbook city tables as the primary local dataset
 * source, persisted to local tables and refreshed monthly for the store's
 * selected states.
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
        return 'Tax Address Resolver';
    }

    public function get_description(): string
    {
        return __(
            'US sales tax resolver powered by imported SalesTaxHandbook city tables stored locally in the WordPress database and refreshed monthly for the states your store uses.',
            'ffl-funnels-addons'
        );
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
    }

    /* ── Boot ──────────────────────────────────────────────────────── */

    public function boot(): void
    {
        $base = $this->get_path();

        // Core classes.
        require_once $base . 'includes/class-tax-resolver-db.php';
        require_once $base . 'includes/class-tax-coverage.php';
        require_once $base . 'includes/class-tax-address-normalizer.php';
        require_once $base . 'includes/class-tax-quote-result.php';
        require_once $base . 'includes/class-tax-geocoder.php';
        require_once $base . 'includes/class-tax-resolver-router.php';
        require_once $base . 'includes/class-tax-quote-engine.php';
        require_once $base . 'includes/class-tax-dataset-pipeline.php';
        require_once $base . 'includes/class-tax-rest-api.php';
        require_once $base . 'includes/class-tax-woocommerce-integration.php';

        // Resolvers.
        require_once $base . 'includes/resolvers/class-tax-resolver-base.php';
        require_once $base . 'includes/resolvers/class-handbook-city-dataset-resolver.php';

        // Register resolvers.
        Tax_Resolver_Router::register(new Handbook_City_Dataset_Resolver());

        // Register REST API routes.
        add_action('rest_api_init', ['Tax_REST_API', 'register_routes']);

        // WooCommerce runtime tax calculation.
        Tax_WooCommerce_Integration::init();

        // Cron.
        require_once $base . 'includes/class-tax-rates-cron.php';
        Tax_Rates_Cron::init();

        // Check if DB needs upgrade.
        if (Tax_Resolver_DB::needs_upgrade()) {
            Tax_Resolver_DB::install();
        }

        foreach (Tax_Coverage::ALL_STATES as $state_code) {
            $has_dataset = Tax_Dataset_Pipeline::has_active_handbook_dataset($state_code);

            Tax_Coverage::update_state(
                $state_code,
                $has_dataset ? Tax_Coverage::SUPPORTED_ADDRESS_RATE : Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED,
                'handbook_city_dataset',
                $has_dataset
                    ? 'Imported SalesTaxHandbook city-table dataset is active for this state.'
                    : 'Run Sync SalesTaxHandbook States to import the SalesTaxHandbook state city table for this state.'
            );
        }

        $settings = get_option('ffla_tax_resolver_settings', []);
        Tax_Quote_Engine::set_cache_ttl((int) ($settings['cache_ttl'] ?? 86400));

        // Admin UI.
        if (is_admin()) {
            require_once $base . 'admin/class-tax-rates-admin.php';
            $this->admin = new Tax_Rates_Admin();
            $this->admin->init();
        }
    }

    /* ── Activation / Deactivation ─────────────────────────────────── */

    public function activate(): void
    {
        $base = $this->get_path();

        // Ensure DB class is loaded.
        if (!class_exists('Tax_Resolver_DB')) {
            require_once $base . 'includes/class-tax-resolver-db.php';
        }

        // Create custom tables.
        Tax_Resolver_DB::install();

        // Default settings.
        if (false === get_option('ffla_tax_resolver_settings')) {
            update_option('ffla_tax_resolver_settings', [
                'cache_ttl'       => 86400,    // 24 hours
                'auto_sync'       => '1',
                'sync_schedule'   => 'monthly',
                'wc_auto_sync'    => '0',
                'restrict_states' => '0',
                'enabled_states'  => [],
            ]);
        }
    }

    public function deactivate(): void
    {
        wp_clear_scheduled_hook('ffla_tax_dataset_sync');
        wp_clear_scheduled_hook('ffla_tax_cache_cleanup');
        wp_clear_scheduled_hook('ffla_tax_audit_purge');
    }

    /* ── Admin Pages ───────────────────────────────────────────────── */

    public function get_admin_pages(): array
    {
        return [
            [
                'slug'  => 'ffla-tax-rates',
                'title' => __('Tax Resolver', 'ffl-funnels-addons'),
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
