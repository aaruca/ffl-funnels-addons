<?php
/**
 * In-memory WordPress / WooCommerce stand-ins shared by the Product Reviews
 * feature checks (product-reviews-*-smoke.php other than the two original
 * harnesses, which carry their own).
 *
 * Covers hooks with priorities, options, transients, users and capabilities,
 * posts and products, comments and comment meta (with the query arguments the
 * module uses), orders and wc_get_orders(), Action Scheduler, wp_mail(),
 * nonces, redirects, wp_die() and JSON responses. Load it, then the module
 * classes the check needs.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', rtrim(sys_get_temp_dir(), '/\\') . '/ffla-pr-stubs-' . getmypid() . '/');
}
if (!defined('FFLA_PATH')) {
    define('FFLA_PATH', dirname(__DIR__, 2) . '/');
}
if (!defined('FFLA_URL')) {
    define('FFLA_URL', 'https://store.test/wp-content/plugins/ffl-funnels-addons/');
}
if (!defined('FFLA_VERSION')) {
    define('FFLA_VERSION', 'test');
}
foreach (['MINUTE_IN_SECONDS' => 60, 'HOUR_IN_SECONDS' => 3600, 'DAY_IN_SECONDS' => 86400, 'WEEK_IN_SECONDS' => 604800] as $const => $value) {
    if (!defined($const)) {
        define($const, $value);
    }
}

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
        parent::__construct('redirect to ' . $url);
    }
}

class Died extends Exception
{
    public $status;
    public function __construct(string $message = '', $status = null)
    {
        $this->status = $status;
        parent::__construct($message);
    }
}

class JsonSent extends Exception
{
    public $success;
    public $payload;
    public $status;
    public function __construct(bool $success, $payload, $status = null)
    {
        $this->success = $success;
        $this->payload = $payload;
        $this->status  = $status;
        parent::__construct('json');
    }
}

/* ── Hooks ─────────────────────────────────────────────────────────────── */

$GLOBALS['hooks']   = [];
$GLOBALS['did']     = [];
$GLOBALS['doing']   = [];
$GLOBALS['shortcodes'] = [];

function add_filter($tag, $cb, $priority = 10, $accepted = 1)
{
    $GLOBALS['hooks'][$tag][$priority][] = [$cb, $accepted];
    return true;
}
function add_action($tag, $cb, $priority = 10, $accepted = 1)
{
    return add_filter($tag, $cb, $priority, $accepted);
}
function remove_all_filters($tag)
{
    unset($GLOBALS['hooks'][$tag]);
}
function has_filter($tag)
{
    return !empty($GLOBALS['hooks'][$tag]);
}
function apply_filters($tag, $value, ...$rest)
{
    if (empty($GLOBALS['hooks'][$tag])) {
        return $value;
    }
    $GLOBALS['doing'][] = $tag;
    ksort($GLOBALS['hooks'][$tag]);
    foreach ($GLOBALS['hooks'][$tag] as $callbacks) {
        foreach ($callbacks as [$cb, $accepted]) {
            $value = $cb(...array_slice(array_merge([$value], $rest), 0, $accepted));
        }
    }
    array_pop($GLOBALS['doing']);
    return $value;
}
function do_action($tag, ...$args)
{
    $GLOBALS['did'][$tag] = ($GLOBALS['did'][$tag] ?? 0) + 1;
    if (empty($GLOBALS['hooks'][$tag])) {
        return;
    }
    $GLOBALS['doing'][] = $tag;
    ksort($GLOBALS['hooks'][$tag]);
    foreach ($GLOBALS['hooks'][$tag] as $callbacks) {
        foreach ($callbacks as [$cb, $accepted]) {
            $cb(...array_slice($args, 0, $accepted));
        }
    }
    array_pop($GLOBALS['doing']);
}
function did_action($tag) { return $GLOBALS['did'][$tag] ?? 0; }
function doing_action($tag) { return in_array($tag, $GLOBALS['doing'], true); }
function doing_filter($tag) { return in_array($tag, $GLOBALS['doing'], true); }
function add_shortcode($tag, $cb) { $GLOBALS['shortcodes'][$tag] = $cb; }
function has_shortcode($content, $tag) { return strpos((string) $content, '[' . $tag) !== false; }

/* ── Text, escaping, formatting ────────────────────────────────────────── */

function __($text, $domain = null) { return $text; }
function _e($text, $domain = null) { echo $text; }
function _x($text, $context, $domain = null) { return $text; }
function _n($single, $plural, $n, $domain = null) { return 1 === (int) $n ? $single : $plural; }
function esc_html__($text, $domain = null) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_html_e($text, $domain = null) { echo htmlspecialchars($text, ENT_QUOTES); }
function esc_attr__($text, $domain = null) { return htmlspecialchars($text, ENT_QUOTES); }
function esc_attr_e($text, $domain = null) { echo htmlspecialchars($text, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_textarea($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES); }
function esc_url_raw($url) { return (string) $url; }
function esc_js($text) { return addslashes((string) $text); }
function wp_kses_post($html) { return (string) $html; }
function wp_kses($html, $allowed = []) { return (string) $html; }
function wpautop($text) { return '<p>' . $text . '</p>'; }
function absint($v) { return abs((int) $v); }
function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_email($s) { return trim((string) $s); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_title($s) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $s), '-')); }
function sanitize_hex_color($c) { return preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $c) ? (string) $c : null; }
function sanitize_file_name($s) { return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $s); }
function wp_unslash($s) { return $s; }
/** As WordPress: comment data travels slashed into wp_new_comment(). */
function wp_slash($v) { return is_array($v) ? array_map('wp_slash', $v) : (is_string($v) ? addslashes($v) : $v); }
function stub_unslash_deep($v) { return is_array($v) ? array_map('stub_unslash_deep', $v) : (is_string($v) ? stripslashes($v) : $v); }
function wp_parse_args($args, $defaults = []) { return array_merge((array) $defaults, (array) $args); }
function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
function wp_specialchars_decode($s, $quote = 0) { return htmlspecialchars_decode((string) $s, ENT_QUOTES); }
function wp_trim_words($text, $num = 55, $more = '…')
{
    $words = preg_split('/\s+/', trim(strip_tags((string) $text)));
    return count($words) > $num ? implode(' ', array_slice($words, 0, $num)) . $more : implode(' ', $words);
}
function number_format_i18n($n, $decimals = 0) { return number_format((float) $n, $decimals); }
function wp_date($format, $ts = null) { return gmdate($format, $ts ?? time()); }
function current_time($type = 'mysql', $gmt = 0) { return 'timestamp' === $type ? time() : gmdate('Y-m-d H:i:s'); }
function wp_timezone() { return new DateTimeZone('UTC'); }
function mysql2date($format, $date) { return gmdate($format, strtotime($date . ' UTC')); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function wp_unique_id($prefix = '') { static $n = 0; return $prefix . (++$n); }
function selected($a, $b, $echo = true) { $r = ((string) $a === (string) $b) ? ' selected="selected"' : ''; if ($echo) { echo $r; } return $r; }
function checked($a, $b = true, $echo = true) { $r = ((string) $a === (string) $b) ? ' checked="checked"' : ''; if ($echo) { echo $r; } return $r; }
function wp_rand($min = 0, $max = 0) { return random_int($min, $max ?: PHP_INT_MAX); }
function wp_generate_password($length = 12, $special = true, $extra = false)
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out   = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/* ── URLs, requests, responses ─────────────────────────────────────────── */

function home_url($path = '') { return 'https://store.test/' . ltrim((string) $path, '/'); }
function site_url($path = '') { return home_url($path); }
function admin_url($path = '') { return 'https://store.test/wp-admin/' . ltrim((string) $path, '/'); }
function add_query_arg(...$a)
{
    [$args, $url] = is_array($a[0]) ? [$a[0], $a[1] ?? ''] : [[$a[0] => $a[1]], $a[2] ?? ''];
    $url = (string) $url;
    $hash = '';
    if (false !== ($pos = strpos($url, '#'))) {
        $hash = substr($url, $pos);
        $url  = substr($url, 0, $pos);
    }
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args) . $hash;
}
function remove_query_arg($keys, $url = '') { return (string) $url; }
function trailingslashit($s) { return rtrim((string) $s, '/') . '/'; }
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function get_permalink($id) { return 'https://store.test/?p=' . (int) $id; }
function wp_validate_redirect($url, $fallback = '') { return $url !== '' ? $url : $fallback; }
function wp_safe_redirect($url, $status = 302) { throw new Redirected((string) $url); }
function wp_redirect($url, $status = 302) { throw new Redirected((string) $url); }
function wp_get_referer() { return $GLOBALS['referer'] ?? false; }
function wp_die($message = '', $title = '', $args = [])
{
    throw new Died(is_string($message) ? $message : '', is_array($args) ? ($args['response'] ?? null) : null);
}
function wp_send_json_success($data = null, $status = null) { throw new JsonSent(true, $data, $status); }
function wp_send_json_error($data = null, $status = null) { throw new JsonSent(false, $data, $status); }
function wp_send_json($data, $status = null) { throw new JsonSent(true, $data, $status); }
function nocache_headers() { $GLOBALS['nocache'] = ($GLOBALS['nocache'] ?? 0) + 1; }
function status_header($code) { $GLOBALS['status_header'] = $code; }
function get_query_var($key, $default = '') { return $GLOBALS['query_vars'][$key] ?? $default; }
function is_admin() { return !empty($GLOBALS['is_admin']); }
function wp_doing_ajax() { return !empty($GLOBALS['doing_ajax']); }

