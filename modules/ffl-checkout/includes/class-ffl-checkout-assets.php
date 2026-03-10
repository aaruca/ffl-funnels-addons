<?php
/**
 * FFL Checkout Assets — Frontend script/style loader.
 *
 * Conditionally enqueues Mapbox Search Box + custom JS on the WooCommerce checkout page.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Assets
{
    /**
     * Mapbox Search Box JS version (CDN).
     */
    const MAPBOX_SEARCH_BOX_VERSION = '1.0.0-beta.21';

    /**
     * Register the wp_enqueue_scripts hook.
     */
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue'], 20);
    }

    /**
     * Conditionally enqueue Mapbox Search Box and our custom JS on the checkout page.
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

        $ver = self::MAPBOX_SEARCH_BOX_VERSION;

        // ── Mapbox Search Box CSS ──────────────────────────────────────
        wp_enqueue_style(
            'mapbox-search-box-css',
            'https://api.mapbox.com/search-js/v' . $ver . '/web.css',
            [],
            null
        );

        // ── Mapbox Search Box JS (ES module via classic script tag) ────
        // The CDN exposes a UMD/IIFE build alongside the ESM build.
        wp_enqueue_script(
            'mapbox-search-box-js',
            'https://api.mapbox.com/search-js/v' . $ver . '/web.js',
            [],
            null,
            true   // Load in footer.
        );

        // ── Our custom initialisation script ───────────────────────────
        $module_url = FFLA_URL . 'modules/ffl-checkout/';

        wp_enqueue_script(
            'ffl-checkout-mapbox',
            $module_url . 'assets/js/ffl-checkout-mapbox.js',
            ['mapbox-search-box-js'],
            FFLA_VERSION,
            true
        );

        wp_localize_script('ffl-checkout-mapbox', 'fflCheckoutMapbox', [
            'accessToken' => $token,
        ]);
    }
}
