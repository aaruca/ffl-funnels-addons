<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Bricks\Element')) {
    return;
}

class Loadout_Progress_Element extends \Bricks\Element
{
    public $category = 'woocommerce';
    public $name = 'loadout-progress';
    public $icon = 'ti-stats-up';
    public $scripts = ['loadout-frontend'];

    public function get_label()
    {
        return esc_html__('Loadout: Progress Bar', 'ffl-funnels-addons');
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

        $this->controls['placeholder_label'] = [
            'tab' => 'content',
            'label' => esc_html__('Placeholder Label', 'ffl-funnels-addons'),
            'type' => 'text',
            'default' => 'Add items to unlock perks',
        ];

        $this->controls['bar_color'] = [
            'tab' => 'style',
            'label' => esc_html__('Bar Color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => 'background', 'selector' => '.ffla-loadout__progress-bar']],
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $loadout_id = absint($settings['loadout_id'] ?? 0);
        $placeholder = $settings['placeholder_label'] ?? 'Add items to unlock perks';

        $this->set_attribute('_root', 'class', 'ffla-loadout ffla-loadout--progress-only');
        $this->set_attribute('_root', 'data-loadout-id', $loadout_id);

        ?>
        <div <?php echo $this->render_attributes('_root'); ?>>
            <div class="ffla-loadout__progress">
                <div class="ffla-loadout__progress-track">
                    <div class="ffla-loadout__progress-bar" style="width:0%;"></div>
                </div>
                <span class="ffla-loadout__progress-label"><?php echo esc_html($placeholder); ?></span>
            </div>
        </div>
        <?php
    }
}
