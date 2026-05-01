<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Reviews_List extends \Bricks\Element
{
    public $category = 'FFL Funnels';
    public $name     = 'ffla-reviews-list';
    public $icon     = 'ti-comment-alt';
    public $tag      = 'div';

    public function get_label()
    {
        return esc_html__('Reviews List', 'ffl-funnels-addons');
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

        $this->controls['perPage'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Max reviews', 'ffl-funnels-addons'),
            'type'    => 'number',
            'default' => 5,
            'min'     => 1,
            'max'     => 50,
        ];

        $this->controls['orderBy'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Order by', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                'recent'  => esc_html__('Most recent', 'ffl-funnels-addons'),
                'helpful' => esc_html__('Most helpful', 'ffl-funnels-addons'),
            ],
            'default' => 'recent',
        ];

        $this->controls['starFilledColor'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Filled stars color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'default' => '#d4a017',
            'css'     => [['property' => '--ffla-star-filled', 'selector' => '']],
        ];

        $this->controls['starEmptyColor'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Empty stars color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'default' => '#d8dce5',
            'css'     => [['property' => '--ffla-star-empty', 'selector' => '']],
        ];

        $this->controls['starSize'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Star size', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'default' => '16px',
            'css'     => [['property' => 'font-size', 'selector' => '.ffla-stars']],
        ];

        $this->controls['cardBg'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['cardBorder'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card border', 'ffl-funnels-addons'),
            'type'  => 'border',
            'css'   => [['property' => 'border', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['cardRadius'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card border radius', 'ffl-funnels-addons'),
            'type'  => 'number',
            'units' => true,
            'css'   => [['property' => 'border-radius', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['cardPadding'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card padding', 'ffl-funnels-addons'),
            'type'  => 'spacing',
            'css'   => [['property' => 'padding', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['listGap'] = [
            'tab'   => 'content',
            'label' => esc_html__('List gap', 'ffl-funnels-addons'),
            'type'  => 'number',
            'units' => true,
            'css'   => [['property' => 'gap', 'selector' => '']],
        ];

        $this->controls['authorTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Author typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-review-card__author']],
        ];

        $this->controls['dateTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Date typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-review-card__date']],
        ];

        $this->controls['contentTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Review text typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-review-card__content']],
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $explicit = !empty($settings['productId']) ? absint($settings['productId']) : 0;
        $product_id = \Product_Reviews_Core::resolve_context_product_id($explicit);
        if ($product_id <= 0) {
            return $this->render_element_placeholder([
                'title' => esc_html__('No product found for reviews list.', 'ffl-funnels-addons'),
            ]);
        }

        $per_page = !empty($settings['perPage']) ? absint($settings['perPage']) : 5;
        $per_page = max(1, min(50, $per_page));
        $list_settings = [
            'perPage' => $per_page,
            'orderBy' => $settings['orderBy'] ?? 'recent',
        ];

        $this->set_attribute('_root', 'class', 'ffla-reviews-list');

        echo '<div ' . $this->render_attributes('_root') . '>';
        \Product_Reviews_Frontend_Render::render_reviews_list($product_id, $list_settings, false);
        echo '</div>';
    }
}
