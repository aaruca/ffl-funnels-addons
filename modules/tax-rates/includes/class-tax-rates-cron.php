<?php
/**
 * Tax Rates Cron — Monthly auto-refresh.
 *
 * Schedules a monthly WP-Cron job that re-imports all previously
 * imported US states so rates stay current.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Rates_Cron
{
    const HOOK = 'ffla_tax_rates_refresh';

    public static function init(): void
    {
        // Register custom 30-day interval.
        add_filter('cron_schedules', [__CLASS__, 'add_monthly_schedule']);

        // Hook the refresh callback.
        add_action(self::HOOK, [__CLASS__, 'refresh_all_states']);

        // Schedule on init if auto-refresh is enabled and not yet scheduled.
        add_action('init', [__CLASS__, 'maybe_schedule']);
    }

    /**
     * Add a ~30-day cron interval.
     */
    public static function add_monthly_schedule(array $schedules): array
    {
        if (!isset($schedules['ffla_monthly'])) {
            $schedules['ffla_monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Once a month (FFLA)', 'ffl-funnels-addons'),
            ];
        }
        return $schedules;
    }

    /**
     * Schedule the cron if auto-refresh is on and not already scheduled.
     * Clears it if auto-refresh is disabled.
     */
    public static function maybe_schedule(): void
    {
        $settings     = get_option('ffl_tax_rates_settings', []);
        $auto_refresh = !empty($settings['auto_refresh']);

        if ($auto_refresh) {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time(), 'ffla_monthly', self::HOOK);
            }
        } else {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /**
     * Re-import all states that have been previously imported.
     */
    public static function refresh_all_states(): void
    {
        $settings = get_option('ffl_tax_rates_settings', []);
        $depth    = $settings['rate_depth'] ?? 'county';
        $states   = Tax_Rates_Importer::get_us_states();

        foreach (array_keys($states) as $code) {
            $log = get_option('ffla_tax_import_' . $code, null);
            if ($log !== null) {
                Tax_Rates_Importer::import_state($code, $depth);
            }
        }
    }
}
