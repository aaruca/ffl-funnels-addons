<?php
/**
 * FFL Checkout Admin — Settings page.
 *
 * Renders the Radar.com configuration panel inside the FFLA admin shell.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Admin
{
    /** Option key used to store all FFL Checkout settings. */
    const OPTION_KEY = 'ffl_checkout_settings';

    /**
     * Register hooks for saving settings.
     */
    public function init(): void
    {
        add_action('admin_post_ffl_checkout_save_settings', [$this, 'save_settings']);
    }

    /**
     * Handle form submission — save settings.
     */
    public function save_settings(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        check_admin_referer('ffl_checkout_save_settings', 'ffl_checkout_nonce');

        $settings = [
            'radar_publishable_key' => isset($_POST['radar_publishable_key'])
                ? sanitize_text_field(wp_unslash($_POST['radar_publishable_key']))
                : '',
            'autocomplete_enabled'  => isset($_POST['autocomplete_enabled']) ? '1' : '0',
        ];

        update_option(self::OPTION_KEY, $settings);

        // Redirect back with success notice.
        wp_safe_redirect(add_query_arg(
            ['page' => 'ffla-ffl-checkout', 'saved' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Render the settings page content (inside the FFLA admin shell).
     */
    public function render_settings_page(): void
    {
        $settings = get_option(self::OPTION_KEY, []);
        $key      = $settings['radar_publishable_key'] ?? '';
        $enabled  = $settings['autocomplete_enabled'] ?? '0';

        // Show saved notice.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['saved'])) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        // ── Settings Card ─────────────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Radar Address Autocomplete', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-section-desc">' . esc_html__('Configure Radar.com address autocomplete for WooCommerce checkout fields. When enabled, the billing and shipping address fields will show address suggestions as the customer types.', 'ffl-funnels-addons') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ffl_checkout_save_settings">';
        wp_nonce_field('ffl_checkout_save_settings', 'ffl_checkout_nonce');

        FFLA_Admin::render_password_field(
            __('Radar Publishable Key', 'ffl-funnels-addons'),
            'radar_publishable_key',
            $key,
            __('Your Radar.com project publishable key (starts with prj_live_pk_ or prj_test_pk_). Found in the Radar Dashboard → Settings → API Keys.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Enable Autocomplete', 'ffl-funnels-addons'),
            'autocomplete_enabled',
            $enabled,
            __('Enable Radar address autocomplete on WooCommerce checkout billing and shipping address fields.', 'ffl-funnels-addons')
        );

        echo '<div class="wb-field" style="padding-top: var(--wb-spacing-lg);">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div></div>'; // end card

        // ── Instructions Card ─────────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Setup Instructions', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<ol style="margin:0 0 0 1.25em; line-height:1.8;">';
        echo '<li>' . esc_html__('Create a free account at', 'ffl-funnels-addons') . ' <a href="https://radar.com" target="_blank" rel="noopener">radar.com</a></li>';
        echo '<li>' . esc_html__('Go to Settings → API Keys and copy your Publishable Key.', 'ffl-funnels-addons') . '</li>';
        echo '<li>' . esc_html__('Paste the key above and enable autocomplete.', 'ffl-funnels-addons') . '</li>';
        echo '<li>' . esc_html__('Visit your WooCommerce checkout page — address suggestions should appear when typing in the street address field.', 'ffl-funnels-addons') . '</li>';
        echo '</ol>';
        echo '</div></div>'; // end card
    }
}
