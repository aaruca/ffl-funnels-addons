<?php
/**
 * Scheduled delivery of WooCommerce tax report packages.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Report_Email
{
    const SETTINGS_OPTION = 'ffla_tax_report_email_settings';
    const HISTORY_OPTION = 'ffla_tax_report_email_history';
    const SCHEDULE_HOOK = 'ffla_tax_report_monthly_email';
    const SEND_HOOK = 'ffla_tax_report_email_send';
    const ACTION_GROUP = 'ffla-tax-reports';
    const LOCK_TRANSIENT = 'ffla_tax_report_email_lock';

    public static function init(): void
    {
        add_action(self::SCHEDULE_HOOK, [__CLASS__, 'run_scheduled'], 10, 1);
        add_action(self::SEND_HOOK, [__CLASS__, 'run'], 10, 4);
        // WooCommerce initializes Action Scheduler at init priority 1.
        add_action('init', [__CLASS__, 'ensure_schedule'], 20);
    }

    public static function default_settings(): array
    {
        return [
            'enabled'           => '0',
            'recipients'        => [sanitize_email((string) get_option('admin_email'))],
            'send_day'          => 2,
            'send_time'         => '06:00',
            'statuses'          => ['processing', 'completed', 'on-hold', 'refunded'],
            'include_pii'       => '0',
            'max_attachment_mb' => 15,
        ];
    }

    public static function get_settings(): array
    {
        $saved = get_option(self::SETTINGS_OPTION, []);
        return self::sanitize_settings(array_merge(self::default_settings(), is_array($saved) ? $saved : []));
    }

    public static function sanitize_settings(array $input): array
    {
        $defaults = self::default_settings();
        $raw_recipients = $input['recipients'] ?? $defaults['recipients'];
        if (is_array($raw_recipients)) {
            $recipient_parts = $raw_recipients;
        } else {
            $recipient_parts = preg_split('/[,;\r\n]+/', (string) $raw_recipients) ?: [];
        }

        $recipients = [];
        foreach ($recipient_parts as $recipient) {
            $email = sanitize_email(trim((string) $recipient));
            if ($email !== '' && is_email($email)) {
                $recipients[] = $email;
            }
        }
        $recipients = array_values(array_unique($recipients));
        if (empty($recipients) && !empty($defaults['recipients'][0])) {
            $recipients[] = $defaults['recipients'][0];
        }

        $available = [];
        if (function_exists('wc_get_order_statuses')) {
            foreach (array_keys(wc_get_order_statuses()) as $status) {
                $available[] = preg_replace('/^wc-/', '', (string) $status);
            }
        }
        $requested_statuses = isset($input['statuses']) && is_array($input['statuses'])
            ? $input['statuses']
            : $defaults['statuses'];
        $statuses = [];
        foreach ($requested_statuses as $status) {
            $status = preg_replace('/^wc-/', '', sanitize_key((string) $status));
            if ($status !== '' && (empty($available) || in_array($status, $available, true))) {
                $statuses[] = $status;
            }
        }
        if (empty($statuses)) {
            $statuses = $defaults['statuses'];
        }

        $time = sanitize_text_field((string) ($input['send_time'] ?? $defaults['send_time']));
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            $time = $defaults['send_time'];
        }

        return [
            'enabled'           => !empty($input['enabled']) ? '1' : '0',
            'recipients'        => $recipients,
            'send_day'          => min(28, max(1, (int) ($input['send_day'] ?? $defaults['send_day']))),
            'send_time'         => $time,
            'statuses'          => array_values(array_unique($statuses)),
            'include_pii'       => !empty($input['include_pii']) ? '1' : '0',
            'max_attachment_mb' => min(50, max(1, (int) ($input['max_attachment_mb'] ?? $defaults['max_attachment_mb']))),
        ];
    }

    public static function update_settings(array $input): array
    {
        $settings = self::sanitize_settings($input);
        update_option(self::SETTINGS_OPTION, $settings, false);
        self::clear_schedule();
        self::ensure_schedule();
        return $settings;
    }

    public static function calculate_next_run(?DateTimeInterface $now = null, ?array $settings = null): DateTimeImmutable
    {
        $settings = $settings === null ? self::get_settings() : self::sanitize_settings($settings);
        $timezone = wp_timezone();
        $current = $now
            ? (new DateTimeImmutable($now->format('Y-m-d H:i:s'), $now->getTimezone()))->setTimezone($timezone)
            : new DateTimeImmutable('now', $timezone);
        $target = new DateTimeImmutable(
            sprintf('%s-%02d %s:00', $current->format('Y-m'), (int) $settings['send_day'], $settings['send_time']),
            $timezone
        );

        if ($target <= $current) {
            $next_month = $current->modify('first day of next month');
            $target = new DateTimeImmutable(
                sprintf('%s-%02d %s:00', $next_month->format('Y-m'), (int) $settings['send_day'], $settings['send_time']),
                $timezone
            );
        }

        return $target;
    }

    public static function previous_month_period(?DateTimeInterface $reference = null): array
    {
        $timezone = wp_timezone();
        $current = $reference
            ? (new DateTimeImmutable($reference->format('Y-m-d H:i:s'), $reference->getTimezone()))->setTimezone($timezone)
            : new DateTimeImmutable('now', $timezone);
        $month_start = new DateTimeImmutable($current->format('Y-m-01 00:00:00'), $timezone);
        $from = $month_start->modify('-1 month');
        $to = $month_start->modify('-1 day');

        return [
            'date_from' => $from->format('Y-m-d'),
            'date_to'   => $to->format('Y-m-d'),
        ];
    }

    public static function ensure_schedule(): void
    {
        $settings = self::get_settings();
        if ($settings['enabled'] !== '1') {
            self::clear_schedule();
            return;
        }

        $target = self::calculate_next_run(null, $settings);
        $args = [$target->format('Y-m')];
        if (self::has_scheduled_action(self::SCHEDULE_HOOK, $args)) {
            return;
        }

        self::schedule_single($target->getTimestamp(), self::SCHEDULE_HOOK, $args, true);
    }

    public static function clear_schedule(): void
    {
        if (self::action_scheduler_ready() && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::SCHEDULE_HOOK, [], self::ACTION_GROUP);
        }
        wp_clear_scheduled_hook(self::SCHEDULE_HOOK);
    }

    public static function clear_all_schedules(): void
    {
        if (self::action_scheduler_ready() && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::SCHEDULE_HOOK, [], self::ACTION_GROUP);
            as_unschedule_all_actions(self::SEND_HOOK, [], self::ACTION_GROUP);
        }
        wp_clear_scheduled_hook(self::SCHEDULE_HOOK);
        wp_clear_scheduled_hook(self::SEND_HOOK);
    }

    public static function get_next_run(): int
    {
        $settings = self::get_settings();
        if ($settings['enabled'] !== '1') {
            return 0;
        }
        $target = self::calculate_next_run(null, $settings);
        $args = [$target->format('Y-m')];

        if (self::action_scheduler_ready() && function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action(self::SCHEDULE_HOOK, $args, self::ACTION_GROUP);
            return is_int($next) ? $next : 0;
        }

        return (int) wp_next_scheduled(self::SCHEDULE_HOOK, $args);
    }

    public static function queue_manual_send(string $mode): bool
    {
        $mode = $mode === 'test' ? 'test' : 'manual';
        $args = [$mode, 0, '', ''];
        if (self::action_scheduler_ready() && function_exists('as_enqueue_async_action')) {
            return (int) as_enqueue_async_action(self::SEND_HOOK, $args, self::ACTION_GROUP, true) > 0;
        }

        $scheduled = wp_schedule_single_event(time() + 5, self::SEND_HOOK, $args, true);
        return !is_wp_error($scheduled) && (bool) $scheduled;
    }

    public static function run_scheduled(string $schedule_month = ''): void
    {
        try {
            $reference = preg_match('/^\d{4}-\d{2}$/', $schedule_month)
                ? new DateTimeImmutable($schedule_month . '-15 12:00:00', wp_timezone())
                : null;
            $period = self::previous_month_period($reference);
            self::run('scheduled', 0, $period['date_from'], $period['date_to']);
        } finally {
            // The current single action is still running, so schedule the next
            // month with a different YYYY-MM argument.
            $settings = self::get_settings();
            if ($settings['enabled'] === '1') {
                $target = self::calculate_next_run(null, $settings);
                self::schedule_single(
                    $target->getTimestamp(),
                    self::SCHEDULE_HOOK,
                    [$target->format('Y-m')],
                    true
                );
            }
        }
    }

    public static function run(string $mode = 'manual', int $attempt = 0, string $date_from = '', string $date_to = ''): bool
    {
        $mode = in_array($mode, ['scheduled', 'retry', 'manual', 'test'], true) ? $mode : 'manual';
        $settings = self::get_settings();
        if (in_array($mode, ['scheduled', 'retry'], true) && $settings['enabled'] !== '1') {
            self::record_history([
                'status'  => 'skipped',
                'mode'    => $mode,
                'attempt' => $attempt,
                'message' => 'Monthly email delivery is disabled.',
            ]);
            return false;
        }

        if (get_transient(self::LOCK_TRANSIENT)) {
            self::record_history([
                'status'  => 'skipped',
                'mode'    => $mode,
                'attempt' => $attempt,
                'message' => 'Another tax report email is already being generated.',
            ]);
            if (in_array($mode, ['scheduled', 'retry'], true)) {
                self::schedule_retry($attempt + 1, $date_from, $date_to);
            }
            return false;
        }

        set_transient(self::LOCK_TRANSIENT, 1, 30 * MINUTE_IN_SECONDS);
        $attachments = [];
        $package = [];
        $report = [];
        try {
            if (empty($settings['recipients'])) {
                throw new RuntimeException(__('Add at least one valid report email recipient.', 'ffl-funnels-addons'));
            }
            if ($date_from === '' || $date_to === '') {
                $period = self::previous_month_period();
                $date_from = $period['date_from'];
                $date_to = $period['date_to'];
            }

            $filters = [
                'date_from'   => $date_from,
                'date_to'     => $date_to,
                'statuses'    => $settings['statuses'],
                'include_pii' => $settings['include_pii'] === '1',
            ];
            $service = new Tax_Report_Service();
            $report = $service->generate($filters);
            $package = Tax_Report_Exporter::create_package($report);

            $max_bytes = (int) $settings['max_attachment_mb'] * 1024 * 1024;
            $summary_only = (int) ($package['bytes'] ?? 0) > $max_bytes;
            if ($summary_only) {
                Tax_Report_Exporter::cleanup_file((string) ($package['path'] ?? ''));
                $package = Tax_Report_Exporter::create_summary_pdf($report);
            }
            $attachments[] = (string) $package['path'];

            $subject = self::build_subject($report, $mode);
            $message = self::build_message($report, $settings, $package, $summary_only);
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            $sent = wp_mail($settings['recipients'], $subject, $message, $headers, $attachments);
            if (!$sent) {
                throw new RuntimeException(__('WordPress could not hand the message to the configured mail transport.', 'ffl-funnels-addons'));
            }

            self::record_history([
                'status'          => $summary_only ? 'sent_summary_only' : 'sent',
                'mode'            => $mode,
                'attempt'         => $attempt,
                'date_from'       => $date_from,
                'date_to'         => $date_to,
                'report_id'       => (string) ($report['manifest']['report_id'] ?? ''),
                'recipients'      => $settings['recipients'],
                'attachment_name' => (string) ($package['filename'] ?? ''),
                'attachment_bytes'=> (int) ($package['bytes'] ?? 0),
                'message'         => $summary_only
                    ? 'The complete ZIP exceeded the configured limit, so the PDF summary was sent.'
                    : 'The complete tax report package was accepted by the mail transport.',
            ]);
            return true;
        } catch (Throwable $e) {
            $error = sanitize_text_field($e->getMessage());
            self::record_history([
                'status'     => 'failed',
                'mode'       => $mode,
                'attempt'    => $attempt,
                'date_from'  => $date_from,
                'date_to'    => $date_to,
                'report_id'  => (string) ($report['manifest']['report_id'] ?? ''),
                'recipients' => $settings['recipients'],
                'message'    => $error,
            ]);
            self::log_error($error, $mode, $attempt);
            if (in_array($mode, ['scheduled', 'retry'], true)) {
                self::schedule_retry($attempt + 1, $date_from, $date_to);
            }
            return false;
        } finally {
            foreach ($attachments as $attachment) {
                Tax_Report_Exporter::cleanup_file((string) $attachment);
            }
            if (!empty($package['path'])) {
                Tax_Report_Exporter::cleanup_file((string) $package['path']);
            }
            delete_transient(self::LOCK_TRANSIENT);
        }
    }

    public static function get_history(int $limit = 10): array
    {
        $history = get_option(self::HISTORY_OPTION, []);
        return is_array($history) ? array_slice($history, 0, max(1, $limit)) : [];
    }

    private static function build_subject(array $report, string $mode): string
    {
        $filters = (array) ($report['manifest']['filters'] ?? []);
        $prefix = $mode === 'test' ? '[TEST] ' : '';
        return sprintf(
            '%s[%s] WooCommerce Tax Report — %s to %s',
            $prefix,
            wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? '')
        );
    }

    private static function build_message(array $report, array $settings, array $attachment, bool $summary_only): string
    {
        $manifest = (array) ($report['manifest'] ?? []);
        $filters = (array) ($manifest['filters'] ?? []);
        $stats = (array) ($report['stats'] ?? []);
        $message = '<h2>' . esc_html__('Monthly WooCommerce tax report', 'ffl-funnels-addons') . '</h2>';
        $message .= '<p><strong>' . esc_html__('Period:', 'ffl-funnels-addons') . '</strong> '
            . esc_html((string) ($filters['date_from'] ?? '') . ' — ' . (string) ($filters['date_to'] ?? '')) . '<br>';
        $message .= '<strong>' . esc_html__('Orders:', 'ffl-funnels-addons') . '</strong> ' . esc_html((string) ($stats['orders'] ?? 0)) . '<br>';
        $message .= '<strong>' . esc_html__('Refunds:', 'ffl-funnels-addons') . '</strong> ' . esc_html((string) ($stats['refunds'] ?? 0)) . '<br>';
        $message .= '<strong>' . esc_html__('Exceptions:', 'ffl-funnels-addons') . '</strong> ' . esc_html((string) ($stats['exceptions'] ?? 0)) . '<br>';
        $message .= '<strong>' . esc_html__('Report ID:', 'ffl-funnels-addons') . '</strong> ' . esc_html((string) ($manifest['report_id'] ?? '')) . '</p>';

        if ($summary_only) {
            $message .= '<p><strong>' . esc_html__('Attachment notice:', 'ffl-funnels-addons') . '</strong> '
                . esc_html__('The complete ZIP exceeded the configured email size limit. A PDF summary is attached; generate the complete package from Tax Resolver → Tax Reports.', 'ffl-funnels-addons') . '</p>';
        } else {
            $message .= '<p>' . esc_html__('The complete ZIP workpaper is attached, including CSV files, XLSX, PDF, HTML, checksums and the report manifest.', 'ffl-funnels-addons') . '</p>';
        }
        if ($settings['include_pii'] === '1') {
            $message .= '<p><strong>' . esc_html__('Confidential:', 'ffl-funnels-addons') . '</strong> '
                . esc_html__('This attachment includes customer and shipping-address information. Store and forward it securely.', 'ffl-funnels-addons') . '</p>';
        }
        $size = function_exists('size_format') ? size_format((int) ($attachment['bytes'] ?? 0), 2) : (string) ($attachment['bytes'] ?? 0) . ' bytes';
        $message .= '<p><strong>' . esc_html__('Attachment:', 'ffl-funnels-addons') . '</strong> '
            . esc_html((string) ($attachment['filename'] ?? '')) . ' (' . esc_html($size) . ')</p>';
        $message .= '<p><small>' . esc_html__('This report is an accounting workpaper generated from WooCommerce records; it is not a filed tax return.', 'ffl-funnels-addons') . '</small></p>';
        return $message;
    }

    private static function schedule_retry(int $attempt, string $date_from, string $date_to): void
    {
        $delays = [1 => 15 * MINUTE_IN_SECONDS, 2 => HOUR_IN_SECONDS, 3 => 6 * HOUR_IN_SECONDS];
        if (!isset($delays[$attempt])) {
            return;
        }
        self::schedule_single(
            time() + $delays[$attempt],
            self::SEND_HOOK,
            ['retry', $attempt, $date_from, $date_to],
            true
        );
    }

    private static function schedule_single(int $timestamp, string $hook, array $args, bool $unique): bool
    {
        if (self::action_scheduler_ready() && function_exists('as_schedule_single_action')) {
            return (int) as_schedule_single_action($timestamp, $hook, $args, self::ACTION_GROUP, $unique) > 0;
        }
        if ($unique && wp_next_scheduled($hook, $args)) {
            return true;
        }
        $scheduled = wp_schedule_single_event($timestamp, $hook, $args, true);
        return !is_wp_error($scheduled) && (bool) $scheduled;
    }

    private static function has_scheduled_action(string $hook, array $args): bool
    {
        if (self::action_scheduler_ready()) {
            if (function_exists('as_has_scheduled_action')) {
                return (bool) as_has_scheduled_action($hook, $args, self::ACTION_GROUP);
            }
            if (function_exists('as_next_scheduled_action')) {
                return false !== as_next_scheduled_action($hook, $args, self::ACTION_GROUP);
            }
        }
        return false !== wp_next_scheduled($hook, $args);
    }

    private static function action_scheduler_ready(): bool
    {
        if (!function_exists('as_schedule_single_action')) {
            return false;
        }
        return !class_exists('Action_Scheduler') || Action_Scheduler::is_initialized();
    }

    private static function record_history(array $entry): void
    {
        $history = get_option(self::HISTORY_OPTION, []);
        if (!is_array($history)) {
            $history = [];
        }
        $entry['created_at_utc'] = gmdate('c');
        array_unshift($history, $entry);
        update_option(self::HISTORY_OPTION, array_slice($history, 0, 50), false);
    }

    private static function log_error(string $message, string $mode, int $attempt): void
    {
        if (function_exists('ffla_tax_log')) {
            ffla_tax_log('error', 'Tax report email failed', [
                'mode'    => $mode,
                'attempt' => $attempt,
                'message' => $message,
            ]);
        }
    }
}
