<?php
/**
 * WooCommerce tax integration.
 *
 * Makes the tax resolver the source of truth during WooCommerce cart and
 * checkout tax calculation by overriding matched rates for supported US states.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_WooCommerce_Integration
{
    /**
     * Register WooCommerce hooks.
     */
    public static function init(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_filter('woocommerce_matched_tax_rates', [__CLASS__, 'filter_matched_tax_rates'], 20, 6);
        add_filter('woocommerce_rate_label', [__CLASS__, 'filter_runtime_rate_label'], 10, 2);
        add_filter('woocommerce_rate_code', [__CLASS__, 'filter_runtime_rate_code'], 10, 2);
        add_filter('woocommerce_rate_compound', [__CLASS__, 'filter_runtime_rate_compound'], 10, 2);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'store_order_tax_quote'], 10, 2);
        add_action('woocommerce_checkout_create_order_tax_item', [__CLASS__, 'decorate_runtime_order_tax_item'], 10, 3);
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

        // Role-based tax gate: exempt roles return an empty rate set and we
        // wipe the runtime tax meta so stale synthetic rates from an earlier
        // request can't leak through.
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
        if (is_array($quote) && !empty($quote['state'])) {
            $order->update_meta_data('_ffla_tax_quote', wp_json_encode($quote));
            $order->update_meta_data('_ffla_tax_query_id', $quote['queryId'] ?? '');
            $order->update_meta_data('_ffla_tax_source', $quote['source'] ?? '');
        }
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
     * Session-based (the cart/checkout flow). Admin order recalculation resolves
     * pickup from the order's line items instead, which WooCommerce handles via
     * its own get_tax_location() base override — see the note in filter.
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
        $total_rate = 0.0;
        foreach ($quote->breakdown as $item) {
            $total_rate += (float) ($item['rate'] ?? 0);
        }

        if (empty($quote->breakdown)) {
            $total_rate = (float) $quote->totalRate;
        }

        $rate_percent = (float) number_format($total_rate * 100, 4, '.', '');
        if ($rate_percent <= 0) {
            self::store_runtime_tax_meta([]);
            return [];
        }

        $rate_id = 990000;
        $label   = __('Sales Tax', 'ffl-funnels-addons');
        $code    = sprintf('US-%s-FFLA-TOTAL', strtoupper($quote->state));

        $rates = [
            $rate_id => [
                'rate'     => $rate_percent,
                'label'    => $label,
                'shipping' => 'yes',
                'compound' => 'no',
            ],
        ];

        self::store_runtime_tax_meta([
            (string) $rate_id => [
                'id'       => $rate_id,
                'label'    => $label,
                'code'     => $code,
                'rate'     => $rate_percent,
                'compound' => false,
                'state'    => $quote->state,
                'type'     => 'tax',
            ],
        ]);

        return $rates;
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
        if (!function_exists('WC') || !WC()->session) {
            return [];
        }

        $runtime_rates = WC()->session->get('ffla_runtime_tax_rates');
        if (!is_array($runtime_rates)) {
            return [];
        }

        $key = (string) $tax_rate_id;

        return isset($runtime_rates[$key]) && is_array($runtime_rates[$key])
            ? $runtime_rates[$key]
            : [];
    }
}
