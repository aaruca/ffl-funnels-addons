<?php

/**
 * Core Logic for Wishlist Management
 *
 * Handles database operations, session management, and wishlist actions.
 *
 * @package    Algenib_Wishlist
 * @subpackage Algenib_Wishlist/includes
 */

class Alg_Wishlist_Core
{

    private static $session_cookie_name = 'alg_wishlist_session';

    /**
     * Initialize user session (Guest or Logged in)
     */
    public static function init_session()
    {
        if (is_user_logged_in()) {
            // Check if there was a guest session and merge
            self::merge_guest_wishlist();
        } else {
            // Ensure guest has a session ID cookie
            if (!isset($_COOKIE[self::$session_cookie_name])) {
                $session_id = wp_generate_password(32, false);
                setcookie(self::$session_cookie_name, $session_id, time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN);
                $_COOKIE[self::$session_cookie_name] = $session_id; // Set for immediate use
            }
        }
    }

    /**
     * Get current User/Session ID for DB queries
     */
    public static function get_current_owner()
    {
        if (is_user_logged_in()) {
            return array('field' => 'user_id', 'value' => get_current_user_id());
        } else {
            $session_id = isset($_COOKIE[self::$session_cookie_name]) ? $_COOKIE[self::$session_cookie_name] : '';
            return array('field' => 'session_id', 'value' => $session_id);
        }
    }

    /**
     * Get default wishlist ID for current user
     */
    public static function get_default_wishlist_id()
    {
        global $wpdb;
        $owner = self::get_current_owner();

        if (empty($owner['value']))
            return false;

        $table = $wpdb->prefix . 'alg_wishlists';
        $sql = $wpdb->prepare("SELECT id FROM $table WHERE {$owner['field']} = %s AND is_default = 1", $owner['value']);
        $id = $wpdb->get_var($sql);

        if (!$id) {
            // Create default list if none exists
            $id = self::create_wishlist('My Wishlist', true);
        }

        return $id;
    }

    /**
     * Create a new wishlist
     */
    public static function create_wishlist($name, $is_default = false)
    {
        global $wpdb;
        $owner = self::get_current_owner();

        if (empty($owner['value']))
            return false;

        $table = $wpdb->prefix . 'alg_wishlists';
        $wpdb->insert(
            $table,
            array(
                $owner['field'] => $owner['value'],
                'wishlist_name' => sanitize_text_field($name),
                'wishlist_slug' => sanitize_title($name),
                'is_default' => $is_default ? 1 : 0,
                'date_created' => current_time('mysql'),
                'last_updated' => current_time('mysql')
            )
        );
        return $wpdb->insert_id;
    }

    /**
     * Add item to wishlist
     */
    public static function add_item($product_id, $variation_id = 0)
    {
        global $wpdb;
        $wishlist_id = self::get_default_wishlist_id();

        if (!$wishlist_id)
            return false;

        // Check if already exists
        $table_items = $wpdb->prefix . 'alg_wishlist_items';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT item_id FROM $table_items WHERE wishlist_id = %d AND product_id = %d AND variation_id = %d",
            $wishlist_id,
            $product_id,
            $variation_id
        ));

        if ($exists)
            return 'exists';

        $wpdb->insert(
            $table_items,
            array(
                'wishlist_id' => $wishlist_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'date_added' => current_time('mysql')
            )
        );

        return 'added';
    }

    /**
     * Remove item from wishlist
     */
    public static function remove_item($product_id, $variation_id = 0)
    {
        global $wpdb;
        $wishlist_id = self::get_default_wishlist_id();

        $table_items = $wpdb->prefix . 'alg_wishlist_items';
        $wpdb->delete(
            $table_items,
            array(
                'wishlist_id' => $wishlist_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id
            )
        );

        return 'removed';
    }

    /**
     * Check if product is in wishlist
     */
    public static function is_in_wishlist($product_id, $variation_id = 0)
    {
        global $wpdb;
        $wishlist_id = self::get_default_wishlist_id();
        if (!$wishlist_id)
            return false;

        $table_items = $wpdb->prefix . 'alg_wishlist_items';
        $query = "SELECT item_id FROM $table_items WHERE wishlist_id = %d AND product_id = %d";
        $args = array($wishlist_id, $product_id);

        if ($variation_id) {
            $query .= " AND variation_id = %d";
            $args[] = $variation_id;
        }

        $result = $wpdb->get_var($wpdb->prepare($query, $args));
        return !empty($result);
    }

    /**
     * Get all items in default wishlist
     */
    public static function get_wishlist_items()
    {
        global $wpdb;
        $wishlist_id = self::get_default_wishlist_id();
        if (!$wishlist_id)
            return array();

        $table_items = $wpdb->prefix . 'alg_wishlist_items';
        return $wpdb->get_col($wpdb->prepare("SELECT product_id FROM $table_items WHERE wishlist_id = %d", $wishlist_id));
    }

    /**
     * Merge Guest Wishlist to User
     */
    private static function merge_guest_wishlist()
    {
        if (!isset($_COOKIE[self::$session_cookie_name]))
            return;

        $session_id = $_COOKIE[self::$session_cookie_name];
        global $wpdb;
        $table = $wpdb->prefix . 'alg_wishlists';

        // Find guest wishlist
        $guest_list_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE session_id = %s", $session_id));

        if ($guest_list_id) {
            // Determine User List
            $user_id = get_current_user_id();
            $user_list_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id = %d AND is_default = 1", $user_id));

            if (!$user_list_id) {
                // Just assign guest list to user
                $wpdb->update(
                    $table,
                    array('user_id' => $user_id, 'session_id' => ''),
                    array('id' => $guest_list_id)
                );
            } else {
                // Merge items: Move items from guest list to user list
                $table_items = $wpdb->prefix . 'alg_wishlist_items';
                // Update wishlist_id for all items in guest list to user list
                // Note: Better to do INSERT IGNORE or check for duplicates, but simple UPDATE for MVP
                $wpdb->query($wpdb->prepare(
                    "UPDATE IGNORE $table_items SET wishlist_id = %d WHERE wishlist_id = %d",
                    $user_list_id,
                    $guest_list_id
                ));

                // Delete old guest list
                $wpdb->delete($table, array('id' => $guest_list_id));
            }

            // Clear cookie
            setcookie(self::$session_cookie_name, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
        }
    }

    /**
     * Get Wishlist Page ID
     */
    public static function get_wishlist_page_id()
    {
        $settings = get_option('alg_wishlist_settings');
        if (isset($settings['alg_wishlist_page_id'])) {
            return intval($settings['alg_wishlist_page_id']);
        }
        return 0;
    }

}
