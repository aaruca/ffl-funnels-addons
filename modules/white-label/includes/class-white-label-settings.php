<?php
/**
 * White Label — settings store.
 *
 * The whole module configuration lives in a single wp_options row. This class
 * is the only place that reads or writes it, and it offers dot-path access so
 * callers can pull a nested value in one call.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Settings
{
    /** The single option key that holds the entire module configuration. */
    const OPTION = 'ffla_white_label_settings';

    /** @var array<string, mixed>|null Per-request cache of the stored settings. */
    private static $cache = null;

    /**
     * Read a setting.
     *
     * @param string $path    Dot-notation path to a value:
     *                        - ''                     → the full settings array
     *                        - 'styles'               → the whole "styles" branch
     *                        - 'styles.sidebarColor'  → a nested value
     * @param mixed  $default Returned when the path does not resolve.
     * @return mixed
     */
    public static function get(string $path = '', $default = null)
    {
        $settings = self::all();

        if ('' === $path) {
            return $settings;
        }

        $value = $settings;
        foreach (explode('.', $path) as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /**
     * The full settings array as stored (always an array).
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (null === self::$cache) {
            $stored      = get_option(self::OPTION, []);
            self::$cache = is_array($stored) ? $stored : [];
        }

        return self::$cache;
    }

    /**
     * Replace the whole settings array.
     *
     * @param array<string, mixed> $settings
     */
    public static function save(array $settings): void
    {
        update_option(self::OPTION, $settings, false);
        self::$cache = $settings;
    }

    /**
     * Write a single value by dot-path, creating intermediate branches as
     * needed, then persist. Complements get() for nested writes.
     *
     * @param string $path  Dot-notation path, e.g. 'styles.sidebarColor'.
     * @param mixed  $value
     */
    public static function set(string $path, $value): void
    {
        if ('' === $path) {
            return;
        }

        $settings = self::all();
        $segments = explode('.', $path);
        $branch   = &$settings;

        foreach ($segments as $index => $segment) {
            $is_last = ($index === count($segments) - 1);

            if ($is_last) {
                $branch[$segment] = $value;
                break;
            }

            if (!isset($branch[$segment]) || !is_array($branch[$segment])) {
                $branch[$segment] = [];
            }
            $branch = &$branch[$segment];
        }
        unset($branch);

        self::save($settings);
    }

    /**
     * Drop the per-request cache (e.g. after an external option change).
     */
    public static function flush_cache(): void
    {
        self::$cache = null;
    }
}
