<?php
/**
 * WooBooster Cron — Manages scheduled events for Smart Recommendations.
 *
 * @package FFL_Funnels_Addons
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
            'display' => __('Every 6 Hours', 'ffl-funnels-addons'),
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
    public function run_copurchase($force = false)
    {
        // Cron passes no arguments (WordPress hands the callback an empty
        // string), so scheduled runs may skip an unchanged window; the manual
        // "Rebuild" button passes true.
        $builder = new WooBooster_Copurchase();
        return $builder->build(true === $force);
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
                $wpdb->esc_like('_transient_wb_trending_') . '%',
                $wpdb->esc_like('_transient_timeout_wb_trending_') . '%'
            )
        );

        // Delete cached recommendation results. They are stored as
        // `wbrc_<md5>` transients when there is no persistent object cache
        // (Similar Products results included); `wb_similar_*` is the name an
        // older version used. Bumping the cache version below also retires
        // entries kept in a persistent object cache.
        $cache_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_wbrc_') . '%',
                $wpdb->esc_like('_transient_timeout_wbrc_') . '%',
                $wpdb->esc_like('_transient_wb_similar_') . '%',
                $wpdb->esc_like('_transient_timeout_wb_similar_') . '%'
            )
        );

        if (class_exists('WooBooster_Matcher')) {
            WooBooster_Matcher::invalidate_recommendation_cache();
        }

        // Clear build stats.
        delete_option('woobooster_last_build');

        return array(
            'copurchase' => (int) $copurchase_deleted,
            'trending' => (int) $trending_deleted,
            'cache' => (int) $cache_deleted,
        );
    }
}
