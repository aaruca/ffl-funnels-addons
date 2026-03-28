<?php
/**
 * WSS Activator — Creates database tables and default options.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Activator
{
    /**
     * Run on module activation.
     */
    public static function activate(): void
    {
        self::create_tables();
        self::set_default_options();
    }

    /**
     * Create the wss_log table.
     */
    private static function create_tables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name      = $wpdb->prefix . 'wss_log';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            direction varchar(20) NOT NULL,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(10) NOT NULL,
            message text DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY direction (direction),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Set default options if they don't exist.
     */
    private static function set_default_options(): void
    {
        if (false === get_option('wss_settings')) {
            update_option('wss_settings', [
                'sheet_id' => '',
                'tab_name' => 'Inventory',
            ]);
        }
    }
}
