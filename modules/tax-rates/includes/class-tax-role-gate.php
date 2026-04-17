<?php
/**
 * User-role tax gate.
 *
 * Lets the store owner restrict tax collection to a subset of WordPress
 * user roles (plus an optional "guest" pseudo-role for non-logged-in
 * customers). When the gate is inactive, every customer pays tax exactly
 * like before — the feature is opt-in so existing sites don't change
 * behavior on upgrade.
 *
 * Example use case: a wholesale store that only charges tax to retail
 * customers ("customer" role) but leaves wholesale/B2B roles untaxed.
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

    /**
     * Is role-based tax gating currently enabled?
     */
    public static function is_active(): bool
    {
        $settings = get_option(self::SETTINGS_KEY, []);
        if (!is_array($settings)) {
            return false;
        }

        return !empty($settings['tax_role_restrict'])
            && (string) $settings['tax_role_restrict'] === '1';
    }

    /**
     * Roles that should pay tax when the gate is active, normalized to a
     * list of slugs. Includes the special `self::GUEST_ROLE_KEY` value for
     * non-logged-in customers when the admin explicitly selected it.
     *
     * @return string[]
     */
    public static function get_allowed_roles(): array
    {
        $settings = get_option(self::SETTINGS_KEY, []);
        $raw      = is_array($settings) ? ($settings['taxed_roles'] ?? []) : [];

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
     * keeps running exactly like before.
     */
    public static function should_charge_for_current_customer(): bool
    {
        if (!self::is_active()) {
            return true;
        }

        $allowed = self::get_allowed_roles();
        if (empty($allowed)) {
            // Gate is on but nothing is checked: safest default is "no one
            // pays tax" rather than "everyone pays tax" — the admin
            // explicitly opted into restriction so we honor it.
            return false;
        }

        $user_id = self::resolve_customer_user_id();

        if ($user_id <= 0) {
            return in_array(self::GUEST_ROLE_KEY, $allowed, true);
        }

        $user = get_userdata($user_id);
        if (!$user || empty($user->roles) || !is_array($user->roles)) {
            return in_array(self::GUEST_ROLE_KEY, $allowed, true);
        }

        foreach ($user->roles as $role) {
            if (in_array(sanitize_key((string) $role), $allowed, true)) {
                return true;
            }
        }

        return false;
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
