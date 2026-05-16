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

    /**
     * Render a small stock status chip for a WC_Product.
     * Shared by the AJAX search response and the PHP renderers so a product
     * configured in a loadout always shows its current stock at-a-glance.
     */
    public static function format_stock_html($product): string
    {
        if (!$product) {
            return '';
        }
        $status   = $product->get_stock_status();
        $manages  = $product->managing_stock();
        $qty      = $product->get_stock_quantity();

        if ($status === 'outofstock') {
            return '<span class="loadout-stock loadout-stock--oos">' . esc_html__('Out of stock', 'ffl-funnels-addons') . '</span>';
        }
        if ($status === 'onbackorder') {
            return '<span class="loadout-stock loadout-stock--backorder">' . esc_html__('On backorder', 'ffl-funnels-addons') . '</span>';
        }
        if ($manages && $qty !== null) {
            $cls = $qty <= 5 ? 'loadout-stock--low' : 'loadout-stock--ok';
            return '<span class="loadout-stock ' . esc_attr($cls) . '">' . sprintf(
                /* translators: %d: stock count */
                esc_html__('%d in stock', 'ffl-funnels-addons'),
                (int) $qty
            ) . '</span>';
        }
        return '<span class="loadout-stock loadout-stock--ok">' . esc_html__('In stock', 'ffl-funnels-addons') . '</span>';
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
                    'id'             => $pid,
                    'name'           => $product->get_name(),
                    'sku'            => $product->get_sku(),
                    'price'          => $product->get_price_html(),
                    'stock_status'   => $product->get_stock_status(),    // instock | outofstock | onbackorder
                    'stock_quantity' => $product->get_stock_quantity(),   // int or null
                    'manages_stock'  => $product->managing_stock(),       // bool
                    'stock_html'     => self::format_stock_html($product),
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
