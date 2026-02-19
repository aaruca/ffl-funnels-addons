<?php
/**
 * WooBooster Cron — Manages scheduled events for Smart Recommendations.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Cron
{

    /**
     * Initialize cron hooks.
     */
    public function init()
    {
        add_action('woobooster_copurchase_event', array($this, 'run_copurchase'));
        add_action('woobooster_trending_event', array($this, 'run_trending'));
        add_filter('cron_schedules', array($this, 'add_schedules'));
    }

    /**
     * Add custom cron schedules.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_schedules($schedules)
    {
        $schedules['woobooster_6hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours', 'woobooster'),
        );
        return $schedules;
    }

    /**
     * Schedule cron events based on settings.
     */
    public static function schedule()
    {
        $options = get_option('woobooster_settings', array());

        if (!empty($options['smart_copurchase'])) {
            if (!wp_next_scheduled('woobooster_copurchase_event')) {
                wp_schedule_event(time(), 'daily', 'woobooster_copurchase_event');
            }
        } else {
            wp_clear_scheduled_hook('woobooster_copurchase_event');
        }

        if (!empty($options['smart_trending'])) {
            if (!wp_next_scheduled('woobooster_trending_event')) {
                wp_schedule_event(time(), 'woobooster_6hours', 'woobooster_trending_event');
            }
        } else {
            wp_clear_scheduled_hook('woobooster_trending_event');
        }
    }

    /**
     * Unschedule all cron events.
     */
    public static function unschedule()
    {
        wp_clear_scheduled_hook('woobooster_copurchase_event');
        wp_clear_scheduled_hook('woobooster_trending_event');
    }

    /**
     * Run co-purchase index build.
     *
     * @return array Stats from the build.
     */
    public function run_copurchase()
    {
        $builder = new WooBooster_Copurchase();
        return $builder->build();
    }

    /**
     * Run trending index build.
     *
     * @return array Stats from the build.
     */
    public function run_trending()
    {
        $builder = new WooBooster_Trending();
        return $builder->build();
    }

    /**
     * Purge all Smart Recommendations data.
     *
     * @return array Counts of deleted data.
     */
    public static function purge_all()
    {
        global $wpdb;

        // Delete co-purchase postmeta.
        $copurchase_deleted = $wpdb->delete(
            $wpdb->postmeta,
            array('meta_key' => '_woobooster_copurchased'),
            array('%s')
        );

        // Delete trending transients.
        $trending_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_wb_trending_%',
                '_transient_timeout_wb_trending_%'
            )
        );

        // Delete similar transients.
        $similar_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_wb_similar_%',
                '_transient_timeout_wb_similar_%'
            )
        );

        // Clear build stats.
        delete_option('woobooster_last_build');

        return array(
            'copurchase' => (int) $copurchase_deleted,
            'trending' => (int) $trending_deleted,
            'similar' => (int) $similar_deleted,
        );
    }
}
