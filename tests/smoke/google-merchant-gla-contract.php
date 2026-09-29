<?php
/**
 * Contract checks against a real Google for WooCommerce (google-listings-and-ads)
 * copy. The Google Merchant Policy module relies on these upstream hooks, classes,
 * meta keys and priorities; an upstream change must fail CI instead of silently
 * weakening feed protection. Reads source files only, loads nothing.
 *
 * Run: php tests/smoke/google-merchant-gla-contract.php /path/to/google-listings-and-ads
 * Without a path (argument or FFLA_GLA_ROOT) the check is skipped.
 */

$root = $argv[1] ?? (getenv('FFLA_GLA_ROOT') ?: '');
if ($root === '') {
    echo "Google for WooCommerce contract: skipped (pass the plugin directory).\n";
    exit(0);
}
$root = rtrim($root, '/\\');

$checks = 0;
$failures = [];
$sources = [];

function gla_source(string $relative): string
{
    global $root, $sources, $failures;
    if (!array_key_exists($relative, $sources)) {
        $path = $root . '/' . $relative;
        $sources[$relative] = is_file($path) ? (string) file_get_contents($path) : null;
        if ($sources[$relative] === null) {
            $failures[] = "Missing file: $relative";
        }
    }
    return (string) $sources[$relative];
}

function expect_source(string $relative, string $pattern, string $label): ?array
{
    global $checks, $failures;
    $checks++;
    if (preg_match($pattern, gla_source($relative), $matches) !== 1) {
        $failures[] = "$label ($relative)";
        return null;
    }
    return $matches;
}

$version = expect_source('google-listings-and-ads.php', "/define\(\s*'WC_GLA_VERSION'\s*,\s*'([^']+)'/", 'WC_GLA_VERSION is defined (dependency detection)');

// Enforce filters every sync-ready product list through this pre-filter.
expect_source('src/Product/ProductFilter.php', "/public function filter_sync_ready_products\(\s*array \\\$products\s*\)/", 'Sync-ready filter receives WC_Product[]');
expect_source('src/Product/ProductFilter.php', "/apply_filters\(\s*'woocommerce_gla_get_sync_ready_products_pre_filter'\s*,\s*\\\$products\s*\)/", 'Pre-filter hook applied to the product list');
expect_source('src/Product/ProductRepository.php', "/function find_sync_ready_products\(.*?product_filter->filter_sync_ready_products\(/s", 'Sync-ready queries pass through ProductFilter');

// The queued update job is wrapped by class, method and hook name.
expect_source('src/Jobs/UpdateProducts.php', "/namespace Automattic\\\\WooCommerce\\\\GoogleListingsAndAds\\\\Jobs;/", 'UpdateProducts namespace');
expect_source('src/Jobs/UpdateProducts.php', "/class UpdateProducts extends AbstractProductSyncerJob\b/", 'UpdateProducts class');
expect_source('src/Jobs/UpdateProducts.php', "/function get_name\(\)\s*:\s*string\s*\{\s*return 'update_products';/", 'Job name update_products');
expect_source('src/Jobs/AbstractProductSyncerJob.php', "/abstract class AbstractProductSyncerJob extends AbstractActionSchedulerJob\b/", 'Product syncer job uses the base Action Scheduler job');
expect_source('src/Jobs/AbstractActionSchedulerJob.php', "/add_action\(\s*\\\$this->get_process_item_hook\(\)\s*,\s*\[\s*\\\$this\s*,\s*'handle_process_items_action'\s*\]\s*\)/", 'Process hook registered at default priority with one argument');
expect_source('src/Jobs/AbstractActionSchedulerJob.php', "/public function handle_process_items_action\(\s*array \\\$items = \[\]\s*\)/", 'handle_process_items_action(array $items)');
expect_source('src/Jobs/AbstractActionSchedulerJob.php', "/return \"\{\\\$this->get_hook_base_name\(\)\}process_item\";/", 'Process hook suffix');
expect_source('src/Jobs/AbstractActionSchedulerJob.php', "/return \"\{\\\$this->get_slug\(\)\}\/jobs\/\{\\\$this->get_name\(\)\}\/\";/", 'Job hook base gla/jobs/<name>/');
expect_source('src/PluginHelper.php', "/function get_slug\(\)\s*:\s*string\s*\{[^}]*return 'gla';/s", 'Plugin slug gla');

