<?php
/**
 * Conflict Checker — detects old individual plugins that conflict with FFLA.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Conflict_Checker
{
    /** @var array basename => display name */
    private static $conflicts = [
        'woobooster/woobooster.php'            => 'WooBooster',
        'algenib-wishlist/algenib-wishlist.php' => 'Algenib Wishlist',
        'doofinder-sync/doofinder-sync.php'     => 'Doofinder Sync',
    ];

    /**
     * Register the admin notice hook.
     */
    public static function init(): void
    {
        add_action('admin_notices', [__CLASS__, 'show_conflict_notices']);
    }

    /**
     * Show warning notices for any conflicting plugins that are still active.
     */
    public static function show_conflict_notices(): void
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        foreach (self::$conflicts as $basename => $name) {
            if (is_plugin_active($basename)) {
                $deactivate_url = wp_nonce_url(
                    admin_url('plugins.php?action=deactivate&plugin=' . urlencode($basename)),
                    'deactivate-plugin_' . $basename
                );

                printf(
                    '<div class="notice notice-warning is-dismissible"><p><strong>FFL Funnels Addons:</strong> %s</p><p><a href="%s" class="button button-small">%s</a></p></div>',
                    sprintf(
                        esc_html__('The standalone plugin "%s" is still active. Please deactivate it to avoid conflicts — FFL Funnels Addons already includes this functionality.', 'ffl-funnels-addons'),
                        esc_html($name)
                    ),
                    esc_url($deactivate_url),
                    sprintf(esc_html__('Deactivate %s', 'ffl-funnels-addons'), esc_html($name))
                );
            }
        }
    }
}
