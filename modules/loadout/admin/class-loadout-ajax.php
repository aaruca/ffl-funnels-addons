<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Ajax
{
    public function init(): void
    {
        add_action('wp_ajax_loadout_search_products', [$this, 'search_products']);
        add_action('wp_ajax_loadout_toggle_status', [$this, 'toggle_status']);
    }

    public function search_products(): void
    {
        check_ajax_referer('loadout_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
        $per_page = 20;

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            's' => $search,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key'     => '_stock_status',
                    'value'   => 'outofstock',
                    'compare' => '!=',
                ],
            ],
        ];

        $query = new WP_Query($args);
        $results = [];

        foreach ($query->posts as $pid) {
            $product = wc_get_product($pid);
            if ($product) {
                $results[] = [
                    'id' => $pid,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'price' => $product->get_price_html(),
                ];
            }
        }

        wp_send_json_success([
            'products' => $results,
            'page' => $page,
            'pages' => $query->max_num_pages,
            'has_more' => $page < $query->max_num_pages,
        ]);
    }

    public function toggle_status(): void
    {
        check_ajax_referer('loadout_admin', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied.']);
        }

        $loadout_id = isset($_POST['loadout_id']) ? absint($_POST['loadout_id']) : 0;
        $loadout = Loadout::get($loadout_id);
        if (!$loadout) {
            wp_send_json_error(['message' => 'Loadout not found.']);
        }

        $loadout->toggle_status();
        wp_send_json_success([
            'status' => $loadout->get_status(),
            'label' => $loadout->get_status() ? 'Active' : 'Inactive',
        ]);
    }
}
