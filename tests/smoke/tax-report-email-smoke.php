<?php
/**
 * Report email delivery: the monthly send after the shared-delivery refactor,
 * and "Email me this report" background requests (queueing, sending to the
 * requesting admin, waiting for the queue lock, permission loss, failures, the
 * oversized-package fallback and temporary-file cleanup).
 * Report generation and packaging are stubbed; no database, mail or network.
 * Run: php tests/smoke/tax-report-email-smoke.php
 */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['options'] = ['admin_email' => 'owner@example.com'];
$GLOBALS['transients'] = [];
$GLOBALS['mail'] = [];
$GLOBALS['actions'] = [];
$GLOBALS['generated'] = [];
$GLOBALS['temp_files'] = [];
$GLOBALS['package_bytes'] = 2048;
$GLOBALS['generate_error'] = '';
$GLOBALS['users'] = [
    7 => (object) ['ID' => 7, 'user_email' => 'manager@example.com', 'caps' => ['manage_woocommerce']],
    8 => (object) ['ID' => 8, 'user_email' => 'clerk@example.com', 'caps' => []],
];

function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_html__($s, $d = null) { return esc_html($s); }
function sanitize_email($s) { return trim((string) $s); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $s)); }
function is_email($s) { return (bool) filter_var($s, FILTER_VALIDATE_EMAIL); }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['options'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function wp_timezone() { return new DateTimeZone('America/New_York'); }
function get_bloginfo($k) { return 'Fixture Store'; }
function wp_specialchars_decode($s, $q = null) { return $s; }
function size_format($b, $d = 0) { return $b . ' B'; }
function get_userdata($id) { return $GLOBALS['users'][$id] ?? false; }
function user_can($user, $cap) { return in_array($cap, $user->caps ?? [], true); }
function wp_mail($to, $subject, $message, $headers, $attachments) {
    $exists = array_map('is_file', (array) $attachments);
    $GLOBALS['mail'][] = compact('to', 'subject', 'message', 'attachments', 'exists');
    return empty($GLOBALS['mail_fails']);
}
function as_enqueue_async_action($hook, $args, $group, $unique) { $GLOBALS['actions'][] = ['async', $hook, $args, $group]; return count($GLOBALS['actions']); }
function as_schedule_single_action($ts, $hook, $args, $group, $unique) { $GLOBALS['actions'][] = ['single', $hook, $args, $group, $ts]; return count($GLOBALS['actions']); }
function as_has_scheduled_action($hook, $args, $group) {
    foreach ($GLOBALS['actions'] as $action) {
        if ($action[1] === $hook && $action[2] === $args) { return true; }
    }
    return false;
}
function ffla_tax_log($level, $message, $context) { $GLOBALS['logged'][] = $context; }

/** Stand-ins for report generation and packaging. */
class Tax_Report_Service {
    public static function normalize_filters(array $input): array {
        foreach (['date_from', 'date_to'] as $key) {
            if (!isset($input[$key]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $input[$key])) {
                throw new InvalidArgumentException('Enter a valid report date range.');
            }
        }
        return [
            'date_from' => $input['date_from'], 'date_to' => $input['date_to'],
            'statuses' => $input['statuses'] ?? ['completed'], 'states' => $input['states'] ?? [],
            'include_negative_orders' => !empty($input['include_negative_orders']),
            'report_detail' => $input['report_detail'] ?? 'filing', 'include_pii' => !empty($input['include_pii']),
        ];
    }
    public function generate(array $filters, array $options = []): array {
        $GLOBALS['generated'][] = $filters;
        if ($GLOBALS['generate_error'] !== '') { throw new RuntimeException($GLOBALS['generate_error']); }
        return ['manifest' => ['report_id' => 'rep-' . count($GLOBALS['generated']), 'filters' => $filters], 'stats' => ['orders' => 3],
            'summaries' => ['states' => [], 'jurisdictions' => [], 'filing_totals' => [['currency' => 'USD', 'taxable_sales' => '100.00', 'net_tax' => '8.00', 'calculated_tax' => '8.00', 'over_under' => '0.00']]]];
    }
}
class Tax_Report_Exporter {
    private static function temp(string $name, int $bytes): array {
        $path = tempnam(sys_get_temp_dir(), 'ffla-mail-');
        file_put_contents($path, 'x');
        $GLOBALS['temp_files'][] = $path;
        return ['path' => $path, 'filename' => $name, 'bytes' => $bytes];
    }
    public static function create_package(array $report): array { return self::temp('package.zip', $GLOBALS['package_bytes']); }
    public static function create_summary_pdf(array $report): array { return self::temp('summary.pdf', 1024); }
    public static function cleanup_file(string $path): void { if ($path !== '' && is_file($path)) { unlink($path); } }
}

require_once __DIR__ . '/../../modules/tax-rates/includes/class-tax-report-email.php';

$checks = 0;
function check($ok, $label) { global $checks; if (!$ok) { throw new RuntimeException($label); } $checks++; }
function history(): array { return $GLOBALS['options'][Tax_Report_Email::HISTORY_OPTION] ?? []; }
function no_temp_files_left(): bool { foreach ($GLOBALS['temp_files'] as $path) { if (is_file($path)) { return false; } } return true; }
function reset_state(): void {
    $GLOBALS['mail'] = []; $GLOBALS['actions'] = []; $GLOBALS['generated'] = []; $GLOBALS['transients'] = [];
    $GLOBALS['options'][Tax_Report_Email::HISTORY_OPTION] = []; $GLOBALS['package_bytes'] = 2048; $GLOBALS['generate_error'] = '';
    unset($GLOBALS['mail_fails']);
}
$request = ['date_from' => '2026-01-01', 'date_to' => '2026-10-16', 'statuses' => ['completed', 'refunded'], 'report_detail' => 'filing',
    'page' => 'ffla-sales-tax-reports', '_wpnonce' => 'abc', 'action' => 'ffla_tax_report_export', 'delivery' => 'email'];

// 1. Queueing keeps only the filters and does not duplicate a pending request.
reset_state();
check(Tax_Report_Email::queue_on_demand($request, 7) === true, 'Request queued');
check(count($GLOBALS['actions']) === 1 && $GLOBALS['actions'][0][1] === Tax_Report_Email::ON_DEMAND_HOOK && $GLOBALS['actions'][0][3] === Tax_Report_Email::ACTION_GROUP, 'Queued as one async action in the report group');
[$queued_user, $queued_filters, $queued_attempt] = $GLOBALS['actions'][0][2];
check($queued_user === 7 && $queued_attempt === 0, 'Action carries the requesting user and attempt 0');
check(!isset($queued_filters['_wpnonce']) && !isset($queued_filters['action']) && $queued_filters['date_to'] === '2026-10-16' && $queued_filters['include_pii'] === false, 'Only normalized report filters are stored with the action');
check(Tax_Report_Email::queue_on_demand($request, 7) === true && count($GLOBALS['actions']) === 1, 'An identical pending request is not queued twice');
check(Tax_Report_Email::queue_on_demand($request, 0) === false, 'A request needs a user');
$threw = false;
try { Tax_Report_Email::queue_on_demand(['date_from' => 'bad'], 7); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'Invalid filters are rejected before queueing');

// 2. The request is built and emailed to the requesting admin only.
reset_state();
check(Tax_Report_Email::run_on_demand(7, $queued_filters, 0) === true, 'Background request sent');
check(count($GLOBALS['mail']) === 1 && $GLOBALS['mail'][0]['to'] === ['manager@example.com'], 'Sent to the requesting admin\'s account email only');
check($GLOBALS['generated'][0]['date_from'] === '2026-01-01' && $GLOBALS['generated'][0]['date_to'] === '2026-10-16', 'Report built for the requested period');
check(strpos($GLOBALS['mail'][0]['subject'], '2026-01-01 to 2026-10-16') !== false, 'Subject names the period');
check(strpos($GLOBALS['mail'][0]['message'], '<h2>Sales tax filing report</h2>') !== false, 'Background email is not labeled monthly');
check($GLOBALS['mail'][0]['exists'] === [true], 'Package exists while it is sent');
check(no_temp_files_left(), 'Package deleted after sending');
$entry = history()[0];
check($entry['status'] === 'sent' && $entry['mode'] === 'on_demand' && $entry['report_id'] === 'rep-1', 'History records the sent request');
check(!isset($entry['recipients']) && $entry['recipients_count'] === 1, 'History keeps a recipient count, not the address');
check(!isset($GLOBALS['transients'][Tax_Report_Email::LOCK_TRANSIENT]), 'Queue lock released');

// 3. Shipping addresses requested: the email carries the confidentiality notice.
reset_state();
Tax_Report_Email::run_on_demand(7, array_merge($queued_filters, ['include_pii' => true]), 0);
check(strpos($GLOBALS['mail'][0]['message'], 'Confidential:') !== false, 'PII package is flagged as confidential');

// 4. Another report email holds the lock: wait and retry, then give up.
reset_state();
$GLOBALS['transients'][Tax_Report_Email::LOCK_TRANSIENT] = 1;
check(Tax_Report_Email::run_on_demand(7, $queued_filters, 0) === false && empty($GLOBALS['mail']) && empty($GLOBALS['generated']), 'Nothing is built while another report email runs');
check(count($GLOBALS['actions']) === 1 && $GLOBALS['actions'][0][0] === 'single' && $GLOBALS['actions'][0][2] === [7, $queued_filters, 1], 'Retried later as attempt 1 with the same filters');
check(history()[0]['status'] === 'waiting', 'History shows the request waiting');
$GLOBALS['actions'] = [];
Tax_Report_Email::run_on_demand(7, $queued_filters, Tax_Report_Email::ON_DEMAND_MAX_ATTEMPTS - 1);
check(empty($GLOBALS['actions']) && history()[0]['status'] === 'failed', 'Last attempt stops retrying and reports a failure');
check(!empty($GLOBALS['transients'][Tax_Report_Email::LOCK_TRANSIENT]), 'Another job\'s lock is left alone');

// 5. A user who lost access gets nothing.
reset_state();
check(Tax_Report_Email::run_on_demand(8, $queued_filters, 0) === false && empty($GLOBALS['mail']) && empty($GLOBALS['generated']), 'No report for a user without manage_woocommerce');
check(history()[0]['status'] === 'failed' && strpos(history()[0]['message'], 'can no longer receive') !== false, 'Permission loss recorded');
check(Tax_Report_Email::run_on_demand(99, $queued_filters, 0) === false && empty($GLOBALS['mail']), 'No report for a deleted user');

// 6. A failure is recorded, logged, and leaves no lock or files behind.
reset_state();
$GLOBALS['generate_error'] = 'The report stopped before it reached this server\'s memory limit.';
check(Tax_Report_Email::run_on_demand(7, $queued_filters, 0) === false && empty($GLOBALS['mail']), 'Failed build sends nothing');
check(history()[0]['status'] === 'failed' && strpos(history()[0]['message'], 'memory limit') !== false, 'Failure reason recorded');
check(empty($GLOBALS['actions']), 'A failed build is not retried automatically');
check(!isset($GLOBALS['transients'][Tax_Report_Email::LOCK_TRANSIENT]), 'Lock released after a failure');
reset_state();
$GLOBALS['mail_fails'] = true;
check(Tax_Report_Email::run_on_demand(7, $queued_filters, 0) === false && history()[0]['status'] === 'failed', 'Mail transport refusal is a failure');
check(no_temp_files_left(), 'Package deleted after a refused send');

// 7. Package above the attachment limit: the PDF summary is sent instead.
reset_state();
$GLOBALS['package_bytes'] = 16 * 1024 * 1024;
Tax_Report_Email::run_on_demand(7, $queued_filters, 0);
check(history()[0]['status'] === 'sent_summary_only' && basename($GLOBALS['mail'][0]['attachments'][0]) !== '' && $GLOBALS['mail'][0]['exists'] === [true], 'Oversized package falls back to the PDF summary');
check(no_temp_files_left(), 'Both package and summary deleted');

// 8. Monthly email still sends to the configured recipients (shared delivery refactor).
reset_state();
$GLOBALS['options'][Tax_Report_Email::SETTINGS_OPTION] = ['enabled' => '1', 'recipients' => ['accountant@example.com'], 'statuses' => ['completed']];
check(Tax_Report_Email::run('manual', 0, '2026-09-01', '2026-09-30') === true, 'Manual monthly send works');
check($GLOBALS['mail'][0]['to'] === ['accountant@example.com'] && strpos($GLOBALS['mail'][0]['message'], '<h2>Monthly sales tax filing report</h2>') !== false, 'Monthly email keeps its recipients and heading');
check($GLOBALS['generated'][0]['date_from'] === '2026-09-01' && history()[0]['status'] === 'sent' && history()[0]['mode'] === 'manual', 'Monthly history unchanged');
check(no_temp_files_left() && !isset($GLOBALS['transients'][Tax_Report_Email::LOCK_TRANSIENT]), 'Monthly send cleans up');

echo $checks . " report email checks passed.\n";
