<?php
/**
 * FFLA Admin Shell.
 *
 * Handles the unified admin menu, asset enqueue, shared layout (header, sidebar, footer),
 * and routes page rendering to modules.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Admin
{
    /** @var FFLA_Module_Registry */
    private $registry;

    public function __construct(FFLA_Module_Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Register hooks.
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_head', [$this, 'hide_wp_submenu']);
        add_action('wp_ajax_ffla_toggle_module', [$this, 'ajax_toggle_module']);
    }

    /**
     * Hide module submenu items from the native WP admin left sidebar.
     * All navigation happens through the plugin's custom sidebar instead.
     * The top-level "FFL Funnels" menu item is kept visible.
     */
    public function hide_wp_submenu(): void
    {
        // Collect all module page slugs.
        $slugs = [];
        foreach ($this->registry->get_active() as $module) {
            foreach ($module->get_admin_pages() as $page) {
                $slugs[] = esc_attr($page['slug']);
            }
        }

        if (empty($slugs)) {
            return;
        }

        $selectors = array_map(function ($slug) {
            return '#adminmenu a[href="admin.php?page=' . $slug . '"]';
        }, $slugs);

        echo '<style>' . implode(',', $selectors) . '{display:none!important;}</style>';
    }

    /**
     * Build the admin menu.
     */
    public function add_menu(): void
    {
        // Top-level menu.
        add_menu_page(
            __('FFL Funnels Addons', 'ffl-funnels-addons'),
            __('FFL Funnels', 'ffl-funnels-addons'),
            'manage_woocommerce',
            'ffl-funnels-addons',
            [$this, 'render_page'],
            'dashicons-admin-plugins',
            56
        );

        // Dashboard sub-page (replaces default duplicate).
        add_submenu_page(
            'ffl-funnels-addons',
            __('Dashboard', 'ffl-funnels-addons'),
            __('Dashboard', 'ffl-funnels-addons'),
            'manage_woocommerce',
            'ffl-funnels-addons',
            [$this, 'render_page']
        );

        // Register admin pages for each active module.
        foreach ($this->registry->get_active() as $module) {
            foreach ($module->get_admin_pages() as $page) {
                add_submenu_page(
                    'ffl-funnels-addons',
                    $page['title'],
                    $page['title'],
                    'manage_woocommerce',
                    $page['slug'],
                    [$this, 'render_page']
                );
            }
        }
    }

    /**
     * Enqueue admin assets on FFLA pages only.
     */
    public function enqueue_assets(string $hook): void
    {
        // Only load on our pages.
        if (!$this->is_ffla_page($hook)) {
            return;
        }

        // Shared design system CSS.
        wp_enqueue_style(
            'ffla-admin',
            FFLA_URL . 'admin/css/ffla-admin.css',
            [],
            FFLA_VERSION
        );

        // Shared JS.
        wp_enqueue_script(
            'ffla-admin',
            FFLA_URL . 'admin/js/ffla-admin.js',
            [],
            FFLA_VERSION,
            true
        );

        wp_localize_script('ffla-admin', 'fflaAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ffla_admin_nonce'),
            'i18n' => [
                'activating' => __('Activating...', 'ffl-funnels-addons'),
                'deactivating' => __('Deactivating...', 'ffl-funnels-addons'),
                'checking' => __('Checking...', 'ffl-funnels-addons'),
            ],
        ]);

        // Let active modules enqueue their own assets.
        $page_slug = $this->get_current_page_slug();
        foreach ($this->registry->get_active() as $module) {
            $module_page_slugs = array_column($module->get_admin_pages(), 'slug');
            if (in_array($page_slug, $module_page_slugs, true)) {
                // Module-specific CSS.
                $module_css = $module->get_path() . 'admin/css/' . $module->get_id() . '-module.css';
                if (file_exists($module_css)) {
                    wp_enqueue_style(
                        $module->get_id() . '-module',
                        $module->get_url() . 'admin/css/' . $module->get_id() . '-module.css',
                        ['ffla-admin'],
                        FFLA_VERSION
                    );
                }

                // Module-specific JS.
                $module_js = $module->get_path() . 'admin/js/' . $module->get_id() . '-module.js';
                if (file_exists($module_js)) {
                    wp_enqueue_script(
                        $module->get_id() . '-module',
                        $module->get_url() . 'admin/js/' . $module->get_id() . '-module.js',
                        ['ffla-admin'],
                        FFLA_VERSION,
                        true
                    );
                }
            }
        }
    }

    /**
     * Check if the current admin page belongs to FFLA.
     */
    private function is_ffla_page(string $hook): bool
    {
        // Top-level page and all sub-pages.
        if (strpos($hook, 'ffl-funnels') !== false || strpos($hook, 'ffla') !== false) {
            return true;
        }

        // Check current page query param.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        return strpos($page, 'ffl-funnels') !== false || strpos($page, 'ffla') !== false;
    }

    /**
     * Get the current page slug from query params.
     */
    private function get_current_page_slug(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset($_GET['page']) ? sanitize_key($_GET['page']) : 'ffl-funnels-addons';
    }

    /**
     * Main render dispatcher.
     */
    public function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-funnels-addons'));
        }

        $page = $this->get_current_page_slug();

        echo '<div class="ffla-admin">';
        $this->render_header();
        echo '<div class="wb-layout">';
        $this->render_sidebar($page);
        echo '<div class="wb-content">';

        switch ($page) {
            case 'ffl-funnels-addons':
                $dashboard = new FFLA_Dashboard($this->registry);
                $dashboard->render();
                break;

            default:
                // Delegate to the appropriate module.
                $this->render_module_page($page);
                break;
        }

        echo '</div>'; // .wb-content
        echo '</div>'; // .wb-layout
        $this->render_footer();
        echo '</div>'; // .ffla-admin
    }

    /**
     * Render the shared header.
     */
    private function render_header(): void
    {
        echo '<header class="wb-header">';
        echo '<div class="wb-header__title">';
        echo '<h1>' . esc_html__('FFL Funnels Addons', 'ffl-funnels-addons') . '</h1>';
        echo '</div>';
        echo '<div class="wb-header__version">v' . esc_html(FFLA_VERSION) . '</div>';
        echo '</header>';
    }

    /**
     * Render the sidebar with collapsible module dropdowns.
     */
    private function render_sidebar(string $current_page): void
    {
        echo '<nav class="wb-sidebar">';
        echo '<ul class="wb-sidebar__nav">';

        // ── Dashboard (always top-level link) ─────────────────────────
        $dash_active = ($current_page === 'ffl-funnels-addons') ? ' wb-sidebar__item--active' : '';
        $dash_icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 2h5v5H2V2zM9 2h5v5H9V2zM2 9h5v5H2V9zM9 9h5v5H9V9z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/></svg>';
        echo '<li class="wb-sidebar__item' . esc_attr($dash_active) . '">';
        echo '<a href="' . esc_url(admin_url('admin.php?page=ffl-funnels-addons')) . '">';
        echo '<span class="wb-sidebar__icon">' . $dash_icon . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<span class="wb-sidebar__label">' . esc_html__('Dashboard', 'ffl-funnels-addons') . '</span>';
        echo '</a></li>';

        // ── Module groups as collapsible dropdowns ────────────────────
        foreach ($this->registry->get_active() as $module) {
            $pages = $module->get_admin_pages();
            if (empty($pages)) {
                continue;
            }

            // Auto-open if any sub-page is the current page.
            $module_slugs = array_column($pages, 'slug');
            $is_open = in_array($current_page, $module_slugs, true);
            $open_attr = $is_open ? ' open' : '';

            echo '<li class="wb-sidebar__group-dropdown">';
            echo '<details' . $open_attr . '>';
            echo '<summary class="wb-sidebar__group-summary">';
            echo '<span class="wb-sidebar__group-label">' . esc_html($module->get_name()) . '</span>';
            echo '<span class="wb-sidebar__chevron">'
                . '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">'
                . '<path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
                . '</svg></span>';
            echo '</summary>';

            echo '<ul class="wb-sidebar__sub-nav">';
            foreach ($pages as $page) {
                $sub_active = ($page['slug'] === $current_page) ? ' wb-sidebar__item--active' : '';
                $url = admin_url('admin.php?page=' . $page['slug']);

                echo '<li class="wb-sidebar__item wb-sidebar__item--sub' . esc_attr($sub_active) . '">';
                echo '<a href="' . esc_url($url) . '">';
                if (!empty($page['icon'])) {
                    echo '<span class="wb-sidebar__icon">' . $page['icon'] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                echo '<span class="wb-sidebar__label">' . esc_html($page['title']) . '</span>';
                echo '</a></li>';
            }
            echo '</ul>';
            echo '</details>';
            echo '</li>';
        }

        echo '</ul>';
        echo '</nav>';
    }

    /**
     * Render the footer.
     */
    private function render_footer(): void
    {
        echo '<footer class="wb-footer">';
        echo '<span>' . esc_html__('FFL Funnels Addons', 'ffl-funnels-addons') . ' v' . esc_html(FFLA_VERSION) . '</span>';
        echo '<span class="wb-footer__sep">&middot;</span>';
        echo '<span>' . esc_html__('Modular WooCommerce Toolkit', 'ffl-funnels-addons') . '</span>';
        echo '</footer>';
    }

    /**
     * Delegate page rendering to the right module.
     */
    private function render_module_page(string $page_slug): void
    {
        foreach ($this->registry->get_active() as $module) {
            $slugs = array_column($module->get_admin_pages(), 'slug');
            if (in_array($page_slug, $slugs, true)) {
                $module->render_admin_page($page_slug);
                return;
            }
        }

        // Fallback: page not found.
        echo '<div class="wb-card"><div class="wb-card__body">';
        echo '<p>' . esc_html__('Page not found or module is inactive.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';
    }

    /**
     * AJAX: Toggle a module on/off.
     */
    public function ajax_toggle_module(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $module_id = isset($_POST['module_id']) ? sanitize_key($_POST['module_id']) : '';
        $activate = isset($_POST['active']) ? (bool) $_POST['active'] : false;

        if (empty($module_id)) {
            wp_send_json_error(['message' => __('Invalid module.', 'ffl-funnels-addons')]);
        }

        if ($activate) {
            $result = $this->registry->activate_module($module_id);
        } else {
            $result = $this->registry->deactivate_module($module_id);
        }

        if ($result) {
            wp_send_json_success([
                'message' => $activate
                    ? sprintf(__('%s activated.', 'ffl-funnels-addons'), $module_id)
                    : sprintf(__('%s deactivated.', 'ffl-funnels-addons'), $module_id),
                'reload' => true,
            ]);
        } else {
            wp_send_json_error(['message' => __('Module not found.', 'ffl-funnels-addons')]);
        }
    }

    /**
     * Static helper to render a text field.
     */
    public static function render_text_field(string $label, string $name, string $value, string $desc): void
    {
        ?>
        <div class="wb-field">
            <label class="wb-field__label" for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
            <div class="wb-field__control">
                <input type="text" id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>"
                    value="<?php echo esc_attr($value); ?>" class="wb-input">
                <p class="wb-field__desc"><?php echo esc_html($desc); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Static helper to render a toggle field.
     */
    public static function render_toggle_field(string $label, string $name, string $value, string $desc): void
    {
        ?>
        <div class="wb-field">
            <label class="wb-field__label"><?php echo esc_html($label); ?></label>
            <div class="wb-field__control">
                <label class="wb-toggle">
                    <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($value, '1'); ?>>
                    <span class="wb-toggle__slider"></span>
                </label>
                <p class="wb-field__desc"><?php echo esc_html($desc); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Static helper to render a select field.
     */
    public static function render_select_field(string $label, string $name, string $value, array $options, string $desc): void
    {
        ?>
        <div class="wb-field">
            <label class="wb-field__label" for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
            <div class="wb-field__control">
                <select id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" class="wb-select">
                    <?php foreach ($options as $opt_val => $opt_label): ?>
                        <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($value, $opt_val); ?>>
                            <?php echo esc_html($opt_label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="wb-field__desc"><?php echo esc_html($desc); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Static helper to render a color field.
     */
    public static function render_color_field(string $label, string $name, string $value, string $desc): void
    {
        ?>
        <div class="wb-field">
            <label class="wb-field__label" for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
            <div class="wb-field__control">
                <input type="color" id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>"
                    value="<?php echo esc_attr($value); ?>" class="wb-input" style="width:60px;height:36px;padding:2px;">
                <p class="wb-field__desc"><?php echo esc_html($desc); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Static helper to render a textarea field.
     */
    public static function render_textarea_field(string $label, string $name, string $value, string $desc): void
    {
        ?>
        <div class="wb-field">
            <label class="wb-field__label" for="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label>
            <div class="wb-field__control">
                <textarea id="<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" class="wb-input"
                    rows="4"><?php echo esc_textarea($value); ?></textarea>
                <p class="wb-field__desc"><?php echo esc_html($desc); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Static helper to render a notice.
     */
    public static function render_notice(string $type, string $message): void
    {
        $icon_map = [
            'success' => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2.5 7.5l3 3 6-7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            'warning' => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 1l6 11H1L7 1z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M7 5v3M7 10v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'danger' => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 3l8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
            'info' => '<svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.2"/><path d="M7 6v4M7 4.5v.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>',
        ];
        $icon = $icon_map[$type] ?? '';

        echo '<div class="wb-message wb-message--' . esc_attr($type) . '">';
        echo $icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<span>' . esc_html($message) . '</span>';
        echo '</div>';
    }
}
