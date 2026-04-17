<?php
/**
 * Woo Sheets Sync Module — Entry Point.
 *
 * Extends FFLA_Module to integrate Google Sheets bidirectional sync
 * into the unified FFL Funnels Addons plugin.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooSheets_Module extends FFLA_Module
{
    /** @var WSS_Admin|null */
    private $admin;

    public function get_id(): string
    {
        return 'woo-sheets-sync';
    }

    public function get_name(): string
    {
        return __('Woo Sheets Sync', 'ffl-funnels-addons');
    }

    public function get_description(): string
    {
        return __('Bidirectional sync between WooCommerce product inventory and Google Sheets. OAuth 2.0, daily cron, conflict resolution.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 3h18v18H3V3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M3 9h18M3 15h18M9 3v18M15 3v18" stroke="currentColor" stroke-width="1.2"/></svg>';
    }

    public function boot(): void
    {
        $path = $this->get_path();

        // Core includes.
        require_once $path . 'includes/class-wss-google-oauth.php';
        require_once $path . 'includes/class-wss-google-sheets.php';
        require_once $path . 'includes/class-wss-logger.php';
        require_once $path . 'includes/class-wss-sync-groups.php';
        require_once $path . 'includes/services/class-wss-attribute-upsert-service.php';
        require_once $path . 'includes/services/class-wss-product-upsert-service.php';
        require_once $path . 'includes/services/class-wss-variation-upsert-service.php';
        require_once $path . 'includes/api/class-wss-rest-routes.php';
        require_once $path . 'includes/class-wss-sync-engine.php';
        require_once $path . 'includes/class-wss-sync-orchestrator.php';
        require_once $path . 'includes/class-wss-sync-job.php';
        require_once $path . 'includes/class-wss-activator.php';
        require_once $path . 'includes/class-wss-cron.php';

        // Register the Action Scheduler hook so async Sync Now jobs fire.
        WSS_Sync_Job::init();

        $attr_service = new WSS_Attribute_Upsert_Service();
        $product_service = new WSS_Product_Upsert_Service($attr_service);
        $variation_service = new WSS_Variation_Upsert_Service($attr_service, $product_service);
        $rest_routes = new WSS_REST_Routes($attr_service, $product_service, $variation_service);
        $rest_routes->init();

        // Admin.
        if (is_admin()) {
            require_once $path . 'admin/class-wss-admin.php';
            require_once $path . 'admin/class-wss-metabox.php';

            $this->admin = new WSS_Admin();
            $this->admin->init();

            $metabox = new WSS_Metabox();
            $metabox->init();
        }

        // Cron — register event handlers and schedule.
        $cron = new WSS_Cron();
        $cron->init();
        WSS_Cron::schedule();
    }

    public function activate(): void
    {
        $path = $this->get_path();
        require_once $path . 'includes/class-wss-activator.php';
        WSS_Activator::activate();
    }

    public function deactivate(): void
    {
        WSS_Cron::unschedule();
    }

    public function get_admin_pages(): array
    {
        $icon_settings = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6.5 1.5h3l.5 2 1.7 1-1.7 1.5.5 2.5-2.5-1-2.5 1 .5-2.5L4.3 4.5l1.7-1 .5-2z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.2"/></svg>';
        $icon_dashboard = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 2h5v5H2V2zM9 2h5v5H9V2zM2 9h5v5H2V9zM9 9h5v5H9V9z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>';

        $icon_docs = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 1h6l4 4v10H4V1z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M10 1v4h4M6 8h6M6 10.5h6M6 13h3" stroke="currentColor" stroke-width="1.2"/></svg>';

        return [
            [
                'slug'  => 'ffla-wss-settings',
                'title' => __('WSS Settings', 'ffl-funnels-addons'),
                'icon'  => $icon_settings,
            ],
            [
                'slug'  => 'ffla-wss-dashboard',
                'title' => __('WSS Dashboard', 'ffl-funnels-addons'),
                'icon'  => $icon_dashboard,
            ],
            [
                'slug'  => 'ffla-wss-docs',
                'title' => __('WSS Docs', 'ffl-funnels-addons'),
                'icon'  => $icon_docs,
            ],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        if (!$this->admin) {
            FFLA_Admin::render_notice('warning', __('Woo Sheets Sync admin could not be loaded. Please deactivate and reactivate the module.', 'ffl-funnels-addons'));
            return;
        }

        switch ($page_slug) {
            case 'ffla-wss-settings':
                $this->admin->render_settings_page();
                break;

            case 'ffla-wss-dashboard':
                $this->admin->render_dashboard_page();
                break;

            case 'ffla-wss-docs':
                $this->admin->render_docs_page();
                break;
        }
    }

    /**
     * Admin notice when FFL Manager plugin is not active.
     */
    public function missing_ffl_manager_notice(): void
    {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            wp_kses_post(
                sprintf(
                    /* translators: %s: plugin name */
                    esc_html__('%s requires the FFL Manager plugin to be installed and active.', 'ffl-funnels-addons'),
                    '<strong>Woo Sheets Sync</strong>'
                )
            )
        );
    }
}
