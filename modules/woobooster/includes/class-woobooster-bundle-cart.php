<?php
/**
 * WooBooster Bundle Cart.
 *
 * Adds a bundle to the cart as a single synthetic line item: one representative
 * product carries the whole bundle price, and the contained products are stored
 * as cart-item meta. Removing the line removes the entire bundle — it cannot be
 * split apart, so a bundle is always bought as a unit.
 *
 * Trade-off: WooCommerce only tracks stock/tax/shipping for the representative
 * product. The other bundled products are recorded as meta, not as their own
 * stock-managed line items.
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
    const META_BUNDLE_ID      = '_woobooster_bundle_id';
    const META_BUNDLE_HASH    = '_woobooster_bundle_hash';
    const META_BUNDLE_ITEMS   = '_woobooster_bundle_items';
    const META_BUNDLE_TOTAL   = '_woobooster_bundle_total';
    const META_SOURCE_PRODUCT = '_woobooster_bundle_source_product_id';

    public static function init()
    {
        add_action('wp_ajax_woobooster_add_bundle_to_cart', array(__CLASS__, 'ajax_add_bundle_to_cart'));
        add_action('wp_ajax_nopriv_woobooster_add_bundle_to_cart', array(__CLASS__, 'ajax_add_bundle_to_cart'));

        // Price the synthetic line at the stored bundle total.
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'set_bundle_price'), 20);

        // Keep each bundle add as its own line so it never merges with a plain
        // purchase of the representative product (or another bundle).
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'preserve_bundle_meta'), 10, 2);

        // Cart / checkout display.
        add_filter('woocommerce_cart_item_name', array(__CLASS__, 'cart_item_name'), 10, 2);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'cart_item_data_display'), 10, 2);
        add_filter('woocommerce_cart_item_thumbnail', array(__CLASS__, 'cart_item_thumbnail'), 10, 3);

        // Persist bundle contents onto the order line item.
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'add_order_line_item_meta'), 10, 3);
    }

    /**
     * Give every bundle line a unique key so WooCommerce never merges it with
     * another cart item.
     */
    public static function preserve_bundle_meta($cart_item_data, $product_id)
    {
        if (!empty($cart_item_data[self::META_BUNDLE_HASH])) {
            $cart_item_data['unique_key'] = $cart_item_data[self::META_BUNDLE_HASH];
        }
        return $cart_item_data;
    }

    /**
     * AJAX: Add a bundle to the cart as one synthetic line item.
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

        // The schedule is enforced for widget visibility, but a captured or
        // replayed request must not be able to redeem an expired (or not yet
        // started) bundle.
        if (!self::bundle_schedule_is_active($bundle)) {
            wp_send_json_error(array('message' => __('This bundle offer is not currently available.', 'ffl-funnels-addons')));
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

        // Validate that required items are included (for fixed pricing, all items usually required).
        $bundle_items = WooBooster_Bundle::get_items($bundle->id);
        $required_ids = array_map(
            fn($item) => absint($item->product_id),
            array_filter($bundle_items, fn($item) => !empty($item->required))
        );
        $missing_required = array_diff($required_ids, $product_ids);
        if (!empty($missing_required)) {
            wp_send_json_error(array('message' => __('Bundle requires all items to be selected.', 'ffl-funnels-addons')));
        }

        // Compute authoritative price snapshots server-side using the FULL bundle.
        // For fixed pricing, this ensures the target price is distributed across the complete set.
        $full_price_map = WooBooster_Bundle::calculate_item_prices($bundle, $resolved_items);

        // Extract prices for only the selected items.
        $price_map = array_intersect_key($full_price_map, array_flip($product_ids));

        // Static item quantities (dynamic items default to qty 1).
        $qty_map      = array();
        $static_items = WooBooster_Bundle::get_items($bundle_id);
        foreach ($static_items as $static) {
            $qty_map[absint($static->product_id)] = isset($static->quantity) ? max(1, (int) $static->quantity) : 1;
        }

        $items  = array();
        $total  = 0.0;
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

            $qty = isset($qty_map[$pid]) ? $qty_map[$pid] : 1;

            $items[] = array(
                'product_id'   => $pid,
                'variation_id' => $variation_id,
                'variation'    => $variation_attrs,
                'quantity'     => $qty,
                'name'         => $product->get_name(),
                'original'     => (float) $snapshot['original'],
                'discounted'   => (float) $snapshot['discounted'],
            );
            $total += (float) $snapshot['discounted'] * $qty;
        }

        if (empty($items)) {
            wp_send_json_error(array(
                'message' => __('No products could be added to cart.', 'ffl-funnels-addons'),
                'errors'  => $errors,
            ));
        }

        // The first item is the representative product WooCommerce attaches the
        // line to (its thumbnail, stock and tax class drive the line).
        $representative_id        = $items[0]['product_id'];
        $representative_variation = $items[0]['variation_id'];
        $representative_attrs     = $items[0]['variation'];

        // Cryptographically unique key for this bundle line.
        $bundle_hash = wp_generate_password(16, false, false);

        $cart_item_data = array(
            self::META_BUNDLE_ID      => $bundle_id,
            self::META_BUNDLE_HASH    => $bundle_hash,
            self::META_BUNDLE_ITEMS   => $items,
            self::META_BUNDLE_TOTAL   => $total,
            self::META_SOURCE_PRODUCT => $source_product_id,
        );

        $result = WC()->cart->add_to_cart(
            $representative_id,
            1,
            $representative_variation,
            $representative_attrs,
            $cart_item_data
        );

        if (!$result) {
            wp_send_json_error(array(
                'message' => __('Could not add the bundle to cart.', 'ffl-funnels-addons'),
                'errors'  => $errors,
            ));
        }

        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        $data = array(
            'message'   => __('Bundle added to cart.', 'ffl-funnels-addons'),
            'added'     => count($items),
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
     * Price the synthetic bundle line at the stored bundle total.
     *
     * set_price() is the per-unit price; WooCommerce multiplies by the line
     * quantity, so increasing the quantity buys multiple whole bundles.
     *
     * @param WC_Cart $cart The WooCommerce cart.
     */
    public static function set_bundle_price($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $schedule_cache = array();

        foreach ($cart->get_cart() as $cart_item) {
            if (!isset($cart_item[self::META_BUNDLE_TOTAL]) || empty($cart_item['data']) || !is_object($cart_item['data'])) {
                continue;
            }

            // Don't honour the stored bundle price once the bundle is disabled or
            // its scheduled window has passed — the line may have been sitting in
            // the cart (or a persistent session) since before it expired.
            $bundle_id = isset($cart_item[self::META_BUNDLE_ID]) ? absint($cart_item[self::META_BUNDLE_ID]) : 0;
            if ($bundle_id) {
                if (!array_key_exists($bundle_id, $schedule_cache)) {
                    $bundle = WooBooster_Bundle::get($bundle_id);
                    $schedule_cache[$bundle_id] = ($bundle && $bundle->status && self::bundle_schedule_is_active($bundle));
                }
                if (!$schedule_cache[$bundle_id]) {
                    continue;
                }
            }

            $cart_item['data']->set_price((float) $cart_item[self::META_BUNDLE_TOTAL]);
        }
    }

    /**
     * Whether a bundle is inside its scheduled window.
     *
     * start_date/end_date are stored as GMT (the admin form converts via
     * get_gmt_from_date), so compare against GMT "now" — the same clock the
     * matcher uses. An empty bound means "unbounded".
     *
     * @param object $bundle Bundle row.
     * @return bool
     */
    private static function bundle_schedule_is_active($bundle)
    {
        $now = current_time('mysql', true);

        if (!empty($bundle->start_date) && $now < $bundle->start_date) {
            return false;
        }
        if (!empty($bundle->end_date) && $now > $bundle->end_date) {
            return false;
        }

        return true;
    }

    /**
     * Show the bundle name instead of the representative product's name.
     */
    public static function cart_item_name($name, $cart_item)
    {
        if (empty($cart_item[self::META_BUNDLE_ID])) {
            return $name;
        }

        $bundle = WooBooster_Bundle::get((int) $cart_item[self::META_BUNDLE_ID]);
        if ($bundle && !empty($bundle->name)) {
            return esc_html($bundle->name);
        }

        return $name;
    }

    /**
     * List the bundled products beneath the cart line.
     *
     * Each item gets its own block with padding and a faint bottom border so
     * the list reads cleanly in both the mini-cart and the checkout review
     * column. Plain-text `value` is preserved for emails / order details that
     * strip HTML.
     */
    public static function cart_item_data_display($item_data, $cart_item)
    {
        if (empty($cart_item[self::META_BUNDLE_ITEMS]) || !is_array($cart_item[self::META_BUNDLE_ITEMS])) {
            return $item_data;
        }

        $lines      = array();
        $html_lines = array();
        foreach ($cart_item[self::META_BUNDLE_ITEMS] as $bi) {
            if (empty($bi['name'])) {
                continue;
            }
            $qty   = isset($bi['quantity']) ? max(1, (int) $bi['quantity']) : 1;
            $label = ($qty > 1 ? $qty . '× ' : '') . $bi['name'];

            $lines[]      = $label;
            $html_lines[] = '<li style="padding:4px 0;border-bottom:1px solid rgba(0,0,0,0.06);">' . esc_html($label) . '</li>';
        }

        if (!empty($html_lines)) {
            // Drop the bottom border on the last item so the list ends cleanly.
            $last_index = count($html_lines) - 1;
            $html_lines[$last_index] = str_replace('border-bottom:1px solid rgba(0,0,0,0.06);', '', $html_lines[$last_index]);

            $display = '<ul class="woobooster-bundle-includes" style="margin:4px 0 0;padding:0;list-style:none;font-size:0.9em;">' . implode('', $html_lines) . '</ul>';
            $item_data[] = array(
                'key'     => __('Includes', 'ffl-funnels-addons'),
                'value'   => implode(', ', $lines),
                'display' => $display,
            );
        }

        return $item_data;
    }

    /**
     * Swap the cart line's thumbnail with the bundle's own image (when set).
     * Falls back to WooCommerce's default thumbnail (representative product)
     * when no image is configured.
     *
     * @param string $thumbnail Default thumbnail HTML.
     * @param array  $cart_item Cart item data.
     * @param string $cart_item_key Cart item key (unused but part of the filter signature).
     * @return string
     */
    public static function cart_item_thumbnail($thumbnail, $cart_item, $cart_item_key)
    {
        if (empty($cart_item[self::META_BUNDLE_ID])) {
            return $thumbnail;
        }

        $bundle = WooBooster_Bundle::get((int) $cart_item[self::META_BUNDLE_ID]);
        if (!$bundle || empty($bundle->image_id)) {
            return $thumbnail;
        }

        $custom = wp_get_attachment_image(
            (int) $bundle->image_id,
            'woocommerce_thumbnail',
            false,
            array('class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail')
        );

        return $custom ?: $thumbnail;
    }

    /**
     * Persist bundle contents onto the order line item so the order record
     * shows what was inside the bundle.
     *
     * @param WC_Order_Item_Product $item          Order line item.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values        Cart item values.
     */
    public static function add_order_line_item_meta($item, $cart_item_key, $values)
    {
        if (empty($values[self::META_BUNDLE_ID])) {
            return;
        }

        $item->add_meta_data('_woobooster_bundle_id', (int) $values[self::META_BUNDLE_ID], true);

        if (!empty($values[self::META_BUNDLE_ITEMS]) && is_array($values[self::META_BUNDLE_ITEMS])) {
            $lines = array();
            foreach ($values[self::META_BUNDLE_ITEMS] as $bi) {
                if (empty($bi['name'])) {
                    continue;
                }
                $qty     = isset($bi['quantity']) ? max(1, (int) $bi['quantity']) : 1;
                $lines[] = ($qty > 1 ? $qty . '× ' : '') . $bi['name'];
            }
            if (!empty($lines)) {
                $item->add_meta_data(__('Bundle contents', 'ffl-funnels-addons'), implode(', ', $lines), true);
            }
        }
    }
}
