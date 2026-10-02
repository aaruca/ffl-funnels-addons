<?php
/**
 * Offline regression checks for the Sales Tax Resolver checkout path:
 * per-customer decisions are applied after WooCommerce's rate cache, exempt
 * customers pay no tax on any tax class, "Shop base address" quotes the store
 * street, streets are never paired with another address's ZIP, zero-tax
 * states are quoted, and a stale session quote is never stored on an order.
 * No database, network, session, charges or WooCommerce install.
 * Run: php tests/smoke/tax-resolver-fixes-smoke.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['hooks'] = [];
$GLOBALS['options'] = ['woocommerce_tax_based_on' => 'shipping', 'woocommerce_store_address' => '500 Store Rd'];
$GLOBALS['users'] = [5 => ['customer'], 7 => ['wholesale']];
$GLOBALS['wp_cache'] = [];
$GLOBALS['quote_calls'] = [];
$GLOBALS['logs'] = [];
$GLOBALS['native'] = [
    ''             => [1 => ['rate' => 4.0, 'label' => 'GA State', 'shipping' => 'yes', 'compound' => 'no']],
    'reduced-rate' => [2 => ['rate' => 1.0, 'label' => 'GA Reduced', 'shipping' => 'no', 'compound' => 'no']],
];

function add_filter($hook, $callback, $priority = 10, $accepted = 1) { $GLOBALS['hooks'][$hook][$priority][] = [$callback, $accepted]; }
function add_action($hook, $callback, $priority = 10, $accepted = 1) { add_filter($hook, $callback, $priority, $accepted); }
function apply_filters($hook, $value, ...$args) {
    if (empty($GLOBALS['hooks'][$hook])) { return $value; }
    ksort($GLOBALS['hooks'][$hook]);
    foreach ($GLOBALS['hooks'][$hook] as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $value = $callback(...array_slice(array_merge([$value], $args), 0, $accepted));
        }
    }
    return $value;
}
function do_action($hook, ...$args) {
    if (empty($GLOBALS['hooks'][$hook])) { return; }
    ksort($GLOBALS['hooks'][$hook]);
    foreach ($GLOBALS['hooks'][$hook] as $callbacks) {
        foreach ($callbacks as [$callback, $accepted]) {
            $callback(...array_slice($args, 0, $accepted));
        }
    }
}
function __($text, $domain = null) { return $text; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function wp_json_encode($value) { return json_encode($value); }
function wp_generate_uuid4() { static $n = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$n); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function get_userdata($id) { return isset($GLOBALS['users'][$id]) ? (object) ['roles' => $GLOBALS['users'][$id]] : false; }
function get_current_user_id() { return 0; }
function wp_doing_ajax() { return false; }
function is_admin() { return false; }
function current_user_can($cap) { return false; }
function wc_clean($value) { return is_string($value) ? trim(strip_tags($value)) : $value; }
function wc_normalize_postcode($postcode) { return preg_replace('/[\s\-]/', '', trim(strtoupper((string) $postcode))); }
function wc_get_chosen_shipping_method_ids() { return $GLOBALS['chosen_methods'] ?? []; }
function wp_cache_delete($key, $group = '') { unset($GLOBALS['wp_cache'][$key]); return true; }
function wc_get_logger() {
    return new class {
        public function log($level, $message, $context = []) { $GLOBALS['logs'][] = [$level, $message]; }
        public function warning($message, $context = []) { $GLOBALS['logs'][] = ['warning', $message]; }
    };
}

class WooCommerce {}
class WC_Cache_Helper { public static function get_cache_prefix($group) { return 'wc_cache_1_'; } }
class TestSession {
    public $data = [];
    public function get($key, $default = null) { return $this->data[$key] ?? $default; }
    public function set($key, $value) { $this->data[$key] = $value; }
}
/** WC_Customer with billing/shipping getters. */
class TestCustomer {
    public $id; public $shipping; public $billing;
    public function __construct($id, array $shipping, ?array $billing = null) { $this->id = $id; $this->shipping = $shipping; $this->billing = $billing ?? $shipping; }
    public function get_id() { return $this->id; }
    public function get_shipping_address_1() { return $this->shipping['address_1'] ?? ''; }
    public function get_shipping_state() { return $this->shipping['state'] ?? ''; }
    public function get_shipping_postcode() { return $this->shipping['postcode'] ?? ''; }
    public function get_billing_address_1() { return $this->billing['address_1'] ?? ''; }
    public function get_billing_state() { return $this->billing['state'] ?? ''; }
    public function get_billing_postcode() { return $this->billing['postcode'] ?? ''; }
}
class TestCountries {
    public function get_base_country() { return 'US'; }
    public function get_base_state() { return 'GA'; }
    public function get_base_postcode() { return '30263'; }
    public function get_base_city() { return 'Newnan'; }
}
$GLOBALS['wc'] = (object) ['session' => null, 'customer' => null, 'countries' => new TestCountries(), 'cart' => null];
function WC() { return $GLOBALS['wc']; }

