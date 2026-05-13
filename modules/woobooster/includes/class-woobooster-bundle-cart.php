<?php
/**
 * WooBooster Bundle Cart.
 *
 * Adds bundle items to the cart in a single hash-bound group and applies
 * the bundle discount only when the full original set is still present.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Bundle_Cart
{
    /**
     * Cart-item meta keys.
     */
    const META_BUNDLE_ID       = '_woobooster_bundle_id';
    const META_BUNDLE_HASH     = '_woobooster_bundle_hash';
    const META_BUNDLE_SIZE     = '_woobooster_bundle_size';
    const META_PRICE_SNAPSHOT  = '_woobooster_bundle_price_snapshot';
    const META_SOURCE_PRODUCT  = '_woobooster_bundle_source_product_id';

    public static function init()
    {
        add_action('wp_ajax_woobooster_add_bundle_to_cart', array(__CLASS__, 'ajax_add_bundle_to_cart'));
        add_action('wp_ajax_nopriv_woobooster_add_bundle_to_cart', array(__CLASS__, 'ajax_add_bundle_to_cart'));

        add_action('woocommerce_cart_calculate_fees', array(__CLASS__, 'apply_bundle_discounts'));

        // Make each bundle line uniquely identifiable so the same product added
        // outside the bundle is not merged with the bundle line.
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'preserve_bundle_meta'), 10, 2);
    }

    /**
     * Prevent WC from merging bundle cart items with non-bundle items.
     */
    public static function preserve_bundle_meta($cart_item_data, $product_id)
    {
        if (!empty($cart_item_data[self::META_BUNDLE_HASH])) {
            $cart_item_data['unique_key'] = $cart_item_data[self::META_BUNDLE_HASH] . '_' . $product_id;
        }
        return $cart_item_data;
    }

    /**
     * AJAX: Add bundle products to cart.
     */
    public static function ajax_add_bundle_to_cart()
    {
        check_ajax_referer('woobooster_bundle_cart', 'nonce');

        $bundle_id          = isset($_POST['bundle_id']) ? absint($_POST['bundle_id']) : 0;
        $source_product_id  = isset($_POST['source_product_id']) ? absint($_POST['source_product_id']) : 0;
        $product_ids        = isset($_POST['product_ids']) ? array_map('absint', (array) $_POST['product_ids']) : array();
        $product_ids        = array_values(array_unique(array_filter($product_ids)));

        if (!$bundle_id || empty($product_ids)) {
            wp_send_json_error(array('message' => __('Invalid bundle or no products selected.', 'ffl-funnels-addons')));
        }

        $bundle = WooBooster_Bundle::get($bundle_id);
        if (!$bundle || !$bundle->status) {
            wp_send_json_error(array('message' => __('Bundle not found or inactive.', 'ffl-funnels-addons')));
        }

        // Source product fallback: first checkbox if not sent explicitly.
        if (!$source_product_id) {
            $source_product_id = $product_ids[0];
        }

        $matcher        = new WooBooster_Bundle_Matcher();
        $resolved_items = $matcher->resolve_bundle_items($bundle, $source_product_id);
        $resolved_items = array_map('absint', $resolved_items);

        $product_ids = array_values(array_intersect($product_ids, $resolved_items));
        if (empty($product_ids)) {
            wp_send_json_error(array('message' => __('Selected products do not belong to this bundle.', 'ffl-funnels-addons')));
        }

        // Compute authoritative price snapshots server-side.
        $price_map = WooBooster_Bundle::calculate_item_prices($bundle, $product_ids);

        // Static item quantities (dynamic items default to qty 1).
        $qty_map      = array();
        $static_items = WooBooster_Bundle::get_items($bundle_id);
        foreach ($static_items as $static) {
            $qty_map[absint($static->product_id)] = isset($static->quantity) ? max(1, (int) $static->quantity) : 1;
        }

        // Cryptographically unique hash for this add-to-cart action.
        $bundle_hash = wp_generate_password(16, false, false);
        $size        = count($product_ids);

        $added  = 0;
        $errors = array();

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
                $errors[] = sprintf(
                    /* translators: %d: product ID */
                    __('Product #%d is not available.', 'ffl-funnels-addons'),
                    $pid
                );
                continue;
            }

            list($add_id, $variation_id, $variation_attrs) = self::resolve_purchasable($product);
            if (!$add_id) {
                $errors[] = sprintf(
                    /* translators: %s: product name */
                    __('"%s" requires choosing a variation before it can be bundled.', 'ffl-funnels-addons'),
                    $product->get_name()
                );
                continue;
            }

            $snapshot = isset($price_map[$pid]) ? $price_map[$pid] : array(
                'original'   => (float) $product->get_price(),
                'discounted' => (float) $product->get_price(),
            );

            $cart_item_data = array(
                self::META_BUNDLE_ID      => $bundle_id,
                self::META_BUNDLE_HASH    => $bundle_hash,
                self::META_BUNDLE_SIZE    => $size,
                self::META_PRICE_SNAPSHOT => $snapshot,
                self::META_SOURCE_PRODUCT => $source_product_id,
            );

            $qty    = isset($qty_map[$pid]) ? $qty_map[$pid] : 1;
            $result = WC()->cart->add_to_cart($add_id, $qty, $variation_id, $variation_attrs, $cart_item_data);

            if ($result) {
                $added++;
            } else {
                $errors[] = sprintf(
                    /* translators: %d: product ID */
                    __('Could not add product #%d to cart.', 'ffl-funnels-addons'),
                    $pid
                );
            }
        }

        if ($added === 0) {
            wp_send_json_error(array(
                'message' => __('No products could be added to cart.', 'ffl-funnels-addons'),
                'errors'  => $errors,
            ));
        }

        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        $data = array(
            'message'   => sprintf(
                /* translators: %d: number of products added */
                _n('%d product added to cart.', '%d products added to cart.', $added, 'ffl-funnels-addons'),
                $added
            ),
            'added'     => $added,
            'fragments' => apply_filters('woocommerce_add_to_cart_fragments', array(
                'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
            )),
            'cart_hash' => WC()->cart->get_cart_hash(),
        );

        if (!empty($errors)) {
            $data['errors'] = $errors;
        }

        wp_send_json_success($data);
    }

    /**
     * Pick the actual purchasable ID for a product.
     *
     * For variable products, falls back to the default variation when one
     * is configured. Returns [add_id, variation_id, variation_attrs].
     */
    private static function resolve_purchasable($product)
    {
        if (!$product->is_type('variable')) {
            return array($product->get_id(), 0, array());
        }

        $defaults = $product->get_default_attributes();
        if (empty($defaults)) {
            return array(0, 0, array());
        }

        $data_store    = WC_Data_Store::load('product');
        $variation_id  = $data_store->find_matching_product_variation($product, array_combine(
            array_map(function ($k) { return 'attribute_' . $k; }, array_keys($defaults)),
            array_values($defaults)
        ));

        if (!$variation_id) {
            return array(0, 0, array());
        }

        $variation_attrs = array();
        foreach ($defaults as $key => $value) {
            $variation_attrs['attribute_' . $key] = $value;
        }

        return array($product->get_id(), $variation_id, $variation_attrs);
    }

    /**
     * Apply bundle discounts as negative fees when the full bundle set is intact.
     *
     * @param WC_Cart $cart The WooCommerce cart.
     */
    public static function apply_bundle_discounts($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $by_hash = array();

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item[self::META_BUNDLE_HASH])) {
                continue;
            }
            $hash = (string) $cart_item[self::META_BUNDLE_HASH];
            if (!isset($by_hash[$hash])) {
                $by_hash[$hash] = array(
                    'bundle_id' => absint($cart_item[self::META_BUNDLE_ID] ?? 0),
                    'size'      => absint($cart_item[self::META_BUNDLE_SIZE] ?? 0),
                    'items'     => array(),
                );
            }
            $by_hash[$hash]['items'][] = $cart_item;
        }

        if (empty($by_hash)) {
            return;
        }

        foreach ($by_hash as $hash => $group) {
            // Skip incomplete bundles (customer removed at least one item).
            if ($group['size'] && count($group['items']) < $group['size']) {
                continue;
            }

            $bundle = WooBooster_Bundle::get($group['bundle_id']);
            if (!$bundle || !$bundle->status) {
                continue;
            }
            if ('none' === $bundle->discount_type || empty($bundle->discount_value)) {
                continue;
            }

            $discount = 0.0;
            foreach ($group['items'] as $cart_item) {
                $snapshot = $cart_item[self::META_PRICE_SNAPSHOT] ?? null;
                if (!is_array($snapshot) || !isset($snapshot['original'], $snapshot['discounted'])) {
                    // No trustworthy snapshot — bail rather than guess.
                    continue 2;
                }
                $qty       = (int) $cart_item['quantity'];
                $discount += max(0.0, ((float) $snapshot['original'] - (float) $snapshot['discounted']) * $qty);
            }

            if ($discount <= 0) {
                continue;
            }

            $fee_name = sprintf(
                /* translators: 1: bundle name, 2: short hash */
                __('Bundle Discount: %1$s (#%2$s)', 'ffl-funnels-addons'),
                $bundle->name,
                substr($hash, 0, 4)
            );
            $cart->add_fee($fee_name, -$discount, false);
        }
    }
}
