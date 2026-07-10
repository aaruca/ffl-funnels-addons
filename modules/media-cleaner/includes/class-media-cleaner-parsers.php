<?php
/**
 * Media Cleaner — parser loader.
 *
 * Each parser is a small file that hooks the scan actions and contributes
 * references. Parsers reach the engine through the global $ffla_mclean core
 * instance (set in the module bootstrap), mirroring the well-worn
 * Media-Cleaner pattern so new parsers are trivial drop-ins.
 *
 * Scan hooks a parser may use:
 *   - do_action  'ffla_mclean_scan_once'                 (site-wide, once)
 *   - do_action  'ffla_mclean_scan_widget'   ($widget)
 *   - do_action  'ffla_mclean_scan_postmeta' ($post_id)
 *   - do_action  'ffla_mclean_scan_post'     ($html, $post_id)
 *   - add_filter 'ffla_mclean_post_html'     ($html, $post_id)  // inject builder markup
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Parsers
{
    /**
     * Load every parser file. Built-in parsers first, then any dropped into the
     * parsers/ directory, then whatever a filter appends.
     */
    public static function load(string $dir): void
    {
        // Deterministic order for the ones that ship with the module.
        $ordered = [
            'common.php',
            'bricks.php',
            'woocommerce.php',
            'acf.php',
            'page-builders.php',
            'ffla-self.php',
        ];

        $loaded = [];
        foreach ($ordered as $file) {
            $path = trailingslashit($dir) . $file;
            if (is_readable($path)) {
                require_once $path;
                $loaded[$file] = true;
            }
        }

        // Auto-discover any additional parser files.
        foreach (glob(trailingslashit($dir) . '*.php') ?: [] as $path) {
            $file = basename($path);
            if (isset($loaded[$file]) || $file === 'index.php') {
                continue;
            }
            require_once $path;
        }

        do_action('ffla_mclean_parsers_loaded', $dir);
    }
}
