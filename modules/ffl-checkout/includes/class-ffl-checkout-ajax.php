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
        // Note: ffl_get_mapbox_token and ffl_get_vendor_options are intentionally
        // not registered. No client code calls them, and exposing them (to
        // unauthenticated users especially) only widened the attack surface —
        // vendor-option enumeration and API-quota drain. The Mapbox token is
        // already provided inline via wp_localize_script.

        add_action('wp_ajax_ffl_update_cart_vendor', [__CLASS__, 'update_cart_vendor']);
        add_action('wp_ajax_nopriv_ffl_update_cart_vendor', [__CLASS__, 'update_cart_vendor']);
    }

    /* ── Mapbox Token ────────────────────────────────────────────────── */

    /**
     * Return the resolved Mapbox token via AJAX.
     *
     * Uses the same "Auto + override" resolution as the asset loader: the
     * admin's own token if set, otherwise one borrowed from g-FFL Checkout.
     */
    public static function get_mapbox_token(): void
    {
        check_ajax_referer('ffl_checkout_nonce', 'security');

        $token = FFL_Checkout_Mapbox::resolve_token();

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

        // Per-session lock to prevent concurrent updates from racing on the
        // same cart item key (double-click, two browser tabs). Without this,
        // two requests could both pass validation and the second's session
        // write would overwrite the first.
        $session_id = WC()->session ? WC()->session->get_customer_id() : '';
        $lock_key   = 'ffl_vendor_lock_' . md5($session_id . '|' . $cart_item_key);
        if (get_transient($lock_key)) {
            wp_send_json_error('Another vendor update is in progress. Please retry.');
        }
        set_transient($lock_key, 1, 5);

        // Validate the cart item exists.
        $cart_contents = WC()->cart->get_cart();
        if (!isset($cart_contents[$cart_item_key])) {
            delete_transient($lock_key);
            wp_send_json_error('Cart item not found.');
        }

        $cart_item  = $cart_contents[$cart_item_key];
        $product_id = $cart_item['product_id'] ?? 0;

        if (!FFL_Checkout_Vendor_Api::is_eligible($product_id)) {
            delete_transient($lock_key);
            wp_send_json_error('Product is not eligible.');
        }

        // ── Security: verify the submitted values match a real API option ──
        $upc = FFL_Checkout_Vendor_Api::get_upc_for_product($product_id);
        if (empty($upc)) {
            delete_transient($lock_key);
            wp_send_json_error('Product UPC not found.');
        }

        $options = FFL_Checkout_Vendor_Api::get_warehouse_options($upc);
        if (is_wp_error($options) || empty($options)) {
            delete_transient($lock_key);
            wp_send_json_error('Could not verify vendor options.');
        }

        $valid = false;
        foreach ($options as $option) {
            // Cast both sides: the POST values are sanitized strings while the
            // API payload is JSON-decoded, so a numeric warehouse_id/sku would
            // fail a strict === and reject every legitimate selection.
            if (
                (string) ($option['warehouse_id'] ?? '') === $warehouse_id
                && abs(floatval($option['price'] ?? 0) - $price) < 0.01
                && (string) ($option['sku'] ?? '') === $sku
                && (string) ($option['shipping_class'] ?? '') === $shipping_class
            ) {
                $valid = true;
                break;
            }
        }

        if (!$valid) {
            delete_transient($lock_key);
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

        delete_transient($lock_key);
        wp_send_json_success(['message' => 'Vendor updated.']);
    }
}
