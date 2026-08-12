<?php
/**
 * White Label Module — entry point.
 *
 * Restyles and locks down wp-admin per user. This first slice only wires the
 * module into the plugin, sets up the single-option settings store, and shows a
 * placeholder settings page. Features are added incrementally on top.
 *
 * Ships disabled by default (the registry only boots modules listed in the
 * ffla_active_modules option).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Module extends FFLA_Module
{
    /** @var White_Label_Admin|null */
    private $admin;

    public function get_id(): string
    {
        return 'white-label';
    }

    public function get_name(): string
    {
        return __('White Label', 'ffl-funnels-addons');
    }

    public function get_description(): string
    {
        return __('Brand and lock down wp-admin per user — login/admin styling, per-role menus, admin-bar control, and access restrictions.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><rect x="9" y="11" width="6" height="5" rx="1"/><path d="M10 11V9a2 2 0 0 1 4 0v2"/></svg>';
    }

    public function boot(): void
    {
        require_once $this->get_path() . 'includes/class-white-label-settings.php';
        require_once $this->get_path() . 'includes/class-white-label-access.php';

        // Enforce client restrictions for non-exempt, logged-in users — in
        // wp-admin AND on the front end (so the admin bar is cleaned there too).
        // Each hook only fires in its relevant context. Only once staff exemption
        // is configured, so nobody is locked out mid-setup.
        if (
            is_user_logged_in()
            && White_Label_Access::restrictions_active()
            && !White_Label_Access::current_user_is_exempt()
        ) {
            require_once $this->get_path() . 'includes/class-white-label-restrictions.php';
            $restrictions = White_Label_Settings::get('restrictions', []);
            (new White_Label_Restrictions(is_array($restrictions) ? $restrictions : []))->register_hooks();
        }

        if (is_admin()) {
            require_once $this->get_path() . 'admin/class-white-label-admin.php';
            $this->admin = new White_Label_Admin();
            $this->admin->init();

            // Light/dark toggle (admin-bar) + per-user mode as a body class.
            require_once $this->get_path() . 'includes/class-white-label-theme-mode.php';
            (new White_Label_Theme_Mode())->init();

            // Custom dashboard takeover (when enabled in the Dashboard tab).
            $dashboard = White_Label_Settings::get('dashboard', []);
            if (is_array($dashboard) && !empty($dashboard['enabled'])) {
                require_once $this->get_path() . 'includes/class-white-label-dashboard.php';
                (new White_Label_Dashboard($dashboard))->register_hooks();
            }

            // Sidebar menu ordering — applies to everyone (organisation, not a
            // restriction), so it's outside the exemption gate above.
            $menu = White_Label_Settings::get('menu', []);
            if (is_array($menu) && !empty($menu['top'])) {
                require_once $this->get_path() . 'includes/class-white-label-menu-order.php';
                (new White_Label_Menu_Order($menu))->register_hooks();
            }
        }
    }

    public function activate(): void
    {
        require_once $this->get_path() . 'includes/class-white-label-settings.php';

        // Create the single option so it exists as an (empty) array from day one.
        if (null === get_option(White_Label_Settings::OPTION, null)) {
            White_Label_Settings::save([]);
        }
    }

    public function deactivate(): void
    {
        // Settings are intentionally preserved so re-activation restores them.
    }

    public function get_admin_pages(): array
    {
        return [
            [
                'slug'  => 'ffla-white-label',
                'title' => __('White Label', 'ffl-funnels-addons'),
                'icon'  => $this->get_icon_svg(),
            ],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        if ($this->admin instanceof White_Label_Admin) {
            $this->admin->render_settings_content();
        }
    }
}
