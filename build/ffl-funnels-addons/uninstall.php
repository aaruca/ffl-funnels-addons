<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Check if user opted to delete data on uninstall.
$ffla_active_modules = get_option('ffla_active_modules', []);

// WooBooster cleanup.
if (in_array('woobooster', $ffla_active_modules, true)) {
    $wb_settings = get_option('woobooster_settings', []);

    if (!empty($wb_settings['delete_data_uninstall'])) {
        global $wpdb;

        // Drop custom tables.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_rules");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_rule_conditions");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_rule_actions");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_rule_index");

        // Delete options.
        delete_option('woobooster_settings');
        delete_option('woobooster_db_version');
        delete_option('woobooster_last_build');

        // Delete transients.
        delete_transient('woobooster_github_release');
        delete_transient('woobooster_github_api_error');

        // Clear copurchase meta from all products.
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_woobooster_copurchased'");
    }
}

// Wishlist cleanup (always clean up tables if module was active).
if (in_array('wishlist', $ffla_active_modules, true)) {
    global $wpdb;

    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}alg_wishlists");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}alg_wishlist_items");

    delete_option('alg_wishlist_settings');
}

// FFLA core cleanup.
delete_option('ffla_active_modules');
delete_transient('ffla_github_release');
delete_transient('ffla_github_api_error');
