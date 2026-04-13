<?php
/**
 * Tax Rates Module — Entry Point.
 *
 * Tax Address Resolver for US sales tax by address.
 *
 * Uses Google Sheets CSV ZIP datasets stored locally in WordPress and
 * refreshed monthly for the store's selected states.
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
            'US sales tax resolver powered by a shared Google Sheets ZIP dataset stored locally in WordPress and refreshed monthly for the states your store uses.',
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
        require_once $base . 'includes/resolvers/class-sheet-zip-dataset-resolver.php';
        require_once $base . 'includes/resolvers/class-usgeocoder-api-resolver.php';

        // Register resolvers.
        Tax_Resolver_Router::register(new Sheet_ZIP_Dataset_Resolver());
        Tax_Resolver_Router::register(new USGeocoder_API_Resolver());

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

        $settings = get_option('ffla_tax_resolver_settings', []);
        $api_key  = trim((string) ($settings['usgeocoder_auth_key'] ?? ''));

        foreach (Tax_Coverage::ALL_STATES as $state_code) {
            if ($api_key !== '') {
                Tax_Coverage::update_state(
                    $state_code,
                    Tax_Coverage::SUPPORTED_WITH_REMOTE,
                    'usgeocoder_api',
                    'USGeocoder live API is active for this state.'
                );
                continue;
            }

            $has_dataset = Tax_Dataset_Pipeline::has_active_sheet_dataset($state_code);
            $status      = $has_dataset
                ? Tax_Dataset_Pipeline::get_active_sheet_coverage_status($state_code)
                : Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
            $note        = 'Run sheet sync to build the local ZIP dataset for this state.';

            if ($has_dataset) {
                $note = $status === Tax_Coverage::NO_SALES_TAX
                    ? 'A zero-tax Google Sheet dataset is active for this state.'
                    : 'Google Sheet ZIP dataset is active for this state.';
            }

            Tax_Coverage::update_state(
                $state_code,
                $status,
                'sheet_zip_dataset',
                $note
            );
        }

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
                'restrict_states' => '0',
                'enabled_states'  => [],
                'sheet_source_url'=> Tax_Dataset_Pipeline::DEFAULT_SHEET_URL,
                'usgeocoder_auth_key' => '',
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
