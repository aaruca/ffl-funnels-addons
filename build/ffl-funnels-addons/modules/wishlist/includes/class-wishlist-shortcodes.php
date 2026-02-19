<?php

/**
 * Shortcodes Handler
 * 
 * Registers [alg_wishlist_button] and [alg_wishlist_count].
 *
 * @package    Algenib_Wishlist
 * @subpackage Algenib_Wishlist/includes/shortcodes
 */

class Alg_Wishlist_Shortcodes
{

    public function register_shortcodes()
    {
        add_shortcode('alg_wishlist_button', array($this, 'render_button'));
        add_shortcode('alg_wishlist_count', array($this, 'render_count'));
        add_shortcode('alg_wishlist_page', array($this, 'render_page'));
    }

    public function render_button($atts)
    {
        $atts = shortcode_atts(array(
            'product_id' => get_the_ID(),
            'class' => '',
            'text' => '',
            'color' => '',
            'active_color' => '',
            'hover_color' => '',
            'icon' => '' // Pass raw SVG or 'heart'
        ), $atts);

        $id = intval($atts['product_id']);
        if (!$id)
            return '';

        $style = '';
        if (!empty($atts['color'])) {
            $style .= '--alg-btn-color: ' . esc_attr($atts['color']) . ';';
        }
        if (!empty($atts['active_color'])) {
            $style .= '--alg-btn-active-color: ' . esc_attr($atts['active_color']) . ';';
        }
        if (!empty($atts['hover_color'])) {
            $style .= '--alg-btn-hover-color: ' . esc_attr($atts['hover_color']) . ';';
        }

        $icon_html = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';

        if (!empty($atts['icon'])) {
            // Allow basic sanitization but keep SVG structure if passed directly
            // For security, usually, we'd limit this, but let's assume admin trust or simple class name
            // If it's a known icon set class, use <i>, else if it looks like SVG, output it?
            // Safer: Just assume it's replacement SVG content provided correctly.
            // For now, let's keep the default heart if empty, or render the passed content if it contains <svg
            if (strpos($atts['icon'], '<svg') !== false) {
                $icon_html = $atts['icon']; // Trust admin shortcode input
            }
        }

        ob_start();
        ?>
        <button type="button" class="alg-add-to-wishlist <?php echo esc_attr($atts['class']); ?>"
            data-product-id="<?php echo esc_attr($id); ?>" style="<?php echo esc_attr($style); ?>"
            aria-label="<?php esc_attr_e('Add to Wishlist', 'algenib-wishlist'); ?>">

            <?php echo $icon_html; ?>

            <?php if (!empty($atts['text'])): ?>
                <span class="alg-btn-text">
                    <?php echo esc_html($atts['text']); ?>
                </span>
            <?php endif; ?>

        </button>
        <?php
        return ob_get_clean();
    }

    public function render_count($atts)
    {
        $atts = shortcode_atts(array(
            'class' => '',
            'color' => '',
            'icon_color' => '',
            'icon' => 'heart'
        ), $atts);

        $style = '';
        if (!empty($atts['color'])) {
            $style .= 'color: ' . esc_attr($atts['color']) . ';';
        }

        $icon_style = '';
        if (!empty($atts['icon_color'])) {
            $icon_style .= 'color: ' . esc_attr($atts['icon_color']) . '; stroke: ' . esc_attr($atts['icon_color']) . ';';
        }

        $icon_html = '';
        if ($atts['icon'] === 'heart' || empty($atts['icon'])) {
            $icon_html = '<svg class="alg-count-icon" style="' . esc_attr($icon_style) . '" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';
        }

        ob_start();
        ?>
        <a href="<?php echo esc_url(get_permalink(Alg_Wishlist_Core::get_wishlist_page_id())); ?>"
            class="alg-wishlist-counter-link <?php echo esc_attr($atts['class']); ?>" style="<?php echo esc_attr($style); ?>">
            <?php echo $icon_html; ?>
            <span class="alg-wishlist-count hidden">0</span>
        </a>
        <?php
        return ob_get_clean();
    }

    public function render_page($atts)
    {
        $items = Alg_Wishlist_Core::get_wishlist_items();

        if (empty($items)) {
            return '<p class="alg-wishlist-empty">' . esc_html__('Your wishlist is empty.', 'algenib-wishlist') . '</p>';
        }

        ob_start();
        ?>
        <div class="alg-wishlist-grid">
            <?php if (empty($items)): ?>
                <div class="alg-wishlist-empty">
                    <p><?php esc_html_e('Your wishlist is currently empty.', 'algenib-wishlist'); ?></p>
                    <a href="<?php echo esc_url(get_permalink(wc_get_page_id('shop'))); ?>" class="button alg-return-shop">
                        <?php esc_html_e('Return to Shop', 'algenib-wishlist'); ?>
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($items as $product_id):
                    $product = wc_get_product($product_id);
                    if (!$product)
                        continue;
                    ?>
                    <div class="alg-wishlist-card" data-product-id="<?php echo esc_attr($product_id); ?>">
                        <div class="alg-card-image">
                            <a href="<?php echo esc_url($product->get_permalink()); ?>">
                                <?php echo $product->get_image('woocommerce_thumbnail'); ?>
                            </a>
                            <button type="button" class="alg-remove-btn" data-product-id="<?php echo esc_attr($product_id); ?>"
                                aria-label="<?php esc_attr_e('Remove', 'algenib-wishlist'); ?>">
                                &times;
                            </button>
                        </div>
                        <div class="alg-card-details">
                            <h3 class="alg-card-title">
                                <a href="<?php echo esc_url($product->get_permalink()); ?>"><?php echo $product->get_name(); ?></a>
                            </h3>
                            <div class="alg-card-price">
                                <?php echo $product->get_price_html(); ?>
                            </div>
                            <div class="alg-card-actions">
                                <a href="<?php echo esc_url($product->add_to_cart_url()); ?>" class="button alg-add-cart-btn"
                                    data-product_id="<?php echo esc_attr($product_id); ?>" data-quantity="1">
                                    <?php esc_html_e('Add to Cart', 'algenib-wishlist'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

}
