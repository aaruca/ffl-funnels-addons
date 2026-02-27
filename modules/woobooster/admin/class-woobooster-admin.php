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
        add_action('wp_ajax_woobooster_ai_create_rule', array($this, 'ajax_ai_create_rule'));
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
            array('jquery', 'woobooster-module'),
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

        FFLA_Admin::render_password_field(
            __('OpenAI API Key', 'ffl-funnels-addons'),
            'woobooster_openai_key',
            isset($options['openai_key']) ? $options['openai_key'] : '',
            __('Enter your OpenAI API key to enable AI rule generation. Needs access to GPT-4o models.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_password_field(
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

        $this->render_smart_recommendations_section();

        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';
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

                <div id="wb-smart-settings-form">
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

                </div>

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
                                    absint($cp['products']),
                                    esc_html($cp['time']),
                                    esc_html($cp['date'])
                                );
                            }
                            if (!empty($last_build['trending'])) {
                                $tr = $last_build['trending'];
                                $parts[] = sprintf(
                                    __('Trending: %1$d categories in %2$ss (%3$s)', 'ffl-funnels-addons'),
                                    absint($tr['categories']),
                                    esc_html($tr['time']),
                                    esc_html($tr['date'])
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

        // General settings.
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

        // Smart recommendations settings (same form).
        if (isset($_POST['woobooster_smart_save'])) {
            $options['smart_copurchase'] = isset($_POST['woobooster_smart_copurchase']) ? '1' : '0';
            $options['smart_trending'] = isset($_POST['woobooster_smart_trending']) ? '1' : '0';
            $options['smart_recently_viewed'] = isset($_POST['woobooster_smart_recently_viewed']) ? '1' : '0';
            $options['smart_similar'] = isset($_POST['woobooster_smart_similar']) ? '1' : '0';
            $options['smart_days'] = isset($_POST['woobooster_smart_days']) ? absint($_POST['woobooster_smart_days']) : 90;
            $options['smart_max_relations'] = isset($_POST['woobooster_smart_max_relations']) ? absint($_POST['woobooster_smart_max_relations']) : 20;
        }

        update_option('woobooster_settings', $options);

        if (isset($_POST['woobooster_smart_save'])) {
            WooBooster_Cron::schedule();
        }

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

        $max_import = 500;
        if (count($data['rules']) > $max_import) {
            wp_send_json_error(array('message' => sprintf(
                /* translators: %d: maximum number of rules allowed per import */
                __('Maximum %d rules per import.', 'ffl-funnels-addons'),
                $max_import
            )));
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

        // Use DELETE instead of TRUNCATE — TRUNCATE requires DROP privilege
        // which many shared hosts do not grant to the WordPress DB user.
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table names are hardcoded constants.
        $wpdb->query("DELETE FROM {$conditions_table}");
        $wpdb->query("DELETE FROM {$actions_table}");
        $wpdb->query("DELETE FROM {$index_table}");
        $wpdb->query("DELETE FROM {$rules_table}");
        // phpcs:enable

        wp_send_json_success(array('message' => __('All rules deleted successfully.', 'ffl-funnels-addons')));
    }

    /**
     * AJAX: Handle AI Rule Generation Request.
     *
     * Uses a proper while-loop to handle multi-turn tool calls from OpenAI.
     * Supports parallel tool calls, web search, store search, and rule CRUD.
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

        $options = get_option('woobooster_settings', array());
        $api_key = isset($options['openai_key']) ? $options['openai_key'] : '';
        $tavily_key = isset($options['tavily_key']) ? $options['tavily_key'] : '';

        if (empty($api_key)) {
            wp_send_json_error(array('message' => __('OpenAI API Key is required. Please add it in WooBooster General Settings.', 'ffl-funnels-addons')));
        }

        // Build system prompt with full domain context.
        $system_prompt = $this->build_ai_system_prompt($tavily_key);
        array_unshift($chat_history, array('role' => 'system', 'content' => $system_prompt));

        $api_url = 'https://api.openai.com/v1/chat/completions';
        $tools = $this->get_ai_tools($tavily_key);
        $headers = array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . trim($api_key),
        );

        // Track tool steps for frontend feedback.
        $steps = array();
        $max_turns = 8;
        $turn = 0;

        while ($turn < $max_turns) {
            $turn++;

            $response = wp_remote_post($api_url, array(
                'body' => wp_json_encode(array(
                    'model' => 'gpt-4o-mini',
                    'messages' => $chat_history,
                    'tools' => $tools,
                )),
                'headers' => $headers,
                'timeout' => 45,
                'data_format' => 'body',
            ));

            if (is_wp_error($response)) {
                error_log('WooBooster AI: WP_Error — ' . $response->get_error_message());
                wp_send_json_error(array('message' => __('AI service error. Please try again.', 'ffl-funnels-addons'), 'steps' => $steps));
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (empty($data) || isset($data['error'])) {
                $err_msg = isset($data['error']['message']) ? $data['error']['message'] : 'Unknown API error';
                error_log('WooBooster AI: API error — ' . $err_msg);
                wp_send_json_error(array('message' => __('AI service error. Please try again.', 'ffl-funnels-addons'), 'steps' => $steps));
            }

            $assistant_message = $data['choices'][0]['message'];

            // No tool calls — AI is responding with text. Done.
            if (empty($assistant_message['tool_calls'])) {
                break;
            }

            // Add assistant message (with tool_calls) to history.
            $chat_history[] = $assistant_message;

            // Execute ALL tool calls from this turn (supports parallel calls).
            foreach ($assistant_message['tool_calls'] as $tool_call) {
                $fn_name = $tool_call['function']['name'];
                $fn_args = json_decode($tool_call['function']['arguments'], true);

                $tool_result = '';

                switch ($fn_name) {
                    case 'search_store':
                        $steps[] = array('tool' => 'search_store', 'label' => sprintf(__('Searching store for "%s"...', 'ffl-funnels-addons'), $fn_args['query'] ?? ''));
                        $tool_result = $this->ai_tool_search_store($fn_args);
                        break;

                    case 'search_web':
                        $steps[] = array('tool' => 'search_web', 'label' => sprintf(__('Searching the web for "%s"...', 'ffl-funnels-addons'), $fn_args['query'] ?? ''));
                        $tool_result = $this->ai_tool_search_web($fn_args, $tavily_key);
                        break;

                    case 'get_rules':
                        $steps[] = array('tool' => 'get_rules', 'label' => __('Checking existing rules...', 'ffl-funnels-addons'));
                        $tool_result = $this->ai_tool_get_rules();
                        break;

                    default:
                        $tool_result = 'Unknown tool: ' . $fn_name;
                        break;
                }

                // Add tool result to history for the next turn.
                if (!empty($tool_result)) {
                    $chat_history[] = array(
                        'role' => 'tool',
                        'tool_call_id' => $tool_call['id'],
                        'content' => $tool_result,
                    );
                }
            }
            // Loop continues — OpenAI will get all tool results and decide next step.
        }

        // Return the final text response from the AI (just a message, no auto-creation).
        wp_send_json_success(array(
            'is_final' => false,
            'message' => wp_kses_post($assistant_message['content'] ?? ''),
            'steps' => $steps,
        ));
    }

    /**
     * Build the AI system prompt with full WooBooster and FFL domain context.
     */
    private function build_ai_system_prompt(string $tavily_key): string
    {
        $has_web = !empty($tavily_key);
        $web_instruction = $has_web
            ? "- Use `search_web` to find product compatibility data (e.g. \"best holsters for Glock 19\", \"compatible optics for AR-15 platform\", \"what magazines work with Sig P365\"). This is very powerful — use it whenever the user asks about compatibility or \"best sellers\" for a specific product."
            : "- Web search is not available (no Tavily API key configured). Rely on store search and your own knowledge.";

        return "You are a product recommendation specialist for an FFL (Federal Firearms Licensed) WooCommerce store. You help store owners create WooBooster recommendation rules that drive cross-sells and upsells.

## How WooBooster Rules Work
A rule has TWO parts:
1. **Condition** — WHEN to show recommendations (triggered when a customer views a product matching this condition)
2. **Action** — WHAT products to recommend

### Condition Attributes (use these exact values):
- `product_cat` — Product category (use the slug, e.g. \"handguns\", \"rifles\")
- `product_tag` — Product tag (use the slug)
- `pa_*` — Product attribute taxonomy (e.g. `pa_caliber`, `pa_brand`, `pa_manufacturer`)
- `specific_product` — A specific product by ID

### Condition Operators:
- `equals` — Exact match (most common)
- `not_equals` — Everything except this
- `contains` — Partial match

### Action Sources (what to recommend):
- `category` — Products from a category slug
- `tag` — Products with a tag slug
- `attribute_value` — Products with a specific attribute value
- `specific_products` — Hand-picked products by ID (put IDs in action_products, NOT action_value)
- `copurchase` — Frequently bought together (based on order history)
- `trending` — Currently trending products
- `apply_coupon` — Attach a coupon to the recommendation

### Action Sort Options (action_orderby):
- `rand` — Random order (default, good for variety)
- `bestselling` — Best sellers first (great for proven products)
- `price` — Cheapest first
- `price_desc` — Most expensive first
- `date` — Newest arrivals first
- `rating` — Highest rated first

## Your Workflow (INTERACTIVE — always confirm before creating)

### Golden rules:
- **NEVER ask the user for IDs, slugs, or technical data** — always use \`search_store\` yourself to find them.
- **NEVER generate a [RULE] block until the user confirms** the products they want.
- **NEVER create rules automatically** — always wait for explicit approval.
- Only use IDs and slugs obtained from \`search_store\` results. Never invent or guess them.

### Step-by-step process:

**Step 1 — Understand the request**
Ask clarifying questions if the intent is vague. Once clear, proceed.

**Step 2 — Find the condition product/category**
- Call \`search_store\` yourself.
- **One match**: \"I found [Name] (ID: X) — I'll use this as the trigger. Confirmed?\"
- **Multiple matches**: list them and ask the user to choose:
  > I found several matches. Which one do you mean?
  > 1. Glock 19 Gen 5 (ID: 1042)
  > 2. Glock 19X (ID: 1089)
- **No match**: tell the user and ask how to proceed.
- Do NOT continue to the next step until the user confirms.

**Step 3 — Find the recommended products**
- {$web_instruction}
- After any web search, always call \`search_store\` to verify which of those products actually exist in the store. Only present products that are confirmed in inventory.
- Present them and ask for confirmation:
  > I found these matching products in your store. Should I use all of them, or remove any?
  > 1. Safariland Gravity OWB (ID: 204600)
  > 2. Safariland Gravity OWB Multi-Cam (ID: 204598)
  > 3. GrovTec IWB Holster (ID: 205560)
- Do NOT generate the [RULE] until the user confirms the final product list.

**Step 4 — Propose and create**
Only after the user confirms both the condition and the recommended products, describe the rule in plain text and then emit the [RULE] block.

CRITICAL RULES for the [RULE] block:
- Do NOT wrap it in markdown code fences (no triple backticks). Emit it directly in your message.
- The JSON must contain ALL confirmed product IDs — never leave action_products empty.
- When action_source is \`specific_products\`, action_products is MANDATORY. List every confirmed ID separated by commas.
- Use ONLY real IDs from search_store results. Never use placeholder values.

Format (emit exactly like this, no code fences):

[RULE]{\"name\":\"Glock 19 Holsters\",\"condition_attribute\":\"specific_product\",\"condition_value\":\"1042\",\"action_source\":\"specific_products\",\"action_products\":\"204606,204604,204600,204598,204596,204580\",\"action_orderby\":\"bestselling\"}[/RULE]

Category action example:

[RULE]{\"name\":\"Glock 19 Holsters\",\"condition_attribute\":\"specific_product\",\"condition_value\":\"1042\",\"action_source\":\"category\",\"action_value\":\"holsters-gun-leather\",\"action_orderby\":\"bestselling\"}[/RULE]

After emitting the [RULE] block, ask: \"Shall I create this rule?\"

Prefer \`product_cat\` or \`pa_*\` conditions over \`specific_product\` for broader reach, unless the user specifically wants one product.

## FFL Store Context
Common product types: firearms (handguns, rifles, shotguns), ammunition, holsters, optics/scopes, red dots, magazines, cleaning kits, gun cases, safes, ear protection, eye protection, grips, stocks, lights, lasers, bipods, slings, targets, range gear, reloading equipment, and tactical accessories.";
    }

    /**
     * Define the AI tool schemas.
     */
    private function get_ai_tools(string $tavily_key): array
    {
        $tools = array(
            array(
                'type' => 'function',
                'function' => array(
                    'name' => 'search_store',
                    'description' => 'Search the WooCommerce catalog for products, categories, tags, or attributes. Returns IDs and slugs needed for rule creation.',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array(
                            'type' => array('type' => 'string', 'enum' => array('product', 'category', 'tag', 'attribute'), 'description' => 'Entity type to search'),
                            'query' => array('type' => 'string', 'description' => 'Search term (e.g. "Glock 19", "holsters", "9mm")'),
                        ),
                        'required' => array('type', 'query'),
                    ),
                ),
            ),
            array(
                'type' => 'function',
                'function' => array(
                    'name' => 'get_rules',
                    'description' => 'List existing WooBooster rules to avoid duplicates or understand current setup.',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => new \stdClass(),
                    ),
                ),
            ),
            array(
                'type' => 'function',
                'function' => array(
                    'name' => 'create_rule',
                    'description' => 'Create a new recommendation rule. Always search_store first to get correct slugs/IDs.',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array(
                            'name' => array('type' => 'string', 'description' => 'Descriptive rule name'),
                            'priority' => array('type' => 'integer', 'description' => 'Lower = higher priority. Default 10.'),
                            'condition_attribute' => array('type' => 'string', 'description' => 'One of: product_cat, product_tag, specific_product, or pa_* taxonomy'),
                            'condition_operator' => array('type' => 'string', 'enum' => array('equals', 'not_equals', 'contains')),
                            'condition_value' => array('type' => 'string', 'description' => 'The slug or ID for the condition'),
                            'action_source' => array('type' => 'string', 'enum' => array('category', 'tag', 'attribute_value', 'specific_products', 'copurchase', 'trending')),
                            'action_value' => array('type' => 'string', 'description' => 'Slug for category/tag/attribute_value actions'),
                            'action_products' => array('type' => 'string', 'description' => 'Comma-separated product IDs for specific_products action'),
                            'action_orderby' => array('type' => 'string', 'enum' => array('rand', 'bestselling', 'price', 'price_desc', 'date', 'rating'), 'description' => 'Sort order. Default rand.'),
                            'action_limit' => array('type' => 'integer', 'description' => 'Max products to show. Default 4.'),
                        ),
                        'required' => array('name', 'condition_attribute', 'condition_operator', 'action_source'),
                    ),
                ),
            ),
            array(
                'type' => 'function',
                'function' => array(
                    'name' => 'update_rule',
                    'description' => 'Update an existing rule. Only provide fields you want to change.',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array(
                            'rule_id' => array('type' => 'integer', 'description' => 'ID of the rule to update'),
                            'name' => array('type' => 'string'),
                            'priority' => array('type' => 'integer'),
                            'condition_attribute' => array('type' => 'string'),
                            'condition_operator' => array('type' => 'string', 'enum' => array('equals', 'not_equals', 'contains')),
                            'condition_value' => array('type' => 'string'),
                            'action_source' => array('type' => 'string', 'enum' => array('category', 'tag', 'attribute_value', 'specific_products', 'copurchase', 'trending')),
                            'action_value' => array('type' => 'string'),
                            'action_products' => array('type' => 'string'),
                            'action_orderby' => array('type' => 'string', 'enum' => array('rand', 'bestselling', 'price', 'price_desc', 'date', 'rating')),
                            'action_limit' => array('type' => 'integer'),
                        ),
                        'required' => array('rule_id'),
                    ),
                ),
            ),
        );

        if (!empty($tavily_key)) {
            $tools[] = array(
                'type' => 'function',
                'function' => array(
                    'name' => 'search_web',
                    'description' => 'Search the web for product compatibility, best-sellers, or general firearms knowledge. Use for questions like "what holsters fit X" or "compatible optics for Y".',
                    'parameters' => array(
                        'type' => 'object',
                        'properties' => array(
                            'query' => array('type' => 'string', 'description' => 'Search query'),
                        ),
                        'required' => array('query'),
                    ),
                ),
            );
        }

        return $tools;
    }

    /**
     * Get a final text message from the AI after a terminal tool call.
     */
    private function ai_get_final_message(string $api_url, array $headers, array $chat_history, array $tools): string
    {
        $response = wp_remote_post($api_url, array(
            'body' => wp_json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => $chat_history,
                'tools' => $tools,
            )),
            'headers' => $headers,
            'timeout' => 30,
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            return __('Rule saved successfully.', 'ffl-funnels-addons');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $content = $data['choices'][0]['message']['content'] ?? '';

        return !empty($content) ? wp_kses_post($content) : __('Rule saved successfully.', 'ffl-funnels-addons');
    }

    // ── AI Tool Handlers ──────────────────────────────────────────────

    /**
     * Tool: Search the WooCommerce store catalog.
     */
    private function ai_tool_search_store(array $args): string
    {
        $type = isset($args['type']) ? sanitize_text_field($args['type']) : 'product';
        $query = isset($args['query']) ? sanitize_text_field($args['query']) : '';
        $results = array();

        if ('product' === $type) {
            $products = wc_get_products(array(
                'status' => 'publish',
                'limit' => 15,
                's' => $query,
                'return' => 'objects',
            ));
            foreach ($products as $p) {
                $item = array('id' => $p->get_id(), 'name' => $p->get_name(), 'slug' => $p->get_slug());
                $cats = wp_get_post_terms($p->get_id(), 'product_cat', array('fields' => 'names'));
                if (!is_wp_error($cats) && !empty($cats)) {
                    $item['categories'] = implode(', ', $cats);
                }
                $results[] = $item;
            }
        } elseif ('attribute' === $type) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $terms = $wpdb->get_results($wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug, tt.taxonomy
                FROM {$wpdb->terms} AS t
                INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
                WHERE t.name LIKE %s AND tt.taxonomy LIKE %s LIMIT 20",
                '%' . $wpdb->esc_like($query) . '%',
                'pa_%'
            ));
            foreach ($terms as $t) {
                $results[] = array('id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'taxonomy' => $t->taxonomy);
            }
        } else {
            $taxonomy = ('tag' === $type) ? 'product_tag' : 'product_cat';
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'name__like' => $query,
                'number' => 15,
                'hide_empty' => false,
            ));
            if (!is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $results[] = array('id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => $t->count);
                }
            }
        }

        return empty($results)
            ? sprintf('No %s found matching "%s".', $type, $query)
            : wp_json_encode($results);
    }

    /**
     * Tool: Search the web via Tavily API.
     */
    private function ai_tool_search_web(array $args, string $tavily_key): string
    {
        $query = isset($args['query']) ? $args['query'] : '';

        if (empty($tavily_key)) {
            return 'Web search is not configured (no Tavily API key).';
        }

        $response = wp_remote_post('https://api.tavily.com/search', array(
            'body' => wp_json_encode(array(
                'api_key' => trim($tavily_key),
                'query' => $query,
                'search_depth' => 'basic',
                'include_answer' => true,
                'max_results' => 5,
            )),
            'headers' => array('Content-Type' => 'application/json'),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return 'Web search failed: ' . $response->get_error_message();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['answer'])) {
            return $body['answer'];
        }

        if (isset($body['results'])) {
            return wp_json_encode(array_slice($body['results'], 0, 5));
        }

        return 'No web results found.';
    }

    /**
     * Tool: Get all existing rules.
     */
    private function ai_tool_get_rules(): string
    {
        require_once WOOBOOSTER_PATH . 'includes/class-woobooster-rule.php';
        $rules = WooBooster_Rule::get_all_rules();
        $summary = array();

        foreach ($rules as $rule) {
            $summary[] = array(
                'id' => $rule->id,
                'name' => $rule->name,
                'priority' => $rule->priority,
                'status' => $rule->status ? 'active' : 'inactive',
                'condition' => $rule->condition_attribute . ' ' . $rule->condition_operator . ' ' . $rule->condition_value,
                'action' => $rule->action_source . ':' . $rule->action_value,
            );
        }

        return empty($summary) ? 'No rules exist yet.' : wp_json_encode($summary);
    }

    /**
     * Tool: Create a new rule with proper conditions/actions table support.
     *
     * @return array{success: bool, message: string, rule_id?: int, edit_url?: string}
     */
    private function ai_tool_create_rule(array $args): array
    {
        require_once WOOBOOSTER_PATH . 'includes/class-woobooster-rule.php';

        $rule_data = array(
            'name' => sanitize_text_field($args['name'] ?? ''),
            'priority' => absint($args['priority'] ?? 10),
            'status' => 0, // Inactive — let owner review first.
            'condition_attribute' => sanitize_key($args['condition_attribute'] ?? ''),
            'condition_operator' => $args['condition_operator'] ?? 'equals',
            'condition_value' => sanitize_text_field($args['condition_value'] ?? ''),
            'action_source' => sanitize_key($args['action_source'] ?? 'category'),
            'action_value' => sanitize_text_field($args['action_value'] ?? ''),
            'action_orderby' => sanitize_key($args['action_orderby'] ?? 'rand'),
            'action_limit' => max(1, absint($args['action_limit'] ?? 4)),
        );

        $rule_id = WooBooster_Rule::create($rule_data);

        if (!$rule_id) {
            return array('success' => false, 'message' => 'Failed to save rule to database.');
        }

        // Save condition to the conditions table.
        WooBooster_Rule::save_conditions($rule_id, array(
            array( // Group 0
                array(
                    'condition_attribute' => $rule_data['condition_attribute'],
                    'condition_operator' => $rule_data['condition_operator'],
                    'condition_value' => $rule_data['condition_value'],
                    'include_children' => 1,
                    'min_quantity' => 1,
                ),
            ),
        ));

        // Save action to the actions table.
        $action_row = array(
            'action_source' => $rule_data['action_source'],
            'action_value' => $rule_data['action_value'],
            'action_orderby' => $rule_data['action_orderby'],
            'action_limit' => $rule_data['action_limit'],
            'include_children' => 1,
        );
        if (!empty($args['action_products'])) {
            $action_row['action_products'] = sanitize_text_field($args['action_products']);
            // Auto-derive action_limit from product count for specific_products
            if ('specific_products' === $rule_data['action_source']) {
                $product_ids = array_filter(array_map('intval', explode(',', $action_row['action_products'])));
                if (!empty($product_ids)) {
                    $action_row['action_limit'] = count($product_ids);
                }
            }
        }
        WooBooster_Rule::save_actions($rule_id, array(
            array($action_row), // Group 0
        ));

        $edit_url = admin_url('admin.php?page=ffla-woobooster-rules&action=edit&rule_id=' . $rule_id);

        return array(
            'success' => true,
            'message' => sprintf('Rule #%d "%s" created successfully (inactive). Edit URL: %s', $rule_id, $rule_data['name'], $edit_url),
            'rule_id' => $rule_id,
            'edit_url' => $edit_url,
        );
    }

    /**
     * Tool: Update an existing rule.
     *
     * @return array{success: bool, message: string, rule_id?: int, edit_url?: string}
     */
    private function ai_tool_update_rule(array $args): array
    {
        require_once WOOBOOSTER_PATH . 'includes/class-woobooster-rule.php';

        $rule_id = absint($args['rule_id'] ?? 0);
        if (!$rule_id) {
            return array('success' => false, 'message' => 'Missing rule_id.');
        }

        $existing = WooBooster_Rule::get($rule_id);
        if (!$existing) {
            return array('success' => false, 'message' => sprintf('Rule #%d not found.', $rule_id));
        }

        // Update main rule table (only provided fields).
        $update_data = array();
        $field_map = array('name', 'priority', 'condition_attribute', 'condition_operator', 'condition_value', 'action_source', 'action_value', 'action_orderby', 'action_limit');
        foreach ($field_map as $field) {
            if (isset($args[$field])) {
                $update_data[$field] = $args[$field];
            }
        }

        if (!empty($update_data)) {
            WooBooster_Rule::update($rule_id, $update_data);
        }

        // If condition fields changed, rebuild conditions table.
        if (isset($args['condition_attribute'])) {
            WooBooster_Rule::save_conditions($rule_id, array(
                array(
                    array(
                        'condition_attribute' => sanitize_key($args['condition_attribute']),
                        'condition_operator' => $args['condition_operator'] ?? $existing->condition_operator,
                        'condition_value' => sanitize_text_field($args['condition_value'] ?? $existing->condition_value),
                        'include_children' => 1,
                        'min_quantity' => 1,
                    ),
                ),
            ));
        }

        // If action fields changed, rebuild actions table.
        if (isset($args['action_source'])) {
            $action_row = array(
                'action_source' => sanitize_key($args['action_source']),
                'action_value' => sanitize_text_field($args['action_value'] ?? $existing->action_value),
                'action_orderby' => sanitize_key($args['action_orderby'] ?? 'rand'),
                'action_limit' => max(1, absint($args['action_limit'] ?? 4)),
                'include_children' => 1,
            );
            if (!empty($args['action_products'])) {
                $action_row['action_products'] = sanitize_text_field($args['action_products']);
            }
            WooBooster_Rule::save_actions($rule_id, array(
                array($action_row),
            ));
        }

        $edit_url = admin_url('admin.php?page=ffla-woobooster-rules&action=edit&rule_id=' . $rule_id);

        return array(
            'success' => true,
            'message' => sprintf('Rule #%d updated. Edit URL: %s', $rule_id, $edit_url),
            'rule_id' => $rule_id,
            'edit_url' => $edit_url,
        );
    }
    /**
     * Render the AI Chat Modal HTML structure
     */
    /**
     * AJAX: Create a rule from AI chat suggestion.
     * Called when user clicks "Create Rule" button after AI proposes one.
     */
    public function ajax_ai_create_rule()
    {
        check_ajax_referer('woobooster_admin', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'ffl-funnels-addons')));
        }

        // Parse incoming rule data from frontend.
        $rule_data = isset($_POST['rule_data']) ? wp_unslash($_POST['rule_data']) : '';
        if (empty($rule_data)) {
            wp_send_json_error(array('message' => __('No rule data provided.', 'ffl-funnels-addons')));
        }

        // Decode and validate.
        $data = json_decode($rule_data, true);
        if (!is_array($data)) {
            wp_send_json_error(array('message' => __('Invalid rule data format.', 'ffl-funnels-addons')));
        }

        // Create the rule via the tool function (reuse existing logic).
        $result = $this->ai_tool_create_rule($data);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'rule_id' => $result['rule_id'],
                'edit_url' => $result['edit_url'],
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }

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
                                    <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                                    <path d="M19 3v4"/><path d="M21 5h-4"/>
                                </svg>
                                <?php esc_html_e('WooBooster AI Assistant', 'ffl-funnels-addons'); ?>
                            </h3>
                            <div class="wb-ai-modal__header-actions">
                                <button type="button" id="wb-clear-ai-chat" class="wb-ai-modal__clear">
                                    <?php esc_html_e('Clear', 'ffl-funnels-addons'); ?>
                                </button>
                                <button type="button" id="wb-close-ai-modal" class="wb-ai-modal__close"
                                    aria-label="<?php esc_attr_e('Close', 'ffl-funnels-addons'); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M18 6L6 18M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Chat Body -->
                        <div id="wb-ai-chat-body" class="wb-ai-modal__body">
                            <!-- Empty State -->
                            <div id="wb-ai-empty-state" class="wb-ai-empty">
                                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                                    <path d="M19 3v4"/><path d="M21 5h-4"/>
                                </svg>
                                <h4><?php esc_html_e('What kind of rule do you need?', 'ffl-funnels-addons'); ?></h4>
                                <p><?php esc_html_e('Describe your cross-sell or upsell goal. The AI will search your store catalog, look up product compatibility on the web, and create the rule for you.', 'ffl-funnels-addons'); ?></p>

                                <div class="wb-ai-suggestions">
                                    <button type="button" class="wb-ai-suggestion-btn"
                                        data-prompt="Find the best-selling holsters for the Glock 19 and recommend them when someone views that gun">
                                        <?php esc_html_e('Recommend holsters for the Glock 19', 'ffl-funnels-addons'); ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                    </button>
                                    <button type="button" class="wb-ai-suggestion-btn"
                                        data-prompt="When someone looks at any 9mm ammo, show them eye and ear protection from my store">
                                        <?php esc_html_e('Cross-sell safety gear with 9mm ammo', 'ffl-funnels-addons'); ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                    </button>
                                    <button type="button" class="wb-ai-suggestion-btn"
                                        data-prompt="Show compatible optics and red dots when a customer views any AR-15 rifle">
                                        <?php esc_html_e('Suggest optics for AR-15 rifles', 'ffl-funnels-addons'); ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                    </button>
                                    <button type="button" class="wb-ai-suggestion-btn"
                                        data-prompt="When viewing any shotgun, recommend cleaning kits and cases that fit">
                                        <?php esc_html_e('Cleaning kits & cases for shotguns', 'ffl-funnels-addons'); ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Typing Indicator -->
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
                                    placeholder="<?php esc_attr_e('Describe a recommendation rule...', 'ffl-funnels-addons'); ?>"
                                    rows="1"></textarea>
                                <button type="submit" id="wb-ai-submit-btn" class="wb-ai-submit" disabled
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
