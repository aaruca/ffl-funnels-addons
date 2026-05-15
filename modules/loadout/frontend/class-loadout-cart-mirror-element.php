<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\Bricks\Element')) {
    return;
}

class Loadout_Cart_Mirror_Element extends \Bricks\Element
{
    public $category = 'woocommerce';
    public $name = 'loadout-cart-mirror';
    public $icon = 'ti-shopping-cart-full';
    public $scripts = ['loadout-frontend'];

    public function get_label()
    {
        return esc_html__('Loadout: Cart Mirror', 'ffl-funnels-addons');
    }

    public function set_controls()
    {
        $loadout_options = ['' => '— All Loadouts —'];
        foreach (Loadout::get_all(['status' => 1]) as $l) {
            $loadout_options[$l->get_id()] = $l->get_name();
        }

        $this->controls['loadout_id'] = [
            'tab' => 'content',
            'label' => esc_html__('Filter by Loadout', 'ffl-funnels-addons'),
            'type' => 'select',
            'options' => $loadout_options,
        ];

        $this->controls['heading'] = [
            'tab' => 'content',
            'label' => esc_html__('Heading', 'ffl-funnels-addons'),
            'type' => 'text',
            'default' => 'Your Cart',
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $loadout_id = absint($settings['loadout_id'] ?? 0);
        $heading = $settings['heading'] ?? 'Your Cart';

        $this->set_attribute('_root', 'class', 'ffla-loadout ffla-loadout--cart-only');
        $this->set_attribute('_root', 'data-loadout-id', $loadout_id);

        ?>
        <div <?php echo $this->render_attributes('_root'); ?>>
            <aside class="ffla-loadout__cart">
                <h3><?php echo esc_html($heading); ?></h3>
                <div class="ffla-loadout__cart-summary">
                    <p><?php esc_html_e('Loading cart...', 'ffl-funnels-addons'); ?></p>
                </div>
            </aside>
        </div>
        <?php
    }
}
