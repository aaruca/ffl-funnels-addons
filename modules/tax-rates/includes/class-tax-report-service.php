<?php
/**
 * WooCommerce tax reporting service.
 *
 * Builds concise filing reports from the values permanently recorded on
 * WooCommerce orders, refunds, destinations, and stored tax quotes.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Report_Service
{
    const SCHEMA_VERSION = '2.0.0';
    const HISTORY_OPTION = 'ffla_tax_report_runs';

    /** @var int */
    private $precision;

    /** @var int */
    private $scale;

    public function __construct()
    {
        $this->precision = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        $this->scale = (int) pow(10, $this->precision);
    }

    /**
     * Default report filters.
     */
    public static function default_filters(): array
    {
        $today = current_datetime();

        return [
            'date_from'   => $today->format('Y-01-01'),
            'date_to'     => $today->format('Y-m-d'),
            'statuses'    => ['processing', 'completed', 'on-hold', 'refunded'],
            'include_pii' => false,
        ];
    }

    /**
     * Validate and normalize report filters from an admin request.
     *
     * @throws InvalidArgumentException When a date or status is invalid.
     */
    public static function normalize_filters(array $input): array
    {
        $defaults = self::default_filters();
        $from = isset($input['date_from']) ? sanitize_text_field((string) $input['date_from']) : $defaults['date_from'];
        $to = isset($input['date_to']) ? sanitize_text_field((string) $input['date_to']) : $defaults['date_to'];

        if (!self::is_valid_date($from) || !self::is_valid_date($to)) {
            throw new InvalidArgumentException(__('Enter a valid report date range.', 'ffl-funnels-addons'));
        }

        if ($from > $to) {
            throw new InvalidArgumentException(__('The report start date must be before the end date.', 'ffl-funnels-addons'));
        }

        $available = [];
        if (function_exists('wc_get_order_statuses')) {
            foreach (array_keys(wc_get_order_statuses()) as $status) {
                $available[] = preg_replace('/^wc-/', '', (string) $status);
            }
        }

        $requested = isset($input['statuses']) && is_array($input['statuses'])
            ? $input['statuses']
            : $defaults['statuses'];
        $statuses = [];
        foreach ($requested as $status) {
            $status = preg_replace('/^wc-/', '', sanitize_key((string) $status));
            if ($status !== '' && (empty($available) || in_array($status, $available, true))) {
                $statuses[] = $status;
            }
        }
        $statuses = array_values(array_unique($statuses));

        if (empty($statuses)) {
            throw new InvalidArgumentException(__('Select at least one valid WooCommerce order status.', 'ffl-funnels-addons'));
        }

        return [
            'date_from'   => $from,
            'date_to'     => $to,
            'statuses'    => $statuses,
            'include_pii' => !empty($input['include_pii']),
        ];
    }

    /**
     * Generate all report datasets and summaries.
     *
     * @param array $filters Report filters.
     * @param array $options Supports summary_only and exception_limit.
     */
    public function generate(array $filters, array $options = []): array
    {
        if (!function_exists('wc_get_orders')) {
            throw new RuntimeException(__('WooCommerce is required to generate tax reports.', 'ffl-funnels-addons'));
        }

        $filters = self::normalize_filters($filters);
        $summary_only = !empty($options['summary_only']);
        $exception_limit = isset($options['exception_limit']) ? max(1, (int) $options['exception_limit']) : 250;
        $generated_at = gmdate('c');
        $report_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('ffla-', true);

        $report = [
            'manifest' => [
                'schema_version'   => self::SCHEMA_VERSION,
                'report_id'        => $report_id,
                'generated_at_utc' => $generated_at,
                'site_name'        => get_bloginfo('name'),
                'site_url'         => home_url('/'),
                'site_timezone'    => wp_timezone_string(),
                'filters'          => $filters,
                'source_of_truth'  => 'WooCommerce final order, line-item, tax-line, refund, destination, and stored tax-quote values',
                'calculation_note' => 'Collected tax is preserved as stored. Calculated tax is taxable sales multiplied by the effective rate stored with each order.',
                'plugin_version'   => defined('FFLA_VERSION') ? FFLA_VERSION : '',
                'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : '',
                'wordpress_version'   => get_bloginfo('version'),
            ],
            'stats' => [
                'orders'                    => 0,
                'refunds'                   => 0,
                'order_lines'               => 0,
                'tax_lines'                 => 0,
                'exceptions'                => 0,
                'orders_with_snapshot'      => 0,
                'orders_without_snapshot'   => 0,
                'orders_with_stored_quote'  => 0,
                'orders_with_cogs'          => 0,
            ],
            'totals_by_currency' => [],
            'orders'              => [],
            'order_lines'         => [],
            'tax_lines'           => [],
            'refunds'             => [],
            'exceptions'          => [],
            'summaries'           => [
                'filing_totals' => [],
                'states'        => [],
                'jurisdictions' => [],
                'products'      => [],
                'payments'      => [],
                'exceptions'    => [],
            ],
        ];

        $currency_totals = [];
        $state_totals = [];
        $jurisdiction_totals = [];
        $product_totals = [];
        $payment_totals = [];
        $exception_totals = [];
        $currencies = [];
        $period_refund_ids = [];

        $timezone = wp_timezone();
        $from = new DateTimeImmutable($filters['date_from'] . ' 00:00:00', $timezone);
        $to = new DateTimeImmutable($filters['date_to'] . ' 23:59:59', $timezone);

        $page = 1;
        $pages = 1;
        do {
            $result = wc_get_orders([
                'type'         => 'shop_order',
                'status'       => $filters['statuses'],
                'date_created' => $from->getTimestamp() . '...' . $to->getTimestamp(),
                'orderby'      => 'date',
                'order'        => 'ASC',
                'limit'        => 200,
                'page'         => $page,
                'paginate'     => true,
                'return'       => 'objects',
            ]);

            $orders = is_object($result) && isset($result->orders) ? $result->orders : (is_array($result) ? $result : []);
            $pages = is_object($result) && isset($result->max_num_pages) ? max(1, (int) $result->max_num_pages) : 1;

            foreach ($orders as $order) {
                if (!is_a($order, 'WC_Order')) {
                    continue;
                }

                $quote = $this->get_stored_quote($order);
                $tax_location = $this->get_tax_location($order, $quote);
                $period_refunds = $this->get_refunds_in_period($order, $from->getTimestamp(), $to->getTimestamp());
                foreach ($period_refunds as $period_refund) {
                    $period_refund_ids[(int) $period_refund->get_id()] = true;
                }
                $refund_map = $this->get_line_refund_map($order, $period_refunds);
                $order_row = $this->build_order_row($order, $quote, $tax_location, $filters['include_pii'], $period_refunds);
                $line_rows = $this->build_line_rows($order, $refund_map);
                $tax_rows = $this->build_tax_rows($order, $period_refunds);
                $refund_rows = $this->build_refund_rows($order, $period_refunds);
                $exceptions = $this->detect_exceptions($order, $order_row, $line_rows, $tax_rows, $refund_rows, $quote, $tax_location);

                $currency = $order_row['currency'] !== '' ? $order_row['currency'] : '(none)';
                $currencies[$currency] = true;
                $report['stats']['orders']++;
                $report['stats']['refunds'] += count($refund_rows);
                $report['stats']['order_lines'] += count($line_rows);
                $report['stats']['tax_lines'] += count($tax_rows);
                $report['stats']['exceptions'] += count($exceptions);

                if ($order_row['snapshot_hash'] !== '') {
                    $report['stats']['orders_with_snapshot']++;
                } else {
                    $report['stats']['orders_without_snapshot']++;
                }
                if (!empty($quote)) {
                    $report['stats']['orders_with_stored_quote']++;
                }
                if ($this->rows_have_cogs($line_rows)) {
                    $report['stats']['orders_with_cogs']++;
                }

                if (!$summary_only) {
                    $report['orders'][] = $order_row;
                    $report['order_lines'] = array_merge($report['order_lines'], $line_rows);
                    $report['tax_lines'] = array_merge($report['tax_lines'], $tax_rows);
                    $report['refunds'] = array_merge($report['refunds'], $refund_rows);
                }
                if (!$summary_only || count($report['exceptions']) < $exception_limit) {
                    $room = $summary_only ? max(0, $exception_limit - count($report['exceptions'])) : count($exceptions);
                    $report['exceptions'] = array_merge($report['exceptions'], array_slice($exceptions, 0, $room));
                }

                $this->aggregate_currency($currency_totals, $currency, $order_row);
                $this->aggregate_state($state_totals, $currency, $tax_location, $order_row, $line_rows, $quote);
                $this->aggregate_jurisdictions($jurisdiction_totals, $currency, $tax_location, $order_row, $quote, $tax_rows, $line_rows);
                $this->aggregate_products($product_totals, $currency, $line_rows);
                $this->aggregate_payment($payment_totals, $currency, $order_row);
                $this->aggregate_exceptions($exception_totals, $exceptions);
            }

            $page++;
        } while ($page <= $pages);

        // Refunds belong to the period in which they were created, even when
        // the original sale was in an earlier period or its current status was
        // not selected above.
        $this->add_unseen_period_refunds(
            $from,
            $to,
            $period_refund_ids,
            $summary_only,
            $exception_limit,
            $report,
            $currency_totals,
            $state_totals,
            $jurisdiction_totals,
            $product_totals,
            $payment_totals,
            $exception_totals,
            $currencies
        );

        $report['totals_by_currency'] = $this->finalize_currency_totals($currency_totals);
        $report['summaries']['jurisdictions'] = $this->finalize_jurisdiction_totals($jurisdiction_totals);
        $report['summaries']['states'] = $this->enrich_state_filing_totals(
            $this->finalize_state_totals($state_totals),
            $report['summaries']['jurisdictions']
        );
        $report['summaries']['filing_totals'] = $this->build_filing_totals($report['summaries']['states']);
        $report['summaries']['products'] = $this->finalize_product_totals($product_totals);
        $report['summaries']['payments'] = $this->finalize_payment_totals($payment_totals);
        $report['summaries']['exceptions'] = array_values($exception_totals);

        usort($report['summaries']['exceptions'], function ($a, $b) {
            return ((int) $b['count']) <=> ((int) $a['count']);
        });

        $report['manifest']['stats'] = $report['stats'];
        $report['manifest']['currencies'] = array_keys($currencies);
        $report['manifest']['totals_by_currency'] = $report['totals_by_currency'];
        $report['manifest']['data_quality'] = [
            'snapshot_coverage_percent' => $report['stats']['orders'] > 0
                ? round(($report['stats']['orders_with_snapshot'] / $report['stats']['orders']) * 100, 2)
                : 0,
            'stored_quote_coverage_percent' => $report['stats']['orders'] > 0
                ? round(($report['stats']['orders_with_stored_quote'] / $report['stats']['orders']) * 100, 2)
                : 0,
            'exception_count' => $report['stats']['exceptions'],
        ];
        $report['manifest']['limitations'] = $this->get_limitations($report);

        return $report;
    }

    /**
     * Build a compact, PII-free fiscal snapshot for an order revision.
     */
    public function build_fiscal_snapshot($order): array
    {
        if (is_numeric($order)) {
            $order = wc_get_order((int) $order);
        }
        if (!is_a($order, 'WC_Order')) {
            return [];
        }

        $quote = $this->get_stored_quote($order);
        $location = $this->get_tax_location($order, $quote);
        $refund_map = $this->get_line_refund_map($order);
        $order_row = $this->build_order_row($order, $quote, $location, false);
        $line_rows = $this->build_line_rows($order, $refund_map);
        $tax_rows = $this->build_tax_rows($order);
        $refund_rows = $this->build_refund_rows($order);

        $compact_lines = [];
        foreach ($line_rows as $line) {
            $compact_lines[] = array_intersect_key($line, array_flip([
                'item_id', 'item_type', 'product_id', 'variation_id', 'sku', 'name',
                'quantity', 'tax_class', 'subtotal', 'discount', 'total_ex_tax',
                'tax', 'total_inc_tax', 'refunded_amount', 'refunded_tax', 'vendor',
                'vendor_sku', 'taxes_json', 'cogs_value',
            ]));
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'order_id'       => (int) $order->get_id(),
            'order_number'   => (string) $order->get_order_number(),
            'status'         => (string) $order->get_status(),
            'currency'       => (string) $order->get_currency(),
            'date_created_utc' => $order_row['date_created_utc'],
            'tax_location'   => $location,
            'totals'         => array_intersect_key($order_row, array_flip([
                'gross_product_sales', 'discounts', 'net_product_sales', 'shipping',
                'fees', 'tax_collected', 'tax_refunded', 'net_tax', 'refunds',
                'order_total', 'net_collected',
            ])),
            'quote'          => $this->sanitize_quote_evidence($quote, false),
            'lines'          => $compact_lines,
            'tax_lines'      => $tax_rows,
            'refunds'        => $refund_rows,
        ];
    }

    /**
     * Export column order. Keeping this explicit makes CSV/XLSX schemas stable.
     */
    public static function get_columns(string $dataset): array
    {
        $columns = [
            'orders' => [
                'order_id', 'order_number', 'date_created_local', 'date_created_utc', 'date_paid_local',
                'status', 'created_via', 'currency', 'customer_id', 'payment_method', 'payment_method_title',
                'transaction_id', 'tax_address_source', 'tax_country', 'tax_state', 'tax_city', 'tax_postcode',
                'billing_first_name', 'billing_last_name', 'billing_company', 'billing_email', 'billing_phone',
                'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country',
                'shipping_first_name', 'shipping_last_name', 'shipping_company', 'shipping_address_1', 'shipping_address_2',
                'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country', 'shipping_address_formatted',
                'gross_product_sales', 'discounts', 'net_product_sales', 'shipping', 'fees', 'tax_collected',
                'tax_refunded', 'net_tax', 'refunds', 'order_total', 'net_collected', 'customer_tax_exempt',
                'tax_quote_query_id', 'tax_quote_source', 'tax_quote_outcome', 'tax_quote_rate_percent',
                'tax_quote_effective_date', 'tax_quote_evidence_json', 'snapshot_hash', 'customer_note',
            ],
            'order_lines' => [
                'order_id', 'order_number', 'date_created_local', 'status', 'currency', 'tax_state', 'item_id',
                'item_type', 'product_id', 'variation_id', 'sku', 'name', 'categories', 'quantity', 'tax_class',
                'subtotal', 'subtotal_tax', 'discount', 'total_ex_tax', 'tax', 'total_inc_tax', 'refunded_quantity',
                'refunded_amount', 'refunded_tax', 'vendor', 'vendor_sku', 'vendor_price', 'shipping_class',
                'shipping_method_id', 'coupon_code', 'taxes_json', 'cogs_value', 'cogs_source',
            ],
            'tax_lines' => [
                'order_id', 'order_number', 'date_created_local', 'status', 'currency', 'tax_state', 'tax_item_id',
                'rate_id', 'rate_code', 'label', 'rate_percent', 'compound', 'product_tax', 'shipping_tax',
                'tax_collected', 'tax_refunded', 'net_tax', 'tax_quote_source', 'tax_quote_query_id',
            ],
            'refunds' => [
                'order_id', 'order_number', 'refund_id', 'date_created_local', 'date_created_utc', 'currency',
                'reason', 'created_by', 'amount', 'tax_refunded', 'product_refund', 'shipping_refund', 'fee_refund',
                'line_items_json',
            ],
            'filing-totals' => [
                'currency', 'orders', 'taxable_sales', 'non_taxable_sales', 'needs_review_sales',
                'net_tax', 'calculated_tax', 'over_under',
            ],
            'state-summary' => [
                'state', 'currency', 'orders', 'taxable_sales', 'non_taxable_sales', 'needs_review_sales',
                'net_tax', 'calculated_tax', 'over_under', 'filing_status',
            ],
            'jurisdiction-summary' => [
                'state', 'jurisdiction_type', 'jurisdiction_name', 'rate_percent',
                'currency', 'orders', 'taxable_sales', 'net_tax',
                'calculated_tax', 'over_under', 'filing_status',
            ],
            'order-audit' => [
                'order_number', 'date_created_local', 'status', 'currency', 'tax_address_source', 'tax_state',
                'tax_city', 'tax_postcode', 'shipping_address_formatted', 'net_product_sales', 'shipping', 'fees',
                'tax_collected', 'tax_refunded', 'net_tax', 'order_total', 'customer_tax_exempt',
            ],
            'product-summary' => [
                'product_id', 'variation_id', 'sku', 'product_name', 'categories', 'currency', 'orders', 'quantity',
                'gross_sales', 'discounts', 'net_sales', 'tax', 'refunded_amount', 'cogs_value',
            ],
            'payment-summary' => [
                'payment_method', 'payment_method_title', 'currency', 'orders', 'order_total', 'refunds',
                'net_collected', 'tax_collected', 'tax_refunded', 'net_tax',
            ],
            'exceptions' => [
                'severity', 'code', 'order_id', 'order_number', 'date_created_local', 'country', …14106 tokens truncated…             $this->add_refund_adjustment_to_state($state_totals, $currency, $location, $amount, $tax, $refund_sales);
                $this->add_refund_adjustment_to_payment($payment_totals, $currency, $order, $amount, $tax);
                $this->add_refund_adjustment_to_jurisdiction(
                    $jurisdiction_totals,
                    $currency,
                    $location,
                    $order,
                    $quote,
                    $tax,
                    (int) $refund_sales['taxable_sales']
                );
                $this->add_refund_adjustment_to_products($product_totals, $currency, $order, $refund);

                $exception = [
                    'severity'          => 'info',
                    'code'              => 'prior_or_excluded_period_refund',
                    'order_id'          => (int) $order->get_id(),
                    'order_number'      => (string) $order->get_order_number(),
                    'date_created_local'=> $refund_row['date_created_local'],
                    'country'           => $location['country'],
                    'state'             => $location['state'],
                    'currency'          => $currency,
                    'amount'            => $refund_row['amount'],
                    'message'           => 'This refund occurred in the report period but its original order was outside the selected order population.',
                    'evidence'          => 'refund_id=' . $refund_id,
                ];
                $report['stats']['exceptions']++;
                if (!$summary_only || count($report['exceptions']) < $exception_limit) {
                    $report['exceptions'][] = $exception;
                }
                $this->aggregate_exceptions($exception_totals, [$exception]);
            }
            $page++;
        } while ($page <= $pages);
    }

    private function add_refund_adjustment_to_currency(array &$totals, string $currency, int $amount, int $tax): void
    {
        if (!isset($totals[$currency])) {
            $totals[$currency] = $this->new_money_bucket();
            $totals[$currency]['currency'] = $currency;
        }
        $totals[$currency]['refunds'] += $amount;
        $totals[$currency]['tax_refunded'] += $tax;
        $totals[$currency]['net_tax'] -= $tax;
        $totals[$currency]['net_collected'] -= $amount;
    }

    private function add_refund_adjustment_to_state(array &$totals, string $currency, array $location, int $amount, int $tax, array $sales): void
    {
        $country = $location['country'] !== '' ? $location['country'] : '(none)';
        $state = $location['state'] !== '' ? $location['state'] : '(none)';
        $key = $country . '|' . $state . '|' . $currency;
        if (!isset($totals[$key])) {
            $totals[$key] = $this->new_money_bucket();
            $totals[$key]['country'] = $country;
            $totals[$key]['state'] = $state;
            $totals[$key]['currency'] = $currency;
            $totals[$key]['sales_with_tax'] = 0;
            $totals[$key]['sales_without_tax'] = 0;
            $totals[$key]['taxable_sales'] = 0;
            $totals[$key]['non_taxable_sales'] = 0;
            $totals[$key]['needs_review_sales'] = 0;
        }
        $totals[$key]['refunds'] += $amount;
        $totals[$key]['tax_refunded'] += $tax;
        $totals[$key]['net_tax'] -= $tax;
        $totals[$key]['net_collected'] -= $amount;
        $totals[$key]['sales_with_tax'] -= (int) $sales['taxable_sales'];
        $totals[$key]['sales_without_tax'] -= (int) $sales['non_taxable_sales'] + (int) $sales['needs_review_sales'];
        $totals[$key]['taxable_sales'] -= (int) $sales['taxable_sales'];
        $totals[$key]['non_taxable_sales'] -= (int) $sales['non_taxable_sales'];
        $totals[$key]['needs_review_sales'] -= (int) $sales['needs_review_sales'];
    }

    private function add_refund_adjustment_to_payment(array &$totals, string $currency, $order, int $amount, int $tax): void
    {
        $method = (string) $order->get_payment_method();
        $key = $method . '|' . $currency;
        if (!isset($totals[$key])) {
            $totals[$key] = [
                'payment_method' => $method,
                'payment_method_title' => (string) $order->get_payment_method_title(),
                'currency' => $currency,
                'orders' => 0,
                'order_total' => 0,
                'refunds' => 0,
                'net_collected' => 0,
                'tax_collected' => 0,
                'tax_refunded' => 0,
                'net_tax' => 0,
            ];
        }
        $totals[$key]['refunds'] += $amount;
        $totals[$key]['net_collected'] -= $amount;
        $totals[$key]['tax_refunded'] += $tax;
        $totals[$key]['net_tax'] -= $tax;
    }

    private function add_refund_adjustment_to_jurisdiction(array &$totals, string $currency, array $location, $order, array $quote, int $tax, int $taxable_sales): void
    {
        if ($tax <= 0) {
            return;
        }
        $breakdown = isset($quote['breakdown']) && is_array($quote['breakdown']) ? array_values(array_filter($quote['breakdown'], function ($item) {
            return isset($item['rate']) && (float) $item['rate'] > 0;
        })) : [];
        if (!empty($breakdown)) {
            $rate_total = array_sum(array_map(function ($item) {
                return (float) $item['rate'];
            }, $breakdown));
            $jurisdiction = $this->get_filing_jurisdiction($location, $breakdown);
            $this->add_jurisdiction_bucket(
                $totals,
                $location,
                $currency,
                (string) $jurisdiction['type'],
                (string) $jurisdiction['name'],
                $rate_total * 100,
                (string) ($quote['source'] ?? ''),
                0,
                $tax,
                'combined_stored_quote',
                (int) $order->get_id(),
                -$taxable_sales,
                -(int) round($taxable_sales * $rate_total),
                (string) $jurisdiction['code'],
                (string) $jurisdiction['status']
            );
            return;
        }

        $this->add_jurisdiction_bucket(
            $totals,
            $location,
            $currency,
            'refund',
            'Unallocated refund tax',
            0,
            '',
            0,
            $tax,
            'refund_total_only',
            (int) $order->get_id(),
            -$taxable_sales,
            -$tax,
            '',
            'needs_review'
        );
    }

    private function add_refund_adjustment_to_products(array &$totals, string $currency, $order, $refund): void
    {
        foreach ($refund->get_items('line_item') as $item) {
            $original_id = (int) $item->get_meta('_refunded_item_id', true);
            $original = $original_id ? $order->get_item($original_id) : null;
            $product_id = $original && method_exists($original, 'get_product_id') ? (int) $original->get_product_id() : (int) $item->get_product_id();
            $variation_id = $original && method_exists($original, 'get_variation_id') ? (int) $original->get_variation_id() : (int) $item->get_variation_id();
            $product = $original && method_exists($original, 'get_product') ? $original->get_product() : $item->get_product();
            $sku = $product && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';
            $name = $original ? (string) $original->get_name() : (string) $item->get_name();
            $key = implode('|', [$product_id, $variation_id, $sku, $currency]);
            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'sku' => $sku,
                    'product_name' => $name,
                    'categories' => $this->get_product_categories($product_id),
                    'currency' => $currency,
                    'order_ids' => [],
                    'quantity' => 0,
                    'gross_sales' => 0,
                    'discounts' => 0,
                    'net_sales' => 0,
                    'tax' => 0,
                    'refunded_amount' => 0,
                    'cogs_value' => 0,
                    'has_cogs' => false,
                ];
            }
            $totals[$key]['order_ids'][(int) $order->get_id()] = true;
            $totals[$key]['refunded_amount'] += $this->minor(abs((float) $item->get_total()));
        }
    }

    private function new_money_bucket(): array
    {
        return [
            'orders' => 0,
            'gross_product_sales' => 0,
            'discounts' => 0,
            'net_product_sales' => 0,
            'shipping' => 0,
            'fees' => 0,
            'tax_collected' => 0,
            'tax_refunded' => 0,
            'net_tax' => 0,
            'refunds' => 0,
            'order_total' => 0,
            'net_collected' => 0,
        ];
    }

    private function add_order_money(array &$bucket, array $order): void
    {
        foreach (['gross_product_sales', 'discounts', 'net_product_sales', 'shipping', 'fees', 'tax_collected', 'tax_refunded', 'net_tax', 'refunds', 'order_total', 'net_collected'] as $field) {
            $bucket[$field] += $this->minor($order[$field]);
        }
    }

    private function finalize_currency_totals(array $totals): array
    {
        $rows = [];
        foreach ($totals as $row) {
            foreach (['gross_product_sales', 'discounts', 'net_product_sales', 'shipping', 'fees', 'tax_collected', 'tax_refunded', 'net_tax', 'refunds', 'order_total', 'net_collected'] as $field) {
                $row[$field] = $this->decimal($row[$field]);
            }
            $rows[] = $row;
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['currency'], $b['currency']);
        });
        return $rows;
    }

    private function finalize_state_totals(array $totals): array
    {
        $rows = [];
        foreach ($totals as $row) {
            $row['gross_sales'] = (int) ($row['taxable_sales'] ?? 0)
                + (int) ($row['non_taxable_sales'] ?? 0)
                + (int) ($row['needs_review_sales'] ?? 0);
            foreach (['gross_product_sales', 'discounts', 'net_product_sales', 'shipping', 'fees', 'gross_sales', 'sales_with_tax', 'sales_without_tax', 'taxable_sales', 'non_taxable_sales', 'needs_review_sales', 'tax_collected', 'tax_refunded', 'net_tax', 'refunds', 'order_total', 'net_collected'] as $field) {
                $row[$field] = $this->decimal($row[$field]);
            }
            $rows[] = $row;
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['country'] . $a['state'] . $a['currency'], $b['country'] . $b['state'] . $b['currency']);
        });
        return $rows;
    }

    private function finalize_jurisdiction_totals(array $totals): array
    {
        $rows = [];
        foreach ($totals as $row) {
            $row['orders'] = count($row['order_ids']);
            unset($row['order_ids']);
            $row['over_under'] = (int) $row['net_tax'] - (int) $row['calculated_tax'];
            $row['filing_status'] = $row['filing_status'] === 'needs_review'
                ? __('Needs review', 'ffl-funnels-addons')
                : __('Ready', 'ffl-funnels-addons');
            foreach (['taxable_sales', 'tax_collected', 'tax_refunded', 'net_tax', 'calculated_tax', 'over_under'] as $field) {
                $row[$field] = $this->decimal($row[$field]);
            }
            $rows[] = $row;
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['country'] . $a['state'] . $a['jurisdiction_type'] . $a['jurisdiction_name'], $b['country'] . $b['state'] . $b['jurisdiction_type'] . $b['jurisdiction_name']);
        });
        return $rows;
    }

    private function enrich_state_filing_totals(array $states, array $jurisdictions): array
    {
        $by_state = [];
        foreach ($jurisdictions as $jurisdiction) {
            $key = implode('|', [
                (string) ($jurisdiction['country'] ?? ''),
                (string) ($jurisdiction['state'] ?? ''),
                (string) ($jurisdiction['currency'] ?? ''),
            ]);
            if (!isset($by_state[$key])) {
                $by_state[$key] = [
                    'calculated_tax' => 0,
                    'jurisdictions' => 0,
                    'needs_review' => false,
                ];
            }
            $by_state[$key]['calculated_tax'] += $this->minor($jurisdiction['calculated_tax'] ?? 0);
            $by_state[$key]['jurisdictions']++;
            if (($jurisdiction['filing_status'] ?? '') === __('Needs review', 'ffl-funnels-addons')) {
                $by_state[$key]['needs_review'] = true;
            }
        }

        foreach ($states as &$state) {
            $key = implode('|', [
                (string) ($state['country'] ?? ''),
                (string) ($state['state'] ?? ''),
                (string) ($state['currency'] ?? ''),
            ]);
            $jurisdiction_totals = $by_state[$key] ?? [
                'calculated_tax' => 0,
                'jurisdictions' => 0,
                'needs_review' => false,
            ];
            $calculated = (int) $jurisdiction_totals['calculated_tax'];
            $over_under = $this->minor($state['net_tax'] ?? 0) - $calculated;
            $state['calculated_tax'] = $this->decimal($calculated);
            $state['over_under'] = $this->decimal($over_under);
            $state['jurisdictions'] = (int) $jurisdiction_totals['jurisdictions'];

            if ($this->minor($state['needs_review_sales'] ?? 0) !== 0 || $jurisdiction_totals['needs_review']) {
                $state['filing_status'] = __('Needs review', 'ffl-funnels-addons');
            } elseif ($this->minor($state['taxable_sales'] ?? 0) === 0 && $this->minor($state['net_tax'] ?? 0) === 0) {
                $state['filing_status'] = __('No tax due', 'ffl-funnels-addons');
            } else {
                $state['filing_status'] = __('Ready', 'ffl-funnels-addons');
            }
        }
        unset($state);

        return $states;
    }

    private function build_filing_totals(array $states): array
    {
        $totals = [];
        foreach ($states as $state) {
            $currency = (string) ($state['currency'] ?? '(none)');
            if (!isset($totals[$currency])) {
                $totals[$currency] = [
                    'currency' => $currency,
                    'states' => 0,
                    'orders' => 0,
                    'gross_sales' => 0,
                    'taxable_sales' => 0,
                    'non_taxable_sales' => 0,
                    'needs_review_sales' => 0,
                    'tax_collected' => 0,
                    'tax_refunded' => 0,
                    'net_tax' => 0,
                    'calculated_tax' => 0,
                    'over_under' => 0,
                ];
            }
            $totals[$currency]['states']++;
            $totals[$currency]['orders'] += (int) ($state['orders'] ?? 0);
            foreach (['gross_sales', 'taxable_sales', 'non_taxable_sales', 'needs_review_sales', 'tax_collected', 'tax_refunded', 'net_tax', 'calculated_tax', 'over_under'] as $field) {
                $totals[$currency][$field] += $this->minor($state[$field] ?? 0);
            }
        }

        foreach ($totals as &$row) {
            foreach (['gross_sales', 'taxable_sales', 'non_taxable_sales', 'needs_review_sales', 'tax_collected', 'tax_refunded', 'net_tax', 'calculated_tax', 'over_under'] as $field) {
                $row[$field] = $this->decimal($row[$field]);
            }
        }
        unset($row);

        return array_values($totals);
    }

    private function finalize_product_totals(array $totals): array
    {
        $rows = [];
        foreach ($totals as $row) {
            $row['orders'] = count($row['order_ids']);
            unset($row['order_ids']);
            $row['quantity'] = number_format($row['quantity'], 4, '.', '');
            foreach (['gross_sales', 'discounts', 'net_sales', 'tax', 'refunded_amount'] as $field) {
                $row[$field] = $this->decimal($row[$field]);
            }
            $row['cogs_value'] = $row['has_cogs'] ? $this->decimal($row['cogs_value']) : '';
            unset($row['has_cogs']);
            $rows[] = $row;
        }
        usort($rows, function ($a, $b) {
            return $this->minor($b['net_sales']) <=> $this->minor($a['net_sales']);
        });
        return $rows;
    }

    private function finalize_payment_totals(array $totals): array
    {
        $rows = [];
        foreach ($totals as $row) {
            foreach (['order_total', 'refunds', 'net_collected', 'tax_collected', 'tax_refunded', 'net_tax'] as $field) {
                $row[$field] = $this->decimal($row[$field]);
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function get_limitations(array $report): array
    {
        $limitations = [
            'This report helps prepare sales tax returns from this WooCommerce site; it is not a filed return or legal determination.',
            'Sales are selected by order-created date; refunds are selected by refund-created date so prior-period sales refunded in this period remain visible.',
            'It does not include sales from external marketplaces, POS systems, or other websites unless those transactions were imported as WooCommerce orders.',
            'Tax registrations, filing frequencies, exemption certificates, and marketplace-facilitator evidence are not reliably available from standard WooCommerce order data.',
            'Jurisdiction totals use the combined stored FFLA rate and its destination components when available; WooCommerce-only tax lines are marked Needs review.',
            'Rows marked Needs review require jurisdiction mapping or taxability review before filing.',
        ];
        if (count($report['manifest']['currencies'] ?? []) > 1) {
            $limitations[] = 'The report contains multiple currencies. Currency totals are intentionally kept separate and are not converted.';
        }
        return $limitations;
    }

    private function rows_have_cogs(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['item_type'] === 'product' && $row['cogs_value'] !== '') {
                return true;
            }
        }
        return false;
    }

    private function get_product_categories(int $product_id): string
    {
        if (!$product_id || !function_exists('wc_get_product_cat_ids')) {
            return '';
        }
        $names = [];
        foreach (wc_get_product_cat_ids($product_id) as $term_id) {
            $name = get_term_field('name', $term_id, 'product_cat');
            if (!is_wp_error($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return implode(' | ', $names);
    }

    private function date_value($date, bool $utc): string
    {
        if (!$date || !is_object($date) || !method_exists($date, 'getTimestamp')) {
            return '';
        }
        $timestamp = (int) $date->getTimestamp();
        return $utc ? gmdate('Y-m-d H:i:s', $timestamp) : wp_date('Y-m-d H:i:s', $timestamp, wp_timezone());
    }

    private function minor($value): int
    {
        return (int) round((float) $value * $this->scale);
    }

    private function decimal(int $minor): string
    {
        return number_format($minor / $this->scale, $this->precision, '.', '');
    }
}
