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
    private const API_ENDPOINT = 'https://api.usgeocoder.com/api/get_info.php';
    private const TIMEOUT = 20;

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

        if ($auth_key === '') {
            $result = new Tax_Quote_Result();
            $result->inputAddress      = $normalized;
            $result->normalizedAddress = $normalized;
            $result->state             = $state_code;
            $result->coverageStatus    = Tax_Coverage::SUPPORTED_WITH_REMOTE;
            $result->source            = $this->get_source_code();
            $result->trace['resolver'] = $this->get_id();
            $result->trace['geocodeUsed'] = false;
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                'USGeocoder auth key is missing. Add it in Tax Resolver settings.'
            );
            return $result;
        }

        $query = [
            'authkey' => $auth_key,
            'format'  => 'json',
        ];

        $street = trim((string) ($normalized['street'] ?? ''));
        $zip5   = substr(preg_replace('/[^0-9]/', '', (string) ($normalized['zip'] ?? '')), 0, 5);

        if ($street !== '' && $zip5 !== '') {
            $query['address'] = $street;
            $query['zipcode'] = $zip5;
        } else {
            $result = new Tax_Quote_Result();
            $result->inputAddress      = $normalized;
            $result->normalizedAddress = $normalized;
            $result->state             = $state_code;
            $result->coverageStatus    = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
            $result->source            = $this->get_source_code();
            $result->trace['resolver'] = $this->get_id();
            $result->trace['geocodeUsed'] = false;
            $result->set_error(
                Tax_Quote_Result::OUTCOME_VALIDATION_ERROR,
                'USGeocoder resolution requires street and ZIP code.'
            );
            return $result;
        }

        $url = self::API_ENDPOINT . '?' . http_build_query($query);
        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            $result = new Tax_Quote_Result();
            $result->inputAddress      = $normalized;
            $result->normalizedAddress = $normalized;
            $result->state             = $state_code;
            $result->coverageStatus    = Tax_Coverage::DEGRADED;
            $result->source            = $this->get_source_code();
            $result->trace['resolver'] = $this->get_id();
            $result->trace['geocodeUsed'] = false;
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                'USGeocoder request failed: ' . $response->get_error_message()
            );
            return $result;
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body_raw  = (string) wp_remote_retrieve_body($response);
        $payload   = json_decode($body_raw, true);

        if ($http_code !== 200 || !is_array($payload)) {
            $result = new Tax_Quote_Result();
            $result->inputAddress      = $normalized;
            $result->normalizedAddress = $normalized;
            $result->state             = $state_code;
            $result->coverageStatus    = Tax_Coverage::DEGRADED;
            $result->source            = $this->get_source_code();
            $result->trace['resolver'] = $this->get_id();
            $result->trace['geocodeUsed'] = false;
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                sprintf('USGeocoder returned an invalid response (HTTP %d).', $http_code)
            );
            return $result;
        }

        $result = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->state             = $state_code;
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_WITH_REMOTE;
        $result->determinationScope = 'address_rate_only';
        $result->resolutionMode     = 'usgeocoder_live_api';
        $result->source             = $this->get_source_code();
        $result->sourceVersion      = 'live';
        $result->confidence         = Tax_Quote_Result::CONFIDENCE_MEDIUM;
        $result->trace['resolver']  = $this->get_id();
        $result->trace['geocodeUsed'] = false;
        $result->trace['sourceUrl'] = self::API_ENDPOINT;

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

        // Avoid huge noisy payloads.
        if (count($items) > 8) {
            $items = array_slice($items, 0, 8);
        }

        return $items;
    }

    private static function extract_total_rate(array $payload): ?float
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
