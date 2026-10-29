<?php
/**
 * FFL Funnels → Product Reviews → Review requests: what happened to the
 * requests of recent orders (scheduled, sent, opened, clicked, reviewed,
 * skipped and why), with totals for the last 30 days.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Requests_Log
{
    const PAGE = 'ffla-product-reviews-requests';

    const PER_PAGE = 25;

    /** Orders looked at for the 30-day totals. */
    const STATS_LIMIT = 1000;

    /**
     * Orders with any request activity: scheduled, sent by hand, or skipped
     * with a reason.
     *
     * @return array<string, mixed>
     */
    private static function activity_meta_query(): array
    {
        return [
            'relation' => 'OR',
            ['key' => Product_Reviews_Email::SCHEDULED_META, 'compare' => 'EXISTS'],
            ['key' => Product_Reviews_Email::LAST_SENT_META, 'compare' => 'EXISTS'],
            ['key' => Product_Reviews_Email::SKIPPED_META, 'compare' => 'EXISTS'],
        ];
    }

    /**
     * Totals over the orders created in the last 30 days that have request
     * activity.
     *
     * @return array<string, int>
     */
    public static function stats(): array
    {
        $orders = wc_get_orders([
            'limit'        => self::STATS_LIMIT,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'date_created' => '>' . (time() - 30 * DAY_IN_SECONDS),
            'meta_query'   => self::activity_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ]);

        $out = ['scheduled' => 0, 'sent' => 0, 'opened' => 0, 'clicked' => 0, 'reviewed' => 0, 'skipped' => 0, 'reviews' => 0];
        foreach ((array) $orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $status = Product_Reviews_Email::request_status($order);
            if ($status['sent'] > 0) {
                $out['sent']++;
            }
            if ($status['opened'] > 0) {
                $out['opened']++;
            }
            if ($status['clicked'] > 0) {
                $out['clicked']++;
            }
            if ($status['reviewed'] > 0) {
                $out['reviewed']++;
                $out['reviews'] += $status['reviewed'];
            }
            if ('scheduled' === $status['key']) {
                $out['scheduled']++;
            }
            if ('skipped' === $status['key']) {
                $out['skipped']++;
            }
        }

        return $out;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen filters.
        $paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $filter = isset($_GET['request_status']) ? sanitize_key(wp_unslash($_GET['request_status'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $stats = self::stats();
        $rate  = static function (int $part, int $whole): string {
            return $whole > 0 ? number_format_i18n(round(($part / $whole) * 100)) . '%' : '–';
        };

        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Last 30 days', 'ffl-funnels-addons') . '</h3>'
            . '<p>' . esc_html__('Orders placed in the last 30 days that have a review request.', 'ffl-funnels-addons') . '</p></div>';
        echo '<div class="wb-card__body"><div class="ffla-pr-stats">';
        $tiles = [
            [__('Sent', 'ffl-funnels-addons'), number_format_i18n($stats['sent']), ''],
            [__('Opened', 'ffl-funnels-addons'), number_format_i18n($stats['opened']), $rate($stats['opened'], $stats['sent'])],
            [__('Review page opened', 'ffl-funnels-addons'), number_format_i18n($stats['clicked']), $rate($stats['clicked'], $stats['sent'])],
            [__('Reviewed', 'ffl-funnels-addons'), number_format_i18n($stats['reviewed']), $rate($stats['reviewed'], $stats['sent'])],
            [__('Reviews written', 'ffl-funnels-addons'), number_format_i18n($stats['reviews']), ''],
            [__('Scheduled', 'ffl-funnels-addons'), number_format_i18n($stats['scheduled']), ''],
            [__('Skipped', 'ffl-funnels-addons'), number_format_i18n($stats['skipped']), ''],
        ];
        foreach ($tiles as $tile) {
            echo '<div class="ffla-pr-stat"><span class="ffla-pr-stat__label">' . esc_html($tile[0]) . '</span>'
                . '<span class="ffla-pr-stat__value">' . esc_html($tile[1]) . '</span>'
                . ($tile[2] !== '' ? '<span class="ffla-pr-stat__rate">' . esc_html(sprintf(
                    /* translators: %s: percentage of sent requests */
                    __('%s of sent', 'ffl-funnels-addons'),
                    $tile[2]
                )) . '</span>' : '')
                . '</div>';
        }
        echo '</div>';
        if (Product_Reviews_Tracking::enabled()) {
            echo '<p class="wb-field__desc">' . esc_html__('Opens and clicks are approximate: some mail apps block images, and company mail scanners open links on their own.', 'ffl-funnels-addons') . '</p>';
        } else {
            echo '<p class="wb-field__desc">' . esc_html__('Open and click tracking is off (Product Reviews → Emails).', 'ffl-funnels-addons') . '</p>';
        }
        echo '</div></div>';

        $query = [
            'limit'      => self::PER_PAGE,
            'paged'      => $paged,
            'paginate'   => true,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => self::activity_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ];
        $result = wc_get_orders($query);
        $orders = is_object($result) && isset($result->orders) ? (array) $result->orders : (array) $result;
        $pages  = is_object($result) && isset($result->max_num_pages) ? (int) $result->max_num_pages : 1;

        $filters = [
            ''          => __('All', 'ffl-funnels-addons'),
            'scheduled' => __('Scheduled', 'ffl-funnels-addons'),
            'sent'      => __('Sent, not opened', 'ffl-funnels-addons'),
            'opened'    => __('Opened', 'ffl-funnels-addons'),
            'clicked'   => __('Clicked', 'ffl-funnels-addons'),
            'reviewed'  => __('Reviewed', 'ffl-funnels-addons'),
            'skipped'   => __('Skipped', 'ffl-funnels-addons'),
            'failed'    => __('Failed', 'ffl-funnels-addons'),
        ];

        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Review requests', 'ffl-funnels-addons') . '</h3>';
        echo '<nav class="ffla-pr-tabs ffla-pr-tabs--small" aria-label="' . esc_attr__('Filter by status', 'ffl-funnels-addons') . '">';
        foreach ($filters as $key => $label) {
            $url = add_query_arg(['page' => self::PAGE, 'request_status' => $key], admin_url('admin.php'));
            echo '<a class="ffla-pr-tabs__tab' . ($key === $filter ? ' is-current' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav></div>';
        echo '<div class="wb-card__body wb-card__body--table">';

        $rows = [];
        foreach ($orders as $order) {
            if (!$order instanceof \WC_Order) {
                continue;
            }
            $status = Product_Reviews_Email::request_status($order);
            if ($filter !== '' && $status['key'] !== $filter) {
                continue;
            }
            $rows[] = [$order, $status];
        }

        if (empty($rows)) {
            echo '<div class="wb-empty-state"><p>' . esc_html__('No review requests here yet.', 'ffl-funnels-addons') . '</p></div>';
        } else {
            $date = static function (int $ts): string {
                return $ts > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $ts) : '–';
            };

            echo '<table class="wb-table"><thead><tr>';
            foreach ([__('Order', 'ffl-funnels-addons'), __('Customer', 'ffl-funnels-addons'), __('Status', 'ffl-funnels-addons'), __('Scheduled for', 'ffl-funnels-addons'), __('Sent', 'ffl-funnels-addons'), __('Opened', 'ffl-funnels-addons'), __('Review page', 'ffl-funnels-addons'), __('Reviews', 'ffl-funnels-addons'), __('Reminder', 'ffl-funnels-addons')] as $head) {
                echo '<th>' . esc_html($head) . '</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($rows as [$order, $status]) {
                $edit = method_exists($order, 'get_edit_order_url') ? $order->get_edit_order_url() : admin_url('post.php?post=' . $order->get_id() . '&action=edit');
                echo '<tr>';
                echo '<td><a class="wb-link--strong" href="' . esc_url($edit) . '">#' . esc_html($order->get_order_number()) . '</a></td>';
                echo '<td>' . esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name())) . '<br><span class="wb-text--muted">' . esc_html($order->get_billing_email()) . '</span></td>';
                echo '<td><span class="ffla-pr-status ffla-pr-status--' . esc_attr($status['key']) . '">' . esc_html($status['label']) . '</span></td>';
                echo '<td>' . esc_html('scheduled' === $status['key'] ? $date($status['scheduled_for']) : '–') . '</td>';
                echo '<td>' . esc_html($date($status['sent'])) . '</td>';
                echo '<td>' . esc_html($date($status['opened'])) . '</td>';
                echo '<td>' . esc_html($date($status['clicked'])) . '</td>';
                echo '<td>' . esc_html($status['reviewed'] > 0 ? number_format_i18n($status['reviewed']) : '–') . '</td>';
                echo '<td>' . esc_html($date($status['reminder'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        if ($pages > 1) {
            echo '<div class="wb-card__footer">';
            for ($p = 1; $p <= $pages; $p++) {
                $url = add_query_arg(['page' => self::PAGE, 'paged' => $p, 'request_status' => $filter], admin_url('admin.php'));
                echo $p === $paged
                    ? '<span class="wb-btn wb-btn--xs wb-btn--primary">' . esc_html((string) $p) . '</span>'
                    : '<a class="wb-btn wb-btn--xs wb-btn--subtle" href="' . esc_url($url) . '">' . esc_html((string) $p) . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
}
