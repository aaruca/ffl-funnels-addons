<?php
/**
 * Product Reviews — Simple Cloudflare Turnstile integration.
 *
 * Thin wrapper around the public functions exposed by the
 * "Simple Cloudflare Turnstile" plugin
 * (https://wordpress.org/plugins/simple-cloudflare-turnstile/).
 *
 * Renders the widget on the FFL review form and validates submissions
 * via cfturnstile_check(). When the plugin is not active, all calls
 * become no-ops and submissions proceed without a CAPTCHA challenge.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Turnstile
{
    /** Form id passed to cfturnstile_field_show() to scope the widget. */
    const FORM_ID = 'ffla_product_review';

    /**
     * The Simple Cloudflare Turnstile plugin exposes public helpers we can
     * delegate to. Both must exist before we render or validate anything.
     */
    public static function is_available(): bool
    {
        return function_exists('cfturnstile_field_show')
            && function_exists('cfturnstile_check');
    }

    /**
     * Render the Turnstile widget for the review form.
     *
     * Usage mirrors the plugin's documented signature:
     *     cfturnstile_field_show( $a, $b, $form_id, $rand );
     *
     * @param string $form_id Optional override for the form scope id.
     */
    public static function render_field(string $form_id = self::FORM_ID): void
    {
        if (!self::is_available()) {
            return;
        }

        echo '<div class="ffla-review-turnstile-wrap">';
        cfturnstile_field_show('', '', $form_id, mt_rand());
        echo '</div>';
    }

    /**
     * Validate the Turnstile response in $_POST['cf-turnstile-response'].
     *
     * Returns true when the plugin is missing (so submissions are not
     * blocked when no CAPTCHA is configured) and otherwise defers to the
     * plugin's cfturnstile_check() helper.
     */
    public static function passes(): bool
    {
        if (!self::is_available()) {
            return true;
        }

        $result = cfturnstile_check();
        if (!is_array($result)) {
            return false;
        }

        return !empty($result['success']);
    }
}
