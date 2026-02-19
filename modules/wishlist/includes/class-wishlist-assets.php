<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assets Handler
 *
 * Enqueues Frontend JS and CSS.
 *
 * @package FFL_Funnels_Addons
 */

class Alg_Wishlist_Assets
{

    public function enqueue_scripts()
    {
        // CSS
        wp_enqueue_style('alg-wishlist-css', ALG_WISHLIST_URL . 'assets/css/algenib-wishlist.css', array(), ALG_WISHLIST_VERSION);

        // JS
        wp_enqueue_script('alg-wishlist-js', ALG_WISHLIST_URL . 'assets/js/algenib-wishlist.js', array(), ALG_WISHLIST_VERSION, true);

        // Localize
        $items = Alg_Wishlist_Core::get_wishlist_items();

        wp_localize_script('alg-wishlist-js', 'AlgWishlistSettings', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('alg_wishlist_nonce'),
            'initial_items' => $items, // Pass PHP state to JS on load
            'i18n' => array(
                'added' => __('Added to Wishlist', 'algenib-wishlist'),
                'removed' => __('Removed from Wishlist', 'algenib-wishlist'),
                'text_add' => __('Add to wishlist', 'algenib-wishlist'),
                'text_remove' => __('Remove from wishlist', 'algenib-wishlist')
            )
        ));

        $options = get_option('alg_wishlist_settings');
        $primary = $this->sanitize_css_color(isset($options['alg_wishlist_color_primary']) ? $options['alg_wishlist_color_primary'] : '#ff4b4b');
        $hover   = $this->sanitize_css_color(isset($options['alg_wishlist_color_hover']) ? $options['alg_wishlist_color_hover'] : '#ff0000');
        $active  = $this->sanitize_css_color(isset($options['alg_wishlist_color_active']) ? $options['alg_wishlist_color_active'] : '#cc0000');
        $custom  = isset($options['alg_wishlist_custom_css']) ? wp_strip_all_tags($options['alg_wishlist_custom_css']) : '';

        $custom_css = "
            :root {
                --alg-btn-color: {$primary};
                --alg-btn-hover-color: {$hover};
                --alg-btn-active-color: {$active};

                --alg-wishlist-primary: {$primary};
                --alg-wishlist-active: {$active};
            }
            .alg-wishlist-btn svg { stroke: var(--alg-wishlist-primary); }
            .alg-wishlist-btn.active svg { fill: var(--alg-wishlist-active); stroke: var(--alg-wishlist-active); }
            {$custom}
            
            /* Toast Notification */
            .alg-wishlist-toast {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) scale(0.9);
                background: rgba(33, 33, 33, 0.95);
                color: #fff;
                padding: 20px 40px;
                border-radius: 8px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                z-index: 99999;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
                font-size: 16px;
                font-weight: 500;
                text-align: center;
                backdrop-filter: blur(5px);
                border: 1px solid rgba(255,255,255,0.1);
            }
            .alg-wishlist-toast.show {
                opacity: 1;
                visibility: visible;
                transform: translate(-50%, -50%) scale(1);
            }

            /* Wishlist Grid & Cards */
            .alg-wishlist-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
                gap: 20px;
                margin-top: 20px;
            }
            .alg-wishlist-card {
                background: #fff;
                border: 1px solid #eee;
                border-radius: 8px;
                overflow: hidden;
                transition: transform 0.2s, box-shadow 0.2s;
                position: relative;
            }
            .alg-wishlist-card:hover {
                transform: translateY(-3px);
                box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            }
            .alg-card-image {
                position: relative;
                padding-bottom: 100%; /* 1:1 Aspect Ratio */
                overflow: hidden;
                background: #f9f9f9;
            }
            .alg-card-image img {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            .alg-remove-btn {
                position: absolute;
                top: 10px;
                right: 10px;
                background: rgba(255,255,255,0.9);
                border: none;
                width: 28px;
                height: 28px;
                border-radius: 50%;
                line-height: 28px;
                text-align: center;
                cursor: pointer;
                color: #ff4b4b;
                font-size: 18px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
                padding: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.2s;
            }
            .alg-remove-btn:hover {
                background: #ff4b4b;
                color: #fff;
            }
            .alg-card-details {
                padding: 15px;
                text-align: center;
            }
            .alg-card-title {
                font-size: 15px;
                margin: 0 0 8px;
                line-height: 1.3;
            }
            .alg-card-title a {
                text-decoration: none;
                color: inherit;
            }
            .alg-card-price {
                font-weight: bold;
                color: #333;
                margin-bottom: 12px;
            }
            .alg-card-actions .button {
                width: 100%;
                font-size: 13px;
                padding: 8px 0;
            }
            .alg-wishlist-empty {
                grid-column: 1 / -1;
                text-align: center;
                padding: 40px;
                background: #f8f8f8;
                border-radius: 8px;
                color: #666;
            }

        ";

        wp_add_inline_style('alg-wishlist-css', $custom_css);
    }

    /**
     * Sanitize a CSS color value. Allows hex, rgb(), rgba(), hsl(), hsla(), and named colors.
     */
    private function sanitize_css_color(string $color): string
    {
        $color = trim($color);

        // Allow hex colors.
        if (preg_match('/^#([0-9a-fA-F]{3}){1,2}$/', $color)) {
            return $color;
        }

        // Allow rgb/rgba/hsl/hsla with safe characters only.
        if (preg_match('/^(rgb|rgba|hsl|hsla)\(\s*[\d\s%,.\\/]+\)$/i', $color)) {
            return $color;
        }

        // Allow CSS named colors (single word, letters only).
        if (preg_match('/^[a-zA-Z]+$/', $color)) {
            return $color;
        }

        return '#ff4b4b'; // Safe fallback.
    }
}
