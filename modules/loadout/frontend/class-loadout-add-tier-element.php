<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Bricks\Element')) {
    return;
}

class Loadout_Add_Tier_Element extends \Bricks\Element
{
    public $category = 'woocommerce';
    public $name = 'loadout-add-tier-button';
    public $icon = 'ti-package';
    public $scripts = ['loadout-frontend'];

    public function get_label()
    {
        return esc_html__('Loadout: Add Tier Button', 'ffl-funnels-addons');
    }

    public function set_controls()
    {
        $this->controls['label'] = [
            'tab' => 'content',
            'label' => esc_html__('Label', 'ffl-funnels-addons'),
            'type' => 'text',
            'default' => 'ADD CART',
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
            'label' => esc_html__('Product Loadout ID', 'ffl-funnels-addons'),
            'type' => 'text',
            'default' => '{post_id}',
            'required' => ['source', '=', 'product_tab'],
        ];
    }

    public function render()
    {
        $tier = Loadout_Bricks::current_loop_object(Loadout_Bricks::QUERY_TIERS);
        if (!$tier instanceof Loadout_Tier) {
            echo '<div ' . $this->render_attributes('_root') . '>' . esc_html__('Place this inside a Loadout Tiers loop.', 'ffl-funnels-addons') . '</div>';
            return;
        }

        $settings = $this->settings;
        $source = $settings['source'] ?? 'widget';
        $product_loadout_id = 0;
        if ($source === 'product_tab') {
            $raw = $settings['product_loadout_id'] ?? '{post_id}';
            if (function_exists('bricks_render_dynamic_data')) {
                $product_loadout_id = absint(bricks_render_dynamic_data($raw, $this->post_id ?? 0));
            } else {
                $product_loadout_id = $raw === '{post_id}' ? (int) get_the_ID() : absint($raw);
            }
        }

        $this->set_attribute('_root', 'class', 'ffla-loadout__add-tier-btn');
        $this->set_attribute('_root', 'type', 'button');
        $this->set_attribute('_root', 'data-tier-id', $tier->get_id());
        $this->set_attribute('_root', 'data-tier-slug', $tier->get_slug());
        $this->set_attribute('_root', 'data-loadout-id', $tier->get_loadout_id());
        $this->set_attribute('_root', 'data-source', $source);
        if ($product_loadout_id) {
            $this->set_attribute('_root', 'data-product-loadout-id', $product_loadout_id);
        }

        echo '<button ' . $this->render_attributes('_root') . '>' . esc_html($settings['label'] ?? 'ADD CART') . '</button>';
    }
}
