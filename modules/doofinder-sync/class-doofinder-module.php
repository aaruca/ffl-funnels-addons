<?php
/**
 * Doofinder Sync Module — Entry Point.
 *
 * Extends FFLA_Module. Refactored from procedural doofinder-sync.php.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Doofinder_Module extends FFLA_Module
{
    private $admin;

    public function get_id(): string
    {
        return 'doofinder-sync';
    }

    public function get_name(): string
    {
        return 'Doofinder Sync';
    }

    public function get_description(): string
    {
        return 'Dynamically injects product meta (categories, brands, discounts) for Doofinder search indexing.';
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
    }

    public function get_version(): string
    {
        return '2.4.1';
    }

    /* ── Boot ──────────────────────────────────────────────────────── */

    public function boot(): void
    {
        $base = $this->get_path();

        // Core meta injection logic.
        require_once $base . 'includes/class-doofinder-core.php';
        Doofinder_Core::init();

        // Admin debug page.
        if (is_admin()) {
            require_once $base . 'admin/class-doofinder-admin.php';
            $this->admin = new Doofinder_Admin();
        }
    }

    /* ── Activation / Deactivation ─────────────────────────────────── */

    public function activate(): void
    {
        // No tables or options to create.
    }

    public function deactivate(): void
    {
        // No cleanup needed.
    }

    /* ── Admin Pages ───────────────────────────────────────────────── */

    public function get_admin_pages(): array
    {
        return [
            [
                'slug'  => 'ffla-doofinder-debug',
                'title' => 'Doofinder Debug',
            ],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        if ($this->admin) {
            $this->admin->render_debug_content();
        }
    }
}
