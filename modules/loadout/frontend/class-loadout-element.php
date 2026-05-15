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

    public function get_label()
    {
        return esc_html__('Loadout', 'ffl-funnels-addons');
    }

    public function set_controls()
    {
        // Stub for Phase 6
    }

    public function render()
    {
        // Stub for Phase 6
        echo '<div class="ffla-loadout">' . esc_html__('Loadout Widget', 'ffl-funnels-addons') . '</div>';
    }
}
