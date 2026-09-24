<?php
/**
 * Offline regression checks for stored-order tax resolution: subscription
 * renewals built by cron, admin "Recalculate", FPPC final shipping.
 * No database, network, session, charges or WooCommerce install.
 * Run: php tests/smoke/tax-order-context-smoke.php
 */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['hooks'] = [];
$GLOBALS['options'] = ['woocommerce_tax_based_on' => 'shipping', 'woocommerce_store_address' => '500 Store Rd'];
$GLOBALS['users'] = [5 => ['customer'], 7 => ['wholesale']];
$GLOBALS['current_user'] = 0;
$GLOBALS['wp_cache'] = [];
$GLOBALS['cache_deletes'] = [];
$GLOBALS['matched_calls'] = 0;
$GLOBALS['quote_calls'] = [];
$GLOBALS['logs'] = [];
$GLOBALS['legacy_update_taxes'] = false;
// Native rows like a store that imported WooCommerce GA rates (ids 1/9/10/11 = 8%).
$GLOBALS['native_ga'] = [
    1  => ['rate' => 4.0, 'label' => 'GA State', 'shipping' => 'yes', 'compound' => 'no'],
    9  => ['rate' => 2.0, 'label' => 'Harris County', 'shipping' => 'yes', 'compound' => 'no'],
    10 => ['rate' => 1.0, 'label' => 'LOST', 'shipping' => 'yes', 'compound' => 'no'],
    11 => ['rate' => 1.0, 'label' => 'SPLOST', 'shipping' => 'yes', 'compound' => 'no'],
];
$GLOBALS['native_table'] = $GLOBALS['native_ga'];

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
function get_current_user_id() { return $GLOBALS['current_user']; }
function wp_doing_ajax() { return false; }
function is_admin() { return false; }
function current_user_can($cap) { return false; }
function wc_clean($value) { return is_string($value) ? trim(strip_tags($value)) : $value; }
function wc_normalize_postcode($postcode) { return preg_replace('/[\s\-]/', '', trim(strtoupper((string) $postcode))); }
function wc_get_chosen_shipping_method_ids() { return $GLOBALS['chosen_methods'] ?? []; }
function wp_cache_delete($key, $group = '') { $GLOBALS['cache_deletes'][] = $key; unset($GLOBALS['wp_cache'][$key]); return true; }
function wc_get_logger() {
    return new class {
        public function warning($message, $context = []) { $GLOBALS['logs'][] = $message; }
    };
}

class WooCommerce {}
class WC_Cache_Helper { public static function get_cache_prefix($group) { return 'wc_cache_1_'; } }
class TestSession {
    public $data = [];
    public function get($key, $default = null) { return $this->data[$key] ?? $default; }
    public function set($key, $value) { $this->data[$key] = $value; }
}
class TestCustomer {
    public $id; public $street; public $billing;
    public function __construct($id, $street, $billing = null) { $this->id = $id; $this->street = $street; $this->billing = $billing ?? $street; }
    public function get_id() { return $this->id; }
    public function get_shipping_address_1() { return $this->street; }
    public function get_billing_address_1() { return $this->billing; }
}
class TestCountries {
    public function get_base_country() { return 'US'; }
    public function get_base_state() { return 'GA'; }
    public function get_base_postcode() { return '30263'; }
    public function get_base_city() { return 'Newnan'; }
}
$GLOBALS['wc'] = (object) ['session' => null, 'customer' => null, 'countries' => new TestCountries(), 'cart' => null];
function WC() { return $GLOBALS['wc']; }

