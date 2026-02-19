<?php
/**
 * WooBooster Bricks Builder Integration.
 *
 * Registers the "WooBooster Recommendations" custom Query Loop type in Bricks Builder.
 * Handles query execution, loop context setup, and editor control registration.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Bricks
{

    /**
     * The custom query type identifier.
     */
    const QUERY_TYPE = 'woobooster_recommendations';

    /**
     * Initialize all Bricks hooks.
     */
    public function init()
    {
        // Register the custom query type in Bricks' dropdown.
        add_filter('bricks/setup/control_options', array($this, 'register_query_type'));

        // Register controls on common elements (Container, Block, Div) so they appear
        // in a dedicated group outside the Query Loop tab.
        $elements = array('container', 'block', 'div');
        foreach ($elements as $element) {
            add_filter("bricks/elements/{$element}/controls", array($this, 'add_element_controls'), 20);
        }

        // Query Execution Hooks
        add_filter('bricks/query/run', array($this, 'run_query'), 10, 2);
        add_filter('bricks/query/loop_object', array($this, 'set_loop_object'), 10, 3);
        add_filter('bricks/query/loop_object_id', array($this, 'set_loop_object_id'), 10, 3);
        add_action('bricks/query/after_loop', array($this, 'after_loop'), 10, 1);
    }

    /**
     * Step 1: Register the custom query type in Bricks' dropdown.
     *
     * @param array $control_options Existing options.
     * @return array
     */
    public function register_query_type($control_options)
    {
        $control_options['queryTypes'][self::QUERY_TYPE] = esc_html__('WooBooster Recommendations', 'woobooster');
        return $control_options;
    }

    /**
     * Step 2: Register separate settings group for WooBooster.
     *
     * Injected into Container/Block/Div elements so controls are visible
     * outside the potentially buggy Query loop inner accordion.
     *
     * @param array $controls Existing controls.
     * @return array
     */
    public function add_element_controls($controls)
    {
        // 1. Create a new Control Group "WooBooster".
        // It will sit below the standard "Content" controls.
        $controls['woobooster_settings_group'] = array(
            'tab' => 'content',
            'type' => 'separator',
            'label' => esc_html__('WooBooster Settings', 'woobooster'),
            'required' => array('query.objectType', '=', self::QUERY_TYPE),
        );

        // 2. Add controls to this group.

        // Product Source.
        $controls['woobooster_source'] = array(
            'tab' => 'content',
            'label' => esc_html__('Product Source', 'woobooster'),
            'type' => 'select',
            'options' => array(
                'current' => esc_html__('Current Product (auto-detect)', 'woobooster'),
                'manual' => esc_html__('Manual Product ID', 'woobooster'),
                'cart' => esc_html__('Last Added to Cart', 'woobooster'),
            ),
            'default' => 'current',
            'required' => array('query.objectType', '=', self::QUERY_TYPE),
        );

        // Manual Product ID.
        $controls['woobooster_product_id'] = array(
            'tab' => 'content',
            'label' => esc_html__('Product ID', 'woobooster'),
            'type' => 'number',
            'required' => array(
                array('query.objectType', '=', self::QUERY_TYPE),
                array('woobooster_source', '=', 'manual'),
            ),
        );

        // Override Limit.
        $controls['woobooster_limit'] = array(
            'tab' => 'content',
            'label' => esc_html__('Max Products (override)', 'woobooster'),
            'type' => 'number',
            'default' => '',
            'placeholder' => esc_html__('Use rule default', 'woobooster'),
            'required' => array('query.objectType', '=', self::QUERY_TYPE),
        );

        // Exclude Out of Stock.
        $controls['woobooster_exclude_outofstock'] = array(
            'tab' => 'content',
            'label' => esc_html__('Exclude Out of Stock', 'woobooster'),
            'type' => 'checkbox',
            'default' => true,
            'required' => array('query.objectType', '=', self::QUERY_TYPE),
        );

        // Fallback.
        $controls['woobooster_fallback'] = array(
            'tab' => 'content',
            'label' => esc_html__('Fallback if No Match', 'woobooster'),
            'type' => 'select',
            'options' => array(
                'none' => esc_html__('Show Nothing', 'woobooster'),
                'woo_related' => esc_html__('WooCommerce Related', 'woobooster'),
                'recent' => esc_html__('Recent Products', 'woobooster'),
                'bestselling' => esc_html__('Bestselling Products', 'woobooster'),
            ),
            'default' => 'woo_related',
            'required' => array('query.objectType', '=', self::QUERY_TYPE),
        );

        return $controls;
    }

    /**
     * Step 3: Execute the recommendation query.
     *
     * Returns an array of WP_Post objects so Bricks dynamic data resolves.
     *
     * @param array  $results   Default results.
     * @param object $query_obj Bricks query object.
     * @return array Array of WP_Post objects.
     */
    public function run_query($results, $query_obj)
    {
        if ($query_obj->object_type !== self::QUERY_TYPE) {
            return $results;
        }

        $settings = $query_obj->settings;
        $product_id = $this->resolve_product_id($settings);

        if (!$product_id) {
            return array();
        }

        // Get recommendations via the Matcher engine.
        $matcher = new WooBooster_Matcher();
        $product_ids = $matcher->get_recommendations($product_id, array(
            'limit' => !empty($settings['woobooster_limit']) ? absint($settings['woobooster_limit']) : null,
            'exclude_outofstock' => isset($settings['woobooster_exclude_outofstock']) ? $settings['woobooster_exclude_outofstock'] : true,
        ));

        // Handle fallback.
        if (empty($product_ids) && !empty($settings['woobooster_fallback']) && 'none' !== $settings['woobooster_fallback']) {
            $product_ids = $this->get_fallback_products(
                $product_id,
                $settings['woobooster_fallback'],
                !empty($settings['woobooster_limit']) ? absint($settings['woobooster_limit']) : 4
            );
        }

        if (empty($product_ids)) {
            return array();
        }

        // Convert IDs to WP_Post objects — CRITICAL for Bricks dynamic data.
        return array_filter(array_map('get_post', $product_ids));
    }

    /**
     * Step 4: Set the loop context per item.
     *
     * CRITICAL — Without this, Bricks dynamic data tags like
     * {woo_product_price}, {woo_product_image}, {woo_add_to_cart} will NOT work.
     *
     * @param mixed  $loop_object Current loop item.
     * @param int    $loop_key    Loop index.
     * @param object $query_obj   Bricks query object.
     * @return mixed
     */
    public function set_loop_object($loop_object, $loop_key, $query_obj)
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

        // Set global $post and run setup_postdata for WordPress template tags.
        $GLOBALS['post'] = $post;
        setup_postdata($post);

        // Set global $product for WooCommerce dynamic data.
        if (function_exists('wc_get_product')) {
            $GLOBALS['product'] = wc_get_product($post->ID);
        }

        return $post;
    }

    /**
     * Step 5: Map loop object to its post ID.
     *
     * Tells Bricks which ID to use for metadata and dynamic data resolution.
     *
     * @param int    $object_id  Default object ID.
     * @param mixed  $loop_object Current loop item.
     * @param object $query_obj  Bricks query object.
     * @return int
     */
    public function set_loop_object_id($object_id, $loop_object, $query_obj)
    {
        if (!is_object($query_obj) || $query_obj->object_type !== self::QUERY_TYPE) {
            return $object_id;
        }

        if (is_a($loop_object, 'WP_Post')) {
            return $loop_object->ID;
        }

        if (is_numeric($loop_object)) {
            return absint($loop_object);
        }

        return $object_id;
    }

    /**
     * Step 6: Reset post data after the loop completes.
     *
     * @param object $query_obj Bricks query object.
     */
    public function after_loop($query_obj)
    {
        if (!is_object($query_obj) || $query_obj->object_type !== self::QUERY_TYPE) {
            return;
        }
        wp_reset_postdata();
    }

    /**
     * Resolve the product ID based on query settings.
     *
     * @param array $settings Query settings from Bricks.
     * @return int Product ID or 0.
     */
    private function resolve_product_id($settings)
    {
        $source = isset($settings['woobooster_source']) ? $settings['woobooster_source'] : 'current';

        switch ($source) {
            case 'manual':
                return absint(isset($settings['woobooster_product_id']) ? $settings['woobooster_product_id'] : 0);

            case 'cart':
                return $this->get_cart_product_id();

            case 'current':
            default:
                // 1. Try global product (Product Page).
                global $product;
                if ($product && is_a($product, 'WC_Product')) {
                    return $product->get_id();
                }

                // 2. Check if on Cart page (Auto-detect).
                if (function_exists('is_cart') && is_cart()) {
                    return $this->get_cart_product_id();
                }

                // 3. Fallback to post ID if on a product post.
                if (is_singular('product')) {
                    return get_the_ID();
                }

                // 4. Try Bricks editor preview.
                if ($this->is_bricks_builder()) {
                    return $this->get_preview_product_id();
                }

                return 0;
        }
    }

    /**
     * Helper to get a product ID from the cart.
     *
     * @return int
     */
    private function get_cart_product_id()
    {
        if (function_exists('WC') && WC()->cart) {
            $cart_items = WC()->cart->get_cart();
            if (!empty($cart_items)) {
                // Return the most recently added item (end of array).
                $last_item = end($cart_items);
                return isset($last_item['product_id']) ? absint($last_item['product_id']) : 0;
            }
        }
        return 0;
    }

    /**
     * Get a product ID for Bricks editor preview.
     *
     * @return int
     */
    private function get_preview_product_id()
    {
        // Try Bricks Database page_data (safe — no args mismatch).
        if (class_exists('\\Bricks\\Database')) {
            $page_data = \Bricks\Database::$page_data ?? array();
            $preview_id = isset($page_data['preview_or_post_id']) ? absint($page_data['preview_or_post_id']) : 0;
            if ($preview_id && get_post_type($preview_id) === 'product') {
                return $preview_id;
            }
        }

        // Fallback: check query vars / GET params.
        if (!empty($_GET['preview_id'])) {
            $preview_id = absint($_GET['preview_id']);
            if ($preview_id && get_post_type($preview_id) === 'product') {
                return $preview_id;
            }
        }

        // Last resort: get any published product.
        $products = wc_get_products(array(
            'limit' => 1,
            'status' => 'publish',
            'return' => 'ids',
        ));

        return !empty($products) ? absint($products[0]) : 0;
    }

    /**
     * Check if currently in the Bricks Builder editor.
     *
     * @return bool
     */
    private function is_bricks_builder()
    {
        if (function_exists('bricks_is_builder')) {
            return bricks_is_builder();
        }

        if (function_exists('bricks_is_builder_call')) {
            return bricks_is_builder_call();
        }

        return false;
    }

    /**
     * Get fallback product IDs.
     *
     * @param int    $product_id Product to exclude.
     * @param string $type       Fallback type.
     * @param int    $limit      Max products.
     * @return array
     */
    private function get_fallback_products($product_id, $type, $limit)
    {
        $limit = absint($limit);

        switch ($type) {
            case 'woo_related':
                return wc_get_related_products($product_id, $limit);

            case 'recent':
                $q = new WP_Query(array(
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'posts_per_page' => $limit,
                    'post__not_in' => array($product_id),
                    'fields' => 'ids',
                    'orderby' => 'date',
                    'order' => 'DESC',
                ));
                return $q->posts;

            case 'bestselling':
                $q = new WP_Query(array(
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'posts_per_page' => $limit,
                    'post__not_in' => array($product_id),
                    'fields' => 'ids',
                    'meta_key' => 'total_sales',
                    'orderby' => 'meta_value_num',
                    'order' => 'DESC',
                ));
                return $q->posts;

            default:
                return array();
        }
    }
}
