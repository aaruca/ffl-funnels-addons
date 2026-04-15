<?php
/**
 * WSS Admin — Settings page, dashboard, and AJAX handlers.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Admin
{
    /**
     * Register hooks.
     */
    public function init(): void
    {
        add_action('admin_init', [$this, 'handle_settings_save']);
        add_action('admin_init', [$this, 'handle_oauth_callback']);

        add_action('wp_ajax_wss_manual_sync', [$this, 'ajax_manual_sync']);
        add_action('wp_ajax_wss_clear_log', [$this, 'ajax_clear_log']);
        add_action('wp_ajax_wss_disconnect', [$this, 'ajax_disconnect']);
        add_action('wp_ajax_wss_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_wss_resolve_product_names', [$this, 'ajax_resolve_product_names']);
        add_action('wp_ajax_wss_save_sync_products', [$this, 'ajax_save_sync_products']);
        add_action('wp_ajax_wss_link_by_taxonomy', [$this, 'ajax_link_by_taxonomy']);
        add_action('wp_ajax_wss_sync_groups', [$this, 'ajax_sync_groups']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_wss_dashboard'], 100);
    }

    /**
     * Localize sync groups for the WSS Dashboard JS (after module script is registered).
     */
    public function enqueue_wss_dashboard(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== 'ffla-wss-dashboard') {
            return;
        }

        if (!class_exists('WSS_Sync_Groups')) {
            return;
        }

        WSS_Sync_Groups::ensure_migrated();

        wp_localize_script('woo-sheets-sync-module', 'wssDashboard', [
            'groups' => WSS_Sync_Groups::get_groups(),
        ]);
    }

    // ──────────────────────────────────────────────────
    // Settings Page
    // ──────────────────────────────────────────────────

    /**
     * Render the Settings page.
     */
    public function render_settings_page(): void
    {
        $oauth    = new WSS_Google_OAuth();
        $settings = get_option('wss_settings', []);
        ?>

        <?php
        // Show success/error from OAuth redirect or settings save.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $oauth_result = sanitize_key($_GET['wss_oauth_result'] ?? '');
        if ($oauth_result === 'success') {
            FFLA_Admin::render_notice('success', __('Google account connected successfully.', 'ffl-funnels-addons'));
        } elseif ($oauth_result === 'error') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $error_msg = sanitize_text_field(wp_unslash($_GET['wss_oauth_error'] ?? ''));
            FFLA_Admin::render_notice('danger', $error_msg ?: __('OAuth connection failed.', 'ffl-funnels-addons'));
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['wss_saved'])) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }
        ?>

        <!-- Google Account Connection -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Google Account Connection', 'ffl-funnels-addons'); ?></h2>
            </div>
            <div class="wb-card__body">
                <?php if ($oauth->is_connected()): ?>
                    <div class="wss-oauth-status wss-oauth-status--connected">
                        <span class="wss-oauth-status__icon">&#x2705;</span>
                        <span>
                            <?php
                            printf(
                                /* translators: %s: Google account email */
                                esc_html__('Connected as %s', 'ffl-funnels-addons'),
                                '<strong>' . esc_html($oauth->get_user_email()) . '</strong>'
                            );
                            ?>
                        </span>
                        <button type="button" class="wb-btn wb-btn--sm wb-btn--danger" id="wss-disconnect-btn">
                            <?php esc_html_e('Disconnect', 'ffl-funnels-addons'); ?>
                        </button>
                    </div>
                <?php else: ?>
                    <a href="<?php echo esc_url($oauth->get_auth_url()); ?>" class="wb-btn wb-btn--primary">
                        <?php esc_html_e('Connect with Google', 'ffl-funnels-addons'); ?>
                    </a>
                    <p class="wb-field__desc" style="margin-top: var(--wb-spacing-sm);">
                        <?php esc_html_e('You will be redirected to Google to authorize access to your spreadsheets.', 'ffl-funnels-addons'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sheet Configuration -->
        <form method="post" action="">
            <?php wp_nonce_field('wss_save_settings', 'wss_settings_nonce'); ?>

            <div class="wb-card">
                <div class="wb-card__header">
                    <h2><?php esc_html_e('Sheet Configuration', 'ffl-funnels-addons'); ?></h2>
                </div>
                <div class="wb-card__body">
                    <?php
                    FFLA_Admin::render_text_field(
                        __('Google Sheet URL or ID', 'ffl-funnels-addons'),
                        'wss_sheet_id',
                        $settings['sheet_id'] ?? '',
                        __('Paste the full Google Sheet link (or just the ID). Example: https://docs.google.com/spreadsheets/d/.../edit', 'ffl-funnels-addons')
                    );
                    ?>
                    <p class="wb-field__desc">
                        <?php esc_html_e('Sheet tab names are managed in WSS Dashboard → Sheet tab groups.', 'ffl-funnels-addons'); ?>
                    </p>
                </div>
                <div class="wb-card__footer" style="display:flex;justify-content:flex-end;align-items:center;">
                    <button type="submit" name="wss_save" class="wb-btn wb-btn--primary">
                        <?php esc_html_e('Save Settings', 'ffl-funnels-addons'); ?>
                    </button>
                </div>
            </div>
        </form>
        <?php
    }

    /**
     * Extract the Sheet ID from a full Google Sheets URL or return as-is if already an ID.
     */
    private static function extract_sheet_id(string $input): string
    {
        $input = trim($input);

        if (preg_match('#/spreadsheets/d/([a-zA-Z0-9_-]+)#', $input, $m)) {
            return $m[1];
        }

        return $input;
    }

    /**
     * Handle settings form POST.
     */
    public function handle_settings_save(): void
    {
        if (!isset($_POST['wss_save'])) {
            return;
        }

        if (!check_admin_referer('wss_save_settings', 'wss_settings_nonce')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $existing = get_option('wss_settings', []);

        $raw_sheet = sanitize_text_field(wp_unslash($_POST['wss_sheet_id'] ?? ''));
        $sheet_id  = self::extract_sheet_id($raw_sheet);

        $settings = array_merge($existing, [
            'sheet_id'  => $sheet_id,
        ]);

        update_option('wss_settings', $settings);

        // Redirect to prevent resubmission.
        wp_safe_redirect(add_query_arg('wss_saved', '1', wp_get_referer() ?: admin_url('admin.php?page=ffla-wss-settings')));
        exit;
    }

    /**
     * Handle the Google OAuth callback.
     */
    public function handle_oauth_callback(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['wss_oauth']) || $_GET['wss_oauth'] !== 'callback') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = sanitize_key($_GET['page'] ?? '');
        if ($page !== 'ffla-wss-settings') {
            return;
        }

        // Debug: log all GET params for this callback.
        self::debug_log('OAuth callback triggered. GET params: ' . wp_json_encode(array_map('sanitize_text_field', wp_unslash($_GET))));

        // Check for proxy error redirect.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['wss_oauth_result']) && $_GET['wss_oauth_result'] === 'error') {
            self::debug_log('Proxy returned error: ' . sanitize_text_field(wp_unslash($_GET['wss_oauth_error'] ?? '')));
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $payload = isset($_GET['wss_proxy_payload']) ? wp_unslash($_GET['wss_proxy_payload']) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $state   = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));

        self::debug_log('Payload length: ' . strlen($payload) . ' | State: ' . $state);
        self::debug_log('Stored transient state: ' . (get_transient('wss_oauth_state') ?: '(empty/expired)'));

        if (empty($payload) || empty($state)) {
            self::debug_log('ERROR: Missing payload or state');
            wp_safe_redirect(add_query_arg([
                'page'             => 'ffla-wss-settings',
                'wss_oauth_result' => 'error',
                'wss_oauth_error'  => urlencode(__('Missing proxy payload.', 'ffl-funnels-addons')),
            ], admin_url('admin.php')));
            exit;
        }

        $oauth  = new WSS_Google_OAuth();
        $result = $oauth->handle_proxy_callback($payload, $state);

        if (is_wp_error($result)) {
            self::debug_log('ERROR from handle_proxy_callback: ' . $result->get_error_message());
            wp_safe_redirect(add_query_arg([
                'page'             => 'ffla-wss-settings',
                'wss_oauth_result' => 'error',
                'wss_oauth_error'  => urlencode($result->get_error_message()),
            ], admin_url('admin.php')));
            exit;
        }

        self::debug_log('SUCCESS — tokens stored, redirecting to settings page.');
        wp_safe_redirect(add_query_arg([
            'page'             => 'ffla-wss-settings',
            'wss_oauth_result' => 'success',
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Write a debug log entry to the WordPress debug log and a dedicated WSS log file.
     */
    private static function debug_log(string $message): void
    {
        $line = '[WSS OAuth ' . gmdate('Y-m-d H:i:s') . '] ' . $message;

        // WordPress debug.log (if WP_DEBUG_LOG is enabled).
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($line);
        }

        // Dedicated log file in uploads dir.
        $upload_dir = wp_upload_dir();
        $log_file   = $upload_dir['basedir'] . '/wss-oauth-debug.log';
        file_put_contents($log_file, $line . "\n", FILE_APPEND | LOCK_EX); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }

    // ──────────────────────────────────────────────────
    // Dashboard Page
    // ──────────────────────────────────────────────────

    /**
     * Render the Dashboard page.
     */
    public function render_dashboard_page(): void
    {
        if (class_exists('WSS_Sync_Groups')) {
            WSS_Sync_Groups::ensure_migrated();
        }

        $last_sync = get_option('wss_last_sync', []);
        $logger    = new WSS_Logger();

        $sync_groups = class_exists('WSS_Sync_Groups') ? WSS_Sync_Groups::get_groups() : [];
        $union_count = class_exists('WSS_Sync_Groups')
            ? count(WSS_Sync_Groups::resolve_all_linked_parent_ids($sync_groups))
            : 0;

        // Get categories and tags for bulk linking.
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
        $tags       = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => true]);
        ?>

        <!-- Sync Status -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Sync Status', 'ffl-funnels-addons'); ?></h2>
                <button type="button" class="wb-btn wb-btn--primary wb-btn--sm" id="wss-sync-now-btn">
                    <?php esc_html_e('Sync Now', 'ffl-funnels-addons'); ?>
                </button>
            </div>
            <div class="wb-card__body">
                <div id="wss-sync-result"></div>
                <?php if (!empty($last_sync['time'])): ?>
                    <p>
                        <?php
                        printf(
                            esc_html__('Last sync: %s', 'ffl-funnels-addons'),
                            '<strong>' . esc_html($last_sync['time']) . '</strong>'
                        );
                        ?>
                    </p>
                    <?php if (!empty($last_sync['error'])): ?>
                        <?php FFLA_Admin::render_notice('danger', $last_sync['error']); ?>
                    <?php else: ?>
                        <?php if (!empty($last_sync['woo_to_sheet'])): ?>
                            <p class="wss-stats">
                                <strong><?php esc_html_e('Woo → Sheet:', 'ffl-funnels-addons'); ?></strong>
                                <?php
                                $s = $last_sync['woo_to_sheet'];
                                printf(
                                    esc_html__('%1$d updated, %2$d appended, %3$d skipped, %4$d errors', 'ffl-funnels-addons'),
                                    (int) ($s['updated'] ?? 0),
                                    (int) ($s['appended'] ?? 0),
                                    (int) ($s['skipped'] ?? 0),
                                    (int) ($s['errors'] ?? 0)
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($last_sync['sheet_to_woo'])): ?>
                            <p class="wss-stats">
                                <strong><?php esc_html_e('Sheet → Woo:', 'ffl-funnels-addons'); ?></strong>
                                <?php
                                $s = $last_sync['sheet_to_woo'];
                                printf(
                                    esc_html__('%1$d updated, %2$d skipped, %3$d errors', 'ffl-funnels-addons'),
                                    (int) ($s['updated'] ?? 0),
                                    (int) ($s['skipped'] ?? 0),
                                    (int) ($s['errors'] ?? 0)
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                        <?php if (!empty($last_sync['groups']) && is_array($last_sync['groups'])): ?>
                            <details class="wss-last-sync-groups" style="margin-top:var(--wb-spacing-md)">
                                <summary><?php esc_html_e('Per-tab results', 'ffl-funnels-addons'); ?></summary>
                                <ul class="wss-last-sync-groups__list">
                                    <?php foreach ($last_sync['groups'] as $gr): ?>
                                        <li>
                                            <strong><?php echo esc_html((string) ($gr['tab_name'] ?? '')); ?></strong>
                                            <?php if (!empty($gr['error'])): ?>
                                                — <span class="wss-status--error"><?php echo esc_html((string) $gr['error']); ?></span>
                                            <?php else: ?>
                                                <?php
                                                $w = $gr['woo_to_sheet'] ?? [];
                                                $s = $gr['sheet_to_woo'] ?? [];
                                                ?>
                                                — <?php esc_html_e('Woo→Sheet', 'ffl-funnels-addons'); ?>:
                                                <?php echo (int) ($w['updated'] ?? 0); ?>/<?php echo (int) ($w['appended'] ?? 0); ?>/<?php echo (int) ($w['skipped'] ?? 0); ?>
                                                · <?php esc_html_e('Sheet→Woo', 'ffl-funnels-addons'); ?>:
                                                <?php echo (int) ($s['updated'] ?? 0); ?>/<?php echo (int) ($s['skipped'] ?? 0); ?>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    <p><?php esc_html_e('No sync has been run yet.', 'ffl-funnels-addons'); ?></p>
                <?php endif; ?>

                <?php
                $next = wp_next_scheduled('wss_daily_sync');
                if ($next) {
                    printf(
                        '<p class="wss-next-run">' . esc_html__('Next scheduled sync: %s', 'ffl-funnels-addons') . '</p>',
                        esc_html(wp_date('Y-m-d H:i:s', $next))
                    );
                }
                ?>
            </div>
        </div>

        <!-- Sheet tab groups -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Sheet tab groups', 'ffl-funnels-addons'); ?></h2>
                <span class="wss-product-count" id="wss-product-count">
                    <?php printf(esc_html__('%d products in all tabs', 'ffl-funnels-addons'), (int) $union_count); ?>
                </span>
            </div>
            <div class="wb-card__body">
                <p class="wb-field__desc" style="margin-bottom:var(--wb-spacing-md)">
                    <?php esc_html_e('Each group maps WooCommerce products to one Google Sheet tab. The same product can appear in multiple tabs. Sync runs each tab in order; if the same variation exists in more than one tab, Sheet→Woo uses the last tab in this list as the winner.', 'ffl-funnels-addons'); ?>
                </p>
                <button type="button" class="wb-btn wb-btn--secondary" id="wss-add-tab-group">
                    <?php esc_html_e('Add sheet tab', 'ffl-funnels-addons'); ?>
                </button>
            </div>
        </div>

        <div id="wss-groups-root">
            <?php foreach ($sync_groups as $group): ?>
                <?php
                $gid   = isset($group['id']) ? (string) $group['id'] : '';
                $gesc  = esc_attr($gid);
                $pids  = $group['product_ids'] ?? [];
                $cids  = $group['category_ids'] ?? [];
                $tids  = $group['tag_ids'] ?? [];
                ?>
                <div class="wb-card wss-sync-group" style="margin-top:var(--wb-spacing-lg)" data-group-id="<?php echo $gesc; ?>">
                    <div class="wb-card__header" style="display:flex;align-items:center;justify-content:space-between;gap:var(--wb-spacing-md);flex-wrap:wrap">
                        <h3 class="wss-sync-group__title"><?php esc_html_e('Sheet tab', 'ffl-funnels-addons'); ?></h3>
                        <button type="button" class="wb-btn wb-btn--sm wb-btn--danger wss-remove-group-btn"<?php echo count($sync_groups) <= 1 ? ' disabled' : ''; ?>>
                            <?php esc_html_e('Remove tab group', 'ffl-funnels-addons'); ?>
                        </button>
                    </div>
                    <div class="wb-card__body">
                        <div class="wb-field" style="margin-bottom:var(--wb-spacing-md)">
                            <label class="wb-field__label"><?php esc_html_e('Tab name (Google Sheet)', 'ffl-funnels-addons'); ?></label>
                            <div class="wb-field__control">
                                <input type="text" class="wb-input wss-group-tab-name" value="<?php echo esc_attr((string) ($group['tab_name'] ?? 'Inventory')); ?>" maxlength="99">
                            </div>
                        </div>

                        <div class="wss-bulk-link">
                            <div class="wss-bulk-link__row">
                                <label class="wb-field__label"><?php esc_html_e('Link all products to this tab', 'ffl-funnels-addons'); ?></label>
                                <button type="button" class="wb-btn wb-btn--sm wb-btn--secondary wss-group-link-all"><?php esc_html_e('Link all', 'ffl-funnels-addons'); ?></button>
                                <button type="button" class="wb-btn wb-btn--sm wb-btn--danger wss-group-unlink-all"><?php esc_html_e('Clear tab rules', 'ffl-funnels-addons'); ?></button>
                            </div>

                            <?php if (!empty($categories) && !is_wp_error($categories)): ?>
                            <div class="wss-bulk-link__row">
                                <label class="wb-field__label"><?php esc_html_e('Add by category', 'ffl-funnels-addons'); ?></label>
                                <select class="wss-group-category-select wb-input">
                                    <option value=""><?php esc_html_e('Select a category...', 'ffl-funnels-addons'); ?></option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo esc_attr($cat->term_id); ?>"><?php echo esc_html($cat->name); ?> (<?php echo esc_html((string) $cat->count); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="wb-btn wb-btn--sm wb-btn--secondary wss-group-add-category"><?php esc_html_e('Add', 'ffl-funnels-addons'); ?></button>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($tags) && !is_wp_error($tags)): ?>
                            <div class="wss-bulk-link__row">
                                <label class="wb-field__label"><?php esc_html_e('Add by tag', 'ffl-funnels-addons'); ?></label>
                                <select class="wss-group-tag-select wb-input">
                                    <option value=""><?php esc_html_e('Select a tag...', 'ffl-funnels-addons'); ?></option>
                                    <?php foreach ($tags as $tag): ?>
                                        <option value="<?php echo esc_attr($tag->term_id); ?>"><?php echo esc_html($tag->name); ?> (<?php echo esc_html((string) $tag->count); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="wb-btn wb-btn--sm wb-btn--secondary wss-group-add-tag"><?php esc_html_e('Add', 'ffl-funnels-addons'); ?></button>
                            </div>
                            <?php endif; ?>
                        </div>

                        <p class="wb-field__label" style="margin-top:var(--wb-spacing-md)"><?php esc_html_e('Rules (categories & tags)', 'ffl-funnels-addons'); ?></p>
                        <div class="wss-group-tax-chips">
                            <?php foreach ($cids as $cid): ?>
                                <?php $term = get_term((int) $cid, 'product_cat'); ?>
                                <?php if ($term && !is_wp_error($term)): ?>
                                    <span class="wss-chip wss-chip--taxonomy" data-term-id="<?php echo esc_attr((string) $cid); ?>" data-taxonomy="product_cat">
                                        <?php echo esc_html(sprintf(/* translators: %s: category name */ __('Cat: %s', 'ffl-funnels-addons'), $term->name)); ?>
                                        <button type="button" class="wss-chip__remove" aria-label="<?php esc_attr_e('Remove', 'ffl-funnels-addons'); ?>">&times;</button>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php foreach ($tids as $tid): ?>
                                <?php $term = get_term((int) $tid, 'product_tag'); ?>
                                <?php if ($term && !is_wp_error($term)): ?>
                                    <span class="wss-chip wss-chip--taxonomy" data-term-id="<?php echo esc_attr((string) $tid); ?>" data-taxonomy="product_tag">
                                        <?php echo esc_html(sprintf(/* translators: %s: tag name */ __('Tag: %s', 'ffl-funnels-addons'), $term->name)); ?>
                                        <button type="button" class="wss-chip__remove" aria-label="<?php esc_attr_e('Remove', 'ffl-funnels-addons'); ?>">&times;</button>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <hr class="wss-bulk-link__separator">

                        <p class="wb-field__label"><?php esc_html_e('Individual products', 'ffl-funnels-addons'); ?></p>
                        <div class="wss-product-search">
                            <input type="text" class="wb-product-search__input" placeholder="<?php esc_attr_e('Search products by name…', 'ffl-funnels-addons'); ?>" autocomplete="off">
                            <div class="wb-autocomplete__dropdown"></div>
                        </div>

                        <div class="wss-linked-products wss-group-product-chips">
                            <?php foreach ($pids as $pid): ?>
                                <?php $product = wc_get_product((int) $pid); ?>
                                <?php if ($product): ?>
                                    <span class="wss-chip" data-id="<?php echo esc_attr((string) $pid); ?>">
                                        <?php echo esc_html($product->get_name()); ?>
                                        <button type="button" class="wss-chip__remove">&times;</button>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (empty($pids) && empty($cids) && empty($tids)): ?>
                                <p class="wss-empty-state wss-group-empty"><?php esc_html_e('No products in this tab yet. Add categories, tags, or search for a product.', 'ffl-funnels-addons'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Sync Log -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Sync Log', 'ffl-funnels-addons'); ?></h2>
                <button type="button" class="wb-btn wb-btn--sm wb-btn--danger" id="wss-clear-log-btn">
                    <?php esc_html_e('Clear Log', 'ffl-funnels-addons'); ?>
                </button>
            </div>
            <div class="wb-card__body" id="wss-log-container">
                <?php $this->render_log_table($logger->get_recent(50)); ?>
            </div>
        </div>

        <?php
    }

    /**
     * Render the synced products table.
     */
    private function render_synced_products_table(): void
    {
        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'meta_key'       => '_wss_sync_enabled',
            'meta_value'     => '1',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);

        if (empty($product_ids)) {
            echo '<p>' . esc_html__('No products are marked for sync. Enable sync from the product edit screen.', 'ffl-funnels-addons') . '</p>';
            return;
        }

        echo '<table class="wb-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Product', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Variations', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Last Synced', 'ffl-funnels-addons') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }

            $variation_count = $product->is_type('variable') ? count($product->get_children()) : 1;
            $last_synced     = get_post_meta($pid, '_wss_last_synced', true);

            echo '<tr>';
            echo '<td>' . esc_html($product->get_name()) . '</td>';
            echo '<td>' . esc_html($variation_count) . '</td>';
            echo '<td>' . ($last_synced ? esc_html(wp_date('Y-m-d H:i', strtotime($last_synced))) : '&mdash;') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render the log table.
     */
    private function render_log_table(array $entries): void
    {
        if (empty($entries)) {
            echo '<p>' . esc_html__('No log entries yet.', 'ffl-funnels-addons') . '</p>';
            return;
        }

        echo '<table class="wb-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Time', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Direction', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Product', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Variation', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Status', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Message', 'ffl-funnels-addons') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($entries as $entry) {
            $status_class = 'wss-status--' . esc_attr($entry['status']);
            $direction_label = $entry['direction'] === 'woo_to_sheet' ? 'Woo → Sheet' : 'Sheet → Woo';

            echo '<tr>';
            echo '<td>' . esc_html(wp_date('Y-m-d H:i:s', strtotime($entry['created_at']))) . '</td>';
            echo '<td>' . esc_html($direction_label) . '</td>';
            echo '<td>' . esc_html($entry['product_id']) . '</td>';
            echo '<td>' . esc_html($entry['variation_id']) . '</td>';
            echo '<td><span class="wss-status-badge ' . $status_class . '">' . esc_html($entry['status']) . '</span></td>';
            echo '<td>' . esc_html($entry['message']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // ──────────────────────────────────────────────────
    // AJAX Handlers
    // ──────────────────────────────────────────────────

    /**
     * AJAX: Run manual sync.
     */
    public function ajax_manual_sync(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $settings = get_option('wss_settings', []);

        if (empty($settings['sheet_id'])) {
            wp_send_json_error(['message' => __('No Google Sheet ID configured.', 'ffl-funnels-addons')]);
        }

        $oauth  = new WSS_Google_OAuth();
        if (!$oauth->is_connected()) {
            wp_send_json_error(['message' => __('Not connected to Google. Please authorize first.', 'ffl-funnels-addons')]);
        }

        $sheets = new WSS_Google_Sheets($oauth);
        $logger = new WSS_Logger();

        if (!class_exists('WSS_Sync_Orchestrator')) {
            wp_send_json_error(['message' => __('Sync orchestrator not available.', 'ffl-funnels-addons')]);
        }

        $result = WSS_Sync_Orchestrator::run_all($sheets, $logger);

        if (isset($result['error'])) {
            wp_send_json_error(['message' => $result['error']]);
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: CRUD for sheet tab sync groups.
     */
    public function ajax_sync_groups(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        if (!class_exists('WSS_Sync_Groups')) {
            wp_send_json_error(['message' => __('Sync groups not available.', 'ffl-funnels-addons')]);
        }

        WSS_Sync_Groups::ensure_migrated();

        $op = sanitize_key($_POST['op'] ?? '');

        if ($op === 'get') {
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        $groups = WSS_Sync_Groups::get_groups();
        $gid    = isset($_POST['group_id']) ? sanitize_text_field(wp_unslash($_POST['group_id'])) : '';

        if ($op === 'add_group') {
            $n         = count($groups) + 1;
            $default_tab = sprintf(
                /* translators: %d: tab group index (1-based) */
                __('Sheet tab %d', 'ffl-funnels-addons'),
                $n
            );

            $groups[] = [
                'id'            => wp_generate_uuid4(),
                'tab_name'      => $default_tab,
                'product_ids'   => [],
                'category_ids'  => [],
                'tag_ids'       => [],
            ];
            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        if ($op === 'remove_group') {
            if ($gid === '' || count($groups) <= 1) {
                wp_send_json_error(['message' => __('You must keep at least one sheet tab group.', 'ffl-funnels-addons')]);
            }

            $removed_tab = '';
            foreach ($groups as $g) {
                if (($g['id'] ?? '') === $gid) {
                    $removed_tab = (string) ($g['tab_name'] ?? '');
                    break;
                }
            }

            $groups = array_values(array_filter($groups, static function ($g) use ($gid) {
                return ($g['id'] ?? '') !== $gid;
            }));

            WSS_Sync_Groups::save_groups($groups);

            $delete_warning = '';
            $settings       = get_option('wss_settings', []);
            if (
                $removed_tab !== ''
                && !empty($settings['sheet_id'])
                && class_exists('WSS_Google_OAuth')
                && class_exists('WSS_Google_Sheets')
            ) {
                $oauth = new WSS_Google_OAuth();
                if ($oauth->is_connected()) {
                    $sheets = new WSS_Google_Sheets($oauth);
                    $deleted = $sheets->delete_tab_if_exists((string) $settings['sheet_id'], $removed_tab);
                    if (is_wp_error($deleted)) {
                        $delete_warning = $deleted->get_error_message();
                    }
                }
            }

            wp_send_json_success([
                'groups'  => WSS_Sync_Groups::get_groups(),
                'warning' => $delete_warning,
            ]);
        }

        $idx = $this->wss_find_group_index($groups, $gid);
        if ($idx < 0) {
            wp_send_json_error(['message' => __('Group not found.', 'ffl-funnels-addons')]);
        }

        if ($op === 'set_tab') {
            $tab = isset($_POST['tab_name']) ? trim(wp_strip_all_tags(wp_unslash($_POST['tab_name']))) : '';
            $tab = preg_replace('/[\[\]\*\/\\\?\:]/', '', $tab) ?? '';
            $tab = trim((string) $tab);
            if ($tab === '') {
                wp_send_json_error(['message' => __('Tab name is required.', 'ffl-funnels-addons')]);
            }

            $groups[$idx]['tab_name'] = $tab;
            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        if ($op === 'add_product') {
            $pid = absint($_POST['product_id'] ?? 0);
            if ($pid <= 0) {
                wp_send_json_error(['message' => __('Invalid product.', 'ffl-funnels-addons')]);
            }

            if (!in_array($pid, $groups[$idx]['product_ids'], true)) {
                $groups[$idx]['product_ids'][] = $pid;
            }

            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        if ($op === 'remove_product') {
            $pid = absint($_POST['product_id'] ?? 0);
            $groups[$idx]['product_ids'] = array_values(array_filter(
                $groups[$idx]['product_ids'],
                static function ($v) use ($pid) {
                    return (int) $v !== $pid;
                }
            ));

            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        if ($op === 'link_category') {
            $term_id = absint($_POST['term_id'] ?? 0);
            if ($term_id <= 0) {
                wp_send_json_error(['message' => __('Invalid category.', 'ffl-funnels-addons')]);
            }

            if (!in_array($term_id, $groups[$idx]['category_ids'], true)) {
                $groups[$idx]['category_ids'][] = $term_id;
            }

            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        if ($op === 'unlink_category') {
            $term_id = absint($_POST['term_id'] ?? 0);
            $groups[$idx]['category_ids'] = array_values(array_filter(
                $groups[$idx]['category_ids'],
                static function ($v) use ($term_id) {
                    return (int) $v !== $term_id;
                }
            ));

            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        if ($op === 'link_tag') {
            $term_id = absint($_POST['term_id'] ?? 0);
            if ($term_id <= 0) {
                wp_send_json_error(['message' => __('Invalid tag.', 'ffl-funnels-addons')]);
            }

            if (!in_array($term_id, $groups[$idx]['tag_ids'], true)) {
                $groups[$idx]['tag_ids'][] = $term_id;
            }

            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        if ($op === 'unlink_tag') {
            $term_id = absint($_POST['term_id'] ?? 0);
            $groups[$idx]['tag_ids'] = array_values(array_filter(
                $groups[$idx]['tag_ids'],
                static function ($v) use ($term_id) {
                    return (int) $v !== $term_id;
                }
            ));

            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        if ($op === 'link_all') {
            $all = get_posts([
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            $groups[$idx]['product_ids']   = array_map('intval', $all ?: []);
            $groups[$idx]['category_ids'] = [];
            $groups[$idx]['tag_ids']      = [];

            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        if ($op === 'unlink_all') {
            $groups[$idx]['product_ids']   = [];
            $groups[$idx]['category_ids'] = [];
            $groups[$idx]['tag_ids']      = [];

            WSS_Sync_Groups::save_groups($groups);
            wp_send_json_success(['groups' => WSS_Sync_Groups::get_groups()]);
        }

        wp_send_json_error(['message' => __('Invalid operation.', 'ffl-funnels-addons')]);
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     */
    private function wss_find_group_index(array $groups, string $id): int
    {
        foreach ($groups as $i => $g) {
            if (($g['id'] ?? '') === $id) {
                return (int) $i;
            }
        }

        return -1;
    }

    /**
     * AJAX: Clear log.
     */
    public function ajax_clear_log(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $logger = new WSS_Logger();
        $logger->clear();

        wp_send_json_success(['message' => __('Log cleared.', 'ffl-funnels-addons')]);
    }

    /**
     * AJAX: Disconnect Google account.
     */
    public function ajax_disconnect(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $oauth = new WSS_Google_OAuth();
        $oauth->revoke();

        wp_send_json_success(['message' => __('Disconnected from Google.', 'ffl-funnels-addons')]);
    }

    /**
     * AJAX: Search products for the autocomplete selector.
     */
    public function ajax_search_products(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';

        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            's'              => $search,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $results = [];
        foreach ($query->posts as $pid) {
            $product = wc_get_product($pid);
            if ($product) {
                $results[] = [
                    'id'   => $pid,
                    'name' => $product->get_name(),
                ];
            }
        }

        wp_send_json_success(['products' => $results]);
    }

    /**
     * AJAX: Resolve product names from IDs (for loading existing chips).
     */
    public function ajax_resolve_product_names(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $ids_raw = isset($_POST['ids']) ? sanitize_text_field(wp_unslash($_POST['ids'])) : '';
        $ids     = array_filter(array_map('absint', explode(',', $ids_raw)));

        $names = [];
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            $names[$id] = $product ? $product->get_name() : '#' . $id;
        }

        wp_send_json_success(['names' => $names]);
    }

    /**
     * AJAX: Save synced product IDs (add/remove individual products).
     */
    public function ajax_save_sync_products(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $action_type = sanitize_key($_POST['sync_action'] ?? '');
        $product_id  = absint($_POST['product_id'] ?? 0);

        if ($action_type === 'add' && $product_id) {
            update_post_meta($product_id, '_wss_sync_enabled', '1');
            wp_send_json_success(['message' => __('Product linked.', 'ffl-funnels-addons')]);
        } elseif ($action_type === 'remove' && $product_id) {
            delete_post_meta($product_id, '_wss_sync_enabled');
            wp_send_json_success(['message' => __('Product unlinked.', 'ffl-funnels-addons')]);
        } elseif ($action_type === 'link_all') {
            $all = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
            foreach ($all as $pid) {
                update_post_meta($pid, '_wss_sync_enabled', '1');
            }
            wp_send_json_success(['message' => sprintf(__('%d products linked.', 'ffl-funnels-addons'), count($all)), 'count' => count($all)]);
        } elseif ($action_type === 'unlink_all') {
            $all = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
            foreach ($all as $pid) {
                delete_post_meta($pid, '_wss_sync_enabled');
            }
            wp_send_json_success(['message' => __('All products unlinked.', 'ffl-funnels-addons'), 'count' => 0]);
        } else {
            wp_send_json_error(['message' => __('Invalid action.', 'ffl-funnels-addons')]);
        }
    }

    /**
     * AJAX: Link products by taxonomy (category or tag).
     */
    public function ajax_link_by_taxonomy(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $taxonomy = sanitize_key($_POST['taxonomy'] ?? '');
        $term_id  = absint($_POST['term_id'] ?? 0);

        if (!in_array($taxonomy, ['product_cat', 'product_tag'], true) || !$term_id) {
            wp_send_json_error(['message' => __('Invalid taxonomy or term.', 'ffl-funnels-addons')]);
        }

        $product_ids = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [[ // phpcs:ignore WordPress.DB.SlowDBQuery
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => $term_id,
            ]],
        ]);

        foreach ($product_ids as $pid) {
            update_post_meta($pid, '_wss_sync_enabled', '1');
        }

        // Return updated list of all synced IDs.
        $all_synced = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'meta_key'       => '_wss_sync_enabled',
            'meta_value'     => '1',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);

        // Resolve names for new IDs.
        $names = [];
        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            $names[$pid] = $product ? $product->get_name() : '#' . $pid;
        }

        wp_send_json_success([
            'message'    => sprintf(__('%d products linked.', 'ffl-funnels-addons'), count($product_ids)),
            'linked_ids' => array_map('strval', $product_ids),
            'names'      => $names,
            'total'      => count($all_synced),
        ]);
    }

    // ──────────────────────────────────────────────────
    // Documentation Page
    // ──────────────────────────────────────────────────

    /**
     * Render the Documentation page.
     */
    public function render_docs_page(): void
    {
        ?>
        <style>
            .wss-docs h3 { margin: 0 0 var(--wb-spacing-sm); font-size: var(--wb-font-size-md); }
            .wss-docs p,
            .wss-docs li { font-size: var(--wb-font-size-sm); line-height: 1.6; color: var(--wb-color-neutral-foreground-1); }
            .wss-docs ol,
            .wss-docs ul { margin: var(--wb-spacing-xs) 0 var(--wb-spacing-md) var(--wb-spacing-lg); }
            .wss-docs li { margin-bottom: var(--wb-spacing-xs); }
            .wss-docs code { background: var(--wb-color-neutral-background-3, #f3f4f6); padding: 2px 6px; border-radius: var(--wb-radius-sm); font-size: var(--wb-font-size-xs); }
            .wss-docs .wss-table-docs { width: 100%; border-collapse: collapse; margin: var(--wb-spacing-sm) 0 var(--wb-spacing-md); font-size: var(--wb-font-size-sm); }
            .wss-docs .wss-table-docs th,
            .wss-docs .wss-table-docs td { padding: 8px 12px; border: 1px solid var(--wb-color-neutral-stroke-2, #e5e7eb); text-align: left; }
            .wss-docs .wss-table-docs th { background: var(--wb-color-neutral-background-3, #f3f4f6); font-weight: var(--wb-font-weight-semibold); }
            .wss-docs .wss-note { background: #fffbeb; border-left: 4px solid #f59e0b; padding: var(--wb-spacing-sm) var(--wb-spacing-md); border-radius: var(--wb-radius-sm); margin: var(--wb-spacing-sm) 0 var(--wb-spacing-md); font-size: var(--wb-font-size-sm); }
            .wss-docs .wss-note strong { color: #92400e; }
        </style>

        <div class="wss-docs">

        <!-- Getting Started -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2>Getting Started</h2>
            </div>
            <div class="wb-card__body">
                <h3>1. Connect Your Google Account</h3>
                <ol>
                    <li>Go to <strong>WSS Settings</strong>.</li>
                    <li>Click <strong>Connect with Google</strong>.</li>
                    <li>Sign in with the Google account that has access to the spreadsheet you want to use.</li>
                    <li>Grant the requested permissions (Spreadsheets + Email).</li>
                    <li>You will be redirected back. A green "Connected" badge confirms the connection.</li>
                </ol>

                <h3>2. Configure Your Spreadsheet</h3>
                <ol>
                    <li>In <strong>WSS Settings</strong>, paste the full Google Sheet URL (or just the Sheet ID).</li>
                    <li>Optional: the <strong>Sheet Tab Name</strong> field updates the <em>first</em> tab group; you can rename tabs and add more groups only on the Dashboard.</li>
                    <li>Click <strong>Save Settings</strong>.</li>
                </ol>

                <h3>3. Sheet Tab Groups (Products per Tab)</h3>
                <ol>
                    <li>Go to <strong>WSS Dashboard</strong>.</li>
                    <li>Each <strong>Sheet tab group</strong> has a <strong>Tab name</strong> that must match a tab at the bottom of your Google Sheet (case-sensitive, no characters <code>[ ] * / \\ ? :</code>).</li>
                    <li>Use <strong>Add sheet tab</strong> to create another group. The same product can appear in multiple tabs; each tab syncs its own rows.</li>
                    <li>Within a group, add products via <strong>Link all</strong>, <strong>Add by category/tag</strong>, or product search. <strong>Clear tab rules</strong> removes every rule for that tab only.</li>
                </ol>

                <h3>4. Run Your First Sync</h3>
                <ol>
                    <li>Click <strong>Sync Now</strong> on the Dashboard.</li>
                    <li>The plugin will write headers and product data to your spreadsheet.</li>
                    <li>Check the <strong>Sync Log</strong> for details.</li>
                </ol>
            </div>
        </div>

        <!-- Spreadsheet Layout -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2>Spreadsheet Column Layout</h2>
            </div>
            <div class="wb-card__body">
                <p>The plugin automatically creates and maintains these columns:</p>
                <table class="wss-table-docs">
                    <thead>
                        <tr>
                            <th>Column</th>
                            <th>Header</th>
                            <th>Description</th>
                            <th>Editable?</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>A</td><td><code>product_id</code></td><td>WooCommerce parent product ID</td><td>Only for new products</td></tr>
                        <tr><td>B</td><td><code>variation_id</code></td><td>Variation ID (same as product_id for simple products)</td><td>Only for new products</td></tr>
                        <tr><td>C</td><td><code>product_name</code></td><td>Product name</td><td>Required for new products</td></tr>
                        <tr><td>D</td><td><code>attributes</code></td><td>Variation attributes (e.g. "Color: Red | Size: L")</td><td>Only for new variations</td></tr>
                        <tr><td>E</td><td><code>sku</code></td><td>Product SKU</td><td>Yes</td></tr>
                        <tr><td>F</td><td><code>regular_price</code></td><td>Regular price</td><td>Yes</td></tr>
                        <tr><td>G</td><td><code>sale_price</code></td><td>Sale price (enter <code>0</code> to clear, leave empty to keep current)</td><td>Yes</td></tr>
                        <tr><td>H</td><td><code>stock_qty</code></td><td>Stock quantity (only if manage_stock is TRUE)</td><td>Yes</td></tr>
                        <tr><td>I</td><td><code>stock_status</code></td><td><code>instock</code>, <code>outofstock</code>, or <code>onbackorder</code></td><td>Yes</td></tr>
                        <tr><td>J</td><td><code>manage_stock</code></td><td><code>TRUE</code> or <code>FALSE</code></td><td>Yes</td></tr>
                        <tr><td>K</td><td><code>woo_updated_at</code></td><td>Timestamp of last sync</td><td>No (auto-generated)</td></tr>
                        <tr><td>L</td><td><code>sheet_updated_at</code></td><td>Reserved for future use</td><td>No</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Editing Existing Products -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2>Editing Products in the Sheet</h2>
            </div>
            <div class="wb-card__body">
                <p>To update existing products from the spreadsheet:</p>
                <ol>
                    <li>Find the row of the product/variation you want to edit.</li>
                    <li>Change the value in any editable column (F&ndash;J).</li>
                    <li>Run <strong>Sync Now</strong> (or wait for the automatic daily sync).</li>
                </ol>
                <p>The plugin compares your spreadsheet values against WooCommerce. If they differ, the <strong>spreadsheet values win</strong> and WooCommerce is updated.</p>

                <div class="wss-note">
                    <strong>Note:</strong> Empty cells are treated as "no change". If you want to clear a sale price, enter <code>0</code> (zero) &mdash; don't just delete the cell.
                </div>
            </div>
        </div>

        <!-- Creating New Products -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2>Creating New Products from the Sheet</h2>
            </div>
            <div class="wb-card__body">
                <p>You can create new WooCommerce products directly from the spreadsheet.</p>

                <h3>Create a Simple Product</h3>
                <p>Add a new row with:</p>
                <table class="wss-table-docs">
                    <thead>
                        <tr><th>Column</th><th>Value</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>A (<code>product_id</code>)</td><td><code>0</code> (or leave empty)</td></tr>
                        <tr><td>B (<code>variation_id</code>)</td><td><code>0</code> (or leave empty)</td></tr>
                        <tr><td>C (<code>product_name</code>)</td><td>Your product name (required)</td></tr>
                        <tr><td>E (<code>sku</code>)</td><td>SKU (recommended)</td></tr>
                        <tr><td>F (<code>regular_price</code>)</td><td>Price (e.g. <code>29.99</code>)</td></tr>
                        <tr><td>H-J</td><td>Stock info as needed</td></tr>
                    </tbody>
                </table>
                <p>After syncing, columns A and B will be automatically updated with the new product's IDs.</p>

                <h3>Create a Variation for an Existing Variable Product</h3>
                <table class="wss-table-docs">
                    <thead>
                        <tr><th>Column</th><th>Value</th></tr>
                    </thead>
                    <tbody>
                        <tr><td>A (<code>product_id</code>)</td><td>The parent variable product ID (e.g. <code>123</code>)</td></tr>
                        <tr><td>B (<code>variation_id</code>)</td><td><code>0</code> (or leave empty)</td></tr>
                        <tr><td>D (<code>attributes</code>)</td><td>Attribute combination (e.g. <code>Color: Red | Size: L</code>)</td></tr>
                        <tr><td>E (<code>sku</code>)</td><td>Unique SKU for this variation</td></tr>
                        <tr><td>F-J</td><td>Price and stock info</td></tr>
                    </tbody>
                </table>

                <div class="wss-note">
                    <strong>Attributes format:</strong> Use the exact attribute label and value separated by a colon, and separate multiple attributes with a pipe (<code>|</code>). Example: <code>Color: Red | Size: L</code>. The attribute names must match those defined on the parent variable product in WooCommerce. If a term value doesn't exist yet, it will be created automatically.
                </div>

                <div class="wss-note">
                    <strong>Duplicate SKU protection:</strong> If a product with the same SKU already exists in WooCommerce, a new product will <em>not</em> be created. Instead, the existing product's IDs will be written back to the sheet and it will be linked for syncing.
                </div>
            </div>
        </div>

        <!-- How Sync Works -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2>How the Sync Works</h2>
            </div>
            <div class="wb-card__body">
                <p>Each sync runs in two phases:</p>
                <ol>
                    <li><strong>Sheet &rarr; WooCommerce (first):</strong> The plugin reads the sheet and compares values against WooCommerce. If the sheet has different data, WooCommerce is updated. New rows (with <code>variation_id = 0</code>) create new products.</li>
                    <li><strong>WooCommerce &rarr; Sheet (second):</strong> The plugin writes the current WooCommerce product data back to the sheet. This includes any products linked for sync that aren't yet in the sheet.</li>
                </ol>
                <p>This means <strong>sheet edits take priority</strong>. If you change a price in both the sheet and WooCommerce between syncs, the sheet value wins.</p>

                <h3>Multiple Tabs and Duplicates</h3>
                <p>With several tab groups, the engine processes groups <strong>in the order shown on the Dashboard</strong> (top to bottom). For each tab it only reads and writes rows for products assigned to that tab.</p>
                <p><strong>WooCommerce &rarr; Sheet:</strong> If a product is in two tabs, it gets <strong>one row per tab</strong> after sync.</p>
                <p><strong>Sheet &rarr; WooCommerce:</strong> If the same <code>variation_id</code> appears in more than one tab with different values, <strong>the last tab in the list wins</strong> when writing back to WooCommerce. Keep conflicting edits in one tab, or reorder groups so the authoritative tab is last.</p>

                <h3>Automatic Sync</h3>
                <p>A daily cron job runs the sync automatically. You can see the next scheduled sync time on the Dashboard.</p>

                <h3>Manual Sync</h3>
                <p>Click <strong>Sync Now</strong> on the Dashboard at any time to run an immediate sync.</p>
            </div>
        </div>

        <!-- Troubleshooting -->
        <div class="wb-card">
            <div class="wb-card__header">
                <h2>Troubleshooting</h2>
            </div>
            <div class="wb-card__body">
                <table class="wss-table-docs">
                    <thead>
                        <tr><th>Problem</th><th>Solution</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>"Unable to parse range" error</td>
                            <td>A tab name in <strong>Sheet tab groups</strong> (Dashboard) does not match the tab at the bottom of your Google Sheet. Check spelling, spaces, and invalid characters (<code>[ ] * / \\ ? :</code> are stripped when saving).</td>
                        </tr>
                        <tr>
                            <td>"Not connected to Google" error</td>
                            <td>Go to WSS Settings and click <strong>Connect with Google</strong> to re-authorize.</td>
                        </tr>
                        <tr>
                            <td>"Google Sheets API has not been used" error</td>
                            <td>Enable the Google Sheets API in your Google Cloud Console project.</td>
                        </tr>
                        <tr>
                            <td>Products not appearing in the sheet</td>
                            <td>Confirm the product is included in that tab&rsquo;s group (search, category/tag rules, or Link all) on the <strong>WSS Dashboard</strong>, then run <strong>Sync Now</strong>.</td>
                        </tr>
                        <tr>
                            <td>Sheet edits not applying to WooCommerce</td>
                            <td>Only editable columns (E&ndash;J) are synced. Make sure the value is actually different from WooCommerce. Empty cells are ignored (treated as "no change").</td>
                        </tr>
                        <tr>
                            <td>New product row not creating a product</td>
                            <td>Column C (<code>product_name</code>) must not be empty. Columns A and B must be <code>0</code> or empty.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        </div><!-- .wss-docs -->
        <?php
    }
}
