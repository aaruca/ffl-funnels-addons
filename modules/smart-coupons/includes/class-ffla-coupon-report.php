<?php
/**
 * Smart Coupons — report.
 *
 * Paid and processing orders that used a coupon in the period: per category
 * and per coupon, the orders, revenue, discount given, average order value
 * and how many were new customers; plus average order value with and without
 * coupons. Reads WooCommerce Analytics' order and coupon lookup tables.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Coupon_Report
{
    public static function periods(): array
    {
        return [
            '30'  => __('Last 30 days', 'ffl-funnels-addons'),
            '90'  => __('Last 90 days', 'ffl-funnels-addons'),
            '365' => __('Last 12 months', 'ffl-funnels-addons'),
        ];
    }

    private static function tables_ready(): bool
    {
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_order_coupon_lookup';
        return $lookup === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup));
    }

    /**
     * @return array{coupons: array, orders_with: array, orders_without: array}
     */
    public static function data(int $days): array
    {
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_order_coupon_lookup';
        $stats = $wpdb->prefix . 'wc_order_stats';
        $since = wp_date('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $statuses = array_map(static function ($s) { return 'wc-' . $s; }, array_merge(wc_get_is_paid_statuses(), ['on-hold']));
        $in = implode(',', array_fill(0, count($statuses), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $coupons = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT l.coupon_id, COUNT(DISTINCT l.order_id) AS orders, SUM(l.discount_amount) AS discount,
                    SUM(s.total_sales) AS revenue, SUM(CASE WHEN s.returning_customer = 0 THEN 1 ELSE 0 END) AS new_customers
             FROM {$lookup} l JOIN {$stats} s ON s.order_id = l.order_id
             WHERE s.status IN ({$in}) AND s.date_created >= %s AND s.parent_id = 0
             GROUP BY l.coupon_id ORDER BY orders DESC LIMIT 200",
            array_merge($statuses, [$since])
        ), ARRAY_A);
        $with = (array) $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS orders, COALESCE(AVG(s.total_sales), 0) AS aov FROM {$stats} s
             WHERE s.status IN ({$in}) AND s.date_created >= %s AND s.parent_id = 0 AND s.order_id IN (SELECT order_id FROM {$lookup})",
            array_merge($statuses, [$since])
        ), ARRAY_A);
        $without = (array) $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS orders, COALESCE(AVG(s.total_sales), 0) AS aov FROM {$stats} s
             WHERE s.status IN ({$in}) AND s.date_created >= %s AND s.parent_id = 0 AND s.order_id NOT IN (SELECT order_id FROM {$lookup})",
            array_merge($statuses, [$since])
        ), ARRAY_A);
        // phpcs:enable
        return ['coupons' => $coupons, 'orders_with' => $with, 'orders_without' => $without];
    }

    private static function money($amount): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price((float) $amount)), ENT_QUOTES, 'UTF-8');
    }

    public static function page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $days = isset($_GET['period']) ? absint($_GET['period']) : 30; // phpcs:ignore WordPress.Security.NonceVerification
        $days = isset(self::periods()[(string) $days]) ? $days : 30;
        $base = admin_url('admin.php?page=' . Smart_Coupons_Module::PAGE_REPORT);

        echo '<div class="wb-card"><div class="wb-card__body ffla-cpn-report"><p class="ffla-cpn-periods">';
        foreach (self::periods() as $key => $label) {
            echo (string) $days === (string) $key ? '<strong>' . esc_html($label) . '</strong> ' : '<a href="' . esc_url(add_query_arg('period', $key, $base)) . '">' . esc_html($label) . '</a> ';
        }
        echo '</p>';
        if (!self::tables_ready()) {
            echo '<p>' . esc_html__('The coupon report needs WooCommerce Analytics data. Enable WooCommerce Analytics and import historical data (WooCommerce → Settings → Advanced → Features / Analytics).', 'ffl-funnels-addons') . '</p></div></div>';
            return;
        }

        $d = self::data($days);
        $total_orders = array_sum(array_column($d['coupons'], 'orders'));
        $total_discount = array_sum(array_column($d['coupons'], 'discount'));
        $total_revenue = array_sum(array_column($d['coupons'], 'revenue'));
        $new = array_sum(array_column($d['coupons'], 'new_customers'));

        echo '<div class="ffla-cpn-kpis">'
            . self::tile(__('Orders with a coupon', 'ffl-funnels-addons'), number_format_i18n((int) ($d['orders_with']['orders'] ?? 0)), sprintf(
                /* translators: %s: percent */
                __('%s of orders', 'ffl-funnels-addons'),
                self::share((int) ($d['orders_with']['orders'] ?? 0), (int) ($d['orders_with']['orders'] ?? 0) + (int) ($d['orders_without']['orders'] ?? 0))
            ))
            . self::tile(__('Discount given', 'ffl-funnels-addons'), self::money($total_discount))
            . self::tile(__('Revenue from coupon orders', 'ffl-funnels-addons'), self::money($total_revenue))
            . self::tile(__('Average order with / without', 'ffl-funnels-addons'), self::money($d['orders_with']['aov'] ?? 0), sprintf(
                /* translators: %s: amount */
                __('without a coupon: %s', 'ffl-funnels-addons'),
                self::money($d['orders_without']['aov'] ?? 0)
            ))
            . self::tile(__('New customers', 'ffl-funnels-addons'), number_format_i18n($new), sprintf(
                /* translators: %s: percent */
                __('%s of coupon orders', 'ffl-funnels-addons'),
                self::share($new, $total_orders)
            ))
            . '</div></div></div>';

        // By category.
        $by_cat = [];
        $uncategorized = ['orders' => 0, 'discount' => 0.0, 'revenue' => 0.0, 'new_customers' => 0, 'coupons' => 0];
        foreach ($d['coupons'] as $row) {
            $terms = wp_get_object_terms((int) $row['coupon_id'], FFLA_Coupon_Categories::TAX, ['fields' => 'ids']);
            $terms = is_wp_error($terms) || !$terms ? [0] : $terms;
            foreach ($terms as $term_id) {
                $slot = &$by_cat[(int) $term_id];
                $slot = $slot ?? ['orders' => 0, 'discount' => 0.0, 'revenue' => 0.0, 'new_customers' => 0, 'coupons' => 0];
                $slot['orders'] += (int) $row['orders'];
                $slot['discount'] += (float) $row['discount'];
                $slot['revenue'] += (float) $row['revenue'];
                $slot['new_customers'] += (int) $row['new_customers'];
                $slot['coupons']++;
                unset($slot);
            }
        }
        foreach (get_terms(['taxonomy' => FFLA_Coupon_Categories::TAX, 'hide_empty' => false]) as $term) {
            if (FFLA_Coupon_Categories::rules((int) $term->term_id)['budget'] && !isset($by_cat[(int) $term->term_id])) {
                $by_cat[(int) $term->term_id] = $uncategorized;
            }
        }
        uasort($by_cat, static function ($a, $b) {
            return $b['discount'] <=> $a['discount'];
        });

        echo '<div class="wb-card"><div class="wb-card__header"><h2>' . esc_html__('By category', 'ffl-funnels-addons') . '</h2></div><div class="wb-card__body">';
        echo '<table class="widefat striped ffla-cpn-report"><thead><tr><th>' . esc_html__('Category', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Coupons used', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Orders', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Revenue', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Discount', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('New customers', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Budget this month', 'ffl-funnels-addons') . '</th></tr></thead><tbody>';
        foreach ($by_cat as $term_id => $c) {
            $rules = $term_id ? FFLA_Coupon_Categories::rules($term_id) : null;
            $budget = '';
            if ($rules && $rules['budget']) {
                $spent = FFLA_Coupon_Categories::spent_this_month($term_id);
                $pct = min(100, $spent / $rules['budget'] * 100);
                $budget = '<div class="ffla-cpn-meter' . ($spent >= $rules['budget'] ? ' is-full' : '') . '" role="img" aria-label="' . esc_attr(sprintf('%d%%', $pct)) . '"><span style="width:' . esc_attr((string) round($pct)) . '%"></span></div> ' . esc_html(self::money($spent) . ' / ' . self::money($rules['budget']));
            }
            echo '<tr><td>' . ($term_id ? FFLA_Coupon_Categories::badge($term_id) : esc_html__('No category', 'ffl-funnels-addons')) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
                . '<td class="num">' . esc_html(number_format_i18n($c['coupons'])) . '</td><td class="num">' . esc_html(number_format_i18n($c['orders'])) . '</td>'
                . '<td class="num">' . esc_html(self::money($c['revenue'])) . '</td><td class="num">' . esc_html(self::money($c['discount'])) . '</td>'
                . '<td class="num">' . esc_html(number_format_i18n($c['new_customers'])) . '</td><td>' . ($budget ?: '—') . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</tbody></table></div></div>';

        // By coupon.
        echo '<div class="wb-card"><div class="wb-card__header"><h2>' . esc_html__('By coupon', 'ffl-funnels-addons') . '</h2></div><div class="wb-card__body">';
        if (!$d['coupons']) {
            echo '<p>' . esc_html__('No coupon orders in this period.', 'ffl-funnels-addons') . '</p></div></div>';
            return;
        }
        echo '<table class="widefat striped ffla-cpn-report"><thead><tr><th>' . esc_html__('Coupon', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Category', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Orders', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Revenue', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Discount', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Avg. order', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('New customers', 'ffl-funnels-addons') . '</th></tr></thead><tbody>';
        foreach ($d['coupons'] as $row) {
            $id = (int) $row['coupon_id'];
            $code = get_the_title($id) ?: '#' . $id;
            $terms = wp_get_object_terms($id, FFLA_Coupon_Categories::TAX, ['fields' => 'ids']);
            $batch = get_post_meta($id, '_ffla_batch', true);
            echo '<tr><td><a href="' . esc_url((string) get_edit_post_link($id)) . '"><strong>' . esc_html(strtoupper($code)) . '</strong></a>' . ($batch ? ' <span class="ffla-cpn-muted">' . esc_html($batch) . '</span>' : '') . '</td>'
                . '<td>' . (is_wp_error($terms) || !$terms ? '—' : implode(' ', array_map([FFLA_Coupon_Categories::class, 'badge'], array_map('intval', $terms)))) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput
                . '<td class="num">' . esc_html(number_format_i18n((int) $row['orders'])) . '</td><td class="num">' . esc_html(self::money($row['revenue'])) . '</td>'
                . '<td class="num">' . esc_html(self::money($row['discount'])) . '</td><td class="num">' . esc_html(self::money((int) $row['orders'] ? $row['revenue'] / $row['orders'] : 0)) . '</td>'
                . '<td class="num">' . esc_html(number_format_i18n((int) $row['new_customers'])) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    private static function tile(string $label, string $value, string $sub = ''): string
    {
        return '<div class="ffla-cpn-kpi"><span class="label">' . esc_html($label) . '</span><span class="value">' . esc_html($value) . '</span>' . ('' !== $sub ? '<span class="sub">' . esc_html($sub) . '</span>' : '') . '</div>';
    }

    private static function share(int $part, int $whole): string
    {
        return $whole ? number_format_i18n($part / $whole * 100, 0) . '%' : '—';
    }
}
