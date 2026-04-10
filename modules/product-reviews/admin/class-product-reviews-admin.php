<?php
/**
 * Product Reviews Admin.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Admin
{
    public function init(): void
    {
        add_action('admin_post_product_reviews_save_settings', [$this, 'handle_settings_save']);
    }

    public function render_settings_content(): void
    {
        $settings = Product_Reviews_Core::get_settings();
        $saved    = isset($_GET['settings-updated']) && '1' === sanitize_text_field(wp_unslash($_GET['settings-updated']));

        if ($saved) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="product_reviews_save_settings">';
        wp_nonce_field('product_reviews_save_settings_nonce', '_product_reviews_nonce');

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Review Collection', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Enable review requests', 'ffl-funnels-addons'),
            'enable_requests',
            $settings['enable_requests'] ?? '1',
            __('Send post-purchase emails asking customers to review purchased products.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Delay after completed order (days)', 'ffl-funnels-addons'),
            'request_delay_days',
            $settings['request_delay_days'] ?? '7',
            __('How many days to wait before sending a review request email.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Enable helpful votes', 'ffl-funnels-addons'),
            'enable_helpful_votes',
            $settings['enable_helpful_votes'] ?? '1',
            __('Allow customers to mark a review as helpful.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Hide default Woo reviews tab', 'ffl-funnels-addons'),
            'hide_default_reviews_tab',
            $settings['hide_default_reviews_tab'] ?? '0',
            __('Enable this if you are rendering reviews only with Bricks elements.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Cloudflare Turnstile', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Enable Turnstile on review forms', 'ffl-funnels-addons'),
            'enable_turnstile',
            $settings['enable_turnstile'] ?? '0',
            __('Requires valid Cloudflare site key and secret key. Applied to product review submissions.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Turnstile Site Key', 'ffl-funnels-addons'),
            'turnstile_site_key',
            $settings['turnstile_site_key'] ?? '',
            __('Public key used by the frontend widget.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_password_field(
            __('Turnstile Secret Key', 'ffl-funnels-addons'),
            'turnstile_secret_key',
            $settings['turnstile_secret_key'] ?? '',
            __('Private key used for server-side verification.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Email Template', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_text_field(
            __('Email subject', 'ffl-funnels-addons'),
            'email_subject',
            $settings['email_subject'] ?? '',
            __('Example: How was your purchase?', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Email heading', 'ffl-funnels-addons'),
            'email_heading',
            $settings['email_heading'] ?? '',
            __('Appears as the first line in the email body.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_textarea_field(
            __('Email body template', 'ffl-funnels-addons'),
            'email_template',
            $settings['email_template'] ?? '',
            __('Available placeholders: {customer_name}, {product_name}, {review_url}, {order_id}.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Bricks Elements', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__('The module registers 3 native Bricks elements: Reviews Rating Badge, Reviews List, and Review Form.', 'ffl-funnels-addons') . '</p>';
        echo '<p class="wb-field__desc">' . esc_html__('These elements are grouped under "FFL Funnels - Product Reviews".', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';

        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';
    }

    public function handle_settings_save(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        check_admin_referer('product_reviews_save_settings_nonce', '_product_reviews_nonce');

        $current = Product_Reviews_Core::get_settings();
        $new     = $current;

        $new['enable_requests'] = isset($_POST['enable_requests']) ? '1' : '0';
        $new['enable_helpful_votes'] = isset($_POST['enable_helpful_votes']) ? '1' : '0';
        $new['hide_default_reviews_tab'] = isset($_POST['hide_default_reviews_tab']) ? '1' : '0';
        $new['enable_turnstile'] = isset($_POST['enable_turnstile']) ? '1' : '0';

        $new['request_delay_days'] = isset($_POST['request_delay_days'])
            ? (string) max(0, absint($_POST['request_delay_days']))
            : '7';

        $new['email_subject'] = isset($_POST['email_subject'])
            ? sanitize_text_field(wp_unslash($_POST['email_subject']))
            : '';
        $new['email_heading'] = isset($_POST['email_heading'])
            ? sanitize_text_field(wp_unslash($_POST['email_heading']))
            : '';
        $new['email_template'] = isset($_POST['email_template'])
            ? sanitize_textarea_field(wp_unslash($_POST['email_template']))
            : '';
        $new['turnstile_site_key'] = isset($_POST['turnstile_site_key'])
            ? sanitize_text_field(wp_unslash($_POST['turnstile_site_key']))
            : '';
        $new['turnstile_secret_key'] = isset($_POST['turnstile_secret_key'])
            ? sanitize_text_field(wp_unslash($_POST['turnstile_secret_key']))
            : '';

        update_option('ffla_product_reviews_settings', $new);

        wp_safe_redirect(add_query_arg(
            ['page' => 'ffla-product-reviews', 'settings-updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }
}
