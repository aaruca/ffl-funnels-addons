<?php
/**
 * Offline check of Smart Coupons' recurring schedule (Smart Coupons tab →
 * When it works → Schedule): weekly days, days of the month (also over the
 * turn of the month and in short months), dates each year (also over the new
 * year and on February 29), what the customer is told, what is saved from
 * the coupon screen, and the rows on that screen.
 *
 * Run: php tests/smoke/smart-coupons-schedule-smoke.php
 */

define('ABSPATH', __DIR__ . '/');

$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
}

function __($text, $domain = null) { return $text; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($text, $domain = null) { return esc_html($text); }
function absint($v) { return abs((int) $v); }
function wp_timezone() { return new DateTimeZone('America/Chicago'); }
function wp_date($format, $ts = null) { return (new DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(wp_timezone())->format($format); }
function date_i18n($format, $ts) { return gmdate($format, (int) $ts); }
function checked($a, $b = true, $echo = true) { return (string) $a === (string) $b ? ' checked="checked"' : ''; }
function selected($a, $b = true, $echo = true) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
function wc_format_coupon_code($code) { return strtolower(trim((string) $code)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function wp_roles() { return new class { public function get_names() { return ['customer' => 'Customer']; } }; }

// Enough of WordPress and WooCommerce for FFLA_Coupon_Rules::validate() to reach the schedule.
function get_option($key, $default = false) { return $default; }
function get_transient($key) { return 0; }
function apply_filters($tag, $value) { return $value; }
function sanitize_text_field($s) { return trim((string) $s); }
function wp_unslash($s) { return $s; }
function esc_html_e($t) { echo $t; }
function get_current_user_id() { return 0; }
class WC_Cart {}
class WC_Discounts
{
    private $cart;
    public function __construct($cart) { $this->cart = $cart; }
    public function get_object() { return $this->cart; }
}
class WC_Coupon
{
    public $meta = [];
    public function __construct($id = 0) {}
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function get_code() { return 'weekend'; }
}

require dirname(__DIR__, 2) . '/modules/smart-coupons/includes/class-ffla-coupon-schedule.php';
require dirname(__DIR__, 2) . '/modules/smart-coupons/includes/class-ffla-coupon-rules.php';
require dirname(__DIR__, 2) . '/modules/smart-coupons/includes/class-ffla-coupon-settings.php';
require dirname(__DIR__, 2) . '/modules/smart-coupons/includes/class-ffla-coupon-admin.php';

function on(string $date): DateTimeImmutable
{
    return new DateTimeImmutable($date . ' 12:00:00', wp_timezone());
}

function schedule(array $in): array
{
    return FFLA_Coupon_Schedule::sanitize($in);
}

/* ── One time ──────────────────────────────────────────────────────────── */

$once = schedule([]);
check('once' === $once['schedule'] && '' === FFLA_Coupon_Schedule::refusal($once, on('2026-03-04')), 'one time (the default): no schedule limit');

/* ── Every week ────────────────────────────────────────────────────────── */

$weekend = schedule(['schedule' => 'recurring', 'frequency' => 'weekly', 'weekdays' => ['6', '7', '9', 'x']]);
check([6, 7] === $weekend['weekdays'], 'weekdays saved, unknown values dropped');
check('' === FFLA_Coupon_Schedule::refusal($weekend, on('2026-10-31')), 'Saturday: works'); // 2026-10-31 is a Saturday.
check('' === FFLA_Coupon_Schedule::refusal($weekend, on('2026-11-01')), 'Sunday: works');
check('This coupon only works on Saturdays and Sundays.' === FFLA_Coupon_Schedule::refusal($weekend, on('2026-11-02')), 'Monday: the customer is told when it works');
$three = schedule(['schedule' => 'recurring', 'frequency' => 'weekly', 'weekdays' => [1, 3, 5]]);
check('on Mondays, Wednesdays and Fridays' === FFLA_Coupon_Schedule::describe($three), 'three days read naturally');
// Store time, not UTC: 11 pm Saturday in Chicago is Sunday in UTC.
check(FFLA_Coupon_Schedule::matches(schedule(['schedule' => 'recurring', 'weekdays' => [6]]), new DateTimeImmutable('2026-10-31 23:30:00', wp_timezone())), 'the store\'s day counts, not UTC\'s');
$none = schedule(['schedule' => 'recurring', 'frequency' => 'weekly', 'weekdays' => []]);
check('' === FFLA_Coupon_Schedule::refusal($none, on('2026-11-02')) && !FFLA_Coupon_Schedule::is_recurring($none), 'recurring with no day chosen does not lock the coupon');

/* ── Every month ───────────────────────────────────────────────────────── */

$first_week = schedule(['schedule' => 'recurring', 'frequency' => 'monthly', 'month_from' => '1', 'month_to' => '7']);
check('' === FFLA_Coupon_Schedule::refusal($first_week, on('2026-11-05')), 'the 5th: works');
check('This coupon only works from the 1st to the 7th of each month.' === FFLA_Coupon_Schedule::refusal($first_week, on('2026-11-08')), 'the 8th: refused with the days');
$turn = schedule(['schedule' => 'recurring', 'frequency' => 'monthly', 'month_from' => 25, 'month_to' => 5]);
check(FFLA_Coupon_Schedule::matches($turn, on('2026-11-28')) && FFLA_Coupon_Schedule::matches($turn, on('2026-12-03')) && !FFLA_Coupon_Schedule::matches($turn, on('2026-12-10')), '25th to 5th runs over the turn of the month');
$end = schedule(['schedule' => 'recurring', 'frequency' => 'monthly', 'month_from' => 29, 'month_to' => 31]);
check(FFLA_Coupon_Schedule::matches($end, on('2027-02-28')) && !FFLA_Coupon_Schedule::matches($end, on('2027-02-27')), 'in February the 29th–31st means its last day');
check(FFLA_Coupon_Schedule::matches($end, on('2026-04-30')), 'in April the 31st means the 30th');
$payday = schedule(['schedule' => 'recurring', 'frequency' => 'monthly', 'month_from' => 15, 'month_to' => 15]);
check('on the 15th of each month' === FFLA_Coupon_Schedule::describe($payday), 'one day of the month');
check('1st 2nd 3rd 4th 11th 12th 13th 21st 22nd 23rd 31st' === implode(' ', array_map(['FFLA_Coupon_Schedule', 'ordinal'], [1, 2, 3, 4, 11, 12, 13, 21, 22, 23, 31])), 'ordinals');
check(1 === schedule(['month_from' => '40'])['month_from'] && 7 === schedule(['month_to' => '0'])['month_to'], 'days outside 1–31 fall back');

/* ── Every year ────────────────────────────────────────────────────────── */

$black_friday = schedule(['schedule' => 'recurring', 'frequency' => 'yearly', 'year_from' => '2026-11-25', 'year_to' => '2026-12-02']);
check('11-25' === $black_friday['year_from'] && '12-02' === $black_friday['year_to'], 'dates saved as day and month (the year is ignored)');
check('' === FFLA_Coupon_Schedule::refusal($black_friday, on('2027-11-30')), 'comes back the next year');
check('This coupon only works from November 25 to December 2 each year.' === FFLA_Coupon_Schedule::refusal($black_friday, on('2027-12-05')), 'outside: refused with the dates');
$holidays = schedule(['schedule' => 'recurring', 'frequency' => 'yearly', 'year_from' => '12-20', 'year_to' => '01-05']);
check(FFLA_Coupon_Schedule::matches($holidays, on('2026-12-24')) && FFLA_Coupon_Schedule::matches($holidays, on('2027-01-02')) && !FFLA_Coupon_Schedule::matches($holidays, on('2027-02-01')), 'December 20 to January 5 runs over the new year');
$leap = schedule(['schedule' => 'recurring', 'frequency' => 'yearly', 'year_from' => '02-29', 'year_to' => '02-29']);
check(FFLA_Coupon_Schedule::matches($leap, on('2027-02-28')) && FFLA_Coupon_Schedule::matches($leap, on('2028-02-29')) && !FFLA_Coupon_Schedule::matches($leap, on('2028-02-28')), 'February 29 means February 28 in other years');
check('' === schedule(['year_from' => '2026-02-30'])['year_from'] && '' === schedule(['year_from' => 'soon'])['year_from'], 'impossible dates are not saved');
$half = schedule(['schedule' => 'recurring', 'frequency' => 'yearly', 'year_from' => '07-04']);
check(!FFLA_Coupon_Schedule::is_recurring($half) && '' === FFLA_Coupon_Schedule::refusal($half, on('2026-01-01')), 'a yearly schedule without an end date does not lock the coupon');
check('on July 4 each year' === FFLA_Coupon_Schedule::describe(schedule(['frequency' => 'yearly', 'year_from' => '07-04', 'year_to' => '07-04'])), 'one date each year');
check('weekly' === schedule(['frequency' => 'hourly'])['frequency'], 'an unknown frequency falls back to weekly');

/* ── Saved with the coupon's other options ─────────────────────────────── */

$defaults = FFLA_Coupon_Settings::coupon_defaults();
check('once' === $defaults['schedule'] && true === $defaults['repeat'], 'coupon defaults include the schedule; Buy X get Y\'s own "repeat" is untouched');
$saved = FFLA_Coupon_Settings::sanitize_coupon(['schedule' => 'recurring', 'frequency' => 'monthly', 'month_from' => '1', 'month_to' => '7', 'starts' => '2026-11-01', 'repeat' => '1']);
check('recurring' === $saved['schedule'] && 'monthly' === $saved['frequency'] && '2026-11-01' === $saved['starts'] && true === $saved['repeat'], 'the coupon screen saves the schedule next to Starts on');

/* ── The rows on the coupon screen ─────────────────────────────────────── */

$rows = new ReflectionMethod('FFLA_Coupon_Admin', 'schedule_rows');
$rows->setAccessible(true);
ob_start();
$rows->invoke(null, FFLA_Coupon_Settings::coupon_defaults());
$html = ob_get_clean();
check(strpos($html, 'id="ffla_schedule" name="ffla[schedule]"') !== false && strpos($html, '<option value="recurring"') !== false, 'Schedule: One time or Recurring');
check(strpos($html, 'name="ffla[starts]"') !== false, 'Starts on stays');
check(strpos($html, 'id="ffla_frequency"') !== false && strpos($html, 'ffla-show-recurring') !== false, 'Repeats, shown for recurring');
check(7 === substr_count($html, 'name="ffla[weekdays][]"') && strpos($html, 'ffla-show-weekly') !== false, 'a box per weekday, for weekly');
check(strpos($html, 'name="ffla[month_from]"') !== false && strpos($html, 'name="ffla[month_to]"') !== false && strpos($html, 'ffla-show-monthly') !== false, 'start and end day, for monthly');
check(strpos($html, 'type="date" class="short" id="ffla_year_from"') !== false && strpos($html, 'id="ffla_year_to"') !== false && strpos($html, 'ffla-show-yearly') !== false, 'start and end date, for yearly');
ob_start();
$rows->invoke(null, $weekend + FFLA_Coupon_Settings::coupon_defaults());
$html = ob_get_clean();
check(2 === substr_count($html, 'checked="checked"') && strpos($html, 'As saved: works on Saturdays and Sundays.') !== false, 'a saved schedule is ticked and summed up');

/* ── At the cart ───────────────────────────────────────────────────────── */

$today   = (int) (new DateTimeImmutable('now', wp_timezone()))->format('N');
$coupon  = new WC_Coupon();
$coupon->meta['_ffla_coupon'] = ['schedule' => 'recurring', 'frequency' => 'weekly', 'weekdays' => array_values(array_diff(range(1, 7), [$today]))];
$message = '';
try {
    FFLA_Coupon_Rules::validate(true, $coupon, new WC_Discounts(new WC_Cart()));
} catch (Exception $e) {
    $message = $e->getMessage();
}
check(0 === strpos($message, 'This coupon only works on '), 'a recurring coupon applied on another day is refused in the cart: ' . $message);

$coupon->meta['_ffla_coupon']['weekdays'] = [$today];
$message = '';
try {
    FFLA_Coupon_Rules::validate(true, $coupon, new WC_Discounts(new WC_Cart()));
} catch (Throwable $e) {
    $message = $e->getMessage(); // Later checks need more of WooCommerce than this file stubs.
}
check(false === strpos($message, 'This coupon only works'), 'on one of its days the schedule lets it through');

echo "OK: {$checks} smart-coupons schedule checks passed\n";
