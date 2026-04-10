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

    public $category = 'FFL Funnels';
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
        $this->control_groups['icon'] = [
            'title' => esc_html__('Icon', 'ffl-funnels-addons'),
            'tab' => 'content',
        ];
        $this->control_groups['styling'] = [
            'title' => esc_html__('Styling', 'ffl-funnels-addons'),
            'tab' => 'content',
        ];
    }

    public function set_controls()
    {

        // ── Content Tab: Counter ──────────────────────────────────

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

        $this->controls['labelText'] = [
            'group' => 'counter',
            'tab' => 'content',
            'label' => esc_html__('Label text', 'ffl-funnels-addons'),
            'type' => 'text',
            'placeholder' => esc_html__('e.g. Wishlist', 'ffl-funnels-addons'),
            'description' => esc_html__('Optional text label next to the icon.', 'ffl-funnels-addons'),
        ];

        $this->controls['labelPosition'] = [
            'group' => 'counter',
            'tab' => 'content',
            'label' => esc_html__('Label position', 'ffl-funnels-addons'),
            'type' => 'select',
            'options' => [
                'left'  => esc_html__('Left of icon', 'ffl-funnels-addons'),
                'right' => esc_html__('Right of icon', 'ffl-funnels-addons'),
            ],
            'default' => 'right',
        ];

        $this->controls['labelDisplay'] = [
            'group' => 'counter',
            'tab' => 'content',
            'label' => esc_html__('Show label', 'ffl-funnels-addons'),
            'type' => 'select',
            'options' => [
                'inline' => esc_html__('Show', 'ffl-funnels-addons'),
                'none'   => esc_html__('Hide', 'ffl-funnels-addons'),
            ],
            'css' => [['property' => 'display', 'selector' => '.ffla-count-label']],
            'responsive' => true,
        ];

        // ── Content Tab: Icon ─────────────────────────────────────

        // Icon picker — supports icon libraries (Font Awesome, Themify, Ionicons)
        // and custom SVG uploads from the media library.
        $this->controls['customIcon'] = [
            'group' => 'icon',
            'tab' => 'content',
            'label' => esc_html__('Custom icon', 'ffl-funnels-addons'),
            'type' => 'icon',
            'description' => esc_html__('Leave empty for the default heart icon.', 'ffl-funnels-addons'),
            'required' => ['showIcon', '=', true],
        ];

        // ── Style Tab: Styling ────────────────────────────────────

        $this->controls['iconColor'] = [
            'group' => 'styling',
            'tab' => 'content',
            'label' => esc_html__('Icon color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => 'color', 'selector' => '']],
        ];

        $this->controls['iconSize'] = [
            'group' => 'styling',
            'tab' => 'content',
            'label' => esc_html__('Icon size', 'ffl-funnels-addons'),
            'type' => 'number',
            'units' => true,
            'css' => [
                ['property' => 'width', 'selector' => '.ffla-count-icon'],
                ['property' => 'height', 'selector' => '.ffla-count-icon'],
                ['property' => 'font-size', 'selector' => '.ffla-count-icon i'],
            ],
            'default' => 20,
        ];

        $this->controls['badgeBg'] = [
            'group' => 'styling',
            'tab' => 'content',
            'label' => esc_html__('Badge background', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => 'background-color', 'selector' => '.alg-wishlist-count']],
        ];

        $this->controls['badgeColor'] = [
            'group' => 'styling',
            'tab' => 'content',
            'label' => esc_html__('Badge text color', 'ffl-funnels-addons'),
            'type' => 'color',
            'css' => [['property' => 'color', 'selector' => '.alg-wishlist-count']],
        ];

        $this->controls['badgeTypo'] = [
            'group' => 'styling',
            'tab' => 'content',
            'label' => esc_html__('Badge typography', 'ffl-funnels-addons'),
            'type' => 'typography',
            'css' => [['property' => 'typography', 'selector' => '.alg-wishlist-count']],
        ];
    }

    /**
     * Default heart SVG icon (used when no custom icon is selected).
     */
    private function get_default_icon_svg(): string
    {
        return '<svg class="ffla-count-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>';
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

        // Build icon markup — use Bricks native icon if set, otherwise default heart.
        $icon_html = '';
        if ($show_icon) {
            $icon_data = $settings['customIcon'] ?? '';
            if (!empty($icon_data)) {
                ob_start();
                self::render_icon($icon_data, ['ffla-count-icon']);
                $icon_html = ob_get_clean();
            } else {
                $icon_html = $this->get_default_icon_svg();
            }
        }

        $badge_class = 'alg-wishlist-count';
        if ($hide_zero) {
            $badge_class .= ' hidden';
        }

        // Label text + position.
        $label_text     = isset($settings['labelText']) ? trim($settings['labelText']) : '';
        $label_position = isset($settings['labelPosition']) ? $settings['labelPosition'] : 'right';
        $label_html     = $label_text !== '' ? '<span class="ffla-count-label">' . esc_html($label_text) . '</span>' : '';

        // Wrap icon + badge so the badge positions relative to the icon.
        if ($show_icon) {
            $icon_wrap  = '<span class="ffla-count-icon-wrap">';
            $icon_wrap .= $icon_html;
            $icon_wrap .= '<span class="' . esc_attr($badge_class) . '">0</span>';
            $icon_wrap .= '</span>';
        } else {
            $icon_wrap = '<span class="' . esc_attr($badge_class) . '">0</span>';
        }

        $output  = "<{$this->tag} {$this->render_attributes('_root')}>";
        $output .= ($label_position === 'left') ? $label_html : '';
        $output .= $icon_wrap;
        $output .= ($label_position !== 'left') ? $label_html : '';
        $output .= "</{$this->tag}>";

        echo $output;
    }
}
