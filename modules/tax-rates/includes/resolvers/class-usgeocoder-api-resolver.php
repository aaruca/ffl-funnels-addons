<?php
/**
 * USGeocoder API Resolver.
 *
 * Resolves tax rates from the live USGeocoder API.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class USGeocoder_API_Resolver extends Tax_Resolver_Base
{
    public const API_ENDPOINT = 'https://api.usgeocoder.com/api/get_info.php';
    public const TIMEOUT = 20;

    public function get_id(): string
    {
        return 'usgeocoder_api';
    }

    public function get_name(): string
    {
        return __('USGeocoder Live API', 'ffl-funnels-addons');
    }

    public function get_source_code(): string
    {
        return 'usgeocoder_api';
    }

    public function get_supported_states(): array
    {
        return class_exists('Tax_Coverage')
            ? Tax_Coverage::ALL_STATES
            : [];
    }

    public function requires_geocode(): bool
    {
        return false;
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $state_code = strtoupper((string) ($normalized['state'] ?? ''));
        $settings   = get_option('ffla_tax_resolver_settings', []);
        $auth_key   = trim((string) ($settings['usgeocoder_auth_key'] ?? ''));

        // Use the state's configured coverage status instead of a hardcoded
        // value so admin reconfiguration (restrict_states / enabled_states)
        // drives the response shape.
        $coverage_status = $this->resolve_coverage_status($state_code);

        if ($auth_key === '') {
            return $this->error_result(
                $normalized,
                $state_code,
                $coverage_status,
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                'USGeocoder auth key is missing. Add it in Tax Resolver settings.'
            );
        }

        // Respect the restrict_states / enabled_states gate: when the admin
        // disables a state for the store, refuse to hit the paid API even if
        // the router accidentally routed us here.
        if (class_exists('Tax_Coverage') && !Tax_Coverage::is_enabled_for_store($state_code)) {
            return $this->error_result(
                $normalized,
                $state_code,
                $coverage_status,
                Tax_Quote_Result::OUTCOME_STATE_DISABLED,
                sprintf('State %s is not enabled for this store. Enable it in Tax Resolver settings.', $state_code)
            );
        }

        $street = trim((string) ($normalized['street'] ?? ''));
        $zip5   = substr(preg_replace('/[^0-9]/', '', (string) ($normalized['zip'] ?? '')), 0, 5);

        if ($street === '' || $zip5 === '') {
            return $this->error_result(
                $normalized,
                $state_code,
                Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED,
                Tax_Quote_Result::OUTCOME_VALIDATION_ERROR,
                'USGeocoder resolution requires street and ZIP code.'
            );
        }

        $response = self::fetch_api($auth_key, [
            'address' => $street,
            'zipcode' => $zip5,
        ]);

        // Every real HTTP attempt counts toward the monthly/rolling usage
        // totals, regardless of outcome. Cache hits never reach here because
        // Tax_Quote_Engine short-circuits them upstream.
        if (class_exists('Tax_USGeocoder_Usage')) {
            Tax_USGeocoder_Usage::record_call($response['ok'] ?? false);
        }

        if (!empty($response['wp_error'])) {
            return $this->error_result(
                $normalized,
                $state_code,
                Tax_Coverage::DEGRADED,
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                'USGeocoder request failed: ' . $response['error']
            );
        }

        if (($response['http_code'] ?? 0) !== 200 || !is_array($response['payload'] ?? null)) {
            return $this->error_result(
                $normalized,
                $state_code,
                Tax_Coverage::DEGRADED,
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                sprintf('USGeocoder returned an invalid response (HTTP %d).', (int) ($response['http_code'] ?? 0))
            );
        }

        $payload = $response['payload'];

        // Honor USGeocoder's own request_status before doing anything else.
        // If they returned a hard "Denied" / "Invalid" / "NoMatch" we should
        // not try to scrape rates out of an empty payload.
        $status = self::extract_request_status_value($payload);
        $status_lower = strtolower($status);
        if (in_array($status_lower, ['denied', 'invalid', 'error'], true)) {
            return $this->error_result(
                $normalized,
                $state_code,
                Tax_Coverage::DEGRADED,
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                sprintf('USGeocoder rejected the request (request_status_code=%s).', $status ?: 'unknown')
            );
        }
        if (in_array($status_lower, ['nomatch'], true)) {
            return $this->error_result(
                $normalized,
                $state_code,
                Tax_Coverage::DEGRADED,
                Tax_Quote_Result::OUTCOME_VALIDATION_ERROR,
                'USGeocoder did not match the address (request_status_code=NoMatch).'
            );
        }

        $result = new Tax_Quote_Result();
        $result->inputAddress       = $normalized;
        $result->normalizedAddress  = $normalized;
        $result->state              = $state_code;
        $result->coverageStatus     = $coverage_status;
        $result->determinationScope = 'address_rate_only';
        $result->resolutionMode     = 'usgeocoder_live_api';
        $result->source             = $this->get_source_code();
        $result->sourceVersion      = 'live';
        $result->confidence         = Tax_Quote_Result::CONFIDENCE_MEDIUM;
        $result->trace['resolver']  = $this->get_id();
        $result->trace['geocodeUsed'] = false;
        $result->trace['sourceUrl']   = self::API_ENDPOINT;
        if ($status !== '') {
            $result->trace['requestStatus'] = $status;
        }

        $matched_address = self::pick_first_string($payload, [
            ['usgeocoder', 'request_status', 'request_address'],
            ['request_status', 'request_address'],
            ['result', 'address'],
            ['address'],
            ['matchedAddress'],
            ['street_address'],
        ]);

        if ($matched_address !== '') {
            $result->matchedAddress = $matched_address;
        }

        // Preferred path: explicit field mappings against the documented
        // Total Collection / Mandatory Collection schema. Skips the fuzzy
        // walker entirely when known fields are present.
        $known_breakdown = self::extract_known_breakdown($payload, $result);
        if (!empty($known_breakdown)) {
            foreach ($known_breakdown as $item) {
                $result->add_breakdown($item['type'], $item['jurisdiction'], $item['rate']);
            }
        } else {
            // Fallback: fuzzy parser for unknown payload shapes.
            $breakdown = self::extract_breakdown_items($payload);
            foreach ($breakdown as $item) {
                $result->add_breakdown($item['type'], $item['jurisdiction'], $item['rate']);
            }
        }

        if (empty($result->breakdown)) {
            $total = self::extract_total_rate($payload);
            if ($total === null) {
                $result->coverageStatus = Tax_Coverage::DEGRADED;
                $result->set_error(
                    Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
                    'USGeocoder did not return a usable tax rate for this address.'
                );
                return $result;
            }

            $result->add_breakdown('special', 'USGeocoder Total Rate', $total);
        }

        $result->calculate_total();

        if ((float) $result->totalRate === 0.0) {
            $result->coverageStatus = Tax_Coverage::NO_SALES_TAX;
            $result->outcomeCode    = Tax_Quote_Result::OUTCOME_NO_SALES_TAX;
        }

        $result->limitations[] = 'Resolved from live USGeocoder API response.';

        return $result;
    }

    /**
     * Fetch the USGeocoder endpoint and normalize the response envelope.
     *
     * Shared by `resolve()` and the admin "Test key" AJAX action.
     *
     * @param string               $auth_key Raw authkey value.
     * @param array<string,string> $query    Additional query arguments.
     * @return array{ok:bool,http_code:int,payload:array|null,error:string,wp_error:bool,body:string}
     */
    public static function fetch_api(string $auth_key, array $query): array
    {
        $query = array_merge($query, [
            'authkey' => $auth_key,
            'format'  => 'json',
        ]);

        $url = self::API_ENDPOINT . '?' . http_build_query($query);

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'payload'   => null,
                'error'     => $response->get_error_message(),
                'wp_error'  => true,
                'body'      => '',
            ];
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body_raw  = (string) wp_remote_retrieve_body($response);
        $payload   = json_decode($body_raw, true);
        $is_ok     = $http_code === 200 && is_array($payload);

        return [
            'ok'        => $is_ok,
            'http_code' => $http_code,
            'payload'   => is_array($payload) ? $payload : null,
            'error'     => $is_ok ? '' : sprintf('HTTP %d', $http_code),
            'wp_error'  => false,
            'body'      => $body_raw,
        ];
    }

    /**
     * Build an error Tax_Quote_Result populated with the shared trace fields.
     */
    private function error_result(
        array $normalized,
        string $state_code,
        string $coverage_status,
        string $outcome,
        string $message
    ): Tax_Quote_Result {
        $result = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state             = $state_code;
        $result->coverageStatus    = $coverage_status;
        $result->source            = $this->get_source_code();
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = false;
        $result->set_error($outcome, $message);

        return $result;
    }

    /**
     * Read the effective coverage status for the state from the DB, with a
     * sensible fallback when the row is missing (e.g. first boot before
     * Tax_Coverage::reconcile_from_settings() runs).
     */
    private function resolve_coverage_status(string $state_code): string
    {
        if (!class_exists('Tax_Coverage')) {
            return 'SUPPORTED_WITH_REMOTE_LOOKUP';
        }

        $rule = Tax_Coverage::get_state($state_code);
        if ($rule && !empty($rule['coverage_status'])) {
            return (string) $rule['coverage_status'];
        }

        return Tax_Coverage::SUPPORTED_WITH_REMOTE;
    }

    /**
     * Extract `usgeocoder.request_status.request_status_code.value` (or the
     * XML-decoded equivalent) so we can act on Denied / Invalid / NoMatch
     * before we try to scrape rates.
     */
    private static function extract_request_status_value(array $payload): string
    {
        // JSON path: usgeocoder.request_status.request_status_code.{value|0}
        $candidates = [
            ['usgeocoder', 'request_status', 'request_status_code', 'value'],
            ['request_status', 'request_status_code', 'value'],
            ['usgeocoder', 'request_status', 'request_status_code'],
            ['request_status', 'request_status_code'],
        ];
        foreach ($candidates as $path) {
            $node = $payload;
            foreach ($path as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) {
                    $node = null;
                    break;
                }
                $node = $node[$part];
            }
            if (is_scalar($node) && trim((string) $node) !== '') {
                return trim((string) $node);
            }
            if (is_array($node) && isset($node['value']) && is_scalar($node['value'])) {
                return trim((string) $node['value']);
            }
        }
        return '';
    }

    /**
     * Build the breakdown using the documented USGeocoder field names.
     *
     * The response wraps everything under `usgeocoder.*` and the sales-tax
     * payload lives in two siblings:
     *  - totalcollection_tax_summary  → state / county / city + totals
     *  - totalcollection_tax_details  → adds county/city/special district lines
     *
     * `mandatorycollection_*` mirrors the same shape but with `m_tax_*`
     * field names; values can be "Collections Not Required" strings on
     * jurisdictions where the seller has no obligation.
     *
     * Strategy:
     *  - Prefer Total Collection details (most specific)
     *  - Fall back to Total Collection summary
     *  - Fall back again to Mandatory Collection details / summary
     *  - Skip non-numeric rates ("Collections Not Required")
     *  - Capture t_tax_code / t_tax_incorporated_city on trace
     *
     * @return array<int,array{type:string,jurisdiction:string,rate:float}>
     */
    private static function extract_known_breakdown(array $payload, Tax_Quote_Result $result): array
    {
        $root = isset($payload['usgeocoder']) && is_array($payload['usgeocoder'])
            ? $payload['usgeocoder']
            : $payload;

        // Try total-collection paths first (paid plans typically include this),
        // then mandatory-collection (some plans only include this).
        $module_groups = [
            ['details_key' => 'totalcollection_tax_details', 'summary_key' => 'totalcollection_tax_summary', 'prefix' => 't_tax_'],
            ['details_key' => 'mandatorycollection_tax_details', 'summary_key' => 'mandatorycollection_tax_summary', 'prefix' => 'm_tax_'],
        ];

        foreach ($module_groups as $group) {
            // Use details if available (richer), else summary.
            $node = null;
            $is_details = false;
            if (isset($root[$group['details_key']]) && is_array($root[$group['details_key']])) {
                $node = $root[$group['details_key']];
                $is_details = true;
            } elseif (isset($root[$group['summary_key']]) && is_array($root[$group['summary_key']])) {
                $node = $root[$group['summary_key']];
            }
            if (!$node) {
                continue;
            }

            $p = $group['prefix'];
            $items = [];

            // State
            $state_rate = self::numeric_field($node, $p . 'state_tax');
            if ($state_rate !== null) {
                $state_name = self::scalar_field($node, [$p . 'state_jurisction_name', $p . 'state_jurisdiction_name']);
                $items[] = [
                    'type'         => 'state',
                    'jurisdiction' => $state_name !== '' ? $state_name : 'State Tax',
                    'rate'         => $state_rate,
                ];
            }

            // County (base rate)
            $county_rate = self::numeric_field($node, $p . 'county_tax');
            if ($county_rate !== null) {
                $county_name = self::scalar_field($node, [$p . 'county_jurisdiction_name']);
                $items[] = [
                    'type'         => 'county',
                    'jurisdiction' => $county_name !== '' ? $county_name . ' County' : 'County Tax',
                    'rate'         => $county_rate,
                ];
            }

            // County districts 1..N (details only). Names live in *_district{N}_name,
            // rates in *_district{N}_tax. Documented N ≤ 3, but we walk until missing
            // to stay safe if USGeocoder ever extends the schema.
            // Skip these itemizations when a positive county base rate already covers them — USGeocoder
            // reports county_tax as the rolled-up total *and* repeats it as county_district1_tax, causing double-counting.
            if ($is_details && !($county_rate !== null && $county_rate > 0)) {
                for ($i = 1; $i <= 10; $i++) {
                    $rate = self::numeric_field($node, $p . 'county_district' . $i . '_tax');
                    if ($rate === null) {
                        break;
                    }
                    $name = self::scalar_field($node, [$p . 'county_district' . $i . '_name', $p . 'county_district' . $i . '_abbr']);
                    $items[] = [
                        'type'         => 'special',
                        'jurisdiction' => $name !== '' ? $name : ('County District ' . $i),
                        'rate'         => $rate,
                    ];
                }
            }

            // City (base rate)
            $city_rate = self::numeric_field($node, $p . 'city_tax');
            if ($city_rate !== null) {
                $city_name = self::scalar_field($node, [$p . 'city_jurisdiction_name', $p . 'incorporated_city']);
                $items[] = [
                    'type'         => 'city',
                    'jurisdiction' => $city_name !== '' ? $city_name . ' City' : 'City Tax',
                    'rate'         => $city_rate,
                ];
            }

            // City districts 1..N (details only).
            // Same de-duplication: skip when a positive city base rate already covers them.
            if ($is_details && !($city_rate !== null && $city_rate > 0)) {
                for ($i = 1; $i <= 10; $i++) {
                    $rate = self::numeric_field($node, $p . 'city_district' . $i . '_tax');
                    if ($rate === null) {
                        break;
                    }
                    $name = self::scalar_field($node, [$p . 'city_district' . $i . '_name', $p . 'city_district' . $i . '_abbr']);
                    $items[] = [
                        'type'         => 'special',
                        'jurisdiction' => $name !== '' ? $name : ('City District ' . $i),
                        'rate'         => $rate,
                    ];
                }
            }

            // Special districts (details only).
            // These are independent of city/county and should always be extracted.
            if ($is_details) {
                for ($i = 1; $i <= 10; $i++) {
                    $rate = self::numeric_field($node, $p . 'special_district' . $i . '_tax');
                    if ($rate === null) {
                        break;
                    }
                    $name = self::scalar_field($node, [$p . 'special_district' . $i . '_name', $p . 'special_district' . $i . '_abbr']);
                    $items[] = [
                        'type'         => 'special',
                        'jurisdiction' => $name !== '' ? $name : ('Special District ' . $i),
                        'rate'         => $rate,
                    ];
                }
            }

            // Capture tax code + incorporated city in the trace so the audit
            // table has the full vendor metadata.
            $tax_code = self::scalar_field($node, [$p . 'code']);
            if ($tax_code !== '') {
                $result->trace['taxCode'] = $tax_code;
            }
            $incorp_city = self::scalar_field($node, [$p . 'incorporated_city']);
            if ($incorp_city !== '') {
                $result->trace['incorporatedCity'] = $incorp_city;
            }
            $result->trace['usgeocoderModule'] = ($group['prefix'] === 't_tax_') ? 'total_collection' : 'mandatory_collection';

            if (!empty($items)) {
                // Explicit field mappings → bump confidence from medium to high
                // because we're no longer guessing at the response shape.
                $result->confidence = Tax_Quote_Result::CONFIDENCE_HIGH;
                return $items;
            }
        }

        return [];
    }

    /**
     * Read a numeric field from a USGeocoder node. Accepts "9.250%" / "0.0925"
     * / "Collections Not Required" — only returns a float when the value
     * parses to a usable rate (>= 0). Anything else returns null so the
     * caller can decide whether to skip the line entirely.
     */
    private static function numeric_field(array $node, string $key): ?float
    {
        if (!array_key_exists($key, $node) || !is_scalar($node[$key])) {
            return null;
        }
        $raw = trim((string) $node[$key]);
        if ($raw === '') {
            return null;
        }
        // USGeocoder uses string sentinels like "Collections Not Required"
        // for mandatory-collection jurisdictions with no obligation. Skip
        // anything that doesn't contain a digit.
        if (!preg_match('/\d/', $raw)) {
            return null;
        }
        return self::to_decimal_rate($raw);
    }

    private static function scalar_field(array $node, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $node) && is_scalar($node[$key])) {
                $val = trim((string) $node[$key]);
                if ($val !== '') {
                    return $val;
                }
            }
        }
        return '';
    }

    /**
     * @return array<int,array{type:string,jurisdiction:string,rate:float}>
     */
    private static function extract_breakdown_items(array $payload): array
    {
        $items = [];
        $candidates = self::collect_arrays_with_rate($payload);

        foreach ($candidates as $row) {
            $name = '';
            foreach (['name', 'jurisdiction', 'label', 'description', 'module', 'type'] as $k) {
                if (!empty($row[$k]) && is_scalar($row[$k])) {
                    $name = trim((string) $row[$k]);
                    break;
                }
            }

            $rate = null;
            foreach ($row as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                if (stripos((string) $key, 'rate') !== false || stripos((string) $key, 'tax') !== false) {
                    $rate = self::to_decimal_rate((string) $value);
                    if ($rate !== null) {
                        break;
                    }
                }
            }

            if ($rate === null) {
                continue;
            }

            $lower_name = strtolower($name);
            $type = 'special';
            if (strpos($lower_name, 'state') !== false) {
                $type = 'state';
            } elseif (strpos($lower_name, 'county') !== false) {
                $type = 'county';
            } elseif (strpos($lower_name, 'city') !== false) {
                $type = 'city';
            }

            $items[] = [
                'type' => $type,
                'jurisdiction' => $name !== '' ? $name : 'USGeocoder Detail',
                'rate' => $rate,
            ];
        }

        // Dedupe by (jurisdiction lowercase + rate to 6dp). Belt-and-suspenders
        // in case the JSON shape changes again and a row gets collected at two
        // depths — better to display one accurate line than two and double the
        // total.
        $seen = [];
        $deduped = [];
        foreach ($items as $item) {
            $key = strtolower((string) $item['jurisdiction']) . '|' . number_format((float) $item['rate'], 6, '.', '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $item;
        }
        $items = $deduped;

        if (count($items) > 8) {
            $items = array_slice($items, 0, 8);
        }

        return $items;
    }

    public static function extract_total_rate(array $payload): ?float
    {
        // Documented schema: usgeocoder.totalcollection_tax_*.t_tax_total_tax
        // (paid plans) or usgeocoder.mandatorycollection_tax_*.m_tax_total_tax.
        $root = isset($payload['usgeocoder']) && is_array($payload['usgeocoder'])
            ? $payload['usgeocoder']
            : $payload;
        $known_paths = [
            ['totalcollection_tax_details', 't_tax_total_tax'],
            ['totalcollection_tax_summary', 't_tax_total_tax'],
            ['mandatorycollection_tax_details', 'm_tax_total_tax'],
            ['mandatorycollection_tax_summary', 'm_tax_total_tax'],
        ];
        foreach ($known_paths as $path) {
            $node = $root;
            foreach ($path as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) {
                    $node = null;
                    break;
                }
                $node = $node[$part];
            }
            if (is_scalar($node)) {
                $rate = self::to_decimal_rate((string) $node);
                if ($rate !== null) {
                    return $rate;
                }
            }
        }

        // Fallback: keyword scan (kept for compatibility with unknown shapes).
        $preferred = self::find_numeric_by_key_pattern($payload, '/(total|combined).*(tax|rate)|(tax|rate).*(total|combined)/i');
        if ($preferred !== null) {
            return self::to_decimal_rate((string) $preferred);
        }
        $fallback = self::find_numeric_by_key_pattern($payload, '/(tax|rate)/i');
        return $fallback !== null ? self::to_decimal_rate((string) $fallback) : null;
    }

    /**
     * Walk the JSON depth-first and collect rate-bearing nodes, preferring
     * the deepest match in each branch.
     *
     * USGeocoder returns both a summary (e.g. `total_sales_tax_rate`) AND
     * itemized details. Including both produces a double-count where the
     * summary row gets summed with the detail rows. The fix is to recurse
     * first: if any nested descendant carries a rate-like key, use those
     * detail rows and skip the current (summary) node. We only fall back to
     * the current node when nothing deeper qualifies.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function collect_arrays_with_rate($node): array
    {
        $rows = [];

        if (!is_array($node)) {
            return $rows;
        }

        // Depth-first: collect from descendants first.
        foreach ($node as $value) {
            if (is_array($value)) {
                $rows = array_merge($rows, self::collect_arrays_with_rate($value));
            }
        }

        // Any descendant returned a row → prefer those (they're more specific
        // than the current summary node). Skip the current node.
        if (!empty($rows)) {
            return $rows;
        }

        // Leaf-ish node: include only if it has a rate-like scalar key.
        foreach ($node as $key => $value) {
            if (is_string($key)
                && (stripos($key, 'rate') !== false || stripos($key, 'tax') !== false)
                && is_scalar($value)
            ) {
                $rows[] = $node;
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<int,array<int,string>> $paths
     */
    private static function pick_first_string(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $node = $payload;
            foreach ($path as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) {
                    $node = null;
                    break;
                }
                $node = $node[$part];
            }

            if (is_scalar($node)) {
                $value = trim((string) $node);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private static function find_numeric_by_key_pattern($node, string $pattern): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        foreach ($node as $key => $value) {
            if (is_string($key) && preg_match($pattern, $key) && is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '' && preg_match('/[-+]?\d+(?:\.\d+)?/', $text)) {
                    return $text;
                }
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $match = self::find_numeric_by_key_pattern($value, $pattern);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }

    private static function to_decimal_rate(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $number = preg_replace('/[^0-9.\-]+/', '', $value);
        if ($number === '' || !is_numeric($number)) {
            return null;
        }

        $rate = (float) $number;
        // USGeocoder always returns percentages (e.g. 2.9 for 2.9%).
        // Always divide by 100 — the old "> 1" guard left sub-1% jurisdictions
        // (0.5% county, 0.1% SCFD) unscaled, producing 50% / 10% rates.
        $rate /= 100;

        if ($rate < 0) {
            return null;
        }

        return round($rate, 6);
    }
}
