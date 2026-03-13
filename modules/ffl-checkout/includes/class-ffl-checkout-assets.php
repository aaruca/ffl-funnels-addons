<?php
/**
 * FFL Checkout Assets — Frontend script/style loader.
 *
 * Enqueues Mapbox autocomplete JS and vendor selector JS/CSS
 * on the WooCommerce checkout page.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Assets
{
    /**
     * Register the wp_enqueue_scripts hook.
     */
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue'], 20);
    }

    /**
     * Conditionally enqueue our custom assets on the checkout page.
     */
    public static function maybe_enqueue(): void
    {
        // Only load on WooCommerce checkout.
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $settings   = get_option('ffl_checkout_settings', []);
        $module_url = FFLA_URL . 'modules/ffl-checkout/';

        // ── Mapbox Address Autocomplete ──────────────────────────────────
        $autocomplete_enabled = ($settings['autocomplete_enabled'] ?? '0') === '1';
        $token                = $settings['mapbox_public_token'] ?? '';

        if ($autocomplete_enabled && !empty($token)) {
            wp_enqueue_script(
                'ffl-checkout-mapbox',
                $module_url . 'assets/js/ffl-checkout-mapbox.js',
                [],
                FFLA_VERSION,
                true
            );

            wp_localize_script('ffl-checkout-mapbox', 'fflCheckoutMapbox', [
                'accessToken' => $token,
            ]);
        }

        // ── Vendor Selector ─────────────────────────────────────────────
        $vendor_enabled = ($settings['vendor_selector_enabled'] ?? '0') === '1';

        if ($vendor_enabled) {
            wp_enqueue_script(
                'ffl-checkout-vendor',
                $module_url . 'assets/js/ffl-checkout-vendor.js',
                ['jquery'],
                FFLA_VERSION,
                true
            );

            wp_localize_script('ffl-checkout-vendor', 'fflVendor', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('ffl_checkout_nonce'),
            ]);
        }
    }
}
