<?php
/**
 * FFL Checkout Module — Entry Point.
 *
 * Extends FFLA_Module. Integrates Mapbox address autocomplete,
 * FFL Dealer Finder bridge, and vendor selector into the
 * WooCommerce checkout.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Module extends FFLA_Module
{
    /** @var FFL_Checkout_Admin|null */
    private $admin;

    public function get_id(): string
    {
        return 'ffl-checkout';
    }

    public function get_name(): string
    {
        return 'FFL Checkout';
    }

    public function get_description(): string
    {
        return __('Mapbox address autocomplete, FFL Dealer Finder bridge, and vendor selector for WooCommerce checkout.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
    }

    /* ── Boot ──────────────────────────────────────────────────────── */

    public function boot(): void
    {
        $base = $this->get_path();

        // Frontend asset loading (Mapbox autocomplete + vendor selector).
        require_once $base . 'includes/class-ffl-checkout-assets.php';
        FFL_Checkout_Assets::init();

        // AJAX handlers (Mapbox token + vendor endpoints).
        require_once $base . 'includes/class-ffl-checkout-ajax.php';
        FFL_Checkout_Ajax::init();

        // Dealer Finder bridge shortcode [ffl_dealer_finder].
        require_once $base . 'includes/class-ffl-checkout-dealer-bridge.php';
        FFL_Checkout_Dealer_Bridge::init();

        // Dealer Finder Bricks element.
        add_action('init', function () use ($base) {
            if (class_exists('\Bricks\Elements')) {
                \Bricks\Elements::register_element(
                    $base . 'frontend/class-ffl-dealer-finder-element.php'
                );
            }
        }, 11);

        // Vendor API proxy.
        require_once $base . 'includes/class-ffl-checkout-vendor-api.php';

        // Vendor selector shortcode [ffl_vendor_selector].
        require_once $base . 'includes/class-ffl-checkout-vendor-shortcode.php';
        FFL_Checkout_Vendor_Shortcode::init();

        // Vendor cart hooks (reapply price/SKU/shipping from session).
        require_once $base . 'includes/class-ffl-checkout-vendor-cart.php';
        FFL_Checkout_Vendor_Cart::init();

        // Admin settings page.
        if (is_admin()) {
            require_once $base . 'admin/class-ffl-checkout-admin.php';
            $this->admin = new FFL_Checkout_Admin();
            $this->admin->init();
        }
    }

    /* ── Activation / Deactivation ─────────────────────────────────── */

    public function activate(): void
    {
        if (false === get_option('ffl_checkout_settings')) {
            update_option('ffl_checkout_settings', [
                'mapbox_public_token'     => '',
                'autocomplete_enabled'    => '0',
                'vendor_selector_enabled' => '0',
            ]);
        }
    }

    public function deactivate(): void
    {
        // No cleanup needed — keep settings for re-activation.
    }

    /* ── Admin Pages ───────────────────────────────────────────────── */

    public function get_admin_pages(): array
    {
        return [
            [
                'slug'  => 'ffla-ffl-checkout',
                'title' => __('FFL Checkout Settings', 'ffl-funnels-addons'),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
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
