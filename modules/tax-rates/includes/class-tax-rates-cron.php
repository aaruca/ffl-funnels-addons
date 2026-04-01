<?php
/**
 * Tax Rates Cron - Dataset sync and maintenance.
 *
 * Schedules:
 *   - Monthly SalesTaxHandbook dataset rebuild
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

    public static function init(): void
    {
        // Register custom schedules.
        add_filter('cron_schedules', [__CLASS__, 'add_schedules']);

        // Hook callbacks.
        add_action(self::SYNC_HOOK, [__CLASS__, 'run_sync']);
        add_action(self::CLEANUP_HOOK, [__CLASS__, 'run_cleanup']);
        add_action(self::PURGE_HOOK, [__CLASS__, 'run_purge']);

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
    }

    /**
     * Run dataset sync.
     */
    public static function run_sync(): void
    {
        if (class_exists('Tax_Dataset_Pipeline')) {
            Tax_Dataset_Pipeline::sync(Tax_Dataset_Pipeline::HANDBOOK_SOURCE_CODE);
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
}
