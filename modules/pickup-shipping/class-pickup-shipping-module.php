<?php
/** Configurable, opt-in delivery selector. No tax or carrier calculation hooks. */
defined('ABSPATH') || exit;
class Pickup_Shipping_Module extends FFLA_Module
{
    public function get_id(): string { return 'pickup-shipping'; }
    public function get_name(): string { return __('Pickup & Shipping', 'ffl-funnels-addons'); }
    public function get_description(): string { return __('Store pickup or shipping, with optional FFL delivery rules for classic WooCommerce checkout.', 'ffl-funnels-addons'); }
    public function get_icon_svg(): string { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4h14v13H1zM15 9h4l4 4v4h-8"/><circle cx="5" cy="18" r="2"/><circle cx="19" cy="18" r="2"/></svg>'; }
    public function boot(): void
    {
        require_once __DIR__ . '/includes/class-pickup-shipping-settings.php';
        require_once __DIR__ . '/includes/class-pickup-shipping-engine.php';
        require_once __DIR__ . '/includes/class-pickup-shipping-checkout.php';
        Pickup_Shipping_Checkout::init();
        if (is_admin()) {
            require_once __DIR__ . '/admin/class-pickup-shipping-admin.php';
            Pickup_Shipping_Admin::init();
        }
    }
    public function activate(): void
    {
        require_once __DIR__ . '/includes/class-pickup-shipping-settings.php';
        add_option(Pickup_Shipping_Settings::OPTION, Pickup_Shipping_Settings::defaults(), '', false);
    }
    public function deactivate(): void { /* Keep settings; no cron, tables or generated rates. */ }
    public function get_admin_pages(): array { return [['slug'=>'ffla-pickup-shipping','title'=>__('Pickup & Shipping', 'ffl-funnels-addons')]]; }
    public function render_admin_page(string $page_slug): void { Pickup_Shipping_Admin::render(); }
}