class Tax_Coverage {
    const UNSUPPORTED = 'UNSUPPORTED';
    const NO_SALES_TAX = 'NO_SALES_TAX';
    public static $supported = ['GA', 'FL', 'OR'];
    public static function is_enabled_for_store(string $state): bool { return true; }
    public static function is_supported(string $state): bool { return in_array(strtoupper($state), self::$supported, true); }
}
class Tax_Quote_Engine {
    public static function quote(array $input): Tax_Quote_Result {
        $GLOBALS['quote_calls'][] = $input;
        $normalized = Tax_Address_Normalizer::normalize($input);
        if (!$normalized['valid']) {
            return Tax_Quote_Result::validation_error($input, $normalized['errors']);
        }
        $result = new Tax_Quote_Result();
        $result->inputAddress = $input;
        $result->normalizedAddress = $normalized;
        $result->state = $normalized['state'];
        if ($normalized['state'] === 'OR') {
            // Imported zero-tax state.
            $result->outcomeCode = Tax_Quote_Result::OUTCOME_NO_SALES_TAX;
            $result->totalRate = 0.0;
            return $result;
        }
        $result->source = 'sheet_zip_dataset';
        $result->add_breakdown('state', 'State', 0.04);
        $result->add_breakdown('county', 'County', $normalized['zip'] === '30263' ? 0.03 : 0.04);
        $result->calculate_total();
        return $result;
    }
}

require_once __DIR__ . '/../../modules/tax-rates/includes/functions-tax-log.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-quote-result.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-address-normalizer.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-role-gate.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-woocommerce-integration.php';
Tax_WooCommerce_Integration::init();

/** WC_Tax::find_rates() with its object cache and both filters. */
function find_rates(string $state, string $postcode, string $city, string $class = '', string $country = 'US'): array {
    $args = ['country' => $country, 'state' => $state, 'postcode' => $postcode, 'city' => $city, 'tax_class' => $class];
    $key = 'wc_cache_1_wc_tax_rates_' . md5(sprintf('%s+%s+%s+%s+%s', $country, $state, $city, wc_normalize_postcode($postcode), $class));
    if (!array_key_exists($key, $GLOBALS['wp_cache'])) {
        $native = $country === 'US' ? ($GLOBALS['native'][$class] ?? []) : [];
        $GLOBALS['wp_cache'][$key] = apply_filters('woocommerce_matched_tax_rates', $native, $country, $state, wc_normalize_postcode($postcode), $city, $class);
    }
    return apply_filters('woocommerce_find_rates', $GLOBALS['wp_cache'][$key], $args);
}

