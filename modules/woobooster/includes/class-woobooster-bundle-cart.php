<?php
/**
 * WooBooster Bundle Cart.
 *
 * Handles adding bundle items to cart via AJAX and applying
 * bundle discounts as negative fees.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Bundle_Cart
{
    /**
     * Initialize hooks.
     */
    public static function init()
    {
        // AJAX: Add bundle to cart (logged-in + guest).
        add_action('wp_ajax_woobooster_add_bundle_to_cart', array(__CLASS__, 'ajax_add_bundle_to_cart'));
        add_action('wp_ajax_nopriv_woobooster_add_bundle_to_cart', array(__CLASS__, 'ajax_add_bundle_to_cart'));

        // Apply bundle discount as negative fee.
        add_action('woocommerce_cart_calculate_fees', array(__CLASS__, 'apply_bundle_discounts'));
    }

    /**
     * AJAX: Add bundle products to cart.
     */
    public static function ajax_add_bundle_to_cart()
    {
        check_ajax_referer('woobooster_bundle_cart', 'nonce');

        $bundle_id   = isset($_POST['bundle_id']) ? absint($_POST['bundle_id']) : 0;
        $product_ids = isset($_POST['product_ids']) ? array_map('absint', (array) $_POST['product_ids']) : array();

        if (!$bundle_id || empty($product_ids)) {
            wp_send_json_error(array('message' => __('Invalid bundle or no products selected.', 'ffl-funnels-addons')));
        }

        // Verify bundle exists and is active.
        $bundle = WooBooster_Bundle::get($bundle_id);
        if (!$bundle || !$bundle->status) {
            wp_send_json_error(array('message' => __('Bundle not found or inactive.', 'ffl-funnels-addons')));
        }

        // Verify the products belong to this bundle's resolved items.
        $matcher = new WooBooster_Bundle_Matcher();
        // Get any product to resolve items (use first product as context).
        $resolved_items = $matcher->resolve_bundle_items($bundle, $product_ids[0]);

        // Generate a unique hash for this add-to-cart action.
        $bundle_hash = md5($bundle_id . '_' . implode('_', $product_ids) . '_' . time());

        $added = 0;
        $errors = array();

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
                $errors[] = sprintf(__('Product #%d is not available.', 'ffl-funnels-addons'), $pid);
                continue;
            }

            $cart_item_data = array(
                '_woobooster_bundle_id'   => $bundle_id,
                '_woobooster_bundle_hash' => $bundle_hash,
            );

            $result = WC()->cart->add_to_cart($pid, 1, 0, array(), $cart_item_data);

            if ($result) {
                $added++;
            } else {
                $errors[] = sprintf(__('Could not add product #%d to cart.', 'ffl-funnels-addons'), $pid);
            }
        }

        if ($added === 0) {
            wp_send_json_error(array(
                'message' => __('No products could be added to cart.', 'ffl-funnels-addons'),
                'errors'  => $errors,
            ));
        }

        // Return cart fragments for mini-cart update.
        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        $data = array(
            'message'    => sprintf(__('%d product(s) added to cart.', 'ffl-funnels-addons'), $added),
            'added'      => $added,
            'fragments'  => apply_filters('woocommerce_add_to_cart_fragments', array(
                'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
            )),
            'cart_hash'  => WC()->cart->get_cart_hash(),
        );

        if (!empty($errors)) {
            $data['errors'] = $errors;
        }

        wp_send_json_success($data);
    }

    /**
     * Apply bundle discounts as negative fees in the cart.
     *
     * @param WC_Cart $cart The WooCommerce cart.
     */
    public static function apply_bundle_discounts($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Group cart items by bundle.
        $bundle_items = array();

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['_woobooster_bundle_id'])) {
                continue;
            }

            $bundle_id = absint($cart_item['_woobooster_bundle_id']);
            if (!isset($bundle_items[$bundle_id])) {
                $bundle_items[$bundle_id] = array();
            }
            $bundle_items[$bundle_id][] = $cart_item;
        }

        if (empty($bundle_items)) {
            return;
        }

        foreach ($bundle_items as $bundle_id => $items) {
            $bundle = WooBooster_Bundle::get($bundle_id);
            if (!$bundle || !$bundle->status) {
                continue;
            }

            if ($bundle->discount_type === 'none' || empty($bundle->discount_value) || $bundle->discount_value <= 0) {
                continue;
            }

            $discount = 0;

            foreach ($items as $cart_item) {
                $product = $cart_item['data'];
                $price   = (float) $product->get_price();
                $qty     = (int) $cart_item['quantity'];

                if ($bundle->discount_type === 'percentage') {
                    $discount += ($price * $bundle->discount_value / 100) * $qty;
                } elseif ($bundle->discount_type === 'fixed') {
                    $per_item = min($bundle->discount_value, $price);
                    $discount += $per_item * $qty;
                }
            }

            if ($discount > 0) {
                $fee_name = sprintf(__('Bundle Discount: %s', 'ffl-funnels-addons'), $bundle->name);
                $cart->add_fee($fee_name, -$discount, false);
            }
        }
    }
}
