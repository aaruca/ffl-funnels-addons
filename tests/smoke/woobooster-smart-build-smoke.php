<?php
/**
 * WooBooster Smart builds and rule lookup index — offline regression checks
 * (no WordPress, MySQL or network).
 *
 * The real Bought Together and Trending builds run against SQLite (orders in
 * HPOS and in legacy posts, wc_order_product_lookup with unsynced orders read
 * from order item meta, virtual products, bulk orders, a category tree) and
 * must produce exactly what the previous algorithms (frozen copies below)
 * produce. Also covers: the in-memory cache released between batches, no
 * all-meta priming when lists are written, the build lock (held, abandoned,
 * someone else's token), the memory guard stopping with a recorded reason and
 * the previous index kept, Rebuild Now (run_builds), the debounced rule index
 * rebuild on category create/edit/delete plus the daily safety net, and the
 * cached group_id schema check.
 *
 * Run: php tests/smoke/woobooster-smart-build-smoke.php
 * (re-runs itself once without the lookup table, for the order item meta SQL).
 */

define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
define('OBJECT', 'OBJECT');
define('DB_NAME', 'main');

ini_set('memory_limit', '512M');
set_time_limit(300); // Like a web request: PHP has a time limit the build must raise.

$NO_LOOKUP = '1' === getenv('WB_NO_LOOKUP');

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

/* ── WordPress stand-ins ─────────────────────────────────────────────── */

