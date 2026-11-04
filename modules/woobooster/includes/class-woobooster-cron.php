<?php
/**
 * WooBooster Cron — Manages scheduled events for Smart Recommendations and
 * the rule lookup index, plus the guards every long build runs under (one
 * build at a time, a longer time limit, a memory ceiling).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Cron
{

    /** Single, debounced rule/bundle index rebuild after a category change. */
    const RULE_INDEX_HOOK = 'woobooster_rebuild_rule_index';

    /** Daily safety-net rebuild of the rule/bundle index. */
    const RULE_INDEX_DAILY_HOOK = 'woobooster_rule_index_daily';

    /** Option name prefix of the build locks (rows in wp_options). */
    const LOCK_PREFIX = 'woobooster_build_lock_';

    /**
     * Parent and slug of terms about to be edited, keyed by term ID, so an
     * edit that changes neither does not rebuild anything.
     *
     * @var array<int, array{parent:int, slug:string}>
     */
    private static $terms_before_edit = array();

    /**
     * Locks this request holds: option name => token.
     *
     * @var array<string, string>
     */
    private static $held_locks = array();

    /**
     * When each held lock was last refreshed: option name => Unix time.
     *
     * @var array<string, int>
     */
    private static $lock_refreshed = array();

    /**
     * Initialize cron hooks.
     */
    public function init()
    {
        add_action('woobooster_copurchase_event', array($this, 'run_copurchase'));
        add_action('woobooster_trending_event', array($this, 'run_trending'));
        add_filter('cron_schedules', array($this, 'add_schedules'));

        // "+ Children" conditions are expanded into the lookup index when a
        // rule or bundle is saved, so subcategories created, moved or renamed
        // later were never matched. Any such change now queues one rebuild.
        add_action('created_term', array(__CLASS__, 'on_term_created'), 10, 3);
        add_action('edit_terms', array(__CLASS__, 'on_term_before_edit'), 10, 2);
        add_action('edited_term', array(__CLASS__, 'on_term_edited'), 10, 3);
        add_action('delete_term', array(__CLASS__, 'on_term_deleted'), 10, 3);
        add_action(self::RULE_INDEX_HOOK, array(__CLASS__, 'run_rule_index_rebuild'));
        add_action(self::RULE_INDEX_DAILY_HOOK, array(__CLASS__, 'run_rule_index_rebuild'));
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

        // Rules and bundles work without Smart strategies, so the daily index
        // safety net does not depend on the settings above.
        if (!wp_next_scheduled(self::RULE_INDEX_DAILY_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::RULE_INDEX_DAILY_HOOK);
        }
    }

    /**
     * Unschedule all cron events.
     */
    public static function unschedule()
    {
        wp_clear_scheduled_hook('woobooster_copurchase_event');
        wp_clear_scheduled_hook('woobooster_trending_event');
        wp_clear_scheduled_hook(self::RULE_INDEX_HOOK);
        wp_clear_scheduled_hook(self::RULE_INDEX_DAILY_HOOK);
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
     * Run every enabled Smart build one after the other (the admin "Rebuild
     * Now" button). Each build takes its own lock, raises the time limit and
     * stops cleanly near the memory limit; the in-memory cache is emptied
     * between them.
     *
     * @param bool $force Rebuild Bought Together even when no orders changed.
     * @return array<string, array> Stats per build ('copurchase', 'trending'),
     *                              only for enabled strategies. A build that
     *                              did not finish has 'stopped' (locked,
     *                              memory, lock_lost) and a 'reason'.
     */
    public static function run_builds($force = false): array
    {
        $options = get_option('woobooster_settings', array());
        $cron = new self();
        $results = array();

        if (!empty($options['smart_copurchase'])) {
            $results['copurchase'] = $cron->run_copurchase(true === $force);
            self::release_memory();
        }

        if (!empty($options['smart_trending'])) {
            $results['trending'] = $cron->run_trending();
            self::release_memory();
        }

        return $results;
    }

    /* ── Rule / bundle lookup index ──────────────────────────────────── */

    /**
     * Whether a taxonomy's hierarchy feeds "+ Children" conditions: product
     * categories, plus any other hierarchical product taxonomy (e.g. Brands).
     *
     * @param mixed $taxonomy Taxonomy name.
     */
    private static function is_indexed_taxonomy($taxonomy): bool
    {
        if ('product_cat' === $taxonomy) {
            return true;
        }

        return is_string($taxonomy)
            && '' !== $taxonomy
            && taxonomy_exists($taxonomy)
            && is_taxonomy_hierarchical($taxonomy)
            && is_object_in_taxonomy('product', $taxonomy);
    }

    /**
     * created_term: a new category may be the child of a "+ Children" rule.
     */
    public static function on_term_created($term_id, $tt_id = 0, $taxonomy = '')
    {
        if (self::is_indexed_taxonomy($taxonomy)) {
            self::schedule_rule_index_rebuild();
        }
    }

    /**
     * edit_terms (before the update): remember parent and slug.
     */
    public static function on_term_before_edit($term_id, $taxonomy = '')
    {
        if (!self::is_indexed_taxonomy($taxonomy)) {
            return;
        }

        $term = get_term((int) $term_id, $taxonomy);
        if ($term && !is_wp_error($term)) {
            self::$terms_before_edit[(int) $term_id] = array(
                'parent' => (int) $term->parent,
                'slug'   => (string) $term->slug,
            );
        }
    }

    /**
     * edited_term: rebuild only when the parent or slug changed (a name or
     * description edit cannot change the index).
     */
    public static function on_term_edited($term_id, $tt_id = 0, $taxonomy = '')
    {
        if (!self::is_indexed_taxonomy($taxonomy)) {
            return;
        }

        $term_id = (int) $term_id;
        $before = isset(self::$terms_before_edit[$term_id]) ? self::$terms_before_edit[$term_id] : null;
        unset(self::$terms_before_edit[$term_id]);

        $term = get_term($term_id, $taxonomy);
        if (
            null === $before
            || !$term
            || is_wp_error($term)
            || (int) $term->parent !== $before['parent']
            || (string) $term->slug !== $before['slug']
        ) {
            self::schedule_rule_index_rebuild();
        }
    }

    /**
     * delete_term: its children move up to its parent.
     */
    public static function on_term_deleted($term, $tt_id = 0, $taxonomy = '')
    {
        if (self::is_indexed_taxonomy($taxonomy)) {
            self::schedule_rule_index_rebuild();
        }
    }

    /**
     * Queue one background rebuild of the rule and bundle lookup index, a
     * minute from now (filter `woobooster_rule_index_rebuild_delay`, seconds).
     * Changes made before it runs are covered by the same run, so a product
     * import that creates hundreds of categories rebuilds once.
     *
     * @return bool Whether a new event was scheduled.
     */
    public static function schedule_rule_index_rebuild(): bool
    {
        if (false !== wp_next_scheduled(self::RULE_INDEX_HOOK)) {
            return false;
        }

        $delay = max(1, (int) apply_filters('woobooster_rule_index_rebuild_delay', MINUTE_IN_SECONDS));

        return true === wp_schedule_single_event(time() + $delay, self::RULE_INDEX_HOOK);
    }

    /**
     * Rebuild the rule and bundle lookup indexes from the current category
     * tree, then retire cached recommendations. Runs from the debounced
     * event and daily.
     *
     * @return array{rules:mixed, bundles:bool, stopped?:string}
     */
    public static function run_rule_index_rebuild(): array
    {
        $token = self::acquire_lock('rule_index');
        if (false === $token) {
            // A rebuild is running; queue another so changes made after it
            // read the categories are not lost.
            self::schedule_rule_index_rebuild();
            return array('rules' => null, 'bundles' => false, 'stopped' => 'locked');
        }

        try {
            $result = array('rules' => null, 'bundles' => false);

            if (class_exists('WooBooster_Rule')) {
                $result['rules'] = WooBooster_Rule::rebuild_full_index();
            }

            if (class_exists('WooBooster_Bundle') && method_exists('WooBooster_Bundle', 'rebuild_full_bundle_index')) {
                WooBooster_Bundle::rebuild_full_bundle_index();
                $result['bundles'] = true;
            }

            if (class_exists('WooBooster_Matcher')) {
                WooBooster_Matcher::invalidate_recommendation_cache();
            }

            return $result;
        } finally {
            self::release_lock('rule_index', $token);
        }
    }

    /* ── Build guards ─────────────────────────────────────────────────── */

    /**
     * Take the lock for one build, so two runs (WP-Cron and the "Rebuild
     * Now" button, or two overlapping cron requests) never build the same
     * index at once.
     *
     * The lock is a wp_options row created with INSERT IGNORE (atomic, the
     * same approach as WP_Upgrader::create_lock()), holding "time|token". A
     * lock whose holder has not refreshed it for `woobooster_smart_build_lock_timeout`
     * seconds (default 10 minutes) is treated as abandoned (the request died)
     * and taken over.
     *
     * @param string $name Build name (copurchase, trending, rule_index).
     * @return string|false Token to pass to refresh_lock()/release_lock(), or
     *                      false when another run holds the lock.
     */
    public static function acquire_lock(string $name)
    {
        global $wpdb;

        $option = self::LOCK_PREFIX . sanitize_key($name);
        $timeout = max(60, (int) apply_filters('woobooster_smart_build_lock_timeout', 10 * MINUTE_IN_SECONDS, $name));
        $token = str_replace('.', '', uniqid('', true));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $now = time();
            $value = $now . '|' . $token;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $inserted = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $option,
                    $value
                )
            );
            if ($inserted) {
                return self::hold_lock($option, $token);
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $current = $wpdb->get_var(
                $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option)
            );
            if (null === $current) {
                continue; // Released in between: try again.
            }

            $since = (int) strtok((string) $current, '|');
            if ($since > $now - $timeout) {
                return false;
            }

            // Abandoned: take it over, unless another request just did.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $taken = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                    $value,
                    $option,
                    (string) $current
                )
            );

            return $taken ? self::hold_lock($option, $token) : false;
        }

        return false;
    }

    /**
     * Record a lock as held by this request and make sure it is released if
     * the request ends without reaching release_lock().
     */
    private static function hold_lock(string $option, string $token): string
    {
        static $shutdown_registered = false;

        self::$held_locks[$option] = $token;
        self::$lock_refreshed[$option] = time();

        if (!$shutdown_registered) {
            $shutdown_registered = true;
            register_shutdown_function(array(__CLASS__, 'release_held_locks'));
        }

        return $token;
    }

    /**
     * Keep a lock alive during a long build (at most one write per 30 s).
     *
     * @return bool False when the lock is no longer ours (it was taken over
     *              as abandoned): the caller should stop.
     */
    public static function refresh_lock(string $name, string $token): bool
    {
        global $wpdb;

        $option = self::LOCK_PREFIX . sanitize_key($name);
        $now = time();
        if (isset(self::$lock_refreshed[$option]) && self::$lock_refreshed[$option] > $now - 30) {
            return true;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $current = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option)
        );
        if (!self::is_lock_value($current, $token)) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $now . '|' . $token,
                $option,
                $current
            )
        );
        self::$lock_refreshed[$option] = $now;

        return true;
    }

    /**
     * Release a lock taken by this request (never someone else's).
     */
    public static function release_lock(string $name, string $token): void
    {
        global $wpdb;

        $option = self::LOCK_PREFIX . sanitize_key($name);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
                $option,
                '%|' . $wpdb->esc_like($token)
            )
        );

        if (isset(self::$held_locks[$option]) && self::$held_locks[$option] === $token) {
            unset(self::$held_locks[$option], self::$lock_refreshed[$option]);
        }
    }

    /**
     * Shutdown fallback: release locks a fatal error left behind.
     */
    public static function release_held_locks(): void
    {
        foreach (self::$held_locks as $option => $token) {
            self::release_lock(substr($option, strlen(self::LOCK_PREFIX)), $token);
        }
    }

    private static function is_lock_value(string $value, string $token): bool
    {
        $suffix = '|' . $token;
        return '' !== $token && strlen($value) > strlen($suffix) && substr($value, -strlen($suffix)) === $suffix;
    }

    /**
     * Give a build room to finish: a longer PHP time limit (filter
     * `woobooster_smart_build_time_limit`, seconds, default 15 minutes; 0 =
     * unlimited; never lowered) and WordPress's admin memory limit
     * (WP_MAX_MEMORY_LIMIT, filter `woobooster_smart_build_memory_limit`).
     */
    public static function prepare_long_run(): void
    {
        $limit = (int) apply_filters('woobooster_smart_build_time_limit', 15 * MINUTE_IN_SECONDS);
        $current = (int) ini_get('max_execution_time');

        if ($current > 0 && ($limit <= 0 || $limit > $current)) {
            if (function_exists('wc_set_time_limit')) {
                wc_set_time_limit(max(0, $limit));
            } elseif (function_exists('set_time_limit') && false === strpos((string) ini_get('disable_functions'), 'set_time_limit')) {
                @set_time_limit(max(0, $limit)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('woobooster_smart_build');
        }
    }

    /**
     * Memory use (bytes, as PHP counts it against memory_limit) at which a
     * build stops: 85% of the limit (filter `woobooster_smart_build_memory_ratio`).
     *
     * @return int 0 when PHP has no memory limit.
     */
    public static function memory_threshold(): int
    {
        $limit = self::memory_limit_bytes();
        if ($limit <= 0) {
            return 0;
        }

        $ratio = (float) apply_filters('woobooster_smart_build_memory_ratio', 0.85);
        $ratio = min(1.0, max(0.0, $ratio));

        return (int) max(1, floor($limit * $ratio));
    }

    /**
     * Whether a build passed its memory threshold.
     */
    public static function memory_exceeded(int $threshold): bool
    {
        return $threshold > 0 && memory_get_usage(true) >= $threshold;
    }

    /**
     * PHP memory_limit in bytes (-1 when unlimited).
     */
    public static function memory_limit_bytes(): int
    {
        $raw = (string) ini_get('memory_limit');
        if (function_exists('wp_convert_hr_to_bytes')) {
            return (int) wp_convert_hr_to_bytes($raw);
        }

        $value = (int) $raw;
        $unit = strtolower(substr(trim($raw), -1));
        if ('g' === $unit) {
            $value *= 1024 * 1024 * 1024;
        } elseif ('m' === $unit) {
            $value *= 1024 * 1024;
        } elseif ('k' === $unit) {
            $value *= 1024;
        }
        return $value;
    }

    /**
     * "212 MB of 256 MB" for stop reasons.
     */
    public static function memory_usage_text(): string
    {
        $format = static function ($bytes) {
            return function_exists('size_format') ? (string) size_format($bytes) : round($bytes / 1048576) . ' MB';
        };

        return sprintf(
            /* translators: 1: memory in use, 2: PHP memory limit */
            __('%1$s of %2$s', 'ffl-funnels-addons'),
            $format(memory_get_usage(true)),
            $format(max(0, self::memory_limit_bytes()))
        );
    }

    /**
     * Peak memory of this request, in MB, for build stats.
     */
    public static function memory_peak_mb(): float
    {
        return round(memory_get_peak_usage(true) / 1048576, 1);
    }

    /**
     * Empty WordPress's in-memory object cache. Every post, meta row and
     * term a request reads stays there until the request ends, so paging
     * alone does not bound a long build's memory.
     */
    public static function release_memory(): void
    {
        if (function_exists('wp_cache_supports') && function_exists('wp_cache_flush_runtime') && wp_cache_supports('flush_runtime')) {
            wp_cache_flush_runtime();
        }
    }

    /**
     * Stats for a build that did not run because another one holds its lock.
     */
    public static function locked_stats(string $type, float $start): array
    {
        return self::stopped_build(
            $type,
            'locked',
            __('Another update of this list is already running. Try again in a few minutes.', 'ffl-funnels-addons'),
            $start
        );
    }

    /**
     * Stats for a build that stopped before writing anything. The previous
     * index is kept; except for 'locked', the stored build stats keep the last
     * completed build and gain `stopped`, `stopped_reason` and `stopped_at`
     * (a completed build replaces them).
     *
     * @param string $type   copurchase|trending.
     * @param string $code   locked|memory|lock_lost.
     * @param string $reason Plain-language reason.
     * @param float  $start  microtime(true) at the start of the build.
     * @param array  $extra  Progress counters to report.
     * @return array
     */
    public static function stopped_build(string $type, string $code, string $reason, float $start, array $extra = array()): array
    {
        $now = current_time('mysql');
        $stats = array_merge(
            array('type' => $type, 'products' => 0),
            'trending' === $type ? array('categories' => 0) : array(),
            $extra,
            array(
                'stopped'        => $code,
                'reason'         => $reason,
                'stopped_reason' => $reason,
                'memory_peak'    => self::memory_peak_mb(),
                'time'           => round(microtime(true) - $start, 2),
                'date'           => $now,
            )
        );

        if ('locked' !== $code) {
            $last_build = get_option('woobooster_last_build', array());
            $last_build = is_array($last_build) ? $last_build : array();
            $previous = isset($last_build[$type]) && is_array($last_build[$type]) ? $last_build[$type] : array('type' => $type);
            unset($previous['skipped']);
            $previous['stopped'] = $code;
            $previous['stopped_reason'] = $reason;
            $previous['stopped_at'] = $now;
            $last_build[$type] = $previous;
            update_option('woobooster_last_build', $last_build);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WooBooster ' . $type . ' build stopped (' . $code . '): ' . $reason); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        }

        return $stats;
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
