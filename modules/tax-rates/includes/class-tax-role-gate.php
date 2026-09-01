<?php
/**
 * Customer, role, and product-scoped tax exemption rules.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolve full-order and product-scoped tax exemptions.
 *
 * The original role gate remains available for backwards compatibility. New
 * conditional rules are evaluated per WooCommerce product so mixed carts can
 * contain both taxable and exempt lines without exempting shipping.
 */
class Tax_Role_Gate
{
    public const SETTINGS_KEY   = 'ffla_tax_resolver_settings';
    public const GUEST_ROLE_KEY = 'guest';
    public const MAX_RULES      = 100;

    /** @var array<string,mixed>|null */
    private static $settings_cache = null;

    /** @var array<int,bool>|null */
    private static $exempt_user_set = null;

    /** @var array<int,bool> */
    private static $charge_decisions = [];

    /** @var array<int,array<string,mixed>>|null */
    private static $conditional_rules_cache = null;

    /** @var array<string,mixed>|null */
    private static $customer_context_cache = null;

    /** @var array<string,array<int,array<string,mixed>>> */
    private static $product_match_cache = [];

    /** @var array<int,array<string,array<int,bool>>> */
    private static $product_term_cache = [];

    /** @var array<string,array<int,bool>> */
    private static $category_scope_cache = [];

    /**
     * Are customer, role, and conditional tax exemptions enabled?
     */
    public static function is_active(): bool
    {
        $settings = self::get_settings();

        return !empty($settings['tax_role_restrict'])
            && (string) $settings['tax_role_restrict'] === '1';
    }

