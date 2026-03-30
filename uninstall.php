<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleans up all data created by any module that was ever active.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$ffla_active_modules = get_option('ffla_active_modules', []);

// ── WooBooster cleanup ──────────────────────────────────────────────
if (in_array('woobooster', $ffla_active_modules, true)) {
    $wb_settings = get_option('woobooster_settings', []);

    if (!empty($wb_settings['delete_data_uninstall'])) {
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

// ── Wishlist cleanup ────────────────────────────────────────────────
if (in_array('wishlist', $ffla_active_modules, true)) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}alg_wishlists");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}alg_wishlist_items");

    delete_option('alg_wishlist_settings');
}

// ── Doofinder Sync cleanup ──────────────────────────────────────────
if (in_array('doofinder-sync', $ffla_active_modules, true)) {
    delete_option('dsync_settings');
    delete_option('dsync_layer_hash');
}

// ── FFL Checkout cleanup ────────────────────────────────────────────
if (in_array('ffl-checkout', $ffla_active_modules, true)) {
    delete_option('ffl_checkout_settings');
    // Clean up per-order meta.
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_ffl_vendor_id', '_ffl_vendor_name', '_ffl_vendor_license')");
}

// ── Tax Rates cleanup ───────────────────────────────────────────────
if (in_array('tax-rates', $ffla_active_modules, true)) {
    delete_option('ffla_tax_rates_settings');
    delete_option('ffla_tax_rates_last_import');
}

// ── Woo Sheets Sync cleanup ────────────────────────────────────────
if (in_array('woo-sheets-sync', $ffla_active_modules, true)) {
    delete_option('wss_settings');
    delete_option('wss_oauth_tokens');
    delete_option('wss_field_map');
    delete_option('wss_sync_status');

    // Clean up debug log files.
    $upload_dir = wp_upload_dir();
    $log_dir    = $upload_dir['basedir'] . '/wss-logs';
    if (is_dir($log_dir)) {
        array_map('unlink', glob("$log_dir/*"));
        @rmdir($log_dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
    // Legacy log file location.
    $legacy_log = $upload_dir['basedir'] . '/wss-oauth-debug.log';
    if (file_exists($legacy_log)) {
        @unlink($legacy_log); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }
}

// ── FFLA core cleanup ──────────────────────────────────────────────
delete_option('ffla_active_modules');
delete_transient('ffla_github_release');
delete_transient('ffla_github_api_error');
