<?php
/**
 * Standalone smoke checks for the tax-holiday and Merchant policy engines.
 * Run: php tests/smoke/tax-policy-smoke.php
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['ffla_smoke_options'] = [];
$GLOBALS['ffla_smoke_post_meta'] = [];
$GLOBALS['ffla_smoke_term_meta'] = [];
$GLOBALS['ffla_smoke_product_terms'] = [];
$GLOBALS['ffla_smoke_terms'] = [];

function get_option($key, $default = false) { return $GLOBALS['ffla_smoke_options'][$key] ?? $default; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wc_format_decimal($value) { return number_format((float) $value, 2, '.', ''); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }
function wp_timezone() { return new DateTimeZone('UTC'); }
function is_wp_error($value) { return false; }
function apply_filters($hook, $value) { return $value; }
function __($text, $domain = null) { return $text; }
function wp_parse_args($args, $defaults = []) { return array_merge($defaults, (array) $args); }
function wp_strip_all_tags($text) { return strip_tags((string) $text); }
function get_post_meta($post_id, $key, $single = false) { return $GLOBALS['ffla_smoke_post_meta'][$post_id][$key] ?? ''; }
function get_term_meta($term_id, $key, $single = false) { return $GLOBALS['ffla_smoke_term_meta'][$term_id][$key] ?? ''; }
function get_term($term_id, $taxonomy = '') { return $GLOBALS['ffla_smoke_terms'][$term_id] ?? null; }
function get_ancestors($term_id, $taxonomy = '', $type = '') { return $term_id === 20 ? [10] : []; }
function wp_get_post_terms($product_id, $taxonomy, $args = []) {
    $ids = $GLOBALS['ffla_smoke_product_terms'][$product_id][$taxonomy] ?? [];
    if (($args['fields'] ?? '') === 'names') {
        return array_map(function ($id) { return $GLOBALS['ffla_smoke_terms'][$id]->name; }, $ids);
    }
    return $ids;
}

class FFLA_Smoke_Product
{
    private $id;
    private $name;
    private $price;
    private $parent;

    public function __construct($id, $name, $price = 0, $parent = 0)
    {
        $this->id = $id;
        $this->name = $name;
        $this->price = $price;
        $this->parent = $parent;
    }

    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent; }
    public function get_price() { return $this->price; }
    public function get_name() { return $this->name; }
    public function get_short_description() { return ''; }
}

function ffla_smoke_assert($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-holiday-engine.php';
require_once __DIR__ . '/../../modules/google-merchant-policy/includes/class-google-merchant-policy-engine.php';

$GLOBALS['ffla_smoke_terms'][10] = (object) ['term_id' => 10, 'parent' => 0, 'name' => 'Apparel', 'slug' => 'apparel'];
$GLOBALS['ffla_smoke_terms'][20] = (object) ['term_id' => 20, 'parent' => 10, 'name' => 'Shirts', 'slug' => 'shirts'];
$GLOBALS['ffla_smoke_product_terms'][101]['product_cat'] = [20];
$GLOBALS['ffla_smoke_product_terms'][101]['product_tag'] = [];

$GLOBALS['ffla_smoke_options'][Tax_Holiday_Engine::SETTINGS_KEY] = [
    'tax_holidays_enabled' => '1',
    'tax_holiday_rules' => [[
        'id' => 'holiday-test',
        'name' => 'Test Holiday',
        'enabled' => '1',
        'start_at' => '2026-01-01T00:00',
        'end_at' => '2026-12-31T23:59',
        'states' => ['GA'],
        'scope' => 'selected',
        'product_ids' => [],
        'category_ids' => [10],
        'tag_ids' => [],
        'price_limit' => '100.00',
        'shipping_mode' => 'proportional',
    ]],
];
Tax_Holiday_Engine::reset_runtime_cache();
$shirt = new FFLA_Smoke_Product(101, 'Shirt', 50);
$active_time = (new DateTimeImmutable('2026-09-03T12:00:00Z'))->getTimestamp();
ffla_smoke_assert(count(Tax_Holiday_Engine::get_matching_rules_for_product($shirt, 'GA', $active_time)) === 1, 'Parent category should include its child.');
ffla_smoke_assert(count(Tax_Holiday_Engine::get_matching_rules_for_product($shirt, 'FL', $active_time)) === 0, 'State restriction should be enforced.');
$expensive = new FFLA_Smoke_Product(102, 'Expensive shirt', 150);
$GLOBALS['ffla_smoke_product_terms'][102]['product_cat'] = [20];
$GLOBALS['ffla_smoke_product_terms'][102]['product_tag'] = [];
ffla_smoke_assert(count(Tax_Holiday_Engine::get_matching_rules_for_product($expensive, 'GA', $active_time)) === 0, 'Price ceiling should be enforced.');

$GLOBALS['ffla_smoke_options'][Google_Merchant_Policy_Engine::OPTION] = Google_Merchant_Policy_Engine::default_settings();
$GLOBALS['ffla_smoke_term_meta'][10][Google_Merchant_Policy_Engine::TERM_META] = 'allow';
Google_Merchant_Policy_Engine::reset_runtime_cache();
ffla_smoke_assert(Google_Merchant_Policy_Engine::evaluate_product($shirt)['status'] === 'allowed', 'Child category should inherit Allow.');

$rifle = new FFLA_Smoke_Product(103, 'Precision Rifle', 900);
$GLOBALS['ffla_smoke_product_terms'][103]['product_cat'] = [20];
Google_Merchant_Policy_Engine::reset_runtime_cache();
ffla_smoke_assert(Google_Merchant_Policy_Engine::evaluate_product($rifle)['status'] === 'blocked', 'Restricted firearm content must override Allow.');

$case = new FFLA_Smoke_Product(104, 'Rifle Case', 80);
$GLOBALS['ffla_smoke_product_terms'][104]['product_cat'] = [20];
Google_Merchant_Policy_Engine::reset_runtime_cache();
ffla_smoke_assert(Google_Merchant_Policy_Engine::evaluate_product($case)['status'] === 'allowed', 'A carrying case should not be blocked only because its title says rifle.');

$ammo = new FFLA_Smoke_Product(105, 'Range Supply', 25);
$GLOBALS['ffla_smoke_product_terms'][105]['product_cat'] = [20];
$GLOBALS['ffla_smoke_post_meta'][105]['_ammunition_product'] = 'yes';
Google_Merchant_Policy_Engine::reset_runtime_cache();
ffla_smoke_assert(Google_Merchant_Policy_Engine::evaluate_product($ammo)['status'] === 'blocked', 'Ammunition metadata must override Allow.');

echo "Tax and Merchant policy smoke checks passed.\n";
