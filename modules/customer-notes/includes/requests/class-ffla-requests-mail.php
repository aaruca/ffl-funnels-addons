<?php
/**
 * Customer request emails.
 *
 * Customer emails (when "Customer emails" is on): request received, staff
 * replies, return approved (with return instructions), other status updates
 * staff choose to announce, closure with the resolution, and a fresh tracking
 * link. Staff emails: new request, customer reply, assignment.
 *
 * Uses WooCommerce's email template when available so messages match the
 * store's other emails. Every attempt is logged on the request's private
 * timeline; acceptance by the mail transport is not proof of delivery.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Mail
{
    public static function boot(): void
    {
        add_action('ffla_request_created', [__CLASS__, 'on_created'], 10, 2);
        add_action('ffla_request_assigned', [__CLASS__, 'staff_assigned'], 10, 2);
        add_action('ffla_request_rated', [__CLASS__, 'staff_low_rating'], 10, 3);
    }

    public static function customer_enabled(): bool
    {
        return FFLA_Customer_Operations_Settings::enabled('requests_emails');
    }

    public static function on_created($request, $order): void
    {
        if ('staff' === $request->source) {
            return; // Staff decide whether to notify when they open it.
        }
        self::customer_received($request);
        self::staff_new($request);
    }

    /* ── Customer ──────────────────────────────────────────────────────── */

    public static function customer_received($request): void
    {
        if (!self::customer_enabled()) {
            return;
        }
        $body = '<p>' . esc_html(sprintf(
            /* translators: 1: customer first name or empty, 2: request number */
            __('Hi%1$s, we received your request %2$s and will get back to you soon.', 'ffl-funnels-addons'),
            self::first_name($request),
            $request->number
        )) . '</p>' . self::summary($request);

        if ($request->has_firearm) {
            $body .= self::notice((string) FFLA_Requests::setting('requests_firearm_notice'), $request);
        }

        self::send_customer($request,
            /* translators: %s: request number */
            sprintf(__('We received your request %s', 'ffl-funnels-addons'), $request->number),
            __('Request received', 'ffl-funnels-addons'),
            $body,
            'received'
        );
    }

    public static function customer_message($request, string $text): void
    {
        if (!self::customer_enabled()) {
            return;
        }
        $body = '<p>' . esc_html(sprintf(
            /* translators: %s: request number */
            __('There is a new reply on your request %s:', 'ffl-funnels-addons'),
            $request->number
        )) . '</p>' . self::quote($text) . self::summary($request);

        self::send_customer($request,
            /* translators: %s: request number */
            sprintf(__('New reply on your request %s', 'ffl-funnels-addons'), $request->number),
            __('New reply', 'ffl-funnels-addons'),
            $body,
            'reply'
        );
    }

    /**
     * Status update email. For an approved return the return instructions
     * are included.
     */
    public static function customer_status($request, string $note = ''): void
    {
        if (!self::customer_enabled()) {
            return;
        }
        $status = FFLA_Requests::status_label($request->status, 'customer');
        $body = '<p>' . esc_html(sprintf(
            /* translators: 1: request number, 2: status */
            __('Your request %1$s is now: %2$s.', 'ffl-funnels-addons'),
            $request->number,
            $status
        )) . '</p>';
        if ('' !== trim($note)) {
            $body .= self::quote($note);
        }
        if ('approved' === $request->status) {
            $body .= self::notice((string) FFLA_Requests::setting('requests_return_instructions'), $request);
            if ($request->has_firearm) {
                $body .= self::notice((string) FFLA_Requests::setting('requests_firearm_notice'), $request);
            }
        }
        $body .= self::summary($request);

        self::send_customer($request,
            /* translators: 1: request number, 2: status */
            sprintf(__('Request %1$s: %2$s', 'ffl-funnels-addons'), $request->number, $status),
            $status,
            $body,
            'status:' . $request->status
        );
    }

    public static function customer_closed($request): void
    {
        if (!self::customer_enabled()) {
            return;
        }
        $outcome = FFLA_Requests::resolutions()[$request->resolution] ?? $request->resolution;
        $body = '<p>' . esc_html(sprintf(
            /* translators: 1: request number, 2: outcome */
            __('Your request %1$s has been closed. Outcome: %2$s.', 'ffl-funnels-addons'),
            $request->number,
            $outcome
        )) . '</p>';
        if ('' !== trim((string) $request->resolution_note)) {
            $body .= self::quote((string) $request->resolution_note);
        }
        $body .= '<p>' . esc_html(sprintf(
            /* translators: %d: days */
            __('If something is still not right, reply from your request page within %d days and we will reopen it.', 'ffl-funnels-addons'),
            FFLA_Requests::REOPEN_DAYS
        )) . '</p>';
        if (FFLA_Customer_Operations_Settings::enabled('requests_ratings') && 'withdrawn' !== $request->resolution) {
            $body .= '<p>' . esc_html__('How did we do? You can rate our help from your request page — it takes a few seconds.', 'ffl-funnels-addons') . '</p>';
        }
        $body .= self::summary($request);

        self::send_customer($request,
            /* translators: %s: request number */
            sprintf(__('Your request %s is closed', 'ffl-funnels-addons'), $request->number),
            __('Request closed', 'ffl-funnels-addons'),
            $body,
            'closed'
        );
    }

    /** Send the (new) tracking link, e.g. when the customer lost it. */
    public static function customer_link($request): bool
    {
        if (!self::customer_enabled()) {
            return false;
        }
        $body = '<p>' . esc_html(sprintf(
            /* translators: %s: request number */
            __('Here is the link to follow your request %s. Earlier links no longer work.', 'ffl-funnels-addons'),
            $request->number
        )) . '</p>' . self::summary($request);

        return self::send_customer($request,
            /* translators: %s: request number */
            sprintf(__('Your link for request %s', 'ffl-funnels-addons'), $request->number),
            __('Your request link', 'ffl-funnels-addons'),
            $body,
            'link'
        );
    }

    /** One reminder when we have been waiting on the customer. */
    public static function customer_reminder($request): bool
    {
        if (!self::customer_enabled()) {
            return false;
        }
        $last = '';
        foreach (array_reverse(FFLA_Requests::events((int) $request->id, true)) as $event) {
            if ('staff' === $event->actor_type && '' !== trim((string) $event->body)) {
                $last = (string) $event->body;
                break;
            }
        }
        $body = '<p>' . esc_html(sprintf(
            /* translators: 1: first name, 2: request number */
            __('Hi%1$s, we are waiting for your reply on request %2$s so we can keep going.', 'ffl-funnels-addons'),
            self::first_name($request),
            $request->number
        )) . '</p>' . self::quote($last) . self::summary($request);

        return self::send_customer($request,
            /* translators: %s: request number */
            sprintf(__('Reminder: we need your reply on request %s', 'ffl-funnels-addons'), $request->number),
            __('We need your reply', 'ffl-funnels-addons'),
            $body,
            'reminder'
        );
    }

    /* ── Staff ─────────────────────────────────────────────────────────── */

    public static function staff_tracking($request): void
    {
        $link = FFLA_Requests::tracking_link((string) $request->return_carrier, (string) $request->return_tracking);
        $body = '<p>' . esc_html(sprintf(
            /* translators: 1: request number, 2: carrier, 3: tracking number */
            __('The customer shipped the return for request %1$s: %2$s %3$s.', 'ffl-funnels-addons'),
            $request->number,
            FFLA_Requests::carriers()[$request->return_carrier] ?? $request->return_carrier,
            $request->return_tracking
        )) . '</p>' . ($link ? '<p><a href="' . esc_url($link) . '">' . esc_html__('Track the package', 'ffl-funnels-addons') . '</a></p>' : '')
            . '<p><a href="' . esc_url(self::admin_request_url($request)) . '">' . esc_html__('Open the request', 'ffl-funnels-addons') . '</a></p>';

        self::send_staff($request, self::staff_recipients($request),
            /* translators: %s: request number */
            sprintf(__('Return shipped for request %s', 'ffl-funnels-addons'), $request->number),
            __('Return on its way', 'ffl-funnels-addons'),
            $body,
            'staff_tracking'
        );
    }

    public static function staff_low_rating($request, int $rating, string $comment): void
    {
        if ($rating > 2) {
            return;
        }
        $body = '<p>' . esc_html(sprintf(
            /* translators: 1: request number, 2: rating */
            __('The customer rated request %1$s %2$d out of 5.', 'ffl-funnels-addons'),
            $request->number,
            $rating
        )) . '</p>' . self::quote($comment)
            . '<p><a href="' . esc_url(self::admin_request_url($request)) . '">' . esc_html__('Open the request', 'ffl-funnels-addons') . '</a></p>';

        self::send_staff($request, self::staff_recipients($request),
            /* translators: %s: request number */
            sprintf(__('Low rating on request %s', 'ffl-funnels-addons'), $request->number),
            __('Low customer rating', 'ffl-funnels-addons'),
            $body,
            'staff_low_rating'
        );
    }

    /**
     * Daily list of overdue requests and requests waiting for staff.
     *
     * @param array $overdue  Request rows.
     * @param array $awaiting Request rows.
     */
    public static function staff_digest(array $overdue, array $awaiting): bool
    {
        $to = self::staff_recipients((object) ['assignee' => 0]);
        if (!$to || (!$overdue && !$awaiting)) {
            return false;
        }
        $table = static function (array $rows, string $title): string {
            if (!$rows) {
                return '';
            }
            $html = '<h3>' . esc_html($title) . ' (' . count($rows) . ')</h3><table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse;">';
            foreach ($rows as $r) {
                $html .= '<tr><td style="border:1px solid #e5e5e5;"><a href="' . esc_url(self::admin_request_url($r)) . '">' . esc_html($r->number) . '</a></td>'
                    . '<td style="border:1px solid #e5e5e5;">' . esc_html(FFLA_Requests::type_label($r->type) . ' · ' . FFLA_Requests::status_label($r->status)) . '</td>'
                    . '<td style="border:1px solid #e5e5e5;">' . esc_html($r->customer_name) . '</td>'
                    . '<td style="border:1px solid #e5e5e5;">' . esc_html($r->due_at ? FFLA_Requests::local_time($r->due_at, get_option('date_format')) : FFLA_Requests::local_time($r->updated_at, get_option('date_format'))) . '</td></tr>';
            }
            return $html . '</table>';
        };
        $body = $table($overdue, __('Overdue', 'ffl-funnels-addons')) . $table($awaiting, __('Waiting for a staff reply', 'ffl-funnels-addons'))
            . '<p><a href="' . esc_url(admin_url('admin.php?page=ffla-requests&view=awaiting')) . '">' . esc_html__('Open the requests inbox', 'ffl-funnels-addons') . '</a></p>';

        $subject = sprintf(
            /* translators: 1: overdue count, 2: waiting count */
            __('Requests digest: %1$d overdue, %2$d waiting for a reply', 'ffl-funnels-addons'),
            count($overdue),
            count($awaiting)
        );
        try {
            if (function_exists('WC') && WC() && method_exists(WC(), 'mailer')) {
                $mailer = WC()->mailer();
                return (bool) $mailer->send(implode(',', $to), $subject, $mailer->wrap_message(__('Customer requests', 'ffl-funnels-addons'), $body));
            }
            return (bool) wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function staff_new($request): void
    {
        $order_url = self::admin_request_url($request);
        $events = FFLA_Requests::events((int) $request->id, true);
        $first = $events ? (string) $events[0]->body : '';

        $body = '<p>' . esc_html(sprintf(
            /* translators: 1: type, 2: request number, 3: order number */
            __('New %1$s request %2$s on order #%3$s.', 'ffl-funnels-addons'),
            strtolower(FFLA_Requests::type_label($request->type)),
            $request->number,
            $request->order_number
        )) . '</p>' . self::staff_details($request) . self::quote($first)
            . '<p><a href="' . esc_url($order_url) . '">' . esc_html__('Open the request', 'ffl-funnels-addons') . '</a></p>';

        self::send_staff($request, self::staff_recipients($request),
            /* translators: 1: type, 2: request number */
            sprintf(__('New %1$s request %2$s', 'ffl-funnels-addons'), strtolower(FFLA_Requests::type_label($request->type)), $request->number),
            __('New customer request', 'ffl-funnels-addons'),
            $body,
            'staff_new'
        );
    }

    public static function staff_reply($request, string $text): void
    {
        $body = '<p>' . esc_html(sprintf(
            /* translators: %s: request number */
            __('The customer replied on request %s:', 'ffl-funnels-addons'),
            $request->number
        )) . '</p>' . self::quote($text)
            . '<p><a href="' . esc_url(self::admin_request_url($request)) . '">' . esc_html__('Open the request', 'ffl-funnels-addons') . '</a></p>';

        self::send_staff($request, self::staff_recipients($request),
            /* translators: %s: request number */
            sprintf(__('Customer replied on request %s', 'ffl-funnels-addons'), $request->number),
            __('Customer reply', 'ffl-funnels-addons'),
            $body,
            'staff_reply'
        );
    }

    public static function staff_assigned($request, int $user_id): void
    {
        if ($user_id === get_current_user_id()) {
            return; // Assigning yourself needs no email.
        }
        $user = get_user_by('id', $user_id);
        if (!$user || !is_email($user->user_email)) {
            return;
        }
        $body = '<p>' . esc_html(sprintf(
            /* translators: %s: request number */
            __('Request %s was assigned to you.', 'ffl-funnels-addons'),
            $request->number
        )) . '</p>' . self::staff_details($request)
            . '<p><a href="' . esc_url(self::admin_request_url($request)) . '">' . esc_html__('Open the request', 'ffl-funnels-addons') . '</a></p>';

        self::send_staff($request, [$user->user_email],
            /* translators: %s: request number */
            sprintf(__('Request %s assigned to you', 'ffl-funnels-addons'), $request->number),
            __('Request assigned', 'ffl-funnels-addons'),
            $body,
            'staff_assigned'
        );
    }

    /**
     * Assignee if any, otherwise the configured staff list, otherwise the
     * site admin email.
     *
     * @return string[]
     */
    public static function staff_recipients($request): array
    {
        $emails = [];
        if ((int) $request->assignee) {
            $user = get_user_by('id', (int) $request->assignee);
            if ($user && is_email($user->user_email)) {
                $emails[] = $user->user_email;
            }
        }
        if (!$emails) {
            foreach (preg_split('/[\s,;]+/', (string) FFLA_Requests::setting('requests_staff_emails')) as $email) {
                if (is_email($email)) {
                    $emails[] = $email;
                }
            }
        }
        if (!$emails && is_email(get_option('admin_email'))) {
            $emails[] = get_option('admin_email');
        }
        return array_values(array_unique($emails));
    }

    /* ── Rendering ─────────────────────────────────────────────────────── */

    private static function first_name($request): string
    {
        $parts = explode(' ', trim((string) $request->customer_name));
        return '' !== $parts[0] ? ' ' . $parts[0] : '';
    }

    private static function quote(string $text): string
    {
        return '' === trim($text) ? '' : '<blockquote style="margin:16px 0;padding:12px 16px;border-left:4px solid #ccc;background:#f8f8f8;">' . nl2br(esc_html($text)) . '</blockquote>';
    }

    /** A highlighted block of store-written instructions with placeholders filled. */
    private static function notice(string $template, $request): string
    {
        $text = self::fill($template, $request);
        return '' === trim($text) ? '' : '<div style="margin:16px 0;padding:12px 16px;border:1px solid #e5e5e5;border-radius:4px;">' . nl2br(esc_html($text)) . '</div>';
    }

    public static function fill(string $template, $request): string
    {
        $template = str_replace('\\n', "\n", $template);
        return strtr($template, [
            '{request_number}' => $request->number,
            '{order_number}'   => $request->order_number,
            '{customer_name}'  => $request->customer_name,
            '{store_name}'     => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
        ]);
    }

    private static function summary($request): string
    {
        $rows = [
            __('Request', 'ffl-funnels-addons') => $request->number,
            __('Order', 'ffl-funnels-addons')   => '#' . $request->order_number,
            __('Type', 'ffl-funnels-addons')    => FFLA_Requests::type_label($request->type),
            __('Status', 'ffl-funnels-addons')  => FFLA_Requests::status_label($request->status, 'customer'),
        ];
        $html = '<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5;border-collapse:collapse;margin:16px 0;">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th style="text-align:left;border:1px solid #e5e5e5;">' . esc_html($label) . '</th><td style="border:1px solid #e5e5e5;">' . esc_html($value) . '</td></tr>';
        }
        foreach (FFLA_Requests::items($request) as $line) {
            $html .= '<tr><th style="text-align:left;border:1px solid #e5e5e5;">' . esc_html__('Item', 'ffl-funnels-addons') . '</th><td style="border:1px solid #e5e5e5;">' . esc_html($line['name'] . ' × ' . $line['qty']) . '</td></tr>';
        }
        $html .= '</table>';

        $url = FFLA_Requests::tracking_url($request);
        $html .= '<p><a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 18px;background:#2271b1;color:#fff;text-decoration:none;border-radius:4px;">'
            . esc_html__('View your request', 'ffl-funnels-addons') . '</a></p>'
            . '<p style="font-size:12px;color:#666;">' . esc_html__('Keep this email: the link above lets you check the status and reply without signing in.', 'ffl-funnels-addons') . '</p>';

        return $html;
    }

    private static function staff_details($request): string
    {
        $reasons = FFLA_Requests::reasons($request->type);
        $rows = [
            __('Customer', 'ffl-funnels-addons') => trim($request->customer_name . ' <' . $request->customer_email . '>'),
            __('Reason', 'ffl-funnels-addons')   => $reasons[$request->reason] ?? $request->reason,
        ];
        if ($request->preferred) {
            $rows[__('Prefers', 'ffl-funnels-addons')] = FFLA_Requests::preferences()[$request->preferred] ?? $request->preferred;
        }
        foreach (FFLA_Requests::items($request) as $line) {
            $rows[] = $line['name'] . ' × ' . $line['qty'] . (!empty($line['firearm']) ? ' — ' . __('FIREARM: return through an FFL', 'ffl-funnels-addons') : '')
                . (!empty($line['fee']) ? ' — ' . sprintf(
                    /* translators: %s: percent */
                    __('restocking fee %s%%', 'ffl-funnels-addons'),
                    wc_format_decimal($line['fee'], 2, true)
                ) : '')
                . (!empty($line['exception']) ? ' — ' . __('outside return rules', 'ffl-funnels-addons') : '');
        }
        $ffl = FFLA_Requests::ffl($request);
        if ($ffl) {
            $rows[__('FFL dealer', 'ffl-funnels-addons')] = $ffl['name'] . ' · ' . FFLA_Requests::format_license($ffl['license']) . ' · ' . trim($ffl['city'] . ', ' . $ffl['state'], ', ');
        }
        $html = '<ul>';
        foreach ($rows as $label => $value) {
            $html .= '<li>' . (is_int($label) ? '' : '<strong>' . esc_html($label) . ':</strong> ') . esc_html($value) . '</li>';
        }
        return $html . '</ul>';
    }

    public static function admin_request_url($request): string
    {
        return admin_url('admin.php?page=ffla-requests&request=' . (int) $request->id);
    }

    /* ── Transport ─────────────────────────────────────────────────────── */

    private static function send_customer($request, string $subject, string $heading, string $body, string $label): bool
    {
        if (!is_email($request->customer_email)) {
            return false;
        }
        $reply_to = class_exists('FFLA_Requests_Inbound') ? FFLA_Requests_Inbound::reply_to($request) : '';
        if ('' !== $reply_to) {
            $body .= '<p style="color:#646970;font-size:13px;">' . esc_html__('You can reply to this email to answer us — attach photos if they help.', 'ffl-funnels-addons') . '</p>';
        }
        return self::send($request, [$request->customer_email], $subject, $heading, $body, 'customer:' . $label, $reply_to);
    }

    private static function send_staff($request, array $to, string $subject, string $heading, string $body, string $label): bool
    {
        return $to ? self::send($request, $to, $subject, $heading, $body, $label) : false;
    }

    private static function send($request, array $to, string $subject, string $heading, string $body, string $label, string $reply_to = ''): bool
    {
        $ok = false;
        try {
            $headers = ['Content-Type: text/html; charset=UTF-8'];
            if ('' !== $reply_to && is_email($reply_to)) {
                $headers[] = 'Reply-To: ' . $reply_to;
            }
            if (function_exists('WC') && WC() && method_exists(WC(), 'mailer')) {
                $mailer = WC()->mailer();
                $ok = (bool) $mailer->send(implode(',', $to), $subject, $mailer->wrap_message($heading, $body), implode("\r\n", $headers) . "\r\n");
            } else {
                $ok = (bool) wp_mail($to, $subject, '<h2>' . esc_html($heading) . '</h2>' . $body, $headers);
            }
            $status = $ok ? 'accepted' : 'failed';
        } catch (Throwable $e) {
            $status = 'uncertain';
        }

        FFLA_Requests::add_event($request, 'email', ['type' => 'system', 'id' => 0], false,
            /* translators: 1: subject, 2: status */
            sprintf(__('Email "%1$s": %2$s', 'ffl-funnels-addons'), $subject, $status),
            ['label' => $label, 'status' => $status, 'recipients' => count($to)]
        );

        return $ok;
    }
}
