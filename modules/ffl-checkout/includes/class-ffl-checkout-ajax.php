<?php
/**
 * FFL Checkout — AJAX Handlers.
 *
 * Provides the Mapbox token and vendor selection endpoints via AJAX.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Ajax
{
    /**
     * Register AJAX hooks.
     */
    public static function init(): void
    {
        // Mapbox token.
        add_action('wp_ajax_ffl_get_mapbox_token', [__CLASS__, 'get_mapbox_token']);
        add_action('wp_ajax_nopriv_ffl_get_mapbox_token', [__CLASS__, 'get_mapbox_token']);

        // Vendor selector.
        add_action('wp_ajax_ffl_get_vendor_options', [__CLASS__, 'get_vendor_options']);
        add_action('wp_ajax_nopriv_ffl_get_vendor_options', [__CLASS__, 'get_vendor_options']);

        add_action('wp_ajax_ffl_update_cart_vendor', [__CLASS__, 'update_cart_vendor']);
        add_action('wp_ajax_nopriv_ffl_update_cart_vendor', [__CLASS__, 'update_cart_vendor']);
    }

    /* ── Mapbox Token ────────────────────────────────────────────────── */

    /**
     * Return the Mapbox public token via AJAX.
     */
    public static function get_mapbox_token(): void
    {
        check_ajax_referer('ffl_checkout_nonce', 'security');

        $settings = get_option('ffl_checkout_settings', []);
        $token    = $settings['mapbox_public_token'] ?? '';

        if (empty($token)) {
            wp_send_json_error('Mapbox token not configured.');
        }

        wp_send_json_success($token);
    }

    /* ── Vendor Options ──────────────────────────────────────────────── */

    /**
     * Fetch warehouse options for a product via the Garidium API.
     */
    public static function get_vendor_options(): void
    {
        check_ajax_referer('ffl_checkout_nonce', 'security');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $product_id = absint($_POST['product_id'] ?? 0);

        if (!$product_id) {
            wp_send_json_error('Missing product ID.');
        }

        if (!FFL_Checkout_Vendor_Api::is_eligible($product_id)) {
            wp_send_json_error('Product is not eligible for vendor selection.');
        }

        $upc = FFL_Checkout_Vendor_Api::get_upc_for_product($product_id);
        if (empty($upc)) {
            wp_send_json_error('Product UPC not found.');
        }

        $options = FFL_Checkout_Vendor_Api::get_warehouse_options($upc);

        if (is_wp_error($options)) {
            wp_send_json_error($options->get_error_message());
        }

        wp_send_json_success($options);
    }

    /* ── Update Cart Vendor ──────────────────────────────────────────── */

    /**
     * Update a cart item's vendor selection.
     *
     * Validates the submitted data against actual API options to prevent
     * price manipulation.
     */
    public static function update_cart_vendor(): void
    {
        check_ajax_referer('ffl_checkout_nonce', 'security');

        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error('Cart not available.');
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $cart_item_key = sanitize_text_field($_POST['cart_item_key'] ?? '');
        $warehouse_id  = sanitize_text_field($_POST['warehouse_id'] ?? '');
        $price         = floatval($_POST['price'] ?? 0);
        $sku           = sanitize_text_field($_POST['sku'] ?? '');
        $shipping_class = sanitize_text_field($_POST['shipping_class'] ?? '');
        // phpcs:enable

        if (empty($cart_item_key) || empty($warehouse_id)) {
            wp_send_json_error('Missing required fields.');
        }

        // Validate the cart item exists.
        $cart_contents = WC()->cart->get_cart();
        if (!isset($cart_contents[$cart_item_key])) {
            wp_send_json_error('Cart item not found.');
        }

        $cart_item  = $cart_contents[$cart_item_key];
        $product_id = $cart_item['product_id'] ?? 0;

        if (!FFL_Checkout_Vendor_Api::is_eligible($product_id)) {
            wp_send_json_error('Product is not eligible.');
        }

        // ── Security: verify the submitted values match a real API option ──
        $upc = FFL_Checkout_Vendor_Api::get_upc_for_product($product_id);
        if (empty($upc)) {
            wp_send_json_error('Product UPC not found.');
        }

        $options = FFL_Checkout_Vendor_Api::get_warehouse_options($upc);
        if (is_wp_error($options) || empty($options)) {
            wp_send_json_error('Could not verify vendor options.');
        }

        $valid = false;
        foreach ($options as $option) {
            if (
                ($option['warehouse_id'] ?? '') === $warehouse_id
                && abs(floatval($option['price'] ?? 0) - $price) < 0.01
                && ($option['sku'] ?? '') === $sku
                && ($option['shipping_class'] ?? '') === $shipping_class
            ) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            wp_send_json_error('Invalid vendor selection.');
        }

        // ── Resolve shipping class name → term ID ──
        $shipping_class_id = FFL_Checkout_Vendor_Api::resolve_shipping_class_id($shipping_class);

        // ── Update cart item data ──
        WC()->cart->cart_contents[$cart_item_key]['custom_product_option']                = $warehouse_id;
        WC()->cart->cart_contents[$cart_item_key]['custom_product_option_price']           = $price;
        WC()->cart->cart_contents[$cart_item_key]['custom_product_option_sku']             = $sku;
        WC()->cart->cart_contents[$cart_item_key]['custom_product_option_shipping_class']  = $shipping_class_id;

        // Apply to the product object.
        WC()->cart->cart_contents[$cart_item_key]['data']->set_price($price);
        WC()->cart->cart_contents[$cart_item_key]['data']->set_sku($sku);
        WC()->cart->cart_contents[$cart_item_key]['data']->set_shipping_class_id($shipping_class_id);

        // Persist to session.
        WC()->cart->set_session();

        wp_send_json_success(['message' => 'Vendor updated.']);
    }
}
