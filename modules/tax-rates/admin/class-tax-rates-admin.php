<?php
/**
 * Tax Rates Admin — Settings page.
 *
 * Renders the state selector, progress UI, and import log.
 * Uses the shared WB design system (.wb-* classes / --wb-* variables).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Rates_Admin
{
    const OPTION_KEY = 'ffl_tax_rates_settings';

    public function init(): void
    {
        add_action('admin_post_ffla_tax_rates_save_settings', [$this, 'save_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /* ── Assets ────────────────────────────────────────────────────── */

    public function enqueue_assets(string $hook): void
    {
        // Only load on our page.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'ffla-tax-rates') {
            return;
        }

        $base_url = plugin_dir_url(dirname(__DIR__)) . 'modules/tax-rates/admin/';

        wp_enqueue_script(
            'ffla-tax-rates-admin',
            $base_url . 'js/tax-rates-admin.js',
            ['jquery'],
            FFLA_VERSION,
            true
        );

        wp_localize_script('ffla-tax-rates-admin', 'FflataxRates', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('ffla_tax_rates_nonce'),
            'i18n'     => [
                'researching' => __('Researching', 'ffl-funnels-addons'),
                'imported'    => __('rates imported', 'ffl-funnels-addons'),
                'failed'      => __('Failed', 'ffl-funnels-addons'),
                'done'        => __('Import complete', 'ffl-funnels-addons'),
                'of'          => __('of', 'ffl-funnels-addons'),
                'states'      => __('states', 'ffl-funnels-addons'),
                'noStates'    => __('Please select at least one state.', 'ffl-funnels-addons'),
            ],
        ]);

        wp_enqueue_style(
            'ffla-tax-rates-admin',
            $base_url . 'css/tax-rates-admin.css',
            [],
            FFLA_VERSION
        );
    }

    /* ── Save Settings ─────────────────────────────────────────────── */

    public function save_settings(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        check_admin_referer('ffla_tax_rates_save_settings', 'ffla_tax_rates_nonce');

        $settings = [
            'rate_depth'   => in_array($_POST['rate_depth'] ?? '', ['state', 'county'], true)
                ? sanitize_text_field(wp_unslash($_POST['rate_depth']))
                : 'county',
            'auto_refresh' => isset($_POST['auto_refresh']) ? '1' : '0',
        ];

        update_option(self::OPTION_KEY, $settings);

        // Re-evaluate cron schedule based on new setting.
        Tax_Rates_Cron::maybe_schedule();

        wp_safe_redirect(add_query_arg(
            ['page' => 'ffla-tax-rates', 'saved' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /* ── Render Page ───────────────────────────────────────────────── */

    public function render_settings_page(): void
    {
        $s = wp_parse_args(get_option(self::OPTION_KEY, []), [
            'rate_depth'   => 'county',
            'auto_refresh' => '1',
        ]);

        $wb_settings = get_option('woobooster_settings', []);
        $has_openai  = !empty($wb_settings['openai_key']);
        $has_tavily  = !empty($wb_settings['tavily_key']);
        $wc_enabled  = get_option('woocommerce_calc_taxes') === 'yes';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['saved'])) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        // ── WooCommerce tax not enabled warning ──
        if (!$wc_enabled) {
            FFLA_Admin::render_notice('warning',
                __('WooCommerce taxes are currently disabled. Go to <strong>WooCommerce → Settings → Tax</strong> and enable "Enable tax rates and calculations" for imported rates to take effect.', 'ffl-funnels-addons')
            );
        }

        // ── Missing API keys warning ──
        if (!$has_openai) {
            FFLA_Admin::render_notice('warning',
                __('OpenAI API key is not configured. Add it in <strong>FFL Funnels → WooBooster → Settings</strong>. Without it, tax rates cannot be imported.', 'ffl-funnels-addons')
            );
        }

        if (!$has_tavily) {
            FFLA_Admin::render_notice('info',
                __('Tavily API key is not set (optional). Without it, OpenAI will use its built-in knowledge for tax data — results may be less current. Add it in <strong>WooBooster → Settings</strong>.', 'ffl-funnels-addons')
            );
        }

        // ── Note on data accuracy ──
        FFLA_Admin::render_notice('info',
            __('Imported rates are researched via web search + AI. Always verify against your state\'s official revenue department before going live.', 'ffl-funnels-addons')
        );

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ffla_tax_rates_save_settings">';
        wp_nonce_field('ffla_tax_rates_save_settings', 'ffla_tax_rates_nonce');

        // ── Import Settings Card ─────────────────────────────────────
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Import Settings', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_select_field(
            __('Rate depth', 'ffl-funnels-addons'),
            'rate_depth',
            $s['rate_depth'],
            [
                'county' => __('State + County (recommended)', 'ffl-funnels-addons'),
                'state'  => __('State only', 'ffl-funnels-addons'),
            ],
            __('County-level rates cover local surtaxes. State-only is faster but less precise.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Monthly auto-refresh', 'ffl-funnels-addons'),
            'auto_refresh',
            $s['auto_refresh'],
            __('Automatically re-import all previously imported states every 30 days to keep rates current.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div style="padding-top: var(--wb-spacing-lg); padding-bottom: var(--wb-spacing-xl);">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';

        // ── State Selector Card ──────────────────────────────────────
        $this->render_state_selector_card($s['rate_depth']);

        // ── Progress Card (hidden until import starts) ───────────────
        $this->render_progress_card();

        // ── Import Log Card ──────────────────────────────────────────
        $this->render_log_card();
    }

    /* ── State Selector ────────────────────────────────────────────── */

    private function render_state_selector_card(string $depth): void
    {
        $states = Tax_Rates_Importer::get_us_states();

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header">';
        echo '<h3>' . esc_html__('Select States to Import', 'ffl-funnels-addons') . '</h3>';
        echo '<div class="wb-card__actions">';
        echo '<button type="button" id="ffla-tax-select-all" class="wb-btn wb-btn--subtle wb-btn--sm">' . esc_html__('Select All', 'ffl-funnels-addons') . '</button>';
        echo '<button type="button" id="ffla-tax-deselect-all" class="wb-btn wb-btn--subtle wb-btn--sm">' . esc_html__('Deselect All', 'ffl-funnels-addons') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="wb-card__body">';

        echo '<div class="ffla-tax-states-grid">';
        foreach ($states as $code => $name) {
            $log    = get_option('ffla_tax_import_' . $code, null);
            $status = '';
            if ($log !== null) {
                if (($log['status'] ?? '') === 'ok') {
                    $date   = date_i18n(get_option('date_format'), strtotime($log['imported_at']));
                    $count  = intval($log['count'] ?? 0);
                    $status = '<span class="ffla-tax-state-status ffla-tax-state-status--ok">' . esc_html($date . ' — ' . $count . ' rates') . '</span>';
                } else {
                    $status = '<span class="ffla-tax-state-status ffla-tax-state-status--error">' . esc_html__('Error', 'ffl-funnels-addons') . '</span>';
                }
            } else {
                $status = '<span class="ffla-tax-state-status ffla-tax-state-status--none">' . esc_html__('Not imported', 'ffl-funnels-addons') . '</span>';
            }

            echo '<label class="ffla-tax-state-item">';
            echo '<span class="wb-checkbox">';
            echo '<input type="checkbox" class="ffla-tax-state-checkbox" value="' . esc_attr($code) . '" data-name="' . esc_attr($name) . '">';
            echo '<span class="wb-checkbox__indicator"></span>';
            echo '</span>';
            echo '<span class="ffla-tax-state-info">';
            echo '<span class="ffla-tax-state-name">' . esc_html($code) . ' — ' . esc_html($name) . '</span>';
            echo $status;
            echo '</span>';
            echo '</label>';
        }
        echo '</div>';

        echo '<div style="margin-top: var(--wb-spacing-xl);">';
        echo '<button type="button" id="ffla-tax-import-btn" class="wb-btn wb-btn--primary" data-depth="' . esc_attr($depth) . '">';
        echo esc_html__('Research & Import Selected States', 'ffl-funnels-addons');
        echo '</button>';
        echo '</div>';

        echo '</div></div>';
    }

    /* ── Progress Card ─────────────────────────────────────────────── */

    private function render_progress_card(): void
    {
        echo '<div id="ffla-tax-progress" class="wb-card" style="display:none; margin-top: var(--wb-spacing-xl);">';
        echo '<div class="wb-card__header"><h3 id="ffla-tax-progress-title">' . esc_html__('Importing Tax Rates', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        // Progress bar.
        echo '<div class="ffla-tax-progress-bar-wrap">';
        echo '<div id="ffla-tax-bar" class="ffla-tax-progress-bar"></div>';
        echo '</div>';

        // Animated status label (reuses wb-ai-loading-message style).
        echo '<div class="wb-ai-loading-message" style="margin-top: var(--wb-spacing-lg);">';
        echo '<span id="ffla-tax-current-label">&hellip;</span>';
        echo '<span class="wb-ai-dots"><span></span><span></span><span></span></span>';
        echo '</div>';

        // Line-by-line log.
        echo '<div id="ffla-tax-log" class="ffla-tax-log"></div>';

        // "X of Y states" counter.
        echo '<p id="ffla-tax-count" class="ffla-tax-count">0 ' . esc_html__('of', 'ffl-funnels-addons') . ' 0 ' . esc_html__('states', 'ffl-funnels-addons') . '</p>';

        echo '</div></div>';
    }

    /* ── Log Card ──────────────────────────────────────────────────── */

    private function render_log_card(): void
    {
        $states = Tax_Rates_Importer::get_us_states();
        $rows   = [];

        foreach ($states as $code => $name) {
            $log = get_option('ffla_tax_import_' . $code, null);
            if ($log !== null) {
                $rows[] = ['code' => $code, 'name' => $name, 'log' => $log];
            }
        }

        if (empty($rows)) {
            return;
        }

        echo '<div class="wb-card" style="margin-top: var(--wb-spacing-xl);">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Import Log', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body wb-card__body--table">';
        echo '<table class="wb-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('State', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Rates imported', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Last updated', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Status', 'ffl-funnels-addons') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($rows as $row) {
            $log    = $row['log'];
            $ok     = ($log['status'] ?? '') === 'ok';
            $date   = isset($log['imported_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log['imported_at'])) : '—';
            $status = $ok
                ? '<span class="wb-status wb-status--active">' . esc_html__('OK', 'ffl-funnels-addons') . '</span>'
                : '<span class="wb-status wb-status--inactive">' . esc_html__('Error', 'ffl-funnels-addons') . '</span>';

            echo '<tr>';
            echo '<td><strong>' . esc_html($row['code']) . '</strong> ' . esc_html($row['name']) . '</td>';
            echo '<td>' . esc_html($log['count'] ?? '0') . '</td>';
            echo '<td>' . esc_html($date) . '</td>';
            echo '<td>' . $status . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }
}
