<?php
/**
 * WSS Google Service Account — Server-to-server auth via the JWT bearer flow.
 *
 * No OAuth consent screen, no refresh-token expiry, no proxy. The admin pastes a
 * service-account JSON key; the target Google Sheet is shared with the service
 * account's email as an Editor. Access tokens are minted directly from Google
 * and cached until shortly before they expire.
 *
 * Credentials are read in this order of precedence:
 *   1. WSS_SERVICE_ACCOUNT_JSON — raw JSON string defined in wp-config.php
 *   2. WSS_SERVICE_ACCOUNT_FILE — absolute path to the JSON key file
 *   3. wss_settings['service_account_json'] — encrypted option (admin paste)
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Google_Service_Account implements WSS_Token_Provider
{
    private const SCOPE             = 'https://www.googleapis.com/auth/spreadsheets';
    private const TOKEN_CACHE       = 'wss_sa_access_token';
    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** @var array<string,mixed>|null Parsed credential array, or null when unavailable. */
    private $creds;

    public function __construct()
    {
        $this->creds = self::load_credentials();
    }

    /**
     * Load and decode the service-account key from the configured source.
     *
     * @return array<string,mixed>|null
     */
    private static function load_credentials(): ?array
    {
        $json = '';

        if (defined('WSS_SERVICE_ACCOUNT_JSON') && WSS_SERVICE_ACCOUNT_JSON !== '') {
            $json = (string) WSS_SERVICE_ACCOUNT_JSON;
        } elseif (defined('WSS_SERVICE_ACCOUNT_FILE') && WSS_SERVICE_ACCOUNT_FILE !== '' && is_readable(WSS_SERVICE_ACCOUNT_FILE)) {
            $json = (string) file_get_contents(WSS_SERVICE_ACCOUNT_FILE); // phpcs:ignore WordPress.WP.AlternativeFunctions
        } else {
            $settings = get_option('wss_settings', []);
            $stored   = $settings['service_account_json'] ?? '';
            if ($stored !== '') {
                $json = WSS_Crypto::decrypt_maybe_plain((string) $stored);
            }
        }

        if ($json === '') {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
            return null;
        }

        return $data;
    }

    public function is_connected(): bool
    {
        return is_array($this->creds)
            && !empty($this->creds['client_email'])
            && !empty($this->creds['private_key']);
    }

    public function get_user_email(): string
    {
        return is_array($this->creds) ? (string) ($this->creds['client_email'] ?? '') : '';
    }

    /**
     * Validate a raw JSON string as a usable service-account key.
     *
     * @return string|WP_Error Service-account email on success, WP_Error otherwise.
     */
    public static function validate_json(string $json)
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new WP_Error('wss_sa', __('The service account key is not valid JSON.', 'ffl-funnels-addons'));
        }
        if (($data['type'] ?? '') !== 'service_account') {
            return new WP_Error('wss_sa', __('That JSON is not a service account key (expected "type": "service_account").', 'ffl-funnels-addons'));
        }
        if (empty($data['client_email']) || empty($data['private_key'])) {
            return new WP_Error('wss_sa', __('The service account key is missing client_email or private_key.', 'ffl-funnels-addons'));
        }

        return (string) $data['client_email'];
    }

    /**
     * Read the configured service-account email without exposing the key.
     */
    public static function configured_email(): string
    {
        $creds = self::load_credentials();
        return is_array($creds) ? (string) ($creds['client_email'] ?? '') : '';
    }

    /**
     * Clear the cached access token (call whenever credentials change).
     */
    public static function flush_token_cache(): void
    {
        delete_transient(self::TOKEN_CACHE);
    }

    /**
     * Get a valid access token, minting and caching a new one when needed.
     *
     * @return string|WP_Error
     */
    public function get_access_token()
    {
        if (!$this->is_connected()) {
            return new WP_Error('wss_sa', __('No service account configured.', 'ffl-funnels-addons'));
        }

        $cached = get_transient(self::TOKEN_CACHE);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $assertion = $this->build_assertion();
        if (is_wp_error($assertion)) {
            return $assertion;
        }

        $token_uri = (string) ($this->creds['token_uri'] ?? self::DEFAULT_TOKEN_URI);

        $response = wp_remote_post($token_uri, [
            'timeout' => 30,
            'body'    => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            $msg = $body['error_description'] ?? $body['error'] ?? __('Failed to obtain a service account token.', 'ffl-funnels-addons');
            return new WP_Error('wss_sa', $msg);
        }

        $token      = (string) $body['access_token'];
        $expires_in = (int) ($body['expires_in'] ?? 3600);

        // Cache slightly short of expiry so a stale token is never served.
        set_transient(self::TOKEN_CACHE, $token, max(60, $expires_in - 60));

        return $token;
    }

    /**
     * Build and RS256-sign the JWT assertion for the token request.
     *
     * @return string|WP_Error
     */
    private function build_assertion()
    {
        $now    = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => (string) $this->creds['client_email'],
            'scope' => self::SCOPE,
            'aud'   => (string) ($this->creds['token_uri'] ?? self::DEFAULT_TOKEN_URI),
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $signing_input = self::base64url((string) wp_json_encode($header))
            . '.'
            . self::base64url((string) wp_json_encode($claims));

        $signature = '';
        $signed    = openssl_sign($signing_input, $signature, (string) $this->creds['private_key'], OPENSSL_ALGO_SHA256);

        if (!$signed || $signature === '') {
            return new WP_Error('wss_sa', __('Could not sign the request. Check the private_key in the service account JSON.', 'ffl-funnels-addons'));
        }

        return $signing_input . '.' . self::base64url($signature);
    }

    /**
     * URL-safe base64 without padding (JWT encoding).
     */
    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