// Feed exclusion and removal meta.
expect_source('src/PluginHelper.php', "/function get_meta_key_prefix\(\)\s*:\s*string\s*\{[^}]*return \"_wc_\{\\\$this->get_slug\(\)\}\";/s", 'Meta prefix _wc_gla');
expect_source('src/PluginHelper.php', "/return \"\{\\\$prefix\}_\{\\\$key\}\";/", 'Meta key format <prefix>_<key>');
foreach (['KEY_VISIBILITY' => 'visibility', 'KEY_GOOGLE_IDS' => 'google_ids', 'KEY_SYNCED_AT' => 'synced_at'] as $constant => $value) {
    expect_source('src/Product/ProductMetaHandler.php', "/const $constant\s*=\s*'$value';/", "ProductMetaHandler::$constant");
}
expect_source('src/Value/ChannelVisibility.php', "/const DONT_SYNC_AND_SHOW\s*=\s*'dont-sync-and-show';/", 'Channel visibility value dont-sync-and-show');
expect_source('src/Value/ChannelVisibility.php', "/const SYNC_AND_SHOW\s*=\s*'sync-and-show';/", 'Channel visibility value sync-and-show');
expect_source('src/Product/ProductHelper.php', "/function is_sync_ready\(.*?ChannelVisibility::DONT_SYNC_AND_SHOW !== \\\$this->get_channel_visibility\(\s*\\\$product\s*\)/s", 'Excluded visibility makes a product not sync-ready');
expect_source('src/Product/ProductHelper.php', "/function is_product_synced\(.*?get_synced_at\(.*?get_google_ids\(/s", 'Synced means synced_at plus Google IDs');
expect_source('src/Product/BatchProductHelper.php', "/!\s*\\\$this->product_helper->is_sync_ready\(\s*\\\$product\s*\)/", 'Every push re-checks sync readiness');

// Uploads and removals are delegated to Google's own lifecycle; our save hook runs before it.
expect_source('src/Product/SyncerHooks.php', "/namespace Automattic\\\\WooCommerce\\\\GoogleListingsAndAds\\\\Product;/", 'SyncerHooks namespace');
expect_source('src/Product/SyncerHooks.php', "/add_action\(\s*'woocommerce_update_product'\s*,\s*\[\s*\\\$this\s*,\s*'update_by_object'\s*\]\s*,\s*90\s*,\s*2\s*\)/", 'SyncerHooks::update_by_object on woocommerce_update_product at 90 (after our 80)');
expect_source('src/Product/SyncerHooks.php', "/public function pre_delete\(\s*int \\\$product_id\s*\)/", 'SyncerHooks::pre_delete(int)');
expect_source('src/Product/SyncerHooks.php', "/public function delete\(\s*int \\\$product_id\s*\)/", 'SyncerHooks::delete(int)');
$metabox = expect_source('src/Admin/MetaBox/ChannelVisibilityMetaBox.php', "/add_action\(\s*'woocommerce_update_product'\s*,\s*\[\s*\\\$this\s*,\s*'handle_submission'\s*\]\s*,\s*(\d+)/", 'Channel visibility field saved on woocommerce_update_product');
$checks++;
if ($metabox !== null && (int) $metabox[1] >= 80) {
    $failures[] = 'Channel visibility field must save before our priority-80 enforcement (found ' . $metabox[1] . ')';
}

// Two-way sync: an allowed product is uploaded through SyncerHooks' own update flow.
expect_source('src/Product/SyncerHooks.php', "/public function update_by_object\(\s*int \\\$product_id\s*,\s*WC_Product \\\$product\s*\)/", 'SyncerHooks::update_by_object(int, WC_Product)');
expect_source('src/Product/SyncerHooks.php', "/if \(\s*\\\$this->product_helper->is_sync_ready\(\s*\\\$product\s*\)\s*\)\s*\{.*?elseif \(\s*\\\$this->product_helper->is_product_synced\(\s*\\\$product\s*\)\s*\).*?get\(\s*UpdateProducts::class\s*\)->schedule\(.*?get\(\s*DeleteProducts::class\s*\)->schedule\(/s", 'update_by_object uploads sync-ready products and removes synced ones');
expect_source('src/Product/ProductHelper.php', "/function get_channel_visibility\(.*?get_visibility\(\s*\\\$this->maybe_swap_for_parent\(\s*\\\$wc_product\s*\)\s*\)/s", 'Variation visibility is read from the parent');

