<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fired during plugin activation
 *
 * @package FFL_Funnels_Addons
 */

class Alg_Wishlist_Activator
{

    /**
     * Create database tables on activation.
     */
    public static function activate()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // 1. Wishlists Table
        // Stores the list meta (Name, Privacy, User ID, Session ID for guests)
        $table_wishlists = $wpdb->prefix . 'alg_wishlists';
        $sql_wishlists = "CREATE TABLE $table_wishlists (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) DEFAULT 0,
			session_id varchar(255) DEFAULT '',
			wishlist_slug varchar(200) DEFAULT '',
			wishlist_name text NOT NULL,
			wishlist_privacy tinyint(1) DEFAULT 0, -- 0: Public, 1: Shared, 2: Private
			is_default tinyint(1) DEFAULT 0,
			date_created datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			last_updated datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY session_id (session_id)
		) $charset_collate;";

        // 2. Wishlist Items Table
        // Stores the actual products linked to a wishlist ID
        $table_items = $wpdb->prefix . 'alg_wishlist_items';
        $sql_items = "CREATE TABLE $table_items (
			item_id bigint(20) NOT NULL AUTO_INCREMENT,
			wishlist_id bigint(20) NOT NULL,
			product_id bigint(20) NOT NULL,
			variation_id bigint(20) DEFAULT 0,
			date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (item_id),
			KEY wishlist_id (wishlist_id),
			KEY product_id (product_id)
		) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_wishlists);
        dbDelta($sql_items);

        // Set version option
        if (get_option('alg_wishlist_version') !== ALG_WISHLIST_VERSION) {
            update_option('alg_wishlist_version', ALG_WISHLIST_VERSION);
        }
    }

}