/* ── Nonces and capabilities ───────────────────────────────────────────── */

function wp_create_nonce($action = -1) { return 'nonce-' . $action; }
function wp_verify_nonce($nonce, $action = -1) { return ('nonce-' . $action) === $nonce ? 1 : false; }
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
{
    $html = '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
    if ($echo) {
        echo $html;
    }
    return $html;
}
function wp_nonce_url($url, $action = -1, $name = '_wpnonce') { return add_query_arg($name, 'nonce-' . $action, $url); }
function check_admin_referer($action = -1, $name = '_wpnonce')
{
    $value = $_REQUEST[$name] ?? ($_POST[$name] ?? ($_GET[$name] ?? ''));
    if (('nonce-' . $action) !== $value) {
        throw new Died('nonce check failed', 403);
    }
    return 1;
}
function check_ajax_referer($action = -1, $name = false, $die = true)
{
    $value = $_REQUEST[$name ?: '_ajax_nonce'] ?? ($_POST[$name ?: '_ajax_nonce'] ?? '');
    $ok    = ('nonce-' . $action) === $value;
    if (!$ok && $die) {
        throw new Died('-1', 403);
    }
    return $ok ? 1 : false;
}

$GLOBALS['user']  = 0;
$GLOBALS['users'] = [
    1 => ['email' => 'admin@store.test', 'name' => 'Store Admin', 'first' => 'Store', 'last' => 'Admin', 'roles' => ['administrator'], 'caps' => ['manage_woocommerce', 'moderate_comments', 'manage_options', 'edit_shop_orders', 'upload_files']],
    7 => ['email' => 'buyer@example.com', 'name' => 'Buyer Account', 'first' => 'Ann', 'last' => 'Buyer', 'roles' => ['customer'], 'caps' => []],
    8 => ['email' => 'other@example.com', 'name' => 'Other Account', 'first' => 'Otto', 'last' => 'Other', 'roles' => ['subscriber'], 'caps' => []],
    9 => ['email' => 'staff@store.test', 'name' => 'Store Staff', 'first' => 'Sam', 'last' => 'Staff', 'roles' => ['shop_manager'], 'caps' => ['moderate_comments', 'manage_woocommerce', 'edit_shop_orders']],
];

class WP_User
{
    public $ID = 0;
    public $user_email = '';
    public $display_name = '';
    public $first_name = '';
    public $last_name = '';
    public $roles = [];
    public function exists() { return $this->ID > 0; }
}
function is_user_logged_in() { return $GLOBALS['user'] > 0; }
function get_current_user_id() { return $GLOBALS['user']; }
function user_can($user_id, $cap)
{
    $user_id = $user_id instanceof WP_User ? $user_id->ID : (int) $user_id;
    return in_array($cap, $GLOBALS['users'][$user_id]['caps'] ?? [], true);
}
function current_user_can($cap, ...$args) { return user_can($GLOBALS['user'], $cap); }
function get_userdata($id)
{
    $id = (int) $id;
    if (!isset($GLOBALS['users'][$id])) {
        return false;
    }
    $u               = new WP_User();
    $u->ID           = $id;
    $u->user_email   = $GLOBALS['users'][$id]['email'];
    $u->display_name = $GLOBALS['users'][$id]['name'];
    $u->first_name   = $GLOBALS['users'][$id]['first'] ?? '';
    $u->last_name    = $GLOBALS['users'][$id]['last'] ?? '';
    $u->roles        = $GLOBALS['users'][$id]['roles'] ?? [];
    return $u;
}
function get_user_by($field, $value)
{
    foreach ($GLOBALS['users'] as $id => $u) {
        if ('email' === $field && strtolower($u['email']) === strtolower((string) $value)) {
            return get_userdata($id);
        }
        if ('id' === $field && (int) $value === (int) $id) {
            return get_userdata($id);
        }
    }
    return false;
}
function wp_get_current_user() { return get_userdata($GLOBALS['user']) ?: new WP_User(); }
function get_user_meta($id, $key = '', $single = false) { return $GLOBALS['umeta'][(int) $id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { $GLOBALS['umeta'][(int) $id][$key] = $value; return true; }
function delete_user_meta($id, $key) { unset($GLOBALS['umeta'][(int) $id][$key]); return true; }
function wp_roles()
{
    return new class {
        public function get_names()
        {
            return ['administrator' => 'Administrator', 'shop_manager' => 'Shop manager', 'customer' => 'Customer', 'subscriber' => 'Subscriber'];
        }
    };
}
function get_avatar_url($id_or_email, $args = []) { return 'https://secure.gravatar.com/avatar/' . md5(strtolower(trim(is_string($id_or_email) ? $id_or_email : 'x'))) . '?s=' . ($args['size'] ?? 96); }
function get_avatar($id_or_email, $size = 96, $default = '', $alt = '', $args = []) { return '<img class="avatar" src="' . get_avatar_url($id_or_email, ['size' => $size]) . '" alt="">'; }

/* ── Options and transients ────────────────────────────────────────────── */

$GLOBALS['options'] = [
    'date_format'                                      => 'Y-m-d',
    'time_format'                                      => 'H:i',
    'blogname'                                         => 'Gun &amp; Gear',
    'admin_email'                                      => 'admin@store.test',
    'woocommerce_enable_reviews'                       => 'yes',
    'woocommerce_enable_review_rating'                 => 'yes',
    'woocommerce_review_rating_verification_required' => 'no',
    'woocommerce_email_from_address'                   => 'shop@store.test',
    'woocommerce_email_from_name'                      => 'Gun &amp; Gear, LLC',
    'ffla_product_reviews_settings'                    => ['order_review_page_id' => '500'],
];
$GLOBALS['transients'] = [];

function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value = '', $deprecated = '', $autoload = 'yes') { if (!array_key_exists($key, $GLOBALS['options'])) { $GLOBALS['options'][$key] = $value; return true; } return false; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['transients'][$key]); return true; }
function wp_cache_get($key, $group = '') { return $GLOBALS['wp_cache'][$group][$key] ?? false; }
function wp_cache_set($key, $value, $group = '', $ttl = 0) { $GLOBALS['wp_cache'][$group][$key] = $value; return true; }
function wp_cache_delete($key, $group = '') { unset($GLOBALS['wp_cache'][$group][$key]); return true; }
function get_bloginfo($show = '') { return 'name' === $show ? wp_specialchars_decode(get_option('blogname')) : ''; }
function wp_salt($scheme = '') { return 'salt-' . $scheme; }

function set_setting(string $key, $value): void
{
    $GLOBALS['options']['ffla_product_reviews_settings'][$key] = $value;
}

/* ── Errors ────────────────────────────────────────────────────────────── */

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
function is_wp_error($thing) { return $thing instanceof WP_Error; }

$GLOBALS['log'] = [];
function wc_get_logger()
{
    return new class {
        public function warning($message, $context = []) { $GLOBALS['log'][] = $message; }
        public function info($message, $context = []) { $GLOBALS['log'][] = $message; }
        public function error($message, $context = []) { $GLOBALS['log'][] = $message; }
    };
}

