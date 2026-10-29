<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Store_Rating_Badge extends \Bricks\Element
{
    public $category = 'FFL Funnels';
    public $name     = 'ffla-store-rating-badge';
    public $icon     = 'ti-medall';
    public $tag      = 'div';

    public function get_label()
    {
        return esc_html__('Store Rating Badge', 'ffl-funnels-addons');
    }

    public function enqueue_scripts()
    {
        \Product_Reviews_Assets::enqueue(false);
    }

    public function set_controls()
    {
        $this->controls['style'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Style', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''        => esc_html__('Card (label, stars, details)', 'ffl-funnels-addons'),
                'compact' => esc_html__('Compact (one line)', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['theme'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Theme', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''     => esc_html__('Light', 'ffl-funnels-addons'),
                'dark' => esc_html__('Dark', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['label'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Label', 'ffl-funnels-addons'),
            'type'        => 'text',
            'placeholder' => esc_html__('Customer reviews', 'ffl-funnels-addons'),
        ];

        // Selects, not checkboxes: Bricks drops an unchecked checkbox's key.
        $this->controls['showCount'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Review count', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''     => esc_html__('Show', 'ffl-funnels-addons'),
                'hide' => esc_html__('Hide', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['showVerified'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Verified buyers share', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''     => esc_html__('Show', 'ffl-funnels-addons'),
                'hide' => esc_html__('Hide', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['hideIfEmpty'] = [
            'tab'     => 'content',
            'label'   => esc_html__('With no reviews yet', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                ''     => esc_html__('Show 0.0', 'ffl-funnels-addons'),
                'hide' => esc_html__('Hide the badge', 'ffl-funnels-addons'),
            ],
            'default' => '',
        ];

        $this->controls['link'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Link', 'ffl-funnels-addons'),
            'type'        => 'link',
            'description' => esc_html__('For example your page with a store-wide Reviews List.', 'ffl-funnels-addons'),
        ];

        $this->controls['starFilledColor'] = [
            'tab'   => 'style',
            'label' => esc_html__('Filled stars color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => '--ffla-star-filled', 'selector' => '']],
        ];

        $this->controls['background'] = [
            'tab'   => 'style',
            'label' => esc_html__('Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => '--ffla-badge-bg', 'selector' => '']],
        ];

        $this->controls['textColor'] = [
            'tab'   => 'style',
            'label' => esc_html__('Text color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => '--ffla-badge-text', 'selector' => '']],
        ];

        $this->controls['averageTypography'] = [
            'tab'   => 'style',
            'label' => esc_html__('Average typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-store-badge__average']],
        ];
    }

    public function render()
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        $badge = [
            'style'        => $settings['style'] ?? '',
            'theme'        => $settings['theme'] ?? '',
            'label'        => $settings['label'] ?? '',
            'showCount'    => 'hide' !== ($settings['showCount'] ?? ''),
            'showVerified' => 'hide' !== ($settings['showVerified'] ?? ''),
            'hideIfEmpty'  => 'hide' === ($settings['hideIfEmpty'] ?? ''),
        ];

        $style = 'compact' === $badge['style'] ? 'compact' : 'card';
        $theme = 'dark' === $badge['theme'] ? 'dark' : 'light';
        $tag   = 'div';

        $this->set_attribute('_root', 'class', 'ffla-store-badge');
        $this->set_attribute('_root', 'class', 'ffla-store-badge--' . $style);
        $this->set_attribute('_root', 'class', 'ffla-store-badge--' . $theme);

        if (!empty($settings['link']) && method_exists($this, 'set_link_attributes')) {
            $this->set_link_attributes('_root', $settings['link']);
            $tag = 'a';
        }

        $html = \Product_Reviews_Frontend_Render::render_store_badge(
            $badge,
            '<' . $tag . ' ' . $this->render_attributes('_root') . '>',
            '</' . $tag . '>'
        );

        if ($html === '') {
            $in_builder = (function_exists('bricks_is_builder') && bricks_is_builder())
                || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());

            return $in_builder
                ? $this->render_element_placeholder(['title' => esc_html__('No reviews yet (badge hidden on frontend)', 'ffl-funnels-addons')])
                : null;
        }

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
    }
}
