<?php
/**
 * MonsterInsights Compatibility — entry point.
 *
 * MonsterInsights remains the analytics owner: it loads the Google tag and
 * records checkout, purchases, and refunds. This optional module only restores
 * storefront events that custom Bricks product templates and AJAX side-carts can prevent WooCommerce
 * integrations from seeing. Every JavaScript fallback first checks the shared
 * dataLayer, so it does not duplicate an event MonsterInsights already sent.
 *
 * Backward compatibility for Google Analytics for WooCommerce is retained for
 * stores that have not migrated yet, but no second Google tag is ever loaded.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ga4_Bridge_Module extends FFLA_Module
{
    private const SCRIPT_HANDLE = 'ffla-monsterinsights-bridge';

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
        return __('MonsterInsights Compatibility', 'ffl-funnels-addons');
    }

    public function get_description(): string
    {
        return __('Optional compatibility for missing GA4 product views and AJAX add-to-cart events on Bricks and Merchant-powered WooCommerce stores.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-6 3 4 5-8"/></svg>';
    }

    public function boot(): void
    {
        add_action('woocommerce_after_add_to_cart_form', [$this, 'fire_view_item_hooks'], 99);
        add_action('woocommerce_add_to_cart', [$this, 'persist_added_to_cart'], 99, 5);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_monsterinsights_bridge'], 99);
        add_action('admin_notices', [$this, 'render_dependency_notice']);
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
        // MonsterInsights receives a narrow JavaScript fallback below. Re-firing
        // broad WooCommerce template hooks can repeat unrelated plugin output,
        // so this legacy hook repair is restricted to the old integration.
        if ($this->is_monsterinsights_ready() || !class_exists('WC_Google_Gtag_JS')) {
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
            // MonsterInsights is handled immediately in the browser. Keeping
            // the legacy payload as well would duplicate add_to_cart when both
            // integrations are temporarily present during a migration.
            if ($this->is_monsterinsights_ready()) {
                return;
            }

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

    /**
     * Add the small provider-aware fallback on product screens.
     * It never loads gtag.js; events are sent through MonsterInsights' tracker.
     */
    public function enqueue_monsterinsights_bridge(): void
    {
        if (!$this->is_monsterinsights_ready() || is_admin()) {
            return;
        }

        if (!function_exists('is_product') || !is_product()) {
            return;
        }

        $items   = [];
        $value   = 0.0;
        $product = wc_get_product(get_queried_object_id());
        if ($product instanceof WC_Product) {
            $items[] = $this->format_product_item($product, 1);
            $value   = $product->is_type('variable')
                ? (float) $product->get_variation_price('min', false)
                : (float) $product->get_price();
        }

        if (!$items) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            FFLA_URL . 'modules/ga4-bridge/assets/js/monsterinsights-bridge.js',
            ['jquery'],
            FFLA_VERSION,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'fflaMonsterInsightsBridge',
            [
                'measurementId' => $this->monsterinsights_measurement_id(),
                'currency'      => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
                'value'         => wc_format_decimal($value, wc_get_price_decimals()),
                'items'         => $items,
            ]
        );
    }

    /**
     * Explain a missing dependency without turning analytics into a hard site
     * dependency. The notice is limited to FFL Funnels screens.
     */
    public function render_dependency_notice(): void
    {
        if (!current_user_can('manage_woocommerce') || $this->is_monsterinsights_ready() || class_exists('WC_Google_Gtag_JS')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || false === strpos((string) $screen->id, 'ffl-funnels')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('MonsterInsights Compatibility is active, but MonsterInsights Pro with the eCommerce Addon and a GA4 connection was not detected. The module will not load a separate Google tag.', 'ffl-funnels-addons');
        echo '</p></div>';
    }

    /**
     * Determine whether MonsterInsights owns a usable GA4 tracker.
     */
    private function is_monsterinsights_ready(): bool
    {
        $core_loaded = defined('MONSTERINSIGHTS_VERSION')
            || defined('MONSTERINSIGHTS_PRO_VERSION')
            || function_exists('monsterinsights');

        return $core_loaded
            && class_exists('MonsterInsights_eCommerce')
            && '' !== $this->monsterinsights_measurement_id();
    }

    /**
     * Return a validated public GA4 measurement ID (never credentials).
     */
    private function monsterinsights_measurement_id(): string
    {
        if (!function_exists('monsterinsights_get_v4_id')) {
            return '';
        }

        $measurement_id = strtoupper(trim((string) monsterinsights_get_v4_id()));

        return preg_match('/^G-[A-Z0-9]+$/', $measurement_id) ? $measurement_id : '';
    }

    /**
     * Convert a WooCommerce product to the GA4 item fields used by the bridge.
     *
     * @return array<string, int|float|string>
     */
    private function format_product_item(WC_Product $product, int $quantity): array
    {
        $taxonomy_product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $price               = $product->is_type('variable')
            ? (float) $product->get_variation_price('min', false)
            : (float) $product->get_price();

        $item = [
            'item_id'   => (string) $product->get_id(),
            'item_name' => wp_strip_all_tags($product->get_name()),
            'price'     => $price,
            'quantity'  => max(1, $quantity),
        ];

        $category_names = wp_get_post_terms($taxonomy_product_id, 'product_cat', ['fields' => 'names']);
        if (!is_wp_error($category_names)) {
            foreach (array_slice(array_values($category_names), 0, 5) as $index => $category_name) {
                $category_key        = 0 === $index ? 'item_category' : 'item_category' . ($index + 1);
                $item[$category_key] = sanitize_text_field((string) $category_name);
            }
        }

        if ($product->is_type('variation')) {
            $attributes = wc_get_formatted_variation($product, true, false, false);
            if ('' !== $attributes) {
                $item['item_variant'] = wp_strip_all_tags($attributes);
            }
        }

        foreach (['product_brand', 'pwb-brand'] as $brand_taxonomy) {
            if (!taxonomy_exists($brand_taxonomy)) {
                continue;
            }

            $brands = wp_get_post_terms($taxonomy_product_id, $brand_taxonomy, ['fields' => 'names']);
            if (!is_wp_error($brands) && !empty($brands[0])) {
                $item['item_brand'] = sanitize_text_field((string) $brands[0]);
                break;
            }
        }

        return $item;
    }
}