$GLOBALS['wp_filters'] = [];
$GLOBALS['options'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['cron'] = [];
$GLOBALS['cache_flushes'] = 0;
$GLOBALS['primed'] = [];
$GLOBALS['meta_calls'] = [];
$GLOBALS['time_limits'] = [];
$GLOBALS['memory_raises'] = [];
$GLOBALS['primed_terms'] = [];

class WP_Error {}

function __($text, $domain = '') { return $text; }
function absint($v) { return abs((int) $v); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function wp_json_encode($data) { return json_encode($data); }
function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function number_format_i18n($n) { return number_format((float) $n); }
function size_format($bytes) { return round($bytes / 1048576) . ' MB'; }
function wp_convert_hr_to_bytes($value)
{
    $value = strtolower(trim((string) $value));
    $bytes = (int) $value;
    if (false !== strpos($value, 'g')) {
        $bytes *= 1073741824;
    } elseif (false !== strpos($value, 'm')) {
        $bytes *= 1048576;
    } elseif (false !== strpos($value, 'k')) {
        $bytes *= 1024;
    }
    return $bytes;
}
function wp_raise_memory_limit($context) { $GLOBALS['memory_raises'][] = $context; return false; }
function wc_set_time_limit($limit) { $GLOBALS['time_limits'][] = $limit; }
function wp_cache_supports($feature) { return 'flush_runtime' === $feature; }
function wp_cache_flush_runtime() { $GLOBALS['cache_flushes']++; return true; }
function wc_get_order_statuses()
{
    return ['wc-pending' => 'Pending', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-cancelled' => 'Cancelled', 'wc-refunded' => 'Refunded', 'wc-failed' => 'Failed'];
}

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['wp_filters'][$hook][] = [$callback, $accepted_args]; return true; }
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return add_filter($hook, $callback, $priority, $accepted_args); }
function remove_all_filters($hook) { unset($GLOBALS['wp_filters'][$hook]); }
function has_filter($hook) { return !empty($GLOBALS['wp_filters'][$hook]); }
function apply_filters($hook, $value, ...$args)
{
    foreach ($GLOBALS['wp_filters'][$hook] ?? [] as [$callback, $accepted]) {
        $value = $callback(...array_slice(array_merge([$value], $args), 0, max(1, $accepted)));
    }
    return $value;
}
function do_action($hook, ...$args)
{
    foreach ($GLOBALS['wp_filters'][$hook] ?? [] as [$callback, $accepted]) {
        call_user_func_array($callback, array_slice($args, 0, $accepted));
    }
}

function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function add_option($key, $value = '', $deprecated = '', $autoload = null) { if (array_key_exists($key, $GLOBALS['options'])) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; return true; }

function is_serialized($data) { return is_string($data) && (bool) preg_match('/^(a|s|i|d|b|O):|^N;$/', $data); }
function maybe_serialize($data) { return (is_array($data) || is_object($data)) ? serialize($data) : $data; }
function maybe_unserialize($data) { return is_serialized($data) ? @unserialize(trim($data)) : $data; }

/* Cron: a plain list of events, like the `cron` option. */
function wp_next_scheduled($hook, $args = [])
{
    $next = false;
    foreach ($GLOBALS['cron'] as $event) {
        if ($event['hook'] === $hook && (false === $next || $event['ts'] < $next)) {
            $next = $event['ts'];
        }
    }
    return $next;
}
function wp_schedule_single_event($ts, $hook, $args = [], $wp_error = false) { $GLOBALS['cron'][] = ['ts' => $ts, 'hook' => $hook, 'schedule' => false]; return true; }
function wp_schedule_event($ts, $recurrence, $hook, $args = [], $wp_error = false) { $GLOBALS['cron'][] = ['ts' => $ts, 'hook' => $hook, 'schedule' => $recurrence]; return true; }
function wp_clear_scheduled_hook($hook, $args = []) { $GLOBALS['cron'] = array_values(array_filter($GLOBALS['cron'], static function ($e) use ($hook) { return $e['hook'] !== $hook; })); return 0; }
function events_for($hook) { return array_values(array_filter($GLOBALS['cron'], static function ($e) use ($hook) { return $e['hook'] === $hook; })); }
/** What wp-cron.php does: unschedule a due single event, then fire its hook. */
function run_cron_event($hook)
{
    $events = events_for($hook);
    check(1 === count($events), "exactly one $hook event is queued before it runs");
    wp_clear_scheduled_hook($hook);
    do_action($hook);
}

/* Terms: an in-memory store mirrored into the SQLite term tables. */
$GLOBALS['terms'] = [];
function add_test_term(int $id, string $taxonomy, string $slug, int $parent = 0)
{
    $GLOBALS['terms'][$taxonomy][$id] = (object) ['term_id' => $id, 'taxonomy' => $taxonomy, 'slug' => $slug, 'name' => ucfirst($slug), 'parent' => $parent];
    $GLOBALS['wpdb']->db->exec("INSERT OR REPLACE INTO wp_term_taxonomy (term_taxonomy_id, term_id, taxonomy, parent) VALUES ($id, $id, '$taxonomy', $parent)");
}
function taxonomy_exists($taxonomy) { return in_array($taxonomy, ['product_cat', 'product_tag', 'category', 'pa_caliber'], true); }
function is_taxonomy_hierarchical($taxonomy) { return in_array($taxonomy, ['product_cat', 'category'], true); }
function is_object_in_taxonomy($object_type, $taxonomy) { return 'product' === $object_type && in_array($taxonomy, ['product_cat', 'product_tag', 'pa_caliber'], true); }
function get_term($id, $taxonomy = '')
{
    $term = $GLOBALS['terms'][$taxonomy][(int) $id] ?? null;
    return $term ? clone $term : null;
}
function get_term_by($field, $value, $taxonomy)
{
    foreach ($GLOBALS['terms'][$taxonomy] ?? [] as $term) {
        if ('slug' === $field && $term->slug === $value) {
            return clone $term;
        }
    }
    return false;
}
function get_term_children($term_id, $taxonomy)
{
    $children = [];
    foreach ($GLOBALS['terms'][$taxonomy] ?? [] as $term) {
        if ((int) $term->parent === (int) $term_id) {
            $children[] = (int) $term->term_id;
            $children = array_merge($children, get_term_children($term->term_id, $taxonomy));
        }
    }
    return $children;
}
function get_ancestors($id, $object_type = '', $resource_type = '')
{
    $ancestors = [];
    $term = $GLOBALS['terms'][$object_type][(int) $id] ?? null;
    while ($term && $term->parent && !in_array((int) $term->parent, $ancestors, true)) {
        $ancestors[] = (int) $term->parent;
        $term = $GLOBALS['terms'][$object_type][(int) $term->parent] ?? null;
    }
    return $ancestors;
}
function _prime_term_caches($ids, $update_meta_cache = true) { $GLOBALS['primed_terms'][] = $ids; }

/* Post meta over the SQLite postmeta table. get_post_meta() and an
   update_post_meta() without a previous value load ALL of a post's meta in
   WordPress; both are recorded as "primed". */
function get_post_meta($post_id, $key = '', $single = false)
{
    $GLOBALS['primed'][] = (int) $post_id;
    $value = $GLOBALS['wpdb']->db->querySingle("SELECT meta_value FROM wp_postmeta WHERE post_id = " . (int) $post_id . " AND meta_key = '" . SQLite3::escapeString($key) . "' ORDER BY meta_id LIMIT 1");
    return null === $value ? '' : maybe_unserialize($value);
}
function add_post_meta($post_id, $key, $value, $unique = false)
{
    $GLOBALS['meta_calls'][] = ['add', (int) $post_id];
    $db = $GLOBALS['wpdb']->db;
    $k = SQLite3::escapeString($key);
    if ($unique && $db->querySingle("SELECT COUNT(*) FROM wp_postmeta WHERE post_id = " . (int) $post_id . " AND meta_key = '$k'")) {
        return false;
    }
    $db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (" . (int) $post_id . ", '$k', '" . SQLite3::escapeString((string) maybe_serialize($value)) . "')");
    return $db->lastInsertRowID();
}
function update_post_meta($post_id, $key, $value, $prev_value = '')
{
    $GLOBALS['meta_calls'][] = ['update', (int) $post_id, !empty($prev_value)];
    if (empty($prev_value)) {
        $GLOBALS['primed'][] = (int) $post_id;
    }
    $db = $GLOBALS['wpdb']->db;
    $k = SQLite3::escapeString($key);
    if (!$db->querySingle("SELECT COUNT(*) FROM wp_postmeta WHERE post_id = " . (int) $post_id . " AND meta_key = '$k'")) {
        return (bool) add_post_meta($post_id, $key, $value);
    }
    $sql = "UPDATE wp_postmeta SET meta_value = '" . SQLite3::escapeString((string) maybe_serialize($value)) . "' WHERE post_id = " . (int) $post_id . " AND meta_key = '$k'";
    if (!empty($prev_value)) {
        $sql .= " AND meta_value = '" . SQLite3::escapeString((string) maybe_serialize($prev_value)) . "'";
    }
    $db->exec($sql);
    return $db->changes() > 0;
}
function delete_post_meta($post_id, $key, $value = '')
{
    $GLOBALS['meta_calls'][] = ['delete', (int) $post_id];
    $db = $GLOBALS['wpdb']->db;
    $db->exec("DELETE FROM wp_postmeta WHERE post_id = " . (int) $post_id . " AND meta_key = '" . SQLite3::escapeString($key) . "'");
    return $db->changes() > 0;
}

// Order storage switch (HPOS on/off).
eval('namespace Automattic\\WooCommerce\\Utilities; class OrderUtil { public static $hpos = true; public static function custom_orders_table_usage_is_enabled() { return self::$hpos; } }');

class WooBooster_Matcher
{
    public static $invalidations = 0;
    public static function invalidate_recommendation_cache(): void { self::$invalidations++; }
}

class WooBooster_Bundle
{
    public static $rebuilds = 0;
    public static function rebuild_full_bundle_index() { self::$rebuilds++; }
}

/**
 * $wpdb over SQLite. prepare() supports %d, %s, %i and fails on a
 * placeholder/argument count mismatch. MySQL-only syntax used by the code
 * (INSERT IGNORE, SHOW TABLES, INFORMATION_SCHEMA, DATEDIFF, GREATEST) is
 * translated or registered; SUM() is the naive left-to-right sum MySQL uses
 * for doubles (SQLite's own SUM compensates rounding errors).
 */
class Sqlite_Wpdb
{
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $options = 'wp_options';
    public $terms = 'wp_terms';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $term_relationships = 'wp_term_relationships';
    public $insert_id = 0;
    public $last_error = '';
    public $queries = [];
    /** @var SQLite3 */
    public $db;

    public function __construct()
    {
        $this->db = new SQLite3(':memory:');
        $this->db->createFunction('DATEDIFF', static function ($a, $b) {
            if (null === $a || null === $b) {
                return null;
            }
            return (int) round((strtotime(substr((string) $a, 0, 10) . ' 00:00:00 UTC') - strtotime(substr((string) $b, 0, 10) . ' 00:00:00 UTC')) / 86400);
        }, 2);
        $this->db->createFunction('GREATEST', static function ($a, $b) {
            return (null === $a || null === $b) ? null : max((float) $a, (float) $b);
        }, 2);
        $this->db->createFunction('POW', static function ($a, $b) {
            return pow((float) $a, (float) $b);
        }, 2);
        $this->db->createAggregate('SUM', static function ($context, $row, $value) {
            if (null === $value) {
                return $context;
            }
            return (null === $context ? 0.0 : $context) + $value;
        }, static function ($context, $rows) {
            return $context;
        }, 1);
    }

    public function prepare($query, ...$args)
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        $sql = preg_replace_callback('/%(%|d|s|i|f)/', static function ($m) use (&$i, $args) {
            if ('%' === $m[1]) {
                return '%';
            }
            if (!array_key_exists($i, $args)) {
                throw new LogicException('prepare(): fewer arguments than placeholders');
            }
            $v = $args[$i++];
            if ('d' === $m[1]) {
                return (string) (int) $v;
            }
            if ('f' === $m[1]) {
                return sprintf('%F', (float) $v);
            }
            if ('i' === $m[1]) {
                return '`' . str_replace('`', '``', (string) $v) . '`';
            }
            return "'" . SQLite3::escapeString((string) $v) . "'";
        }, $query);
        if ($i !== count($args)) {
            throw new LogicException('prepare(): more arguments than placeholders');
        }
        return $sql;
    }

    private function translate(string $sql): string
    {
        $sql = preg_replace('/^\s*INSERT IGNORE INTO/i', 'INSERT OR IGNORE INTO', $sql);
        if (preg_match("/^\s*SHOW TABLES LIKE '(.*)'\s*$/is", $sql, $m)) {
            return "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE '{$m[1]}'";
        }
        if (preg_match("/FROM INFORMATION_SCHEMA\.COLUMNS WHERE TABLE_SCHEMA = '[^']*' AND TABLE_NAME = '([^']+)' AND COLUMN_NAME = '([^']+)'/i", $sql, $m)) {
            return "SELECT name AS COLUMN_NAME FROM pragma_table_info('{$m[1]}') WHERE name = '{$m[2]}'";
        }
        return $sql;
    }

    private function run(string $sql)
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        $result = @$this->db->query($this->translate($sql));
        if (false === $result) {
            $this->last_error = $this->db->lastErrorMsg();
            throw new RuntimeException('SQL error: ' . $this->last_error . "\n" . $sql);
        }
        return $result;
    }

    private function rows(string $sql): array
    {
        $result = $this->run($sql);
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function query($sql)
    {
        if (preg_match('/^\s*(SELECT|SHOW)/i', $sql)) {
            return count($this->rows($sql));
        }
        $this->run($sql);
        return $this->db->changes();
    }

    public function get_results($sql, $output = OBJECT)
    {
        $rows = $this->rows($sql);
        if (ARRAY_A === $output) {
            return $rows;
        }
        if (ARRAY_N === $output) {
            return array_map('array_values', $rows);
        }
        return array_map(static function ($row) { return (object) $row; }, $rows);
    }

    public function get_col($sql)
    {
        return array_map(static function ($row) { return reset($row); }, $this->rows($sql));
    }

    public function get_var($sql)
    {
        $rows = $this->rows($sql);
        return $rows ? reset($rows[0]) : null;
    }

    public function get_row($sql, $output = OBJECT)
    {
        $rows = $this->get_results($sql, $output);
        return $rows ? $rows[0] : null;
    }

    public function insert($table, $data, $format = null)
    {
        $cols = implode(', ', array_keys($data));
        $vals = implode(', ', array_map(static function ($v) { return null === $v ? 'NULL' : "'" . SQLite3::escapeString((string) $v) . "'"; }, array_values($data)));
        $this->run("INSERT INTO $table ($cols) VALUES ($vals)");
        $this->insert_id = $this->db->lastInsertRowID();
        return 1;
    }

    public function delete($table, $where, $format = null)
    {
        $conds = [];
        foreach ($where as $col => $v) {
            $conds[] = "$col = '" . SQLite3::escapeString((string) $v) . "'";
        }
        $this->run("DELETE FROM $table WHERE " . implode(' AND ', $conds));
        return $this->db->changes();
    }

    public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }
    public function flush() {}

    public function count_queries(string $needle): int
    {
        return count(array_filter($this->queries, static function ($q) use ($needle) { return false !== stripos($q, $needle); }));
    }
}

