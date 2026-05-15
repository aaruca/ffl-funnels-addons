<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Admin
{
    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'ffla-loadouts') === false) {
            return;
        }

        wp_enqueue_script(
            'loadout-admin',
            plugins_url('js/loadout-admin.js', __FILE__),
            ['jquery'],
            FFLA_VERSION,
            true
        );

        wp_enqueue_style(
            'loadout-admin',
            plugins_url('css/loadout-admin.css', __FILE__),
            [],
            FFLA_VERSION
        );

        wp_localize_script('loadout-admin', 'loadoutAdmin', [
            'nonce' => wp_create_nonce('loadout_admin'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);
    }

    public function render_content(): void
    {
        // Stub for Phase 3
        echo '<div class="wrap"><h1>' . esc_html__('Loadouts', 'ffl-funnels-addons') . '</h1></div>';
    }
}
