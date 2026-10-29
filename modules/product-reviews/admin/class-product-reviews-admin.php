<?php
/**
 * Product Reviews Admin.
 *
 * Settings live on one page split into tabs (Product_Reviews_Admin_Fields).
 * Each tab is its own form and saves only its own keys.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-product-reviews-admin-fields.php';

class Product_Reviews_Admin
{
    const PAGE = 'ffla-product-reviews';

    public function init(): void
    {
        add_action('admin_post_product_reviews_save_settings', [$this, 'handle_settings_save']);
        add_action('admin_notices', [$this, 'render_missing_hub_notice']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_media_library']);
    }

    private static function requests_blocked_by_hub(): bool
    {
        return '1' === Product_Reviews_Core::get_setting('enable_requests', '1') && !Product_Reviews_Core::hub_page_ready();
    }

    private static function missing_hub_message(): string
    {
        return __('Review request emails are switched on, but no published Hub page is selected, so none are being scheduled or sent. Select the page that holds the [ffla_order_reviews] shortcode or the Bricks “Order reviews hub” element under Order review hub, or switch requests off.', 'ffl-funnels-addons');
    }

    public static function settings_url(string $tab = ''): string
    {
        $args = ['page' => self::PAGE];
        if ($tab !== '') {
            $args['tab'] = $tab;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    private static function current_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        $tabs = Product_Reviews_Admin_Fields::tabs();

        return isset($tabs[$tab]) ? $tab : (string) array_key_first($tabs);
    }

    /**
     * Media pickers (email logo) need the media frame on the settings page.
     */
    public function enqueue_media_library(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (self::PAGE === $page && function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }
    }

    /**
     * Shown everywhere in wp-admin except this module's settings page, which
     * carries the same warning inline.
     */
    public function render_missing_hub_notice(): void
    {
        if (!current_user_can('manage_woocommerce') || !self::requests_blocked_by_hub()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && false !== strpos((string) $screen->id, self::PAGE)) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('Product Reviews:', 'ffl-funnels-addons') . '</strong> '
            . esc_html(self::missing_hub_message())
            . ' <a href="' . esc_url(self::settings_url('requests')) . '">' . esc_html__('Open Product Reviews settings', 'ffl-funnels-addons') . '</a></p></div>';
    }

    public function render_settings_content(): void
    {
        $settings = Product_Reviews_Core::get_settings();
        $tab      = self::current_tab();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $saved    = isset($_GET['settings-updated']) && '1' === sanitize_text_field(wp_unslash($_GET['settings-updated']));

        if ($saved) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        if (self::requests_blocked_by_hub()) {
            FFLA_Admin::render_notice('warning', esc_html(self::missing_hub_message()));
        }

        echo '<nav class="ffla-pr-tabs" aria-label="' . esc_attr__('Product Reviews settings', 'ffl-funnels-addons') . '">';
        foreach (Product_Reviews_Admin_Fields::tabs() as $slug => $label) {
            $current = $slug === $tab;
            echo '<a class="ffla-pr-tabs__tab' . ($current ? ' is-current' : '') . '" href="' . esc_url(self::settings_url($slug)) . '"'
                . ($current ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="product_reviews_save_settings">';
        echo '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
        wp_nonce_field('product_reviews_save_settings_nonce', '_product_reviews_nonce');

        foreach (Product_Reviews_Admin_Fields::cards($tab) as $card) {
            echo '<div class="wb-card">';
            echo '<div class="wb-card__header"><h3>' . esc_html($card['title']) . '</h3>';
            if (!empty($card['desc'])) {
                echo '<p>' . esc_html($card['desc']) . '</p>';
            }
            echo '</div>';
            echo '<div class="wb-card__body">';
            foreach ($card['fields'] as $field) {
                Product_Reviews_Admin_Fields::render_field($field, $settings);
            }
            echo '</div></div>';
        }

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

        $tab  = isset($_POST['tab']) ? sanitize_key(wp_unslash($_POST['tab'])) : '';
        $tabs = Product_Reviews_Admin_Fields::tabs();
        if (!isset($tabs[$tab])) {
            wp_die(esc_html__('Unknown settings tab.', 'ffl-funnels-addons'));
        }

        $current = Product_Reviews_Core::get_settings();
        $new     = $current;

        foreach (Product_Reviews_Admin_Fields::fields($tab) as $field) {
            $value = Product_Reviews_Admin_Fields::sanitize_field($field, $current);
            if ($field['store'] ?? true) {
                $new[(string) $field['key']] = $value;
            }
        }

        $rewrite_changed = ($current['order_review_pretty_urls'] ?? '') !== ($new['order_review_pretty_urls'] ?? '')
            || ($current['order_review_rewrite_slug'] ?? '') !== ($new['order_review_rewrite_slug'] ?? '');

        update_option('ffla_product_reviews_settings', $new);

        // Both classes memoize their parsed settings per request, and this
        // request still has a redirect to render.
        Product_Reviews_Criteria::flush_memo();
        Product_Reviews_Moderation::flush_memo();

        if ($rewrite_changed) {
            Product_Reviews_Core::register_order_review_rewrites();
            flush_rewrite_rules(false);
        }

        /**
         * After a settings tab was saved.
         *
         * @param string               $tab
         * @param array<string, mixed> $new
         * @param array<string, mixed> $current Settings before the save.
         */
        do_action('ffla_product_reviews_settings_saved', $tab, $new, $current);

        wp_safe_redirect(add_query_arg('settings-updated', '1', self::settings_url($tab)));
        exit;
    }
}