$wpdb = new Sqlite_Wpdb();
$GLOBALS['wpdb'] = $wpdb;

// SUM() is the naive one: (1e16 + 1) - 1e16 rounds to 0 left to right.
check(0.0 === (float) $wpdb->db->querySingle('SELECT SUM(v) FROM (SELECT 1e16 AS v UNION ALL SELECT 1.0 UNION ALL SELECT -1e16)'), 'SQLite SUM() replaced by naive left-to-right summation');

$wpdb->db->exec('CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)');
$wpdb->db->exec('CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_type TEXT, post_status TEXT, post_date_gmt TEXT)');
$wpdb->db->exec('CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)');
$wpdb->db->exec('CREATE TABLE wp_wc_orders (id INTEGER PRIMARY KEY, type TEXT, status TEXT, date_created_gmt TEXT)');
$wpdb->db->exec('CREATE TABLE wp_woocommerce_order_items (order_item_id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, order_item_type TEXT)');
$wpdb->db->exec('CREATE TABLE wp_woocommerce_order_itemmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, order_item_id INTEGER, meta_key TEXT, meta_value TEXT)');
if (!$NO_LOOKUP) {
    $wpdb->db->exec('CREATE TABLE wp_wc_order_product_lookup (order_item_id INTEGER PRIMARY KEY, order_id INTEGER, product_id INTEGER, variation_id INTEGER)');
}
$wpdb->db->exec('CREATE TABLE wp_terms (term_id INTEGER PRIMARY KEY, name TEXT, slug TEXT)');
$wpdb->db->exec('CREATE TABLE wp_term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT, parent INTEGER)');
$wpdb->db->exec('CREATE TABLE wp_term_relationships (object_id INTEGER, term_taxonomy_id INTEGER)');

$root = dirname(__DIR__, 2) . '/modules/woobooster/includes/';
require $root . 'class-woobooster-cron.php';
require $root . 'class-woobooster-copurchase.php';
require $root . 'class-woobooster-trending.php';
require $root . 'class-woobooster-rule.php';

/* ── Frozen copies of the previous algorithms (the reference) ────────── */

/**
 * Bought Together as built before: nested a => [b => …] arrays, both
 * directions, then the old rank_pairs() verbatim.
 *
 * @param array<int, int[]> $orders order_id => distinct product IDs, ID order.
 */
function reference_copurchase(array $orders, array $excluded, int $bulk_cap, int $max_relations): array
{
    $orders_with = [];
    $pair_weight = [];
    $pair_count = [];
    $orders_scanned = 0;
    $multi_item_orders = 0;
    $single_item_orders = 0;
    $bulk_orders = 0;
    foreach ($orders as $order_id => $product_ids) {
        $orders_scanned++;
        if ($excluded) {
            $product_ids = array_values(array_diff($product_ids, $excluded));
        }
        foreach ($product_ids as $pid) {
            $orders_with[$pid] = isset($orders_with[$pid]) ? $orders_with[$pid] + 1 : 1;
        }
        $count = count($product_ids);
        if ($count < 2) {
            $single_item_orders++;
            continue;
        }
        if ($count > $bulk_cap) {
            $bulk_orders++;
            continue;
        }
        $multi_item_orders++;
        $weight = 1 / ($count - 1);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $a = $product_ids[$i];
                $b = $product_ids[$j];
                $pair_weight[$a][$b] = (isset($pair_weight[$a][$b]) ? $pair_weight[$a][$b] : 0) + $weight;
                $pair_weight[$b][$a] = (isset($pair_weight[$b][$a]) ? $pair_weight[$b][$a] : 0) + $weight;
                $pair_count[$a][$b] = (isset($pair_count[$a][$b]) ? $pair_count[$a][$b] : 0) + 1;
                $pair_count[$b][$a] = (isset($pair_count[$b][$a]) ? $pair_count[$b][$a] : 0) + 1;
            }
        }
    }
    $min_support = $multi_item_orders >= 200 ? 2 : 1;
    $index = reference_rank_pairs($pair_weight, $pair_count, $orders_with, $orders_scanned, $min_support, $max_relations);
    ksort($index); // Stored lists are compared by product ID.

    return [
        'index' => $index,
        'orders_scanned' => $orders_scanned,
        'multi_item_orders' => $multi_item_orders,
        'single_item_orders' => $single_item_orders,
        'bulk_orders' => $bulk_orders,
        'min_support' => $min_support,
    ];
}

function reference_rank_pairs(array $pair_weight, array $pair_count, array $orders_with, int $total_orders, int $min_support, int $max_relations): array
{
    $index = array();
    if ($total_orders < 1) {
        return $index;
    }
    foreach ($pair_weight as $a => $related) {
        $orders_a = isset($orders_with[$a]) ? max(1, $orders_with[$a]) : 1;
        $scores = array();
        foreach ($related as $b => $weight) {
            if ((isset($pair_count[$a][$b]) ? $pair_count[$a][$b] : 0) < $min_support) {
                continue;
            }
            $orders_b = isset($orders_with[$b]) ? max(1, $orders_with[$b]) : 1;
            $confidence = $weight / $orders_a;
            $lift = $confidence / ($orders_b / $total_orders);
            if ($lift <= 1) {
                continue;
            }
            $scores[$b] = $confidence * log($lift, 2);
        }
        if (empty($scores)) {
            continue;
        }
        uksort($scores, static function ($x, $y) use ($scores) {
            if ($scores[$x] === $scores[$y]) {
                return $x <=> $y;
            }
            return $scores[$y] <=> $scores[$x];
        });
        $index[(int) $a] = array_map('intval', array_slice(array_keys($scores), 0, $max_relations));
    }
    return $index;
}

function reference_top_ids(array $scores, int $limit): array
{
    uksort($scores, static function ($a, $b) use ($scores) {
        if ($scores[$a] === $scores[$b]) {
            return $a <=> $b;
        }
        return $scores[$b] <=> $scores[$a];
    });
    return array_map('intval', array_slice(array_keys($scores), 0, $limit));
}

/**
 * Trending as built before: the old per product/day query, score_rows() in
 * PHP, then the old category roll-up.
 */
