<?php
/**
 * FFL Checkout Admin — Settings page.
 *
 * Renders configuration panels for:
 * - Mapbox address autocomplete
 * - FFL Dealer Finder widget (map, messages, local pickup, C&R, colors)
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
            // ── Mapbox Autocomplete ──
            'mapbox_public_token'  => sanitize_text_field(wp_unslash($_POST['mapbox_public_token'] ?? '')),
            'autocomplete_enabled' => isset($_POST['autocomplete_enabled']) ? '1' : '0',

            // ── Dealer Finder Widget ──
            'include_map'          => isset($_POST['include_map']) ? '1' : '0',
            'checkout_message'     => wp_kses_post(wp_unslash($_POST['checkout_message'] ?? '')),
            'ammo_checkout_message' => wp_kses_post(wp_unslash($_POST['ammo_checkout_message'] ?? '')),
            'required_notice_text' => wp_kses_post(wp_unslash($_POST['required_notice_text'] ?? '')),

            // ── Local Pickup ──
            'local_pickup_license' => sanitize_text_field(wp_unslash($_POST['local_pickup_license'] ?? '')),

            // ── C&R Override ──
            'candr_enabled'        => isset($_POST['candr_enabled']) ? '1' : '0',

            // ── Colors ──
            'checkout_message_bg_color'   => sanitize_hex_color($_POST['checkout_message_bg_color'] ?? '#FFFFF0'),
            'checkout_message_text_color' => sanitize_hex_color($_POST['checkout_message_text_color'] ?? '#000000'),
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
            'mapbox_public_token'         => '',
            'autocomplete_enabled'        => '0',
            'include_map'                 => '1',
            'checkout_message'            => '<b>Federal law dictates that your online firearms purchase must be delivered to a federally licensed firearms dealer (FFL) before you can take possession.</b> This process is called a Transfer. Enter your zip code, radius, and FFL name (optional), then click the Find button to get a list of FFL dealers in your area. Select the FFL dealer you want the firearm shipped to. <b><u>Before Checking Out, Contact your selected FFL dealer to confirm they are currently accepting transfers</u></b>. You can also confirm transfer costs.',
            'ammo_checkout_message'       => '',
            'required_notice_text'        => 'REQUIRED: You must search for and select an FFL dealer to complete your order',
            'local_pickup_license'        => '',
            'candr_enabled'               => '0',
            'checkout_message_bg_color'   => '#FFFFF0',
            'checkout_message_text_color' => '#000000',
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
        echo '<p class="wb-section-desc">' . esc_html__('Your Mapbox token powers both the address autocomplete on checkout fields and the dealer finder map.', 'ffl-funnels-addons') . '</p>';

        FFLA_Admin::render_password_field(
            __('Mapbox Public Token', 'ffl-funnels-addons'),
            'mapbox_public_token',
            $s['mapbox_public_token'],
            __('Your Mapbox public access token (starts with pk.eyJ1). Found in the Mapbox Dashboard → Account → Access Tokens.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Enable Address Autocomplete', 'ffl-funnels-addons'),
            'autocomplete_enabled',
            $s['autocomplete_enabled'],
            __('Show address suggestions as customers type in billing/shipping address fields.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Show Dealer Map', 'ffl-funnels-addons'),
            'include_map',
            $s['include_map'],
            __('Display a Mapbox map with FFL dealer pins in the dealer finder widget. Disable to show a list-only view.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        // ── Dealer Finder Messages ──────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Dealer Finder Messages', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_text_field(
            __('Required Notice', 'ffl-funnels-addons'),
            'required_notice_text',
            $s['required_notice_text'],
            __('Red banner shown above the dealer finder when FFL selection is required.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_textarea_field(
            __('Checkout Message (Firearms)', 'ffl-funnels-addons'),
            'checkout_message',
            $s['checkout_message'],
            __('Instructional text shown above the search form. Supports HTML. Customize the store email address and transfer instructions.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_textarea_field(
            __('Checkout Message (Ammo/Compliance)', 'ffl-funnels-addons'),
            'ammo_checkout_message',
            $s['ammo_checkout_message'],
            __('Message shown when the checkout is triggered by ammo/state compliance instead of firearms. Leave blank to use the firearms message.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_color_field(
            __('Message Background', 'ffl-funnels-addons'),
            'checkout_message_bg_color',
            $s['checkout_message_bg_color'],
            __('Background color for the checkout message box.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_color_field(
            __('Message Text Color', 'ffl-funnels-addons'),
            'checkout_message_text_color',
            $s['checkout_message_text_color'],
            __('Text color for the checkout message box.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        // ── Special Features ────────────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Special Features', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_text_field(
            __('Local Pickup FFL License', 'ffl-funnels-addons'),
            'local_pickup_license',
            $s['local_pickup_license'],
            __('Enter your store\'s FFL license number (20 characters) to show a "Click Here for In Store Pickup" button. Leave blank to hide.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Enable C&R Override', 'ffl-funnels-addons'),
            'candr_enabled',
            $s['candr_enabled'],
            __('Allow customers with a Curio & Relic (C&R) license to upload it and bypass FFL selection.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        // ── Save Button ─────────────────────────────────────────────────
        echo '<div style="padding-top: var(--wb-spacing-lg);">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';
    }
}
