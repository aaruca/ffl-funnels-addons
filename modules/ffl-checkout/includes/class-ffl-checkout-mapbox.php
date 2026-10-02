<?php
/**
 * FFL Checkout Mapbox — token resolver.
 *
 * Resolves which Mapbox access token to hand the frontend, following an
 * "Auto + override" model:
 *
 *   1. The admin's own token (Settings → Mapbox Public Token) always wins.
 *   2. If that's blank, borrow a token from the g-FFL Checkout plugin, which
 *      vends one from its vendor (Garidium) using the FFL API key that's
 *      already configured whenever FFL Checkout Settings is set up.
 *   3. If neither is available, return '' so callers fall back to no map.
 *
 * The borrowed token is cached in a transient so we don't hit the vendor on
 * every checkout page load. The cache remembers which g-FFL key it was
 * borrowed with, so a changed or removed key never keeps serving the old
 * token.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Mapbox
{
    /** Transient holding the borrowed token. */
    const BORROW_TRANSIENT = 'ffla_borrowed_mapbox_token';

    /** Sentinel set after a failed borrow, so we stop retrying on every render. */
    const BORROW_FAIL_TRANSIENT = 'ffla_borrowed_mapbox_token_fail';

    /** How long to skip retrying the vendor after a failure. */
    const BORROW_FAIL_TTL = 60;

    /** Cache lifetime for a borrowed token — short enough to tolerate rotation. */
    const BORROW_TTL = 50 * MINUTE_IN_SECONDS;

    /** g-FFL Checkout's vendor endpoint (mirrors that plugin's own proxy). */
    const VENDOR_ENDPOINT = 'https://ffl-api.garidium.com';

    /** WP option the g-FFL Checkout plugin stores its vendor API key in. */
    const FFL_API_KEY_OPTION = 'ffl_api_key_option';

    /**
     * Resolve the Mapbox token to use on the frontend.
     *
     * @param array $settings Optional pre-loaded ffl_checkout_settings array.
     * @return string A usable token, or '' when none is available.
     */
    public static function resolve_token(array $settings = []): string
    {
        if (empty($settings)) {
            $settings = get_option('ffl_checkout_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
        }

        // 1. Admin's own token wins.
        $own = isset($settings['mapbox_public_token'])
            ? trim((string) $settings['mapbox_public_token'])
            : '';
        if ($own !== '') {
            return $own;
        }

        // 2. Otherwise borrow from g-FFL Checkout.
        return self::borrow_token();
    }

    /**
     * Whether a token can currently be borrowed from g-FFL Checkout.
     *
     * Used by the admin UI to tell the operator whether the "Auto" fallback
     * will actually work. True when the FFL vendor API key is configured.
     */
    public static function is_borrow_available(): bool
    {
        return self::ffl_api_key() !== '';
    }

    /**
     * Drop the cached borrowed token and the failure back-off.
     */
    public static function flush_cache(): void
    {
        delete_transient(self::BORROW_TRANSIENT);
        delete_transient(self::BORROW_FAIL_TRANSIENT);
    }

    /**
     * Flush the borrowed token whenever g-FFL Checkout's API key changes.
     */
    public static function init(): void
    {
        add_action('update_option_' . self::FFL_API_KEY_OPTION, [__CLASS__, 'flush_cache'], 10, 0);
        add_action('add_option_' . self::FFL_API_KEY_OPTION, [__CLASS__, 'flush_cache'], 10, 0);
        add_action('delete_option_' . self::FFL_API_KEY_OPTION, [__CLASS__, 'flush_cache'], 10, 0);
    }

    /**
     * Token borrowed from g-FFL Checkout (cached), or '' when none can be
     * borrowed right now. Used by the settings page to report the real state.
     */
    public static function borrowed_token(): string
    {
        return self::borrow_token();
    }

    /**
     * Fetch a Mapbox token from the g-FFL Checkout vendor, cached.
     */
    private static function borrow_token(): string
    {
        $api_key = self::ffl_api_key();
        if ($api_key === '') {
            return '';
        }
        $key_hash = self::key_fingerprint($api_key);

        // Only a token borrowed with the current key counts. Older cache
        // entries (a bare string) carry no key and are refreshed once.
        $cached = get_transient(self::BORROW_TRANSIENT);
        if (
            is_array($cached)
            && isset($cached['token'], $cached['key'])
            && is_string($cached['token'])
            && $cached['token'] !== ''
            && hash_equals((string) $cached['key'], $key_hash)
        ) {
            return $cached['token'];
        }

        // This runs while the checkout page is rendering. Only successes were
        // cached, so a vendor outage meant every single render re-issued the
        // request and blocked for the full timeout. Back off instead.
        $failed_for = get_transient(self::BORROW_FAIL_TRANSIENT);
        if (false !== $failed_for && (string) $failed_for === $key_hash) {
            return '';
        }

        $response = wp_safe_remote_post(self::VENDOR_ENDPOINT, [
            'headers' => [
                'origin'       => get_site_url(),
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
                'x-api-key'    => $api_key,
            ],
            'body'    => wp_json_encode(['action' => 'get_mapbox_token']),
            // Render-blocking: fail fast rather than hold the page open.
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            set_transient(self::BORROW_FAIL_TRANSIENT, $key_hash, self::BORROW_FAIL_TTL);
            return '';
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::BORROW_FAIL_TRANSIENT, $key_hash, self::BORROW_FAIL_TTL);
            return '';
        }

        $data  = json_decode(wp_remote_retrieve_body($response), true);
        $token = self::extract_token($data);
        if ($token === '') {
            set_transient(self::BORROW_FAIL_TRANSIENT, $key_hash, self::BORROW_FAIL_TTL);
            return '';
        }

        // Clear any stale failure sentinel now that the vendor is healthy again.
        delete_transient(self::BORROW_FAIL_TRANSIENT);

        set_transient(self::BORROW_TRANSIENT, ['token' => $token, 'key' => $key_hash], self::BORROW_TTL);
        return $token;
    }

    /**
     * Non-reversible marker of the g-FFL key a cached token belongs to.
     */
    private static function key_fingerprint(string $api_key): string
    {
        return hash('sha256', 'ffla_mapbox|' . $api_key);
    }

    /**
     * Read the g-FFL Checkout vendor API key.
     */
    private static function ffl_api_key(): string
    {
        $key = get_option(self::FFL_API_KEY_OPTION, '');
        return is_string($key) ? trim($key) : '';
    }

    /**
     * The vendor returns the token either as a bare JSON string or wrapped in a
     * common envelope. Handle both so we're resilient to the response shape.
     *
     * @param mixed $data Decoded JSON body.
     */
    private static function extract_token($data): string
    {
        if (is_string($data)) {
            return trim($data);
        }
        if (is_array($data)) {
            foreach (['token', 'access_token', 'mapbox_token', 'accessToken'] as $key) {
                if (!empty($data[$key]) && is_string($data[$key])) {
                    return trim($data[$key]);
                }
            }
        }
        return '';
    }
}
