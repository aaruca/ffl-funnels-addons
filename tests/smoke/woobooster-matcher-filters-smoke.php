<?php
/**
 * Standalone regression harness for WooBooster's product-page matching.
 *
 * Run: php tests/smoke/woobooster-matcher-filters-smoke.php
 *
 * Covers: rules that only apply a coupon do not take over product pages
 * (the next rule is used; mixed rules still recommend), Smart sources honour
 * the action's exceptions, catalog-hidden products are never returned, and
 * results cached before this revision are not served.
 *
 * WP_Query and $wpdb are in-memory stand-ins over a small catalog. WP_Query
 * follows WordPress where it matters: post__not_in is ignored when post__in
 * is set, tax_query clauses include child terms of hierarchical taxonomies by
 * default, meta_query and tax_query clauses are ANDed. No database is used.
 */

define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');

$checks = 0;
function check($test, $message)
{
    global $checks;
    $checks++;
    if (!$test) {
        throw new RuntimeException("FAIL: $message");
    }
}

/* ── Catalog ──────────────────────────────────────────────────────────── */

// product_cat: firearms > handguns; ammo; holsters; fees.
$terms = array(
    'product_cat' => array(
        'firearms' => array('id' => 1, 'parent' => 0),
        'handguns' => array('id' => 2, 'parent' => 1),
        'ammo'     => array('id' => 3, 'parent' => 0),
        'holsters' => array('id' => 4, 'parent' => 0),
        'fees'     => array('id' => 5, 'parent' => 0),
    ),
    'product_visibility' => array(
        'exclude-from-catalog' => array('id' => 901, 'parent' => 0),
        'exclude-from-search'  => array('id' => 902, 'parent' => 0),
        'outofstock'           => array('id' => 903, 'parent' => 0),
    ),
);

function product($cats, $price, $sales, $date, $extra = array())
{
    return array_merge(array(
        'cats'  => $cats,
        'price' => $price,
        'sales' => $sales,
        'date'  => $date,
        'stock' => 'instock',
        'vis'   => array(),
    ), $extra);
}

$catalog = array(
    100 => product(array('handguns'), 500, 40, '2026-01-01'),
    101 => product(array('ammo'), 20, 90, '2026-01-02'),
    102 => product(array('ammo'), 30, 80, '2026-01-03'),
    103 => product(array('holsters'), 50, 70, '2026-01-04'),
    104 => product(array('holsters'), 60, 60, '2026-01-05'),
    105 => product(array('firearms'), 900, 50, '2026-01-06'),
    106 => product(array('handguns'), 450, 30, '2026-01-07'),
    // Transfer fee: Catalog visibility "Hidden".
    107 => product(array('fees'), 25, 999, '2026-01-08', array('vis' => array('exclude-from-catalog', 'exclude-from-search'))),
    // LE-only ammo: "Search results only".
    108 => product(array('ammo'), 25, 95, '2026-01-09', array('vis' => array('exclude-from-catalog'))),
    109 => product(array('ammo'), 22, 85, '2026-01-10', array('stock' => 'outofstock', 'vis' => array('outofstock'))),
    110 => product(array('holsters'), 300, 20, '2026-01-11'),
    111 => product(array('handguns'), 480, 99, '2026-01-12'),
    112 => product(array('holsters'), 40, 1, '2026-01-13'),
);

$meta = array(
    106 => array('_woobooster_copurchased' => array(100, 105, 101, 102, 103, 110, 107, 108, 109, 104)),
);

function term_row($taxonomy, $slug)
{
    return $GLOBALS['terms'][$taxonomy][$slug];
}

/** Term objects of a product: categories and visibility flags. */
function product_terms($id)
{
    $out = array();
    foreach ($GLOBALS['catalog'][$id]['cats'] as $slug) {
        $out[] = array('taxonomy' => 'product_cat', 'slug' => $slug, 'term_id' => term_row('product_cat', $slug)['id']);
    }
    foreach ($GLOBALS['catalog'][$id]['vis'] as $slug) {
        $out[] = array('taxonomy' => 'product_visibility', 'slug' => $slug, 'term_id' => term_row('product_visibility', $slug)['id']);
    }
    return $out;
}

function descendant_ids($taxonomy, $id)
{
    $ids = array($id);
    foreach ($GLOBALS['terms'][$taxonomy] as $row) {
        if ($row['parent'] === $id) {
            $ids = array_merge($ids, descendant_ids($taxonomy, $row['id']));
        }
    }
    return $ids;
}

