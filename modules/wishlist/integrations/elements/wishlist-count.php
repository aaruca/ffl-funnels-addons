<?php
namespace Bricks;

if (!defined('ABSPATH'))
    exit;

/**
 * Wishlist Counter Badge — Native Bricks Element.
 *
 * Renders a wishlist counter icon+badge for use in headers/navs.
 * The badge count is updated via JS (alg-wishlist-js).
 *
 * @package FFL_Funnels_Addons
 */
class FFLA_Wishlist_Count extends \Bricks\Element
{

    public $category = 'FFL Funnels - Wishlist';
    public $name = 'ffla-wishlist-count';
    public $icon = 'ti-bag';
    public $tag = 'a';
    public $scripts = [];
    public $nestable = false;

    public function get_label()
    {
        return esc_html__('Wishlist Counter', 'ffl-funnels-addons');
    }

    public function set_control_groups()
    {
        $this->control_groups['counter'] = [
            'title' => esc_html__('Counter', 'ffl-funnels-addons'),
            'tab' => 'content',
        ];
        $this->control_groups['styling'] = [
            'title' => esc_html__('Styling', 'ffl-funnels-addons'),
            'tab' => 'style',
        ];
    }

    public function set_controls()
    {

        // ── Content ────────────────────────────────────────────────

        $this->controls['wishlistPage'] = [
            'group' => 'counter',
            'tab' => 'content',
            'label' => esc_html__('Wishlist page', 'ffl-funnels-addons'),
            'type' => 'link',
            'description' => esc_html__('Link to the wishlist page. Defaults to the page set in Wishlist settings.', 'ffl-funnels-addons'),
        ];

        // F1: Make link toggle — when off, renders <div> instead of <a>
        $this->controls['isLink'] = [
            'group' => 'counter',
            'tab' => 'content',
            'label' => esc_html__('Make link', 'ffl-funnels-addons'),
            'type' => 'checkbox',
            'inline' => true,
            'small' => true,
            'default' => true,
            'description' => esc_html__('When disabled, renders a div instead of an anchor tag (useful to avoid nested links).', 'ffl-funnels-addons'),
        ];

        $this->controls['hideWhenZero'] = [
            'group' => 'counter',
            'tab' => 'content',
            'label' => esc_html__('Hide badge when zero', 'ffl-funnels-addons'),
            'type' => 'checkbox',
            'inline' => true,
            'small' => true,
            'default' => true,
        ];

        // F2: Show icon toggle
        $this->controls['showIcon'] = [
            'group' => 'counter',
            'tab' => 'content',
            'label' => esc_html__('Show icon', 'ffl-funnels-addons'),
            'type' => 'checkbox',
            'inline' => true,
            'small' => true,
            'default' => true,
        ];

        // F3: Custom icon (raw SVG)
        $this->controls['customIcon'] = [
            'group' => 'counter',
            'tab' => 'content',
            'label' => esc_html__('Custom icon (SVG)', 'ffl-funnels-addons'),
            'type' => 'textarea',
            'hasDynamicData' => false,
            'description' => esc_html__('Paste a custom SVG to replace the default heart icon.', 'ffl-funnels-addons'),
            'required' => ['showIcon', '=', true],
        ];

        // ── Style ──────────────────────────────────────────────────

        $this->controls['iconColor'] = [
            'group' => 'styling',
            'tab' => 'style',
            'label' => esc_html__('Icon color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => 'color', 'selector' => '']],
        ];

        $this->controls['iconSize'] = [
            'group' => 'styling',
            'tab' => 'style',
            'label' => esc_html__('Icon size', 'ffl-funnels-addons'),
            'type' => 'number',
            'units' => true,
            'css' => [
                ['property' => 'width', 'selector' => '.ffla-count-icon'],
                ['property' => 'height', 'selector' => '.ffla-count-icon']
            ],
            'default' => 20,
        ];

        $this->controls['badgeBg'] = [
            'group' => 'styling',
            'tab' => 'style',
            'label' => esc_html__('Badge background', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => 'background-color', 'selector' => '.alg-wishlist-count']],
        ];

        $this->controls['badgeColor'] = [
            'group' => 'styling',
            'tab' => 'style',
            'label' => esc_html__('Badge text color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => 'color', 'selector' => '.alg-wishlist-count']],
        ];

        $this->controls['badgeTypo'] = [
            'group' => 'styling',
            'tab' => 'style',
            'label' => esc_html__('Badge typography', 'ffl-funnels-addons'),
            'type' => 'typography',
            'css' => [['property' => 'typography', 'selector' => '.alg-wishlist-count']],
        ];
    }

    /**
     * Allowed SVG tags for wp_kses sanitization.
     */
    private function get_svg_allowed_tags()
    {
        return [
            'svg'      => ['xmlns' => true, 'viewBox' => true, 'width' => true, 'height' => true, 'fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'class' => true],
            'path'     => ['d' => true, 'fill' => true, 'stroke' => true],
            'circle'   => ['cx' => true, 'cy' => true, 'r' => true, 'fill' => true, 'stroke' => true],
            'rect'     => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true, 'fill' => true, 'stroke' => true],
            'line'     => ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true, 'stroke' => true],
            'polyline' => ['points' => true, 'fill' => true, 'stroke' => true],
            'polygon'  => ['points' => true, 'fill' => true, 'stroke' => true],
            'g'        => ['fill' => true, 'stroke' => true, 'transform' => true],
        ];
    }

    public function render()
    {
        $settings = $this->settings;

        // F1: Toggle between <a> and <div> based on isLink setting.
        // Default isLink = true (backwards compatible).
        $is_link = !isset($settings['isLink']) || !empty($settings['isLink']);

        if ($is_link) {
            $this->tag = 'a';

            // Resolve wishlist page URL.
            $page_url = '';
            if (!empty($settings['wishlistPage']['url'])) {
                $page_url = $settings['wishlistPage']['url'];
            } elseif (class_exists('Alg_Wishlist_Core')) {
                $page_id = \Alg_Wishlist_Core::get_wishlist_page_id();
                if ($page_id) {
                    $page_url = get_permalink($page_id);
                }
            }

            $this->set_attribute('_root', 'href', $page_url ? esc_url($page_url) : '#');
        } else {
            $this->tag = 'div';
        }

        $this->set_attribute('_root', 'class', 'alg-wishlist-counter-link');

        $hide_zero = !empty($settings['hideWhenZero']);

        // F2: Show icon toggle (default = true for backwards compat).
        $show_icon = !isset($settings['showIcon']) || !empty($settings['showIcon']);

        // F3: Custom icon SVG.
        $icon_svg = '';
        if ($show_icon) {
            if (!empty($settings['customIcon']) && strpos($settings['customIcon'], '<svg') !== false) {
                $icon_svg = wp_kses($settings['customIcon'], $this->get_svg_allowed_tags());
            } else {
                $icon_svg = '<svg class="ffla-count-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';
            }
        }

        $badge_class = 'alg-wishlist-count';
        if ($hide_zero) {
            $badge_class .= ' hidden';
        }

        $output = "<{$this->tag} {$this->render_attributes('_root')}>";
        $output .= $icon_svg;
        $output .= '<span class="' . esc_attr($badge_class) . '">0</span>';
        $output .= "</{$this->tag}>";

        echo $output;
    }
}