function reference_trending(Sqlite_Wpdb $wpdb, bool $use_hpos, int $days, float $half_life, int $now, bool $lookup): array
{
    $statuses = WooBooster_Trending::get_order_statuses();
    $statuses = $use_hpos ? WooBooster_Trending::expand_statuses_for_hpos($statuses) : $statuses;
    $date_cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));
    $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
    if ($use_hpos) {
        $order_join = "JOIN wp_wc_orders o ON o.id = %s";
        $where = "o.type = 'shop_order' AND o.status IN ({$placeholders}) AND o.date_created_gmt >= %s";
        $date_col = 'o.date_created_gmt';
    } else {
        $order_join = "JOIN wp_posts o ON o.ID = %s";
        $where = "o.post_type = 'shop_order' AND o.post_status IN ({$placeholders}) AND o.post_date_gmt >= %s";
        $date_col = 'o.post_date_gmt';
    }
    $args = array_merge($statuses, [$date_cutoff]);
    if ($lookup) {
        $join = sprintf($order_join, 'l.order_id');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.product_id AS product_id, DATE({$date_col}) AS day, COUNT(DISTINCT l.order_id) AS orders
            FROM wp_wc_order_product_lookup l
            {$join}
            WHERE {$where} AND l.product_id > 0
            GROUP BY l.product_id, DATE({$date_col})",
            $args
        ), ARRAY_A);
    } else {
        $join = sprintf($order_join, 'oi.order_id');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT oim.meta_value AS product_id, DATE({$date_col}) AS day, COUNT(DISTINCT oi.order_id) AS orders
            FROM wp_woocommerce_order_items oi
            {$join}
            JOIN wp_woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_product_id'
            WHERE {$where} AND oi.order_item_type = 'line_item' AND oim.meta_value > 0
            GROUP BY oim.meta_value, DATE({$date_col})",
            $args
        ), ARRAY_A);
    }

    $product_scores = WooBooster_Trending::score_rows($rows, $now, $half_life, WooBooster_Copurchase::excluded_product_ids());

    $category_products = [];
    $product_ids = array_keys($product_scores);
    if ($product_ids) {
        $cat_relationships = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_id, tr.object_id AS product_id
            FROM wp_term_relationships tr
            INNER JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tr.object_id IN (" . implode(', ', array_fill(0, count($product_ids), '%d')) . ") AND tt.taxonomy = %s",
            array_merge($product_ids, ['product_cat'])
        ));
        $ancestors = [];
        foreach ($cat_relationships as $row) {
            $cat_id = absint($row->term_id);
            $pid = absint($row->product_id);
            if (!isset($product_scores[$pid])) {
                continue;
            }
            if (!isset($ancestors[$cat_id])) {
                $ancestors[$cat_id] = array_map('absint', (array) get_ancestors($cat_id, 'product_cat', 'taxonomy'));
            }
            foreach (array_merge([$cat_id], $ancestors[$cat_id]) as $target) {
                $category_products[$target][$pid] = $product_scores[$pid];
            }
        }
    }

    $out = ['global' => reference_top_ids($product_scores, 50), 'cats' => [], 'scores' => $product_scores, 'rows' => count($rows)];
    foreach ($category_products as $cat_id => $products) {
        $out['cats'][$cat_id] = reference_top_ids($products, 50);
    }
    ksort($out['cats']);
    return $out;
}

/* ── Fixture: products, categories, orders ────────────────────────────── */

mt_srand(20261104);

$product_ids = range(5001, 5400);
$virtual = [5399, 5400];
foreach ($product_ids as $pid) {
    $wpdb->db->exec("INSERT INTO wp_posts (ID, post_type, post_status, post_date_gmt) VALUES ($pid, 'product', 'publish', '2025-01-01 00:00:00')");
}
foreach ($virtual as $pid) {
    $wpdb->db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ($pid, '_virtual', 'yes')");
}

// Category tree: firearms > handguns > compact, firearms > rifles, ammo > 9mm, accessories.
add_test_term(10, 'product_cat', 'firearms');
add_test_term(11, 'product_cat', 'handguns', 10);
add_test_term(12, 'product_cat', 'rifles', 10);
add_test_term(13, 'product_cat', 'compact', 11);
add_test_term(20, 'product_cat', 'ammo');
add_test_term(21, 'product_cat', '9mm', 20);
add_test_term(30, 'product_cat', 'accessories');
$leaf_choices = [11, 12, 13, 13, 12, 11, 21, 20, 30];
foreach ($product_ids as $pid) {
    $cats = [$leaf_choices[mt_rand(0, count($leaf_choices) - 1)]];
    if (mt_rand(1, 4) === 1) {
        $cats[] = $leaf_choices[mt_rand(0, count($leaf_choices) - 1)];
    }
    foreach (array_unique($cats) as $cat) {
        $wpdb->db->exec("INSERT INTO wp_term_relationships (object_id, term_taxonomy_id) VALUES ($pid, $cat)");
    }
}

/** Popular products are picked far more often (Zipf-like). */
$weights = [];
$total_weight = 0.0;
foreach ($product_ids as $i => $pid) {
    $total_weight += 1.0 / ($i + 4);
    $weights[$pid] = $total_weight;
}
function pick_product(array $weights, float $total): int
{
    $r = mt_rand() / mt_getrandmax() * $total;
    foreach ($weights as $pid => $cum) {
        if ($r <= $cum) {
            return $pid;
        }
    }
    return array_key_last($weights);
}

$order_lines = [];  // order_id => [product IDs as the build sees them]
$order_meta = [];   // order_id => ['days_ago' => …, 'status' => …]
$order_count = 1200;
for ($order_id = 1; $order_id <= $order_count; $order_id++) {
    $days_ago = mt_rand(0, 120);
    if ($days_ago >= 88 && $days_ago <= 92) {
        $days_ago += 6; // Keep clear of the 90-day cutoff.
    }
    $ts = time() - $days_ago * DAY_IN_SECONDS - mt_rand(0, DAY_IN_SECONDS - 1);
    $date = gmdate('Y-m-d H:i:s', $ts);
    $roll = mt_rand(1, 100);
    $status = $roll <= 70 ? 'completed' : ($roll <= 88 ? 'processing' : ($roll <= 95 ? 'cancelled' : 'refunded'));
    $wpdb->db->exec("INSERT INTO wp_wc_orders (id, type, status, date_created_gmt) VALUES ($order_id, 'shop_order', '$status', '$date')");
    $wpdb->db->exec("INSERT INTO wp_posts (ID, post_type, post_status, post_date_gmt) VALUES ($order_id, 'shop_order', 'wc-$status', '$date')");

    $size_roll = mt_rand(1, 100);
    if ($size_roll <= 28) {
        $size = 1;
    } elseif ($size_roll <= 90) {
        $size = mt_rand(2, 6);
    } elseif ($size_roll <= 97) {
        $size = mt_rand(7, 18);
    } else {
        $size = mt_rand(41, 55); // Bulk/dealer order, above the cap of 40.
    }
    $lines = [];
    for ($k = 0; $k < $size; $k++) {
        $lines[] = pick_product($weights, $total_weight);
    }
    if (mt_rand(1, 6) === 1) {
        $lines[] = 5001; // 5001 and 5002 are a kit: always sold together (score ties).
        $lines[] = 5002;
    }
    if (in_array(5001, $lines, true) && !in_array(5002, $lines, true)) {
        $lines[] = 5002;
    }
    if (in_array(5002, $lines, true) && !in_array(5001, $lines, true)) {
        $lines[] = 5001;
    }
    // 5201 + 5202: a rarer kit (holster + mag pouch) bought with one pistol,
    // so it shows up, tied, in the Bought Together lists of that pistol and
    // of what is bought with it.
    if (in_array(5005, $lines, true) && mt_rand(1, 2) === 1) {
        $lines[] = 5201;
        $lines[] = 5202;
    }
    if (in_array(5201, $lines, true) xor in_array(5202, $lines, true)) {
        $lines[] = in_array(5201, $lines, true) ? 5202 : 5201;
    }
    if (mt_rand(1, 40) === 1) {
        $lines[] = $virtual[mt_rand(0, 1)]; // Transfer fee.
    }
    if (mt_rand(1, 150) === 1) {
        $lines = []; // An order without line items.
    }
    $synced = mt_rand(1, 10) > 1; // 10% not in the lookup table yet.

    foreach ($lines as $k => $pid) {
        $wpdb->db->exec("INSERT INTO wp_woocommerce_order_items (order_id, order_item_type) VALUES ($order_id, 'line_item')");
        $item_id = $wpdb->db->lastInsertRowID();
        $wpdb->db->exec("INSERT INTO wp_woocommerce_order_itemmeta (order_item_id, meta_key, meta_value) VALUES ($item_id, '_product_id', '$pid')");
        if ($synced && !$NO_LOOKUP) {
            $wpdb->db->exec("INSERT INTO wp_wc_order_product_lookup (order_item_id, order_id, product_id, variation_id) VALUES ($item_id, $order_id, $pid, 0)");
        }
    }
    // A shipping line never counts.
    $wpdb->db->exec("INSERT INTO wp_woocommerce_order_items (order_id, order_item_type) VALUES ($order_id, 'shipping')");

    $order_lines[$order_id] = array_values(array_unique($lines));
    $order_meta[$order_id] = ['days_ago' => $days_ago, 'status' => $status, 'synced' => $synced && !$NO_LOOKUP];
}
// A refund row in HPOS must not count as an order.
$wpdb->db->exec("INSERT INTO wp_wc_orders (id, type, status, date_created_gmt) VALUES (99999, 'shop_order_refund', 'completed', '" . gmdate('Y-m-d H:i:s') . "')");

