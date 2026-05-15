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
        $loadout_options = ['' => '— Select —'];
        foreach (Loadout::get_all(['status' => 1]) as $l) {
            $loadout_options[$l->get_id()] = $l->get_name();
        }

        $this->controls['loadout_id'] = [
            'tab' => 'content',
            'label' => esc_html__('Loadout', 'ffl-funnels-addons'),
            'type' => 'select',
            'options' => $loadout_options,
        ];

        $this->controls['default_tier_index'] = [
            'tab' => 'content',
            'label' => esc_html__('Default Tier Index', 'ffl-funnels-addons'),
            'type' => 'number',
            'default' => 0,
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $loadout_id = absint($settings['loadout_id'] ?? 0);
        $default_index = absint($settings['default_tier_index'] ?? 0);

        if (!$loadout_id) {
            echo '<div ' . $this->render_attributes('_root') . '>' . esc_html__('Select a loadout in the element settings.', 'ffl-funnels-addons') . '</div>';
            return;
        }

        $tiers = Loadout_Tier::get_by_loadout($loadout_id);
        if (empty($tiers)) {
            echo '<div ' . $this->render_attributes('_root') . '>' . esc_html__('This loadout has no tiers.', 'ffl-funnels-addons') . '</div>';
            return;
        }

        $this->set_attribute('_root', 'class', 'ffla-loadout__tiers');

        echo '<nav ' . $this->render_attributes('_root') . '>';
        foreach ($tiers as $i => $tier) {
            $active = $i === $default_index;
            printf(
                '<button type="button" class="ffla-loadout__tier-btn%s" data-tier-slug="%s" data-tier-id="%d" aria-selected="%s">%s</button>',
                $active ? ' is-active' : '',
                esc_attr($tier->get_slug()),
                esc_attr($tier->get_id()),
                $active ? 'true' : 'false',
                esc_html($tier->get_name())
            );
        }
        echo '</nav>';
    }
}
