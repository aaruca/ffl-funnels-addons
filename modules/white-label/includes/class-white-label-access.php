<?php
/**
 * White Label — access / exemption resolver.
 *
 * Clients log in as full Administrators, so restrictions can't key off roles or
 * capabilities. Instead, YOUR staff are exempted by email pattern (glob-style,
 * e.g. "*@fflfunnels.com" or "adeel*"); everyone else is a "client" and gets the
 * restrictions. A wp-config constant (FFLA_WL_SUPERUSERS) is an additional,
 * tamper-proof exemption list so you can never be locked out from the dashboard.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Access
{
    /** Tamper-proof exemption list (array of email patterns) defined in wp-config. */
    const SUPERUSERS_CONSTANT = 'FFLA_WL_SUPERUSERS';

    /** @var array<int, bool> Per-request memo, keyed by user ID. */
    private static $cache = [];

    /**
     * Whether the given user (default: current) bypasses all restrictions.
     */
    public static function is_exempt(?WP_User $user = null): bool
    {
        if (!$user instanceof WP_User) {
            $user = wp_get_current_user();
        }
        if (!$user->exists()) {
            return false;
        }

        $user_id = (int) $user->ID;
        if (array_key_exists($user_id, self::$cache)) {
            return self::$cache[$user_id];
        }

        $exempt = self::determine($user);

        /**
         * Filter the final exemption decision.
         *
         * @param bool    $exempt Whether the user bypasses restrictions.
         * @param WP_User $user   The user being evaluated.
         */
        self::$cache[$user_id] = (bool) apply_filters('ffla_wl_is_exempt', $exempt, $user);

        return self::$cache[$user_id];
    }

    /**
     * Convenience wrapper for the current user.
     */
    public static function current_user_is_exempt(): bool
    {
        return self::is_exempt(wp_get_current_user());
    }

    /**
     * Whether restrictions are "live". They only apply once staff exemption is
     * configured, so an operator can set up who is exempt before anything bites
     * (and can't lock themselves out mid-setup).
     */
    public static function restrictions_active(): bool
    {
        if (!empty(self::exempt_patterns())) {
            return true;
        }

        if (defined(self::SUPERUSERS_CONSTANT)) {
            $list = constant(self::SUPERUSERS_CONSTANT);
            if (is_array($list) && array_filter(array_map('trim', $list))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Test one email against one glob pattern ("*" matches any run of chars).
     */
    public static function email_matches(string $email, string $pattern): bool
    {
        $email   = trim($email);
        $pattern = trim($pattern);
        if ('' === $email || '' === $pattern) {
            return false;
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

        return (bool) preg_match($regex, $email);
    }

    /**
     * Drop the per-request memo (used after a settings save in the same request).
     */
    public static function flush_cache(): void
    {
        self::$cache = [];
    }

    /* =====================================================================
     * Internals
     * ================================================================== */

    private static function determine(WP_User $user): bool
    {
        if (is_multisite() && is_super_admin($user->ID)) {
            return true;
        }

        $email = (string) $user->user_email;

        if (self::matches_constant($email)) {
            return true;
        }

        foreach (self::exempt_patterns() as $pattern) {
            if (self::email_matches($email, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exempt email patterns saved in the module settings.
     *
     * @return array<int, string>
     */
    private static function exempt_patterns(): array
    {
        $patterns = White_Label_Settings::get('restrictions.exempt_emails', []);

        return is_array($patterns) ? $patterns : [];
    }

    private static function matches_constant(string $email): bool
    {
        if (!defined(self::SUPERUSERS_CONSTANT)) {
            return false;
        }
        $list = constant(self::SUPERUSERS_CONSTANT);
        if (!is_array($list)) {
            return false;
        }

        foreach ($list as $pattern) {
            if (self::email_matches($email, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }
}