/* ── WordPress / WooCommerce stand-ins ────────────────────────────────── */

$options = array('enabled' => '1', 'exclude_outofstock' => '1', 'debug_mode' => '0', 'smart_days' => 90);
$wp_options = array();
$object_cache = array();
$transients = array();

function woobooster_get_option($key, $default = '') { return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['wp_options'][$key] = $value; return true; }
function wp_cache_get($key, $group = '') { return array_key_exists($group . $key, $GLOBALS['object_cache']) ? $GLOBALS['object_cache'][$group . $key] : false; }
function wp_cache_set($key, $value, $group = '', $ttl = 0) { $GLOBALS['object_cache'][$group . $key] = $value; return true; }
function wp_using_ext_object_cache() { return false; }
function get_transient($key) { return array_key_exists($key, $GLOBALS['transients']) ? $GLOBALS['transients'][$key] : false; }
function set_transient($key, $value, $ttl = 0) { $GLOBALS['transients'][$key] = $value; return true; }
function apply_filters($hook, $value, ...$args) { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_unslash($value) { return $value; }
function wp_json_encode($value) { return json_encode($value); }
function current_time($type, $gmt = 0) { return '2026-11-04 12:00:00'; }
function is_wp_error($thing) { return false; }
function __($text, $domain = '') { return $text; }
function taxonomy_exists($taxonomy) { return isset($GLOBALS['terms'][$taxonomy]); }
function is_taxonomy_hierarchical($taxonomy) { return 'product_cat' === $taxonomy; }
function wc_get_product_visibility_term_ids()
{
    $ids = array();
    foreach ($GLOBALS['terms']['product_visibility'] as $slug => $row) {
        $ids[$slug] = $row['id'];
    }
    return $ids;
}
function get_term_by($field, $value, $taxonomy)
{
    if (!isset($GLOBALS['terms'][$taxonomy][$value])) {
        return false;
    }
    $row = $GLOBALS['terms'][$taxonomy][$value];
    return (object) array('term_id' => $row['id'], 'term_taxonomy_id' => $row['id'], 'slug' => $value, 'taxonomy' => $taxonomy);
}
function get_term($term_id, $taxonomy = '')
{
    return (object) array('term_id' => (int) $term_id, 'term_taxonomy_id' => (int) $term_id, 'taxonomy' => $taxonomy);
}
function term_is_ancestor_of($parent, $child, $taxonomy)
{
    return $parent !== $child && in_array((int) $child, descendant_ids($taxonomy, (int) $parent), true);
}
function wp_get_post_terms($id, $taxonomy, $args = array())
{
    $ids = array();
    foreach ($GLOBALS['catalog'][$id]['cats'] as $slug) {
        $ids[] = term_row('product_cat', $slug)['id'];
    }
    return $ids;
}
function wp_get_object_terms($id, $taxonomy, $args = array()) { return wp_get_post_terms($id, $taxonomy, $args); }
function get_post_meta($id, $key, $single = false)
{
    if ('_price' === $key) {
        return (string) $GLOBALS['catalog'][$id]['price'];
    }
    return isset($GLOBALS['meta'][$id][$key]) ? $GLOBALS['meta'][$id][$key] : '';
}
function wc_get_product($id)
{
    if (!isset($GLOBALS['catalog'][$id])) {
        return false;
    }
    return new class($id) {
        private $id;
        public function __construct($id) { $this->id = $id; }
        public function get_name() { return 'Product ' . $this->id; }
        public function get_price_html() { return '$' . $GLOBALS['catalog'][$this->id]['price']; }
        public function get_stock_status() { return $GLOBALS['catalog'][$this->id]['stock']; }
    };
}

/** A product's value for a meta_query key (null = no row). */
function meta_value($id, $key)
{
    $p = $GLOBALS['catalog'][$id];
    switch ($key) {
        case '_price':
            return $p['price'];
        case '_stock_status':
            return $p['stock'];
        case 'total_sales':
            return $p['sales'];
    }
    return null;
}

function tax_clause_matches($id, array $clause)
{
    $taxonomy = $clause['taxonomy'];
    $field = isset($clause['field']) ? $clause['field'] : 'term_id';
    $wanted = array();
    foreach ((array) $clause['terms'] as $term) {
        if ('slug' === $field) {
            if (!isset($GLOBALS['terms'][$taxonomy][$term])) {
                continue;
            }
            $term = $GLOBALS['terms'][$taxonomy][$term]['id'];
        }
        $term = (int) $term; // term_id and term_taxonomy_id are equal here.
        $children = !isset($clause['include_children']) || $clause['include_children'];
        $wanted = array_merge($wanted, $children && is_taxonomy_hierarchical($taxonomy) ? descendant_ids($taxonomy, $term) : array($term));
    }
    $has = array();
    foreach (product_terms($id) as $term) {
        if ($term['taxonomy'] === $taxonomy) {
            $has[] = (int) $term['term_id'];
        }
    }
    $operator = isset($clause['operator']) ? strtoupper($clause['operator']) : 'IN';
    $hit = (bool) array_intersect($has, $wanted);
    return 'NOT IN' === $operator ? !$hit : $hit;
}

function tax_query_matches($id, array $query)
{
    $relation = isset($query['relation']) ? strtoupper($query['relation']) : 'AND';
    $results = array();
    foreach ($query as $key => $clause) {
        if ('relation' === $key || !is_array($clause)) {
            continue;
        }
        $results[] = isset($clause['taxonomy']) ? tax_clause_matches($id, $clause) : tax_query_matches($id, $clause);
    }
    if (empty($results)) {
        return true;
    }
    return 'OR' === $relation ? in_array(true, $results, true) : !in_array(false, $results, true);
}

function meta_query_matches($id, array $query)
{
    $relation = isset($query['relation']) ? strtoupper($query['relation']) : 'AND';
    $results = array();
    foreach ($query as $key => $clause) {
        if ('relation' === $key || !is_array($clause)) {
            continue;
        }
        if (!isset($clause['key'])) {
            $results[] = meta_query_matches($id, $clause);
            continue;
        }
        $value = meta_value($id, $clause['key']);
        $compare = isset($clause['compare']) ? strtoupper($clause['compare']) : '=';
        switch ($compare) {
            case 'EXISTS':
                $results[] = null !== $value;
                break;
            case 'NOT EXISTS':
                $results[] = null === $value;
                break;
            case '>=':
                $results[] = null !== $value && (float) $value >= (float) $clause['value'];
                break;
            case '<=':
                $results[] = null !== $value && (float) $value <= (float) $clause['value'];
                break;
            default:
                $results[] = null !== $value && (string) $value === (string) $clause['value'];
        }
    }
    if (empty($results)) {
        return true;
    }
    return 'OR' === $relation ? in_array(true, $results, true) : !in_array(false, $results, true);
}

class WP_Query
{
    public $posts = array();

    public function __construct($args)
    {
        $ids = array_keys($GLOBALS['catalog']);
        // WP_Query::get_posts(): post__in, ELSE post__not_in.
        if (!empty($args['post__in'])) {
            $ids = array_values(array_intersect($ids, array_map('intval', $args['post__in'])));
        } elseif (!empty($args['post__not_in'])) {
            $ids = array_values(array_diff($ids, array_map('intval', $args['post__not_in'])));
        }
        $tax = isset($args['tax_query']) ? (array) $args['tax_query'] : array();
        $metaq = isset($args['meta_query']) ? (array) $args['meta_query'] : array();
        $ids = array_values(array_filter($ids, static function ($id) use ($tax, $metaq) {
            return tax_query_matches($id, $tax) && meta_query_matches($id, $metaq);
        }));

        $orderby = isset($args['orderby']) ? $args['orderby'] : 'date';
        if ('post__in' === $orderby) {
            $position = array_flip(array_map('intval', $args['post__in']));
            usort($ids, static function ($a, $b) use ($position) { return $position[$a] - $position[$b]; });
        } elseif ('meta_value_num' === $orderby) {
            $key = $args['meta_key'];
            $desc = 'DESC' === strtoupper(isset($args['order']) ? $args['order'] : 'DESC');
            usort($ids, static function ($a, $b) use ($key, $desc) {
                $cmp = (float) meta_value($a, $key) <=> (float) meta_value($b, $key);
                return $desc ? -$cmp : $cmp;
            });
        } elseif (is_array($orderby)) { // Best sellers, then newest ID.
            usort($ids, static function ($a, $b) {
                return array((float) meta_value($b, 'total_sales'), $b) <=> array((float) meta_value($a, 'total_sales'), $a);
            });
        } else {
            usort($ids, static function ($a, $b) { return strcmp($GLOBALS['catalog'][$b]['date'], $GLOBALS['catalog'][$a]['date']); });
        }

        if (isset($args['posts_per_page']) && $args['posts_per_page'] > 0) {
            $ids = array_slice($ids, 0, (int) $args['posts_per_page']);
        }
        $this->posts = $ids;
    }
}

/* ── Rules ────────────────────────────────────────────────────────────── */

function rule($id, $priority, array $condition_groups, array $action_groups)
{
    $GLOBALS['rules'][$id] = (object) array(
        'id' => $id, 'name' => 'Rule ' . $id, 'priority' => $priority, 'status' => 1,
        'start_date' => null, 'end_date' => null, 'action_source' => 'category',
    );
    $GLOBALS['conditions'][$id] = $condition_groups;
    $GLOBALS['actions'][$id] = $action_groups;
}
function cond($attribute, $value)
{
    return (object) array('condition_attribute' => $attribute, 'condition_value' => $value, 'condition_operator' => 'equals', 'include_children' => 0);
}
function act($source, $value = '', array $extra = array())
{
    return (object) array_merge(array(
        'action_source' => $source, 'action_value' => $value, 'action_limit' => 10, 'action_orderby' => 'bestselling',
        'include_children' => 0, 'action_products' => '', 'exclude_categories' => '', 'exclude_products' => '',
        'exclude_price_min' => null, 'exclude_price_max' => null, 'action_coupon_id' => null,
    ), $extra);
}
function coupon() { return act('apply_coupon', '', array('action_coupon_id' => 77)); }

/** Index keys a rule's conditions produce (WooBooster_Rule::rebuild_index_for_rule()). */
function index_keys($rule_id)
{
    $keys = array();
    foreach ($GLOBALS['conditions'][$rule_id] as $group) {
        foreach ($group as $cond) {
            foreach (explode(',', $cond->condition_value) as $value) {
                $keys[] = $cond->condition_attribute . ':' . $value;
            }
        }
    }
    return $keys;
}

class WooBooster_Rule
{
    public static function get($id) { return isset($GLOBALS['rules'][$id]) ? $GLOBALS['rules'][$id] : null; }
    public static function get_conditions($id) { return isset($GLOBALS['conditions'][$id]) ? $GLOBALS['conditions'][$id] : array(); }
    public static function get_actions($id) { return isset($GLOBALS['actions'][$id]) ? $GLOBALS['actions'][$id] : array(); }
}

class StubWpdb
{
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public $posts = 'wp_posts';
    public $term_relationships = 'wp_term_relationships';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $terms = 'wp_terms';
    public $log = array();

    public function prepare($sql, ...$args)
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = $args[0];
        }
        return preg_replace_callback('/%[dsi]/', static function ($m) use (&$args) {
            $value = array_shift($args);
            return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes((string) $value) . "'";
        }, $sql);
    }

    private static function ids_in($sql, $column)
    {
        preg_match('/' . preg_quote($column, '/') . ' IN \(([^)]*)\)/', $sql, $m);
        return array_map('intval', array_filter(array_map('trim', explode(',', $m[1]))));
    }

    public function get_col($sql)
    {
        $this->log[] = $sql;
        if (false !== strpos($sql, 'woobooster_rule_index')) {
            preg_match('/condition_key IN \((.*)\)\s+GROUP BY/s', $sql, $m);
            $keys = array_map(static function ($k) { return trim($k, " '"); }, explode(',', $m[1]));
            $ids = array();
            foreach ($GLOBALS['rules'] as $id => $rule) {
                if (array_intersect(index_keys($id), $keys)) {
                    $ids[] = $id;
                }
            }
            usort($ids, static function ($a, $b) {
                return array($GLOBALS['rules'][$a]->priority, $a) <=> array($GLOBALS['rules'][$b]->priority, $b);
            });
            return $ids;
        }
        if (false !== strpos($sql, 'tr.term_taxonomy_id IN')) { // Similar Products candidate pool.
            preg_match('/p\.ID != (\d+)/', $sql, $m);
            return array_values(array_diff(array_keys($GLOBALS['catalog']), array((int) $m[1])));
        }
        return array();
    }

    public function get_var($sql)
    {
        $this->log[] = $sql;
        return null; // SHOW TABLES: no order lookup table.
    }

    public function get_results($sql, $output = null)
    {
        $this->log[] = $sql;
        if (false !== strpos($sql, 'wp_woobooster_rules')) {
            $rows = array();
            foreach (self::ids_in($sql, 'id') as $id) {
                if (!isset($GLOBALS['rules'][$id])) {
                    continue;
                }
                $row = clone $GLOBALS['rules'][$id];
                if (false !== strpos($sql, 'wb_product_actions')) {
                    $all = 0;
                    $products = 0;
                    foreach ($GLOBALS['actions'][$id] as $group) {
                        foreach ($group as $action) {
                            $all++;
                            $products += 'apply_coupon' !== $action->action_source ? 1 : 0;
                        }
                    }
                    $row->wb_action_rows = (string) $all;
                    $row->wb_product_actions = (string) $products;
                }
                $rows[] = $row;
            }
            return $rows;
        }
        if (false !== strpos($sql, 'SELECT tt.taxonomy, t.slug, t.term_id')) {
            preg_match('/tr\.object_id = (\d+)/', $sql, $m);
            return product_terms((int) $m[1]);
        }
        if (false !== strpos($sql, 'SELECT post_id, meta_key, meta_value')) {
            $rows = array();
            foreach (self::ids_in($sql, 'post_id') as $id) {
                $rows[] = (object) array('post_id' => $id, 'meta_key' => '_price', 'meta_value' => (string) $GLOBALS['catalog'][$id]['price']);
                $rows[] = (object) array('post_id' => $id, 'meta_key' => '_stock_status', 'meta_value' => $GLOBALS['catalog'][$id]['stock']);
            }
            return $rows;
        }
        if (false !== strpos($sql, 'SELECT ID, post_date_gmt')) {
            $rows = array();
            foreach (self::ids_in($sql, 'ID') as $id) {
                $rows[] = (object) array('ID' => $id, 'post_date_gmt' => $GLOBALS['catalog'][$id]['date'] . ' 00:00:00');
            }
            return $rows;
        }
        if (false !== strpos($sql, 'SELECT tr.object_id, tt.taxonomy, t.slug')) {
            $rows = array();
            foreach (self::ids_in($sql, 'tr.object_id') as $id) {
                foreach (product_terms($id) as $term) {
                    $rows[] = (object) array('object_id' => $id, 'taxonomy' => $term['taxonomy'], 'slug' => $term['slug']);
                }
            }
            return $rows;
        }
        return array();
    }
}

