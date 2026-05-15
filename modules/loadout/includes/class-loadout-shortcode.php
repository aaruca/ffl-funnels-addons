<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Shortcode
{
    public static function init(): void
    {
        add_shortcode('loadout', [__CLASS__, 'render']);
    }

    public static function render($atts): string
    {
        $atts = shortcode_atts([
            'id' => 0,
            'slug' => '',
            'default_tier' => 0,
            'show_cart' => 'yes',
            'show_cross_sells' => 'yes',
        ], $atts, 'loadout');

        $loadout = null;
        if (!empty($atts['slug'])) {
            $loadout = Loadout::get_by_slug($atts['slug']);
        } elseif (!empty($atts['id'])) {
            $loadout = Loadout::get(absint($atts['id']));
        }

        if (!$loadout || !$loadout->get_status()) {
            return '';
        }

        $loadout_id = $loadout->get_id();
        $tiers = Loadout_Tier::get_by_loadout($loadout_id);
        if (empty($tiers)) {
            return '';
        }

        $cross_sells = Loadout_Cross_Sell::get_by_loadout($loadout_id);
        $anchor_id = $loadout->get_anchor_product_id();
        $anchor_product = $anchor_id ? wc_get_product($anchor_id) : null;
        $hero_url = $loadout->get_hero_image_id() ? wp_get_attachment_image_url($loadout->get_hero_image_id(), 'full') : '';
        $brand_logo_url = $loadout->get_brand_logo_id() ? wp_get_attachment_image_url($loadout->get_brand_logo_id(), 'medium') : '';
        $default_index = absint($atts['default_tier']);
        $show_cart = $atts['show_cart'] !== 'no';
        $show_cross_sells = $atts['show_cross_sells'] !== 'no';

        ob_start();
        ?>
        <div class="ffla-loadout" data-loadout-id="<?php echo esc_attr($loadout_id); ?>">
            <?php
            // Reuse rendering by instantiating element if available, else inline render.
            if (class_exists('Loadout_Element')) {
                // The element class has the renderer but it's private; call via helper here.
                self::render_inline($loadout, $tiers, $cross_sells, $anchor_product, $hero_url, $brand_logo_url, $default_index, $show_cart, $show_cross_sells);
            } else {
                self::render_inline($loadout, $tiers, $cross_sells, $anchor_product, $hero_url, $brand_logo_url, $default_index, $show_cart, $show_cross_sells);
            }
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static function render_inline($loadout, $tiers, $cross_sells, $anchor_product, $hero_url, $brand_logo_url, $default_index, $show_cart, $show_cross_sells): void
    {
        ?>
        <header class="ffla-loadout__header">
            <?php if ($brand_logo_url): ?>
                <img class="ffla-loadout__brand" src="<?php echo esc_url($brand_logo_url); ?>" alt="">
            <?php endif; ?>
            <h2 class="ffla-loadout__title"><?php echo esc_html($loadout->get_headline() ?: $loadout->get_name()); ?></h2>
            <?php if ($loadout->get_subheadline()): ?>
                <p class="ffla-loadout__subtitle"><?php echo esc_html($loadout->get_subheadline()); ?></p>
            <?php endif; ?>
        </header>

        <div class="ffla-loadout__progress">
            <div class="ffla-loadout__progress-track">
                <div class="ffla-loadout__progress-bar" style="width:0%;"></div>
            </div>
            <span class="ffla-loadout__progress-label"></span>
        </div>

        <nav class="ffla-loadout__tiers">
            <?php foreach ($tiers as $i => $tier): ?>
                <button type="button"
                        class="ffla-loadout__tier-btn<?php echo $i === $default_index ? ' is-active' : ''; ?>"
                        data-tier-slug="<?php echo esc_attr($tier->get_slug()); ?>"
                        data-tier-id="<?php echo esc_attr($tier->get_id()); ?>"
                        aria-selected="<?php echo $i === $default_index ? 'true' : 'false'; ?>">
                    <?php echo esc_html($tier->get_name()); ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <div class="ffla-loadout__body">
            <aside class="ffla-loadout__anchor">
                <?php if ($anchor_product): ?>
                    <?php if ($hero_url): ?>
                        <img class="ffla-loadout__hero" src="<?php echo esc_url($hero_url); ?>" alt="">
                    <?php else: ?>
                        <div class="ffla-loadout__hero-fallback"><?php echo $anchor_product->get_image('medium'); ?></div>
                    <?php endif; ?>
                    <h3 class="ffla-loadout__anchor-name"><?php echo esc_html($anchor_product->get_name()); ?></h3>
                    <div class="ffla-loadout__anchor-price"><?php echo $anchor_product->get_price_html(); ?></div>
                    <button type="button" class="ffla-loadout__add-btn"
                            data-product-id="<?php echo esc_attr($anchor_product->get_id()); ?>"
                            data-quantity="1"
                            data-discount-pct="0">
                        <?php esc_html_e('ADD HERO', 'ffl-funnels-addons'); ?>
                    </button>
                <?php endif; ?>
            </aside>

            <section class="ffla-loadout__recommended">
                <?php foreach ($tiers as $i => $tier): ?>
                    <div class="ffla-loadout__panel<?php echo $i === $default_index ? ' is-active' : ''; ?>"
                         data-tier-slug="<?php echo esc_attr($tier->get_slug()); ?>"
                         data-tier-id="<?php echo esc_attr($tier->get_id()); ?>"
                         data-threshold="<?php echo esc_attr($tier->get_threshold_items()); ?>">

                        <h3 class="ffla-loadout__panel-title">
                            <?php
                            printf(
                                esc_html__('Recommended %s Setup', 'ffl-funnels-addons'),
                                esc_html($tier->get_name())
                            );
                            ?>
                        </h3>

                        <ul class="ffla-loadout__items">
                            <?php foreach ($tier->get_items() as $item):
                                $p = wc_get_product($item->get_product_id());
                                if (!$p) continue;
                                $regular_price = (float) $p->get_regular_price();
                                if ($regular_price <= 0) $regular_price = (float) $p->get_price();
                                $combined_discount = min(100, (float) $item->get_discount_pct() + (float) $tier->get_accessory_discount());
                                $final_price = $combined_discount > 0 ? $regular_price * (1 - $combined_discount / 100) : $regular_price;
                                $is_in_stock = $p->is_in_stock();
                            ?>
                                <li class="ffla-loadout__item<?php echo $is_in_stock ? '' : ' is-oos'; ?>">
                                    <div class="ffla-loadout__item-thumb"><?php echo $p->get_image('thumbnail'); ?></div>
                                    <div class="ffla-loadout__item-info">
                                        <h4 class="ffla-loadout__item-name"><?php echo esc_html($p->get_name()); ?></h4>
                                        <?php if ($combined_discount > 0): ?>
                                            <span class="ffla-loadout__badge"><?php echo esc_html(round($combined_discount)); ?>% OFF</span>
                                        <?php endif; ?>
                                        <div class="ffla-loadout__item-price">
                                            <?php if ($combined_discount > 0): ?>
                                                <s><?php echo wc_price($regular_price); ?></s>
                                            <?php endif; ?>
                                            <strong><?php echo wc_price($final_price); ?></strong>
                                        </div>
                                    </div>
                                    <button type="button" class="ffla-loadout__add-btn"
                                            data-product-id="<?php echo esc_attr($p->get_id()); ?>"
                                            data-quantity="<?php echo esc_attr($item->get_quantity()); ?>"
                                            data-discount-pct="<?php echo esc_attr($item->get_discount_pct()); ?>"
                                            data-item-id="<?php echo esc_attr($item->get_id()); ?>"
                                            <?php disabled(!$is_in_stock); ?>>
                                        <?php echo $is_in_stock ? esc_html__('ADD', 'ffl-funnels-addons') : esc_html__('OUT', 'ffl-funnels-addons'); ?>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <button type="button" class="ffla-loadout__add-tier-btn"
                                data-tier-id="<?php echo esc_attr($tier->get_id()); ?>"
                                data-tier-slug="<?php echo esc_attr($tier->get_slug()); ?>">
                            <?php esc_html_e('ADD CART', 'ffl-funnels-addons'); ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </section>

            <?php if ($show_cart): ?>
                <aside class="ffla-loadout__cart">
                    <h3><?php esc_html_e('Your Cart', 'ffl-funnels-addons'); ?></h3>
                    <div class="ffla-loadout__cart-summary"></div>
                </aside>
            <?php endif; ?>
        </div>

        <?php if ($show_cross_sells && !empty($cross_sells)): ?>
            <section class="ffla-loadout__cross-sells">
                <h3><?php esc_html_e('Complete Your Loadout', 'ffl-funnels-addons'); ?></h3>
                <div class="ffla-loadout__cross-sells-grid">
                    <?php foreach ($cross_sells as $cs):
                        $cs_image = $cs->get_image_id() ? wp_get_attachment_image($cs->get_image_id(), 'medium') : '';
                    ?>
                        <a href="#" class="ffla-loadout__cross-sell-tile">
                            <?php if ($cs_image): ?>
                                <?php echo $cs_image; ?>
                            <?php endif; ?>
                            <span><?php echo esc_html($cs->get_label()); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <footer class="ffla-loadout__checkout">
            <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="ffla-loadout__checkout-btn">
                <?php esc_html_e('PROCEED TO CHECKOUT', 'ffl-funnels-addons'); ?>
            </a>
        </footer>
        <?php
    }
}
