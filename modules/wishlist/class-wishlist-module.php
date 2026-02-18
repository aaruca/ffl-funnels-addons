<?php
/**
 * Wishlist Module — Entry Point.
 *
 * Extends FFLA_Module. Replaces the old Alg_Wishlist_Loader.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wishlist_Module extends FFLA_Module
{
    private $admin;

    public function get_id(): string
    {
        return 'wishlist';
    }

    public function get_name(): string
    {
        return 'Wishlist';
    }

    public function get_description(): string
    {
        return 'High-performance Wishlist with guest support, Bricks Builder integration and Doofinder compatibility.';
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
    }

    public function get_version(): string
    {
        return '1.0.11';
    }

    /* ── Boot ──────────────────────────────────────────────────────── */

    public function boot(): void
    {
        $base = $this->get_path();

        // Core logic.
        require_once $base . 'includes/class-wishlist-core.php';

        // Frontend assets.
        require_once $base . 'includes/class-wishlist-assets.php';
        $assets = new Alg_Wishlist_Assets();
        add_action('wp_enqueue_scripts', [$assets, 'enqueue_scripts']);

        // Shortcodes.
        require_once $base . 'includes/class-wishlist-shortcodes.php';
        $shortcodes = new Alg_Wishlist_Shortcodes();
        add_action('init', [$shortcodes, 'register_shortcodes']);

        // AJAX.
        require_once $base . 'includes/class-wishlist-ajax.php';
        $ajax = new Alg_Wishlist_Ajax();
        add_action('wp_ajax_alg_add_to_wishlist', [$ajax, 'add_to_wishlist']);
        add_action('wp_ajax_nopriv_alg_add_to_wishlist', [$ajax, 'add_to_wishlist']);

        // Session.
        add_action('init', ['Alg_Wishlist_Core', 'init_session']);

        // Admin.
        if (is_admin()) {
            require_once $base . 'admin/class-wishlist-admin.php';
            $this->admin = new Wishlist_Admin();
            $this->admin->init();

            // DB health check.
            add_action('admin_init', [$this, 'verify_database_tables']);
        }

        // Integrations (after plugins_loaded).
        add_action('plugins_loaded', [$this, 'load_integrations'], 20);
    }

    /**
     * Load Bricks & Doofinder integrations.
     */
    public function load_integrations(): void
    {
        $base = $this->get_path();

        require_once $base . 'integrations/class-wishlist-bricks.php';
        Alg_Wishlist_Bricks::init();

        require_once $base . 'integrations/class-wishlist-doofinder.php';
        $doofinder = new Alg_Wishlist_Doofinder();
        add_action('wp_enqueue_scripts', [$doofinder, 'enqueue_compatibility_script']);
    }

    /**
     * Ensure tables exist (self-healing).
     */
    public function verify_database_tables(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'alg_wishlists';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            require_once $this->get_path() . 'includes/class-wishlist-activator.php';
            Alg_Wishlist_Activator::activate();
        }
    }

    /* ── Activation / Deactivation ─────────────────────────────────── */

    public function activate(): void
    {
        require_once $this->get_path() . 'includes/class-wishlist-activator.php';
        Alg_Wishlist_Activator::activate();
    }

    public function deactivate(): void
    {
        // Nothing to clean up on deactivate.
    }

    /* ── Admin Pages ───────────────────────────────────────────────── */

    public function get_admin_pages(): array
    {
        return [
            [
                'slug'  => 'ffla-wishlist',
                'title' => 'Wishlist Settings',
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
