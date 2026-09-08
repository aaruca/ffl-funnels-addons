<?php
/** Standalone regression harness. Run: php tests/smoke/google-merchant-policy-smoke.php */
namespace Automattic\WooCommerce\GoogleListingsAndAds\Jobs {
    class UpdateProducts {
        public $calls = [];
        public function handle_process_items_action(array $items = []) { $this->calls[] = $items; }
    }
}
namespace Automattic\WooCommerce\GoogleListingsAndAds\Product {
    class SyncerHooks {
        public $prepared = [];
        public $deleted = [];
        public function update_by_object($id, $product) {}
        public function pre_delete($id) { $this->prepared[] = $id; }
        public function delete($id) { $this->deleted[] = $id; }
    }
}
namespace {
// Optional official wp-includes/class-wp-hook.php verifies mutation while the
// real WordPress dispatcher is iterating: php this-file.php /path/to/class-wp-hook.php
if (!empty($argv[1])) { require $argv[1]; }
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
$options = $products = $jobs = $cron = $wp_filter = [];
$as_fail = $cron_fail = false;
$checks = 0;
function check($test, $message) {
    global $checks;
    $checks++;
    if (!$test) { throw new \RuntimeException("FAIL: $message"); }
}
function __($text, $domain = '') { return $text; }
function wp_parse_args($value, $defaults = []) { return array_merge($defaults, $value); }
function sanitize_key($s) { return strtolower($s); }
function sanitize_text_field($s) { return strip_tags($s); }
function wp_strip_all_tags($s) { return strip_tags($s); }
function is_wp_error($v) { return false; }
function wp_generate_uuid4() { static $n = 0; return 'generation-' . ++$n; }
function wp_cache_delete($key, $group = '') {}
function maybe_serialize($v) { return serialize($v); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value, $deprecated = '', $autoload = false) {
    if (array_key_exists($key, $GLOBALS['options'])) { return false; }
    return update_option($key, $value);
}
function get_post_meta($id, $key, $single = true) { return $GLOBALS['products'][$id]->meta[$key] ?? ''; }
function get_term_meta($id, $key, $single = true) { return $GLOBALS['policies'][$id] ?? 'allow'; }
function update_term_meta($id, $key, $value) { $GLOBALS['policies'][$id] = $value; return true; }
function get_term($id, $taxonomy = '') { return (object) ['parent' => $id === 2 ? 1 : 0, 'name' => 'Accessories']; }
function wp_get_post_terms($id, $taxonomy, $args = []) {
    return ($args['fields'] ?? '') === 'names' ? ['Accessories'] : ($GLOBALS['products'][$id]->terms ?? []);
}
function wc_get_product($id) { return $GLOBALS['products'][$id] ?? false; }
function callback_key($cb) {
    return is_array($cb) ? (is_object($cb[0]) ? spl_object_hash($cb[0]) : $cb[0]) . $cb[1] : (is_object($cb) ? spl_object_hash($cb) : $cb);
}
function _wp_filter_build_unique_id($hook, $callback, $priority) { return callback_key($callback); }
function add_action($hook, $cb, $priority = 10, $args = 1) {
    if (class_exists('WP_Hook')) {
        if (!isset($GLOBALS['wp_filter'][$hook])) { $GLOBALS['wp_filter'][$hook] = new \WP_Hook(); }
        $GLOBALS['wp_filter'][$hook]->add_filter($hook, $cb, $priority, $args);
        return;
    }
    if (!isset($GLOBALS['wp_filter'][$hook])) { $GLOBALS['wp_filter'][$hook] = (object) ['callbacks' => []]; }
    $GLOBALS['wp_filter'][$hook]->callbacks[$priority][callback_key($cb)] = ['function' => $cb, 'accepted_args' => $args];
}
function add_filter($hook, $cb, $priority = 10, $args = 1) { add_action($hook, $cb, $priority, $args); }
function remove_action($hook, $cb, $priority = 10) {
    if (class_exists('WP_Hook')) { $GLOBALS['wp_filter'][$hook]->remove_filter($hook, $cb, $priority); return; }
    unset($GLOBALS['wp_filter'][$hook]->callbacks[$priority][callback_key($cb)]);
}
function apply_filters($hook, $value, ...$args) {
    $groups = $GLOBALS['wp_filter'][$hook]->callbacks ?? [];
    ksort($groups);
    foreach ($groups as $entries) { foreach ($entries as $entry) { $value = ($entry['function'])($value, ...$args); } }
    return $value;
}
function as_get_scheduled_actions($query, $format = '') {
    return array_keys(array_filter($GLOBALS['jobs'], function ($job) use ($query) {
        return $job['hook'] === $query['hook'] && $job['args'] === $query['args'] && $job['status'] === $query['status'];
    }));
}
function as_schedule_single_action($at, $hook, $args, $group, $unique = false) {
    if ($GLOBALS['as_fail']) { return 0; }
    if ($unique) {
        foreach ($GLOBALS['jobs'] as $j) {
            if ($j['hook'] === $hook && $j['args'] === $args && in_array($j['status'], ['pending', 'in-progress'], true)) { return 0; }
        }
    }
    $id = count($GLOBALS['jobs']) + 1;
    $GLOBALS['jobs'][$id] = compact('hook', 'args', 'group') + ['status' => 'pending'];
    return $id;
}
function as_unschedule_all_actions($hook, $args = null, $group = '') {
    foreach ($GLOBALS['jobs'] as &$job) { if ($job['hook'] === $hook && $job['status'] === 'pending') { $job['status'] = 'canceled'; } } unset($job);
}
function wp_next_scheduled($hook, $args = []) { return $GLOBALS['cron'][$hook][serialize($args)] ?? false; }
function wp_schedule_single_event($at, $hook, $args = []) {
    if ($GLOBALS['cron_fail']) { return false; }
    $GLOBALS['cron'][$hook][serialize($args)] = $at;
    return true;
}
function wp_schedule_event($at, $frequency, $hook) { return wp_schedule_single_event($at, $hook); }
function wp_unschedule_hook($hook) { unset($GLOBALS['cron'][$hook]); }

class FakeDB {
    public $posts = 'wp_posts';
    public $options = 'wp_options';
    public $last_error = '';
    public $on_update;
    public function prepare($sql, ...$args) { return vsprintf($sql, $args); }
    public function get_var($sql) { return $GLOBALS['products'] ? max(array_keys($GLOBALS['products'])) : 0; }
    public function get_col($sql) {
        preg_match('/ID > (\d+) AND ID <= (\d+).*LIMIT (\d+)/', $sql, $m);
        $ids = array_filter(array_keys($GLOBALS['products']), function ($id) use ($m) { return $id > $m[1] && $id <= $m[2]; });
        sort($ids);
        return array_slice($ids, 0, (int) $m[3]);
    }
    public function update($table, $values, $where, ...$formats) {
        if ($this->on_update) { $cb = $this->on_update; $this->on_update = null; $cb(); }
        if (serialize(get_option($where['option_name'])) !== $where['option_value']) { return 0; }
        update_option($where['option_name'], unserialize($values['option_value']));
        return 1;
    }
    public function delete($table, $where, ...$formats) {
        if (serialize(get_option($where['option_name'])) !== $where['option_value']) { return 0; }
        unset($GLOBALS['options'][$where['option_name']]); return 1;
    }
}
$wpdb = new FakeDB();
class Product {
    public $id, $name, $parent = 0, $description = '', $meta = [], $terms = [1], $type = 'simple', $on_save;
    public function __construct($id, $name = 'Range Bag') { $this->id = $id; $this->name = $name; }
    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent; }
    public function get_name() { return $this->name; }
    public function get_short_description() { return ''; }
    public function get_description() { return $this->description; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function save_meta_data() { if ($this->on_save) { ($this->on_save)(); } }
    public function is_type($type) { return $this->type === $type; }
}
require __DIR__ . '/../../modules/google-merchant-policy/includes/class-google-merchant-policy-engine.php';
require __DIR__ . '/../../modules/google-merchant-policy/includes/class-google-merchant-policy-google-sync.php';
require __DIR__ . '/../../modules/google-merchant-policy/includes/class-google-merchant-policy-reconciler.php';
use Google_Merchant_Policy_Engine as Engine;
use Google_Merchant_Policy_Google_Sync as Sync;
use Google_Merchant_Policy_Reconciler as Scan;
update_option(Engine::OPTION, ['mode' => 'enforce', 'content_safety' => '1', 'batch_size' => 10]);
function decision($id) { Engine::reset_runtime_cache(); return Engine::evaluate_product($GLOBALS['products'][$id])['status']; }
$products[1] = new Product(1, 'Precision Rifle with case');
check(decision(1) === 'blocked', 'Included case must not neutralize firearm detection');
$products[2] = new Product(2, 'Rifle Case');
check(decision(2) === 'allowed', 'Explicit carrying case still allowed');
$products[3] = new Product(3, 'Range Item');
$products[3]->description = '<p>Precision rifle supplied with a lock</p>';
check(decision(3) === 'blocked', 'Full description inspected');
$products[4] = new Product(4, 'Stun Gun');
check(decision(4) === 'blocked', 'Stun gun safety detection');
$products[5] = new Product(5, 'Tazer');
check(decision(5) === 'blocked', 'Tazer alternate spelling');
$products[6] = new Product(6, 'Variant'); $products[6]->parent = 2;
$products[6]->meta['_ammunition_product'] = 'yes';
check(decision(6) === 'blocked', 'Variation own flags cannot disappear behind allowed parent');
$products[7] = new Product(7, 'Variant'); $products[7]->parent = 1;
check(decision(7) === 'blocked', 'Variation inherits parent hard block');
$products[8] = new Product(8); $products[8]->terms = [];
check(decision(8) === 'pending', 'Unclassified products withheld');
$products[9] = new Product(9); $products[9]->terms = [2]; $policies[2] = 'inherit';
check(decision(9) === 'allowed', 'Child category inherits allow');
$products[2]->meta[Engine::VISIBILITY_META] = 'dont-sync-and-show';
Engine::apply_to_product($products[2]);
check($products[2]->get_meta(Engine::VISIBILITY_META) === 'dont-sync-and-show', 'Manual exclusion never removed');
update_option(Engine::OPTION, ['mode' => 'audit']);
Engine::apply_to_product($products[1]);
check($products[1]->get_meta(Engine::VISIBILITY_META) === '', 'Audit has no visibility writes');
update_option(Engine::OPTION, ['mode' => 'enforce', 'batch_size' => 10]);
Engine::init();
check(!isset($wp_filter['save_post_product']), 'No premature save_post enforcement');
check(isset($wp_filter['woocommerce_update_product']->callbacks[80]), 'Final WC object checked before Google priority90');
$products[1]->name = 'Range Bag';
Engine::on_wc_product_saved(1, $products[1]);
check($products[1]->get_meta(Engine::STATUS_META) === 'allowed', 'WC lifecycle invalidates stale decision');
$products[1]->name = 'Precision Rifle';

$calls = [];
$original = function ($items) use (&$calls) { $calls[] = $items; };
Sync::process_update([1, 8], $original);
check($calls === [], 'All-excluded update completes without Google empty-list exception');
Sync::process_update([1, 9], $original);
check($calls === [[9]], 'Mixed jobs send only allowed items');
Sync::process_update([999], $original);
check(end($calls) === [999], 'Missing products still reach original validation');
Sync::process_update([], $original);
check(end($calls) === [], 'Unrelated empty jobs not hidden');
$thrown = false;
try { Sync::process_update([9], function () { throw new \RuntimeException('Google unavailable'); }); } catch (\RuntimeException $e) { $thrown = $e->getMessage() === 'Google unavailable'; }
check($thrown, 'Real Google errors propagate');
update_option(Engine::OPTION, ['mode' => 'audit']);
Sync::process_update([1, 9], $original);
check(end($calls) === [1, 9], 'Audit leaves queued uploads unchanged');
update_option(Engine::OPTION, ['mode' => 'enforce', 'batch_size' => 10]);
$job = new \Automattic\WooCommerce\GoogleListingsAndAds\Jobs\UpdateProducts();
add_action(Sync::UPDATE_HOOK, [$job, 'handle_process_items_action']);
$unrelated = function () {};
add_action(Sync::UPDATE_HOOK, $unrelated);
Sync::protect_update_job(); Sync::protect_update_job();
check(count($wp_filter[Sync::UPDATE_HOOK]->callbacks[10]) === 2, 'Wrap only official callback once; preserve unrelated callbacks');
foreach ($wp_filter[Sync::UPDATE_HOOK]->callbacks[10] as $entry) { ($entry['function'])([1, 9]); }
check($job->calls === [[9]], 'Registered wrapper delegates filtered job');
if (class_exists('WP_Hook')) {
    unset($wp_filter[Sync::UPDATE_HOOK]);
    $late_job = new \Automattic\WooCommerce\GoogleListingsAndAds\Jobs\UpdateProducts();
    Sync::init();
    add_action(Sync::UPDATE_HOOK, [$late_job, 'handle_process_items_action']);
    $wp_filter[Sync::UPDATE_HOOK]->do_action([[1, 9]]);
    check($late_job->calls === [[9]], 'Late hook replacement works during real WordPress dispatch');
    $wp_filter[Sync::UPDATE_HOOK]->do_action([[1, 8]]);
    check($late_job->calls === [[9]], 'Real WordPress dispatcher skips all-excluded job');
}

$products[1]->meta['_wc_gla_google_ids'] = ['US' => 'online:en:US:1'];
$products[1]->meta['_wc_gla_synced_at'] = time();
$thrown = false;
try { Sync::request_withdrawal($products[1]); } catch (\RuntimeException $e) { $thrown = true; }
check($thrown, 'Disconnected Google removal is not reported as successful');
$syncer = new \Automattic\WooCommerce\GoogleListingsAndAds\Product\SyncerHooks();
add_action('woocommerce_update_product', [$syncer, 'update_by_object'], 90, 2);
check(Sync::request_withdrawal($products[1]), 'Existing excluded product delegated for removal');
check($syncer->prepared === [1] && $syncer->deleted === [1], 'Use Google deletion lifecycle only');
$products[1]->type = 'variable';
check(!Sync::request_withdrawal($products[1]), 'No unbounded child expansion from variable parent');
$products[1]->type = 'simple'; unset($products[1]->meta['_wc_gla_synced_at']);
$thrown = false;
try { Sync::request_withdrawal($products[1]); } catch (\RuntimeException $e) { $thrown = true; }
check($thrown, 'Inconsistent remote IDs need review, not false removal claim');

$products = [];
for ($i = 1; $i <= 35; $i++) { $products[$i] = new Product($i); }
function run_next() {
    foreach ($GLOBALS['jobs'] as $id => $job) {
        if ($job['status'] !== 'pending') { continue; }
        $GLOBALS['jobs'][$id]['status'] = 'in-progress';
        Scan::run_batch(...$job['args']);
        $GLOBALS['jobs'][$id]['status'] = 'complete';
        return true;
    }
    return false;
}
$state = Scan::start(); $generation = $state['scan_id'];
run_next();
check(Scan::get_state()['processed'] === 10, 'First full batch');
check(count(as_get_scheduled_actions(['hook' => Scan::ACTION, 'args' => [$generation], 'status' => 'pending'])) === 1, 'Running action does not suppress its successor');
Scan::pause();
check(Scan::get_state()['status'] === 'paused' && !run_next(), 'Pause cancels pending work');
Scan::resume();
check(Scan::get_state()['processed'] === 10, 'Resume preserves progress');
Scan::run_batch($generation);
check(Scan::get_state()['processed'] === 10, 'Stale generation ignored');
unset($products[1]); // OFFSET would now skip ID 11.
$products[100] = new Product(100); // Outside original scan snapshot.
for ($i = 0; $i < 10 && run_next(); $i++) {}
$state = Scan::get_state();
check($state['status'] === 'complete' && $state['processed'] === 35 && $state['last_id'] === 35, 'Multiple batches complete with stable cursor despite catalog edits');
check($products[11]->get_meta(Engine::STATUS_META) === 'allowed', 'No skipped row after deletion');
check($products[100]->get_meta(Engine::STATUS_META) === '', 'New IDs excluded from fixed scan snapshot');

Scan::start(); as_unschedule_all_actions(Scan::ACTION);
Scan::recover();
check(run_next(), 'Watchdog recovers lost successor');
$state = Scan::get_state();
update_option(Scan::LOCK, ['token' => 'other-worker', 'expires' => time() + 300]);
Scan::run_batch($state['scan_id']);
check(Scan::get_state()['processed'] === $state['processed'], 'Concurrent lock prevents duplicate processing');
update_option(Scan::LOCK, ['token' => 'expired-worker', 'expires' => time() - 1]);
Scan::run_batch($state['scan_id']);
check(Scan::get_state()['processed'] > $state['processed'], 'Expired lock recovers');
check(!get_option(Scan::LOCK), 'Only owned lock released');
Scan::start();
$wpdb->on_update = function () { Scan::pause(); };
run_next();
check(Scan::get_state()['status'] === 'paused' && Scan::get_state()['processed'] === 0, 'Atomic checkpoint cannot overwrite concurrent pause');
Scan::resume();
$products[2]->on_save = function () { throw new \RuntimeException('simulated failure'); };
run_next();
check(Scan::get_state()['status'] === 'failed' && Scan::get_state()['last_id'] === 0, 'Failed item cursor not committed');
$products[2]->on_save = null;
Scan::resume(); run_next();
check(Scan::get_state()['processed'] === 10 && Scan::get_state()['last_error'] === '', 'Retry resumes without losing counts');
update_option(Scan::STATE_OPTION, ['status' => 'running', 'offset' => 100, 'processed' => 100]);
Scan::recover();
check(Scan::get_state()['scan_id'] !== '' && Scan::get_state()['processed'] === 0, 'Legacy stalled scans safely migrate once');
$generation = Scan::get_state()['scan_id']; Scan::recover();
check(Scan::get_state()['scan_id'] === $generation, 'Recovery does not restart new scans');
$as_fail = true; Scan::start();
check(wp_next_scheduled(Scan::ACTION, [Scan::get_state()['scan_id']]) !== false, 'WP-Cron fallback when AS enqueue fails');
$cron_fail = true; Scan::start();
check(Scan::get_state()['status'] === 'failed' && Scan::get_state()['last_error'] !== '', 'Both schedulers failing yields actionable error');
echo "Google Merchant policy: $checks regression checks passed.\n";
}