/* ── Posts, products, terms ────────────────────────────────────────────── */

$GLOBALS['posts'] = [
    101 => ['product', 'publish', 'Hunting Rifle Scope'],
    102 => ['product', 'publish', 'Range Bag'],
    103 => ['product', 'publish', 'Cleaning Kit'],
    104 => ['product', 'publish', 'Gift Card'],
    500 => ['page', 'publish', 'Review your order'],
    501 => ['page', 'draft', 'Draft page'],
];
$GLOBALS['closed']        = [];
$GLOBALS['product_props'] = [
    101 => ['price' => '249.99', 'sku' => 'SCOPE-1', 'cats' => [20]],
    102 => ['price' => '59.00', 'sku' => 'BAG-1', 'cats' => [21]],
    103 => ['price' => '19.50', 'sku' => 'KIT-1', 'cats' => [21]],
    104 => ['price' => '0', 'sku' => 'GIFT', 'cats' => [22]],
];
$GLOBALS['terms'] = [
    20 => ['product_cat', 'Optics', 'optics', 0],
    21 => ['product_cat', 'Accessories', 'accessories', 0],
    22 => ['product_cat', 'Free stuff', 'free-stuff', 0],
];

class WP_Post
{
    public $ID;
    public $post_type;
    public $post_status;
    public $post_title;
    public $post_content = '';
}
class WP_Term
{
    public $term_id;
    public $taxonomy;
    public $name;
    public $slug;
    public $parent = 0;
}

function get_post_type($id) { return $GLOBALS['posts'][(int) $id][0] ?? false; }
function get_post_status($id) { return $GLOBALS['posts'][(int) $id][1] ?? false; }
function get_post($id)
{
    $id = $id instanceof WP_Post ? $id->ID : (int) $id;
    $p  = $GLOBALS['posts'][$id] ?? null;
    if (!$p) {
        return null;
    }
    $post              = new WP_Post();
    $post->ID          = $id;
    $post->post_type   = $p[0];
    $post->post_status = $p[1];
    $post->post_title  = $p[2] ?? ('Post ' . $id);
    return $post;
}
function get_the_title($id) { return $GLOBALS['posts'][(int) $id][2] ?? ('Post ' . (int) $id); }
function comments_open($id) { return !in_array((int) $id, $GLOBALS['closed'], true); }
function get_terms($args = [])
{
    $out = [];
    foreach ($GLOBALS['terms'] as $id => $t) {
        if (!empty($args['taxonomy']) && $t[0] !== $args['taxonomy']) {
            continue;
        }
        $term           = new WP_Term();
        $term->term_id  = $id;
        $term->taxonomy = $t[0];
        $term->name     = $t[1];
        $term->slug     = $t[2];
        $term->parent   = $t[3];
        $out[]          = $term;
    }
    return $out;
}
function get_term($id, $taxonomy = '')
{
    foreach (get_terms() as $term) {
        if ($term->term_id === (int) $id) {
            return $term;
        }
    }
    return null;
}
function get_ancestors($id, $taxonomy = '', $type = '')
{
    $out = [];
    $t   = $GLOBALS['terms'][(int) $id] ?? null;
    while ($t && $t[3] > 0) {
        $out[] = $t[3];
        $t     = $GLOBALS['terms'][$t[3]] ?? null;
    }
    return $out;
}
function wc_get_product_cat_ids($product_id) { return $GLOBALS['product_props'][(int) $product_id]['cats'] ?? []; }
function wp_get_post_terms($post_id, $taxonomy, $args = [])
{
    $ids = 'product_cat' === $taxonomy ? wc_get_product_cat_ids($post_id) : [];
    if (($args['fields'] ?? '') === 'ids') {
        return $ids;
    }
    return array_values(array_filter(array_map('get_term', $ids)));
}
function get_the_post_thumbnail_url($id, $size = 'thumbnail') { return 'https://store.test/img/' . (int) $id . '-' . $size . '.jpg'; }

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
function wc_review_ratings_enabled() { return 'yes' === get_option('woocommerce_enable_review_rating', 'yes'); }
function wc_price($amount, $args = []) { return '$' . number_format((float) $amount, 2); }
function get_woocommerce_currency_symbol($currency = '') { return '$'; }
function wc_format_decimal($n, $dp = false) { return (string) (float) $n; }
function wc_get_order_statuses()
{
    return [
        'wc-pending'    => 'Pending payment',
        'wc-processing' => 'Processing',
        'wc-on-hold'    => 'On hold',
        'wc-completed'  => 'Completed',
        'wc-cancelled'  => 'Cancelled',
        'wc-refunded'   => 'Refunded',
        'wc-failed'     => 'Failed',
        'wc-delivered'  => 'Delivered',
    ];
}

class WC_DateTime extends DateTime
{
    public function getOffsetTimestamp() { return $this->getTimestamp(); }
    public function date_i18n($format = 'Y-m-d') { return $this->format($format); }
}

