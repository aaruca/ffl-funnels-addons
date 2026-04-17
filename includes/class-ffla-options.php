<?php
/**
 * FFLA_Options — unified option accessor with legacy-key fallback.
 *
 * Historically the plugin's modules stored settings under ad-hoc prefixes
 * (`alg_wishlist_*`, `wss_*`, `woobooster_*`). This helper lets new code use a
 * single canonical key (usually the `ffla_*` variant) while still reading the
 * legacy option so existing production data keeps working without a
 * destructive data migration.
 *
 * Usage:
 *   $settings = FFLA_Options::get('wss_settings', [], 'ffla_wss_settings');
 *   FFLA_Options::update('wss_settings', $settings, 'ffla_wss_settings');
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Options
{
    /**
     * Read an option, preferring the canonical key but falling back to a
     * legacy key when the canonical one does not yet exist.
     *
     * @param string $legacy_key  The legacy option name (e.g. `wss_settings`).
     * @param mixed  $default     Default returned when neither key is present.
     * @param string $ffla_key    Optional canonical `ffla_*` key. When empty,
     *                            the legacy key is used as-is.
     * @return mixed
     */
    public static function get(string $legacy_key, $default = false, string $ffla_key = '')
    {
        $ffla_key = $ffla_key !== '' ? $ffla_key : $legacy_key;

        $sentinel = '__ffla_opt_missing__';
        $value    = get_option($ffla_key, $sentinel);
        if ($value !== $sentinel) {
            return $value;
        }

        if ($legacy_key !== $ffla_key) {
            $legacy_value = get_option($legacy_key, $sentinel);
            if ($legacy_value !== $sentinel) {
                return $legacy_value;
            }
        }

        return $default;
    }

    /**
     * Write an option. When a canonical key is provided, both the canonical
     * and legacy keys are kept in sync so downstream code reading either key
     * sees the same value (makes rollbacks painless and callers migrating
     * on-the-fly don't split settings across keys).
     *
     * @param string $legacy_key Legacy option name.
     * @param mixed  $value      Value to store.
     * @param string $ffla_key   Optional canonical `ffla_*` key.
     * @param bool   $autoload   Whether to autoload (mirrored on both keys).
     * @return bool True if either write succeeded.
     */
    public static function update(string $legacy_key, $value, string $ffla_key = '', bool $autoload = false): bool
    {
        $ok_legacy = update_option($legacy_key, $value, $autoload);
        if ($ffla_key !== '' && $ffla_key !== $legacy_key) {
            $ok_ffla = update_option($ffla_key, $value, $autoload);
            return $ok_legacy || $ok_ffla;
        }
        return $ok_legacy;
    }

    /**
     * Delete both legacy and canonical copies of an option.
     */
    public static function delete(string $legacy_key, string $ffla_key = ''): bool
    {
        $ok_legacy = delete_option($legacy_key);
        if ($ffla_key !== '' && $ffla_key !== $legacy_key) {
            $ok_ffla = delete_option($ffla_key);
            return $ok_legacy || $ok_ffla;
        }
        return $ok_legacy;
    }
}
