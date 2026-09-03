<?php
/**
 * Google Merchant Policy module.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Google_Merchant_Policy_Module extends FFLA_Module
{
    /** @var Google_Merchant_Policy_Admin|null */
    private $admin;

    public function get_id(): string
    {
        return 'google-merchant-policy';
    }

    public function get_name(): string
    {
        return __('Google Merchant Policy', 'ffl-funnels-addons');
    }

    public function get_description(): string
    {
        return __('Audit and protect the Google for WooCommerce product feed with inherited category policies, firearm/ammunition safety checks, and gradual background reconciliation.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>';
    }

    public function boot(): void
    {
        $base = $this->get_path();
        require_once $base . 'includes/class-google-merchant-policy-engine.php';
        require_once $base . 'includes/class-google-merchant-policy-reconciler.php';

        Google_Merchant_Policy_Engine::init();
        Google_Merchant_Policy_Reconciler::init();

        if (is_admin()) {
            require_once $base . 'admin/class-google-merchant-policy-admin.php';
            $this->admin = new Google_Merchant_Policy_Admin();
            $this->admin->init();
        }
    }

    public function activate(): void
    {
        $base = $this->get_path();
        if (!class_exists('Google_Merchant_Policy_Engine')) {
            require_once $base . 'includes/class-google-merchant-policy-engine.php';
        }
        $settings = get_option(Google_Merchant_Policy_Engine::OPTION, []);
        update_option(
            Google_Merchant_Policy_Engine::OPTION,
            wp_parse_args(is_array($settings) ? $settings : [], Google_Merchant_Policy_Engine::default_settings()),
            false
        );
    }

    public function deactivate(): void
    {
        $base = $this->get_path();
        if (!class_exists('Google_Merchant_Policy_Reconciler')) {
            require_once $base . 'includes/class-google-merchant-policy-reconciler.php';
        }
        Google_Merchant_Policy_Reconciler::clear_schedule();
    }

    public function get_admin_pages(): array
    {
        return [[
            'slug'  => 'ffla-google-merchant-policy',
            'title' => __('Google Merchant Policy', 'ffl-funnels-addons'),
            'icon'  => $this->get_icon_svg(),
        ]];
    }

    public function render_admin_page(string $page_slug): void
    {
        if ($this->admin) {
            $this->admin->render();
        }
    }
}
