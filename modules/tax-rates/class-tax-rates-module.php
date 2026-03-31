<?php
/**
 * Tax Rates Module — Entry Point.
 *
 * Official Tax Address Resolver for US sales tax by address.
 * Uses hybrid model with official free government sources:
 *   - SST Rate/Boundary files for member states
 *   - Census Bureau Geocoder for address validation
 *   - Individual state resolvers for non-SST states
 *
 * No paid APIs, no AI, no scraping — only official sources.
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
            'US sales tax resolver using official government sources. Geocodes addresses, resolves jurisdictions, and syncs rates to WooCommerce — no paid APIs, fully auditable.',
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
        require_once $base . 'includes/resolvers/class-sst-resolver.php';
        require_once $base . 'includes/resolvers/class-louisiana-remote-resolver.php';
        require_once $base . 'includes/resolvers/class-texas-rate-file-resolver.php';

        // Register resolvers.
        Tax_Resolver_Router::register(new SST_Resolver());
        Tax_Resolver_Router::register(new Louisiana_Remote_Resolver());
        Tax_Resolver_Router::register(new Texas_Rate_File_Resolver());

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

        Tax_Coverage::update_state(
            'LA',
            Tax_Coverage::SUPPORTED_WITH_REMOTE,
            'la_remote',
            'Resolved through the official Louisiana Parish E-File lookup.'
        );

        Tax_Coverage::update_state(
            'TX',
            Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED,
            'tx_rate_file',
            'Resolved through the official Texas Comptroller sales tax rate file with conservative handling for ambiguous special districts.'
        );

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
                'sync_schedule'   => 'quarterly',
                'wc_auto_sync'    => '1',
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
