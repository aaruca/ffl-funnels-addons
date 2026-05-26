<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Bricks\Element')) {
    return;
}

class Loadout_Products_Element extends \Bricks\Element
{
    public $category = 'woocommerce';
    public $name = 'loadout-products';
    public $icon = 'ti-package';
    public $scripts = ['loadout-frontend'];

    public function get_label()
    {
        return esc_html__('Loadout: Products', 'ffl-funnels-addons');
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
            'description' => esc_html__('Leave empty to auto-pick based on the current product\'s Loadout settings (linked global Loadout or per-product config).', 'ffl-funnels-addons'),
        ];

        $this->controls['default_tier_index'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Default Tier Index', 'ffl-funnels-addons'),
            'type'        => 'number',
            'default'     => 0,
            'description' => esc_html__('Which tier panel is visible first. Pair this element with a "Loadout: Tier Tabs" element (with its products turned off) to switch between panels.', 'ffl-funnels-addons'),
        ];
    }

    public function render()
    {
        $settings      = $this->settings;
        $loadout_id    = absint($settings['loadout_id'] ?? 0);
        $default_index = absint($settings['default_tier_index'] ?? 0);

        $data               = Loadout_Element_Helpers::resolve_full_tiers_for_current_context($loadout_id);
        $loadout_id_attr    = $data['loadout_id'];
        $product_loadout_id = $data['product_loadout_id'];
        $tiers              = $data['tiers'];

        $this->set_attribute('_root', 'class', 'ffla-loadout ffla-loadout--products-only');
        if ($loadout_id_attr) {
            $this->set_attribute('_root', 'data-loadout-id', $loadout_id_attr);
        }
        if ($product_loadout_id) {
            $this->set_attribute('_root', 'data-product-loadout-id', $product_loadout_id);
        }

        echo '<div ' . $this->render_attributes('_root') . '>';
        Loadout_Element_Helpers::render_recommended_section($tiers, $default_index);
        echo '</div>';
    }
}
