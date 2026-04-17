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

        $matched_address = self::pick_first_string($payload, [
            ['result', 'address'],
            ['address'],
            ['matchedAddress'],
            ['street_address'],
        ]);

        if ($matched_address !== '') {
            $result->matchedAddress = $matched_address;
        }

        $breakdown = self::extract_breakdown_items($payload);
        foreach ($breakdown as $item) {
            $result->add_breakdown($item['type'], $item['jurisdiction'], $item['rate']);
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

        if (count($items) > 8) {
            $items = array_slice($items, 0, 8);
        }

        return $items;
    }

    public static function extract_total_rate(array $payload): ?float
    {
        $preferred = self::find_numeric_by_key_pattern($payload, '/(total|combined).*(tax|rate)|(tax|rate).*(total|combined)/i');
        if ($preferred !== null) {
            return self::to_decimal_rate((string) $preferred);
        }

        $fallback = self::find_numeric_by_key_pattern($payload, '/(tax|rate)/i');
        return $fallback !== null ? self::to_decimal_rate((string) $fallback) : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function collect_arrays_with_rate($node): array
    {
        $rows = [];

        if (!is_array($node)) {
            return $rows;
        }

        $has_rate_like_key = false;
        foreach ($node as $key => $value) {
            if (is_string($key) && (stripos($key, 'rate') !== false || stripos($key, 'tax') !== false) && is_scalar($value)) {
                $has_rate_like_key = true;
                break;
            }
        }

        if ($has_rate_like_key) {
            $rows[] = $node;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $rows = array_merge($rows, self::collect_arrays_with_rate($value));
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
        if ($rate > 1) {
            $rate /= 100;
        }

        if ($rate < 0) {
            return null;
        }

        return round($rate, 6);
    }
}
