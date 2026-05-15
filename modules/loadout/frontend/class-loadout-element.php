<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Bricks\Element')) {
    return;
}

class Loadout_Element extends \Bricks\Element
{
    public $category = 'woocommerce';
    public $name = 'loadout';
    public $icon = 'ti-layout-list';
    public $css_selector = '.ffla-loadout';
    public $scripts = ['loadout-frontend'];

    public function get_label()
    {
        return esc_html__('Loadout', 'ffl-funnels-addons');
    }

    public function set_controls()
    {
        $this->controls['loadout_id'] = [
            'tab' => 'content',
            'label' => esc_html__('Loadout', 'ffl-funnels-addons'),
            'type' => 'select',
            'options' => $this->get_loadout_options(),
            'placeholder' => esc_html__('Select a loadout', 'ffl-funnels-addons'),
        ];

        $this->controls['default_tier_index'] = [
            'tab' => 'content',
            'label' => esc_html__('Default Tier Index', 'ffl-funnels-addons'),
            'type' => 'number',
            'default' => 0,
            'min' => 0,
            'description' => esc_html__('0 = first tier, 1 = second tier, etc.', 'ffl-funnels-addons'),
        ];

        $this->controls['show_cart_panel'] = [
            'tab' => 'content',
            'label' => esc_html__('Show Cart Panel', 'ffl-funnels-addons'),
            'type' => 'checkbox',
            'default' => true,
        ];

        $this->controls['show_cross_sells'] = [
            'tab' => 'content',
            'label' => esc_html__('Show Cross-Sells', 'ffl-funnels-addons'),
            'type' => 'checkbox',
            'default' => true,
        ];

        $this->controls['accent_color'] = [
            'tab' => 'style',
            'label' => esc_html__('Accent Color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [
                ['property' => '--ffla-loadout-accent', 'selector' => ''],
            ],
        ];

        $this->controls['bg_color'] = [
            'tab' => 'style',
            'label' => esc_html__('Background Color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [
                ['property' => 'background', 'selector' => ''],
            ],
        ];
    }

    private function get_loadout_options(): array
    {
        $loadouts = Loadout::get_all(['status' => 1]);
        $options = [];
        foreach ($loadouts as $loadout) {
            $options[$loadout->get_id()] = $loadout->get_name();
        }
        return $options;
    }

    public function render()
    {
        $settings = $this->settings;
        $loadout_id = isset($settings['loadout_id']) ? absint($settings['loadout_id']) : 0;

        if (!$loadout_id) {
            echo '<div ' . $this->render_attributes('_root') . '>';
            echo '<p>' . esc_html__('No loadout selected. Choose a loadout in the element settings.', 'ffl-funnels-addons') . '</p>';
            echo '</div>';
            return;
        }

        $loadout = Loadout::get($loadout_id);
        if (!$loadout) {
            echo '<div ' . $this->render_attributes('_root') . '>';
            echo '<p>' . esc_html__('Loadout not found.', 'ffl-funnels-addons') . '</p>';
            echo '</div>';
            return;
        }

        $tiers = Loadout_Tier::get_by_loadout($loadout_id);
        if (empty($tiers)) {
            echo '<div ' . $this->render_attributes('_root') . '>';
            echo '<p>' . esc_html__('This loadout has no tiers configured.', 'ffl-funnels-addons') . '</p>';
            echo '</div>';
            return;
        }

        $default_index = isset($settings['default_tier_index']) ? absint($settings['default_tier_index']) : 0;
        $show_cart = !isset($settings['show_cart_panel']) || $settings['show_cart_panel'];
        $show_cross_sells = !isset($settings['show_cross_sells']) || $settings['show_cross_sells'];

        $cross_sells = Loadout_Cross_Sell::get_by_loadout($loadout_id);
        $anchor_id = $loadout->get_anchor_product_id();
        $anchor_product = $anchor_id ? wc_get_product($anchor_id) : null;
        $hero_url = $loadout->get_hero_image_id() ? wp_get_attachment_image_url($loadout->get_hero_image_id(), 'full') : '';
        $brand_logo_url = $loadout->get_brand_logo_id() ? wp_get_attachment_image_url($loadout->get_brand_logo_id(), 'medium') : '';

        $this->set_attribute('_root', 'class', 'ffla-loadout');
        $this->set_attribute('_root', 'data-loadout-id', $loadout_id);

        echo '<div ' . $this->render_attributes('_root') . '>';
        $this->render_widget_content($loadout, $tiers, $cross_sells, $anchor_product, $hero_url, $brand_logo_url, $default_index, $show_cart, $show_cross_sells);
        echo '</div>';
    }

