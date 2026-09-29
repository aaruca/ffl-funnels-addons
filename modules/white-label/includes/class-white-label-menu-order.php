<?php
/**
 * White Label — sidebar menu ordering & dividers.
 *
 * - Removes the separators WordPress and plugins add to the admin menu by
 *   default (via CSS in white-label-menu.css).
 * - Lets the operator add their own dividers anywhere and reorder them together
 *   with the menu items (a divider is a normal top-level "slug" token that we
 *   inject into $menu as a separator entry).
 * - Reorders the top-level menu using WordPress's native ordering filters.
 *
 * Applies to everyone — it's an organisational preference, not a restriction.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Menu_Order
{
    /** Prefix that marks a saved order token as a custom divider. */
    const DIVIDER_PREFIX = 'ffla-divider-';

    /** @var array<int, string> Saved top-level order (menu slugs + divider tokens). */
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

    /**
     * Whether an order token is a custom divider.
     */
    public static function is_divider(string $slug): bool
    {
        return 0 === strpos($slug, self::DIVIDER_PREFIX);
    }

    public function register_hooks(): void
    {
        // Always: strip default separators + style our dividers.
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        // Inject the custom divider entries before the menu is rendered.
        add_action('admin_menu', [$this, 'inject_dividers'], 9998);

        // Reorder only when an order is saved.
        if (!empty($this->top)) {
            add_filter('custom_menu_order', '__return_true');
            add_filter('menu_order', [$this, 'order_top_level']);
        }
    }

    public function enqueue(): void
    {
        wp_enqueue_style(
            'ffla-wl-menu',
            FFLA_URL . 'modules/white-label/admin/css/white-label-menu.css',
            [],
            FFLA_VERSION
        );
    }

    /**
     * Inject a separator $menu entry for each custom divider token in the order,
     * so WordPress renders it and the menu_order filter can position it.
     */
    public function inject_dividers(): void
    {
        if (!isset($GLOBALS['menu']) || !is_array($GLOBALS['menu'])) {
            return;
        }

        // Slugs already present, to avoid double-injecting on repeat hooks.
        $existing = [];
        foreach ($GLOBALS['menu'] as $item) {
            if (isset($item[2])) {
                $existing[(string) $item[2]] = true;
            }
        }

        foreach ($this->top as $slug) {
            if (!self::is_divider($slug) || isset($existing[$slug])) {
                continue;
            }
            // [title, capability, menu_slug, page_title, classes]. The class must
            // contain 'wp-menu-separator' for WP to render it as a separator; the
            // extra class marks it as ours (kept visible; defaults are hidden).
            $GLOBALS['menu'][] = ['', 'read', $slug, '', 'wp-menu-separator ffla-wl-menu-divider'];
        }
    }

    /**
     * Return the top-level menu in the saved order: saved tokens first (menu slugs
     * that still exist, plus every custom divider), then anything else in its
     * original order.
     *
     * @param array<int, string> $menu_order
     * @return array<int, string>
     */
    public function order_top_level(array $menu_order): array
    {
        $front = [];
        foreach ($this->top as $slug) {
            if (self::is_divider($slug) || in_array($slug, $menu_order, true)) {
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
