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
}
