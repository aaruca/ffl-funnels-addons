<?php
/**
 * WooBooster Analytics — Dashboard & Queries.
 *
 * Displays revenue, conversion, and performance metrics for
 * products sold via WooBooster recommendations.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Analytics
{

    /**
     * Render the analytics dashboard.
     */
    public function render_dashboard()
    {
        $range = $this->get_date_range();
        $stats = $this->get_stats($range['from'], $range['to']);
        $top_rules = $this->get_top_rules($range['from'], $range['to'], 10);
        $top_products = $this->get_top_products($range['from'], $range['to'], 10);
        $conversion = $this->get_conversion_rate($range['from'], $range['to']);
        $daily = $this->get_daily_revenue($range['from'], $range['to']);

        // Enqueue Chart.js from CDN (loaded in footer).
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', array(), '4.4.7', true);

        // Enqueue our analytics chart initializer.
        $module_url = FFLA_URL . 'modules/woobooster/';
        wp_enqueue_script('woobooster-analytics-chart', $module_url . 'admin/js/woobooster-analytics.js', array('chartjs'), FFLA_VERSION, true);

        // Pass the daily revenue data to JS.
        wp_localize_script('woobooster-analytics-chart', 'WBAnalyticsChart', array(
            'labels' => $daily['labels'],
            'total' => $daily['total'],
            'wb' => $daily['wb'],
            'currency' => function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol()) : '$',
        ));

        $this->render_date_filter($range);
        $this->render_chart();
        $this->render_stat_cards($stats, $conversion);
        $this->render_top_rules_table($top_rules);
        $this->render_top_products_table($top_products);
    }

    /**
     * Get the date range from query params or default to last 30 days.
     *
     * @return array ['from' => string, 'to' => string]
     */
    private function get_date_range()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $from = isset($_GET['wb_from']) ? sanitize_text_field($_GET['wb_from']) : '';
        $to = isset($_GET['wb_to']) ? sanitize_text_field($_GET['wb_to']) : '';
        // phpcs:enable

        if (!$from || !strtotime($from)) {
            $from = gmdate('Y-m-d', strtotime('-30 days'));
        }
        if (!$to || !strtotime($to)) {
            $to = gmdate('Y-m-d');
        }

        return array('from' => $from, 'to' => $to);
    }

    /**
     * Render the date range filter form.
     *
     * @param array $range Current date range.
     */
    private function render_date_filter($range)
    {
        $page_url = admin_url('admin.php?page=ffla-woobooster-analytics');
        ?>
        <div class="wb-card" style="margin-bottom: 20px;">
            <div class="wb-card__body" style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
                    style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin:0;">
                    <input type="hidden" name="page" value="ffla-woobooster-analytics">
                    <label class="wb-field__label" style="margin:0;"><?php esc_html_e('From', 'ffl-funnels-addons'); ?></label>
                    <input type="date" name="wb_from" value="<?php echo esc_attr($range['from']); ?>"
                        class="wb-input wb-input--sm" style="width:160px;">
                    <label class="wb-field__label" style="margin:0;"><?php esc_html_e('To', 'ffl-funnels-addons'); ?></label>
                    <input type="date" name="wb_to" value="<?php echo esc_attr($range['to']); ?>" class="wb-input wb-input--sm"
                        style="width:160px;">
                    <button type="submit"
                        class="wb-btn wb-btn--primary wb-btn--sm"><?php esc_html_e('Filter', 'ffl-funnels-addons'); ?></button>
                </form>
                <div style="margin-left:auto; display:flex; gap:8px;">
                    <?php
                    $presets = array(
                        '7' => __('7d', 'ffl-funnels-addons'),
                        '30' => __('30d', 'ffl-funnels-addons'),
                        '90' => __('90d', 'ffl-funnels-addons'),
                    );
                    foreach ($presets as $days => $label) {
                        $preset_from = gmdate('Y-m-d', strtotime("-{$days} days"));
                        $preset_to = gmdate('Y-m-d');
                        $url = add_query_arg(array('page' => 'ffla-woobooster-analytics', 'wb_from' => $preset_from, 'wb_to' => $preset_to), admin_url('admin.php'));
                        echo '<a href="' . esc_url($url) . '" class="wb-btn wb-btn--subtle wb-btn--sm">' . esc_html($label) . '</a>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the 4 stat cards.
     *
     * @param array $stats      Stats data.
     * @param array $conversion Conversion data.
     */
    private function render_stat_cards($stats, $conversion)
    {
        ?>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;">
            <?php $this->render_card(__('WB Net Revenue', 'ffl-funnels-addons'), wc_price($stats['net_revenue'])); ?>
            <?php $this->render_card(__('Tax Generated', 'ffl-funnels-addons'), wc_price($stats['tax_revenue'])); ?>
            <?php $this->render_card(__('Items Sold', 'ffl-funnels-addons'), number_format_i18n($stats['items_sold'])); ?>
            <?php
            $pct = $stats['total_revenue'] > 0
                ? round(($stats['net_revenue'] / $stats['total_revenue']) * 100, 1)
                : 0;
            $this->render_card(__('% of Total Revenue', 'ffl-funnels-addons'), $pct . '%');
            ?>
        </div>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;">
            <?php $this->render_card(__('Add-to-Cart', 'ffl-funnels-addons'), number_format_i18n($conversion['add_to_cart'])); ?>
            <?php $this->render_card(__('Conversion Rate', 'ffl-funnels-addons'), $conversion['rate'] . '%'); ?>
        </div>
        <?php
    }

    /**
     * Render a single stat card.
     *
     * @param string $label Stat label.
     * @param string $value Stat value (already formatted).
     */
    private function render_card($label, $value)
    {
        ?>
        <div class="wb-card">
            <div class="wb-card__body" style="text-align:center; padding:20px;">
                <div style="font-size:var(--wb-font-size-sm); color:var(--wb-color-neutral-foreground-3); margin-bottom:8px;">
                    <?php echo esc_html($label); ?>
                </div>
                <div
                    style="font-size:var(--wb-font-size-xl); font-weight:var(--wb-font-weight-semibold); color:var(--wb-color-neutral-foreground-1);">
                    <?php echo wp_kses_post($value); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Top Rules table.
     *
     * @param array $rules Top rules data.
     */
    private function render_top_rules_table($rules)
    {
        ?>
        <div class="wb-card" style="margin-bottom:20px;">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Top Rules', 'ffl-funnels-addons'); ?></h2>
            </div>
            <div class="wb-card__body wb-card__body--table">
                <?php if (empty($rules)): ?>
                    <p style="padding:16px; color:var(--wb-color-neutral-foreground-3);">
                        <?php esc_html_e('No data yet. Recommendations need to generate sales to appear here.', 'ffl-funnels-addons'); ?>
                    </p>
                <?php else: ?>
                    <table class="wb-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Rule', 'ffl-funnels-addons'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Revenue', 'ffl-funnels-addons'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Items', 'ffl-funnels-addons'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $row): ?>
                                <tr>
                                    <td><?php echo esc_html($row['name']); ?></td>
                                    <td style="text-align:right;"><?php echo wp_kses_post(wc_price($row['revenue'])); ?></td>
                                    <td style="text-align:right;"><?php echo esc_html(number_format_i18n($row['items'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Top Products table.
     *
     * @param array $products Top products data.
     */
    private function render_top_products_table($products)
    {
        ?>
        <div class="wb-card">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Top Products', 'ffl-funnels-addons'); ?></h2>
            </div>
            <div class="wb-card__body wb-card__body--table">
                <?php if (empty($products)): ?>
                    <p style="padding:16px; color:var(--wb-color-neutral-foreground-3);">
                        <?php esc_html_e('No data yet. Recommendations need to generate sales to appear here.', 'ffl-funnels-addons'); ?>
                    </p>
                <?php else: ?>
                    <table class="wb-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Product', 'ffl-funnels-addons'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Revenue', 'ffl-funnels-addons'); ?></th>
                                <th style="text-align:right;"><?php esc_html_e('Times Sold', 'ffl-funnels-addons'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $row): ?>
                                <tr>
                                    <td><?php echo esc_html($row['name']); ?></td>
                                    <td style="text-align:right;"><?php echo wp_kses_post(wc_price($row['revenue'])); ?></td>
                                    <td style="text-align:right;"><?php echo esc_html(number_format_i18n($row['count'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the revenue chart canvas.
     */
    private function render_chart()
    {
        ?>
        <div class="wb-card" style="margin-bottom: 20px;">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Revenue Overview', 'ffl-funnels-addons'); ?></h2>
            </div>
            <div class="wb-card__body">
                <div style="position:relative; height:320px; width:100%;">
                    <canvas id="wb-revenue-chart"></canvas>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get daily revenue breakdown: total store vs WooBooster-attributed.
     *
     * @param string $date_from Start date (Y-m-d).
     * @param string $date_to   End date (Y-m-d).
     * @return array ['labels' => [...], 'total' => [...], 'wb' => [...]]
     */
    public function get_daily_revenue($date_from, $date_to)
    {
        $result = array('labels' => array(), 'total' => array(), 'wb' => array());

        // Build day buckets.
        $start = new DateTime($date_from);
        $end = new DateTime($date_to);
        $end->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);

        $day_totals = array();
        $day_wb = array();

        foreach ($period as $dt) {
            $key = $dt->format('Y-m-d');
            $day_totals[$key] = 0;
            $day_wb[$key] = 0;
        }

        // Query completed/processing orders in range.
        $orders = wc_get_orders(array(
            'status' => array('wc-completed', 'wc-processing'),
            'date_created' => $date_from . '...' . $date_to . ' 23:59:59',
            'limit' => -1,
            'return' => 'ids',
        ));

        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            $day_key = $order->get_date_created()->format('Y-m-d');
            if (!isset($day_totals[$day_key])) {
                continue;
            }

            $day_totals[$day_key] += (float) $order->get_subtotal();

            foreach ($order->get_items() as $item) {
                if ($item->get_meta('_wb_source_rule')) {
                    $day_wb[$day_key] += (float) $item->get_subtotal();
                }
            }
        }

        // Flatten into indexed arrays.
        foreach ($day_totals as $label => $total) {
            // Use short date format for labels (e.g. "Feb 20").
            $result['labels'][] = gmdate('M j', strtotime($label));
            $result['total'][] = round($total, 2);
            $result['wb'][] = round($day_wb[$label], 2);
        }

        return $result;
    }

    /**
     * Get aggregated stats for the date range.
     *
     * @param string $date_from Start date (Y-m-d).
     * @param string $date_to   End date (Y-m-d).
     * @return array
     */
    public function get_stats($date_from, $date_to)
    {
        $result = array(
            'net_revenue' => 0,
            'tax_revenue' => 0,
            'items_sold' => 0,
            'total_revenue' => 0,
        );

        $orders = wc_get_orders(array(
            'status' => array('wc-completed', 'wc-processing'),
            'date_created' => $date_from . '...' . $date_to . ' 23:59:59',
            'limit' => -1,
            'return' => 'ids',
        ));

        if (empty($orders)) {
            return $result;
        }

        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            $result['total_revenue'] += (float) $order->get_subtotal();

            foreach ($order->get_items() as $item) {
                $rule_id = $item->get_meta('_wb_source_rule');
                if ($rule_id) {
                    $result['net_revenue'] += (float) $item->get_subtotal();
                    $result['tax_revenue'] += (float) $item->get_subtotal_tax();
                    $result['items_sold'] += (int) $item->get_quantity();
                }
            }
        }

        return $result;
    }

    /**
     * Get top rules by revenue.
     *
     * @param string $date_from Start date.
     * @param string $date_to   End date.
     * @param int    $limit     Max rules to return.
     * @return array
     */
    public function get_top_rules($date_from, $date_to, $limit = 10)
    {
        $orders = wc_get_orders(array(
            'status' => array('wc-completed', 'wc-processing'),
            'date_created' => $date_from . '...' . $date_to . ' 23:59:59',
            'limit' => -1,
            'return' => 'ids',
        ));

        if (empty($orders)) {
            return array();
        }

        $rules_data = array();

        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            foreach ($order->get_items() as $item) {
                $rule_id = $item->get_meta('_wb_source_rule');
                if (!$rule_id) {
                    continue;
                }

                $rule_id = absint($rule_id);
                if (!isset($rules_data[$rule_id])) {
                    $rules_data[$rule_id] = array('revenue' => 0, 'items' => 0);
                }

                $rules_data[$rule_id]['revenue'] += (float) $item->get_subtotal();
                $rules_data[$rule_id]['items'] += (int) $item->get_quantity();
            }
        }

        // Sort by revenue descending.
        arsort($rules_data);
        $rules_data = array_slice($rules_data, 0, $limit, true);

        // Resolve rule names.
        $result = array();
        foreach ($rules_data as $rule_id => $data) {
            $rule = WooBooster_Rule::get($rule_id);
            $name = $rule ? $rule->name : sprintf(__('Rule #%d (deleted)', 'ffl-funnels-addons'), $rule_id);

            $result[] = array(
                'rule_id' => $rule_id,
                'name' => $name,
                'revenue' => $data['revenue'],
                'items' => $data['items'],
            );
        }

        return $result;
    }

    /**
     * Get top products sold via recommendations.
     *
     * @param string $date_from Start date.
     * @param string $date_to   End date.
     * @param int    $limit     Max products to return.
     * @return array
     */
    public function get_top_products($date_from, $date_to, $limit = 10)
    {
        $orders = wc_get_orders(array(
            'status' => array('wc-completed', 'wc-processing'),
            'date_created' => $date_from . '...' . $date_to . ' 23:59:59',
            'limit' => -1,
            'return' => 'ids',
        ));

        if (empty($orders)) {
            return array();
        }

        $products_data = array();

        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }

            foreach ($order->get_items() as $item) {
                $rule_id = $item->get_meta('_wb_source_rule');
                if (!$rule_id) {
                    continue;
                }

                $product_id = $item->get_product_id();
                if (!isset($products_data[$product_id])) {
                    $products_data[$product_id] = array('revenue' => 0, 'count' => 0);
                }

                $products_data[$product_id]['revenue'] += (float) $item->get_subtotal();
                $products_data[$product_id]['count'] += (int) $item->get_quantity();
            }
        }

        // Sort by revenue descending.
        arsort($products_data);
        $products_data = array_slice($products_data, 0, $limit, true);

        // Resolve product names.
        $result = array();
        foreach ($products_data as $product_id => $data) {
            $product = wc_get_product($product_id);
            $name = $product ? $product->get_name() : sprintf(__('Product #%d', 'ffl-funnels-addons'), $product_id);

            $result[] = array(
                'product_id' => $product_id,
                'name' => $name,
                'revenue' => $data['revenue'],
                'count' => $data['count'],
            );
        }

        return $result;
    }

    /**
     * Get conversion rate: add-to-cart vs completed purchases.
     *
     * @param string $date_from Start date.
     * @param string $date_to   End date.
     * @return array
     */
    public function get_conversion_rate($date_from, $date_to)
    {
        // Count add-to-cart events from the monthly counter.
        $counter = get_option(WooBooster_Tracker::COUNTER_OPTION, array());
        $atc_total = 0;

        // Build list of months in range.
        $start = new DateTime($date_from);
        $end = new DateTime($date_to);
        $end->modify('first day of next month');

        $interval = new DateInterval('P1M');
        $period = new DatePeriod($start->modify('first day of this month'), $interval, $end);

        foreach ($period as $dt) {
            $month_key = $dt->format('Y-m');
            if (isset($counter[$month_key])) {
                foreach ($counter[$month_key] as $count) {
                    $atc_total += absint($count);
                }
            }
        }

        // Count purchased items with attribution.
        $orders = wc_get_orders(array(
            'status' => array('wc-completed', 'wc-processing'),
            'date_created' => $date_from . '...' . $date_to . ' 23:59:59',
            'limit' => -1,
            'return' => 'ids',
        ));

        $purchased = 0;
        foreach ($orders as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            foreach ($order->get_items() as $item) {
                if ($item->get_meta('_wb_source_rule')) {
                    $purchased += (int) $item->get_quantity();
                }
            }
        }

        $rate = $atc_total > 0 ? round(($purchased / $atc_total) * 100, 1) : 0;

        return array(
            'add_to_cart' => $atc_total,
            'purchased' => $purchased,
            'rate' => $rate,
        );
    }
}