/** Orders the build reads for a given window, as the previous code saw them. */
function window_orders(array $order_lines, array $order_meta, int $days): array
{
    $orders = [];
    foreach ($order_lines as $order_id => $lines) {
        $meta = $order_meta[$order_id];
        if ($meta['days_ago'] >= $days || !in_array($meta['status'], ['completed', 'processing'], true)) {
            continue;
        }
        $orders[$order_id] = $lines;
    }
    ksort($orders);
    return $orders;
}

function stored_lists(Sqlite_Wpdb $wpdb): array
{
    $lists = [];
    foreach ($wpdb->get_results("SELECT post_id, meta_value FROM wp_postmeta WHERE meta_key = '_woobooster_copurchased' ORDER BY post_id, meta_id", ARRAY_A) as $row) {
        $lists[(int) $row['post_id']] = maybe_unserialize($row['meta_value']);
    }
    return $lists;
}

function trending_transients(): array
{
    $out = ['global' => $GLOBALS['transients']['wb_trending_global'] ?? null, 'cats' => []];
    foreach ($GLOBALS['transients'] as $key => $value) {
        if (0 === strpos($key, 'wb_trending_cat_')) {
            $out['cats'][(int) substr($key, strlen('wb_trending_cat_'))] = $value;
        }
    }
    ksort($out['cats']);
    return $out;
}

function lock_rows(Sqlite_Wpdb $wpdb): array
{
    return $wpdb->get_col("SELECT option_name FROM wp_options WHERE option_name LIKE 'woobooster_build_lock_%'");
}

$GLOBALS['options']['woobooster_settings'] = ['smart_days' => 90, 'smart_max_relations' => 20, 'smart_copurchase' => '1', 'smart_trending' => '1'];

/* ── Bought Together: identical to the previous algorithm ────────────── */

$expected = reference_copurchase(window_orders($order_lines, $order_meta, 90), $virtual, 40, 20);
check($expected['multi_item_orders'] >= 200 && 2 === $expected['min_support'], 'fixture is large enough for the min-support-2 rule');
check($expected['bulk_orders'] > 0 && $expected['single_item_orders'] > 0, 'fixture has bulk and single-item orders');
check(count($expected['index']) > 100, 'fixture produces a sizeable index');

// Stale list of a product that no longer has pairs, and a wrong list.
$wpdb->db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (5398, '_woobooster_copurchased', '" . serialize([5001]) . "')");
$some_product = array_keys($expected['index'])[3];
$wpdb->db->exec("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES ($some_product, '_woobooster_copurchased', '" . serialize([1, 2, 3]) . "')");
check(!isset($expected['index'][5398]), 'fixture: product 5398 has no qualifying pair');

add_filter('woobooster_copurchase_batch_size', static function () { return 50; });
add_filter('woobooster_copurchase_write_batch_size', static function () { return 37; });

$flushes_before = $GLOBALS['cache_flushes'];
$builder = new WooBooster_Copurchase();
$stats = $builder->build(true);
$batches = (int) ceil($expected['orders_scanned'] / 50);

check(empty($stats['stopped']), 'Bought Together build completes');
check(stored_lists($wpdb) === $expected['index'], 'Bought Together lists identical to the previous algorithm (scores, ties, order, min support, bulk cap, virtual products, unsynced orders)');
$kit_lists = 0;
foreach ($expected['index'] as $pid => $list) {
    $at = array_search(5201, $list, true);
    if (false !== $at && in_array(5202, $list, true)) {
        $kit_lists++;
        check(($list[$at + 1] ?? null) === 5202, "kit products tie in the list of $pid and are ordered by ID");
    }
}
check($kit_lists >= 1, "fixture has exact score ties (kit products in $kit_lists lists)");
foreach (['orders_scanned', 'multi_item_orders', 'single_item_orders', 'bulk_orders', 'min_support'] as $key) {
    check($stats[$key] === $expected[$key], "build stat $key unchanged");
}
check(count($expected['index']) === $stats['products'], 'products indexed counted');
check(1 === $stats['products_cleared'], 'the stale list of a product without pairs is removed');
check(count($expected['index']) === $stats['products_updated'], 'new and wrong lists are written');
check($GLOBALS['cache_flushes'] - $flushes_before >= $batches, "in-memory cache emptied after every order batch ($batches batches)");
check(empty($GLOBALS['primed']), 'no product had all of its meta loaded (no get_post_meta, no update without previous value)');
check(in_array(['update', $some_product, true], $GLOBALS['meta_calls'], true), 'a changed list is updated with its known previous value');
check(in_array(900, $GLOBALS['time_limits'], true), 'time limit raised to 15 minutes for the build');
check(in_array('woobooster_smart_build', $GLOBALS['memory_raises'], true), 'memory limit raised to the admin limit for the build');
check([] === lock_rows($wpdb), 'lock released after the build');
check(empty($GLOBALS['options']['woobooster_last_build']['copurchase']['stopped']), 'completed build stores no stop reason');

// Same data again: nothing to write.
$GLOBALS['meta_calls'] = [];
$stats = $builder->build(true);
check(0 === $stats['products_updated'] && 0 === $stats['products_cleared'] && [] === $GLOBALS['meta_calls'], 'an unchanged index writes nothing');
$stats = $builder->build(false);
check(!empty($stats['skipped']), 'unchanged window is skipped by the scheduled run');

// Legacy post storage reads the same orders.
\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = false;
$stats = $builder->build(true);
check('posts' === $stats['storage'] && stored_lists($wpdb) === $expected['index'], 'legacy post storage gives the same lists');
\Automattic\WooCommerce\Utilities\OrderUtil::$hpos = true;

// Another max-relations setting (more pruning while ranking).
$GLOBALS['options']['woobooster_settings']['smart_max_relations'] = 3;
$expected3 = reference_copurchase(window_orders($order_lines, $order_meta, 90), $virtual, 40, 3);
$builder->build(true);
check(stored_lists($wpdb) === $expected3['index'], 'max 3 relations: identical to the previous algorithm');
$GLOBALS['options']['woobooster_settings']['smart_max_relations'] = 20;

// A short window (fewer than 200 multi-item orders: min support 1).
$GLOBALS['options']['woobooster_settings']['smart_days'] = 20;
$expected20 = reference_copurchase(window_orders($order_lines, $order_meta, 20), $virtual, 40, 20);
$stats = $builder->build(true);
check(1 === $expected20['min_support'] && 1 === $stats['min_support'], '20-day window uses min support 1');
check(stored_lists($wpdb) === $expected20['index'], '20-day window: identical to the previous algorithm');
$GLOBALS['options']['woobooster_settings']['smart_days'] = 90;
$builder->build(true);

if ($NO_LOOKUP) {
    // Child run: also check Trending's order item meta SQL, then stop.
    $now = time();
    $stats = (new WooBooster_Trending())->build();
    if ($now !== time()) {
        $now = time();
        $stats = (new WooBooster_Trending())->build();
    }
    $reference = reference_trending($wpdb, true, 90, 14.0, $now, false);
    $built = trending_transients();
    check($built['global'] === $reference['global'] && $built['cats'] === $reference['cats'], 'Trending from order item meta (no lookup table) identical to the previous algorithm');
    echo $checks . " checks passed (woobooster smart build, no lookup table).\n";
    exit(0);
}

/* ── Rank function: flat pairs vs the previous nested ranking ────────── */

$pair_key = new ReflectionMethod('WooBooster_Copurchase', 'pair_key');
$pair_ids = new ReflectionMethod('WooBooster_Copurchase', 'pair_ids');
if (PHP_VERSION_ID < 80100) {
    $pair_key->setAccessible(true);
    $pair_ids->setAccessible(true);
}
check([7, 9] === $pair_ids->invoke(null, $pair_key->invoke(null, 9, 7)), 'pair key is order-independent and reversible');
check([5, 3000000000] === $pair_ids->invoke(null, $pair_key->invoke(null, 3000000000, 5)), 'IDs too large to pack fall back to a string key');

