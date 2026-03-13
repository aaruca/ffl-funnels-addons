<?php
/**
 * FFL Checkout Admin — Settings page.
 *
 * Renders configuration for Mapbox address autocomplete and vendor selector.
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

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $settings = [
            'mapbox_public_token'      => sanitize_text_field(wp_unslash($_POST['mapbox_public_token'] ?? '')),
            'autocomplete_enabled'     => isset($_POST['autocomplete_enabled']) ? '1' : '0',
            'vendor_selector_enabled'  => isset($_POST['vendor_selector_enabled']) ? '1' : '0',
        ];
        // phpcs:enable

        update_option(self::OPTION_KEY, $settings);

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
        $s = wp_parse_args(get_option(self::OPTION_KEY, []), [
            'mapbox_public_token'      => '',
            'autocomplete_enabled'     => '0',
            'vendor_selector_enabled'  => '0',
        ]);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['saved'])) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ffl_checkout_save_settings">';
        wp_nonce_field('ffl_checkout_save_settings', 'ffl_checkout_nonce');

        // ── Mapbox Card ──────────────────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Mapbox Settings', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-section-desc">' . esc_html__('Your Mapbox token powers the address autocomplete on checkout fields.', 'ffl-funnels-addons') . '</p>';

        FFLA_Admin::render_password_field(
            __('Mapbox Public Token', 'ffl-funnels-addons'),
            'mapbox_public_token',
            $s['mapbox_public_token'],
            __('Your Mapbox public access token (starts with pk.eyJ1). Found in the Mapbox Dashboard > Account > Access Tokens.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Enable Address Autocomplete', 'ffl-funnels-addons'),
            'autocomplete_enabled',
            $s['autocomplete_enabled'],
            __('Show address suggestions as customers type in billing/shipping address fields.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        // ── Vendor Selector Card ─────────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Vendor Selector', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-section-desc">' . esc_html__('Allow customers to change vendor/warehouse for eligible products at checkout. Use the [ffl_vendor_selector] shortcode in your Bricks checkout template.', 'ffl-funnels-addons') . '</p>';

        FFLA_Admin::render_toggle_field(
            __('Enable Vendor Selector', 'ffl-funnels-addons'),
            'vendor_selector_enabled',
            $s['vendor_selector_enabled'],
            __('Show vendor selection for eligible products (requires g-FFL Cockpit API key).', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        // ── Shortcodes Info Card ─────────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Shortcodes', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-section-desc">' . esc_html__('Place these shortcodes in your Bricks checkout template:', 'ffl-funnels-addons') . '</p>';
        echo '<p><code>[ffl_dealer_finder]</code> &mdash; ' . esc_html__('FFL Dealer Finder widget (requires g-FFL Checkout plugin).', 'ffl-funnels-addons') . '</p>';
        echo '<p><code>[ffl_vendor_selector]</code> &mdash; ' . esc_html__('Vendor/warehouse selector for eligible cart items.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';

        // ── Save Button ─────────────────────────────────────────────────
        echo '<div style="padding-top: var(--wb-spacing-lg);">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';
    }
}
