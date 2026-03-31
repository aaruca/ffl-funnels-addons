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
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'store_order_tax_quote'], 10, 2);
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
            return [];
        }

        if (!$quote->is_success()) {
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
     * Build address input for the quote engine from the active customer.
     */
    private static function build_address_input(string $state, string $postcode, string $city): array
    {
        $street = '';

        if (function_exists('WC') && WC()->customer) {
            $street = WC()->customer->get_shipping_address();
            if ($street === '') {
                $street = WC()->customer->get_billing_address();
            }
        }

        return [
            'street' => $street,
            'city'   => $city,
            'state'  => $state,
            'zip'    => $postcode,
        ];
    }

    /**
     * Convert a tax quote result into WooCommerce-style matched rates.
     */
    private static function build_wc_rates_from_quote(Tax_Quote_Result $quote, string $tax_class): array
    {
        $rates = [];

        foreach ($quote->breakdown as $index => $item) {
            $rate_id = 990000 + $index;
            $label   = self::build_rate_label($item);

            $rates[$rate_id] = [
                'tax_rate_id'       => $rate_id,
                'tax_rate_country'  => 'US',
                'tax_rate_state'    => $quote->state,
                'tax_rate'          => number_format(((float) $item['rate']) * 100, 4, '.', ''),
                'tax_rate_name'     => $label,
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 1,
                'tax_rate_order'    => $index,
                'tax_rate_class'    => $tax_class,
                'label'             => $label,
                'shipping'          => 'yes',
                'compound'          => 'no',
            ];
        }

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
}
