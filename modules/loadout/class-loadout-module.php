<?php
/**
 * Loadout Module — Entry Point.
 *
 * Extends FFLA_Module to integrate Loadout (tiered product configurator) into the unified plugin.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Module extends FFLA_Module
{
    private $admin;

    public function get_id(): string
    {
        return 'loadout';
    }

    public function get_name(): string
    {
        return __('Loadout', 'ffl-funnels-addons');
    }

    public function get_description(): string
    {
        return __('Tiered product configurator with gamified savings, perks unlocks, and per-item cross-sells. Includes standalone Bricks element and product-level tabs.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6h16M4 12h16M4 18h16M2 6h2M2 12h2M2 18h2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
    }

    public function boot(): void
    {
        $path = $this->get_path();

        // Core includes.
        require_once $path . 'includes/class-loadout-activator.php';
        require_once $path . 'includes/class-loadout.php';
        require_once $path . 'includes/class-loadout-tier.php';
        require_once $path . 'includes/class-loadout-tier-item.php';
        require_once $path . 'includes/class-loadout-cross-sell.php';
        require_once $path . 'includes/class-loadout-cart.php';
        require_once $path . 'includes/class-loadout-product-tab.php';
        require_once $path . 'includes/class-loadout-shortcode.php';

        // Always load Loadout_Product_Admin — it owns meta-key constants and the
        // static get_product_config() helper that Loadout_Product_Tab needs on
        // frontend product pages. Instantiation stays admin-only below.
        require_once $path . 'admin/class-loadout-product-admin.php';

        // Admin.
        if (is_admin()) {
            // Run pending schema migrations on file-based updates.
            add_action('admin_init', array('Loadout_Activator', 'migrate_tables'));

            require_once $path . 'admin/class-loadout-admin.php';
            require_once $path . 'admin/class-loadout-ajax.php';
            require_once $path . 'admin/class-loadout-form.php';
            require_once $path . 'admin/class-loadout-list.php';

            $this->admin = new Loadout_Admin();
            $this->admin->init();

            $ajax = new Loadout_Ajax();
            $ajax->init();

            $product_admin = new Loadout_Product_Admin();
            $product_admin->init();
        }

        // Frontend.
        require_once $path . 'frontend/class-loadout-frontend.php';

        $frontend = new Loadout_Frontend();
        $frontend->init();

        // Cart integration.
        Loadout_Cart::init();

        // Product tab.
        Loadout_Product_Tab::init();

        // Shortcode.
        Loadout_Shortcode::init();

        // Bricks Builder integration.
        if (defined('BRICKS_VERSION')) {
            require_once $path . 'frontend/class-loadout-element-helpers.php';

            add_action('init', function () use ($path) {
                if (class_exists('\Bricks\Elements')) {
                    // Register the complete monolithic "loadout" element first. This maintains
                    // backward compatibility with Bricks templates saved before v1.33.0 that
                    // reference the legacy "loadout" element name. Without this, unregistered
                    // element names resolve to same-named global PHP classes (via StudlyCaps
                    // conversion), causing Bricks to instantiate the Loadout data model class
                    // as if it were an Element, resulting in fatal errors.
                    \Bricks\Elements::register_element($path . 'frontend/class-loadout-element.php');

                    // Register the composable elements that can be placed independently.
                    \Bricks\Elements::register_element($path . 'frontend/class-loadout-tier-tabs-element.php');
                    \Bricks\Elements::register_element($path . 'frontend/class-loadout-progress-element.php');
                    \Bricks\Elements::register_element($path . 'frontend/class-loadout-cart-mirror-element.php');
                }
            }, 11);
        }
    }

    public function activate(): void
    {
        $path = $this->get_path();
        require_once $path . 'includes/class-loadout-activator.php';
        Loadout_Activator::activate();
    }

    public function deactivate(): void
    {
        // Future: cleanup if needed.
    }

    public function get_admin_pages(): array
    {
        return [
            [
                'slug' => 'ffla-loadouts',
                'title' => __('Loadouts', 'ffl-funnels-addons'),
                'icon' => $this->get_icon_svg(),
            ],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        if (!$this->admin) {
            FFLA_Admin::render_notice('warning', __('Loadout admin could not be loaded. Please deactivate and reactivate the module.', 'ffl-funnels-addons'));
            return;
        }

        switch ($page_slug) {
            case 'ffla-loadouts':
                $this->admin->render_content();
                break;
        }
    }
}
