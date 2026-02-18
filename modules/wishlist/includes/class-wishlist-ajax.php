<?php

/**
 * AJAX Handler
 * 
 * Processes frontend requests for adding/removing items.
 *
 * @package    Algenib_Wishlist
 * @subpackage Algenib_Wishlist/includes
 */

class Alg_Wishlist_Ajax
{

    public function add_to_wishlist()
    {
        // Verify Nonce
        if (!check_ajax_referer('alg_wishlist_nonce', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Invalid Nonce'));
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $action = isset($_POST['todo']) ? sanitize_text_field($_POST['todo']) : 'toggle';

        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid Product ID'));
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
            'count' => $count,
            'items' => $items // Send all items to sync frontend state
        ));
    }

}