for ($round = 0; $round < 40; $round++) {
    $flat_w = [];
    $flat_c = [];
    $nested_w = [];
    $nested_c = [];
    $orders_with = [];
    // Half the rounds are dense (every product has dozens of partners), so
    // the candidate lists are cut many times while ranking.
    $dense = 0 === $round % 2;
    $n = $dense ? mt_rand(40, 90) : mt_rand(5, 60);
    for ($p = 1; $p <= $n; $p++) {
        $orders_with[$p] = mt_rand(1, 30);
    }
    for ($e = 0; $e < $n * ($dense ? 40 : 4); $e++) {
        $a = mt_rand(1, $n);
        $b = mt_rand(1, $n);
        if ($a === $b) {
            continue;
        }
        $k = $pair_key->invoke(null, $a, $b);
        $add = 1 / mt_rand(1, 6);
        if (isset($flat_w[$k])) {
            $flat_w[$k] += $add;
            $flat_c[$k] = ($flat_c[$k] ?? 1) + 1;
        } else {
            $flat_w[$k] = $add;
        }
        $nested_w[$a][$b] = (isset($nested_w[$a][$b]) ? $nested_w[$a][$b] : 0) + $add;
        $nested_w[$b][$a] = (isset($nested_w[$b][$a]) ? $nested_w[$b][$a] : 0) + $add;
        $nested_c[$a][$b] = ($nested_c[$a][$b] ?? 0) + 1;
        $nested_c[$b][$a] = ($nested_c[$b][$a] ?? 0) + 1;
    }
    foreach ([[1, 1], [2, 3], [1, 20], [3, 2], [1, 8], [2, 30]] as [$support, $max]) {
        $ref = reference_rank_pairs($nested_w, $nested_c, $orders_with, 100, $support, $max);
        $new = WooBooster_Copurchase::rank_pair_map($flat_w, $flat_c, $orders_with, 100, $support, $max);
        ksort($ref);
        ksort($new);
        check($ref === $new, "rank_pair_map() equals the previous ranking (round $round, support $support, max $max)");
        $nested_new = WooBooster_Copurchase::rank_pairs($nested_w, $nested_c, $orders_with, 100, $support, $max);
        ksort($nested_new);
        check($ref === $nested_new, "rank_pairs() unchanged (round $round, support $support, max $max)");
    }
}
$big = [];
for ($a = 1; $a <= 120; $a++) {
    for ($b = $a + 1; $b <= 120; $b++) {
        $big[$pair_key->invoke(null, $a, $b)] = 0.5;
    }
}
$asked = 0;
$stopped_rank = WooBooster_Copurchase::rank_pair_map($big, [], array_fill(1, 120, 3), 1000, 1, 5, static function () use (&$asked) { $asked++; return true; });
check(count($big) > 5000 && null === $stopped_rank && 1 === $asked, 'rank_pair_map() checks the guard every 5,000 pairs and stops when told');
check(120 === count(WooBooster_Copurchase::rank_pair_map($big, [], array_fill(1, 120, 3), 1000, 1, 5, static function () { return false; })), 'rank_pair_map() finishes when the guard allows it');

/* ── Build lock ───────────────────────────────────────────────────────── */

$before = stored_lists($wpdb);
$last_build_before = $GLOBALS['options']['woobooster_last_build'];

$held = WooBooster_Cron::acquire_lock('copurchase');
check(is_string($held) && '' !== $held, 'lock taken');
check(false === WooBooster_Cron::acquire_lock('copurchase'), 'a second run cannot take a held lock');
$stats = $builder->build(true);
check('locked' === $stats['stopped'] && '' !== $stats['reason'] && 0 === $stats['products'], 'a build while another runs stops with a reason');
check(stored_lists($wpdb) === $before && $GLOBALS['options']['woobooster_last_build'] === $last_build_before, 'the blocked build changed nothing');
WooBooster_Cron::release_lock('copurchase', 'someone-else');
check(['woobooster_build_lock_copurchase'] === lock_rows($wpdb), 'a lock is never released with another token');
check(is_string(WooBooster_Cron::acquire_lock('trending')), 'locks are per build: trending can run alongside');
WooBooster_Cron::release_lock('trending', WooBooster_Cron::acquire_lock('trending') ?: '');
WooBooster_Cron::release_held_locks();
check([] === lock_rows($wpdb), 'release_held_locks() (shutdown) frees every lock this request holds');

// An abandoned lock (request died 20 minutes ago) is taken over.
$wpdb->db->exec("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('woobooster_build_lock_copurchase', '" . (time() - 1200) . "|deadbeef', 'no')");
$stats = $builder->build(true);
check(empty($stats['stopped']) && stored_lists($wpdb) === $expected['index'], 'an abandoned lock is taken over');
check([] === lock_rows($wpdb), 'taken-over lock released after the build');

/* ── Memory guard ─────────────────────────────────────────────────────── */

add_filter('woobooster_smart_build_memory_ratio', static function () { return 1e-12; });
$before = stored_lists($wpdb);
$products_before = $GLOBALS['options']['woobooster_last_build']['copurchase']['products'];
$stats = $builder->build(true);
check('memory' === $stats['stopped'] && false !== strpos($stats['reason'], 'memory limit'), 'memory guard stops the build with a reason');
check($stats['orders_scanned'] > 0 && $stats['orders_scanned'] < $expected['orders_scanned'], 'memory guard stops early, not at the end');
check(stored_lists($wpdb) === $before, 'a stopped build keeps the previous lists');
$saved = $GLOBALS['options']['woobooster_last_build']['copurchase'];
check('memory' === $saved['stopped'] && $saved['stopped_reason'] === $stats['reason'] && !empty($saved['stopped_at']), 'stop reason stored for the admin');
check($saved['products'] === $products_before && !empty($saved['signature']), 'stored stats of the last completed build are kept');
check([] === lock_rows($wpdb), 'lock released after a stopped build');

$trending_before = trending_transients();
$stats = (new WooBooster_Trending())->build();
check('memory' === $stats['stopped'] && isset($stats['categories']) && '' !== $stats['reason'], 'Trending memory guard stops with a reason');
$saved = $GLOBALS['options']['woobooster_last_build']['trending'];
check(isset($saved['products'], $saved['categories'], $saved['time'], $saved['date']) && '' === $saved['date'] && 'memory' === $saved['stopped'], 'a first build that stops still stores the keys the admin reads (empty date: waiting for the first update)');
check(trending_transients() === $trending_before, 'stopped Trending build writes nothing');
remove_all_filters('woobooster_smart_build_memory_ratio');

$stats = $builder->build(true);
check(empty($stats['stopped']) && empty($GLOBALS['options']['woobooster_last_build']['copurchase']['stopped']), 'the next completed build clears the stop reason');

/* ── Trending: identical to the previous algorithm ───────────────────── */

