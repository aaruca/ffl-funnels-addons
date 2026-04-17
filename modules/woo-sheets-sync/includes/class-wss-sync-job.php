<?php
/**
 * Async sync job helper backed by Action Scheduler (bundled with WooCommerce)
 * with a synchronous fallback when Action Scheduler is unavailable.
 *
 * State is kept in short-lived transients (10 min TTL) so the admin JS can
 * poll progress and pick up the final payload without us bloating the
 * options table.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Sync_Job
{
    private const STATE_PREFIX = 'wss_sync_job_';
    private const HOOK         = 'wss_run_sync_job';
    private const GROUP        = 'wss-sync';
    private const TTL_SECONDS  = 600; // 10 minutes.

    /**
     * Register the Action Scheduler hook once (safe if AS is absent: the hook
     * simply never fires).
     */
    public static function init(): void
    {
        add_action(self::HOOK, [__CLASS__, 'handle_action'], 10, 1);
    }

    /**
     * True when Action Scheduler is available and can schedule single actions.
     */
    public static function is_action_scheduler_available(): bool
    {
        return function_exists('as_enqueue_async_action') && function_exists('as_has_scheduled_action');
    }

    /**
     * Enqueue a new async sync job, or return a synchronous fallback result
     * when Action Scheduler is not available.
     *
     * @return array{ mode: string, job_id?: string, result?: array, error?: string }
     */
    public static function enqueue(): array
    {
        if (!self::is_action_scheduler_available()) {
            $result = self::run_sync_now();
            return [
                'mode'   => 'sync',
                'result' => $result,
            ];
        }

        $job_id = wp_generate_uuid4();
        self::save_state($job_id, [
            'status'     => 'queued',
            'progress'   => 0,
            'started_at' => time(),
            'result'     => null,
            'error'      => null,
        ]);

        as_enqueue_async_action(self::HOOK, [$job_id], self::GROUP);

        return [
            'mode'   => 'async',
            'job_id' => $job_id,
        ];
    }

    /**
     * Action Scheduler callback. Runs the orchestrator and writes the result
     * back into the job transient so admin JS polling can pick it up.
     */
    public static function handle_action(string $job_id): void
    {
        $state = self::get_state($job_id);
        if ($state === null) {
            // Job state evicted (timed out / cleared). Nothing to do.
            return;
        }

        $state['status']   = 'running';
        $state['progress'] = 5;
        self::save_state($job_id, $state);

        $result = self::run_sync_now();

        if (isset($result['error'])) {
            $state['status']   = 'error';
            $state['error']    = (string) $result['error'];
            $state['progress'] = 100;
        } else {
            $state['status']   = 'done';
            $state['progress'] = 100;
            $state['result']   = $result;
        }
        $state['finished_at'] = time();

        self::save_state($job_id, $state);
    }

    /**
     * Return the current state array for a job_id, or null if expired/unknown.
     *
     * @return array<string, mixed>|null
     */
    public static function get_state(string $job_id): ?array
    {
        if ($job_id === '') {
            return null;
        }
        $state = get_transient(self::STATE_PREFIX . $job_id);
        return is_array($state) ? $state : null;
    }

    /**
     * Persist the state for a job_id with the standard TTL.
     *
     * @param array<string, mixed> $state
     */
    private static function save_state(string $job_id, array $state): void
    {
        set_transient(self::STATE_PREFIX . $job_id, $state, self::TTL_SECONDS);
    }

    /**
     * Run the orchestrator synchronously and return its result payload (or an
     * `error` key if setup is incomplete).
     *
     * @return array<string, mixed>
     */
    private static function run_sync_now(): array
    {
        $settings = get_option('wss_settings', []);
        if (empty($settings['sheet_id'])) {
            return ['error' => __('No Google Sheet ID configured.', 'ffl-funnels-addons')];
        }

        if (!class_exists('WSS_Google_OAuth') || !class_exists('WSS_Google_Sheets') || !class_exists('WSS_Logger') || !class_exists('WSS_Sync_Orchestrator')) {
            return ['error' => __('Sync orchestrator not available.', 'ffl-funnels-addons')];
        }

        $oauth = new WSS_Google_OAuth();
        if (!$oauth->is_connected()) {
            return ['error' => __('Not connected to Google. Please authorize first.', 'ffl-funnels-addons')];
        }

        $sheets = new WSS_Google_Sheets($oauth);
        $logger = new WSS_Logger();

        return WSS_Sync_Orchestrator::run_all($sheets, $logger);
    }
}
