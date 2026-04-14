<?php
/**
 * WSS Google OAuth — Full OAuth 2.0 lifecycle via proxy.
 *
 * The OAuth consent flow goes through a proxy server (alearuca.com/wss-proxy/)
 * that holds the Google Client ID and Client Secret. After authorization, the
 * proxy returns encrypted tokens + credentials to the client site.
 *
 * Token refresh happens directly with Google (no proxy dependency).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Google_OAuth
{
    private const TOKEN_OPTION      = 'wss_google_tokens';
    private const TOKEN_ENDPOINT    = 'https://oauth2.googleapis.com/token';
    private const REVOKE_ENDPOINT   = 'https://oauth2.googleapis.com/revoke';
    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * Proxy URL — where the OAuth consent flow is handled.
     * Change this to your own server URL.
     */
    private const PROXY_URL = 'https://alearuca.com/wss-proxy/';

    /**
     * Get the shared secret between the proxy and the plugin.
     * Define WSS_PROXY_SECRET in wp-config.php to override.
     */
    private static function get_proxy_secret(): string
    {
        if (defined('WSS_PROXY_SECRET') && WSS_PROXY_SECRET !== '') {
            return WSS_PROXY_SECRET;
        }
        // Fallback — rotate this value immediately and move to wp-config.php.
        return '50420637e8a7472b5215101a107d3185fcd09cca2a8a6a827fbcb9f56288629e';
    }

    /**
     * Get the Client ID — stored in wss_settings after proxy callback.
     */
    public function get_client_id(): string
    {
        $settings = get_option('wss_settings', []);
        $encrypted = $settings['client_id'] ?? '';

        if ($encrypted === '') {
            return '';
        }

        return self::decrypt_maybe_plain($encrypted);
    }

    /**
     * Get the Client Secret — stored encrypted in wss_settings after proxy callback.
     */
    public function get_client_secret(): string
    {
        $settings = get_option('wss_settings', []);
        $encrypted = $settings['client_secret'] ?? '';

        if ($encrypted === '') {
            return '';
        }

        return self::decrypt_maybe_plain($encrypted);
    }

    /**
     * Credentials are always available via the proxy.
     * Returns true if we already have stored credentials from a previous connection,
     * or true by default (since the proxy provides them).
     */
    public function credentials_defined(): bool
    {
        return true;
    }

    /**
     * Get the return URL for the proxy callback.
     */
    public function get_return_url(): string
    {
        return admin_url('admin.php?page=ffla-wss-settings&wss_oauth=callback');
    }

    /**
     * Build the authorization URL — redirects to the proxy, not Google directly.
     */
    public function get_auth_url(): string
    {
        $state = wp_generate_password(32, false);
        set_transient('wss_oauth_state', $state, 1800); // 30 minutes

        return self::PROXY_URL . '?' . http_build_query([
            'action'     => 'authorize',
            'return_url' => $this->get_return_url(),
            'state'      => $state,
        ]);
    }

    /**
     * Handle the proxy callback — decrypt payload and store tokens + credentials.
     *
     * @param string $encrypted_payload Base64-encoded encrypted payload from proxy.
     * @param string $state             CSRF state parameter.
     * @return true|WP_Error
     */
    public function handle_proxy_callback(string $encrypted_payload, string $state)
    {
        // Validate CSRF state.
        $stored_state = get_transient('wss_oauth_state');
        delete_transient('wss_oauth_state');

        self::debug_log("handle_proxy_callback: stored_state=" . ($stored_state ?: '(empty)') . " | received_state={$state}");
        self::debug_log("handle_proxy_callback: payload length=" . strlen($encrypted_payload));

        if (!$stored_state || !hash_equals($stored_state, $state)) {
            self::debug_log("ERROR: State mismatch! stored=" . ($stored_state ?: '(empty)') . " vs received={$state}");
            return new WP_Error('wss_oauth', __('Invalid OAuth state. Please try again.', 'ffl-funnels-addons'));
        }

        // Decrypt the proxy payload.
        $json = self::proxy_decrypt($encrypted_payload);

        self::debug_log("handle_proxy_callback: decrypted length=" . strlen($json));

        if ($json === '') {
            self::debug_log("ERROR: proxy_decrypt returned empty. Payload first 100 chars: " . substr($encrypted_payload, 0, 100));
            return new WP_Error('wss_oauth', __('Failed to decrypt proxy response.', 'ffl-funnels-addons'));
        }

        $data = json_decode($json, true);

        self::debug_log("handle_proxy_callback: JSON keys=" . (is_array($data) ? implode(',', array_keys($data)) : 'DECODE FAILED'));

        if (empty($data['access_token']) || empty($data['refresh_token'])) {
            self::debug_log("ERROR: Missing tokens in decrypted data");
            return new WP_Error('wss_oauth', __('Invalid token data from proxy.', 'ffl-funnels-addons'));
        }

        // Store tokens encrypted (non-autoloaded).
        $tokens = [
            'access_token'  => self::encrypt($data['access_token']),
            'refresh_token' => self::encrypt($data['refresh_token']),
            'expires_at'    => time() + (int) ($data['expires_in'] ?? 3600),
            'user_email'    => $data['user_email'] ?? '',
        ];

        update_option(self::TOKEN_OPTION, $tokens, false);
        self::debug_log("handle_proxy_callback: Tokens saved.");

        // Store credentials encrypted in wss_settings so refresh_token works without proxy.
        if (!empty($data['client_id']) && !empty($data['client_secret'])) {
            $settings = get_option('wss_settings', []);
            $settings['client_id']     = self::encrypt($data['client_id']);
            $settings['client_secret'] = self::encrypt($data['client_secret']);
            update_option('wss_settings', $settings);
            self::debug_log("handle_proxy_callback: Credentials encrypted and saved.");
        }

        return true;
    }

    /**
     * Debug log helper.
     */
    private static function debug_log(string $message): void
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $line = '[WSS OAuth ' . gmdate('Y-m-d H:i:s') . '] ' . $message;
        error_log($line);

        // Write to a log file in the private uploads dir, only in debug mode.
        $upload_dir = wp_upload_dir();
        $log_dir    = $upload_dir['basedir'] . '/wss-logs';
        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
            // Protect directory from public access.
            @file_put_contents($log_dir . '/.htaccess', 'deny from all'); // phpcs:ignore WordPress.WP.AlternativeFunctions
            @file_put_contents($log_dir . '/index.php', '<?php // Silence is golden.'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        $log_file = $log_dir . '/wss-oauth-debug.log';
        file_put_contents($log_file, $line . "\n", FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.WP.AlternativeFunctions
    }

    /**
     * Get a valid access token, refreshing if expired.
     *
     * @return string|WP_Error
     */
    public function get_access_token()
    {
        $tokens = get_option(self::TOKEN_OPTION, []);

        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            return new WP_Error('wss_oauth', __('Not connected to Google. Please authorize first.', 'ffl-funnels-addons'));
        }

        // Refresh if expired or about to expire (60s buffer).
        if (time() >= ($tokens['expires_at'] - 60)) {
            $result = $this->refresh_token();
            if (is_wp_error($result)) {
                return $result;
            }
            $tokens = get_option(self::TOKEN_OPTION, []);
        }

        return self::decrypt_maybe_plain((string) $tokens['access_token']);
    }

    /**
     * Refresh the access token directly with Google (no proxy needed).
     *
     * @return true|WP_Error
     */
    public function refresh_token()
    {
        $tokens = get_option(self::TOKEN_OPTION, []);

        if (empty($tokens['refresh_token'])) {
            return new WP_Error('wss_oauth', __('No refresh token available. Please reconnect.', 'ffl-funnels-addons'));
        }

        $client_id     = $this->get_client_id();
        $client_secret = $this->get_client_secret();

        if ($client_id === '' || $client_secret === '') {
            return new WP_Error('wss_oauth', __('Missing credentials. Please reconnect with Google.', 'ffl-funnels-addons'));
        }

        $response = wp_remote_post(self::TOKEN_ENDPOINT, [
            'body' => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => self::decrypt_maybe_plain((string) $tokens['refresh_token']),
                'grant_type'    => 'refresh_token',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            $error_msg = $body['error_description'] ?? $body['error'] ?? __('Failed to refresh token.', 'ffl-funnels-addons');
            return new WP_Error('wss_oauth', $error_msg);
        }

        $tokens['access_token'] = self::encrypt($body['access_token']);
        $tokens['expires_at']   = time() + (int) ($body['expires_in'] ?? 3600);

        // Google may issue a new refresh token.
        if (!empty($body['refresh_token'])) {
            $tokens['refresh_token'] = self::encrypt($body['refresh_token']);
        }

        update_option(self::TOKEN_OPTION, $tokens, false);

        return true;
    }

    /**
     * Revoke the token and delete stored credentials.
     */
    public function revoke(): void
    {
        $tokens = get_option(self::TOKEN_OPTION, []);

        if (!empty($tokens['access_token'])) {
            $plain_token = self::decrypt($tokens['access_token']);
            if ($plain_token !== '') {
                wp_remote_post(self::REVOKE_ENDPOINT, [
                    'body'    => ['token' => $plain_token],
                    'timeout' => 10,
                ]);
            }
        }

        delete_option(self::TOKEN_OPTION);

        // Also clear stored credentials.
        $settings = get_option('wss_settings', []);
        unset($settings['client_id'], $settings['client_secret']);
        update_option('wss_settings', $settings);
    }

    /**
     * Check if we have a valid connection (refresh token exists).
     */
    public function is_connected(): bool
    {
        $tokens = get_option(self::TOKEN_OPTION, []);
        return !empty($tokens['refresh_token']);
    }

    /**
     * Get the connected user's email address.
     */
    public function get_user_email(): string
    {
        $tokens = get_option(self::TOKEN_OPTION, []);
        return $tokens['user_email'] ?? '';
    }

    // ──────────────────────────────────────────────────
    // Encryption helpers
    // ──────────────────────────────────────────────────

    /**
     * Decrypt a payload from the proxy (uses PROXY_SECRET).
     */
    private static function proxy_decrypt(string $encoded): string
    {
        $key  = hash('sha256', self::get_proxy_secret(), true);
        // Restore URL-safe base64: replace -_ back to +/ and add padding.
        $encoded = strtr($encoded, '-_', '+/');
        $pad     = strlen($encoded) % 4;
        if ($pad) {
            $encoded .= str_repeat('=', 4 - $pad);
        }
        $data    = base64_decode($encoded, true);

        if ($data === false || strlen($data) < 17) {
            return '';
        }

        $iv     = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $plain !== false ? $plain : '';
    }

    /**
     * Encrypt a value for safe DB storage (uses WordPress AUTH_KEY).
     */
    private static function encrypt(string $plain): string
    {
        $key    = self::storage_key();
        $iv     = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            return '';
        }

        return base64_encode($iv . $cipher);
    }

    /**
     * Decrypt a value from DB storage (uses WordPress AUTH_KEY).
     */
    private static function decrypt(string $encoded): string
    {
        $key  = self::storage_key();
        $data = base64_decode($encoded, true);

        if ($data === false || strlen($data) < 17) {
            return '';
        }

        $iv     = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $plain !== false ? $plain : '';
    }

    /**
     * Backward-compatible decrypt for legacy plain-text stored values.
     *
     * Older installs may already have unencrypted token/credential strings
     * stored in options; if decryption fails we keep the original value.
     */
    private static function decrypt_maybe_plain(string $value): string
    {
        $decoded = self::decrypt($value);
        if ($decoded !== '') {
            return $decoded;
        }

        return $value;
    }

    /**
     * Derive a 32-byte key from WordPress AUTH_KEY for local DB encryption.
     */
    private static function storage_key(): string
    {
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'wss-fallback-key-change-me';
        return hash('sha256', $salt . 'wss_credentials', true);
    }
}