add_filter('woobooster_trending_chunk_size', static function () { return 45; });
foreach ([true, false] as $hpos) {
    \Automattic\WooCommerce\Utilities\OrderUtil::$hpos = $hpos;
    $GLOBALS['transients'] = [];
    $flushes_before = $GLOBALS['cache_flushes'];
    do {
        $now = time();
        $stats = (new WooBooster_Trending())->build();
    } while ($now !== time());
    $reference = reference_trending($wpdb, $hpos, 90, 14.0, $now, true);
    $built = trending_transients();
    $label = $hpos ? 'HPOS' : 'legacy posts';
    check(empty($stats['stopped']), "Trending build completes ($label)");
    check($built['global'] === $reference['global'], "store-wide Trending list identical to the previous algorithm ($label)");
    check($built['cats'] === $reference['cats'], "per-category Trending lists identical, parents including children ($label)");
    check(count($reference['scores']) === $stats['products'] && count($reference['cats']) === $stats['categories'], "Trending stats unchanged ($label)");
    check($GLOBALS['cache_flushes'] - $flushes_before >= (int) ceil(count($reference['scores']) / 45), "in-memory cache emptied between category chunks ($label)");
    check(count($reference['scores']) > 200 && count($built['cats'][10]) === 50, 'the firearms category has more than 200 products and keeps its top 50');
    check($reference['rows'] > count($reference['scores']), 'the database now returns one row per product instead of one per product and day');

    // The SQL sums themselves, at fixed times of day (before and after noon
    // UTC, where the max(0, age) clamp matters for today's sales).
    $decayed = new ReflectionMethod('WooBooster_Trending', 'decayed_scores');
    if (PHP_VERSION_ID < 80100) {
        $decayed->setAccessible(true);
    }
    $statuses = WooBooster_Trending::get_order_statuses();
    $statuses = $hpos ? WooBooster_Trending::expand_statuses_for_hpos($statuses) : $statuses;
    foreach ([strtotime(gmdate('Y-m-d') . ' 03:17:00 UTC'), strtotime(gmdate('Y-m-d') . ' 12:00:00 UTC'), strtotime(gmdate('Y-m-d') . ' 21:45:30 UTC')] as $at) {
        $sql_scores = $decayed->invoke(new WooBooster_Trending(), $hpos, $statuses, gmdate('Y-m-d H:i:s', strtotime('-90 days')), $at, 14.0, WooBooster_Copurchase::excluded_product_ids());
        $php_scores = reference_trending($wpdb, $hpos, 90, 14.0, $at, true)['scores'];
        ksort($sql_scores);
        ksort($php_scores);
        check($sql_scores === $php_scores, "SQL decayed sums equal score_rows() bit for bit at " . gmdate('H:i', $at) . " UTC ($label)");
    }
}
check(in_array(5001, $built['global'], true) && !in_array(5399, $built['global'], true), 'kit product trends; virtual fee product is left out');
$at = array_search(5001, $built['global'], true);
check(5002 === ($built['global'][$at + 1] ?? null), 'kit products have equal Trending scores and are ordered by ID');
remove_all_filters('woobooster_trending_chunk_size');

// Half-life setting goes into the SQL unchanged.
add_filter('woobooster_trending_half_life_days', static function () { return 3.7; });
do {
    $now = time();
    (new WooBooster_Trending())->build();
} while ($now !== time());
$reference = reference_trending($wpdb, true, 90, 3.7, $now, true);
check(trending_transients()['cats'] === $reference['cats'] && trending_transients()['global'] === $reference['global'], 'a custom half-life (3.7 days) gives identical lists');
remove_all_filters('woobooster_trending_half_life_days');

$held = WooBooster_Cron::acquire_lock('trending');
$stats = (new WooBooster_Trending())->build();
check('locked' === $stats['stopped'], 'Trending build is locked while another runs');
WooBooster_Cron::release_lock('trending', $held);

/* ── Rebuild Now: both builds in one call ─────────────────────────────── */

$results = WooBooster_Cron::run_builds(true);
check(['copurchase', 'trending'] === array_keys($results), 'run_builds() runs both enabled builds');
check(empty($results['copurchase']['stopped']) && empty($results['copurchase']['skipped']), 'run_builds(true) forces Bought Together');
check(isset($results['copurchase']['products'], $results['copurchase']['time'], $results['trending']['categories'], $results['trending']['time']), 'run_builds() returns the stats the admin message uses');
$GLOBALS['options']['woobooster_settings']['smart_copurchase'] = '0';
check(['trending'] === array_keys(WooBooster_Cron::run_builds(true)), 'run_builds() skips disabled strategies');
$GLOBALS['options']['woobooster_settings']['smart_copurchase'] = '1';
$held = WooBooster_Cron::acquire_lock('copurchase');
$results = WooBooster_Cron::run_builds(true);
check('locked' === $results['copurchase']['stopped'] && empty($results['trending']['stopped']), 'a locked build does not block the other one');
WooBooster_Cron::release_lock('copurchase', $held);

/* ── Rule lookup index: "+ Children" follows the category tree ───────── */

