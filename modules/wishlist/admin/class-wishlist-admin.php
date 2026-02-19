<?php
/**
 * Wishlist Admin — Settings page.
 *
 * Redesigned to use the FFLA shared design system.
 * Replaces the old Alg_Wishlist_Admin + Settings API.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wishlist_Admin
{
    /**
     * Hook into WordPress.
     */
    public function init(): void
    {
        add_action('admin_post_wishlist_save_settings', [$this, 'handle_settings_save']);
    }

    /**
     * Render the settings page content (inside FFLA shell).
     */
    public function render_settings_content(): void
    {
        $options = get_option('alg_wishlist_settings', []);
        $saved = isset($_GET['settings-updated']) && $_GET['settings-updated'] === '1';

        if ($saved) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        // ── Settings Card ───────────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Global Styles', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wishlist_save_settings">';
        wp_nonce_field('wishlist_save_settings_nonce', '_wishlist_nonce');

        FFLA_Admin::render_color_field(
            __('Primary Color (Heart)', 'ffl-funnels-addons'),
            'alg_wishlist_color_primary',
            $options['alg_wishlist_color_primary'] ?? '#ff4b4b',
            __('Default color for the wishlist heart icon.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_color_field(
            __('Hover Color', 'ffl-funnels-addons'),
            'alg_wishlist_color_hover',
            $options['alg_wishlist_color_hover'] ?? '#ff0000',
            __('Color when hovering over the heart.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_color_field(
            __('Active Color (Filled)', 'ffl-funnels-addons'),
            'alg_wishlist_color_active',
            $options['alg_wishlist_color_active'] ?? '#cc0000',
            __('Color when the product is in the wishlist.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_textarea_field(
            __('Custom Icon SVG', 'ffl-funnels-addons'),
            'alg_wishlist_icon_svg',
            $options['alg_wishlist_icon_svg'] ?? '',
            __('Paste raw SVG code to replace the default heart icon.', 'ffl-funnels-addons')
        );

        echo '</div></div>'; // end card

        // ── General Settings Card ───────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('General Settings', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        // Page selector — custom field since FFLA_Admin doesn't have one.
        $selected_page = $options['alg_wishlist_page_id'] ?? 0;
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="alg_wishlist_page_id">' . esc_html__('Wishlist Page', 'ffl-funnels-addons') . '</label>';
        echo '<div class="wb-field__control">';
        wp_dropdown_pages([
            'name' => 'alg_wishlist_page_id',
            'id' => 'alg_wishlist_page_id',
            'selected' => $selected_page,
            'show_option_none' => __('-- Select Page --', 'ffl-funnels-addons'),
            'option_none_value' => '0',
            'class' => 'wb-select',
        ]);
        echo '<p class="wb-field__desc">' . esc_html__('Select the page where you placed the [alg_wishlist_page] shortcode.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';

        FFLA_Admin::render_textarea_field(
            __('Custom CSS', 'ffl-funnels-addons'),
            'alg_wishlist_custom_css',
            $options['alg_wishlist_custom_css'] ?? '',
            __('Add your own CSS overrides here.', 'ffl-funnels-addons')
        );

        echo '</div>'; // end body

        // Save button.
        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">';
        echo esc_html__('Save Settings', 'ffl-funnels-addons');
        echo '</button>';
        echo '</div>';

        echo '</form>';
        echo '</div>'; // end card

        // ── Documentation Card ──────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Documentation', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        echo '<h4>' . esc_html__('Bricks Builder', 'ffl-funnels-addons') . '</h4>';
        echo '<p>' . esc_html__('Two Bricks elements are available when this module is active:', 'ffl-funnels-addons') . '</p>';
        echo '<ul class="wb-list">';
        echo '<li><strong>Wishlist Button (Algenib)</strong> — ' . esc_html__('Drag into any Product Loop or Single Product template.', 'ffl-funnels-addons') . '</li>';
        echo '<li><strong>Wishlist Counter (Algenib)</strong> — ' . esc_html__('Place in your Header to show the item count.', 'ffl-funnels-addons') . '</li>';
        echo '</ul>';

        echo '<hr class="wb-hr">';

        echo '<h4>' . esc_html__('Shortcodes', 'ffl-funnels-addons') . '</h4>';
        echo '<ul class="wb-list">';
        echo '<li><code>[alg_wishlist_button]</code> — ' . esc_html__('Displays the "Add to Wishlist" heart button.', 'ffl-funnels-addons') . '</li>';
        echo '<li><code>[alg_wishlist_count]</code> — ' . esc_html__('Displays the current wishlist item count.', 'ffl-funnels-addons') . '</li>';
        echo '<li><code>[alg_wishlist_page]</code> — ' . esc_html__('Displays the full wishlist grid. Place on a dedicated page.', 'ffl-funnels-addons') . '</li>';
        echo '</ul>';

        echo '<hr class="wb-hr">';

        echo '<h4>' . esc_html__('Doofinder Integration', 'ffl-funnels-addons') . '</h4>';
        echo '<p>' . esc_html__('Add this HTML to your Doofinder Layer Template (Product Card) to show a wishlist button in search results. The plugin JS will automatically detect this button and handle wishlist logic.', 'ffl-funnels-addons') . '</p>';

        $df_snippet = '<button type="button" class="wbw-doofinder-btn" data-product-id="<%= @item[&quot;id&quot;] %>" title="Add to Wishlist">' . "\n"
            . '  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' . "\n"
            . '    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>' . "\n"
            . '  </svg>' . "\n"
            . '</button>';

        echo '<div id="wbw-df-snippet-wrap" style="position:relative;background:#1e1e1e;border-radius:6px;padding:16px 48px 16px 16px;margin-top:12px;">';
        echo '<button type="button" id="wbw-df-copy-btn" title="' . esc_attr__('Copy to clipboard', 'ffl-funnels-addons') . '" '
            . 'style="position:absolute;top:10px;right:10px;background:none;border:1px solid #555;border-radius:4px;cursor:pointer;color:#ccc;padding:4px 8px;font-size:12px;line-height:1;" '
            . 'onclick="(function(btn){var code=document.getElementById(\'wbw-df-snippet-code\').textContent;navigator.clipboard.writeText(code).then(function(){btn.textContent=\'✓ Copied\';btn.style.color=\'#4ade80\';setTimeout(function(){btn.textContent=\'Copy\';btn.style.color=\'#ccc\';},1800)});})(this)">'
            . esc_html__('Copy', 'ffl-funnels-addons') . '</button>';
        echo '<pre id="wbw-df-snippet-code" style="margin:0;white-space:pre;overflow-x:auto;color:#d4d4d4;font-size:13px;font-family:monospace;line-height:1.5;">' . esc_html($df_snippet) . '</pre>';
        echo '</div>';

        echo '</div></div>'; // end docs card
    }

    /**
     * Handle settings form submission.
     */
    public function handle_settings_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'ffl-funnels-addons'));
        }

        check_admin_referer('wishlist_save_settings_nonce', '_wishlist_nonce');

        $options = get_option('alg_wishlist_settings', []);

        $fields = [
            'alg_wishlist_color_primary',
            'alg_wishlist_color_hover',
            'alg_wishlist_color_active',
            'alg_wishlist_icon_svg',
            'alg_wishlist_custom_css',
        ];

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $options[$field] = sanitize_text_field(wp_unslash($_POST[$field]));
            }
        }

        // Page ID is an integer.
        if (isset($_POST['alg_wishlist_page_id'])) {
            $options['alg_wishlist_page_id'] = absint($_POST['alg_wishlist_page_id']);
        }

        update_option('alg_wishlist_settings', $options);

        wp_safe_redirect(add_query_arg(
            ['page' => 'ffla-wishlist', 'settings-updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }
}
