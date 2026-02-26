<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX Handler
 *
 * Processes frontend requests for adding/removing items.
 *
 * @package FFL_Funnels_Addons
 */

class Alg_Wishlist_Ajax
{

    public function add_to_wishlist()
    {
        // Verify Nonce — dies automatically on failure.
        check_ajax_referer('alg_wishlist_nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        $action = isset($_POST['todo']) ? sanitize_text_field(wp_unslash($_POST['todo'])) : 'toggle';

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid Product ID', 'ffl-funnels-addons')));
        }

        $status = '';
        if ($action === 'remove') {
            Alg_Wishlist_Core::remove_item($product_id, $variation_id);
            $status = 'removed';
        } elseif ($action === 'add') {
            Alg_Wishlist_Core::add_item($product_id, $variation_id);
            $status = 'added';
        } else {
            // Toggle logic
            if (Alg_Wishlist_Core::is_in_wishlist($product_id, $variation_id)) {
                Alg_Wishlist_Core::remove_item($product_id, $variation_id);
                $status = 'removed';
            } else {
                Alg_Wishlist_Core::add_item($product_id, $variation_id);
                $status = 'added';
            }
        }

        // Get updated count
        $items = Alg_Wishlist_Core::get_wishlist_items();
        $count = count($items);

        wp_send_json_success(array(
            'status' => $status,
            'count'  => $count,
        ));
    }

}
