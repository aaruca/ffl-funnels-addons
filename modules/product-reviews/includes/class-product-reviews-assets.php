<?php
/**
 * Product Reviews Assets.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Assets
{
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend_assets']);
    }

    public static function enqueue_frontend_assets(): void
    {
        if (!self::should_enqueue_frontend_assets()) {
            return;
        }

        wp_enqueue_style(
            'ffla-product-reviews',
            FFLA_URL . 'modules/product-reviews/assets/css/product-reviews.css',
            [],
            FFLA_VERSION
        );

        wp_enqueue_script(
            'ffla-product-reviews',
            FFLA_URL . 'modules/product-reviews/assets/js/product-reviews.js',
            [],
            FFLA_VERSION,
            true
        );

        wp_localize_script('ffla-product-reviews', 'fflaProductReviews', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ffla_product_reviews_nonce'),
            'i18n'    => [
                'voteSaved' => __('Thanks for your feedback!', 'ffl-funnels-addons'),
                'voteError' => __('Unable to register your vote.', 'ffl-funnels-addons'),
            ],
        ]);

        if (Product_Reviews_Core::is_turnstile_enabled()) {
            wp_enqueue_script(
                'ffla-cloudflare-turnstile',
                'https://challenges.cloudflare.com/turnstile/v0/api.js',
                [],
                null,
                true
            );
        }
    }

    /**
     * Load assets only where reviews UI is typically needed (single product by default).
     * Use filter {@see 'ffla_product_reviews_enqueue_assets'} to force-load (e.g. Bricks on non-product templates).
     */
    private static function should_enqueue_frontend_assets(): bool
    {
        if (apply_filters('ffla_product_reviews_enqueue_assets', false)) {
            return true;
        }

        if (function_exists('is_product') && is_product()) {
            return true;
        }

        return false;
    }
}
