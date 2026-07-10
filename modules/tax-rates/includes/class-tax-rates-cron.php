<?php
/**
 * Tax Rates Cron - Sheet dataset sync and maintenance.
 *
 * Schedules:
 *   - Monthly rebuild of Google Sheet state datasets
 *   - Daily quote-cache cleanup
 *   - Weekly audit-log purge
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Rates_Cron
{
    const SYNC_HOOK    = 'ffla_tax_dataset_sync';
    const CLEANUP_HOOK = 'ffla_tax_cache_cleanup';
    const PURGE_HOOK   = 'ffla_tax_audit_purge';
    const FLUSH_HOOK   = 'ffla_tax_cache_flush';

    /** Timestamp of the last full cache flush (manual or automatic). */
    const LAST_FLUSH_OPTION = 'ffla_tax_last_cache_flush';

    /** Map the saved interval setting onto a WP-Cron recurrence. */
    const FLUSH_RECURRENCE = [
        'daily'   => 'daily',
        'weekly'  => 'weekly',
        'monthly' => 'ffla_monthly',
    ];

    public static function init(): void
    {
        // Register custom schedules.
        add_filter('cron_schedules', [__CLASS__, 'add_schedules']);

        // Hook callbacks.
        add_action(self::SYNC_HOOK, [__CLASS__, 'run_sync']);
        add_action(self::CLEANUP_HOOK, [__CLASS__, 'run_cleanup']);
        add_action(self::PURGE_HOOK, [__CLASS__, 'run_purge']);
        add_action(self::FLUSH_HOOK, [__CLASS__, 'run_cache_flush']);

        // Schedule on init.
        add_action('init', [__CLASS__, 'maybe_schedule']);
    }

    /**
     * Add custom cron schedules.
     */
    public static function add_schedules(array $schedules): array
    {
        if (!isset($schedules['ffla_monthly'])) {
            $schedules['ffla_monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Monthly (FFLA Tax)', 'ffl-funnels-addons'),
            ];
        }

        return $schedules;
    }

    /**
     * Schedule cron jobs if not already scheduled.
     */
    public static function maybe_schedule(): void
    {
        $settings = get_option('ffla_tax_resolver_settings', []);
        $scheduled_sync = wp_get_scheduled_event(self::SYNC_HOOK);

        // Dataset sync.
        if (!empty($settings['auto_sync'])) {
            if (!$scheduled_sync || $scheduled_sync->schedule !== 'ffla_monthly') {
                wp_clear_scheduled_hook(self::SYNC_HOOK);
                wp_schedule_event(time(), 'ffla_monthly', self::SYNC_HOOK);
            }
        } else {
            wp_clear_scheduled_hook(self::SYNC_HOOK);
        }

        // Cache cleanup (daily).
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CLEANUP_HOOK);
        }

        // Audit purge (weekly).
        if (!wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_event(time(), 'weekly', self::PURGE_HOOK);
        }

        // Full cache flush — opt-in, and rescheduled when the interval changes.
        $interval   = (string) ($settings['cache_flush_interval'] ?? 'never');
        $recurrence = self::FLUSH_RECURRENCE[$interval] ?? '';

        if ('' === $recurrence) {
            wp_clear_scheduled_hook(self::FLUSH_HOOK);
            return;
        }

        $scheduled_flush = wp_get_scheduled_event(self::FLUSH_HOOK);
        if (!$scheduled_flush || $scheduled_flush->schedule !== $recurrence) {
            // Clear first: wp_schedule_event() is a no-op while an event with a
            // different recurrence is still registered, so changing the setting
            // would otherwise never take effect.
            wp_clear_scheduled_hook(self::FLUSH_HOOK);
            wp_schedule_event(time() + self::flush_interval_seconds($recurrence), $recurrence, self::FLUSH_HOOK);
        }
    }

    /**
     * Seconds until the first run of a newly-scheduled flush.
     *
     * Delayed by one full interval so saving settings never wipes the cache
     * immediately (every cleared address costs a billed API call to re-resolve).
     */
    private static function flush_interval_seconds(string $recurrence): int
    {
        $schedules = wp_get_schedules();

        return isset($schedules[$recurrence]['interval'])
            ? (int) $schedules[$recurrence]['interval']
            : DAY_IN_SECONDS;
    }

    /**
     * Run sheet dataset sync.
     */
    public static function run_sync(): void
    {
        if (class_exists('Tax_Dataset_Pipeline')) {
            Tax_Dataset_Pipeline::sync(Tax_Dataset_Pipeline::SHEET_SOURCE_CODE);
        }
    }

    /**
     * Run cache cleanup.
     */
    public static function run_cleanup(): void
    {
        if (class_exists('Tax_Resolver_DB')) {
            Tax_Resolver_DB::cleanup_cache();
        }
    }

    /**
     * Run audit log purge.
     */
    public static function run_purge(): void
    {
        if (class_exists('Tax_Resolver_DB')) {
            Tax_Resolver_DB::purge_audit(90);
        }
    }

    /**
     * Empty the whole address cache (scheduled "auto-clear").
     *
     * Distinct from run_cleanup(), which only removes already-expired rows.
     */
    public static function run_cache_flush(): void
    {
        if (!class_exists('Tax_Resolver_DB')) {
            return;
        }

        Tax_Resolver_DB::flush_address_cache();
        update_option(self::LAST_FLUSH_OPTION, time(), false);
    }
}
