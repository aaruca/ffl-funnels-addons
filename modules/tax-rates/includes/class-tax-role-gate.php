<?php
/**
 * Customer tax gate (individual users and role exemption lists).
 *
 * Lets the store owner exempt specific WordPress users and/or a subset of
 * WordPress user roles (plus an optional "guest" pseudo-role for
 * non-logged-in customers) from tax collection. When the gate is inactive,
 * every customer pays tax exactly like before — the feature is opt-in so
 * existing sites don't change behavior on upgrade.
 *
 * Example use case: a wholesale store that taxes retail customers by
 * default and exempts wholesale / B2B roles from tax collection.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Role_Gate
{
    public const SETTINGS_KEY   = 'ffla_tax_resolver_settings';
    public const GUEST_ROLE_KEY = 'guest';

    /** @var array<string,mixed>|null */
    private static $settings_cache = null;

    /** @var array<int,bool>|null */
    private static $exempt_user_set = null;

    /** @var array<int,bool> */
    private static $charge_decisions = [];

    /**
     * Are customer and role tax exemptions currently enabled?
     */
    public static function is_active(): bool
    {
        $settings = self::get_settings();

        return !empty($settings['tax_role_restrict'])
            && (string) $settings['tax_role_restrict'] === '1';
    }

    /**
     * Roles that should be exempt from tax when the gate is active,
     * normalized to a list of slugs. Includes the special
     * `self::GUEST_ROLE_KEY` value for non-logged-in customers when the
     * admin explicitly selected it.
     *
     * @return string[]
     */
    public static function get_exempt_roles(): array
    {
        $settings = self::get_settings();

        // Canonical key is `tax_exempt_roles`. Older 1.14.0 installs used
        // `taxed_roles` with inverted semantics (opt-in list). Since the
        // feature shipped to production for less than a day before being
        // flipped, we simply ignore the legacy key — it would have the
        // wrong meaning under the new model.
        $raw = $settings['tax_exempt_roles'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $slug) {
            if (!is_scalar($slug)) {
                continue;
            }
            $slug = sanitize_key((string) $slug);
            if ($slug === '') {
                continue;
            }
            $clean[$slug] = true;
        }

        return array_keys($clean);
    }

    /**
     * Individual WordPress user IDs that should be exempt from tax.
     *
     * Only positive integers are retained. The normalized list is stored in
     * the existing resolver option and cached for the current PHP request.
     *
     * @return int[]
     */
    public static function get_exempt_user_ids(): array
    {
        return array_keys(self::get_exempt_user_set());
    }

    /**
     * All role choices surfaced in the admin picker: WordPress roles plus
     * the Guest pseudo-role, labeled for display.
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
     * Decide whether the current customer (WooCommerce customer or logged-in
     * user, falling back to the logged-in WP user) should be charged tax.
     *
     * When the gate is inactive, always returns true so the tax resolver
     * keeps running exactly like before. When the gate is active, returns
     * false when the customer's user ID is explicitly exempt or the customer
     * has at least one role that appears in the configured exempt list.
     */
    public static function should_charge_for_current_customer(): bool
    {
        if (!self::is_active()) {
            return true;
        }

        $user_id = self::resolve_customer_user_id();

        if (array_key_exists($user_id, self::$charge_decisions)) {
            return self::$charge_decisions[$user_id];
        }

        $exempt_roles = self::get_exempt_roles();
        $exempt_users = self::get_exempt_user_set();

        if ($user_id > 0 && isset($exempt_users[$user_id])) {
            self::$charge_decisions[$user_id] = false;
            return false;
        }

        if (empty($exempt_roles)) {
            // Gate is on but no matching individual or role exemption exists.
            self::$charge_decisions[$user_id] = true;
            return true;
        }

        if ($user_id <= 0) {
            // Guest customer: exempt only if the admin explicitly checked
            // the Guest pseudo-role.
            self::$charge_decisions[$user_id] = !in_array(self::GUEST_ROLE_KEY, $exempt_roles, true);
            return self::$charge_decisions[$user_id];
        }

        $user = get_userdata($user_id);
        if (!$user || empty($user->roles) || !is_array($user->roles)) {
            // Logged-in user with no role — treat like a guest for the
            // exemption decision.
            self::$charge_decisions[$user_id] = !in_array(self::GUEST_ROLE_KEY, $exempt_roles, true);
            return self::$charge_decisions[$user_id];
        }

        foreach ($user->roles as $role) {
            if (in_array(sanitize_key((string) $role), $exempt_roles, true)) {
                self::$charge_decisions[$user_id] = false;
                return false;
            }
        }

        self::$charge_decisions[$user_id] = true;
        return true;
    }

    /**
     * Read resolver settings once per request. WordPress already object-caches
     * options, but this also avoids repeatedly normalizing the same payload
     * during WooCommerce checkout recalculations.
     *
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
     * Return an O(1)-lookup set of explicitly exempt user IDs.
     *
     * @return array<int,bool>
     */
    private static function get_exempt_user_set(): array
    {
        if (self::$exempt_user_set !== null) {
            return self::$exempt_user_set;
        }

        $raw = self::get_settings()['tax_exempt_user_ids'] ?? [];
        $set = [];

        if (is_array($raw)) {
            foreach ($raw as $user_id) {
                if (!is_scalar($user_id)) {
                    continue;
                }

                $user_id = (int) $user_id;
                if ($user_id > 0) {
                    $set[$user_id] = true;
                }
            }
        }

        self::$exempt_user_set = $set;
        return self::$exempt_user_set;
    }

    /**
     * Resolve the user id of the customer for whom taxes are being
     * calculated. Prefers the WooCommerce customer (supports manual orders
     * edited from the admin on behalf of a specific user) and falls back to
     * the currently logged-in WP user.
     */
    private static function resolve_customer_user_id(): int
    {
        if (function_exists('WC') && WC()) {
            $customer = WC()->customer;
            if ($customer && method_exists($customer, 'get_id')) {
                $id = (int) $customer->get_id();
                if ($id > 0) {
                    return $id;
                }
            }
        }

        if (function_exists('get_current_user_id')) {
            $id = (int) get_current_user_id();
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }
}
