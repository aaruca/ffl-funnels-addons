<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Bricks\Element')) {
    return;
}

class Loadout_Add_Button_Element extends \Bricks\Element
{
    public $category = 'woocommerce';
    public $name = 'loadout-add-button';
    public $icon = 'ti-shopping-cart';
    public $scripts = ['loadout-frontend'];

    public function get_label()
    {
        return esc_html__('Loadout: Add Item Button', 'ffl-funnels-addons');
    }

    public function set_controls()
    {
        $this->controls['label'] = [
            'tab' => 'content',
            'label' => esc_html__('Label', 'ffl-funnels-addons'),
            'type' => 'text',
            'default' => 'ADD',
        ];

        $this->controls['source'] = [
            'tab' => 'content',
            'label' => esc_html__('Source', 'ffl-funnels-addons'),
            'type' => 'select',
            'options' => [
                'widget' => 'Widget (standalone)',
                'product_tab' => 'Product Tab',
            ],
            'default' => 'widget',
        ];

        $this->controls['product_loadout_id'] = [
            'tab' => 'content',
            'label' => esc_html__('Product Loadout ID (product_tab source)', 'ffl-funnels-addons'),
            'type' => 'text',
            'description' => esc_html__('Use {post_id} for the current product page.', 'ffl-funnels-addons'),
            'default' => '{post_id}',
            'required' => ['source', '=', 'product_tab'],
        ];

        $this->controls['background'] = [
            'tab' => 'style',
            'label' => esc_html__('Background', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => 'background', 'selector' => '']],
        ];

        $this->controls['color'] = [
            'tab' => 'style',
            'label' => esc_html__('Text Color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => 'color', 'selector' => '']],
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $item = Loadout_Bricks::current_loop_object(Loadout_Bricks::QUERY_ITEMS);
        if (!$item instanceof Loadout_Tier_Item) {
            echo '<div ' . $this->render_attributes('_root') . '>' . esc_html__('Place this inside a Loadout Tier Items loop.', 'ffl-funnels-addons') . '</div>';
            return;
        }

        $tier = Loadout_Bricks::current_loop_object(Loadout_Bricks::QUERY_TIERS);
        $loadout = Loadout_Bricks::current_loop_object(Loadout_Bricks::QUERY_LOADOUTS);
        if (!$loadout && $tier instanceof Loadout_Tier) {
            $loadout = Loadout::get($tier->get_loadout_id());
        }

        $product = wc_get_product($item->get_product_id());
        $is_in_stock = $product && $product->is_in_stock();
        $source = $settings['source'] ?? 'widget';
        $label = $settings['label'] ?? 'ADD';

        $product_loadout_id = 0;
        if ($source === 'product_tab') {
            $raw = $settings['product_loadout_id'] ?? '{post_id}';
            if (function_exists('bricks_render_dynamic_data')) {
                $product_loadout_id = absint(bricks_render_dynamic_data($raw, $this->post_id ?? 0));
            } else {
                $product_loadout_id = $raw === '{post_id}' ? (int) get_the_ID() : absint($raw);
            }
        }

        $this->set_attribute('_root', 'class', 'ffla-loadout__add-btn');
        $this->set_attribute('_root', 'type', 'button');
        $this->set_attribute('_root', 'data-product-id', $item->get_product_id());
        $this->set_attribute('_root', 'data-quantity', $item->get_quantity());
        $this->set_attribute('_root', 'data-discount-pct', $item->get_discount_pct());
        $this->set_attribute('_root', 'data-item-id', $item->get_id());
        if ($tier instanceof Loadout_Tier) {
            $this->set_attribute('_root', 'data-tier-id', $tier->get_id());
            $this->set_attribute('_root', 'data-tier-slug', $tier->get_slug());
        }
        if ($loadout instanceof Loadout) {
            $this->set_attribute('_root', 'data-loadout-id', $loadout->get_id());
        }
        $this->set_attribute('_root', 'data-source', $source);
        if ($product_loadout_id) {
            $this->set_attribute('_root', 'data-product-loadout-id', $product_loadout_id);
        }
        if (!$is_in_stock) {
            $this->set_attribute('_root', 'disabled', 'disabled');
            $label = esc_html__('OUT', 'ffl-funnels-addons');
        }

        echo '<button ' . $this->render_attributes('_root') . '>' . esc_html($label) . '</button>';
    }
}
