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

        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="request_email_mode">' . esc_html__('Review request email', 'ffl-funnels-addons') . '</label>';
        echo '<div class="wb-field__control">';
        $mode = $settings['request_email_mode'] ?? 'per_product';
        echo '<select class="wb-select" name="request_email_mode" id="request_email_mode">';
        echo '<option value="per_product"' . selected($mode, 'per_product', false) . '>' . esc_html__('One email per product (scheduled separately)', 'ffl-funnels-addons') . '</option>';
        echo '<option value="bundle"' . selected($mode, 'bundle', false) . '>' . esc_html__('One email per order (all products, single link)', 'ffl-funnels-addons') . '</option>';
        echo '</select>';
        echo '<p class="wb-field__desc">' . esc_html__('Bundle mode uses signed links to your hub page; placeholders {review_order_url} and {product_names_list} are recommended in the template.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';

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
            __('Turn on when you use only the Bricks Review Form / list / badge so shoppers do not see WooCommerce’s second, built-in reviews tab.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Hold all new reviews for moderation', 'ffl-funnels-addons'),
            'moderate_all_reviews',
            $settings['moderate_all_reviews'] ?? '0',
            __('When enabled, every product review stays pending until an administrator approves it. Useful with or without media uploads.', 'ffl-funnels-addons')
        );

        $mod_url = admin_url('edit-comments.php?comment_status=moderated');
        echo '<p class="wb-field__desc">';
        echo wp_kses(
            sprintf(
                /* translators: %s: URL to moderated comments screen */
                __('Pending reviews appear under Comments → Pending. <a href="%s">Open moderated queue</a>.', 'ffl-funnels-addons'),
                esc_url($mod_url)
            ),
            [
                'a' => [
                    'href' => [],
                ],
            ]
        );
        echo '</p>';

        echo '</div></div>';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Order review hub', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        $hub_page = absint($settings['order_review_page_id'] ?? 0);
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="order_review_page_id">' . esc_html__('Hub page', 'ffl-funnels-addons') . '</label>';
        echo '<div class="wb-field__control">';
        wp_dropdown_pages([
            'name'              => 'order_review_page_id',
            'id'                => 'order_review_page_id',
            'selected'          => $hub_page,
            'show_option_none'  => __('-- Select Page --', 'ffl-funnels-addons'),
            'option_none_value' => '0',
            'class'             => 'wb-select',
        ]);
        echo '<p class="wb-field__desc">' . esc_html__('Page that contains the [ffla_order_reviews] shortcode or the Bricks “Order reviews hub” element.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';

        FFLA_Admin::render_toggle_field(
            __('Pretty URLs for review links', 'ffl-funnels-addons'),
            'order_review_pretty_urls',
            $settings['order_review_pretty_urls'] ?? '0',
            __('Use /your-slug/{token}/ instead of ?ffla_ro= on the hub URL. Save settings once, then visit Settings → Permalinks if links 404.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Pretty URL slug', 'ffl-funnels-addons'),
            'order_review_rewrite_slug',
            $settings['order_review_rewrite_slug'] ?? 'order-review',
            __('Segment before the signed token, e.g. order-review → /order-review/{token}/.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Show extra rating criteria on hub forms', 'ffl-funnels-addons'),
            'order_review_show_criteria',
            $settings['order_review_show_criteria'] ?? '0',
            __('Adds optional Quality and Value star groups on each product form on the hub page.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Cloudflare Turnstile', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Enable Turnstile on review forms', 'ffl-funnels-addons'),
            'enable_turnstile',
            $settings['enable_turnstile'] ?? '0',
            __('Requires valid Cloudflare site key and secret key. Applies to the Bricks Review Form submission endpoint; the default Woo reviews tab is not modified.', 'ffl-funnels-addons')
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
            __('Placeholders: {customer_name}, {product_name}, {product_names_list}, {review_url}, {review_order_url}, {order_id}, {user_id}. Per-product mode uses {review_url}; bundle mode uses {review_order_url} and {product_names_list}.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Bricks Elements', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__('The module registers Bricks elements: Reviews Rating Badge, Reviews List, Review Form, and Order reviews hub.', 'ffl-funnels-addons') . '</p>';
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
        $new['moderate_all_reviews'] = isset($_POST['moderate_all_reviews']) ? '1' : '0';
        $new['enable_turnstile'] = isset($_POST['enable_turnstile']) ? '1' : '0';

        $new['request_delay_days'] = isset($_POST['request_delay_days'])
            ? (string) max(0, absint($_POST['request_delay_days']))
            : '7';

        $new['request_email_mode'] = (isset($_POST['request_email_mode']) && 'bundle' === $_POST['request_email_mode'])
            ? 'bundle'
            : 'per_product';

        $new['order_review_page_id'] = isset($_POST['order_review_page_id'])
            ? (string) absint($_POST['order_review_page_id'])
            : '0';

        $new['order_review_show_criteria'] = isset($_POST['order_review_show_criteria']) ? '1' : '0';
        $new['order_review_pretty_urls'] = isset($_POST['order_review_pretty_urls']) ? '1' : '0';

        $new['order_review_rewrite_slug'] = isset($_POST['order_review_rewrite_slug'])
            ? sanitize_title(wp_unslash($_POST['order_review_rewrite_slug']))
            : 'order-review';
        if ($new['order_review_rewrite_slug'] === '') {
            $new['order_review_rewrite_slug'] = 'order-review';
        }

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

        $rewrite_changed = ($current['order_review_pretty_urls'] ?? '') !== ($new['order_review_pretty_urls'] ?? '')
            || ($current['order_review_rewrite_slug'] ?? '') !== ($new['order_review_rewrite_slug'] ?? '');

        update_option('ffla_product_reviews_settings', $new);

        if ($rewrite_changed) {
            Product_Reviews_Core::register_order_review_rewrites();
            flush_rewrite_rules(false);
        }

        wp_safe_redirect(add_query_arg(
            ['page' => 'ffla-product-reviews', 'settings-updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }
}
