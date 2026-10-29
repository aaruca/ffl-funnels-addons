<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Product_Qna extends \Bricks\Element
{
    public $category = 'FFL Funnels';
    public $name     = 'ffla-product-qna';
    public $icon     = 'ti-help-alt';
    public $tag      = 'div';

    public function get_label()
    {
        return esc_html__('Product Q&A', 'ffl-funnels-addons');
    }

    public function enqueue_scripts()
    {
        \Product_Reviews_Assets::enqueue();
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

        $this->controls['title'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Title', 'ffl-funnels-addons'),
            'type'        => 'text',
            'placeholder' => esc_html__('Questions & Answers', 'ffl-funnels-addons'),
        ];

        $this->controls['perPage'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Questions per page', 'ffl-funnels-addons'),
            'type'        => 'number',
            'min'         => 1,
            'max'         => 50,
            'placeholder' => 5,
        ];

        $this->controls['showSearch'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Search box', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''     => esc_html__('Use module setting', 'ffl-funnels-addons'),
                'show' => esc_html__('Show', 'ffl-funnels-addons'),
                'hide' => esc_html__('Hide', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['showForm'] = [
            'tab'     => 'content',
            'label'   => esc_html__('“Ask a question” form', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''     => esc_html__('Show', 'ffl-funnels-addons'),
                'hide' => esc_html__('Hide', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['itemBorder'] = [
            'tab'   => 'style',
            'label' => esc_html__('Question border', 'ffl-funnels-addons'),
            'type'  => 'border',
            'css'   => [['property' => 'border', 'selector' => '.ffla-qna-item']],
        ];

        $this->controls['itemBackground'] = [
            'tab'   => 'style',
            'label' => esc_html__('Question background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.ffla-qna-item']],
        ];

        $this->controls['questionTypography'] = [
            'tab'   => 'style',
            'label' => esc_html__('Question typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-qna-item__text']],
        ];

        $this->controls['answerTypography'] = [
            'tab'   => 'style',
            'label' => esc_html__('Answer typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-qna-answer__text']],
        ];
    }

    public function render()
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        if (!\Product_Reviews_Qna::enabled()) {
            return $this->render_element_placeholder([
                'title' => esc_html__('Product Q&A is switched off (Product Reviews → Settings → Q&A).', 'ffl-funnels-addons'),
            ]);
        }

        $explicit   = !empty($settings['productId']) ? absint($settings['productId']) : 0;
        $product_id = \Product_Reviews_Core::resolve_context_product_id($explicit);
        if ($product_id <= 0) {
            return $this->render_element_placeholder([
                'title' => esc_html__('No product found for Q&A.', 'ffl-funnels-addons'),
            ]);
        }

        $args = [
            'title'    => isset($settings['title']) ? (string) $settings['title'] : '',
            'perPage'  => isset($settings['perPage']) ? absint($settings['perPage']) : 0,
            'showForm' => 'hide' !== ($settings['showForm'] ?? ''),
        ];
        if (!empty($settings['showSearch'])) {
            $args['showSearch'] = 'show' === $settings['showSearch'];
        }

        $this->set_attribute('_root', 'class', 'ffla-qna');
        $this->set_attribute('_root', 'id', 'qna');

        echo '<div ' . $this->render_attributes('_root') . '>';
        \Product_Reviews_Qna::render($product_id, $args, false);
        echo '</div>';
    }
}
