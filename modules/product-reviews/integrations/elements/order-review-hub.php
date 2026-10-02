<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Order_Review_Hub extends \Bricks\Element
{
    public $category = 'FFL Funnels';
    public $name     = 'ffla-order-review-hub';
    public $icon     = 'ti-layout-list-post';
    public $tag      = 'div';

    public function get_label()
    {
        return esc_html__('Order reviews hub', 'ffl-funnels-addons');
    }

    public function set_controls()
    {
        $this->controls['introTitle'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Intro title (optional)', 'ffl-funnels-addons'),
            'type'        => 'text',
            'placeholder' => esc_html__('Leave empty to use default intro only.', 'ffl-funnels-addons'),
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $this->set_attribute('_root', 'class', 'ffla-order-review-hub-wrap');
        echo '<div ' . $this->render_attributes('_root') . '>';
        echo \Product_Reviews_Order_Hub::render_hub(false, is_array($settings) ? $settings : []); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }
}
