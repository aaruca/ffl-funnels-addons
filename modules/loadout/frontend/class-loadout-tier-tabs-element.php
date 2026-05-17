<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Bricks\Element')) {
    return;
}

class Loadout_Tier_Tabs_Element extends \Bricks\Element
{
    public $category = 'woocommerce';
    public $name = 'loadout-tier-tabs';
    public $icon = 'ti-layout-tab';
    public $scripts = ['loadout-frontend'];

    public function get_label()
    {
        return esc_html__('Loadout: Tier Tabs', 'ffl-funnels-addons');
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
            'tab'     => 'content',
            'label'   => esc_html__('Default Tier Index', 'ffl-funnels-addons'),
            'type'    => 'number',
            'default' => 0,
        ];
    }

    public function render()
    {
        $settings      = $this->settings;
        $loadout_id    = absint($settings['loadout_id'] ?? 0);
        $default_index = absint($settings['default_tier_index'] ?? 0);

        $tiers_data        = Loadout_Element_Helpers::resolve_tiers_for_current_context($loadout_id);
        $loadout_id_attr   = $tiers_data['loadout_id'];
        $product_loadout_id = $tiers_data['product_loadout_id'];
        $tiers             = $tiers_data['tiers'];

        $this->set_attribute('_root', 'class', 'ffla-loadout__tiers');
        if ($loadout_id_attr) {
            $this->set_attribute('_root', 'data-loadout-id', $loadout_id_attr);
        }
        if ($product_loadout_id) {
            $this->set_attribute('_root', 'data-product-loadout-id', $product_loadout_id);
        }

        if (empty($tiers)) {
            echo '<nav ' . $this->render_attributes('_root') . '>';
            echo '<span class="ffla-loadout__tier-empty">' . esc_html__('No loadout configured for this context.', 'ffl-funnels-addons') . '</span>';
            echo '</nav>';
            return;
        }

        echo '<nav ' . $this->render_attributes('_root') . '>';
        foreach ($tiers as $i => $tier) {
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
    }
}
