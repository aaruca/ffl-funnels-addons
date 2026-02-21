<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Ajax
{
    public function init()
    {
        add_action('wp_ajax_woobooster_search_terms', array($this, 'search_terms'));
        add_action('wp_ajax_woobooster_toggle_rule', array($this, 'toggle_rule'));
        add_action('wp_ajax_woobooster_test_rule', array($this, 'test_rule'));
        add_action('wp_ajax_woobooster_search_products', array($this, 'search_products'));
        add_action('wp_ajax_woobooster_search_coupons', array($this, 'search_coupons'));
        add_action('wp_ajax_woobooster_resolve_product_names', array($this, 'resolve_product_names'));
    }

    public function search_terms()
    {
        check_ajax_referer('woobooster_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_key($_POST['taxonomy']) : '';
        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = 20;

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            wp_send_json_error(array('message' => 'Invalid taxonomy.'));
        }
        $args = array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => $per_page,
            'offset' => ($page - 1) * $per_page,
            'orderby' => 'name',
            'order' => 'ASC',
        );
        if ($search) {
            $args['search'] = $search;
        }
        $terms = get_terms($args);
        $count_args = $args;
        unset($count_args['number'], $count_args['offset']);
        $count_args['fields'] = 'count';
        $total = (int) get_terms($count_args);
        $results = array();
        if (!is_wp_error($terms)) {
            foreach ($terms as $term) {
                $results[] = array('slug' => $term->slug, 'name' => $term->name, 'count' => $term->count);
            }
        }
        wp_send_json_success(array(
            'terms' => $results,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $per_page),
            'has_more' => ($page * $per_page) < $total,
        ));
    }

    public function toggle_rule()
    {
        check_ajax_referer('woobooster_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }
        $rule_id = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
        if (!$rule_id) {
            wp_send_json_error(array('message' => 'Invalid rule ID.'));
        }
        $result = WooBooster_Rule::toggle_status($rule_id);
        if ($result) {
            $rule = WooBooster_Rule::get($rule_id);
            wp_send_json_success(array(
                'status' => $rule->status,
                'label' => $rule->status ? 'Active' : 'Inactive',
            ));
        }
        wp_send_json_error(array('message' => 'Failed to toggle rule.'));
    }

    public function test_rule()
    {
        check_ajax_referer('woobooster_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }
        $input = isset($_POST['product']) ? sanitize_text_field(wp_unslash($_POST['product'])) : '';
        if (!$input) {
            wp_send_json_error(array('message' => 'Please enter a product ID or SKU.'));
        }
        $product_id = absint($input);
        if (!$product_id) {
            $product_id = wc_get_product_id_by_sku($input);
        }
        if (!$product_id) {
            wp_send_json_error(array('message' => 'Product not found.'));
        }
        $matcher = new WooBooster_Matcher();
        $diagnostics = $matcher->get_diagnostics($product_id);
        wp_send_json_success($diagnostics);
    }

    /**
     * AJAX: Search products by name.
     */
    public function search_products()
    {
        check_ajax_referer('woobooster_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = 20;

        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            's' => $search,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
        );

        $query = new WP_Query($args);
        $results = array();

        foreach ($query->posts as $pid) {
            $product = wc_get_product($pid);
            if ($product) {
                $results[] = array(
                    'id' => $pid,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                );
            }
        }

        wp_send_json_success(array(
            'products' => $results,
            'total' => $query->found_posts,
            'page' => $page,
            'pages' => $query->max_num_pages,
            'has_more' => $page < $query->max_num_pages,
        ));
    }

    /**
     * AJAX: Search WooCommerce coupons by code.
     */
    public function search_coupons()
    {
        check_ajax_referer('woobooster_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $per_page = 20;

        $args = array(
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            's' => $search,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
        );

        $query = new WP_Query($args);
        $results = array();

        foreach ($query->posts as $cid) {
            $coupon = new WC_Coupon($cid);
            $results[] = array(
                'id' => $cid,
                'code' => $coupon->get_code(),
                'type' => $coupon->get_discount_type(),
                'amount' => $coupon->get_amount(),
            );
        }

        wp_send_json_success(array(
            'coupons' => $results,
            'total' => $query->found_posts,
        ));
    }

    /**
     * Resolve product IDs to names.
     */
    public function resolve_product_names()
    {
        check_ajax_referer('woobooster_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $ids_raw = isset($_POST['ids']) ? sanitize_text_field(wp_unslash($_POST['ids'])) : '';
        $ids = array_filter(array_map('absint', explode(',', $ids_raw)));

        if (empty($ids)) {
            wp_send_json_success(array('names' => array()));
        }

        $names = array();
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if ($product) {
                $names[$id] = $product->get_name();
            } else {
                $names[$id] = '#' . $id;
            }
        }

        wp_send_json_success(array('names' => $names));
    }
}
