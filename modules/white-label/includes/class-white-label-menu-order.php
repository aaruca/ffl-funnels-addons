<?php
/**
 * White Label — sidebar menu ordering.
 *
 * Reorders the top-level admin menu using WordPress's native ordering filters
 * (custom_menu_order + menu_order). Applies to everyone — it's an organisational
 * preference, not a restriction. Any menu not in the saved order (e.g. a newly
 * installed plugin) keeps its place at the end until reordered.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Menu_Order
{
    /** @var array<int, string> Saved top-level slug order. */
    private $top;

    /**
     * @param array<string, mixed> $menu The 'menu' settings sub-array.
     */
    public function __construct(array $menu)
    {
        $this->top = isset($menu['top']) && is_array($menu['top'])
            ? array_values(array_filter(array_map('strval', $menu['top'])))
            : [];
    }

    public function register_hooks(): void
    {
        if (empty($this->top)) {
            return;
        }

        add_filter('custom_menu_order', '__return_true');
        add_filter('menu_order', [$this, 'order_top_level']);
    }

    /**
     * Return the top-level menu in the saved order: saved slugs first (those that
     * still exist), then anything else in its original order.
     *
     * @param array<int, string> $menu_order
     * @return array<int, string>
     */
    public function order_top_level(array $menu_order): array
    {
        $front = [];
        foreach ($this->top as $slug) {
            if (in_array($slug, $menu_order, true)) {
                $front[] = $slug;
            }
        }

        $rest = [];
        foreach ($menu_order as $slug) {
            if (!in_array($slug, $front, true)) {
                $rest[] = $slug;
            }
        }

        return array_merge($front, $rest);
    }
}
