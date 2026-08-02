<?php
/**
 * WooCommerce tax reporting service.
 *
 * Builds accountant-ready workpapers from the values permanently recorded on
 * WooCommerce orders and refunds. It never recalculates historical taxes.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Report_Service
{
    const SCHEMA_VERSION = '1.0.0';
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
                'source_of_truth'  => 'WooCommerce final order, line-item, tax-line, and refund values',
                'calculation_note' => 'Historical taxes are reported as stored and are never recalculated by this report.',
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
                $this->aggregate_state($state_totals, $currency, $tax_location, $order_row, $line_rows);
                $this->aggregate_jurisdictions($jurisdiction_totals, $currency, $tax_location, $order_row, $quote, $tax_rows);
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
        $report['summaries']['states'] = $this->finalize_state_totals($state_totals);
        $report['summaries']['jurisdictions'] = $this->finalize_jurisdiction_totals($jurisdiction_totals);
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
            'state-summary' => [
                'country', 'state', 'currency', 'orders', 'gross_product_sales', 'discounts', 'net_product_sales',
                'shipping', 'fees', 'sales_with_tax', 'sales_without_tax', 'tax_collected', 'tax_refunded', 'net_tax',
                'refunds', 'order_total', 'net_collected',
            ],
            'jurisdiction-summary' => [
                'country', 'state', 'jurisdiction_type', 'jurisdiction_name', 'rate_percent', 'source', 'currency',
                'orders', 'tax_collected', 'tax_refunded', 'net_tax', 'allocation_method',
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
                'severity', 'code', 'order_id', 'order_number', 'date_created_local', 'country', 'state', 'currency',
                'amount', 'message', 'evidence',
            ],
        ];

        return apply_filters('ffla_tax_report_columns', isset($columns[$dataset]) ? $columns[$dataset] : [], $dataset);
    }

    /**
     * Save non-PII report generation metadata for the audit trail.
     */
    public static function record_run(array $manifest): void
    {
        $history = get_option(self::HISTORY_OPTION, []);
        if (!is_array($history)) {
            $history = [];
        }

        array_unshift($history, [
            'report_id'        => (string) ($manifest['report_id'] ?? ''),
            'generated_at_utc' => (string) ($manifest['generated_at_utc'] ?? ''),
            'filters'          => (array) ($manifest['filters'] ?? []),
            'stats'            => (array) ($manifest['stats'] ?? []),
            'currencies'       => (array) ($manifest['currencies'] ?? []),
            'files'            => (array) ($manifest['files'] ?? []),
        ]);

        update_option(self::HISTORY_OPTION, array_slice($history, 0, 50), false);
    }

    public static function get_recent_runs(int $limit = 10): array
    {
        $history = get_option(self::HISTORY_OPTION, []);
        return is_array($history) ? array_slice($history, 0, max(1, $limit)) : [];
    }

    private static function is_valid_date(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches)) {
            return false;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    private function build_order_row($order, array $quote, array $location, bool $include_pii, ?array $refunds = null): array
    {
        $refunds = $refunds === null ? $order->get_refunds() : $refunds;
        $gross = 0;
        $net_products = 0;
        foreach ($order->get_items('line_item') as $item) {
            $gross += $this->minor($item->get_subtotal());
            $net_products += $this->minor($item->get_total());
        }

        $fees = 0;
        foreach ($order->get_items('fee') as $item) {
            $fees += $this->minor($item->get_total());
        }

        $refunded_amount = 0;
        $refunded_tax = 0;
        foreach ($refunds as $refund) {
            $refunded_amount += method_exists($refund, 'get_amount')
                ? $this->minor(abs((float) $refund->get_amount()))
                : $this->minor(abs((float) $refund->get_total()));
            $refunded_tax += $this->minor(abs((float) $refund->get_total_tax()));
        }

        $tax_collected = $this->minor($order->get_total_tax());
        $order_total = $this->minor($order->get_total());
        $rate = isset($quote['totalRate']) ? (float) $quote['totalRate'] * 100 : 0;
        $quote_evidence = $this->sanitize_quote_evidence($quote, $include_pii);

        $shipping_address_formatted = '';
        if ($include_pii) {
            $shipping_name = trim((string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name());
            $shipping_region = trim((string) $order->get_shipping_state() . ' ' . (string) $order->get_shipping_postcode());
            $shipping_address_formatted = implode(', ', array_values(array_filter([
                $shipping_name,
                (string) $order->get_shipping_company(),
                (string) $order->get_shipping_address_1(),
                (string) $order->get_shipping_address_2(),
                (string) $order->get_shipping_city(),
                $shipping_region,
                (string) $order->get_shipping_country(),
            ], function ($part) {
                return trim((string) $part) !== '';
            })));
        }

        $row = [
            'order_id'               => (int) $order->get_id(),
            'order_number'           => (string) $order->get_order_number(),
            'date_created_local'      => $this->date_value($order->get_date_created(), false),
            'date_created_utc'        => $this->date_value($order->get_date_created(), true),
            'date_paid_local'         => $this->date_value($order->get_date_paid(), false),
            'status'                  => (string) $order->get_status(),
            'created_via'             => method_exists($order, 'get_created_via') ? (string) $order->get_created_via() : '',
            'currency'                => (string) $order->get_currency(),
            'customer_id'             => (int) $order->get_customer_id(),
            'payment_method'          => (string) $order->get_payment_method(),
            'payment_method_title'    => (string) $order->get_payment_method_title(),
            'transaction_id'          => $include_pii ? (string) $order->get_transaction_id() : '',
            'tax_address_source'      => $location['source'],
            'tax_country'             => $location['country'],
            'tax_state'               => $location['state'],
            'tax_city'                => $location['city'],
            'tax_postcode'            => $location['postcode'],
            'billing_first_name'      => $include_pii ? (string) $order->get_billing_first_name() : '',
            'billing_last_name'       => $include_pii ? (string) $order->get_billing_last_name() : '',
            'billing_company'         => $include_pii ? (string) $order->get_billing_company() : '',
            'billing_email'           => $include_pii ? (string) $order->get_billing_email() : '',
            'billing_phone'           => $include_pii ? (string) $order->get_billing_phone() : '',
            'billing_address_1'       => $include_pii ? (string) $order->get_billing_address_1() : '',
            'billing_address_2'       => $include_pii ? (string) $order->get_billing_address_2() : '',
            'billing_city'            => $include_pii ? (string) $order->get_billing_city() : '',
            'billing_state'           => (string) $order->get_billing_state(),
            'billing_postcode'        => (string) $order->get_billing_postcode(),
            'billing_country'         => (string) $order->get_billing_country(),
            'shipping_first_name'     => $include_pii ? (string) $order->get_shipping_first_name() : '',
            'shipping_last_name'      => $include_pii ? (string) $order->get_shipping_last_name() : '',
            'shipping_company'        => $include_pii ? (string) $order->get_shipping_company() : '',
            'shipping_address_1'      => $include_pii ? (string) $order->get_shipping_address_1() : '',
            'shipping_address_2'      => $include_pii ? (string) $order->get_shipping_address_2() : '',
            'shipping_city'           => $include_pii ? (string) $order->get_shipping_city() : '',
            'shipping_state'          => (string) $order->get_shipping_state(),
            'shipping_postcode'       => (string) $order->get_shipping_postcode(),
            'shipping_country'        => (string) $order->get_shipping_country(),
            'shipping_address_formatted'=> $shipping_address_formatted,
            'gross_product_sales'     => $this->decimal($gross),
            'discounts'               => $this->decimal(max(0, $gross - $net_products)),
            'net_product_sales'       => $this->decimal($net_products),
            'shipping'                => $this->decimal($this->minor($order->get_shipping_total())),
            'fees'                    => $this->decimal($fees),
            'tax_collected'           => $this->decimal($tax_collected),
            'tax_refunded'            => $this->decimal($refunded_tax),
            'net_tax'                 => $this->decimal($tax_collected - $refunded_tax),
            'refunds'                 => $this->decimal($refunded_amount),
            'order_total'             => $this->decimal($order_total),
            'net_collected'           => $this->decimal($order_total - $refunded_amount),
            'customer_tax_exempt'     => $this->is_order_tax_exempt($order) ? 'yes' : 'no',
            'tax_quote_query_id'      => (string) ($quote['queryId'] ?? $order->get_meta('_ffla_tax_query_id', true)),
            'tax_quote_source'        => (string) ($quote['source'] ?? $order->get_meta('_ffla_tax_source', true)),
            'tax_quote_outcome'       => is_scalar($quote['outcomeCode'] ?? '') ? (string) ($quote['outcomeCode'] ?? '') : wp_json_encode($quote['outcomeCode']),
            'tax_quote_rate_percent'  => number_format($rate, 4, '.', ''),
            'tax_quote_effective_date'=> (string) ($quote['effectiveDate'] ?? ''),
            'tax_quote_evidence_json' => !empty($quote_evidence) ? wp_json_encode($quote_evidence) : '',
            'snapshot_hash'           => (string) $order->get_meta('_ffla_tax_report_snapshot_hash', true),
            'customer_note'           => $include_pii ? (string) $order->get_customer_note() : '',
        ];

        return apply_filters('ffla_tax_report_order_row', $row, $order, $quote, $location);
    }

    private function build_line_rows($order, array $refund_map): array
    {
        $rows = [];
        $base = [
            'order_id'          => (int) $order->get_id(),
            'order_number'      => (string) $order->get_order_number(),
            'date_created_local'=> $this->date_value($order->get_date_created(), false),
            'status'            => (string) $order->get_status(),
            'currency'          => (string) $order->get_currency(),
            'tax_state'         => strtoupper((string) ($order->get_shipping_state() ?: $order->get_billing_state())),
        ];

        foreach ($order->get_items('line_item') as $item_id => $item) {
            $product = $item->get_product();
            $product_id = (int) $item->get_product_id();
            $variation_id = (int) $item->get_variation_id();
            $refund = $refund_map['line_item:' . $item_id] ?? ['amount' => 0, 'tax' => 0, 'quantity' => 0];
            $subtotal = $this->minor($item->get_subtotal());
            $total = $this->minor($item->get_total());
            $tax = $this->minor($item->get_total_tax());
            $cogs = '';
            if (method_exists($item, 'get_cogs_value')) {
                $value = $item->get_cogs_value();
                if ($value !== null && $value !== '') {
                    $cogs = $this->decimal($this->minor($value));
                }
            }

            $row = array_merge($base, [
                'item_id'            => (int) $item_id,
                'item_type'          => 'product',
                'product_id'         => $product_id,
                'variation_id'       => $variation_id,
                'sku'                => $product && method_exists($product, 'get_sku') ? (string) $product->get_sku() : (string) $item->get_meta('_SKU', true),
                'name'               => (string) $item->get_name(),
                'categories'         => $this->get_product_categories($product_id),
                'quantity'           => (string) $item->get_quantity(),
                'tax_class'          => (string) $item->get_tax_class(),
                'subtotal'           => $this->decimal($subtotal),
                'subtotal_tax'       => $this->decimal($this->minor($item->get_subtotal_tax())),
                'discount'           => $this->decimal(max(0, $subtotal - $total)),
                'total_ex_tax'       => $this->decimal($total),
                'tax'                => $this->decimal($tax),
                'total_inc_tax'      => $this->decimal($total + $tax),
                'refunded_quantity'  => (string) $refund['quantity'],
                'refunded_amount'    => $this->decimal((int) $refund['amount']),
                'refunded_tax'       => $this->decimal((int) $refund['tax']),
                'vendor'             => (string) $item->get_meta('Vendor', true),
                'vendor_sku'         => (string) $item->get_meta('_SKU', true),
                'vendor_price'       => (string) $item->get_meta('_Price', true),
                'shipping_class'     => (string) $item->get_meta('_ShippingClass', true),
                'shipping_method_id' => '',
                'coupon_code'        => '',
                'taxes_json'         => wp_json_encode($item->get_taxes()),
                'cogs_value'         => $cogs,
                'cogs_source'        => $cogs !== '' ? 'woocommerce' : '',
            ]);
            $rows[] = apply_filters('ffla_tax_report_line_row', $row, $item, $order);
        }

        foreach ($order->get_items('shipping') as $item_id => $item) {
            $refund = $refund_map['shipping:' . $item_id] ?? ['amount' => 0, 'tax' => 0, 'quantity' => 0];
            $total = $this->minor($item->get_total());
            $tax = $this->minor($item->get_total_tax());
            $row = array_merge($base, $this->empty_line_fields(), [
                'item_id'            => (int) $item_id,
                'item_type'          => 'shipping',
                'name'               => (string) $item->get_name(),
                'quantity'           => '1',
                'subtotal'           => $this->decimal($total),
                'total_ex_tax'       => $this->decimal($total),
                'tax'                => $this->decimal($tax),
                'total_inc_tax'      => $this->decimal($total + $tax),
                'refunded_amount'    => $this->decimal((int) $refund['amount']),
                'refunded_tax'       => $this->decimal((int) $refund['tax']),
                'shipping_method_id' => method_exists($item, 'get_method_id') ? (string) $item->get_method_id() : '',
                'taxes_json'         => wp_json_encode($item->get_taxes()),
            ]);
            $rows[] = apply_filters('ffla_tax_report_line_row', $row, $item, $order);
        }

        foreach ($order->get_items('fee') as $item_id => $item) {
            $refund = $refund_map['fee:' . $item_id] ?? ['amount' => 0, 'tax' => 0, 'quantity' => 0];
            $total = $this->minor($item->get_total());
            $tax = $this->minor($item->get_total_tax());
            $row = array_merge($base, $this->empty_line_fields(), [
                'item_id'           => (int) $item_id,
                'item_type'         => 'fee',
                'name'              => (string) $item->get_name(),
                'quantity'          => '1',
                'tax_class'         => method_exists($item, 'get_tax_class') ? (string) $item->get_tax_class() : '',
                'subtotal'          => $this->decimal($total),
                'total_ex_tax'      => $this->decimal($total),
                'tax'               => $this->decimal($tax),
                'total_inc_tax'     => $this->decimal($total + $tax),
                'refunded_amount'   => $this->decimal((int) $refund['amount']),
                'refunded_tax'      => $this->decimal((int) $refund['tax']),
                'taxes_json'        => wp_json_encode($item->get_taxes()),
            ]);
            $rows[] = apply_filters('ffla_tax_report_line_row', $row, $item, $order);
        }

        foreach ($order->get_items('coupon') as $item_id => $item) {
            $discount = $this->minor(method_exists($item, 'get_discount') ? $item->get_discount() : 0);
            $row = array_merge($base, $this->empty_line_fields(), [
                'item_id'      => (int) $item_id,
                'item_type'    => 'coupon',
                'name'         => (string) $item->get_name(),
                'coupon_code'  => method_exists($item, 'get_code') ? (string) $item->get_code() : (string) $item->get_name(),
                'quantity'     => '1',
                'discount'     => $this->decimal($discount),
                'total_ex_tax' => $this->decimal(-$discount),
                'tax'          => $this->decimal(-$this->minor(method_exists($item, 'get_discount_tax') ? $item->get_discount_tax() : 0)),
            ]);
            $rows[] = apply_filters('ffla_tax_report_line_row', $row, $item, $order);
        }

        return $rows;
    }

    private function empty_line_fields(): array
    {
        return array_fill_keys(array_slice(self::get_columns('order_lines'), 6), '');
    }

    private function build_tax_rows($order, ?array $refunds = null): array
    {
        $refunds = $refunds === null ? $order->get_refunds() : $refunds;
        $rows = [];
        $quote = $this->get_stored_quote($order);
        $refund_tax = $this->get_refunded_tax_by_rate($refunds);
        $total_refunded = (int) $refund_tax['total'];
        $items = $order->get_items('tax');
        $unassigned_refund = $total_refunded;

        foreach ($items as $item_id => $item) {
            $rate_id = (int) $item->get_rate_id();
            $collected = $this->minor($item->get_tax_total()) + $this->minor($item->get_shipping_tax_total());
            $refunded = 0;
            if ($rate_id && isset($refund_tax['rates'][$rate_id])) {
                $refunded = (int) $refund_tax['rates'][$rate_id];
            }
            $unassigned_refund -= $refunded;

            $rows[] = [
                'order_id'          => (int) $order->get_id(),
                'order_number'      => (string) $order->get_order_number(),
                'date_created_local'=> $this->date_value($order->get_date_created(), false),
                'status'            => (string) $order->get_status(),
                'currency'          => (string) $order->get_currency(),
                'tax_state'         => strtoupper((string) ($quote['state'] ?? ($order->get_shipping_state() ?: $order->get_billing_state()))),
                'tax_item_id'       => (int) $item_id,
                'rate_id'           => $rate_id,
                'rate_code'         => (string) $item->get_rate_code(),
                'label'             => (string) $item->get_label(),
                'rate_percent'      => method_exists($item, 'get_rate_percent') ? (string) $item->get_rate_percent() : '',
                'compound'          => $item->is_compound() ? 'yes' : 'no',
                'product_tax'       => $this->decimal($this->minor($item->get_tax_total())),
                'shipping_tax'      => $this->decimal($this->minor($item->get_shipping_tax_total())),
                'tax_collected'     => $this->decimal($collected),
                'tax_refunded'      => $this->decimal($refunded),
                'net_tax'           => $this->decimal($collected - $refunded),
                'tax_quote_source'  => (string) ($quote['source'] ?? $order->get_meta('_ffla_tax_source', true)),
                'tax_quote_query_id'=> (string) ($quote['queryId'] ?? $order->get_meta('_ffla_tax_query_id', true)),
            ];
        }

        // Older WooCommerce versions may not expose refunded tax by rate. In
        // that case allocate the known order-level refund proportionally while
        // preserving the exact total on the final line.
        if ($unassigned_refund > 0 && !empty($rows)) {
            $collected_total = array_sum(array_map(function ($row) {
                return $this->minor($row['tax_collected']);
            }, $rows));
            $allocated = 0;
            $last = count($rows) - 1;
            foreach ($rows as $index => &$row) {
                $share = ($index === $last)
                    ? $unassigned_refund - $allocated
                    : ($collected_total > 0 ? (int) round($unassigned_refund * ($this->minor($row['tax_collected']) / $collected_total)) : 0);
                $allocated += $share;
                $row_refund = $this->minor($row['tax_refunded']) + $share;
                $row['tax_refunded'] = $this->decimal($row_refund);
                $row['net_tax'] = $this->decimal($this->minor($row['tax_collected']) - $row_refund);
            }
            unset($row);
        }

        return $rows;
    }

    private function build_refund_rows($order, ?array $refunds = null): array
    {
        $refunds = $refunds === null ? $order->get_refunds() : $refunds;
        $rows = [];
        foreach ($refunds as $refund) {
            $product = 0;
            $shipping = 0;
            $fees = 0;
            $details = [];

            foreach (['line_item' => 'product', 'shipping' => 'shipping', 'fee' => 'fee'] as $item_type => $label) {
                foreach ($refund->get_items($item_type) as $item) {
                    $amount = $this->minor(abs((float) $item->get_total()));
                    if ($label === 'product') {
                        $product += $amount;
                    } elseif ($label === 'shipping') {
                        $shipping += $amount;
                    } else {
                        $fees += $amount;
                    }
                    $details[] = [
                        'type'             => $label,
                        'refunded_item_id' => (int) $item->get_meta('_refunded_item_id', true),
                        'name'             => (string) $item->get_name(),
                        'quantity'         => abs((float) $item->get_quantity()),
                        'amount'           => $this->decimal($amount),
                        'tax'              => $this->decimal($this->minor(abs((float) $item->get_total_tax()))),
                    ];
                }
            }

            $amount = method_exists($refund, 'get_amount')
                ? $this->minor(abs((float) $refund->get_amount()))
                : $this->minor(abs((float) $refund->get_total()));

            $rows[] = [
                'order_id'          => (int) $order->get_id(),
                'order_number'      => (string) $order->get_order_number(),
                'refund_id'         => (int) $refund->get_id(),
                'date_created_local'=> $this->date_value($refund->get_date_created(), false),
                'date_created_utc'  => $this->date_value($refund->get_date_created(), true),
                'currency'          => (string) $order->get_currency(),
                'reason'            => (string) $refund->get_reason(),
                'created_by'        => (int) $refund->get_refunded_by(),
                'amount'            => $this->decimal($amount),
                'tax_refunded'      => $this->decimal($this->minor(abs((float) $refund->get_total_tax()))),
                'product_refund'    => $this->decimal($product),
                'shipping_refund'   => $this->decimal($shipping),
                'fee_refund'        => $this->decimal($fees),
                'line_items_json'   => wp_json_encode($details),
            ];
        }

        return $rows;
    }

    private function get_line_refund_map($order, ?array $refunds = null): array
    {
        $refunds = $refunds === null ? $order->get_refunds() : $refunds;
        $map = [];
        foreach ($refunds as $refund) {
            foreach (['line_item', 'shipping', 'fee'] as $item_type) {
                foreach ($refund->get_items($item_type) as $item) {
                    $original_id = (int) $item->get_meta('_refunded_item_id', true);
                    if (!$original_id) {
                        continue;
                    }
                    $key = $item_type . ':' . $original_id;
                    if (!isset($map[$key])) {
                        $map[$key] = ['amount' => 0, 'tax' => 0, 'quantity' => 0];
                    }
                    $map[$key]['amount'] += $this->minor(abs((float) $item->get_total()));
                    $map[$key]['tax'] += $this->minor(abs((float) $item->get_total_tax()));
                    $map[$key]['quantity'] += abs((float) $item->get_quantity());
                }
            }
        }
        return $map;
    }

    private function get_refunds_in_period($order, int $from, int $to): array
    {
        $matches = [];
        foreach ($order->get_refunds() as $refund) {
            $date = $refund->get_date_created();
            if (!$date || !method_exists($date, 'getTimestamp')) {
                continue;
            }
            $timestamp = (int) $date->getTimestamp();
            if ($timestamp >= $from && $timestamp <= $to) {
                $matches[] = $refund;
            }
        }
        return $matches;
    }

    private function get_refunded_tax_by_rate(array $refunds): array
    {
        $result = ['total' => 0, 'rates' => []];
        foreach ($refunds as $refund) {
            $result['total'] += $this->minor(abs((float) $refund->get_total_tax()));
            foreach (['line_item', 'shipping', 'fee'] as $item_type) {
                foreach ($refund->get_items($item_type) as $item) {
                    if (!method_exists($item, 'get_taxes')) {
                        continue;
                    }
                    $taxes = $item->get_taxes();
                    if (empty($taxes['total']) || !is_array($taxes['total'])) {
                        continue;
                    }
                    foreach ($taxes['total'] as $rate_id => $amount) {
                        $rate_id = (int) $rate_id;
                        if (!$rate_id) {
                            continue;
                        }
                        if (!isset($result['rates'][$rate_id])) {
                            $result['rates'][$rate_id] = 0;
                        }
                        $result['rates'][$rate_id] += $this->minor(abs((float) $amount));
                    }
                }
            }
        }

        // Some gateways/refund workflows leave line-level tax data incomplete.
        // Any remainder is allocated across the original tax lines below.
        $known = array_sum($result['rates']);
        if ($known > $result['total']) {
            $result['rates'] = [];
        }
        return $result;
    }

    private function get_tax_location($order, array $quote): array
    {
        $based_on = get_option('woocommerce_tax_based_on', 'shipping');
        $source = $based_on;
        $country = '';
        $state = '';
        $city = '';
        $postcode = '';

        if ($this->is_local_pickup_order($order) || $based_on === 'base') {
            $countries = function_exists('WC') && WC()->countries ? WC()->countries : null;
            $source = $this->is_local_pickup_order($order) ? 'store_base_local_pickup' : 'store_base';
            if ($countries) {
                $country = (string) $countries->get_base_country();
                $state = (string) $countries->get_base_state();
                $city = (string) $countries->get_base_city();
                $postcode = (string) $countries->get_base_postcode();
            }
        } elseif ($based_on === 'billing') {
            $country = (string) $order->get_billing_country();
            $state = (string) $order->get_billing_state();
            $city = (string) $order->get_billing_city();
            $postcode = (string) $order->get_billing_postcode();
        } else {
            $country = (string) $order->get_shipping_country();
            $state = (string) $order->get_shipping_state();
            $city = (string) $order->get_shipping_city();
            $postcode = (string) $order->get_shipping_postcode();
            if ($country === '') {
                $source = 'billing_fallback';
                $country = (string) $order->get_billing_country();
                $state = (string) $order->get_billing_state();
                $city = (string) $order->get_billing_city();
                $postcode = (string) $order->get_billing_postcode();
            }
        }

        if (!empty($quote['state'])) {
            $state = (string) $quote['state'];
            $source .= '+stored_quote';
        }
        $matched = [];
        if (!empty($quote['matchedAddress']) && is_array($quote['matchedAddress'])) {
            $matched = $quote['matchedAddress'];
        } elseif (!empty($quote['normalizedAddress']) && is_array($quote['normalizedAddress'])) {
            $matched = $quote['normalizedAddress'];
        }
        if (!empty($matched)) {
            $city = (string) ($matched['city'] ?? $city);
            $postcode = (string) ($matched['zip'] ?? $matched['postcode'] ?? $postcode);
        }

        return [
            'source'   => $source,
            'country'  => strtoupper($country),
            'state'    => strtoupper($state),
            'city'     => $city,
            'postcode' => $postcode,
        ];
    }

    private function is_local_pickup_order($order): bool
    {
        foreach ($order->get_items('shipping') as $item) {
            $method = method_exists($item, 'get_method_id') ? (string) $item->get_method_id() : '';
            if (in_array($method, ['local_pickup', 'legacy_local_pickup', 'pickup_location'], true)) {
                return true;
            }
        }
        return false;
    }

    private function get_stored_quote($order): array
    {
        $value = $order->get_meta('_ffla_tax_quote', true);
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function sanitize_quote_evidence(array $quote, bool $include_pii): array
    {
        if ($include_pii || empty($quote)) {
            return $quote;
        }

        $allowed = array_flip(['country', 'countryCode', 'state', 'stateCode', 'city', 'zip', 'postcode', 'postalCode']);
        foreach (['inputAddress', 'normalizedAddress', 'matchedAddress'] as $key) {
            if (!array_key_exists($key, $quote)) {
                continue;
            }
            if (is_array($quote[$key])) {
                $quote[$key] = array_intersect_key($quote[$key], $allowed);
            } else {
                // A formatted matched-address string may contain a full street.
                unset($quote[$key]);
            }
        }

        return $quote;
    }

    private function is_order_tax_exempt($order): bool
    {
        $keys = ['_vat_exempt', 'is_vat_exempt', '_billing_tax_exempt', '_woocommerce_customer_tax_exempt'];
        foreach ($keys as $key) {
            $value = strtolower((string) $order->get_meta($key, true));
            if (in_array($value, ['1', 'yes', 'true', 'on'], true)) {
                return true;
            }
        }
        return (bool) apply_filters('ffla_tax_report_order_is_exempt', false, $order);
    }

    private function detect_exceptions($order, array $order_row, array $line_rows, array $tax_rows, array $refund_rows, array $quote, array $location): array
    {
        $exceptions = [];
        $base = [
            'order_id'          => $order_row['order_id'],
            'order_number'      => $order_row['order_number'],
            'date_created_local'=> $order_row['date_created_local'],
            'country'           => $location['country'],
            'state'             => $location['state'],
            'currency'          => $order_row['currency'],
        ];
        $add = function (string $severity, string $code, string $message, $amount = '', string $evidence = '') use (&$exceptions, $base): void {
            $exceptions[] = array_merge($base, [
                'severity' => $severity,
                'code'     => $code,
                'amount'   => $amount,
                'message'  => $message,
                'evidence' => $evidence,
            ]);
        };

        if ($location['country'] === '' || ($location['country'] === 'US' && $location['state'] === '')) {
            $add('error', 'missing_tax_address', 'The order does not contain enough destination data to assign a tax state.');
        }
        if ($location['country'] === 'US' && $location['postcode'] === '') {
            $add('warning', 'missing_tax_postcode', 'The US tax address has no postal code.');
        }
        if ($order_row['snapshot_hash'] === '') {
            $add('info', 'missing_fiscal_snapshot', 'No permanent FFLA fiscal snapshot exists for this historical order. The report still uses the current stored WooCommerce values.');
        }
        if (empty($quote) && $this->minor($order_row['tax_collected']) > 0) {
            $add('info', 'missing_tax_quote', 'Tax was collected but no FFLA resolver quote is attached to the order.', $order_row['tax_collected']);
        }
        if (!empty($quote) && isset($quote['outcomeCode'])) {
            $outcome = is_scalar($quote['outcomeCode']) ? strtoupper((string) $quote['outcomeCode']) : '';
            if ($outcome !== '' && !in_array($outcome, ['SUCCESS', 'NO_SALES_TAX'], true)) {
                $add('warning', 'degraded_tax_quote', 'The stored resolver quote did not report a normal success outcome.', '', wp_json_encode($quote['outcomeCode']));
            }
        }

        $tax_total = $this->minor($order_row['tax_collected']);
        $tax_lines_total = 0;
        foreach ($tax_rows as $tax_row) {
            $tax_lines_total += $this->minor($tax_row['tax_collected']);
        }
        if (abs($tax_total - $tax_lines_total) > 1) {
            $add('error', 'tax_line_mismatch', 'Order tax total does not match the sum of WooCommerce tax lines.', $this->decimal($tax_total - $tax_lines_total));
        }

        $line_net = 0;
        $line_tax = 0;
        foreach ($line_rows as $line) {
            if ($line['item_type'] === 'coupon') {
                continue;
            }
            $line_net += $this->minor($line['total_ex_tax']);
            $line_tax += $this->minor($line['tax']);
        }
        $expected_total = $line_net + $line_tax;
        $order_total = $this->minor($order_row['order_total']);
        if (abs($expected_total - $order_total) > 1) {
            $add('warning', 'order_total_reconciliation', 'Order total does not reconcile to exported product, shipping, fee, and tax lines.', $this->decimal($order_total - $expected_total));
        }

        $refund_tax = 0;
        foreach ($refund_rows as $refund) {
            $refund_tax += $this->minor($refund['tax_refunded']);
        }
        if (abs($refund_tax - $this->minor($order_row['tax_refunded'])) > 1) {
            $add('error', 'refund_tax_mismatch', 'Order refunded tax does not match the refund records.', $this->decimal($this->minor($order_row['tax_refunded']) - $refund_tax));
        }

        $sales_before_tax = $this->minor($order_row['net_product_sales']) + $this->minor($order_row['shipping']) + $this->minor($order_row['fees']);
        if ($location['country'] === 'US' && $sales_before_tax > 0 && $tax_total === 0) {
            if (strtoupper((string) ($quote['outcomeCode'] ?? '')) === 'NO_SALES_TAX') {
                $add('info', 'no_sales_tax_quote', 'No tax was collected and the stored resolver quote identifies a no-sales-tax result.', $this->decimal($sales_before_tax));
            } elseif ($order_row['customer_tax_exempt'] === 'yes') {
                $add('info', 'tax_exempt_order', 'No tax was collected and the order contains a tax-exempt indicator.', $this->decimal($sales_before_tax));
            } else {
                $add('warning', 'no_tax_collected', 'No tax was collected on a positive-value US order. Review nexus, product taxability, and exemption evidence.', $this->decimal($sales_before_tax));
            }
        }
        if ($location['country'] !== '' && $location['country'] !== 'US' && $tax_total > 0) {
            $add('info', 'non_us_tax_collected', 'Tax was collected for a destination outside the United States.', $order_row['tax_collected']);
        }
        if ($order_total < 0) {
            $add('error', 'negative_order_total', 'The order has a negative final total.', $order_row['order_total']);
        }
        if ($order_row['created_via'] === 'admin') {
            $add('info', 'manual_order', 'This order was created in the WooCommerce administrator and may include manually entered values.');
        }

        return apply_filters('ffla_tax_report_order_exceptions', $exceptions, $order, $order_row, $quote);
    }

    private function aggregate_currency(array &$totals, string $currency, array $order): void
    {
        if (!isset($totals[$currency])) {
            $totals[$currency] = $this->new_money_bucket();
            $totals[$currency]['currency'] = $currency;
        }
        $totals[$currency]['orders']++;
        $this->add_order_money($totals[$currency], $order);
    }

    private function aggregate_state(array &$totals, string $currency, array $location, array $order, array $lines): void
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
        }
        $totals[$key]['orders']++;
        $this->add_order_money($totals[$key], $order);

        foreach ($lines as $line) {
            if (!in_array($line['item_type'], ['product', 'shipping', 'fee'], true)) {
                continue;
            }
            $field = $this->minor($line['tax']) !== 0 ? 'sales_with_tax' : 'sales_without_tax';
            $totals[$key][$field] += $this->minor($line['total_ex_tax']);
        }
    }

    private function aggregate_jurisdictions(array &$totals, string $currency, array $location, array $order, array $quote, array $tax_rows): void
    {
        $collected = $this->minor($order['tax_collected']);
        $refunded = $this->minor($order['tax_refunded']);
        $breakdown = isset($quote['breakdown']) && is_array($quote['breakdown']) ? $quote['breakdown'] : [];
        $valid = [];
        foreach ($breakdown as $item) {
            $rate = isset($item['rate']) ? (float) $item['rate'] : 0;
            if ($rate > 0) {
                $item['_rate'] = $rate;
                $valid[] = $item;
            }
        }

        if (!empty($valid)) {
            $rate_total = array_sum(array_column($valid, '_rate'));
            $collected_allocated = 0;
            $refunded_allocated = 0;
            $last = count($valid) - 1;
            foreach ($valid as $index => $item) {
                $part_collected = $index === $last ? $collected - $collected_allocated : (int) round($collected * ($item['_rate'] / $rate_total));
                $part_refunded = $index === $last ? $refunded - $refunded_allocated : (int) round($refunded * ($item['_rate'] / $rate_total));
                $collected_allocated += $part_collected;
                $refunded_allocated += $part_refunded;
                $type = (string) ($item['type'] ?? $item['jurisdictionType'] ?? 'jurisdiction');
                $name = (string) ($item['name'] ?? $item['jurisdiction'] ?? $item['label'] ?? $location['state']);
                $this->add_jurisdiction_bucket(
                    $totals,
                    $location,
                    $currency,
                    $type,
                    $name,
                    (float) $item['_rate'] * 100,
                    (string) ($quote['source'] ?? ''),
                    $part_collected,
                    $part_refunded,
                    'proportional_stored_quote',
                    (int) $order['order_id']
                );
            }
            return;
        }

        foreach ($tax_rows as $tax) {
            $this->add_jurisdiction_bucket(
                $totals,
                $location,
                $currency,
                'woocommerce_tax_line',
                (string) ($tax['label'] ?: $tax['rate_code']),
                (float) $tax['rate_percent'],
                (string) $tax['tax_quote_source'],
                $this->minor($tax['tax_collected']),
                $this->minor($tax['tax_refunded']),
                'woocommerce_tax_line',
                (int) $order['order_id']
            );
        }
    }

    private function add_jurisdiction_bucket(array &$totals, array $location, string $currency, string $type, string $name, float $rate, string $source, int $collected, int $refunded, string $method, int $order_id): void
    {
        $country = $location['country'] !== '' ? $location['country'] : '(none)';
        $state = $location['state'] !== '' ? $location['state'] : '(none)';
        $rate_formatted = number_format($rate, 4, '.', '');
        $key = implode('|', [$country, $state, $type, $name, $rate_formatted, $source, $currency, $method]);
        if (!isset($totals[$key])) {
            $totals[$key] = [
                'country' => $country,
                'state' => $state,
                'jurisdiction_type' => $type,
                'jurisdiction_name' => $name,
                'rate_percent' => $rate_formatted,
                'source' => $source,
                'currency' => $currency,
                'order_ids' => [],
                'tax_collected' => 0,
                'tax_refunded' => 0,
                'net_tax' => 0,
                'allocation_method' => $method,
            ];
        }
        $totals[$key]['order_ids'][$order_id] = true;
        $totals[$key]['tax_collected'] += $collected;
        $totals[$key]['tax_refunded'] += $refunded;
        $totals[$key]['net_tax'] += $collected - $refunded;
    }

    private function aggregate_products(array &$totals, string $currency, array $lines): void
    {
        foreach ($lines as $line) {
            if ($line['item_type'] !== 'product') {
                continue;
            }
            $key = implode('|', [$line['product_id'], $line['variation_id'], $line['sku'], $currency]);
            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'product_id' => $line['product_id'],
                    'variation_id' => $line['variation_id'],
                    'sku' => $line['sku'],
                    'product_name' => $line['name'],
                    'categories' => $line['categories'],
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
            $totals[$key]['order_ids'][(int) $line['order_id']] = true;
            $totals[$key]['quantity'] += (float) $line['quantity'];
            $totals[$key]['gross_sales'] += $this->minor($line['subtotal']);
            $totals[$key]['discounts'] += $this->minor($line['discount']);
            $totals[$key]['net_sales'] += $this->minor($line['total_ex_tax']);
            $totals[$key]['tax'] += $this->minor($line['tax']);
            $totals[$key]['refunded_amount'] += $this->minor($line['refunded_amount']);
            if ($line['cogs_value'] !== '') {
                $totals[$key]['cogs_value'] += $this->minor($line['cogs_value']);
                $totals[$key]['has_cogs'] = true;
            }
        }
    }

    private function aggregate_payment(array &$totals, string $currency, array $order): void
    {
        $key = $order['payment_method'] . '|' . $currency;
        if (!isset($totals[$key])) {
            $totals[$key] = [
                'payment_method' => $order['payment_method'],
                'payment_method_title' => $order['payment_method_title'],
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
        $totals[$key]['orders']++;
        foreach (['order_total', 'refunds', 'net_collected', 'tax_collected', 'tax_refunded', 'net_tax'] as $field) {
            $totals[$key][$field] += $this->minor($order[$field]);
        }
    }

    private function aggregate_exceptions(array &$totals, array $exceptions): void
    {
        foreach ($exceptions as $exception) {
            $key = $exception['severity'] . '|' . $exception['code'];
            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'severity' => $exception['severity'],
                    'code' => $exception['code'],
                    'count' => 0,
                    'message' => $exception['message'],
                ];
            }
            $totals[$key]['count']++;
        }
    }

    private function add_unseen_period_refunds(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        array $seen,
        bool $summary_only,
        int $exception_limit,
        array &$report,
        array &$currency_totals,
        array &$state_totals,
        array &$jurisdiction_totals,
        array &$product_totals,
        array &$payment_totals,
        array &$exception_totals,
        array &$currencies
    ): void {
        $page = 1;
        $pages = 1;
        do {
            $result = wc_get_orders([
                'type'         => 'shop_order_refund',
                'date_created' => $from->getTimestamp() . '...' . $to->getTimestamp(),
                'orderby'      => 'date',
                'order'        => 'ASC',
                'limit'        => 200,
                'page'         => $page,
                'paginate'     => true,
                'return'       => 'objects',
            ]);
            $refunds = is_object($result) && isset($result->orders) ? $result->orders : (is_array($result) ? $result : []);
            $pages = is_object($result) && isset($result->max_num_pages) ? max(1, (int) $result->max_num_pages) : 1;

            foreach ($refunds as $refund) {
                $refund_id = is_object($refund) && method_exists($refund, 'get_id') ? (int) $refund->get_id() : 0;
                if (!$refund_id || isset($seen[$refund_id])) {
                    continue;
                }
                $order = wc_get_order((int) $refund->get_parent_id());
                if (!is_a($order, 'WC_Order')) {
                    continue;
                }

                $rows = $this->build_refund_rows($order, [$refund]);
                if (empty($rows)) {
                    continue;
                }
                $refund_row = $rows[0];
                $quote = $this->get_stored_quote($order);
                $location = $this->get_tax_location($order, $quote);
                $currency = (string) ($refund_row['currency'] ?: '(none)');
                $amount = $this->minor($refund_row['amount']);
                $tax = $this->minor($refund_row['tax_refunded']);
                $currencies[$currency] = true;
                $report['stats']['refunds']++;

                if (!$summary_only) {
                    $report['refunds'][] = $refund_row;
                }

                $this->add_refund_adjustment_to_currency($currency_totals, $currency, $amount, $tax);
                $this->add_refund_adjustment_to_state($state_totals, $currency, $location, $amount, $tax);
                $this->add_refund_adjustment_to_payment($payment_totals, $currency, $order, $amount, $tax);
                $this->add_refund_adjustment_to_jurisdiction($jurisdiction_totals, $currency, $location, $order, $quote, $tax);
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

    private function add_refund_adjustment_to_state(array &$totals, string $currency, array $location, int $amount, int $tax): void
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
        }
        $totals[$key]['refunds'] += $amount;
        $totals[$key]['tax_refunded'] += $tax;
        $totals[$key]['net_tax'] -= $tax;
        $totals[$key]['net_collected'] -= $amount;
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

    private function add_refund_adjustment_to_jurisdiction(array &$totals, string $currency, array $location, $order, array $quote, int $tax): void
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
            $allocated = 0;
            $last = count($breakdown) - 1;
            foreach ($breakdown as $index => $item) {
                $part = $index === $last ? $tax - $allocated : (int) round($tax * ((float) $item['rate'] / $rate_total));
                $allocated += $part;
                $this->add_jurisdiction_bucket(
                    $totals,
                    $location,
                    $currency,
                    (string) ($item['type'] ?? 'jurisdiction'),
                    (string) ($item['jurisdiction'] ?? $item['name'] ?? $location['state']),
                    (float) $item['rate'] * 100,
                    (string) ($quote['source'] ?? ''),
                    0,
                    $part,
                    'proportional_stored_quote',
                    (int) $order->get_id()
                );
            }
            return;
        }

        $this->add_jurisdiction_bucket($totals, $location, $currency, 'refund', 'Unallocated refund tax', 0, '', 0, $tax, 'refund_total_only', (int) $order->get_id());
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
            foreach (['gross_product_sales', 'discounts', 'net_product_sales', 'shipping', 'fees', 'sales_with_tax', 'sales_without_tax', 'tax_collected', 'tax_refunded', 'net_tax', 'refunds', 'order_total', 'net_collected'] as $field) {
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
            foreach (['tax_collected', 'tax_refunded', 'net_tax'] as $field) {
                $row[$field] = $this->decimal($row[$field]);
            }
            $rows[] = $row;
        }
        usort($rows, function ($a, $b) {
            return strcmp($a['country'] . $a['state'] . $a['jurisdiction_type'] . $a['jurisdiction_name'], $b['country'] . $b['state'] . $b['jurisdiction_type'] . $b['jurisdiction_name']);
        });
        return $rows;
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
            'This package is an accounting workpaper generated from this WooCommerce site; it is not a filed tax return or legal determination.',
            'Sales are selected by order-created date; refunds are selected by refund-created date so prior-period sales refunded in this period remain visible.',
            'It does not include sales from external marketplaces, POS systems, or other websites unless those transactions were imported as WooCommerce orders.',
            'Payment processor fees, deposits, chargebacks, and Form 1099-K values must be reconciled with processor and bank statements.',
            'Tax registrations, filing frequencies, exemption certificates, and marketplace-facilitator evidence are not reliably available from standard WooCommerce order data.',
            'Jurisdiction allocations use the stored FFLA quote when available; otherwise the report preserves WooCommerce tax lines without inventing a jurisdiction.',
        ];
        if ((int) $report['stats']['orders_with_cogs'] === 0) {
            $limitations[] = 'No WooCommerce cost-of-goods values were found. Inventory and COGS must be supplied by the accounting or inventory system.';
        }
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
