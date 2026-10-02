<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX Handler
 *
 * Processes frontend requests for adding/removing items, and returns the
 * visitor's current list (used to correct pages served from a cache).
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

        if ($this->rate_limited()) {
            wp_send_json_error(array('message' => __('Too many wishlist requests. Please wait a moment.', 'ffl-funnels-addons')));
        }

        $in_list = Alg_Wishlist_Core::is_in_wishlist($product_id, $variation_id);
        if ($action !== 'add' && $action !== 'remove') {
            $action = $in_list ? 'remove' : 'add';
        }

        // The size limit applies to every add, including the default toggle.
        if ($action === 'add' && !$in_list) {
            $items = Alg_Wishlist_Core::get_wishlist_items();
            $cap = (int) apply_filters('alg_wishlist_max_items', 200);
            if (count($items) >= $cap) {
                wp_send_json_error(array('message' => __('Wishlist is full.', 'ffl-funnels-addons')));
            }
        }

        if ($action === 'remove') {
            Alg_Wishlist_Core::remove_item($product_id, $variation_id);
            $status = 'removed';
        } else {
            $result = Alg_Wishlist_Core::add_item($product_id, $variation_id);
            if (false === $result) {
                wp_send_json_error(array('message' => __('Your wishlist could not be saved.', 'ffl-funnels-addons')));
            }
            $status = 'added';
        }

        $items = Alg_Wishlist_Core::get_wishlist_items();
        Alg_Wishlist_Core::set_state_cookie($items);

        wp_send_json_success(array(
            'status' => $status,
            'count'  => count($items),
            'items'  => array_map('intval', $items),
            'state'  => Alg_Wishlist_Core::state_hash($items),
        ));
    }

    /**
     * The visitor's own list, a fresh request token and the state hash.
     *
     * Read-only and scoped to the visitor's own cookie/session, so it needs no
     * token itself; the page script calls it when the page it got was built
     * for somebody else (full-page cache) or its token has expired.
     */
    public function get_state()
    {
        $items = Alg_Wishlist_Core::get_wishlist_items();
        Alg_Wishlist_Core::set_state_cookie($items);
        nocache_headers();

        wp_send_json_success(array(
            'items' => array_map('intval', $items),
            'count' => count($items),
            'state' => Alg_Wishlist_Core::state_hash($items),
            'nonce' => wp_create_nonce('alg_wishlist_nonce'),
        ));
    }

    /**
     * Per-visitor limit (account, or guest session, or IP for a guest without
     * one) plus a looser per-IP limit for guests, both per fixed minute. A
     * shared IP (proxy, CDN, office) therefore no longer puts every guest in
     * one small bucket.
     */
    private function rate_limited(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';

        if (is_user_logged_in()) {
            $visitor = 'u:' . get_current_user_id();
        } else {
            $owner   = Alg_Wishlist_Core::get_current_owner();
            $visitor = !empty($owner['value']) ? 's:' . $owner['value'] : 'ip:' . $ip;
        }

        if ($this->hit('alg_wl_rl_' . md5($visitor), (int) apply_filters('alg_wishlist_rate_limit', 60))) {
            return true;
        }
        if (!is_user_logged_in()
            && $this->hit('alg_wl_rl_ip_' . md5($ip), (int) apply_filters('alg_wishlist_ip_rate_limit', 300))) {
            return true;
        }
        return false;
    }

    /**
     * Count one request in a fixed one-minute window; true when over $cap.
     */
    private function hit(string $key, int $cap): bool
    {
        $now    = time();
        $window = get_transient($key);
        if (!is_array($window) || ($now - (int) ($window['t'] ?? 0)) >= MINUTE_IN_SECONDS) {
            $window = array('t' => $now, 'n' => 0);
        }
        if ($window['n'] >= $cap) {
            return true;
        }
        $window['n']++;
        set_transient($key, $window, max(1, MINUTE_IN_SECONDS - ($now - $window['t'])));
        return false;
    }

}
