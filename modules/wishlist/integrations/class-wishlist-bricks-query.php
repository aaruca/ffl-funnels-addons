<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Bricks Wishlist Query
 */
class Alg_Wishlist_Bricks_Query
{
    /**
     * The custom query type identifier.
     */
    const QUERY_TYPE = 'alg_wishlist';

    public static function init()
    {
        add_filter('bricks/setup/control_options', [__CLASS__, 'register_query_type']);
        add_filter('bricks/query/run', [__CLASS__, 'run_query'], 10, 2);
        add_filter('bricks/query/loop_object', [__CLASS__, 'set_loop_object'], 10, 3);
        add_filter('bricks/query/loop_object_id', [__CLASS__, 'set_loop_object_id'], 10, 3);
        add_action('bricks/query/after_loop', [__CLASS__, 'after_loop'], 10, 1);
    }

    /**
     * Register "Algenib Wishlist" in the Query Type dropdown
     */
    public static function register_query_type($options)
    {
        $options['queryTypes'][self::QUERY_TYPE] = esc_html__('Algenib Wishlist', 'algenib-wishlist');
        return $options;
    }

    /**
     * Run the query: Fetch Product IDs from Wishlist
     */
    public static function run_query($results, $query_obj)
    {
        if ($query_obj->object_type !== self::QUERY_TYPE) {
            return $results;
        }

        // Get Wishlist Items (IDs)
        $items = Alg_Wishlist_Core::get_wishlist_items();

        if (empty($items)) {
            return [];
        }

        // We fetch WP_Post objects to be safe, though Bricks can handle IDs sometimes.
        // It's safer to return the actual objects expected by a loop.
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'post__in' => $items,
            'posts_per_page' => -1,
            'orderby' => 'post__in', // Keep order of addition
        ];

        $query = new WP_Query($args);

        return $query->posts;
    }

    /**
     * Setup the loop object (Global Post & Product)
     */
    public static function set_loop_object($loop_object, $loop_key, $query_obj)
    {
        if ($query_obj->object_type !== self::QUERY_TYPE) {
            return $loop_object;
        }

        // Handle both WP_Post objects and raw IDs.
        $post = $loop_object;
        if (is_numeric($loop_object)) {
            $post = get_post($loop_object);
        }

        if (!$post || !is_a($post, 'WP_Post')) {
            return $loop_object;
        }

        global $post; // Defines global $post
        $post = $loop_object;
        setup_postdata($post);

        // Ensure WooCommerce global product is set
        if (function_exists('wc_get_product')) {
            $GLOBALS['product'] = wc_get_product($post->ID);
        }

        return $post;
    }

    /**
     * Return the valid object ID for dynamic data
     */
    public static function set_loop_object_id($object_id, $object, $query_obj)
    {
        // Fix: Warning: Attempt to read property "object_type" on string
        if (!is_object($query_obj)) {
            return $object_id;
        }

        if ($query_obj->object_type !== self::QUERY_TYPE) {
            return $object_id;
        }

        if (is_a($object, 'WP_Post')) {
            return $object->ID;
        }

        if (is_numeric($object)) {
            return absint($object);
        }

        return $object_id;
    }

    /**
     * Cleanup after loop
     */
    public static function after_loop($query_obj)
    {
        if ($query_obj->object_type !== self::QUERY_TYPE) {
            return;
        }
        wp_reset_postdata();
    }
}
