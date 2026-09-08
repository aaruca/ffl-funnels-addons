<?php
/** Resumable, bounded catalog reconciliation; no remote Google calls here. */
if (!defined('ABSPATH')) {
    exit;
}

class Google_Merchant_Policy_Reconciler
{
    const ACTION = 'ffla_google_merchant_policy_reconcile';
    const WATCHDOG = 'ffla_google_merchant_policy_watchdog';
    const GROUP = 'ffla-google-merchant-policy';
    const STATE_OPTION = 'ffla_google_merchant_policy_reconcile_state';
    const LOCK = 'ffla_google_merchant_policy_reconcile_lock_v2';

    public static function init(): void
    {
        add_action(self::ACTION, [__CLASS__, 'run_batch'], 10, 1);
        add_action(self::WATCHDOG, [__CLASS__, 'recover']);
        add_action('wp_loaded', [__CLASS__, 'recover'], 110);
    }

    public static function start(): array
    {
        self::clear_schedule();
        global $wpdb;
        $max_id = $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish'");
        $state = self::defaults();
        $state['status'] = $wpdb->last_error ? 'failed' : 'running';
        $state['last_error'] = $wpdb->last_error ? __('Could not read the product catalog.', 'ffl-funnels-addons') : '';
        $state['scan_id'] = wp_generate_uuid4();
        $state['max_id'] = (int) $max_id;
        $state['started_at'] = gmdate('c');
        update_option(self::STATE_OPTION, $state, false);
        self::recover();
        return self::get_state();
    }

    public static function resume(): void
    {
        $state = self::get_state();
        if (empty($state['scan_id']) || in_array($state['status'], ['idle', 'complete'], true)) {
            self::start();
            return;
        }
        // Rotate generation to invalidate an older, paused worker.
        $state['scan_id'] = wp_generate_uuid4();
        $state['status'] = 'running';
        $state['last_error'] = '';
        update_option(self::STATE_OPTION, $state, false);
        self::clear_schedule();
        self::recover();
    }

    public static function pause(): array
    {
        $state = self::get_state();
        $state['status'] = 'paused';
        update_option(self::STATE_OPTION, $state, false);
        self::clear_schedule();
        return $state;
    }

    private static function defaults(): array
    {
        return [
            'status' => 'idle', 'scan_id' => '', 'last_id' => 0, 'max_id' => 0,
            'offset' => 0, 'processed' => 0, 'allowed' => 0, 'blocked' => 0,
            'pending' => 0, 'skipped' => 0, 'withdrawal_requests' => 0,
            'started_at' => '', 'updated_at' => '', 'finished_at' => '', 'last_error' => '',
        ];
    }

    public static function get_state(): array
    {
        wp_cache_delete(self::STATE_OPTION, 'options');
        $state = get_option(self::STATE_OPTION, []);
        return wp_parse_args(is_array($state) ? $state : [], self::defaults());
    }

