<?php
/**
 * FFL Checkout — Vendor Cart Hooks.
 *
 * WooCommerce hooks that reapply vendor data (price, SKU, shipping class)
 * from the cart session and persist it to order item meta.
 *
 * Runs at priority 20 so it executes AFTER g-FFL Cockpit's own hooks
 * (priority 10), ensuring that vendor changes made at checkout take
 * precedence over the initial product-page selection.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Vendor_Cart
{
    /**
     * Register WooCommerce hooks.
     */
    public static function init(): void
    {
        // Reapply vendor data when the cart is loaded from session.
        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'reapply_vendor_from_session'], 20, 2);

        // Save vendor data to order item meta on checkout.
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_vendor_to_order'], 20, 4);
    }

    /* ── Session Restore ─────────────────────────────────────────────── */

    /**
     * Reapply vendor price, SKU, and shipping class from cart session data.
     *
     * Uses the same meta keys as g-FFL Cockpit:
     * - custom_product_option_sku
     * - custom_product_option_price
     * - custom_product_option_shipping_class
     *
     * @param array $cart_item  Cart item data.
     * @param array $values    Session values.
     * @return array Modified cart item.
     */
    public static function reapply_vendor_from_session(array $cart_item, array $values): array
    {
        $product_id = $cart_item['product_id'] ?? 0;

        if (!FFL_Checkout_Vendor_Api::is_eligible($product_id)) {
            return $cart_item;
        }

        if (
            isset($cart_item['custom_product_option_sku'])
            && isset($cart_item['custom_product_option_price'])
            && isset($cart_item['custom_product_option_shipping_class'])
        ) {
            $cart_item['data']->set_sku($cart_item['custom_product_option_sku']);
            $cart_item['data']->set_price($cart_item['custom_product_option_price']);
            $cart_item['data']->set_shipping_class_id($cart_item['custom_product_option_shipping_class']);
        }

        return $cart_item;
    }

    /* ── Order Meta ──────────────────────────────────────────────────── */

    /**
     * Save vendor data to order line item meta.
     *
     * Uses the same meta keys as g-FFL Cockpit for compatibility:
     * - Vendor
     * - _SKU
     * - _Price
     * - _ShippingClass
     *
     * @param \WC_Order_Item_Product $item           Order item.
     * @param string                 $cart_item_key   Cart item key.
     * @param array                  $values          Cart item values.
     * @param \WC_Order              $order           The order.
     */
    public static function save_vendor_to_order($item, string $cart_item_key, array $values, $order): void
    {
        $product_id = $item->get_product_id();

        if (!FFL_Checkout_Vendor_Api::is_eligible($product_id)) {
            return;
        }

        if (!isset($values['custom_product_option'])) {
            return;
        }

        $item->add_meta_data('Vendor', $values['custom_product_option'], true);
        $item->add_meta_data('_SKU', $values['custom_product_option_sku'] ?? '', true);
        $item->add_meta_data('_Price', $values['custom_product_option_price'] ?? '', true);

        // Resolve shipping class ID → name for the order meta.
        $shipping_class_id = $values['custom_product_option_shipping_class'] ?? 0;
        if ($shipping_class_id) {
            $term = get_term($shipping_class_id, 'product_shipping_class');
            if (!is_wp_error($term) && !empty($term)) {
                $item->add_meta_data('_ShippingClass', $term->name, true);
            }
        }
    }
}
