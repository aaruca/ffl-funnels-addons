<?php
/**
 * White Label — client restrictions (enforcement).
 *
 * For non-exempt users only (the caller registers this class only for them). It
 * hides the chosen admin-menu items AND blocks them by direct URL, plus hides
 * this plugin's own menu so a client can't reach the White Label settings.
 *
 * Hiding a menu is only cosmetic — the page still opens by URL — so every hidden
 * item is also enforced with an admin_init redirect guard.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Restrictions
{
    /** This plugin's own top-level menu slug — always hidden from restricted users. */
    const FFLA_MENU_SLUG = 'ffl-funnels-addons';

    /** @var array<string, mixed> The 'restrictions' settings sub-array. */
    private $settings;

    /**
     * @param array<string, mixed> $restrictions
     */
    public function __construct(array $restrictions)
    {
        $this->settings = $restrictions;
    }

    public function register_hooks(): void
    {
        add_action('admin_menu', [$this, 'hide_menus'], 9999);
        add_action('admin_init', [$this, 'block_pages']);
        // Late, so every plugin has added its admin-bar nodes first. Fires in
        // wp-admin and on the front end.
        add_action('admin_bar_menu', [$this, 'remove_admin_bar_nodes'], 9999);
    }

    /**
     * Remove hidden items (and our own menu) from the sidebar.
     */
    public function hide_menus(): void
    {
        remove_menu_page(self::FFLA_MENU_SLUG);

        foreach ($this->hidden_slugs() as $slug) {
            if (false !== strpos($slug, '::')) {
                list($parent, $child) = explode('::', $slug, 2);
                remove_submenu_page($parent, $child);
            } else {
                remove_menu_page($slug);
            }
        }
    }

    /**
     * Redirect away from any hidden/blocked screen reached by URL.
     */
    public function block_pages(): void
    {
        if ($this->request_matches(self::FFLA_MENU_SLUG)) {
            $this->redirect_away();
        }

        foreach ($this->hidden_slugs() as $slug) {
            // A submenu slug is "parent::child"; the child is the real page.
            $target = false !== strpos($slug, '::') ? explode('::', $slug, 2)[1] : $slug;
            if ($this->request_matches($target)) {
                $this->redirect_away();
            }
        }
    }

    /**
     * Remove admin-bar nodes that point at a hidden page, so the top bar matches
     * the sidebar. Most plugin nodes link to their admin page via `href`.
     */
    public function remove_admin_bar_nodes(WP_Admin_Bar $bar): void
    {
        $hidden     = $this->hidden_slugs();
        $hidden_ids = $this->hidden_adminbar_ids();
        if (empty($hidden) && empty($hidden_ids)) {
            return;
        }

        $nodes = $bar->get_nodes();
        if (!is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            $id = (string) $node->id;

            // Explicitly-chosen node (catches dropdowns / custom-URL items).
            if (in_array($id, $hidden_ids, true)) {
                $bar->remove_node($id);
                continue;
            }

            // Automatic: node links to a page hidden in the menu tree.
            if (!empty($node->href) && $this->href_targets_blocked((string) $node->href, $hidden)) {
                $bar->remove_node($id);
            }
        }
    }

    /* =====================================================================
     * Internals
     * ================================================================== */

    /**
     * @return array<int, string>
     */
    private function hidden_slugs(): array
    {
        $slugs = isset($this->settings['hidden_menu']) && is_array($this->settings['hidden_menu'])
            ? $this->settings['hidden_menu']
            : [];

        return array_values(array_filter(array_map('strval', $slugs)));
    }

    /**
     * @return array<int, string>
     */
    private function hidden_adminbar_ids(): array
    {
        $ids = isset($this->settings['hidden_adminbar']) && is_array($this->settings['hidden_adminbar'])
            ? $this->settings['hidden_adminbar']
            : [];

        return array_values(array_filter(array_map('strval', $ids)));
    }

    private function redirect_away(): void
    {
        // Never redirect to a page that is itself blocked, or we'd loop — most
        // importantly the dashboard (index.php), which is admin_url()'s default
        // and whose first submenu WordPress mirrors from the parent slug.
        if (!$this->is_slug_blocked('index.php')) {
            wp_safe_redirect(admin_url());
            exit;
        }

        if (!$this->is_slug_blocked('profile.php')) {
            wp_safe_redirect(admin_url('profile.php'));
            exit;
        }

        wp_die(esc_html__('Access to this area of the dashboard is restricted.', 'ffl-funnels-addons'));
    }

    /**
     * Whether a given page slug is in the blocked set (matching the child of a
     * "parent::child" entry, since that is the real page).
     */
    private function is_slug_blocked(string $slug): bool
    {
        foreach ($this->hidden_slugs() as $entry) {
            $target = false !== strpos($entry, '::') ? explode('::', $entry, 2)[1] : $entry;
            if ($target === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the current admin request is the page referenced by $slug.
     */
    private function request_matches(string $slug): bool
    {
        $pagenow = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';

        return $this->target_matches($slug, $pagenow, $this->current_request_args());
    }

    /**
     * Whether an href (from an admin-bar node) points at any hidden page.
     *
     * @param array<int, string> $hidden
     */
    private function href_targets_blocked(string $href, array $hidden): bool
    {
        $parts = wp_parse_url($href);
        if (empty($parts['path'])) {
            return false;
        }

        $file = basename($parts['path']);
        $args = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $args);
        }

        foreach ($hidden as $entry) {
            $target = false !== strpos($entry, '::') ? explode('::', $entry, 2)[1] : $entry;
            if ($this->target_matches($target, $file, $args)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Core matcher: does $slug reference the page identified by an admin file +
     * query args? Menu slugs come in three shapes:
     *   - "my-plugin"                 → a ?page= slug (most plugin pages)
     *   - "edit.php?post_type=page"   → a core file with query args
     *   - "themes.php"                → a plain core file
     *
     * @param array<string, mixed> $args
     */
    private function target_matches(string $slug, string $file, array $args): bool
    {
        $slug = trim(html_entity_decode($slug));
        if ('' === $slug) {
            return false;
        }

        $page = isset($args['page']) ? (string) $args['page'] : '';

        // File with query args, e.g. "edit.php?post_type=page".
        if (false !== strpos($slug, '?')) {
            list($slug_file, $slug_query) = explode('?', $slug, 2);
            if ($file !== $slug_file) {
                return false;
            }
            parse_str($slug_query, $slug_args);
            foreach ($slug_args as $key => $value) {
                $have = isset($args[$key]) ? (string) $args[$key] : '';
                if ($have !== (string) $value) {
                    return false;
                }
            }
            return true;
        }

        // Plain core file, e.g. "plugins.php", "themes.php", "edit.php" (Posts).
        if ('.php' === substr($slug, -4)) {
            if ('' !== $page || $file !== $slug) {
                return false;
            }
            // Distinguish "edit.php" (Posts) from "edit.php?post_type=<cpt>".
            if ('edit.php' === $slug) {
                $post_type = isset($args['post_type']) ? (string) $args['post_type'] : '';
                if ('' !== $post_type && 'post' !== $post_type) {
                    return false;
                }
            }
            return true;
        }

        // Bare plugin-page slug (admin.php?page=<slug>).
        return '' !== $page && $page === $slug;
    }

    /**
     * A sanitised copy of the current request's query args (read-only screen match).
     *
     * @return array<string, string>
     */
    private function current_request_args(): array
    {
        $args = [];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page match.
        foreach ((array) $_GET as $key => $value) {
            if (is_scalar($value)) {
                $args[sanitize_key((string) $key)] = sanitize_text_field(wp_unslash((string) $value));
            }
        }

        return $args;
    }
}
