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
        return __('Tax Address Resolver', 'ffl-funnels-addons');
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
        require_once $base . 'includes/class-tax-usgeocoder-usage.php';
        require_once $base . 'includes/class-tax-role-gate.php';

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

        // Check if DB needs upgrade. When schema changes land, reconcile
        // coverage rows right after install so the new settings apply before
        // the first request lands.
        if (Tax_Resolver_DB::needs_upgrade()) {
            Tax_Resolver_DB::install();
            Tax_Coverage::reconcile_from_settings();
        }

        $settings = get_option('ffla_tax_resolver_settings', []);
        Tax_Quote_Engine::set_cache_ttl((int) ($settings['cache_ttl'] ?? 86400));

        // Reconcile coverage rows when the settings option changes instead of
        // writing to the DB on every request (the old boot() loop).
        add_action('update_option_ffla_tax_resolver_settings', [__CLASS__, 'on_settings_updated'], 10, 2);
        add_action('add_option_ffla_tax_resolver_settings', [__CLASS__, 'on_settings_added'], 10, 2);

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
        if (!class_exists('Tax_Coverage')) {
            require_once $base . 'includes/class-tax-coverage.php';
        }
        if (!class_exists('Tax_Dataset_Pipeline')) {
            require_once $base . 'includes/class-tax-dataset-pipeline.php';
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
                'tax_role_restrict' => '0',
                'tax_exempt_roles'  => [],
            ]);
        }

        // Reconcile coverage rows so the first request already sees the
        // correct routing without needing to go through the boot() fallback.
        Tax_Coverage::reconcile_from_settings();
    }

    /**
     * Hook callback for `update_option_ffla_tax_resolver_settings`.
     */
    public static function on_settings_updated($old_value, $new_value): void
    {
        if (!class_exists('Tax_Coverage')) {
            return;
        }
        Tax_Coverage::reconcile_from_settings(is_array($new_value) ? $new_value : []);
    }

    /**
     * Hook callback for `add_option_ffla_tax_resolver_settings` (first save).
     */
    public static function on_settings_added($option, $value): void
    {
        if (!class_exists('Tax_Coverage')) {
            return;
        }
        Tax_Coverage::reconcile_from_settings(is_array($value) ? $value : []);
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
