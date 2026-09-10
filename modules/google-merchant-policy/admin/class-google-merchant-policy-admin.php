<?php
/**
 * Google Merchant Policy admin.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Google_Merchant_Policy_Admin
{
    const PAGE = 'ffla-google-merchant-policy';

    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_ffla_gmp_save', [$this, 'save']);
        add_action('admin_post_ffla_gmp_pause', [$this, 'pause']);
        add_action('admin_post_ffla_gmp_run_batch', [$this, 'run_batch']);
        add_action('product_cat_add_form_fields', [$this, 'render_add_term_field']);
        add_action('product_cat_edit_form_fields', [$this, 'render_edit_term_field']);
        add_action('edited_product_cat', [$this, 'save_term_field'], 30, 2);
    }

    public function enqueue_assets(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) !== self::PAGE) {
            return;
        }
        $base = FFLA_URL . 'modules/google-merchant-policy/admin/';
        wp_enqueue_style('ffla-google-merchant-policy', $base . 'css/google-merchant-policy-admin.css', [], FFLA_VERSION . '.2');
        wp_enqueue_script('ffla-google-merchant-policy', $base . 'js/google-merchant-policy-admin.js', [], FFLA_VERSION . '.2', true);
    }

    public function save(): void
    {
        $this->authorize('ffla_gmp_save');

        $settings = Google_Merchant_Policy_Engine::sanitize_settings([
            'mode' => wp_unslash($_POST['mode'] ?? 'audit'),
            'batch_size' => wp_unslash($_POST['batch_size'] ?? 50),
            'content_safety' => isset($_POST['content_safety']) ? '1' : '0',
        ]);
        update_option(Google_Merchant_Policy_Engine::OPTION, $settings, false);

        $policies = isset($_POST['category_policy']) && is_array($_POST['category_policy'])
            ? wp_unslash($_POST['category_policy'])
            : [];
        foreach ($policies as $term_id => $policy) {
            $term_id = (int) $term_id;
            if ($term_id <= 0 || get_term_field('taxonomy', $term_id) !== 'product_cat') {
                continue;
            }
            Google_Merchant_Policy_Engine::set_category_policy($term_id, (string) $policy);
        }

        Google_Merchant_Policy_Reconciler::start();
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'saved' => '1'], admin_url('admin.php')));
        exit;
    }

    public function pause(): void
    {
        $this->authorize('ffla_gmp_pause');
        Google_Merchant_Policy_Reconciler::pause();
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'paused' => '1'], admin_url('admin.php')));
        exit;
    }

    public function run_batch(): void
    {
        $this->authorize('ffla_gmp_run_batch');
        $state = Google_Merchant_Policy_Reconciler::get_state();
        if (!in_array((string) $state['status'], ['running'], true)) {
            Google_Merchant_Policy_Reconciler::resume();
        }
        Google_Merchant_Policy_Reconciler::run_batch();
        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'batch' => '1'], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (isset($_GET['saved'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            FFLA_Admin::render_notice('success', __('Policies saved. A gradual catalog scan has been queued.', 'ffl-funnels-addons'));
        }
        if (isset($_GET['paused'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            FFLA_Admin::render_notice('info', __('The catalog scan is paused. Existing audit metadata and feed exclusions were preserved.', 'ffl-funnels-addons'));
        }
        if (!Google_Merchant_Policy_Engine::dependency_available()) {
            FFLA_Admin::render_notice('warning', __('Google for WooCommerce is not active. Policies can be prepared and audited, but its product-feed filter will not run until that plugin is active.', 'ffl-funnels-addons'));
        }

        $settings = Google_Merchant_Policy_Engine::get_settings();
        $state = Google_Merchant_Policy_Reconciler::get_state();
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        $terms = is_wp_error($terms) ? [] : $this->sort_terms_hierarchically($terms);

        echo '<div class="ffla-gmp-summary">';
        $this->summary_card(__('Mode', 'ffl-funnels-addons'), (string) ($settings['mode'] === 'enforce' ? __('Enforce', 'ffl-funnels-addons') : __('Audit only', 'ffl-funnels-addons')), $settings['mode']);
        $this->summary_card(__('Catalog scan', 'ffl-funnels-addons'), ucfirst((string) $state['status']), (string) $state['status']);
        $this->summary_card(__('Processed', 'ffl-funnels-addons'), number_format_i18n((int) $state['processed']), 'neutral');
        $this->summary_card(__('Allowed / Blocked / Pending', 'ffl-funnels-addons'), sprintf('%d / %d / %d', (int) $state['allowed'], (int) $state['blocked'], (int) $state['pending']), 'neutral');
        echo '</div>';

        echo '<div class="wb-card ffla-gmp-intro"><div class="wb-card__body">';
        if (!empty($state['updated_at'])) {
            echo '<p>' . esc_html(sprintf(__('Last progress (UTC): %1$s. Google withdrawal requests delegated: %2$d. Unavailable products skipped: %3$d.', 'ffl-funnels-addons'), $state['updated_at'], $state['withdrawal_requests'], $state['skipped'])) . '</p>';
        }
        echo '<p><strong>' . esc_html__('Local scan completion is not Google approval.', 'ffl-funnels-addons') . '</strong> ' . esc_html__('Removal requests are processed asynchronously by Google for WooCommerce. Verify its scheduled actions and the remaining products in Merchant Center before requesting an account review. Keyword checks assist your category policies; they cannot certify every product as compliant.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';
        require __DIR__ . '/views/usage-guide.php';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ffla-gmp-form">';
        echo '<input type="hidden" name="action" value="ffla_gmp_save">';
        wp_nonce_field('ffla_gmp_save');
        echo '<p id="ffla-gmp-unsaved" class="ffla-gmp-unsaved" role="status" hidden>' . esc_html__('Unsaved changes. Category-result badges still reflect saved rules. Use Save policies & start catalog scan to apply your edits. Resume and Pause do not save this form.', 'ffl-funnels-addons') . '</p>';

        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Policy settings', 'ffl-funnels-addons') . '</h3></div><div class="wb-card__body ffla-gmp-settings">';
        echo '<div class="wb-field"><label for="ffla-gmp-mode"><strong>' . esc_html__('Operating mode', 'ffl-funnels-addons') . '</strong></label><select id="ffla-gmp-mode" name="mode" aria-describedby="ffla-gmp-mode-help">';
        echo '<option value="audit"' . selected($settings['mode'], 'audit', false) . '>' . esc_html__('Audit only — no feed changes', 'ffl-funnels-addons') . '</option>';
        echo '<option value="enforce"' . selected($settings['mode'], 'enforce', false) . '>' . esc_html__('Enforce — protect the feed', 'ffl-funnels-addons') . '</option></select><p id="ffla-gmp-mode-help" class="wb-field__desc">' . esc_html__('Audit records decisions only. Enforce excludes Blocked AND Pending products. Neither mode automatically restores an existing exclusion. Mode changes apply when you save.', 'ffl-funnels-addons') . '</p></div>';
        echo '<div class="wb-field"><label for="ffla-gmp-batch"><strong>' . esc_html__('Products per batch', 'ffl-funnels-addons') . '</strong></label><input id="ffla-gmp-batch" name="batch_size" type="number" min="10" max="250" step="10" aria-describedby="ffla-gmp-batch-help" value="' . esc_attr((string) $settings['batch_size']) . '"><p id="ffla-gmp-batch-help" class="wb-field__desc">' . esc_html__('Start at 50; allowed range: 10–250. Smaller batches reduce work per request, not the catalog size. Saving changes restarts the scan.', 'ffl-funnels-addons') . '</p></div>';
        echo '<label class="ffla-gmp-checkbox"><input type="checkbox" name="content_safety" value="1"' . checked((string) $settings['content_safety'], '1', false) . '><span><strong>' . esc_html__('Restricted-content safety scan', 'ffl-funnels-addons') . '</strong><small>' . esc_html__('Keep enabled to check names, descriptions and category names for restricted-content patterns, even in Allow categories. Turning it off does not bypass firearm/ammunition flags or remove existing exclusions. Pattern matching is not a guarantee of Google compliance.', 'ffl-funnels-addons') . '</small></span></label>';
        echo '</div></div>';

        echo '<div class="wb-card"><div class="wb-card__header ffla-gmp-category-header"><div><h3>' . esc_html__('Category policies', 'ffl-funnels-addons') . '</h3><p>' . esc_html__('Children inherit their parent unless they have an explicit policy. New root categories start Pending; new child categories start Inherit.', 'ffl-funnels-addons') . '</p></div><input type="search" id="ffla-gmp-category-search" placeholder="' . esc_attr__('Search categories…', 'ffl-funnels-addons') . '"></div><div class="wb-card__body">';
        echo '<p class="ffla-gmp-category-note">' . esc_html__('Badges show saved category rules, not live product approval. After changing dropdowns, save to recalculate them. Products assigned to several categories must pass all of them; Block wins, then Pending, then Allow.', 'ffl-funnels-addons') . '</p>';
        echo '<div class="ffla-gmp-table-wrap"><table class="widefat striped ffla-gmp-category-table"><thead><tr><th>' . esc_html__('Category', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Products', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Rule', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Saved effective category rule', 'ffl-funnels-addons') . '</th></tr></thead><tbody>';
        foreach ($terms as $entry) {
            $term = $entry['term'];
            $depth = $entry['depth'];
            $stored = Google_Merchant_Policy_Engine::get_category_policy((int) $term->term_id);
            if ($stored === '') {
                $stored = (int) $term->parent > 0 ? 'inherit' : 'pending';
            }
            $effective = Google_Merchant_Policy_Engine::get_effective_category_policy((int) $term->term_id);
            echo '<tr data-category-name="' . esc_attr(strtolower((string) $term->name)) . '"><td><span class="ffla-gmp-depth" style="--depth:' . esc_attr((string) $depth) . '"></span><strong>' . esc_html((string) $term->name) . '</strong><code>' . esc_html((string) $term->slug) . '</code></td><td>' . esc_html(number_format_i18n((int) $term->count)) . '</td><td><select name="category_policy[' . esc_attr((string) $term->term_id) . ']">';
            foreach (['inherit' => __('Inherit parent', 'ffl-funnels-addons'), 'allow' => __('Allow', 'ffl-funnels-addons'), 'block' => __('Block', 'ffl-funnels-addons'), 'pending' => __('Pending — not eligible in Enforce', 'ffl-funnels-addons')] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '"' . selected($stored, $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></td><td><span class="ffla-gmp-policy ffla-gmp-policy--' . esc_attr((string) $effective['policy']) . '">' . esc_html(ucfirst((string) $effective['policy'])) . '</span><small>' . esc_html((string) $effective['reason']) . '</small></td></tr>';
        }
        if (empty($terms)) {
            echo '<tr><td colspan="4">' . esc_html__('No WooCommerce product categories were found.', 'ffl-funnels-addons') . '</td></tr>';
        }
        echo '</tbody></table></div></div></div>';

        echo '<div class="ffla-gmp-actions"><button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save policies & start catalog scan', 'ffl-funnels-addons') . '</button><span>' . esc_html__('Saves all edits and starts a NEW scan with reset counters. Existing exclusions remain. Use Resume to continue a paused scan without restarting it.', 'ffl-funnels-addons') . '</span></div></form>';

        echo '<div class="ffla-gmp-secondary-actions">';
        $this->action_form('ffla_gmp_run_batch', 'ffla_gmp_run_batch', __('Resume / run next batch', 'ffl-funnels-addons'), 'wb-btn wb-btn--secondary');
        $this->action_form('ffla_gmp_pause', 'ffla_gmp_pause', __('Pause scan', 'ffl-funnels-addons'), 'wb-btn');
        echo '</div>';
        echo '<p class="ffla-gmp-action-help">' . esc_html__('Resume and Pause use saved settings only. Resume continues a checkpoint unless the scan is Idle or Complete; then it starts a new scan. Pause stops this scan, not Enforce filtering or Google jobs already delegated.', 'ffl-funnels-addons') . '</p>';

        if ((string) $state['last_error'] !== '') {
            FFLA_Admin::render_notice('error', sprintf(__('Last scan error: %s', 'ffl-funnels-addons'), esc_html((string) $state['last_error'])));
        }
    }

    public function render_add_term_field(): void
    {
        echo '<div class="form-field"><label>' . esc_html__('Google Merchant policy', 'ffl-funnels-addons') . '</label>';
        echo '<p>' . esc_html__('Assigned automatically when the category is created: root categories start Pending and child categories start by inheriting their parent. You can change the rule afterward.', 'ffl-funnels-addons') . '</p></div>';
    }

    public function render_edit_term_field($term): void
    {
        $stored = Google_Merchant_Policy_Engine::get_category_policy((int) $term->term_id);
        if ($stored === '') {
            $stored = (int) $term->parent > 0 ? 'inherit' : 'pending';
        }
        echo '<tr class="form-field"><th scope="row"><label for="ffla-gmp-term-policy">' . esc_html__('Google Merchant policy', 'ffl-funnels-addons') . '</label></th><td>';
        $this->term_select('ffla_gmp_term_policy', $stored);
        echo '<p class="description">' . esc_html__('Firearm/ammunition flags always override Allow. Text checks also apply when enabled. Pending is excluded in Enforce; Inherit follows the first explicit ancestor rule. Saving this category starts a new catalog scan.', 'ffl-funnels-addons') . '</p></td></tr>';
    }

    public function save_term_field(int $term_id, int $tt_id): void
    {
        if (!current_user_can('manage_woocommerce') || !isset($_POST['ffla_gmp_term_policy'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }
        Google_Merchant_Policy_Engine::set_category_policy($term_id, sanitize_key(wp_unslash($_POST['ffla_gmp_term_policy']))); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    private function term_select(string $name, string $selected_value): void
    {
        echo '<select id="ffla-gmp-term-policy" name="' . esc_attr($name) . '">';
        foreach (['inherit' => __('Inherit parent', 'ffl-funnels-addons'), 'allow' => __('Allow', 'ffl-funnels-addons'), 'block' => __('Block', 'ffl-funnels-addons'), 'pending' => __('Pending — not eligible in Enforce', 'ffl-funnels-addons')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($selected_value, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private function authorize(string $nonce): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }
        check_admin_referer($nonce);
    }

    private function summary_card(string $label, string $value, string $status): void
    {
        echo '<div class="ffla-gmp-summary__card"><span>' . esc_html($label) . '</span><strong class="ffla-gmp-summary__value ffla-gmp-summary__value--' . esc_attr($status) . '">' . esc_html($value) . '</strong></div>';
    }

    private function action_form(string $action, string $nonce, string $label, string $class): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="' . esc_attr($action) . '">';
        wp_nonce_field($nonce);
        echo '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    private function sort_terms_hierarchically(array $terms): array
    {
        $children = [];
        foreach ($terms as $term) {
            $children[(int) $term->parent][] = $term;
        }
        foreach ($children as &$group) {
            usort($group, function ($a, $b) {
                return strcasecmp((string) $a->name, (string) $b->name);
            });
        }
        unset($group);

        $result = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$children, &$result): void {
            foreach ($children[$parent] ?? [] as $term) {
                $result[] = ['term' => $term, 'depth' => $depth];
                $walk((int) $term->term_id, $depth + 1);
            }
        };
        $walk(0, 0);
        return $result;
    }
}