class WC_Product
{
    public $id;
    public $parent = 0;
    public function __construct(int $id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_name() { return get_the_title($this->id); }
    public function is_type($type) { return 'variation' === $type ? $this->parent > 0 : 'simple' === $type; }
    public function get_type() { return $this->parent > 0 ? 'variation' : 'simple'; }
    public function get_parent_id() { return $this->parent; }
    public function get_price() { return $GLOBALS['product_props'][$this->id]['price'] ?? '10'; }
    public function get_sku() { return $GLOBALS['product_props'][$this->id]['sku'] ?? ''; }
    public function get_permalink() { return get_permalink($this->id); }
    public function get_image_id() { return 0; }
    public function get_image($size = 'woocommerce_thumbnail') { return '<img src="https://store.test/img/' . $this->id . '.jpg" alt="">'; }
    public function get_category_ids() { return wc_get_product_cat_ids($this->id); }
    public function get_rating_counts() { return $GLOBALS['product_props'][$this->id]['rating_counts'] ?? []; }
    public function get_average_rating() { return $GLOBALS['product_props'][$this->id]['average'] ?? 0; }
    public function get_review_count() { return $GLOBALS['product_props'][$this->id]['review_count'] ?? 0; }
    public function get_meta($key, $single = true) { return $GLOBALS['product_props'][$this->id]['meta'][$key] ?? ''; }
    public function get_global_unique_id() { return $GLOBALS['product_props'][$this->id]['gtin'] ?? ''; }
    public function is_visible() { return true; }
}
function wc_get_product($id)
{
    if ($id instanceof WC_Product) {
        return $id;
    }
    return in_array(get_post_type($id), ['product', 'product_variation'], true) ? new WC_Product((int) $id) : false;
}
function wc_get_products($args = [])
{
    $ids = [];
    foreach ($GLOBALS['posts'] as $id => $p) {
        if ('product' === $p[0] && 'publish' === $p[1]) {
            $ids[] = $id;
        }
    }
    if (!empty($args['limit']) && $args['limit'] > 0) {
        $ids = array_slice($ids, 0, (int) $args['limit']);
    }
    return ('ids' === ($args['return'] ?? '')) ? $ids : array_map('wc_get_product', $ids);
}

/* ── Comments ──────────────────────────────────────────────────────────── */

class WP_Comment
{
    public $comment_ID = 0;
    public $comment_post_ID = 0;
    public $comment_author = '';
    public $comment_author_email = '';
    public $comment_author_IP = '';
    public $comment_content = '';
    public $comment_type = 'review';
    public $comment_parent = 0;
    public $user_id = 0;
    public $comment_approved = '1';
    public $comment_karma = 0;
    public $comment_date = '2026-01-01 00:00:00';
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
    foreach (($data['meta'] ?? []) as $k => $v) {
        $GLOBALS['cmeta'][$c->comment_ID][$k] = $v;
    }
    return $c->comment_ID;
}
function get_comment($id)
{
    if ($id instanceof WP_Comment) {
        return $id;
    }
    return $GLOBALS['comments'][(int) $id] ?? null;
}

/**
 * The subset of WP_Comment_Query the module uses: post_id, post__in, type,
 * type__in, parent, parent__in, status, user_id, author_email, comment__in,
 * search, meta_key/meta_value, meta_query (=, EXISTS, >=, LIKE), orderby
 * (comment_date_gmt / comment__in / [field => dir]), order, number, offset,
 * count, fields=ids.
 */
function get_comments($args = [])
{
    $out = [];
    foreach ($GLOBALS['comments'] as $c) {
        if (isset($args['post_id']) && (int) $args['post_id'] > 0 && (int) $c->comment_post_ID !== (int) $args['post_id']) {
            continue;
        }
        if (!empty($args['post__in']) && !in_array((int) $c->comment_post_ID, array_map('intval', $args['post__in']), true)) {
            continue;
        }
        if (isset($args['post_type'])) {
            $types = (array) $args['post_type'];
            if (!in_array(get_post_type($c->comment_post_ID), $types, true)) {
                continue;
            }
        }
        if (isset($args['type']) && $args['type'] !== '' && $c->comment_type !== $args['type']) {
            continue;
        }
        if (!empty($args['type__in']) && !in_array($c->comment_type, (array) $args['type__in'], true)) {
            continue;
        }
        if (isset($args['parent']) && $args['parent'] !== '' && (int) $c->comment_parent !== (int) $args['parent']) {
            continue;
        }
        if (!empty($args['parent__in']) && !in_array((int) $c->comment_parent, array_map('intval', $args['parent__in']), true)) {
            continue;
        }
        $status = $args['status'] ?? 'all';
        if ('all' === $status && !in_array((string) $c->comment_approved, ['0', '1'], true)) {
            continue;
        }
        if ('approve' === $status && '1' !== (string) $c->comment_approved) {
            continue;
        }
        if ('hold' === $status && '0' !== (string) $c->comment_approved) {
            continue;
        }
        if (isset($args['user_id']) && (int) $c->user_id !== (int) $args['user_id']) {
            continue;
        }
        if (isset($args['author_email']) && strtolower($c->comment_author_email) !== strtolower($args['author_email'])) {
            continue;
        }
        if (!empty($args['comment__in']) && !in_array((int) $c->comment_ID, array_map('intval', $args['comment__in']), true)) {
            continue;
        }
        if (!empty($args['search']) && stripos($c->comment_content . ' ' . $c->comment_author, (string) $args['search']) === false) {
            continue;
        }
        if (isset($args['meta_key']) && !isset($args['meta_query'])
            && (string) ($GLOBALS['cmeta'][$c->comment_ID][$args['meta_key']] ?? '') !== (string) ($args['meta_value'] ?? '')) {
            continue;
        }
        if (!empty($args['meta_query']) && !stub_meta_query_matches((int) $c->comment_ID, $args['meta_query'])) {
            continue;
        }
        $out[] = $c;
    }

    $orderby = $args['orderby'] ?? 'comment_date_gmt';
    $order   = strtoupper($args['order'] ?? 'DESC');
    if ('comment__in' === $orderby && !empty($args['comment__in'])) {
        $pos = array_flip(array_map('intval', $args['comment__in']));
        usort($out, function ($a, $b) use ($pos) {
            return $pos[(int) $a->comment_ID] <=> $pos[(int) $b->comment_ID];
        });
    } else {
        $keys = is_array($orderby) ? $orderby : [$orderby => $order];
        usort($out, function ($a, $b) use ($keys) {
            foreach ($keys as $field => $dir) {
                if (is_int($field)) {
                    $field = $dir;
                    $dir   = 'DESC';
                }
                $field = 'comment_date_gmt' === $field || 'comment_date' === $field ? 'comment_date_gmt' : $field;
                $va    = property_exists($a, $field) ? $a->$field : 0;
                $vb    = property_exists($b, $field) ? $b->$field : 0;
                if ($va == $vb && 'comment_date_gmt' === $field) {
                    $va = (int) $a->comment_ID;
                    $vb = (int) $b->comment_ID;
                }
                $cmp = $va <=> $vb;
                if ($cmp !== 0) {
                    return 'ASC' === strtoupper((string) $dir) ? $cmp : -$cmp;
                }
            }
            return 0;
        });
    }

    if (!empty($args['count'])) {
        return count($out);
    }
    $offset = (int) ($args['offset'] ?? 0);
    if ($offset > 0 || !empty($args['number'])) {
        $out = array_slice($out, $offset, !empty($args['number']) ? (int) $args['number'] : null);
    }
    if ('ids' === ($args['fields'] ?? '')) {
        return array_map(function ($c) {
            return (int) $c->comment_ID;
        }, $out);
    }
    return $out;
}

/**
 * @param array<int|string, mixed> $query
 */
function stub_meta_query_matches(int $comment_id, array $query): bool
{
    $relation = strtoupper((string) ($query['relation'] ?? 'AND'));
    $results  = [];
    foreach ($query as $k => $clause) {
        if ('relation' === $k || !is_array($clause)) {
            continue;
        }
        if (!isset($clause['key'])) {
            $results[] = stub_meta_query_matches($comment_id, $clause);
            continue;
        }
        $exists  = array_key_exists($clause['key'], $GLOBALS['cmeta'][$comment_id] ?? []);
        $actual  = $GLOBALS['cmeta'][$comment_id][$clause['key']] ?? null;
        $compare = strtoupper((string) ($clause['compare'] ?? '='));
        $value   = $clause['value'] ?? null;
        switch ($compare) {
            case 'EXISTS':
                $results[] = $exists;
                break;
            case 'NOT EXISTS':
                $results[] = !$exists;
                break;
            case '>=':
                $results[] = $exists && (float) $actual >= (float) $value;
                break;
            case '>':
                $results[] = $exists && (float) $actual > (float) $value;
                break;
            case '<=':
                $results[] = $exists && (float) $actual <= (float) $value;
                break;
            case 'IN':
                $results[] = $exists && in_array((string) $actual, array_map('strval', (array) $value), true);
                break;
            case 'LIKE':
                $hay       = is_array($actual) ? serialize($actual) : (string) $actual;
                $results[] = $exists && stripos($hay, (string) $value) !== false;
                break;
            case '!=':
                $results[] = !$exists || (string) $actual !== (string) $value;
                break;
            default:
                $results[] = $exists && (string) (is_array($actual) ? '' : $actual) === (string) $value;
        }
    }
    if (empty($results)) {
        return true;
    }
    return 'OR' === $relation ? in_array(true, $results, true) : !in_array(false, $results, true);
}
/** Several rows under one meta key (add_comment_meta() without $unique). */
class StubMulti
{
    public $values;
    public function __construct(array $values) { $this->values = $values; }
}
function get_comment_meta($id, $key = '', $single = false)
{
    if ($key === '') {
        return $GLOBALS['cmeta'][(int) $id] ?? [];
    }
    $v = $GLOBALS['cmeta'][(int) $id][$key] ?? '';
    if ($v instanceof StubMulti) {
        return $single ? ($v->values[0] ?? '') : $v->values;
    }
    return $single ? $v : ($v === '' ? [] : [$v]);
}
function update_comment_meta($id, $key, $value) { $GLOBALS['cmeta'][(int) $id][$key] = $value; return true; }
function add_comment_meta($id, $key, $value, $unique = false)
{
    if ($unique && isset($GLOBALS['cmeta'][(int) $id][$key])) {
        return false;
    }
    $GLOBALS['cmeta'][(int) $id][$key] = $value;
    return true;
}
function delete_comment_meta($id, $key, $value = '') { unset($GLOBALS['cmeta'][(int) $id][$key]); return true; }
function metadata_exists($type, $id, $key) { return isset($GLOBALS['cmeta'][(int) $id][$key]); }
function get_comment_author($comment) { $c = get_comment($comment); return $c ? $c->comment_author : ''; }
function get_comment_text($comment) { $c = get_comment($comment); return $c ? $c->comment_content : ''; }
function get_comment_date($format = '', $comment = 0) { $c = get_comment($comment); return $c ? gmdate($format ?: 'Y-m-d', strtotime($c->comment_date_gmt . ' UTC')) : ''; }
function get_comment_link($comment) { $c = get_comment($comment); return $c ? get_permalink($c->comment_post_ID) . '#comment-' . $c->comment_ID : ''; }
function wp_get_current_commenter() { return ['comment_author' => '', 'comment_author_email' => '', 'comment_author_url' => '']; }
function wp_set_comment_status($id, $status)
{
    $c = get_comment($id);
    if (!$c) {
        return false;
    }
    $map = ['approve' => '1', 'hold' => '0', 'spam' => 'spam', 'trash' => 'trash', '1' => '1', '0' => '0'];
    $old = $c->comment_approved;
    $c->comment_approved = $map[(string) $status] ?? (string) $status;
    $names = ['1' => 'approved', '0' => 'unapproved', 'spam' => 'spam', 'trash' => 'trash'];
    do_action('transition_comment_status', $names[$c->comment_approved] ?? $c->comment_approved, $names[$old] ?? $old, $c);
    return true;
}
function wp_update_comment_count_now($post_id) { $GLOBALS['comment_count_refreshed'][] = (int) $post_id; return true; }
function clean_comment_cache($ids) {}

/** wp_new_comment() → wp_allow_comment() → wp_insert_comment(), condensed. */
$GLOBALS['wp_verdict'] = 1;
function wp_new_comment($data, $wp_error = false)
{
    $data = apply_filters('preprocess_comment', $data);
    $data['comment_parent'] = absint($data['comment_parent'] ?? 0);
    $data['user_id']        = (int) ($data['user_id'] ?? 0);

    $approved = apply_filters('pre_comment_approved', $data['comment_approved'] ?? $GLOBALS['wp_verdict'], $data);
    if (is_wp_error($approved)) {
        return $approved;
    }

    // wp_insert_comment() unslashes what it stores.
    $id = add_test_comment(stub_unslash_deep($data) + ['comment_date_gmt' => gmdate('Y-m-d H:i:s')]);
    $GLOBALS['comments'][$id]->comment_approved = (string) $approved;
    do_action('comment_post', $id, $approved, $data);

    return $id;
}
function wp_insert_comment($data)
{
    $id = add_test_comment($data + ['comment_date_gmt' => gmdate('Y-m-d H:i:s')]);
    if (isset($data['comment_meta']) && is_array($data['comment_meta'])) {
        foreach ($data['comment_meta'] as $k => $v) {
            update_comment_meta($id, $k, $v);
        }
    }
    do_action('wp_insert_comment', $id, get_comment($id));
    return $id;
}
function wp_delete_comment($id, $force = false)
{
    do_action('delete_comment', (int) $id, get_comment($id));
    unset($GLOBALS['comments'][(int) $id], $GLOBALS['cmeta'][(int) $id]);
    return true;
}

/* ── Media ─────────────────────────────────────────────────────────────── */

$GLOBALS['attachments'] = [];
function get_post_mime_type($id) { return $GLOBALS['attachments'][(int) $id]['mime'] ?? 'image/jpeg'; }
function wp_get_attachment_url($id) { return isset($GLOBALS['attachments'][(int) $id]) ? $GLOBALS['attachments'][(int) $id]['url'] : false; }
function wp_get_attachment_image_url($id, $size = 'thumbnail') { $u = wp_get_attachment_url($id); return $u ? $u . '?size=' . $size : false; }
function wp_get_attachment_image($id, $size = 'thumbnail', $icon = false, $attr = []) { $u = wp_get_attachment_url($id); return $u ? '<img src="' . $u . '" srcset="' . $u . ' 300w" sizes="100vw" alt="">' : ''; }
function wp_get_attachment_image_src($id, $size = 'thumbnail') { $u = wp_get_attachment_url($id); return $u ? [$u, 800, 600, false] : false; }
function wp_video_shortcode($attr) { return '<div class="wp-video"><video><source src="' . ($attr['src'] ?? '') . '"></video></div>'; }
function wp_delete_attachment($id, $force = false) { unset($GLOBALS['attachments'][(int) $id]); return true; }
function add_test_attachment(string $url, string $mime = 'image/jpeg'): int
{
    $id = 900 + count($GLOBALS['attachments']) + 1;
    $GLOBALS['attachments'][$id] = ['url' => $url, 'mime' => $mime];
    return $id;
}

/* ── WooCommerce orders ────────────────────────────────────────────────── */

class WC_Order_Item_Product
{
    public $id;
    public $product_id;
    public $qty;
    public $total = 10.0;
    public function __construct(int $id, int $product_id, int $qty)
    {
        $this->id         = $id;
        $this->product_id = $product_id;
        $this->qty        = $qty;
    }
    public function get_id() { return $this->id; }
    public function get_product() { return wc_get_product($this->product_id); }
    public function get_product_id() { return $this->product_id; }
    public function get_variation_id() { return 0; }
    public function get_quantity() { return $this->qty; }
    public function get_name() { return get_the_title($this->product_id); }
    public function get_total() { return $this->total; }
    public function get_subtotal() { return $this->total; }
}

class WC_Order
{
    public $id;
    public $status = 'completed';
    public $email;
    public $phone = '';
    public $first = 'Ann';
    public $last = 'Buyer';
    public $country = 'US';
    public $user_id = 0;
    public $items = [];
    public $refunded = [];
    public $meta = [];
    public $created;
    public $completed;
    public $notes = [];
    public $saved = 0;
    public function get_id() { return $this->id; }
    public function get_order_number() { return (string) $this->id; }
    public function get_billing_email() { return $this->email; }
    public function get_billing_phone() { return $this->phone; }
    public function get_billing_first_name() { return $this->first; }
    public function get_billing_last_name() { return $this->last; }
    public function get_billing_country() { return $this->country; }
    public function get_shipping_country() { return $this->country; }
    public function get_formatted_billing_full_name() { return trim($this->first . ' ' . $this->last); }
    public function get_user_id() { return $this->user_id; }
    public function get_customer_id() { return $this->user_id; }
    public function get_user() { return $this->user_id ? get_userdata($this->user_id) : false; }
    public function get_items($type = 'line_item') { return $this->items; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function add_meta_data($key, $value, $unique = false) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() { $this->saved++; return $this->id; }
    public function get_status() { return $this->status; }
    public function has_status($status) { return in_array($this->status, (array) $status, true); }
    public function get_date_created() { return $this->created ? new WC_DateTime('@' . $this->created) : null; }
    public function get_date_completed() { return $this->completed ? new WC_DateTime('@' . $this->completed) : null; }
    public function get_qty_refunded_for_item($item_id) { return -($this->refunded[$item_id] ?? 0); }
    public function get_edit_order_url() { return admin_url('post.php?post=' . $this->id . '&action=edit'); }
    public function get_view_order_url() { return home_url('my-account/view-order/' . $this->id); }
    public function add_order_note($note, $is_customer = 0, $added_by_user = false) { $this->notes[] = $note; return count($this->notes); }
    public function get_total() { $t = 0; foreach ($this->items as $i) { $t += $i->total; } return $t; }
    public function get_currency() { return 'USD'; }
}

$GLOBALS['orders'] = [];
function wc_get_order($id)
{
    if ($id instanceof WC_Order) {
        return $id;
    }
    return $GLOBALS['orders'][(int) $id] ?? false;
}

/**
 * billing_email, customer_id, status, date_created ('>' . ts), exclude, limit,
 * return=ids, orderby date.
 */
function wc_get_orders($args = [])
{
    $out = [];
    foreach ($GLOBALS['orders'] as $o) {
        if (!empty($args['billing_email']) && strtolower($o->email) !== strtolower((string) $args['billing_email'])) {
            continue;
        }
        if (!empty($args['customer_id']) && (int) $o->user_id !== (int) $args['customer_id']) {
            continue;
        }
        if (!empty($args['status'])) {
            $statuses = array_map(function ($s) {
                return preg_replace('/^wc-/', '', (string) $s);
            }, (array) $args['status']);
            if (!in_array($o->status, $statuses, true)) {
                continue;
            }
        }
        if (!empty($args['exclude']) && in_array((int) $o->id, array_map('intval', (array) $args['exclude']), true)) {
            continue;
        }
        if (!empty($args['date_created']) && is_string($args['date_created']) && strpos($args['date_created'], '>') === 0) {
            $after = (int) trim(substr($args['date_created'], 1), '= ');
            if ((int) $o->created <= $after) {
                continue;
            }
        }
        $out[] = $o;
    }
    if (!empty($args['meta_query']) && is_array($args['meta_query'])) {
        $out = array_values(array_filter($out, function ($o) use ($args) {
            return stub_order_meta_matches($o, $args['meta_query']);
        }));
    }
    usort($out, function ($a, $b) {
        return [$b->created, $b->id] <=> [$a->created, $a->id];
    });
    $total = count($out);
    $limit = (int) ($args['limit'] ?? -1);
    if ($limit > 0) {
        $page = max(1, (int) ($args['paged'] ?? 1));
        $out  = array_slice($out, ($page - 1) * $limit, $limit);
    }
    $out = ('ids' === ($args['return'] ?? '')) ? array_map(function ($o) {
        return $o->id;
    }, $out) : $out;
    if (!empty($args['paginate'])) {
        return (object) ['orders' => $out, 'total' => $total, 'max_num_pages' => $limit > 0 ? (int) ceil($total / $limit) : 1];
    }
    return $out;
}

/**
 * EXISTS / = clauses on order meta, with AND or OR.
 *
 * @param array<int|string, mixed> $query
 */
function stub_order_meta_matches(WC_Order $order, array $query): bool
{
    $relation = strtoupper((string) ($query['relation'] ?? 'AND'));
    $results  = [];
    foreach ($query as $k => $clause) {
        if ('relation' === $k || !is_array($clause) || !isset($clause['key'])) {
            continue;
        }
        $exists = array_key_exists($clause['key'], $order->meta) && '' !== $order->meta[$clause['key']];
        $compare = strtoupper((string) ($clause['compare'] ?? '='));
        if ('EXISTS' === $compare) {
            $results[] = $exists;
        } elseif ('NOT EXISTS' === $compare) {
            $results[] = !$exists;
        } else {
            $results[] = $exists && (string) $order->meta[$clause['key']] === (string) ($clause['value'] ?? '');
        }
    }
    if (empty($results)) {
        return true;
    }
    return 'OR' === $relation ? in_array(true, $results, true) : !in_array(false, $results, true);
}

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

$GLOBALS['as']       = [];
$GLOBALS['mails']    = [];
$GLOBALS['mail_ok']  = true;
$GLOBALS['unhooked'] = [];

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
function as_unschedule_action($hook, $args = null, $group = '') { as_unschedule_all_actions($hook, $args, $group); }
function wp_next_scheduled($hook, $args = []) { return false; }
function wp_schedule_single_event($ts, $hook, $args = []) { return as_schedule_single_action($ts, $hook, $args, 'wp-cron'); }
function wp_clear_scheduled_hook($hook, $args = []) {}
function wp_unschedule_hook($hook) { $GLOBALS['unhooked'][] = $hook; }
function wp_mail($to, $subject, $body, $headers = '', $attachments = [])
{
    $GLOBALS['mails'][] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers];
    return $GLOBALS['mail_ok'];
}

function actions_for(string $hook, ?array $args = null): array
{
    return array_values(array_filter($GLOBALS['as'], function ($a) use ($hook, $args) {
        return $a['hook'] === $hook && (null === $args || $a['args'] === $args);
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

/* ── Assets ────────────────────────────────────────────────────────────── */

$GLOBALS['styles']  = ['registered' => [], 'enqueued' => []];
$GLOBALS['scripts'] = ['registered' => [], 'enqueued' => [], 'localized' => []];
function wp_register_style($h, $src = '', $deps = [], $ver = false) { $GLOBALS['styles']['registered'][$h] = $src; return true; }
function wp_enqueue_style($h, $src = '') { $GLOBALS['styles']['enqueued'][$h] = true; }
function wp_style_is($h, $list = 'enqueued') { return 'registered' === $list ? isset($GLOBALS['styles']['registered'][$h]) : isset($GLOBALS['styles']['enqueued'][$h]); }
function wp_register_script($h, $src = '', $deps = [], $ver = false, $footer = false) { $GLOBALS['scripts']['registered'][$h] = $src; return true; }
function wp_enqueue_script($h, $src = '') { $GLOBALS['scripts']['enqueued'][$h] = true; }
function wp_script_is($h, $list = 'enqueued') { return 'registered' === $list ? isset($GLOBALS['scripts']['registered'][$h]) : isset($GLOBALS['scripts']['enqueued'][$h]); }
function wp_localize_script($h, $name, $data) { $GLOBALS['scripts']['localized'][$name] = $data; return true; }
function wp_add_inline_script($h, $data, $pos = 'after') { return true; }
function wp_enqueue_media() { $GLOBALS['media_enqueued'] = true; }

/* ── Rewrite ───────────────────────────────────────────────────────────── */

$GLOBALS['rewrite_flushed'] = 0;
function add_rewrite_tag($tag, $regex, $query = '') {}
function add_rewrite_rule($regex, $query, $after = 'bottom') { $GLOBALS['rewrite_rules'][$regex] = $query; }
function flush_rewrite_rules($hard = true) { $GLOBALS['rewrite_flushed']++; }

/* ── Helpers for the checks ────────────────────────────────────────────── */

function set_static(string $class, string $prop, $value): void
{
    $p = new ReflectionProperty($class, $prop);
    if (PHP_VERSION_ID < 80100) {
        $p->setAccessible(true);
    }
    $p->setValue(null, $value);
}

function call_private(string $class, string $method, ...$args)
{
    $m = new ReflectionMethod($class, $method);
    if (PHP_VERSION_ID < 80100) {
        $m->setAccessible(true);
    }
    return $m->invoke(null, ...$args);
}

/** Capture what a callback echoes. */
function capture(callable $cb): string
{
    ob_start();
    try {
        $cb();
    } finally {
        $out = (string) ob_get_clean();
    }
    return $out;
}

/** Run a handler that ends in wp_safe_redirect(); return the query of the target. */
function expect_redirect(callable $cb): array
{
    try {
        $cb();
    } catch (Redirected $r) {
        $query = [];
        parse_str((string) parse_url($r->url, PHP_URL_QUERY), $query);
        $query['__url'] = $r->url;
        return $query;
    }
    throw new RuntimeException('handler did not redirect');
}

/** Run an AJAX handler; return [success, data, status]. */
function expect_json(callable $cb): array
{
    try {
        $cb();
    } catch (JsonSent $j) {
        return [$j->success, $j->payload, $j->status];
    }
    throw new RuntimeException('handler did not answer with JSON');
}

function reset_request(int $user = 0): void
{
    $GLOBALS['user'] = $user;
    $_POST           = [];
    $_GET            = [];
    $_REQUEST        = [];
    $_FILES          = [];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

abstract class FFLA_Module
{
    public function get_path(): string
    {
        return FFLA_PATH . 'modules/' . $this->get_id() . '/';
    }
    public function get_url(): string
    {
        return FFLA_URL . 'modules/' . $this->get_id() . '/';
    }
}

/** The shared admin shell's field helpers, printing just enough markup to check. */
class FFLA_Admin
{
    public static function render_text_field($label, $name, $value, $desc) { echo '<input type="text" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; }
    public static function render_password_field($label, $name, $value, $desc) { echo '<input type="password" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; }
    public static function render_toggle_field($label, $name, $value, $desc) { echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1"' . ('1' === (string) $value ? ' checked' : '') . '>'; }
    public static function render_select_field($label, $name, $value, $options, $desc)
    {
        echo '<select name="' . esc_attr($name) . '">';
        foreach ($options as $k => $l) {
            echo '<option value="' . esc_attr((string) $k) . '"' . ((string) $k === (string) $value ? ' selected' : '') . '>' . esc_html($l) . '</option>';
        }
        echo '</select>';
    }
    public static function render_color_field($label, $name, $value, $desc) { echo '<input type="color" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">'; }
    public static function render_textarea_field($label, $name, $value, $desc) { echo '<textarea name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea>'; }
    public static function render_media_field($label, $name, $id, $desc, $button = '') { echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . (int) $id . '">'; }
    public static function render_notice($type, $message) { echo '<div class="wb-message wb-message--' . esc_attr($type) . '">' . $message . '</div>'; }
}
function wp_dropdown_pages($args = []) { echo '<select name="' . esc_attr($args['name'] ?? 'page_id') . '"></select>'; }
function wp_editor($content, $id, $settings = []) { echo '<textarea name="' . esc_attr($settings['textarea_name'] ?? $id) . '">' . esc_textarea($content) . '</textarea>'; }
function get_current_screen() { return $GLOBALS['screen'] ?? null; }

/** Load module classes by file name relative to modules/product-reviews/. */
function load_module(array $files): void
{
    foreach ($files as $file) {
        require_once FFLA_PATH . 'modules/product-reviews/' . $file;
    }
}

/* ── $wpdb over SQLite ─────────────────────────────────────────────────── */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

/**
 * Runs the module's own SQL for real. Before every statement the in-memory
 * comments, comment meta, posts and product categories are copied into
 * SQLite tables named like WordPress's; after a write they are copied back.
 * MySQL-isms the module uses (CAST AS SIGNED/UNSIGNED, LIMIT/OFFSET, GROUP
 * BY with MAX()) behave the same in SQLite for these checks.
 */
class Stub_Wpdb
{
    public $prefix = 'wp_';
    public $comments = 'wp_comments';
    public $commentmeta = 'wp_commentmeta';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $options = 'wp_options';
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $term_relationships = 'wp_term_relationships';
    public $last_query = '';
    public $queries = [];
    public $insert_id = 0;
    /** @var SQLite3 */
    private $db;

    public function __construct()
    {
        $this->db = new SQLite3(':memory:');
        $this->db->exec('CREATE TABLE wp_comments (comment_ID INTEGER PRIMARY KEY, comment_post_ID INTEGER, comment_author TEXT, comment_author_email TEXT, comment_content TEXT, comment_type TEXT, comment_parent INTEGER, user_id INTEGER, comment_approved TEXT, comment_karma INTEGER, comment_date_gmt TEXT)');
        $this->db->exec('CREATE TABLE wp_commentmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, comment_id INTEGER, meta_key TEXT, meta_value TEXT)');
        $this->db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_title TEXT)');
        $this->db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT, parent INTEGER)');
        $this->db->exec('CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)');
    }

    private function sync_in(): void
    {
        foreach (['wp_comments', 'wp_commentmeta', 'wp_posts', 'wp_term_taxonomy', 'wp_term_relationships'] as $t) {
            $this->db->exec("DELETE FROM $t");
        }
        $c = $this->db->prepare('INSERT INTO wp_comments VALUES (:id, :post, :author, :email, :content, :type, :parent, :user, :approved, :karma, :date)');
        foreach ($GLOBALS['comments'] as $comment) {
            $c->bindValue(':id', (int) $comment->comment_ID);
            $c->bindValue(':post', (int) $comment->comment_post_ID);
            $c->bindValue(':author', (string) $comment->comment_author);
            $c->bindValue(':email', (string) $comment->comment_author_email);
            $c->bindValue(':content', (string) $comment->comment_content);
            $c->bindValue(':type', (string) $comment->comment_type);
            $c->bindValue(':parent', (int) $comment->comment_parent);
            $c->bindValue(':user', (int) $comment->user_id);
            $c->bindValue(':approved', (string) $comment->comment_approved);
            $c->bindValue(':karma', (int) $comment->comment_karma);
            $c->bindValue(':date', (string) $comment->comment_date_gmt);
            $c->execute();
        }
        $m = $this->db->prepare('INSERT INTO wp_commentmeta (comment_id, meta_key, meta_value) VALUES (:id, :key, :value)');
        foreach ($GLOBALS['cmeta'] as $comment_id => $meta) {
            foreach ($meta as $key => $value) {
                $m->bindValue(':id', (int) $comment_id);
                $m->bindValue(':key', (string) $key);
                $m->bindValue(':value', is_array($value) || is_object($value) ? serialize($value) : (string) $value);
                $m->execute();
            }
        }
        $p = $this->db->prepare('INSERT INTO wp_posts VALUES (:id, :type, :status, :title)');
        foreach ($GLOBALS['posts'] as $id => $post) {
            $p->bindValue(':id', (int) $id);
            $p->bindValue(':type', (string) $post[0]);
            $p->bindValue(':status', (string) $post[1]);
            $p->bindValue(':title', (string) ($post[2] ?? ''));
            $p->execute();
        }
        foreach ($GLOBALS['terms'] as $term_id => $term) {
            $this->db->exec(sprintf("INSERT INTO wp_term_taxonomy VALUES (%d, %d, '%s', %d)", $term_id, $term_id, SQLite3::escapeString($term[0]), (int) $term[3]));
        }
        foreach ($GLOBALS['product_props'] as $product_id => $props) {
            foreach ($props['cats'] ?? [] as $term_id) {
                $this->db->exec(sprintf('INSERT INTO wp_term_relationships VALUES (%d, %d)', $product_id, $term_id));
            }
        }
    }

    /** Copy writes back: comment columns the module updates, and comment meta. */
    private function sync_out(): void
    {
        $rows = $this->db->query('SELECT comment_ID, comment_karma, comment_approved FROM wp_comments');
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            if (isset($GLOBALS['comments'][(int) $row['comment_ID']])) {
                $GLOBALS['comments'][(int) $row['comment_ID']]->comment_karma    = (int) $row['comment_karma'];
                $GLOBALS['comments'][(int) $row['comment_ID']]->comment_approved = (string) $row['comment_approved'];
            }
        }
        $meta = [];
        $rows = $this->db->query('SELECT comment_id, meta_key, meta_value FROM wp_commentmeta');
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $value = $row['meta_value'];
            $un    = @unserialize((string) $value);
            $meta[(int) $row['comment_id']][$row['meta_key']] = (false !== $un || 'b:0;' === $value) ? $un : $value;
        }
        $GLOBALS['cmeta'] = $meta;
    }

    public function prepare($query, ...$args)
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        return preg_replace_callback('/%(%|d|s|f)/', function ($m) use (&$i, $args) {
            if ('%' === $m[1]) {
                return '%';
            }
            $v = $args[$i++] ?? '';
            if ('d' === $m[1]) {
                return (string) (int) $v;
            }
            if ('f' === $m[1]) {
                return (string) (float) $v;
            }
            return "'" . SQLite3::escapeString((string) $v) . "'";
        }, $query);
    }

    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }

    private function run(string $sql)
    {
        $this->last_query = $sql;
        $this->queries[]  = $sql;
        $this->sync_in();
        $result = @$this->db->query($sql);
        if (false === $result) {
            throw new RuntimeException('SQL error: ' . $this->db->lastErrorMsg() . "\n" . $sql);
        }
        return $result;
    }

    public function get_col($sql, $x = 0)
    {
        $result = $this->run($sql);
        $out    = [];
        while ($row = $result->fetchArray(SQLITE3_NUM)) {
            $out[] = $row[$x];
        }
        return $out;
    }

    public function get_var($sql)
    {
        $row = $this->run($sql)->fetchArray(SQLITE3_NUM);
        return $row ? $row[0] : null;
    }

    public function get_results($sql, $output = 'OBJECT')
    {
        $result = $this->run($sql);
        $out    = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $out[] = 'ARRAY_A' === $output ? $row : (object) $row;
        }
        return $out;
    }

    public function get_row($sql, $output = 'OBJECT')
    {
        $rows = $this->get_results($sql, $output);
        return $rows[0] ?? null;
    }

    public function query($sql)
    {
        $this->run($sql);
        $this->sync_out();
        return $this->db->changes();
    }

    public function update($table, $data, $where, $format = null, $where_format = null)
    {
        $set = [];
        foreach ($data as $k => $v) {
            $set[] = $k . " = '" . SQLite3::escapeString((string) $v) . "'";
        }
        $cond = [];
        foreach ($where as $k => $v) {
            $cond[] = $k . " = '" . SQLite3::escapeString((string) $v) . "'";
        }
        return $this->query("UPDATE $table SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $cond));
    }
}
$GLOBALS['wpdb'] = new Stub_Wpdb();

function get_term_children($term_id, $taxonomy)
{
    $out = [];
    foreach ($GLOBALS['terms'] as $id => $t) {
        if ((int) $t[3] === (int) $term_id && $t[0] === $taxonomy) {
            $out[] = $id;
            $out   = array_merge($out, get_term_children($id, $taxonomy));
        }
    }
    return $out;
}
function wp_list_pluck($list, $field)
{
    return array_map(function ($o) use ($field) {
        return is_array($o) ? $o[$field] : $o->$field;
    }, $list);
}

/* ── Bricks ────────────────────────────────────────────────────────────── */

function load_bricks_stubs(): void
{
    if (class_exists('\Bricks\Element')) {
        return;
    }
    eval('namespace Bricks;
    class Element
    {
        public $settings = [];
        public $control_groups = [];
        public $controls = [];
        private $attributes = [];
        public function __construct($settings = []) { $this->settings = $settings; }
        public function set_attribute($key, $attr, $value) { $this->attributes[$key][$attr][] = $value; }
        public function set_link_attributes($key, $link) { $this->attributes[$key]["href"][] = $link["url"] ?? ""; }
        public function render_attributes($key)
        {
            $out = [];
            foreach ($this->attributes[$key] ?? [] as $attr => $values) {
                $out[] = $attr . "=\"" . implode(" ", $values) . "\"";
            }
            return implode(" ", $out);
        }
        public function render_element_placeholder($args) { return "<div class=\"placeholder\">" . $args["title"] . "</div>"; }
    }
    class Helpers
    {
        public static function get_bricks_data($post_id, $type) { return $GLOBALS["bricks_data"] ?? false; }
    }');
}

/* ── Context ───────────────────────────────────────────────────────────── */

$GLOBALS['is_product'] = false;
$GLOBALS['queried']    = 0;
function is_product() { return !empty($GLOBALS['is_product']); }
function is_page($id = 0) { return false; }
function is_singular($type = '') { return false; }
function get_queried_object_id() { return (int) ($GLOBALS['queried'] ?? 0); }
function get_the_ID() { return (int) ($GLOBALS['queried'] ?? 0); }
function is_checkout() { return !empty($GLOBALS['is_checkout']); }

/* ── WooCommerce globals, meta boxes ───────────────────────────────────── */

class Stub_WC_Countries
{
    public function get_country_calling_code($cc)
    {
        $codes = ['US' => '+1', 'CA' => '+1', 'MX' => '+52', 'GB' => '+44'];
        return $codes[strtoupper((string) $cc)] ?? '';
    }
}
class Stub_WC
{
    public $countries;
    public $structured_data = null;
    public function __construct() { $this->countries = new Stub_WC_Countries(); }
}
$GLOBALS['woocommerce'] = new Stub_WC();
if (!function_exists('WC')) {
    function WC() { return $GLOBALS['woocommerce']; }
}

$GLOBALS['meta_boxes'] = [];
function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default')
{
    $GLOBALS['meta_boxes'][] = ['id' => $id, 'screen' => $screen, 'callback' => $callback];
}
function wc_get_page_screen_id($for) { return 'woocommerce_page_wc-orders'; }

/* ── Coupons ───────────────────────────────────────────────────────────── */

$GLOBALS['coupons'] = [];
class WC_Coupon
{
    public $id = 0;
    public $props = ['code' => '', 'discount_type' => 'fixed_cart', 'amount' => 0, 'date_expires' => null, 'email_restrictions' => [], 'usage_limit' => 0, 'usage_limit_per_user' => 0, 'individual_use' => false, 'exclude_sale_items' => false, 'free_shipping' => false, 'minimum_amount' => '', 'maximum_amount' => '', 'product_categories' => [], 'excluded_product_categories' => [], 'description' => ''];
    public $meta = [];
    public function __construct($id_or_code = 0)
    {
        if ($id_or_code && isset($GLOBALS['coupons'][(int) $id_or_code])) {
            $saved       = $GLOBALS['coupons'][(int) $id_or_code];
            $this->id    = $saved->id;
            $this->props = $saved->props;
            $this->meta  = $saved->meta;
        }
    }
    public function __call($name, $args)
    {
        if (strpos($name, 'set_') === 0) {
            $key = substr($name, 4);
            $this->props[$key] = 'date_expires' === $key && $args[0] !== null ? new WC_DateTime('@' . (int) $args[0]) : $args[0];
            return null;
        }
        if (strpos($name, 'get_') === 0) {
            return $this->props[substr($name, 4)] ?? null;
        }
        throw new BadMethodCallException($name);
    }
    public function get_id() { return $this->id; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function get_meta($key) { return $this->meta[$key] ?? ''; }
    public function save()
    {
        if (!$this->id) {
            $this->id = 7000 + count($GLOBALS['coupons']) + 1;
        }
        $GLOBALS['coupons'][$this->id] = $this;
        return $this->id;
    }
}
function wc_get_coupon_id_by_code($code)
{
    foreach ($GLOBALS['coupons'] as $id => $c) {
        if (strtolower($c->props['code']) === strtolower((string) $code)) {
            return $id;
        }
    }
    return 0;
}
function wc_get_page_permalink($page) { return 'https://store.test/' . $page . '/'; }

/* ── Files, downloads, sideloads ───────────────────────────────────────── */

$GLOBALS['upload_base'] = rtrim(sys_get_temp_dir(), '/\\') . '/ffla-pr-uploads-' . getmypid();
register_shutdown_function(function () {
    $base = $GLOBALS['upload_base'];
    if (!is_dir($base)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($base);
});
function wp_upload_dir($time = null, $create = true, $refresh = false) { return ['basedir' => $GLOBALS['upload_base'], 'baseurl' => 'https://store.test/wp-content/uploads', 'error' => false]; }
function wp_mkdir_p($dir) { return is_dir($dir) || @mkdir($dir, 0777, true); }
function get_date_from_gmt($date, $format = 'Y-m-d H:i:s') { return gmdate($format, strtotime($date . ' UTC')); }
function wc_get_product_id_by_sku($sku)
{
    foreach ($GLOBALS['product_props'] as $id => $props) {
        if (($props['sku'] ?? '') === $sku) {
            return $id;
        }
    }
    return 0;
}

/** URL => file bytes, or WP_Error. */
$GLOBALS['downloads'] = [];
function download_url($url, $timeout = 300)
{
    if (!isset($GLOBALS['downloads'][$url])) {
        return new WP_Error('http_404', 'Not Found');
    }
    if ($GLOBALS['downloads'][$url] instanceof WP_Error) {
        return $GLOBALS['downloads'][$url];
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ffla-dl-');
    file_put_contents($tmp, $GLOBALS['downloads'][$url]);
    $GLOBALS['tmp_files'][] = $tmp;
    return $tmp;
}
function wp_check_filetype_and_ext($tmp, $name, $mimes = null)
{
    $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm'];
    $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
    return isset($map[$ext]) ? ['ext' => $ext, 'type' => $map[$ext], 'proper_filename' => false] : ['ext' => false, 'type' => false, 'proper_filename' => false];
}
$GLOBALS['sideloaded'] = [];
function media_handle_sideload($file, $post_id = 0, $desc = null, $overrides = [])
{
    $GLOBALS['sideloaded'][] = ['name' => $file['name'], 'bytes' => file_get_contents($file['tmp_name'])];
    @unlink($file['tmp_name']);
    return add_test_attachment('https://store.test/wp-content/uploads/' . $file['name']);
}

/** Records WooCommerce rating recounts. */
class WC_Comments
{
    public static $cleared = [];
    public static function clear_transients($post_id) { self::$cleared[] = (int) $post_id; }
}

/** Build a small valid PNG; $extra chunks go between IHDR and IDAT. */
function stub_png(string $extra = ''): string
{
    $chunk = function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    return "\x89PNG\r\n\x1A\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)) . $extra
        . $chunk('IDAT', gzcompress("\x00\xff\x00\x00")) . $chunk('IEND', '');
}
function stub_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

/* ── HTTP (CAPTCHA verify endpoints) ───────────────────────────────────── */

/** URL => ['code' => int, 'body' => string] or WP_Error. */
$GLOBALS['http_responses'] = [];
$GLOBALS['http_requests']  = [];
function wp_safe_remote_post($url, $args = [])
{
    $GLOBALS['http_requests'][] = ['url' => $url, 'args' => $args];
    $r = $GLOBALS['http_responses'][$url] ?? new WP_Error('http_request_failed', 'unreachable');
    return $r;
}
function wp_remote_retrieve_response_code($r) { return is_array($r) ? (int) ($r['code'] ?? 0) : 0; }
function wp_remote_retrieve_body($r) { return is_array($r) ? (string) ($r['body'] ?? '') : ''; }
function wp_login_url($redirect = '') { return 'https://store.test/wp-login.php' . ($redirect !== '' ? '?redirect_to=' . rawurlencode($redirect) : ''); }