    private function render_widget_content($loadout, $tiers, $cross_sells, $anchor_product, $hero_url, $brand_logo_url, $default_index, $show_cart, $show_cross_sells): void
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
                    <button type="button" class="ffla-loadout__add-btn ffla-loadout__add-anchor"
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
                                if (!$p) {
                                    continue;
                                }
                                $regular_price = (float) $p->get_regular_price();
                                if ($regular_price <= 0) {
                                    $regular_price = (float) $p->get_price();
                                }
                                $combined_discount = (float) $item->get_discount_pct() + (float) $tier->get_accessory_discount();
                                $combined_discount = min(100, $combined_discount);
                                $final_price = $combined_discount > 0 ? $regular_price * (1 - $combined_discount / 100) : $regular_price;
                                $is_in_stock = $p->is_in_stock();
                            ?>
                                <li class="ffla-loadout__item<?php echo $is_in_stock ? '' : ' is-oos'; ?>">
                                    <div class="ffla-loadout__item-thumb"><?php echo $p->get_image('thumbnail'); ?></div>
                                    <div class="ffla-loadout__item-info">
                                        <h4 class="ffla-loadout__item-name"><?php echo esc_html($p->get_name()); ?></h4>
                                        <?php if ($p->get_average_rating()): ?>
                                            <div class="ffla-loadout__item-rating">
                                                <?php echo wc_get_rating_html($p->get_average_rating()); ?>
                                            </div>
                                        <?php endif; ?>
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

                        <?php $perks = $tier->get_perks(); ?>
                        <?php if (!empty($perks)): ?>
                            <div class="ffla-loadout__perks">
                                <h5><?php esc_html_e('Perks Unlocked at Threshold:', 'ffl-funnels-addons'); ?></h5>
                                <ul>
                                    <?php foreach ($perks as $perk): ?>
                                        <li><?php echo esc_html($perk); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($tier->get_bonus_product_id()): ?>
                            <div class="ffla-loadout__bonus">
                                <strong>
                                    <?php echo esc_html($tier->get_bonus_label() ?: __('FREE Bonus Item', 'ffl-funnels-addons')); ?>
                                </strong>
                                <?php if ($tier->get_bonus_display_value()): ?>
                                    <span class="ffla-loadout__bonus-value">
                                        <?php printf(
                                            esc_html__('Valued at %s', 'ffl-funnels-addons'),
                                            wc_price($tier->get_bonus_display_value())
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>

            <?php if ($show_cart): ?>
                <aside class="ffla-loadout__cart">
                    <h3><?php esc_html_e('Your Cart', 'ffl-funnels-addons'); ?></h3>
                    <div class="ffla-loadout__cart-summary">
                        <p><?php esc_html_e('Loading cart...', 'ffl-funnels-addons'); ?></p>
                    </div>
                </aside>
            <?php endif; ?>
        </div>

        <?php if ($show_cross_sells && !empty($cross_sells)): ?>
            <section class="ffla-loadout__cross-sells">
                <h3><?php esc_html_e('Complete Your Loadout', 'ffl-funnels-addons'); ?></h3>
                <div class="ffla-loadout__cross-sells-grid">
                    <?php foreach ($cross_sells as $cs):
                        $cs_image = $cs->get_image_id() ? wp_get_attachment_image($cs->get_image_id(), 'medium') : '';
                        $cs_link = self::resolve_cross_sell_link($cs);
                    ?>
                        <a href="<?php echo esc_url($cs_link); ?>" class="ffla-loadout__cross-sell-tile">
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

    private static function resolve_cross_sell_link($cs): string
    {
        $type = $cs->get_link_type();
        $value = $cs->get_link_value();
        if (!$value) {
            return '#';
        }
        switch ($type) {
            case 'category':
                $term = get_term_by('slug', $value, 'product_cat');
                return $term ? get_term_link($term) : '#';
            case 'url':
                return esc_url_raw($value);
            case 'loadout':
                $loadout = Loadout::get_by_slug($value);
                return $loadout ? '#loadout-' . $loadout->get_id() : '#';
            default:
                return '#';
        }
    }
}
