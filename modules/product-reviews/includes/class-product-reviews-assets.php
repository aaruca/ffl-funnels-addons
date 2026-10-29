<?php
/**
 * Product Reviews Assets.
 *
 * The CSS and JavaScript are enqueued by whatever prints review markup: the
 * Bricks elements from their enqueue_scripts(), the list, form and hub
 * renderers when they run. Only pages known in advance to show reviews load
 * them in <head>. A shop loop with a rating badge gets its styles; a product
 * page without any FFL review output loads nothing.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Assets
{
    const HANDLE = 'ffla-product-reviews';

    /** @var bool */
    private static $script_enqueued = false;

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend_assets']);
    }

    public static function register(): void
    {
        if (wp_style_is(self::HANDLE, 'registered')) {
            return;
        }

        wp_register_style(
            self::HANDLE,
            FFLA_URL . 'modules/product-reviews/assets/css/product-reviews.css',
            [],
            FFLA_VERSION
        );

        wp_register_script(
            self::HANDLE,
            FFLA_URL . 'modules/product-reviews/assets/js/product-reviews.js',
            [],
            FFLA_VERSION,
            true
        );
    }

    /**
     * Safe to call any number of times, before or during the page body. Styles
     * enqueued after <head> are printed in the footer.
     *
     * @param bool $with_script False for output that has nothing interactive (the rating badge).
     */
    public static function enqueue(bool $with_script = true): void
    {
        self::register();
        wp_enqueue_style(self::HANDLE);

        if (!$with_script || self::$script_enqueued) {
            return;
        }

        self::$script_enqueued = true;

        wp_localize_script(self::HANDLE, 'fflaProductReviews', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ffla_product_reviews_nonce'),
            'i18n'    => [
                'voteSaved'     => __('Thanks for your feedback!', 'ffl-funnels-addons'),
                'voteError'     => __('Unable to register your vote.', 'ffl-funnels-addons'),
                'loading'       => __('Loading reviews…', 'ffl-funnels-addons'),
                'loadError'     => __('Reviews could not be loaded. Please try again.', 'ffl-funnels-addons'),
                'clearFilters'  => __('Clear filters', 'ffl-funnels-addons'),
                'lightboxLabel' => __('Review photo', 'ffl-funnels-addons'),
                'close'         => __('Close', 'ffl-funnels-addons'),
                'previous'      => __('Previous photo', 'ffl-funnels-addons'),
                'next'          => __('Next photo', 'ffl-funnels-addons'),
                /* translators: 1: photo number, 2: photos in the review */
                'photoOf'       => __('Photo %1$s of %2$s', 'ffl-funnels-addons'),
            ],
            'fileUpload' => [
                'removeLabel'  => __('Remove file', 'ffl-funnels-addons'),
                'maxCount'     => Product_Reviews_Core::media_max_files(),
                'maxBytes'     => Product_Reviews_Core::media_max_mb() * 1024 * 1024,
                'maxFiles'     => sprintf(
                    /* translators: %d: number of files */
                    _n('You can attach up to %d file.', 'You can attach up to %d files.', Product_Reviews_Core::media_max_files(), 'ffl-funnels-addons'),
                    Product_Reviews_Core::media_max_files()
                ),
                'fileTooLarge' => sprintf(
                    /* translators: %d: size in megabytes */
                    __('Each file must be %d MB or less.', 'ffl-funnels-addons'),
                    Product_Reviews_Core::media_max_mb()
                ),
            ],
        ]);

        wp_enqueue_script(self::HANDLE);

        // Cloudflare Turnstile assets (api.js + widget styling) are loaded
        // by the "Simple Cloudflare Turnstile" plugin when active.
    }

    public static function enqueue_frontend_assets(): void
    {
        self::register();

        if (self::should_enqueue_frontend_assets()) {
            self::enqueue();
        }
    }

    /**
     * Pages whose review output is known before the body renders, so the CSS
     * can go in <head>: the hub page, pages with the shortcode, product pages
     * whose Reviews tab is replaced by the FFL list and form, and the Bricks
     * editor. Everything else is enqueued by the output itself.
     *
     * @see 'ffla_product_reviews_enqueue_assets' Force enqueue in <head> (e.g. custom templates).
     */
    private static function should_enqueue_frontend_assets(): bool
    {
        if (apply_filters('ffla_product_reviews_enqueue_assets', false)) {
            return true;
        }

        // Bricks: load in the builder so Product Reviews elements preview correctly (avoid bricks_is_builder_call on frontend).
        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return true;
        }

        if (function_exists('is_product') && is_product()
            && '1' === Product_Reviews_Core::get_setting('replace_default_reviews_tab', '0')) {
            return true;
        }

        $hub_page = absint(Product_Reviews_Core::get_setting('order_review_page_id', '0'));
        if ($hub_page > 0 && function_exists('is_page') && is_page($hub_page)) {
            return true;
        }

        if (function_exists('is_singular') && is_singular()) {
            $post = get_post();
            if ($post && isset($post->post_content) && has_shortcode((string) $post->post_content, 'ffla_order_reviews')) {
                return true;
            }
        }

        return false;
    }
}
