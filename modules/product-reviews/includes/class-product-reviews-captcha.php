<?php
/**
 * CAPTCHA on the review and Q&A forms (Review form tab).
 *
 * - `plugin` (default): Cloudflare Turnstile through the Simple Cloudflare
 *   Turnstile plugin, when it is active, as before.
 * - `turnstile`, `hcaptcha`, `recaptcha_v2`, `recaptcha_v3`: the store's own
 *   site and secret keys, checked against the provider's verify endpoint.
 * - `none`.
 *
 * Signed review links (the hub) never get a challenge: the link is the proof.
 * A token is verified once per request (providers refuse a second check).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Captcha
{
    const PROVIDERS = ['plugin', 'turnstile', 'hcaptcha', 'recaptcha_v2', 'recaptcha_v3', 'none'];

    /** @var bool|null This request's result for the store's own keys. */
    private static $passed = null;

    public static function provider(): string
    {
        $provider = (string) Product_Reviews_Core::get_setting('captcha_provider', 'plugin');

        return in_array($provider, self::PROVIDERS, true) ? $provider : 'plugin';
    }

    private static function site_key(): string
    {
        return trim((string) Product_Reviews_Core::get_setting('captcha_site_key', ''));
    }

    private static function secret_key(): string
    {
        return trim((string) Product_Reviews_Core::get_setting('captcha_secret_key', ''));
    }

    /**
     * A challenge will be shown and checked.
     */
    public static function is_available(): bool
    {
        $provider = self::provider();

        if ('none' === $provider) {
            return false;
        }

        if ('plugin' === $provider) {
            return class_exists('Product_Reviews_Turnstile') && Product_Reviews_Turnstile::is_available();
        }

        return self::site_key() !== '' && self::secret_key() !== '';
    }

    /**
     * The provider also protects WordPress's own comment form, and checks it
     * itself (only the Turnstile plugin can).
     */
    public static function protects_native_comments(): bool
    {
        return 'plugin' === self::provider() && class_exists('Product_Reviews_Turnstile') && Product_Reviews_Turnstile::protects_native_comments();
    }

    public static function label(): string
    {
        $labels = [
            'plugin'       => __('Cloudflare Turnstile (Simple Cloudflare Turnstile plugin)', 'ffl-funnels-addons'),
            'turnstile'    => __('Cloudflare Turnstile', 'ffl-funnels-addons'),
            'hcaptcha'     => __('hCaptcha', 'ffl-funnels-addons'),
            'recaptcha_v2' => __('Google reCAPTCHA v2 (checkbox)', 'ffl-funnels-addons'),
            'recaptcha_v3' => __('Google reCAPTCHA v3 (invisible)', 'ffl-funnels-addons'),
            'none'         => __('None', 'ffl-funnels-addons'),
        ];

        return $labels[self::provider()] ?? '';
    }

    /* ---------------------------------------------------------------------
     * Widget
     * ------------------------------------------------------------------- */

    private static function enqueue_script(): void
    {
        $provider = self::provider();
        $urls     = [
            'turnstile'    => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'hcaptcha'     => 'https://js.hcaptcha.com/1/api.js',
            'recaptcha_v2' => 'https://www.google.com/recaptcha/api.js',
            'recaptcha_v3' => 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode(self::site_key()),
        ];
        if (!isset($urls[$provider])) {
            return;
        }

        // The providers' own scripts; version null so their URL stays as they publish it.
        wp_enqueue_script('ffla-review-captcha-' . $provider, $urls[$provider], [], null, true); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
    }

    /**
     * The challenge inside a form. reCAPTCHA v3 has no widget: the module's
     * script asks for a token when the form is sent.
     */
    public static function render_field(string $form_id = 'ffla_product_review'): void
    {
        if (!self::is_available()) {
            return;
        }

        $provider = self::provider();

        if ('plugin' === $provider) {
            Product_Reviews_Turnstile::render_field($form_id);

            return;
        }

        self::enqueue_script();
        $key = self::site_key();

        echo '<div class="ffla-review-turnstile-wrap ffla-review-captcha">';
        switch ($provider) {
            case 'turnstile':
                echo '<div class="cf-turnstile" data-sitekey="' . esc_attr($key) . '" data-action="' . esc_attr($form_id) . '"></div>';
                break;
            case 'hcaptcha':
                echo '<div class="h-captcha" data-sitekey="' . esc_attr($key) . '"></div>';
                break;
            case 'recaptcha_v2':
                echo '<div class="g-recaptcha" data-sitekey="' . esc_attr($key) . '"></div>';
                break;
            case 'recaptcha_v3':
                echo '<input type="hidden" name="g-recaptcha-response" value="" data-ffla-recaptcha-v3="' . esc_attr($key) . '" data-action="' . esc_attr(preg_replace('/[^A-Za-z0-9_]/', '_', $form_id)) . '">';
                echo '<p class="ffla-review-captcha__note">' . esc_html__('This form is protected by reCAPTCHA.', 'ffl-funnels-addons') . '</p>';
                break;
        }
        echo '</div>';
    }

    /* ---------------------------------------------------------------------
     * Verification
     * ------------------------------------------------------------------- */

    /**
     * True when no challenge is set up (nothing to check) or the posted
     * token is valid.
     */
    public static function passes(): bool
    {
        if (!self::is_available()) {
            return true;
        }

        if ('plugin' === self::provider()) {
            return Product_Reviews_Turnstile::passes();
        }

        if (self::$passed === null) {
            self::$passed = self::verify();
        }

        return self::$passed;
    }

    public static function reset(): void
    {
        self::$passed = null;
    }

    private static function verify(): bool
    {
        $provider = self::provider();
        $fields   = [
            'turnstile'    => ['cf-turnstile-response', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'],
            'hcaptcha'     => ['h-captcha-response', 'https://api.hcaptcha.com/siteverify'],
            'recaptcha_v2' => ['g-recaptcha-response', 'https://www.google.com/recaptcha/api/siteverify'],
            'recaptcha_v3' => ['g-recaptcha-response', 'https://www.google.com/recaptcha/api/siteverify'],
        ];
        if (!isset($fields[$provider])) {
            return true;
        }

        [$field, $endpoint] = $fields[$provider];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the token is the check.
        $token = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        if ($token === '' || strlen($token) > 4096) {
            return false;
        }

        $response = wp_safe_remote_post($endpoint, [
            'timeout' => 10,
            'body'    => [
                'secret'   => self::secret_key(),
                'response' => $token,
                'remoteip' => class_exists('Product_Reviews_Ajax') ? Product_Reviews_Ajax::client_ip() : '',
            ],
        ]);

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    sprintf('CAPTCHA check (%s) could not reach the provider.', $provider),
                    ['source' => 'ffla-product-reviews']
                );
            }

            return false;
        }

        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['success'])) {
            return false;
        }

        if ('recaptcha_v3' === $provider) {
            $threshold = (float) Product_Reviews_Core::get_setting('captcha_v3_threshold', '0.5');

            return isset($body['score']) && (float) $body['score'] >= $threshold;
        }

        return true;
    }
}
