<?php
/**
 * White Label — admin screen controller.
 *
 * Two jobs:
 *  1. Render the settings page (delegating markup to admin/views/*).
 *  2. Turn the saved Styles colours into CSS variables and load the theme
 *     stylesheet that maps those variables onto WordPress's selectors.
 *
 * The heavy lifting of "which selector gets which colour" lives in the CSS
 * file (admin/css/white-label-theme.css), not in PHP. PHP only injects the
 * variables.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Admin
{
    const PAGE_SLUG    = 'ffla-white-label';
    const SAVE_ACTION  = 'ffla_wl_save_settings';
    const NONCE_ACTION = 'ffla_wl_settings';
    const NONCE_FIELD  = '_ffla_wl_nonce';

    const EXPORT_ACTION       = 'ffla_wl_export';
    const IMPORT_ACTION       = 'ffla_wl_import';
    const IMPORT_NONCE_ACTION = 'ffla_wl_import';
    const IMPORT_NONCE_FIELD  = '_ffla_wl_import_nonce';

    /** Envelope marker so an exported file can be recognised on import. */
    const EXPORT_MARKER = 'ffla_white_label';

    /** Caches the full admin-bar node list, captured inside admin_bar_menu. */
    const ADMINBAR_CACHE = 'ffla_wl_adminbar_nodes';

    /**
     * Register admin hooks.
     */
    public function init(): void
    {
        add_action('admin_post_' . self::SAVE_ACTION, [$this, 'handle_save']);
        add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'handle_export']);
        add_action('admin_post_' . self::IMPORT_ACTION, [$this, 'handle_import']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_theme']);
        // Capture the admin-bar node list where it is guaranteed populated.
        add_action('admin_bar_menu', [$this, 'cache_admin_bar_nodes'], 99998);
    }

    /**
     * The settings tabs, in display order: slug => label.
     *
     * @return array<string, string>
     */
    private function get_tabs(): array
    {
        return [
            'styles'       => __('Styles', 'ffl-funnels-addons'),
            'menu'         => __('Menu', 'ffl-funnels-addons'),
            'dashboard'    => __('Dashboard', 'ffl-funnels-addons'),
            'restrictions' => __('Restrictions', 'ffl-funnels-addons'),
            'import-export' => __('Import / Export', 'ffl-funnels-addons'),
        ];
    }

    /**
     * The Styles colour fields, grouped for the UI: group label => (key => label).
     * Each key becomes a CSS variable --ffla-wl-<key> and a form field
     * ffla_wl[styles][<key>].
     *
     * @return array<string, array<string, string>>
     */
    private function get_style_fields(): array
    {
        return [
            __('General', 'ffl-funnels-addons') => [
                'primaryColor'    => __('Primary colour', 'ffl-funnels-addons'),
                'primaryContrast' => __('Primary contrast (button text)', 'ffl-funnels-addons'),
                'borderColor'     => __('Borders & dividers', 'ffl-funnels-addons'),
            ],
            __('Sidebar', 'ffl-funnels-addons') => [
                'sidebarBg'          => __('Background', 'ffl-funnels-addons'),
                'sidebarText'        => __('Item text', 'ffl-funnels-addons'),
                'sidebarIcon'        => __('Item icon', 'ffl-funnels-addons'),
                'sidebarHoverBg'     => __('Item hover background', 'ffl-funnels-addons'),
                'sidebarHoverText'   => __('Item hover text', 'ffl-funnels-addons'),
                'sidebarHoverIcon'   => __('Item hover icon', 'ffl-funnels-addons'),
                'sidebarCurrentBg'   => __('Current item background', 'ffl-funnels-addons'),
                'sidebarCurrentText' => __('Current item text', 'ffl-funnels-addons'),
                'sidebarCurrentIcon' => __('Current item icon', 'ffl-funnels-addons'),
            ],
            __('Submenu', 'ffl-funnels-addons') => [
                'submenuBg'          => __('Background', 'ffl-funnels-addons'),
                'submenuText'        => __('Item text', 'ffl-funnels-addons'),
                'submenuHoverText'   => __('Item hover text', 'ffl-funnels-addons'),
                'submenuCurrentText' => __('Current item text', 'ffl-funnels-addons'),
            ],
            __('Admin bar', 'ffl-funnels-addons') => [
                'adminbarBg'        => __('Background', 'ffl-funnels-addons'),
                'adminbarText'      => __('Item text', 'ffl-funnels-addons'),
                'adminbarIcon'      => __('Item icon', 'ffl-funnels-addons'),
                'adminbarHoverBg'   => __('Item hover background', 'ffl-funnels-addons'),
                'adminbarHoverText' => __('Item hover text', 'ffl-funnels-addons'),
            ],
            __('Admin bar dropdown', 'ffl-funnels-addons') => [
                'adminbarSubBg'        => __('Background', 'ffl-funnels-addons'),
                'adminbarSubText'      => __('Item text', 'ffl-funnels-addons'),
                'adminbarSubHoverBg'   => __('Item hover background', 'ffl-funnels-addons'),
                'adminbarSubHoverText' => __('Item hover text', 'ffl-funnels-addons'),
            ],
            __('Dashboard', 'ffl-funnels-addons') => [
                'dashBg'     => __('Page background', 'ffl-funnels-addons'),
                'dashCard'   => __('Card background', 'ffl-funnels-addons'),
                'dashText'   => __('Text', 'ffl-funnels-addons'),
                'dashMuted'  => __('Muted text', 'ffl-funnels-addons'),
                'dashBorder' => __('Border', 'ffl-funnels-addons'),
            ],
        ];
    }

    /* =====================================================================
     * Styling — inject variables + load the theme stylesheet
     * ================================================================== */

    /**
     * On every admin page: if any Styles colours are set, load the theme CSS
     * and inject the saved values as CSS variables. Nothing loads when nothing
     * is configured, so an unstyled module leaves wp-admin looking stock.
     */
    public function enqueue_theme(): void
    {
        $variables = $this->build_style_variables_css();
        if ('' === $variables) {
            return;
        }

        wp_enqueue_style(
            'ffla-wl-theme',
            FFLA_URL . 'modules/white-label/admin/css/white-label-theme.css',
            [],
            FFLA_VERSION
        );
        wp_add_inline_style('ffla-wl-theme', $variables);

        // Sidebar plugin icons that ship as a background-image SVG can't be
        // recoloured by `color`, so a small helper rewrites their fill to the
        // active sidebar-icon colour. Load it whenever the theme is active (the
        // per-mode defaults always define a sidebar-icon colour), so it also
        // re-tints on light/dark toggle. It sets background-image — never CSS
        // `mask`, which promoted a GPU layer and blanked large pages until a
        // reflow. Admin-bar inline-SVG icons are handled purely in CSS via
        // `fill`.
        wp_enqueue_script(
            'ffla-wl-theme',
            FFLA_URL . 'modules/white-label/admin/js/white-label-theme.js',
            [],
            FFLA_VERSION,
            true
        );
    }

    /**
     * The saved styles normalised to the light/dark shape:
     *   ['light' => [key => hex], 'dark' => [key => hex]].
     *
     * Migrates the old flat format (a single set of colours) by applying it to
     * BOTH modes, so existing configurations keep looking identical until the
     * operator differentiates the two.
     *
     * @return array{light: array<string,string>, dark: array<string,string>}
     */
    private function get_styles(): array
    {
        $styles = White_Label_Settings::get('styles', []);
        if (!is_array($styles)) {
            $styles = [];
        }

        if (!isset($styles['light']) && !isset($styles['dark'])) {
            // Old flat format → keep the configured colours as the DARK theme;
            // light starts empty so the built-in light defaults make the toggle
            // visibly switch the whole admin.
            return ['light' => [], 'dark' => $styles];
        }

        return [
            'light' => isset($styles['light']) && is_array($styles['light']) ? $styles['light'] : [],
            'dark'  => isset($styles['dark']) && is_array($styles['dark']) ? $styles['dark'] : [],
        ];
    }

    /**
     * Built-in per-mode default palette for the neutral chrome colours, so the
     * toggle switches the whole admin even when a mode isn't fully configured.
     * Accent backgrounds (hover/current) are intentionally omitted — they keep
     * inheriting the Primary colour.
     *
     * @return array{light: array<string,string>, dark: array<string,string>}
     */
    private function get_style_defaults(): array
    {
        return [
            'dark' => [
                'primaryContrast'   => '#ffffff',
                'borderColor'       => '#2c3338',
                'sidebarBg'         => '#1d2327',
                'sidebarText'       => '#e7edf7',
                'sidebarIcon'       => '#a7aaad',
                'sidebarHoverText'  => '#ffffff',
                'sidebarHoverIcon'  => '#ffffff',
                'sidebarCurrentText' => '#ffffff',
                'sidebarCurrentIcon' => '#ffffff',
                'submenuBg'         => '#2c3338',
                'submenuText'       => '#c3c4c7',
                'adminbarBg'        => '#1d2327',
                'adminbarText'      => '#e7edf7',
                'adminbarIcon'      => '#a7aaad',
                'adminbarHoverText' => '#ffffff',
                'adminbarSubBg'     => '#2c3338',
                'adminbarSubText'   => '#c3c4c7',
                'adminbarSubHoverText' => '#ffffff',
                'dashBg'            => '#0b1120',
                'dashCard'          => '#121a2e',
                'dashText'          => '#e7edf7',
                'dashMuted'         => '#93a3bd',
                'dashBorder'        => '#1e293b',
            ],
            'light' => [
                'primaryContrast'   => '#ffffff',
                'borderColor'       => '#e2e4e9',
                'sidebarBg'         => '#ffffff',
                'sidebarText'       => '#1d2327',
                'sidebarIcon'       => '#50575e',
                'sidebarHoverText'  => '#ffffff',
                'sidebarHoverIcon'  => '#ffffff',
                'sidebarCurrentText' => '#ffffff',
                'sidebarCurrentIcon' => '#ffffff',
                'submenuBg'         => '#f6f7f7',
                'submenuText'       => '#3c434a',
                'adminbarBg'        => '#ffffff',
                'adminbarText'      => '#1d2327',
                'adminbarIcon'      => '#50575e',
                'adminbarHoverText' => '#ffffff',
                'adminbarSubBg'     => '#ffffff',
                'adminbarSubText'   => '#3c434a',
                'adminbarSubHoverText' => '#ffffff',
                'dashBg'            => '#f4f6fb',
                'dashCard'          => '#ffffff',
                'dashText'          => '#0f172a',
                'dashMuted'         => '#64748b',
                'dashBorder'        => '#e6eaf1',
            ],
        ];
    }

    /**
     * Build the mode-keyed variable blocks from the saved styles. Each mode gets
     * a `body.ffla-theme-<mode> { --ffla-wl-<key>: <hex>; }` block. Returns ''
     * when nothing valid is set.
     */
    private function build_style_variables_css(): string
    {
        $styles = $this->get_styles();

        // Theming is opt-in: only apply once the operator has configured at
        // least one colour (in either mode). Otherwise leave the admin stock.
        if (empty($styles['light']) && empty($styles['dark'])) {
            return '';
        }

        $defaults = $this->get_style_defaults();
        $css      = '';

        foreach (['light', 'dark'] as $mode) {
            $merged       = array_merge($defaults[$mode], $styles[$mode]); // user wins
            $declarations = '';
            foreach ($merged as $key => $value) {
                $key   = (string) $key;
                $value = trim((string) $value);
                if (!preg_match('/^[A-Za-z0-9]+$/', $key)) {
                    continue;
                }
                if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
                    continue;
                }
                $declarations .= '--ffla-wl-' . $key . ':' . $value . ';';
            }

            if ('' !== $declarations) {
                $css .= 'body.ffla-theme-' . $mode . '{' . $declarations . '}';
            }
        }

        return $css;
    }

    /* =====================================================================
     * Settings page
     * ================================================================== */

    /**
     * Entry point called by the module through the shared admin shell.
     */
    public function render_settings_content(): void
    {
        // Self-protection: once staff exemption is configured, only exempt staff
        // may view or change White Label settings (clients are Administrators too).
        if (White_Label_Access::restrictions_active() && !White_Label_Access::current_user_is_exempt()) {
            wp_die(esc_html__('You do not have permission to access these settings.', 'ffl-funnels-addons'));
        }

        $restrictions = (array) White_Label_Settings::get('restrictions', []);

        $view_data = [
            'tabs'          => $this->get_tabs(),
            'active_tab'    => $this->get_active_tab(),
            'style_fields'  => $this->get_style_fields(),
            'style_values'  => $this->get_styles(),
            'menu_tree'     => $this->get_admin_menu_tree(),
            'menu_items'    => $this->get_ordered_top_level(),
            'adminbar_nodes' => $this->get_admin_bar_nodes(),
            'exempt_emails' => implode("\n", isset($restrictions['exempt_emails']) && is_array($restrictions['exempt_emails']) ? $restrictions['exempt_emails'] : []),
            'hidden_menu'   => isset($restrictions['hidden_menu']) && is_array($restrictions['hidden_menu']) ? $restrictions['hidden_menu'] : [],
            'hidden_adminbar' => isset($restrictions['hidden_adminbar']) && is_array($restrictions['hidden_adminbar']) ? $restrictions['hidden_adminbar'] : [],
            'dashboard'     => $this->get_dashboard_settings(),
            'superusers_defined' => defined(White_Label_Access::SUPERUSERS_CONSTANT),
            'was_saved'     => $this->just_saved(),
            'form_action'   => esc_url(admin_url('admin-post.php')),
            'save_action'   => self::SAVE_ACTION,
            'nonce_action'  => self::NONCE_ACTION,
            'nonce_field'   => self::NONCE_FIELD,
            // Import / Export tab.
            'export_json'   => wp_json_encode($this->build_export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'export_url'    => esc_url(wp_nonce_url(
                add_query_arg('action', self::EXPORT_ACTION, admin_url('admin-post.php')),
                self::EXPORT_ACTION
            )),
            'import_action'       => self::IMPORT_ACTION,
            'import_nonce_action' => self::IMPORT_NONCE_ACTION,
            'import_nonce_field'  => self::IMPORT_NONCE_FIELD,
            'import_notice'       => $this->import_notice(),
        ];

        $this->render_view('settings-page', $view_data);
    }

    /**
     * Snapshot the current admin menu as a tree for the hide/block UI. Read from
     * the menu globals, which are populated by the time this page renders. The
     * viewer is exempt staff, so they see the complete menu.
     *
     * @return array<int, array{slug:string, label:string, children:array<int, array{slug:string, label:string}>}>
     */
    private function get_admin_menu_tree(): array
    {
        $menu    = isset($GLOBALS['menu']) && is_array($GLOBALS['menu']) ? $GLOBALS['menu'] : [];
        $submenu = isset($GLOBALS['submenu']) && is_array($GLOBALS['submenu']) ? $GLOBALS['submenu'] : [];

        $tree = [];
        foreach ($menu as $item) {
            $slug  = isset($item[2]) ? (string) $item[2] : '';
            $label = $this->clean_menu_label(isset($item[0]) ? (string) $item[0] : '');
            if ('' === $slug || 0 === strpos($slug, 'separator') || '' === $label) {
                continue;
            }

            $children = [];
            if (!empty($submenu[$slug]) && is_array($submenu[$slug])) {
                foreach ($submenu[$slug] as $sub) {
                    $child_slug  = isset($sub[2]) ? (string) $sub[2] : '';
                    $child_label = $this->clean_menu_label(isset($sub[0]) ? (string) $sub[0] : '');
                    if ('' === $child_slug || '' === $child_label) {
                        continue;
                    }
                    $children[] = ['slug' => $slug . '::' . $child_slug, 'label' => $child_label];
                }
            }

            $tree[] = ['slug' => $slug, 'label' => $label, 'children' => $children];
        }

        return $tree;
    }

    /**
     * Strip update-count bubbles and tags from a raw menu label.
     */
    private function clean_menu_label(string $label): string
    {
        $label = preg_replace('/<span[^>]*>.*?<\/span>/s', '', $label);

        return trim(wp_strip_all_tags((string) $label));
    }

    /**
     * Top-level menu items in the saved order, for the reorder UI:
     * slug => label. Saved order first, then any new/unordered menus.
     *
     * @return array<string, string>
     */
    private function get_ordered_top_level(): array
    {
        $labels = [];
        foreach ($this->get_admin_menu_tree() as $item) {
            $labels[$item['slug']] = $item['label'];
        }

        $saved = White_Label_Settings::get('menu.top', []);
        $saved = is_array($saved) ? $saved : [];

        $ordered = [];
        foreach ($saved as $slug) {
            $slug = (string) $slug;
            if (isset($labels[$slug])) {
                $ordered[$slug] = $labels[$slug];
                unset($labels[$slug]);
            }
        }
        foreach ($labels as $slug => $label) {
            $ordered[$slug] = $label;
        }

        return $ordered;
    }

    /**
     * Capture the FULL top-level admin-bar list from inside admin_bar_menu (the
     * one place every node is guaranteed to exist) and cache it for the settings
     * screen. Runs on every admin page; skips users whose bar we prune, so the
     * cached list stays complete.
     */
    public function cache_admin_bar_nodes(WP_Admin_Bar $bar): void
    {
        if (White_Label_Access::restrictions_active() && !White_Label_Access::current_user_is_exempt()) {
            return;
        }

        set_transient(self::ADMINBAR_CACHE, $this->build_admin_bar_list($bar), DAY_IN_SECONDS);
    }

    /**
     * The cached top-level admin-bar items for the remove list: node id => label.
     *
     * @return array<string, string>
     */
    private function get_admin_bar_nodes(): array
    {
        $cached = get_transient(self::ADMINBAR_CACHE);

        return is_array($cached) ? $cached : [];
    }

    /**
     * Build the top-level node list (id => label) from a WP_Admin_Bar. A node is
     * top-level when it sits directly under a root/group container.
     *
     * @return array<string, string>
     */
    private function build_admin_bar_list(WP_Admin_Bar $bar): array
    {
        $nodes = $bar->get_nodes();
        if (!is_array($nodes)) {
            return [];
        }

        $list = [];
        foreach ($nodes as $node) {
            if (!empty($node->group)) {
                continue; // skip layout group containers
            }

            $parent = isset($node->parent) ? $node->parent : '';
            $is_top = empty($parent)
                || (isset($nodes[$parent]) && !empty($nodes[$parent]->group))
                || in_array((string) $parent, ['root', 'root-default', 'top-secondary'], true);
            if (!$is_top) {
                continue;
            }

            $id = isset($node->id) ? (string) $node->id : '';
            if ('' === $id) {
                continue;
            }
            $label = trim(wp_strip_all_tags(isset($node->title) ? (string) $node->title : ''));
            $list[$id] = '' !== $label ? $label : $id;
        }

        return $list;
    }

    /**
     * Include a view partial with the given data in scope.
     *
     * @param array<string, mixed> $data
     */
    private function render_view(string $view, array $data): void
    {
        $view_file = __DIR__ . '/views/' . $view . '.php';
        if (!is_readable($view_file)) {
            return;
        }

        $render = static function () use ($view_file, $data) {
            // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- controlled, internal view data.
            extract($data);
            include $view_file;
        };
        $render();
    }

    /**
     * Persist the settings form.
     */
    public function handle_save(): void
    {
        if (
            !current_user_can('manage_woocommerce')
            || (White_Label_Access::restrictions_active() && !White_Label_Access::current_user_is_exempt())
        ) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'ffl-funnels-addons'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $submitted        = isset($_POST['ffla_wl']) && is_array($_POST['ffla_wl']) ? wp_unslash($_POST['ffla_wl']) : [];
        $raw_styles       = isset($submitted['styles']) && is_array($submitted['styles']) ? $submitted['styles'] : [];
        $raw_restrictions = isset($submitted['restrictions']) && is_array($submitted['restrictions']) ? $submitted['restrictions'] : [];

        $raw_menu = isset($submitted['menu']) && is_array($submitted['menu']) ? $submitted['menu'] : [];

        $raw_dashboard = isset($submitted['dashboard']) && is_array($submitted['dashboard']) ? $submitted['dashboard'] : [];

        $settings                 = White_Label_Settings::all();
        $settings['styles']       = $this->sanitize_styles($raw_styles);
        $settings['restrictions'] = $this->sanitize_restrictions($raw_restrictions);
        $settings['menu']         = $this->sanitize_menu($raw_menu);
        $settings['dashboard']    = $this->sanitize_dashboard($raw_dashboard);
        White_Label_Settings::save($settings);
        White_Label_Access::flush_cache();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $tab = isset($_POST['active_tab']) ? sanitize_key(wp_unslash($_POST['active_tab'])) : 'styles';

        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'settings-updated' => '1', 'tab' => $tab],
            admin_url('admin.php')
        ));
        exit;
    }

    /* =====================================================================
     * Import / Export
     * ================================================================== */

    /**
     * The export envelope: a marker, plugin version, timestamp, and the full
     * saved settings. The marker/version let import validate the file.
     *
     * @return array<string, mixed>
     */
    private function build_export_payload(): array
    {
        return [
            'marker'   => self::EXPORT_MARKER,
            'version'  => defined('FFLA_VERSION') ? FFLA_VERSION : '',
            'exported' => gmdate('c'),
            'site'     => home_url('/'),
            'settings' => White_Label_Settings::all(),
        ];
    }

    /**
     * Stream the current settings as a downloadable .json file.
     */
    public function handle_export(): void
    {
        if (
            !current_user_can('manage_woocommerce')
            || (White_Label_Access::restrictions_active() && !White_Label_Access::current_user_is_exempt())
        ) {
            wp_die(esc_html__('You do not have permission to export these settings.', 'ffl-funnels-addons'));
        }
        check_admin_referer(self::EXPORT_ACTION);

        $json = wp_json_encode($this->build_export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $host     = wp_parse_url(home_url(), PHP_URL_HOST);
        $host     = is_string($host) ? preg_replace('/[^A-Za-z0-9.\-]/', '', $host) : 'site';
        $filename = 'white-label-' . $host . '-' . gmdate('Ymd') . '.json';

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen((string) $json));

        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
        exit;
    }

    /**
     * Import settings from an uploaded .json file or pasted JSON. The incoming
     * data is never trusted: it is decoded, then every section is run through the
     * same sanitisers the save form uses before it is persisted.
     */
    public function handle_import(): void
    {
        if (
            !current_user_can('manage_woocommerce')
            || (White_Label_Access::restrictions_active() && !White_Label_Access::current_user_is_exempt())
        ) {
            wp_die(esc_html__('You do not have permission to import these settings.', 'ffl-funnels-addons'));
        }
        check_admin_referer(self::IMPORT_NONCE_ACTION, self::IMPORT_NONCE_FIELD);

        $raw = $this->read_import_payload();
        if ('' === $raw) {
            $this->redirect_after_import('empty');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->redirect_after_import('invalid');
        }

        // Accept either the export envelope or a bare settings array.
        if (isset($decoded['marker']) && self::EXPORT_MARKER === $decoded['marker']) {
            $incoming = isset($decoded['settings']) && is_array($decoded['settings']) ? $decoded['settings'] : null;
        } elseif (isset($decoded['styles']) || isset($decoded['restrictions']) || isset($decoded['menu']) || isset($decoded['dashboard'])) {
            $incoming = $decoded;
        } else {
            $incoming = null;
        }

        if (null === $incoming) {
            $this->redirect_after_import('invalid');
        }

        White_Label_Settings::save($this->sanitize_import_settings($incoming));
        White_Label_Access::flush_cache();

        $this->redirect_after_import('success');
    }

    /**
     * Pull the JSON to import from the uploaded file, falling back to the pasted
     * textarea. Returns '' when neither is present.
     */
    private function read_import_payload(): string
    {
        if (
            isset($_FILES['ffla_wl_import_file']['tmp_name'], $_FILES['ffla_wl_import_file']['error'])
            && UPLOAD_ERR_OK === (int) $_FILES['ffla_wl_import_file']['error']
            && is_uploaded_file($_FILES['ffla_wl_import_file']['tmp_name'])
        ) {
            $contents = file_get_contents($_FILES['ffla_wl_import_file']['tmp_name']);
            if (is_string($contents) && '' !== trim($contents)) {
                return trim($contents);
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in caller.
        if (isset($_POST['ffla_wl_import_json'])) {
            // Raw JSON: unslash but do not sanitise here; json_decode + the
            // per-section sanitisers handle it safely downstream.
            return trim((string) wp_unslash($_POST['ffla_wl_import_json']));
        }

        return '';
    }

    /**
     * Run an imported settings array through the same per-section sanitisers the
     * save form uses, so imported data is held to the identical standard.
     *
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function sanitize_import_settings(array $incoming): array
    {
        // Import is a replacement operation, as stated in the UI. Start from
        // clean defaults so omitted or empty sections cannot retain settings
        // from the site that receives the import.
        $styles       = isset($incoming['styles']) && is_array($incoming['styles']) ? $incoming['styles'] : [];
        $restrictions = isset($incoming['restrictions']) && is_array($incoming['restrictions']) ? $incoming['restrictions'] : [];
        $menu         = isset($incoming['menu']) && is_array($incoming['menu']) ? $incoming['menu'] : [];
        $dashboard    = isset($incoming['dashboard']) && is_array($incoming['dashboard']) ? $incoming['dashboard'] : [];

        // sanitize_restrictions expects the form shape (exempt_emails as text).
        if (isset($restrictions['exempt_emails']) && is_array($restrictions['exempt_emails'])) {
            $restrictions['exempt_emails'] = implode("\n", $restrictions['exempt_emails']);
        }

        return [
            'styles'       => $this->sanitize_styles($styles),
            'restrictions' => $this->sanitize_restrictions($restrictions),
            'menu'         => $this->sanitize_menu($menu),
            'dashboard'    => $this->sanitize_dashboard($dashboard),
        ];
    }

    /**
     * Redirect back to the Import / Export tab with a status flag.
     */
    private function redirect_after_import(string $status): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'tab' => 'import-export', 'ffla_wl_import' => $status],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * The import result notice for the view, read from the redirect flag.
     *
     * @return array{type: string, message: string}|null
     */
    private function import_notice(): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI state.
        if (!isset($_GET['ffla_wl_import'])) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI state.
        switch (sanitize_key(wp_unslash($_GET['ffla_wl_import']))) {
            case 'success':
                return ['type' => 'success', 'message' => __('Settings imported.', 'ffl-funnels-addons')];
            case 'empty':
                return ['type' => 'error', 'message' => __('No file or JSON was provided.', 'ffl-funnels-addons')];
            case 'invalid':
                return ['type' => 'error', 'message' => __('That file is not a valid White Label export.', 'ffl-funnels-addons')];
            default:
                return null;
        }
    }

    /**
     * Keep only known style keys holding a valid hex colour.
     *
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private function sanitize_styles(array $raw): array
    {
        $clean = ['light' => [], 'dark' => []];

        foreach (['light', 'dark'] as $mode) {
            $set = isset($raw[$mode]) && is_array($raw[$mode]) ? $raw[$mode] : [];
            foreach ($this->get_style_fields() as $fields) {
                foreach (array_keys($fields) as $key) {
                    $value = isset($set[$key]) ? trim((string) $set[$key]) : '';
                    if ('' !== $value && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
                        $clean[$mode][$key] = $value;
                    }
                }
            }
        }

        return $clean;
    }

    /**
     * Sanitise the restriction settings (exempt email patterns + hidden menu).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitize_restrictions(array $raw): array
    {
        // Exempt email patterns, one per line. Kept as text so "*" survives.
        $emails = [];
        $raw_emails = isset($raw['exempt_emails']) ? (string) $raw['exempt_emails'] : '';
        foreach (preg_split('/[\r\n]+/', $raw_emails) ?: [] as $line) {
            $line = sanitize_text_field($line);
            if ('' !== $line) {
                $emails[] = $line;
            }
        }

        // Hidden menu slugs from the checkbox tree.
        $hidden = [];
        $raw_hidden = isset($raw['hidden_menu']) && is_array($raw['hidden_menu']) ? $raw['hidden_menu'] : [];
        foreach ($raw_hidden as $slug) {
            $slug = $this->sanitize_menu_slug((string) $slug);
            if ('' !== $slug) {
                $hidden[] = $slug;
            }
        }

        // Admin-bar node IDs to remove.
        $adminbar = [];
        $raw_adminbar = isset($raw['hidden_adminbar']) && is_array($raw['hidden_adminbar']) ? $raw['hidden_adminbar'] : [];
        foreach ($raw_adminbar as $id) {
            $id = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $id);
            if ('' !== $id) {
                $adminbar[] = $id;
            }
        }

        return [
            'exempt_emails'   => array_values(array_unique($emails)),
            'hidden_menu'     => array_values(array_unique($hidden)),
            'hidden_adminbar' => array_values(array_unique($adminbar)),
        ];
    }

    /**
     * Sanitise the menu-ordering settings (top-level slug order).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function sanitize_menu(array $raw): array
    {
        $top = [];
        $raw_top = isset($raw['top']) && is_array($raw['top']) ? $raw['top'] : [];
        foreach ($raw_top as $slug) {
            $slug = $this->sanitize_menu_slug((string) $slug);
            if ('' !== $slug) {
                $top[] = $slug;
            }
        }

        return ['top' => array_values(array_unique($top))];
    }

    /**
     * Dashboard settings with defaults filled in.
     *
     * @return array{enabled: bool, links: array<string, string>}
     */
    private function get_dashboard_settings(): array
    {
        $saved = White_Label_Settings::get('dashboard', []);
        $saved = is_array($saved) ? $saved : [];
        $links = isset($saved['links']) && is_array($saved['links']) ? $saved['links'] : [];

        return [
            'enabled' => !empty($saved['enabled']),
            'links'   => [
                'support'        => (string) ($links['support'] ?? ''),
                'knowledge_base' => (string) ($links['knowledge_base'] ?? ''),
                'cockpit'        => (string) ($links['cockpit'] ?? ''),
                'command_center' => (string) ($links['command_center'] ?? ''),
            ],
        ];
    }

    /**
     * Sanitise the dashboard settings (enable flag + four card URLs).
     *
     * @param array<string, mixed> $raw
     * @return array{enabled: bool, links: array<string, string>}
     */
    private function sanitize_dashboard(array $raw): array
    {
        $links = isset($raw['links']) && is_array($raw['links']) ? $raw['links'] : [];

        return [
            'enabled' => !empty($raw['enabled']),
            'links'   => [
                'support'        => esc_url_raw((string) ($links['support'] ?? '')),
                'knowledge_base' => esc_url_raw((string) ($links['knowledge_base'] ?? '')),
                'cockpit'        => esc_url_raw((string) ($links['cockpit'] ?? '')),
                'command_center' => esc_url_raw((string) ($links['command_center'] ?? '')),
            ],
        ];
    }

    /**
     * Sanitise an admin menu slug — a file/page reference that may carry a query
     * string and a "parent::child" separator.
     */
    private function sanitize_menu_slug(string $slug): string
    {
        $slug = trim($slug);

        return (string) preg_replace('/[^A-Za-z0-9_.\-\/=?&%:]/', '', $slug);
    }

    /**
     * The tab to show on load, from the query string, falling back to the first.
     */
    private function get_active_tab(): string
    {
        $tabs = $this->get_tabs();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI state.
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'styles';

        return isset($tabs[$tab]) ? $tab : 'styles';
    }

    /**
     * Whether we just came back from a successful save.
     */
    private function just_saved(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only UI state.
        return isset($_GET['settings-updated']) && '1' === sanitize_text_field(wp_unslash($_GET['settings-updated']));
    }
}
