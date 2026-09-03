<?php
/**
 * Merchant-defined sales-tax holiday rules.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Holiday_Engine
{
    const SETTINGS_KEY = 'ffla_tax_resolver_settings';

    /** @var array|null */
    private static $settings_cache = null;

    /** @var array<string,array> */
    private static $match_cache = [];

    public static function reset_runtime_cache(): void
    {
        self::$settings_cache = null;
        self::$match_cache = [];
    }

    public static function is_active(): bool
    {
        $settings = self::get_settings();
        return (string) ($settings['tax_holidays_enabled'] ?? '0') === '1'
            && !empty(self::get_rules(true));
    }

    public static function get_rules(bool $enabled_only = false): array
    {
        $settings = self::get_settings();
        $rules = self::sanitize_rules($settings['tax_holiday_rules'] ?? []);
        if (!$enabled_only) {
            return $rules;
        }

        return array_values(array_filter($rules, function ($rule) {
            return (string) ($rule['enabled'] ?? '0') === '1' && self::is_rule_complete($rule);
        }));
    }

    public static function sanitize_rules($raw_rules): array
    {
        if (!is_array($raw_rules)) {
            return [];
        }

        $rules = [];
        foreach (array_slice($raw_rules, 0, 100) as $raw_rule) {
            if (!is_array($raw_rule)) {
                continue;
            }

            $scope = sanitize_key((string) ($raw_rule['scope'] ?? 'selected'));
            if (!in_array($scope, ['all', 'selected'], true)) {
                $scope = 'selected';
            }

            $shipping_mode = sanitize_key((string) ($raw_rule['shipping_mode'] ?? 'taxable'));
            if (!in_array($shipping_mode, ['taxable', 'exempt', 'proportional'], true)) {
                $shipping_mode = 'taxable';
            }

            $id = sanitize_key((string) ($raw_rule['id'] ?? ''));
            if ($id === '') {
                $id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('holiday-', true);
            }

            $price_limit = '';
            if (isset($raw_rule['price_limit']) && trim((string) $raw_rule['price_limit']) !== '') {
                $price_limit = function_exists('wc_format_decimal')
                    ? wc_format_decimal($raw_rule['price_limit'])
                    : number_format((float) $raw_rule['price_limit'], 2, '.', '');
                if ((float) $price_limit < 0) {
                    $price_limit = '';
                }
            }

            $name = sanitize_text_field((string) ($raw_rule['name'] ?? ''));
            $rules[] = [
                'id'           => $id,
                'name'         => $name !== '' ? $name : __('Tax holiday', 'ffl-funnels-addons'),
                'enabled'      => !empty($raw_rule['enabled']) ? '1' : '0',
                'start_at'     => self::sanitize_local_datetime($raw_rule['start_at'] ?? ''),
                'end_at'       => self::sanitize_local_datetime($raw_rule['end_at'] ?? ''),
                'states'       => self::sanitize_states($raw_rule['states'] ?? []),
                'scope'        => $scope,
                'product_ids'  => self::sanitize_ids($raw_rule['product_ids'] ?? [], 1000),
                'category_ids' => self::sanitize_ids($raw_rule['category_ids'] ?? [], 1000),
                'tag_ids'      => self::sanitize_ids($raw_rule['tag_ids'] ?? [], 1000),
                'price_limit'  => $price_limit,
                'shipping_mode'=> $shipping_mode,
            ];
        }

        return $rules;
    }

    public static function is_rule_complete(array $rule): bool
    {
        $start = self::local_timestamp((string) ($rule['start_at'] ?? ''));
        $end = self::local_timestamp((string) ($rule['end_at'] ?? ''));
        if ($start <= 0 || $end <= 0 || $end < $start) {
            return false;
        }

        if ((string) ($rule['scope'] ?? 'selected') === 'all') {
            return true;
        }

        return !empty($rule['product_ids']) || !empty($rule['category_ids']) || !empty($rule['tag_ids']);
    }

    public static function get_rule_status(array $rule, ?int $now = null): string
    {
        if ((string) ($rule['enabled'] ?? '0') !== '1') {
            return 'disabled';
        }
        if (!self::is_rule_complete($rule)) {
            return 'incomplete';
        }

        $now = $now ?? time();
        $start = self::local_timestamp((string) $rule['start_at']);
        $end = self::local_timestamp((string) $rule['end_at']);
        if ($now < $start) {
            return 'scheduled';
        }
        if ($now > $end) {
            return 'expired';
        }
        return 'active';
    }

    public static function should_exempt_product($product): bool
    {
        return !empty(self::get_matching_rules_for_product($product));
    }

    public static function get_matching_rules_for_product($product, string $state = '', ?int $now = null): array
    {
        if (!self::is_active() || !is_object($product) || !method_exists($product, 'get_id')) {
            return [];
        }

        $state = strtoupper(trim($state !== '' ? $state : self::get_destination_state()));
        $now = $now ?? time();
        $product_id = (int) $product->get_id();
        $parent_id = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $taxonomy_product_id = $parent_id > 0 ? $parent_id : $product_id;
        $price = method_exists($product, 'get_price') ? (float) $product->get_price() : 0.0;
        $cache_key = implode(':', [$product_id, $taxonomy_product_id, $state, $now]);
        if (isset(self::$match_cache[$cache_key])) {
            return self::$match_cache[$cache_key];
        }

        $category_ids = self::get_product_term_ids($taxonomy_product_id, 'product_cat');
        $tag_ids = self::get_product_term_ids($taxonomy_product_id, 'product_tag');
        $category_lineage = $category_ids;
        foreach ($category_ids as $category_id) {
            if (function_exists('get_ancestors')) {
                $category_lineage = array_merge($category_lineage, get_ancestors($category_id, 'product_cat', 'taxonomy'));
            }
        }
        $category_lineage = array_values(array_unique(array_map('intval', $category_lineage)));

        $matches = [];
        foreach (self::get_rules(true) as $rule) {
            if (self::get_rule_status($rule, $now) !== 'active') {
                continue;
            }
            if (!empty($rule['states']) && !in_array($state, $rule['states'], true)) {
                continue;
            }
            if ($rule['price_limit'] !== '' && $price > (float) $rule['price_limit']) {
                continue;
            }

            $scope_match = $rule['scope'] === 'all'
                || in_array($product_id, $rule['product_ids'], true)
                || ($parent_id > 0 && in_array($parent_id, $rule['product_ids'], true))
                || !empty(array_intersect($category_lineage, $rule['category_ids']))
                || !empty(array_intersect($tag_ids, $rule['tag_ids']));
            if (!$scope_match) {
                continue;
            }

            $matches[] = [
                'type'          => 'tax_holiday',
                'id'            => (string) $rule['id'],
                'name'          => (string) $rule['name'],
                'start_at'      => (string) $rule['start_at'],
                'end_at'        => (string) $rule['end_at'],
                'states'        => (array) $rule['states'],
                'scope'         => (string) $rule['scope'],
                'price_limit'   => (string) $rule['price_limit'],
                'shipping_mode' => (string) $rule['shipping_mode'],
                'destination_state' => $state,
            ];
        }

        self::$match_cache[$cache_key] = $matches;
        return $matches;
    }

    /**
     * Return the exempt fraction of shipping for the current cart.
     *
     * Full exemption wins. Proportional rules exempt the same share of shipping
     * as their eligible merchandise share. Taxable shipping contributes zero.
     */
    public static function get_shipping_exempt_fraction($cart = null): float
    {
        if (!self::is_active()) {
            return 0.0;
        }
        if ($cart === null && function_exists('WC')) {
            $cart = WC()->cart;
        }
        if (!is_object($cart) || !method_exists($cart, 'get_cart')) {
            return 0.0;
        }

        $total = 0.0;
        $proportional_eligible = 0.0;
        foreach ($cart->get_cart() as $cart_item) {
            $product = is_array($cart_item) ? ($cart_item['data'] ?? null) : null;
            if (!is_object($product)) {
                continue;
            }
            $line_total = max(0.0, (float) ($cart_item['line_total'] ?? 0));
            $total += $line_total;
            foreach (self::get_matching_rules_for_product($product) as $match) {
                if (($match['shipping_mode'] ?? 'taxable') === 'exempt') {
                    return 1.0;
                }
                if (($match['shipping_mode'] ?? 'taxable') === 'proportional') {
                    $proportional_eligible += $line_total;
                    break;
                }
            }
        }

        if ($total <= 0) {
            return 0.0;
        }
        return max(0.0, min(1.0, $proportional_eligible / $total));
    }

    public static function get_destination_state(): string
    {
        if (function_exists('wc_get_chosen_shipping_method_ids')
            && apply_filters('woocommerce_apply_base_tax_for_local_pickup', true)) {
            $chosen = wc_get_chosen_shipping_method_ids();
            $pickup_methods = apply_filters('woocommerce_local_pickup_methods', ['legacy_local_pickup', 'local_pickup']);
            if (is_array($chosen) && is_array($pickup_methods) && count(array_intersect($chosen, $pickup_methods)) > 0
                && function_exists('WC') && WC()->countries) {
                return strtoupper((string) WC()->countries->get_base_state());
            }
        }

        if (function_exists('WC') && WC()->customer) {
            $based_on = (string) get_option('woocommerce_tax_based_on', 'shipping');
            if ($based_on === 'billing') {
                return strtoupper((string) WC()->customer->get_billing_state());
            }
            if ($based_on !== 'base') {
                $state = strtoupper((string) WC()->customer->get_shipping_state());
                if ($state !== '') {
                    return $state;
                }
                $state = strtoupper((string) WC()->customer->get_billing_state());
                if ($state !== '') {
                    return $state;
                }
            }
        }

        if (function_exists('WC') && WC()->countries) {
            return strtoupper((string) WC()->countries->get_base_state());
        }
        return '';
    }

    private static function get_settings(): array
    {
        if (self::$settings_cache === null) {
            $settings = get_option(self::SETTINGS_KEY, []);
            self::$settings_cache = is_array($settings) ? $settings : [];
        }
        return self::$settings_cache;
    }

    private static function sanitize_local_datetime($value): string
    {
        $value = sanitize_text_field((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return '';
        }
        return self::local_timestamp($value) > 0 ? $value : '';
    }

    private static function local_timestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $timezone);
            return $date instanceof DateTimeImmutable ? $date->getTimestamp() : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function sanitize_states($states): array
    {
        $clean = [];
        foreach ((array) $states as $state) {
            $state = strtoupper(sanitize_text_field((string) $state));
            if (preg_match('/^[A-Z]{2}$/', $state)) {
                $clean[$state] = $state;
            }
        }
        ksort($clean);
        return array_values($clean);
    }

    private static function sanitize_ids($ids, int $limit): array
    {
        $clean = [];
        foreach (array_slice((array) $ids, 0, $limit) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        sort($clean, SORT_NUMERIC);
        return array_values($clean);
    }

    private static function get_product_term_ids(int $product_id, string $taxonomy): array
    {
        if ($product_id <= 0 || !function_exists('wp_get_post_terms')) {
            return [];
        }
        $ids = wp_get_post_terms($product_id, $taxonomy, ['fields' => 'ids']);
        return is_wp_error($ids) ? [] : array_values(array_unique(array_map('intval', (array) $ids)));
    }
}