    /**
     * Roles that receive the legacy full-order exemption.
     *
     * @return string[]
     */
    public static function get_exempt_roles(): array
    {
        $settings = self::get_settings();
        $raw = $settings['tax_exempt_roles'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        return self::normalize_role_slugs($raw);
    }

    /**
     * Individual users that receive the legacy full-order exemption.
     *
     * @return int[]
     */
    public static function get_exempt_user_ids(): array
    {
        return array_keys(self::get_exempt_user_set());
    }

    /**
     * All role choices surfaced in the admin picker.
     *
     * @return array<string,string> slug => human-readable label.
     */
    public static function get_role_choices(): array
    {
        $choices = [
            self::GUEST_ROLE_KEY => __('Guest (not logged in)', 'ffl-funnels-addons'),
        ];

        $wp_roles = function_exists('wp_roles') ? wp_roles() : null;
        if ($wp_roles && isset($wp_roles->role_names) && is_array($wp_roles->role_names)) {
            foreach ($wp_roles->role_names as $slug => $label) {
                $slug = sanitize_key((string) $slug);
                if ($slug === '' || $slug === self::GUEST_ROLE_KEY) {
                    continue;
                }
                $choices[$slug] = (string) translate_user_role((string) $label);
            }
        }

        return $choices;
    }

    /**
     * Normalize conditional exemption rules for storage and runtime use.
     *
     * Empty audiences or product scopes are retained for editing but can never
     * match at runtime. IDs are stored instead of names so taxonomy renames do
     * not silently break a rule.
     *
     * @param mixed $raw_rules Untrusted settings or request data.
     * @return array<int,array<string,mixed>>
     */
    public static function sanitize_conditional_rules($raw_rules): array
    {
        if (!is_array($raw_rules)) {
            return [];
        }

        $rules = [];
        foreach (array_slice(array_values($raw_rules), 0, self::MAX_RULES) as $index => $raw_rule) {
            if (!is_array($raw_rule)) {
                continue;
            }

            $id = sanitize_key((string) ($raw_rule['id'] ?? ''));
            if ($id === '') {
                $seed = wp_json_encode($raw_rule) . '|' . microtime(true) . '|' . $index;
                $id = 'rule-' . substr(md5($seed), 0, 12);
            }

            $name = sanitize_text_field((string) ($raw_rule['name'] ?? ''));
            if ($name === '') {
                $name = sprintf(
                    /* translators: %d: exemption rule number. */
                    __('Exemption rule %d', 'ffl-funnels-addons'),
                    $index + 1
                );
            }

            $rules[] = [
                'id'           => substr($id, 0, 64),
                'name'         => substr($name, 0, 120),
                'enabled'      => !empty($raw_rule['enabled']) && (string) $raw_rule['enabled'] !== '0' ? '1' : '0',
                'user_ids'     => self::normalize_positive_ids($raw_rule['user_ids'] ?? [], 500),
                'roles'        => self::normalize_role_slugs($raw_rule['roles'] ?? []),
                'category_ids' => self::normalize_positive_ids($raw_rule['category_ids'] ?? [], 500),
                'tag_ids'      => self::normalize_positive_ids($raw_rule['tag_ids'] ?? [], 500),
            ];
        }

        return $rules;
    }

    /**
     * Get stored conditional rules.
     *
     * @param bool $enabled_only Return only enabled, complete rules.
     * @return array<int,array<string,mixed>>
     */
    public static function get_conditional_rules(bool $enabled_only = false): array
    {
        if (self::$conditional_rules_cache === null) {
            self::$conditional_rules_cache = self::sanitize_conditional_rules(
                self::get_settings()['tax_exemption_rules'] ?? []
            );
        }

        if (!$enabled_only) {
            return self::$conditional_rules_cache;
        }

        return array_values(array_filter(self::$conditional_rules_cache, function (array $rule): bool {
            return (string) ($rule['enabled'] ?? '0') === '1' && self::is_rule_complete($rule);
        }));
    }

    /**
     * A rule must have at least one audience and one product taxonomy scope.
     */
    public static function is_rule_complete(array $rule): bool
    {
        $has_audience = !empty($rule['user_ids']) || !empty($rule['roles']);
        $has_scope = !empty($rule['category_ids']) || !empty($rule['tag_ids']);

        return $has_audience && $has_scope;
    }

    /**
     * Return every conditional rule matching the current customer and product.
     *
     * Parent categories automatically include every descendant. Variations are
     * evaluated against the taxonomy terms assigned to their parent product.
     *
     * @param mixed $product WC_Product instance or product ID.
     * @return array<int,array<string,mixed>> Audit-safe rule match snapshots.
     */
    public static function get_matching_rules_for_product($product): array
    {
        if (!self::is_active()) {
            return [];
        }

        $rules = self::get_conditional_rules(true);
        if (empty($rules)) {
            return [];
        }

        if (!is_object($product) && is_numeric($product) && function_exists('wc_get_product')) {
            $product = wc_get_product((int) $product);
        }
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return [];
        }

        $product_id = (int) $product->get_id();
        $parent_id = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $taxonomy_product_id = $parent_id > 0 ? $parent_id : $product_id;
        if ($taxonomy_product_id <= 0) {
            return [];
        }

        $context = self::get_current_customer_context();
        $cache_key = self::context_cache_key($context) . '|' . $taxonomy_product_id;
        if (array_key_exists($cache_key, self::$product_match_cache)) {
            return self::$product_match_cache[$cache_key];
        }

        $term_sets = self::get_product_term_sets($taxonomy_product_id);
        $matches = [];

        foreach ($rules as $rule) {
            $audience = self::match_rule_audience($rule, $context);
            if ($audience === '') {
                continue;
            }

            $category_scope = self::get_category_scope($rule['category_ids']);
            $matched_categories = array_keys(array_intersect_key($term_sets['product_cat'], $category_scope));
            $tag_scope = array_fill_keys($rule['tag_ids'], true);
            $matched_tags = array_keys(array_intersect_key($term_sets['product_tag'], $tag_scope));

            if (empty($matched_categories) && empty($matched_tags)) {
                continue;
            }

            $matches[] = [
                'id'                   => (string) $rule['id'],
                'name'                 => (string) $rule['name'],
                'matched_audience'     => $audience,
                'matched_category_ids' => array_values(array_map('intval', $matched_categories)),
                'matched_tag_ids'      => array_values(array_map('intval', $matched_tags)),
            ];
        }

        self::$product_match_cache[$cache_key] = $matches;
        return $matches;
    }

