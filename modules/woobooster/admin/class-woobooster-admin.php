<?php
/**
 * WooBooster Admin.
 *
 * Handles WooBooster-specific admin pages, settings save, and AJAX handlers.
 * The shell (header, sidebar, footer) is handled by FFLA_Admin.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Admin
{
    /**
     * Initialize admin hooks.
     */
    public function init()
    {
        add_action('admin_init', array($this, 'handle_settings_save'));
        add_action('admin_enqueue_scripts', array($this, 'localize_scripts'), 20);

        // AJAX handlers (keep original action names for backward compatibility).
        add_action('wp_ajax_woobooster_export_rules', array($this, 'ajax_export_rules'));
        add_action('wp_ajax_woobooster_import_rules', array($this, 'ajax_import_rules'));
        add_action('wp_ajax_woobooster_rebuild_index', array($this, 'ajax_rebuild_index'));
        add_action('wp_ajax_woobooster_purge_index', array($this, 'ajax_purge_index'));
        add_action('wp_ajax_woobooster_delete_all_rules', array($this, 'ajax_delete_all_rules'));
        add_action('wp_ajax_woobooster_ai_generate', array($this, 'ajax_ai_generate'));
    }

    /**
     * Localize the module JS when on WooBooster pages.
     */
    public function localize_scripts($hook)
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

        if (strpos($page, 'ffla-woobooster') === false) {
            return;
        }

        // Localize the module script (enqueued by FFLA_Admin).
        wp_localize_script('woobooster-module', 'wooboosterAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woobooster_admin'),
            'i18n' => array(
                'confirmDelete' => __('Are you sure you want to delete this rule?', 'ffl-funnels-addons'),
                'searching' => __('Searching...', 'ffl-funnels-addons'),
                'noResults' => __('No results found.', 'ffl-funnels-addons'),
                'loading' => __('Loading...', 'ffl-funnels-addons'),
                'testing' => __('Testing...', 'ffl-funnels-addons'),
            ),
        ));

        // Enqueue the AI Chat script
        wp_enqueue_script(
            'woobooster-ai-js',
            plugins_url('js/woobooster-ai.js', __FILE__),
            array('jquery'),
            WOOBOOSTER_VERSION,
            true
        );
    }

    /**
     * Render the Settings page content (no shell).
     *
     * @return void
     */
    public function render_settings_content()
    {
        $options = get_option('woobooster_settings', array());

        // Show save notice.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['settings-updated']) && 'true' === $_GET['settings-updated']) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        echo '<form method="post" action="">';
        wp_nonce_field('woobooster_save_settings', 'woobooster_settings_nonce');

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h2>' . esc_html__('General Settings', 'ffl-funnels-addons') . '</h2></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Enable Recommendations', 'ffl-funnels-addons'),
            'woobooster_enabled',
            isset($options['enabled']) ? $options['enabled'] : '1',
            __('Enable or disable the entire recommendation system.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Frontend Section Title', 'ffl-funnels-addons'),
            'woobooster_section_title',
            isset($options['section_title']) ? $options['section_title'] : __('You May Also Like', 'ffl-funnels-addons'),
            __('The heading displayed above the recommended products on the product page.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_select_field(
            __('Rendering Method', 'ffl-funnels-addons'),
            'woobooster_render_method',
            isset($options['render_method']) ? $options['render_method'] : 'bricks',
            array(
                'bricks' => __('Bricks Query Loop (recommended)', 'ffl-funnels-addons'),
                'woo_hook' => __('WooCommerce Hook (fallback)', 'ffl-funnels-addons'),
            ),
            __('Choose how recommendations are rendered on the frontend.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('OpenAI API Key', 'ffl-funnels-addons'),
            'woobooster_openai_key',
            isset($options['openai_key']) ? $options['openai_key'] : '',
            __('Enter your OpenAI API key to enable AI rule generation. Needs access to GPT-4o models.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Tavily API Key', 'ffl-funnels-addons'),
            'woobooster_tavily_key',
            isset($options['tavily_key']) ? $options['tavily_key'] : '',
            __('Optional. Enter a Tavily API key to allow the AI to search the web for specific product knowledge (e.g. \"best ammo for glock 19\").', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Exclude Out of Stock', 'ffl-funnels-addons'),
            'woobooster_exclude_outofstock',
            isset($options['exclude_outofstock']) ? $options['exclude_outofstock'] : '1',
            __('Globally exclude out-of-stock products from recommendations.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Debug Mode', 'ffl-funnels-addons'),
            'woobooster_debug_mode',
            isset($options['debug_mode']) ? $options['debug_mode'] : '0',
            __('Log rule matching details to WooCommerce Status Logs.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Delete Data on Uninstall', 'ffl-funnels-addons'),
            'woobooster_delete_data',
            isset($options['delete_data_uninstall']) ? $options['delete_data_uninstall'] : '0',
            __('Remove all WooBooster data (rules, settings) when the plugin is uninstalled.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';

        $this->render_smart_recommendations_section();
    }

    /**
     * Render the Smart Recommendations settings card.
     */
    private function render_smart_recommendations_section()
    {
        $options = get_option('woobooster_settings', array());
        $last_build = get_option('woobooster_last_build', array());
        ?>
        <div class="wb-card" style="margin-top:24px;">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Smart Recommendations', 'ffl-funnels-addons'); ?></h2>
            </div>
            <div class="wb-card__body">
                <p class="wb-field__desc" style="margin-bottom:20px;">
                    <?php esc_html_e('Enable intelligent recommendation strategies. These work as new Action Sources in your rules. Zero extra database tables.', 'ffl-funnels-addons'); ?>
                </p>

                <form method="post" action="" id="wb-smart-settings-form">
                    <?php wp_nonce_field('woobooster_save_settings', 'woobooster_settings_nonce'); ?>
                    <input type="hidden" name="woobooster_smart_save" value="1">

                    <div class="wb-field">
                        <label class="wb-field__label"><?php esc_html_e('Bought Together', 'ffl-funnels-addons'); ?></label>
                        <div class="wb-field__control">
                            <label class="wb-toggle">
                                <input type="checkbox" name="woobooster_smart_copurchase" value="1" <?php checked(!empty($options['smart_copurchase']), true); ?>>
                                <span class="wb-toggle__slider"></span>
                            </label>
                            <p class="wb-field__desc">
                                <?php esc_html_e('Analyze orders to find products frequently purchased together. Runs nightly via WP-Cron.', 'ffl-funnels-addons'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="wb-field">
                        <label class="wb-field__label"><?php esc_html_e('Trending Products', 'ffl-funnels-addons'); ?></label>
                        <div class="wb-field__control">
                            <label class="wb-toggle">
                                <input type="checkbox" name="woobooster_smart_trending" value="1" <?php checked(!empty($options['smart_trending']), true); ?>>
                                <span class="wb-toggle__slider"></span>
                            </label>
                            <p class="wb-field__desc">
                                <?php esc_html_e('Track bestselling products per category. Updates every 6 hours via WP-Cron.', 'ffl-funnels-addons'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="wb-field">
                        <label class="wb-field__label"><?php esc_html_e('Recently Viewed', 'ffl-funnels-addons'); ?></label>
                        <div class="wb-field__control">
                            <label class="wb-toggle">
                                <input type="checkbox" name="woobooster_smart_recently_viewed" value="1" <?php checked(!empty($options['smart_recently_viewed']), true); ?>>
                                <span class="wb-toggle__slider"></span>
                            </label>
                            <p class="wb-field__desc">
                                <?php esc_html_e('Show products the visitor recently viewed. Uses a browser cookie.', 'ffl-funnels-addons'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="wb-field">
                        <label class="wb-field__label"><?php esc_html_e('Similar Products', 'ffl-funnels-addons'); ?></label>
                        <div class="wb-field__control">
                            <label class="wb-toggle">
                                <input type="checkbox" name="woobooster_smart_similar" value="1" <?php checked(!empty($options['smart_similar']), true); ?>>
                                <span class="wb-toggle__slider"></span>
                            </label>
                            <p class="wb-field__desc">
                                <?php esc_html_e('Find products with similar price range and category, ordered by sales.', 'ffl-funnels-addons'); ?>
                            </p>
                        </div>
                    </div>

                    <hr style="border:none; border-top:1px solid #eee; margin:20px 0;">

                    <?php
                    $smart_days = isset($options['smart_days']) ? $options['smart_days'] : '90';
                    $smart_max = isset($options['smart_max_relations']) ? $options['smart_max_relations'] : '20';
                    ?>
                    <div class="wb-field">
                        <label class="wb-field__label"
                            for="wb-smart-days"><?php esc_html_e('Days to Analyze', 'ffl-funnels-addons'); ?></label>
                        <div class="wb-field__control">
                            <input type="number" id="wb-smart-days" name="woobooster_smart_days"
                                value="<?php echo esc_attr($smart_days); ?>" min="7" max="365" class="wb-input wb-input--sm"
                                style="width:100px;">
                            <p class="wb-field__desc">
                                <?php esc_html_e('How many days of order history to scan for co-purchase and trending data.', 'ffl-funnels-addons'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="wb-field">
                        <label class="wb-field__label"
                            for="wb-smart-max"><?php esc_html_e('Max Relations Per Product', 'ffl-funnels-addons'); ?></label>
                        <div class="wb-field__control">
                            <input type="number" id="wb-smart-max" name="woobooster_smart_max_relations"
                                value="<?php echo esc_attr($smart_max); ?>" min="5" max="50" class="wb-input wb-input--sm"
                                style="width:100px;">
                            <p class="wb-field__desc">
                                <?php esc_html_e('Maximum number of related products to store per product in co-purchase index.', 'ffl-funnels-addons'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="wb-actions-bar" style="margin-top:16px;">
                        <button type="submit"
                            class="wb-btn wb-btn--primary"><?php esc_html_e('Save Smart Settings', 'ffl-funnels-addons'); ?></button>
                    </div>
                </form>

                <hr style="border:none; border-top:1px solid #eee; margin:20px 0;">

                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <button type="button" id="wb-rebuild-index" class="wb-btn wb-btn--subtle">
                        <?php esc_html_e('Rebuild Now', 'ffl-funnels-addons'); ?>
                    </button>
                    <button type="button" id="wb-purge-index" class="wb-btn wb-btn--subtle wb-btn--danger">
                        <?php esc_html_e('Clear All Data', 'ffl-funnels-addons'); ?>
                    </button>
                    <span id="wb-smart-status" style="color: var(--wb-color-neutral-foreground-2); font-size:13px;">
                        <?php
                        if (!empty($last_build)) {
                            $parts = array();
                            if (!empty($last_build['copurchase'])) {
                                $cp = $last_build['copurchase'];
                                $parts[] = sprintf(
                                    __('Co-purchase: %1$d products in %2$ss (%3$s)', 'ffl-funnels-addons'),
                                    $cp['products'],
                                    $cp['time'],
                                    $cp['date']
                                );
                            }
                            if (!empty($last_build['trending'])) {
                                $tr = $last_build['trending'];
                                $parts[] = sprintf(
                                    __('Trending: %1$d categories in %2$ss (%3$s)', 'ffl-funnels-addons'),
                                    $tr['categories'],
                                    $tr['time'],
                                    $tr['date']
                                );
                            }
                            if (!empty($parts)) {
                                echo esc_html(implode(' · ', $parts));
                            }
                        }
                        ?>
                    </span>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Rule Manager page content.
     */
    public function render_rules_content()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : 'list';

        switch ($action) {
            case 'add':
            case 'edit':
                $form = new WooBooster_Rule_Form();
                $form->render();
                break;
            default:
                $list = new WooBooster_Rule_List();
                $list->prepare_items();

                echo '<div class="wb-card">';
                echo '<div class="wb-card__header">';
                echo '<h2>' . esc_html__('Rules', 'ffl-funnels-addons') . '</h2>';
                $add_url = admin_url('admin.php?page=ffla-woobooster-rules&action=add');
                echo '<div class="wb-card__actions">';
                echo '<button type="button" id="wb-export-rules" class="wb-btn wb-btn--subtle wb-btn--sm" style="margin-right: 8px;">' . esc_html__('Export', 'ffl-funnels-addons') . '</button>';
                echo '<button type="button" id="wb-import-rules-btn" class="wb-btn wb-btn--subtle wb-btn--sm" style="margin-right: 8px;">' . esc_html__('Import', 'ffl-funnels-addons') . '</button>';
                echo '<button type="button" id="wb-delete-all-rules" class="wb-btn wb-btn--subtle wb-btn--sm wb-btn--danger" style="margin-right: 8px;">' . esc_html__('Delete All', 'ffl-funnels-addons') . '</button>';
                echo '<input type="file" id="wb-import-file" style="display:none;" accept=".json">';

                // AI Generator Button
                echo '<button type="button" id="wb-open-ai-modal" class="wb-btn wb-btn--sm" style="margin-right: 8px; background: linear-gradient(135deg, #a855f7, #7e22ce); color: white; border: none;">';
                echo '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 4px;"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>';
                echo esc_html__('Generate with AI', 'ffl-funnels-addons');
                echo '</button>';

                echo '<a href="' . esc_url($add_url) . '" class="wb-btn wb-btn--primary wb-btn--sm">';
                echo WooBooster_Icons::get('plus'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo esc_html__('Add Rule', 'ffl-funnels-addons');
                echo '</a>';
                echo '</div>';
                echo '</div>';
                echo '<div class="wb-card__body wb-card__body--table">';
                echo '<form method="get">';
                echo '<input type="hidden" name="page" value="ffla-woobooster-rules" />';
                $list->search_box(__('Search Rules', 'woobooster'), 'rule');
                $list->display();
                echo '</form>';
                echo '</div></div>';

                // Render AI Modal Structure
                $this->render_ai_chat_modal();
                break;
                echo '</form>';
                echo '</div></div>';
                break;
        }
    }

    /**
     * Render the Diagnostics page content.
     */
    public function render_diagnostics_content()
    {
        $tester = new WooBooster_Rule_Tester();
        $tester->render();
    }

    /**
     * Render the Documentation page content.
     */
    public function render_documentation_content()
    {
        ?>
        <div class="wb-card">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Documentation', 'ffl-funnels-addons'); ?></h2>
            </div>
            <div class="wb-card__body">
                <h3><?php esc_html_e('Getting Started', 'ffl-funnels-addons'); ?></h3>
                <p><?php esc_html_e('WooBooster automatically displays recommended products based on your rules. By default, it replaces the standard WooCommerce "Related Products" section.', 'ffl-funnels-addons'); ?>
                </p>

                <hr class="wb-hr">

                <h3><?php esc_html_e('Shortcode Usage', 'ffl-funnels-addons'); ?></h3>
                <p><?php esc_html_e('Use the shortcode to display recommendations anywhere on your site:', 'ffl-funnels-addons'); ?>
                </p>
                <code class="wb-code">[woobooster product_id="123" limit="4"]</code>
                <ul class="wb-list">
                    <li><strong>product_id</strong>:
                        <?php esc_html_e('(Optional) ID of the product to base recommendations on. Defaults to current product.', 'ffl-funnels-addons'); ?>
                    </li>
                    <li><strong>limit</strong>:
                        <?php esc_html_e('(Optional) Number of products to show. Default: 4.', 'ffl-funnels-addons'); ?>
                    </li>
                </ul>

                <hr class="wb-hr">

                <h3><?php esc_html_e('Bricks Builder Integration', 'ffl-funnels-addons'); ?></h3>
                <p><?php esc_html_e('WooBooster is fully compatible with Bricks Builder.', 'ffl-funnels-addons'); ?></p>
                <ol class="wb-list">
                    <li><?php esc_html_e('Add a "Query Loop" element to your template.', 'ffl-funnels-addons'); ?></li>
                    <li><?php esc_html_e('Set the Query Type to "WooBooster Recommendations".', 'ffl-funnels-addons'); ?></li>
                    <li><?php esc_html_e('Customize your layout using standard Bricks elements.', 'ffl-funnels-addons'); ?></li>
                </ol>

                <hr class="wb-hr">

                <h3><?php esc_html_e('Rules Engine', 'ffl-funnels-addons'); ?></h3>
                <p><?php esc_html_e('Rules are processed in order from top to bottom. The first rule that matches the current product will be used to generate recommendations.', 'ffl-funnels-addons'); ?>
                </p>

                <hr class="wb-hr">

                <h3><?php esc_html_e('Smart Recommendations', 'ffl-funnels-addons'); ?></h3>
                <p><?php esc_html_e('WooBooster includes four intelligent recommendation strategies that go beyond simple taxonomy matching. Enable them in WB Settings.', 'ffl-funnels-addons'); ?>
                </p>
                <ul class="wb-list">
                    <li><strong><?php esc_html_e('Bought Together', 'ffl-funnels-addons'); ?></strong>:
                        <?php esc_html_e('Analyzes completed orders to find products frequently purchased together.', 'ffl-funnels-addons'); ?>
                    </li>
                    <li><strong><?php esc_html_e('Trending', 'ffl-funnels-addons'); ?></strong>:
                        <?php esc_html_e('Tracks bestselling products per category based on recent sales data.', 'ffl-funnels-addons'); ?>
                    </li>
                    <li><strong><?php esc_html_e('Recently Viewed', 'ffl-funnels-addons'); ?></strong>:
                        <?php esc_html_e('Shows products the visitor has recently browsed via browser cookie.', 'ffl-funnels-addons'); ?>
                    </li>
                    <li><strong><?php esc_html_e('Similar Products', 'ffl-funnels-addons'); ?></strong>:
                        <?php esc_html_e('Finds products with similar price in the same category.', 'ffl-funnels-addons'); ?>
                    </li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Handle settings form submission.
     */
    public function handle_settings_save()
    {
        if (!isset($_POST['woobooster_settings_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_key($_POST['woobooster_settings_nonce']), 'woobooster_save_settings')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $existing = get_option('woobooster_settings', array());

        if (isset($_POST['woobooster_smart_save'])) {
            $existing['smart_copurchase'] = isset($_POST['woobooster_smart_copurchase']) ? '1' : '0';
            $existing['smart_trending'] = isset($_POST['woobooster_smart_trending']) ? '1' : '0';
            $existing['smart_recently_viewed'] = isset($_POST['woobooster_smart_recently_viewed']) ? '1' : '0';
            $existing['smart_similar'] = isset($_POST['woobooster_smart_similar']) ? '1' : '0';
            $existing['smart_days'] = isset($_POST['woobooster_smart_days']) ? absint($_POST['woobooster_smart_days']) : 90;
            $existing['smart_max_relations'] = isset($_POST['woobooster_smart_max_relations']) ? absint($_POST['woobooster_smart_max_relations']) : 20;

            update_option('woobooster_settings', $existing);
            WooBooster_Cron::schedule();

            wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=ffla-woobooster')));
            exit;
        }

        $options = array_merge($existing, array(
            'enabled' => isset($_POST['woobooster_enabled']) ? '1' : '0',
            'section_title' => isset($_POST['woobooster_section_title']) ? sanitize_text_field(wp_unslash($_POST['woobooster_section_title'])) : '',
            'render_method' => isset($_POST['woobooster_render_method']) ? sanitize_key($_POST['woobooster_render_method']) : 'bricks',
            'openai_key' => isset($_POST['woobooster_openai_key']) ? sanitize_text_field(wp_unslash($_POST['woobooster_openai_key'])) : '',
            'tavily_key' => isset($_POST['woobooster_tavily_key']) ? sanitize_text_field(wp_unslash($_POST['woobooster_tavily_key'])) : '',
            'exclude_outofstock' => isset($_POST['woobooster_exclude_outofstock']) ? '1' : '0',
            'debug_mode' => isset($_POST['woobooster_debug_mode']) ? '1' : '0',
            'delete_data_uninstall' => isset($_POST['woobooster_delete_data']) ? '1' : '0',
        ));

        update_option('woobooster_settings', $options);

        wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=ffla-woobooster')));
        exit;
    }

    /**
     * AJAX: Export rules to JSON.
     */
    public function ajax_export_rules()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffl-funnels-addons')));
        }

        $rules = WooBooster_Rule::get_all();
        $export_rules = array();

        foreach ($rules as $rule) {
            $rule_data = (array) $rule;
            $rule_data['conditions'] = WooBooster_Rule::get_conditions($rule->id);
            $rule_data['actions'] = WooBooster_Rule::get_actions($rule->id);
            $export_rules[] = $rule_data;
        }

        $export_data = array(
            'version' => WOOBOOSTER_VERSION,
            'date' => gmdate('Y-m-d H:i:s'),
            'rules' => $export_rules,
        );

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="woobooster-rules-' . gmdate('Y-m-d') . '.json"');
        echo wp_json_encode($export_data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * AJAX: Import rules from JSON.
     */
    public function ajax_import_rules()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffl-funnels-addons')));
        }

        $json = isset($_POST['json']) ? wp_unslash($_POST['json']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $data = json_decode($json, true);

        if (!$data || !isset($data['rules']) || !is_array($data['rules'])) {
            wp_send_json_error(array('message' => __('Invalid JSON file.', 'ffl-funnels-addons')));
        }

        $count = 0;
        foreach ($data['rules'] as $rule_data) {
            $conditions = isset($rule_data['conditions']) ? $rule_data['conditions'] : array();
            $actions = isset($rule_data['actions']) ? $rule_data['actions'] : array();
            unset($rule_data['id'], $rule_data['conditions'], $rule_data['actions'], $rule_data['created_at'], $rule_data['updated_at']);

            if (empty($rule_data['name'])) {
                continue;
            }

            $rule_id = WooBooster_Rule::create($rule_data);
            if ($rule_id) {
                if (!empty($conditions)) {
                    $clean_conditions = array();
                    foreach ($conditions as $group_id => $group) {
                        $group_arr = array();
                        foreach ($group as $cond) {
                            $cond = (array) $cond;
                            $group_arr[] = array(
                                'condition_attribute' => sanitize_key($cond['condition_attribute'] ?? ''),
                                'condition_operator' => 'equals',
                                'condition_value' => sanitize_text_field($cond['condition_value'] ?? ''),
                                'include_children' => absint($cond['include_children'] ?? 0),
                            );
                        }
                        if (!empty($group_arr)) {
                            $clean_conditions[absint($group_id)] = $group_arr;
                        }
                    }
                    if (!empty($clean_conditions)) {
                        WooBooster_Rule::save_conditions($rule_id, $clean_conditions);
                    }
                }

                if (!empty($actions)) {
                    $clean_actions = array();
                    foreach ($actions as $action) {
                        $action = (array) $action;
                        $clean_actions[] = array(
                            'action_source' => sanitize_key($action['action_source'] ?? 'category'),
                            'action_value' => sanitize_text_field($action['action_value'] ?? ''),
                            'action_limit' => absint($action['action_limit'] ?? 4),
                            'action_orderby' => sanitize_key($action['action_orderby'] ?? 'rand'),
                            'include_children' => absint($action['include_children'] ?? 0),
                        );
                    }
                    if (!empty($clean_actions)) {
                        WooBooster_Rule::save_actions($rule_id, $clean_actions);
                    }
                }

                $count++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(
                __('%d rules imported successfully.', 'ffl-funnels-addons'),
                $count
            ),
            'count' => $count,
        ));
    }

    /**
     * AJAX: Rebuild Smart Recommendations index.
     */
    public function ajax_rebuild_index()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffl-funnels-addons')));
        }

        $cron = new WooBooster_Cron();
        $results = array();
        $options = get_option('woobooster_settings', array());

        if (!empty($options['smart_copurchase'])) {
            $results['copurchase'] = $cron->run_copurchase();
        }

        if (!empty($options['smart_trending'])) {
            $results['trending'] = $cron->run_trending();
        }

        $parts = array();
        if (!empty($results['copurchase'])) {
            $cp = $results['copurchase'];
            $parts[] = sprintf(__('Co-purchase: %1$d products in %2$ss', 'ffl-funnels-addons'), $cp['products'], $cp['time']);
        }
        if (!empty($results['trending'])) {
            $tr = $results['trending'];
            $parts[] = sprintf(__('Trending: %1$d categories in %2$ss', 'ffl-funnels-addons'), $tr['categories'], $tr['time']);
        }

        $message = !empty($parts) ? implode(' · ', $parts) : __('No strategies enabled. Enable at least one above.', 'ffl-funnels-addons');

        wp_send_json_success(array(
            'message' => $message,
            'results' => $results,
        ));
    }

    /**
     * AJAX: Purge all Smart Recommendations data.
     */
    public function ajax_purge_index()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffl-funnels-addons')));
        }

        $counts = WooBooster_Cron::purge_all();
        $total = $counts['copurchase'] + $counts['trending'] + $counts['similar'];

        wp_send_json_success(array(
            'message' => sprintf(__('Cleared %d items.', 'ffl-funnels-addons'), $total),
            'counts' => $counts,
        ));
    }

    /**
     * AJAX: Delete ALL rules.
     */
    public function ajax_delete_all_rules()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffl-funnels-addons')));
        }

        global $wpdb;
        $rules_table = $wpdb->prefix . 'woobooster_rules';
        $index_table = $wpdb->prefix . 'woobooster_rule_index';
        $conditions_table = $wpdb->prefix . 'woobooster_rule_conditions';
        $actions_table = $wpdb->prefix . 'woobooster_rule_actions';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- TRUNCATE does not support placeholders; table names are hardcoded.
        $wpdb->query("TRUNCATE TABLE {$conditions_table}");
        $wpdb->query("TRUNCATE TABLE {$actions_table}");
        $wpdb->query("TRUNCATE TABLE {$index_table}");
        $wpdb->query("TRUNCATE TABLE {$rules_table}");
        // phpcs:enable

        wp_send_json_success(array('message' => __('All rules deleted successfully.', 'ffl-funnels-addons')));
    }

    /**
     * AJAX: Handle AI Rule Generation Request
     * Uses wp_remote_post to query our secure AI Proxy.
     */
    public function ajax_ai_generate()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffl-funnels-addons')));
        }

        $chat_history_json = isset($_POST['chat_history']) ? wp_unslash($_POST['chat_history']) : '[]';
        $chat_history = json_decode($chat_history_json, true);

        if (!is_array($chat_history) || empty($chat_history)) {
            wp_send_json_error(array('message' => __('No message provided.', 'ffl-funnels-addons')));
        }

        // Send conversation to OpenAI directly
        $options = get_option('woobooster_settings', array());
        $api_key = isset($options['openai_key']) ? $options['openai_key'] : '';
        $tavily_key = isset($options['tavily_key']) ? $options['tavily_key'] : '';

        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('OpenAI API Key is required. Please add it in WooBooster General Settings.', 'ffl-funnels-addons')));
        }

        $system_message = array(
            'role' => 'system',
            'content' => "You are an AI assistant that helps store owners create product recommendation rules.
If the user wants to create a rule, ask for clarification if you don't have enough info, or call the 'create_rule' tool to generate the JSON configuration.
Keep your responses concise and helpful."
        );
        array_unshift($chat_history, $system_message);

        $api_url = 'https://api.openai.com/v1/chat/completions';

        $tools = array(
            array(
                'type' => 'function',
                'function' => array(
                    'name' => 'create_rule',
                    'description' => 'Create a WooCommerce recommendation rule',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array(
                            'name' => array('type' => 'string', 'description' => 'Name of the rule'),
                            'priority' => array('type' => 'integer', 'description' => 'Priority (default 10)'),
                            'condition_operator' => array('type' => 'string', 'enum' => array('equals', 'not_equals', 'contains')),
                            'condition_value' => array('type' => 'string', 'description' => 'The value to match (e.g., category slug or attribute value)'),
                            'condition_attribute' => array('type' => 'string', 'description' => 'The attribute or taxonomy to check (e.g. pa_brand, pa_caliber)'),
                            'action_source' => array('type' => 'string', 'enum' => array('category', 'tag', 'attribute', 'attribute_value', 'copurchase', 'trending', 'specific_products')),
                            'action_value' => array('type' => 'string', 'description' => 'Comma separated IDs or slugs of products/categories to recommend'),
                            'action_limit' => array('type' => 'integer', 'description' => 'Max products to show (default 4)')
                        ),
                        'required' => array('name', 'condition_operator', 'condition_value', 'condition_attribute', 'action_source', 'action_value')
                    )
                )
            )
        );

        if (!empty($tavily_key)) {
            $tools[] = array(
                'type' => 'function',
                'function' => array(
                    'name' => 'search_web',
                    'description' => 'Search the internet for product knowledge, compatibility, or general information',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array(
                            'query' => array('type' => 'string', 'description' => 'The search query string')
                        ),
                        'required' => array('query')
                    )
                )
            );
        }

        $api_args = array(
            'body' => wp_json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => $chat_history,
                'tools' => $tools
            )),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . trim($api_key)
            ),
            'timeout' => 45,
            'data_format' => 'body'
        );

        $response = wp_remote_post($api_url, $api_args);

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || isset($data['error'])) {
            $err_msg = isset($data['error']['message']) ? $data['error']['message'] : __('Invalid response from OpenAI.', 'ffl-funnels-addons');
            wp_send_json_error(array('message' => $err_msg));
        }

        $assistant_message = $data['choices'][0]['message'];

        // Handle tool execution loop
        if (!empty($assistant_message['tool_calls'])) {
            $tool_call = $assistant_message['tool_calls'][0];

            // 1. Tavily Search Web loop
            if ($tool_call['function']['name'] === 'search_web' && !empty($tavily_key)) {
                $args = json_decode($tool_call['function']['arguments'], true);
                $query = isset($args['query']) ? $args['query'] : '';

                $tavily_response = wp_remote_post('https://api.tavily.com/search', array(
                    'body' => wp_json_encode(array(
                        'api_key' => trim($tavily_key),
                        'query' => $query,
                        'search_depth' => 'basic',
                        'include_answer' => true,
                        'max_results' => 3
                    )),
                    'headers' => array('Content-Type' => 'application/json'),
                    'timeout' => 30
                ));

                $search_result_text = "Search failed.";
                if (!is_wp_error($tavily_response)) {
                    $tavily_body = json_decode(wp_remote_retrieve_body($tavily_response), true);
                    if (isset($tavily_body['answer'])) {
                        $search_result_text = $tavily_body['answer'];
                    } elseif (isset($tavily_body['results'])) {
                        $search_result_text = wp_json_encode($tavily_body['results']);
                    }
                }

                // Append assistant context and tool result to history
                $chat_history[] = $assistant_message;
                $chat_history[] = array(
                    'role' => 'tool',
                    'tool_call_id' => $tool_call['id'],
                    'name' => 'search_web',
                    'content' => $search_result_text
                );

                // Re-run OpenAI
                $api_args['body'] = wp_json_encode(array(
                    'model' => 'gpt-4o-mini',
                    'messages' => $chat_history,
                    'tools' => $tools
                ));

                $response2 = wp_remote_post($api_url, $api_args);
                if (is_wp_error($response2)) {
                    wp_send_json_error(array('message' => $response2->get_error_message()));
                }

                $data2 = json_decode(wp_remote_retrieve_body($response2), true);
                if (empty($data2) || isset($data2['error'])) {
                    $err_msg = isset($data2['error']['message']) ? $data2['error']['message'] : __('Invalid response from OpenAI.', 'ffl-funnels-addons');
                    wp_send_json_error(array('message' => $err_msg));
                }

                // Assign updated assistant message
                $assistant_message = $data2['choices'][0]['message'];

                // Re-assign tool call reference if the recursion yielded another tool call (e.g. create_rule)
                if (!empty($assistant_message['tool_calls'])) {
                    $tool_call = $assistant_message['tool_calls'][0];
                }
            }

            // 2. Create Rule Tool Call (could be triggered immediately, or after search_web)
            if (!empty($assistant_message['tool_calls']) && $tool_call['function']['name'] === 'create_rule') {
                require_once WOOBOOSTER_PATH . 'includes/class-woobooster-rule.php';

                $rule_data = json_decode($tool_call['function']['arguments'], true);
                $rule_id = WooBooster_Rule::create($rule_data);

                if ($rule_id) {
                    wp_send_json_success(array(
                        'is_final' => true,
                        'message' => __('Rule generated and saved successfully! Refreshing...', 'ffl-funnels-addons'),
                        'rule_id' => $rule_id
                    ));
                } else {
                    wp_send_json_error(array('message' => __('AI generated the rule, but saving to the database failed. Invalid schema?', 'ffl-funnels-addons')));
                }
            }
        }

        // A clarifying response or intermediate chat message
        wp_send_json_success(array(
            'is_final' => false,
            'message' => wp_kses_post($assistant_message['content'] ?? '')
        ));
    }
    /**
     * Render the AI Chat Modal HTML structure
     */
    private function render_ai_chat_modal()
    {
        ?>
        <div id="wb-ai-modal-overlay" class="wb-ai-modal-overlay">
            <div class="wb-ai-modal" role="dialog" aria-modal="true" aria-labelledby="wb-ai-modal-title">

                <!-- Header -->
                <div class="wb-ai-modal__header">
                    <h3 id="wb-ai-modal-title" class="wb-ai-modal__title">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                        </svg>
                        <?php esc_html_e('WooBooster AI Assistant', 'ffl-funnels-addons'); ?>
                    </h3>
                    <button type="button" id="wb-close-ai-modal" class="wb-ai-modal__close"
                        aria-label="<?php esc_attr_e('Close', 'ffl-funnels-addons'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M18 6L6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Chat Body -->
                <div id="wb-ai-chat-body" class="wb-ai-modal__body">
                    <!-- Empty State (Shown initially) -->
                    <div id="wb-ai-empty-state" class="wb-ai-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path
                                d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
                            </path>
                            <circle cx="12" cy="12" r="4"></circle>
                        </svg>
                        <h4><?php esc_html_e('What kind of rule do you need?', 'ffl-funnels-addons'); ?></h4>
                        <p><?php esc_html_e('Describe your cross-sell goal in natural language. The AI will look up your store\'s actual inventory and attributes to build a strict WooBooster rule.', 'ffl-funnels-addons'); ?>
                        </p>

                        <div class="wb-ai-suggestions">
                            <button type="button" class="wb-ai-suggestion-btn">
                                "Suggest compatible magazines and cleaning kits for the Sig Sauer P365"
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>
                            <button type="button" class="wb-ai-suggestion-btn">
                                "When someone looks at 9mm Ammo, show them eye and ear protection."
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>
                            <button type="button" class="wb-ai-suggestion-btn">
                                "Show popular Holsters for the Glock 19 under $100."
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2">
                                    <line x1="5" y1="12" x2="19" y2="12"></line>
                                    <polyline points="12 5 19 12 12 19"></polyline>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Messages will be injected here via JS -->

                    <!-- Template for Typing Indicator -->
                    <div id="wb-ai-typing-indicator" class="wb-ai-message wb-ai-message--assistant" style="display: none;">
                        <div class="wb-typing-indicator">
                            <div class="wb-typing-dot"></div>
                            <div class="wb-typing-dot"></div>
                            <div class="wb-typing-dot"></div>
                        </div>
                    </div>
                </div>

                <!-- Input Footer -->
                <div class="wb-ai-modal__footer">
                    <form id="wb-ai-chat-form" class="wb-ai-input-group">
                        <textarea id="wb-ai-input" class="wb-ai-input"
                            placeholder="<?php esc_attr_e('Example: Show optics compatible with an AR-15...', 'ffl-funnels-addons'); ?>"
                            rows="1"></textarea>
                        <button type="submit" id="wb-ai-submit-btn" class="wb-ai-submit"
                            aria-label="<?php esc_attr_e('Send message', 'ffl-funnels-addons'); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="22" y1="2" x2="11" y2="13"></line>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                            </svg>
                        </button>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }
}
