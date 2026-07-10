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
 * every checkout page load.
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
     * Drop the cached borrowed token (e.g. after a failed map load).
     */
    public static function flush_cache(): void
    {
        delete_transient(self::BORROW_TRANSIENT);
    }

    /**
     * Fetch a Mapbox token from the g-FFL Checkout vendor, cached.
     */
    private static function borrow_token(): string
    {
        $cached = get_transient(self::BORROW_TRANSIENT);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $api_key = self::ffl_api_key();
        if ($api_key === '') {
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
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return '';
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return '';
        }

        $data  = json_decode(wp_remote_retrieve_body($response), true);
        $token = self::extract_token($data);
        if ($token === '') {
            return '';
        }

        set_transient(self::BORROW_TRANSIENT, $token, self::BORROW_TTL);
        return $token;
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