    /**
     * Should this product line be exempt for the current customer?
     *
     * @param mixed $product WC_Product instance or product ID.
     */
    public static function should_exempt_product($product): bool
    {
        return !empty(self::get_matching_rules_for_product($product));
    }

    /**
     * Decide whether the current customer receives the legacy full-order
     * exemption. Conditional product rules deliberately do not participate in
     * this decision so shipping and unrelated products remain taxable.
     */
    public static function should_charge_for_current_customer(): bool
    {
        if (!self::is_active()) {
            return true;
        }

        $context = self::get_current_customer_context();
        $user_id = (int) $context['user_id'];

        if (array_key_exists($user_id, self::$charge_decisions)) {
            return self::$charge_decisions[$user_id];
        }

        $exempt_roles = self::get_exempt_roles();
        $exempt_users = self::get_exempt_user_set();

        if ($user_id > 0 && isset($exempt_users[$user_id])) {
            self::$charge_decisions[$user_id] = false;
            return false;
        }

        foreach ($context['roles'] as $role) {
            if (in_array($role, $exempt_roles, true)) {
                self::$charge_decisions[$user_id] = false;
                return false;
            }
        }

        self::$charge_decisions[$user_id] = true;
        return true;
    }

    /**
     * Current customer context used by global and conditional rules.
     *
     * @return array{user_id:int,roles:array<int,string>}
     */
    public static function get_current_customer_context(): array
    {
        if (self::$customer_context_cache !== null) {
            return self::$customer_context_cache;
        }

        $user_id = self::resolve_customer_user_id();
        $roles = [];

        if ($user_id > 0) {
            $user = get_userdata($user_id);
            if ($user && !empty($user->roles) && is_array($user->roles)) {
                $roles = self::normalize_role_slugs($user->roles);
            }
        }

        if (empty($roles)) {
            $roles = [self::GUEST_ROLE_KEY];
        }

        self::$customer_context_cache = [
            'user_id' => $user_id,
            'roles'   => $roles,
        ];

        return self::$customer_context_cache;
    }

