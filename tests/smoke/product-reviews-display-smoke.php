<?php
/**
 * Offline regression harness for Product Reviews display, SEO and performance.
 *
 * Run: php tests/smoke/product-reviews-display-smoke.php
 *
 * Stubs WordPress and WooCommerce with in-memory comments, comment meta and
 * products, then checks:
 * - review videos keep their <source> and images keep srcset/sizes (the
 *   wp_kses_post stub drops them the way core's 'post' allowlist does);
 * - "Verified buyer" honours WooCommerce's own `verified` meta;
 * - summary, badge and dynamic tags use WooCommerce's stored rating counts,
 *   average and review count, with no query or transient of their own;
 * - the CSS/JS load from the elements and renderers, not on every product page;
 * - the product structured data (WooCommerce and Rank Math) marks up exactly
 *   the rated reviews the FFL list shows;
 * - pinning refreshes the product's comment count (page cache purge hooks);
 * - helpful votes: fresh nonce on a stale one, daily cap keyed by date,
 *   visitor IP from CF-Connecting-IP only behind a real Cloudflare edge.
 */

define('ABSPATH', __DIR__);
define('FFLA_URL', 'https://shop.test/wp-content/plugins/ffl-funnels-addons/');
define('FFLA_VERSION', 'test');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

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
    public $status;
    public function __construct($payload, $status = null)
    {
        $this->payload = $payload;
        $this->status  = $status;
        parent::__construct('json');
    }
}

/* ── In-memory WordPress ───────────────────────────────────────────────── */

$GLOBALS['options']      = ['date_format' => 'Y-m-d'];
$GLOBALS['filters']      = [];
$GLOBALS['comments']     = [];
$GLOBALS['cmeta']        = [];
$GLOBALS['products']     = [];
$GLOBALS['post_types']   = [];
$GLOBALS['transients']   = [];
$GLOBALS['transient_reads'] = 0;
$GLOBALS['is_product']   = false;
$GLOBALS['queried']      = 0;
$GLOBALS['today']        = '20261021';
$GLOBALS['doing']        = [];
$GLOBALS['comment_count_refreshed'] = [];
$GLOBALS['wc_recounted'] = [];
$GLOBALS['styles']       = ['registered' => [], 'enqueued' => []];
$GLOBALS['scripts']      = ['registered' => [], 'enqueued' => [], 'localized' => 0];
$GLOBALS['bricks_data']  = false;
$GLOBALS['nonce_ok']     = true;

class WP_Comment
{
    public $comment_ID;
    public $comment_post_ID;
    public $comment_author = '';
    public $comment_content = '';
    public $comment_date_gmt = '2026-01-01 00:00:00';
    public $comment_approved = '1';
    public $comment_type = 'review';
    public $comment_parent = 0;
    public $comment_karma = 0;
    public function __construct(array $fields)
    {
        foreach ($fields as $key => $value) {
            $this->$key = $value;
        }
    }
}

class WP_Post
{
    public $ID;
    public function __construct($id) { $this->ID = $id; }
}

class WC_Product
{
    private $id;
    private $counts;
    private $average;
    private $reviews;
    public function __construct($id, array $counts, $average, $reviews)
    {
        $this->id = $id;
        $this->counts = $counts;
        $this->average = $average;
        $this->reviews = $reviews;
    }
    public function get_id() { return $this->id; }
    public function get_parent_id() { return 0; }
    public function is_type($type) { return false; }
    public function get_rating_counts() { return $this->counts; }
    public function get_average_rating() { return $this->average; }
    public function get_review_count() { return $this->reviews; }
}

class WC_Comments
{
    public static function clear_transients($post_id) { $GLOBALS['wc_recounted'][] = (int) $post_id; }
}

class WC_Structured_Data
{
    private $data = [];
    public function get_data() { return $this->data; }
    public function set_data($data, $reset = false)
    {
        if ($reset) {
            $this->data = [];
        }
        $this->data[] = $data;
        return true;
    }
}

class FakeWoo
{
    public $structured_data;
}
$GLOBALS['woo'] = new FakeWoo();
$GLOBALS['woo']->structured_data = new WC_Structured_Data();
function WC() { return $GLOBALS['woo']; }

