<?php
/**
 * WooCommerce tax integration.
 *
 * Makes the tax resolver the source of truth during WooCommerce cart and
 * checkout tax calculation by overriding matched rates for supported US states,
 * and during stored-order recalculation (renewals, admin Recalculate) from the
 * order's own address, customer and stored quote.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_WooCommerce_Integration
{
    /** Synthetic WooCommerce rate ID used for the combined resolver rate. */
    private const RUNTIME_RATE_ID = 990000;

    /**
     * Stored-order tax recalculations in progress, innermost last.
     *
     * WooCommerce also recalculates taxes outside checkout: subscription
     * renewals built by cron/Action Scheduler, admin "Recalculate", payment
     * plan sizing (FPPC final shipping, early payoff) and REST updates. Those
     * requests have no customer session, so each frame carries the order whose
     * taxes are being calculated. A frame opens on
     * woocommerce_order_before_calculate_taxes and closes on
     * woocommerce_order_after_calculate_totals, which WooCommerce always runs
     * after calculate_taxes() (directly, or via admin "Recalculate").
     *
     * @var array<int,array<string,mixed>>
     */
    private static $order_contexts = [];

    /**
     * Register WooCommerce hooks.
     */
    public static function init(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_order_before_calculate_taxes', [__CLASS__, 'begin_order_tax_context'], 1, 2);
        add_action('woocommerce_order_after_calculate_totals', [__CLASS__, 'end_order_tax_context'], 0, 2);
        add_filter('woocommerce_find_rates', [__CLASS__, 'filter_find_rates_for_order_context'], 20, 2);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'reset_order_tax_contexts'], 0, 0);
        add_filter('woocommerce_matched_tax_rates', [__CLASS__, 'filter_matched_tax_rates'], 20, 6);
        add_filter('woocommerce_product_is_taxable', [__CLASS__, 'filter_product_is_taxable'], 20, 2);
        add_filter('woocommerce_calc_shipping_tax', [__CLASS__, 'filter_shipping_taxes_for_holidays'], 20, 3);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'prime_cart_product_terms'], 5, 1);
        add_filter('woocommerce_rate_label', [__CLASS__, 'filter_runtime_rate_label'], 10, 2);
        add_filter('woocommerce_rate_code', [__CLASS__, 'filter_runtime_rate_code'], 10, 2);
        add_filter('woocommerce_rate_compound', [__CLASS__, 'filter_runtime_rate_compound'], 10, 2);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'store_order_tax_quote'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'store_line_item_exemption'], 20, 4);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'store_order_exemption_summary'], 20, 2);
        add_action('woocommerce_store_api_checkout_update_order_meta', [__CLASS__, 'store_api_order_exemptions'], 20, 1);
        add_action('woocommerce_checkout_create_order_tax_item', [__CLASS__, 'decorate_runtime_order_tax_item'], 10, 3);
    }

    /**
     * Make only products matched by a conditional exemption rule non-taxable.
     *
     * This hook is supported by classic checkout and Checkout Blocks. It runs
     * before WooCommerce builds line taxes, while shipping remains independently
     * taxable. The legacy full-order gate still operates in matched-tax-rates.
     *
     * @param bool  $taxable Current WooCommerce product taxability.
     * @param mixed $product WC_Product instance.
     */
    public static function filter_product_is_taxable($taxable, $product): bool
    {
        if (!$taxable) {
            return (bool) $taxable;
        }

        // Product editing screens should always display the stored tax status;
        // AJAX/REST cart calculations still pass through the rule engine.
        $doing_ajax = function_exists('wp_doing_ajax')
            ? wp_doing_ajax()
            : (defined('DOING_AJAX') && DOING_AJAX);
        if (is_admin() && !$doing_ajax) {
            return (bool) $taxable;
        }

        $audience_exempt = class_exists('Tax_Role_Gate')
            && Tax_Role_Gate::is_active()
            && Tax_Role_Gate::should_exempt_product($product);
        $holiday_exempt = class_exists('Tax_Holiday_Engine')
            && Tax_Holiday_Engine::is_active()
            && Tax_Holiday_Engine::should_exempt_product($product);

        return !($audience_exempt || $holiday_exempt);
    }

    /**
     * Apply merchant-selected shipping behavior during an active holiday.
     *
     * @param array $taxes Calculated shipping taxes by rate ID.
     * @return array
     */
    public static function filter_shipping_taxes_for_holidays($taxes, $price, $rates): array
    {
        if (!is_array($taxes) || !class_exists('Tax_Holiday_Engine')) {
            return is_array($taxes) ? $taxes : [];
        }

        $fraction = Tax_Holiday_Engine::get_shipping_exempt_fraction();
        if ($fraction <= 0) {
            return $taxes;
        }

        $taxable_fraction = max(0.0, 1.0 - $fraction);
        foreach ($taxes as $rate_id => $amount) {
            $taxes[$rate_id] = (float) $amount * $taxable_fraction;
        }
        return $taxes;
    }

    /**
     * Prime product taxonomy caches in one batch before per-line evaluation.
     *
     * This avoids category/tag database queries per cart item on stores with
     * many conditional rules. Variations are represented by their parent ID.
     */
    public static function prime_cart_product_terms($cart): void
    {
        $has_audience_rules = class_exists('Tax_Role_Gate') && Tax_Role_Gate::is_active()
            && !empty(Tax_Role_Gate::get_conditional_rules(true));
        $has_holiday_rules = class_exists('Tax_Holiday_Engine') && Tax_Holiday_Engine::is_active();
        if ((!$has_audience_rules && !$has_holiday_rules)
            || !is_object($cart) || !method_exists($cart, 'get_cart')) {
            return;
        }

        $product_ids = [];
        foreach ($cart->get_cart() as $cart_item) {
            $product = is_array($cart_item) ? ($cart_item['data'] ?? null) : null;
            if (!is_object($product) || !method_exists($product, 'get_id')) {
                continue;
            }
            $parent_id = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
            $product_id = $parent_id > 0 ? $parent_id : (int) $product->get_id();
            if ($product_id > 0) {
                $product_ids[$product_id] = $product_id;
            }
        }

        if (!empty($product_ids) && function_exists('update_object_term_cache')) {
            update_object_term_cache(array_values($product_ids), 'product');
        }
    }

    /**
     * Override WooCommerce matched rates for supported US destinations.
     *
     * @param  array  $matched_tax_rates Rates from WooCommerce core.
     * @param  string $country  Country code.
     * @param  string $state    State code.
     * @param  string $postcode Postal code.
     * @param  string $city     City.
     * @param  string $tax_class Tax class slug.
     * @return array
     */
    public static function filter_matched_tax_rates(
        $matched_tax_rates,
        $country,
        $state,
        $postcode,
        $city,
        $tax_class
    ): array {
        if (!is_array($matched_tax_rates)) {
            return [];
        }

        // Stored-order recalculation (renewal, admin Recalculate, plan sizing):
        // resolve from the order itself, never from a session or current user.
        $order_context = self::active_order_context($country, $state, $postcode, $city);
        if (null !== $order_context) {
            self::remember_rate_lookup($order_context, $country, $state, $postcode, $city, $tax_class);
            return self::order_context_rates($order_context, $matched_tax_rates, (string) $tax_class);
        }

        $country = strtoupper((string) $country);
        $state = strtoupper((string) $state);
        $postcode = (string) $postcode;
        $city = (string) $city;
        $tax_class = (string) $tax_class;

        // Local pickup is taxed at the store's own address, never the customer's.
        // WooCommerce core only forces the base address for pickup conditionally
        // (WC_Customer::get_taxable_address, and only when its filters + the
        // chosen method line up), and even when it does it leaves the customer's
        // street on the customer object — which build_address_input would then
        // read and pair with the store ZIP (a "Frankenstein" address). So we
        // detect pickup ourselves and pin the WHOLE address (country/state/zip/
        // city + street) to the store base HERE, before the coverage gates
        // evaluate $state.
        //
        // Single-location stores want exactly this. A multi-location Blocks
        // store where WooCommerce already resolved the SPECIFIC pickup location
        // can return false from ffla_tax_local_pickup_use_store_base to keep
        // core's per-location address instead.
        $pickup_street = null;
        if (self::is_local_pickup_selected()
            && apply_filters('ffla_tax_local_pickup_use_store_base', true)) {
            $base = self::store_base_address();
            if ('' !== $base['state'] && '' !== $base['country']) {
                $country       = $base['country'];
                $state         = $base['state'];
                $postcode      = $base['zip'];
                $city          = $base['city'];
                $pickup_street = $base['street'];
            }
        }

        if ($country !== 'US') {
            return $matched_tax_rates;
        }

        if ($state === '') {
            return $matched_tax_rates;
        }

        // This resolver models general goods rates; leave custom tax classes alone.
        if ($tax_class !== '') {
            return $matched_tax_rates;
        }

        // Customer/role tax gate: an explicit customer ID or exempt role
        // returns an empty rate set. Wipe runtime tax meta so stale synthetic
        // rates from an earlier request cannot leak through.
        if (class_exists('Tax_Role_Gate') && Tax_Role_Gate::is_active()
            && !Tax_Role_Gate::should_charge_for_current_customer()) {
            self::store_runtime_tax_meta([]);
            return [];
        }

        if (!Tax_Coverage::is_enabled_for_store($state)) {
            self::store_runtime_tax_meta([]);
            return $matched_tax_rates;
        }

        if (!Tax_Coverage::is_supported($state)) {
            return $matched_tax_rates;
        }

        $input = self::build_address_input($state, $postcode, $city, $pickup_street);
        if (empty($input['street']) && empty($input['zip'])) {
            return $matched_tax_rates;
        }

        try {
            $quote = Tax_Quote_Engine::quote($input);
        } catch (\Throwable $e) {
            if (function_exists('ffla_tax_log')) {
                ffla_tax_log('error', 'WooCommerce tax override failed', [
                    'state'   => $state,
                    'city'    => $city,
                    'zip'     => $postcode,
                    'message' => $e->getMessage(),
                ]);
            }

            return $matched_tax_rates;
        }

        if (function_exists('WC') && WC()->session) {
            WC()->session->set('ffla_last_tax_quote', $quote->to_array());
        }

        if (!$quote->is_success()) {
            self::store_runtime_tax_meta([]);
            return $matched_tax_rates;
        }

        return self::build_wc_rates_from_quote($quote, $tax_class);
    }

    /**
     * Store the last tax quote on the order for auditability.
     */
    public static function store_order_tax_quote($order, array $data): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        $quote = WC()->session->get('ffla_last_tax_quote');
        if (is_array($quote)) {
            self::write_order_tax_quote($order, $quote);
        }
    }

    /**
     * Persist quote evidence on an order (checkout and order recalculation).
     */
    private static function write_order_tax_quote($order, array $quote): void
    {
        if (empty($quote['state'])) {
            return;
        }

        $order->update_meta_data('_ffla_tax_quote', wp_json_encode($quote));
        $order->update_meta_data('_ffla_tax_query_id', $quote['queryId'] ?? '');
        $order->update_meta_data('_ffla_tax_source', $quote['source'] ?? '');
    }

    /**
     * Persist the exact conditional-rule match on each exempt order line.
     *
     * The snapshot keeps historical tax reports stable after a rule is renamed,
     * disabled, or deleted. Leading-underscore metadata stays out of normal
     * customer-facing item meta displays.
     */
    public static function store_line_item_exemption($item, $cart_item_key, $values, $order): void
    {
        $product = is_array($values) ? ($values['data'] ?? null) : null;
        $audience_matches = class_exists('Tax_Role_Gate') && Tax_Role_Gate::is_active()
            ? Tax_Role_Gate::get_matching_rules_for_product($product)
            : [];
        $holiday_matches = class_exists('Tax_Holiday_Engine') && Tax_Holiday_Engine::is_active()
            ? Tax_Holiday_Engine::get_matching_rules_for_product($product)
            : [];
        $matches = array_merge($audience_matches, $holiday_matches);
        if (empty($matches)) {
            return;
        }

        $rule_ids = [];
        $rule_names = [];
        foreach ($matches as $match) {
            $rule_ids[] = (string) ($match['id'] ?? '');
            $rule_names[] = (string) ($match['name'] ?? '');
        }
        $rule_ids = array_values(array_filter(array_unique($rule_ids)));
        $rule_names = array_values(array_filter(array_unique($rule_names)));

        $item->add_meta_data('_ffla_tax_exempt', 'yes', true);
        $item->add_meta_data('_ffla_tax_exemption_rule_ids', implode(', ', $rule_ids), true);
        $item->add_meta_data('_ffla_tax_exemption_rule_names', implode(', ', $rule_names), true);
        $item->add_meta_data('_ffla_tax_exemption_snapshot', wp_json_encode($matches), true);
        $item->add_meta_data('_ffla_tax_exemption_type', !empty($holiday_matches) && !empty($audience_matches) ? 'audience+holiday' : (!empty($holiday_matches) ? 'holiday' : 'audience'), true);
        if (!empty($audience_matches)) {
            $audience_names = array_values(array_filter(array_unique(array_map(function ($match) {
                return (string) ($match['name'] ?? '');
            }, $audience_matches))));
            $item->add_meta_data('_ffla_tax_audience_rule_names', implode(', ', $audience_names), true);
        }
        if (!empty($holiday_matches)) {
            $holiday_ids = array_values(array_filter(array_unique(array_map(function ($match) {
                return (string) ($match['id'] ?? '');
            }, $holiday_matches))));
            $holiday_names = array_values(array_filter(array_unique(array_map(function ($match) {
                return (string) ($match['name'] ?? '');
            }, $holiday_matches))));
            $item->add_meta_data('_ffla_tax_holiday_rule_ids', implode(', ', $holiday_ids), true);
            $item->add_meta_data('_ffla_tax_holiday_rule_names', implode(', ', $holiday_names), true);
            $item->add_meta_data('_ffla_tax_holiday_snapshot', wp_json_encode($holiday_matches), true);
        }
    }

    /**
     * Store an order-level summary while retaining product-line granularity.
     */
    public static function store_order_exemption_summary($order, array $data): void
    {
        if (!is_object($order)) {
            return;
        }

        $order->delete_meta_data('_ffla_tax_full_order_exempt');
        $order->delete_meta_data('_ffla_tax_full_order_exempt_context');
        $order->delete_meta_data('_ffla_conditional_tax_exempt_items');
        $order->delete_meta_data('_ffla_conditional_tax_exempt_sales');
        $order->delete_meta_data('_ffla_conditional_tax_exemption_rules');
        $order->delete_meta_data('_ffla_tax_holiday_exempt_items');
        $order->delete_meta_data('_ffla_tax_holiday_exempt_sales');
        $order->delete_meta_data('_ffla_tax_holiday_exempt_shipping');
        $order->delete_meta_data('_ffla_tax_holiday_rules');
        $order->delete_meta_data('_ffla_tax_holiday_snapshot');
        foreach ($order->get_items('shipping') as $shipping_item) {
            $was_holiday = (string) $shipping_item->get_meta('_ffla_tax_exemption_type', true) === 'holiday';
            $shipping_item->delete_meta_data('_ffla_tax_holiday_exempt_amount');
            $shipping_item->delete_meta_data('_ffla_tax_holiday_rule_names');
            $shipping_item->delete_meta_data('_ffla_tax_holiday_snapshot');
            if ($was_holiday) {
                $shipping_item->delete_meta_data('_ffla_tax_exempt');
                $shipping_item->delete_meta_data('_ffla_tax_exemption_type');
            }
            if (method_exists($shipping_item, 'get_id') && $shipping_item->get_id() > 0) {
                $shipping_item->save();
            }
        }

        if (class_exists('Tax_Role_Gate') && Tax_Role_Gate::is_active()
            && !Tax_Role_Gate::should_charge_for_current_customer()) {
            $context = Tax_Role_Gate::get_current_customer_context();
            $order->update_meta_data('_ffla_tax_full_order_exempt', 'yes');
            $order->update_meta_data('_ffla_tax_full_order_exempt_context', wp_json_encode($context));
        }

        $count = 0;
        $sales = 0.0;
        $rule_names = [];
        $holiday_count = 0;
        $holiday_sales = 0.0;
        $holiday_rule_names = [];
        $holiday_snapshots = [];
        foreach ($order->get_items('line_item') as $item) {
            if (strtolower((string) $item->get_meta('_ffla_tax_exempt', true)) !== 'yes') {
                continue;
            }
            $type = (string) $item->get_meta('_ffla_tax_exemption_type', true);
            if ($type === '' || strpos($type, 'audience') !== false) {
                $count++;
                $sales += (float) $item->get_total();
                $stored_names = (string) $item->get_meta('_ffla_tax_audience_rule_names', true);
                if ($stored_names === '') {
                    $stored_names = (string) $item->get_meta('_ffla_tax_exemption_rule_names', true);
                }
                $names = array_map('trim', explode(',', $stored_names));
                foreach ($names as $name) {
                    if ($name !== '') {
                        $rule_names[$name] = true;
                    }
                }
            }

            if ((string) $item->get_meta('_ffla_tax_holiday_snapshot', true) !== '') {
                $holiday_count++;
                $holiday_sales += (float) $item->get_total();
                $names = array_map('trim', explode(',', (string) $item->get_meta('_ffla_tax_holiday_rule_names', true)));
                foreach ($names as $name) {
                    if ($name !== '') {
                        $holiday_rule_names[$name] = true;
                    }
                }
                $snapshot = json_decode((string) $item->get_meta('_ffla_tax_holiday_snapshot', true), true);
                foreach (is_array($snapshot) ? $snapshot : [] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $key = (string) ($entry['id'] ?? md5(wp_json_encode($entry)));
                    $holiday_snapshots[$key] = $entry;
                }
            }
        }

        if ($count > 0) {
            $order->update_meta_data('_ffla_conditional_tax_exempt_items', $count);
            $order->update_meta_data(
                '_ffla_conditional_tax_exempt_sales',
                function_exists('wc_format_decimal') ? wc_format_decimal($sales) : number_format($sales, 2, '.', '')
            );
            $order->update_meta_data('_ffla_conditional_tax_exemption_rules', implode(', ', array_keys($rule_names)));
        }

        if ($holiday_count > 0) {
            $shipping_fraction = class_exists('Tax_Holiday_Engine') ? Tax_Holiday_Engine::get_shipping_exempt_fraction() : 0.0;
            $shipping_exempt = max(0.0, (float) $order->get_shipping_total() * $shipping_fraction);
            $order->update_meta_data('_ffla_tax_holiday_exempt_items', $holiday_count);
            $order->update_meta_data('_ffla_tax_holiday_exempt_sales', function_exists('wc_format_decimal') ? wc_format_decimal($holiday_sales) : number_format($holiday_sales, 2, '.', ''));
            $order->update_meta_data('_ffla_tax_holiday_exempt_shipping', function_exists('wc_format_decimal') ? wc_format_decimal($shipping_exempt) : number_format($shipping_exempt, 2, '.', ''));
            $order->update_meta_data('_ffla_tax_holiday_rules', implode(', ', array_keys($holiday_rule_names)));
            $order->update_meta_data('_ffla_tax_holiday_snapshot', wp_json_encode(array_values($holiday_snapshots)));

            $shipping_total = max(0.0, (float) $order->get_shipping_total());
            foreach ($order->get_items('shipping') as $shipping_item) {
                $line_total = max(0.0, (float) $shipping_item->get_total());
                $line_exempt = $shipping_total > 0 ? $shipping_exempt * ($line_total / $shipping_total) : 0.0;
                $shipping_item->update_meta_data('_ffla_tax_holiday_exempt_amount', function_exists('wc_format_decimal') ? wc_format_decimal($line_exempt) : number_format($line_exempt, 2, '.', ''));
                $shipping_item->update_meta_data('_ffla_tax_holiday_rule_names', implode(', ', array_keys($holiday_rule_names)));
                $shipping_item->update_meta_data('_ffla_tax_holiday_snapshot', wp_json_encode(array_values($holiday_snapshots)));
                if ($line_total > 0 && $line_exempt >= $line_total - 0.00001) {
                    $shipping_item->update_meta_data('_ffla_tax_exempt', 'yes');
                    $shipping_item->update_meta_data('_ffla_tax_exemption_type', 'holiday');
                }
                if (method_exists($shipping_item, 'get_id') && $shipping_item->get_id() > 0) {
                    $shipping_item->save();
                }
            }
        }
    }

    /**
     * Persist exemption evidence for Cart/Checkout Blocks (Store API).
     *
     * Store API checkout does not fire the classic create-order line/meta
     * hooks, so update the already-created draft order items before payment.
     */
    public static function store_api_order_exemptions($order): void
    {
        if (!is_object($order) || !class_exists('Tax_Role_Gate')) {
            return;
        }

        foreach ($order->get_items('line_item') as $item) {
            $item->delete_meta_data('_ffla_tax_exempt');
            $item->delete_meta_data('_ffla_tax_exemption_rule_ids');
            $item->delete_meta_data('_ffla_tax_exemption_rule_names');
            $item->delete_meta_data('_ffla_tax_exemption_snapshot');
            $item->delete_meta_data('_ffla_tax_exemption_type');
            $item->delete_meta_data('_ffla_tax_audience_rule_names');
            $item->delete_meta_data('_ffla_tax_holiday_rule_ids');
            $item->delete_meta_data('_ffla_tax_holiday_rule_names');
            $item->delete_meta_data('_ffla_tax_holiday_snapshot');

            $audience_matches = class_exists('Tax_Role_Gate') && Tax_Role_Gate::is_active()
                ? Tax_Role_Gate::get_matching_rules_for_product($item->get_product())
                : [];
            $holiday_matches = class_exists('Tax_Holiday_Engine') && Tax_Holiday_Engine::is_active()
                ? Tax_Holiday_Engine::get_matching_rules_for_product($item->get_product())
                : [];
            $matches = array_merge($audience_matches, $holiday_matches);
            if (!empty($matches)) {
                $rule_ids = [];
                $rule_names = [];
                foreach ($matches as $match) {
                    $rule_ids[] = (string) ($match['id'] ?? '');
                    $rule_names[] = (string) ($match['name'] ?? '');
                }
                $item->update_meta_data('_ffla_tax_exempt', 'yes');
                $item->update_meta_data('_ffla_tax_exemption_rule_ids', implode(', ', array_values(array_filter(array_unique($rule_ids)))));
                $item->update_meta_data('_ffla_tax_exemption_rule_names', implode(', ', array_values(array_filter(array_unique($rule_names)))));
                $item->update_meta_data('_ffla_tax_exemption_snapshot', wp_json_encode($matches));
                $item->update_meta_data('_ffla_tax_exemption_type', !empty($holiday_matches) && !empty($audience_matches) ? 'audience+holiday' : (!empty($holiday_matches) ? 'holiday' : 'audience'));
                if (!empty($audience_matches)) {
                    $audience_names = array_values(array_filter(array_unique(array_map(function ($match) {
                        return (string) ($match['name'] ?? '');
                    }, $audience_matches))));
                    $item->update_meta_data('_ffla_tax_audience_rule_names', implode(', ', $audience_names));
                }
                if (!empty($holiday_matches)) {
                    $holiday_ids = array_values(array_filter(array_unique(array_map(function ($match) {
                        return (string) ($match['id'] ?? '');
                    }, $holiday_matches))));
                    $holiday_names = array_values(array_filter(array_unique(array_map(function ($match) {
                        return (string) ($match['name'] ?? '');
                    }, $holiday_matches))));
                    $item->update_meta_data('_ffla_tax_holiday_rule_ids', implode(', ', $holiday_ids));
                    $item->update_meta_data('_ffla_tax_holiday_rule_names', implode(', ', $holiday_names));
                    $item->update_meta_data('_ffla_tax_holiday_snapshot', wp_json_encode($holiday_matches));
                }
            }
            $item->save();
        }

        self::store_order_tax_quote($order, []);
        self::store_order_exemption_summary($order, []);
        $order->save();
    }

    /**
     * Override tax labels for runtime-only tax IDs.
     */
    public static function filter_runtime_rate_label(string $label, $tax_rate_id): string
    {
        $runtime_rate = self::get_runtime_rate_meta($tax_rate_id);
        return $runtime_rate['label'] ?? $label;
    }

    /**
     * Provide non-empty tax codes for runtime-only tax IDs.
     */
    public static function filter_runtime_rate_code(string $code, $tax_rate_id): string
    {
        $runtime_rate = self::get_runtime_rate_meta($tax_rate_id);
        return $runtime_rate['code'] ?? $code;
    }

    /**
     * Respect the runtime compound flag when Woo asks about the tax rate ID.
     */
    public static function filter_runtime_rate_compound(bool $compound, $tax_rate_id): bool
    {
        $runtime_rate = self::get_runtime_rate_meta($tax_rate_id);
        if (!isset($runtime_rate['compound'])) {
            return $compound;
        }

        return (bool) $runtime_rate['compound'];
    }

    /**
     * Populate order tax items with the runtime tax metadata Woo can't fetch from DB.
     */
    public static function decorate_runtime_order_tax_item($item, $tax_rate_id, $order): void
    {
        $runtime_rate = self::get_runtime_rate_meta($tax_rate_id);
        if (empty($runtime_rate)) {
            return;
        }

        $item->set_props([
            'rate_code'    => $runtime_rate['code'] ?? '',
            'label'        => $runtime_rate['label'] ?? '',
            'compound'     => !empty($runtime_rate['compound']),
            'rate_percent' => isset($runtime_rate['rate']) ? (float) $runtime_rate['rate'] : 0.0,
        ]);
    }

    /**
     * Build address input for the quote engine from the active customer.
     *
     * WooCommerce already resolved the taxable location before calling this
     * filter: $state/$postcode/$city come from WC_Customer::get_taxable_address(),
     * which honours the `woocommerce_tax_based_on` option and the customer's
     * "Ship to a different address" choice. Those are authoritative.
     *
     * Only the street line has to be fetched separately, and it MUST come from
     * the same address WooCommerce taxed — otherwise we geocode one address's
     * street against another address's ZIP and land in the wrong jurisdiction.
     *
     * We deliberately do NOT read the street from $_POST. WooCommerce serializes
     * the hidden shipping_* inputs into the posted checkout form even when "Ship
     * to a different address" is unchecked, so a street the customer typed and
     * then abandoned would beat the billing address they actually intend. Reading
     * WC()->customer avoids that: WooCommerce syncs billing into the customer's
     * shipping fields whenever ship-to-different is off, so the shipping getter
     * already returns the billing street in the same-address case.
     */
    private static function build_address_input(string $state, string $postcode, string $city, ?string $forced_street = null): array
    {
        $input = [
            'street' => '',
            'city'   => $city,
            'state'  => $state,
            'zip'    => $postcode,
        ];

        // Local pickup: the whole address is already pinned to the store base
        // by the caller. Use the store's own street (empty is fine — the caller
        // only bails when street AND zip are both empty) and never read the
        // customer's street.
        if (null !== $forced_street) {
            $input['street'] = $forced_street;
            return $input;
        }

        if (!function_exists('WC') || !WC()->customer) {
            return $input;
        }

        $customer = WC()->customer;
        $based_on = get_option('woocommerce_tax_based_on', 'shipping');

        if ('billing' === $based_on) {
            $input['street'] = (string) $customer->get_billing_address_1();
        } elseif ('base' === $based_on) {
            // Store base address — there is no per-customer street. Leaving it
            // empty lets the resolver fall back to ZIP-level matching; the caller
            // only bails when street AND zip are both empty.
            $input['street'] = '';
        } else {
            // 'shipping' (WooCommerce default).
            $input['street'] = (string) $customer->get_shipping_address_1();
            if ('' === $input['street']) {
                $input['street'] = (string) $customer->get_billing_address_1();
            }
        }

        return $input;
    }

    /**
     * Whether the customer has chosen a Local Pickup shipping method.
     *
     * Mirrors WooCommerce core's own test in WC_Customer::get_taxable_address():
     * the woocommerce_apply_base_tax_for_local_pickup filter must be on, and a
     * chosen shipping-method id must be in woocommerce_local_pickup_methods.
     * Honouring the same filters means the Blocks "pickup_location" method is
     * included whenever the Blocks Local Pickup feature registered it.
     *
     * Session-based (the cart/checkout flow). Stored-order recalculation reads
     * the order's shipping lines instead — see order_has_local_pickup().
     */
    private static function is_local_pickup_selected(): bool
    {
        if (!function_exists('wc_get_chosen_shipping_method_ids')) {
            return false;
        }

        if (!apply_filters('woocommerce_apply_base_tax_for_local_pickup', true)) {
            return false;
        }

        $chosen = wc_get_chosen_shipping_method_ids();
        if (empty($chosen) || !is_array($chosen)) {
            return false;
        }

        $pickup_methods = apply_filters('woocommerce_local_pickup_methods', ['legacy_local_pickup', 'local_pickup']);
        if (!is_array($pickup_methods)) {
            return false;
        }

        return count(array_intersect($chosen, $pickup_methods)) > 0;
    }

    /**
     * The store's own base address, used to tax local-pickup orders.
     *
     * State / ZIP / city come from the WooCommerce base-location settings; the
     * street from the store address (WooCommerce → Settings → General) so the
     * geocoder resolves the store's exact rooftop instead of falling back to
     * ZIP-level matching.
     *
     * @return array{country:string,state:string,zip:string,city:string,street:string}
     */
    private static function store_base_address(): array
    {
        $countries = (function_exists('WC') && WC()->countries) ? WC()->countries : null;

        return [
            'country' => $countries ? strtoupper((string) $countries->get_base_country()) : '',
            'state'   => $countries ? strtoupper((string) $countries->get_base_state()) : '',
            'zip'     => $countries ? (string) $countries->get_base_postcode() : '',
            'city'    => $countries ? (string) $countries->get_base_city() : '',
            'street'  => (string) get_option('woocommerce_store_address', ''),
        ];
    }

    /**
     * Convert a tax quote result into a single combined WooCommerce rate.
     *
     * The resolver may return a multi-jurisdiction breakdown (state + county +
     * city + special district). WooCommerce renders one tax line per matched
     * rate, so we sum the breakdown into a single rate to show the customer one
     * "Sales Tax" line at checkout. The full jurisdiction breakdown is still
     * preserved on the order via store_order_tax_quote() for auditing.
     */
    private static function build_wc_rates_from_quote(Tax_Quote_Result $quote, string $tax_class): array
    {
        [$rates, $runtime_meta] = self::synthetic_rates_from_quote($quote);
        self::store_runtime_tax_meta($runtime_meta);

        return $rates;
    }

    /**
     * The single synthetic rate and its runtime metadata for a quote.
     *
     * Shared by checkout and stored-order recalculation so every payment of a
     * split sale carries the same rate ID, label and code.
     *
     * @return array{0:array,1:array} Matched WooCommerce rates, runtime metadata.
     */
    private static function synthetic_rates_from_quote(Tax_Quote_Result $quote): array
    {
        $total_rate = 0.0;
        foreach ($quote->breakdown as $item) {
            $total_rate += (float) ($item['rate'] ?? 0);
        }

        if (empty($quote->breakdown)) {
            $total_rate = (float) $quote->totalRate;
        }

        $rate_percent = (float) number_format($total_rate * 100, 4, '.', '');
        if ($rate_percent <= 0) {
            return [[], []];
        }

        $rate_id = self::RUNTIME_RATE_ID;
        $label   = __('Sales Tax', 'ffl-funnels-addons');
        $code    = sprintf('US-%s-FFLA-TOTAL', strtoupper((string) $quote->state));

        $rates = [
            $rate_id => [
                'rate'     => $rate_percent,
                'label'    => $label,
                'shipping' => 'yes',
                'compound' => 'no',
            ],
        ];

        $runtime_meta = [
            (string) $rate_id => [
                'id'       => $rate_id,
                'label'    => $label,
                'code'     => $code,
                'rate'     => $rate_percent,
                'compound' => false,
                'state'    => $quote->state,
                'type'     => 'tax',
            ],
        ];

        return [$rates, $runtime_meta];
    }

    /**
     * Store runtime-only tax metadata for later cart/order presentation hooks.
     */
    private static function store_runtime_tax_meta(array $runtime_meta): void
    {
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('ffla_runtime_tax_rates', $runtime_meta);
        }
    }

    /**
     * Get runtime-only tax metadata for a specific synthetic tax rate ID.
     */
    private static function get_runtime_rate_meta($tax_rate_id): array
    {
        $key = (string) $tax_rate_id;

        // An order recalculation owns the rates it just returned. It takes
        // precedence over the session, which in admin AJAX belongs to the
        // administrator and may hold another destination's metadata.
        $order_runtime = self::active_order_context_runtime_meta();
        if (isset($order_runtime[$key]) && is_array($order_runtime[$key])) {
            return $order_runtime[$key];
        }

        if (!function_exists('WC') || !WC()->session) {
            return [];
        }

        $runtime_rates = WC()->session->get('ffla_runtime_tax_rates');
        if (!is_array($runtime_rates)) {
            return [];
        }

        return isset($runtime_rates[$key]) && is_array($runtime_rates[$key])
            ? $runtime_rates[$key]
            : [];
    }

    /*
    |--------------------------------------------------------------------------
    | Stored-order tax context
    |--------------------------------------------------------------------------
    */

    /**
     * Open a frame when WooCommerce starts calculating an order's taxes.
     *
     * @param mixed $args  Location overrides passed to calculate_taxes().
     * @param mixed $order WC_Order or subclass.
     */
    public static function begin_order_tax_context($args, $order): void
    {
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return;
        }

        // A repeated calculation of the same order replaces its earlier frame.
        $index = self::find_order_context_index($order);
        if (null !== $index) {
            self::close_order_contexts_from($index);
        }

        $enabled = self::order_context_applies($order);
        $location = $enabled ? self::order_tax_location($order, is_array($args) ? $args : []) : [];

        self::$order_contexts[] = [
            'order'    => $order,
            'enabled'  => $enabled && ($location['country'] ?? '') !== '',
            'location' => $location,
            'match'    => $enabled ? self::location_signature(
                $location['country'] ?? '',
                $location['state'] ?? '',
                $location['postcode'] ?? '',
                $location['city'] ?? ''
            ) : '',
            'resolved' => false,
            'rates'    => null,
            'runtime'  => [],
            'lookups'  => [],
        ];
    }

    /**
     * Close the order's frame after WooCommerce totals the order.
     *
     * WC_Tax::get_rate_percent_value() has no filter, so update_taxes() leaves
     * the synthetic tax line at 0%; older WooCommerce versions also pass no
     * rate key to the label/code filters. The runtime metadata is written onto
     * the tax lines here, before calculate_totals() saves the order.
     *
     * @param mixed $and_taxes Whether calculate_totals() also calculated taxes.
     * @param mixed $order     WC_Order or subclass.
     */
    public static function end_order_tax_context($and_taxes, $order): void
    {
        $index = self::find_order_context_index($order);
        if (null === $index) {
            return;
        }

        $frame = self::$order_contexts[$index];
        self::close_order_contexts_from($index);

        if (empty($frame['enabled']) || empty($frame['runtime']) || !method_exists($order, 'get_items')) {
            return;
        }

        foreach ($order->get_items('tax') as $item) {
            $runtime_rate = $frame['runtime'][(string) $item->get_rate_id()] ?? null;
            if (!is_array($runtime_rate)) {
                continue;
            }
            $item->set_props([
                'rate_code'    => $runtime_rate['code'] ?? '',
                'label'        => $runtime_rate['label'] ?? '',
                'compound'     => !empty($runtime_rate['compound']),
                'rate_percent' => isset($runtime_rate['rate']) ? (float) $runtime_rate['rate'] : 0.0,
            ]);
        }
    }

    /**
     * A cart calculation never runs inside an order calculation, so a frame
     * still open here was left by a bare calculate_taxes() call.
     */
    public static function reset_order_tax_contexts(): void
    {
        if (!empty(self::$order_contexts)) {
            self::close_order_contexts_from(0);
        }
    }

    /**
     * Apply the order context on every WC_Tax::find_rates() call.
     *
     * woocommerce_matched_tax_rates only runs on a find_rates() cache miss;
     * the result is cached per country/state/city/postcode/class without the
     * street or the order. An earlier lookup for the same location could
     * otherwise hand the order WooCommerce's native rates.
     *
     * @param mixed $matched_tax_rates Rates found (possibly from cache).
     * @param mixed $args              find_rates() location arguments.
     * @return mixed
     */
    public static function filter_find_rates_for_order_context($matched_tax_rates, $args)
    {
        if (empty(self::$order_contexts) || !is_array($matched_tax_rates) || !is_array($args)) {
            return $matched_tax_rates;
        }

        $index = self::active_order_context(
            $args['country'] ?? '',
            $args['state'] ?? '',
            $args['postcode'] ?? '',
            $args['city'] ?? ''
        );
        if (null === $index) {
            return $matched_tax_rates;
        }

        return self::order_context_rates($index, $matched_tax_rates, (string) ($args['tax_class'] ?? ''));
    }

    /**
     * Whether a stored order is resolved from its own data.
     *
     * Orders carrying a stored resolver quote (checkout orders and everything
     * copied from them: subscriptions, renewals, payoff orders) and payment
     * plan orders use the order context. Other orders keep the session-based
     * behavior. The checkout's own order is always excluded: Store API
     * recalculates its draft with $order->calculate_totals() while the cart
     * session is live, and checkout must stay exactly as it was.
     */
    private static function order_context_applies($order): bool
    {
        if (self::is_checkout_session_order($order)) {
            return false;
        }

        $applies = !empty(self::get_stored_order_quote($order)) || self::is_payment_plan_order($order);

        return (bool) apply_filters('ffla_tax_order_context_enabled', $applies, $order);
    }

    private static function is_checkout_session_order($order): bool
    {
        if (method_exists($order, 'has_status') && $order->has_status('checkout-draft')) {
            return true;
        }

        if (!function_exists('WC') || !WC()->session) {
            return false;
        }

        $order_id = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
        if ($order_id <= 0) {
            return false;
        }

        foreach (['store_api_draft_order', 'order_awaiting_payment'] as $key) {
            if ((int) WC()->session->get($key) === $order_id) {
                return true;
            }
        }

        return false;
    }

    /**
     * FPPC (Split Payment) parent, subscription, renewal or payoff order.
     *
     * Renewals are first recalculated before FPPC stamps its own renewal meta,
     * so meta copied from the subscription and the line component keys count.
     */
    private static function is_payment_plan_order($order): bool
    {
        if ('yes' === (string) $order->get_meta('_fppc_managed_plan', true)
            || (int) $order->get_meta('_fppc_plan_subscription_id', true) > 0
            || !empty($order->get_meta('_fppc_plan_principals', true))) {
            return true;
        }

        if (method_exists($order, 'get_items')) {
            foreach ($order->get_items('line_item') as $item) {
                if ('yes' === (string) $item->get_meta('_fppc_managed_plan', true)
                    || '' !== (string) $item->get_meta('_fppc_component_key', true)) {
                    return true;
                }
            }
        }

        if (!class_exists('FPPC_Helpers') || !is_callable(['FPPC_Helpers', 'is_payment_plan'])) {
            return false;
        }

        if (is_a($order, 'WC_Subscription')) {
            return (bool) FPPC_Helpers::is_payment_plan($order);
        }

        $subscription_id = (int) $order->get_meta('_subscription_renewal', true);
        if ($subscription_id > 0 && function_exists('wcs_get_subscription')) {
            $subscription = wcs_get_subscription($subscription_id);
            return $subscription && FPPC_Helpers::is_payment_plan($subscription);
        }

        return false;
    }

    /**
     * The location WooCommerce will pass to find_rates() for this calculation.
     *
     * @return array{country:string,state:string,postcode:string,city:string}
     */
    private static function order_tax_location($order, array $args): array
    {
        $location = method_exists($order, 'get_taxable_location')
            ? $order->get_taxable_location($args)
            : $args;

        return [
            'country'  => strtoupper((string) ($location['country'] ?? '')),
            'state'    => strtoupper((string) ($location['state'] ?? '')),
            'postcode' => (string) ($location['postcode'] ?? ''),
            'city'     => (string) ($location['city'] ?? ''),
        ];
    }

    /**
     * The innermost frame, when it is an enabled order context for exactly
     * this lookup location.
     */
    private static function active_order_context($country, $state, $postcode, $city): ?int
    {
        if (empty(self::$order_contexts)) {
            return null;
        }

        $index = count(self::$order_contexts) - 1;
        $frame = self::$order_contexts[$index];
        if (empty($frame['enabled'])) {
            return null;
        }

        return $frame['match'] === self::location_signature($country, $state, $postcode, $city) ? $index : null;
    }

    /**
     * Runtime rate metadata of the innermost enabled frame.
     */
    private static function active_order_context_runtime_meta(): array
    {
        if (empty(self::$order_contexts)) {
            return [];
        }

        $frame = self::$order_contexts[count(self::$order_contexts) - 1];

        return !empty($frame['enabled']) && is_array($frame['runtime']) ? $frame['runtime'] : [];
    }

    private static function location_signature($country, $state, $postcode, $city): string
    {
        $postcode = (string) $postcode;
        $postcode = function_exists('wc_normalize_postcode') && function_exists('wc_clean')
            ? wc_normalize_postcode(wc_clean($postcode))
            : strtoupper(preg_replace('/[\s\-]/', '', trim($postcode)));

        return implode('|', [
            strtoupper(trim((string) $country)),
            strtoupper(trim((string) $state)),
            $postcode,
            strtoupper(trim((string) $city)),
        ]);
    }

    private static function find_order_context_index($order): ?int
    {
        for ($index = count(self::$order_contexts) - 1; $index >= 0; $index--) {
            if (self::$order_contexts[$index]['order'] === $order) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Remove a frame and any frame opened after it, and drop the find_rates()
     * cache entries they wrote so order-derived rates never serve a later
     * cart or order at the same city/ZIP.
     */
    private static function close_order_contexts_from(int $index): void
    {
        $closed = array_slice(self::$order_contexts, $index);
        self::$order_contexts = array_slice(self::$order_contexts, 0, $index);

        if (!class_exists('WC_Cache_Helper') || !function_exists('wp_cache_delete')) {
            return;
        }

        $prefix = WC_Cache_Helper::get_cache_prefix('taxes');
        foreach ($closed as $frame) {
            foreach (array_keys($frame['lookups']) as $lookup) {
                wp_cache_delete($prefix . 'wc_tax_rates_' . md5($lookup), 'taxes');
            }
        }
    }

    /**
     * Record the find_rates() cache key (same format as WC_Tax::find_rates())
     * written with an order-context result.
     */
    private static function remember_rate_lookup(int $index, $country, $state, $postcode, $city, $tax_class): void
    {
        $lookup = sprintf('%s+%s+%s+%s+%s', $country, $state, $city, $postcode, $tax_class);
        self::$order_contexts[$index]['lookups'][$lookup] = true;
    }

    /**
     * Rates for one lookup inside an order context.
     *
     * Mirrors the checkout guards (US only, standard tax class, role gate,
     * coverage) using the order's customer and address. The resolution is
     * computed once per frame; every lookup of the calculation reuses it.
     */
    private static function order_context_rates(int $index, array $matched_tax_rates, string $tax_class): array
    {
        if ($tax_class !== '') {
            return $matched_tax_rates;
        }

        if (!self::$order_contexts[$index]['resolved']) {
            [$rates, $runtime] = self::resolve_order_context_rates(self::$order_contexts[$index]);
            self::$order_contexts[$index]['resolved'] = true;
            self::$order_contexts[$index]['rates'] = $rates;
            self::$order_contexts[$index]['runtime'] = $runtime;
        }

        $rates = self::$order_contexts[$index]['rates'];

        return is_array($rates) ? $rates : $matched_tax_rates;
    }

    /**
     * Resolve the synthetic rate for a stored order.
     *
     * @return array{0:array|null,1:array} Rates (null = keep WooCommerce's
     *                                     matched rates) and runtime metadata.
     */
    private static function resolve_order_context_rates(array $frame): array
    {
        $order = $frame['order'];
        $address = self::order_context_address($order, $frame['location']);

        if ($address['country'] !== 'US' || $address['state'] === '') {
            return [null, []];
        }

        if (class_exists('Tax_Role_Gate') && Tax_Role_Gate::is_active()) {
            $charge = Tax_Role_Gate::should_charge_for_order($order);
            self::record_order_full_exemption($order, !$charge);
            if (!$charge) {
                return [[], []];
            }
        }

        if (!Tax_Coverage::is_enabled_for_store($address['state'])
            || !Tax_Coverage::is_supported($address['state'])) {
            return [null, []];
        }

        $input = [
            'street' => $address['street'],
            'city'   => $address['city'],
            'state'  => $address['state'],
            'zip'    => $address['zip'],
        ];
        if (empty($input['street']) && empty($input['zip'])) {
            return [null, []];
        }

        $quote = self::stored_quote_for_input($order, $input);
        if (null === $quote) {
            try {
                $quote = Tax_Quote_Engine::quote($input);
            } catch (\Throwable $e) {
                self::log_order_context('Order tax recalculation failed; WooCommerce rates kept', $order, [
                    'state'   => $input['state'],
                    'zip'     => $input['zip'],
                    'message' => $e->getMessage(),
                ]);
                return [null, []];
            }
            self::write_order_tax_quote($order, $quote->to_array());
        }

        if (!$quote->is_success()) {
            self::log_order_context('Order tax quote unsuccessful; WooCommerce rates kept', $order, [
                'state'   => $input['state'],
                'zip'     => $input['zip'],
                'outcome' => $quote->outcomeCode,
            ]);
            return [null, []];
        }

        return self::synthetic_rates_from_quote($quote);
    }

    /**
     * Address to quote for an order.
     *
     * Country/state/ZIP/city come from WooCommerce's taxable location for the
     * order (woocommerce_tax_based_on, admin overrides, location filters).
     * Local pickup pins the whole address to the store base, exactly like
     * checkout. The street is taken from the order address WooCommerce taxed,
     * and only when that address has the same state and ZIP.
     *
     * @return array{country:string,state:string,zip:string,city:string,street:string}
     */
    private static function order_context_address($order, array $location): array
    {
        if (self::order_has_local_pickup($order)
            && apply_filters('ffla_tax_local_pickup_use_store_base', true)) {
            $base = self::store_base_address();
            if ('' !== $base['state'] && '' !== $base['country']) {
                return $base;
            }
        }

        return [
            'country' => $location['country'],
            'state'   => $location['state'],
            'zip'     => $location['postcode'],
            'city'    => $location['city'],
            'street'  => self::order_street_for_location($order, $location),
        ];
    }

    private static function order_street_for_location($order, array $location): string
    {
        $based_on = get_option('woocommerce_tax_based_on', 'shipping');
        if ('base' === $based_on) {
            // Same as checkout: no per-customer street for store-base taxes.
            return '';
        }

        $zip = substr(preg_replace('/\D/', '', (string) $location['postcode']), 0, 5);
        foreach ('billing' === $based_on ? ['billing'] : ['shipping', 'billing'] as $type) {
            $street = (string) $order->{'get_' . $type . '_address_1'}();
            $state = strtoupper((string) $order->{'get_' . $type . '_state'}());
            $postcode = substr(preg_replace('/\D/', '', (string) $order->{'get_' . $type . '_postcode'}()), 0, 5);
            if ($street !== '' && $state === $location['state'] && $postcode === $zip) {
                return $street;
            }
        }

        return '';
    }

    /**
     * Local pickup detected from the order's own shipping lines.
     */
    private static function order_has_local_pickup($order): bool
    {
        if (!method_exists($order, 'get_items')
            || !apply_filters('woocommerce_apply_base_tax_for_local_pickup', true)) {
            return false;
        }

        $pickup_methods = apply_filters('woocommerce_local_pickup_methods', ['legacy_local_pickup', 'local_pickup']);
        $pickup_methods = array_merge(
            ['legacy_local_pickup', 'local_pickup', 'pickup_location'],
            is_array($pickup_methods) ? $pickup_methods : []
        );

        foreach ($order->get_items('shipping') as $item) {
            $method_id = method_exists($item, 'get_method_id') ? (string) $item->get_method_id() : '';
            if ($method_id !== '' && in_array($method_id, $pickup_methods, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The order's stored quote when it is a successful quote for this address.
     *
     * Reusing it keeps every payment of a plan on the rate the customer agreed
     * to at checkout and makes no live API call.
     */
    private static function stored_quote_for_input($order, array $input): ?Tax_Quote_Result
    {
        $stored = self::get_stored_order_quote($order);
        if (empty($stored) || !in_array(
            strtoupper((string) ($stored['outcomeCode'] ?? '')),
            [Tax_Quote_Result::OUTCOME_SUCCESS, Tax_Quote_Result::OUTCOME_NO_SALES_TAX],
            true
        )) {
            return null;
        }

        $normalized = Tax_Address_Normalizer::normalize($input);
        $stored_key = self::stored_quote_address_key($stored);
        if ($stored_key === '' || !hash_equals($stored_key, (string) $normalized['key'])
            || strtoupper((string) ($stored['state'] ?? '')) !== $normalized['state']) {
            return null;
        }

        $quote = new Tax_Quote_Result();
        foreach ($stored as $key => $value) {
            if (property_exists($quote, $key)) {
                $quote->$key = $value;
            }
        }

        return $quote;
    }

    private static function stored_quote_address_key(array $stored): string
    {
        $normalized = isset($stored['normalizedAddress']) && is_array($stored['normalizedAddress'])
            ? $stored['normalizedAddress']
            : [];
        if (!empty($normalized['key']) && is_string($normalized['key'])) {
            return $normalized['key'];
        }
        if (isset($normalized['street'], $normalized['zip'])) {
            return Tax_Address_Normalizer::build_key($normalized);
        }
        if (isset($stored['inputAddress']) && is_array($stored['inputAddress'])) {
            return (string) Tax_Address_Normalizer::normalize($stored['inputAddress'])['key'];
        }

        return '';
    }

    private static function get_stored_order_quote($order): array
    {
        $value = $order->get_meta('_ffla_tax_quote', true);
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }

        return is_array($value) && !empty($value['state']) ? $value : [];
    }

    /**
     * Keep the full-order exemption evidence in step with the renewal's own
     * decision (the parent's flag is copied onto every renewal).
     */
    private static function record_order_full_exemption($order, bool $exempt): void
    {
        if ($exempt) {
            $order->update_meta_data('_ffla_tax_full_order_exempt', 'yes');
            $order->update_meta_data(
                '_ffla_tax_full_order_exempt_context',
                wp_json_encode(Tax_Role_Gate::get_order_customer_context($order))
            );
            return;
        }

        $order->delete_meta_data('_ffla_tax_full_order_exempt');
        $order->delete_meta_data('_ffla_tax_full_order_exempt_context');
    }

    /**
     * Order recalculations run unattended (cron), so a fallback to
     * WooCommerce's native table must leave a trace.
     */
    private static function log_order_context(string $message, $order, array $context): void
    {
        $context = array_merge([
            'orderId' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
        ], $context);

        if (function_exists('ffla_tax_log')) {
            ffla_tax_log('warning', $message, $context);
            return;
        }

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning($message . ' ' . wp_json_encode($context), ['source' => 'ffla-tax']);
        }
    }
}
