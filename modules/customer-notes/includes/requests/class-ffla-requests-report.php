<?php
/**
 * Customer requests — report (WooCommerce → Requests → Report) and CSV export.
 *
 * Requests opened in the chosen period: volume, open now, time to close and
 * to first staff reply (medians), refunded amount, ratings, top reasons,
 * outcomes, and products by returned units with their return rate (returned ÷
 * sold in the same period, from WooCommerce's order analytics table).
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Report
{
    const LIMIT = 5000;

    public static function periods(): array
    {
        return [
            '30'  => __('Last 30 days', 'ffl-funnels-addons'),
            '90'  => __('Last 90 days', 'ffl-funnels-addons'),
            '365' => __('Last 12 months', 'ffl-funnels-addons'),
            'all' => __('All time', 'ffl-funnels-addons'),
        ];
    }

    private static function period(): string
    {
        $period = isset($_GET['period']) ? sanitize_key(wp_unslash($_GET['period'])) : '90'; // phpcs:ignore WordPress.Security.NonceVerification
        return isset(self::periods()[$period]) ? $period : '90';
    }

    private static function since(string $period): ?string
    {
        return 'all' === $period ? null : gmdate('Y-m-d H:i:s', time() - (int) $period * DAY_IN_SECONDS);
    }

    private static function rows(string $period): array
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $since = self::since($period);
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        return (array) ($since
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE created_at >= %s ORDER BY id DESC LIMIT %d", $since, self::LIMIT))
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['requests']} ORDER BY id DESC LIMIT %d", self::LIMIT)));
        // phpcs:enable
    }

    private static function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $n = count($values);
        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    public static function data(string $period): array
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $rows = self::rows($period);

        $out = [
            'total' => count($rows), 'issues' => 0, 'returns' => 0, 'open' => 0, 'closed' => 0,
            'close_hours' => null, 'reply_hours' => null, 'refunded' => 0.0,
            'rating_avg' => null, 'rating_count' => 0, 'ratings' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'reasons' => [], 'resolutions' => [], 'products' => [], 'has_sales' => false, 'capped' => count($rows) >= self::LIMIT,
        ];
        $close = [];
        $ratings = [];
        $ids = [];
        $created = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r->id;
            $created[(int) $r->id] = strtotime($r->created_at . ' UTC');
            $out['return' === $r->type ? 'returns' : 'issues']++;
            if (FFLA_Requests::is_open($r->status)) {
                $out['open']++;
            } else {
                $out['closed']++;
                if ($r->closed_at) {
                    $close[] = max(0, strtotime($r->closed_at . ' UTC') - $created[(int) $r->id]) / HOUR_IN_SECONDS;
                }
                if ($r->resolution) {
                    $out['resolutions'][$r->resolution] = ($out['resolutions'][$r->resolution] ?? 0) + 1;
                }
            }
            $out['refunded'] += (float) $r->refund_total;
            if ((int) $r->rating) {
                $ratings[] = (int) $r->rating;
                $out['ratings'][(int) $r->rating]++;
            }
            $key = $r->type . ':' . $r->reason;
            $out['reasons'][$key] = ($out['reasons'][$key] ?? 0) + 1;

            foreach (FFLA_Requests::items($r) as $line) {
                $pid = (int) ($line['product_id'] ?? 0);
                if (!$pid) {
                    continue;
                }
                if (!isset($out['products'][$pid])) {
                    $out['products'][$pid] = ['name' => (string) $line['name'], 'returned' => 0, 'returns' => 0, 'issues' => 0, 'sold' => null];
                }
                if ('return' === $r->type) {
                    $out['products'][$pid]['returned'] += (int) $line['qty'];
                    $out['products'][$pid]['returns']++;
                } else {
                    $out['products'][$pid]['issues']++;
                }
            }
        }
        $out['close_hours'] = self::median($close);
        if ($ratings) {
            $out['rating_avg'] = array_sum($ratings) / count($ratings);
            $out['rating_count'] = count($ratings);
        }
        arsort($out['reasons']);
        arsort($out['resolutions']);

        // First public staff action per request.
        if ($ids) {
            $first = (array) $wpdb->get_results( // phpcs:ignore
                "SELECT request_id, MIN(created_at) AS first_at FROM {$t['events']} WHERE actor_type = 'staff' AND is_public = 1 AND kind IN ('message','status','resolution','refund','tracking') AND request_id IN (" . implode(',', array_map('intval', $ids)) . ') GROUP BY request_id'
            );
            $reply = [];
            foreach ($first as $row) {
                if (isset($created[(int) $row->request_id])) {
                    $reply[] = max(0, strtotime($row->first_at . ' UTC') - $created[(int) $row->request_id]) / HOUR_IN_SECONDS;
                }
            }
            $out['reply_hours'] = self::median($reply);
        }

        // Products: most returned units first, then most issues.
        uasort($out['products'], static function ($a, $b) {
            return [$b['returned'], $b['issues']] <=> [$a['returned'], $a['issues']];
        });
        $out['products'] = array_slice($out['products'], 0, 20, true);

        // Units sold in the same period (WooCommerce analytics lookup table).
        $lookup = $wpdb->prefix . 'wc_order_product_lookup';
        if ($out['products'] && $lookup === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup))) {
            $out['has_sales'] = true;
            $pids = implode(',', array_map('intval', array_keys($out['products'])));
            $since = self::since($period);
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
            $sold = (array) ($since
                ? $wpdb->get_results($wpdb->prepare("SELECT product_id, SUM(product_qty) AS qty FROM {$lookup} WHERE product_id IN ({$pids}) AND date_created >= %s GROUP BY product_id", get_date_from_gmt($since)))
                : $wpdb->get_results("SELECT product_id, SUM(product_qty) AS qty FROM {$lookup} WHERE product_id IN ({$pids}) GROUP BY product_id"));
            // phpcs:enable
            foreach ($sold as $row) {
                if (isset($out['products'][(int) $row->product_id])) {
                    $out['products'][(int) $row->product_id]['sold'] = (int) $row->qty;
                }
            }
        }

        return $out;
    }

    private static function duration(?float $hours): string
    {
        if (null === $hours) {
            return '—';
        }
        if ($hours < 1) {
            /* translators: %d: minutes */
            return sprintf(__('%d min', 'ffl-funnels-addons'), max(1, (int) round($hours * 60)));
        }
        if ($hours < 48) {
            /* translators: %s: hours */
            return sprintf(__('%s h', 'ffl-funnels-addons'), number_format_i18n($hours, 1));
        }
        /* translators: %s: days */
        return sprintf(__('%s days', 'ffl-funnels-addons'), number_format_i18n($hours / 24, 1));
    }

    private static function bar(int $value, int $max, string $label): string
    {
        $pct = $max && $value ? max(2, round($value / $max * 100)) : 0;
        return '<div class="ffla-req-bar" role="img" aria-label="' . esc_attr($label) . '"><span style="width:' . (int) $pct . '%"></span></div>';
    }

    private static function tile(string $label, string $value, string $sub = ''): string
    {
        return '<div class="ffla-req-kpi"><span class="label">' . esc_html($label) . '</span><span class="value">' . esc_html($value) . '</span>'
            . ('' !== $sub ? '<span class="sub">' . esc_html($sub) . '</span>' : '') . '</div>';
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        FFLA_Requests::maybe_install_now();
        $period = self::period();
        $d = self::data($period);
        $base = admin_url('admin.php?page=' . FFLA_Requests_Admin::SLUG . '&screen=report');

        echo '<div class="wrap ffla-req-admin ffla-req-report"><h1 class="wp-heading-inline">' . esc_html__('Requests report', 'ffl-funnels-addons') . '</h1> '
            . '<a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=' . FFLA_Requests_Admin::SLUG)) . '">' . esc_html__('← All requests', 'ffl-funnels-addons') . '</a><hr class="wp-header-end">';

        echo '<ul class="subsubsub">';
        $links = [];
        foreach (self::periods() as $key => $label) {
            $links[] = '<li><a href="' . esc_url(add_query_arg('period', $key, $base)) . '"' . ($period === $key ? ' class="current" aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo implode(' | </li>', $links) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="float:right"><input type="hidden" name="action" value="ffla_req_export"><input type="hidden" name="period" value="' . esc_attr($period) . '">';
        wp_nonce_field('ffla_req_export');
        echo '<button type="submit" class="button">' . esc_html__('Export CSV', 'ffl-funnels-addons') . '</button></form><br class="clear">';

        if (!$d['total']) {
            echo '<p>' . esc_html__('No requests in this period.', 'ffl-funnels-addons') . '</p></div>';
            return;
        }
        if ($d['capped']) {
            echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf(
                /* translators: %d: limit */
                __('Showing the latest %d requests of this period.', 'ffl-funnels-addons'),
                self::LIMIT
            )) . '</p></div>';
        }

        echo '<div class="ffla-req-kpis">'
            . self::tile(__('Requests opened', 'ffl-funnels-addons'), number_format_i18n($d['total']), sprintf(
                /* translators: 1: issues, 2: returns */
                __('%1$s issues · %2$s returns', 'ffl-funnels-addons'),
                number_format_i18n($d['issues']),
                number_format_i18n($d['returns'])
            ))
            . self::tile(__('Still open', 'ffl-funnels-addons'), number_format_i18n($d['open']), sprintf(
                /* translators: %s: closed */
                __('%s closed', 'ffl-funnels-addons'),
                number_format_i18n($d['closed'])
            ))
            . self::tile(__('First staff reply', 'ffl-funnels-addons'), self::duration($d['reply_hours']), __('median', 'ffl-funnels-addons'))
            . self::tile(__('Time to close', 'ffl-funnels-addons'), self::duration($d['close_hours']), __('median', 'ffl-funnels-addons'))
            . self::tile(__('Refunded from requests', 'ffl-funnels-addons'), html_entity_decode(wp_strip_all_tags(wc_price($d['refunded'])), ENT_QUOTES, 'UTF-8'))
            . self::tile(__('Customer rating', 'ffl-funnels-addons'), null === $d['rating_avg'] ? '—' : number_format_i18n($d['rating_avg'], 1) . ' / 5', sprintf(
                /* translators: %s: count */
                _n('%s rating', '%s ratings', $d['rating_count'], 'ffl-funnels-addons'),
                number_format_i18n($d['rating_count'])
            ))
            . '</div>';

        echo '<div class="ffla-req-report-grid">';

        // Reasons.
        $max = $d['reasons'] ? max($d['reasons']) : 0;
        echo '<div class="ffla-req-box"><h2>' . esc_html__('Top reasons', 'ffl-funnels-addons') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Reason', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Type', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Requests', 'ffl-funnels-addons') . '</th><th style="width:30%"></th></tr></thead><tbody>';
        foreach ($d['reasons'] as $key => $count) {
            [$type, $reason] = explode(':', $key, 2);
            $label = FFLA_Requests::reasons($type)[$reason] ?? $reason;
            echo '<tr><td>' . esc_html($label) . '</td><td>' . esc_html(FFLA_Requests::type_label($type)) . '</td><td class="num">' . esc_html(number_format_i18n($count)) . '</td><td>' . self::bar($count, $max, $label . ': ' . $count) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</tbody></table></div>';

        // Outcomes.
        $max = $d['resolutions'] ? max($d['resolutions']) : 0;
        echo '<div class="ffla-req-box"><h2>' . esc_html__('Outcomes of closed requests', 'ffl-funnels-addons') . '</h2>';
        if ($d['resolutions']) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Outcome', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Requests', 'ffl-funnels-addons') . '</th><th style="width:35%"></th></tr></thead><tbody>';
            foreach ($d['resolutions'] as $key => $count) {
                $label = FFLA_Requests::resolutions()[$key] ?? $key;
                echo '<tr><td>' . esc_html($label) . '</td><td class="num">' . esc_html(number_format_i18n($count)) . '</td><td>' . self::bar($count, $max, $label . ': ' . $count) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="description">' . esc_html__('No closed requests yet.', 'ffl-funnels-addons') . '</p>';
        }
        echo '</div>';

        // Products.
        echo '<div class="ffla-req-box"><h2>' . esc_html__('Products with the most returns and issues', 'ffl-funnels-addons') . '</h2>';
        if ($d['products']) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Product', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Units returned', 'ffl-funnels-addons') . '</th>'
                . ($d['has_sales'] ? '<th class="num">' . esc_html__('Units sold', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Return rate', 'ffl-funnels-addons') . '</th>' : '')
                . '<th class="num">' . esc_html__('Issues', 'ffl-funnels-addons') . '</th></tr></thead><tbody>';
            foreach ($d['products'] as $pid => $p) {
                $rate = $d['has_sales'] && $p['sold'] ? $p['returned'] / $p['sold'] * 100 : null;
                echo '<tr><td><a href="' . esc_url((string) get_edit_post_link($pid)) . '">' . esc_html($p['name']) . '</a></td><td class="num">' . esc_html(number_format_i18n($p['returned'])) . '</td>'
                    . ($d['has_sales'] ? '<td class="num">' . esc_html(null === $p['sold'] ? '—' : number_format_i18n($p['sold'])) . '</td><td class="num">' . esc_html(null === $rate ? '—' : number_format_i18n($rate, 1) . '%') . '</td>' : '')
                    . '<td class="num">' . esc_html(number_format_i18n($p['issues'])) . '</td></tr>';
            }
            echo '</tbody></table>';
            if (!$d['has_sales']) {
                echo '<p class="description">' . esc_html__('Return rates need WooCommerce Analytics data (the order product lookup table).', 'ffl-funnels-addons') . '</p>';
            }
        } else {
            echo '<p class="description">' . esc_html__('No requests with items in this period.', 'ffl-funnels-addons') . '</p>';
        }
        echo '</div>';

        // Ratings.
        $max = max($d['ratings']);
        echo '<div class="ffla-req-box"><h2>' . esc_html__('Ratings', 'ffl-funnels-addons') . '</h2>';
        if ($d['rating_count']) {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Stars', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Ratings', 'ffl-funnels-addons') . '</th><th style="width:45%"></th></tr></thead><tbody>';
            foreach ($d['ratings'] as $stars => $count) {
                echo '<tr><td><span class="ffla-req-stars" aria-hidden="true">' . esc_html(str_repeat('★', $stars) . str_repeat('☆', 5 - $stars)) . '</span> <span class="screen-reader-text">' . esc_html($stars . '/5') . '</span></td><td class="num">' . esc_html(number_format_i18n($count)) . '</td><td>' . self::bar($count, $max, $stars . '/5: ' . $count) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
            echo '</tbody></table>';
        } else {
            echo '<p class="description">' . esc_html__('No ratings yet.', 'ffl-funnels-addons') . '</p>';
        }
        echo '</div></div></div>';
    }

    /** CSV of the period's requests. */
    public static function export(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        check_admin_referer('ffla_req_export');
        $period = isset($_POST['period']) ? sanitize_key(wp_unslash($_POST['period'])) : '90';
        $period = isset(self::periods()[$period]) ? $period : '90';

        // Spreadsheet formula injection guard.
        $cell = static function ($value): string {
            $value = (string) $value;
            return '' !== $value && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
        };

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="customer-requests-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Request', 'Order', 'Type', 'Reason', 'Status', 'Outcome', 'Customer', 'Email', 'Opened', 'Closed', 'Hours to close', 'Refunded', 'Rating', 'Rating comment', 'Return tracking', 'FFL dealer', 'Items']);
        foreach (self::rows($period) as $r) {
            $items = implode('; ', array_map(static function ($l) {
                return $l['name'] . ' x' . (int) $l['qty'];
            }, FFLA_Requests::items($r)));
            $ffl = FFLA_Requests::ffl($r);
            fputcsv($out, array_map($cell, [
                $r->number,
                $r->order_number,
                $r->type,
                FFLA_Requests::reasons($r->type)[$r->reason] ?? $r->reason,
                FFLA_Requests::status_label($r->status),
                $r->resolution ? (FFLA_Requests::resolutions()[$r->resolution] ?? $r->resolution) : '',
                $r->customer_name,
                $r->customer_email,
                FFLA_Requests::local_time($r->created_at, 'Y-m-d H:i'),
                FFLA_Requests::local_time($r->closed_at, 'Y-m-d H:i'),
                $r->closed_at ? round((strtotime($r->closed_at . ' UTC') - strtotime($r->created_at . ' UTC')) / HOUR_IN_SECONDS, 1) : '',
                (float) $r->refund_total ? wc_format_decimal($r->refund_total, wc_get_price_decimals()) : '',
                (int) $r->rating ?: '',
                $r->rating_comment,
                trim((FFLA_Requests::carriers()[$r->return_carrier] ?? '') . ' ' . $r->return_tracking),
                $ffl ? $ffl['name'] . ' ' . FFLA_Requests::format_license((string) $ffl['license']) : '',
                $items,
            ]));
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }
}
