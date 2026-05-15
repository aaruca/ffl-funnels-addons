<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Frontend
{
    public function init(): void
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(): void
    {
        // Always enqueue on product pages (in case product tab is enabled) and any page using loadout.
        if (!is_singular('product') && !$this->page_has_loadout()) {
            // Still enqueue for general use; lightweight enough.
        }

        wp_enqueue_script(
            'loadout-frontend',
            plugins_url('js/loadout.js', __FILE__),
            ['jquery'],
            FFLA_VERSION,
            true
        );

        wp_enqueue_style(
            'loadout-frontend',
            plugins_url('css/loadout.css', __FILE__),
            [],
            FFLA_VERSION
        );

        wp_localize_script('loadout-frontend', 'loadoutFrontend', [
            'nonce' => wp_create_nonce('loadout_frontend'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'cartUrl' => wc_get_cart_url(),
            'strings' => [
                'adding' => __('Adding...', 'ffl-funnels-addons'),
                'added' => __('Added!', 'ffl-funnels-addons'),
                'addError' => __('Could not add item.', 'ffl-funnels-addons'),
                'addedToCart' => __('Added to cart', 'ffl-funnels-addons'),
            ],
        ]);
    }

    private function page_has_loadout(): bool
    {
        global $post;
        if (!$post) {
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
