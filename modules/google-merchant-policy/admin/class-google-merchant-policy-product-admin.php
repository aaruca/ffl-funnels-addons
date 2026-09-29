<?php
/**
 * Per-product Google decisions: product edit box, Products list column, filter
 * and bulk actions. Every change is saved through WooCommerce, so this addon
 * and Google for WooCommerce react to it like any other product edit.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Google_Merchant_Policy_Product_Admin
{
    const BOX_ID = 'ffla_google_merchant_policy';
    const GLA_BOX_ID = 'channel_visibility';
    const NONCE = 'ffla_gmp_product_override';
    const FIELD = 'ffla_gmp_override';
    const COLUMN = 'ffla_gmp';
    const FILTER = 'ffla_gmp_filter';
    const BULK_ACTIONS = [
        'ffla_gmp_include' => 'include',
        'ffla_gmp_exclude' => 'exclude',
        'ffla_gmp_follow' => '',
    ];

    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        // Google for WooCommerce registers its Channel visibility box at priority 10.
        add_action('add_meta_boxes', [$this, 'register_meta_box'], 100, 1);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_meta_box'], 10, 1);
        add_filter('manage_edit-product_columns', [$this, 'add_column'], 30);
        add_action('manage_product_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_action('restrict_manage_posts', [$this, 'render_filter'], 30, 1);
        add_action('pre_get_posts', [$this, 'apply_filter']);
        add_filter('bulk_actions-edit-product', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-product', [$this, 'handle_bulk_action'], 10, 3);
        add_action('admin_notices', [$this, 'render_bulk_notice']);
    }

    public function enqueue_assets(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || ($screen->post_type ?? '') !== 'product' || !in_array($screen->base, ['post', 'edit'], true)) {
            return;
        }
        wp_enqueue_style('ffla-google-merchant-policy', FFLA_URL . 'modules/google-merchant-policy/admin/css/google-merchant-policy-admin.css', [], FFLA_VERSION . '.3');
    }

    public function register_meta_box($post_type): void
    {
        if ($post_type !== 'product') {
            return;
        }
        // In Enforce this addon decides Google visibility. Google's own selector
        // would be overwritten on save, so this box replaces it.
        if (self::enforce() && Google_Merchant_Policy_Engine::dependency_available()) {
            remove_meta_box(self::GLA_BOX_ID, 'product', 'side');
        }
        add_meta_box(self::BOX_ID, __('Google Merchant Policy', 'ffl-funnels-addons'), [$this, 'render_meta_box'], 'product', 'side', 'default');
    }

    public function render_meta_box($post): void
    {
        $product = wc_get_product((int) $post->ID);
        if (!$product) {
            echo '<p>' . esc_html__('Save the product to see its Google decision.', 'ffl-funnels-addons') . '</p>';
            return;
        }
        $decision = Google_Merchant_Policy_Engine::evaluate_product($product);
        $override = Google_Merchant_Policy_Engine::get_override($product);

        wp_nonce_field(self::NONCE, self::NONCE . '_nonce');
        echo '<p>' . $this->badge((string) $decision['status']) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
        echo '<p class="description">' . esc_html((string) $decision['reason']) . '</p>';

        echo '<p><label for="ffla-gmp-override"><strong>' . esc_html__('Decision for this product', 'ffl-funnels-addons') . '</strong></label><br>';
        echo '<select id="ffla-gmp-override" name="' . esc_attr(self::FIELD) . '" class="widefat">';
        foreach (self::override_labels() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($override, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';
        echo '<p class="description">' . esc_html__('Always include skips the category rules and text checks. Products marked as firearm or ammunition stay excluded.', 'ffl-funnels-addons') . '</p>';
        if ($override === 'exclude' && (string) $product->get_meta(Google_Merchant_Policy_Engine::OVERRIDE_SOURCE_META, true) === 'google-for-woocommerce') {
            echo '<p class="description">' . esc_html__('Kept from the Channel visibility setting in Google for WooCommerce.', 'ffl-funnels-addons') . '</p>';
        }

        echo '<p class="ffla-gmp-product-google">';
        if (!Google_Merchant_Policy_Engine::dependency_available()) {
            esc_html_e('Google for WooCommerce is not active.', 'ffl-funnels-addons');
        } elseif (!self::enforce()) {
            esc_html_e('Audit only: Google is not changed. Switch Google Merchant Policy to Enforce to apply this decision.', 'ffl-funnels-addons');
        } else {
            echo esc_html($decision['status'] === 'allowed'
                ? __('Sent to Google through Google for WooCommerce.', 'ffl-funnels-addons')
                : __('Kept out of Google. Removal is requested if it was synced.', 'ffl-funnels-addons'));
            $google = self::google_status($product);
            if ($google !== '') {
                echo '<br>' . esc_html($google);
            }
        }
        echo '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=' . Google_Merchant_Policy_Admin::PAGE)) . '">' . esc_html__('Category rules and catalog scan', 'ffl-funnels-addons') . '</a></p>';
    }

    public function save_meta_box($product): void
    {
        if (!isset($_POST[self::FIELD], $_POST[self::NONCE . '_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE . '_nonce'])), self::NONCE)
            || !current_user_can('edit_product', $product->get_id())) {
            return;
        }
        $override = sanitize_key(wp_unslash($_POST[self::FIELD]));
        // The select is posted on every save: record only an actual change.
        if (array_key_exists($override, self::override_labels()) && $override !== Google_Merchant_Policy_Engine::get_override($product)) {
            Google_Merchant_Policy_Engine::set_override($product, $override);
        }
    }

    public function add_column(array $columns): array
    {
        $result = [];
        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $result[self::COLUMN] = __('Google', 'ffl-funnels-addons');
            }
            $result[$key] = $label;
        }
        if (!isset($result[self::COLUMN])) {
            $result[self::COLUMN] = __('Google', 'ffl-funnels-addons');
        }
        return $result;
    }

    public function render_column($column, $post_id): void
    {
        if ($column !== self::COLUMN) {
            return;
        }
        $status = (string) get_post_meta((int) $post_id, Google_Merchant_Policy_Engine::STATUS_META, true);
        if ($status === '') {
            $status = (string) Google_Merchant_Policy_Engine::evaluate_product((int) $post_id)['status'];
        }
        echo $this->badge($status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
        $override = sanitize_key((string) get_post_meta((int) $post_id, Google_Merchant_Policy_Engine::OVERRIDE_META, true));
        if ($override !== '' && isset(self::override_labels()[$override])) {
            echo '<small class="ffla-gmp-column-note">' . esc_html(self::override_labels()[$override]) . '</small>';
        }
        $product = wc_get_product((int) $post_id);
        $google = $product ? self::google_status($product, true) : '';
        if ($google !== '') {
            echo '<small class="ffla-gmp-column-note">' . esc_html($google) . '</small>';
        }
    }

    public function render_filter($post_type): void
    {
        if ($post_type !== 'product') {
            return;
        }
        $current = isset($_GET[self::FILTER]) ? sanitize_key(wp_unslash($_GET[self::FILTER])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        echo '<select name="' . esc_attr(self::FILTER) . '"><option value="">' . esc_html__('All Google decisions', 'ffl-funnels-addons') . '</option>';
        foreach (self::filter_labels() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function apply_filter($query): void
    {
        global $pagenow;
        if (!is_admin() || $pagenow !== 'edit.php' || !$query->is_main_query() || $query->get('post_type') !== 'product') {
            return;
        }
        $filter = isset($_GET[self::FILTER]) ? sanitize_key(wp_unslash($_GET[self::FILTER])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset(self::filter_labels()[$filter])) {
            return;
        }
        $key = in_array($filter, ['include', 'exclude'], true)
            ? Google_Merchant_Policy_Engine::OVERRIDE_META
            : Google_Merchant_Policy_Engine::STATUS_META;
        $meta_query = (array) $query->get('meta_query');
        $meta_query[] = ['key' => $key, 'value' => $filter];
        $query->set('meta_query', $meta_query);
    }

    public function add_bulk_actions(array $actions): array
    {
        $actions['ffla_gmp_include'] = __('Google: always include', 'ffl-funnels-addons');
        $actions['ffla_gmp_exclude'] = __('Google: always exclude', 'ffl-funnels-addons');
        $actions['ffla_gmp_follow'] = __('Google: follow policy rules', 'ffl-funnels-addons');
        return $actions;
    }

    /** WordPress verified the bulk-posts nonce before this filter runs. */
    public function handle_bulk_action($redirect, $action, $post_ids)
    {
        if (!array_key_exists((string) $action, self::BULK_ACTIONS)) {
            return $redirect;
        }
        $updated = 0;
        foreach ((array) $post_ids as $post_id) {
            $post_id = (int) $post_id;
            $product = current_user_can('edit_product', $post_id) ? wc_get_product($post_id) : false;
            if (!$product || (int) $product->get_parent_id() > 0) {
                continue;
            }
            Google_Merchant_Policy_Engine::set_override($product, self::BULK_ACTIONS[$action]);
            // A normal WooCommerce save: this addon applies the decision and Google
            // for WooCommerce uploads or removes the product in the same request.
            $product->save();
            $updated++;
        }
        return add_query_arg(
            ['ffla_gmp_bulk' => $updated, 'ffla_gmp_bulk_action' => (string) $action],
            remove_query_arg(['ffla_gmp_bulk', 'ffla_gmp_bulk_action'], $redirect)
        );
    }

    public function render_bulk_notice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only notice.
        if (!isset($_GET['ffla_gmp_bulk'], $_GET['ffla_gmp_bulk_action'])
            || !array_key_exists(sanitize_key(wp_unslash($_GET['ffla_gmp_bulk_action'])), self::BULK_ACTIONS)) {
            return;
        }
        $count = absint($_GET['ffla_gmp_bulk']);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $message = sprintf(
            /* translators: %d: number of products. */
            _n('Google decision updated for %d product.', 'Google decision updated for %d products.', $count, 'ffl-funnels-addons'),
            $count
        );
        $message .= ' ' . (self::enforce()
            ? __('Google for WooCommerce uploads or removes them automatically.', 'ffl-funnels-addons')
            : __('Audit only: Google is not changed until Enforce is active.', 'ffl-funnels-addons'));
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private static function enforce(): bool
    {
        return (string) Google_Merchant_Policy_Engine::get_settings()['mode'] === 'enforce';
    }

    private static function override_labels(): array
    {
        return [
            '' => __('Follow policy rules', 'ffl-funnels-addons'),
            'include' => __('Always include', 'ffl-funnels-addons'),
            'exclude' => __('Always exclude', 'ffl-funnels-addons'),
        ];
    }

    private static function filter_labels(): array
    {
        return [
            'allowed' => __('Google: Allowed', 'ffl-funnels-addons'),
            'blocked' => __('Google: Blocked', 'ffl-funnels-addons'),
            'pending' => __('Google: Pending', 'ffl-funnels-addons'),
            'include' => __('Google: Always include', 'ffl-funnels-addons'),
            'exclude' => __('Google: Always exclude', 'ffl-funnels-addons'),
        ];
    }

    /** Status Google for WooCommerce stored for the product, as one line ($compact for the list column). */
    private static function google_status($product, bool $compact = false): string
    {
        $sync = [
            'synced' => __('Synced', 'ffl-funnels-addons'),
            'pending' => __('Sync pending', 'ffl-funnels-addons'),
            'has-errors' => __('Has sync errors', 'ffl-funnels-addons'),
            'not-synced' => __('Not synced', 'ffl-funnels-addons'),
        ];
        $merchant = [
            'approved' => __('approved', 'ffl-funnels-addons'),
            'partially_approved' => __('partially approved', 'ffl-funnels-addons'),
            'expiring' => __('expiring', 'ffl-funnels-addons'),
            'pending' => __('under review', 'ffl-funnels-addons'),
            'disapproved' => __('disapproved', 'ffl-funnels-addons'),
        ];
        $sync_status = (string) $product->get_meta('_wc_gla_sync_status', true);
        $mc_status = (string) $product->get_meta('_wc_gla_mc_status', true);
        if (!isset($sync[$sync_status])) {
            return '';
        }
        if ($compact) {
            return $sync[$sync_status] . (isset($merchant[$mc_status]) ? ' · ' . $merchant[$mc_status] : '');
        }
        $line = sprintf(
            /* translators: %s: Google for WooCommerce sync status. */
            __('Google for WooCommerce: %s', 'ffl-funnels-addons'),
            $sync[$sync_status]
        );
        if (isset($merchant[$mc_status])) {
            $line .= sprintf(
                /* translators: %s: Merchant Center product status. */
                __(', Merchant Center: %s', 'ffl-funnels-addons'),
                $merchant[$mc_status]
            );
        }
        return $line;
    }

    private function badge(string $status): string
    {
        $labels = [
            'allowed' => __('Allowed', 'ffl-funnels-addons'),
            'blocked' => __('Blocked', 'ffl-funnels-addons'),
            'pending' => __('Pending', 'ffl-funnels-addons'),
        ];
        $status = isset($labels[$status]) ? $status : 'pending';
        return '<span class="ffla-gmp-badge ffla-gmp-badge--' . esc_attr($status) . '">' . esc_html($labels[$status]) . '</span>';
    }
}
