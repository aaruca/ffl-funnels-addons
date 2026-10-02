<?php
/**
 * FFL Checkout — Vendor Selector Shortcode.
 *
 * Provides the [ffl_vendor_selector] shortcode that renders a
 * vendor/warehouse selection table for each eligible cart item,
 * allowing the customer to change vendors at checkout.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Vendor_Shortcode
{
    /**
     * Register the shortcode.
     */
    public static function init(): void
    {
        add_shortcode('ffl_vendor_selector', [__CLASS__, 'render']);
    }

    /* ── Shortcode Callback ──────────────────────────────────────────── */

    /**
     * Render vendor selection tables for eligible cart items.
     *
     * @param array|string $atts Shortcode attributes (unused).
     * @return string HTML output.
     */
    public static function render($atts = []): string
    {
        if (!function_exists('WC') || !WC()->cart) {
            return '';
        }

        if (!FFL_Checkout_Vendor_Api::selector_enabled()) {
            return '';
        }

        $cart_items = WC()->cart->get_cart();
        if (empty($cart_items)) {
            return '';
        }

        $html = '';

        foreach ($cart_items as $cart_key => $cart_item) {
            $product_id = $cart_item['product_id'] ?? 0;

            if (!FFL_Checkout_Vendor_Api::is_eligible($product_id)) {
                continue;
            }

            $upc = FFL_Checkout_Vendor_Api::get_upc_for_product($product_id);
            if (empty($upc)) {
                continue;
            }

            $options = FFL_Checkout_Vendor_Api::get_warehouse_options($upc);
            if (is_wp_error($options) || empty($options) || !is_array($options)) {
                continue;
            }

            $product        = wc_get_product($product_id);
            $product_name   = $product ? $product->get_name() : '#' . $product_id;
            // Session values are strings; API values may be numbers. Compare as text.
            $current_vendor = isset($cart_item['custom_product_option']) ? (string) $cart_item['custom_product_option'] : '';
            $current_distid = '';

            // Determine the current distributor ID from SKU.
            $sku = $product ? (string) $product->get_sku() : '';
            if ($sku !== '') {
                $parts          = explode('|', $sku);
                $current_distid = (string) ($parts[0] ?? '');
            }

            ob_start();
            self::render_item_selector((string) $cart_key, $product_name, $options, $current_vendor, $current_distid);
            $html .= (string) ob_get_clean();
        }

        if ($html === '') {
            return '';
        }

        return '<div class="ffl-vendor-selector" id="ffl-vendor-selector">' . $html . '</div>';
    }

    /* ── Render Single Item ──────────────────────────────────────────── */

    /**
     * Render the vendor selection table for a single cart item.
     *
     * The option already chosen for the cart item is checked; otherwise the
     * one whose distributor ID matches the product SKU. When neither matches,
     * nothing is checked, because the cart still uses the original vendor
     * until the customer picks one.
     */
    private static function render_item_selector(
        string $cart_key,
        string $product_name,
        array $options,
        string $current_vendor,
        string $current_distid
    ): void {
        ?>
        <div class="ffl-vendor-selector__item" data-cart-key="<?php echo esc_attr($cart_key); ?>">
            <h4 class="ffl-vendor-selector__title"><?php echo esc_html($product_name); ?></h4>
            <table class="ffl-vendor-selector__table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Select', 'ffl-funnels-addons'); ?></th>
                        <th><?php esc_html_e('Vendor', 'ffl-funnels-addons'); ?></th>
                        <th><?php esc_html_e('Stock', 'ffl-funnels-addons'); ?></th>
                        <th><?php esc_html_e('Price', 'ffl-funnels-addons'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Find the one option to check first, so at most one is checked.
                $checked_index = null;
                foreach ($options as $index => $option) {
                    if (is_array($option) && $current_vendor !== '' && (string) ($option['warehouse_id'] ?? '') === $current_vendor) {
                        $checked_index = $index;
                        break;
                    }
                }
                if ($checked_index === null && $current_vendor === '' && $current_distid !== '') {
                    foreach ($options as $index => $option) {
                        if (is_array($option) && (string) ($option['distid'] ?? '') === $current_distid) {
                            $checked_index = $index;
                            break;
                        }
                    }
                }

                foreach ($options as $index => $option) {
                    if (!is_array($option)) {
                        continue;
                    }
                    $warehouse_id   = (string) ($option['warehouse_id'] ?? '');
                    $option_sku     = (string) ($option['sku'] ?? '');
                    $option_price   = (float) ($option['price'] ?? 0);
                    $option_qty     = (string) ($option['qty'] ?? '0');
                    $shipping_class = (string) ($option['shipping_class'] ?? '');
                    ?>
                    <tr>
                        <td class="ffl-vendor-selector__radio">
                            <input type="radio"
                                   name="ffl_vendor_<?php echo esc_attr($cart_key); ?>"
                                   value="<?php echo esc_attr($warehouse_id); ?>"
                                   data-price="<?php echo esc_attr((string) $option_price); ?>"
                                   data-sku="<?php echo esc_attr($option_sku); ?>"
                                   data-shipping-class="<?php echo esc_attr($shipping_class); ?>"
                                   <?php checked($index === $checked_index); ?>>
                        </td>
                        <td><?php
                            /* translators: %s: vendor/warehouse ID. */
                            echo esc_html(sprintf(__('Vendor %s', 'ffl-funnels-addons'), $warehouse_id));
                        ?></td>
                        <td class="ffl-vendor-selector__stock"><?php echo esc_html($option_qty); ?></td>
                        <td class="ffl-vendor-selector__price"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($option_price) : number_format($option_price, 2)); ?></td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
            <div class="ffl-vendor-selector__loading" style="display:none;">
                <?php esc_html_e('Updating...', 'ffl-funnels-addons'); ?>
            </div>
        </div>
        <?php
    }
}
