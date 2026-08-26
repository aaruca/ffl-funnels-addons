<?php
/**
 * WooCommerce Analytics reconciliation for FFLA tax reports.
 *
 * This class is intentionally standalone. It does not register hooks or alter
 * the tax report UI; callers may instantiate it when reconciliation is needed.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

if (class_exists('Tax_Report_Reconciliation', false)) {
    return;
}

class Tax_Report_Reconciliation
{
    const SCHEMA_VERSION = '1.0.0';
    const DEFAULT_TOLERANCE_MINOR = 1;
    const DEFAULT_PAGE_SIZE = 100;
    const DEFAULT_MAX_RECORDS = 10000;
    const ABSOLUTE_MAX_RECORDS = 100000;

    /** @var int */
    private $precision;

    /** @var int */
    private $scale;

    /**
     * @param int|null $precision WooCommerce currency precision. Defaults to the store setting.
     */
    public function __construct($precision = null)
    {
        if ($precision === null) {
            $precision = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
        }

        $this->precision = max(0, min(6, (int) $precision));
        $this->scale = (int) pow(10, $this->precision);
    }

    /**
     * Compare an FFLA report with WooCommerce Analytics tax totals.
     *
     * Supported options:
     * - date_from/date_to: YYYY-MM-DD. Defaults to the report manifest filters.
     * - state/states: Optional destination state code or list. State-scoped comparisons use
     *   the HPOS-safe WooCommerce order API because the Analytics tax DataStore
     *   does not expose an address-state filter.
     * - currency: Optional currency code. Required when the report contains
     *   more than one currency.
     * - statuses: Optional order status slugs. Defaults to the report filters.
     * - include_negative_orders: Mirrors the report's negative-order filter.
     * - tolerance_minor: Money tolerance in minor units. Defaults to one unit.
     * - page_size/max_records: Bounds for the order-API fallback.
     * - allow_order_fallback: Use the bounded order API if Analytics is absent.
     * - ffla_totals: Optional explicit FFLA totals for callers with a compact
     *   report. Accepted keys are tax_total/net_tax, product_tax, shipping_tax,
     *   and orders_count; monetary values are decimal major-unit values.
     *
     * The method always returns a structured result and does not throw errors
     * caused by an unavailable or stale WooCommerce Analytics DataStore.
     *
     * @param array $ffla_report Generated FFLA tax report.
     * @param array $options     Reconciliation options.
     * @return array
     */
    public function reconcile(array $ffla_report, array $options = []): array
    {
        $result = $this->new_result();

        try {
            $scope = $this->build_scope($ffla_report, $options);
            $ffla = $this->extract_ffla_totals($ffla_report, $scope, $options);
            $woocommerce = $this->get_woocommerce_totals($scope, $options);

            $result['scope'] = $this->public_scope($scope);
            $result['ffla'] = $this->public_totals($ffla);
            $result['woocommerce'] = $this->public_totals($woocommerce);
            $result['sources'] = [
                'ffla' => (string) ($ffla['source'] ?? 'ffla_report'),
                'woocommerce' => (string) ($woocommerce['source'] ?? 'unavailable'),
                'analytics_datastore_available' => !empty($woocommerce['analytics_datastore_available']),
            ];

            $range_comparable = !empty($scope['report_range_matches']);
            $currency_comparable = empty($scope['currency_ambiguous']);
            $date_basis_comparable = $scope['analytics_date_type'] === $scope['ffla_date_type'];
            $woo_complete = !empty($woocommerce['available']) && empty($woocommerce['truncated']);
            $tax_comparable = !empty($ffla['tax_total_available'])
                && $woo_complete
                && $range_comparable
                && $currency_comparable
                && $date_basis_comparable;

            $result['checks']['date_range'] = $this->date_range_check($scope);
            $result['checks']['date_basis'] = $this->date_basis_check($scope, $woocommerce);
            $result['checks']['tax_total'] = $this->money_check(
                'tax_total',
                'Total tax',
                $ffla['tax_total_minor'],
                $woocommerce['tax_total_minor'],
                $scope['tolerance_minor'],
                $tax_comparable
            );
            $result['checks']['product_tax'] = $this->money_check(
                'product_tax',
                'Product and fee tax',
                $ffla['product_tax_minor'],
                $woocommerce['product_tax_minor'],
                $scope['tolerance_minor'],
                $tax_comparable && !empty($ffla['components_available']) && !empty($woocommerce['components_available'])
            );
            $result['checks']['shipping_tax'] = $this->money_check(
                'shipping_tax',
                'Shipping tax',
                $ffla['shipping_tax_minor'],
                $woocommerce['shipping_tax_minor'],
                $scope['tolerance_minor'],
                $tax_comparable && !empty($ffla['components_available']) && !empty($woocommerce['components_available'])
            );
            $result['checks']['order_count'] = $this->count_check(
                $ffla['orders_count'],
                $woocommerce['orders_count'],
                $woo_complete
                    && $range_comparable
                    && $currency_comparable
                    && $date_basis_comparable
                    && !empty($ffla['orders_count_available'])
                    && !empty($woocommerce['orders_count_available'])
            );

            $result['warnings'] = $this->build_warnings($scope, $ffla, $woocommerce);
            $result['recommendations'] = $this->build_recommendations(
                $result['checks'],
                $scope,
                $ffla,
                $woocommerce,
                $ffla_report
            );
            $result['available'] = !empty($ffla['tax_total_available']) && !empty($woocommerce['available']);
            $result['comparable'] = $tax_comparable;
            $result['meta'] = [
                'precision' => $this->precision,
                'scale' => $this->scale,
                'tolerance_minor' => $scope['tolerance_minor'],
                'processed_records' => (int) ($woocommerce['processed_records'] ?? 0),
                'max_records' => $scope['max_records'],
                'truncated' => !empty($woocommerce['truncated']),
                'unresolved_refunds' => (int) ($woocommerce['unresolved_refunds'] ?? 0),
                'ffla_component_quality' => (string) ($ffla['component_quality'] ?? 'unavailable'),
                'ffla_detail_truncated' => !empty($ffla['detail_truncated']),
            ];
            $result['status'] = $this->overall_status($result['checks'], $result['warnings']);
        } catch (Throwable $error) {
            $message = $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : 'The reconciliation service could not complete the comparison safely.';

            $result['checks']['date_range'] = $this->unavailable_check('date_range', 'Date range', $message);
            foreach (['date_basis', 'tax_total', 'product_tax', 'shipping_tax', 'order_count'] as $check_id) {
                $result['checks'][$check_id] = $this->unavailable_check(
                    $check_id,
                    ucwords(str_replace('_', ' ', $check_id)),
                    'This check was not run because the reconciliation scope is invalid or unavailable.'
                );
            }
            $result['warnings'][] = $message;
            $result['recommendations'][] = 'Verify that the FFLA report has a valid manifest, date range, and filing totals, then run the reconciliation again.';
            $result['meta']['error_type'] = get_class($error);
        }

        return $result;
    }

    /**
     * Report whether the native Analytics tax DataStore can be autoloaded.
     */
    public static function analytics_datastore_available(): bool
    {
        $class = '\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Taxes\\Stats\\DataStore';

        return class_exists($class) || class_exists('WC_Data_Store');
    }

    private function new_result(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at_utc' => gmdate('c'),
            'status' => 'warn',
            'available' => false,
            'comparable' => false,
            'scope' => [],
            'sources' => [
                'ffla' => 'ffla_report',
                'woocommerce' => 'unavailable',
                'analytics_datastore_available' => false,
            ],
            'ffla' => [],
            'woocommerce' => [],
            'checks' => [],
            'warnings' => [],
            'recommendations' => [],
            'meta' => [
                'precision' => $this->precision,
                'scale' => $this->scale,
            ],
        ];
    }

    private function build_scope(array $report, array $options): array
    {
        $filters = isset($report['manifest']['filters']) && is_array($report['manifest']['filters'])
            ? $report['manifest']['filters']
            : [];
        $report_from = isset($filters['date_from']) ? (string) $filters['date_from'] : '';
        $report_to = isset($filters['date_to']) ? (string) $filters['date_to'] : '';
        $date_from = isset($options['date_from']) ? (string) $options['date_from'] : $report_from;
        $date_to = isset($options['date_to']) ? (string) $options['date_to'] : $report_to;

        $timezone = $this->site_timezone();
        $from = $this->parse_date($date_from, false, $timezone);
        $to = $this->parse_date($date_to, true, $timezone);

        if ($from > $to) {
            throw new InvalidArgumentException('The reconciliation start date must be before the end date.');
        }

        $requested_states = [];
        if (isset($options['states']) && is_array($options['states'])) {
            $requested_states = $options['states'];
        } elseif (isset($options['state'])) {
            $requested_states = [$options['state']];
        } elseif (isset($filters['states']) && is_array($filters['states'])) {
            $requested_states = $filters['states'];
        } elseif (isset($filters['state'])) {
            $requested_states = [$filters['state']];
        }
        $states = [];
        foreach ($requested_states as $requested_state) {
            $requested_state = $this->normalize_state($requested_state);
            if ($requested_state !== '') {
                $states[$requested_state] = $requested_state;
            }
        }
        $states = array_values($states);
        $state = count($states) === 1 ? reset($states) : '';

        $currencies = $this->collect_currencies($report, $states);
        $currency = isset($options['currency']) ? $this->normalize_currency($options['currency']) : '';
        if ($currency === '' && count($currencies) === 1) {
            $currency = reset($currencies);
        }

        $statuses = isset($options['statuses']) && is_array($options['statuses'])
            ? $options['statuses']
            : (isset($filters['statuses']) && is_array($filters['statuses']) ? $filters['statuses'] : []);
        $statuses = $this->normalize_statuses($statuses);

        $analytics_date_type = function_exists('get_option')
            ? (string) get_option('woocommerce_date_type', 'date_paid')
            : 'date_paid';
        if (!in_array($analytics_date_type, ['date_created', 'date_created_gmt', 'date_paid', 'date_completed'], true)) {
            $analytics_date_type = 'date_paid';
        }

        $page_size = isset($options['page_size']) ? (int) $options['page_size'] : self::DEFAULT_PAGE_SIZE;
        $page_size = max(10, min(250, $page_size));
        $max_records = isset($options['max_records']) ? (int) $options['max_records'] : self::DEFAULT_MAX_RECORDS;
        $max_records = max($page_size, min(self::ABSOLUTE_MAX_RECORDS, $max_records));
        $tolerance = isset($options['tolerance_minor'])
            ? max(0, (int) $options['tolerance_minor'])
            : self::DEFAULT_TOLERANCE_MINOR;

        return [
            'date_from' => $date_from,
            'date_to' => $date_to,
            'report_date_from' => $report_from,
            'report_date_to' => $report_to,
            'report_range_matches' => $report_from !== ''
                && $report_to !== ''
                && $report_from === $date_from
                && $report_to === $date_to,
            'from_timestamp' => $from->getTimestamp(),
            'to_timestamp' => $to->getTimestamp(),
            'timezone' => $timezone->getName(),
            'state' => $state,
            'states' => $states,
            'currency' => $currency,
            'report_currencies' => array_values($currencies),
            'currency_ambiguous' => count($currencies) > 1 && $currency === '',
            'statuses' => $statuses,
            'include_negative_orders' => array_key_exists('include_negative_orders', $options)
                ? !empty($options['include_negative_orders'])
                : !empty($filters['include_negative_orders']),
            'analytics_date_type' => $analytics_date_type,
            'ffla_date_type' => 'date_created',
            'tolerance_minor' => $tolerance,
            'page_size' => $page_size,
            'max_records' => $max_records,
        ];
    }

    private function public_scope(array $scope): array
    {
        return [
            'date_from' => $scope['date_from'],
            'date_to' => $scope['date_to'],
            'report_date_from' => $scope['report_date_from'],
            'report_date_to' => $scope['report_date_to'],
            'timezone' => $scope['timezone'],
            'state' => $scope['state'],
            'states' => $scope['states'],
            'currency' => $scope['currency'],
            'report_currencies' => $scope['report_currencies'],
            'statuses' => $scope['statuses'],
            'include_negative_orders' => $scope['include_negative_orders'],
            'ffla_date_type' => $scope['ffla_date_type'],
            'analytics_date_type' => $scope['analytics_date_type'],
        ];
    }

    private function extract_ffla_totals(array $report, array $scope, array $options): array
    {
        $totals = $this->empty_totals('ffla_report');
        $explicit = isset($options['ffla_totals']) && is_array($options['ffla_totals'])
            ? $options['ffla_totals']
            : [];

        $summary_rows = !empty($scope['states'])
            ? ($report['summaries']['states'] ?? [])
            : ($report['summaries']['filing_totals'] ?? []);
        if (!is_array($summary_rows)) {
            $summary_rows = [];
        }

        foreach ($summary_rows as $row) {
            if (!is_array($row) || !$this->row_matches_scope($row, $scope)) {
                continue;
            }
            if (!empty($scope['states']) && !in_array($this->normalize_state($row['state'] ?? ''), $scope['states'], true)) {
                continue;
            }
            $totals['tax_total_minor'] += $this->minor($row['net_tax'] ?? 0);
            $totals['reported_orders_count'] += (int) ($row['orders'] ?? 0);
            $totals['tax_total_available'] = true;
        }

        if (!$totals['tax_total_available'] && empty($scope['states'])) {
            $currency_rows = isset($report['totals_by_currency']) && is_array($report['totals_by_currency'])
                ? $report['totals_by_currency']
                : [];
            foreach ($currency_rows as $row) {
                if (!is_array($row) || !$this->row_matches_scope($row, $scope)) {
                    continue;
                }
                $totals['tax_total_minor'] += $this->minor($row['net_tax'] ?? 0);
                $totals['reported_orders_count'] += (int) ($row['orders'] ?? 0);
                $totals['tax_total_available'] = true;
            }
        }

        if (array_key_exists('tax_total', $explicit) || array_key_exists('net_tax', $explicit)) {
            $totals['tax_total_minor'] = $this->minor(
                array_key_exists('tax_total', $explicit) ? $explicit['tax_total'] : $explicit['net_tax']
            );
            $totals['tax_total_available'] = true;
            $totals['source'] = 'explicit_ffla_totals';
        }

        $order_state_result = !empty($scope['states'])
            ? $this->build_order_state_map($report, $scope['max_records'])
            : ['states' => [], 'truncated' => false];
        $order_states = $order_state_result['states'];
        $tax_lines = isset($report['tax_lines']) && is_array($report['tax_lines']) ? $report['tax_lines'] : [];
        $refunds = isset($report['refunds']) && is_array($report['refunds']) ? $report['refunds'] : [];
        $has_detail = !empty($tax_lines)
            || !empty($refunds)
            || !empty($report['orders'])
            || (isset($report['stats']['orders']) && (int) $report['stats']['orders'] === 0);
        $gross_product_tax = 0;
        $gross_shipping_tax = 0;
        $taxed_order_ids = [];
        $detail_processed = 0;
        $detail_truncated = !empty($order_state_result['truncated']);

        foreach ($tax_lines as $line) {
            if ($detail_processed >= $scope['max_records']) {
                $detail_truncated = true;
                break;
            }
            $detail_processed++;
            if (!is_array($line) || !$this->row_matches_scope($line, $scope)) {
                continue;
            }
            if (!empty($scope['states']) && !in_array($this->normalize_state($line['tax_state'] ?? ''), $scope['states'], true)) {
                continue;
            }
            $gross_product_tax += $this->minor($line['product_tax'] ?? 0);
            $gross_shipping_tax += $this->minor($line['shipping_tax'] ?? 0);
            if (!empty($line['order_id'])) {
                $taxed_order_ids[(int) $line['order_id']] = true;
            }
        }

        $refunded_product_tax = 0;
        $refunded_shipping_tax = 0;
        $unallocated_refund_tax = 0;
        $unscoped_refunds = 0;

        foreach ($refunds as $refund) {
            if ($detail_processed >= $scope['max_records']) {
                $detail_truncated = true;
                break;
            }
            $detail_processed++;
            if (!is_array($refund) || !$this->row_matches_scope($refund, $scope)) {
                continue;
            }

            $order_id = (int) ($refund['order_id'] ?? 0);
            if (!empty($scope['states'])) {
                $refund_state = isset($order_states[$order_id]) ? $order_states[$order_id] : '';
                if ($refund_state === '') {
                    $unscoped_refunds++;
                    continue;
                }
                if (!in_array($refund_state, $scope['states'], true)) {
                    continue;
                }
            }

            $details = $this->decode_refund_details($refund['line_items_json'] ?? '');
            $detail_tax = 0;
            foreach ($details as $detail) {
                if (!is_array($detail)) {
                    continue;
                }
                $tax = abs($this->minor($detail['tax'] ?? 0));
                $detail_tax += $tax;
                if (($detail['type'] ?? '') === 'shipping') {
                    $refunded_shipping_tax += $tax;
                } else {
                    $refunded_product_tax += $tax;
                }
            }
            $refund_tax = abs($this->minor($refund['tax_refunded'] ?? 0));
            if ($refund_tax > $detail_tax) {
                $unallocated_refund_tax += $refund_tax - $detail_tax;
            }
        }

        if ($unallocated_refund_tax > 0) {
            $gross_total = $gross_product_tax + $gross_shipping_tax;
            $shipping_share = $gross_total > 0
                ? (int) round($unallocated_refund_tax * ($gross_shipping_tax / $gross_total))
                : 0;
            $refunded_shipping_tax += $shipping_share;
            $refunded_product_tax += $unallocated_refund_tax - $shipping_share;
        }

        if ($has_detail) {
            $totals['product_tax_minor'] = $gross_product_tax - $refunded_product_tax;
            $totals['shipping_tax_minor'] = $gross_shipping_tax - $refunded_shipping_tax;
            $totals['components_available'] = $unscoped_refunds === 0 && !$detail_truncated;
            $totals['orders_count'] = count($taxed_order_ids);
            $totals['orders_count_available'] = !$detail_truncated;
            $totals['component_quality'] = $detail_truncated
                ? 'truncated'
                : ($unallocated_refund_tax > 0 ? 'estimated_refund_split' : 'exact_from_report_lines');
        }

        if (array_key_exists('product_tax', $explicit) && array_key_exists('shipping_tax', $explicit)) {
            $totals['product_tax_minor'] = $this->minor($explicit['product_tax']);
            $totals['shipping_tax_minor'] = $this->minor($explicit['shipping_tax']);
            $totals['components_available'] = true;
            $totals['component_quality'] = 'explicit';
        }
        if (array_key_exists('orders_count', $explicit)) {
            $totals['orders_count'] = max(0, (int) $explicit['orders_count']);
            $totals['orders_count_available'] = true;
        }

        $totals['unscoped_refunds'] = $unscoped_refunds;
        $totals['detail_truncated'] = $detail_truncated;
        $totals['processed_records'] = $detail_processed;
        $totals['component_gap_minor'] = $totals['components_available'] && $totals['tax_total_available']
            ? $totals['tax_total_minor'] - ($totals['product_tax_minor'] + $totals['shipping_tax_minor'])
            : null;

        return $totals;
    }

    private function get_woocommerce_totals(array $scope, array $options): array
    {
        $allow_fallback = !array_key_exists('allow_order_fallback', $options) || !empty($options['allow_order_fallback']);
        $needs_scoped_query = !empty($scope['states'])
            || count($scope['report_currencies']) > 1
            || empty($scope['include_negative_orders']);

        if (!$needs_scoped_query) {
            $analytics = $this->query_analytics_datastore($scope);
            if (!empty($analytics['available'])) {
                return $analytics;
            }
            if (!$allow_fallback) {
                return $analytics;
            }

            $fallback = $this->query_orders_bounded($scope, 'woocommerce_orders_fallback');
            $fallback['analytics_datastore_available'] = false;
            $fallback['analytics_error'] = $analytics['error'] ?? 'WooCommerce Analytics tax data is unavailable.';
            return $fallback;
        }

        $scoped = $this->query_orders_bounded($scope, 'woocommerce_orders_scoped');
        $scoped['analytics_datastore_available'] = self::analytics_datastore_available();
        $scoped['scope_note'] = 'WooCommerce Analytics cannot reproduce every selected destination-state, currency, or negative-order filter, so the HPOS-compatible order API was used.';
        return $scoped;
    }

    private function query_analytics_datastore(array $scope): array
    {
        $totals = $this->empty_totals('woocommerce_analytics_tax_datastore');
        $totals['analytics_datastore_available'] = false;
        $store = null;

        try {
            $class = '\\Automattic\\WooCommerce\\Admin\\API\\Reports\\Taxes\\Stats\\DataStore';
            if (class_exists($class)) {
                $store = new $class();
            } elseif (class_exists('WC_Data_Store') && method_exists('WC_Data_Store', 'load')) {
                $store = WC_Data_Store::load('report-taxes-stats');
            }

            if (!is_object($store) || !is_callable([$store, 'get_data'])) {
                $totals['error'] = 'WooCommerce Analytics tax DataStore is not available.';
                return $totals;
            }
            $totals['analytics_datastore_available'] = true;

            $timezone = new DateTimeZone($scope['timezone']);
            $after = (new DateTimeImmutable($scope['date_from'] . ' 00:00:00', $timezone))->format(DATE_ATOM);
            $before = (new DateTimeImmutable($scope['date_to'] . ' 23:59:59', $timezone))->format(DATE_ATOM);
            $query = [
                'after' => $after,
                'before' => $before,
                'interval' => 'year',
                'page' => 1,
                'per_page' => 100,
                'fields' => ['tax_codes', 'total_tax', 'order_tax', 'shipping_tax', 'orders_count'],
            ];
            if (!empty($scope['statuses'])) {
                $query['status_is'] = $scope['statuses'];
            }

            $data = $store->get_data($query);
            if (function_exists('is_wp_error') && is_wp_error($data)) {
                $totals['error'] = 'WooCommerce Analytics returned an error while reading tax totals.';
                return $totals;
            }
            if (!is_object($data) || !isset($data->totals) || !is_object($data->totals)) {
                $totals['error'] = 'WooCommerce Analytics returned an unexpected tax totals response.';
                return $totals;
            }

            $analytics_totals = $data->totals;
            $totals['tax_total_minor'] = $this->minor($analytics_totals->total_tax ?? 0);
            $totals['product_tax_minor'] = $this->minor($analytics_totals->order_tax ?? 0);
            $totals['shipping_tax_minor'] = $this->minor($analytics_totals->shipping_tax ?? 0);
            $totals['orders_count'] = max(0, (int) ($analytics_totals->orders_count ?? 0));
            $totals['tax_total_available'] = true;
            $totals['components_available'] = true;
            $totals['orders_count_available'] = true;
            $totals['available'] = true;
            $totals['component_quality'] = 'analytics_datastore';
        } catch (Throwable $error) {
            $totals['error'] = 'WooCommerce Analytics tax data could not be loaded safely.';
            $totals['error_type'] = get_class($error);
        }

        return $totals;
    }

    private function query_orders_bounded(array $scope, string $source): array
    {
        $totals = $this->empty_totals($source);
        if (!function_exists('wc_get_orders')) {
            $totals['error'] = 'The WooCommerce order query API is unavailable.';
            return $totals;
        }

        $date_key = in_array($scope['analytics_date_type'], ['date_paid', 'date_completed'], true)
            ? $scope['analytics_date_type']
            : 'date_created';
        $range = $scope['from_timestamp'] . '...' . $scope['to_timestamp'];
        $processed = 0;
        $truncated = false;
        $parent_cache = [];

        try {
            $page = 1;
            $pages = 1;
            do {
                $query = [
                    'type' => 'shop_order',
                    'limit' => $scope['page_size'],
                    'page' => $page,
                    'paginate' => true,
                    'return' => 'objects',
                    'orderby' => 'date',
                    'order' => 'ASC',
                    $date_key => $range,
                ];
                if (!empty($scope['statuses'])) {
                    $query['status'] = $scope['statuses'];
                }
                $response = wc_get_orders($query);
                $orders = is_object($response) && isset($response->orders)
                    ? $response->orders
                    : (is_array($response) ? $response : []);
                $pages = is_object($response) && isset($response->max_num_pages)
                    ? max(1, (int) $response->max_num_pages)
                    : 1;

                foreach ($orders as $order) {
                    if ($processed >= $scope['max_records']) {
                        $truncated = true;
                        break 2;
                    }
                    $processed++;
                    if (!is_object($order) || !method_exists($order, 'get_id')) {
                        continue;
                    }

                    $location = $this->order_location($order);
                    $parent_cache[(int) $order->get_id()] = $location;
                    if (!$this->location_matches_scope($location, $scope)) {
                        continue;
                    }

                    $components = $this->order_tax_components($order, false);
                    $totals['product_tax_minor'] += $components['product'];
                    $totals['shipping_tax_minor'] += $components['shipping'];
                    $totals['tax_total_minor'] += $components['total'];
                    if ($components['has_tax_row']) {
                        $totals['orders_count']++;
                    }
                }
                $page++;
            } while ($page <= $pages);

            if (!$truncated) {
                $page = 1;
                $pages = 1;
                do {
                    $response = wc_get_orders([
                        'type' => 'shop_order_refund',
                        'date_created' => $range,
                        'limit' => $scope['page_size'],
                        'page' => $page,
                        'paginate' => true,
                        'return' => 'objects',
                        'orderby' => 'date',
                        'order' => 'ASC',
                    ]);
                    $refunds = is_object($response) && isset($response->orders)
                        ? $response->orders
                        : (is_array($response) ? $response : []);
                    $pages = is_object($response) && isset($response->max_num_pages)
                        ? max(1, (int) $response->max_num_pages)
                        : 1;

                    foreach ($refunds as $refund) {
                        if ($processed >= $scope['max_records']) {
                            $truncated = true;
                            break 2;
                        }
                        $processed++;
                        if (!is_object($refund) || !method_exists($refund, 'get_parent_id')) {
                            continue;
                        }

                        $parent_id = (int) $refund->get_parent_id();
                        $location = $parent_cache[$parent_id] ?? null;
                        if ($location === null) {
                            $parent = function_exists('wc_get_order') ? wc_get_order($parent_id) : null;
                            if (!is_object($parent)) {
                                $totals['unresolved_refunds']++;
                                continue;
                            }
                            $location = $this->order_location($parent);
                            if (count($parent_cache) < $scope['max_records']) {
                                $parent_cache[$parent_id] = $location;
                            }
                        }
                        if (!$this->location_matches_scope($location, $scope)) {
                            continue;
                        }

                        $components = $this->order_tax_components($refund, true);
                        $totals['product_tax_minor'] -= $components['product'];
                        $totals['shipping_tax_minor'] -= $components['shipping'];
                        $totals['tax_total_minor'] -= $components['total'];
                    }
                    $page++;
                } while ($page <= $pages);
            }

            $totals['available'] = true;
            $totals['tax_total_available'] = true;
            $totals['components_available'] = true;
            $totals['orders_count_available'] = true;
            $totals['component_quality'] = 'woocommerce_order_items';
            $totals['processed_records'] = $processed;
            $totals['truncated'] = $truncated;
            $totals['order_query_date_type'] = $date_key;
            $totals['refund_query_date_type'] = 'date_created';
        } catch (Throwable $error) {
            $totals['error'] = 'WooCommerce orders could not be aggregated safely.';
            $totals['error_type'] = get_class($error);
            $totals['processed_records'] = $processed;
            $totals['truncated'] = $truncated;
        }

        return $totals;
    }

    private function order_tax_components($order, bool $absolute): array
    {
        $product = 0;
        $shipping = 0;
        $has_tax_row = false;
        $items = method_exists($order, 'get_items') ? $order->get_items('tax') : [];

        foreach ((array) $items as $item) {
            if (!is_object($item)) {
                continue;
            }
            $product_tax = method_exists($item, 'get_tax_total') ? $this->minor($item->get_tax_total()) : 0;
            $shipping_tax = method_exists($item, 'get_shipping_tax_total') ? $this->minor($item->get_shipping_tax_total()) : 0;
            $product += $absolute ? abs($product_tax) : $product_tax;
            $shipping += $absolute ? abs($shipping_tax) : $shipping_tax;
            $has_tax_row = true;
        }

        $reported_total = method_exists($order, 'get_total_tax') ? $this->minor($order->get_total_tax()) : $product + $shipping;
        if ($absolute) {
            $reported_total = abs($reported_total);
        }
        $component_total = $product + $shipping;
        if ($reported_total !== $component_total) {
            // Preserve the authoritative order/refund total. Any tax not
            // identified as shipping belongs to Analytics' order_tax bucket.
            $product += $reported_total - $component_total;
        }

        return [
            'product' => $product,
            'shipping' => $shipping,
            'total' => $reported_total,
            'has_tax_row' => $has_tax_row,
        ];
    }

    private function order_location($order): array
    {
        $based_on = function_exists('get_option') ? (string) get_option('woocommerce_tax_based_on', 'shipping') : 'shipping';
        $local_pickup = false;
        if (method_exists($order, 'get_items')) {
            foreach ((array) $order->get_items('shipping') as $shipping_item) {
                $method_id = is_object($shipping_item) && method_exists($shipping_item, 'get_method_id')
                    ? (string) $shipping_item->get_method_id()
                    : '';
                if (in_array($method_id, ['local_pickup', 'legacy_local_pickup', 'pickup_location'], true)) {
                    $local_pickup = true;
                    break;
                }
            }
        }

        $state = '';
        $country = '';
        if (($based_on === 'base' || $local_pickup) && function_exists('WC') && WC() && isset(WC()->countries)) {
            $state = (string) WC()->countries->get_base_state();
            $country = (string) WC()->countries->get_base_country();
        } elseif ($based_on === 'billing') {
            $state = method_exists($order, 'get_billing_state') ? (string) $order->get_billing_state() : '';
            $country = method_exists($order, 'get_billing_country') ? (string) $order->get_billing_country() : '';
        } else {
            $state = method_exists($order, 'get_shipping_state') ? (string) $order->get_shipping_state() : '';
            $country = method_exists($order, 'get_shipping_country') ? (string) $order->get_shipping_country() : '';
            if ($country === '') {
                $state = method_exists($order, 'get_billing_state') ? (string) $order->get_billing_state() : '';
                $country = method_exists($order, 'get_billing_country') ? (string) $order->get_billing_country() : '';
            }
        }

        if (method_exists($order, 'get_meta')) {
            $quote = $order->get_meta('_ffla_tax_quote', true);
            if (is_string($quote) && $quote !== '') {
                $decoded = json_decode($quote, true);
                $quote = is_array($decoded) ? $decoded : [];
            }
            if (is_array($quote) && !empty($quote['state'])) {
                $state = (string) $quote['state'];
            }
        }

        return [
            'state' => $this->normalize_state($state),
            'country' => strtoupper($country),
            'currency' => method_exists($order, 'get_currency') ? $this->normalize_currency($order->get_currency()) : '',
            'negative_order' => method_exists($order, 'get_total') && $this->minor($order->get_total()) < 0,
        ];
    }

    private function location_matches_scope(array $location, array $scope): bool
    {
        if (empty($scope['include_negative_orders']) && !empty($location['negative_order'])) {
            return false;
        }
        if (!empty($scope['states']) && !in_array($location['state'], $scope['states'], true)) {
            return false;
        }
        if (!empty($scope['states']) && $location['country'] !== '' && $location['country'] !== 'US') {
            return false;
        }
        if ($scope['currency'] !== '' && $location['currency'] !== $scope['currency']) {
            return false;
        }

        return true;
    }

    private function date_range_check(array $scope): array
    {
        if (!empty($scope['report_range_matches'])) {
            return [
                'id' => 'date_range',
                'label' => 'Date range',
                'status' => 'pass',
                'comparable' => true,
                'ffla' => $scope['report_date_from'] . ' through ' . $scope['report_date_to'],
                'woocommerce' => $scope['date_from'] . ' through ' . $scope['date_to'],
                'message' => 'Both sources use the same inclusive calendar date range.',
            ];
        }

        return [
            'id' => 'date_range',
            'label' => 'Date range',
            'status' => 'warn',
            'comparable' => false,
            'ffla' => trim($scope['report_date_from'] . ' through ' . $scope['report_date_to']),
            'woocommerce' => $scope['date_from'] . ' through ' . $scope['date_to'],
            'message' => 'The requested reconciliation range does not match the FFLA report range.',
        ];
    }

    private function date_basis_check(array $scope, array $woocommerce): array
    {
        $same_basis = $scope['analytics_date_type'] === $scope['ffla_date_type'];
        $message = $same_basis
            ? 'Both sources select orders by their creation date.'
            : 'FFLA uses order creation date, while WooCommerce Analytics is configured to use ' . $scope['analytics_date_type'] . '.';
        if (($woocommerce['source'] ?? '') === 'woocommerce_orders_scoped' && $scope['analytics_date_type'] !== 'date_created') {
            $message .= ' Refunds remain scoped by refund creation date because refunds do not have a reliable paid/completed date.';
        }

        return [
            'id' => 'date_basis',
            'label' => 'Date basis',
            'status' => $same_basis ? 'pass' : 'warn',
            'comparable' => $same_basis,
            'ffla' => $scope['ffla_date_type'],
            'woocommerce' => $scope['analytics_date_type'],
            'message' => $message,
        ];
    }

    private function money_check(
        string $id,
        string $label,
        $ffla_minor,
        $woocommerce_minor,
        int $tolerance_minor,
        bool $comparable
    ): array {
        if (!$comparable || $ffla_minor === null || $woocommerce_minor === null) {
            return $this->unavailable_check(
                $id,
                $label,
                'This value is not safely comparable for the selected report scope.'
            );
        }

        $ffla_minor = (int) $ffla_minor;
        $woocommerce_minor = (int) $woocommerce_minor;
        $difference = $ffla_minor - $woocommerce_minor;
        $passes = abs($difference) <= $tolerance_minor;

        return [
            'id' => $id,
            'label' => $label,
            'status' => $passes ? 'pass' : 'warn',
            'comparable' => true,
            'ffla_minor' => $ffla_minor,
            'woocommerce_minor' => $woocommerce_minor,
            'difference_minor' => $difference,
            'absolute_difference_minor' => abs($difference),
            'tolerance_minor' => $tolerance_minor,
            'ffla' => $this->decimal($ffla_minor),
            'woocommerce' => $this->decimal($woocommerce_minor),
            'difference' => $this->decimal($difference),
            'message' => $passes
                ? 'The difference is within the configured minor-unit tolerance.'
                : 'FFLA minus WooCommerce Analytics exceeds the configured tolerance.',
        ];
    }

    private function count_check($ffla_count, $woocommerce_count, bool $comparable): array
    {
        if (!$comparable || $ffla_count === null || $woocommerce_count === null) {
            return $this->unavailable_check(
                'order_count',
                'Taxed order count',
                'Taxed order counts require FFLA tax-line detail and a complete WooCommerce source query.'
            );
        }

        $ffla_count = (int) $ffla_count;
        $woocommerce_count = (int) $woocommerce_count;
        $difference = $ffla_count - $woocommerce_count;

        return [
            'id' => 'order_count',
            'label' => 'Taxed order count',
            'status' => $difference === 0 ? 'pass' : 'warn',
            'comparable' => true,
            'ffla' => $ffla_count,
            'woocommerce' => $woocommerce_count,
            'difference' => $difference,
            'message' => $difference === 0
                ? 'Both sources contain the same number of orders with tax rows.'
                : 'The number of orders with tax rows differs between the two sources.',
        ];
    }

    private function unavailable_check(string $id, string $label, string $message): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => 'warn',
            'comparable' => false,
            'ffla' => null,
            'woocommerce' => null,
            'difference' => null,
            'message' => $message,
        ];
    }

    private function build_warnings(array $scope, array $ffla, array $woocommerce): array
    {
        $warnings = [];
        if (!empty($scope['currency_ambiguous'])) {
            $warnings[] = 'The FFLA report contains multiple currencies. Select one currency before reconciling monetary totals.';
        }
        if (empty($woocommerce['available'])) {
            $warnings[] = (string) ($woocommerce['error'] ?? 'WooCommerce tax data is unavailable.');
        }
        if (($woocommerce['source'] ?? '') !== 'woocommerce_analytics_tax_datastore') {
            $warnings[] = (string) ($woocommerce['scope_note'] ?? 'A bounded WooCommerce order query was used instead of the Analytics tax DataStore.');
        }
        if (!empty($woocommerce['truncated'])) {
            $warnings[] = 'The WooCommerce order query reached its safety cap; partial totals are not comparable.';
        }
        if (!empty($woocommerce['unresolved_refunds'])) {
            $warnings[] = 'One or more refunds could not be linked to a parent order and were excluded from the scoped totals.';
        }
        if (!empty($ffla['unscoped_refunds'])) {
            $warnings[] = 'One or more FFLA refunds could not be assigned to the selected state, so component checks are unavailable.';
        }
        if (($ffla['component_quality'] ?? '') === 'estimated_refund_split') {
            $warnings[] = 'Some refunded tax lacked item-level type data and was split proportionally between product and shipping tax.';
        }
        if (!empty($ffla['detail_truncated'])) {
            $warnings[] = 'The FFLA detail scan reached its safety cap; summary tax remains available, but component and order-count checks are disabled.';
        }
        if ($ffla['component_gap_minor'] !== null && abs((int) $ffla['component_gap_minor']) > $scope['tolerance_minor']) {
            $warnings[] = 'FFLA product and shipping tax components do not add up to the FFLA net tax total within tolerance.';
        }

        return array_values(array_unique(array_filter($warnings)));
    }

    private function build_recommendations(
        array $checks,
        array $scope,
        array $ffla,
        array $woocommerce,
        array $report
    ): array {
        $recommendations = [];

        if (empty($scope['report_range_matches'])) {
            $recommendations[] = 'Regenerate the FFLA report or rerun reconciliation using the exact report start and end dates.';
        }
        if ($scope['analytics_date_type'] !== $scope['ffla_date_type']) {
            $recommendations[] = 'Align WooCommerce Analytics date type with Date created, or expect timing differences for orders paid or completed on another day.';
        }
        if (!empty($scope['currency_ambiguous'])) {
            $recommendations[] = 'Run one reconciliation per currency; do not add monetary totals from different currencies together.';
        }
        if (empty($woocommerce['available'])) {
            $recommendations[] = 'Confirm WooCommerce Analytics is enabled, then import or regenerate historical Analytics data from WooCommerce status tools.';
        } elseif (($woocommerce['source'] ?? '') !== 'woocommerce_analytics_tax_datastore') {
            $recommendations[] = 'Use the global, single-currency scope when you need a direct Analytics DataStore comparison; state and multi-currency scopes use bounded order aggregation.';
        }
        if (!empty($woocommerce['truncated'])) {
            $recommendations[] = 'Narrow the date range or deliberately raise max_records after checking site capacity, then rerun the reconciliation.';
        }
        if (!empty($ffla['detail_truncated'])) {
            $recommendations[] = 'Narrow the FFLA report range or deliberately raise max_records to reconcile all tax lines and refunds.';
        }
        if (!empty($woocommerce['unresolved_refunds'])) {
            $recommendations[] = 'Review orphaned refunds and restore their parent-order relationship before relying on scoped refund totals.';
        }
        if (isset($checks['tax_total']) && $checks['tax_total']['status'] === 'warn' && !empty($checks['tax_total']['comparable'])) {
            $recommendations[] = 'Regenerate WooCommerce Analytics lookup data, then verify order statuses, late refunds, and the configured Analytics date basis.';
        }
        if ((isset($checks['product_tax']) && $checks['product_tax']['status'] === 'warn')
            || (isset($checks['shipping_tax']) && $checks['shipping_tax']['status'] === 'warn')) {
            $recommendations[] = 'Review WooCommerce tax lines and refund items to confirm tax is classified between order items/fees and shipping consistently.';
        }
        if (isset($checks['order_count']) && $checks['order_count']['status'] === 'warn') {
            $recommendations[] = 'Check for orders missing Analytics tax lookup rows and confirm the same order statuses are selected in both reports.';
        }
        if (empty($ffla['components_available']) || empty($ffla['orders_count_available'])) {
            $recommendations[] = 'Generate a full FFLA report, not summary-only, to enable product tax, shipping tax, and taxed-order-count checks.';
        }
        $exception_count = isset($report['manifest']['data_quality']['exception_count'])
            ? (int) $report['manifest']['data_quality']['exception_count']
            : (int) ($report['stats']['exceptions'] ?? 0);
        if ($exception_count > 0) {
            $recommendations[] = 'Resolve or document the FFLA report exceptions before filing; reconciliation does not replace order-level review.';
        }

        return array_values(array_unique($recommendations));
    }

    private function overall_status(array $checks, array $warnings): string
    {
        if (!empty($warnings)) {
            return 'warn';
        }
        foreach ($checks as $check) {
            if (($check['status'] ?? 'warn') !== 'pass') {
                return 'warn';
            }
        }

        return !empty($checks) ? 'pass' : 'warn';
    }

    private function public_totals(array $totals): array
    {
        return [
            'available' => !empty($totals['available']) || !empty($totals['tax_total_available']),
            'tax_total_minor' => $totals['tax_total_minor'],
            'tax_total' => $totals['tax_total_minor'] !== null ? $this->decimal((int) $totals['tax_total_minor']) : null,
            'product_tax_minor' => $totals['product_tax_minor'],
            'product_tax' => $totals['product_tax_minor'] !== null ? $this->decimal((int) $totals['product_tax_minor']) : null,
            'shipping_tax_minor' => $totals['shipping_tax_minor'],
            'shipping_tax' => $totals['shipping_tax_minor'] !== null ? $this->decimal((int) $totals['shipping_tax_minor']) : null,
            'orders_count' => $totals['orders_count'],
            'reported_orders_count' => $totals['reported_orders_count'] ?? null,
            'components_available' => !empty($totals['components_available']),
            'orders_count_available' => !empty($totals['orders_count_available']),
            'truncated' => !empty($totals['truncated']),
            'detail_truncated' => !empty($totals['detail_truncated']),
            'source' => (string) ($totals['source'] ?? 'unavailable'),
        ];
    }

    private function empty_totals(string $source): array
    {
        return [
            'source' => $source,
            'available' => false,
            'tax_total_minor' => 0,
            'product_tax_minor' => 0,
            'shipping_tax_minor' => 0,
            'orders_count' => 0,
            'reported_orders_count' => 0,
            'tax_total_available' => false,
            'components_available' => false,
            'orders_count_available' => false,
            'component_quality' => 'unavailable',
            'component_gap_minor' => null,
            'processed_records' => 0,
            'truncated' => false,
            'unresolved_refunds' => 0,
            'unscoped_refunds' => 0,
            'detail_truncated' => false,
        ];
    }

    private function collect_currencies(array $report, array $states): array
    {
        $rows = !empty($states)
            ? ($report['summaries']['states'] ?? [])
            : ($report['summaries']['filing_totals'] ?? []);
        if (!is_array($rows) || empty($rows)) {
            $rows = isset($report['totals_by_currency']) && is_array($report['totals_by_currency'])
                ? $report['totals_by_currency']
                : [];
        }

        $currencies = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!empty($states) && !in_array($this->normalize_state($row['state'] ?? ''), $states, true)) {
                continue;
            }
            $currency = $this->normalize_currency($row['currency'] ?? '');
            if ($currency !== '') {
                $currencies[$currency] = $currency;
            }
        }

        return array_values($currencies);
    }

    private function build_order_state_map(array $report, int $limit): array
    {
        $map = [];
        $processed = 0;
        $truncated = false;
        $orders = isset($report['orders']) && is_array($report['orders']) ? $report['orders'] : [];
        foreach ($orders as $order) {
            if ($processed >= $limit) {
                $truncated = true;
                break;
            }
            $processed++;
            if (!is_array($order) || empty($order['order_id'])) {
                continue;
            }
            $state = $this->normalize_state(
                $order['tax_state'] ?? ($order['shipping_state'] ?? ($order['billing_state'] ?? ''))
            );
            if ($state !== '') {
                $map[(int) $order['order_id']] = $state;
            }
        }
        $tax_lines = isset($report['tax_lines']) && is_array($report['tax_lines']) ? $report['tax_lines'] : [];
        foreach ($tax_lines as $line) {
            if ($processed >= $limit) {
                $truncated = true;
                break;
            }
            $processed++;
            if (!is_array($line) || empty($line['order_id'])) {
                continue;
            }
            $state = $this->normalize_state($line['tax_state'] ?? '');
            if ($state !== '') {
                $map[(int) $line['order_id']] = $state;
            }
        }

        return [
            'states' => $map,
            'truncated' => $truncated,
        ];
    }

    private function row_matches_scope(array $row, array $scope): bool
    {
        if ($scope['currency'] !== '') {
            $currency = $this->normalize_currency($row['currency'] ?? '');
            if ($currency !== $scope['currency']) {
                return false;
            }
        }

        return true;
    }

    private function decode_refund_details($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalize_statuses(array $statuses): array
    {
        $normalized = [];
        foreach ($statuses as $status) {
            $status = strtolower(trim((string) $status));
            $status = preg_replace('/^wc-/', '', $status);
            $status = preg_replace('/[^a-z0-9_-]/', '', $status);
            if ($status !== '') {
                $normalized[$status] = $status;
            }
        }

        return array_values($normalized);
    }

    private function normalize_state($state): string
    {
        $state = strtoupper(trim((string) $state));

        return preg_replace('/[^A-Z0-9-]/', '', $state);
    }

    private function normalize_currency($currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        return preg_replace('/[^A-Z0-9]/', '', $currency);
    }

    private function site_timezone(): DateTimeZone
    {
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }
        if (function_exists('wc_timezone_string')) {
            try {
                return new DateTimeZone((string) wc_timezone_string());
            } catch (Throwable $error) {
                // Fall through to UTC.
            }
        }

        return new DateTimeZone('UTC');
    }

    private function parse_date(string $date, bool $end_of_day, DateTimeZone $timezone): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('The FFLA report must provide dates in YYYY-MM-DD format.');
        }
        $time = $end_of_day ? '23:59:59' : '00:00:00';
        $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $date . ' ' . $time, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$value || (is_array($errors) && (!empty($errors['warning_count']) || !empty($errors['error_count'])))) {
            throw new InvalidArgumentException('The FFLA report contains an invalid calendar date.');
        }

        return $value;
    }

    /**
     * Convert a major-unit decimal to minor units without float arithmetic for
     * string inputs. DataStore floats are first expanded to a fixed decimal.
     */
    private function minor($value): int
    {
        if (is_float($value)) {
            $value = number_format($value, $this->precision + 4, '.', '');
        } elseif (is_int($value)) {
            $value = (string) $value;
        } elseif (!is_numeric($value)) {
            return 0;
        }

        $value = str_replace([',', ' '], '', trim((string) $value));
        if (stripos($value, 'e') !== false) {
            $value = number_format((float) $value, $this->precision + 4, '.', '');
        }
        if (!preg_match('/^([+-]?)(\d+)(?:\.(\d*))?$/', $value, $matches)) {
            return 0;
        }

        $negative = $matches[1] === '-';
        $whole = (int) $matches[2];
        $fraction = isset($matches[3]) ? $matches[3] : '';
        $fraction = str_pad($fraction, $this->precision + 1, '0');
        $kept = $this->precision > 0 ? substr($fraction, 0, $this->precision) : '';
        $round_digit = (int) substr($fraction, $this->precision, 1);
        $minor = ($whole * $this->scale) + ($kept === '' ? 0 : (int) $kept);
        if ($round_digit >= 5) {
            $minor++;
        }

        return $negative ? -$minor : $minor;
    }

    private function decimal(int $minor): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $whole = intdiv($absolute, $this->scale);
        if ($this->precision === 0) {
            return ($negative ? '-' : '') . (string) $whole;
        }
        $fraction = str_pad((string) ($absolute % $this->scale), $this->precision, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '') . $whole . '.' . $fraction;
    }
}