// Database-backed collaborators are replaced; the result/normalizer/role gate are real.
class Tax_Coverage {
    const UNSUPPORTED = 'UNSUPPORTED';
    const NO_SALES_TAX = 'NO_SALES_TAX';
    public static $states = ['GA', 'FL'];
    public static function is_enabled_for_store(string $state): bool { return in_array(strtoupper($state), self::$states, true); }
    public static function is_supported(string $state): bool { return in_array(strtoupper($state), self::$states, true); }
    public static function get_state(string $state): ?array { return null; }
}
class Tax_Quote_Engine {
    public static $fail = false;
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
        if (self::$fail) {
            $result->set_error(Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE, 'Resolver offline');
            return $result;
        }
        $result->source = 'usgeocoder_api';
        $result->add_breakdown('state', 'Georgia', 0.04);
        $result->add_breakdown('county', $normalized['city'] === 'NEWNAN' ? 'Coweta' : 'Harris', $normalized['zip'] === '31811' ? 0.035 : 0.04);
        $result->calculate_total();
        return $result;
    }
}

require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-quote-result.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-address-normalizer.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-role-gate.php';
require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-woocommerce-integration.php';
Tax_WooCommerce_Integration::init();

class TestItem {
    public $id; public $total; public $tax_class = ''; public $taxable = true; public $method_id = ''; public $meta = []; public $taxes = [];
    public function __construct($id, $total) { $this->id = $id; $this->total = $total; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function get_method_id() { return $this->method_id; }
    public function get_total() { return $this->total; }
}
class TestTaxItem {
    public $rate_id; public $label = ''; public $rate_code = ''; public $compound = false; public $rate_percent = 0.0; public $tax_total = 0.0; public $shipping_tax_total = 0.0;
    public function __construct($rate_id) { $this->rate_id = $rate_id; }
    public function get_rate_id() { return $this->rate_id; }
    public function set_props($props) { foreach ($props as $key => $value) { $this->$key = $value; } }
}

/** WC_Tax::find_rates() with its object cache and both filters. */
function test_find_rates(array $args): array {
    $postcode = wc_normalize_postcode(wc_clean($args['postcode']));
    $key = WC_Cache_Helper::get_cache_prefix('taxes') . 'wc_tax_rates_' . md5(sprintf('%s+%s+%s+%s+%s', $args['country'], $args['state'], $args['city'], $postcode, $args['tax_class']));
    if (!array_key_exists($key, $GLOBALS['wp_cache'])) {
        $native = $args['country'] === 'US' && $args['state'] === 'GA' && $args['tax_class'] === '' ? $GLOBALS['native_table'] : [];
        $GLOBALS['matched_calls']++;
        $GLOBALS['wp_cache'][$key] = apply_filters('woocommerce_matched_tax_rates', $native, $args['country'], $args['state'], $postcode, $args['city'], $args['tax_class']);
    }
    return apply_filters('woocommerce_find_rates', $GLOBALS['wp_cache'][$key], $args);
}
function test_rates_cache_key($country, $state, $city, $postcode, $class = '') {
    return 'wc_cache_1_wc_tax_rates_' . md5(sprintf('%s+%s+%s+%s+%s', $country, $state, $city, wc_normalize_postcode($postcode), $class));
}

class TestOrder {
    public $id; public $status = 'pending'; public $customer_id = 5; public $meta = [];
    public $items = ['line_item' => [], 'fee' => [], 'shipping' => [], 'tax' => []];
    public $shipping = ['address_1' => '123 Main St', 'city' => 'Pine Mountain', 'state' => 'GA', 'postcode' => '31822', 'country' => 'US'];
    public $billing = ['address_1' => '123 Main St', 'city' => 'Pine Mountain', 'state' => 'GA', 'postcode' => '31822', 'country' => 'US'];
    public function __construct($id) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_customer_id() { return $this->customer_id; }
    public function has_status($status) { return $this->status === $status; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function get_items($type = 'line_item') { return $this->items[$type] ?? []; }
    public function __call($name, $args) {
        if (preg_match('/^get_(shipping|billing)_(address_1|city|state|postcode|country)$/', $name, $m)) {
            return $this->{$m[1]}[$m[2]];
        }
        throw new BadMethodCallException($name);
    }
    /** WC_Abstract_Order::get_tax_location() */
    public function get_taxable_location($args = []) {
        $based_on = get_option('woocommerce_tax_based_on');
        if ($based_on === 'shipping' && !$this->shipping['country']) { $based_on = 'billing'; }
        $source = $based_on === 'billing' ? $this->billing : $this->shipping;
        $location = array_merge(['country' => $source['country'], 'state' => $source['state'], 'postcode' => $source['postcode'], 'city' => $source['city']], $args);
        $pickup = apply_filters('woocommerce_local_pickup_methods', ['legacy_local_pickup', 'local_pickup']);
        foreach ($this->items['shipping'] as $item) {
            if (in_array($item->method_id, $pickup, true) && true === apply_filters('woocommerce_apply_base_tax_for_local_pickup', true)) { $based_on = 'base'; }
        }
        if ($based_on === 'base') {
            $location = ['country' => 'US', 'state' => 'GA', 'postcode' => '30263', 'city' => 'Newnan'];
        }
        return $location;
    }
    /** WC_Abstract_Order::calculate_taxes() + update_taxes() (11.1, or 8.x/9.x via $legacy_update_taxes). */
    public function calculate_taxes($args = []) {
        do_action('woocommerce_order_before_calculate_taxes', $args, $this);
        $location = $this->get_taxable_location($args);
        $cart = [];
        $shipping = [];
        foreach (array_merge($this->items['line_item'], $this->items['fee']) as $item) {
            $item->taxes = [];
            if (!$item->taxable) { continue; }
            foreach (test_find_rates($location + ['tax_class' => $item->tax_class]) as $rate_id => $rate) {
                $item->taxes[$rate_id] = $item->total * $rate['rate'] / 100;
                $cart[$rate_id] = ($cart[$rate_id] ?? 0) + $item->taxes[$rate_id];
            }
        }
        foreach ($this->items['shipping'] as $item) {
            $item->taxes = [];
            foreach (test_find_rates($location + ['tax_class' => '']) as $rate_id => $rate) {
                if ($rate['shipping'] === 'yes') {
                    $item->taxes[$rate_id] = $item->total * $rate['rate'] / 100;
                    $shipping[$rate_id] = ($shipping[$rate_id] ?? 0) + $item->taxes[$rate_id];
                }
            }
        }
        $this->items['tax'] = [];
        foreach (array_keys($cart + $shipping) as $rate_id) {
            $tax = new TestTaxItem($rate_id);
            $db = $GLOBALS['native_table'][$rate_id] ?? null;
            $key = $db || !$GLOBALS['legacy_update_taxes'] ? $rate_id : null;
            $tax->label = apply_filters('woocommerce_rate_label', $db['label'] ?? 'Tax', $key);
            $tax->rate_code = apply_filters('woocommerce_rate_code', $db ? 'US-GA-' . $db['label'] : '', $key);
            $tax->compound = (bool) apply_filters('woocommerce_rate_compound', false, $key);
            $tax->rate_percent = (float) ($db['rate'] ?? 0);
            $tax->tax_total = $cart[$rate_id] ?? 0;
            $tax->shipping_tax_total = $shipping[$rate_id] ?? 0;
            $this->items['tax'][] = $tax;
        }
    }
    public function calculate_totals($and_taxes = true) {
        do_action('woocommerce_order_before_calculate_totals', $and_taxes, $this);
        if ($and_taxes) { $this->calculate_taxes(); }
        do_action('woocommerce_order_after_calculate_totals', $and_taxes, $this);
    }
}

$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function tax_ids($order) { return array_map(function ($tax) { return $tax->rate_id; }, $order->items['tax']); }
function tax_total($order) { return round(array_sum(array_map(function ($tax) { return $tax->tax_total + $tax->shipping_tax_total; }, $order->items['tax'])), 3); }
function reset_request() {
    $GLOBALS['wc']->session = null; $GLOBALS['wc']->customer = null; $GLOBALS['current_user'] = 0; $GLOBALS['chosen_methods'] = [];
    $GLOBALS['wp_cache'] = []; $GLOBALS['cache_deletes'] = []; $GLOBALS['matched_calls'] = 0; $GLOBALS['quote_calls'] = []; $GLOBALS['logs'] = [];
    $GLOBALS['native_table'] = $GLOBALS['native_ga']; $GLOBALS['legacy_update_taxes'] = false;
    $GLOBALS['options']['woocommerce_tax_based_on'] = 'shipping'; unset($GLOBALS['options']['ffla_tax_resolver_settings']);
    Tax_Quote_Engine::$fail = false; Tax_Role_Gate::reset_runtime_cache();
    unset($GLOBALS['hooks']['woocommerce_local_pickup_methods'], $GLOBALS['hooks']['ffla_tax_local_pickup_use_store_base'], $GLOBALS['hooks']['ffla_tax_order_context_enabled']);
}
/** Deposit quote stored at checkout, copied parent -> subscription -> renewal. */
function deposit_quote(string $street = '123 Main St', string $city = 'Pine Mountain', string $zip = '31822'): string {
    $quote = Tax_Quote_Engine::quote(['street' => $street, 'city' => $city, 'state' => 'GA', 'zip' => $zip])->to_array();
    array_pop($GLOBALS['quote_calls']);
    return wp_json_encode($quote);
}
function renewal(int $id = 370188): TestOrder {
    $order = new TestOrder($id);
    $order->meta = ['_ffla_tax_quote' => deposit_quote(), '_ffla_tax_query_id' => 'deposit-query', '_ffla_tax_source' => 'usgeocoder_api', '_fppc_managed_plan' => 'yes', '_subscription_renewal' => 369900];
    $line = new TestItem(1, 50.00); $line->meta['_fppc_component_key'] = 'line-abc';
    $order->items['line_item'] = [1 => $line];
    return $order;
}

// 1. Cron renewal: no session, no customer, user 0. Stored deposit quote wins over the native table.
reset_request();
$order = renewal();
$stored_before = $order->meta['_ffla_tax_quote'];
$order->calculate_totals(true);
check(tax_ids($order) === [990000], 'Renewal uses the synthetic checkout rate, not native rows 1/9/10/11');
check(tax_total($order) === 4.0, 'Renewal collects 8% on the installment');
$tax = $order->items['tax'][0];
check($tax->label === 'Sales Tax' && $tax->rate_code === 'US-GA-FFLA-TOTAL', 'Label/code resolved without a session');
check($tax->rate_percent === 8.0 && $tax->compound === false, 'Rate percent written onto the order tax line');
check(count($GLOBALS['quote_calls']) === 0, 'Stored quote reused without a live API call');
check($order->meta['_ffla_tax_quote'] === $stored_before, 'Stored deposit quote left untouched');
check($order->meta['_ffla_tax_query_id'] === 'deposit-query', 'Reused quote does not rewrite audit meta');
check(!isset($GLOBALS['wp_cache'][test_rates_cache_key('US', 'GA', 'Pine Mountain', '31822')]), 'Order-derived find_rates cache entry is dropped when the frame closes');
check(in_array(test_rates_cache_key('US', 'GA', 'Pine Mountain', '31822'), $GLOBALS['cache_deletes'], true), 'Cache deletion uses the WC_Tax::find_rates key');

// 2. Store with no native tax table: installments still collect tax.
reset_request();
$GLOBALS['native_table'] = [];
$order = renewal();
$order->calculate_totals(true);
check(tax_ids($order) === [990000] && tax_total($order) === 4.0, 'Empty native table no longer yields $0 renewal tax');

// 3. FPPC final shipping fee (fee item, standard class) is taxed on the last renewal.
reset_request();
$GLOBALS['native_table'] = [];
$order = renewal();
$fee = new TestItem(2, 24.95); $fee->meta['_fppc_final_shipping_fee'] = 'yes';
$order->items['fee'] = [2 => $fee];
$order->calculate_totals(true);
check(round($fee->taxes[990000] ?? 0, 3) === 1.996, 'Final shipping fee taxed at the plan rate');
check(tax_total($order) === 5.996, 'Installment plus final shipping tax');

// 4. A stale native cache entry for the same location cannot leak into the renewal.
reset_request();
$GLOBALS['wp_cache'][test_rates_cache_key('US', 'GA', 'Pine Mountain', '31822')] = $GLOBALS['native_ga'];
$order = renewal();
$order->calculate_totals(true);
check($GLOBALS['matched_calls'] === 0, 'Fixture really served the lookup from cache');
check(tax_ids($order) === [990000], 'woocommerce_find_rates applies the order context on cache hits');

// 5. Address changed after checkout: re-quote once, store the new quote like checkout does.
reset_request();
$order = renewal();
$order->shipping = ['address_1' => '9 Oak Ave', 'city' => 'Hamilton', 'state' => 'GA', 'postcode' => '31811', 'country' => 'US'];
$order->items['shipping'] = [3 => new TestItem(3, 10.00)];
$order->items['fee'] = [2 => new TestItem(2, 5.00)];
$order->calculate_totals(true);
check(count($GLOBALS['quote_calls']) === 1, 'One live quote for all item/shipping lookups');
check($GLOBALS['quote_calls'][0]['street'] === '9 Oak Ave' && $GLOBALS['quote_calls'][0]['zip'] === '31811', 'Quote uses the order address WooCommerce taxed');
$stored = json_decode($order->meta['_ffla_tax_quote'], true);
check($stored['normalizedAddress']['zip'] === '31811' && $order->meta['_ffla_tax_query_id'] === $stored['queryId'], 'New quote stored on the order');
check($order->items['tax'][0]->rate_percent === 7.5 && tax_total($order) === 4.875, 'Rates from the new quote');
$order->calculate_totals(true);
check(count($GLOBALS['quote_calls']) === 1, 'Next recalculation reuses the newly stored quote');

// 6. A billing street is never paired with a different shipping ZIP.
reset_request();
$order = renewal();
$order->meta['_ffla_tax_quote'] = '';
$order->shipping['address_1'] = '';
$order->billing = ['address_1' => '77 Elsewhere Rd', 'city' => 'Atlanta', 'state' => 'GA', 'postcode' => '30301', 'country' => 'US'];
$order->calculate_totals(true);
check($GLOBALS['quote_calls'][0]['street'] === '' && tax_ids($order) === [1, 9, 10, 11], 'No Frankenstein address; invalid quote keeps WooCommerce rates');
check(count($GLOBALS['logs']) === 1, 'Unattended fallback to native rates is logged');

// 7. Local pickup detected from the order's shipping lines, taxed at the store base.
foreach (['local_pickup', 'pickup_location', 'legacy_local_pickup'] as $method) {
    reset_request();
    $order = renewal();
    $pickup = new TestItem(3, 0.00); $pickup->method_id = $method;
    $order->items['shipping'] = [3 => $pickup];
    $order->calculate_totals(true);
    check(count($GLOBALS['quote_calls']) === 1 && $GLOBALS['quote_calls'][0]['street'] === '500 Store Rd'
        && $GLOBALS['quote_calls'][0]['zip'] === '30263' && $GLOBALS['quote_calls'][0]['city'] === 'Newnan', "Pickup ($method) pinned to the store base address");
    check(tax_ids($order) === [990000], "Pickup ($method) uses the synthetic rate");
}
reset_request();
add_filter('ffla_tax_local_pickup_use_store_base', function () { return false; });
$order = renewal();
$pickup = new TestItem(3, 0.00); $pickup->method_id = 'pickup_location';
$order->items['shipping'] = [3 => $pickup];
$order->calculate_totals(true);
check(count($GLOBALS['quote_calls']) === 0, 'Multi-location opt-out keeps WooCommerce\'s own pickup location');

// 8. Role gate evaluates the order's customer, not the (cron) current user.
reset_request();
$GLOBALS['options']['ffla_tax_resolver_settings'] = ['tax_role_restrict' => '1', 'tax_exempt_roles' => ['wholesale']];
$order = renewal();
$order->customer_id = 7;
$order->calculate_totals(true);
check(tax_ids($order) === [] && tax_total($order) === 0.0, 'Exempt customer renewal collects no tax in cron');
check(($order->meta['_ffla_tax_full_order_exempt'] ?? '') === 'yes', 'Exemption evidence recorded on the renewal');
check(json_decode($order->meta['_ffla_tax_full_order_exempt_context'], true)['user_id'] === 7, 'Evidence names the order customer');
check(Tax_Role_Gate::should_charge_for_current_customer() === true, 'Current request (guest) would have been charged');
reset_request();
$GLOBALS['options']['ffla_tax_resolver_settings'] = ['tax_role_restrict' => '1', 'tax_exempt_roles' => ['wholesale']];
$GLOBALS['wc']->customer = new TestCustomer(7, 'Admin St');
$order = renewal();
$order->meta['_ffla_tax_full_order_exempt'] = 'yes';
$order->calculate_totals(true);
check(Tax_Role_Gate::should_charge_for_current_customer() === false, 'Admin session user is exempt');
check(tax_ids($order) === [990000] && !isset($order->meta['_ffla_tax_full_order_exempt']), 'Taxable order customer is charged; stale copied flag cleared');

// 9. Non-plan order without a stored quote keeps the previous (session) behavior.
reset_request();
$order = new TestOrder(500);
$order->items['line_item'] = [1 => new TestItem(1, 50.00)];
$order->calculate_totals(true);
check(tax_ids($order) === [1, 9, 10, 11], 'Unchanged: native fallback when cron has no session');
check(count($GLOBALS['quote_calls']) === 1 && $GLOBALS['quote_calls'][0]['street'] === '', 'Unchanged: previous session-path lookup');
check(!isset($order->meta['_ffla_tax_quote']), 'No quote written to non-plan orders');

// 10. Checkout drafts stay on the session path (Store API recalculates its draft order).
foreach (['checkout-draft', 'session-draft', 'awaiting-payment'] as $case) {
    reset_request();
    $GLOBALS['wc']->session = new TestSession();
    $GLOBALS['wc']->customer = new TestCustomer(5, '42 Checkout Ln');
    $order = renewal(900);
    if ($case === 'checkout-draft') { $order->status = 'checkout-draft'; }
    if ($case === 'session-draft') { $GLOBALS['wc']->session->set('store_api_draft_order', 900); }
    if ($case === 'awaiting-payment') { $GLOBALS['wc']->session->set('order_awaiting_payment', 900); }
    $before = $order->meta;
    $order->calculate_totals(true);
    check(count($GLOBALS['quote_calls']) === 1 && $GLOBALS['quote_calls'][0]['street'] === '42 Checkout Ln', "Checkout order ($case) quoted from the session customer");
    check(isset($GLOBALS['wc']->session->data['ffla_last_tax_quote'], $GLOBALS['wc']->session->data['ffla_runtime_tax_rates']), "Checkout order ($case) updates the session like before");
    check($order->meta === $before, "Checkout order ($case) meta untouched by the order context");
}

// 11. Admin session metadata from another destination cannot mislabel a renewal.
reset_request();
$GLOBALS['wc']->session = new TestSession();
$GLOBALS['wc']->session->set('ffla_runtime_tax_rates', ['990000' => ['label' => 'Sales Tax', 'code' => 'US-FL-FFLA-TOTAL', 'rate' => 7.0, 'compound' => false]]);
$order = renewal();
$order->calculate_totals(true);
check($order->items['tax'][0]->rate_code === 'US-GA-FFLA-TOTAL' && $order->items['tax'][0]->rate_percent === 8.0, 'Order context metadata beats a stale admin session');
check($GLOBALS['wc']->session->data['ffla_runtime_tax_rates']['990000']['code'] === 'US-FL-FFLA-TOTAL', 'Administrator session left untouched');

// 12. WooCommerce 8.x/9.x update_taxes() passes no key for DB-less rates; tax lines are still labelled.
reset_request();
$GLOBALS['legacy_update_taxes'] = true;
$order = renewal();
$order->calculate_totals(true);
$tax = $order->items['tax'][0];
check($tax->label === 'Sales Tax' && $tax->rate_code === 'US-GA-FFLA-TOTAL' && $tax->rate_percent === 8.0, 'Legacy update_taxes path finalized after totals');

// 13. A stored failed quote is not reused; a failing live quote keeps WooCommerce rates and is logged.
reset_request();
$order = renewal();
$failed = json_decode($order->meta['_ffla_tax_quote'], true);
$failed['outcomeCode'] = 'SOURCE_UNAVAILABLE';
$order->meta['_ffla_tax_quote'] = wp_json_encode($failed);
$order->calculate_totals(true);
check(count($GLOBALS['quote_calls']) === 1 && tax_ids($order) === [990000], 'Failed checkout quote is re-quoted');
reset_request();
Tax_Quote_Engine::$fail = true;
$order = renewal();
$order->shipping['address_1'] = '9 Oak Ave';
$order->calculate_totals(true);
check(tax_ids($order) === [1, 9, 10, 11] && count($GLOBALS['logs']) === 1, 'Live failure falls back like checkout and leaves a log');
check(json_decode($order->meta['_ffla_tax_quote'], true)['outcomeCode'] === 'SOURCE_UNAVAILABLE', 'Failed quote recorded for audit, as checkout does');

// 14. Guards: custom tax classes and non-US destinations keep WooCommerce rates.
reset_request();
$order = renewal();
$order->items['line_item'][1]->tax_class = 'reduced-rate';
$order->items['fee'] = [2 => new TestItem(2, 10.00)];
$order->calculate_totals(true);
check(empty($order->items['line_item'][1]->taxes) && isset($order->items['fee'][2]->taxes[990000]), 'Only the standard class is overridden');
reset_request();
$order = renewal();
$order->shipping['country'] = 'CA'; $order->shipping['state'] = 'ON';
$order->calculate_totals(true);
check(tax_ids($order) === [] && count($GLOBALS['quote_calls']) === 0, 'Non-US destination untouched');

// 15. Admin "Recalculate": calculate_taxes(posted location) + calculate_totals(false).
reset_request();
$order = renewal();
$order->calculate_taxes(['country' => 'US', 'state' => 'GA', 'postcode' => '31822', 'city' => 'PINE MOUNTAIN']);
$order->calculate_totals(false);
check(tax_ids($order) === [990000] && $order->items['tax'][0]->rate_percent === 8.0, 'Admin recalculation uses the stored quote');

// 16. A bare calculate_taxes() frame never reaches a later cart calculation.
reset_request();
$order = renewal();
$order->calculate_taxes();
do_action('woocommerce_before_calculate_totals', null);
$rates = test_find_rates(['country' => 'US', 'state' => 'GA', 'postcode' => '31822', 'city' => 'Pine Mountain', 'tax_class' => '']);
check(array_keys($rates) === [1, 9, 10, 11], 'Cart calculation resets orphaned order frames');

// 17. Plan detection without a stored quote (renewal line component keys) and opt-out filter.
reset_request();
$order = renewal();
unset($order->meta['_ffla_tax_quote'], $order->meta['_fppc_managed_plan']);
$order->calculate_totals(true);
check(count($GLOBALS['quote_calls']) === 1 && tax_ids($order) === [990000] && isset($order->meta['_ffla_tax_quote']), 'Plan renewal without a stored quote is quoted from the order');
reset_request();
add_filter('ffla_tax_order_context_enabled', function () { return false; });
$order = renewal();
$order->calculate_totals(true);
check(tax_ids($order) === [1, 9, 10, 11], 'ffla_tax_order_context_enabled can opt an order out');

echo "$checks stored-order tax context checks passed.\n";
