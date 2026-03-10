<?php
/**
 * FFL Checkout Assets — Frontend script/style loader.
 *
 * Conditionally enqueues Radar SDK + custom JS on the WooCommerce checkout page.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Assets
{
    /**
     * Radar SDK & autocomplete plugin versions.
     */
    const RADAR_SDK_VERSION          = 'v4.4.3';
    const RADAR_AUTOCOMPLETE_VERSION = 'v1.0.0';

    /**
     * Register the wp_enqueue_scripts hook.
     */
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue'], 20);
    }

    /**
     * Conditionally enqueue Radar SDK and our custom JS on the checkout page.
     */
    public static function maybe_enqueue(): void
    {
        // Only load on WooCommerce checkout.
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        $settings = get_option('ffl_checkout_settings', []);
        $enabled  = ($settings['autocomplete_enabled'] ?? '0') === '1';
        $key      = $settings['radar_publishable_key'] ?? '';

        // Don't load if disabled or key is missing.
        if (!$enabled || empty($key)) {
            return;
        }

        // ── Radar SDK ─────────────────────────────────────────────────
        wp_enqueue_script(
            'radar-sdk',
            'https://js.radar.com/' . self::RADAR_SDK_VERSION . '/radar.min.js',
            [],
            null,  // External script — no local version.
            true   // Load in footer.
        );

        // ── Radar Autocomplete UI Plugin ──────────────────────────────
        wp_enqueue_style(
            'radar-autocomplete-css',
            'https://js.radar.com/autocomplete/' . self::RADAR_AUTOCOMPLETE_VERSION . '/radar-autocomplete.css',
            [],
            null
        );

        wp_enqueue_script(
            'radar-autocomplete-js',
            'https://js.radar.com/autocomplete/' . self::RADAR_AUTOCOMPLETE_VERSION . '/radar-autocomplete.min.js',
            ['radar-sdk'],
            null,
            true
        );

        // ── Our custom initialization script ──────────────────────────
        $module_url = FFLA_URL . 'modules/ffl-checkout/';

        wp_enqueue_script(
            'ffl-checkout-radar',
            $module_url . 'assets/js/ffl-checkout-radar.js',
            ['radar-sdk', 'radar-autocomplete-js'],
            FFLA_VERSION,
            true
        );

        wp_localize_script('ffl-checkout-radar', 'fflCheckoutRadar', [
            'publishableKey' => $key,
        ]);
    }
}
