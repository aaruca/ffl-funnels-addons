<?php
/**
 * GA4 Bridge Module — entry point.
 *
 * Restores two GA4 ecommerce events for stores running "Google Analytics for
 * WooCommerce" with the Bricks theme and the Merchant AJAX side-cart:
 *
 * - view_item: Bricks replaces the single-product template and fires neither
 *   woocommerce_before_single_product (product data) nor
 *   woocommerce_after_single_product (the event). Both are re-fired, guarded,
 *   from the one standard hook Bricks does emit inside product content.
 * - add_to_cart: the GA plugin discards the AJAX payload unless the store
 *   redirects after add. It is persisted to the WC session instead, and the
 *   plugin's own restore path emits it on the next pageview.
 *
 * Deliberately does NOT touch purchase, begin_checkout, or view_item_list —
 * they already work, and re-emitting them would double-count revenue.
 *
 * Known limitations (by design — do not "fix"):
 * - view_item requires an add-to-cart form; out-of-stock products won't fire it.
 * - add_to_cart is emitted on the NEXT pageview, not instantly.
 * - view_cart is not supported by Google Analytics for WooCommerce at all.
 * - Only one GA4 tag per site: Site Kit's Analytics module must stay disconnected.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ga4_Bridge_Module extends FFLA_Module
{
    /**
     * WC_Abstract_Google_Analytics_JS::PENDING_ADDED_TO_CART_SESSION_KEY —
     * the key its restore_added_to_cart_from_session() re-emits from.
     */
    private const PENDING_SESSION_KEY = '_ga_pending_added_to_cart';

    public function get_id(): string
    {
        return 'ga4-bridge';
    }

    public function get_name(): string
    {
        return __('GA4 Bridge', 'ffl-funnels-addons');
    }

    public function get_description(): string
    {
        return __('Restores GA4 view_item on Bricks product pages and add_to_cart with an AJAX side-cart, for Google Analytics for WooCommerce.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-6 3 4 5-8"/></svg>';
    }

    public function boot(): void
    {
        // Both callbacks guard on WC_Google_Gtag_JS themselves. The class is
        // loaded late (via WooCommerce's integrations), so checking here at
        // init:0 could wrongly disable the module for the whole request.
        add_action('woocommerce_after_add_to_cart_form', [$this, 'fire_view_item_hooks'], 99);
        add_action('woocommerce_add_to_cart', [$this, 'persist_added_to_cart'], 99, 5);
    }

    public function activate(): void
    {
        // Nothing to set up; the module stores no data of its own.
    }

    public function deactivate(): void
    {
        // Nothing to tear down.
    }

    public function get_admin_pages(): array
    {
        return [];
    }

    public function render_admin_page(string $page_slug): void
    {
        // No settings page.
    }

    /**
     * view_item needs woocommerce_before_single_product (data) and
     * woocommerce_after_single_product (event), in that order, before the
     * tracker prints at wp_footer:10. This anchor runs inside the product
     * content with the correct global $product, so both are fired here —
     * did_action() guards make double-firing impossible on themes that do
     * emit them.
     */
    public function fire_view_item_hooks(): void
    {
        if (!class_exists('WC_Google_Gtag_JS')) {
            return;
        }

        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        global $product;
        if (!$product instanceof WC_Product) {
            $product = wc_get_product(get_the_ID());
        }
        if (!$product instanceof WC_Product) {
            return;
        }

        if (!did_action('woocommerce_before_single_product')) {
            do_action('woocommerce_before_single_product');
        }
        if (!did_action('woocommerce_after_single_product')) {
            do_action('woocommerce_after_single_product');
        }
    }

    /**
     * The GA plugin only persists an AJAX add-to-cart payload when
     * woocommerce_cart_redirect_after_add is 'yes'. With a side-cart (no
     * redirect) the payload dies with the request — so persist it into the
     * session here and let the plugin's own restore path emit it next
     * pageview, formatted by its own get_formatted_product() so item data
     * stays consistent with every other event.
     *
     * @param string $cart_item_key
     * @param int    $product_id
     * @param int    $quantity
     * @param int    $variation_id
     * @param array  $variation
     */
    public function persist_added_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation): void
    {
        try {
            if (!wp_doing_ajax()) {
                return;
            }

            if (!class_exists('WC_Google_Gtag_JS') || !function_exists('WC') || !WC()->session) {
                return;
            }

            // An unconsumed payload is already queued; keep it (matches the
            // upstream plugin's own single-payload behaviour).
            if (WC()->session->get(self::PENDING_SESSION_KEY)) {
                return;
            }

            $instance = WC_Google_Gtag_JS::get_instance();
            if (!$instance) {
                return;
            }

            $product = wc_get_product($product_id);
            if (!$product instanceof WC_Product) {
                return;
            }

            $formatted = $instance->get_formatted_product($product, $variation_id, $variation, $quantity);
            WC()->session->set(self::PENDING_SESSION_KEY, $formatted);
        } catch (\Throwable $e) {
            // Analytics must never break add-to-cart.
            return;
        }
    }
}