// Every product upload job is wrapped by class and hook name.
foreach (['UpdateAllProducts' => 'update_all_products', 'ResubmitExpiringProducts' => 'resubmit_expiring_products'] as $class => $name) {
    expect_source("src/Jobs/$class.php", "/namespace Automattic\\\\WooCommerce\\\\GoogleListingsAndAds\\\\Jobs;/", "$class namespace");
    expect_source("src/Jobs/$class.php", "/class $class extends AbstractProductSyncerBatchedJob\b/", "$class class");
    expect_source("src/Jobs/$class.php", "/function get_name\(\)\s*:\s*string\s*\{\s*return '$name';/", "Job name $name");
}
expect_source('src/Jobs/AbstractProductSyncerBatchedJob.php', "/abstract class AbstractProductSyncerBatchedJob extends AbstractBatchedActionSchedulerJob\b/", 'Batched product jobs use the batched base job');
expect_source('src/Jobs/AbstractBatchedActionSchedulerJob.php', "/public function init\(\)\s*:\s*void\s*\{[^}]*parent::init\(\);/", 'Batched jobs register the same process hook');

// In Enforce this module replaces the product Channel visibility box.
expect_source('src/Admin/MetaBox/ChannelVisibilityMetaBox.php', "/public const ID\s*=\s*'channel_visibility';/", 'Channel visibility box ID');
expect_source('src/Admin/MetaBox/ChannelVisibilityMetaBox.php', "/function get_screen\(\)\s*:\s*string\s*\{\s*return self::SCREEN_PRODUCT;/", 'Channel visibility box on the product screen');
expect_source('src/Admin/MetaBox/ChannelVisibilityMetaBox.php', "/function get_context\(\)\s*:\s*string\s*\{\s*return self::CONTEXT_SIDE;/", 'Channel visibility box in the side context');
expect_source('src/Admin/MetaBox/MetaBoxInterface.php', "/SCREEN_PRODUCT\s*=\s*'product';.*?CONTEXT_SIDE\s*=\s*'side';/s", 'Meta box screen and context values');
expect_source('src/Admin/MetaBox/MetaBoxInitializer.php', "/add_action\(\s*'add_meta_boxes'\s*,\s*\[\s*\\\$this\s*,\s*'register_meta_boxes'\s*\]\s*\)/", 'Google meta boxes registered at default priority (ours removes at 100)');
expect_source('src/Admin/Admin.php', "/add_meta_box\(\s*\\\$meta_box->get_id\(\)/", 'Meta box registered under its own ID');
expect_source('src/Admin/MetaBox/ChannelVisibilityMetaBox.php', "/!\s*isset\(\s*\\\$_POST\[\s*\\\$field_id\s*\]\s*\)/", 'Visibility is only saved when its field is posted');
expect_source('js/build/channel-visibility-meta-box.js', "/querySelector\(\s*[\"']#gla-channel-visibility-box[\"']\s*\)\s*;?\s*if\s*\(\s*!\s*[\w$]+\s*\)\s*return/", 'Channel visibility script exits when its box is removed');

// In Enforce this module refuses the Product Feed bulk visibility edit.
expect_source('src/API/Site/Controllers/BaseController.php', "/function get_namespace\(\)\s*:\s*string\s*\{\s*return \"wc\/\{\\\$this->get_slug\(\)\}\";/", 'Google REST namespace wc/gla');
expect_source('src/API/Site/Controllers/MerchantCenter/ProductVisibilityController.php', "/register_route\(\s*'mc\/product-visibility'\s*,\s*\[\s*\[\s*'methods'\s*=>\s*TransportMethods::EDITABLE/", 'Bulk visibility route mc/product-visibility');
expect_source('src/API/TransportMethods.php', "/const EDITABLE\s*=\s*'POST, PUT, PATCH';/", 'Bulk visibility accepts POST, PUT and PATCH');

// Google's pull proxy: product lists honour _wc_gla_visibility, variations are
// covered by this module's own filters on the same request marker.
expect_source('src/Integration/WPCOMProxy.php', "/get_param\(\s*'gla_syncable'\s*\)\s*===\s*'1'/", 'Proxy requests marked gla_syncable=1');
expect_source('src/Integration/WPCOMProxy.php', "/KEY_VISIBILITY\s*=\s*'_wc_gla_visibility';/", 'Proxy filters on _wc_gla_visibility');
expect_source('src/Integration/WPCOMProxy.php', "/'woocommerce_rest_' \. \\\$object_type \. '_object_query'/", 'Proxy uses the WooCommerce REST object query filters');

if ($failures) {
    fwrite(STDERR, "Google for WooCommerce contract FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo 'Google for WooCommerce ' . ($version[1] ?? '?') . " contract: $checks checks passed.\n";
