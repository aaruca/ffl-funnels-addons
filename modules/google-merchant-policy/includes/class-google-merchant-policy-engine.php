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
    /** Google visibility value this addon last wrote ('yes' = legacy exclusion). */
    const APPLIED_META = '_ffla_gmp_visibility_applied';
    /** Per-product decision: '' follows the rules, 'include' or 'exclude'. */
    const OVERRIDE_META = '_ffla_gmp_override';
    /** 'manual', or 'google-for-woocommerce' for a kept Channel visibility exclusion. */
    const OVERRIDE_SOURCE_META = '_ffla_gmp_override_source';
    const ENGINE_VERSION = '1.2';

    /** @var array<int,array> */
    private static $decision_cache = [];

    public static function init(): void
    {
        add_filter('woocommerce_gla_get_sync_ready_products_pre_filter', [__CLASS__, 'filter_sync_ready_products'], 5, 1);
        // WooCommerce has saved terms and channel visibility by priority 80;
        // Google's SyncerHooks reads the final object at priority 90.
        foreach (['woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation'] as $hook) {
            add_action($hook, [__CLASS__, 'on_wc_product_saved'], 80, 2);
        }
        add_action('woocommerce_process_product_meta', [__CLASS__, 'on_wc_product_saved'], 80, 1);
        add_action('created_product_cat', [__CLASS__, 'on_category_created'], 10, 2);
        add_action('edited_product_cat', [__CLASS__, 'on_category_edited'], 40, 2);
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

        // Explicit firearm/ammunition flags always block, even a manual Include.
        $hard_reasons = self::get_flag_block_reasons($policy_product_id);
        if ($parent_id > 0) {
            $hard_reasons = array_values(array_unique(array_merge($hard_reasons, self::get_flag_block_reasons($product_id))));
        }
        $override = empty($hard_reasons) ? self::get_override($policy_product) : '';
        if ($override !== '') {
            $reason = $override === 'include'
                ? __('Included manually: category rules and text checks are skipped.', 'ffl-funnels-addons')
                : __('Excluded manually.', 'ffl-funnels-addons');
            $decision = [
                'status' => $override === 'include' ? 'allowed' : 'blocked',
                'reason' => $reason,
                'reasons' => [$reason],
                'product_id' => $product_id,
                'parent_id' => $parent_id,
            ];
            self::$decision_cache[$product_id] = $decision;
            return $decision;
        }
        if (empty($hard_reasons)) {
            $hard_reasons = self::get_content_block_reasons($policy_product, $policy_product_id);
            if ($parent_id > 0) {
                $hard_reasons = array_values(array_unique(array_merge($hard_reasons, self::get_content_block_reasons($product, $product_id))));
            }
        }
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
     * Persist the decision and, in enforce mode, make Google for WooCommerce's
     * channel visibility match it in both directions.
     *
     * 'visibility_change' reports what changed: 'included', 'excluded' or ''.
     */
    public static function apply_to_product($product): array
    {
        if (!is_object($product) && function_exists('wc_get_product')) {
            $product = wc_get_product((int) $product);
        }
        if (!is_object($product) || !method_exists($product, 'update_meta_data')) {
            return self::evaluate_product($product) + ['visibility_change' => ''];
        }

        $enforce = (string) (self::get_settings()['mode'] ?? 'audit') === 'enforce';
        $visibility = (string) $product->get_meta(self::VISIBILITY_META, true);
        $is_variation = method_exists($product, 'get_parent_id') && (int) $product->get_parent_id() > 0;
        // Google for WooCommerce stores exclusions on the parent product. One this
        // addon did not write was made by someone on purpose: keep it as a manual
        // exclusion so Enforce never uploads it behind their back.
        if ($enforce && !$is_variation && $visibility === 'dont-sync-and-show'
            && !self::visibility_written_by_addon($product, $visibility) && self::get_override($product) === '') {
            $product->update_meta_data(self::OVERRIDE_META, 'exclude');
            $product->update_meta_data(self::OVERRIDE_SOURCE_META, 'google-for-woocommerce');
            self::reset_runtime_cache();
        }

        $decision = self::evaluate_product($product) + ['visibility_change' => ''];
        $product->update_meta_data(self::STATUS_META, (string) $decision['status']);
        $product->update_meta_data(self::REASON_META, (string) $decision['reason']);
        $product->update_meta_data(self::VERSION_META, self::ENGINE_VERSION);
        $product->update_meta_data(self::CHECKED_META, gmdate('c'));

        if ($enforce) {
            $target = $decision['status'] === 'allowed' ? 'sync-and-show' : 'dont-sync-and-show';
            // Google for WooCommerce treats a missing value as sync-and-show.
            if (($visibility === '' ? 'sync-and-show' : $visibility) !== $target) {
                $product->update_meta_data(self::VISIBILITY_META, $target);
                $product->update_meta_data(self::APPLIED_META, $target);
                $decision['visibility_change'] = $target === 'sync-and-show' ? 'included' : 'excluded';
            }
        }

        $product->save_meta_data();
        return $decision;
    }

    public static function get_override($product): string
    {
        $value = is_object($product) && method_exists($product, 'get_meta')
            ? sanitize_key((string) $product->get_meta(self::OVERRIDE_META, true))
            : '';
        return in_array($value, ['include', 'exclude'], true) ? $value : '';
    }

    /**
     * Record a merchant decision made in this addon. The product's current Google
     * visibility becomes managed by this addon, so it is never kept as a manual
     * exclusion afterwards. Callers save the product.
     */
    public static function set_override($product, string $override): void
    {
        $override = sanitize_key($override);
        if (in_array($override, ['include', 'exclude'], true)) {
            $product->update_meta_data(self::OVERRIDE_META, $override);
            $product->update_meta_data(self::OVERRIDE_SOURCE_META, 'manual');
        } else {
            $product->delete_meta_data(self::OVERRIDE_META);
            $product->delete_meta_data(self::OVERRIDE_SOURCE_META);
        }
        $visibility = (string) $product->get_meta(self::VISIBILITY_META, true);
        if ($visibility !== '') {
            $product->update_meta_data(self::APPLIED_META, $visibility);
        }
        self::reset_runtime_cache();
    }

    private static function visibility_written_by_addon($product, string $visibility): bool
    {
        $applied = (string) $product->get_meta(self::APPLIED_META, true);
        return $applied === $visibility || ($applied === 'yes' && $visibility === 'dont-sync-and-show');
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

    public static function on_wc_product_saved(int $product_id, $product = null): void
    {
        self::reset_runtime_cache();
        $product = is_object($product) ? $product : wc_get_product($product_id);
        if ($product) {
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

    private static function get_flag_block_reasons(int $product_id): array
    {
        $reasons = [];
        foreach (['_firearm_product' => 'firearm', '_ammunition_product' => 'ammunition'] as $meta_key => $label) {
            $value = strtolower((string) get_post_meta($product_id, $meta_key, true));
            if (in_array($value, ['yes', '1', 'true'], true)) {
                $reasons[] = sprintf(__('Hard block: product is marked as %s.', 'ffl-funnels-addons'), $label);
            }
        }
        return $reasons;
    }

    private static function get_content_block_reasons($product, int $product_id): array
    {
        $reasons = [];
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
        if (method_exists($product, 'get_description')) {
            $parts[] = wp_strip_all_tags((string) $product->get_description());
        }
        $terms = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']);
        if (!is_wp_error($terms)) {
            $parts = array_merge($parts, (array) $terms);
        }
        $text = implode(' ', $parts);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);

        // Storage and carrying accessories are not treated as weapons merely
        // because their title contains "gun" or "rifle".
        // Remove explicit accessory phrases, not every firearm signal merely
        // because a description also mentions an included case or a lock.
        $firearm_text = preg_replace('/\b(gun|firearm|handgun|pistol|revolver|rifle|shotgun)s?\s+(?:(?:carrying|storage|hard|soft)\s+)?(?:cases?|safes?|cabinets?|vaults?|locks?|racks?|bags?|holsters?|slings?|cleaning mats?)\b/i', '', $text);
        $patterns = apply_filters('ffla_google_merchant_hard_block_patterns', [
            'ammunition' => '/\b(ammunition|ammo|cartridges?|rounds?|primers?|gunpowder|smokeless powder)\b/i',
            'firearm' => '/\b(firearms?|handguns?|pistols?|revolvers?|rifles?|shotguns?|machine guns?|short[- ]barreled|sbr|nfa)\b/i',
            'regulated_part' => '/\b(receivers?|frames?|barrels?|triggers?|bolt carriers?|upper receivers?|lower receivers?|magazines?|suppressors?|silencers?)\b/i',
            'weapon_accessory' => '/\b(rifle scopes?|gun scopes?|weapon sights?|night vision scopes?|thermal scopes?|less[- ]lethal weapons?|tasers?|tazers?|stun guns?)\b/i',
        ], $product);

        foreach ((array) $patterns as $label => $pattern) {
            $matched = is_string($pattern) ? preg_match($pattern, $label === 'firearm' ? $firearm_text : $text) : false;
            if ($matched === 1) {
                $reasons[] = sprintf(__('Hard block: restricted %s content detected.', 'ffl-funnels-addons'), str_replace('_', ' ', (string) $label));
            }
        }
        return array_values(array_unique($reasons));
    }
}
