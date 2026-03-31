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

        if (!Tax_Coverage::is_supported($state) && !Tax_Coverage::has_no_tax($state)) {
            return $matched_tax_rates;
        }

        $input = self::build_address_input($state, $postcode, $city);
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

        if ($quote->outcomeCode === Tax_Quote_Result::OUTCOME_NO_SALES_TAX) {
            self::store_runtime_tax_meta([]);
            return [];
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
     */
    private static function build_address_input(string $state, string $postcode, string $city): array
    {
        $request_fields = self::get_checkout_request_fields();
        $street = '';
        $request_street = self::pick_first_non_empty([
            $request_fields['shipping_address_1'] ?? '',
            $request_fields['billing_address_1'] ?? '',
        ]);
        $request_city = self::pick_first_non_empty([
            $request_fields['shipping_city'] ?? '',
            $request_fields['billing_city'] ?? '',
            $city,
        ]);
        $request_state = self::pick_first_non_empty([
            $request_fields['shipping_state'] ?? '',
            $request_fields['billing_state'] ?? '',
            $state,
        ]);
        $request_postcode = self::pick_first_non_empty([
            $request_fields['shipping_postcode'] ?? '',
            $request_fields['billing_postcode'] ?? '',
            $postcode,
        ]);

        if (function_exists('WC') && WC()->customer) {
            $street = $request_street !== '' ? $request_street : WC()->customer->get_shipping_address();
            if ($street === '') {
                $street = WC()->customer->get_billing_address();
            }

            if ($request_city === '') {
                $request_city = WC()->customer->get_shipping_city();
                if ($request_city === '') {
                    $request_city = WC()->customer->get_billing_city();
                }
            }

            if ($request_state === '') {
                $request_state = WC()->customer->get_shipping_state();
                if ($request_state === '') {
                    $request_state = WC()->customer->get_billing_state();
                }
            }

            if ($request_postcode === '') {
                $request_postcode = WC()->customer->get_shipping_postcode();
                if ($request_postcode === '') {
                    $request_postcode = WC()->customer->get_billing_postcode();
                }
            }
        }

        return [
            'street' => $street,
            'city'   => $request_city,
            'state'  => $request_state,
            'zip'    => $request_postcode,
        ];
    }

    /**
     * Read the latest checkout fields from the current request payload.
     */
    private static function get_checkout_request_fields(): array
    {
        $fields = [];

        if (!empty($_POST['post_data']) && is_string($_POST['post_data'])) {
            parse_str(wp_unslash($_POST['post_data']), $fields);
        }

        if (empty($fields) && !empty($_POST) && is_array($_POST)) {
            foreach ($_POST as $key => $value) {
                if (is_scalar($value)) {
                    $fields[(string) $key] = wp_unslash((string) $value);
                }
            }
        }

        return $fields;
    }

    /**
     * Return the first non-empty scalar string from a list.
     */
    private static function pick_first_non_empty(array $values): string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Convert a tax quote result into WooCommerce-style matched rates.
     */
    private static function build_wc_rates_from_quote(Tax_Quote_Result $quote, string $tax_class): array
    {
        $rates = [];
        $runtime_meta = [];

        foreach ($quote->breakdown as $index => $item) {
            $rate_id = 990000 + $index;
            $label   = self::build_rate_label($item);
            $code    = self::build_rate_code($quote->state, $rate_id, $item);

            $rates[$rate_id] = [
                'rate'     => (float) number_format(((float) $item['rate']) * 100, 4, '.', ''),
                'label'    => $label,
                'shipping' => 'yes',
                'compound' => 'no',
            ];

            $runtime_meta[(string) $rate_id] = [
                'id'       => $rate_id,
                'label'    => $label,
                'code'     => $code,
                'rate'     => (float) number_format(((float) $item['rate']) * 100, 4, '.', ''),
                'compound' => false,
                'state'    => $quote->state,
                'type'     => (string) ($item['type'] ?? 'tax'),
            ];
        }

        self::store_runtime_tax_meta($runtime_meta);

        return $rates;
    }

    /**
     * Build a readable rate label for WooCommerce tax lines.
     */
    private static function build_rate_label(array $item): string
    {
        $type = strtoupper((string) ($item['type'] ?? 'tax'));
        $jurisdiction = (string) ($item['jurisdiction'] ?? 'Tax');

        return 'FFLA ' . $type . ': ' . $jurisdiction;
    }

    /**
     * Build a Woo-compatible rate code for runtime-generated rates.
     */
    private static function build_rate_code(string $state, int $rate_id, array $item): string
    {
        $type = strtoupper(preg_replace('/[^A-Z0-9]+/', '', (string) ($item['type'] ?? 'TAX')));
        $suffix = strtoupper(substr(md5(wp_json_encode($item) . '|' . $rate_id), 0, 6));

        return sprintf('US-%s-FFLA-%s-%s', strtoupper($state), $type ?: 'TAX', $suffix);
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
