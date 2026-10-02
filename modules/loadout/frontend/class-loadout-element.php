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
    public $category = 'FFL Funnels';
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
            'description' => esc_html__('Leave empty to auto-pick based on the current product\'s Loadout settings. Inactive loadouts are not shown.', 'ffl-funnels-addons'),
        ];

        $this->controls['default_tier_index'] = [
            'tab'     => 'content',
            'label'       => esc_html__('Default Tier Index', 'ffl-funnels-addons'),
            'type'        => 'number',
            'default'     => 0,
            'min'         => 0,
            'description' => esc_html__('Tier shown first, counting from 0. Falls back to the first tier when it does not exist.', 'ffl-funnels-addons'),
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

        if (class_exists('Loadout_Frontend')) {
            Loadout_Frontend::enqueue();
        }

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

        if ($loadout) {
            Loadout_Element_Helpers::render_header($loadout);
        }

        Loadout_Element_Helpers::render_tabs($tiers, $default_index);

        // Body: main product column + recommended products + cart panel.
        // On a product page the product being viewed is the main item (it is
        // what Add cart puts in the cart); elsewhere the loadout's hero product.
        echo '<div class="ffla-loadout__body">';

        if ($loadout && (!$product_loadout_id || (int) $loadout->get_anchor_product_id() === $product_loadout_id)) {
            Loadout_Element_Helpers::render_loadout_anchor($loadout);
        } elseif ($product_loadout_id) {
            Loadout_Element_Helpers::render_product_anchor($product_loadout_id);
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
            Loadout_Element_Helpers::render_cross_sells($loadout_id);
        }

        // Checkout footer.
        echo '<footer class="ffla-loadout__checkout">';
        echo '<a href="' . esc_url(wc_get_checkout_url()) . '" class="ffla-loadout__checkout-btn">'
            . esc_html__('PROCEED TO CHECKOUT', 'ffl-funnels-addons')
            . '</a>';
        echo '</footer>';

        echo '</div>';
    }
}
