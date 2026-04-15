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
    private const DEFAULT_SYNC_TIME = '02:00';

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
            $settings = get_option('wss_settings', []);
            $time     = isset($settings['sync_time']) ? (string) $settings['sync_time'] : self::DEFAULT_SYNC_TIME;
            wp_schedule_event(self::next_timestamp_for_time($time), 'daily', 'wss_daily_sync');
        }
    }

    /**
     * Recreate the schedule using the current settings sync_time.
     */
    public static function reschedule(): void
    {
        self::unschedule();
        self::schedule();
    }

    /**
     * Unschedule all cron events.
     */
    public static function unschedule(): void
    {
        wp_clear_scheduled_hook('wss_daily_sync');
    }

    /**
     * Compute next daily run timestamp for HH:MM in site timezone.
     */
    private static function next_timestamp_for_time(string $hhmm): int
    {
        if (!preg_match('/^\d{2}:\d{2}$/', $hhmm)) {
            $hhmm = self::DEFAULT_SYNC_TIME;
        }

        [$hour, $minute] = array_map('intval', explode(':', $hhmm));
        $hour   = max(0, min(23, $hour));
        $minute = max(0, min(59, $minute));

        $tz  = wp_timezone();
        $now = new DateTime('now', $tz);
        $run = new DateTime('now', $tz);
        $run->setTime($hour, $minute, 0);

        if ($run <= $now) {
            $run->modify('+1 day');
        }

        return $run->getTimestamp();
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

            WSS_Sync_Orchestrator::run_all($sheets, $logger);
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
