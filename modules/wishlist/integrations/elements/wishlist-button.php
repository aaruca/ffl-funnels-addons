<?php
namespace Bricks;

if (!defined('ABSPATH'))
    exit;

/**
 * Wishlist Button — Native Bricks Element.
 *
 * Renders an "Add to Wishlist" toggle button with heart icon.
 * Integrates with the Wishlist module's existing JS (alg-wishlist-js).
 *
 * @package FFL_Funnels_Addons
 */
class FFLA_Wishlist_Button extends \Bricks\Element
{

    public $category = 'FFL Funnels - Wishlist';
    public $name = 'ffla-wishlist-button';
    public $icon = 'ti-heart';
    public $tag = 'button';
    public $scripts = [];
    public $nestable = false;

    public function get_label()
    {
        return esc_html__('Wishlist Button', 'ffl-funnels-addons');
    }

    public function set_control_groups()
    {
        $this->control_groups['button'] = [
            'title' => esc_html__('Button', 'ffl-funnels-addons'),
            'tab' => 'content',
        ];
        $this->control_groups['colors'] = [
            'title' => esc_html__('Colors', 'ffl-funnels-addons'),
            'tab' => 'style',
        ];
        $this->control_groups['iconStyle'] = [
            'title' => esc_html__('Icon', 'ffl-funnels-addons'),
            'tab' => 'style',
        ];
    }

    public function set_controls()
    {

        // ── Content Tab ────────────────────────────────────────────

        $this->controls['productId'] = [
            'group' => 'button',
            'tab' => 'content',
            'label' => esc_html__('Product ID', 'ffl-funnels-addons'),
            'type' => 'number',
            'hasDynamicData' => true,
            'description' => esc_html__('Leave empty to use the current product ID (inside loops).', 'ffl-funnels-addons'),
            'placeholder' => esc_html__('Auto', 'ffl-funnels-addons'),
        ];

        $this->controls['showText'] = [
            'group' => 'button',
            'tab' => 'content',
            'label' => esc_html__('Show label text', 'ffl-funnels-addons'),
            'type' => 'checkbox',
            'inline' => true,
            'small' => true,
            'default' => false,
        ];

        $this->controls['addText'] = [
            'group' => 'button',
            'tab' => 'content',
            'label' => esc_html__('Add text', 'ffl-funnels-addons'),
            'type' => 'text',
            'inline' => true,
            'default' => esc_html__('Add to Wishlist', 'ffl-funnels-addons'),
            'placeholder' => esc_html__('Add to Wishlist', 'ffl-funnels-addons'),
            'required' => ['showText', '=', true],
        ];

        $this->controls['removeText'] = [
            'group' => 'button',
            'tab' => 'content',
            'label' => esc_html__('Remove text', 'ffl-funnels-addons'),
            'type' => 'text',
            'inline' => true,
            'default' => esc_html__('Remove from Wishlist', 'ffl-funnels-addons'),
            'placeholder' => esc_html__('Remove from Wishlist', 'ffl-funnels-addons'),
            'required' => ['showText', '=', true],
        ];

        // ── Style Tab: Colors ──────────────────────────────────────

        $this->controls['colorDefault'] = [
            'group' => 'colors',
            'tab' => 'style',
            'label' => esc_html__('Default color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => '--alg-btn-color', 'selector' => '']],
        ];

        $this->controls['colorHover'] = [
            'group' => 'colors',
            'tab' => 'style',
            'label' => esc_html__('Hover color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => '--alg-btn-hover-color', 'selector' => '']],
        ];

        $this->controls['colorActive'] = [
            'group' => 'colors',
            'tab' => 'style',
            'label' => esc_html__('Active color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => '--alg-btn-active-color', 'selector' => '']],
        ];

        // ── Style Tab: Icon ────────────────────────────────────────

        $this->controls['iconSize'] = [
            'group' => 'iconStyle',
            'tab' => 'style',
            'label' => esc_html__('Icon size', 'ffl-funnels-addons'),
            'type' => 'number',
            'units' => true,
            'css' => [
                ['property' => 'width', 'selector' => '.ffla-wishlist-icon'],
                ['property' => 'height', 'selector' => '.ffla-wishlist-icon']
            ],
            'default' => 24,
        ];

        $this->controls['textTypo'] = [
            'group' => 'iconStyle',
            'tab' => 'style',
            'label' => esc_html__('Label typography', 'ffl-funnels-addons'),
            'type' => 'typography',
            'css' => [['property' => 'typography', 'selector' => '.ffla-wishlist-label']],
            'required' => ['showText', '=', true],
        ];
    }

    public function render()
    {
        $settings = $this->settings;
        $product_id = !empty($settings['productId']) ? absint($settings['productId']) : get_the_ID();

        if (!$product_id) {
            return $this->render_element_placeholder(
                ['title' => esc_html__('No product ID found.', 'ffl-funnels-addons')]
            );
        }

        // Check active state.
        $items = class_exists('Alg_Wishlist_Core') ? \Alg_Wishlist_Core::get_wishlist_items() : [];
        $is_active = in_array($product_id, $items);

        $this->set_attribute('_root', 'class', 'alg-add-to-wishlist');
        $this->set_attribute('_root', 'type', 'button');
        $this->set_attribute('_root', 'data-product-id', $product_id);
        $this->set_attribute('_root', 'aria-label', esc_attr__('Toggle Wishlist', 'ffl-funnels-addons'));

        if ($is_active) {
            $this->set_attribute('_root', 'class', 'active');
        }

        $add_text = $settings['addText'] ?? esc_html__('Add to Wishlist', 'ffl-funnels-addons');
        $remove_text = $settings['removeText'] ?? esc_html__('Remove from Wishlist', 'ffl-funnels-addons');
        $show_text = !empty($settings['showText']);

        $icon_svg = '<svg class="ffla-wishlist-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';

        $output = "<{$this->tag} {$this->render_attributes('_root')}>";
        $output .= $icon_svg;
        if ($show_text) {
            $text = $is_active ? $remove_text : $add_text;
            $output .= '<span class="ffla-wishlist-label">' . esc_html($text) . '</span>';
        }
        $output .= "</{$this->tag}>";

        echo $output;
    }
}
