<?php
/**
 * FFL Checkout Assets — Frontend script/style loader.
 *
 * Enqueues the Mapbox autocomplete script and the vendor selector script on
 * the WooCommerce checkout page. The vendor table ships without its own CSS;
 * it uses the theme's table styles and the ffl-vendor-selector__* classes.
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
        // Token follows the "Auto + override" model: the admin's own token if
        // set, otherwise one borrowed from the g-FFL Checkout plugin.
        $autocomplete_enabled = ($settings['autocomplete_enabled'] ?? '0') === '1';
        $token                = $autocomplete_enabled
            ? FFL_Checkout_Mapbox::resolve_token($settings)
            : '';

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
        $vendor_enabled = FFL_Checkout_Vendor_Api::selector_enabled();

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
