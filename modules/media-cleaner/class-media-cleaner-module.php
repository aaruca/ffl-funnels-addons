<?php
/**
 * Media Cleaner Module — entry point.
 *
 * Finds and safely removes unused, broken, orphaned, and duplicate media.
 * Understands the full Bricks surface and this plugin's own image references,
 * so it never flags a design asset, a customer review photo, or a bundle image
 * as unused.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Module extends FFLA_Module
{
    /** @var Media_Cleaner_Admin|null */
    private $admin;

    public function get_id(): string
    {
        return 'media-cleaner';
    }

    public function get_name(): string
    {
        return __('Media Cleaner', 'ffl-funnels-addons');
    }

    public function get_description(): string
    {
        return __('Find and safely remove unused, broken, and duplicate media. Bricks-aware and aware of this plugin\'s own images, with a reversible trash.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>';
    }

    public function boot(): void
    {
        $base = $this->get_path();

        require_once $base . 'includes/class-media-cleaner-database.php';
        require_once $base . 'includes/class-media-cleaner-core.php';
        require_once $base . 'includes/class-media-cleaner-trash.php';
        require_once $base . 'includes/class-media-cleaner-manager.php';
        require_once $base . 'includes/class-media-cleaner-engine.php';
        require_once $base . 'includes/class-media-cleaner-scanner.php';
        require_once $base . 'includes/class-media-cleaner-parsers.php';
        require_once $base . 'includes/class-media-cleaner-cron.php';

        // Shared core instance the parsers reach via the global.
        $GLOBALS['ffla_mclean'] = new Media_Cleaner_Core();

        // Register every parser's hooks.
        Media_Cleaner_Parsers::load($base . 'parsers');

        Media_Cleaner_Cron::init();

        if (is_admin()) {
            require_once $base . 'includes/class-media-cleaner-ajax.php';
            require_once $base . 'admin/class-media-cleaner-admin.php';

            Media_Cleaner_Ajax::init();
            $this->admin = new Media_Cleaner_Admin();
            $this->admin->init();
        }

        if (defined('WP_CLI') && WP_CLI) {
            require_once $base . 'includes/class-media-cleaner-cli.php';
        }
    }

    public function activate(): void
    {
        require_once $this->get_path() . 'includes/class-media-cleaner-database.php';
        require_once $this->get_path() . 'includes/class-media-cleaner-core.php';
        require_once $this->get_path() . 'includes/class-media-cleaner-trash.php';
        require_once $this->get_path() . 'includes/class-media-cleaner-cron.php';

        Media_Cleaner_Database::install();
        Media_Cleaner_Trash::ensure_dir();

        $current = get_option(Media_Cleaner_Core::OPTION, []);
        if (!is_array($current)) {
            $current = [];
        }
        update_option(
            Media_Cleaner_Core::OPTION,
            wp_parse_args($current, Media_Cleaner_Core::get_default_settings()),
            false
        );

        Media_Cleaner_Cron::reschedule();
    }

    public function deactivate(): void
    {
        require_once $this->get_path() . 'includes/class-media-cleaner-cron.php';
        Media_Cleaner_Cron::clear();
        // Data (issues, settings, trash) is intentionally preserved.
    }

    public function get_admin_pages(): array
    {
        return [
            [
                // Literal (not Media_Cleaner_Admin::PAGE_SLUG) so this never
                // depends on the admin class being loaded.
                'slug'  => 'ffla-media-cleaner',
                'title' => __('Media Cleaner', 'ffl-funnels-addons'),
                'icon'  => $this->get_icon_svg(),
            ],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        if ($this->admin) {
            $this->admin->render_settings_content();
        }
    }
}