$wpdb = new StubWpdb();

require dirname(__DIR__, 2) . '/modules/woobooster/includes/class-woobooster-matcher.php';

$rules = array();
$conditions = array();
$actions = array();

// 1: "Entire store → apply coupon", highest priority.
rule(1, 1, array(array(cond('__store_all', '1'))), array(array(coupon())));
// 2: handguns → ammo, plus a coupon in its own group.
rule(2, 5, array(array(cond('product_cat', 'handguns'))), array(array(act('category', 'ammo')), array(coupon())));
// 3: entire store → holsters.
rule(3, 10, array(array(cond('__store_all', '1'))), array(array(act('category', 'holsters'))));
// 4: the rifle → holsters AND a coupon in the same group.
rule(4, 2, array(array(cond('specific_product', '105'))), array(array(act('category', 'holsters'), coupon())));
// 5: one handgun → Bought Together without firearms (and their subcategories),
// without product 102, priced 15–100.
rule(5, 3, array(array(cond('specific_product', '106'))), array(array(act('copurchase', '', array(
    'exclude_categories' => 'firearms', 'exclude_products' => '102', 'exclude_price_min' => '15', 'exclude_price_max' => '100',
)))));
// 6: another handgun → Similar Products without holsters or product 101.
rule(6, 4, array(array(cond('specific_product', '111'))), array(array(act('similar', '', array(
    'exclude_categories' => 'holsters', 'exclude_products' => '101',
)))));