    /**
     * Clear request caches after programmatic settings/customer changes.
     */
    public static function reset_runtime_cache(): void
    {
        self::$settings_cache = null;
        self::$exempt_user_set = null;
        self::$charge_decisions = [];
        self::$conditional_rules_cache = null;
        self::$customer_context_cache = null;
        self::$product_match_cache = [];
        self::$product_term_cache = [];
        self::$category_scope_cache = [];
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_settings(): array
    {
        if (self::$settings_cache !== null) {
            return self::$settings_cache;
        }

        $settings = get_option(self::SETTINGS_KEY, []);
        self::$settings_cache = is_array($settings) ? $settings : [];

        return self::$settings_cache;
    }

    /**
     * @return array<int,bool>
     */
    private static function get_exempt_user_set(): array
    {
        if (self::$exempt_user_set !== null) {
            return self::$exempt_user_set;
        }

        self::$exempt_user_set = array_fill_keys(
            self::normalize_positive_ids(self::get_settings()['tax_exempt_user_ids'] ?? [], 500),
            true
        );

        return self::$exempt_user_set;
    }

    /**
     * Resolve the WooCommerce customer, falling back to the logged-in user.
     */
    private static function resolve_customer_user_id(): int
    {
        $user_id = 0;
        $resolved_from_order = false;

        $doing_ajax = function_exists('wp_doing_ajax')
            ? wp_doing_ajax()
            : (defined('DOING_AJAX') && DOING_AJAX);
        if ($doing_ajax && function_exists('current_user_can') && current_user_can('manage_woocommerce')
            && function_exists('wc_get_order')) {
            $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ($order_id > 0) {
                $order = wc_get_order($order_id);
                if ($order && method_exists($order, 'get_customer_id')) {
                    $user_id = max(0, (int) $order->get_customer_id());
                    $resolved_from_order = true;
                }
            }
        }

        if (!$resolved_from_order && function_exists('WC') && WC()) {
            $customer = WC()->customer;
            if ($customer && method_exists($customer, 'get_id')) {
                $user_id = max(0, (int) $customer->get_id());
            }
        }

        if (!$resolved_from_order && $user_id <= 0 && function_exists('get_current_user_id')) {
            $user_id = max(0, (int) get_current_user_id());
        }

        return max(0, (int) apply_filters('ffla_tax_exemption_customer_user_id', $user_id));
    }

    /**
     * @return string Matching audience token or an empty string.
     */
    private static function match_rule_audience(array $rule, array $context): string
    {
        $user_id = (int) ($context['user_id'] ?? 0);
        if ($user_id > 0 && in_array($user_id, $rule['user_ids'], true)) {
            return 'user:' . $user_id;
        }

        foreach ($context['roles'] as $role) {
            if (in_array($role, $rule['roles'], true)) {
                return 'role:' . $role;
            }
        }

        return '';
    }

    /**
     * @return array<string,array<int,bool>>
     */
    private static function get_product_term_sets(int $product_id): array
    {
        if (isset(self::$product_term_cache[$product_id])) {
            return self::$product_term_cache[$product_id];
        }

        $sets = [
            'product_cat' => [],
            'product_tag' => [],
        ];

        foreach (array_keys($sets) as $taxonomy) {
            $terms = function_exists('get_the_terms') ? get_the_terms($product_id, $taxonomy) : [];
            if (!is_array($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (is_object($term) && isset($term->term_id)) {
                    $sets[$taxonomy][(int) $term->term_id] = true;
                }
            }
        }

        self::$product_term_cache[$product_id] = $sets;
        return self::$product_term_cache[$product_id];
    }

    /**
     * Expand selected parent categories to all descendants once per request.
     *
     * @param int[] $category_ids
     * @return array<int,bool>
     */
    private static function get_category_scope(array $category_ids): array
    {
        sort($category_ids, SORT_NUMERIC);
        $cache_key = implode(',', $category_ids);
        if (isset(self::$category_scope_cache[$cache_key])) {
            return self::$category_scope_cache[$cache_key];
        }

        $scope = [];
        foreach ($category_ids as $category_id) {
            $category_id = (int) $category_id;
            if ($category_id <= 0) {
                continue;
            }
            $scope[$category_id] = true;

            if (!function_exists('get_term_children')) {
                continue;
            }
            $children = get_term_children($category_id, 'product_cat');
            if (!is_array($children)) {
                continue;
            }
            foreach ($children as $child_id) {
                $child_id = (int) $child_id;
                if ($child_id > 0) {
                    $scope[$child_id] = true;
                }
            }
        }

        self::$category_scope_cache[$cache_key] = $scope;
        return self::$category_scope_cache[$cache_key];
    }

    /**
     * @param mixed $raw_ids
     * @return int[]
     */
    private static function normalize_positive_ids($raw_ids, int $limit): array
    {
        if (!is_array($raw_ids)) {
            return [];
        }

        $ids = [];
        foreach (array_slice($raw_ids, 0, $limit) as $raw_id) {
            if (!is_scalar($raw_id)) {
                continue;
            }
            $id = (int) $raw_id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids, SORT_NUMERIC);

        return array_values($ids);
    }

    /**
     * @param mixed $raw_roles
     * @return string[]
     */
    private static function normalize_role_slugs($raw_roles): array
    {
        if (!is_array($raw_roles)) {
            return [];
        }

        $roles = [];
        foreach (array_slice($raw_roles, 0, 100) as $raw_role) {
            if (!is_scalar($raw_role)) {
                continue;
            }
            $role = sanitize_key((string) $raw_role);
            if ($role !== '') {
                $roles[$role] = $role;
            }
        }
        ksort($roles);

        return array_values($roles);
    }

    private static function context_cache_key(array $context): string
    {
        return (int) ($context['user_id'] ?? 0) . '|' . implode(',', $context['roles'] ?? []);
    }
}