    public static function recover(): void
    {
        $state = self::get_state();
        if ($state['status'] !== 'running') {
            return;
        }
        // Restart stalled legacy offset scans ONCE, retaining all exclusions.
        if (empty($state['scan_id'])) {
            self::start();
            return;
        }
        if (!wp_next_scheduled(self::WATCHDOG)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::WATCHDOG);
        }
        if (!self::schedule_next(10, $state['scan_id'])) {
            $state['status'] = 'failed';
            $state['last_error'] = __('Could not schedule the next catalog batch. Check WordPress cron / Action Scheduler and resume.', 'ffl-funnels-addons');
            self::checkpoint($state);
        }
    }

    public static function run_batch(string $scan_id = ''): void
    {
        $state = self::get_state();
        if ($state['status'] !== 'running' || ($scan_id !== '' && $scan_id !== $state['scan_id'])) {
            return;
        }
        if (empty($state['scan_id'])) {
            self::recover();
            return;
        }
        $token = wp_generate_uuid4();
        if (!self::acquire_lock($token)) {
            self::recover();
            return;
        }
        try {
            $state = self::get_state();
            if ($state['status'] !== 'running' || ($scan_id !== '' && $scan_id !== $state['scan_id'])) {
                return;
            }
            $settings = Google_Merchant_Policy_Engine::get_settings();
            $batch_size = max(10, min(250, (int) $settings['batch_size']));
            global $wpdb;
            // Keyset pagination prevents OFFSET drift when products are removed.
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish' AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d",
                (int) $state['last_id'], (int) $state['max_id'], $batch_size
            ));
            if ($wpdb->last_error) {
                throw new RuntimeException(__('Could not read the next product batch.', 'ffl-funnels-addons'));
            }
            Google_Merchant_Policy_Engine::reset_runtime_cache();
            $deadline = microtime(true) + 20;
            foreach ($ids as $id) {
                $current = self::get_state();
                if ($current['status'] !== 'running' || $current['scan_id'] !== $state['scan_id']) {
                    return;
                }
                $product = wc_get_product((int) $id);
                if ($product) {
                    $decision = Google_Merchant_Policy_Engine::apply_to_product($product);
                    if ($decision['status'] !== 'allowed'
                        && Google_Merchant_Policy_Google_Sync::request_withdrawal($product)) {
                        $state['withdrawal_requests']++;
                    }
                    $state[$decision['status']]++;
                    $state['processed']++;
                } else {
                    $state['skipped']++;
                }
                $state['last_id'] = (int) $id;
                $state['offset']++;
                $state['updated_at'] = gmdate('c');
                $state['last_error'] = '';
                // Persist each completed item; retries don't double-count it.
                if (!self::checkpoint($state)) {
                    return;
                }
                if (microtime(true) >= $deadline) {
                    self::recover();
                    return;
                }
            }
            if (count($ids) < $batch_size) {
                $state['status'] = 'complete';
                $state['finished_at'] = gmdate('c');
                self::checkpoint($state);
            } else {
                self::recover();
            }
        } catch (Throwable $e) {
            $current = self::get_state();
            if ($current['scan_id'] === $state['scan_id'] && $current['status'] === 'running') {
                $current['status'] = 'failed';
                $current['last_error'] = sanitize_text_field($e->getMessage());
                self::checkpoint($current);
            }
        } finally {
            self::release_lock($token);
        }
    }

    /** Compare-and-swap prevents a worker overwriting a concurrent pause/restart. */
    private static function checkpoint(array $state): bool
    {
        global $wpdb;
        wp_cache_delete(self::STATE_OPTION, 'options');
        $stored = get_option(self::STATE_OPTION, []);
        if (($stored['status'] ?? '') !== 'running' || ($stored['scan_id'] ?? '') !== $state['scan_id']) {
            return false;
        }
        if ($stored === $state) {
            return true;
        }
        $updated = $wpdb->update($wpdb->options,
            ['option_value' => maybe_serialize($state)],
            ['option_name' => self::STATE_OPTION, 'option_value' => maybe_serialize($stored)],
            ['%s'], ['%s', '%s']);
        wp_cache_delete(self::STATE_OPTION, 'options');
        return $updated === 1;
    }

    private static function acquire_lock(string $token): bool
    {
        global $wpdb;
        wp_cache_delete(self::LOCK, 'options');
        $old = get_option(self::LOCK, false);
        if (is_array($old) && (int) ($old['expires'] ?? 0) < time()) {
            $wpdb->delete($wpdb->options, ['option_name' => self::LOCK, 'option_value' => maybe_serialize($old)], ['%s', '%s']);
            wp_cache_delete(self::LOCK, 'options');
        }
        return add_option(self::LOCK, ['token' => $token, 'expires' => time() + 5 * MINUTE_IN_SECONDS], '', false);
    }

    private static function release_lock(string $token): void
    {
        global $wpdb;
        $lock = get_option(self::LOCK, []);
        if (($lock['token'] ?? '') === $token) {
            $wpdb->delete($wpdb->options, ['option_name' => self::LOCK, 'option_value' => maybe_serialize($lock)], ['%s', '%s']);
            wp_cache_delete(self::LOCK, 'options');
        }
    }

    public static function clear_schedule(): void
    {
        wp_unschedule_hook(self::ACTION);
        wp_unschedule_hook(self::WATCHDOG);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION, null, self::GROUP);
        }
    }

    private static function schedule_next(int $delay, string $scan_id): bool
    {
        $args = [$scan_id];
        if (function_exists('as_schedule_single_action') && function_exists('as_get_scheduled_actions')) {
            // as_has_scheduled_action matches the CURRENT in-progress job.
            // Only pending successors matter; unique=true would self-block again.
            try {
                $pending = as_get_scheduled_actions([
                    'hook' => self::ACTION, 'args' => $args, 'group' => self::GROUP,
                    'status' => 'pending', 'per_page' => 1,
                ], 'ids');
                if ($pending || as_schedule_single_action(time() + $delay, self::ACTION, $args, self::GROUP, false)) {
                    return true;
                }
            } catch (Throwable $e) {
                // Fall through to WP-Cron if Action Scheduler cannot enqueue.
            }
        }
        if (wp_next_scheduled(self::ACTION, $args)) {
            return true;
        }
        return wp_schedule_single_event(time() + $delay, self::ACTION, $args) === true;
    }
}
