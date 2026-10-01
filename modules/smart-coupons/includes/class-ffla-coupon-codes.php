<?php
/**
 * Smart Coupons — bulk single-use codes and coupon links.
 *
 * Bulk codes copy every setting of a template coupon (WooCommerce settings,
 * Smart Coupons options and categories) into up to 500 unique codes per run,
 * each a real coupon usable once by default. Batches are tracked with their
 * usage and export to CSV.
 *
 * Coupon links: any URL with ?coupon=CODE applies the coupon — right away
 * when the cart has items, otherwise when the visitor adds the first product.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Coupon_Codes
{
    const BATCHES = 'ffla_coupon_batches';
    const MAX = 500;
    const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // No 0/O, 1/I/L.

    public static function boot(): void
    {
        add_action('wp_loaded', [__CLASS__, 'link'], 30);
        add_action('woocommerce_add_to_cart', [__CLASS__, 'apply_pending'], 20);
        add_action('admin_post_ffla_cpn_generate', [__CLASS__, 'handle_generate']);
        add_action('admin_post_ffla_cpn_batch_csv', [__CLASS__, 'handle_csv']);
    }

    public static function unique_code(string $prefix, int $length): string
    {
        $alphabet = self::ALPHABET;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $prefix;
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = wc_format_coupon_code($code);
            if (!wc_get_coupon_id_by_code($code)) {
                return $code;
            }
        }
        throw new RuntimeException(__('Could not create a unique code. Use a longer code length.', 'ffl-funnels-addons'));
    }

    /* ── Coupon links ──────────────────────────────────────────────────── */

    public static function link_url(string $code): string
    {
        return add_query_arg((string) FFLA_Coupon_Settings::value('link_param'), rawurlencode(strtoupper($code)), home_url('/'));
    }

    public static function link(): void
    {
        $param = (string) FFLA_Coupon_Settings::value('link_param');
        if (!FFLA_Coupon_Settings::value('links') || empty($_GET[$param]) || is_admin() || wp_doing_ajax() || !function_exists('WC') || !WC()->cart || !WC()->session) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $code = wc_format_coupon_code(sanitize_text_field(wp_unslash($_GET[$param]))); // phpcs:ignore WordPress.Security.NonceVerification
        if ('' !== $code && wc_get_coupon_id_by_code($code)) {
            if (!WC()->cart->is_empty()) {
                if (!WC()->cart->has_discount($code)) {
                    WC()->cart->apply_coupon($code);
                }
            } else {
                WC()->session->set_customer_session_cookie(true);
                WC()->session->set('ffla_pending_coupon', $code);
                /* translators: %s: coupon code */
                wc_add_notice(sprintf(__('Coupon %s will be applied when you add a product to your cart.', 'ffl-funnels-addons'), strtoupper($code)), 'notice');
            }
        }
        // Clean URL: refreshing or sharing the page does not re-apply.
        wp_safe_redirect(remove_query_arg($param));
        exit;
    }

    public static function apply_pending(): void
    {
        if (!WC()->session) {
            return;
        }
        $code = (string) WC()->session->get('ffla_pending_coupon', '');
        if ('' === $code) {
            return;
        }
        WC()->session->set('ffla_pending_coupon', null);
        if (!WC()->cart->has_discount($code)) {
            WC()->cart->apply_coupon($code);
        }
    }

    /* ── Bulk codes ────────────────────────────────────────────────────── */

    /**
     * @return array{batch:string, codes:string[]}
     * @throws InvalidArgumentException
     */
    public static function generate(WC_Coupon $template, int $count, string $prefix, int $length, int $usage_limit, string $expires, int $category): array
    {
        if (!$template->get_id()) {
            throw new InvalidArgumentException(__('Choose a template coupon.', 'ffl-funnels-addons'));
        }
        $count = max(1, min(self::MAX, $count));
        $length = max(6, min(16, $length));
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $prefix));

        $data = $template->get_data();
        foreach (['id', 'code', 'usage_count', 'used_by', 'date_created', 'date_modified', 'meta_data', 'date_expires', 'usage_limit'] as $key) {
            unset($data[$key]);
        }
        $options = $template->get_meta(FFLA_Coupon_Settings::META, true);
        $terms = wp_get_object_terms($template->get_id(), FFLA_Coupon_Categories::TAX, ['fields' => 'ids']);
        $terms = is_wp_error($terms) ? [] : array_map('intval', $terms);
        if ($category) {
            $terms[] = $category;
        }

        $batch = 'b' . gmdate('ymdHis') . strtolower(substr(wp_generate_password(4, false), 0, 4));
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $coupon = new WC_Coupon();
            $coupon->set_props($data);
            $coupon->set_code(self::unique_code($prefix, $length));
            $coupon->set_usage_limit($usage_limit > 0 ? $usage_limit : null);
            $coupon->set_date_expires(preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) ? strtotime(get_gmt_from_date($expires . ' 23:59:59') . ' UTC') : $template->get_date_expires());
            $coupon->set_description(trim(sprintf(
                /* translators: 1: batch, 2: template code */
                __('Batch %1$s from %2$s', 'ffl-funnels-addons'),
                $batch,
                strtoupper($template->get_code())
            )));
            if (is_array($options)) {
                $coupon->update_meta_data(FFLA_Coupon_Settings::META, $options);
            }
            $coupon->update_meta_data('_ffla_batch', $batch);
            $coupon->save();
            if ($terms) {
                wp_set_object_terms($coupon->get_id(), array_values(array_unique($terms)), FFLA_Coupon_Categories::TAX);
                FFLA_Coupon_Categories::apply_default_expiry($coupon->get_id(), $coupon); // No expiry yet → the category's default.
            }
            $codes[] = $coupon->get_code();
        }

        $batches = get_option(self::BATCHES, []);
        $batches = is_array($batches) ? $batches : [];
        $batches[$batch] = [
            'template' => $template->get_code(),
            'prefix'   => $prefix,
            'count'    => $count,
            'category' => $category,
            'created'  => time(),
            'by'       => get_current_user_id(),
        ];
        update_option(self::BATCHES, $batches, false);

        return ['batch' => $batch, 'codes' => $codes];
    }

    /** @return array{total:int, used:int} */
    public static function batch_usage(string $batch): array
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN CAST(u.meta_value AS UNSIGNED) > 0 THEN 1 ELSE 0 END) AS used
             FROM {$wpdb->postmeta} b
             JOIN {$wpdb->posts} p ON p.ID = b.post_id AND p.post_type = 'shop_coupon' AND p.post_status <> 'trash'
             LEFT JOIN {$wpdb->postmeta} u ON u.post_id = b.post_id AND u.meta_key = 'usage_count'
             WHERE b.meta_key = '_ffla_batch' AND b.meta_value = %s",
            $batch
        ), ARRAY_A);
        // phpcs:enable
        return ['total' => (int) ($row['total'] ?? 0), 'used' => (int) ($row['used'] ?? 0)];
    }

    public static function page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        FFLA_Coupon_Admin::notices();
        $templates = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 300, 'orderby' => 'title', 'order' => 'ASC',
            'meta_query' => [['key' => '_ffla_batch', 'compare' => 'NOT EXISTS'], ['key' => '_ffla_credit', 'compare' => 'NOT EXISTS']]]); // phpcs:ignore WordPress.DB.SlowDBQuery

        echo '<div class="wb-card"><div class="wb-card__header"><h2>' . esc_html__('Generate single-use codes', 'ffl-funnels-addons') . '</h2></div><div class="wb-card__body">';
        echo '<p>' . esc_html__('Create unique codes for a gun show, a range partner or an email list. Every code copies the template coupon’s discount, restrictions, Smart Coupons options and categories, so set the template up first.', 'ffl-funnels-addons') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ffla-cpn-form"><input type="hidden" name="action" value="ffla_cpn_generate">';
        wp_nonce_field('ffla_cpn_generate');
        echo '<p><label for="ffla-gen-template">' . esc_html__('Template coupon', 'ffl-funnels-addons') . '</label><select id="ffla-gen-template" name="template" required><option value="">' . esc_html__('— Choose —', 'ffl-funnels-addons') . '</option>';
        foreach ($templates as $post) {
            echo '<option value="' . (int) $post->ID . '">' . esc_html(strtoupper($post->post_title)) . '</option>';
        }
        echo '</select></p>';
        echo '<p><label for="ffla-gen-count">' . esc_html__('How many codes', 'ffl-funnels-addons') . '</label><input type="number" id="ffla-gen-count" name="count" min="1" max="' . (int) self::MAX . '" value="50" required></p>'
            . '<p><label for="ffla-gen-prefix">' . esc_html__('Prefix', 'ffl-funnels-addons') . '</label><input type="text" id="ffla-gen-prefix" name="prefix" maxlength="12" placeholder="SHOW-"></p>'
            . '<p><label for="ffla-gen-length">' . esc_html__('Random characters', 'ffl-funnels-addons') . '</label><input type="number" id="ffla-gen-length" name="length" min="6" max="16" value="8"></p>'
            . '<p><label for="ffla-gen-limit">' . esc_html__('Uses per code', 'ffl-funnels-addons') . '</label><input type="number" id="ffla-gen-limit" name="usage_limit" min="0" value="1"> <span class="description">' . esc_html__('0 = unlimited', 'ffl-funnels-addons') . '</span></p>'
            . '<p><label for="ffla-gen-expires">' . esc_html__('Expires', 'ffl-funnels-addons') . '</label><input type="date" id="ffla-gen-expires" name="expires"> <span class="description">' . esc_html__('Empty = same as the template', 'ffl-funnels-addons') . '</span></p>';
        echo '<p><label for="ffla-gen-cat">' . esc_html__('Add to category', 'ffl-funnels-addons') . '</label>';
        wp_dropdown_categories(['taxonomy' => FFLA_Coupon_Categories::TAX, 'name' => 'category', 'id' => 'ffla-gen-cat', 'hide_empty' => false, 'hierarchical' => true, 'show_option_none' => __('— Same as the template —', 'ffl-funnels-addons'), 'option_none_value' => 0]);
        echo '</p><p><button type="submit" class="wb-btn wb-btn--primary button button-primary">' . esc_html__('Generate codes', 'ffl-funnels-addons') . '</button></p></form></div></div>';

        $batches = get_option(self::BATCHES, []);
        echo '<div class="wb-card"><div class="wb-card__header"><h2>' . esc_html__('Batches', 'ffl-funnels-addons') . '</h2></div><div class="wb-card__body">';
        if (!is_array($batches) || !$batches) {
            echo '<p>' . esc_html__('No batches yet.', 'ffl-funnels-addons') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Batch', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Template', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Category', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Codes', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Used', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Created', 'ffl-funnels-addons') . '</th><th></th></tr></thead><tbody>';
            foreach (array_reverse($batches, true) as $id => $b) {
                $usage = self::batch_usage((string) $id);
                $csv = wp_nonce_url(admin_url('admin-post.php?action=ffla_cpn_batch_csv&batch=' . rawurlencode((string) $id)), 'ffla_cpn_csv_' . $id);
                echo '<tr><td><code>' . esc_html((string) $id) . '</code><br><span class="ffla-cpn-muted">' . esc_html($b['prefix']) . '…</span></td><td>' . esc_html(strtoupper((string) $b['template'])) . '</td><td>' . ($b['category'] ? FFLA_Coupon_Categories::badge((int) $b['category']) : '—') . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
                    . '<td class="num">' . esc_html(number_format_i18n($usage['total'])) . '</td><td class="num">' . esc_html(number_format_i18n($usage['used'])) . ($usage['total'] ? ' <span class="ffla-cpn-muted">(' . esc_html(number_format_i18n($usage['used'] / $usage['total'] * 100, 0)) . '%)</span>' : '') . '</td>'
                    . '<td>' . esc_html(wp_date(get_option('date_format'), (int) $b['created'])) . '</td><td><a class="button" href="' . esc_url($csv) . '">' . esc_html__('Download CSV', 'ffl-funnels-addons') . '</a></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div></div>';
    }

    public static function handle_generate(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        check_admin_referer('ffla_cpn_generate');
        try {
            $result = self::generate(
                new WC_Coupon(absint($_POST['template'] ?? 0)),
                absint($_POST['count'] ?? 0),
                sanitize_text_field(wp_unslash($_POST['prefix'] ?? '')),
                absint($_POST['length'] ?? 8),
                absint($_POST['usage_limit'] ?? 1),
                sanitize_text_field(wp_unslash($_POST['expires'] ?? '')),
                absint($_POST['category'] ?? 0)
            );
            FFLA_Coupon_Admin::flash('success', sprintf(
                /* translators: 1: count, 2: batch */
                __('%1$d codes created in batch %2$s.', 'ffl-funnels-addons'),
                count($result['codes']),
                $result['batch']
            ));
        } catch (Throwable $e) {
            FFLA_Coupon_Admin::flash('error', $e->getMessage());
        }
        wp_safe_redirect(admin_url('admin.php?page=' . Smart_Coupons_Module::PAGE_CODES));
        exit;
    }

    public static function handle_csv(): void
    {
        $batch = sanitize_key(wp_unslash($_GET['batch'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        check_admin_referer('ffla_cpn_csv_' . $batch);
        $ids = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_ffla_batch', 'meta_value' => $batch, 'orderby' => 'ID', 'order' => 'ASC']); // phpcs:ignore WordPress.DB.SlowDBQuery
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="coupons-' . $batch . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Code', 'Link', 'Used', 'Usage count', 'Usage limit', 'Expires'], ',', '"', '\\');
        foreach ($ids as $id) {
            $c = new WC_Coupon((int) $id);
            $expires = $c->get_date_expires();
            fputcsv($out, [strtoupper($c->get_code()), self::link_url($c->get_code()), $c->get_usage_count() > 0 ? 'yes' : 'no', $c->get_usage_count(), $c->get_usage_limit() ?: 'unlimited', $expires ? $expires->date('Y-m-d') : ''], ',', '"', '\\');
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }
}
