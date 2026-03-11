<?php
/**
 * FFL Checkout — AJAX Handlers.
 *
 * Proxies API calls to ffl-api.garidium.com through WordPress AJAX
 * so the API key is never exposed to the browser.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Ajax
{
    /** Garidium API base URL. */
    const API_URL = 'https://ffl-api.garidium.com';

    /**
     * Register AJAX hooks.
     */
    public static function init(): void
    {
        // FFL dealer search (proxy).
        add_action('wp_ajax_ffl_search_dealers', [__CLASS__, 'search_dealers']);
        add_action('wp_ajax_nopriv_ffl_search_dealers', [__CLASS__, 'search_dealers']);

        // Mapbox token retrieval (keeps token server-side).
        add_action('wp_ajax_ffl_get_mapbox_token', [__CLASS__, 'get_mapbox_token']);
        add_action('wp_ajax_nopriv_ffl_get_mapbox_token', [__CLASS__, 'get_mapbox_token']);

        // C&R document upload (proxy).
        add_action('wp_ajax_ffl_upload_candr', [__CLASS__, 'upload_candr']);
        add_action('wp_ajax_nopriv_ffl_upload_candr', [__CLASS__, 'upload_candr']);
    }

    /* ── Search Dealers ─────────────────────────────────────────────────── */

    /**
     * Proxy FFL dealer search to the Garidium API.
     *
     * Accepts POST params: search_type, zipcode, radius, ffl_name, license_number.
     */
    public static function search_dealers(): void
    {
        check_ajax_referer('ffl_checkout_nonce', 'security');

        $api_key = get_option('g_ffl_cockpit_key', '');
        if (empty($api_key)) {
            wp_send_json_error('API key not configured.');
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $search_type    = sanitize_text_field($_POST['search_type'] ?? 'location');
        $zipcode        = sanitize_text_field($_POST['zipcode'] ?? '');
        $radius         = absint($_POST['radius'] ?? 25);
        $ffl_name       = sanitize_text_field($_POST['ffl_name'] ?? '');
        $license_number = sanitize_text_field($_POST['license_number'] ?? '');
        // phpcs:enable

        if ($search_type === 'license' && !empty($license_number)) {
            $payload = [
                'action' => 'get_ffl_by_license',
                'data'   => [
                    'api_key'        => $api_key,
                    'license_number' => $license_number,
                ],
            ];
        } else {
            if (empty($zipcode) || strlen($zipcode) < 5) {
                wp_send_json_error('Invalid ZIP code.');
            }

            $payload = [
                'action' => 'get_nearby_ffl_dealers',
                'data'   => [
                    'api_key'      => $api_key,
                    'zip_code'     => $zipcode,
                    'radius_miles' => $radius,
                ],
            ];

            if (!empty($ffl_name)) {
                $payload['data']['ffl_name'] = $ffl_name;
            }
        }

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'x-api-key'    => $api_key,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('API request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            wp_send_json_error('API returned status ' . $code);
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            wp_send_json_error('Invalid API response.');
        }

        // Normalise: the API may return dealers under different keys.
        $dealers = $data['dealers'] ?? $data['data'] ?? $data['ffls'] ?? [];
        if (!is_array($dealers) && is_array($data) && isset($data[0])) {
            $dealers = $data; // Plain array response.
        }

        wp_send_json_success(['data' => $dealers]);
    }

    /* ── Mapbox Token ───────────────────────────────────────────────────── */

    /**
     * Return the Mapbox public token via AJAX so it never appears in page source.
     */
    public static function get_mapbox_token(): void
    {
        check_ajax_referer('ffl_checkout_nonce', 'security');

        $settings = get_option('ffl_checkout_settings', []);
        $token    = $settings['mapbox_public_token'] ?? '';

        if (empty($token)) {
            wp_send_json_error('Mapbox token not configured.');
        }

        wp_send_json_success($token);
    }

    /* ── C&R Upload ─────────────────────────────────────────────────────── */

    /**
     * Proxy C&R document upload to the Garidium API.
     */
    public static function upload_candr(): void
    {
        check_ajax_referer('ffl_checkout_nonce', 'security');

        $api_key = get_option('g_ffl_cockpit_key', '');
        if (empty($api_key)) {
            wp_send_json_error(['message' => 'API key not configured.']);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $license_number = sanitize_text_field($_POST['license_number'] ?? '');
        if (empty($license_number)) {
            wp_send_json_error(['message' => 'License number is required.']);
        }

        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'No file uploaded or upload error.']);
        }

        $file = $_FILES['document'];

        // Validate file type.
        $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed, true)) {
            wp_send_json_error(['message' => 'Invalid file type.']);
        }

        // Build multipart request to Garidium.
        $boundary = wp_generate_password(24, false);
        $body     = '';

        // API key field.
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"api_key\"\r\n\r\n";
        $body .= $api_key . "\r\n";

        // License number field.
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"license_number\"\r\n\r\n";
        $body .= $license_number . "\r\n";

        // Action field.
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"action\"\r\n\r\n";
        $body .= "upload_candr_document\r\n";

        // File field.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $file_contents = file_get_contents($file['tmp_name']);
        $body .= "--{$boundary}\r\n";
        $body .= 'Content-Disposition: form-data; name="document"; filename="' . sanitize_file_name($file['name']) . "\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $file_contents . "\r\n";
        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post(self::API_URL, [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                'x-api-key'    => $api_key,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Upload failed: ' . $response->get_error_message()]);
        }

        $code     = wp_remote_retrieve_response_code($response);
        $res_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            wp_send_json_error(['message' => $res_body['message'] ?? 'Upload failed (status ' . $code . ').']);
        }

        wp_send_json_success($res_body);
    }
}
