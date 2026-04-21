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
        check_ajax_referer('alg_wishlist_nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        $action = isset($_POST['todo']) ? sanitize_text_field(wp_unslash($_POST['todo'])) : 'toggle';

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid Product ID', 'ffl-funnels-addons')));
        }

        $rl_ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $rl_key = 'alg_wl_rl_' . md5(($rl_ip) . '|' . get_current_user_id());
        $rl_count = (int) get_transient($rl_key);
        $rl_cap = (int) apply_filters('alg_wishlist_rate_limit', 60);
        if ($rl_count >= $rl_cap) {
            wp_send_json_error(array('message' => __('Too many wishlist requests. Please wait a moment.', 'ffl-funnels-addons')));
        }
        set_transient($rl_key, $rl_count + 1, MINUTE_IN_SECONDS);

        if ($action === 'add' && !Alg_Wishlist_Core::is_in_wishlist($product_id, $variation_id)) {
            $items = Alg_Wishlist_Core::get_wishlist_items();
            $cap = (int) apply_filters('alg_wishlist_max_items', 200);
            if (count($items) >= $cap) {
                wp_send_json_error(array('message' => __('Wishlist is full.', 'ffl-funnels-addons')));
            }
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
