<?php
/**
 * USGeocoder API usage counter.
 *
 * Tracks how many real HTTP calls we make against the paid USGeocoder API
 * so store owners can estimate their monthly bill and catch runaway usage.
 *
 * Two views are surfaced:
 *   - A `YYYY-MM` history kept in a single wp_option (trimmed to the last
 *     24 months) that survives even after the audit table is purged. Split
 *     per-month into success / failed / total so invalid keys are visible.
 *   - A live rolling 30-day total derived from `wp_ffla_tax_quotes_audit`
 *     filtered on `source_code = 'usgeocoder_api'` + `cache_hit = 0`. Uses
 *     the existing `idx_requested` index for a cheap scan.
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

    /**
     * Record a real HTTP call to the USGeocoder API.
     *
     * @param bool $success Whether the call returned a usable payload.
     */
    public static function record_call(bool $success): void
    {
        $month   = gmdate('Y-m');
        $history = self::read_history();

        if (!isset($history[$month]) || !is_array($history[$month])) {
            $history[$month] = ['total' => 0, 'success' => 0, 'failed' => 0];
        }

        $history[$month]['total']   = (int) $history[$month]['total'] + 1;
        $history[$month]['success'] = (int) $history[$month]['success'] + ($success ? 1 : 0);
        $history[$month]['failed']  = (int) $history[$month]['failed'] + ($success ? 0 : 1);

        $history = self::trim_history($history);

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
     * Get the live rolling 30-day call count from the audit table.
     *
     * Falls back to the history option if the audit table is unavailable.
     */
    public static function get_last_30d(): int
    {
        global $wpdb;

        if (!class_exists('Tax_Resolver_DB')) {
            return self::last_30d_from_history();
        }

        $table = Tax_Resolver_DB::table('quotes_audit');

        if (!$table) {
            return self::last_30d_from_history();
        }

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
