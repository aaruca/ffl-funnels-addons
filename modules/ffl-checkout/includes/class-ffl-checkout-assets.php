<?php
/**
 * FFL Checkout Assets — Frontend script/style loader.
 *
 * Enqueues our Mapbox autocomplete JS on the WooCommerce checkout page.
 * Uses the Mapbox Searchbox REST API directly — no CDN SDK required.
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
     * Conditionally enqueue our custom JS on the checkout page.
     */
    public static function maybe_enqueue(): void
    {
        // Only load on WooCommerce checkout.
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $settings = get_option('ffl_checkout_settings', []);
        $enabled  = ($settings['autocomplete_enabled'] ?? '0') === '1';
        $token    = $settings['mapbox_public_token'] ?? '';

        // Don't load if disabled or token is missing.
        if (!$enabled || empty($token)) {
            return;
        }

        $module_url = FFLA_URL . 'modules/ffl-checkout/';

        wp_enqueue_script(
            'ffl-checkout-mapbox',
            $module_url . 'assets/js/ffl-checkout-mapbox.js',
            [],
            FFLA_VERSION,
            true  // Load in footer.
        );

        wp_localize_script('ffl-checkout-mapbox', 'fflCheckoutMapbox', [
            'accessToken' => $token,
        ]);
    }
}
