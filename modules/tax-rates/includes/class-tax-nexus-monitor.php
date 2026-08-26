<?php
/**
 * Standalone multi-state economic nexus monitor.
 *
 * The monitor intentionally reports indicators, not legal conclusions. State
 * definitions, lookback periods, exclusions, and thresholds change and must be
 * verified before a registration or filing decision is made.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

if (class_exists('Tax_Nexus_Monitor', false)) {
    return;
}

final class Tax_Nexus_Monitor
{
    public const SCHEMA_VERSION = '1.0.0';
    public const DEFAULT_PAGE_SIZE = 200;
    public const DEFAULT_MAX_RECORDS = 50000;
    public const ABSOLUTE_MAX_RECORDS = 200000;
    public const DEFAULT_APPROACHING_PERCENT = 80.0;
    public const ADVISORY_STATUS = 'informational_only_not_tax_or_legal_advice';

    /** @var string|null */
    private $dataset_path;

    public function __construct(?string $dataset_path = null)
    {
        $this->dataset_path = $dataset_path;
    }

    /**
     * Aggregate destination-state activity and compare it with the seed dataset.
     *
     * Supported options: forecast, as_of (Y-m-d), home_state, home_country,
     * timezone, page_size, dataset_path, and money_decimals.
     *
     * @throws InvalidArgumentException When report inputs are invalid.
     * @throws RuntimeException When WooCommerce or the dataset is unavailable.
     */
    public function generate(array $filters, array $options = []): array
    {
        if (!function_exists('wc_get_orders')) {
            throw new RuntimeException('WooCommerce is required to generate an economic nexus monitor.');
        }

        $filters = self::normalize_filters($filters);
        $timezone = $this->resolve_timezone($options['timezone'] ?? null);
        $period_start = $this->parse_date($filters['date_from'], $timezone);
        $period_end = $this->parse_date($filters['date_to'], $timezone);

        if (!$period_start || !$period_end) {
            throw new InvalidArgumentException('Enter a valid nexus-monitor date range.');
        }

        $as_of = isset($options['as_of'])
            ? $this->parse_date($this->clean_text($options['as_of']), $timezone)
            : new DateTimeImmutable('today', $timezone);

        if (!$as_of) {
            throw new InvalidArgumentException('Enter a valid forecast as-of date.');
        }

        $dataset = $this->load_thresholds(isset($options['dataset_path']) ? (string) $options['dataset_path'] : null);
        $home = $this->resolve_home_location($options);
        $money_decimals = isset($options['money_decimals'])
            ? max(0, min(6, (int) $options['money_decimals']))
            : (function_exists('wc_get_price_decimals') ? max(0, min(6, (int) wc_get_price_decimals())) : 2);
        $page_size = isset($options['page_size'])
            ? max(1, min(500, (int) $options['page_size']))
            : self::DEFAULT_PAGE_SIZE;
        $max_records = isset($options['max_records'])
            ? max($page_size, min(self::ABSOLUTE_MAX_RECORDS, (int) $options['max_records']))
            : self::DEFAULT_MAX_RECORDS;
        $records_seen = 0;

        $period_days = ((int) $period_start->diff($period_end)->days) + 1;
        $observed_end = $as_of < $period_end ? $as_of : $period_end;
        $observed_days = $observed_end < $period_start
            ? 0
            : ((int) $period_start->diff($observed_end)->days) + 1;
        $forecast_requested = !empty($options['forecast']);
        $forecast_applied = $forecast_requested && $observed_days > 0 && $observed_days < $period_days;

        $buckets = [];
        foreach ($dataset['states'] as $state => $threshold) {
            $buckets[$state] = $this->new_bucket($state, $threshold);
        }
        if ($home['country'] === 'US' && $home['state'] !== '' && !isset($buckets[$home['state']])) {
            $buckets[$home['state']] = $this->new_bucket(
                $home['state'],
                $this->unknown_threshold($home['state'])
            );
        }

        $stats = [
            'orders_processed'          => 0,
            'orders_skipped_non_us'     => 0,
            'orders_skipped_no_state'   => 0,
            'orders_skipped_invalid'    => 0,
            'orders_country_inferred'   => 0,
            'orders_skipped_negative'   => 0,
            'refunds_processed'         => 0,
            'refunds_skipped_invalid'   => 0,
            'pages_processed'           => 0,
            'max_records'               => $max_records,
            'truncated'                 => false,
        ];

        if ($observed_days > 0) {
            $query_start = $period_start->setTime(0, 0, 0);
            $query_end = $observed_end->setTime(23, 59, 59);
            $page = 1;
            $pages = 1;

            do {
                $result = wc_get_orders([
                    'type'         => 'shop_order',
                    'status'       => $filters['statuses'],
                    'date_created' => $query_start->getTimestamp() . '...' . $query_end->getTimestamp(),
                    'orderby'      => 'date',
                    'order'        => 'ASC',
                    'limit'        => $page_size,
                    'page'         => $page,
                    'paginate'     => true,
                    'return'       => 'objects',
                ]);

                $orders = is_object($result) && isset($result->orders)
                    ? $result->orders
                    : (is_array($result) ? $result : []);
                $pages = is_object($result) && isset($result->max_num_pages)
                    ? max(1, (int) $result->max_num_pages)
                    : 1;
                $stats['pages_processed']++;

                foreach ($orders as $order) {
                    if ($records_seen >= $max_records) {
                        $stats['truncated'] = true;
                        break 2;
                    }
                    $records_seen++;
                    if (!is_object($order) || !method_exists($order, 'get_total')) {
                        $stats['orders_skipped_invalid']++;
                        continue;
                    }
                    if (empty($filters['include_negative_orders']) && (float) $order->get_total() < 0) {
                        $stats['orders_skipped_negative']++;
                        continue;
                    }

                    $destination = $this->get_destination($order);
                    if ($destination['country'] !== 'US') {
                        $stats['orders_skipped_non_us']++;
                        continue;
                    }
                    if ($destination['state'] === '' || !$this->is_us_state_code($destination['state'])) {
                        $stats['orders_skipped_no_state']++;
                        continue;
                    }
                    if ($destination['country_inferred']) {
                        $stats['orders_country_inferred']++;
                    }

                    $state = $destination['state'];
                    if (!isset($buckets[$state])) {
                        $buckets[$state] = $this->new_bucket($state, $this->unknown_threshold($state));
                    }

                    $currency = method_exists($order, 'get_currency')
                        ? strtoupper($this->clean_text($order->get_currency()))
                        : '';
                    $currency = $currency !== '' ? $currency : '(NONE)';
                    $amount = $this->get_order_revenue($order, [
                        'state'             => $state,
                        'currency'          => $currency,
                        'date_from'         => $filters['date_from'],
                        'date_to'           => $filters['date_to'],
                        'observed_through'  => $observed_end->format('Y-m-d'),
                        'default_basis'     => 'order_total_excluding_tax_net_of_refunds',
                    ]);
                    $amount_minor = $this->to_minor($amount, $money_decimals);

                    if (!isset($buckets[$state]['sales_by_currency_minor'][$currency])) {
                        $buckets[$state]['sales_by_currency_minor'][$currency] = 0;
                    }
                    $buckets[$state]['sales_by_currency_minor'][$currency] += $amount_minor;
                    $buckets[$state]['transactions']++;
                    $stats['orders_processed']++;
                }

                $page++;
            } while ($page <= $pages);

            // Apply refunds by refund creation date, including refunds whose
            // original sale predates the selected period. This keeps historical
            // nexus reports stable when a later refund is created.
            if (!empty($stats['truncated'])) {
                throw new RuntimeException('The nexus monitor reached its order safety cap. Narrow the date range and run it again.');
            }

            $page = 1;
            $pages = 1;
            do {
                $result = wc_get_orders([
                    'type'         => 'shop_order_refund',
                    'date_created' => $query_start->getTimestamp() . '...' . $query_end->getTimestamp(),
                    'orderby'      => 'date',
                    'order'        => 'ASC',
                    'limit'        => $page_size,
                    'page'         => $page,
                    'paginate'     => true,
                    'return'       => 'objects',
                ]);
                $refunds = is_object($result) && isset($result->orders)
                    ? $result->orders
                    : (is_array($result) ? $result : []);
                $pages = is_object($result) && isset($result->max_num_pages)
                    ? max(1, (int) $result->max_num_pages)
                    : 1;
                $stats['pages_processed']++;

                foreach ($refunds as $refund) {
                    if ($records_seen >= $max_records) {
                        $stats['truncated'] = true;
                        break 2;
                    }
                    $records_seen++;
                    if (!is_object($refund) || !method_exists($refund, 'get_parent_id')) {
                        $stats['refunds_skipped_invalid']++;
                        continue;
                    }
                    $parent = function_exists('wc_get_order') ? wc_get_order((int) $refund->get_parent_id()) : null;
                    if (!is_object($parent) || !method_exists($parent, 'get_total')) {
                        $stats['refunds_skipped_invalid']++;
                        continue;
                    }
                    if (empty($filters['include_negative_orders']) && (float) $parent->get_total() < 0) {
                        $stats['orders_skipped_negative']++;
                        continue;
                    }
                    $destination = $this->get_destination($parent);
                    if ($destination['country'] !== 'US' || $destination['state'] === '' || !$this->is_us_state_code($destination['state'])) {
                        $stats['refunds_skipped_invalid']++;
                        continue;
                    }
                    $state = $destination['state'];
                    if (!isset($buckets[$state])) {
                        $buckets[$state] = $this->new_bucket($state, $this->unknown_threshold($state));
                    }
                    $currency = method_exists($parent, 'get_currency')
                        ? strtoupper($this->clean_text($parent->get_currency()))
                        : '';
                    $currency = $currency !== '' ? $currency : '(NONE)';
                    if (!isset($buckets[$state]['sales_by_currency_minor'][$currency])) {
                        $buckets[$state]['sales_by_currency_minor'][$currency] = 0;
                    }
                    $refund_revenue = $this->get_refund_revenue($refund, $parent, [
                        'state'            => $state,
                        'currency'         => $currency,
                        'date_from'        => $filters['date_from'],
                        'date_to'          => $filters['date_to'],
                        'observed_through' => $observed_end->format('Y-m-d'),
                    ]);
                    $buckets[$state]['sales_by_currency_minor'][$currency] -= $this->to_minor($refund_revenue, $money_decimals);
                    $stats['refunds_processed']++;
                }
                $page++;
            } while ($page <= $pages);
            if (!empty($stats['truncated'])) {
                throw new RuntimeException('The nexus monitor reached its order and refund safety cap. Narrow the date range and run it again.');
            }
        }

        ksort($buckets);
        $states = [];
        $summary = [
            'states_reported'                    => 0,
            'states_with_transactions'           => 0,
            'states_actual_threshold_exceeded'   => 0,
            'states_actual_approaching'           => 0,
            'states_forecast_threshold_exceeded' => 0,
            'states_requiring_currency_conversion' => 0,
            'states_without_threshold_data'      => 0,
        ];

        foreach ($buckets as $state => $bucket) {
            $row = $this->finalize_bucket(
                $bucket,
                $home,
                $money_decimals,
                $forecast_applied,
                $observed_days,
                $period_days
            );
            $states[] = $row;
            $summary['states_reported']++;
            if ($row['actual_transactions'] > 0) {
                $summary['states_with_transactions']++;
            }
            if ($row['actual_evaluation']['status'] === 'threshold_exceeded') {
                $summary['states_actual_threshold_exceeded']++;
            }
            if ($row['actual_evaluation']['status'] === 'approaching_threshold') {
                $summary['states_actual_approaching']++;
            }
            if (is_array($row['forecast_evaluation']) && $row['forecast_evaluation']['status'] === 'threshold_exceeded') {
                $summary['states_forecast_threshold_exceeded']++;
            }
            if ($row['currency_status'] === 'requires_conversion') {
                $summary['states_requiring_currency_conversion']++;
            }
            if ($row['actual_evaluation']['status'] === 'no_threshold_data') {
                $summary['states_without_threshold_data']++;
            }
        }

        return [
            'schema_version'   => self::SCHEMA_VERSION,
            'generated_at_utc' => gmdate('c'),
            'period'           => [
                'date_from'           => $filters['date_from'],
                'date_to'             => $filters['date_to'],
                'period_days'         => $period_days,
                'observed_through'    => $observed_days > 0 ? $observed_end->format('Y-m-d') : null,
                'observed_days'       => $observed_days,
            ],
            'filters'          => $filters,
            'forecast'         => [
                'requested'        => $forecast_requested,
                'applied'          => $forecast_applied,
                'method'           => $forecast_applied ? 'straight_line_from_observed_period' : null,
                'projection_factor'=> $forecast_applied ? round($period_days / $observed_days, 6) : 1.0,
                'as_of'            => $as_of->format('Y-m-d'),
                'status'           => $forecast_applied
                    ? 'estimate_not_a_prediction_or_legal_conclusion'
                    : ($forecast_requested ? 'not_applied_no_unobserved_days_or_no_observations' : 'disabled'),
            ],
            'physical_home_state' => [
                'country'    => $home['country'],
                'state'      => $home['state'] !== '' ? $home['state'] : null,
                'indication' => $home['state'] !== ''
                    ? 'Store base address only; review physical-presence obligations separately.'
                    : 'No U.S. store base state was available.',
            ],
            'dataset'          => $dataset['metadata'],
            'revenue_measure'  => [
                'default_basis' => 'WooCommerce order total excluding collected tax and net of recorded refunds; shipping and fees remain included.',
                'customization_filters' => ['ffla_tax_nexus_order_revenue', 'ffla_tax_nexus_refund_revenue'],
                'warning'       => 'Each state may define threshold revenue differently. Customize and verify the basis before relying on it.',
            ],
            'stats'             => $stats,
            'summary'           => $summary,
            'states'            => $states,
            'advisory'          => [
                'status'                     => self::ADVISORY_STATUS,
                'requires_professional_review' => true,
                'dataset_verified_by_ffla'   => false,
                'message'                    => 'This monitor is an early-warning aid. It does not determine nexus, registration, collection, or filing obligations. Verify current state rules and the applicable sales definition with a qualified professional or state authority.',
            ],
        ];
    }

    /**
     * Validate and normalize the selected period and WooCommerce statuses.
     */
    public static function normalize_filters(array $filters): array
    {
        $date_from = isset($filters['date_from']) ? trim((string) $filters['date_from']) : '';
        $date_to = isset($filters['date_to']) ? trim((string) $filters['date_to']) : '';
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $date_from, new DateTimeZone('UTC'));
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $date_to, new DateTimeZone('UTC'));

        if (!$from || $from->format('Y-m-d') !== $date_from || !$to || $to->format('Y-m-d') !== $date_to) {
            throw new InvalidArgumentException('Enter valid date_from and date_to values in Y-m-d format.');
        }
        if ($date_from > $date_to) {
            throw new InvalidArgumentException('The nexus-monitor start date must be before the end date.');
        }

        $requested = isset($filters['statuses']) && is_array($filters['statuses'])
            ? $filters['statuses']
            : ['processing', 'completed'];
        $statuses = [];
        foreach ($requested as $status) {
            $status = preg_replace('/^wc-/', '', self::sanitize_key_value((string) $status));
            if ($status !== '') {
                $statuses[] = $status;
            }
        }
        $statuses = array_values(array_unique($statuses));
        if (empty($statuses)) {
            throw new InvalidArgumentException('Select at least one WooCommerce order status.');
        }

        return [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'statuses'  => $statuses,
            'include_negative_orders' => !empty($filters['include_negative_orders']),
        ];
    }

    /**
     * Load and validate the replaceable threshold dataset.
     *
     * Filters:
     * - ffla_tax_nexus_threshold_dataset_path(string $path, Tax_Nexus_Monitor $monitor)
     * - ffla_tax_nexus_threshold_dataset(array $dataset, string $path, Tax_Nexus_Monitor $monitor)
     */
    public function load_thresholds(?string $path = null): array
    {
        $path = $path ?: $this->dataset_path;
        $path = $path ?: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'nexus-thresholds.csv';
        if (function_exists('apply_filters')) {
            $path = (string) apply_filters('ffla_tax_nexus_threshold_dataset_path', $path, $this);
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The economic nexus threshold dataset is missing or unreadable.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The economic nexus threshold dataset could not be opened.');
        }

        try {
            $headers = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($headers)) {
                throw new RuntimeException('The economic nexus threshold dataset has no header row.');
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $headers = array_map([$this, 'normalize_header'], $headers);
            $required = [
                'dataset_version', 'state', 'state_name', 'revenue_threshold', 'transaction_threshold',
                'evaluation_rule', 'comparison_operator', 'approaching_percent', 'threshold_currency',
                'revenue_basis', 'lookback_period', 'source_type', 'source_title', 'source_url',
                'effective_date', 'observed_on', 'verification_status', 'notes',
            ];
            foreach ($required as $column) {
                if (!in_array($column, $headers, true)) {
                    throw new RuntimeException('The nexus dataset is missing required column: ' . $column);
                }
            }

            $states = [];
            $sources = [];
            $versions = [];
            $effective_dates = [];
            $verification_statuses = [];

            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                    continue;
                }
                $row = array_pad($row, count($headers), '');
                $record = array_combine($headers, array_slice($row, 0, count($headers)));
                if (!is_array($record)) {
                    continue;
                }

                $state = $this->normalize_state($record['state']);
                if (!$this->is_us_state_code($state)) {
                    throw new RuntimeException('The nexus dataset contains an invalid state code: ' . $state);
                }
                if (isset($states[$state])) {
                    throw new RuntimeException('The nexus dataset contains a duplicate state code: ' . $state);
                }

                $rule = strtoupper($this->clean_text($record['evaluation_rule']));
                if (!in_array($rule, ['AND', 'OR', 'NONE'], true)) {
                    throw new RuntimeException('The nexus dataset contains an invalid evaluation rule for ' . $state . '.');
                }
                $operator = strtolower($this->clean_text($record['comparison_operator']));
                if (!in_array($operator, ['greater_than', 'greater_than_or_equal'], true)) {
                    throw new RuntimeException('The nexus dataset contains an invalid comparison operator for ' . $state . '.');
                }

                $threshold = [
                    'state'                 => $state,
                    'state_name'            => $this->clean_text($record['state_name']),
                    'revenue_threshold'     => $this->nullable_number($record['revenue_threshold']),
                    'transaction_threshold' => $this->nullable_integer($record['transaction_threshold']),
                    'evaluation_rule'       => $rule,
                    'comparison_operator'   => $operator,
                    'approaching_percent'   => $this->positive_number($record['approaching_percent'], self::DEFAULT_APPROACHING_PERCENT),
                    'threshold_currency'    => strtoupper($this->clean_text($record['threshold_currency'])) ?: 'USD',
                    'revenue_basis'         => $this->clean_text($record['revenue_basis']),
                    'lookback_period'       => $this->clean_text($record['lookback_period']),
                    'source'                => [
                        'type'                => $this->clean_text($record['source_type']),
                        'title'               => $this->clean_text($record['source_title']),
                        'url'                 => $this->clean_text($record['source_url']),
                        'effective_date'      => $this->nullable_text($record['effective_date']),
                        'observed_on'         => $this->nullable_text($record['observed_on']),
                        'verification_status' => $this->clean_text($record['verification_status']),
                    ],
                    'notes'                 => $this->clean_text($record['notes']),
                ];
                $states[$state] = $threshold;

                $version = $this->clean_text($record['dataset_version']);
                if ($version !== '') {
                    $versions[$version] = true;
                }
                if ($threshold['source']['effective_date'] !== null) {
                    $effective_dates[$threshold['source']['effective_date']] = true;
                }
                if ($threshold['source']['verification_status'] !== '') {
                    $verification_statuses[$threshold['source']['verification_status']] = true;
                }
                $source_key = json_encode($threshold['source']);
                $sources[$source_key] = $threshold['source'];
            }
        } finally {
            fclose($handle);
        }

        ksort($states);
        $dataset = [
            'metadata' => [
                'schema_version'          => '1.0',
                'dataset_version'         => count($versions) === 1 ? (string) array_key_first($versions) : 'mixed_or_unspecified',
                'state_count'             => count($states),
                'source'                  => count($sources) === 1 ? reset($sources) : null,
                'sources'                 => array_values($sources),
                'effective_date'          => count($effective_dates) === 1 ? (string) array_key_first($effective_dates) : null,
                'effective_dates'         => array_keys($effective_dates),
                'verification_statuses'   => array_keys($verification_statuses),
                'dataset_status'          => 'replaceable_seed_requires_verification',
                'replacement_path_filter' => 'ffla_tax_nexus_threshold_dataset_path',
                'replacement_data_filter' => 'ffla_tax_nexus_threshold_dataset',
            ],
            'states' => $states,
        ];

        if (function_exists('apply_filters')) {
            $dataset = apply_filters('ffla_tax_nexus_threshold_dataset', $dataset, $path, $this);
        }
        if (!is_array($dataset) || !isset($dataset['metadata'], $dataset['states']) || !is_array($dataset['states'])) {
            throw new RuntimeException('The filtered economic nexus dataset is invalid.');
        }

        return $dataset;
    }

    /**
     * Pure threshold evaluation. Unknown revenue can be represented with
     * revenue_known=false (for example, when conversion to USD is required).
     */
    public function evaluate_threshold(array $metrics, array $threshold): array
    {
        $decimals = isset($metrics['money_decimals']) ? max(0, min(6, (int) $metrics['money_decimals'])) : 2;
        $revenue_minor = isset($metrics['revenue_minor'])
            ? (int) $metrics['revenue_minor']
            : $this->to_minor($metrics['revenue'] ?? 0, $decimals);
        $transactions = max(0, (int) ($metrics['transactions'] ?? 0));
        $revenue_known = !isset($metrics['revenue_known']) || (bool) $metrics['revenue_known'];
        $revenue_threshold = $threshold['revenue_threshold'] ?? null;
        $transaction_threshold = $threshold['transaction_threshold'] ?? null;
        $rule = strtoupper((string) ($threshold['evaluation_rule'] ?? 'OR'));
        $operator = strtolower((string) ($threshold['comparison_operator'] ?? 'greater_than'));
        $approaching_at = $this->positive_number(
            $threshold['approaching_percent'] ?? self::DEFAULT_APPROACHING_PERCENT,
            self::DEFAULT_APPROACHING_PERCENT
        );

        $has_revenue = $revenue_threshold !== null && $revenue_threshold !== '' && is_numeric($revenue_threshold);
        $has_transactions = $transaction_threshold !== null && $transaction_threshold !== '' && is_numeric($transaction_threshold);
        if (!$has_revenue && !$has_transactions) {
            return [
                'status'                       => 'no_threshold_data',
                'threshold_met'                => null,
                'revenue_threshold_met'        => null,
                'transaction_threshold_met'    => null,
                'revenue_progress_percent'     => null,
                'transaction_progress_percent' => null,
                'combined_progress_percent'    => null,
                'approaching_at_percent'       => $approaching_at,
                'reason'                       => 'No economic nexus threshold is present in the selected dataset.',
            ];
        }

        $results = [];
        $progress = [];
        $revenue_met = null;
        $transaction_met = null;
        $revenue_progress = null;
        $transaction_progress = null;

        if ($has_revenue) {
            $threshold_minor = $this->to_minor($revenue_threshold, $decimals);
            if ($revenue_known) {
                $revenue_met = $this->compare_threshold($revenue_minor, $threshold_minor, $operator);
                $revenue_progress = $threshold_minor > 0 ? round(($revenue_minor / $threshold_minor) * 100, 2) : null;
            }
            $results[] = $revenue_met;
            $progress[] = $revenue_progress;
        }
        if ($has_transactions) {
            $transaction_threshold = (int) $transaction_threshold;
            $transaction_met = $this->compare_threshold($transactions, $transaction_threshold, $operator);
            $transaction_progress = $transaction_threshold > 0
                ? round(($transactions / $transaction_threshold) * 100, 2)
                : null;
            $results[] = $transaction_met;
            $progress[] = $transaction_progress;
        }

        $known_results = array_values(array_filter($results, static function ($value) {
            return $value !== null;
        }));
        $threshold_met = null;
        if ($rule === 'AND') {
            if (in_array(false, $known_results, true)) {
                $threshold_met = false;
            } elseif (count($known_results) === count($results) && !empty($results)) {
                $threshold_met = true;
            }
        } else {
            if (in_array(true, $known_results, true)) {
                $threshold_met = true;
            } elseif (count($known_results) === count($results) && !empty($results)) {
                $threshold_met = false;
            }
        }

        $known_progress = array_values(array_filter($progress, static function ($value) {
            return $value !== null;
        }));
        $combined_progress = null;
        if (!empty($known_progress)) {
            if ($rule === 'AND' && count($known_progress) !== count($progress)) {
                $combined_progress = null;
            } else {
                $combined_progress = $rule === 'AND' ? min($known_progress) : max($known_progress);
            }
        }

        if ($threshold_met === true) {
            $status = 'threshold_exceeded';
            $reason = 'The selected-period metrics satisfy the dataset threshold rule.';
        } elseif ($threshold_met === null) {
            $status = 'indeterminate';
            $reason = 'A required threshold metric is unavailable; review currency conversion or dataset inputs.';
        } elseif ($combined_progress !== null && $combined_progress >= $approaching_at) {
            $status = 'approaching_threshold';
            $reason = 'The selected-period metrics have reached the dataset approaching percentage.';
        } else {
            $status = 'below_threshold';
            $reason = 'The selected-period metrics do not satisfy the dataset threshold rule.';
        }

        return [
            'status'                       => $status,
            'threshold_met'                => $threshold_met,
            'revenue_threshold_met'        => $revenue_met,
            'transaction_threshold_met'    => $transaction_met,
            'revenue_progress_percent'     => $revenue_progress,
            'transaction_progress_percent' => $transaction_progress,
            'combined_progress_percent'    => $combined_progress,
            'approaching_at_percent'       => $approaching_at,
            'reason'                       => $reason,
        ];
    }

    /**
     * Pure straight-line projection helper for focused testing and UI adapters.
     */
    public function project_metrics(array $metrics, int $observed_days, int $target_days): array
    {
        if ($observed_days <= 0 || $target_days <= $observed_days) {
            return [
                'applied'      => false,
                'factor'       => 1.0,
                'revenue'      => round((float) ($metrics['revenue'] ?? 0), 2),
                'transactions' => max(0, (int) ($metrics['transactions'] ?? 0)),
            ];
        }

        $factor = $target_days / $observed_days;
        return [
            'applied'      => true,
            'factor'       => round($factor, 6),
            'revenue'      => round(((float) ($metrics['revenue'] ?? 0)) * $factor, 2),
            'transactions' => max(0, (int) round(((int) ($metrics['transactions'] ?? 0)) * $factor)),
        ];
    }

    private function finalize_bucket(
        array $bucket,
        array $home,
        int $money_decimals,
        bool $forecast_applied,
        int $observed_days,
        int $period_days
    ): array {
        $threshold = $bucket['threshold'];
        $threshold_currency = strtoupper((string) ($threshold['threshold_currency'] ?? 'USD'));
        $sales_by_currency = [];
        foreach ($bucket['sales_by_currency_minor'] as $currency => $minor) {
            $sales_by_currency[$currency] = $this->from_minor((int) $minor, $money_decimals);
        }
        ksort($sales_by_currency);

        $foreign_currencies = array_values(array_filter(array_keys($bucket['sales_by_currency_minor']), static function ($currency) use ($threshold_currency) {
            return $currency !== $threshold_currency;
        }));
        $revenue_known = empty($foreign_currencies);
        $revenue_minor = (int) ($bucket['sales_by_currency_minor'][$threshold_currency] ?? 0);
        $actual_metrics = [
            'revenue_minor'  => $revenue_minor,
            'revenue_known'  => $revenue_known,
            'transactions'   => $bucket['transactions'],
            'money_decimals' => $money_decimals,
        ];
        $actual_evaluation = $this->evaluate_threshold($actual_metrics, $threshold);

        $forecast_revenue_minor = null;
        $forecast_transactions = null;
        $forecast_evaluation = null;
        if ($forecast_applied) {
            $factor = $period_days / $observed_days;
            $forecast_revenue_minor = (int) round($revenue_minor * $factor);
            $forecast_transactions = max(0, (int) round($bucket['transactions'] * $factor));
            $forecast_evaluation = $this->evaluate_threshold([
                'revenue_minor'  => $forecast_revenue_minor,
                'revenue_known'  => $revenue_known,
                'transactions'   => $forecast_transactions,
                'money_decimals' => $money_decimals,
            ], $threshold);
        }

        $is_home_state = $home['country'] === 'US' && $home['state'] === $bucket['state'];
        $advisory_status = $this->state_advisory_status(
            $is_home_state,
            $actual_evaluation,
            $forecast_evaluation,
            $revenue_known
        );

        return [
            'state'                    => $bucket['state'],
            'state_name'               => $threshold['state_name'] ?? $bucket['state'],
            'physical_home_state'      => $is_home_state,
            'physical_presence_indicator' => $is_home_state
                ? 'store_base_address_matches_state_review_required'
                : 'not_indicated_by_store_base_address',
            'actual_revenue'           => $this->from_minor($revenue_minor, $money_decimals),
            'actual_transactions'      => $bucket['transactions'],
            'sales_by_currency'        => $sales_by_currency,
            'threshold_currency'       => $threshold_currency,
            'currency_status'          => $revenue_known ? 'ready' : 'requires_conversion',
            'unconverted_currencies'   => $foreign_currencies,
            'forecast_revenue'         => $forecast_revenue_minor !== null
                ? $this->from_minor($forecast_revenue_minor, $money_decimals)
                : null,
            'forecast_transactions'    => $forecast_transactions,
            'threshold'                => $threshold,
            'actual_evaluation'        => $actual_evaluation,
            'forecast_evaluation'      => $forecast_evaluation,
            'advisory_status'          => $advisory_status,
        ];
    }

    private function state_advisory_status(
        bool $is_home_state,
        array $actual,
        ?array $forecast,
        bool $revenue_known
    ): string {
        if ($is_home_state) {
            return 'review_physical_presence_obligations';
        }
        if (!$revenue_known || $actual['status'] === 'indeterminate') {
            return 'convert_currency_and_review';
        }
        if ($actual['status'] === 'threshold_exceeded') {
            return 'review_possible_registration_obligation';
        }
        if (is_array($forecast) && $forecast['status'] === 'threshold_exceeded') {
            return 'monitor_projected_threshold_exposure';
        }
        if ($actual['status'] === 'approaching_threshold') {
            return 'monitor_closely';
        }
        if ($actual['status'] === 'no_threshold_data') {
            return 'verify_state_rules_no_seed_threshold';
        }
        return 'monitor';
    }

    private function new_bucket(string $state, array $threshold): array
    {
        return [
            'state'                   => $state,
            'threshold'               => $threshold,
            'sales_by_currency_minor' => [],
            'transactions'            => 0,
        ];
    }

    private function unknown_threshold(string $state): array
    {
        return [
            'state'                 => $state,
            'state_name'            => $state,
            'revenue_threshold'     => null,
            'transaction_threshold' => null,
            'evaluation_rule'       => 'NONE',
            'comparison_operator'   => 'greater_than',
            'approaching_percent'   => self::DEFAULT_APPROACHING_PERCENT,
            'threshold_currency'    => 'USD',
            'revenue_basis'         => 'unknown',
            'lookback_period'       => 'unknown',
            'source'                => [
                'type' => 'none', 'title' => '', 'url' => '', 'effective_date' => null,
                'observed_on' => null, 'verification_status' => 'missing',
            ],
            'notes'                 => 'No threshold row was supplied for this destination.',
        ];
    }

    private function get_order_revenue($order, array $context): float
    {
        $total = (float) $order->get_total();
        $tax = method_exists($order, 'get_total_tax') ? (float) $order->get_total_tax() : 0.0;
        $amount = $total - $tax;

        if (function_exists('apply_filters')) {
            $amount = apply_filters('ffla_tax_nexus_order_revenue', $amount, $order, $context, $this);
        }
        return is_numeric($amount) ? (float) $amount : 0.0;
    }

    private function get_refund_revenue($refund, $parent_order, array $context): float
    {
        $refunded = method_exists($refund, 'get_amount')
            ? abs((float) $refund->get_amount())
            : (method_exists($refund, 'get_total') ? abs((float) $refund->get_total()) : 0.0);
        $tax_refunded = method_exists($refund, 'get_total_tax') ? abs((float) $refund->get_total_tax()) : 0.0;
        $amount = max(0.0, $refunded - $tax_refunded);
        if (function_exists('apply_filters')) {
            $amount = apply_filters('ffla_tax_nexus_refund_revenue', $amount, $refund, $parent_order, $context, $this);
        }
        return is_numeric($amount) ? max(0.0, (float) $amount) : 0.0;
    }

    private function get_destination($order): array
    {
        $shipping_state = method_exists($order, 'get_shipping_state')
            ? $this->normalize_state($order->get_shipping_state())
            : '';
        $billing_state = method_exists($order, 'get_billing_state')
            ? $this->normalize_state($order->get_billing_state())
            : '';
        $state = $shipping_state !== '' ? $shipping_state : $billing_state;

        $shipping_country = method_exists($order, 'get_shipping_country')
            ? strtoupper($this->clean_text($order->get_shipping_country()))
            : '';
        $billing_country = method_exists($order, 'get_billing_country')
            ? strtoupper($this->clean_text($order->get_billing_country()))
            : '';
        $country = $shipping_country !== '' ? $shipping_country : $billing_country;
        $country_inferred = false;
        if ($country === '' && $this->is_us_state_code($state)) {
            $country = 'US';
            $country_inferred = true;
        }

        return [
            'country'          => $country,
            'state'            => $state,
            'country_inferred' => $country_inferred,
        ];
    }

    private function resolve_home_location(array $options): array
    {
        $country = isset($options['home_country']) ? strtoupper($this->clean_text($options['home_country'])) : '';
        $state = isset($options['home_state']) ? $this->normalize_state($options['home_state']) : '';

        if (($country === '' || $state === '') && function_exists('wc_get_base_location')) {
            $base = wc_get_base_location();
            if (is_array($base)) {
                $country = $country !== '' ? $country : strtoupper($this->clean_text($base['country'] ?? ''));
                $state = $state !== '' ? $state : $this->normalize_state($base['state'] ?? '');
            }
        }
        if (($country === '' || $state === '') && function_exists('get_option')) {
            $default = $this->clean_text(get_option('woocommerce_default_country', ''));
            if ($default !== '') {
                $parts = array_pad(explode(':', $default, 2), 2, '');
                $country = $country !== '' ? $country : strtoupper($parts[0]);
                $state = $state !== '' ? $state : $this->normalize_state($parts[1]);
            }
        }
        if ($country === '' && $this->is_us_state_code($state)) {
            $country = 'US';
        }
        if ($country !== 'US' || !$this->is_us_state_code($state)) {
            $state = '';
        }

        return ['country' => $country, 'state' => $state];
    }

    private function resolve_timezone($timezone): DateTimeZone
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }
        if (is_string($timezone) && trim($timezone) !== '') {
            try {
                return new DateTimeZone(trim($timezone));
            } catch (Throwable $error) {
                throw new InvalidArgumentException('The nexus-monitor timezone is invalid.');
            }
        }
        if (function_exists('wp_timezone')) {
            return wp_timezone();
        }
        return new DateTimeZone('UTC');
    }

    private function parse_date(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function compare_threshold(int $value, int $threshold, string $operator): bool
    {
        return $operator === 'greater_than_or_equal' ? $value >= $threshold : $value > $threshold;
    }

    private function to_minor($amount, int $decimals): int
    {
        return (int) round(((float) $amount) * (10 ** $decimals));
    }

    private function from_minor(int $amount, int $decimals): string
    {
        return number_format($amount / (10 ** $decimals), $decimals, '.', '');
    }

    private function normalize_header($header): string
    {
        return strtolower(trim((string) $header));
    }

    private function clean_text($value): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        return function_exists('sanitize_text_field') ? sanitize_text_field($value) : trim(strip_tags($value));
    }

    private static function sanitize_key_value(string $value): string
    {
        if (function_exists('sanitize_key')) {
            return sanitize_key($value);
        }
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value));
    }

    private function nullable_number($value)
    {
        $value = strtolower($this->clean_text($value));
        return $value === '' || $value === 'none' || !is_numeric($value) ? null : (float) $value;
    }

    private function nullable_integer($value): ?int
    {
        $number = $this->nullable_number($value);
        return $number === null ? null : (int) $number;
    }

    private function positive_number($value, float $fallback): float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : $fallback;
    }

    private function nullable_text($value): ?string
    {
        $value = $this->clean_text($value);
        return $value === '' || strtolower($value) === 'unknown' ? null : $value;
    }

    private function normalize_state($state): string
    {
        $state = strtoupper(str_replace('.', '', $this->clean_text($state)));
        if (preg_match('/^[A-Z]{2}$/', $state)) {
            return $state;
        }
        $map = [
            'ALABAMA'=>'AL','ALASKA'=>'AK','ARIZONA'=>'AZ','ARKANSAS'=>'AR','CALIFORNIA'=>'CA',
            'COLORADO'=>'CO','CONNECTICUT'=>'CT','DELAWARE'=>'DE','DISTRICT OF COLUMBIA'=>'DC',
            'FLORIDA'=>'FL','GEORGIA'=>'GA','HAWAII'=>'HI','IDAHO'=>'ID','ILLINOIS'=>'IL',
            'INDIANA'=>'IN','IOWA'=>'IA','KANSAS'=>'KS','KENTUCKY'=>'KY','LOUISIANA'=>'LA',
            'MAINE'=>'ME','MARYLAND'=>'MD','MASSACHUSETTS'=>'MA','MICHIGAN'=>'MI','MINNESOTA'=>'MN',
            'MISSISSIPPI'=>'MS','MISSOURI'=>'MO','MONTANA'=>'MT','NEBRASKA'=>'NE','NEVADA'=>'NV',
            'NEW HAMPSHIRE'=>'NH','NEW JERSEY'=>'NJ','NEW MEXICO'=>'NM','NEW YORK'=>'NY',
            'NORTH CAROLINA'=>'NC','NORTH DAKOTA'=>'ND','OHIO'=>'OH','OKLAHOMA'=>'OK','OREGON'=>'OR',
            'PENNSYLVANIA'=>'PA','RHODE ISLAND'=>'RI','SOUTH CAROLINA'=>'SC','SOUTH DAKOTA'=>'SD',
            'TENNESSEE'=>'TN','TEXAS'=>'TX','UTAH'=>'UT','VERMONT'=>'VT','VIRGINIA'=>'VA',
            'WASHINGTON'=>'WA','WEST VIRGINIA'=>'WV','WISCONSIN'=>'WI','WYOMING'=>'WY',
        ];
        return $map[$state] ?? '';
    }

    private function is_us_state_code(string $state): bool
    {
        static $codes = [
            'AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL','GA','HI','ID','IL','IN','IA','KS',
            'KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC',
            'ND','OH','OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
        ];
        return in_array($state, $codes, true);
    }
}