$hidden = array(107, 108);

/* ── Results cached before this revision are not served ───────────────── */

// What the previous version cached for product 100: the coupon rule's empty result.
$old_key = 'woobooster_rec2_v0_100_' . md5(wp_json_encode(array()));
$transients['wbrc_' . md5($old_key)] = array('ids' => array(), 'rule_id' => 1);

$matcher = new WooBooster_Matcher();

/* ── Coupon-only rules do not match product pages ─────────────────────── */

$wpdb->log = array();
$ids = $matcher->get_recommendations(100);
check(2 === (int) WooBooster_Matcher::$last_matched_rule->id, 'the coupon-only rule is skipped; the next matching rule (handguns) is used');
check(!empty($ids), 'a result cached under the previous key format is not served');
sort($ids);
check(array(101, 102) === $ids, 'the mixed rule still recommends its products (ammo), without hidden or out-of-stock ones');
$rule_queries = count(array_filter($wpdb->log, static function ($sql) { return false !== strpos($sql, 'wp_woobooster_rules'); }));
$action_queries = count(array_filter($wpdb->log, static function ($sql) {
    return false !== strpos($sql, 'woobooster_rule_actions') && false === strpos($sql, 'wp_woobooster_rules');
}));
check(1 === $rule_queries && 0 === $action_queries, 'every candidate rule and its action kinds load in one query');

