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
     * Schema version. Bump when the table definitions below change so that
     * maybe_upgrade() re-runs dbDelta after an in-place plugin update (the
     * GitHub updater never calls activate()).
     */
    const DB_VERSION = '1.1.0';

    /**
     * Apply pending schema changes after an in-place update.
     *
     * Cheap no-op once current: one option read plus a comparison.
     */
    public static function maybe_upgrade()
    {
        if (get_option('alg_wishlist_db_version') === self::DB_VERSION) {
            return;
        }

        self::activate();
    }

    /**
     * Create database tables on activation.
     */
    public static function activate()
    {
        global $wpdb;

        // Must run BEFORE dbDelta: the items table gains a UNIQUE key below and
        // MySQL refuses to add it while duplicate rows exist.
        self::dedupe_items();

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
			variation_id bigint(20) NOT NULL DEFAULT 0,
			date_added datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (item_id),
			UNIQUE KEY uniq_item (wishlist_id,product_id,variation_id),
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

        update_option('alg_wishlist_db_version', self::DB_VERSION);
    }

    /**
     * Remove duplicate wishlist items, keeping the earliest row of each
     * (wishlist_id, product_id, variation_id) group.
     *
     * Existing installs can already hold duplicates (the guest->user merge used
     * UPDATE IGNORE against a table with no unique key), and the UNIQUE index
     * added above cannot be created until they are gone.
     */
    private static function dedupe_items()
    {
        global $wpdb;

        $table = $wpdb->prefix . 'alg_wishlist_items';

        // Nothing to do on a fresh install.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        // Normalise NULLs first so they collapse under the unique key and the
        // NOT NULL column change cannot fail in strict mode.
        $wpdb->query("UPDATE {$table} SET variation_id = 0 WHERE variation_id IS NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $wpdb->query( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "DELETE a FROM {$table} a
             INNER JOIN {$table} b
                ON a.wishlist_id = b.wishlist_id
               AND a.product_id  = b.product_id
               AND a.variation_id = b.variation_id
               AND a.item_id > b.item_id"
        );
    }
}
