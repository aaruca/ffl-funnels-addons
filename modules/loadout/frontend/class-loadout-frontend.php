<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Front-end assets for the Loadout elements and the [loadout] shortcode.
 *
 * The script and styles are registered on every page but only enqueued where
 * a loadout can appear: product pages, posts whose content holds the
 * shortcode or the widget markup, and the Bricks builder. Elements and the
 * shortcode also call enqueue() when they render, so a loadout placed
 * anywhere else (a Bricks template on a landing page) still gets them.
 */
class Loadout_Frontend
{
    const HANDLE = 'loadout-frontend';

    public function init(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void
    {
        self::register();

        if (is_singular('product') || $this->page_has_loadout() || self::is_bricks_builder()) {
            self::enqueue();
        }
    }

    /**
     * Register the script, its data and the stylesheet (once).
     */
    public static function register(): void
    {
        if (wp_script_is(self::HANDLE, 'registered')) {
            return;
        }

        wp_register_script(
            self::HANDLE,
            plugins_url('js/loadout.js', __FILE__),
            ['jquery'],
            FFLA_VERSION,
            true
        );

        wp_register_style(
            self::HANDLE,
            plugins_url('css/loadout.css', __FILE__),
            [],
            FFLA_VERSION
        );

        wp_localize_script(self::HANDLE, 'loadoutFrontend', [
            'nonce'   => wp_create_nonce('loadout_frontend'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'cartUrl' => wc_get_cart_url(),
            'strings' => [
                'adding'      => __('Adding...', 'ffl-funnels-addons'),
                'added'       => __('Added!', 'ffl-funnels-addons'),
                'addError'    => __('Could not add item.', 'ffl-funnels-addons'),
                'addedToCart' => __('Added to cart', 'ffl-funnels-addons'),
                'emptyCart'   => __('Your cart is empty.', 'ffl-funnels-addons'),
                'savings'     => __('Savings:', 'ffl-funnels-addons'),
                'total'       => __('Total:', 'ffl-funnels-addons'),
                /* translators: %d: number of items still needed */
                'moreToUnlock' => __('%d more item(s) to unlock perks', 'ffl-funnels-addons'),
                'unlocked'    => __('Perks unlocked!', 'ffl-funnels-addons'),
            ],
        ]);
    }

    /**
     * Enqueue the assets. Safe to call while the page renders: the script is
     * printed in the footer and a late stylesheet is printed there too.
     */
    public static function enqueue(): void
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        if (!did_action('wp_enqueue_scripts') && !doing_action('wp_enqueue_scripts')) {
            // Too early to register; enqueue_assets() will run later anyway.
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 20);
            return;
        }
        self::register();
        wp_enqueue_script(self::HANDLE);
        wp_enqueue_style(self::HANDLE);
    }

    private static function is_bricks_builder(): bool
    {
        return (function_exists('bricks_is_builder') && bricks_is_builder())
            || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());
    }

    private function page_has_loadout(): bool
    {
        global $post;
        if (!$post || !isset($post->post_content)) {
            return false;
        }
        if (has_shortcode($post->post_content, 'loadout')) {
            return true;
        }
        if (false !== strpos($post->post_content, 'ffla-loadout')) {
            return true;
        }
        return false;
    }
}
