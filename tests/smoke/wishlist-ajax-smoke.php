<?php
/**
 * Standalone regression harness for the Wishlist AJAX handler.
 *
 * Run: php tests/smoke/wishlist-ajax-smoke.php
 *
 * Uses an in-memory stand-in for Alg_Wishlist_Core so only the handler's own
 * rules are tested: the item limit applies to the default toggle too (not
 * only to "add only" buttons), a failed save is reported instead of
 * "added", and the rate limits are fixed one-minute windows keyed per
 * visitor (account or guest session) plus a looser per-IP limit for guests.
 */

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);

$checks = 0;
function check($test, $message)
{
    global $checks;
    $checks++;
    if (!$test) {
        throw new RuntimeException("FAIL: $message");
    }
}

class JsonSent extends Exception
{
    public $payload;
    public function __construct($payload) { $this->payload = $payload; parent::__construct('json'); }
}

$GLOBALS['now']        = 1000000;
$GLOBALS['transients'] = [];
$GLOBALS['filters']    = [];
$GLOBALS['user']       = 0;

function __($t, $d = '') { return $t; }
function absint($v) { return abs((int) $v); }
function sanitize_text_field($s) { return trim((string) $s); }
function wp_unslash($s) { return $s; }
function check_ajax_referer($a, $f) { return true; }
function wp_send_json_error($d) { throw new JsonSent(['success' => false, 'data' => $d]); }
function wp_send_json_success($d) { throw new JsonSent(['success' => true, 'data' => $d]); }
function apply_filters($tag, $value) { return isset($GLOBALS['filters'][$tag]) ? $GLOBALS['filters'][$tag] : $value; }
function is_user_logged_in() { return $GLOBALS['user'] > 0; }
function get_current_user_id() { return $GLOBALS['user']; }
function get_transient($k) {
    $t = $GLOBALS['transients'][$k] ?? null;
    return ($t && $t['exp'] > $GLOBALS['now']) ? $t['v'] : false;
}
function set_transient($k, $v, $ttl) { $GLOBALS['transients'][$k] = ['v' => $v, 'exp' => $GLOBALS['now'] + $ttl]; return true; }
function nocache_headers() {}
function wp_create_nonce($a) { return 'n'; }

/* In-memory stand-in for the database-backed core. */
class Alg_Wishlist_Core
{
    public static $items = [];
    public static $fail_add = false;
    public static $session = 'guest-1';
    public static function is_in_wishlist($pid, $vid = 0) { return in_array($pid, self::$items, true); }
    public static function get_wishlist_items() { return self::$items; }
    public static function add_item($pid, $vid = 0) { if (self::$fail_add) return false; if (!in_array($pid, self::$items, true)) self::$items[] = $pid; return 'added'; }
    public static function remove_item($pid, $vid = 0) { self::$items = array_values(array_diff(self::$items, [$pid])); return 'removed'; }
    public static function set_state_cookie(array $ids) {}
    public static function state_hash(array $ids) { return $ids ? 'h' . implode('', $ids) : '0'; }
    public static function get_current_owner() { return ['field' => 'session_id', 'value' => self::$session]; }
}

require dirname(__DIR__, 2) . '/modules/wishlist/includes/class-wishlist-ajax.php';

// The handler reads time(); the transient stub expires against $GLOBALS['now'],
// so a window is ended below by moving both.
function call(array $post)
{
    $_POST = $post;
    $_SERVER['REMOTE_ADDR'] = $post['_ip'] ?? '10.0.0.1';
    try {
        (new Alg_Wishlist_Ajax())->add_to_wishlist();
    } catch (JsonSent $e) {
        return $e->payload;
    }
    throw new RuntimeException('no response');
}

/* ── Item limit applies to the default toggle ──────────────────────────── */

$GLOBALS['filters']['alg_wishlist_max_items'] = 2;
$GLOBALS['filters']['alg_wishlist_rate_limit'] = 1000;
$r = call(['product_id' => 1]);
check($r['success'] && $r['data']['status'] === 'added', 'toggle adds');
$r = call(['product_id' => 2]);
check($r['success'] && $r['data']['count'] === 2, 'toggle adds a second');
$r = call(['product_id' => 3]);
check(!$r['success'] && $r['data']['message'] === 'Wishlist is full.', 'default toggle is refused at the limit');
$r = call(['product_id' => 1]);
check($r['success'] && $r['data']['status'] === 'removed', 'toggling a saved product at the limit still removes it');
$r = call(['product_id' => 3, 'todo' => 'add']);
check($r['success'] && $r['data']['items'] === [2, 3], '"add only" works under the limit and returns the list');

/* ── A failed save is not reported as added ────────────────────────────── */

Alg_Wishlist_Core::$fail_add = true;
$r = call(['product_id' => 9, 'todo' => 'add']);
check(!$r['success'], 'failed save is an error');
Alg_Wishlist_Core::$fail_add = false;

/* ── Rate limits: fixed windows ────────────────────────────────────────── */

$GLOBALS['transients'] = [];
$GLOBALS['filters']['alg_wishlist_rate_limit'] = 3;
$results = '';
for ($i = 0; $i < 5; $i++) {
    $results .= call(['product_id' => 2, 'todo' => 'remove'])['success'] ? 'y' : 'n';
}
check($results === 'yyynn', 'per-visitor limit: 3 per window (' . $results . ')');

// Another guest on the same IP (shared proxy) is not blocked by the first.
Alg_Wishlist_Core::$session = 'guest-2';
check(call(['product_id' => 2, 'todo' => 'remove'])['success'], 'another guest behind the same IP is not blocked');

// The per-IP limit still caps guests rotating sessions.
$GLOBALS['transients'] = [];
$GLOBALS['filters']['alg_wishlist_rate_limit'] = 1000;
$GLOBALS['filters']['alg_wishlist_ip_rate_limit'] = 4;
$results = '';
for ($i = 0; $i < 6; $i++) {
    Alg_Wishlist_Core::$session = 'rotating-' . $i;
    $results .= call(['product_id' => 2, 'todo' => 'remove'])['success'] ? 'y' : 'n';
}
check($results === 'yyyynn', 'per-IP limit for guests (' . $results . ')');

// Signed-in users are limited per account, not per IP.
$GLOBALS['user'] = 7;
check(call(['product_id' => 2, 'todo' => 'remove'])['success'], 'signed-in user not caught by the guest IP limit');
$GLOBALS['user'] = 0;

// A window ends: once its transient expires, requests are allowed again.
$GLOBALS['transients'] = [];
$GLOBALS['filters']['alg_wishlist_rate_limit'] = 1;
$GLOBALS['filters']['alg_wishlist_ip_rate_limit'] = 100;
Alg_Wishlist_Core::$session = 'guest-3';
check(call(['product_id' => 2, 'todo' => 'remove'])['success'], 'first request in a window');
check(!call(['product_id' => 2, 'todo' => 'remove'])['success'], 'second request blocked');
$GLOBALS['now'] += 61;
foreach ($GLOBALS['transients'] as $k => $t) {
    $GLOBALS['transients'][$k]['v']['t'] -= 61; // the stored window started 61s ago
}
check(call(['product_id' => 2, 'todo' => 'remove'])['success'], 'allowed again in the next window');

echo "wishlist-ajax-smoke: $checks checks passed\n";
