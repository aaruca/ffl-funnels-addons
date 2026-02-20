<?php
/**
 * WooBooster Frontend — WooCommerce Hook Fallback.
 *
 * Renders recommendations via standard WooCommerce hooks for non-Bricks themes.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Frontend
{

    /**
     * Initialize frontend hooks.
     */
    public function init()
    {
        // Always replace WooCommerce's default related products with
        // WooBooster's engine. If Bricks renders the product template,
        // this hook simply never fires. If a classic/block theme is used,
        // WooBooster takes over and falls back to WooCommerce related
        // products when no rule matches.
        remove_action('woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20);
        add_action('woocommerce_after_single_product_summary', array($this, 'render_recommendations'), 20);

        // Track recently viewed products in a cookie.
        $options = get_option('woobooster_settings', array());
        if (!empty($options['smart_recently_viewed'])) {
            add_action('template_redirect', array($this, 'track_recently_viewed'));
        }
    }

    /**
     * Track the current product in a "recently viewed" cookie.
     */
    public function track_recently_viewed()
    {
        if (!is_singular('product')) {
            return;
        }

        $product_id = get_the_ID();
        if (!$product_id) {
            return;
        }

        $max_items = 20;
        $viewed = array();

        if (isset($_COOKIE['woobooster_recently_viewed'])) {
            $raw = sanitize_text_field(wp_unslash($_COOKIE['woobooster_recently_viewed']));
            $viewed = array_filter(array_map('absint', explode(',', $raw)));
        }

        // Remove current product if already in the list, then prepend it.
        $viewed = array_diff($viewed, array($product_id));
        array_unshift($viewed, $product_id);
        $viewed = array_slice($viewed, 0, $max_items);

        // Set cookie for 30 days, site-wide.
        setcookie(
            'woobooster_recently_viewed',
            implode(',', $viewed),
            time() + (30 * DAY_IN_SECONDS),
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            false // Not HttpOnly — needs to be readable by JS if needed.
        );
    }

    /**
     * Render recommendations on the single product page.
     */
    public function render_recommendations()
    {
        if ('1' !== woobooster_get_option('enabled', '1')) {
            $this->render_woo_fallback();
            return;
        }

        global $product;
        $product_id = 0;

        if ($product && is_a($product, 'WC_Product')) {
            $product_id = $product->get_id();
        } elseif (is_singular('product')) {
            $product_id = get_the_ID();
        }

        if (!$product_id) {
            return;
        }

        $matcher = new WooBooster_Matcher();
        $product_ids = $matcher->get_recommendations($product_id);

        if (empty($product_ids)) {
            $this->render_woo_fallback();
            return;
        }

        // Register recommendation context for analytics tracking.
        if (WooBooster_Matcher::$last_matched_rule) {
            WooBooster_Tracker::register_recommendation(
                WooBooster_Matcher::$last_matched_rule->id,
                $product_ids
            );
        }

        $section_title = woobooster_get_option('section_title', __('You May Also Like', 'woobooster'));
        $columns = min(count($product_ids), 4);

        echo '<section class="woobooster-related products">';
        echo '<h2>' . esc_html($section_title) . '</h2>';
        echo '<ul class="products columns-' . esc_attr($columns) . '">';

        $original_post = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;

        foreach ($product_ids as $pid) {
            $post_obj = get_post($pid);
            if (!$post_obj) {
                continue;
            }
            $GLOBALS['post'] = $post_obj;
            setup_postdata($post_obj);
            wc_get_template_part('content', 'product');
        }

        wp_reset_postdata();
        if ($original_post) {
            $GLOBALS['post'] = $original_post;
        }

        echo '</ul></section>';
    }

    /**
     * Render WooCommerce default related products as fallback.
     */
    private function render_woo_fallback()
    {
        woocommerce_related_products(array(
            'posts_per_page' => 4,
            'columns' => 4,
            'orderby' => 'rand',
        ));
    }
}
