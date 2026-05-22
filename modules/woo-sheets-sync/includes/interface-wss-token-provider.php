<?php
/**
 * WSS_Token_Provider — Common contract for Google access-token sources.
 *
 * Implemented by WSS_Google_OAuth (user consent via proxy) and
 * WSS_Google_Service_Account (server-to-server JWT). WSS_Google_Sheets depends
 * on this interface rather than a concrete provider so either can drive the API.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

interface WSS_Token_Provider
{
    /**
     * A valid OAuth2 bearer access token, or WP_Error on failure.
     *
     * @return string|WP_Error
     */
    public function get_access_token();

    /**
     * Whether usable credentials are present.
     */
    public function is_connected(): bool;

    /**
     * Identity of the connection (user email for OAuth, service-account email for SA).
     */
    public function get_user_email(): string;
}
