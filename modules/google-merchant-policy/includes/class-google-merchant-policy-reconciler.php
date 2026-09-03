<?php
/**
 * Batched product reconciliation for Google Merchant Policy.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Google_Merchant_Policy_Reconciler
{
    const ACTION = 'ffla_google_merchant_policy_reconcile';
    const GROUP = 'ffla-google-merchant-policy';
    const STATE_OPTION = 'ffla_google_merchant_policy_reconcile_state';
    const LOCK = 'ffla_google_merchant_policy_reconcile_lock';

    public static function init(): void
    {
        add_action(self::ACTION, [__CLASS__, 'run_batch']);
    }

    public static function start(): array
    {
        self::clear_schedule();
        $state = [
            'status' => 'running',
            'offset' => 0,
            'processed' => 0,
            'allowed' => 0,
            'blocked' => 0,
            'pending' => 0,
            'started_at' => gmdate('c'),
            'finished_at' => '',
            'last_error' => '',
        ];
        update_option(self::STATE_OPTION, $state, false);
        self::schedule_next(5);
        return $state;
    }

    public static function pause(): array
    {
        self::clear_schedule();
        $state = self::get_state();
        $state['status'] = 'paused';
        update_option(self::STATE_OPTION, $state, false);
        return $state;
    }

    public static function get_state(): array
    {
        $state = get_option(self::STATE_OPTION, []);
        return wp_parse_args(is_array($state) ? $state : [], [
            'status' => 'idle',
            'offset' => 0,
            'processed' => 0,
            'allowed' => 0,
            'blocked' => 0,
            'pending' => 0,
            'started_at' => '',
            'finished_at' => '',
            'last_error' => '',
        ]);
    }

    public static function run_batch(): void
    {
        if (get_transient(self::LOCK)) {
            self::schedule_next(30);
            return;
        }
        set_transient(self::LOCK, '1', 5 * MINUTE_IN_SECONDS);

        try {
            $state = self::get_state();
            if ((string) $state['status'] !== 'running') {
                return;
            }
            if (!class_exists('Google_Merchant_Policy_Engine') || !function_exists('wc_get_product')) {
                throw new RuntimeException(__('WooCommerce policy engine is unavailable.', 'ffl-funnels-addons'));
            }

            $settings = Google_Merchant_Policy_Engine::get_settings();
            $batch_size = max(10, min(250, (int) ($settings['batch_size'] ?? 50)));
            $query = new WP_Query([
                'post_type' => ['product', 'product_variation'],
                'post_status' => 'publish',
                'posts_per_page' => $batch_size,
                'offset' => max(0, (int) $state['offset']),
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ids',
                'no_found_rows' => true,
                'cache_results' => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => true,
            ]);
            $ids = array_map('intval', (array) $query->posts);
            if (empty($ids)) {
                $state['status'] = 'complete';
                $state['finished_at'] = gmdate('c');
                update_option(self::STATE_OPTION, $state, false);
                return;
            }

            if (function_exists('update_object_term_cache')) {
                update_object_term_cache($ids, 'product');
            }
            foreach ($ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product) {
                    continue;
                }
                $decision = Google_Merchant_Policy_Engine::apply_to_product($product);
                $status = (string) ($decision['status'] ?? 'pending');
                if (!isset($state[$status])) {
                    $status = 'pending';
                }
                $state[$status]++;
                $state['processed']++;
            }
            $state['offset'] = (int) $state['offset'] + count($ids);
            update_option(self::STATE_OPTION, $state, false);

            if (count($ids) < $batch_size) {
                $state['status'] = 'complete';
                $state['finished_at'] = gmdate('c');
                update_option(self::STATE_OPTION, $state, false);
                return;
            }
            self::schedule_next(10);
        } catch (Throwable $e) {
            $state = self::get_state();
            $state['status'] = 'failed';
            $state['last_error'] = sanitize_text_field($e->getMessage());
            update_option(self::STATE_OPTION, $state, false);
        } finally {
            delete_transient(self::LOCK);
        }
    }

    public static function clear_schedule(): void
    {
        wp_clear_scheduled_hook(self::ACTION);
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::ACTION, [], self::GROUP);
        }
    }

    private static function schedule_next(int $delay): void
    {
        $timestamp = time() + max(1, $delay);
        if (function_exists('as_schedule_single_action')) {
            if (!function_exists('as_has_scheduled_action') || !as_has_scheduled_action(self::ACTION, [], self::GROUP)) {
                as_schedule_single_action($timestamp, self::ACTION, [], self::GROUP, true);
            }
            return;
        }
        if (!wp_next_scheduled(self::ACTION)) {
            wp_schedule_single_event($timestamp, self::ACTION);
        }
    }
}
