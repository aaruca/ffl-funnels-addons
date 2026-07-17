<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core Logic for Wishlist Management
 *
 * Handles database operations, session management, and wishlist actions.
 *
 * @package FFL_Funnels_Addons
 */

class Alg_Wishlist_Core
{

    private static $session_cookie_name = 'alg_wishlist_session';

    /**
     * Initialize user session (Guest or Logged in)
     */
    /**
     * Allowed DB field names for owner queries.
     */
    private static $allowed_owner_fields = ['user_id', 'session_id'];

    /**
     * Validate owner field against whitelist.
     */
    private static function safe_owner_field(string $field): string
    {
        return in_array($field, self::$allowed_owner_fields, true) ? $field : 'user_id';
    }

    public static function init_session()
    {
        if (is_user_logged_in()) {
            // Check if there was a guest session and merge
            self::merge_guest_wishlist();
        } else {
            // Ensure guest has a session ID cookie
            if (!isset($_COOKIE[self::$session_cookie_name])) {
                $session_id = wp_generate_password(32, false);
                if (headers_sent()) {
                    return;
                }
                setcookie(self::$session_cookie_name, $session_id, [
                    'expires' => time() + 30 * DAY_IN_SECONDS,
                    'path' => COOKIEPATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
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
            $session_id = isset($_COOKIE[self::$session_cookie_name])
                ? sanitize_text_field(wp_unslash($_COOKIE[self::$session_cookie_name]))
                : '';
            return array('field' => 'session_id', 'value' => $session_id);
        }
    }

    /**
     * Get default wishlist ID for current user.
     *
     * @param bool $create_if_missing Create the list when none exists. Only ever
     *                                true on a real write (add_item). Read paths
     *                                (page loads, counts, is-in checks) must NOT
     *                                create rows: this method is reached from the
     *                                asset enqueue on every frontend request, and
     *                                cookieless crawlers would otherwise seed a
     *                                new empty wishlist row per request.
     * @return int|false
     */
    public static function get_default_wishlist_id($create_if_missing = false)
    {
        global $wpdb;
        $owner = self::get_current_owner();

        if (empty($owner['value']))
            return false;

        $table = $wpdb->prefix . 'alg_wishlists';
        $field = self::safe_owner_field($owner['field']);
        // Deterministically pick the oldest default list, so if a race ever
        // produced two, every request resolves to the same one.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare("SELECT id FROM {$table} WHERE {$field} = %s AND is_default = 1 ORDER BY id ASC LIMIT 1", $owner['value']);
        $id = $wpdb->get_var($sql);

        if (!$id && $create_if_missing) {
            $id = self::create_wishlist('My Wishlist', true);

            // Collapse any duplicate default created by a concurrent request.
            if ($id) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} WHERE {$field} = %s AND is_default = 1 AND id > %d",
                    $owner['value'],
                    (int) $id
                ));
            }
        }

