<?php
/**
 * Customer requests — automation (hourly WP-Cron).
 *
 * - Reminder: one email when a request has waited on the customer for N days.
 * - Auto-close: close requests still waiting on the customer after N days
 *   with the outcome "Closed — no reply from customer" (they can reply for
 *   14 days to reopen).
 * - Digest: each morning (store time, from 8:00) one email to staff listing
 *   overdue requests and requests waiting for a staff reply.
 *
 * Every switch is off by default and re-checked on each run. Each run handles
 * at most 50 requests per job so a backlog never times out.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Automation
{
    const HOOK = 'ffla_requests_hourly';
    const BATCH = 50;
    const DIGEST_OPTION = 'ffla_requests_digest_day';
    const DIGEST_HOUR = 8;

    public static function boot(): void
    {
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('init', [__CLASS__, 'schedule'], 20);
    }

    public static function active(): bool
    {
        if (!FFLA_Requests::enabled()) {
            return false;
        }
        foreach (['requests_auto_remind', 'requests_auto_close', 'requests_staff_digest'] as $key) {
            if (FFLA_Customer_Operations_Settings::enabled($key)) {
                return true;
            }
        }
        return false;
    }

    /** Keep the hourly event only while some automation is on. */
    public static function schedule(): void
    {
        $next = wp_next_scheduled(self::HOOK);
        if (self::active() && !$next) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK);
        } elseif (!self::active() && $next) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /** @return array{reminded:int, closed:int, digest:bool} */
    public static function run(): array
    {
        $out = ['reminded' => 0, 'closed' => 0, 'digest' => false];
        if (!FFLA_Requests::enabled() || get_option(FFLA_Requests::DB_OPTION) !== FFLA_Requests::DB_VERSION) {
            return $out;
        }
        if (FFLA_Customer_Operations_Settings::enabled('requests_auto_close')) {
            $out['closed'] = self::auto_close();
        }
        if (FFLA_Customer_Operations_Settings::enabled('requests_auto_remind')) {
            $out['reminded'] = self::remind();
        }
        if (FFLA_Customer_Operations_Settings::enabled('requests_staff_digest')) {
            $out['digest'] = self::digest();
        }
        return $out;
    }

    private static function cutoff(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
    }

    public static function remind(): int
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $days = max(1, (int) FFLA_Requests::setting('requests_remind_days'));
        $rows = (array) $wpdb->get_results($wpdb->prepare( // phpcs:ignore
            "SELECT * FROM {$t['requests']} WHERE status = 'waiting_customer' AND waiting_since IS NOT NULL AND waiting_since < %s AND reminded_at IS NULL ORDER BY waiting_since ASC LIMIT %d",
            self::cutoff($days),
            self::BATCH
        ));
        $sent = 0;
        foreach ($rows as $request) {
            // Mark first so a mail failure never turns into repeated emails.
            $wpdb->update($t['requests'], ['reminded_at' => current_time('mysql', true)], ['id' => (int) $request->id]);
            if (FFLA_Requests_Mail::customer_reminder($request)) {
                $sent++;
            }
        }
        return $sent;
    }

    public static function auto_close(): int
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $days = max(1, (int) FFLA_Requests::setting('requests_close_days'));
        $rows = (array) $wpdb->get_results($wpdb->prepare( // phpcs:ignore
            "SELECT * FROM {$t['requests']} WHERE status = 'waiting_customer' AND waiting_since IS NOT NULL AND waiting_since < %s ORDER BY waiting_since ASC LIMIT %d",
            self::cutoff($days),
            self::BATCH
        ));
        $closed = 0;
        foreach ($rows as $request) {
            try {
                FFLA_Requests::close($request, 'no_response', sprintf(
                    /* translators: %d: days */
                    __('We closed this request because we did not hear back. If you still need help, reply within %d days and we will reopen it.', 'ffl-funnels-addons'),
                    FFLA_Requests::REOPEN_DAYS
                ), ['type' => 'system', 'id' => 0]);
                FFLA_Requests_Mail::customer_closed(FFLA_Requests::get((int) $request->id));
                $closed++;
            } catch (Throwable $e) {
                continue;
            }
        }
        return $closed;
    }

    public static function digest(bool $force = false): bool
    {
        global $wpdb;
        $today = wp_date('Y-m-d');
        if (!$force && ((int) wp_date('G') < self::DIGEST_HOUR || get_option(self::DIGEST_OPTION) === $today)) {
            return false;
        }
        update_option(self::DIGEST_OPTION, $today, false);

        $t = FFLA_Requests::tables();
        $now = current_time('mysql', true);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $overdue = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['requests']} WHERE status <> 'closed' AND due_at IS NOT NULL AND due_at < %s ORDER BY due_at ASC LIMIT %d",
            $now,
            self::BATCH
        ));
        $awaiting = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['requests']} WHERE status <> 'closed' AND awaiting = 'staff' ORDER BY updated_at ASC LIMIT %d",
            self::BATCH
        ));
        // phpcs:enable
        return FFLA_Requests_Mail::staff_digest($overdue, $awaiting);
    }
}
