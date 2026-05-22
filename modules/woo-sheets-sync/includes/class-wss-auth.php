<?php
/**
 * WSS Auth — Selects the active Google token provider.
 *
 * Prefers a configured service account (the maintenance-free path) and falls
 * back to the proxy OAuth flow when none is present.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Auth
{
    /**
     * Resolve the provider the sync engine should authenticate with.
     */
    public static function get_provider(): WSS_Token_Provider
    {
        if (class_exists('WSS_Google_Service_Account')) {
            $settings = get_option('wss_settings', []);
            $mode     = (string) ($settings['auth_mode'] ?? '');

            $forced_by_constant = (defined('WSS_SERVICE_ACCOUNT_JSON') && WSS_SERVICE_ACCOUNT_JSON !== '')
                || (defined('WSS_SERVICE_ACCOUNT_FILE') && WSS_SERVICE_ACCOUNT_FILE !== '');

            if ($mode === 'service_account' || $forced_by_constant) {
                $sa = new WSS_Google_Service_Account();
                if ($sa->is_connected()) {
                    return $sa;
                }
            }
        }

        return new WSS_Google_OAuth();
    }
}
