<?php
/**
 * Tax Rates Admin.
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
        add_action('wp_ajax_ffla_tax_run_sync', [$this, 'ajax_run_sync']);
        add_action('wp_ajax_ffla_tax_purge_legacy_data', [$this, 'ajax_purge_legacy_data']);
        add_action('wp_ajax_ffla_tax_test_usgeocoder', [$this, 'ajax_test_usgeocoder']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

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
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'restUrl'   => esc_url_raw(rest_url('ffl-tax/v1/')),
            'nonce'     => wp_create_nonce('ffla_tax_resolver_nonce'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'i18n'      => [
                'enterStateCode'       => __('Please enter a state code.', 'ffl-funnels-addons'),
                'lookingUp'            => __('Looking up…', 'ffl-funnels-addons'),
                'lookUpTaxRate'        => __('Look Up Tax Rate', 'ffl-funnels-addons'),
                'resolvingAddress'     => __('Resolving address', 'ffl-funnels-addons'),
                'requestFailed'        => __('Request failed.', 'ffl-funnels-addons'),
                'requestFailedConsole' => __('Request failed. Check console for details.', 'ffl-funnels-addons'),
                'totalSalesTaxRate'    => __('Total Sales Tax Rate', 'ffl-funnels-addons'),
                'matched'              => __('Matched:', 'ffl-funnels-addons'),
                'jurisdiction'         => __('Jurisdiction', 'ffl-funnels-addons'),
                'type'                 => __('Type', 'ffl-funnels-addons'),
                'rate'                 => __('Rate', 'ffl-funnels-addons'),
                'coverage'             => __('Coverage', 'ffl-funnels-addons'),
                'source'               => __('Source', 'ffl-funnels-addons'),
                'version'              => __('Version', 'ffl-funnels-addons'),
                'confidence'           => __('Confidence', 'ffl-funnels-addons'),
                'scope'                => __('Scope', 'ffl-funnels-addons'),
                'mode'                 => __('Mode', 'ffl-funnels-addons'),
                'resolver'             => __('Resolver:', 'ffl-funnels-addons'),
                'geocode'              => __('Geocode:', 'ffl-funnels-addons'),
                'cache'                => __('Cache:', 'ffl-funnels-addons'),
                'yes'                  => __('Yes', 'ffl-funnels-addons'),
                'no'                   => __('No', 'ffl-funnels-addons'),
                'hit'                  => __('Hit', 'ffl-funnels-addons'),
                'miss'                 => __('Miss', 'ffl-funnels-addons'),
                'limitations'          => __('Limitations:', 'ffl-funnels-addons'),
                'state'                => __('State:', 'ffl-funnels-addons'),
                'unknownError'         => __('Unknown error.', 'ffl-funnels-addons'),
                'syncingSheetData'     => __('Syncing sheet data…', 'ffl-funnels-addons'),
                'syncingCsvDescription' => __('Downloading the shared CSV and rebuilding local state datasets. This can take a minute.', 'ffl-funnels-addons'),
                'syncFinished'         => __('Sync finished.', 'ffl-funnels-addons'),
                'syncFailed'           => __('Sync failed.', 'ffl-funnels-addons'),
                'syncSheetData'        => __('Sync Sheet Data', 'ffl-funnels-addons'),
                'sheetSyncFailed'      => __('Sheet sync request failed.', 'ffl-funnels-addons'),
                'confirmPurgeLegacy'   => __('This will permanently delete old local tax datasets, quote cache, and audit logs. Continue?', 'ffl-funnels-addons'),
                'deletingOldDatabase'  => __('Deleting old database…', 'ffl-funnels-addons'),
                'deletingLegacyData'   => __('Deleting legacy local tax data.', 'ffl-funnels-addons'),
                'cleanupFinished'      => __('Cleanup finished.', 'ffl-funnels-addons'),
                'cleanupCompleted'     => __('Cleanup completed.', 'ffl-funnels-addons'),
                'cleanupRequestFailed' => __('Cleanup request failed.', 'ffl-funnels-addons'),
                'deleteOldTaxDb'       => __('Delete Old Tax Database', 'ffl-funnels-addons'),
                'testKey'              => __('Test key', 'ffl-funnels-addons'),
                'testKeyTesting'       => __('Testing…', 'ffl-funnels-addons'),
                'testKeyEmpty'         => __('Enter a USGeocoder key first.', 'ffl-funnels-addons'),
                'testKeyOk'            => __('Key works. Sample lookup succeeded.', 'ffl-funnels-addons'),
                'testKeyFailed'        => __('Key test failed.', 'ffl-funnels-addons'),
                'testKeyRequestFailed' => __('Test request failed. Check your network and try again.', 'ffl-funnels-addons'),
            ],
        ]);

        wp_enqueue_style(
            'ffla-tax-rates-admin',
            $base_url . 'css/tax-rates-admin.css',
            [],
            FFLA_VERSION
        );
    }

    public function save_settings(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        check_admin_referer('ffla_tax_resolver_save', 'ffla_tax_nonce');

        $previous_settings = wp_parse_args(get_option(self::SETTINGS_KEY, []), [
            'enabled_states' => [],
        ]);
        $previous_enabled_states = [];
        if (is_array($previous_settings['enabled_states'])) {
            foreach ($previous_settings['enabled_states'] as $state_code) {
                $state_code = strtoupper(sanitize_text_field((string) $state_code));
                if (preg_match('/^[A-Z]{2}$/', $state_code)) {
                    $previous_enabled_states[] = $state_code;
                }
            }
        }

        $previous_enabled_states = array_values(array_unique($previous_enabled_states));
        sort($previous_enabled_states);

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

        $tax_exempt_roles = [];
        if (!empty($_POST['tax_exempt_roles']) && is_array($_POST['tax_exempt_roles'])) {
            $choices = class_exists('Tax_Role_Gate') ? Tax_Role_Gate::get_role_choices() : [];
            foreach (wp_unslash($_POST['tax_exempt_roles']) as $slug) {
                if (!is_scalar($slug)) {
                    continue;
                }
                $slug = sanitize_key((string) $slug);
                if ($slug === '') {
                    continue;
                }
                if (!empty($choices) && !isset($choices[$slug])) {
                    continue;
                }
                $tax_exempt_roles[] = $slug;
            }
        }
        $tax_exempt_roles = array_values(array_unique($tax_exempt_roles));
        sort($tax_exempt_roles);

        $settings = [
            'cache_ttl'       => max(60, (int) ($_POST['cache_ttl'] ?? 86400)),
            'auto_sync'       => isset($_POST['auto_sync']) ? '1' : '0',
            'sync_schedule'   => 'monthly',
            'wc_auto_sync'    => '0',
            'restrict_states' => isset($_POST['restrict_states']) ? '1' : '0',
            'enabled_states'  => $enabled_states,
            'sheet_source_url'=> esc_url_raw(wp_unslash($_POST['sheet_source_url'] ?? Tax_Dataset_Pipeline::DEFAULT_SHEET_URL)),
            'usgeocoder_auth_key' => sanitize_text_field(wp_unslash($_POST['usgeocoder_auth_key'] ?? '')),
            'tax_role_restrict'   => isset($_POST['tax_role_restrict']) ? '1' : '0',
            'tax_exempt_roles'    => $tax_exempt_roles,
        ];

        $removed_states = array_values(array_diff($previous_enabled_states, $enabled_states));
        $purged_states  = 0;

        foreach ($removed_states as $state_code) {
            $purge_result = Tax_Dataset_Pipeline::purge_state_dataset($state_code);
            if ((int) ($purge_result['deleted_versions'] ?? 0) > 0) {
                $purged_states++;
            }
        }

        // Detect setting changes that invalidate cached quotes before the
        // option write (so we know what changed, not just the final state).
        $prev_key      = trim((string) ($previous_settings['usgeocoder_auth_key'] ?? ''));
        $new_key       = trim((string) $settings['usgeocoder_auth_key']);
        $prev_restrict = (string) ($previous_settings['restrict_states'] ?? '0');
        $new_restrict  = (string) $settings['restrict_states'];

        $cache_flushed  = 0;
        $flush_reasons  = [];

        if ($prev_key !== $new_key) {
            $flush_reasons[] = $new_key === ''
                ? 'api_key_removed'
                : ($prev_key === '' ? 'api_key_added' : 'api_key_changed');

            // Any previous per-key validation result is no longer meaningful.
            delete_transient('ffla_tax_key_validation');
        }

        if ($prev_restrict !== $new_restrict || $previous_enabled_states !== $enabled_states) {
            $flush_reasons[] = 'enabled_states_changed';
        }

        $prev_ttl = (int) ($previous_settings['cache_ttl'] ?? 86400);
        $new_ttl  = (int) $settings['cache_ttl'];
        if ($new_ttl < $prev_ttl) {
            $flush_reasons[] = 'cache_ttl_reduced';
        }

        if (!empty($flush_reasons) && class_exists('Tax_Resolver_DB')) {
            $cache_flushed = Tax_Resolver_DB::flush_address_cache();
        }

        update_option(self::SETTINGS_KEY, $settings);
        Tax_Rates_Cron::maybe_schedule();

        wp_safe_redirect(add_query_arg(
            [
                'page'          => 'ffla-tax-rates',
                'tab'           => 'settings',
                'saved'         => '1',
                'purged_states' => (string) $purged_states,
                'cache_flushed' => (string) $cache_flushed,
                'flush_reason'  => !empty($flush_reasons) ? implode(',', $flush_reasons) : '',
            ],
            admin_url('admin.php')
        ));
        exit;
    }

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

    public function ajax_run_sync(): void
    {
        check_ajax_referer('ffla_tax_resolver_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $results = Tax_Dataset_Pipeline::sync(Tax_Dataset_Pipeline::SHEET_SOURCE_CODE);
        $states  = $results['sheet'] ?? [];
        $checked = count($states);
        $updated = 0;
        $skipped = 0;
        $errors  = [];
        $zip_rows = 0;

        foreach ($states as $state_result) {
            if (!empty($state_result['success']) && empty($state_result['skipped'])) {
                $updated++;
            }

            if (!empty($state_result['skipped'])) {
                $skipped++;
            }

            $zip_rows += (int) ($state_result['zip_rows'] ?? 0);

            if (!empty($state_result['error'])) {
                $errors[] = sprintf(
                    '%s: %s',
                    $state_result['state'] ?? '--',
                    $state_result['error']
                );
            }
        }

        wp_send_json_success([
            'message' => sprintf(
                __('Sheet sync checked %1$d states, updated %2$d datasets, skipped %3$d unchanged states, imported %4$d ZIP rows, and found %5$d errors.', 'ffl-funnels-addons'),
                $checked,
                $updated,
                $skipped,
                $zip_rows,
                count($errors)
            ),
            'results' => $results,
            'errors'  => $errors,
        ]);
    }

    public function ajax_purge_legacy_data(): void
    {
        check_ajax_referer('ffla_tax_resolver_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $result = Tax_Resolver_DB::purge_legacy_local_data();

        wp_send_json_success([
            'message' => sprintf(
                __('Legacy local tax data deleted. Datasets: %1$d, rates: %2$d, cache: %3$d, audit: %4$d.', 'ffl-funnels-addons'),
                (int) ($result['dataset_versions_deleted'] ?? 0),
                (int) ($result['jurisdiction_rates_deleted'] ?? 0),
                (int) ($result['address_cache_deleted'] ?? 0),
                (int) ($result['quotes_audit_deleted'] ?? 0)
            ),
            'result' => $result,
        ]);
    }

    /**
     * AJAX "Test key" handler: validates a USGeocoder key against a known-good
     * sample address before the admin commits it to settings. Result is cached
     * for 1 hour in a transient so the settings page can show a persistent
     * status badge without re-hitting the paid API on every reload.
     */
    public function ajax_test_usgeocoder(): void
    {
        check_ajax_referer('ffla_tax_resolver_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $key = isset($_POST['key']) ? trim(sanitize_text_field(wp_unslash((string) $_POST['key']))) : '';

        if ($key === '') {
            wp_send_json_error([
                'status'  => 'empty',
                'message' => __('Enter a USGeocoder key first.', 'ffl-funnels-addons'),
            ]);
        }

        if (!class_exists('USGeocoder_API_Resolver')) {
            wp_send_json_error([
                'status'  => 'boot',
                'message' => __('USGeocoder resolver is not loaded.', 'ffl-funnels-addons'),
            ]);
        }

        // Sample address from the USGeocoder documentation; any valid key
        // should resolve it to a usable tax rate.
        $response = USGeocoder_API_Resolver::fetch_api($key, [
            'address' => '1591 Williamsport Dr',
            'zipcode' => '95131',
        ]);

        $payload = [
            'status'    => 'ok',
            'http_code' => (int) ($response['http_code'] ?? 0),
            'checkedAt' => current_time('mysql'),
            'message'   => __('Key works. Sample lookup succeeded.', 'ffl-funnels-addons'),
        ];

        if (!empty($response['wp_error'])) {
            $payload['status']  = 'network_error';
            $payload['message'] = sprintf(
                /* translators: %s: wp_error message from the HTTP call. */
                __('Network error while contacting USGeocoder: %s', 'ffl-funnels-addons'),
                (string) $response['error']
            );
        } elseif ((int) $response['http_code'] !== 200) {
            $payload['status']  = 'http_error';
            $payload['message'] = sprintf(
                /* translators: %d: HTTP status code returned by USGeocoder. */
                __('USGeocoder returned HTTP %d. The key is likely invalid or inactive.', 'ffl-funnels-addons'),
                (int) $response['http_code']
            );
        } elseif (!is_array($response['payload'] ?? null)) {
            $payload['status']  = 'empty_payload';
            $payload['message'] = __('USGeocoder returned an empty response. Try again in a moment.', 'ffl-funnels-addons');
        } else {
            $rate = USGeocoder_API_Resolver::extract_total_rate($response['payload']);
            if ($rate === null) {
                $payload['status']  = 'no_rate';
                $payload['message'] = __('USGeocoder responded but did not return a usable rate. The key may be disabled.', 'ffl-funnels-addons');
            } else {
                $payload['rate'] = $rate;
            }
        }

        set_transient('ffla_tax_key_validation', $payload, HOUR_IN_SECONDS);

        if ($payload['status'] === 'ok') {
            wp_send_json_success($payload);
        }

        wp_send_json_error($payload);
    }

    /**
     * Render the USGeocoder card with mode badge, explanation, key field,
     * Test-key button and the usage card when the key is in effect.
     */
    private function render_usgeocoder_card(array $settings): void
    {
        $auth_key = trim((string) ($settings['usgeocoder_auth_key'] ?? ''));
        $mode     = $auth_key === '' ? 'sheet' : 'api';
        $test     = get_transient('ffla_tax_key_validation');
        $test     = is_array($test) ? $test : null;

        $badge_class = 'ffla-tax-mode-badge ffla-tax-mode-badge--sheet';
        $badge_label = __('Sheet Mode (free)', 'ffl-funnels-addons');

        if ($mode === 'api') {
            $has_failure = $test && ($test['status'] ?? '') !== 'ok';
            if ($has_failure) {
                $badge_class = 'ffla-tax-mode-badge ffla-tax-mode-badge--warn';
                $badge_label = __('USGeocoder Mode (key invalid)', 'ffl-funnels-addons');
            } else {
                $badge_class = 'ffla-tax-mode-badge ffla-tax-mode-badge--api';
                $badge_label = __('USGeocoder Mode (live API)', 'ffl-funnels-addons');
            }
        }

        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header" style="display:flex;align-items:center;justify-content:space-between;gap:var(--wb-spacing-md);">';
        echo '<h3>' . esc_html__('USGeocoder API', 'ffl-funnels-addons') . '</h3>';
        echo '<span class="' . esc_attr($badge_class) . '">' . esc_html($badge_label) . '</span>';
        echo '</div>';
        echo '<div class="wb-card__body">';

        echo '<p class="wb-field__desc">' . wp_kses(
            __('Leave the key empty to use our shared <strong>Google Sheet dataset</strong> — it is free, refreshed monthly, and covers ZIP-level rates for every state your store sells to. Paste a <strong>USGeocoder auth key</strong> to upgrade to address-level precision using live JSON responses.', 'ffl-funnels-addons'),
            ['strong' => []]
        ) . '</p>';

        echo '<p class="wb-field__desc">' . wp_kses(
            sprintf(
                /* translators: %s: USGeocoder website link */
                __('USGeocoder is a paid service (per-call pricing). You can create an account and manage your key at %s. If the live API ever fails during checkout the resolver automatically falls back to the Sheet dataset so orders keep going through.', 'ffl-funnels-addons'),
                '<a href="https://www.usgeocoder.com/" target="_blank" rel="noopener noreferrer">usgeocoder.com</a>'
            ),
            ['a' => ['href' => [], 'target' => [], 'rel' => []]]
        ) . '</p>';

        FFLA_Admin::render_text_field(
            __('USGeocoder Auth Key', 'ffl-funnels-addons'),
            'usgeocoder_auth_key',
            $auth_key,
            __('32-character authkey from your USGeocoder account. Example: 0e3152f320d173d00885ed2926c90887.', 'ffl-funnels-addons')
        );

        echo '<p class="wb-field__actions" style="margin-top:var(--wb-spacing-sm);display:flex;gap:var(--wb-spacing-sm);align-items:center;flex-wrap:wrap;">';
        echo '<button type="button" class="button button-secondary" id="ffla-tax-test-key" data-test-key>'
            . esc_html__('Test key', 'ffl-funnels-addons') . '</button>';
        echo '<span class="ffla-tax-test-key__status" id="ffla-tax-test-key-status" aria-live="polite"></span>';
        echo '</p>';

        if ($test) {
            $is_ok = ($test['status'] ?? '') === 'ok';
            echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm);">';
            echo '<strong>' . esc_html__('Last test:', 'ffl-funnels-addons') . '</strong> ';
            echo '<span style="color:' . ($is_ok ? '#1a7f37' : '#c6300b') . '">';
            echo esc_html((string) ($test['message'] ?? ''));
            echo '</span>';
            if (!empty($test['checkedAt'])) {
                echo ' <span class="wb-field__hint">(' . esc_html((string) $test['checkedAt']) . ')</span>';
            }
            echo '</p>';
        }

        echo '</div></div>';

        if ($mode === 'api') {
            $this->render_usgeocoder_usage_card();
        }
    }

    /**
     * Render the "API Usage" card with rolling-30d badge and per-month history.
     */
    private function render_usgeocoder_usage_card(): void
    {
        if (!class_exists('Tax_USGeocoder_Usage')) {
            return;
        }

        $last_30  = Tax_USGeocoder_Usage::get_last_30d();
        $history  = Tax_USGeocoder_Usage::get_monthly(6);

        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header" style="display:flex;align-items:center;justify-content:space-between;gap:var(--wb-spacing-md);">';
        echo '<h3>' . esc_html__('API Usage', 'ffl-funnels-addons') . '</h3>';
        echo '<span class="ffla-tax-usage-badge">'
            . esc_html(sprintf(
                /* translators: %s: number of API calls in the last 30 days. */
                _n('Last 30 days: %s call', 'Last 30 days: %s calls', $last_30, 'ffl-funnels-addons'),
                number_format_i18n($last_30)
            ))
            . '</span>';
        echo '</div>';
        echo '<div class="wb-card__body">';

        echo '<p class="wb-field__desc">'
            . esc_html__('Counts every real call against the USGeocoder API. Cached quotes do not count — the local 24-hour address cache absorbs repeats for free.', 'ffl-funnels-addons')
            . '</p>';

        if (empty($history)) {
            echo '<p class="wb-field__desc">' . esc_html__('No calls recorded yet.', 'ffl-funnels-addons') . '</p>';
        } else {
            echo '<ul class="ffla-tax-usage-list" style="margin:0;padding:0;list-style:none;">';
            foreach ($history as $row) {
                $label = (string) ($row['label'] ?? $row['month']);
                $total = (int) ($row['total'] ?? 0);
                $fail  = (int) ($row['failed'] ?? 0);

                echo '<li style="display:flex;justify-content:space-between;gap:var(--wb-spacing-md);padding:var(--wb-spacing-xs) 0;border-bottom:1px solid var(--wb-color-border-subtle,#eee);">';
                echo '<span>' . esc_html($label) . '</span>';
                echo '<span>' . esc_html(number_format_i18n($total)) . ' ' . esc_html(_n('call', 'calls', $total, 'ffl-funnels-addons'));
                if ($fail > 0) {
                    echo ' <span class="wb-field__hint">(' . esc_html(sprintf(
                        /* translators: %s: number of failed API calls. */
                        __('%s failed', 'ffl-funnels-addons'),
                        number_format_i18n($fail)
                    )) . ')</span>';
                }
                echo '</span>';
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</div></div>';
    }

    /**
     * Render the "Tax exemptions by user role" card so admins can opt into
     * role-based tax exemptions (e.g. wholesale stores that tax retail
     * customers by default but exempt B2B accounts).
     *
     * Semantics: checked roles are EXEMPT from tax. Everyone else is taxed
     * exactly like before. When the feature is off, the whole gate is
     * ignored — every customer pays tax just like on a fresh install.
     */
    private function render_role_gate_card(array $settings): void
    {
        if (!class_exists('Tax_Role_Gate')) {
            return;
        }

        $is_active     = (string) ($settings['tax_role_restrict'] ?? '0') === '1';
        $exempt_roles  = is_array($settings['tax_exempt_roles'] ?? null) ? $settings['tax_exempt_roles'] : [];
        $checked_set   = array_flip(array_map('sanitize_key', array_map('strval', $exempt_roles)));
        $role_choices  = Tax_Role_Gate::get_role_choices();

        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header" style="display:flex;align-items:center;justify-content:space-between;gap:var(--wb-spacing-md);">';
        echo '<h3>' . esc_html__('Tax exemptions by user role', 'ffl-funnels-addons') . '</h3>';
        $badge_label = $is_active
            ? __('Exemptions ON', 'ffl-funnels-addons')
            : __('Exemptions OFF', 'ffl-funnels-addons');
        $badge_class = $is_active ? 'ffla-tax-mode-badge--api' : 'ffla-tax-mode-badge--sheet';
        echo '<span class="ffla-tax-mode-badge ' . esc_attr($badge_class) . '">' . esc_html($badge_label) . '</span>';
        echo '</div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Exempt certain user roles from tax', 'ffl-funnels-addons'),
            'tax_role_restrict',
            $is_active ? '1' : '0',
            __('Turn this on to skip tax charges for the user roles checked below. When off, every customer is taxed exactly like before — this is the safe default.', 'ffl-funnels-addons')
        );

        echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm);">'
            . esc_html__('Checked roles will NOT be charged tax. Unchecked roles (and guests, unless you check "Guest") will be taxed normally. Users with multiple roles are exempt as long as any of their roles is checked.', 'ffl-funnels-addons')
            . '</p>';

        echo '<div class="ffla-tax-role-picker" id="ffla-tax-role-picker">';
        foreach ($role_choices as $slug => $label) {
            $checked = isset($checked_set[$slug]) ? ' checked' : '';
            echo '<label class="ffla-tax-role-picker__item">';
            echo '<input type="checkbox" name="tax_exempt_roles[]" value="' . esc_attr($slug) . '"' . $checked . '>';
            echo '<span class="ffla-tax-role-picker__label">' . esc_html($label) . '</span>';
            echo '<code class="ffla-tax-role-picker__slug">' . esc_html($slug) . '</code>';
            echo '</label>';
        }
        echo '</div>';

        if ($is_active && empty($exempt_roles)) {
            echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm);">'
                . esc_html__('No roles are exempt yet — every customer is taxed normally. Check a role above to exempt it from tax collection.', 'ffl-funnels-addons')
                . '</p>';
        }

        echo '</div></div>';
    }

    public function render_settings_page(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = sanitize_key($_GET['tab'] ?? 'lookup');

        if (isset($_GET['saved'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        if (isset($_GET['purged_states']) && (int) $_GET['purged_states'] > 0) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            FFLA_Admin::render_notice(
                'info',
                sprintf(
                    /* translators: %d: number of deselected states whose datasets were deleted */
                    __('Removed local datasets for %d deselected states.', 'ffl-funnels-addons'),
                    (int) $_GET['purged_states'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                )
            );
        }

        if (!empty($_GET['cache_flushed']) && !empty($_GET['flush_reason'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $flushed = (int) $_GET['cache_flushed']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $reasons = array_filter(array_map('sanitize_key', explode(',', (string) $_GET['flush_reason']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $labels  = [
                'api_key_added'          => __('USGeocoder key added', 'ffl-funnels-addons'),
                'api_key_removed'        => __('USGeocoder key removed', 'ffl-funnels-addons'),
                'api_key_changed'        => __('USGeocoder key changed', 'ffl-funnels-addons'),
                'enabled_states_changed' => __('enabled states changed', 'ffl-funnels-addons'),
            ];
            $labels = array_intersect_key($labels, array_flip($reasons));

            FFLA_Admin::render_notice(
                'info',
                sprintf(
                    /* translators: 1: reasons list, 2: number of cached rows removed */
                    __('Address cache flushed (%1$s). %2$d cached quotes removed so new settings apply immediately.', 'ffl-funnels-addons'),
                    !empty($labels) ? implode(', ', $labels) : __('settings changed', 'ffl-funnels-addons'),
                    $flushed
                )
            );
        }

        if (get_option('woocommerce_calc_taxes') !== 'yes') {
            FFLA_Admin::render_notice(
                'warning',
                __('WooCommerce taxes are disabled. Enable them in <strong>WooCommerce -> Settings -> Tax</strong> for resolved rates to apply at checkout.', 'ffl-funnels-addons')
            );
        }

        $tabs = [
            'lookup'    => __('Quote Lookup', 'ffl-funnels-addons'),
            'coverage'  => __('Coverage Matrix', 'ffl-funnels-addons'),
            'datasets'  => __('Datasets', 'ffl-funnels-addons'),
            'audit'     => __('Audit Log', 'ffl-funnels-addons'),
            'settings'  => __('Settings', 'ffl-funnels-addons'),
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
                break;
        }
    }

    private function render_lookup_tab(): void
    {
        $state_filter_active = Tax_Coverage::has_state_filter();
        $enabled_states      = Tax_Coverage::get_enabled_states();

        echo '<div class="ffla-tax-lookup-layout">';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Tax Quote Lookup', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc" style="margin-bottom:var(--wb-spacing-lg)">';
        echo esc_html__('Enter a US address to look up the applicable sales tax rate and jurisdictional breakdown from the local Google Sheet dataset stored in WordPress.', 'ffl-funnels-addons');
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

        echo '<div class="wb-card" id="ffla-tax-result-card" style="display:none">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Quote Result', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body" id="ffla-tax-result-body"></div>';
        echo '</div>';

        echo '</div>';

        FFLA_Admin::render_notice(
            'info',
            __('This tool resolves from the local ZIP dataset imported from your shared Google Sheet. ZIP matches are used first, then city fallback, then the state floor for that state.', 'ffl-funnels-addons')
        );
    }

    private function render_coverage_tab(): void
    {
        $matrix = Tax_Coverage::get_matrix();
        $state_filter_active = Tax_Coverage::has_state_filter();

        $ready = 0;
        $awaiting_sync = 0;
        $issues = 0;
        $unsupported = 0;
        $enabled_for_store = 0;
        $disabled_for_store = 0;

        foreach ($matrix as $row) {
            switch ($row['coverage_status']) {
                case Tax_Coverage::SUPPORTED_ADDRESS_RATE:
                case Tax_Coverage::NO_SALES_TAX:
                    $ready++;
                    break;
                case Tax_Coverage::SUPPORTED_WITH_REMOTE:
                case Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED:
                    $awaiting_sync++;
                    break;
                case Tax_Coverage::DEGRADED:
                    $issues++;
                    break;
                default:
                    $unsupported++;
                    break;
            }

            if (Tax_Coverage::is_enabled_for_store($row['state_code'])) {
                $enabled_for_store++;
            } else {
                $disabled_for_store++;
            }
        }

        echo '<div class="ffla-tax-stats">';
        echo '<div class="ffla-tax-stat ffla-tax-stat--supported"><span class="ffla-tax-stat__value">' . esc_html($ready) . '</span><span class="ffla-tax-stat__label">' . esc_html__('Imported And Ready', 'ffl-funnels-addons') . '</span></div>';
        echo '<div class="ffla-tax-stat ffla-tax-stat--disabled"><span class="ffla-tax-stat__value">' . esc_html($awaiting_sync) . '</span><span class="ffla-tax-stat__label">' . esc_html__('Awaiting Sync', 'ffl-funnels-addons') . '</span></div>';
        echo '<div class="ffla-tax-stat ffla-tax-stat--enabled"><span class="ffla-tax-stat__value">' . esc_html($enabled_for_store) . '</span><span class="ffla-tax-stat__label">' . esc_html__('Enabled For Store', 'ffl-funnels-addons') . '</span></div>';
        echo '<div class="ffla-tax-stat ffla-tax-stat--disabled"><span class="ffla-tax-stat__value">' . esc_html($disabled_for_store) . '</span><span class="ffla-tax-stat__label">' . esc_html__('Disabled For Store', 'ffl-funnels-addons') . '</span></div>';
        echo '<div class="ffla-tax-stat ffla-tax-stat--unsupported"><span class="ffla-tax-stat__value">' . esc_html($issues + $unsupported) . '</span><span class="ffla-tax-stat__label">' . esc_html__('Needs Attention', 'ffl-funnels-addons') . '</span></div>';
        echo '</div>';

        if ($state_filter_active) {
            FFLA_Admin::render_notice(
                'info',
                __('State filtering is active. Cells marked Off are technically supported by the resolver but currently disabled for this store.', 'ffl-funnels-addons')
            );
        }

        FFLA_Admin::render_notice(
            'info',
            __('Source model: every state now resolves from a local dataset imported from the shared Google Sheet. ZIP rows are primary, city rows are fallback, and unmatched locations fall back to the imported state floor.', 'ffl-funnels-addons')
        );

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('State Coverage Matrix', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<div class="ffla-tax-coverage-grid">';

        $names = self::get_state_names();

        foreach ($matrix as $row) {
            $code = $row['state_code'];
            $status = $row['coverage_status'];
            $name = $names[$code] ?? $code;
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
                    break;
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
            if (!empty($row['resolver_name'])) {
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

        echo '<div class="ffla-tax-legend">';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--supported"></span> ' . esc_html__('Imported And Ready', 'ffl-funnels-addons') . '</span>';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--context"></span> ' . esc_html__('Awaiting Sync', 'ffl-funnels-addons') . '</span>';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--degraded"></span> ' . esc_html__('Degraded', 'ffl-funnels-addons') . '</span>';
        echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--unsupported"></span> ' . esc_html__('Not Supported', 'ffl-funnels-addons') . '</span>';
        if ($state_filter_active) {
            echo '<span class="ffla-tax-legend__item"><span class="ffla-tax-legend__dot ffla-tax-legend__dot--disabled"></span> ' . esc_html__('Disabled For Store', 'ffl-funnels-addons') . '</span>';
        }
        echo '</div>';
    }

    private function render_datasets_tab(): void
    {
        global $wpdb;

        $target_states = Tax_Dataset_Pipeline::get_target_sheet_states();
        $sheet_status  = Tax_Dataset_Pipeline::get_source_status();

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Sheet Sync', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__('This module builds its local tax database from the shared Google Sheets CSV source. The sync imports ZIP rows, city fallback rows, and a state floor for each selected state into dataset_versions and jurisdiction_rates.', 'ffl-funnels-addons') . '</p>';
        echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-xs)">' . esc_html(sprintf(
            __('Current CSV source: %s', 'ffl-funnels-addons'),
            (string) ($sheet_status['exportUrl'] ?? Tax_Dataset_Pipeline::get_sheet_export_url())
        )) . '</p>';

        if (Tax_Coverage::has_state_filter()) {
            if (empty($target_states)) {
                echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm);color:var(--wb-color-danger-foreground,#c53030)">' . esc_html__('State filtering is active, but no states are selected yet. Sync will process 0 states until you choose at least one state in Store State Access.', 'ffl-funnels-addons') . '</p>';
            } else {
                echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm)">' . esc_html(sprintf(
                    __('State filtering is active, so the monthly rebuild will refresh only the %d states selected in Store State Access.', 'ffl-funnels-addons'),
                    count($target_states)
                )) . '</p>';
            }
        } else {
            echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm)">' . esc_html__('State filtering is off, so the monthly rebuild will refresh all 50 states plus DC.', 'ffl-funnels-addons') . '</p>';
        }

        echo '<div style="margin-top:var(--wb-spacing-lg)">';
        echo '<button type="button" id="ffla-sync-btn" class="wb-btn wb-btn--primary">' . esc_html__('Sync Sheet Data', 'ffl-funnels-addons') . '</button>';
        echo '</div>';
        echo '<div id="ffla-upload-status" class="ffla-tax-upload-status" style="display:none"></div>';
        echo '</div></div>';

        $table = Tax_Resolver_DB::table('dataset_versions');
        $datasets = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'active' AND source_code = %s
                 ORDER BY state_code ASC, effective_date DESC",
                Tax_Dataset_Pipeline::SHEET_SOURCE_CODE
            ),
            ARRAY_A
        ) ?: [];

        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Active Imported Datasets', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body wb-card__body--table">';

        if (empty($datasets)) {
            echo '<p style="color:var(--wb-color-neutral-foreground-3)">' . esc_html__('No Google Sheet datasets have been imported yet. Run sheet sync to build the local ZIP database before testing checkout.', 'ffl-funnels-addons') . '</p>';
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

            foreach ($datasets as $dataset) {
                $freshness = preg_replace('/[^0-9]/', '', (string) ($dataset['freshness_policy'] ?? '45'));
                $freshness_days = max(1, (int) ($freshness !== '' ? $freshness : '45'));
                $age_days = (int) round((time() - strtotime((string) $dataset['loaded_at'])) / DAY_IN_SECONDS, 0);
                $is_fresh = $age_days <= $freshness_days;

                echo '<tr>';
                echo '<td><strong>' . esc_html($dataset['state_code'] ?: '-') . '</strong></td>';
                echo '<td><strong>' . esc_html($dataset['source_code']) . '</strong></td>';
                echo '<td>' . esc_html($dataset['version_label']) . '</td>';
                echo '<td>' . esc_html($dataset['effective_date']) . '</td>';
                echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime((string) $dataset['loaded_at']))) . '</td>';
                echo '<td>' . esc_html((string) $dataset['row_count']) . '</td>';
                echo '<td>';
                if ($is_fresh) {
                    echo '<span class="wb-status wb-status--active">' . esc_html($age_days . 'd / ' . $freshness_days . 'd') . '</span>';
                } else {
                    echo '<span class="wb-status wb-status--inactive">' . esc_html__('STALE', 'ffl-funnels-addons') . ' (' . esc_html((string) $age_days) . 'd)</span>';
                }
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div></div>';
    }
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
            echo '</tr></thead><tbody>';

            foreach ($rows as $row) {
                $rate_display = $row['total_rate'] !== null
                    ? number_format((float) $row['total_rate'] * 100, 2) . '%'
                    : '-';
                $outcome_class = in_array((string) $row['outcome_code'], ['SUCCESS', 'NO_SALES_TAX'], true)
                    ? 'wb-status--active'
                    : 'wb-status--inactive';

                echo '<tr>';
                echo '<td>' . esc_html(date_i18n('M j, H:i', strtotime((string) $row['requested_at']))) . '</td>';
                echo '<td><strong>' . esc_html((string) ($row['state_code'] ?? '-')) . '</strong></td>';
                echo '<td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' . esc_attr((string) ($row['matched_address'] ?? '')) . '">' . esc_html((string) ($row['matched_address'] ?: '-')) . '</td>';
                echo '<td>' . esc_html($rate_display) . '</td>';
                echo '<td><span class="wb-status ' . esc_attr($outcome_class) . '">' . esc_html((string) $row['outcome_code']) . '</span></td>';
                echo '<td>' . esc_html((string) ($row['source_code'] ?? '-')) . '</td>';
                echo '<td>' . esc_html((string) ($row['duration_ms'] ?? '-')) . 'ms</td>';
                echo '<td>' . (!empty($row['cache_hit']) ? 'Yes' : '-') . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table>';
        }

        echo '</div></div>';
    }

    private function render_settings_tab(): void
    {
        $settings = wp_parse_args(get_option(self::SETTINGS_KEY, []), [
            'cache_ttl'       => 86400,
            'auto_sync'       => '1',
            'sync_schedule'   => 'monthly',
            'wc_auto_sync'    => '0',
            'restrict_states' => '0',
            'enabled_states'  => [],
            'sheet_source_url'=> Tax_Dataset_Pipeline::DEFAULT_SHEET_URL,
            'usgeocoder_auth_key' => '',
        ]);

        $enabled_states = is_array($settings['enabled_states']) ? $settings['enabled_states'] : [];
        $sheet_status = Tax_Dataset_Pipeline::get_source_status();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ffla_tax_resolver_save_settings">';
        wp_nonce_field('ffla_tax_resolver_save', 'ffla_tax_nonce');

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('General Settings', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_select_field(
            __('Cache TTL', 'ffl-funnels-addons'),
            'cache_ttl',
            (string) $settings['cache_ttl'],
            [
                '3600'   => __('1 hour', 'ffl-funnels-addons'),
                '21600'  => __('6 hours', 'ffl-funnels-addons'),
                '86400'  => __('24 hours (recommended)', 'ffl-funnels-addons'),
                '604800' => __('7 days', 'ffl-funnels-addons'),
            ],
            __('How long to cache tax quote results.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Auto sheet sync', 'ffl-funnels-addons'),
            'auto_sync',
            (string) $settings['auto_sync'],
            __('Automatically refresh the local Google Sheet datasets once per month for the states enabled in this store.', 'ffl-funnels-addons')
        );

        echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm)">' . esc_html__('WooCommerce checkout reads taxes from the runtime resolver and local imported datasets. Legacy WooCommerce tax-table sync is no longer part of the normal flow.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';

        $this->render_usgeocoder_card($settings);

        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Google Sheet Source', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_notice(
            !empty($sheet_status['configured']) ? 'success' : 'warning',
            !empty($sheet_status['configured'])
                ? __('Sheet sync is configured and ready to rebuild datasets.', 'ffl-funnels-addons')
                : __('Enter a public Google Sheets URL or direct CSV export URL to enable monthly sync.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Sheet URL', 'ffl-funnels-addons'),
            'sheet_source_url',
            (string) $settings['sheet_source_url'],
            __('Paste either the shared Google Sheets URL or a direct CSV export URL. The plugin will normalize Google Sheets links to their public CSV export form automatically.', 'ffl-funnels-addons')
        );

        echo '<p class="wb-field__desc" style="margin-top:var(--wb-spacing-sm)">' . esc_html(sprintf(
            __('Current CSV export URL: %s', 'ffl-funnels-addons'),
            (string) ($sheet_status['exportUrl'] ?? Tax_Dataset_Pipeline::get_sheet_export_url())
        )) . '</p>';

        echo '</div></div>';

        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Store State Access', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Limit resolver to selected states', 'ffl-funnels-addons'),
            'restrict_states',
            (string) $settings['restrict_states'],
            __('Turn this on if the resolver should only run for states where your business is licensed or registered to operate.', 'ffl-funnels-addons')
        );

        echo '<div class="ffla-tax-state-picker" id="ffla-tax-state-picker">';
        echo '<div class="ffla-tax-state-picker__header">';
        echo '<p class="wb-field__desc">' . esc_html__('Checked states stay active for Quote Lookup, REST quotes, WooCommerce runtime overrides, and the monthly sheet dataset rebuild. Leave the toggle off above to allow every state.', 'ffl-funnels-addons') . '</p>';
        echo '<div class="ffla-tax-state-picker__actions">';
        echo '<button type="button" class="button button-secondary ffla-tax-state-picker__action" data-state-picker-action="select-all">' . esc_html__('Select All', 'ffl-funnels-addons') . '</button>';
        echo '<button type="button" class="button button-secondary ffla-tax-state-picker__action" data-state-picker-action="select-covered">' . esc_html__('Select Covered', 'ffl-funnels-addons') . '</button>';
        echo '<button type="button" class="button button-secondary ffla-tax-state-picker__action" data-state-picker-action="clear-all">' . esc_html__('Clear', 'ffl-funnels-addons') . '</button>';
        echo '</div></div>';
        echo '<div class="ffla-tax-state-picker__grid">';

        foreach (self::get_state_names() as $state_code => $state_name) {
            $coverage   = Tax_Coverage::get_state($state_code);
            $is_covered = self::is_covered_state_status((string) ($coverage['coverage_status'] ?? Tax_Coverage::UNSUPPORTED));
            $checked    = in_array($state_code, $enabled_states, true);
            $item_class = 'ffla-tax-state-picker__item' . ($is_covered ? ' ffla-tax-state-picker__item--covered' : '');

            echo '<label class="' . esc_attr($item_class) . '">';
            echo '<input type="checkbox" name="enabled_states[]" value="' . esc_attr($state_code) . '" class="ffla-tax-state-picker__checkbox" data-covered="' . esc_attr($is_covered ? '1' : '0') . '"' . checked($checked, true, false) . '>';
            echo '<span class="ffla-tax-state-picker__code">' . esc_html($state_code) . '</span>';
            echo '<span class="ffla-tax-state-picker__name">' . esc_html($state_name) . '</span>';
            echo '</label>';
        }

        echo '</div></div></div></div>';

        $this->render_role_gate_card($settings);

        echo '<div style="padding-top:var(--wb-spacing-lg);padding-bottom:var(--wb-spacing-xl)">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';

        echo '<div class="wb-card" style="margin-top:var(--wb-spacing-xl)">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Legacy Data Cleanup', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__('Use this only after migrating to USGeocoder. It deletes old local tax datasets, cached quotes, and audit rows created by the legacy local dataset workflow.', 'ffl-funnels-addons') . '</p>';
        echo '<button type="button" id="ffla-purge-legacy-btn" class="wb-btn wb-btn--danger">' . esc_html__('Delete Old Tax Database', 'ffl-funnels-addons') . '</button>';
        echo '<div id="ffla-purge-legacy-status" class="ffla-tax-upload-status" style="display:none;margin-top:var(--wb-spacing-sm)"></div>';
        echo '</div></div>';
    }

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
            'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
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
