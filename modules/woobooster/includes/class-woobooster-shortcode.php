<?php
/**
 * WooBooster Shortcode.
 *
 * [woobooster product_id="123" limit="6" fallback="recent"]
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Shortcode
{

    /**
     * Register the shortcode.
     */
    public static function init()
    {
        add_shortcode('woobooster', array(__CLASS__, 'render'));
    }

    /**
     * Render the shortcode output.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function render($atts)
    {
        $atts = shortcode_atts(
            array(
                'product_id' => 0,
                'limit' => 0,
                'fallback' => 'none',
            ),
            $atts,
            'woobooster'
        );

        // Resolve product ID.
        $product_id = absint($atts['product_id']);
        if (!$product_id) {
            global $product;
            if ($product && is_a($product, 'WC_Product')) {
                $product_id = $product->get_id();
            } elseif (is_singular('product')) {
                $product_id = get_the_ID();
            }
        }

        if (!$product_id) {
            return '';
        }

        // Get recommendations.
        $matcher = new WooBooster_Matcher();
        $limit = $atts['limit'] ? absint($atts['limit']) : null;
        $product_ids = $matcher->get_recommendations($product_id, array(
            'limit' => $limit,
        ));

        // Register recommendation context for analytics tracking.
        if (!empty($product_ids) && WooBooster_Matcher::$last_matched_rule) {
            WooBooster_Tracker::register_recommendation(
                WooBooster_Matcher::$last_matched_rule->id,
                $product_ids
            );
        }

        // Fallback.
        if (empty($product_ids) && 'none' !== $atts['fallback']) {
            $product_ids = self::get_fallback_products(
                $product_id,
                $atts['fallback'],
                $limit ? $limit : 4
            );
        }

        if (empty($product_ids)) {
            return '';
        }

        // Render output.
        ob_start();

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

        return ob_get_clean();
    }

    /**
     * Get fallback products.
     *
     * @param int    $product_id Product ID to exclude.
     * @param string $type       Fallback type.
     * @param int    $limit      Number of products.
     * @return array Array of product IDs.
     */
    private static function get_fallback_products($product_id, $type, $limit)
    {
        $limit = absint($limit);

        switch ($type) {
            case 'woo_related':
                return wc_get_related_products($product_id, $limit);

            case 'recent':
                $query = new WP_Query(array(
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'posts_per_page' => $limit,
                    'post__not_in' => array($product_id),
                    'fields' => 'ids',
                    'orderby' => 'date',
                    'order' => 'DESC',
                ));
                return $query->posts;

            case 'bestselling':
                $query = new WP_Query(array(
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'posts_per_page' => $limit,
                    'post__not_in' => array($product_id),
                    'fields' => 'ids',
                    'meta_key' => 'total_sales',
                    'orderby' => 'meta_value_num',
                    'order' => 'DESC',
                ));
                return $query->posts;

            default:
                return array();
        }
    }
}
