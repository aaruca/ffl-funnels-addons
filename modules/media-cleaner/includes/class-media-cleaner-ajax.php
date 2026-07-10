<?php
/**
 * Media Cleaner — admin-ajax endpoints.
 *
 * Every endpoint requires the manage_options capability and a valid nonce.
 * These operations delete media site-wide, so the gate is deliberately the
 * strict one, not a shop-manager capability.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Ajax
{
    const NONCE = 'ffla_mclean_admin';

    const CAP = 'manage_options';

    public static function init(): void
    {
        $map = [
            'ffla_mclean_scan_start'  => 'scan_start',
            'ffla_mclean_scan_step'   => 'scan_step',
            'ffla_mclean_scan_abort'  => 'scan_abort',
            'ffla_mclean_results'     => 'results',
            'ffla_mclean_action'      => 'action',
            'ffla_mclean_empty_trash' => 'empty_trash',
        ];

        foreach ($map as $action => $method) {
            add_action('wp_ajax_' . $action, [__CLASS__, $method]);
        }
    }

    /* =====================================================================
     * Guards + factory
     * ================================================================== */

    private static function guard(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    private static function core(): Media_Cleaner_Core
    {
        if (empty($GLOBALS['ffla_mclean']) || !($GLOBALS['ffla_mclean'] instanceof Media_Cleaner_Core)) {
            $GLOBALS['ffla_mclean'] = new Media_Cleaner_Core();
        }

        return $GLOBALS['ffla_mclean'];
    }

    private static function scanner(): Media_Cleaner_Scanner
    {
        return new Media_Cleaner_Scanner(new Media_Cleaner_Engine(self::core()));
    }

    private static function manager(): Media_Cleaner_Manager
    {
        return new Media_Cleaner_Manager(self::core());
    }

    /* =====================================================================
     * Scan
     * ================================================================== */

    public static function scan_start(): void
    {
        self::guard();
        // A fresh scan starts from a clean settings memo (the user may have just
        // saved new options on the same screen).
        Media_Cleaner_Core::flush_settings_memo();
        wp_send_json_success(self::scanner()->start());
    }

    public static function scan_step(): void
    {
        self::guard();

        // Raise limits for this batch where the host allows it.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $result = self::scanner()->step();
        wp_send_json_success($result);
    }

    public static function scan_abort(): void
    {
        self::guard();
        self::scanner()->abort();
        wp_send_json_success(['aborted' => true]);
    }

    /* =====================================================================
     * Results
     * ================================================================== */

    public static function results(): void
    {
        self::guard();

        $status   = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'active';
        $page     = isset($_POST['page']) ? max(1, absint($_POST['page'])) : 1;
        $search   = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
        $per_page = 25;

        $manager = self::manager();
        $data    = $manager->get_issues($status, $page, $per_page, $search);
        $stats   = $manager->get_stats();

        wp_send_json_success([
            'html'       => Media_Cleaner_Admin::render_rows($data['items'], $status),
            'total'      => $data['total'],
            'page'       => $page,
            'pages'      => (int) ceil($data['total'] / $per_page),
            'stats'      => $stats,
            'stats_html' => Media_Cleaner_Admin::render_stats($stats),
        ]);
    }

    /* =====================================================================
     * Actions
     * ================================================================== */

    public static function action(): void
    {
        self::guard();

        $op  = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        $ids = isset($_POST['ids']) ? array_map('absint', (array) wp_unslash($_POST['ids'])) : [];
        $ids = array_filter($ids);

        if (empty($ids)) {
            wp_send_json_error(['message' => __('Nothing selected.', 'ffl-funnels-addons')]);
        }

        $manager = self::manager();
        $done    = 0;

        foreach ($ids as $id) {
            $ok = false;
            switch ($op) {
                case 'trash':
                    $ok = $manager->trash($id);
                    break;
                case 'restore':
                    $ok = $manager->restore($id);
                    break;
                case 'ignore':
                    $ok = $manager->ignore($id, true);
                    break;
                case 'unignore':
                    $ok = $manager->ignore($id, false);
                    break;
                case 'delete':
                    $ok = $manager->delete_permanently($id);
                    break;
                default:
                    wp_send_json_error(['message' => __('Unknown action.', 'ffl-funnels-addons')]);
            }
            if ($ok) {
                $done++;
            }
        }

        wp_send_json_success([
            'processed'  => $done,
            'requested'  => count($ids),
            'stats'      => $manager->get_stats(),
            'stats_html' => Media_Cleaner_Admin::render_stats($manager->get_stats()),
        ]);
    }

    public static function empty_trash(): void
    {
        self::guard();
        $manager = self::manager();
        $count   = $manager->empty_trash();

        wp_send_json_success([
            'emptied'    => $count,
            'stats'      => $manager->get_stats(),
            'stats_html' => Media_Cleaner_Admin::render_stats($manager->get_stats()),
        ]);
    }
}
