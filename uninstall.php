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
    // Clean up per-order meta (classic orders in postmeta).
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_ffl_vendor_id', '_ffl_vendor_name', '_ffl_vendor_license')");
    // HPOS order meta table when WooCommerce uses custom order tables.
    $orders_meta = $wpdb->prefix . 'wc_orders_meta';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $orders_meta)) === $orders_meta) {
        $wpdb->query("DELETE FROM {$orders_meta} WHERE meta_key IN ('_ffl_vendor_id', '_ffl_vendor_name', '_ffl_vendor_license')");
    }
}

// ── Tax Rates cleanup ───────────────────────────────────────────────
$tax_has_tables = (bool) $wpdb->get_var($wpdb->prepare(
    'SHOW TABLES LIKE %s',
    $wpdb->prefix . 'ffla_tax_%'
));

if (in_array('tax-rates', $ffla_active_modules, true) || $tax_has_tables || get_option('ffla_tax_resolver_settings')) {
    $tax_db_file = dirname(__FILE__) . '/modules/tax-rates/includes/class-tax-resolver-db.php';
    if (file_exists($tax_db_file)) {
        require_once $tax_db_file;
        if (class_exists('Tax_Resolver_DB')) {
            Tax_Resolver_DB::uninstall();
        }
    }

    delete_option('ffla_tax_resolver_settings');
    delete_option('ffla_tax_resolver_db_version');
    delete_option('ffla_tax_usgeocoder_usage');
    delete_option('ffla_tax_rates_settings');
    delete_option('ffla_tax_rates_last_import');

    delete_transient('ffla_tax_key_validation');

    wp_clear_scheduled_hook('ffla_tax_dataset_sync');
    wp_clear_scheduled_hook('ffla_tax_cache_cleanup');
    wp_clear_scheduled_hook('ffla_tax_audit_purge');
}

// ── Woo Sheets Sync cleanup ────────────────────────────────────────
if (in_array('woo-sheets-sync', $ffla_active_modules, true)) {
    // Active runtime options used by the module.
    delete_option('wss_settings');
    delete_option('wss_google_tokens');
    delete_option('wss_last_sync');
    // Legacy option keys kept for backward-compat cleanup.
    delete_option('wss_oauth_tokens');
    delete_option('wss_field_map');
    delete_option('wss_sync_status');

    // Transients used during OAuth/sync.
    delete_transient('wss_oauth_state');

    // Unschedule cron events.
    wp_clear_scheduled_hook('wss_daily_sync');

    // Clean up any leftover per-product sync meta.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wss_sync_enabled'");

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

// ── Product Reviews cleanup ─────────────────────────────────────────
if (in_array('product-reviews', $ffla_active_modules, true)) {
    delete_option('ffla_product_reviews_settings');

    wp_unschedule_hook('ffla_send_product_review_request');
    wp_unschedule_hook('ffla_send_order_review_bundle');

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('ffla_send_product_review_request', null, 'ffla-product-reviews');
        as_unschedule_all_actions('ffla_send_order_review_bundle', null, 'ffla-product-reviews');
    }
}

// ── FFLA core cleanup ──────────────────────────────────────────────
delete_option('ffla_active_modules');
delete_transient('ffla_github_release');
delete_transient('ffla_github_api_error');