$ids = $matcher->get_recommendations(103);
sort($ids);
check(3 === (int) WooBooster_Matcher::$last_matched_rule->id, 'a holster page skips the coupon rule and uses the store-wide holsters rule');
check(array(104, 110, 112) === $ids, 'holsters other than the viewed one');

$ids = $matcher->get_recommendations(105);
sort($ids);
check(4 === (int) WooBooster_Matcher::$last_matched_rule->id, 'specific-product rule matched');
check(array(103, 104, 110, 112) === $ids, 'a coupon action inside an AND group no longer empties the group');

$diagnostics = $matcher->get_diagnostics(100);
check(2 === (int) $diagnostics['matched_rule']['id'], 'the rule tester skips the coupon-only rule too');

/* ── Smart sources honour exceptions ──────────────────────────────────── */

// Pinned rule (Bricks loop with a rule picked), so these checks do not depend
// on the coupon-only rule being skipped.
$ids = $matcher->get_recommendations_by_rule(5, 106);
check(!empty($ids), 'Bought Together returns products');
check(!array_intersect($ids, array(100, 105, 106, 111)), 'Exclude Categories (firearms) also excludes its subcategory (handguns), in the ranked list and the fallback');
check(!in_array(102, $ids, true), 'Exclude Products applies to Bought Together');
check(!in_array(110, $ids, true), 'the price range applies to Bought Together');
check(!array_intersect($ids, $hidden) && !in_array(109, $ids, true), 'no hidden or out-of-stock products');
check(array(101, 103, 104) === array_slice($ids, 0, 3), 'the bought-together order is kept');
check(in_array(112, $ids, true), 'the fallback fills with products the exceptions allow');
check($ids === $matcher->get_recommendations(106) && 5 === (int) WooBooster_Matcher::$last_matched_rule->id, 'the product page matches the same rule and products');

