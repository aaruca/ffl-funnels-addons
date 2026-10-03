<?php
/**
 * Customer requests — alerts to Slack, ClickUp, Discord, Google Chat or any
 * app that accepts a JSON webhook.
 *
 * New customer requests, customer replies (from the request page or by
 * email) and 1–2 star ratings are posted to the address in **Alerts link**.
 * Slack, Discord and Google Chat links get their own message format; any
 * other https address (a ClickUp Automation "webhook" trigger, Zapier, Make…)
 * gets a JSON body with the event, a ready-made `text` and the request's
 * fields. Sent in the background, so a slow or broken link never slows the
 * customer down. Filter `ffla_requests_alert_payload` to change what is sent.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Alerts
{
    public static function boot(): void
    {
        add_action('ffla_request_created', [__CLASS__, 'created'], 20, 2);
        add_action('ffla_request_customer_replied', [__CLASS__, 'replied'], 20, 3);
        add_action('ffla_request_rated', [__CLASS__, 'rated'], 20, 3);
        add_action('admin_post_ffla_req_test_alert', [__CLASS__, 'test']);
    }

    /** The alerts address, or '' when none is set or it is not a valid https URL. */
    public static function url(): string
    {
        $url = trim((string) FFLA_Requests::setting('requests_alerts_url'));
        if ('' === $url || 0 !== stripos($url, 'https://') || !wp_http_validate_url($url)) {
            return '';
        }
        return $url;
    }

    public static function created($request, $order = null): void
    {
        if ('staff' === ($request->source ?? '') || !FFLA_Customer_Operations_Settings::enabled('requests_alert_new')) {
            return;
        }
        $reasons = FFLA_Requests::reasons($request->type);
        self::send('request.created', $request, sprintf(
            /* translators: 1: request type, 2: number, 3: customer, 4: reason, 5: order number */
            __('New %1$s %2$s from %3$s — %4$s · order #%5$s', 'ffl-funnels-addons'),
            strtolower(FFLA_Requests::type_label($request->type)),
            $request->number,
            $request->customer_name ?: $request->customer_email,
            $reasons[$request->reason] ?? $request->reason,
            $request->order_number
        ));
    }

    public static function replied($request, string $text = '', string $via = 'page'): void
    {
        if (!FFLA_Customer_Operations_Settings::enabled('requests_alert_reply')) {
            return;
        }
        self::send('request.customer_replied', $request, sprintf(
            /* translators: 1: customer, 2: request number, 3: how (e.g. " by email"), 4: message excerpt */
            __('%1$s replied on %2$s%3$s: “%4$s”', 'ffl-funnels-addons'),
            $request->customer_name ?: $request->customer_email,
            $request->number,
            'email' === $via ? __(' by email', 'ffl-funnels-addons') : '',
            self::excerpt($text)
        ), ['message' => self::excerpt($text, 1000), 'via' => $via]);
    }

    public static function rated($request, int $rating = 0, string $comment = ''): void
    {
        if ($rating > 2 || !FFLA_Customer_Operations_Settings::enabled('requests_alert_rating')) {
            return;
        }
        self::send('request.low_rating', $request, sprintf(
            /* translators: 1: request number, 2: rating, 3: customer, 4: comment */
            __('%1$s was rated %2$d/5 by %3$s%4$s', 'ffl-funnels-addons'),
            $request->number,
            $rating,
            $request->customer_name ?: $request->customer_email,
            '' !== trim($comment) ? ': “' . self::excerpt($comment) . '”' : ''
        ), ['rating' => $rating, 'comment' => self::excerpt($comment, 1000)]);
    }

    private static function excerpt(string $text, int $length = 200): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        return function_exists('mb_strlen') && mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }

    /**
     * Build the body for the address: Slack / Google Chat ("text" with
     * <url|label> links), Discord ("content" with [label](url)), anything else
     * a JSON event.
     */
    public static function payload(string $url, string $event, $request, string $text, array $extra = []): array
    {
        $link = FFLA_Requests_Mail::admin_request_url($request);
        $store = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $label = __('Open request', 'ffl-funnels-addons');

        if ('hooks.slack.com' === $host || 'chat.googleapis.com' === $host) {
            $payload = ['text' => '*' . $store . '* · ' . $text . "\n<" . $link . '|' . $label . '>'];
            if ('hooks.slack.com' === $host) {
                $payload['unfurl_links'] = false;
            }
        } elseif (preg_match('/(^|\.)discord(app)?\.com$/', $host)) {
            $payload = ['content' => '**' . $store . '** · ' . $text . "\n[" . $label . '](' . $link . ')', 'allowed_mentions' => ['parse' => []]];
        } else {
            $payload = [
                'event'   => $event,
                'text'    => $store . ' · ' . $text . ' — ' . $link,
                'store'   => $store,
                'site'    => home_url('/'),
                'url'     => $link,
                'request' => [
                    'number'   => $request->number,
                    'type'     => $request->type,
                    'reason'   => $request->reason,
                    'status'   => $request->status,
                    'priority' => $request->priority,
                    'order'    => $request->order_number,
                    'customer' => $request->customer_name,
                    'email'    => $request->customer_email,
                    'firearm'  => (bool) $request->has_firearm,
                ],
            ] + $extra;
        }
        return (array) apply_filters('ffla_requests_alert_payload', $payload, $event, $request, $url);
    }

    /** @return array|WP_Error|null The response when $blocking, otherwise null. */
    public static function send(string $event, $request, string $text, array $extra = [], bool $blocking = false)
    {
        $url = self::url();
        if ('' === $url) {
            return null;
        }
        $response = wp_remote_post($url, [
            'timeout'     => $blocking ? 8 : 3,
            'blocking'    => $blocking,
            'redirection' => 0,
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => (string) wp_json_encode(self::payload($url, $event, $request, $text, $extra)),
            'user-agent'  => 'FFL-Funnels-Addons/' . (defined('FFLA_VERSION') ? FFLA_VERSION : '1') . '; ' . home_url('/'),
        ]);
        return $blocking ? $response : null;
    }

    /** "Send a test alert" on the settings screen. */
    public static function test(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        check_admin_referer('ffla_req_test_alert');
        $back = admin_url('admin.php?page=ffla-customer-operations#connections-setup');
        if ('' === self::url()) {
            set_transient('ffla_req_alert_test_' . get_current_user_id(), ['error', __('Save an https alerts link first.', 'ffl-funnels-addons')], 120);
            wp_safe_redirect($back);
            exit;
        }
        $sample = (object) [
            'id' => 0, 'number' => 'TEST-1', 'type' => 'issue', 'reason' => 'damaged', 'status' => 'new', 'priority' => 'normal',
            'order_number' => '1001', 'customer_name' => __('Test customer', 'ffl-funnels-addons'), 'customer_email' => 'customer@example.com', 'has_firearm' => 0,
        ];
        $response = self::send('request.test', $sample, __('Test alert from Customer requests. If you can read this, alerts work.', 'ffl-funnels-addons'), [], true);
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $result = $code >= 200 && $code < 300
            ? ['success', __('Test alert sent. Check the channel or app.', 'ffl-funnels-addons')]
            : ['error', sprintf(
                /* translators: %s: HTTP status or error message */
                __('The alerts link did not accept the test (%s). Check the link.', 'ffl-funnels-addons'),
                is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . $code
            )];
        set_transient('ffla_req_alert_test_' . get_current_user_id(), $result, 120);
        wp_safe_redirect($back);
        exit;
    }
}
