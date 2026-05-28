<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Bricks\Element')) {
    return;
}

/**
 * Legacy monolithic "loadout" element.
 *
 * Kept registered (name = 'loadout') so Bricks templates saved before the
 * v1.33.0 single-product refactor keep rendering. Rendering is driven through
 * Loadout_Element_Helpers so it shows the same tier/products UI as the newer
 * composable elements, and it auto-detects the current product's loadout when
 * no explicit loadout is selected.
 */
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
        $loadout_options = ['' => esc_html__('— Auto-detect from current product —', 'ffl-funnels-addons')];
        foreach (Loadout::get_all(['status' => 1]) as $l) {
            $loadout_options[$l->get_id()] = $l->get_name();
        }

        $this->controls['loadout_id'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Loadout', 'ffl-funnels-addons'),
            'type'        => 'select',
            'options'     => $loadout_options,
            'description' => esc_html__('Leave empty to auto-pick based on the current product\'s Loadout settings.', 'ffl-funnels-addons'),
        ];

        $this->controls['default_tier_index'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Default Tier Index', 'ffl-funnels-addons'),
            'type'    => 'number',
            'default' => 0,
            'min'     => 0,
        ];

        $this->controls['show_cart_panel'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Show Cart Panel', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['show_cross_sells'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Show Cross-Sells', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['accent_color'] = [
            'tab'   => 'style',
            'label' => esc_html__('Accent Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [
                ['property' => '--ffla-loadout-accent', 'selector' => ''],
            ],
        ];

        $this->controls['bg_color'] = [
            'tab'   => 'style',
            'label' => esc_html__('Background Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [
                ['property' => 'background', 'selector' => ''],
            ],
        ];
    }

    public function render()
    {
        $settings         = $this->settings;
        $explicit_id      = isset($settings['loadout_id']) ? absint($settings['loadout_id']) : 0;
        $default_index    = isset($settings['default_tier_index']) ? absint($settings['default_tier_index']) : 0;
        $show_cart        = !isset($settings['show_cart_panel']) || $settings['show_cart_panel'];
        $show_cross_sells = !isset($settings['show_cross_sells']) || $settings['show_cross_sells'];

        $data               = Loadout_Element_Helpers::resolve_full_tiers_for_current_context($explicit_id);
        $loadout_id         = $data['loadout_id'];
        $product_loadout_id = $data['product_loadout_id'];
        $tiers              = $data['tiers'];

        // Global Loadout object backs the branding/anchor/cross-sells (only set
        // when this context resolves to a global loadout, not per-product tiers).
        $loadout = $loadout_id ? Loadout::get($loadout_id) : null;

        $this->set_attribute('_root', 'class', 'ffla-loadout');
        if ($loadout_id) {
            $this->set_attribute('_root', 'data-loadout-id', $loadout_id);
        }
        if ($product_loadout_id) {
            $this->set_attribute('_root', 'data-product-loadout-id', $product_loadout_id);
        }

        echo '<div ' . $this->render_attributes('_root') . '>';

        if (empty($tiers)) {
            echo '<p class="ffla-loadout__tier-empty">'
                . esc_html__('No loadout configured for this context.', 'ffl-funnels-addons')
                . '</p>';
            echo '</div>';
            return;
        }

        // Header / branding (only when a global loadout backs the context).
        if ($loadout) {
            $brand_logo_url = $loadout->get_brand_logo_id()
                ? wp_get_attachment_image_url($loadout->get_brand_logo_id(), 'medium')
                : '';
            echo '<header class="ffla-loadout__header">';
            if ($brand_logo_url) {
                echo '<img class="ffla-loadout__brand" src="' . esc_url($brand_logo_url) . '" alt="">';
            }
            echo '<h2 class="ffla-loadout__title">' . esc_html($loadout->get_headline() ?: $loadout->get_name()) . '</h2>';
            if ($loadout->get_subheadline()) {
                echo '<p class="ffla-loadout__subtitle">' . esc_html($loadout->get_subheadline()) . '</p>';
            }
            echo '</header>';
        }

        // Progress bar.
        echo '<div class="ffla-loadout__progress">'
            . '<div class="ffla-loadout__progress-track"><div class="ffla-loadout__progress-bar" style="width:0%;"></div></div>'
            . '<span class="ffla-loadout__progress-label"></span>'
            . '</div>';

        // Tier navigation.
        echo '<nav class="ffla-loadout__tiers">';
        foreach (array_values($tiers) as $i => $tier) {
            $active = $i === $default_index;
            printf(
                '<button type="button" class="ffla-loadout__tier-btn%s" data-tier-slug="%s" data-tier-id="%d" aria-selected="%s">%s</button>',
                $active ? ' is-active' : '',
                esc_attr($tier['slug']),
                esc_attr($tier['id']),
                $active ? 'true' : 'false',
                esc_html($tier['name'])
            );
        }
        echo '</nav>';

        // Body: anchor (global loadout OR current product) + recommended products + cart panel.
        echo '<div class="ffla-loadout__body">';

        if ($loadout) {
            $this->render_anchor($loadout);
        } elseif ($product_loadout_id) {
            $this->render_product_anchor($product_loadout_id);
        }

        Loadout_Element_Helpers::render_recommended_section($tiers, $default_index);

        if ($show_cart) {
            echo '<aside class="ffla-loadout__cart">';
            echo '<h3>' . esc_html__('Your Cart', 'ffl-funnels-addons') . '</h3>';
            echo '<div class="ffla-loadout__cart-summary"><p>' . esc_html__('Loading cart...', 'ffl-funnels-addons') . '</p></div>';
            echo '</aside>';
        }

        echo '</div>';

        // Cross-sells (global only).
        if ($show_cross_sells && $loadout) {
            $this->render_cross_sells($loadout_id);
        }

        // Checkout footer.
        echo '<footer class="ffla-loadout__checkout">';
        echo '<a href="' . esc_url(wc_get_checkout_url()) . '" class="ffla-loadout__checkout-btn">'
            . esc_html__('PROCEED TO CHECKOUT', 'ffl-funnels-addons')
            . '</a>';
        echo '</footer>';

        echo '</div>';
    }

    private function render_anchor(Loadout $loadout): void
    {
        $anchor_id      = $loadout->get_anchor_product_id();
        $anchor_product = $anchor_id ? wc_get_product($anchor_id) : null;
        $hero_url       = $loadout->get_hero_image_id()
            ? wp_get_attachment_image_url($loadout->get_hero_image_id(), 'full')
            : '';

        echo '<aside class="ffla-loadout__anchor">';
        if ($anchor_product) {
            if ($hero_url) {
                echo '<img class="ffla-loadout__hero" src="' . esc_url($hero_url) . '" alt="">';
            } else {
                echo '<div class="ffla-loadout__hero-fallback">' . $anchor_product->get_image('medium') . '</div>';
            }
            echo '<h3 class="ffla-loadout__anchor-name">' . esc_html($anchor_product->get_name()) . '</h3>';
            echo '<div class="ffla-loadout__anchor-price">' . $anchor_product->get_price_html() . '</div>';
            printf(
                '<button type="button" class="ffla-loadout__add-btn ffla-loadout__add-anchor" data-product-id="%d" data-quantity="1" data-discount-pct="0">%s</button>',
                esc_attr($anchor_product->get_id()),
                esc_html__('ADD HERO', 'ffl-funnels-addons')
            );
        }
        echo '</aside>';
    }

    /**
     * Anchor card for the product-page context (no global loadout backing it).
     * Shows the current product's image, name and price as the first column.
     */
    private function render_product_anchor(int $product_id): void
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        echo '<aside class="ffla-loadout__anchor">';
        echo '<div class="ffla-loadout__hero-fallback">' . $product->get_image('medium') . '</div>';
        echo '<h3 class="ffla-loadout__anchor-name">' . esc_html($product->get_name()) . '</h3>';
        echo '<div class="ffla-loadout__anchor-price">' . $product->get_price_html() . '</div>';
        echo '</aside>';
    }

    private function render_cross_sells(int $loadout_id): void
    {
        $cross_sells = Loadout_Cross_Sell::get_by_loadout($loadout_id);
        if (empty($cross_sells)) {
            return;
        }

        echo '<section class="ffla-loadout__cross-sells">';
        echo '<h3>' . esc_html__('Complete Your Loadout', 'ffl-funnels-addons') . '</h3>';
        echo '<div class="ffla-loadout__cross-sells-grid">';
        foreach ($cross_sells as $cs) {
            $cs_image = $cs->get_image_id() ? wp_get_attachment_image($cs->get_image_id(), 'medium') : '';
            $cs_link  = self::resolve_cross_sell_link($cs);
            echo '<a href="' . esc_url($cs_link) . '" class="ffla-loadout__cross-sell-tile">';
            if ($cs_image) {
                echo $cs_image;
            }
            echo '<span>' . esc_html($cs->get_label()) . '</span>';
            echo '</a>';
        }
        echo '</div>';
        echo '</section>';
    }

    private static function resolve_cross_sell_link($cs): string
    {
        $type  = $cs->get_link_type();
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