$ids = $matcher->get_recommendations_by_rule(6, 111);
check(!empty($ids), 'Similar Products returns products');
check(!array_intersect($ids, array(103, 104, 110, 112, 101)), 'Similar Products honours Exclude Categories and Exclude Products');
check(!array_intersect($ids, $hidden), 'Similar Products leaves hidden products out');
check(6 === (int) (function () use ($matcher) { $matcher->get_recommendations(111); return WooBooster_Matcher::$last_matched_rule->id; })(), 'the product page matches the Similar Products rule');

/* ── Catalog-hidden products are never returned ───────────────────────── */

$ids = $matcher->get_smart_recommendations(106, 'copurchase', array('limit' => 20));
check(!empty($ids) && !array_intersect($ids, $hidden), 'Smart loop (Bought Together, no exceptions): no hidden products');
check(in_array(100, $ids, true) && in_array(110, $ids, true), 'without exceptions those products are back');

$ids = $matcher->execute_query(100, act('specific_products', '', array('action_products' => '107,101,108,102')), array(), product_terms(100));
check(array(101, 102) === $ids, 'Specific Products: hidden products are dropped, the chosen order is kept');
$ids = $matcher->execute_query(100, act('specific_products', '', array('action_products' => '107,101,108,102', 'exclude_products' => '102')), array(), product_terms(100));
check(array(101) === $ids, 'Specific Products: Exclude Products applies (WP_Query ignores post__not_in next to post__in)');

$ids = $matcher->execute_query(100, act('category', 'fees'), array(), product_terms(100));
check(array() === $ids, 'Category source: a hidden transfer fee is not recommended');

$options['exclude_outofstock'] = '0';
WooBooster_Matcher::invalidate_recommendation_cache();
$ids = $matcher->execute_query(100, act('category', 'ammo'), array(), product_terms(100));
sort($ids);
check(array(101, 102, 109) === $ids, 'with Exclude Out of Stock off, out-of-stock products return but hidden ones do not');

echo $checks . " checks passed (woobooster matcher filters).\n";
