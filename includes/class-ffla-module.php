<?php
/**
 * Abstract Module base class.
 *
 * Every module (WooBooster, Wishlist, Doofinder) extends this class
 * and registers itself with the Module Registry.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class FFLA_Module
{
    /**
     * Unique module identifier (e.g. 'woobooster', 'wishlist', 'doofinder-sync').
     */
    abstract public function get_id(): string;

    /**
     * Human-readable module name.
     */
    abstract public function get_name(): string;

    /**
     * Short description shown on the dashboard card.
     */
    abstract public function get_description(): string;

    /**
     * Inline SVG icon for the dashboard card.
     */
    abstract public function get_icon_svg(): string;

    /**
     * Module version string.
     */
    abstract public function get_version(): string;

    /**
     * Boot the module — register hooks, load classes.
     * Only called when the module is active.
     */
    abstract public function boot(): void;

    /**
     * Run on module activation (create tables, options, etc.).
     */
    abstract public function activate(): void;

    /**
     * Run on module deactivation (clean up cron, transients, etc.).
     */
    abstract public function deactivate(): void;

    /**
     * Return admin sub-pages to register under the main FFLA menu.
     *
     * Each entry: [
     *   'slug'     => 'ffla-woobooster',
     *   'title'    => 'WB Settings',
     *   'callback' => callable,
     * ]
     *
     * @return array
     */
    abstract public function get_admin_pages(): array;

    /**
     * Render the content for a given admin page slug.
     * The shared shell (header, sidebar, footer) is already rendered by FFLA_Admin.
     */
    abstract public function render_admin_page(string $page_slug): void;

    /**
     * Get the module's base path.
     */
    public function get_path(): string
    {
        return FFLA_PATH . 'modules/' . $this->get_id() . '/';
    }

    /**
     * Get the module's base URL.
     */
    public function get_url(): string
    {
        return FFLA_URL . 'modules/' . $this->get_id() . '/';
    }
}
