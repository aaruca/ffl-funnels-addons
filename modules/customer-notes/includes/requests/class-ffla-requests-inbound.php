<?php
/**
 * Customer requests — reply by email.
 *
 * With **Reply by email** on and a **Reply address** set, every email sent to
 * a customer about a request carries a Reply-To such as
 * `requests+802-1-3f9a0c1b2d@reply.store.com`: the request number plus a short
 * signature only this site can make, so nobody can post into a request by
 * guessing addresses. An inbound email service (Postmark, Mailgun or SendGrid
 * Inbound Parse) receives the reply and posts it to this site's private
 * webhook URL; the answer lands on the request as the customer's reply —
 * quoted text and signatures removed, photos, PDFs and videos attached — and
 * staff are notified as usual. A reply from a different address than the
 * request's customer is kept as an internal note for staff to check.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Inbound
{
    const SECRET = 'ffla_requests_inbound_secret';
    const LOG = 'ffla_requests_inbound_log';

    public static function boot(): void
    {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_post_ffla_req_inbound_secret', [__CLASS__, 'new_secret']);
    }

    public static function enabled(): bool
    {
        return FFLA_Customer_Operations_Settings::enabled('requests_inbound') && '' !== self::address();
    }

    /** The configured reply address, or ''. */
    public static function address(): string
    {
        $address = strtolower(trim((string) FFLA_Requests::setting('requests_inbound_address')));
        return is_email($address) && false === strpos(strstr($address, '@', true), '+') ? $address : '';
    }

    public static function secret(bool $create = false): string
    {
        $secret = (string) get_option(self::SECRET, '');
        if (!preg_match('/^[a-f0-9]{32}$/', $secret) && $create) {
            $secret = FFLA_Requests::random_hex(16);
            update_option(self::SECRET, $secret, false);
        }
        return preg_match('/^[a-f0-9]{32}$/', $secret) ? $secret : '';
    }

    public static function endpoint(): string
    {
        return rest_url('ffla/v1/requests/inbound/' . self::secret(true));
    }

    private static function sig(string $number): string
    {
        return substr(hash_hmac('sha256', 'reply|' . strtolower($number), wp_salt('auth')), 0, 10);
    }

    /** Reply-To for emails about this request ('' when off or the number cannot go in an address). */
    public static function reply_to($request): string
    {
        $address = self::enabled() ? self::address() : '';
        $number = strtolower((string) ($request->number ?? ''));
        if ('' === $address || !preg_match('/^[a-z0-9][a-z0-9._-]{0,50}$/', $number)) {
            return '';
        }
        [$local, $domain] = explode('@', $address, 2);
        return $local . '+' . $number . '-' . self::sig($number) . '@' . $domain;
    }

    /** The request a reply was addressed to, from any of its recipient addresses. */
    public static function match(array $recipients)
    {
        $address = self::address();
        if ('' === $address) {
            return null;
        }
        [$local, $domain] = explode('@', $address, 2);
        foreach ($recipients as $recipient) {
            $recipient = strtolower(trim((string) $recipient));
            $pattern = '/^' . preg_quote($local, '/') . '\+([a-z0-9._-]+)-([a-f0-9]{10})@' . preg_quote($domain, '/') . '$/';
            // Postmark's own inbound addresses (hash@inbound.postmarkapp.com) may be forwarded to; match the tag whatever the domain then.
            if (!preg_match($pattern, $recipient, $m) && !preg_match('/^' . preg_quote($local, '/') . '\+([a-z0-9._-]+)-([a-f0-9]{10})@/', $recipient, $m)) {
                continue;
            }
            if (!hash_equals(self::sig($m[1]), $m[2])) {
                continue;
            }
            $request = FFLA_Requests::get_by_number($m[1]);
            if ($request && strtolower($request->number) === $m[1]) {
                return $request;
            }
        }
        return null;
    }

    public static function routes(): void
    {
        register_rest_route('ffla/v1', '/requests/inbound/(?P<secret>[a-f0-9]{32})', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'receive'],
            'permission_callback' => '__return_true', // The secret in the URL is the credential; checked in receive().
        ]);
    }

    /**
     * Webhook from the inbound email service. Always answers 200 for a valid
     * secret (so the service does not retry a reply we decided to skip) and
     * records what happened in the setup panel's log.
     */
    public static function receive(WP_REST_Request $req)
    {
        $secret = self::secret();
        if ('' === $secret || !hash_equals($secret, (string) $req['secret'])) {
            return new WP_REST_Response(['ok' => false], 403);
        }
        if (!FFLA_Requests::enabled() || !self::enabled()) {
            self::log('', '', 'off');
            return new WP_REST_Response(['ok' => false, 'reason' => 'off'], 200);
        }

        $mail = self::parse($req);
        $request = self::match($mail['to']);
        if (!$request) {
            self::cleanup($mail);
            self::log($mail['from'], '', 'no_request');
            return new WP_REST_Response(['ok' => false, 'reason' => 'no_request'], 200);
        }
        $key = 'ffla_req_inbound_' . (int) $request->id;
        $count = (int) get_transient($key);
        if ($count >= 20) {
            self::cleanup($mail);
            self::log($mail['from'], $request->number, 'rate_limited');
            return new WP_REST_Response(['ok' => false, 'reason' => 'rate_limited'], 200);
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);

        $result = self::apply($request, $mail);
        self::cleanup($mail);
        self::log($mail['from'], $request->number, $result);
        return new WP_REST_Response(['ok' => 'added' === $result || 'internal' === $result, 'result' => $result], 200);
    }

    /** Add the reply to the request. Returns added / internal / closed / empty. */
    private static function apply($request, array $mail): string
    {
        $from_customer = '' !== $mail['from'] && strtolower($mail['from']) === strtolower((string) $request->customer_email);
        $text = self::strip_quoted($mail['text']);
        $customer = ['type' => 'customer', 'id' => (int) $request->customer_id];
        $staff_view = ['type' => 'system', 'id' => 0];

        // Attachments, one by one: a file we cannot accept never blocks the message.
        $prepared = [];
        $skipped = [];
        $existing = FFLA_Requests_Files::count((int) $request->id);
        $videos = FFLA_Requests_Files::count_videos((int) $request->id);
        foreach ($mail['files'] as $file) {
            if (preg_match('/\.(png|jpe?g|gif)$/i', $file['name']) && (int) $file['size'] < 15 * 1024) {
                continue; // Signature logos and icons.
            }
            try {
                $one = FFLA_Requests_Files::prepare([$file], $existing + count($prepared), $videos, !empty($file['uploaded']));
                if (!empty($one[0]['video'])) {
                    $videos++;
                }
                $prepared[] = $one[0];
            } catch (InvalidArgumentException $e) {
                $skipped[] = $file['name'];
            }
        }

        if ('' === $text && !$prepared) {
            return 'empty';
        }
        if ('' === $text) {
            $text = __('(Sent files by email.)', 'ffl-funnels-addons');
        }

        if (!$from_customer) {
            $note = sprintf(
                /* translators: 1: sender address, 2: message */
                __("Email reply from %1\$s, which is not the customer's address on this request. Check it before acting on it.\n\n%2\$s", 'ffl-funnels-addons'),
                '' !== $mail['from'] ? $mail['from'] : __('an unknown sender', 'ffl-funnels-addons'),
                $text
            );
            $event_id = FFLA_Requests::add_event($request, 'note', $staff_view, false, FFLA_Requests::clean_text($note), ['via' => 'email']);
            if ($prepared) {
                FFLA_Requests_Files::store($request, $prepared, $staff_view, false, $event_id);
            }
            FFLA_Requests_Mail::staff_reply(FFLA_Requests::get((int) $request->id), $note);
            return 'internal';
        }

        try {
            $event_id = FFLA_Requests::add_message($request, $customer, $text, true);
        } catch (InvalidArgumentException $e) {
            // Closed too long ago to reopen: keep it for staff rather than lose it.
            FFLA_Requests::add_event($request, 'note', $staff_view, false, FFLA_Requests::clean_text(sprintf(
                /* translators: %s: message */
                __("The customer replied by email after this request was closed:\n\n%s", 'ffl-funnels-addons'),
                $text
            )), ['via' => 'email']);
            return 'closed';
        }
        if ($prepared) {
            FFLA_Requests_Files::store($request, $prepared, $customer, true, $event_id);
        }
        if ($skipped) {
            FFLA_Requests::add_event($request, 'note', $staff_view, false, sprintf(
                /* translators: %s: file names */
                __('Attachments from the email reply that could not be added (type or size not accepted): %s', 'ffl-funnels-addons'),
                implode(', ', array_slice($skipped, 0, 20))
            ), ['via' => 'email']);
        }
        $request = FFLA_Requests::get((int) $request->id);
        FFLA_Requests_Mail::staff_reply($request, $text);
        do_action('ffla_request_customer_replied', $request, $text, 'email');
        return 'added';
    }

    /**
     * Normalize Postmark (JSON), Mailgun and SendGrid (multipart) posts.
     *
     * @return array{from:string, to:string[], text:string, files:array}
     */
    public static function parse(WP_REST_Request $req): array
    {
        $json = $req->get_json_params();
        $mail = ['from' => '', 'to' => [], 'text' => '', 'files' => []];
        $emails = static function ($value): array {
            preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value, $m);
            return array_map('strtolower', $m[0]);
        };

        if (is_array($json) && (isset($json['FromFull']) || isset($json['TextBody']) || isset($json['HtmlBody']))) {
            // Postmark.
            $mail['from'] = strtolower((string) ($json['FromFull']['Email'] ?? ($emails($json['From'] ?? '')[0] ?? '')));
            foreach (['ToFull', 'CcFull', 'BccFull'] as $list) {
                foreach ((array) ($json[$list] ?? []) as $to) {
                    if (is_array($to) && !empty($to['Email'])) {
                        $mail['to'][] = strtolower((string) $to['Email']);
                    }
                }
            }
            $mail['to'] = array_merge($mail['to'], $emails($json['OriginalRecipient'] ?? ''), $emails($json['To'] ?? ''));
            $mail['text'] = '' !== trim((string) ($json['StrippedTextReply'] ?? '')) ? (string) $json['StrippedTextReply']
                : ('' !== trim((string) ($json['TextBody'] ?? '')) ? (string) $json['TextBody'] : self::html_to_text((string) ($json['HtmlBody'] ?? '')));
            foreach ((array) ($json['Attachments'] ?? []) as $attachment) {
                if (!is_array($attachment) || empty($attachment['Content'])) {
                    continue;
                }
                $mail['files'][] = self::temp_file((string) ($attachment['Name'] ?? 'file'), (string) base64_decode((string) $attachment['Content'], true));
            }
        } else {
            $p = $req->get_body_params();
            if (isset($p['body-plain']) || isset($p['stripped-text']) || isset($p['recipient'])) {
                // Mailgun.
                $mail['from'] = $emails($p['from'] ?? ($p['sender'] ?? ''))[0] ?? '';
                $mail['to'] = array_merge($emails($p['recipient'] ?? ''), $emails($p['To'] ?? ''), $emails($p['Cc'] ?? ''));
                $mail['text'] = '' !== trim((string) ($p['stripped-text'] ?? '')) ? (string) $p['stripped-text'] : (string) ($p['body-plain'] ?? '');
            } else {
                // SendGrid Inbound Parse (and similar).
                $mail['from'] = $emails($p['from'] ?? '')[0] ?? '';
                $envelope = json_decode((string) ($p['envelope'] ?? ''), true);
                $mail['to'] = array_merge($emails($p['to'] ?? ''), $emails($p['cc'] ?? ''), is_array($envelope) ? $emails($envelope['to'] ?? []) : []);
                $mail['text'] = '' !== trim((string) ($p['text'] ?? '')) ? (string) $p['text'] : self::html_to_text((string) ($p['html'] ?? ''));
            }
            foreach ($req->get_file_params() as $field => $raw) {
                if (!is_array($raw) || !preg_match('/^attachment/i', (string) $field)) {
                    continue;
                }
                foreach (FFLA_Requests_Files::normalize($raw) as $file) {
                    $mail['files'][] = $file + ['uploaded' => true];
                }
            }
        }
        $mail['to'] = array_values(array_unique(array_filter($mail['to'])));
        $mail['files'] = array_values(array_filter($mail['files']));
        return $mail;
    }

    private static function temp_file(string $name, string $bytes): array
    {
        if ('' === $bytes) {
            return [];
        }
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $path = wp_tempnam('ffla-inbound');
        file_put_contents($path, $bytes); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return ['name' => $name, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes), 'uploaded' => false, 'temp' => true];
    }

    private static function cleanup(array $mail): void
    {
        foreach ($mail['files'] as $file) {
            if (!empty($file['temp']) && is_file((string) $file['tmp_name'])) {
                @unlink((string) $file['tmp_name']); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
    }

    public static function html_to_text(string $html): string
    {
        $html = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<blockquote[^>]*>.*?</blockquote>#is', '', (string) $html); // Quoted earlier messages.
        $html = preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6])>#i', "\n", (string) $html);
        return trim(html_entity_decode(wp_strip_all_tags((string) $html, false), ENT_QUOTES, 'UTF-8'));
    }

    /** Keep only what the customer wrote: drop quoted earlier messages and common signatures. */
    public static function strip_quoted(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $cuts = [
            '/^\s*On\s.{0,300}?\s?wrote:\s*$/msi',            // Gmail, Apple Mail (may wrap onto two lines).
            '/^\s*El\s.{0,300}?\s?escribi[oó]:\s*$/msi',       // Spanish clients.
            '/^-{2,}\s*Original Message\s*-{2,}/mi',
            '/^_{10,}\s*$/m',                                  // Outlook separator line.
            '/^\s*(From|De):\s.+\n\s*(Sent|Date|Enviado|Fecha):/mi',
            '/^-- ?$/m',                                       // Signature delimiter.
        ];
        foreach ($cuts as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $text = substr($text, 0, (int) $m[0][1]);
            }
        }
        $lines = array_filter(explode("\n", $text), static function ($line) {
            return !preg_match('/^\s*>/', $line) && !preg_match('/^\s*(Sent from my|Enviado desde mi|Get Outlook for)\b/i', $line);
        });
        return FFLA_Requests::clean_text(trim(implode("\n", $lines)));
    }

    private static function log(string $from, string $number, string $result): void
    {
        $log = get_option(self::LOG, []);
        $log = is_array($log) ? $log : [];
        // Only the domain of the sender: enough to recognise a test, without keeping addresses.
        $domain = '' !== $from && false !== strpos($from, '@') ? '…@' . substr(strrchr($from, '@'), 1) : '';
        array_unshift($log, ['at' => time(), 'from' => $domain, 'request' => $number, 'result' => $result]);
        update_option(self::LOG, array_slice($log, 0, 10), false);
    }

    public static function new_secret(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        check_admin_referer('ffla_req_inbound_secret');
        update_option(self::SECRET, FFLA_Requests::random_hex(16), false);
        wp_safe_redirect(admin_url('admin.php?page=ffla-customer-operations#connections-setup'));
        exit;
    }

    /** Settings tool panel: test alert, webhook URL, provider steps and recent replies. */
    public static function setup_panel(): void
    {
        $flash = get_transient('ffla_req_alert_test_' . get_current_user_id());
        if (is_array($flash)) {
            delete_transient('ffla_req_alert_test_' . get_current_user_id());
            echo '<div class="notice notice-' . ('success' === $flash[0] ? 'success' : 'error') . ' inline"><p>' . esc_html($flash[1]) . '</p></div>';
        }

        echo '<p class="ffla-set-label">' . esc_html__('Alerts', 'ffl-funnels-addons') . '</p>';
        echo '<p class="description">' . esc_html__('Slack: create an Incoming Webhook for the channel and paste its link. ClickUp: create an Automation with the “Webhook” trigger and paste its link; the JSON includes event, text and request fields to map into a task. Discord and Google Chat webhook links work too.', 'ffl-funnels-addons') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ffla_req_test_alert">';
        wp_nonce_field('ffla_req_test_alert');
        echo '<p><button type="submit" class="button"' . ('' === FFLA_Requests_Alerts::url() ? ' disabled' : '') . '>' . esc_html__('Send a test alert', 'ffl-funnels-addons') . '</button>'
            . ('' === FFLA_Requests_Alerts::url() ? ' <span class="description">' . esc_html__('Save an https alerts link first.', 'ffl-funnels-addons') . '</span>' : '') . '</p></form>';

        echo '<p class="ffla-set-label">' . esc_html__('Reply by email', 'ffl-funnels-addons') . '</p>';
        $state = self::enabled()
            ? sprintf(
                /* translators: %s: example address */
                __('On. Customer emails ask for replies at addresses like %s.', 'ffl-funnels-addons'),
                self::example()
            )
            : (FFLA_Customer_Operations_Settings::enabled('requests_inbound') ? __('Waiting for a valid reply address.', 'ffl-funnels-addons') : __('Off.', 'ffl-funnels-addons'));
        echo '<p>' . esc_html($state) . '</p>';
        echo '<label for="ffla-inbound-url" class="ffla-set-label">' . esc_html__('Webhook URL for your inbound email service', 'ffl-funnels-addons') . '</label>';
        echo '<div class="ffla-inbound-url"><input type="text" id="ffla-inbound-url" readonly value="' . esc_attr(self::endpoint()) . '" onclick="this.select()"></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('The old URL stops working. Update it in your email service right after. Continue?', 'ffl-funnels-addons')) . '\')"><input type="hidden" name="action" value="ffla_req_inbound_secret">';
        wp_nonce_field('ffla_req_inbound_secret');
        echo '<p><button type="submit" class="button-link">' . esc_html__('Make a new URL (if this one was shared)', 'ffl-funnels-addons') . '</button></p></form>';
        echo '<ol class="description">'
            . '<li>' . esc_html__('Postmark: Servers → your server → Default Inbound Stream → Settings → Webhook URL = the URL above. Use the inbound address Postmark shows (…@inbound.postmarkapp.com), or your own address forwarded to it, as the Reply address.', 'ffl-funnels-addons') . '</li>'
            . '<li>' . esc_html__('Mailgun: Receiving → Create route → Match recipient for your reply address (plus-addresses included) → Forward to the URL above.', 'ffl-funnels-addons') . '</li>'
            . '<li>' . esc_html__('SendGrid: Settings → Inbound Parse → add your reply domain with the URL above, and point that domain’s MX record to mx.sendgrid.net.', 'ffl-funnels-addons') . '</li>'
            . '</ol>';
        $log = get_option(self::LOG, []);
        if (is_array($log) && $log) {
            $labels = [
                'added'        => __('added to the request', 'ffl-funnels-addons'),
                'internal'     => __('kept as an internal note (different sender)', 'ffl-funnels-addons'),
                'closed'       => __('request closed — kept as an internal note', 'ffl-funnels-addons'),
                'empty'        => __('empty reply skipped', 'ffl-funnels-addons'),
                'no_request'   => __('no matching request (not a reply to a request email)', 'ffl-funnels-addons'),
                'rate_limited' => __('skipped: too many emails for one request this hour', 'ffl-funnels-addons'),
                'off'          => __('received while Reply by email was off', 'ffl-funnels-addons'),
            ];
            echo '<p class="ffla-set-label">' . esc_html__('Recent email replies', 'ffl-funnels-addons') . '</p><ul class="ffla-inbound-log">';
            foreach ($log as $row) {
                echo '<li>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $row['at']) . ' · ' . ($row['request'] ?: '—') . ' · ' . ($labels[$row['result']] ?? $row['result']) . ($row['from'] ? ' · ' . $row['from'] : '')) . '</li>';
            }
            echo '</ul>';
        }
    }

    private static function example(): string
    {
        $address = self::address();
        if ('' === $address) {
            return '';
        }
        [$local, $domain] = explode('@', $address, 2);
        return $local . '+802-1-' . substr(self::sig('802-1'), 0, 10) . '@' . $domain;
    }
}
