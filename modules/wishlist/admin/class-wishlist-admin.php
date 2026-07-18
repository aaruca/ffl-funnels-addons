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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_media_library']);
    }

    /**
     * The Custom Icon field opens the WordPress media frame, which needs the
     * media scripts loaded. Only on this module's own settings screen.
     */
    public function enqueue_media_library(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ('ffla-wishlist' !== $page) {
            return;
        }

        wp_enqueue_media();
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

        FFLA_Admin::render_media_field(
            __('Custom Icon', 'ffl-funnels-addons'),
            'alg_wishlist_icon_id',
            (int) ($options['alg_wishlist_icon_id'] ?? 0),
            __('Choose an icon from the Media Library to replace the default heart. An SVG is inlined so the colors above still apply; other image types are shown as-is and cannot be recolored. Leave empty to keep the heart.', 'ffl-funnels-addons'),
            __('Select icon', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_textarea_field(
            __('Custom Icon SVG (advanced)', 'ffl-funnels-addons'),
            'alg_wishlist_icon_svg',
            $options['alg_wishlist_icon_svg'] ?? '',
            __('Optional fallback for pasting raw SVG code. Only used when no icon is selected above. The SVG must include a viewBox (e.g. viewBox="0 0 24 24") or it will not render.', 'ffl-funnels-addons')
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

        // ── SnapFind Integration Card ───────────────────────────────
        if (defined('SNAPFIND_DIR')) {
            echo '<div class="wb-card">';
            echo '<div class="wb-card__header"><h3>' . esc_html__('SnapFind (Typesense) Integration', 'ffl-funnels-addons') . '</h3></div>';
            echo '<div class="wb-card__body">';

            FFLA_Admin::render_toggle_field(
                __('Boost wishlisted products in search', 'ffl-funnels-addons'),
                'alg_wishlist_snapfind_boost',
                isset($options['alg_wishlist_snapfind_boost']) ? $options['alg_wishlist_snapfind_boost'] : '0',
                __('When enabled, products the visitor has saved to their wishlist are pushed to the top of SnapFind search results. Heart buttons on results are always available regardless of this option.', 'ffl-funnels-addons')
            );

            echo '</div></div>'; // end card
        }

        // Data retention.
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Data', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Delete wishlist data on uninstall', 'ffl-funnels-addons'),
            'delete_data_uninstall',
            isset($options['delete_data_uninstall']) ? $options['delete_data_uninstall'] : '0',
            __('Off by default. When off, uninstalling the plugin keeps every customer wishlist so a reinstall restores them. Turn on only if you want the wishlist tables permanently dropped on uninstall.', 'ffl-funnels-addons')
        );

        echo '</div></div>'; // end card

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
        echo '<li><code>[alg_wishlist_button_aws]</code> — ' . esc_html__('Displays an "Add to Wishlist" link with text toggle (Add/Remove).', 'ffl-funnels-addons') . '</li>';
        echo '<li><code>[alg_wishlist_count]</code> — ' . esc_html__('Displays the current wishlist item count.', 'ffl-funnels-addons') . '</li>';
        echo '<li><code>[alg_wishlist_page]</code> — ' . esc_html__('Displays the full wishlist grid. Place on a dedicated page.', 'ffl-funnels-addons') . '</li>';
        echo '</ul>';

        echo '<hr class="wb-hr">';

        echo '<h4>' . esc_html__('SnapFind (Typesense) integration', 'ffl-funnels-addons') . '</h4>';
        if (defined('SNAPFIND_DIR')) {
            echo '<p class="description">' . esc_html__('The SnapFind plugin is active. FFL Funnels will automatically add wishlist support on SnapFind product search: heart buttons on each result and ranking boost for products the visitor has saved in their wishlist. No custom template code is required.', 'ffl-funnels-addons') . '</p>';
            echo '<ul class="wb-list" style="margin-top:0.5em;">';
            echo '<li><strong>' . esc_html__('Requirements (frontend):', 'ffl-funnels-addons') . '</strong> ' . esc_html__('Wishlist assets must load on the same page as the SnapFind search. They load automatically when the wishlist module is enabled. If a page only outputs SnapFind without a wishlist shortcode, ensure your theme or Bricks still loads the wishlist script (e.g. header counter or a hidden shortcode) so buttons and AJAX work.', 'ffl-funnels-addons') . '</li>';
            echo '<li><strong>' . esc_html__('Ranking boost (opt-in):', 'ffl-funnels-addons') . '</strong> ' . esc_html__('Disabled by default. Enable “Boost wishlisted products in search” above to push products in the visitor’s default wishlist to the top of Typesense sort order. If the list is empty, no boost is applied.', 'ffl-funnels-addons') . '</li>';
            echo '<li><strong>' . esc_html__('Optional field — wishlist_count (popularity):', 'ffl-funnels-addons') . '</strong> ' . esc_html__('This plugin can send a numeric wishlist_count to Typesense for each product (how many wishlists include that product). In SnapFind → Schema Builder, for “product”, add a new field: slug wishlist_count, type int32, index Yes, sort Yes, facet optional. Then run a full reindex in SnapFind so the field is populated. You can use this field in facets or as an extra sort option.', 'ffl-funnels-addons') . '</li>';
            echo '</ul>';
        } else {
            echo '<p class="description">' . esc_html__('To enable automatic wishlist buttons and search ranking on SnapFind / Typesense results, install and activate the SnapFind plugin. After activation, the integration loads automatically; no code is required on your search templates.', 'ffl-funnels-addons') . '</p>';
        }

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

        // Shared with the render path so save-time and output-time sanitisation
        // never diverge. (The old inline list keyed 'viewBox' camelCase, which
        // wp_kses lowercases and therefore stripped — breaking SVG scaling.)
        $svg_allowlist = class_exists('Alg_Wishlist_Core')
            ? Alg_Wishlist_Core::svg_allowlist()
            : array(
                'svg'  => array('xmlns' => true, 'viewbox' => true, 'fill' => true, 'stroke' => true, 'class' => true),
                'path' => array('d' => true, 'fill' => true, 'stroke' => true),
            );

        // Media Library icon (attachment ID). Cheap to change, so drop the
        // cached inline SVG whenever it does.
        $new_icon_id = isset($_POST['alg_wishlist_icon_id']) ? absint($_POST['alg_wishlist_icon_id']) : 0;
        $old_icon_id = (int) ($options['alg_wishlist_icon_id'] ?? 0);
        $options['alg_wishlist_icon_id'] = $new_icon_id;
        if ($new_icon_id !== $old_icon_id && class_exists('Alg_Wishlist_Core')) {
            Alg_Wishlist_Core::flush_icon_cache($old_icon_id);
            Alg_Wishlist_Core::flush_icon_cache($new_icon_id);
        }

        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $value = wp_unslash($_POST[$field]);
                if ('alg_wishlist_icon_svg' === $field) {
                    $options[$field] = wp_kses($value, $svg_allowlist);
                } elseif ('alg_wishlist_custom_css' === $field) {
                    $options[$field] = wp_strip_all_tags($value);
                } else {
                    $options[$field] = sanitize_text_field($value);
                }
            }
        }

        // Page ID is an integer.
        if (isset($_POST['alg_wishlist_page_id'])) {
            $options['alg_wishlist_page_id'] = absint($_POST['alg_wishlist_page_id']);
        }

        // SnapFind boost toggle (only stored when the SnapFind card is shown).
        if (defined('SNAPFIND_DIR')) {
            $options['alg_wishlist_snapfind_boost'] = isset($_POST['alg_wishlist_snapfind_boost']) ? '1' : '0';
        }

        // Gates the destructive wishlist cleanup in uninstall.php.
        $options['delete_data_uninstall'] = isset($_POST['delete_data_uninstall']) ? '1' : '0';

        update_option('alg_wishlist_settings', $options);

        wp_safe_redirect(add_query_arg(
            ['page' => 'ffla-wishlist', 'settings-updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }
}