class TestOrder {
    public $meta = []; public $shipping; public $billing; public $customer_id = 5; public $items = ['shipping' => []];
    public function __construct(array $shipping) { $this->shipping = $shipping; $this->billing = $shipping; }
    public function get_id() { return 0; }
    public function get_customer_id() { return $this->customer_id; }
    public function has_status($status) { return false; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function get_items($type = 'line_item') { return $this->items[$type] ?? []; }
    public function __call($name, $args) {
        if (preg_match('/^get_(shipping|billing)_(address_1|city|state|postcode|country)$/', $name, $m)) {
            return $this->{$m[1]}[$m[2]] ?? '';
        }
        throw new BadMethodCallException($name);
    }
    public function get_taxable_location($args = []) {
        return ['country' => $this->shipping['country'], 'state' => $this->shipping['state'], 'postcode' => $this->shipping['postcode'], 'city' => $this->shipping['city']];
    }
}

$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function new_request(array $settings = []) {
    $GLOBALS['wc']->session = new TestSession();
    $GLOBALS['wc']->customer = null;
    $GLOBALS['chosen_methods'] = [];
    $GLOBALS['wp_cache'] = $GLOBALS['persistent_cache'] ?? [];
    $GLOBALS['quote_calls'] = [];
    $GLOBALS['options']['woocommerce_tax_based_on'] = 'shipping';
    $GLOBALS['options']['ffla_tax_resolver_settings'] = $settings;
    Tax_Role_Gate::reset_runtime_cache();
    do_action('woocommerce_before_calculate_totals', null);
}
$ga_home = ['address_1' => '123 Main St', 'state' => 'GA', 'postcode' => '31822', 'city' => 'Pine Mountain', 'country' => 'US'];

// 1. The customer's own rate is never written to WooCommerce's (shared) rate cache.
new_request();
$GLOBALS['wc']->customer = new TestCustomer(5, $ga_home);
$rates = find_rates('GA', '31822', 'Pine Mountain');
check(array_keys($rates) === [990000] && $rates[990000]['rate'] === 8.0, 'Checkout gets the resolver rate');
$cached = array_values($GLOBALS['wp_cache'])[0];
check(!isset($cached[990000]) && isset($cached[1]), 'Rate cache keeps WooCommerce rates, not the customer-specific synthetic rate');
$GLOBALS['persistent_cache'] = $GLOBALS['wp_cache'];

// 2. Another (exempt) customer at the same ZIP, served from a persistent cache.
new_request(['tax_role_restrict' => '1', 'tax_exempt_roles' => ['wholesale']]);
$GLOBALS['wc']->customer = new TestCustomer(7, $ga_home);
check(find_rates('GA', '31822', 'Pine Mountain') === [], 'Exempt customer pays no tax even when the location is cached');
check(find_rates('GA', '31822', 'Pine Mountain', 'reduced-rate') === [], 'Exempt customer pays no tax on a reduced-rate class');
check($GLOBALS['quote_calls'] === [], 'No quote for an exempt customer');

// 3. The next taxable customer at the same ZIP is charged again (no cross-customer leak).
new_request(['tax_role_restrict' => '1', 'tax_exempt_roles' => ['wholesale']]);
$GLOBALS['wc']->customer = new TestCustomer(5, $ga_home);
check(array_keys(find_rates('GA', '31822', 'Pine Mountain')) === [990000], 'Taxable customer after an exempt one is charged');
check(array_keys(find_rates('GA', '31822', 'Pine Mountain', 'reduced-rate')) === [2], 'Reduced-rate class keeps WooCommerce rates for a taxable customer');
$GLOBALS['persistent_cache'] = [];

// 4. A synthetic rate cached by an older version is never handed out as native.
$key = 'wc_cache_1_wc_tax_rates_' . md5('US+GA+Pine Mountain+31822+reduced-rate');
$GLOBALS['persistent_cache'] = [$key => [990000 => ['rate' => 8.0, 'label' => 'Sales Tax', 'shipping' => 'yes', 'compound' => 'no'], 2 => $GLOBALS['native']['reduced-rate'][2]]];
new_request();
$GLOBALS['wc']->customer = new TestCustomer(5, $ga_home);
check(array_keys(find_rates('GA', '31822', 'Pine Mountain', 'reduced-rate')) === [2], 'Stale synthetic rate stripped from cached native rates');
$GLOBALS['persistent_cache'] = [];

// 5. One quote per destination and calculation, however many lines ask.
new_request();
$GLOBALS['wc']->customer = new TestCustomer(5, $ga_home);
for ($i = 0; $i < 5; $i++) { find_rates('GA', '31822', 'Pine Mountain'); }
check(count($GLOBALS['quote_calls']) === 1, 'Lines of one calculation share one quote');
do_action('woocommerce_before_calculate_totals', null);
find_rates('GA', '31822', 'Pine Mountain');
check(count($GLOBALS['quote_calls']) === 2, 'A new calculation quotes again');

// 6. "Shop base address": the store street is quoted (a ZIP-only quote is invalid).
new_request();
$GLOBALS['options']['woocommerce_tax_based_on'] = 'base';
$GLOBALS['wc']->customer = new TestCustomer(5, $ga_home);
$rates = find_rates('GA', '30263', 'Newnan');
check($GLOBALS['quote_calls'][0]['street'] === '500 Store Rd' && $GLOBALS['quote_calls'][0]['zip'] === '30263', 'Base basis quotes the store address');
check(array_keys($rates) === [990000] && $rates[990000]['rate'] === 7.0, 'Base basis applies the resolver rate');

// 7. The store-base lookup for tax-inclusive prices never borrows the customer's street.
new_request();
$GLOBALS['wc']->customer = new TestCustomer(5, $ga_home);
find_rates('GA', '30263', 'Newnan');
check($GLOBALS['quote_calls'][0]['street'] === '500 Store Rd', 'Base-rate lookup uses the store street, not the customer street');
new_request();
$GLOBALS['wc']->customer = new TestCustomer(5, ['address_1' => '', 'state' => 'GA', 'postcode' => '31822'], ['address_1' => '77 Elsewhere Rd', 'state' => 'GA', 'postcode' => '30301']);
find_rates('GA', '31822', 'Pine Mountain');
check($GLOBALS['quote_calls'][0]['street'] === '', 'Billing street is not paired with the shipping ZIP');
new_request();
$GLOBALS['options']['woocommerce_tax_based_on'] = 'billing';
$GLOBALS['wc']->customer = new TestCustomer(5, $ga_home, ['address_1' => '77 Elsewhere Rd', 'state' => 'GA', 'postcode' => '30301']);
find_rates('GA', '30301', 'Atlanta');
check($GLOBALS['quote_calls'][0]['street'] === '77 Elsewhere Rd', 'Billing basis uses the billing street');

// 8. Zero-tax states are quoted and return no tax instead of WooCommerce's table.
new_request();
$GLOBALS['wc']->customer = new TestCustomer(5, ['address_1' => '1 Elm St', 'state' => 'OR', 'postcode' => '97201']);
check(find_rates('OR', '97201', 'Portland') === [], 'NO_SALES_TAX state resolves to zero tax');

// 9. Only the order's own destination quote is stored on the order.
new_request();
$GLOBALS['wc']->customer = new TestCustomer(5, $ga_home);
find_rates('GA', '31822', 'Pine Mountain');
find_rates('GA', '30263', 'Newnan'); // base-rate lookup overwrites the "last" quote
$order = new TestOrder($ga_home + ['country' => 'US']);
do_action('woocommerce_checkout_create_order', $order, []);
$stored = json_decode($order->meta['_ffla_tax_quote'] ?? '', true);
check(($stored['normalizedAddress']['zip'] ?? '') === '31822', 'Order stores the quote of its own destination, not the base lookup');

new_request();
$GLOBALS['wc']->customer = new TestCustomer(5, $ga_home);
find_rates('GA', '31822', 'Pine Mountain');
$order = new TestOrder(['address_1' => '9 Rue', 'state' => 'ON', 'postcode' => 'K1A0B1', 'city' => 'Ottawa', 'country' => 'CA']);
$order->meta = ['_ffla_tax_quote' => '{"state":"GA"}', '_ffla_tax_query_id' => 'old', '_ffla_tax_source' => 'x'];
do_action('woocommerce_checkout_create_order', $order, []);
check(!isset($order->meta['_ffla_tax_quote']) && !isset($order->meta['_ffla_tax_query_id']), 'Non-US order gets no stale quote; an earlier attempt\'s quote is removed');

new_request(['tax_role_restrict' => '1', 'tax_exempt_roles' => ['wholesale']]);
$GLOBALS['wc']->session->set('ffla_last_tax_quote', ['state' => 'FL', 'inputAddress' => ['zip' => '33101'], 'queryId' => 'fl']);
$GLOBALS['wc']->customer = new TestCustomer(7, $ga_home);
find_rates('GA', '31822', 'Pine Mountain');
$order = new TestOrder($ga_home + ['country' => 'US']);
do_action('woocommerce_checkout_create_order', $order, []);
check(!isset($order->meta['_ffla_tax_quote']), 'Exempt checkout never stores an earlier destination\'s quote');

// 10. Stored order recalculation: exempt customer pays nothing on any class.
new_request(['tax_role_restrict' => '1', 'tax_exempt_roles' => ['wholesale']]);
$order = new TestOrder($ga_home + ['country' => 'US']);
$order->customer_id = 7;
$order->meta['_ffla_tax_quote'] = json_encode(['state' => 'GA', 'outcomeCode' => 'SUCCESS']);
do_action('woocommerce_order_before_calculate_taxes', [], $order);
check(find_rates('GA', '31822', 'Pine Mountain', 'reduced-rate') === [], 'Exempt renewal pays no reduced-rate tax');
check(($order->meta['_ffla_tax_full_order_exempt'] ?? '') === 'yes', 'Exemption evidence recorded on the order');
do_action('woocommerce_order_after_calculate_totals', true, $order);

// 11. Logging helper exists and writes through the WooCommerce logger.
$GLOBALS['logs'] = [];
ffla_tax_log('nonsense-level', 'Smoke log', ['a' => 1]);
check(function_exists('ffla_tax_log') && $GLOBALS['logs'][0][0] === 'notice' && strpos($GLOBALS['logs'][0][1], 'Smoke log') === 0, 'ffla_tax_log() is defined and logs');

echo "$checks resolver regression checks passed.\n";
