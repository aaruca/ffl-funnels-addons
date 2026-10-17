<?php
/**
 * Keeps long tax-report loops inside PHP's memory limit.
 *
 * WordPress keeps every order, order item, meta row, product and term it reads
 * in its in-memory object cache until the request ends. Report loops read
 * thousands of orders, so that cache, not the report data, is what exhausts
 * memory: on a store without a persistent object cache it grows by roughly a
 * quarter of a megabyte per order. Each loop therefore releases the cache once
 * per page and checks the remaining headroom before it reads the next page.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Report_Memory
{
    /** Share of the memory limit at which a report stops with a clear error. */
    const DEFAULT_CEILING = 0.85;

    /**
     * Give a long report request the time and memory WordPress allows admins.
     */
    public static function prepare(): void
    {
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('wc_set_time_limit')) {
            wc_set_time_limit(0);
        }
    }

    /**
     * Release what the page just processed, then make sure the next page fits.
     *
     * Call it between pages, after every order object of the page has been
     * used. Nothing the reports keep depends on the cache, so emptying it only
     * means later reads go to the database again.
     *
     * @throws RuntimeException When memory use is still near the limit.
     */
    public static function release_page(): void
    {
        self::flush_runtime_cache();
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        self::guard();
    }

    /**
     * Empty the request's in-memory object cache without touching a shared
     * persistent cache (Redis, Memcached) that other visitors rely on.
     */
    public static function flush_runtime_cache(): bool
    {
        if (function_exists('apply_filters') && !apply_filters('ffla_tax_report_flush_runtime_cache', true)) {
            return false;
        }

        if (!function_exists('wp_using_ext_object_cache') || !wp_using_ext_object_cache()) {
            // WordPress' default cache only lives in this request's memory.
            if (function_exists('wp_cache_flush_runtime')) {
                return (bool) wp_cache_flush_runtime();
            }
            return function_exists('wp_cache_flush') ? (bool) wp_cache_flush() : false;
        }

        // With a persistent cache, only a drop-in's own runtime flush is safe.
        return self::drop_in_flushes_runtime_only() ? (bool) wp_cache_flush_runtime() : false;
    }

    /**
     * Stop before PHP's fatal "Allowed memory size exhausted" error, which no
     * try/catch can intercept and which replaces the page with a critical error.
     *
     * @throws RuntimeException When memory use reached the configured ceiling.
     */
    public static function guard(): void
    {
        $limit = self::limit_bytes();
        if ($limit <= 0) {
            return;
        }

        $ceiling = function_exists('apply_filters')
            ? (float) apply_filters('ffla_tax_report_memory_ceiling', self::DEFAULT_CEILING)
            : self::DEFAULT_CEILING;
        $ceiling = min(0.95, max(0.5, $ceiling));

        if (memory_get_usage(true) >= $limit * $ceiling) {
            throw new RuntimeException(__('The report stopped before it reached this server\'s memory limit. Narrow the date range and run it again.', 'ffl-funnels-addons'));
        }
    }

    /**
     * PHP memory limit in bytes, or 0 when unlimited or unknown.
     */
    public static function limit_bytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        if (function_exists('wp_convert_hr_to_bytes')) {
            return max(0, (int) wp_convert_hr_to_bytes($raw));
        }

        $value = (int) $raw;
        switch (strtolower(substr($raw, -1))) {
            case 'g':
                $value *= 1024;
                // no break
            case 'm':
                $value *= 1024;
                // no break
            case 'k':
                $value *= 1024;
        }
        return max(0, $value);
    }

    /**
     * WordPress' compatibility wp_cache_flush_runtime() falls back to
     * wp_cache_flush(), which would empty a shared persistent cache for every
     * visitor. Only a drop-in's own implementation clears just its local copy.
     */
    private static function drop_in_flushes_runtime_only(): bool
    {
        if (!function_exists('wp_cache_flush_runtime')) {
            return false;
        }
        try {
            $file = (string) (new ReflectionFunction('wp_cache_flush_runtime'))->getFileName();
        } catch (ReflectionException $e) {
            return false;
        }
        return $file !== '' && basename($file) !== 'cache-compat.php';
    }
}
