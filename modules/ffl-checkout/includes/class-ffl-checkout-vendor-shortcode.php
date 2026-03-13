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

        $settings = get_option('ffl_checkout_settings', []);
        if (($settings['vendor_selector_enabled'] ?? '0') !== '1') {
            return '';
        }

        $cart_items = WC()->cart->get_cart();
        if (empty($cart_items)) {
            return '';
        }

        ob_start();
        $has_eligible = false;

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
            if (is_wp_error($options) || empty($options)) {
                continue;
            }

            $product          = wc_get_product($product_id);
            $product_name     = $product ? $product->get_name() : '#' . $product_id;
            $current_vendor   = $cart_item['custom_product_option'] ?? '';
            $current_distid   = '';

            // Determine the current distributor ID from SKU.
            $sku = $product ? $product->get_sku() : '';
            if (!empty($sku)) {
                $parts = explode('|', $sku);
                $current_distid = $parts[0] ?? '';
            }

            $has_eligible = true;

            self::render_item_selector($cart_key, $product_name, $options, $current_vendor, $current_distid);
        }

        if (!$has_eligible) {
            return '';
        }

        return '<div class="ffl-vendor-selector" id="ffl-vendor-selector">' . ob_get_clean() . '</div>';
    }

    /* ── Render Single Item ──────────────────────────────────────────── */

    /**
     * Render the vendor selection table for a single cart item.
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
                $has_selection = false;
                foreach ($options as $index => $option) {
                    $warehouse_id   = $option['warehouse_id'] ?? '';
                    $distid         = $option['distid'] ?? '';
                    $option_sku     = $option['sku'] ?? '';
                    $option_price   = $option['price'] ?? 0;
                    $option_qty     = $option['qty'] ?? 0;
                    $shipping_class = $option['shipping_class'] ?? '';

                    // Determine checked state.
                    $is_checked = false;
                    if (!empty($current_vendor) && $current_vendor === $warehouse_id) {
                        $is_checked = true;
                    } elseif (empty($current_vendor) && !empty($current_distid) && $distid === $current_distid) {
                        $is_checked = true;
                    }

                    if ($is_checked) {
                        $has_selection = true;
                    }
                    ?>
                    <tr>
                        <td class="ffl-vendor-selector__radio">
                            <input type="radio"
                                   name="ffl_vendor_<?php echo esc_attr($cart_key); ?>"
                                   value="<?php echo esc_attr($warehouse_id); ?>"
                                   data-price="<?php echo esc_attr($option_price); ?>"
                                   data-sku="<?php echo esc_attr($option_sku); ?>"
                                   data-shipping-class="<?php echo esc_attr($shipping_class); ?>"
                                   <?php checked($is_checked); ?>>
                        </td>
                        <td><?php echo esc_html('Vendor ' . $warehouse_id); ?></td>
                        <td class="ffl-vendor-selector__stock"><?php echo esc_html($option_qty); ?></td>
                        <td class="ffl-vendor-selector__price">$<?php echo esc_html(number_format((float) $option_price, 2)); ?></td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
            <?php
            // If no option matched, auto-select the first one.
            if (!$has_selection && !empty($options)) {
                ?>
                <script>
                    (function() {
                        var radios = document.querySelectorAll('input[name="ffl_vendor_<?php echo esc_js($cart_key); ?>"]');
                        if (radios.length > 0) radios[0].checked = true;
                    })();
                </script>
                <?php
            }
            ?>
            <div class="ffl-vendor-selector__loading" style="display:none;">
                <?php esc_html_e('Updating...', 'ffl-funnels-addons'); ?>
            </div>
        </div>
        <?php
    }
}
