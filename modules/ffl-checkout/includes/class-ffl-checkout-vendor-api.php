<?php
/**
 * FFL Checkout — Vendor API Proxy.
 *
 * Server-side proxy to the Garidium API for fetching warehouse/vendor
 * options per product.  The API key is never exposed to the browser.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Vendor_Api
{
    /** Garidium API endpoint. */
    const API_URL = 'https://ffl-api.garidium.com';

    /** Transient TTL in seconds (5 minutes). */
    const CACHE_TTL = 300;

    /* ── Warehouse Options ───────────────────────────────────────────── */

    /**
     * Fetch warehouse/vendor options for a given UPC.
     *
     * @param string $upc Product UPC code.
     * @return array|WP_Error Array of warehouse options or WP_Error.
     */
    public static function get_warehouse_options(string $upc)
    {
        if (empty($upc)) {
            return new \WP_Error('missing_upc', 'UPC is required.');
        }

        $cache_key = 'ffl_vendor_opts_' . md5($upc);
        $cached    = get_transient($cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $api_key = get_option('g_ffl_cockpit_key', '');
        if (empty($api_key)) {
            return new \WP_Error('missing_api_key', 'g-FFL Cockpit API key not configured.');
        }

        $payload = wp_json_encode([
            'action' => 'get_warehouse_options',
            'data'   => [
                'upc'     => $upc,
                'api_key' => $api_key,
            ],
        ]);

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key'    => $api_key,
                'Origin'       => home_url(),
            ],
            'body' => $payload,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || empty($data) || isset($data['Error'])) {
            $msg = $data['Error'] ?? ('API returned status ' . $code);
            return new \WP_Error('api_error', $msg);
        }

        set_transient($cache_key, $data, self::CACHE_TTL);

        return $data;
    }

    /* ── UPC Retrieval ───────────────────────────────────────────────── */

    /**
     * Get the UPC for a WooCommerce product.
     *
     * Reads the `pa_upc` product attribute (same as g-FFL Cockpit).
     *
     * @param int $product_id WooCommerce product ID.
     * @return string UPC string or empty.
     */
    public static function get_upc_for_product(int $product_id): string
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return '';
        }

        $attributes = $product->get_attributes();

        foreach ($attributes as $attribute) {
            if (!is_object($attribute) || $attribute->get_name() !== 'pa_upc') {
                continue;
            }

            $options = $attribute->get_options();
            if (empty($options)) {
                continue;
            }

            $term = get_term($options[0]);
            if (!is_wp_error($term) && !empty($term)) {
                return (string) $term->name;
            }
        }

        return '';
    }

    /* ── Eligibility Check ───────────────────────────────────────────── */

    /**
     * Check if a product is eligible for vendor selection.
     *
     * @param int $product_id WooCommerce product ID.
     * @return bool True if the product has the `automated_listing` meta.
     */
    public static function is_eligible(int $product_id): bool
    {
        return (bool) get_post_meta($product_id, 'automated_listing', true);
    }

    /* ── Shipping Class Resolution ───────────────────────────────────── */

    /**
     * Resolve a shipping class name to its term ID, creating it if needed.
     *
     * Mirrors g-FFL Cockpit's add_custom_option_to_cart_item() logic.
     *
     * @param string $class_name Shipping class name from the API.
     * @return int Term ID (0 on failure).
     */
    public static function resolve_shipping_class_id(string $class_name): int
    {
        if (empty($class_name)) {
            return 0;
        }

        $term = get_term_by('name', $class_name, 'product_shipping_class');

        if ($term) {
            return (int) $term->term_id;
        }

        // Create the shipping class if it doesn't exist.
        $new_term = wp_insert_term($class_name, 'product_shipping_class');

        if (!is_wp_error($new_term) && isset($new_term['term_id'])) {
            return (int) $new_term['term_id'];
        }

        return 0;
    }
}
