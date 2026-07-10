<?php
/**
 * Media Cleaner — scheduled trash auto-empty.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Cron
{
    const HOOK = 'ffla_mclean_auto_empty';

    public static function init(): void
    {
        add_action(self::HOOK, [__CLASS__, 'run']);

        // Make sure the schedule matches the current setting (covers upgrades
        // and any missed reschedule).
        if (!wp_next_scheduled(self::HOOK) && '0' !== Media_Cleaner_Core::get_setting('trash_auto_empty_days', '0')) {
            self::reschedule();
        }
    }

    /**
     * Align the daily sweep with the setting: scheduled when a retention window
     * is set, cleared when it is "Never".
     */
    public static function reschedule(): void
    {
        $days = (int) Media_Cleaner_Core::get_setting('trash_auto_empty_days', '0');

        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }

        if ($days > 0) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    public static function clear(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
            $timestamp = wp_next_scheduled(self::HOOK);
        }
    }

    /**
     * Permanently remove trashed issues older than the retention window.
     */
    public static function run(): void
    {
        $days = (int) Media_Cleaner_Core::get_setting('trash_auto_empty_days', '0');
        if ($days <= 0) {
            return;
        }

        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();

        // `time` is stamped in site time when an item is trashed; compare in the
        // same space.
        $cutoff = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - ($days * DAY_IN_SECONDS));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE deleted = 1 AND time <= %s",
            $cutoff
        ));

        if (empty($ids)) {
            return;
        }

        $manager = new Media_Cleaner_Manager(new Media_Cleaner_Core());
        foreach ($ids as $id) {
            $manager->delete_permanently((int) $id);
        }
    }
}
