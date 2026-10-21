<?php
/**
 * Offline regression harness for the Product Reviews module.
 *
 * Run: php tests/smoke/product-reviews-smoke.php
 *
 * Loads the module's real classes over in-memory WordPress / WooCommerce
 * stubs (hooks with priorities, comments, orders, Action Scheduler, wp_mail).
 * Covers what has to hold before a store switches the module on: where
 * "Verified buyer" comes from, one review per customer, that
 * wp-comments-post.php cannot store files, that request emails need a
 * published hub page and stop when requests are switched off, the send
 * lifecycle (failures, status detours, old orders, refunds), that only store
 * replies are emailed to reviewers, that signed links die with a refund, the
 * hub's caching, nonce and flood rules, Turnstile on the native endpoint, and
 * EXIF/GPS removal from photos.
 */

$abspath = rtrim(sys_get_temp_dir(), '/\\') . '/ffla-pr-smoke-' . getmypid() . '/';
@mkdir($abspath . 'wp-admin/includes', 0777, true);
foreach (['file.php', 'media.php', 'image.php'] as $wp_admin_file) {
    file_put_contents($abspath . 'wp-admin/includes/' . $wp_admin_file, "<?php\n");
}
register_shutdown_function(function () use ($abspath) {
    foreach (['file.php', 'media.php', 'image.php'] as $f) {
        @unlink($abspath . 'wp-admin/includes/' . $f);
    }
    @rmdir($abspath . 'wp-admin/includes');
    @rmdir($abspath . 'wp-admin');
    @rmdir($abspath);
    foreach ($GLOBALS['tmp_uploads'] ?? [] as $tmp) {
        @unlink($tmp);
    }
});
define('ABSPATH', $abspath);
define('FFLA_PATH', dirname(__DIR__, 2) . '/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

$checks = 0;
function check($test, $message)
{
    global $checks;
    $checks++;
    if (!$test) {
        throw new RuntimeException("FAIL: $message");
    }
}

class Redirected extends Exception
{
    public $url;
    public function __construct(string $url)
    {
        $this->url = $url;
        parent::__construct('redirect');
    }
}

class Died extends Exception
{
}

/* ── Hooks ─────────────────────────────────────────────────────────────── */

$GLOBALS['hooks'] = [];
$GLOBALS['did']   = [];

function add_filter($tag, $cb, $priority = 10, $accepted = 1)
{
    $GLOBALS['hooks'][$tag][$priority][] = [$cb, $accepted];
    return true;
}
function add_action($tag, $cb, $priority = 10, $accepted = 1)
{
    return add_filter($tag, $cb, $priority, $accepted);
}
function apply_filters($tag, $value, ...$rest)
{
    if (empty($GLOBALS['hooks'][$tag])) {
        return $value;
    }
    ksort($GLOBALS['hooks'][$tag]);
    foreach ($GLOBALS['hooks'][$tag] as $callbacks) {
        foreach ($callbacks as [$cb, $accepted]) {
            $value = $cb(...array_slice(array_merge([$value], $rest), 0, $accepted));
        }
    }
    return $value;
}
function do_action($tag, ...$args)
{
    $GLOBALS['did'][$tag] = ($GLOBALS['did'][$tag] ?? 0) + 1;
    if (empty($GLOBALS['hooks'][$tag])) {
        return;
    }
    ksort($GLOBALS['hooks'][$tag]);
    foreach ($GLOBALS['hooks'][$tag] as $callbacks) {
        foreach ($callbacks as [$cb, $accepted]) {
            $cb(...array_slice($args, 0, $accepted));
        }
    }
}
function did_action($tag)
{
    return $GLOBALS['did'][$tag] ?? 0;
}
function add_shortcode($tag, $cb)
{
}

/* ── WordPress basics ──────────────────────────────────────────────────── */

$GLOBALS['options'] = [
    'woocommerce_enable_reviews'                       => 'yes',
    'woocommerce_review_rating_verification_required' => 'no',
    'woocommerce_email_from_address'                   => 'shop@store.test',
    'woocommerce_email_from_name'                      => 'Gun &amp; Gear, LLC',
    'ffla_product_reviews_settings'                    => ['order_review_page_id' => '0'],
];
$GLOBALS['transients'] = [];
$GLOBALS['query_vars'] = [];
$GLOBALS['nocache']    = 0;
$GLOBALS['log']        = [];

function __($text, $domain = null) { return $text; }
function esc_html__($text, $domain = null) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_attr__($text, $domain = null) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return (string) $url; }
function esc_url_raw($url) { return (string) $url; }
function wp_kses_post($html) { return $html; }
function wp_kses($html, $allowed) { return $html; }
function wpautop($text) { return $text; }
function absint($v) { return abs((int) $v); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_email($s) { return trim((string) $s); }
function sanitize_title($s) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $s), '-')); }
function wp_unslash($s) { return $s; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, (array) $args); }
function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_specialchars_decode($s, $quote = 0) { return htmlspecialchars_decode((string) $s, ENT_QUOTES); }
function is_admin() { return false; }
function wp_salt($scheme = '') { return 'salt-' . $scheme; }
function home_url($path = '') { return 'https://store.test/' . ltrim($path, '/'); }
function admin_url($path = '') { return 'https://store.test/wp-admin/' . $path; }
function add_query_arg(...$a)
{
    // add_query_arg($args, $url) or add_query_arg($key, $value, $url).
    [$args, $url] = is_array($a[0]) ? [$a[0], $a[1]] : [[$a[0] => $a[1]], $a[2]];
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
}
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function get_permalink($id) { return 'https://store.test/?p=' . $id; }
function get_the_title($id) { return 'Post ' . $id; }
function wp_validate_redirect($url, $fallback = '') { return $url !== '' ? $url : $fallback; }
function wp_safe_redirect($url) { throw new Redirected($url); }
function wp_die($message = '', $title = '', $args = []) { throw new Died(is_string($message) ? $message : ''); }
function wp_unique_id($prefix = '') { static $n = 0; return $prefix . (++$n); }
function get_query_var($key, $default = '') { return $GLOBALS['query_vars'][$key] ?? $default; }
function nocache_headers() { $GLOBALS['nocache']++; }
function wp_verify_nonce($nonce, $action) { return 'good-nonce' === $nonce ? 1 : false; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default; }
function update_option($key, $value) { $GLOBALS['options'][$key] = $value; return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wc_get_logger()
{
    return new class {
        public function warning($message, $context = [])
        {
            $GLOBALS['log'][] = $message;
        }
    };
}

class WP_Error
{
    public $code;
    public $message;
    public $data;
    public function __construct($code = '', $message = '', $data = '')
    {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}

function set_setting(string $key, $value): void
{
    $GLOBALS['options']['ffla_product_reviews_settings'][$key] = $value;
}

/* ── Users ─────────────────────────────────────────────────────────────── */

$GLOBALS['user']  = 0;
$GLOBALS['users'] = [
    7 => ['email' => 'buyer@example.com', 'name' => 'Buyer Account', 'caps' => []],
    8 => ['email' => 'other@example.com', 'name' => 'Other Account', 'caps' => []],
    9 => ['email' => 'staff@store.test', 'name' => 'Store Staff', 'caps' => ['moderate_comments']],
];

class WP_User
{
    public $ID = 0;
    public $user_email = '';
    public $display_name = '';
}
function is_user_logged_in() { return $GLOBALS['user'] > 0; }
function get_current_user_id() { return $GLOBALS['user']; }
function user_can($user_id, $cap) { return in_array($cap, $GLOBALS['users'][$user_id]['caps'] ?? [], true); }
function current_user_can($cap) { return user_can($GLOBALS['user'], $cap); }
function wp_get_current_user()
{
    $u = new WP_User();
    if ($GLOBALS['user'] > 0) {
        $u->ID           = $GLOBALS['user'];
        $u->user_email   = $GLOBALS['users'][$GLOBALS['user']]['email'];
        $u->display_name = $GLOBALS['users'][$GLOBALS['user']]['name'];
    }
    return $u;
}

/* ── Posts and purchases ───────────────────────────────────────────────── */

$GLOBALS['posts'] = [
    101 => ['product', 'publish'],
    102 => ['product', 'publish'],
    103 => ['product', 'publish'],
    104 => ['product', 'publish'],
    500 => ['page', 'publish'],
    501 => ['page', 'draft'],
];
$GLOBALS['closed'] = [];

function get_post_type($id) { return $GLOBALS['posts'][(int) $id][0] ?? false; }
function get_post($id)
{
    $p = $GLOBALS['posts'][(int) $id] ?? null;
    return $p ? (object) ['ID' => (int) $id, 'post_type' => $p[0], 'post_status' => $p[1]] : null;
}
function comments_open($id) { return !in_array((int) $id, $GLOBALS['closed'], true); }

// Who bought what. Like WooCommerce, a user ID check also matches orders
// placed under that account's email address.
$GLOBALS['buyers'] = [
    101 => ['emails' => ['buyer@example.com'], 'users' => [7]],
    102 => ['emails' => ['buyer@example.com'], 'users' => [7]],
    103 => ['emails' => ['buyer@example.com'], 'users' => [7]],
];
function wc_customer_bought_product($email, $user_id, $product_id)
{
    $b      = $GLOBALS['buyers'][(int) $product_id] ?? ['emails' => [], 'users' => []];
    $emails = array_filter([strtolower((string) $email)]);
    if ($user_id > 0) {
        if (in_array((int) $user_id, $b['users'], true)) {
            return true;
        }
        $emails[] = $GLOBALS['users'][$user_id]['email'] ?? '';
    }
    foreach ($emails as $e) {
        if (in_array($e, $b['emails'], true)) {
            return true;
        }
    }
    return false;
}

/* ── Comments ──────────────────────────────────────────────────────────── */

class WP_Comment
{
    public $comment_ID = 0;
    public $comment_post_ID = 0;
    public $comment_author = '';
    public $comment_author_email = '';
    public $comment_content = '';
    public $comment_type = 'review';
    public $comment_parent = 0;
    public $user_id = 0;
    public $comment_approved = '1';
    public $comment_karma = 0;
    public $comment_date_gmt = '2026-01-01 00:00:00';
}

$GLOBALS['comments'] = [];
$GLOBALS['cmeta']    = [];

function add_test_comment(array $data): int
{
    $c = new WP_Comment();
    foreach ($data as $k => $v) {
        if (property_exists($c, $k)) {
            $c->$k = $v;
        }
    }
    $c->comment_ID = count($GLOBALS['comments']) + 1;
    $GLOBALS['comments'][$c->comment_ID] = $c;
    return $c->comment_ID;
}
function get_comment($id) { return $GLOBALS['comments'][(int) $id] ?? null; }
function get_comments($args)
{
    $out = [];
    foreach ($GLOBALS['comments'] as $c) {
        if (isset($args['post_id']) && (int) $c->comment_post_ID !== (int) $args['post_id']) {
            continue;
        }
        if (isset($args['type']) && $c->comment_type !== $args['type']) {
            continue;
        }
        if (isset($args['parent']) && (int) $c->comment_parent !== (int) $args['parent']) {
            continue;
        }
        $status = $args['status'] ?? 'all';
        if ('all' === $status && !in_array((string) $c->comment_approved, ['0', '1'], true)) {
            continue;
        }
        if ('approve' === $status && '1' !== (string) $c->comment_approved) {
            continue;
        }
        if (isset($args['user_id']) && (int) $c->user_id !== (int) $args['user_id']) {
            continue;
        }
        if (isset($args['author_email']) && strtolower($c->comment_author_email) !== strtolower($args['author_email'])) {
            continue;
        }
        if (isset($args['meta_key'])
            && (string) ($GLOBALS['cmeta'][$c->comment_ID][$args['meta_key']] ?? '') !== (string) $args['meta_value']) {
            continue;
        }
        $out[] = 'ids' === ($args['fields'] ?? '') ? $c->comment_ID : $c;
    }
    return empty($args['number']) ? $out : array_slice($out, 0, (int) $args['number']);
}
function update_comment_meta($id, $key, $value) { $GLOBALS['cmeta'][(int) $id][$key] = $value; return true; }
function get_comment_meta($id, $key, $single = false) { return $GLOBALS['cmeta'][(int) $id][$key] ?? ''; }
function metadata_exists($type, $id, $key) { return isset($GLOBALS['cmeta'][(int) $id][$key]); }

// WordPress's own flood check (wp_check_comment_flood, priority 10).
$GLOBALS['wp_flood']   = false;
$GLOBALS['wp_verdict'] = 1;
add_filter('wp_is_comment_flood', function ($is_flood) {
    return $is_flood || $GLOBALS['wp_flood'];
}, 10);

/** wp_new_comment() → wp_allow_comment() → wp_insert_comment(), condensed. */
function wp_new_comment($data, $wp_error = false)
{
    $data = apply_filters('preprocess_comment', $data);
    $data['comment_parent'] = absint($data['comment_parent'] ?? 0);
    $data['user_id']        = (int) ($data['user_id'] ?? 0);

    if (apply_filters('wp_is_comment_flood', false, '', '', '', $wp_error)) {
        return new WP_Error('comment_flood', 'You are posting comments too quickly. Slow down.', 429);
    }

    $approved = apply_filters('pre_comment_approved', $GLOBALS['wp_verdict'], $data);
    if (is_wp_error($approved)) {
        return $approved;
    }

    $id = add_test_comment($data + ['comment_approved' => (string) $approved]);
    $GLOBALS['comments'][$id]->comment_approved = (string) $approved;
    do_action('comment_post', $id, $approved, $data);

    return $id;
}

/* ── Media ─────────────────────────────────────────────────────────────── */

$GLOBALS['uploaded'] = [];

function wp_check_filetype_and_ext($tmp, $name, $mimes = null)
{
    $map = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'mp4' => 'video/mp4'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return isset($map[$ext]) ? ['ext' => $ext, 'type' => $map[$ext], 'proper_filename' => false] : ['ext' => false, 'type' => false];
}
function media_handle_upload($key, $post_id, $data = [], $overrides = [])
{
    $f = $_FILES[$key];
    $GLOBALS['uploaded'][] = ['name' => $f['name'], 'bytes' => file_get_contents($f['tmp_name']), 'size' => $f['size']];
    return 900 + count($GLOBALS['uploaded']);
}

/* ── WooCommerce orders and products ───────────────────────────────────── */

class WC_DateTime extends DateTime
{
}

class WC_Product
{
    public $id;
    public $parent = 0;
    public function __construct(int $id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_name() { return 'Product ' . $this->id; }
    public function is_type($type) { return 'variation' === $type && $this->parent > 0; }
    public function get_parent_id() { return $this->parent; }
}
function wc_get_product($id) { return get_post_type($id) ? new WC_Product((int) $id) : false; }

class WC_Order_Item_Product
{
    public $id;
    public $product_id;
    public $qty;
    public function __construct(int $id, int $product_id, int $qty)
    {
        $this->id         = $id;
        $this->product_id = $product_id;
        $this->qty        = $qty;
    }
    public function get_id() { return $this->id; }
    public function get_product() { return new WC_Product($this->product_id); }
    public function get_product_id() { return $this->product_id; }
    public function get_quantity() { return $this->qty; }
    public function get_name() { return 'Line ' . $this->id; }
}

class WC_Order
{
    public $id;
    public $status = 'completed';
    public $email;
    public $user_id = 0;
    public $items = [];
    public $refunded = [];
    public $meta = [];
    public $created;
    public $completed;
    public function get_id() { return $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function get_billing_email() { return $this->email; }
    public function get_billing_first_name() { return 'Ann'; }
    public function get_billing_last_name() { return 'Buyer'; }
    public function get_user_id() { return $this->user_id; }
    public function get_items() { return $this->items; }
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() { return $this->id; }
    public function get_status() { return $this->status; }
    public function has_status($status) { return in_array($this->status, (array) $status, true); }
    public function get_date_created() { return $this->created ? new WC_DateTime('@' . $this->created) : null; }
    public function get_date_completed() { return $this->completed ? new WC_DateTime('@' . $this->completed) : null; }
    public function get_qty_refunded_for_item($item_id) { return -($this->refunded[$item_id] ?? 0); }
}

$GLOBALS['orders'] = [];
function wc_get_order($id) { return $GLOBALS['orders'][(int) $id] ?? false; }

/**
 * @param array<int, array{0:int,1:int,2?:int}> $lines product, qty, refunded qty
 */
function make_order(int $id, string $email, array $lines, array $props = []): WC_Order
{
    $o            = new WC_Order();
    $o->id        = $id;
    $o->email     = $email;
    $o->created   = time() - DAY_IN_SECONDS;
    $o->completed = time();
    foreach ($lines as $i => $line) {
        $item_id            = $id * 10 + $i;
        $o->items[$item_id] = new WC_Order_Item_Product($item_id, $line[0], $line[1]);
        if (!empty($line[2])) {
            $o->refunded[$item_id] = $line[2];
        }
    }
    foreach ($props as $k => $v) {
        $o->$k = $v;
    }
    $GLOBALS['orders'][$id] = $o;
    return $o;
}

/* ── Action Scheduler, WP-Cron, wp_mail ────────────────────────────────── */

$GLOBALS['as']        = [];
$GLOBALS['mails']     = [];
$GLOBALS['mail_ok']   = true;
$GLOBALS['unhooked']  = [];

function as_next_scheduled_action($hook, $args = null, $group = '')
{
    foreach ($GLOBALS['as'] as $a) {
        if ($a['hook'] === $hook && (null === $args || $a['args'] === $args)) {
            return $a['ts'];
        }
    }
    return false;
}
function as_schedule_single_action($ts, $hook, $args = [], $group = '')
{
    $GLOBALS['as'][] = ['ts' => $ts, 'hook' => $hook, 'args' => $args, 'group' => $group];
    return count($GLOBALS['as']);
}
function as_unschedule_all_actions($hook, $args = null, $group = '')
{
    $GLOBALS['as'] = array_values(array_filter($GLOBALS['as'], function ($a) use ($hook, $args) {
        return !($a['hook'] === $hook && (null === $args || $a['args'] === $args));
    }));
}
function wp_clear_scheduled_hook($hook, $args = []) {}
function wp_unschedule_hook($hook) { $GLOBALS['unhooked'][] = $hook; }
function wp_mail($to, $subject, $body, $headers = '')
{
    $GLOBALS['mails'][] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers];
    return $GLOBALS['mail_ok'];
}

function actions_for(string $hook, array $args): array
{
    return array_values(array_filter($GLOBALS['as'], function ($a) use ($hook, $args) {
        return $a['hook'] === $hook && $a['args'] === $args;
    }));
}

/** Action Scheduler takes the action off the queue, then runs its callback. */
function run_action(string $hook, array $args): void
{
    $found = false;
    foreach ($GLOBALS['as'] as $i => $a) {
        if ($a['hook'] === $hook && $a['args'] === $args) {
            unset($GLOBALS['as'][$i]);
            $found = true;
            break;
        }
    }
    $GLOBALS['as'] = array_values($GLOBALS['as']);
    check($found, "queued action $hook " . json_encode($args) . ' exists');
    do_action($hook, ...$args);
}

/* ── Module ────────────────────────────────────────────────────────────── */

abstract class FFLA_Module
{
    public function get_path(): string
    {
        return FFLA_PATH . 'modules/' . $this->get_id() . '/';
    }
}

// The forms load the module's CSS/JS themselves once the display fixes
// (1.55.11) are in; asset loading is covered by its own smoke test.
if (!class_exists('Product_Reviews_Assets')) {
    class Product_Reviews_Assets
    {
        public static function enqueue($with_script = true)
        {
        }
    }
}

$module_dir = FFLA_PATH . 'modules/product-reviews/';
require $module_dir . 'includes/class-product-reviews-frontend-render.php';
require $module_dir . 'includes/class-product-reviews-order-hub.php';
require $module_dir . 'includes/class-product-reviews-core.php';
require $module_dir . 'includes/class-product-reviews-image-privacy.php';
require $module_dir . 'includes/class-product-reviews-notifications.php';
require $module_dir . 'includes/class-product-reviews-email.php';
require $module_dir . 'integrations/class-product-reviews-turnstile.php';
require $module_dir . 'class-product-reviews-module.php';

Product_Reviews_Order_Hub::init();
Product_Reviews_Core::init();
Product_Reviews_Notifications::init();
Product_Reviews_Email::init();

// WooCommerce's own "verified owner" flag (WC_Comments::
// add_comment_purchase_verification), worked out from the typed address. It
// is hooked before the module, and only adds the flag when none exists.
add_action('comment_post', function ($id) {
    $c = get_comment($id);
    if ('product' === get_post_type($c->comment_post_ID) && !metadata_exists('comment', $id, 'verified')) {
        update_comment_meta($id, 'verified', (int) wc_customer_bought_product($c->comment_author_email, (int) $c->user_id, (int) $c->comment_post_ID));
    }
}, 9);

function set_static(string $class, string $prop, $value): void
{
    $p = new ReflectionProperty($class, $prop);
    if (PHP_VERSION_ID < 80100) {
        $p->setAccessible(true);
    }
    $p->setValue(null, $value);
}

/** Each submission below is its own request. */
function new_request(int $user = 0): void
{
    set_static('Product_Reviews_Core', 'form_checks_passed', false);
    set_static('Product_Reviews_Core', 'token_order', null);
    set_static('Product_Reviews_Turnstile', 'passed', null);
    $GLOBALS['user']     = $user;
    $GLOBALS['did']      = [];
    $GLOBALS['uploaded'] = [];
    $_POST               = [];
    $_GET                = [];
    $_FILES              = [];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
}

/** POST to admin-post.php?action=ffla_submit_product_review. */
function submit(array $post, int $user = 0, array $files = []): array
{
    new_request($user);
    $_POST  = $post + ['action' => 'ffla_submit_product_review', 'rating' => '5', 'comment' => 'Solid product.'];
    $_FILES = $files;
    try {
        Product_Reviews_Core::handle_form_submission();
    } catch (Redirected $r) {
        parse_str((string) parse_url($r->url, PHP_URL_QUERY), $query);
        $query['comment_id'] = count($GLOBALS['comments']);
        return $query;
    }
    throw new RuntimeException('handler did not redirect');
}

/** POST to wp-comments-post.php (WooCommerce's own form, or a bot). */
function native_post(array $data, int $user = 0, array $post = [], array $files = [])
{
    new_request($user);
    $_POST  = $post;
    $_FILES = $files;
    $GLOBALS['did']['pre_comment_on_post'] = 1;
    $data += [
        'comment_post_ID'      => 101,
        'comment_content'      => 'Native review',
        'comment_type'         => 'review',
        'comment_parent'       => 0,
        'comment_author'       => 'Guest',
        'comment_author_email' => 'guest@example.com',
        'user_id'              => $user,
    ];
    try {
        return wp_new_comment($data, true);
    } catch (Died $d) {
        return $d;
    }
}

function verified(int $comment_id): int
{
    return (int) get_comment_meta($comment_id, 'ffla_verified_purchase', true);
}

/* ═════ 1. "Verified buyer" comes from a signed link or a signed-in buyer ═════ */

check(wc_customer_bought_product('buyer@example.com', 0, 101), 'fixture: WooCommerce matches the typed buyer address (the old source of the badge)');

$r = submit(['comment_post_ID' => 101, 'author' => 'Faker', 'email' => 'buyer@example.com']);
check('success' === $r['ffla_review_status'], 'guest review posts without a nonce (stale cached nonces no longer fail)');
$planted = $r['comment_id'];
check(0 === verified($planted), 'a guest typing a past buyer\'s email is not marked Verified buyer');
check(0 === (int) get_comment_meta($planted, 'verified', true), 'WooCommerce\'s own verified-owner flag is corrected too');

$r = submit(['comment_post_ID' => 101, 'author' => 'Faker', 'email' => 'BUYER@example.com']);
check('error' === $r['ffla_review_status'] && 'You already submitted a review for this product.' === $r['ffla_review_message'], 'a guest gets one review per email address per product');

$r = submit(['comment_post_ID' => 101, 'ffla_review_form_nonce' => 'good-nonce'], 7);
check('success' === $r['ffla_review_status'], 'the real buyer is not blocked by a review someone planted under their address');
check(1 === verified($r['comment_id']) && 1 === (int) get_comment_meta($r['comment_id'], 'verified', true), 'a signed-in buyer is marked Verified buyer (both flags)');

$r = submit(['comment_post_ID' => 101, 'ffla_review_form_nonce' => 'good-nonce'], 7);
check('You already submitted a review for this product.' === ($r['ffla_review_message'] ?? ''), 'a signed-in customer gets one review per product');

$r = submit(['comment_post_ID' => 102, 'ffla_review_form_nonce' => 'stale'], 8);
check('Security check failed. Please refresh and try again.' === ($r['ffla_review_message'] ?? ''), 'a signed-in user still needs a valid nonce');

$token_order = make_order(2001, 'token@example.com', [[102, 1], [103, 2]]);
$token       = Product_Reviews_Core::build_order_review_token(2001, 'token@example.com');
$GLOBALS['wp_flood'] = true;
$r = submit(['comment_post_ID' => 102, 'ffla_order_review_token' => $token, 'author' => 'Ignored', 'email' => 'ignored@example.com']);
check('success' === $r['ffla_review_status'], 'signed link posts without a nonce, despite WordPress flood control');
$c = get_comment($r['comment_id']);
check(1 === verified($r['comment_id']), 'a signed order link marks the review Verified buyer');
check('token@example.com' === $c->comment_author_email && 'Ann Buyer' === $c->comment_author, 'name and email come from the order, not from posted fields');

$r = submit(['comment_post_ID' => 103, 'ffla_order_review_token' => $token]);
check('success' === $r['ffla_review_status'], 'the next product on the hub is not refused as flooding');

$r = submit(['comment_post_ID' => 103, 'ffla_order_review_token' => $token]);
check('You already submitted a review for this product.' === ($r['ffla_review_message'] ?? ''), 'a signed link reviews each product once');

$r = submit(['comment_post_ID' => 104, 'author' => 'Quick', 'email' => 'quick@example.com']);
check('You are posting comments too quickly. Slow down.' === ($r['ffla_review_message'] ?? ''), 'WordPress\'s own refusal reaches the customer instead of a generic error');
$GLOBALS['wp_flood'] = false;

$r = submit(['comment_post_ID' => 102, 'author' => 'Snoop', 'email' => 'token@example.com']);
check('success' === $r['ffla_review_status'] && 0 === verified($r['comment_id']), 'the guest form does not reveal that an address has a verified review (no purchase lookup by email)');

$r = submit(['comment_post_ID' => 101, 'ffla_order_review_token' => $token]);
check('That product is not part of this order.' === ($r['ffla_review_message'] ?? ''), 'a signed link cannot review a product outside its order');

$n = native_post(['comment_author_email' => 'buyer@example.com', 'comment_post_ID' => 103]);
check(is_int($n) && 0 === verified($n) && 0 === (int) get_comment_meta($n, 'verified', true), 'WooCommerce\'s own form: a typed buyer address is not Verified buyer, in either flag');

$n = native_post(['comment_author_email' => 'buyer@example.com', 'comment_post_ID' => 103]);
check($n instanceof WP_Error && 409 === $n->get_error_data(), 'WooCommerce\'s own form: one review per address per product');

$n = native_post(['comment_author_email' => 'staff-test@example.com', 'comment_post_ID' => 104], 9);
$n2 = native_post(['comment_author_email' => 'staff-test@example.com', 'comment_post_ID' => 104], 9);
check(is_int($n) && is_int($n2), 'staff who can moderate comments are not limited to one review');

/* ── Verified owners only ── */

$GLOBALS['options']['woocommerce_review_rating_verification_required'] = 'yes';

$r = submit(['comment_post_ID' => 102, 'author' => 'Guest', 'email' => 'buyer@example.com']);
check('Please log in to review this product, or use the review link from your order email.' === ($r['ffla_review_message'] ?? ''), 'verified owners only: a guest without a signed link must log in, even with a buyer\'s address');

$r = submit(['comment_post_ID' => 102, 'ffla_review_form_nonce' => 'good-nonce'], 8);
check('Only customers who bought this product can review it.' === ($r['ffla_review_message'] ?? ''), 'verified owners only: a signed-in non-buyer is refused');

$r = submit(['comment_post_ID' => 102, 'ffla_review_form_nonce' => 'good-nonce'], 7);
check('success' === $r['ffla_review_status'] && 1 === verified($r['comment_id']), 'verified owners only: a signed-in buyer may review');

$n = native_post(['comment_author_email' => 'buyer@example.com', 'comment_post_ID' => 104]);
check($n instanceof WP_Error && 403 === $n->get_error_data(), 'verified owners only also holds on wp-comments-post.php');

new_request();
ob_start();
Product_Reviews_Frontend_Render::render_review_form(102);
$html = ob_get_clean();
check(strpos($html, '<form') === false && strpos($html, 'use the review link from your order email') !== false, 'verified owners only: guests see why, not a form they cannot send');

$GLOBALS['options']['woocommerce_review_rating_verification_required'] = 'no';

/* ── Guest hourly limit ── */

$GLOBALS['transients'] = [];
$GLOBALS['guest_limit'] = 2;
add_filter('ffla_product_reviews_guest_hourly_limit', function ($limit) { return $GLOBALS['guest_limit'] ?? $limit; });
$statuses = '';
foreach (['g1', 'g2', 'g3'] as $g) {
    $statuses .= submit(['comment_post_ID' => 104, 'author' => $g, 'email' => $g . '@example.com'])['ffla_review_status'] === 'success' ? 'y' : 'n';
}
check('yyn' === $statuses, 'guest reviews are limited per IP address per hour (' . $statuses . ')');
make_order(2002, 'h@example.com', [[104, 1]]);
$r = submit(['comment_post_ID' => 104, 'ffla_order_review_token' => Product_Reviews_Core::build_order_review_token(2002, 'h@example.com')]);
check('success' === $r['ffla_review_status'], 'the guest limit does not apply to signed links');
$GLOBALS['transients']  = [];
$GLOBALS['guest_limit'] = 1000;

/* ═════ 2. Files only through the FFL form, never wp-comments-post.php ═════ */

function jpeg_segment(int $marker, string $payload): string
{
    return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
}

/** TIFF block with an orientation tag and a GPS IFD pointer to secret bytes. */
function exif_tiff(int $orientation, bool $little_endian): string
{
    $s = $little_endian ? 'v' : 'n';
    $l = $little_endian ? 'V' : 'N';
    return ($little_endian ? 'II' : 'MM') . pack($s, 42) . pack($l, 8)
        . pack($s, 2)
        . pack($s, 0x0112) . pack($s, 3) . pack($l, 1) . pack($s, $orientation) . "\x00\x00"
        . pack($s, 0x8825) . pack($s, 4) . pack($l, 1) . pack($l, 38)
        . pack($l, 0)
        . 'GPS-SECRET 40.7128N 74.0060W';
}

$app0      = jpeg_segment(0xE0, "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00");
$icc       = jpeg_segment(0xE2, "ICC_PROFILE\x00\x01\x01fake-icc");
$dqt       = jpeg_segment(0xDB, str_repeat("\x01", 65));
$scan      = jpeg_segment(0xDA, "\x01\x01\x00\x00\x3F\x00") . "SCANDATA\xFF\x00MORE\xFF\xD9";
$photo_jpg = "\xFF\xD8"
    . jpeg_segment(0xE1, "Exif\x00\x00" . exif_tiff(6, true))
    . $app0
    . $icc
    . jpeg_segment(0xE2, "MPF\x00MPF-SECRET")
    . jpeg_segment(0xED, "Photoshop 3.0\x00IPTC-CITY-SECRET")
    . jpeg_segment(0xFE, 'COMMENT-SECRET')
    . jpeg_segment(0xE1, "http://ns.adobe.com/xap/1.0/\x00<x:xmpmeta>XMP-GPS-SECRET</x:xmpmeta>")
    . $dqt
    . $scan;

function upload_files(array $named_bytes): array
{
    $files = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
    foreach ($named_bytes as $name => $bytes) {
        $tmp = tempnam(sys_get_temp_dir(), 'ffla');
        file_put_contents($tmp, $bytes);
        $GLOBALS['tmp_uploads'][] = $tmp;
        $files['name'][]     = $name;
        $files['type'][]     = 'application/octet-stream';
        $files['tmp_name'][] = $tmp;
        $files['error'][]    = 0;
        $files['size'][]     = strlen($bytes);
    }
    return ['ffla_review_media' => $files];
}

$n = native_post(['comment_author_email' => 'bot@example.com', 'comment_post_ID' => 102], 0, [], upload_files(['a.jpg' => $photo_jpg, 'b.mp4' => 'video']));
check(is_int($n) && [] === $GLOBALS['uploaded'], 'files posted to wp-comments-post.php are never stored');
check('1' === get_comment($n)->comment_approved && '' === get_comment_meta($n, 'ffla_review_media_ids', true), 'ignored files do not hold the review or attach media');

$r = submit(['comment_post_ID' => 102, 'author' => 'Hunter', 'email' => 'hunter@example.com'], 0, upload_files(['a.jpg' => $photo_jpg, 'clip.mp4' => 'video-bytes']));
check('success' === $r['ffla_review_status'] && 2 === count($GLOBALS['uploaded']), 'the FFL form stores its files after its checks pass');
check('0' === get_comment($r['comment_id'])->comment_approved, 'a review with files is held for moderation');
check([901, 902] === get_comment_meta($r['comment_id'], 'ffla_review_media_ids', true), 'attachment IDs are saved on the review');
$stored = $GLOBALS['uploaded'][0]['bytes'];
check(false === strpos($stored, 'SECRET'), 'the stored photo carries no GPS, XMP, IPTC, MPF or comment data');
check(strlen($stored) === $GLOBALS['uploaded'][0]['size'], 'the upload size matches the cleaned file');
check('video-bytes' === $GLOBALS['uploaded'][1]['bytes'], 'videos pass through unchanged');

$GLOBALS['wp_verdict'] = 'spam';
$r = submit(['comment_post_ID' => 103, 'author' => 'Spammer', 'email' => 'spam@example.com'], 0, upload_files(['a.jpg' => $photo_jpg]));
check('success' === $r['ffla_review_status'] && 'spam' === get_comment($r['comment_id'])->comment_approved, 'a review filed as spam is saved without a TypeError');
check([] === $GLOBALS['uploaded'], 'files are not stored for a review filed as spam');
$GLOBALS['wp_verdict'] = 1;

$n = native_post(['comment_author_email' => 'hp@example.com', 'comment_post_ID' => 103], 0, ['ffla_hp' => 'bot']);
check($n instanceof Died, 'the honeypot also stops posts to wp-comments-post.php');

/* ── EXIF / GPS removal ── */

$exif6 = jpeg_segment(0xE1, "Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00");
check(
    "\xFF\xD8" . $app0 . $exif6 . $icc . $dqt . $scan === Product_Reviews_Image_Privacy::strip_bytes($photo_jpg, 'image/jpeg'),
    'JPEG: metadata removed, JFIF, colour profile, tables and image data kept, orientation 6 rewritten after JFIF'
);

$photo_be = "\xFF\xD8" . jpeg_segment(0xE1, "Exif\x00\x00" . exif_tiff(8, false)) . $dqt . $scan;
$exif8    = jpeg_segment(0xE1, "Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x08\x00\x00\x00\x00\x00\x00");
check("\xFF\xD8" . $exif8 . $dqt . $scan === Product_Reviews_Image_Privacy::strip_bytes($photo_be, 'image/jpeg'), 'JPEG: big-endian EXIF, orientation 8 kept right after SOI');

$upright = "\xFF\xD8" . jpeg_segment(0xE1, "Exif\x00\x00" . exif_tiff(1, true)) . $dqt . $scan;
check("\xFF\xD8" . $dqt . $scan === Product_Reviews_Image_Privacy::strip_bytes($upright, 'image/jpeg'), 'JPEG: an upright photo needs no EXIF block at all');

check(null === Product_Reviews_Image_Privacy::strip_bytes("\xFF\xD8" . "\xFF\xE1\x40\x00Exif", 'image/jpeg'), 'JPEG: a truncated file is refused');
$r = submit(['comment_post_ID' => 104, 'author' => 'Broken', 'email' => 'broken@example.com'], 0, upload_files(['bad.jpg' => "\xFF\xD8\xFF\xE1\x40\x00Exif GPS-SECRET"]));
check('success' === $r['ffla_review_status'] && [] === $GLOBALS['uploaded'], 'a photo that cannot be cleaned is skipped, not published');

function png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}
$ihdr = png_chunk('IHDR', pack('NN', 1, 1) . "\x08\x02\x00\x00\x00");
$idat = png_chunk('IDAT', 'pixels');
$iend = png_chunk('IEND', '');
$png  = "\x89PNG\r\n\x1A\n" . $ihdr . png_chunk('tEXt', "Comment\x00PNG-SECRET") . png_chunk('eXIf', 'MM-GPS-SECRET') . $idat . png_chunk('iTXt', 'XMP-SECRET') . $iend . 'TRAILING-SECRET';
check("\x89PNG\r\n\x1A\n" . $ihdr . $idat . $iend === Product_Reviews_Image_Privacy::strip_bytes($png, 'image/png'), 'PNG: text and eXIf chunks and trailing bytes removed');

$vp8x = 'VP8X' . pack('V', 10) . chr(0x1C) . str_repeat("\x00", 9);
$vp8  = 'VP8 ' . pack('V', 5) . 'abcde' . "\x00";
$webp_body = $vp8x . $vp8 . 'EXIF' . pack('V', 15) . 'WEBP-GPS-SECRET' . "\x00" . 'XMP ' . pack('V', 4) . 'XMPS';
$webp = 'RIFF' . pack('V', strlen($webp_body) + 4) . 'WEBP' . $webp_body;
$clean_body = 'VP8X' . pack('V', 10) . chr(0x10) . str_repeat("\x00", 9) . $vp8;
check('RIFF' . pack('V', strlen($clean_body) + 4) . 'WEBP' . $clean_body === Product_Reviews_Image_Privacy::strip_bytes($webp, 'image/webp'), 'WebP: EXIF and XMP chunks removed, VP8X flags and RIFF size updated');

/* ═════ 3. Request emails need a published hub page ═════ */

set_setting('order_review_page_id', '0');
$o = make_order(3001, 'mail@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(3001);
check([] === $GLOBALS['as'] && '' === $o->get_meta('_ffla_review_request_scheduled'), 'no hub page: nothing is scheduled');

set_setting('order_review_page_id', '501');
Product_Reviews_Email::schedule_requests_for_order(3001);
check([] === $GLOBALS['as'], 'a draft hub page: nothing is scheduled');

set_setting('order_review_page_id', '500');
Product_Reviews_Email::schedule_requests_for_order(3001);
$queued = actions_for('ffla_send_order_review_bundle', [3001]);
check(1 === count($queued) && 1 === $o->get_meta('_ffla_review_request_scheduled'), 'published hub page: one email per order is the default');
check(abs($queued[0]['ts'] - (time() + 7 * DAY_IN_SECONDS)) < 5, 'scheduled after the request delay');
Product_Reviews_Email::schedule_requests_for_order(3001);
check(1 === count(actions_for('ffla_send_order_review_bundle', [3001])), 'completing the order again does not queue a second email');

set_setting('request_email_mode', 'per_product');
make_order(3002, 'pp@example.com', [[101, 1], [102, 1]]);
Product_Reviews_Email::schedule_requests_for_order(3002);
check(1 === count(actions_for('ffla_send_product_review_request', [3002, 101, 0])) && 1 === count(actions_for('ffla_send_product_review_request', [3002, 102, 0])), 'per-product mode still queues one email per product');
set_setting('request_email_mode', 'bundle');

make_order(3003, 'old@example.com', [[101, 1]], ['created' => time() - 60 * DAY_IN_SECONDS]);
Product_Reviews_Email::schedule_requests_for_order(3003);
check([] === actions_for('ffla_send_order_review_bundle', [3003]), 'an order placed more than delay + 30 days before the send is not emailed');

make_order(3004, 'victim@example.com', [[101, 1], [102, 1], [103, 1]]);
add_test_comment(['comment_post_ID' => 101, 'comment_author_email' => 'victim@example.com', 'comment_approved' => '1']); // typed by someone else
$verified_review = add_test_comment(['comment_post_ID' => 102, 'comment_author_email' => 'victim@example.com', 'comment_approved' => '1']);
update_comment_meta($verified_review, 'ffla_verified_purchase', 1);
Product_Reviews_Email::schedule_requests_for_order(3004);
run_action('ffla_send_order_review_bundle', [3004]);
$mail = end($GLOBALS['mails']);
check('victim@example.com' === $mail['to'] && strpos($mail['body'], 'Product 101') !== false, 'a review planted under the customer\'s address does not stop their request');
check(strpos($mail['body'], 'Product 102') === false, 'a product the customer really reviewed is left out');

/* ═════ 4. Requests switched off, or the hub removed, stop queued emails ═════ */

$GLOBALS['mails'] = [];
set_setting('enable_requests', '0');
run_action('ffla_send_order_review_bundle', [3001]);
check([] === $GLOBALS['mails'] && '' === $o->get_meta('_ffla_review_bundle_sent'), 'requests switched off: a queued email is not sent');
set_setting('enable_requests', '1');

Product_Reviews_Email::schedule_requests_for_order(3001);
check([] === actions_for('ffla_send_order_review_bundle', [3001]), 'a dropped email is not queued again while the order stays scheduled');
$o->delete_meta_data('_ffla_review_request_scheduled');
Product_Reviews_Email::schedule_requests_for_order(3001);
$GLOBALS['posts'][500][1] = 'draft';
run_action('ffla_send_order_review_bundle', [3001]);
check([] === $GLOBALS['mails'], 'hub page unpublished: a queued email is not sent');
$GLOBALS['posts'][500][1] = 'publish';

/* ═════ 5. Sending: headers, failures, status detours, refunds ═════ */

$o->delete_meta_data('_ffla_review_request_scheduled');
Product_Reviews_Email::schedule_requests_for_order(3001);
run_action('ffla_send_order_review_bundle', [3001]);
$mail = end($GLOBALS['mails']);
check(1 === count($GLOBALS['mails']) && $o->get_meta('_ffla_review_bundle_sent') > 0, 'sent once, then marked as sent');
check(in_array('From: Gun & Gear LLC <shop@store.test>', $mail['headers'], true), 'From is WooCommerce\'s sender name and address');
check(in_array('Reply-To: Gun & Gear LLC <shop@store.test>', $mail['headers'], true), 'Reply-To falls back to the WooCommerce sender');
check(strpos($mail['body'], 'https://store.test/?p=500&ffla_ro=') !== false && strpos($mail['body'], 'Unsubscribe') !== false, 'the link opens the hub page, with an unsubscribe line');

$GLOBALS['options']['woocommerce_email_reply_to_enabled'] = 'yes';
$GLOBALS['options']['woocommerce_email_reply_to_address'] = 'help@store.test';
$GLOBALS['options']['woocommerce_email_reply_to_name']    = "Help\r\nBcc: x@evil.test";
check(in_array('Reply-To: HelpBcc: x@evil.test <help@store.test>', Product_Reviews_Email::mail_headers(), true), 'WooCommerce\'s reply-to address is used, with no header injection');
unset($GLOBALS['options']['woocommerce_email_reply_to_enabled']);

$GLOBALS['mails']   = [];
$GLOBALS['mail_ok'] = false;
$f = make_order(3005, 'fail@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(3005);
run_action('ffla_send_order_review_bundle', [3005]);
check('' === $f->get_meta('_ffla_review_bundle_sent') && 1 === $f->get_meta('_ffla_review_bundle_attempts'), 'a failed wp_mail() is not marked as sent');
check(1 === count(actions_for('ffla_send_order_review_bundle', [3005])) && 1 === count($GLOBALS['log']), 'a failed send is logged and retried');
run_action('ffla_send_order_review_bundle', [3005]);
run_action('ffla_send_order_review_bundle', [3005]);
check(3 === $f->get_meta('_ffla_review_bundle_attempts') && [] === actions_for('ffla_send_order_review_bundle', [3005]), 'three attempts in all, then no more retries');
$GLOBALS['mail_ok'] = true;

$GLOBALS['mails'] = [];
$d = make_order(3006, 'detour@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(3006);
$d->status = 'processing';
run_action('ffla_send_order_review_bundle', [3006]);
check([] === $GLOBALS['mails'] && '' === $d->get_meta('_ffla_review_request_scheduled'), 'an order moved back to Processing is not emailed and is released');
$d->status = 'completed';
Product_Reviews_Email::schedule_requests_for_order(3006);
run_action('ffla_send_order_review_bundle', [3006]);
check(1 === count($GLOBALS['mails']), 'completed again after the detour, the request is sent');

$c = make_order(3007, 'delivered@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(3007);
$c->status = 'delivered';
run_action('ffla_send_order_review_bundle', [3007]);
check(2 === count($GLOBALS['mails']) && 'delivered@example.com' === end($GLOBALS['mails'])['to'], 'a custom status reached after completion still gets its email');

$c = make_order(3008, 'pre@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(3008);
$c->status    = 'awaiting-pickup';
$c->completed = null;
run_action('ffla_send_order_review_bundle', [3008]);
check(2 === count($GLOBALS['mails']), 'a custom status before completion is not emailed');

$s = make_order(3009, 'stalled@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(3009);
$s->created = time() - 45 * DAY_IN_SECONDS;
run_action('ffla_send_order_review_bundle', [3009]);
check(2 === count($GLOBALS['mails']), 'an email left in a stalled queue past delay + 30 days is dropped');

$GLOBALS['closed'] = [103];
$x = make_order(3010, 'refund@example.com', [[101, 2, 2], [102, 1], [103, 1], [104, 1]]);
Product_Reviews_Email::schedule_requests_for_order(3010);
run_action('ffla_send_order_review_bundle', [3010]);
$body = end($GLOBALS['mails'])['body'];
check(strpos($body, 'Product 102') !== false && strpos($body, 'Product 104') !== false, 'reviewable products are listed');
check(strpos($body, 'Product 101') === false && strpos($body, 'Product 103') === false, 'fully refunded lines and products closed to reviews are left out');

set_setting('request_email_mode', 'per_product');
$p = make_order(3011, 'pp2@example.com', [[101, 1], [104, 1]]);
Product_Reviews_Email::schedule_requests_for_order(3011);
$p->refunded[30111] = 1;
$count = count($GLOBALS['mails']);
run_action('ffla_send_product_review_request', [3011, 104, 0]);
check(count($GLOBALS['mails']) === $count, 'per-product: a line refunded after queueing is not emailed');
run_action('ffla_send_product_review_request', [3011, 101, 0]);
check(count($GLOBALS['mails']) === $count + 1 && $p->get_meta('_ffla_review_sent_101') > 0, 'per-product: the other product is emailed and marked');
set_setting('request_email_mode', 'bundle');
$GLOBALS['closed'] = [];

/* ═════ 6. Signed links die with a refund or cancellation ═════ */

$t = make_order(4001, 'hub@example.com', [[101, 1], [102, 1, 1]]);
$hub_token = Product_Reviews_Core::build_order_review_token(4001, 'hub@example.com');
check(Product_Reviews_Core::get_order_for_review_token($hub_token) === $t, 'a completed order\'s link works');

new_request();
$_GET['ffla_ro'] = $hub_token;
$hub = Product_Reviews_Order_Hub::render_hub(false);
check(strpos($hub, 'id="ffla-order-review-101"') !== false && strpos($hub, 'id="ffla-order-review-102"') === false, 'the hub leaves out a line refunded in full');
check(strpos($hub, 'name="email"') === false && strpos($hub, 'name="author"') === false && strpos($hub, 'hub@example.com') === false, 'the hub form carries no billing name or email');
check(strpos($hub, 'ffla_review_form_nonce') === false && strpos($hub, 'name="ffla_order_review_token"') !== false, 'the hub form sends the token, not a nonce');

foreach (['refunded', 'cancelled', 'failed'] as $dead) {
    $t->status = $dead;
    check(null === Product_Reviews_Core::get_order_for_review_token($hub_token), "a $dead order's link stops working");
}
$hub = Product_Reviews_Order_Hub::render_hub(false);
check(strpos($hub, 'This review link is invalid or has expired.') !== false, 'the hub says so for a refunded order');
$r = submit(['comment_post_ID' => 101, 'ffla_order_review_token' => $hub_token]);
check('This review link is invalid or has expired.' === ($r['ffla_review_message'] ?? ''), 'a refunded order\'s link cannot post a review');
$t->status = 'completed';

/* ═════ 7. Hub pages are not cached ═════ */

new_request();
Product_Reviews_Order_Hub::maybe_prevent_caching();
$_GET['ffla_ro'] = 'made-up';
Product_Reviews_Order_Hub::maybe_prevent_caching();
check(0 === $GLOBALS['nocache'] && !defined('DONOTCACHEPAGE'), 'pages without a token, or with a made-up one, stay cacheable');
$_GET['ffla_ro'] = $hub_token;
Product_Reviews_Order_Hub::maybe_prevent_caching();
check(1 === $GLOBALS['nocache'] && defined('DONOTCACHEPAGE') && DONOTCACHEPAGE, 'a page opened from a signed link is kept out of page caches');

/* ═════ 8. Only store replies are emailed to the reviewer ═════ */

$GLOBALS['mails'] = [];
$review = add_test_comment(['comment_post_ID' => 101, 'comment_author' => 'Reviewer', 'comment_author_email' => 'reviewer@example.com']);
new_request();
$reply = wp_new_comment(['comment_post_ID' => 101, 'comment_parent' => $review, 'comment_type' => 'review', 'comment_author' => 'Stranger', 'comment_author_email' => 'stranger@example.com', 'comment_content' => 'Visit my site', 'user_id' => 0], true);
check(is_int($reply) && [] === $GLOBALS['mails'], 'an approved reply from anyone but the store is not relayed by email');

new_request(9);
$reply = wp_new_comment(['comment_post_ID' => 101, 'comment_parent' => $review, 'comment_type' => 'review', 'comment_author' => 'Store', 'comment_author_email' => 'staff@store.test', 'comment_content' => 'Thanks for the review!', 'user_id' => 9], true);
check(1 === (int) get_comment_meta($reply, 'ffla_is_store_reply', true), 'a reply from staff is flagged as a store reply');
check(1 === count($GLOBALS['mails']) && 'reviewer@example.com' === $GLOBALS['mails'][0]['to'], 'a store reply is emailed to the reviewer');
check(in_array('From: Gun & Gear LLC <shop@store.test>', $GLOBALS['mails'][0]['headers'], true), 'reply notices use the WooCommerce sender too');

/* ── A failed notice is not recorded as sent ── */

$GLOBALS['mails']   = [];
$GLOBALS['log']     = [];
$GLOBALS['mail_ok'] = false;
new_request(9);
$reply = wp_new_comment(['comment_post_ID' => 101, 'comment_parent' => $review, 'comment_type' => 'review', 'comment_author' => 'Store', 'comment_author_email' => 'staff@store.test', 'comment_content' => 'Second answer', 'user_id' => 9], true);
check(1 === count($GLOBALS['mails']) && '' === get_comment_meta($reply, '_ffla_reply_notified', true) && 1 === count($GLOBALS['log']), 'a store reply notice wp_mail() refused is logged and not marked as sent');
$GLOBALS['mail_ok'] = true;
Product_Reviews_Notifications::on_comment_status_transition('approved', 'unapproved', get_comment($reply));
check(2 === count($GLOBALS['mails']) && 1 === get_comment_meta($reply, '_ffla_reply_notified', true), 'approving the reply again sends the notice and marks it');

$GLOBALS['mails']   = [];
$GLOBALS['mail_ok'] = false;
$held = add_test_comment(['comment_post_ID' => 102, 'comment_author' => 'Held', 'comment_author_email' => 'held@example.com', 'comment_approved' => '0']);
Product_Reviews_Notifications::on_comment_status_transition('approved', 'unapproved', get_comment($held));
check(1 === count($GLOBALS['mails']) && '' === get_comment_meta($held, '_ffla_approved_notified', true), 'an approval notice wp_mail() refused is not marked as sent');
$GLOBALS['mail_ok'] = true;
Product_Reviews_Notifications::on_comment_status_transition('approved', 'unapproved', get_comment($held));
Product_Reviews_Notifications::on_comment_status_transition('approved', 'unapproved', get_comment($held));
check(2 === count($GLOBALS['mails']) && 1 === get_comment_meta($held, '_ffla_approved_notified', true), 'the next approval sends it once and marks it');

/* ═════ 9. Turnstile on wp-comments-post.php when the tab is replaced ═════ */

$GLOBALS['ts_ok']    = false;
$GLOBALS['ts_calls'] = 0;
if (!function_exists('cfturnstile_check')) {
    function cfturnstile_field_show($a = '', $b = '', $form = '', $rand = '') {}
    function cfturnstile_check()
    {
        $GLOBALS['ts_calls']++;
        return ['success' => $GLOBALS['ts_ok']];
    }
}

$n = native_post(['comment_author_email' => 'tab1@example.com', 'comment_post_ID' => 104]);
check(is_int($n), 'tab not replaced: WooCommerce\'s own form works without Turnstile');

set_setting('replace_default_reviews_tab', '1');
$n = native_post(['comment_author_email' => 'tab2@example.com', 'comment_post_ID' => 104]);
check($n instanceof Died && 'Cloudflare validation failed. Please try again.' === $n->getMessage(), 'tab replaced: a top-level review posted to wp-comments-post.php needs Turnstile');
$n = native_post(['comment_author_email' => 'tab3@example.com', 'comment_post_ID' => 104, 'comment_parent' => $review]);
check(is_int($n), 'tab replaced: replies are not challenged');
$GLOBALS['ts_ok'] = true;
$n = native_post(['comment_author_email' => 'tab4@example.com', 'comment_post_ID' => 104]);
check(is_int($n), 'tab replaced: a solved challenge is accepted');

$GLOBALS['ts_ok']    = false;
$GLOBALS['options']['cfturnstile_comment'] = '1';
$calls = $GLOBALS['ts_calls'];
$n = native_post(['comment_author_email' => 'tab5@example.com', 'comment_post_ID' => 104]);
check(is_int($n) && $calls === $GLOBALS['ts_calls'], 'the plugin\'s own comment protection is not doubled (a token verifies once)');
unset($GLOBALS['options']['cfturnstile_comment']);

$GLOBALS['ts_ok'] = true;
$calls = $GLOBALS['ts_calls'];
$r = submit(['comment_post_ID' => 104, 'author' => 'Turn', 'email' => 'turn@example.com']);
check('success' === $r['ffla_review_status'] && $calls + 1 === $GLOBALS['ts_calls'], 'the FFL form verifies its Turnstile token exactly once');
$GLOBALS['ts_ok'] = false;
$r = submit(['comment_post_ID' => 104, 'author' => 'Turn', 'email' => 'turn2@example.com']);
check('Cloudflare validation failed. Please try again.' === ($r['ffla_review_message'] ?? ''), 'the FFL form refuses a failed challenge');
$calls = $GLOBALS['ts_calls'];
$r = submit(['comment_post_ID' => 101, 'ffla_order_review_token' => $hub_token]);
check('success' === $r['ffla_review_status'] && $calls === $GLOBALS['ts_calls'], 'a signed link skips the challenge');
set_setting('replace_default_reviews_tab', '0');

/* ═════ 10. Switching the module off clears its queue ═════ */

$GLOBALS['as'][] = ['ts' => time(), 'hook' => 'ffla_send_order_review_bundle', 'args' => [9999], 'group' => 'ffla-product-reviews'];
$GLOBALS['as'][] = ['ts' => time(), 'hook' => 'ffla_send_product_review_request', 'args' => [9999, 101, 0], 'group' => 'ffla-product-reviews'];
$GLOBALS['as'][] = ['ts' => time(), 'hook' => 'other_plugin_hook', 'args' => [], 'group' => 'other'];
(new Product_Reviews_Module())->deactivate();
check(1 === count($GLOBALS['as']) && 'other_plugin_hook' === $GLOBALS['as'][0]['hook'], 'deactivating the module removes its queued emails, and only those');
check(in_array('ffla_send_order_review_bundle', $GLOBALS['unhooked'], true) && in_array('ffla_send_product_review_request', $GLOBALS['unhooked'], true), 'WP-Cron fallback events are cleared too');

echo "product-reviews-smoke: $checks checks passed\n";
