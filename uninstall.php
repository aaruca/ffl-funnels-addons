<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Destructive cleanup of customer-facing data (WooBooster rules/bundles,
 * wishlists) is gated behind each module's own "delete data on uninstall"
 * opt-in, so reinstalling never silently wipes live data.
 *
 * Modules whose data can be detected independently (tax-rates) are cleaned even
 * when the module is not currently active; the rest are cleaned when active.
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
        // Rules engine tables.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_rules");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_rule_conditions");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_rule_actions");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_rule_index");

        // Bundle tables.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_bundles");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_bundle_items");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_bundle_actions");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_bundle_conditions");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}woobooster_bundle_index");

        // Delete options. (atc_counter / cache_version are referenced through
        // class constants, so a grep for the literal name misses them.)
        delete_option('woobooster_settings');
        delete_option('woobooster_version');
        delete_option('woobooster_db_version');
        delete_option('woobooster_last_build');
        delete_option('woobooster_atc_counter');   // WooBooster_Tracker::COUNTER_OPTION
        delete_option('woobooster_cache_version'); // WooBooster_Matcher::CACHE_VERSION

        // Delete transients.
        delete_transient('woobooster_github_release');
        delete_transient('woobooster_github_api_error');

        // Clear copurchase meta from all products.
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_woobooster_copurchased'");
    }

    // Cron events are cleared on deactivate(), but uninstall can run without a
    // prior deactivation. Unscheduling is non-destructive, so it stays outside
    // the delete-data opt-in.
    wp_clear_scheduled_hook('woobooster_copurchase_event');
    wp_clear_scheduled_hook('woobooster_trending_event');
}

// ── Wishlist cleanup (opt-in) ───────────────────────────────────────
// Customer wishlists are destroyed only when the module's own delete-data
// setting is enabled. Previously an uninstall (a common troubleshooting step
// before reinstalling) permanently wiped every customer's wishlist.
if (in_array('wishlist', $ffla_active_modules, true)) {
    $wl_settings = get_option('alg_wishlist_settings', []);

    if (is_array($wl_settings) && !empty($wl_settings['delete_data_uninstall'])) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}alg_wishlists");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}alg_wishlist_items");

        delete_option('alg_wishlist_settings');
        delete_option('alg_wishlist_version');
        delete_option('alg_wishlist_db_version');
    }
}

// ── Legacy cleanup: Doofinder Sync (addon removed in v1.31.0) ───────
// Purge orphaned options from installs that ran the old Doofinder Sync
// addon, so uninstalling never leaves stale rows behind.
delete_option('dsync_settings');
delete_option('dsync_layer_hash');

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
    delete_option('ffla_tax_last_cache_flush');

    delete_transient('ffla_tax_key_validation');

    wp_clear_scheduled_hook('ffla_tax_dataset_sync');
    wp_clear_scheduled_hook('ffla_tax_cache_cleanup');
    wp_clear_scheduled_hook('ffla_tax_audit_purge');
    wp_clear_scheduled_hook('ffla_tax_cache_flush');
}

// ── Woo Sheets Sync cleanup ────────────────────────────────────────
if (in_array('woo-sheets-sync', $ffla_active_modules, true)) {
    // Active runtime options used by the module.
    delete_option('wss_settings');
    delete_option('wss_google_tokens');
    delete_option('wss_last_sync');
    delete_option('wss_row_map');
    // Legacy option keys kept for backward-compat cleanup.
    delete_option('wss_oauth_tokens');
    delete_option('wss_field_map');
    delete_option('wss_sync_status');

    // Transients used during OAuth/sync.
    delete_transient('wss_oauth_state');
    delete_transient('wss_sa_access_token');
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wss_oauth_state_%' OR option_name LIKE '_transient_timeout_wss_oauth_state_%'");

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

    // `ffla_review_email_optouts` is deliberately NOT deleted. It is the record
    // of who asked to stop receiving review requests. Dropping it would mean a
    // reinstall silently starts emailing those people again.

    wp_unschedule_hook('ffla_send_product_review_request');
    wp_unschedule_hook('ffla_send_order_review_bundle');

    if (function_exists('as_unschedule_all_actions')) {
        as_unschedule_all_actions('ffla_send_product_review_request', null, 'ffla-product-reviews');
        as_unschedule_all_actions('ffla_send_order_review_bundle', null, 'ffla-product-reviews');
    }

    // Cached rating histograms — one transient per product, so they cannot be
    // enumerated by name.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\_transient\_ffla\_rev\_dist\_%'
            OR option_name LIKE '\_transient\_timeout\_ffla\_rev\_dist\_%'"
    );
}

// ── Loadout cleanup (opt-in) ────────────────────────────────────────
// Loadout's four tables hold merchant-authored configuration (loadouts, tiers,
// tier items, cross-sells), not regenerable caches — so uninstalling never
// destroys them unless the module's delete-data flag is explicitly set. This
// branch previously did not exist at all, leaving the tables and the DB-version
// option orphaned forever.
if (in_array('loadout', $ffla_active_modules, true)) {
    $lo_settings = get_option('ffla_loadout_settings', []);

    if (is_array($lo_settings) && !empty($lo_settings['delete_data_uninstall'])) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffla_loadout_cross_sells");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffla_loadout_tier_items");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffla_loadout_tiers");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffla_loadouts");

        delete_option('ffla_loadout_settings');
        delete_option('ffla_loadout_db_version');
    }
}

// ── Media Cleaner cleanup ──────────────────────────────────────────
// Drop the tracking tables, options, and cron. The trash FOLDER
// (uploads/ffla-media-trash) is deliberately left on disk: it holds real files
// a shop chose to remove but could still restore, and uninstalling a plugin
// must never permanently destroy media. Delete that folder by hand if wanted.
if (in_array('media-cleaner', $ffla_active_modules, true)) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffla_mclean_scan");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ffla_mclean_refs");

    delete_option('ffla_media_cleaner_settings');
    delete_option('ffla_media_cleaner_db_version');
    delete_option('ffla_mclean_job');
    delete_option('ffla_mclean_file_list');

    $mc_ts = wp_next_scheduled('ffla_mclean_auto_empty');
    while ($mc_ts) {
        wp_unschedule_event($mc_ts, 'ffla_mclean_auto_empty');
        $mc_ts = wp_next_scheduled('ffla_mclean_auto_empty');
    }

    // The ignore flag lives on attachments; sweep it so removing the plugin
    // does not leave stray post meta behind.
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_ffla_mclean_ignored']);
}

// ── FFLA core cleanup ──────────────────────────────────────────────
delete_option('ffla_active_modules');
delete_transient('ffla_github_release');
delete_transient('ffla_github_api_error');
