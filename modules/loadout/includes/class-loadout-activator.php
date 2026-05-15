<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Activator
{
    const META_LOADOUT_ID        = '_ffla_loadout_id';
    const META_LOADOUT_TIER      = '_ffla_loadout_tier_id';
    const META_LOADOUT_SOURCE    = '_ffla_loadout_source';
    const META_PRODUCT_LOADOUT   = '_ffla_product_loadout_id';
    const META_SET_DISCOUNT      = '_ffla_set_discount_flag';

    public static function activate(): void
    {
        self::migrate_tables();
    }

    public static function migrate_tables(): void
    {
        global $wpdb;

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $current_version = get_option('ffla_loadout_db_version', '0.0.0');
        if (version_compare($current_version, '1.0.0', '>=')) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();

        // wp_ffla_loadouts table.
        $table_loadouts = $wpdb->prefix . 'ffla_loadouts';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_loadouts)) !== $table_loadouts) {
            $sql = "CREATE TABLE $table_loadouts (
                id              BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
                name            VARCHAR(255) NOT NULL,
                slug            VARCHAR(191) NOT NULL,
                status          TINYINT(1) DEFAULT 1,
                anchor_product_id BIGINT(20) DEFAULT NULL,
                hero_image_id   BIGINT(20) DEFAULT NULL,
                brand_logo_id   BIGINT(20) DEFAULT NULL,
                headline        VARCHAR(255) DEFAULT NULL,
                subheadline     VARCHAR(255) DEFAULT NULL,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY slug (slug),
                KEY status (status)
            ) $charset_collate;";
            dbDelta($sql);
        }

        // wp_ffla_loadout_tiers table.
        $table_tiers = $wpdb->prefix . 'ffla_loadout_tiers';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_tiers)) !== $table_tiers) {
            $sql = "CREATE TABLE $table_tiers (
                id                  BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
                loadout_id          BIGINT(20) NOT NULL,
                name                VARCHAR(100) NOT NULL,
                slug                VARCHAR(100) NOT NULL,
                sort_order          INT(11) DEFAULT 0,
                accessory_discount  DECIMAL(5,2) DEFAULT 0,
                set_discount_pct    DECIMAL(5,2) DEFAULT 0,
                perks_json          LONGTEXT DEFAULT NULL,
                bonus_product_id    BIGINT(20) DEFAULT NULL,
                bonus_label         VARCHAR(255) DEFAULT NULL,
                bonus_display_value DECIMAL(10,2) DEFAULT NULL,
                threshold_items     INT(11) DEFAULT 0,
                KEY loadout_id (loadout_id),
                KEY sort_order (sort_order)
            ) $charset_collate;";
            dbDelta($sql);
        }

        // wp_ffla_loadout_tier_items table.
        $table_items = $wpdb->prefix . 'ffla_loadout_tier_items';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_items)) !== $table_items) {
            $sql = "CREATE TABLE $table_items (
                id              BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
                tier_id         BIGINT(20) NOT NULL,
                product_id      BIGINT(20) NOT NULL,
                quantity        INT(11) DEFAULT 1,
                discount_pct    DECIMAL(5,2) DEFAULT 0,
                is_required     TINYINT(1) DEFAULT 0,
                sort_order      INT(11) DEFAULT 0,
                KEY tier_id (tier_id)
            ) $charset_collate;";
            dbDelta($sql);
        }

        // wp_ffla_loadout_cross_sells table.
        $table_cross_sells = $wpdb->prefix . 'ffla_loadout_cross_sells';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_cross_sells)) !== $table_cross_sells) {
            $sql = "CREATE TABLE $table_cross_sells (
                id              BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
                loadout_id      BIGINT(20) NOT NULL,
                label           VARCHAR(255) NOT NULL,
                image_id        BIGINT(20) DEFAULT NULL,
                link_type       VARCHAR(20) DEFAULT 'category',
                link_value      VARCHAR(500) DEFAULT NULL,
                sort_order      INT(11) DEFAULT 0,
                KEY loadout_id (loadout_id)
            ) $charset_collate;";
            dbDelta($sql);
        }

        update_option('ffla_loadout_db_version', '1.0.0');
    }
}