class FakeWpdb
{
    public $comments = 'wp_comments';
    public $commentmeta = 'wp_commentmeta';
    public $calls = 0;
    private $args = [];
    public function prepare($sql, ...$args) { $this->args = $args; return $sql; }
    public function query($sql)
    {
        $this->calls++;
        if (strpos($sql, 'UPDATE wp_commentmeta') !== false) {
            [$comment_id, $key] = $this->args;
            $GLOBALS['cmeta'][$comment_id][$key] = (int) ($GLOBALS['cmeta'][$comment_id][$key] ?? 0) + 1;
        }
        return 1;
    }
    public function update($table, $data, $where)
    {
        $this->calls++;
        $comment = $GLOBALS['comments'][$where['comment_ID']] ?? null;
        foreach ($data as $key => $value) {
            $comment->$key = $value;
        }
        return 1;
    }
    public function get_results(...$a) { $this->calls++; return []; }
    public function get_col(...$a) { $this->calls++; return []; }
    public function get_var(...$a) { $this->calls++; return null; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

function __($t, $d = '') { return $t; }
function esc_html__($t, $d = '') { return $t; }
function _n($single, $plural, $n, $d = '') { return 1 == $n ? $single : $plural; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function absint($v) { return abs((int) $v); }
function sanitize_text_field($s) { return trim((string) $s); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function wp_unslash($s) { return $s; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, (array) $args); }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function add_filter(...$a) {}
function add_action(...$a) {}
function apply_filters($tag, $value, ...$args)
{
    return isset($GLOBALS['filters'][$tag]) ? call_user_func($GLOBALS['filters'][$tag], $value, ...$args) : $value;
}
function doing_action($tag) { return in_array($tag, $GLOBALS['doing'], true); }
function doing_filter($tag) { return in_array($tag, $GLOBALS['doing'], true); }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d); }
function wpautop($t) { return '<p>' . $t . '</p>'; }
function wp_strip_all_tags($t) { return strip_tags((string) $t); }
function wp_kses($html, $allowed) { return strip_tags((string) $html, '<p><br><strong><em>'); }
/**
 * The parts of core's 'post' allowlist (wp-includes/kses.php) that matter
 * here: no <source> element at all, and <img> without srcset, sizes or decoding.
 */
function wp_kses_post($html)
{
    $html = preg_replace('#</?source\b[^>]*>#i', '', (string) $html);
    return preg_replace_callback('#<img\b[^>]*>#i', function ($m) {
        return preg_replace('#\s(?:srcset|sizes|decoding)="[^"]*"#i', '', $m[0]);
    }, $html);
}
function wp_date($format, $ts = null) { return $format === 'Ymd' ? $GLOBALS['today'] : gmdate($format, $ts ?? time()); }
function get_post_type($id) { return $GLOBALS['post_types'][(int) $id] ?? false; }
function wc_get_product($id) { return $GLOBALS['products'][(int) $id] ?? false; }
function wc_review_ratings_enabled() { return true; }
function get_the_ID() { return 0; }
function get_queried_object_id() { return $GLOBALS['queried']; }
function is_product() { return $GLOBALS['is_product']; }
function is_page($id = 0) { return false; }
function is_singular($type = '') { return false; }
function get_post() { return null; }
function has_shortcode($c, $t) { return false; }
function comments_open($id) { return true; }
function admin_url($p = '') { return 'https://shop.test/wp-admin/' . $p; }
function wp_create_nonce($a) { return 'fresh-nonce'; }
// Signed in, so the vote nonce is checked even where guests skip it (PR #33).
function is_user_logged_in() { return true; }
function check_ajax_referer($action, $arg = false, $die = true) { return $GLOBALS['nonce_ok'] ? 1 : false; }
function wp_send_json_error($d = null, $status = null) { throw new JsonSent(['success' => false, 'data' => $d], $status); }
function wp_send_json_success($d = null) { throw new JsonSent(['success' => true, 'data' => $d]); }
function get_transient($k)
{
    $GLOBALS['transient_reads']++;
    return $GLOBALS['transients'][$k] ?? false;
}
function set_transient($k, $v, $ttl) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function wp_cache_delete($k, $g = '') { return true; }
function clean_comment_cache($id) {}
function wp_update_comment_count_now($post_id) { $GLOBALS['comment_count_refreshed'][] = (int) $post_id; return true; }

function wp_style_is($h, $list = 'enqueued') { return isset($GLOBALS['styles'][$list][$h]); }
function wp_register_style($h, ...$a) { $GLOBALS['styles']['registered'][$h] = true; }
function wp_register_script($h, ...$a) { $GLOBALS['scripts']['registered'][$h] = true; }
function wp_enqueue_style($h) { $GLOBALS['styles']['enqueued'][$h] = true; }
function wp_enqueue_script($h) { $GLOBALS['scripts']['enqueued'][$h] = true; }
function wp_localize_script($h, $name, $data) { $GLOBALS['scripts']['localized']++; return true; }

function get_comment($id)
{
    if ($id instanceof WP_Comment) {
        return $id;
    }
    return $GLOBALS['comments'][(int) $id] ?? null;
}
function get_comment_meta($id, $key, $single = false) { return $GLOBALS['cmeta'][(int) $id][$key] ?? ''; }
function metadata_exists($type, $id, $key) { return isset($GLOBALS['cmeta'][(int) $id][$key]); }
function add_comment_meta($id, $key, $value, $unique = false)
{
    if (!isset($GLOBALS['cmeta'][$id][$key])) {
        $GLOBALS['cmeta'][$id][$key] = $value;
    }
    return true;
}
function get_comment_author($c) { return get_comment($c)->comment_author; }
function get_comment_text($c) { return get_comment($c)->comment_content; }
function get_comment_date($format, $c) { return gmdate($format, strtotime(get_comment($c)->comment_date_gmt . ' UTC')); }
function wp_list_pluck($list, $field) { return array_map(function ($o) use ($field) { return $o->$field; }, $list); }
/** Supports what list_reviews() asks for: approved top-level reviews, pinned then newest. */
function get_comments(array $args)
{
    $rows = array_filter($GLOBALS['comments'], function ($c) use ($args) {
        return (int) $c->comment_post_ID === (int) $args['post_id']
            && '1' === (string) $c->comment_approved
            && $c->comment_type === $args['type']
            && (int) $c->comment_parent === (int) $args['parent'];
    });
    usort($rows, function ($a, $b) {
        return [$b->comment_karma, $b->comment_date_gmt] <=> [$a->comment_karma, $a->comment_date_gmt];
    });
    return array_slice(array_values($rows), 0, $args['number']);
}

function get_post_mime_type($id) { return $GLOBALS['mimes'][$id] ?? ''; }
function wp_get_attachment_url($id) { return 'https://shop.test/uploads/' . $id . ($GLOBALS['mimes'][$id] === 'video/mp4' ? '.mp4' : '.jpg'); }
/** Core's markup for an MP4: the file is only in the <source> child. */
function wp_video_shortcode($attr)
{
    return '<div style="width: 640px;" class="wp-video"><video class="wp-video-shortcode" id="video-0-1" width="640" height="360" preload="'
        . $attr['preload'] . '" controls="controls"><source type="video/mp4" src="' . $attr['src'] . '?_=1" /><a href="'
        . $attr['src'] . '">' . $attr['src'] . '</a></video></div>';
}
function wp_get_attachment_image($id, $size)
{
    return '<img width="300" height="200" src="https://shop.test/uploads/' . $id . '-300x200.jpg" class="attachment-medium size-medium" alt="" decoding="async"'
        . ' srcset="https://shop.test/uploads/' . $id . '-300x200.jpg 300w, https://shop.test/uploads/' . $id . '.jpg 1200w" sizes="(max-width: 300px) 100vw, 300px" />';
}

eval('namespace Bricks;
class Element
{
    public $settings = [];
    private $attributes = [];
    public function __construct($settings = []) { $this->settings = $settings; }
    public function set_attribute($key, $attr, $value) { $this->attributes[$key][$attr][] = $value; }
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
    public static function get_bricks_data($post_id, $type) { return $GLOBALS["bricks_data"]; }
}');

$base = dirname(__DIR__, 2) . '/modules/product-reviews/';
require $base . 'includes/class-product-reviews-core.php';
require $base . 'includes/class-product-reviews-criteria.php';
require $base . 'includes/class-product-reviews-frontend-render.php';
require $base . 'includes/class-product-reviews-assets.php';
require $base . 'includes/class-product-reviews-structured-data.php';
require $base . 'includes/class-product-reviews-moderation.php';
require $base . 'includes/class-product-reviews-ajax.php';
require $base . 'integrations/class-product-reviews-dynamic-tags.php';
require $base . 'integrations/elements/reviews-rating-badge.php';

function settings(array $overrides = [])
{
    $GLOBALS['options']['ffla_product_reviews_settings'] = array_merge([
        'enable_criteria'      => '0',
        'show_replies'         => '0',
        'enable_helpful_votes' => '1',
    ], $overrides);
}

function reset_static($class, $property, $value)
{
    $p = new ReflectionProperty($class, $property);
    $p->setAccessible(true);
    $p->setValue(null, $value);
}

function reset_assets()
{
    $GLOBALS['styles']  = ['registered' => [], 'enqueued' => []];
    $GLOBALS['scripts'] = ['registered' => [], 'enqueued' => [], 'localized' => 0];
    reset_static('Product_Reviews_Assets', 'script_enqueued', false);
}

function reset_page()
{
    reset_static('Product_Reviews_Frontend_Render', 'rendered', []);
    reset_static('Product_Reviews_Structured_Data', 'woocommerce_nodes', []);
    $GLOBALS['bricks_data'] = false;
    $GLOBALS['filters'] = [];
}

function add_review($id, $product_id, array $fields, array $meta = [])
{
    $GLOBALS['comments'][$id] = new WP_Comment(array_merge([
        'comment_ID'      => $id,
        'comment_post_ID' => $product_id,
        'comment_author'  => 'Author ' . $id,
        'comment_content' => 'Body of review ' . $id,
    ], $fields));
    $GLOBALS['cmeta'][$id] = $meta;
}

function render_list($product_id, array $settings)
{
    ob_start();
    Product_Reviews_Frontend_Render::render_reviews_list($product_id, $settings);
    return (string) ob_get_clean();
}

/** Each rendered card, keyed by author, in display order. */
function cards($html)
{
    $cards = [];
    foreach (array_slice(explode('<article', $html), 1) as $card) {
        preg_match('#ffla-review-card__author">([^<]+)<#', $card, $m);
        $cards[$m[1]] = $card;
    }
    return $cards;
}

function authors(array $node)
{
    return array_map(function ($r) { return $r['author']['name']; }, $node['review'] ?? []);
}

settings();

/* ── Assets load from the output, not from every product page ──────────── */

$GLOBALS['is_product'] = true;
Product_Reviews_Assets::enqueue_frontend_assets();
check(isset($GLOBALS['styles']['registered']['ffla-product-reviews']), 'assets registered on every page');
check(empty($GLOBALS['styles']['enqueued']) && empty($GLOBALS['scripts']['enqueued']), 'a product page without FFL review output loads no CSS/JS');

settings(['replace_default_reviews_tab' => '1']);
Product_Reviews_Assets::enqueue_frontend_assets();
check(isset($GLOBALS['styles']['enqueued']['ffla-product-reviews'], $GLOBALS['scripts']['enqueued']['ffla-product-reviews']), 'replaced Reviews tab loads CSS/JS in <head>');
settings();
reset_assets();
$GLOBALS['is_product'] = false;

(new \Bricks\FFLA_Reviews_Rating_Badge())->enqueue_scripts();
check(isset($GLOBALS['styles']['enqueued']['ffla-product-reviews']), 'rating badge (shop loop) enqueues the styles');
check(empty($GLOBALS['scripts']['enqueued']), 'rating badge does not load the script');
reset_assets();

/* ── Review media and Verified buyer ───────────────────────────────────── */

$GLOBALS['post_types'][30] = 'product';
$GLOBALS['products'][30]   = new WC_Product(30, [], 0, 0);
$GLOBALS['mimes'] = [901 => 'video/mp4', 902 => 'image/jpeg'];
add_review(301, 30, ['comment_author' => 'Media Mo', 'comment_date_gmt' => '2026-02-05 10:00:00'], ['rating' => 5, 'ffla_review_media_ids' => [901, 902], 'ffla_verified_purchase' => 1]);
add_review(302, 30, ['comment_author' => 'Woo Wes', 'comment_date_gmt' => '2026-02-04 10:00:00'], ['rating' => 4, 'verified' => '1']);
add_review(303, 30, ['comment_author' => 'Guest Gil', 'comment_date_gmt' => '2026-02-03 10:00:00'], ['rating' => 3, 'ffla_verified_purchase' => 0, 'verified' => '0']);
add_review(304, 30, ['comment_author' => 'Bare Bo', 'comment_date_gmt' => '2026-02-02 10:00:00'], ['rating' => 3]);
// A guest who typed a past buyer's email: WooCommerce says verified, the module's own check said no.
add_review(305, 30, ['comment_author' => 'Typed Ty', 'comment_date_gmt' => '2026-02-01 10:00:00'], ['rating' => 5, 'ffla_verified_purchase' => 0, 'verified' => '1']);

$html  = render_list(30, ['perPage' => 10, 'showSummary' => false]);
$cards = cards($html);

check(strpos(wp_kses_post(wp_video_shortcode(['src' => 'x.mp4', 'preload' => 'metadata'])), '<source') === false, 'sanity: the kses stub drops <source> like core');
check(strpos($cards['Media Mo'], '<source type="video/mp4" src="https://shop.test/uploads/901.mp4?_=1" />') !== false, 'video player keeps its <source>');
check(strpos($cards['Media Mo'], 'ffla-review-card__media-item--video"><div style="width: 640px;" class="wp-video">') !== false, 'video markup printed as core built it');
check(strpos($cards['Media Mo'], 'srcset="https://shop.test/uploads/902-300x200.jpg 300w') !== false
    && strpos($cards['Media Mo'], 'sizes="(max-width: 300px) 100vw, 300px"') !== false, 'review image keeps srcset and sizes');

check(strpos($cards['Media Mo'], 'Verified buyer') !== false, 'FFL verified flag shows the label');
check(strpos($cards['Woo Wes'], 'Verified buyer') !== false, 'older review with only WooCommerce\'s `verified` meta shows the label');
check(strpos($cards['Guest Gil'], 'Verified buyer') === false, 'unverified review has no label');
check(strpos($cards['Bare Bo'], 'Verified buyer') === false, 'review with no flags has no label');
check(strpos($cards['Typed Ty'], 'Verified buyer') === false, 'the module\'s own "not verified" wins over WooCommerce\'s email-based `verified`');
check(Product_Reviews_Core::is_verified_purchase(302) && !Product_Reviews_Core::is_verified_purchase(303) && !Product_Reviews_Core::is_verified_purchase(305),
    'is_verified_purchase(): WooCommerce `verified` only when the module has no verdict');

check(isset($GLOBALS['styles']['enqueued']['ffla-product-reviews'], $GLOBALS['scripts']['enqueued']['ffla-product-reviews']), 'reviews list enqueues CSS and the vote script');
render_list(30, ['perPage' => 1]);
check($GLOBALS['scripts']['localized'] === 1, 'script data localized once per page');
reset_assets();
settings(['enable_helpful_votes' => '0']);
render_list(30, ['perPage' => 1]);
check(isset($GLOBALS['styles']['enqueued']['ffla-product-reviews']) && empty($GLOBALS['scripts']['enqueued']), 'votes off: list loads CSS only');
settings();
reset_assets();
reset_page();

/* ── Counts come from WooCommerce's stored aggregates ──────────────────── */

// 6 reviews, 5 of them rated (one left without stars), as WooCommerce stores them.
$GLOBALS['post_types'][40] = 'product';
$GLOBALS['products'][40]   = new WC_Product(40, ['5' => 3, '4' => 1, '2' => 1], '4.20', 6);
$wpdb_before      = $GLOBALS['wpdb']->calls;
$transients_before = $GLOBALS['transient_reads'];

$dist = Product_Reviews_Core::get_rating_distribution(40);
check($dist['counts'] === [5 => 3, 4 => 1, 3 => 0, 2 => 1, 1 => 0], 'per-star counts from get_rating_counts()');
check($dist['total'] === 5 && $dist['average'] === 4.2 && $dist['review_count'] === 6, 'ratings 5, average 4.2, review count 6 (' . json_encode($dist) . ')');

ob_start();
Product_Reviews_Frontend_Render::render_rating_summary(40);
$summary = ob_get_clean();
check(strpos($summary, 'ffla-rating-summary__average">4.2<') !== false, 'summary average matches WooCommerce');
check(strpos($summary, '6 reviews') !== false, 'summary count matches the Reviews (6) tab');
check(strpos($summary, 'width:60.00%') !== false, 'bars divide by the 5 ratings (3 of 5 = 60%)');

$badge = new \Bricks\FFLA_Reviews_Rating_Badge(['productId' => 40]);
ob_start();
$badge->render();
$badge_html = ob_get_clean();
check(strpos($badge_html, 'ffla-reviews-badge__avg">4.2<') !== false && strpos($badge_html, '(6)') !== false, 'badge shows 4.2 and (6)');

$post = new WP_Post(40);
check(Product_Reviews_Dynamic_Tags::render_content('{ffla_review_count}', $post) === '6', '{ffla_review_count} = WooCommerce review count');
check(Product_Reviews_Dynamic_Tags::render_content('{ffla_review_average}', $post) === '4.2', '{ffla_review_average} = WooCommerce average');
check(Product_Reviews_Dynamic_Tags::render_content('{ffla_review_recommend_percent}', $post) === '80', '{ffla_review_recommend_percent} = 4 of 5 ratings');

check($GLOBALS['wpdb']->calls === $wpdb_before, 'no database query of its own for the numbers');
check($GLOBALS['transient_reads'] === $transients_before && empty($GLOBALS['transients']), 'no per-product transient');

$GLOBALS['products'][41] = new WC_Product(41, [], 0, 0);
$GLOBALS['post_types'][41] = 'product';
$badge = new \Bricks\FFLA_Reviews_Rating_Badge(['productId' => 41, 'hideIfNoReviews' => true]);
ob_start();
$badge->render();
check(ob_get_clean() === '', 'badge hidden on a product without reviews');

/* ── A rating written outside WooCommerce's own flows is recounted ─────── */

add_review(401, 40, ['comment_date_gmt' => '2026-03-01 00:00:00'], ['rating' => 4]);
add_review(402, 40, ['comment_approved' => '0'], ['rating' => 1]);
Product_Reviews_Core::flush_caches_for_rating_meta(1, 401, 'rating');
check($GLOBALS['wc_recounted'] === [40], 'WooCommerce recounts after a direct rating write');
$GLOBALS['doing'] = ['comment_post'];
Product_Reviews_Core::flush_caches_for_rating_meta(1, 401, 'rating');
$GLOBALS['doing'] = ['wp_update_comment_data'];
Product_Reviews_Core::flush_caches_for_rating_meta(1, 401, 'rating');
$GLOBALS['doing'] = [];
Product_Reviews_Core::flush_caches_for_rating_meta(1, 402, 'rating');
Product_Reviews_Core::flush_caches_for_rating_meta(1, 401, 'ffla_helpful_yes');
check($GLOBALS['wc_recounted'] === [40], 'no second recount inside WooCommerce\'s own flows, for pending reviews or other meta');

/* ── Structured data = the reviews the FFL list shows ──────────────────── */

$GLOBALS['post_types'][10] = 'product';
$GLOBALS['products'][10]   = new WC_Product(10, ['5' => 2, '4' => 1, '3' => 1], '4.25', 5);
add_review(101, 10, ['comment_author' => 'Pinned Pat', 'comment_karma' => 1, 'comment_date_gmt' => '2026-01-01 09:00:00'], ['rating' => 5]);
add_review(102, 10, ['comment_author' => 'Rated Rae', 'comment_date_gmt' => '2026-03-01 09:00:00'], ['rating' => 4]);
add_review(103, 10, ['comment_author' => 'Middle Max', 'comment_date_gmt' => '2026-04-01 09:00:00'], ['rating' => 3]);
add_review(104, 10, ['comment_author' => 'Legacy Lee', 'comment_type' => 'comment', 'comment_date_gmt' => '2026-05-01 09:00:00'], ['rating' => 5]);
add_review(105, 10, ['comment_author' => 'Unrated Uma', 'comment_date_gmt' => '2026-05-02 09:00:00'], []);
add_review(106, 10, ['comment_author' => 'Pending Pia', 'comment_approved' => '0', 'comment_date_gmt' => '2026-05-03 09:00:00'], ['rating' => 1]);

function woo_review($name) { return ['@type' => 'Review', 'author' => ['@type' => 'Person', 'name' => $name]]; }
$product = $GLOBALS['products'][10];
// What WooCommerce builds: the 5 newest rated approved comments, any type.
$woo_markup = [
    '@type'           => 'Product',
    '@id'             => 'https://shop.test/product/p10/#product',
    'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '4.25', 'reviewCount' => 5],
    'review'          => [woo_review('Legacy Lee'), woo_review('Middle Max'), woo_review('Rated Rae'), woo_review('Pinned Pat')],
];

$GLOBALS['is_product'] = true;
$GLOBALS['queried']    = 10;

// WooCommerce's own Reviews tab in charge: its markup is left alone.
reset_page();
check(Product_Reviews_Structured_Data::filter_woocommerce_product($woo_markup, $product) === $woo_markup, 'WooCommerce tab in use: WooCommerce markup untouched');

// Replaced Reviews tab: markup built before the list renders, from the tab's settings.
settings(['replace_default_reviews_tab' => '1']);
reset_page();
$GLOBALS['filters']['ffla_product_reviews_wc_tab_list_settings'] = function ($s) { return ['perPage' => 3, 'orderBy' => 'recent']; };
$filtered = Product_Reviews_Structured_Data::filter_woocommerce_product($woo_markup, $product);
check(authors($filtered) === ['Pinned Pat', 'Middle Max'], 'tab list (pinned, unrated Uma, Max): rated ones marked up in order (' . implode(', ', authors($filtered)) . ')');
check($filtered['aggregateRating'] === $woo_markup['aggregateRating'], 'aggregateRating left as WooCommerce built it');
check($filtered['review'][0]['reviewRating']['ratingValue'] === '5' && $filtered['review'][0]['reviewBody'] === 'Body of review 101'
    && $filtered['review'][0]['datePublished'] === '2026-01-01T09:00:00+00:00', 'review node has rating, body and date');

$tab_html = render_list(10, Product_Reviews_Core::wc_tab_list_settings(10));
$visible_rated = array_values(array_filter(array_keys(cards($tab_html)), function ($author) {
    return $author !== 'Unrated Uma';
}));
check(array_keys(cards($tab_html)) === ['Pinned Pat', 'Unrated Uma', 'Middle Max'], 'the tab shows pinned first, review type only, approved only');
check(authors($filtered) === $visible_rated, 'marked-up reviews equal the rated reviews the list shows');

// Rank Math (prints in <head>) gets the same list.
$rank_math = [
    'WebPage'        => ['@type' => 'WebPage'],
    'ProductSnippet' => ['@type' => 'Product', 'review' => $woo_markup['review']],
];
$rm = Product_Reviews_Structured_Data::filter_rank_math($rank_math);
check(authors($rm['ProductSnippet']) === ['Pinned Pat', 'Middle Max'] && $rm['WebPage'] === ['@type' => 'WebPage'], 'Rank Math product review list replaced, other entities untouched');

// A list rendered with other settings (e.g. inside a nested Bricks template):
// the footer sync puts exactly those reviews into WooCommerce's markup.
settings();
reset_page();
$crumbs = ['@type' => 'BreadcrumbList', 'itemListElement' => []];
$sd = WC()->structured_data;
$sd->set_data($crumbs, true);
$sd->set_data(Product_Reviews_Structured_Data::filter_woocommerce_product($woo_markup, $product));
check(authors($sd->get_data()[1]) === authors($woo_markup), 'no FFL list known yet: WooCommerce markup kept at build time');
$nested_html = render_list(10, ['perPage' => 4, 'orderBy' => 'recent']);
Product_Reviews_Structured_Data::sync_woocommerce_markup();
$nodes = $sd->get_data();
check(count($nodes) === 2 && $nodes[0] === $crumbs, 'other WooCommerce nodes kept in order');
check(authors($nodes[1]) === ['Pinned Pat', 'Middle Max', 'Rated Rae'], 'footer sync marks up what the list rendered (' . implode(', ', authors($nodes[1])) . ')');
check(!in_array('Legacy Lee', authors($nodes[1]), true), 'comment-type review the FFL list never shows is not marked up');

// Bricks single-product template with a Reviews List element.
reset_page();
$GLOBALS['bricks_data'] = [
    ['name' => 'heading', 'settings' => []],
    ['name' => 'ffla-reviews-list', 'settings' => ['productId' => '30', 'perPage' => 9]],
    ['name' => 'ffla-reviews-list', 'settings' => ['perPage' => 2, 'orderBy' => 'recent']],
];
$filtered = Product_Reviews_Structured_Data::filter_woocommerce_product($woo_markup, $product);
check(authors($filtered) === ['Pinned Pat'], 'Bricks list settings used (another product\'s list skipped; 2 shown, 1 rated)');

// Nothing rated in the visible list: no review markup at all.
reset_page();
$GLOBALS['post_types'][20] = 'product';
$GLOBALS['products'][20]   = new WC_Product(20, [], 0, 1);
add_review(201, 20, ['comment_author' => 'Only Unrated'], []);
$GLOBALS['queried'] = 20;
$GLOBALS['bricks_data'] = [['name' => 'ffla-reviews-list', 'settings' => []]];
$p20 = Product_Reviews_Structured_Data::filter_woocommerce_product(['@type' => 'Product', 'review' => [woo_review('Old')]], $GLOBALS['products'][20]);
check(!array_key_exists('review', $p20), 'no rated review in the list: review markup removed');

// Not the product page itself (e.g. a [product_page] shortcode elsewhere).
reset_page();
$GLOBALS['is_product'] = false;
$GLOBALS['bricks_data'] = [['name' => 'ffla-reviews-list', 'settings' => []]];
check(Product_Reviews_Structured_Data::filter_woocommerce_product($woo_markup, $product) === $woo_markup, 'not on the product page: markup untouched');
reset_page();

/* ── Pinning purges page caches ────────────────────────────────────────── */

check(Product_Reviews_Moderation::set_pinned(102, true) && $GLOBALS['comments'][102]->comment_karma === 1, 'review pinned');
check($GLOBALS['comment_count_refreshed'] === [10], 'pin refreshes the product comment count (clean_post_cache, wp_update_comment_count, edit_post)');

/* ── Helpful votes ─────────────────────────────────────────────────────── */

function vote(array $post, array $server = [])
{
    $_POST   = $post;
    $_SERVER = array_merge(['REMOTE_ADDR' => '198.51.100.7'], $server);
    try {
        Product_Reviews_Ajax::vote_review_helpful();
    } catch (JsonSent $e) {
        return $e;
    }
    throw new RuntimeException('no response');
}

$GLOBALS['nonce_ok'] = false;
$r = vote(['comment_id' => 101, 'vote' => 'yes', 'nonce' => 'stale']);
check(!$r->payload['success'] && $r->status === 403, 'stale nonce refused with 403');
check($r->payload['data']['code'] === 'invalid_nonce' && $r->payload['data']['nonce'] === 'fresh-nonce' && $r->payload['data']['message'] !== '', 'refusal carries a fresh nonce and a message for the script');
check(!isset($GLOBALS['cmeta'][101]['ffla_helpful_yes']), 'no vote counted on a stale nonce');
$GLOBALS['nonce_ok'] = true;

// Cloudflare: the visitor address, only when the request comes from an edge.
$_SERVER = ['REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5'];
check(Product_Reviews_Ajax::client_ip() === '203.0.113.5', 'behind a Cloudflare edge: CF-Connecting-IP');
$_SERVER = ['REMOTE_ADDR' => '2606:4700:10::6816:1', 'HTTP_CF_CONNECTING_IP' => '2001:db8::5'];
check(Product_Reviews_Ajax::client_ip() === '2001:db8::5', 'IPv6 Cloudflare edge: CF-Connecting-IP');
$_SERVER = ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'];
check(Product_Reviews_Ajax::client_ip() === '198.51.100.7', 'direct request: a forged CF-Connecting-IP is ignored');
$_SERVER = ['REMOTE_ADDR' => '173.245.48.10', 'HTTP_CF_CONNECTING_IP' => 'not-an-ip'];
check(Product_Reviews_Ajax::client_ip() === '173.245.48.10', 'invalid CF-Connecting-IP ignored');
check(Product_Reviews_Ajax::is_cloudflare_edge('104.27.255.255') && !Product_Reviews_Ajax::is_cloudflare_edge('104.28.0.0'), 'IPv4 range edges (104.24.0.0/14)');
check(Product_Reviews_Ajax::is_cloudflare_edge('2a06:98c7:ffff::1') && !Product_Reviews_Ajax::is_cloudflare_edge('2a06:98c8::1'), 'IPv6 range edges (2a06:98c0::/29)');

$edge = ['REMOTE_ADDR' => '162.158.1.1'];
$r = vote(['comment_id' => 101, 'vote' => 'yes', 'nonce' => 'n'], $edge + ['HTTP_CF_CONNECTING_IP' => '203.0.113.10']);
check($r->payload['success'] && !$r->payload['data']['throttled'] && $r->payload['data']['count'] === 1, 'first shopper behind the edge votes');
$r = vote(['comment_id' => 101, 'vote' => 'yes', 'nonce' => 'n'], $edge + ['HTTP_CF_CONNECTING_IP' => '203.0.113.11']);
check($r->payload['success'] && !$r->payload['data']['throttled'] && $r->payload['data']['count'] === 2, 'second shopper on the same edge is not blocked');
$r = vote(['comment_id' => 101, 'vote' => 'yes', 'nonce' => 'n'], $edge + ['HTTP_CF_CONNECTING_IP' => '203.0.113.10']);
check($r->payload['data']['throttled'] && $r->payload['data']['count'] === 2, 'same shopper again is throttled');

// Daily cap: keyed by date, so it starts over the next day.
$GLOBALS['transients'] = [];
$GLOBALS['filters']['ffla_helpful_daily_cap'] = function () { return 1; };
$r = vote(['comment_id' => 102, 'vote' => 'yes', 'nonce' => 'n'], ['REMOTE_ADDR' => '198.51.100.20']);
check(!$r->payload['data']['throttled'], 'first vote of the day');
check(isset($GLOBALS['transients']['ffla_helpful_daily_102_20261021']), 'daily counter key carries the date');
$r = vote(['comment_id' => 102, 'vote' => 'yes', 'nonce' => 'n'], ['REMOTE_ADDR' => '198.51.100.21']);
check($r->payload['data']['throttled'] && $r->payload['data']['message'] === 'Daily vote limit reached for this review.', 'cap reached for the day');
$GLOBALS['today'] = '20261022';
$r = vote(['comment_id' => 102, 'vote' => 'yes', 'nonce' => 'n'], ['REMOTE_ADDR' => '198.51.100.22']);
check(!$r->payload['data']['throttled'] && $r->payload['data']['count'] === 2, 'cap starts over the next day');

echo "OK: $checks product-reviews display checks passed\n";
