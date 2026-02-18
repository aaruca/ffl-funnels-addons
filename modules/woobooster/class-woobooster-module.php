<?php
/**
 * WooBooster Module — Entry Point.
 *
 * Extends FFLA_Module to integrate WooBooster into the unified plugin.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Module extends FFLA_Module
{
    public function get_id(): string
    {
        return 'woobooster';
    }

    public function get_name(): string
    {
        return 'WooBooster';
    }

    public function get_description(): string
    {
        return __('Rule-based product recommendations engine for WooCommerce. Supports conditions, actions, smart strategies, and Bricks Builder integration.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
    }

    public function get_version(): string
    {
        return '2.1.0';
    }

    public function boot(): void
    {
        $path = $this->get_path();

        // Core includes.
        require_once $path . 'includes/class-woobooster-rule.php';
        require_once $path . 'includes/class-woobooster-matcher.php';
        require_once $path . 'includes/class-woobooster-activator.php';
        require_once $path . 'includes/class-woobooster-cron.php';
        require_once $path . 'includes/class-woobooster-shortcode.php';
        require_once $path . 'includes/class-woobooster-copurchase.php';
        require_once $path . 'includes/class-woobooster-trending.php';

        // Admin.
        if (is_admin()) {
            require_once $path . 'admin/class-woobooster-admin.php';
            require_once $path . 'admin/class-woobooster-ajax.php';
            require_once $path . 'admin/class-woobooster-icons.php';
            require_once $path . 'admin/class-woobooster-rule-form.php';
            require_once $path . 'admin/class-woobooster-rule-list.php';
            require_once $path . 'admin/class-woobooster-rule-tester.php';

            $admin = new WooBooster_Admin();
            $admin->init();

            $ajax = new WooBooster_Ajax();
            $ajax->init();
        }

        // Frontend.
        require_once $path . 'frontend/class-woobooster-frontend.php';
        require_once $path . 'frontend/class-woobooster-bricks.php';

        $frontend = new WooBooster_Frontend();
        $frontend->init();

        // Cron schedules.
        WooBooster_Cron::schedule();

        // Shortcode.
        WooBooster_Shortcode::init();

        // Bricks Builder integration.
        if (defined('BRICKS_VERSION')) {
            $bricks = new WooBooster_Bricks();
            $bricks->init();
        }
    }

    public function activate(): void
    {
        $path = $this->get_path();
        require_once $path . 'includes/class-woobooster-activator.php';
        WooBooster_Activator::activate();
    }

    public function deactivate(): void
    {
        // Unschedule cron events.
        wp_clear_scheduled_hook('woobooster_copurchase_build');
        wp_clear_scheduled_hook('woobooster_trending_build');
    }

    public function get_admin_pages(): array
    {
        return [
            [
                'slug'  => 'ffla-woobooster',
                'title' => __('WB Settings', 'ffl-funnels-addons'),
                'icon'  => WooBooster_Icons::get('settings'),
            ],
            [
                'slug'  => 'ffla-woobooster-rules',
                'title' => __('WB Rules', 'ffl-funnels-addons'),
                'icon'  => WooBooster_Icons::get('rules'),
            ],
            [
                'slug'  => 'ffla-woobooster-diagnostics',
                'title' => __('WB Diagnostics', 'ffl-funnels-addons'),
                'icon'  => WooBooster_Icons::get('search'),
            ],
            [
                'slug'  => 'ffla-woobooster-docs',
                'title' => __('WB Docs', 'ffl-funnels-addons'),
                'icon'  => WooBooster_Icons::get('docs'),
            ],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        $admin = new WooBooster_Admin();

        switch ($page_slug) {
            case 'ffla-woobooster':
                $admin->render_settings_content();
                break;

            case 'ffla-woobooster-rules':
                $admin->render_rules_content();
                break;

            case 'ffla-woobooster-diagnostics':
                $admin->render_diagnostics_content();
                break;

            case 'ffla-woobooster-docs':
                $admin->render_documentation_content();
                break;
        }
    }
}