$wpdb->db->exec('CREATE TABLE wp_woobooster_rules (id INTEGER PRIMARY KEY, name TEXT, priority INTEGER, status INTEGER, condition_attribute TEXT, condition_operator TEXT, condition_value TEXT, include_children INTEGER DEFAULT 0, action_source TEXT, action_value TEXT, action_orderby TEXT, action_limit INTEGER, created_at TEXT)');
$wpdb->db->exec('CREATE TABLE wp_woobooster_rule_conditions (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INTEGER, group_id INTEGER DEFAULT 0, condition_attribute TEXT, condition_operator TEXT, condition_value TEXT, include_children INTEGER DEFAULT 0, min_quantity INTEGER DEFAULT 1)');
// Legacy actions table without group_id (for the schema check).
$wpdb->db->exec('CREATE TABLE wp_woobooster_rule_actions (id INTEGER PRIMARY KEY AUTOINCREMENT, rule_id INTEGER, action_source TEXT, action_value TEXT)');
$wpdb->db->exec('CREATE TABLE wp_woobooster_rule_index (id INTEGER PRIMARY KEY AUTOINCREMENT, condition_key TEXT, rule_id INTEGER, priority INTEGER)');
$wpdb->db->exec("INSERT INTO wp_woobooster_rules (id, name, priority, status) VALUES (1, 'Firearms + children', 10, 1), (2, 'Off', 5, 0), (3, 'Sale, not ammo', 20, 1), (4, 'Caliber', 10, 1)");
$wpdb->db->exec("INSERT INTO wp_woobooster_rule_conditions (rule_id, group_id, condition_attribute, condition_operator, condition_value, include_children) VALUES
    (1, 0, 'product_cat', 'equals', 'firearms', 1),
    (2, 0, 'product_cat', 'equals', 'ammo', 1),
    (3, 0, 'product_tag', 'equals', 'sale', 0),
    (3, 0, 'product_cat', 'not_equals', 'ammo', 0),
    (4, 1, 'pa_caliber', 'equals', '9mm', 0)");
$GLOBALS['options']['woobooster_db_version'] = '1.10.0';

function rule_keys(Sqlite_Wpdb $wpdb, int $rule_id): array
{
    $keys = $wpdb->get_col("SELECT condition_key FROM wp_woobooster_rule_index WHERE rule_id = $rule_id");
    sort($keys);
    return $keys;
}

// Index as it was when the rules were saved.
foreach ([1, 2, 3, 4] as $rule_id) {
    WooBooster_Rule::rebuild_index_for_rule($rule_id);
}
check(['product_cat:compact', 'product_cat:firearms', 'product_cat:handguns', 'product_cat:rifles'] === rule_keys($wpdb, 1), 'saved rule indexes its category and its children');
check([] === rule_keys($wpdb, 2), 'inactive rule is not indexed');
check(['__not_equals__:1', 'product_tag:sale'] === rule_keys($wpdb, 3), '"is not" condition indexed as the sentinel');
check(!empty($GLOBALS['primed_terms']), 'child terms loaded in one query');
// Leftovers the full rebuild must remove: an inactive and a deleted rule.
$wpdb->db->exec("INSERT INTO wp_woobooster_rule_index (condition_key, rule_id, priority) VALUES ('product_cat:ammo', 2, 5), ('product_cat:old', 99, 1)");

$cron = new WooBooster_Cron();
$cron->init();
$GLOBALS['cron'] = [];

// A new subcategory (and a second one, as an import would) queues ONE rebuild.
add_test_term(14, 'product_cat', 'revolvers', 11);
do_action('created_term', 14, 14, 'product_cat', []);
add_test_term(15, 'product_cat', 'pocket', 13);
do_action('created_term', 15, 15, 'product_cat', []);
add_test_term(16, 'product_cat', 'holsters', 30);
do_action('created_term', 16, 16, 'product_cat', []);
add_test_term(500, 'category', 'blog-news');
do_action('created_term', 500, 500, 'category', []);
$events = events_for(WooBooster_Cron::RULE_INDEX_HOOK);
check(1 === count($events) && false === $events[0]['schedule'], 'three new categories queue exactly one single rebuild event');
check($events[0]['ts'] >= time() + 59 && $events[0]['ts'] <= time() + 61, 'rebuild is debounced by a minute');
check(1 === count($GLOBALS['cron']), 'a blog category does not queue anything');
check(!in_array('product_cat:revolvers', rule_keys($wpdb, 1), true), 'before the rebuild the new subcategory is missing (the bug)');

$invalidations = WooBooster_Matcher::$invalidations;
$bundle_rebuilds = WooBooster_Bundle::$rebuilds;
run_cron_event(WooBooster_Cron::RULE_INDEX_HOOK);
check(['product_cat:compact', 'product_cat:firearms', 'product_cat:handguns', 'product_cat:pocket', 'product_cat:revolvers', 'product_cat:rifles'] === rule_keys($wpdb, 1), 'the rebuild adds subcategories created after the rule was saved');
check(['__not_equals__:1', 'product_tag:sale'] === rule_keys($wpdb, 3) && ['pa_caliber:9mm'] === rule_keys($wpdb, 4), 'other rules keep their entries');
check([] === rule_keys($wpdb, 2) && [] === rule_keys($wpdb, 99), 'entries of inactive and deleted rules are removed');
check($invalidations + 1 === WooBooster_Matcher::$invalidations, 'the rebuild clears cached recommendations');
check($bundle_rebuilds + 1 === WooBooster_Bundle::$rebuilds, 'the bundle index is rebuilt by the same job');
check([] === lock_rows($wpdb), 'rule index lock released');

$result = WooBooster_Rule::rebuild_full_index();
check(3 === $result['rules'] && 0 === $result['rules_changed'] && 0 === $result['entries_removed'], 'an up-to-date index is not rewritten');
$wpdb->db->exec("INSERT INTO wp_woobooster_rule_index (condition_key, rule_id, priority) VALUES ('pa_caliber:9mm', 4, 10)");
$result = WooBooster_Rule::rebuild_full_index();
check(1 === $result['rules_changed'] && ['pa_caliber:9mm'] === rule_keys($wpdb, 4), 'duplicate entries are repaired');

// Edits: a name change does nothing; slug and parent changes rebuild.
do_action('edit_terms', 12, 'product_cat', []);
$GLOBALS['terms']['product_cat'][12]->name = 'Long guns';
do_action('edited_term', 12, 12, 'product_cat', []);
check([] === events_for(WooBooster_Cron::RULE_INDEX_HOOK), 'renaming a category (same slug and parent) queues nothing');

do_action('edit_terms', 12, 'product_cat', []);
$GLOBALS['terms']['product_cat'][12]->slug = 'long-guns';
do_action('edited_term', 12, 12, 'product_cat', []);
do_action('edit_terms', 12, 'product_cat', []);
do_action('edited_term', 12, 12, 'product_cat', []);
check(1 === count(events_for(WooBooster_Cron::RULE_INDEX_HOOK)), 'a slug change queues one rebuild');
run_cron_event(WooBooster_Cron::RULE_INDEX_HOOK);
check(in_array('product_cat:long-guns', rule_keys($wpdb, 1), true) && !in_array('product_cat:rifles', rule_keys($wpdb, 1), true), 'the rebuild follows the new slug');

do_action('edit_terms', 14, 'product_cat', []);
$GLOBALS['terms']['product_cat'][14]->parent = 20;
do_action('edited_term', 14, 14, 'product_cat', []);
check(1 === count(events_for(WooBooster_Cron::RULE_INDEX_HOOK)), 'moving a category to another parent queues one rebuild');
run_cron_event(WooBooster_Cron::RULE_INDEX_HOOK);
check(!in_array('product_cat:revolvers', rule_keys($wpdb, 1), true), 'a category moved out of the tree leaves the rule');

unset($GLOBALS['terms']['product_cat'][15]);
do_action('delete_term', 15, 15, 'product_cat', null, []);
check(1 === count(events_for(WooBooster_Cron::RULE_INDEX_HOOK)), 'deleting a category queues one rebuild');
$invalidations = WooBooster_Matcher::$invalidations;
run_cron_event(WooBooster_Cron::RULE_INDEX_HOOK);
check(!in_array('product_cat:pocket', rule_keys($wpdb, 1), true) && $invalidations + 1 === WooBooster_Matcher::$invalidations, 'a deleted category leaves the rule and the cache is cleared');

// While a rebuild runs, another one is queued instead of running twice.
$held = WooBooster_Cron::acquire_lock('rule_index');
add_test_term(17, 'product_cat', 'pistol-caliber', 12);
do_action('created_term', 17, 17, 'product_cat', []);
wp_clear_scheduled_hook(WooBooster_Cron::RULE_INDEX_HOOK); // The event was picked up by cron...
$result = WooBooster_Cron::run_rule_index_rebuild();        // ...while another rebuild holds the lock.
check('locked' === $result['stopped'] && 1 === count(events_for(WooBooster_Cron::RULE_INDEX_HOOK)), 'a rebuild blocked by a running one queues another');
WooBooster_Cron::release_lock('rule_index', $held);
run_cron_event(WooBooster_Cron::RULE_INDEX_HOOK);
check(in_array('product_cat:pistol-caliber', rule_keys($wpdb, 1), true), 'the queued rebuild picks up the change');

// Daily safety net, independent of the Smart settings.
$GLOBALS['options']['woobooster_settings'] = [];
$GLOBALS['cron'] = [];
WooBooster_Cron::schedule();
$daily = events_for(WooBooster_Cron::RULE_INDEX_DAILY_HOOK);
check(1 === count($daily) && 'daily' === $daily[0]['schedule'], 'daily rule index rebuild scheduled even with Smart strategies off');
WooBooster_Cron::schedule();
check(1 === count(events_for(WooBooster_Cron::RULE_INDEX_DAILY_HOOK)), 'daily event scheduled once');
add_test_term(18, 'product_cat', 'lever-action', 12);   // Created without hooks (e.g. a direct import).
$invalidations = WooBooster_Matcher::$invalidations;
do_action(WooBooster_Cron::RULE_INDEX_DAILY_HOOK);
check(in_array('product_cat:lever-action', rule_keys($wpdb, 1), true) && $invalidations + 1 === WooBooster_Matcher::$invalidations, 'the daily run catches categories added without term hooks');
WooBooster_Cron::schedule_rule_index_rebuild();
WooBooster_Cron::unschedule();
check([] === $GLOBALS['cron'], 'unschedule() clears every WooBooster event');

/* ── group_id schema check is cached per db version ──────────────────── */

$reset = static function () {
    $prop = new ReflectionProperty('WooBooster_Rule', 'group_id_columns');
    if (PHP_VERSION_ID < 80100) {
        $prop->setAccessible(true);
    }
    $prop->setValue(null, []);
};
$reset();
unset($GLOBALS['options'][WooBooster_Rule::SCHEMA_OPTION]);
$wpdb->queries = [];
WooBooster_Rule::get_conditions(1);
WooBooster_Rule::get_conditions(3);
check(1 === $wpdb->count_queries('INFORMATION_SCHEMA'), 'schema checked once per request');
check(['db_version' => '1.10.0', 'conditions' => true] === $GLOBALS['options'][WooBooster_Rule::SCHEMA_OPTION], 'positive check stored for the db version');

$reset(); // Next request.
$wpdb->queries = [];
$groups = WooBooster_Rule::get_conditions(4);
check(0 === $wpdb->count_queries('INFORMATION_SCHEMA') && isset($groups[1]), 'later requests read the stored answer (no INFORMATION_SCHEMA query)');

$reset();
$wpdb->queries = [];
WooBooster_Rule::get_actions(1);
WooBooster_Rule::get_actions(1);
$reset();
WooBooster_Rule::get_actions(1);
check(2 === $wpdb->count_queries('INFORMATION_SCHEMA') && !isset($GLOBALS['options'][WooBooster_Rule::SCHEMA_OPTION]['actions']), 'a missing column is not stored, so it is checked again after a migration');

$GLOBALS['options']['woobooster_db_version'] = '1.11.0';
$reset();
$wpdb->queries = [];
WooBooster_Rule::get_conditions(1);
check(1 === $wpdb->count_queries('INFORMATION_SCHEMA') && '1.11.0' === $GLOBALS['options'][WooBooster_Rule::SCHEMA_OPTION]['db_version'], 'a new db version checks again');

/* ── Same checks without the lookup table (order item meta SQL) ──────── */

$cmd = 'WB_NO_LOOKUP=1 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' 2>&1';
exec($cmd, $child_output, $child_status);
check(0 === $child_status, "run without the lookup table:\n" . implode("\n", $child_output));
echo implode("\n", $child_output) . "\n";

echo $checks . " checks passed (woobooster smart build).\n";
