<?php
defined('ABSPATH') || exit;

/** Bounded, per-order notifications. Never scans orders or charges a customer. */
class FFLA_Customer_Operations_Messages
{
    public static function boot(): void
    {
        add_action('ffla_ops_ready_mail', [__CLASS__, 'ready_job'], 10, 3);
        add_action('ffla_ops_case_mail', [__CLASS__, 'case_job'], 10, 3);
    }

    private static function schedule(string $hook, array $args, int $at): void
    {
        if (!wp_next_scheduled($hook, $args)) { wp_schedule_single_event(max(time() + 5, $at), $hook, $args); }
    }

    public static function queue_ready($order, string $cycle): void
    {
        if (FFLA_Customer_Operations_Settings::enabled('auto_ready')) {
            self::schedule('ffla_ops_ready_mail', [$order->get_id(), $cycle, 0], time() + 10);
        }
    }

    public static function queue_case(int $id, array $case): void
    {
        if (FFLA_Customer_Operations_Settings::enabled('staff_reminders') && $case['assignee']) {
            self::schedule('ffla_ops_case_mail', [$id, $case['due'], $case['assignee']], $case['due']);
        }
    }

    public static function template($order, string $kind): array
    {
        $s = FFLA_Customer_Operations_Settings::get();
        $data = $order ? FFLA_Customer_Operations::data($order) : [];
        $location = $data['pickup_location'] ?? $s;
        $vars = ['{order_number}'=>$order ? $order->get_order_number() : 'PREVIEW',
            '{order_url}'=>$order && $order->get_customer_id() ? $order->get_view_order_url() : wc_get_page_permalink('myaccount')];
        foreach (['store_name','store_address','store_hours','store_instructions'] as $key) { $vars['{' . $key . '}'] = $location[$key] ?? ''; }
        $subject = strtr($s[$kind . '_subject'] ?? '', $vars);
        $body = strtr(str_replace('\\n', "\n", $s[$kind . '_body'] ?? ''), $vars);
        return [sanitize_text_field($subject), $body];
    }

    /** Caller holds the order lease. Persist intent BEFORE wp_mail; uncertain sends are never retried automatically. */
    public static function send($order, string $key, string $to, string $subject, string $body): string
    {
        if (!FFLA_Customer_Operations_Settings::enabled('notifications')) { throw new RuntimeException('Email communications are disabled.'); }
        if (!is_email($to)) { throw new RuntimeException('No valid recipient email is available.'); }
        $log = $order->get_meta('_ffla_ops_mail', true); $log = is_array($log) ? $log : [];
        if (isset($log[$key])) { return 'Already attempted: ' . $log[$key]['status'] . '. Review the mail log before a manual resend.'; }
        if (count($log) >= 250) { throw new RuntimeException('The per-order message limit was reached. Use the store support channel.'); }
        $log[$key] = ['at'=>time(), 'status'=>'sending', 'subject'=>sanitize_text_field($subject)];
        $order->update_meta_data('_ffla_ops_mail', $log); $order->save_meta_data();
        $error = '';
        $capture = static function ($e) use (&$error) { $error = sanitize_text_field($e->get_error_code()); };
        add_action('wp_mail_failed', $capture);
        try {
            $ok = wp_mail($to, sanitize_text_field($subject), $body, ['Content-Type: text/plain; charset=UTF-8']);
            $log[$key]['status'] = $ok ? 'accepted' : 'failed';
            $log[$key]['error'] = $ok ? '' : ($error ?: 'wp_mail_failed');
        } catch (Throwable $e) {
            // A transport exception can happen after delivery: do not claim safe automatic retry.
            $log[$key]['status'] = 'uncertain'; $log[$key]['error'] = 'transport_exception';
        } finally { remove_action('wp_mail_failed', $capture); }
        $order->update_meta_data('_ffla_ops_mail', $log); $order->save_meta_data();
        FFLA_Customer_Operations::audit($order, 'Email ' . $key . ': ' . $log[$key]['status'] . '. Acceptance does not confirm delivery.');
        return 'Email ' . $log[$key]['status'] . '. Acceptance does not confirm delivery.';
    }

    public static function ready_job($id, $cycle, $number): void
    {
        try {
            FFLA_Customer_Operations::locked((int) $id, static function ($order) use ($cycle, $number) {
                if (!$order || !FFLA_Customer_Operations_Settings::enabled('auto_ready')) { return; }
                $data = FFLA_Customer_Operations::data($order); $s = FFLA_Customer_Operations_Settings::get(); $number = (int) $number;
                if (!$cycle || $data['ready_cycle'] !== $cycle || $order->get_status() !== FFLA_Customer_Operations::STATUS
                    || FFLA_Customer_Operations::ready_error($order) !== '' || $number < 0 || $number > (int) $s['reminder_max']) { return; }
                if ($number && !FFLA_Customer_Operations_Settings::enabled('pickup_reminders')) { return; }
                [$subject,$body] = self::template($order, $number ? 'reminder' : 'ready');
                self::send($order, 'ready:' . $cycle . ':' . $number, $order->get_billing_email(), $subject, $body);
                if (FFLA_Customer_Operations_Settings::enabled('pickup_reminders') && $number < (int) $s['reminder_max']) {
                    self::schedule('ffla_ops_ready_mail', [$order->get_id(), $cycle, $number + 1], time() + (int) $s['reminder_days'] * DAY_IN_SECONDS);
                }
            });
        } catch (Throwable $e) { self::log_job_error((int) $id, 'pickup'); }
    }

    public static function case_job($id, $due, $assignee): void
    {
        try {
            FFLA_Customer_Operations::locked((int) $id, static function ($order) use ($due, $assignee) {
                if (!$order || !FFLA_Customer_Operations_Settings::enabled('staff_reminders')) { return; }
                $case = FFLA_Customer_Operations::case_data($order);
                if (in_array($case['state'], ['', 'resolved'], true) || $case['due'] !== (int) $due || $case['assignee'] !== (int) $assignee) { return; }
                $user = get_user_by('id', $assignee);
                if (!$user || !user_can($user, 'manage_woocommerce') || !user_can($user, 'edit_shop_order', $order->get_id())) { return; }
                self::send($order, 'case:' . $due . ':' . $assignee, $user->user_email, 'Order #' . $order->get_order_number() . ' follow-up due',
                    'Please review the assigned case in WooCommerce: ' . $order->get_edit_order_url());
            });
        } catch (Throwable $e) { self::log_job_error((int) $id, 'follow-up'); }
    }

    private static function log_job_error(int $id, string $kind): void
    {
        if (function_exists('wc_get_logger')) { wc_get_logger()->warning('Order #' . $id . ': ' . $kind . ' notification could not run. Review order / mail logs; no automatic retry.', ['source'=>'ffla-order-management']); }
    }
}
