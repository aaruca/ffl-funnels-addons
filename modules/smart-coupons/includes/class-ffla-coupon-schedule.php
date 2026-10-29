<?php
/**
 * Smart Coupons — when a coupon works (Smart Coupons tab → When it works).
 *
 * One time: from "Starts on" until WooCommerce's expiry date, as before.
 * Recurring: only on chosen days, again and again, still between "Starts on"
 * and the expiry date (which end the whole series):
 * - every week, on the chosen weekdays (a weekend sale);
 * - every month, from one day of the month to another (the 1st to the 7th;
 *   25 to 5 runs over the turn of the month; a day past the end of a short
 *   month means its last day);
 * - every year, from one date to another (November 25 to December 2; a range
 *   may run over the new year; February 29 means February 28 in other years).
 *
 * Days are whole days in the store's time zone.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Coupon_Schedule
{
    const FREQUENCIES = ['weekly', 'monthly', 'yearly'];

    /** Per-coupon options this class owns (stored with the coupon's other options). */
    public static function defaults(): array
    {
        return [
            'schedule'   => 'once',   // once, recurring
            'frequency'  => 'weekly', // weekly, monthly, yearly
            'weekdays'   => [],       // ISO-8601: 1 = Monday … 7 = Sunday.
            'month_from' => 1,        // Day of the month, 1-31.
            'month_to'   => 7,
            'year_from'  => '',       // m-d
            'year_to'    => '',       // m-d
        ];
    }

    /**
     * @param array<string, mixed> $in Posted values: dates as Y-m-d (the year
     *                                 is ignored) or m-d.
     */
    public static function sanitize(array $in): array
    {
        $day = static function ($value, int $default): int {
            $value = (int) $value;
            return $value >= 1 && $value <= 31 ? $value : $default;
        };
        $md = static function ($value): string {
            $value = trim((string) $value);
            if (preg_match('/^(?:\d{4}-)?(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[1], (int) $m[2], 2024)) {
                return $m[1] . '-' . $m[2];
            }
            return '';
        };

        $weekdays = array_values(array_unique(array_filter(array_map('intval', (array) ($in['weekdays'] ?? [])), static function (int $d): bool {
            return $d >= 1 && $d <= 7;
        })));
        sort($weekdays);

        return [
            'schedule'   => 'recurring' === ($in['schedule'] ?? '') ? 'recurring' : 'once',
            'frequency'  => in_array($in['frequency'] ?? '', self::FREQUENCIES, true) ? (string) $in['frequency'] : 'weekly',
            'weekdays'   => $weekdays,
            'month_from' => $day($in['month_from'] ?? 1, 1),
            'month_to'   => $day($in['month_to'] ?? 7, 7),
            'year_from'  => $md($in['year_from'] ?? ''),
            'year_to'    => $md($in['year_to'] ?? ''),
        ];
    }

    public static function is_recurring(array $o): bool
    {
        return 'recurring' === ($o['schedule'] ?? 'once') && '' !== self::describe($o);
    }

    /**
     * '' when the coupon may be used on $now (store time), else what to tell
     * the customer. A recurring schedule with nothing chosen does not limit.
     */
    public static function refusal(array $o, ?DateTimeImmutable $now = null): string
    {
        if (!self::is_recurring($o)) {
            return '';
        }

        $now = $now ?: new DateTimeImmutable('now', wp_timezone());

        return self::matches($o, $now) ? '' : sprintf(
            /* translators: %s: e.g. "on Saturdays and Sundays", "from the 1st to the 7th of each month" */
            __('This coupon only works %s.', 'ffl-funnels-addons'),
            self::describe($o)
        );
    }

    public static function matches(array $o, DateTimeImmutable $now): bool
    {
        switch ($o['frequency'] ?? 'weekly') {
            case 'monthly':
                $last = (int) $now->format('t');
                $from = min((int) $o['month_from'], $last);
                $to   = min((int) $o['month_to'], $last);
                $day  = (int) $now->format('j');

                return $from <= $to ? ($day >= $from && $day <= $to) : ($day >= $from || $day <= $to);

            case 'yearly':
                $leap  = '1' === $now->format('L');
                $clamp = static function (string $md) use ($leap): string {
                    return !$leap && '02-29' === $md ? '02-28' : $md;
                };
                $from  = $clamp((string) $o['year_from']);
                $to    = $clamp((string) $o['year_to']);
                $today = $now->format('m-d');

                return $from <= $to ? ($today >= $from && $today <= $to) : ($today >= $from || $today <= $to);

            default:
                return in_array((int) $now->format('N'), array_map('intval', (array) $o['weekdays']), true);
        }
    }

    /**
     * When it works, as words: "on Saturdays and Sundays", "from the 1st to
     * the 7th of each month", "from November 25 to December 2 each year".
     * '' when the schedule is incomplete.
     */
    public static function describe(array $o): string
    {
        switch ($o['frequency'] ?? 'weekly') {
            case 'monthly':
                $from = (int) ($o['month_from'] ?? 0);
                $to   = (int) ($o['month_to'] ?? 0);
                if ($from < 1 || $to < 1) {
                    return '';
                }
                if ($from === $to) {
                    /* translators: %s: day of the month, e.g. "15th" */
                    return sprintf(__('on the %s of each month', 'ffl-funnels-addons'), self::ordinal($from));
                }
                /* translators: 1: first day, e.g. "1st", 2: last day, e.g. "7th" */
                return sprintf(__('from the %1$s to the %2$s of each month', 'ffl-funnels-addons'), self::ordinal($from), self::ordinal($to));

            case 'yearly':
                if ('' === (string) ($o['year_from'] ?? '') || '' === (string) ($o['year_to'] ?? '')) {
                    return '';
                }
                if ($o['year_from'] === $o['year_to']) {
                    /* translators: %s: date, e.g. "July 4" */
                    return sprintf(__('on %s each year', 'ffl-funnels-addons'), self::month_day((string) $o['year_from']));
                }
                /* translators: 1: first date, e.g. "November 25", 2: last date, e.g. "December 2" */
                return sprintf(__('from %1$s to %2$s each year', 'ffl-funnels-addons'), self::month_day((string) $o['year_from']), self::month_day((string) $o['year_to']));

            default:
                $names = self::weekday_names(true);
                $days  = array_values(array_filter(array_map(static function ($d) use ($names) {
                    return $names[(int) $d] ?? '';
                }, (array) ($o['weekdays'] ?? []))));
                if (empty($days)) {
                    return '';
                }
                $last = array_pop($days);
                $list = empty($days) ? $last : sprintf(
                    /* translators: 1: comma-separated days, 2: last day */
                    __('%1$s and %2$s', 'ffl-funnels-addons'),
                    implode(', ', $days),
                    $last
                );
                /* translators: %s: days, e.g. "Saturdays and Sundays" */
                return sprintf(__('on %s', 'ffl-funnels-addons'), $list);
        }
    }

    /**
     * @return array<int, string> 1 = Monday … 7 = Sunday.
     */
    public static function weekday_names(bool $plural = false): array
    {
        return $plural ? [
            1 => __('Mondays', 'ffl-funnels-addons'),
            2 => __('Tuesdays', 'ffl-funnels-addons'),
            3 => __('Wednesdays', 'ffl-funnels-addons'),
            4 => __('Thursdays', 'ffl-funnels-addons'),
            5 => __('Fridays', 'ffl-funnels-addons'),
            6 => __('Saturdays', 'ffl-funnels-addons'),
            7 => __('Sundays', 'ffl-funnels-addons'),
        ] : [
            1 => __('Mon', 'ffl-funnels-addons'),
            2 => __('Tue', 'ffl-funnels-addons'),
            3 => __('Wed', 'ffl-funnels-addons'),
            4 => __('Thu', 'ffl-funnels-addons'),
            5 => __('Fri', 'ffl-funnels-addons'),
            6 => __('Sat', 'ffl-funnels-addons'),
            7 => __('Sun', 'ffl-funnels-addons'),
        ];
    }

    /** 1st, 2nd, 3rd, 4th … 11th, 12th, 13th … 21st, 22nd, 23rd … 31st. */
    public static function ordinal(int $n): string
    {
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return $n . 'th';
        }
        $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th';

        return $n . $suffix;
    }

    /** "11-25" → "November 25" (in the site's language). */
    public static function month_day(string $md): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', '2024-' . $md);
        if (!$date) {
            return $md;
        }

        return function_exists('date_i18n') ? date_i18n('F j', $date->getTimestamp() + $date->getOffset()) : $date->format('F j');
    }
}
