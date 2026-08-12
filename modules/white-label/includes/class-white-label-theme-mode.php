<?php
/**
 * White Label — light / dark theme mode.
 *
 * Adds a per-user light/dark toggle to the admin bar (right side) and stamps the
 * chosen mode as a body class (`ffla-theme-dark` / `ffla-theme-light`) so it is
 * applied server-side, before paint — no flash. The Styles theme injects two
 * variable sets keyed by these classes, so the whole admin switches at once.
 *
 * Default is dark.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Theme_Mode
{
    const META_KEY    = 'ffla_wl_theme_mode';
    const AJAX_ACTION = 'ffla_wl_toggle_theme';
    const NONCE       = 'ffla_wl_theme_mode';

    public function init(): void
    {
        add_filter('admin_body_class', [$this, 'body_class']);
        add_action('admin_bar_menu', [$this, 'toolbar_toggle'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_head', [$this, 'print_toggle_styles']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_toggle']);
    }

    /**
     * The current user's mode: 'light' or 'dark' (default dark).
     */
    public static function current_mode(?int $user_id = null): string
    {
        $user_id = $user_id ?: get_current_user_id();
        $mode    = $user_id ? (string) get_user_meta($user_id, self::META_KEY, true) : '';

        return 'light' === $mode ? 'light' : 'dark';
    }

    public function body_class(string $classes): string
    {
        return trim($classes . ' ffla-theme-' . self::current_mode());
    }

    /**
     * Add the toggle to the right side of the admin bar (wp-admin only).
     */
    public function toolbar_toggle(WP_Admin_Bar $bar): void
    {
        if (!is_admin()) {
            return;
        }

        $bar->add_node([
            'id'     => 'ffla-wl-theme-toggle',
            'parent' => 'top-secondary',
            'href'   => '#',
            'title'  => $this->toggle_markup(self::current_mode()),
            'meta'   => ['title' => __('Toggle light / dark mode', 'ffl-funnels-addons')],
        ]);
    }

    public function enqueue(): void
    {
        wp_enqueue_script(
            'ffla-wl-toggle',
            FFLA_URL . 'modules/white-label/admin/js/white-label-toggle.js',
            [],
            FFLA_VERSION,
            true
        );
        wp_localize_script('ffla-wl-toggle', 'fflaWlToggle', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE),
            'action'  => self::AJAX_ACTION,
        ]);
    }

    /**
     * Persist the chosen mode to user meta.
     */
    public function ajax_toggle(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'dark';
        $mode = 'light' === $mode ? 'light' : 'dark';

        update_user_meta(get_current_user_id(), self::META_KEY, $mode);

        wp_send_json_success(['mode' => $mode]);
    }

    /**
     * Minimal styling for the toolbar toggle (loads on every admin page, so it
     * works regardless of whether the Styles theme is enqueued).
     */
    public function print_toggle_styles(): void
    {
        echo '<style id="ffla-wl-toggle-css">'
            . '#wp-admin-bar-ffla-wl-theme-toggle > .ab-item{display:flex !important;align-items:center;}'
            . '#wp-admin-bar-ffla-wl-theme-toggle .ffla-wl-theme-toggle{display:inline-flex;align-items:center;justify-content:center;}'
            . '#wp-admin-bar-ffla-wl-theme-toggle svg{width:18px;height:18px;display:block;}'
            . '#wp-admin-bar-ffla-wl-theme-toggle .ffla-wl-theme-toggle[data-current="dark"] .ffla-wl-theme-toggle__sun{display:none;}'
            . '#wp-admin-bar-ffla-wl-theme-toggle .ffla-wl-theme-toggle[data-current="light"] .ffla-wl-theme-toggle__moon{display:none;}'
            . '</style>';
    }

    private function toggle_markup(string $mode): string
    {
        $moon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
        $sun  = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" stroke-linecap="round"/></svg>';

        return '<span class="ffla-wl-theme-toggle" data-current="' . esc_attr($mode) . '">'
            . '<span class="ffla-wl-theme-toggle__moon">' . $moon . '</span>'
            . '<span class="ffla-wl-theme-toggle__sun">' . $sun . '</span>'
            . '</span>';
    }
}
