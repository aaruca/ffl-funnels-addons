<?php
/**
 * Tax Rates Admin — Settings & Dashboard.
 *
 * Admin interface for the Tax Address Resolver with panels:
 *   1. Address Lookup — manual tax quote test
 *   2. Coverage Matrix — visual state grid
 *   3. Dataset Management — active versions, sync, upload
 *   4. Settings — cache TTL, sync schedule, API key
 *   5. Audit Log — recent query history
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Rates_Admin
{
    const SETTINGS_KEY = 'ffla_tax_resolver_settings';

    public function init(): void
    {
        add_action('admin_post_ffla_tax_resolver_save_settings', [$this, 'save_settings']);
        add_action('wp_ajax_ffla_tax_quote_lookup', [$this, 'ajax_quote_lookup']);
        add_action('wp_ajax_ffla_tax_upload_csv', [$this, 'ajax_upload_csv']);
        add_action('wp_ajax_ffla_tax_sync_wc', [$this, 'ajax_sync_wc']);
        add_action('wp_ajax_ffla_tax_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_ffla_tax_refresh_handbook', [$this, 'ajax_refresh_handbook']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /* ── Assets ────────────────────────────────────────────────────── */

    public function enqueue_assets(string $hook): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'ffla-tax-rates') {
            return;
        }

        $base_url = FFLA_URL . 'modules/tax-rates/admin/';

        wp_enqueue_script(
            'ffla-tax-rates-admin',
            $base_url . 'js/tax-rates-admin.js',
            ['jquery'],
            FFLA_VERSION,
            true
        );

        wp_localize_script('ffla-tax-rates-admin', 'FflaTaxResolver', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => esc_url_raw(rest_url('ffl-tax/v1/')),
            'nonce'   => wp_create_nonce('ffla_tax_resolver_nonce'),
            'restNonce' => wp_create_nonce('wp_rest'),
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

        check_admin_referer('ffla_tax_resolver_save', 'ffla_tax_nonce');

        $enabled_states = [];
        if (!empty($_POST['enabled_states']) && is_array($_POST['enabled_states'])) {
            foreach (wp_unslash($_POST['enabled_states']) as $state_code) {
                $state_code = strtoupper(sanitize_text_field((string) $state_code));
                if (preg_match('/^[A-Z]{2}$/', $state_code)) {
                    $enabled_states[] = $state_code;
                }
            }
        }

        $enabled_states = array_values(array_unique($enabled_states));
        sort($enabled_states);

        $settings = [
            'cache_ttl'     => max(60, (int) ($_POST['cache_ttl'] ?? 86400)),
            'auto_sync'     => isset($_POST['auto_sync']) ? '1' : '0',
            'sync_schedule' => in_array($_POST['sync_schedule'] ?? '', ['monthly', 'quarterly'], true)
                ? sanitize_text_field(wp_unslash($_POST['sync_schedule'])) : 'quarterly',
            'wc_auto_sync'  => isset($_POST['wc_auto_sync']) ? '1' : '0',
            'api_key_enabled' => isset($_POST['api_key_enabled']) ? '1' : '0',
            'restrict_states' => isset($_POST['restrict_states']) ? '1' : '0',
            'enabled_states'  => $enabled_states,
        ];

        // Handle API key.
        if (!empty($_POST['api_key'])) {
            update_option('ffla_tax_api_key', sanitize_text_field(wp_unslash($_POST['api_key'])));
        }

        update_option(self::SETTINGS_KEY, $settings);
        Tax_Rates_Cron::maybe_schedule();

        wp_safe_redirect(add_query_arg(
            ['page' => 'ffla-tax-rates', 'tab' => 'settings', 'saved' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    /* ── AJAX: Quote Lookup ────────────────────────────────────────── */

    public function ajax_quote_lookup(): void
    {
        check_ajax_referer('ffla_tax_resolver_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $input = [
            'street' => sanitize_text_field(wp_unslash($_POST['street'] ?? '')),
            'city'   => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
            'state'  => sanitize_text_field(wp_unslash($_POST['state'] ?? '')),
            'zip'    => sanitize_text_field(wp_unslash($_POST['zip'] ?? '')),
        ];

        $result = Tax_Quote_Engine::quote($input);

        wp_send_json_success($result->to_array());
    }

    /* ── AJAX: CSV Upload ──────────────────────────────────────────── */

    public function ajax_upload_csv(): void
    {
        check_ajax_referer('ffla_tax_resolver_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        if (empty($_FILES['csv_file'])) {
            wp_send_json_error('No file uploaded.');
        }

        $state_code = strtoupper(sanitize_text_field($_POST['state_code'] ?? ''));
        if (empty($state_code) || !preg_match('/^[A-Z]{2}$/', $state_code)) {
            wp_send_json_error('Invalid state code.');
        }

        $file = $_FILES['csv_file'];

        // Validate file type.
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            wp_send_json_error('Only CSV files are accepted.');
        }

        // Move to datasets directory.
        $dir  = Tax_Dataset_Pipeline::get_storage_dir();
        $dest = $dir . 'SST_' . $state_code . '.csv';

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            wp_send_json_error('Failed to save uploaded file.');
        }

        // Import.
        $result = Tax_Dataset_Pipeline::import_csv($dest, $state_code, 'admin_upload');

        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    __('Imported %d rates for %s.', 'ffl-funnels-addons'),
                    $result['rows'],
                    $state_code
                ),
                'rows' => $result['rows'],
            ]);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /* ── AJAX: WC Sync ─────────────────────────────────────────────── */

    public function ajax_sync_wc(): void
    {
        check_ajax_referer('ffla_tax_resolver_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $state = sanitize_text_field($_POST['state'] ?? '');

        if ($state) {
            $count = Tax_Quote_Engine::sync_to_woocommerce(strtoupper($state));
            wp_send_json_success([
                'message' => sprintf(__('%d rates synced to WooCommerce for %s.', 'ffl-funnels-addons'), $count, $state),
                'count'   => $count,
            ]);
        } else {
            $results = Tax_Quote_Engine::sync_all_to_woocommerce();
            wp_send_json_success([
                'message' => sprintf(__('%d total rates synced to WooCommerce.', 'ffl-funnels-addons'), array_sum($results)),
                'results' => $results,
            ]);
        }
    }

    /* ── AJAX: Run Sync ────────────────────────────────────────────── */

    public function ajax_run_sync(): void
    {
        check_ajax_referer('ffla_tax_resolver_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $results = Tax_Dataset_Pipeline::sync('all');
        wp_send_json_success([
            'message' => __('Dataset sync completed.', 'ffl-funnels-addons'),
            'results' => $results,
        ]);
    }

    /* ── AJAX: Refresh SalesTaxHandbook ───────────────────────────── */

    public function ajax_refresh_handbook(): void
    {
        check_ajax_referer('ffla_tax_resolver_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        if (!class_exists('Official_State_Floor_Resolver')) {
            wp_send_json_error('SalesTaxHandbook fallback resolver is not available.');
        }

        $results = Official_State_Floor_Resolver::refresh_handbook_cache(false);
        $state_targets = (int) ($results['stateCount'] ?? 0);
        $state_pages = (int) ($results['statePagesRefreshed'] ?? 0);
        $message = sprintf(
            __('SalesTaxHandbook refresh checked %1$d fallback states and refreshed %2$d state city tables.', 'ffl-funnels-addons'),
            $state_targets,
            $state_pages
        );

        wp_send_json_success([
            'message' => $message,
            'results' => $results,
        ]);
    }

    /* ── Render Main Page ──────────────────────────────────────────── */

    public function render_settings_page(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = sanitize_key($_GET['tab'] ?? 'lookup');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['saved'])) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        // WooCommerce tax check.
        if (get_option('woocommerce_calc_taxes') !== 'yes') {
            FFLA_Admin::render_notice('warning',
                __('WooCommerce taxes are disabled. Enable them in <strong>WooCommerce → Settings → Tax</strong> for resolved rates to apply at checkout.', 'ffl-funnels-addons')
            );
        }

        // Tab navigation.
        $tabs = [
            'lookup'   => __('Quote Lookup', 'ffl-funnels-addons'),
            'coverage' => __('Coverage Matrix', 'ffl-funnels-addons'),
            'datasets' => __('Datasets', 'ffl-funnels-addons'),
            'audit'    => __('Audit Log', 'ffl-funnels-addons'),
            'settings' => __('Settings', 'ffl-funnels-addons'),
        ];

        echo '<div class="ffla-tax-tabs">';
        foreach ($tabs as $key => $label) {
            $active = ($key === $tab) ? ' ffla-tax-tabs__tab--active' : '';
            $url    = add_query_arg(['page' => 'ffla-tax-rates', 'tab' => $key], admin_url('admin.php'));
            echo '<a href="' . esc_url($url) . '" class="ffla-tax-tabs__tab' . esc_attr($active) . '">';
            echo esc_html($label);
            echo '</a>';
        }
        echo '</div>';

        switch ($tab) {
            case 'coverage':
                $this->render_coverage_tab();
                break;
            case 'datasets':
                $this->render_datasets_tab();
                break;
            case 'audit':
                $this->render_audit_tab();
                break;
            case 'settings':
                $this->render_settings_tab();
                break;
            default:
                $this->render_lookup_tab();
        }
    }

    /* ── Tab: Quote Lookup ─────────────────────────────────────────── */

    private function render_lookup_tab(): void
    {
        $state_filter_active = Tax_Coverage::has_state_filter();
        $enabled_states      = Tax_Coverage::get_enabled_states();

        echo '<div class="ffla-tax-lookup-layout">';

        // Input form.
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Tax Quote Lookup', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc" style="margin-bottom:var(--wb-spacing-lg)">';
        echo esc_html__('Enter a US address to look up the applicable sales tax rate and jurisdictional breakdown.', 'ffl-funnels-addons');
        echo '</p>';

        if ($state_filter_active) {
            echo '<div class="wb-message wb-message--info" style="margin-bottom:var(--wb-spacing-lg)">';
            echo '<span>' . esc_html(sprintf(
                __('This store currently uses the resolver only for %d selected states. Lookups outside that list will be rejected until enabled in Settings.', 'ffl-funnels-addons'),
                count($enabled_states)
            )) . '</span>';
            echo '</div>';
        }

        echo '<div class="ffla-tax-lookup-form" id="ffla-tax-lookup-form">';

        echo '<div class="wb-field"><label class="wb-field__label" for="ffla-tax-street">' . esc_html__('Street', 'ffl-funnels-addons') . '</label>';
        echo '<div class="wb-field__control"><input type="text" id="ffla-tax-street" class="wb-input" placeholder="123 Main St"></div></div>';

        echo '<div class="ffla-tax-row ffla-tax-row--stack">';
        echo '<div class="wb-field ffla-tax-field--city"><label class="wb-field__label" for="ffla-tax-city">' . esc_html__('City', 'ffl-funnels-addons') . '</label>';
        echo '<div class="wb-field__control"><input type="text" id="ffla-tax-city" class="wb-input" placeholder="Chicago"></div></div>';

        echo '<div class="wb-field ffla-tax-field--state"><label class="wb-field__label" for="ffla-tax-state">' . esc_html__('State', 'ffl-funnels-addons') . '</label>';
        echo '<div class="wb-field__control"><input type="text" id="ffla-tax-state" class="wb-input" placeholder="IL" maxlength="2"></div></div>';

        echo '<div class="wb-field ffla-tax-field--zip"><label class="wb-field__label" for="ffla-tax-zip">' . esc_html__('ZIP', 'ffl-funnels-addons') . '</label>';
        echo '<div class="wb-field__control"><input type="text" id="ffla-tax-zip" class="wb-input" placeholder="60601" maxlength="10"></div></div>';
        echo '</div>';

        echo '<button type="button" id="ffla-tax-lookup-btn" class="wb-btn wb-btn--primary">';
        echo esc_html__('Look Up Tax Rate', 'ffl-funnels-addons');
        echo '</button>';
        echo '</div>';

        echo '</div></div>';

        // Result panel.
        echo '<div class="wb-card" id="ffla-tax-result-card" style="display:none">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Quote Result', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body" id="ffla-tax-result-body"></div>';
        echo '</div>';

        echo '</div>'; // .ffla-tax-lookup-layout

        // Disclaimer.
        FFLA_Admin::render_notice('info',
            __('This tool provides informational tax rate quotes using official government sources first and approved secondary source fallbacks where official local coverage is not yet integrated. It is not a legal determination and does not replace professional tax advice.', 'ffl-funnels-addons')
        );
    }

    /* ── Tab: Coverage Matrix ──────────────────────────────────────── */

    private function render_coverage_tab(): void
    {
        $matrix = Tax_Coverage::get_matrix();
        $state_filter_active = Tax_Coverage::has_state_filter();

        // Stats.
        $supported = 0;
        $unsupported = 0;
        $no_tax = 0;
        $enabled_for_store = 0;
        $disabled_for_store = 0;
        foreach ($matrix as $r) {
            switch ($r['coverage_status']) {
                case Tax_Coverage::SUPPORTED_ADDRESS_RATE:
                case Tax_Coverage::SUPPORTED_WITH_REMOTE:
                case Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED:
                    $supported++;
                    break;
                case Tax_Coverage::NO_SALES_TAX:
                    $no_tax++;
                    break;
                default:
                    $unsupported++;
            }

            if (Tax_Coverage::is_enabled_for_store($r['state_code'])) {
                $enabled_for_store++;
            } else {
                $disabled_for_store++;
            }
        }

        // Stats cards.
        echo '<div class="ffla-tax-stats">';
        echo '<div class="ffla-tax-stat ffla-tax-stat--supported"><span class="ffla-tax-stat__value">' . esc_html($supported) . '</span><span class="ffla-tax-stat__label">' . esc_html__('Supported', 'ffl-funnels-addons') . '</span></div>';
        echo '<div class="ffla-tax-stat ffla-tax-stat--no-tax"><span class="ffla-tax-stat__value">' . esc_html($no_tax) . '</span><span class="ffla-tax-stat__label">' . esc_html__('No Sales Tax', 'ffl-funnels-addons') . '</span></div>';
        echo '<div class="ffla-tax-stat ffla-tax-stat--enabled"><span class="ffla-tax-stat__value">' . esc_html($enabled_for_store) . '</span><span class="ffla-tax-stat__label">' . esc_html__('Enabled For Store', 'ffl-funnels-addons') . '</span></div>';
        echo '<div class="ffla-tax-stat ffla-tax-stat--disabled"><span class="ffla-tax-stat__value">' . esc_html($disabled_for_store) . '</span><span class="ffla-tax-stat__label">' . esc_html__('Disabled For Store', 'ffl-funnels-addons') . '</span></div>';
        echo '<div class="ffla-tax-stat ffla-tax-stat--unsupported"><span class="ffla-tax-stat__value">' . esc_html($unsupported) . '</span><span class="ffla-tax-stat__label">' . esc_html__('Not Yet Supported', 'ffl-funnels-addons') . '</span></div>';
        echo '</div>';

        if ($state_filter_active) {
            FFLA_Admin::render_notice(
                'info',
                __('State filtering is active. Cells marked Off are technically covered by the resolver but currently disabled for this store.', 'ffl-funnels-addons')
            );
        }

        FFLA_Admin::render_notice(
            'info',
            __('Source model: official state sources are primary wherever they exist. States still waiting on official local coverage use the SalesTaxHandbook state city table first, with the official statewide floor kept as a conservative fallback.', 'ffl-funnels-addons')
        );

        // State grid.
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('State Coverage Matrix', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<div class="ffla-tax-coverage-grid">';

        $names = self::get_state_names();

        foreach ($matrix as $row) {
            $code   = $row['state_code'];
            $status = $row['coverage_status'];
            $name   = $names[$code] ?? $code;
            $store_enabled = Tax_Coverage::is_enabled_for_store($code);
            $strategy = Tax_Coverage::get_source_strategy($code);

            $class = 'ffla-tax-coverage-cell';
            $badge = '';

            switch ($status) {
                case Tax_Coverage::SUPPORTED_ADDRESS_RATE:
                    $class .= ' ffla-tax-coverage-cell--supported';
                    $badge = '+';
                    break;
                case Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED:
                    $class .= ' ffla-tax-coverage-cell--context';
                    $badge = '~';
                    break;
                case Tax_Coverage::SUPPORTED_WITH_REMOTE:
                    $class .= ' ffla-tax-coverage-cell--remote';
                    $badge = 'R';
                    break;
                case Tax_Coverage::NO_SALES_TAX:
                    $class .= ' ffla-tax-coverage-cell--no-tax';
                    $badge = '0';
                    break;
                case Tax_Coverage::DEGRADED:
                    $class .= ' ffla-tax-coverage-cell--degraded';
                    $badge = '!';
                    break;
                default:
                    $class .= ' ffla-tax-coverage-cell--unsupported';
                    $badge = '';
            }

            if ($state_filter_active && !$store_enabled) {
                $class .= ' ffla-tax-coverage-cell--store-disabled';
            }

            $title = $name . ' - ' . $status;
            if (!empty($strategy['label'])) {
                $title .= ' - ' . $strategy['label'];
            }

            echo '<div class="' . esc_attr($class) . '" title="' . esc_attr($title) . '">';
            echo '<span class="ffla-tax-coverage-cell__code">' . esc_html($code) . '</span>';
            echo '<span class="ffla-tax-coverage-cell__badge">' . esc_html($badge) . '</span>';
            echo '<span class="ffla-tax-coverage-cell__name">' . esc_html($name) . '</span>';
            if ($row['resolver_name']) {
                echo '<span class="ffla-tax-coverage-cell__resolver">' . esc_html($row['resolver_name']) . '</span>';
            }
            if (!empty($strategy['shortLabel']) && $strategy['family'] !== Tax_Coverage::SOURCE_STRATEGY_NONE) {
                echo '<span class="ffla-tax-coverage-cell__source">' . esc_html($strategy['shortLabel']) . '</span>';
            }
            if ($state_filter_active) {
                echo '<span class="ffla-tax-coverage-cell__store">' . esc_html($store_enabled ? __('On', 'ffl-funnels-addons') : __('Off', 'ffl-funnels-addons')) . '</span>';
            }
            echo '</div>';
        }

        echo '</div>';
        echo '</div></div>';

        // Legend.
        echo '<div class="ffla-tax-legend">';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--supported"></span> ' . esc_html__('Supported', 'ffl-funnels-addons') . '</span>';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--context"></span> ' . esc_html__('Context / Dataset Required', 'ffl-funnels-addons') . '</span>';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--remote"></span> ' . esc_html__('Remote Lookup', 'ffl-funnels-addons') . '</span>';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--no-tax"></span> ' . esc_html__('No Sales Tax', 'ffl-funnels-addons') . '</span>';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--degraded"></span> ' . esc_html__('Degraded', 'ffl-funnels-addons') . '</span>';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--unsupported"></span> ' . esc_html__('Not Supported', 'ffl-funnels-addons') . '</span>';
        if ($state_filter_active) {
            echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--disabled"></span> ' . esc_html__('Disabled For Store', 'ffl-funnels-addons') . '</span>';
        }
        echo '</div>';
    }

    /* ── Tab: Datasets ─────────────────────────────────────────────── */

    private function render_datasets_tab(): void
    {
        global $wpdb;
        $handbook_status = class_exists('Official_State_Floor_Resolver')
            ? Official_State_Floor_Resolver::get_handbook_refresh_status()
            : [];
        $handbook_targets = class_exists('Official_State_Floor_Resolver')
            ? Official_State_Floor_Resolver::get_handbook_target_states()
            : [];

        // Upload form.
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Upload Rate CSV', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__('Upload a state CSV manually, or use Sync SST Datasets to download official SST rate files automatically.', 'ffl-funnels-addons') . '</p>';
        echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-xs)">' . esc_html__('Runtime sources such as Louisiana official lookup, Texas official files, statewide resolvers, and SalesTaxHandbook fallback do not create dataset rows here because they are resolved live at quote time.', 'ffl-funnels-addons') . '</p>';
        echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-xs)">' . esc_html__('WooCommerce checkout uses the runtime resolver as the primary source of truth. Syncing tax tables below is optional compatibility support only.', 'ffl-funnels-addons') . '</p>';

        if (Tax_Coverage::has_state_filter()) {
            echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm)">' . esc_html__('When state filtering is active, Sync SST Datasets downloads only the enabled SST states for this store.', 'ffl-funnels-addons') . '</p>';
            echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-xs)">' . esc_html__('SalesTaxHandbook monitoring still refreshes its fallback state pages monthly so those secondary sources stay current.', 'ffl-funnels-addons') . '</p>';
        }

        echo '<div class="ffla-tax-row ffla-tax-row--inline" style="margin-top:var(--wb-spacing-lg)">';
        echo '<div class="wb-field ffla-tax-field--state">';
        echo '<label class="wb-field__label" for="ffla-csv-state">' . esc_html__('State', 'ffl-funnels-addons') . '</label>';
        echo '<div class="wb-field__control"><input type="text" id="ffla-csv-state" class="wb-input" placeholder="IN" maxlength="2"></div>';
        echo '</div>';
        echo '<div class="wb-field" style="flex:1">';
        echo '<label class="wb-field__label" for="ffla-csv-file">' . esc_html__('CSV File', 'ffl-funnels-addons') . '</label>';
        echo '<div class="wb-field__control"><input type="file" id="ffla-csv-file" accept=".csv"></div>';
        echo '</div>';
        echo '</div>';

        echo '<div style="margin-top:var(--wb-spacing-lg)">';
        echo '<button type="button" id="ffla-csv-upload-btn" class="wb-btn wb-btn--primary">' . esc_html__('Upload & Import', 'ffl-funnels-addons') . '</button>';
        echo ' <button type="button" id="ffla-sync-btn" class="wb-btn wb-btn--subtle">' . esc_html__('Sync SST Datasets', 'ffl-funnels-addons') . '</button>';
        echo ' <button type="button" id="ffla-wc-sync-all-btn" class="wb-btn wb-btn--subtle">' . esc_html__('Sync All to WooCommerce (Compatibility)', 'ffl-funnels-addons') . '</button>';
        if (class_exists('Official_State_Floor_Resolver')) {
            echo ' <button type="button" id="ffla-handbook-refresh-btn" class="wb-btn wb-btn--subtle">' . esc_html__('Refresh SalesTaxHandbook Cache', 'ffl-funnels-addons') . '</button>';
        }
        echo '</div>';

        if (class_exists('Official_State_Floor_Resolver')) {
            echo '<div class="ffla-tax-source-status" style="margin-top:var(--wb-spacing-lg)">';
            echo '<strong>' . esc_html__('SalesTaxHandbook Fallback', 'ffl-funnels-addons') . '</strong>';
            echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-xs)">' . esc_html(sprintf(
                __('Monthly refresh runs separately for %d fallback states. It revisits each fallback state rates page and refreshes the city rate table used by the runtime resolver.', 'ffl-funnels-addons'),
                count($handbook_targets)
            )) . '</p>';

            if (!empty($handbook_status['ranAt'])) {
                echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-xs)">' . esc_html(sprintf(
                    __('Last refresh: %1$s. State pages refreshed: %2$d.', 'ffl-funnels-addons'),
                    date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($handbook_status['ranAt'])),
                    (int) ($handbook_status['statePagesRefreshed'] ?? 0)
                )) . '</p>';
            }

            echo '</div>';
        }

        echo '<div id="ffla-upload-status" class="ffla-tax-upload-status" style="display:none"></div>';

        echo '</div></div>';

        // Active datasets table.
        $table   = Tax_Resolver_DB::table('dataset_versions');
        $datasets = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'active' ORDER BY source_code, effective_date DESC",
            ARRAY_A
        ) ?: [];

        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Active Imported Datasets', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body wb-card__body--table">';

        if (empty($datasets)) {
            echo '<p style="color:var(--wb-color-neutral-foreground-3)">' . esc_html__('No SST or manual CSV datasets have been imported yet. That is expected if your store is currently using runtime sources instead.', 'ffl-funnels-addons') . '</p>';
            echo '<p style="color:var(--wb-color-neutral-foreground-3);margin-top:var(--wb-spacing-xs)">' . esc_html__('Runtime sources such as Louisiana official lookup, Texas official files, statewide resolvers, and SalesTaxHandbook fallback do not appear in this table.', 'ffl-funnels-addons') . '</p>';
        } else {
            echo '<table class="wb-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('State', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Source', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Version', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Effective', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Loaded', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Rows', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Freshness', 'ffl-funnels-addons') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($datasets as $ds) {
                $age_days = round((time() - strtotime($ds['loaded_at'])) / DAY_IN_SECONDS, 0);
                $policy   = (int) ($ds['freshness_policy'] ?: 90);
                $fresh    = $age_days <= $policy;

                echo '<tr>';
                echo '<td><strong>' . esc_html($ds['state_code'] ?: '—') . '</strong></td>';
                echo '<td><strong>' . esc_html($ds['source_code']) . '</strong></td>';
                echo '<td>' . esc_html($ds['version_label']) . '</td>';
                echo '<td>' . esc_html($ds['effective_date']) . '</td>';
                echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($ds['loaded_at']))) . '</td>';
                echo '<td>' . esc_html($ds['row_count']) . '</td>';
                echo '<td>';
                if ($fresh) {
                    echo '<span class="wb-status wb-status--active">' . esc_html($age_days . 'd / ' . $policy . 'd') . '</span>';
                } else {
                    echo '<span class="wb-status wb-status--inactive">' . esc_html__('STALE', 'ffl-funnels-addons') . ' (' . esc_html($age_days) . 'd)</span>';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
            echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm)">' . esc_html__('Only imported SST/manual datasets appear in this table. Runtime resolver sources are tracked separately and do not create dataset rows.', 'ffl-funnels-addons') . '</p>';
        }

        echo '</div></div>';
    }

    /* ── Tab: Audit Log ────────────────────────────────────────────── */

    private function render_audit_tab(): void
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('quotes_audit');
        $rows  = $wpdb->get_results(
            "SELECT query_id, requested_at, state_code, resolver_name, source_code,
                    outcome_code, confidence, total_rate, duration_ms, cache_hit, matched_address
             FROM {$table}
             ORDER BY requested_at DESC LIMIT 50",
            ARRAY_A
        ) ?: [];

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Recent Queries', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body wb-card__body--table">';

        if (empty($rows)) {
            echo '<p style="color:var(--wb-color-neutral-foreground-3)">' . esc_html__('No queries yet. Use the Quote Lookup tab to test.', 'ffl-funnels-addons') . '</p>';
        } else {
            echo '<table class="wb-table">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Time', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('State', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Matched Address', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Rate', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Outcome', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Source', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Duration', 'ffl-funnels-addons') . '</th>';
            echo '<th>' . esc_html__('Cache', 'ffl-funnels-addons') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($rows as $row) {
                $rate_display = $row['total_rate'] !== null
                    ? number_format((float) $row['total_rate'] * 100, 2) . '%'
                    : '—';

                $outcome_class = in_array($row['outcome_code'], ['SUCCESS', 'NO_SALES_TAX'], true)
                    ? 'wb-status--active' : 'wb-status--inactive';

                echo '<tr>';
                echo '<td>' . esc_html(date_i18n('M j, H:i', strtotime($row['requested_at']))) . '</td>';
                echo '<td><strong>' . esc_html($row['state_code'] ?? '—') . '</strong></td>';
                echo '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . esc_attr($row['matched_address'] ?? '') . '">'
                    . esc_html($row['matched_address'] ?: '—') . '</td>';
                echo '<td>' . esc_html($rate_display) . '</td>';
                echo '<td><span class="wb-status ' . esc_attr($outcome_class) . '">' . esc_html($row['outcome_code']) . '</span></td>';
                echo '<td>' . esc_html($row['source_code'] ?? '—') . '</td>';
                echo '<td>' . esc_html(($row['duration_ms'] ?? '—') . 'ms') . '</td>';
                echo '<td>' . ($row['cache_hit'] ? '✓' : '—') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div></div>';
    }

    /* ── Tab: Settings ─────────────────────────────────────────────── */

    private function render_settings_tab(): void
    {
        $s = wp_parse_args(get_option(self::SETTINGS_KEY, []), [
            'cache_ttl'       => 86400,
            'auto_sync'       => '1',
            'sync_schedule'   => 'quarterly',
            'wc_auto_sync'    => '1',
            'api_key_enabled' => '0',
            'restrict_states' => '0',
            'enabled_states'  => [],
        ]);

        $enabled_states = is_array($s['enabled_states']) ? $s['enabled_states'] : [];
        $api_key = get_option('ffla_tax_api_key', '');

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ffla_tax_resolver_save_settings">';
        wp_nonce_field('ffla_tax_resolver_save', 'ffla_tax_nonce');

        // General settings.
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('General Settings', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_select_field(
            __('Cache TTL', 'ffl-funnels-addons'),
            'cache_ttl',
            (string) $s['cache_ttl'],
            [
                '3600'   => __('1 hour', 'ffl-funnels-addons'),
                '21600'  => __('6 hours', 'ffl-funnels-addons'),
                '86400'  => __('24 hours (recommended)', 'ffl-funnels-addons'),
                '604800' => __('7 days', 'ffl-funnels-addons'),
            ],
            __('How long to cache tax quote results.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Auto dataset sync', 'ffl-funnels-addons'),
            'auto_sync',
            $s['auto_sync'],
            __('Automatically check for updated datasets on the configured schedule.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_select_field(
            __('Sync schedule', 'ffl-funnels-addons'),
            'sync_schedule',
            $s['sync_schedule'],
            [
                'monthly'   => __('Monthly', 'ffl-funnels-addons'),
                'quarterly' => __('Quarterly (recommended)', 'ffl-funnels-addons'),
            ],
            __('How often to check for updated rate data.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Auto-sync WooCommerce tables (compatibility)', 'ffl-funnels-addons'),
            'wc_auto_sync',
            $s['wc_auto_sync'],
            __('Optionally update WooCommerce tax tables after each dataset sync. Runtime checkout resolution remains the primary source of truth.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        // Store state access.
        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Store State Access', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Limit resolver to selected states', 'ffl-funnels-addons'),
            'restrict_states',
            $s['restrict_states'],
            __('Turn this on if the resolver should only run for states where your business is licensed or registered to operate.', 'ffl-funnels-addons')
        );

        echo '<div class="ffla-tax-state-picker" id="ffla-tax-state-picker">';
        echo '<div class="ffla-tax-state-picker__header">';
        echo '<p class="wb-field__desc">' . esc_html__('Checked states stay active for Quote Lookup, REST quotes, WooCommerce runtime overrides, and SST auto-sync. Leave the toggle off above to allow every state.', 'ffl-funnels-addons') . '</p>';
        echo '<div class="ffla-tax-state-picker__actions">';
        echo '<button type="button" class="button button-secondary ffla-tax-state-picker__action" data-state-picker-action="select-all">' . esc_html__('Select All', 'ffl-funnels-addons') . '</button>';
        echo '<button type="button" class="button button-secondary ffla-tax-state-picker__action" data-state-picker-action="select-covered">' . esc_html__('Select Covered', 'ffl-funnels-addons') . '</button>';
        echo '<button type="button" class="button button-secondary ffla-tax-state-picker__action" data-state-picker-action="clear-all">' . esc_html__('Clear', 'ffl-funnels-addons') . '</button>';
        echo '</div>';
        echo '</div>';

        echo '<div class="ffla-tax-state-picker__grid">';
        foreach (self::get_state_names() as $state_code => $state_name) {
            $coverage   = Tax_Coverage::get_state($state_code);
            $is_covered = self::is_covered_state_status($coverage['coverage_status'] ?? Tax_Coverage::UNSUPPORTED);
            $checked    = in_array($state_code, $enabled_states, true);
            $item_class = 'ffla-tax-state-picker__item' . ($is_covered ? ' ffla-tax-state-picker__item--covered' : '');

            echo '<label class="' . esc_attr($item_class) . '">';
            echo '<input type="checkbox" name="enabled_states[]" value="' . esc_attr($state_code) . '" class="ffla-tax-state-picker__checkbox" data-covered="' . esc_attr($is_covered ? '1' : '0') . '"' . checked($checked, true, false) . '>';
            echo '<span class="ffla-tax-state-picker__code">' . esc_html($state_code) . '</span>';
            echo '<span class="ffla-tax-state-picker__name">' . esc_html($state_name) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '</div>';

        echo '</div></div>';

        // API settings.
        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('API Access', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Enable API key access', 'ffl-funnels-addons'),
            'api_key_enabled',
            $s['api_key_enabled'],
            __('Allow external access to the /ffl-tax/v1/quote endpoint via API key.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('API Key', 'ffl-funnels-addons'),
            'api_key',
            $api_key,
            __('Set a secret key for the X-Tax-API-Key header. Leave empty to keep current.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div style="padding-top:var(--wb-spacing-lg);padding-bottom:var(--wb-spacing-xl)">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';
    }

    /* ── Helpers ───────────────────────────────────────────────────── */

    private static function is_covered_state_status(string $status): bool
    {
        return in_array($status, [
            Tax_Coverage::SUPPORTED_ADDRESS_RATE,
            Tax_Coverage::SUPPORTED_WITH_REMOTE,
            Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED,
            Tax_Coverage::NO_SALES_TAX,
        ], true);
    }

    private static function get_state_names(): array
    {
        return [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'DC' => 'DC', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
            'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
            'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
            'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
            'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
            'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        ];
    }
}
