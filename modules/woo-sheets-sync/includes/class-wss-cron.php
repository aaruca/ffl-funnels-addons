<?php
/**
 * WSS Cron — Manages the daily sync scheduled event.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Cron
{
    /**
     * Initialize cron hooks.
     */
    public function init(): void
    {
        add_action('wss_daily_sync', [$this, 'run_sync']);
    }

    /**
     * Schedule the daily sync event.
     */
    public static function schedule(): void
    {
        if (!wp_next_scheduled('wss_daily_sync')) {
            wp_schedule_event(time(), 'daily', 'wss_daily_sync');
        }
    }

    /**
     * Unschedule all cron events.
     */
    public static function unschedule(): void
    {
        wp_clear_scheduled_hook('wss_daily_sync');
    }

    /**
     * Run the sync via cron.
     */
    public function run_sync(): void
    {
        $settings = get_option('wss_settings', []);

        if (empty($settings['sheet_id'])) {
            return;
        }

        try {
            $oauth  = new WSS_Google_OAuth();
            $sheets = new WSS_Google_Sheets($oauth);
            $logger = new WSS_Logger();
            $engine = new WSS_Sync_Engine($sheets, $logger, $settings);

            $engine->run();
        } catch (\Throwable $e) {
            $logger = new WSS_Logger();
            $logger->log('woo_to_sheet', 0, 0, 'error', 'Cron sync failed: ' . $e->getMessage());

            update_option('wss_last_sync', [
                'time'  => current_time('mysql'),
                'error' => $e->getMessage(),
            ], false);
        }
    }
}
