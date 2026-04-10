<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Reviews_Rating_Badge extends \Bricks\Element
{
    public $category = 'FFL Funnels - Product Reviews';
    public $name     = 'ffla-reviews-rating-badge';
    public $icon     = 'ti-star';
    public $tag      = 'div';

    public function get_label()
    {
        return esc_html__('Reviews Rating Badge', 'ffl-funnels-addons');
    }

    public function set_controls()
    {
        $this->controls['productId'] = [
            'tab'            => 'content',
            'label'          => esc_html__('Product ID', 'ffl-funnels-addons'),
            'type'           => 'number',
            'hasDynamicData' => true,
            'description'    => esc_html__('Leave empty to use current product context.', 'ffl-funnels-addons'),
        ];

        $this->controls['showCount'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Show review count', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
            'inline'  => true,
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $product_id = !empty($settings['productId']) ? absint($settings['productId']) : get_the_ID();
        if (!$product_id || 'product' !== get_post_type($product_id)) {
            return $this->render_element_placeholder([
                'title' => esc_html__('No product found for rating badge.', 'ffl-funnels-addons'),
            ]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $average = (float) $product->get_average_rating();
        $count   = (int) $product->get_review_count();
        $show_count = !isset($settings['showCount']) || !empty($settings['showCount']);

        $this->set_attribute('_root', 'class', 'ffla-reviews-badge');

        echo '<div ' . $this->render_attributes('_root') . '>';
        echo wp_kses_post(wc_get_rating_html($average, $count));
        if ($show_count) {
            echo '<span class="ffla-reviews-badge__count">(' . esc_html((string) $count) . ')</span>';
        }
        echo '</div>';
    }
}
