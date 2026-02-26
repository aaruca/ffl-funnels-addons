<?php
/**
 * WooBooster Analytics — Dashboard & Queries.
 *
 * Displays revenue, conversion, and performance metrics for
 * products sold via WooBooster recommendations.
 *
 * v1.1.1 — Single-pass queries, trend indicators, donut chart,
 *           funnel visual, thumbnails, expanded presets.
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
        $data = $this->compute_all_data($range['from'], $range['to']);

        // Previous period for trend comparison.
        $days_span = max(1, (strtotime($range['to']) - strtotime($range['from'])) / 86400);
        $prev_from = gmdate('Y-m-d', strtotime($range['from'] . " -{$days_span} days"));
        $prev_to = gmdate('Y-m-d', strtotime($range['from'] . ' -1 day'));
        $prev_data = $this->compute_all_data($prev_from, $prev_to);

        // Enqueue Chart.js from CDN (loaded in footer).
        wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js', array(), '4.4.7', true);

        // Enqueue our analytics chart initializer.
        $module_url = FFLA_URL . 'modules/woobooster/';
        wp_enqueue_script('woobooster-analytics-chart', $module_url . 'admin/js/woobooster-analytics.js', array('chartjs'), FFLA_VERSION, true);

        // Prepare donut data (top 5 rules + other).
        $donut = $this->prepare_donut_data($data['top_rules']);

        // Pass data to JS.
        wp_localize_script('woobooster-analytics-chart', 'WBAnalyticsChart', array(
            'labels' => $data['daily']['labels'],
            'total' => $data['daily']['total'],
            'wb' => $data['daily']['wb'],
            'currency' => function_exists('get_woocommerce_currency_symbol') ? html_entity_decode(get_woocommerce_currency_symbol()) : '$',
            'donut' => $donut,
            'funnel' => array(
                'impressions' => $data['conversion']['add_to_cart'],
                'purchased' => $data['conversion']['purchased'],
                'rate' => $data['conversion']['rate'],
            ),
        ));

        $this->render_styles();
        $this->render_date_filter($range);
        $this->render_charts();
        $this->render_stat_cards($data['stats'], $data['conversion'], $prev_data['stats'], $prev_data['conversion']);
        $this->render_funnel($data['conversion']);
        $this->render_tables($data['top_rules'], $data['top_products']);
    }

    /* =====================================================================
       DATE RANGE
       ===================================================================== */

    /**
     * Get the date range from query params or default to last 30 days.
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

    /* =====================================================================
       SINGLE-PASS DATA COMPUTATION
       ===================================================================== */

    /**
     * Compute ALL analytics in a single pass through orders.
     *
     * @param string $date_from Y-m-d.
     * @param string $date_to   Y-m-d.
     * @return array
     */
    private function compute_all_data($date_from, $date_to)
    {
        // Initialize accumulators.
        $stats = array(
            'net_revenue' => 0,
            'tax_revenue' => 0,
            'items_sold' => 0,
            'total_revenue' => 0,
            'wb_orders' => 0,
            'total_orders' => 0,
        );

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

        $rules_data = array();
        $products_data = array();

        // Paginated query — all completed/processing orders in range.
        $offset = 0;
        $page_size = 500;
        $all_processed = false;

        while (!$all_processed) {
            $orders = wc_get_orders(array(
                'status' => array('wc-completed', 'wc-processing'),
                'date_created' => $date_from . '...' . $date_to . ' 23:59:59',
                'limit' => $page_size,
                'offset' => $offset,
                'return' => 'objects',
            ));

            if (empty($orders)) {
                $all_processed = true;
                break;
            }

        foreach ($orders as $order) {

            $stats['total_orders']++;
            $day_key = $order->get_date_created()->format('Y-m-d');
            if (isset($day_totals[$day_key])) {
                $day_totals[$day_key] += (float) $order->get_subtotal();
            }

            $stats['total_revenue'] += (float) $order->get_subtotal();

            $order_has_wb = false;

            foreach ($order->get_items() as $item) {
                $rule_id = $item->get_meta('_wb_source_rule');
                if (!$rule_id) {
                    continue;
                }

                $order_has_wb = true;
                $rule_id = absint($rule_id);
                $subtotal = (float) $item->get_subtotal();
                $subtotal_tax = (float) $item->get_subtotal_tax();
                $qty = (int) $item->get_quantity();
                $product_id = $item->get_product_id();

                // Stats.
                $stats['net_revenue'] += $subtotal;
                $stats['tax_revenue'] += $subtotal_tax;
                $stats['items_sold'] += $qty;

                // Daily WB revenue.
                if (isset($day_wb[$day_key])) {
                    $day_wb[$day_key] += $subtotal;
                }

                // Rules aggregation.
                if (!isset($rules_data[$rule_id])) {
                    $rules_data[$rule_id] = array('revenue' => 0, 'items' => 0);
                }
                $rules_data[$rule_id]['revenue'] += $subtotal;
                $rules_data[$rule_id]['items'] += $qty;

                // Products aggregation.
                if (!isset($products_data[$product_id])) {
                    $products_data[$product_id] = array('revenue' => 0, 'count' => 0);
                }
                $products_data[$product_id]['revenue'] += $subtotal;
                $products_data[$product_id]['count'] += $qty;
            }

            if ($order_has_wb) {
                $stats['wb_orders']++;
            }
        }

            $offset += $page_size;
        } // end while

        // Build daily arrays.
        $daily = array('labels' => array(), 'total' => array(), 'wb' => array());
        foreach ($day_totals as $label => $total) {
            $daily['labels'][] = gmdate('M j', strtotime($label));
            $daily['total'][] = round($total, 2);
            $daily['wb'][] = round($day_wb[$label], 2);
        }

        // Sort and slice top rules.
        arsort($rules_data);
        $rules_data = array_slice($rules_data, 0, 10, true);
        $top_rules = array();
        foreach ($rules_data as $rid => $rd) {
            $rule = WooBooster_Rule::get($rid);
            $name = $rule ? $rule->name : sprintf(__('Rule #%d (deleted)', 'ffl-funnels-addons'), $rid);
            $top_rules[] = array('rule_id' => $rid, 'name' => $name, 'revenue' => $rd['revenue'], 'items' => $rd['items']);
        }

        // Sort and slice top products.
        arsort($products_data);
        $products_data = array_slice($products_data, 0, 10, true);
        $top_products = array();
        foreach ($products_data as $pid => $pd) {
            $product = wc_get_product($pid);
            $name = $product ? $product->get_name() : sprintf(__('Product #%d', 'ffl-funnels-addons'), $pid);
            $thumb = $product ? $product->get_image(array(36, 36)) : '';
            $top_products[] = array('product_id' => $pid, 'name' => $name, 'thumb' => $thumb, 'revenue' => $pd['revenue'], 'count' => $pd['count']);
        }

        // Conversion data from ATC counter.
        $conversion = $this->get_conversion_rate($date_from, $date_to, $stats['items_sold']);

        return compact('stats', 'daily', 'top_rules', 'top_products', 'conversion');
    }

    /**
     * Get conversion rate using ATC counter + pre-computed purchased count.
     */
    private function get_conversion_rate($date_from, $date_to, $purchased_items)
    {
        $counter = get_option(WooBooster_Tracker::COUNTER_OPTION, array());
        $atc_total = 0;

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

        $rate = $atc_total > 0 ? round(($purchased_items / $atc_total) * 100, 1) : 0;

        return array(
            'add_to_cart' => $atc_total,
            'purchased' => $purchased_items,
            'rate' => $rate,
        );
    }

    /* =====================================================================
       DONUT CHART DATA
       ===================================================================== */

    private function prepare_donut_data($top_rules)
    {
        if (empty($top_rules)) {
            return array('labels' => array(), 'values' => array(), 'colors' => array());
        }

        $palette = array('#0f6cbd', '#2b88d8', '#5ca5e0', '#84bce8', '#b4d6f0', '#d4e7f7', '#e8eff5', '#c4daf0', '#8bbde0', '#4f9dd0');
        $labels = array();
        $values = array();
        $colors = array();
        $shown = min(5, count($top_rules));
        $other = 0;

        for ($i = 0; $i < count($top_rules); $i++) {
            if ($i < $shown) {
                $labels[] = $top_rules[$i]['name'];
                $values[] = round($top_rules[$i]['revenue'], 2);
                $colors[] = $palette[$i];
            } else {
                $other += $top_rules[$i]['revenue'];
            }
        }

        if ($other > 0) {
            $labels[] = __('Other', 'ffl-funnels-addons');
            $values[] = round($other, 2);
            $colors[] = '#e2e8f0';
        }

        return array('labels' => $labels, 'values' => $values, 'colors' => $colors);
    }

    /* =====================================================================
       INLINE STYLES (analytics-specific)
       ===================================================================== */

    private function render_styles()
    {
        ?>
        <style>
            .wba-grid-2 {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-bottom: 20px;
            }

            .wba-grid-4 {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 16px;
                margin-bottom: 20px;
            }

            .wba-stat {
                text-align: center;
                padding: 20px 16px;
            }

            .wba-stat__label {
                font-size: var(--wb-font-size-sm);
                color: var(--wb-color-neutral-foreground-3);
                margin-bottom: 6px;
            }

            .wba-stat__value {
                font-size: var(--wb-font-size-xl);
                font-weight: var(--wb-font-weight-semibold);
                color: var(--wb-color-neutral-foreground-1);
            }

            .wba-stat__trend {
                font-size: 12px;
                margin-top: 4px;
                font-weight: 500;
            }

            .wba-trend--up {
                color: #0e7a0d;
            }

            .wba-trend--down {
                color: #c4314b;
            }

            .wba-trend--neutral {
                color: var(--wb-color-neutral-foreground-3);
            }

            .wba-funnel {
                display: flex;
                align-items: stretch;
                gap: 0;
                margin-bottom: 20px;
            }

            .wba-funnel__step {
                flex: 1;
                text-align: center;
                padding: 20px 16px;
                position: relative;
            }

            .wba-funnel__step:not(:last-child)::after {
                content: '→';
                position: absolute;
                right: -12px;
                top: 50%;
                transform: translateY(-50%);
                font-size: 20px;
                color: var(--wb-color-neutral-foreground-3);
                z-index: 1;
            }

            .wba-funnel__icon {
                font-size: 28px;
                margin-bottom: 8px;
            }

            .wba-funnel__count {
                font-size: var(--wb-font-size-xl);
                font-weight: var(--wb-font-weight-semibold);
            }

            .wba-funnel__label {
                font-size: var(--wb-font-size-sm);
                color: var(--wb-color-neutral-foreground-3);
                margin-top: 4px;
            }

            .wba-funnel__rate {
                font-size: 12px;
                color: #0f6cbd;
                font-weight: 600;
                margin-top: 4px;
            }

            .wba-product-cell {
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .wba-product-cell img {
                border-radius: 4px;
                width: 36px;
                height: 36px;
                object-fit: cover;
                flex-shrink: 0;
                box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
            }

            @media (max-width: 782px) {
                .wba-grid-2 {
                    grid-template-columns: 1fr;
                }

                .wba-funnel {
                    flex-direction: column;
                }

                .wba-funnel__step:not(:last-child)::after {
                    content: '↓';
                    right: auto;
                    top: auto;
                    bottom: -12px;
                    left: 50%;
                    transform: translateX(-50%);
                }
            }
        </style>
        <?php
    }

    /* =====================================================================
       RENDER: DATE FILTER
       ===================================================================== */

    private function render_date_filter($range)
    {
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
                <div style="margin-left:auto; display:flex; gap:6px; flex-wrap:wrap;">
                    <?php
                    $presets = array(
                        'today' => array(__('Today', 'ffl-funnels-addons'), 0),
                        'yesterday' => array(__('Yesterday', 'ffl-funnels-addons'), 1),
                        '7d' => array(__('7d', 'ffl-funnels-addons'), 7),
                        '30d' => array(__('30d', 'ffl-funnels-addons'), 30),
                        'this_week' => array(__('This Week', 'ffl-funnels-addons'), 'week'),
                        'this_month' => array(__('This Month', 'ffl-funnels-addons'), 'month'),
                        '90d' => array(__('90d', 'ffl-funnels-addons'), 90),
                    );

                    foreach ($presets as $key => $preset) {
                        list($label, $span) = $preset;

                        if ($span === 'week') {
                            $preset_from = gmdate('Y-m-d', strtotime('monday this week'));
                            $preset_to = gmdate('Y-m-d');
                        } elseif ($span === 'month') {
                            $preset_from = gmdate('Y-m-01');
                            $preset_to = gmdate('Y-m-d');
                        } elseif ($span === 0) {
                            $preset_from = gmdate('Y-m-d');
                            $preset_to = gmdate('Y-m-d');
                        } elseif ($span === 1) {
                            $preset_from = gmdate('Y-m-d', strtotime('-1 day'));
                            $preset_to = gmdate('Y-m-d', strtotime('-1 day'));
                        } else {
                            $preset_from = gmdate('Y-m-d', strtotime("-{$span} days"));
                            $preset_to = gmdate('Y-m-d');
                        }

                        $url = add_query_arg(array('page' => 'ffla-woobooster-analytics', 'wb_from' => $preset_from, 'wb_to' => $preset_to), admin_url('admin.php'));
                        $active = ($range['from'] === $preset_from && $range['to'] === $preset_to) ? ' wb-btn--primary' : ' wb-btn--subtle';
                        echo '<a href="' . esc_url($url) . '" class="wb-btn wb-btn--sm' . esc_attr($active) . '">' . esc_html($label) . '</a>';
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* =====================================================================
       RENDER: CHARTS (bar + donut side by side)
       ===================================================================== */

    private function render_charts()
    {
        ?>
        <div class="wba-grid-2">
            <div class="wb-card">
                <div class="wb-card__header">
                    <h2><?php esc_html_e('Revenue Overview', 'ffl-funnels-addons'); ?></h2>
                </div>
                <div class="wb-card__body">
                    <div style="position:relative; height:300px; width:100%;">
                        <canvas id="wb-revenue-chart"></canvas>
                    </div>
                </div>
            </div>
            <div class="wb-card">
                <div class="wb-card__header">
                    <h2><?php esc_html_e('Revenue by Rule', 'ffl-funnels-addons'); ?></h2>
                </div>
                <div class="wb-card__body">
                    <div
                        style="position:relative; height:300px; width:100%; display:flex; align-items:center; justify-content:center;">
                        <canvas id="wb-donut-chart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* =====================================================================
       RENDER: STAT CARDS WITH TREND INDICATORS
       ===================================================================== */

    private function render_stat_cards($stats, $conversion, $prev_stats, $prev_conversion)
    {
        $pct = $stats['total_revenue'] > 0
            ? round(($stats['net_revenue'] / $stats['total_revenue']) * 100, 1)
            : 0;

        $aov = $stats['wb_orders'] > 0
            ? $stats['net_revenue'] / $stats['wb_orders']
            : 0;

        $prev_aov = $prev_stats['wb_orders'] > 0
            ? $prev_stats['net_revenue'] / $prev_stats['wb_orders']
            : 0;

        ?>
        <div class="wba-grid-4">
            <?php
            $this->render_card(__('WB Net Revenue', 'ffl-funnels-addons'), wc_price($stats['net_revenue']), $stats['net_revenue'], $prev_stats['net_revenue']);
            $this->render_card(__('Tax Generated', 'ffl-funnels-addons'), wc_price($stats['tax_revenue']), $stats['tax_revenue'], $prev_stats['tax_revenue']);
            $this->render_card(__('Items Sold', 'ffl-funnels-addons'), number_format_i18n($stats['items_sold']), $stats['items_sold'], $prev_stats['items_sold']);
            $this->render_card(__('% of Total Revenue', 'ffl-funnels-addons'), $pct . '%', null, null);
            ?>
        </div>
        <div class="wba-grid-4">
            <?php
            $this->render_card(__('WB Orders', 'ffl-funnels-addons'), number_format_i18n($stats['wb_orders']), $stats['wb_orders'], $prev_stats['wb_orders']);
            $this->render_card(__('Avg Order Value', 'ffl-funnels-addons'), wc_price($aov), $aov, $prev_aov);
            $this->render_card(__('Add-to-Cart', 'ffl-funnels-addons'), number_format_i18n($conversion['add_to_cart']), $conversion['add_to_cart'], $prev_conversion['add_to_cart']);
            $this->render_card(__('Conversion Rate', 'ffl-funnels-addons'), $conversion['rate'] . '%', $conversion['rate'], $prev_conversion['rate']);
            ?>
        </div>
        <?php
    }

    /**
     * Render a single stat card with optional trend indicator.
     */
    private function render_card($label, $value, $current = null, $previous = null)
    {
        $trend_html = '';
        if ($current !== null && $previous !== null) {
            if ($previous > 0) {
                $change = round((($current - $previous) / $previous) * 100, 1);
                if ($change > 0) {
                    $trend_html = '<div class="wba-stat__trend wba-trend--up">↑ ' . $change . '%</div>';
                } elseif ($change < 0) {
                    $trend_html = '<div class="wba-stat__trend wba-trend--down">↓ ' . abs($change) . '%</div>';
                } else {
                    $trend_html = '<div class="wba-stat__trend wba-trend--neutral">— 0%</div>';
                }
            } elseif ($current > 0) {
                $trend_html = '<div class="wba-stat__trend wba-trend--up">↑ New</div>';
            }
        }
        ?>
        <div class="wb-card">
            <div class="wb-card__body wba-stat">
                <div class="wba-stat__label"><?php echo esc_html($label); ?></div>
                <div class="wba-stat__value"><?php echo wp_kses_post($value); ?></div>
                <?php echo wp_kses_post($trend_html); ?>
            </div>
        </div>
        <?php
    }

    /* =====================================================================
       RENDER: CONVERSION FUNNEL
       ===================================================================== */

    private function render_funnel($conversion)
    {
        ?>
        <div class="wb-card" style="margin-bottom:20px;">
            <div class="wb-card__header">
                <h2><?php esc_html_e('Conversion Funnel', 'ffl-funnels-addons'); ?></h2>
            </div>
            <div class="wb-card__body">
                <div class="wba-funnel">
                    <div class="wba-funnel__step">
                        <div class="wba-funnel__icon">🛒</div>
                        <div class="wba-funnel__count"><?php echo esc_html(number_format_i18n($conversion['add_to_cart'])); ?>
                        </div>
                        <div class="wba-funnel__label"><?php esc_html_e('Add-to-Cart', 'ffl-funnels-addons'); ?></div>
                    </div>
                    <div class="wba-funnel__step">
                        <div class="wba-funnel__icon">✅</div>
                        <div class="wba-funnel__count"><?php echo esc_html(number_format_i18n($conversion['purchased'])); ?>
                        </div>
                        <div class="wba-funnel__label"><?php esc_html_e('Purchased Items', 'ffl-funnels-addons'); ?></div>
                    </div>
                    <div class="wba-funnel__step">
                        <div class="wba-funnel__icon">📈</div>
                        <div class="wba-funnel__count" style="color:#0f6cbd;"><?php echo esc_html($conversion['rate']); ?>%
                        </div>
                        <div class="wba-funnel__label"><?php esc_html_e('Conversion Rate', 'ffl-funnels-addons'); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /* =====================================================================
       RENDER: TABLES (side by side)
       ===================================================================== */

    private function render_tables($top_rules, $top_products)
    {
        ?>
        <div class="wba-grid-2">
            <?php $this->render_top_rules_table($top_rules); ?>
            <?php $this->render_top_products_table($top_products); ?>
        </div>
        <?php
    }

    private function render_top_rules_table($rules)
    {
        ?>
        <div class="wb-card">
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
                                <th style="text-align:right;"><?php esc_html_e('Sold', 'ffl-funnels-addons'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $row): ?>
                                <tr>
                                    <td>
                                        <div class="wba-product-cell">
                                            <?php echo wp_kses_post($row['thumb']); ?>
                                            <span><?php echo esc_html($row['name']); ?></span>
                                        </div>
                                    </td>
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
}
