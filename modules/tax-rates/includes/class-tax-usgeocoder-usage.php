<?php
/**
 * USGeocoder API usage counter.
 *
 * Tracks how many real HTTP calls we make against the paid USGeocoder API
 * so store owners can estimate their monthly bill and catch runaway usage.
 *
 * Two views are surfaced, both kept in a single wp_option:
 *   - A `YYYY-MM` history (trimmed to the last 24 months) that survives even
 *     after the audit table is purged. Split per-month into success / failed
 *     / total so invalid keys are visible.
 *   - A rolling 30-day total from per-day counts (`_days`, last 31 days).
 *     The first 30 days after the daily counts start (`_days_since`) also
 *     consult the audit log, so calls made before an update are not lost.
 *
 * Cache hits never reach `Tax_Quote_Engine::run_resolver()` so they cannot
 * inflate this counter; every recorded increment maps to a real HTTP call.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_USGeocoder_Usage
{
    public const OPTION_KEY    = 'ffla_tax_usgeocoder_usage';
    public const HISTORY_CAP   = 24; // Keep 24 months max in the option.
    public const DAYS_KEY      = '_days';
    public const DAYS_SINCE_KEY = '_days_since';
    public const DAYS_CAP      = 31;

    /**
     * Record a real HTTP call to the USGeocoder API.
     *
     * @param bool $success Whether the call returned a usable payload.
     */
    public static function record_call(bool $success): void
    {
        $month   = gmdate('Y-m');
        $day     = gmdate('Y-m-d');
        $history = self::read_history();
        [$days, $since] = self::read_days();

        if (!isset($history[$month]) || !is_array($history[$month])) {
            $history[$month] = ['total' => 0, 'success' => 0, 'failed' => 0];
        }

        $history[$month]['total']   = (int) $history[$month]['total'] + 1;
        $history[$month]['success'] = (int) $history[$month]['success'] + ($success ? 1 : 0);
        $history[$month]['failed']  = (int) $history[$month]['failed'] + ($success ? 0 : 1);

        $history = self::trim_history($history);

        $days[$day] = (int) ($days[$day] ?? 0) + 1;
        krsort($days);
        $days = array_slice($days, 0, self::DAYS_CAP, true);

        $history[self::DAYS_KEY]       = $days;
        $history[self::DAYS_SINCE_KEY] = $since !== '' ? $since : $day;

        update_option(self::OPTION_KEY, $history, false);
    }

    /**
     * Get the last N months of usage ordered newest first.
     *
     * @param int $months Maximum number of months to return.
     * @return array<int,array{month:string,label:string,total:int,success:int,failed:int}>
     */
    public static function get_monthly(int $months = 12): array
    {
        $months  = max(1, $months);
        $history = self::read_history();

        krsort($history);
        $history = array_slice($history, 0, $months, true);

        $out = [];
        foreach ($history as $key => $row) {
            $row = is_array($row) ? $row : [];
            $ts  = strtotime($key . '-01 00:00:00 UTC');
            $out[] = [
                'month'   => (string) $key,
                'label'   => $ts ? gmdate('M Y', $ts) : (string) $key,
                'total'   => (int) ($row['total']   ?? 0),
                'success' => (int) ($row['success'] ?? 0),
                'failed'  => (int) ($row['failed']  ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Get the rolling 30-day count of real USGeocoder calls.
     *
     * Every call recorded by record_call() counts, including calls whose
     * answer was unusable and then fell back to the Sheet, so it matches the
     * monthly history.
     */
    public static function get_last_30d(): int
    {
        [$days, $since] = self::read_days();
        $cutoff = gmdate('Y-m-d', time() - 29 * DAY_IN_SECONDS);

        $total = 0;
        foreach ($days as $day => $count) {
            if ($day >= $cutoff) {
                $total += (int) $count;
            }
        }

        // Daily counts cover the whole window once they are 30 days old.
        if ($since !== '' && $since <= $cutoff) {
            return $total;
        }

        // Earlier calls are only in the audit log (or the month history).
        return max($total, self::last_30d_from_audit());
    }

    /**
     * Rolling 30-day count from the audit table (previous method).
     *
     * Falls back to the history option if the audit table is unavailable.
     */
    private static function last_30d_from_audit(): int
    {
        global $wpdb;

        if (!class_exists('Tax_Resolver_DB') || !isset($wpdb)) {
            return self::last_30d_from_history();
        }

        $table = Tax_Resolver_DB::table('quotes_audit');

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE source_code = %s
               AND cache_hit = 0
               AND requested_at > DATE_SUB(%s, INTERVAL 30 DAY)",
            'usgeocoder_api',
            current_time('mysql')
        ));

        if ($count === null) {
            return self::last_30d_from_history();
        }

        return max(0, (int) $count);
    }

    /**
     * Per-day counts (newest first) and the first day they were kept.
     *
     * @return array{0:array<string,int>,1:string}
     */
    private static function read_days(): array
    {
        $raw = get_option(self::OPTION_KEY, []);
        $raw = is_array($raw) ? $raw : [];

        $days = [];
        foreach ((is_array($raw[self::DAYS_KEY] ?? null) ? $raw[self::DAYS_KEY] : []) as $day => $count) {
            if (is_string($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $days[$day] = max(0, (int) $count);
            }
        }
        krsort($days);

        $since = (string) ($raw[self::DAYS_SINCE_KEY] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            $since = '';
        }

        return [$days, $since];
    }

    /**
     * Reset the stored counters (used by cleanup tooling).
     */
    public static function reset(): void
    {
        delete_option(self::OPTION_KEY);
    }

    /**
     * @return array<string,array{total:int,success:int,failed:int}>
     */
    private static function read_history(): array
    {
        $raw = get_option(self::OPTION_KEY, []);
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $month => $row) {
            if (!is_string($month) || !preg_match('/^\d{4}-\d{2}$/', $month)) {
                continue;
            }
            $clean[$month] = [
                'total'   => (int) (is_array($row) ? ($row['total']   ?? 0) : 0),
                'success' => (int) (is_array($row) ? ($row['success'] ?? 0) : 0),
                'failed'  => (int) (is_array($row) ? ($row['failed']  ?? 0) : 0),
            ];
        }

        return $clean;
    }

    /**
     * @param array<string,array{total:int,success:int,failed:int}> $history
     * @return array<string,array{total:int,success:int,failed:int}>
     */
    private static function trim_history(array $history): array
    {
        if (count($history) <= self::HISTORY_CAP) {
            return $history;
        }

        krsort($history);
        return array_slice($history, 0, self::HISTORY_CAP, true);
    }

    /**
     * Approximate rolling 30d total using only the in-option history.
     *
     * Used when the audit table cannot be queried. We add the current
     * calendar month to a fraction of the previous month proportional to
     * how far into the current month we are.
     */
    private static function last_30d_from_history(): int
    {
        $history = self::read_history();
        if (empty($history)) {
            return 0;
        }

        $this_month = gmdate('Y-m');
        $last_month = gmdate('Y-m', strtotime('-1 month'));

        $current = (int) ($history[$this_month]['total'] ?? 0);
        $prev    = (int) ($history[$last_month]['total'] ?? 0);

        $days_into_month = max(1, (int) gmdate('j'));
        $prev_weight     = max(0.0, min(1.0, (30 - $days_into_month) / 30));

        return (int) round($current + ($prev * $prev_weight));
    }
}
