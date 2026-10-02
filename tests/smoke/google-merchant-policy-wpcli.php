<?php
/**
 * Google Merchant Policy scan checks against a real WordPress + WooCommerce site.
 *
 * Usage: wp eval-file tests/smoke/google-merchant-policy-wpcli.php
 * Creates categories and a product, then deletes them. Settings and scan state
 * are restored.
 */
$GLOBALS['g_pass'] = 0;
$GLOBALS['g_fail'] = 0;
function g_ck($c, $m) { if ($c) { $GLOBALS['g_pass']++; echo "  ok  $m\n"; } else { $GLOBALS['g_fail']++; echo "  FAIL $m\n"; } }
function g_drain() {
    for ($i = 0; $i < 200; $i++) {
        $s = Google_Merchant_Policy_Reconciler::get_state();
        if ($s['status'] !== 'running') { return $s; }
        delete_option(Google_Merchant_Policy_Reconciler::LOCK);
        Google_Merchant_Policy_Reconciler::run_batch((string) $s['scan_id']);
    }
    return Google_Merchant_Policy_Reconciler::get_state();
}

$saved_settings = get_option(Google_Merchant_Policy_Engine::OPTION, null);
$saved_state    = get_option(Google_Merchant_Policy_Reconciler::STATE_OPTION, null);

$root  = wp_insert_term('GMP Test Root', 'product_cat');
$child = wp_insert_term('GMP Test Child', 'product_cat', ['parent' => $root['term_id']]);
$other = wp_insert_term('GMP Test Other', 'product_cat');

echo "Scan lifecycle\n";
Google_Merchant_Policy_Reconciler::start();
g_ck((bool) wp_next_scheduled(Google_Merchant_Policy_Reconciler::WATCHDOG), 'watchdog scheduled while running');
$s = g_drain();
g_ck($s['status'] === 'complete', 'scan completes');
g_ck(!wp_next_scheduled(Google_Merchant_Policy_Reconciler::WATCHDOG), 'watchdog removed when the scan completes');

echo "Category edits\n";
Google_Merchant_Policy_Reconciler::start();
$id_before = Google_Merchant_Policy_Reconciler::get_state()['scan_id'];
wp_update_term($child['term_id'], 'product_cat', ['name' => 'GMP Test Child Renamed', 'description' => 'x']);
g_ck(Google_Merchant_Policy_Reconciler::get_state()['scan_id'] === $id_before, 'rename does not restart the scan');
Google_Merchant_Policy_Engine::set_category_policy((int) $child['term_id'], 'allow');
wp_update_term($child['term_id'], 'product_cat', ['name' => 'GMP Test Child Renamed']);
$id_after_rule = Google_Merchant_Policy_Reconciler::get_state()['scan_id'];
g_ck($id_after_rule !== $id_before, 'rule change restarts the scan');
Google_Merchant_Policy_Engine::set_category_policy((int) $child['term_id'], 'allow');
wp_update_term($child['term_id'], 'product_cat', ['name' => 'GMP Test Child Renamed']);
g_ck(Google_Merchant_Policy_Reconciler::get_state()['scan_id'] === $id_after_rule, 'saving the same rule again does not restart');
wp_update_term($child['term_id'], 'product_cat', ['parent' => $other['term_id']]);
g_ck(Google_Merchant_Policy_Reconciler::get_state()['scan_id'] !== $id_after_rule, 'moving the category under another parent restarts');

echo "Switching the module off pauses the scan\n";
Google_Merchant_Policy_Reconciler::pause_for_deactivation();
$s = Google_Merchant_Policy_Reconciler::get_state();
g_ck($s['status'] === 'paused' && $s['last_error'] !== '', 'running scan paused with an explanation');
Google_Merchant_Policy_Reconciler::recover();
g_ck(Google_Merchant_Policy_Reconciler::get_state()['status'] === 'paused', 'page loads do not resume it silently');

echo "One product cannot stop the scan\n";
update_option(Google_Merchant_Policy_Engine::OPTION, array_merge(Google_Merchant_Policy_Engine::get_settings(), ['mode' => 'enforce']), false);
Google_Merchant_Policy_Engine::reset_runtime_cache();
$p = new WC_Product_Simple();
$p->set_name('GMP stuck product');
$p->set_regular_price('5');
$p->set_status('publish');
$p->set_category_ids([(int) $root['term_id']]); // root is Pending → removed in Enforce
$p->update_meta_data('_wc_gla_google_ids', ['US' => 'online:en:US:gla_1']);
$pid = $p->save();
$p2 = new WC_Product_Simple();
$p2->set_name('GMP later product');
$p2->set_regular_price('5');
$p2->set_status('publish');
$pid2 = $p2->save();
Google_Merchant_Policy_Reconciler::start();
$s = g_drain();
g_ck($s['status'] === 'complete', 'scan completes despite the bad product (status ' . $s['status'] . ')');
g_ck((int) $s['item_errors'] >= 1 && (int) $s['error_items'][0]['id'] === $pid, 'bad product recorded for review');
g_ck((string) get_post_meta($pid2, Google_Merchant_Policy_Engine::STATUS_META, true) !== '', 'products after it were still processed');

wp_delete_post($pid, true);
wp_delete_post($pid2, true);
foreach ([$child, $other, $root] as $t) { wp_delete_term($t['term_id'], 'product_cat'); }
Google_Merchant_Policy_Reconciler::clear_schedule();
if ($saved_settings === null) { delete_option(Google_Merchant_Policy_Engine::OPTION); } else { update_option(Google_Merchant_Policy_Engine::OPTION, $saved_settings, false); }
if ($saved_state === null) { delete_option(Google_Merchant_Policy_Reconciler::STATE_OPTION); } else { update_option(Google_Merchant_Policy_Reconciler::STATE_OPTION, $saved_state, false); }
delete_transient('ffla_gmp_recover_check');

echo "\n{$GLOBALS['g_pass']} passed, {$GLOBALS['g_fail']} failed\n";
if ($GLOBALS['g_fail'] > 0) { exit(1); }
