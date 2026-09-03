<?php
/**
 * Google Merchant Center feed policy engine.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Google_Merchant_Policy_Engine
{
    const OPTION = 'ffla_google_merchant_policy_settings';
    const TERM_META = '_ffla_google_merchant_rule';
    const STATUS_META = '_ffla_gmp_status';
    const REASON_META = '_ffla_gmp_reason';
    const VERSION_META = '_ffla_gmp_version';
    const CHECKED_META = '_ffla_gmp_checked_at';
    const VISIBILITY_META = '_wc_gla_visibility';
    const ENGINE_VERSION = '1.0';

    /** @var array<int,array> */
    private static $decision_cache = [];

    public static function init(): void
    {
        add_filter('woocommerce_gla_get_sync_ready_products_pre_filter', [__CLASS__, 'filter_sync_ready_products'], 5, 1);
        add_action('save_post_product', [__CLASS__, 'on_product_saved'], 20, 3);
        add_action('save_post_product_variation', [__CLASS__, 'on_product_saved'], 20, 3);
        add_action('created_product_cat', [__CLASS__, 'on_category_created'], 10, 2);
        add_action('edited_product_cat', [__CLASS__, 'on_category_edited'], 20, 2);
        add_action('update_option_' . self::OPTION, [__CLASS__, 'reset_runtime_cache'], 10, 0);
    }

    public static function default_settings(): array
    {
        return [
            'mode' => 'audit',
            'batch_size' => 50,
            'content_safety' => '1',
        ];
    }

    public static function get_settings(): array
    {
        $settings = get_option(self::OPTION, []);
        return wp_parse_args(is_array($settings) ? $settings : [], self::default_settings());
    }

    public static function sanitize_settings(array $settings): array
    {
        $mode = sanitize_key((string) ($settings['mode'] ?? 'audit'));
        if (!in_array($mode, ['audit', 'enforce'], true)) {
            $mode = 'audit';
        }
        return [
            'mode' => $mode,
            'batch_size' => max(10, min(250, (int) ($settings['batch_size'] ?? 50))),
            'content_safety' => !empty($settings['content_safety']) ? '1' : '0',
        ];
    }

    public static function reset_runtime_cache(): void
    {
        self::$decision_cache = [];
    }

    public static function dependency_available(): bool
    {
        return defined('WC_GLA_VERSION')
            || class_exists('Automattic\\WooCommerce\\GoogleListingsAndAds\\Plugin');
    }

    /**
     * The official Google for WooCommerce pre-filter receives WC_Product[].
     */
    public static function filter_sync_ready_products($products): array
    {
        if (!is_array($products) || (string) (self::get_settings()['mode'] ?? 'audit') !== 'enforce') {
            return is_array($products) ? $products : [];
        }

        return array_values(array_filter($products, function ($product) {
            $decision = self::evaluate_product($product);
            return ($decision['status'] ?? 'pending') === 'allowed';
        }));
    }

    /**
     * Evaluate a product without writing to it.
     *
     * @return array{status:string,reason:string,reasons:array,product_id:int,parent_id:int}
     */
    public static function evaluate_product($product): array
    {
        if (is_numeric($product) && function_exists('wc_get_product')) {
            $product = wc_get_product((int) $product);
        }
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return [
                'status' => 'pending',
                'reason' => __('Product could not be loaded.', 'ffl-funnels-addons'),
                'reasons' => ['product_unavailable'],
                'product_id' => 0,
                'parent_id' => 0,
            ];
        }

        $product_id = (int) $product->get_id();
        $parent_id = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $policy_product_id = $parent_id > 0 ? $parent_id : $product_id;
        if (isset(self::$decision_cache[$product_id])) {
            return self::$decision_cache[$product_id];
        }

        $policy_product = $product;
        if ($parent_id > 0 && function_exists('wc_get_product')) {
            $loaded_parent = wc_get_product($parent_id);
            if ($loaded_parent) {
                $policy_product = $loaded_parent;
            }
        }

        $hard_reasons = self::get_hard_block_reasons($policy_product, $policy_product_id);
        if (!empty($hard_reasons)) {
            $decision = [
                'status' => 'blocked',
                'reason' => implode('; ', $hard_reasons),
                'reasons' => $hard_reasons,
                'product_id' => $product_id,
                'parent_id' => $parent_id,
            ];
            self::$decision_cache[$product_id] = $decision;
            return $decision;
        }

        $term_ids = function_exists('wp_get_post_terms')
            ? wp_get_post_terms($policy_product_id, 'product_cat', ['fields' => 'ids'])
            : [];
        if (is_wp_error($term_ids) || empty($term_ids)) {
            $decision = [
                'status' => 'pending',
                'reason' => __('No product category has an Allow policy.', 'ffl-funnels-addons'),
                'reasons' => ['no_category_policy'],
                'product_id' => $product_id,
                'parent_id' => $parent_id,
            ];
            self::$decision_cache[$product_id] = $decision;
            return $decision;
        }

        $statuses = [];
        $details = [];
        foreach (array_map('intval', (array) $term_ids) as $term_id) {
            $category = self::get_effective_category_policy($term_id);
            $statuses[] = $category['policy'];
            $details[] = $category['reason'];
        }

        if (in_array('block', $statuses, true)) {
            $status = 'blocked';
        } elseif (in_array('pending', $statuses, true)) {
            $status = 'pending';
        } elseif (in_array('allow', $statuses, true)) {
            $status = 'allowed';
        } else {
            $status = 'pending';
        }

        $decision = [
            'status' => $status,
            'reason' => implode('; ', array_values(array_unique(array_filter($details)))),
            'reasons' => array_values(array_unique(array_filter($details))),
            'product_id' => $product_id,
            'parent_id' => $parent_id,
        ];
        self::$decision_cache[$product_id] = $decision;
        return $decision;
    }

    /**
     * Persist an audit decision and, in enforce mode, a one-way feed exclusion.
     */
    public static function apply_to_product($product): array
    {
        $decision = self::evaluate_product($product);
        if (!is_object($product) && function_exists('wc_get_product')) {
            $product = wc_get_product((int) ($decision['product_id'] ?? 0));
        }
        if (!is_object($product) || !method_exists($product, 'update_meta_data')) {
            return $decision;
        }

        $product->update_meta_data(self::STATUS_META, (string) $decision['status']);
        $product->update_meta_data(self::REASON_META, (string) $decision['reason']);
        $product->update_meta_data(self::VERSION_META, self::ENGINE_VERSION);
        $product->update_meta_data(self::CHECKED_META, gmdate('c'));

        $settings = self::get_settings();
        if ((string) ($settings['mode'] ?? 'audit') === 'enforce'
            && in_array($decision['status'], ['blocked', 'pending'], true)) {
            // Only add exclusions. We never remove an existing manual or plugin
            // exclusion when a later scan decides the product is allowed.
            if ((string) $product->get_meta(self::VISIBILITY_META, true) !== 'dont-sync-and-show') {
                $product->update_meta_data(self::VISIBILITY_META, 'dont-sync-and-show');
                $product->update_meta_data('_ffla_gmp_visibility_applied', 'yes');
            }
        }

        $product->save_meta_data();
        return $decision;
    }

    public static function set_category_policy(int $term_id, string $policy): bool
    {
        $policy = sanitize_key($policy);
        if (!in_array($policy, ['allow', 'block', 'inherit', 'pending'], true)) {
            return false;
        }
        self::reset_runtime_cache();
        return false !== update_term_meta($term_id, self::TERM_META, $policy);
    }

    public static function get_category_policy(int $term_id): string
    {
        $policy = sanitize_key((string) get_term_meta($term_id, self::TERM_META, true));
        return in_array($policy, ['allow', 'block', 'inherit', 'pending'], true) ? $policy : '';
    }

    public static function get_effective_category_policy(int $term_id): array
    {
        $seen = [];
        $current = $term_id;
        while ($current > 0 && !isset($seen[$current])) {
            $seen[$current] = true;
            $term = get_term($current, 'product_cat');
            if (!$term || is_wp_error($term)) {
                break;
            }

            $stored = self::get_category_policy($current);
            if (in_array($stored, ['allow', 'block', 'pending'], true)) {
                return [
                    'policy' => $stored,
                    'source_term_id' => $current,
                    'reason' => sprintf(
                        /* translators: 1: category name, 2: category policy. */
                        __('Category “%1$s” resolves to %2$s.', 'ffl-funnels-addons'),
                        (string) $term->name,
                        $stored
                    ),
                ];
            }

            $parent = (int) $term->parent;
            if ($stored === 'inherit' || ($stored === '' && $parent > 0)) {
                $current = $parent;
                continue;
            }
            break;
        }

        return [
            'policy' => 'pending',
            'source_term_id' => 0,
            'reason' => __('Category policy is pending review.', 'ffl-funnels-addons'),
        ];
    }

    public static function on_product_saved(int $post_id, $post, bool $update): void
    {
        if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        $product = function_exists('wc_get_product') ? wc_get_product($post_id) : null;
        if ($product) {
            unset(self::$decision_cache[$post_id]);
            self::apply_to_product($product);
        }
    }

    public static function on_category_created(int $term_id, int $tt_id): void
    {
        $term = get_term($term_id, 'product_cat');
        $policy = $term && !is_wp_error($term) && (int) $term->parent > 0 ? 'inherit' : 'pending';
        update_term_meta($term_id, self::TERM_META, $policy);
        self::reset_runtime_cache();
    }

    public static function on_category_edited(int $term_id, int $tt_id): void
    {
        self::reset_runtime_cache();
        if (class_exists('Google_Merchant_Policy_Reconciler')) {
            Google_Merchant_Policy_Reconciler::start();
        }
    }

    private static function get_hard_block_reasons($product, int $product_id): array
    {
        $reasons = [];
        foreach (['_firearm_product' => 'firearm', '_ammunition_product' => 'ammunition'] as $meta_key => $label) {
            $value = strtolower((string) get_post_meta($product_id, $meta_key, true));
            if (in_array($value, ['yes', '1', 'true'], true)) {
                $reasons[] = sprintf(__('Hard block: product is marked as %s.', 'ffl-funnels-addons'), $label);
            }
        }

        $settings = self::get_settings();
        if ((string) ($settings['content_safety'] ?? '1') !== '1') {
            return $reasons;
        }

        $parts = [];
        if (method_exists($product, 'get_name')) {
            $parts[] = (string) $product->get_name();
        }
        if (method_exists($product, 'get_short_description')) {
            $parts[] = wp_strip_all_tags((string) $product->get_short_description());
        }
        $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
        if (!is_wp_error($terms)) {
            $parts = array_merge($parts, (array) $terms);
        }
        $text = implode(' ', $parts);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);

        // Storage and carrying accessories are not treated as weapons merely
        // because their title contains "gun" or "rifle".
        $safe_accessory = preg_match('/\b(case|safe|cabinet|vault|lock|rack|bag|holster|sling|cleaning mat)\b/i', $text);
        $patterns = apply_filters('ffla_google_merchant_hard_block_patterns', [
            'ammunition' => '/\b(ammunition|ammo|cartridges?|rounds?|primers?|gunpowder|smokeless powder)\b/i',
            'firearm' => '/\b(firearms?|handguns?|pistols?|revolvers?|rifles?|shotguns?|machine guns?|short[- ]barreled|sbr|nfa)\b/i',
            'regulated_part' => '/\b(receivers?|frames?|barrels?|triggers?|bolt carriers?|upper receivers?|lower receivers?|magazines?|suppressors?|silencers?)\b/i',
            'weapon_accessory' => '/\b(rifle scopes?|gun scopes?|weapon sights?|night vision scopes?|thermal scopes?|less[- ]lethal weapons?|tasers?)\b/i',
        ], $product);

        foreach ((array) $patterns as $label => $pattern) {
            if ($safe_accessory && $label === 'firearm') {
                continue;
            }
            $matched = is_string($pattern) ? preg_match($pattern, $text) : false;
            if ($matched === 1) {
                $reasons[] = sprintf(__('Hard block: restricted %s content detected.', 'ffl-funnels-addons'), str_replace('_', ' ', (string) $label));
            }
        }
        return array_values(array_unique($reasons));
    }
}