        return $id ? (int) $id : false;
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
        $field = self::safe_owner_field($owner['field']);
        $wpdb->insert(
            $table,
            array(
                $field => $owner['value'],
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
        // The only path allowed to create the list.
        $wishlist_id = self::get_default_wishlist_id(true);

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

        $inserted = $wpdb->insert(
            $table_items,
            array(
                'wishlist_id' => $wishlist_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
                'date_added' => current_time('mysql')
            )
        );

        // A concurrent request may have inserted the same item between the check
        // above and here; the UNIQUE key rejects it. That's "already there", not
        // a failure.
        if (false === $inserted)
            return 'exists';

        return 'added';
    }

    /**
     * Remove item from wishlist
     */
    public static function remove_item($product_id, $variation_id = 0)
    {
        global $wpdb;
        $wishlist_id = self::get_default_wishlist_id();

        // Nothing to remove — don't create a list just to delete from it.
        if (!$wishlist_id)
            return 'removed';

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

        // Join wp_posts so deleted/unpublished products are excluded. Without
        // this the count badge disagrees with what the wishlist page renders
        // (the page skips products that fail wc_get_product()).
        $table_items = $wpdb->prefix . 'alg_wishlist_items';
        return $wpdb->get_col($wpdb->prepare(
            "SELECT i.product_id
             FROM {$table_items} i
             INNER JOIN {$wpdb->posts} p ON p.ID = i.product_id
             WHERE i.wishlist_id = %d
               AND p.post_type = 'product'
               AND p.post_status = 'publish'
             LIMIT 500",
            $wishlist_id
        ));
    }

    /**
     * Merge Guest Wishlist to User
     */
    private static function merge_guest_wishlist()
    {
        if (!isset($_COOKIE[self::$session_cookie_name]))
            return;

        $session_id = sanitize_text_field(wp_unslash($_COOKIE[self::$session_cookie_name]));
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
                // Merge items from the guest list into the user list without
                // creating duplicates. UPDATE IGNORE only suppresses key
                // collisions, so drop guest rows whose product + variation is
                // already in the target list first, then move the survivors.
                $table_items = $wpdb->prefix . 'alg_wishlist_items';

                $wpdb->query($wpdb->prepare(
                    "DELETE g FROM {$table_items} g
                     INNER JOIN {$table_items} u
                        ON u.wishlist_id = %d
                       AND u.product_id = g.product_id
                       AND u.variation_id = g.variation_id
                     WHERE g.wishlist_id = %d",
                    $user_list_id,
                    $guest_list_id
                ));

                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table_items} SET wishlist_id = %d WHERE wishlist_id = %d",
                    $user_list_id,
                    $guest_list_id
                ));

                // Delete old guest list
                $wpdb->delete($table, array('id' => $guest_list_id));
            }

            // Clear cookie
            if (headers_sent()) {
                return;
            }
            setcookie(self::$session_cookie_name, '', [
                'expires' => time() - 3600,
                'path' => COOKIEPATH,
                'domain' => COOKIE_DOMAIN,
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
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

    /**
     * Allowed tags/attributes for a merchant-supplied wishlist icon SVG.
     *
     * Attribute names are LOWERCASE on purpose: wp_kses lowercases attribute
     * names before matching this list, so a camelCase 'viewBox' key never
     * matches and the attribute gets silently stripped — which breaks SVG
     * scaling. 'viewbox' is the form that survives.
     *
     * @return array<string,array<string,bool>>
     */
    public static function svg_allowlist(): array
    {
        return array(
            'svg'      => array('xmlns' => true, 'viewbox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true, 'style' => true, 'aria-hidden' => true, 'role' => true, 'focusable' => true),
            'path'     => array('d' => true, 'fill' => true, 'fill-rule' => true, 'clip-rule' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true),
            'g'        => array('fill' => true, 'stroke' => true, 'transform' => true),
            'circle'   => array('cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true),
            'ellipse'  => array('cx' => true, 'cy' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true),
            'rect'     => array('x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true),
            'line'     => array('x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true),
            'polyline' => array('points' => true, 'fill' => true, 'stroke' => true),
            'polygon'  => array('points' => true, 'fill' => true, 'stroke' => true),
        );
    }

    /**
     * The merchant's custom wishlist icon SVG (from Settings → Custom Icon SVG),
     * sanitised on output, or '' when none is set.
     */
    public static function custom_icon_svg(): string
    {
        $settings = get_option('alg_wishlist_settings', array());
        $svg = is_array($settings) ? trim((string) ($settings['alg_wishlist_icon_svg'] ?? '')) : '';

        if ($svg === '' || strpos($svg, '<svg') === false) {
            return '';
        }

        return wp_kses($svg, self::svg_allowlist());
    }

    /**
     * The icon a wishlist component shows when it sets no icon of its own:
     * the merchant's custom SVG when configured, otherwise the default heart.
     *
     * @param string $css_class Styling/sizing hook class for the SVG.
     */
    public static function default_icon_svg(string $css_class = 'ffla-wishlist-icon'): string
    {
        $custom = self::custom_icon_svg();
        if ($custom !== '') {
            // Add the hook class only when the merchant did not set their own,
            // so element sizing/colour rules still apply.
            if (!preg_match('/<svg[^>]*\sclass=/i', $custom)) {
                $custom = preg_replace('/<svg\b/i', '<svg class="' . esc_attr($css_class) . '"', $custom, 1);
            }
            return $custom;
        }

        return '<svg class="' . esc_attr($css_class) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';
    }

}
