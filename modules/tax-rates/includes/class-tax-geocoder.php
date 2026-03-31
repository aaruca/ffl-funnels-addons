<?php
/**
 * Tax Geocoder — US Census Bureau Geocoder integration.
 *
 * Uses the free Census Geocoder API to validate and geocode
 * US addresses. Returns matched address, coordinates, and
 * geographic identifiers (state FIPS, county FIPS, tract).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Geocoder
{
    const API_BASE = 'https://geocoding.geo.census.gov/geocoder/geographies/address';
    const BENCHMARK = 'Public_AR_Current';
    const VINTAGE   = 'Current_Current';
    const TIMEOUT   = 15; // seconds

    /**
     * Geocode an address using the Census Bureau API.
     *
     * @param  array $normalized Normalized address components.
     * @return array Geocode result with matched address, coordinates, geographies.
     */
    public static function geocode(array $normalized): array
    {
        $result = [
            'success'        => false,
            'matchType'      => null,
            'matchedAddress' => null,
            'coordinates'    => null,
            'stateFips'      => null,
            'countyFips'     => null,
            'countyName'     => null,
            'tract'          => null,
            'blockGroup'     => null,
            'tigerLineId'    => null,
            'confidence'     => 'none',
            'raw'            => null,
            'error'          => null,
        ];

        // Build query params.
        $params = [
            'street'    => $normalized['street'] ?? '',
            'city'      => $normalized['city'] ?? '',
            'state'     => $normalized['state'] ?? '',
            'zip'       => $normalized['zip'] ?? '',
            'benchmark' => self::BENCHMARK,
            'vintage'   => self::VINTAGE,
            'format'    => 'json',
        ];

        $url = self::API_BASE . '?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            $result['error'] = 'Census Geocoder request failed: ' . $response->get_error_message();
            return $result;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $result['error'] = "Census Geocoder returned HTTP {$code}.";
            return $result;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['result'])) {
            $result['error'] = 'Census Geocoder returned empty result.';
            return $result;
        }

        $matches = $body['result']['addressMatches'] ?? [];

        if (empty($matches)) {
            $result['error'] = 'No address match found.';
            return $result;
        }

        // Use the first (best) match.
        $match = $matches[0];

        $result['success']        = true;
        $result['matchedAddress'] = $match['matchedAddress'] ?? null;
        $result['matchType']      = $match['tigerLine']['side'] ?? 'exact';

        // Coordinates.
        if (!empty($match['coordinates'])) {
            $result['coordinates'] = [
                'lat' => (float) $match['coordinates']['y'],
                'lng' => (float) $match['coordinates']['x'],
            ];
        }

        // Geographies — the Census API nests these under 'geographies'.
        $geos = $match['geographies'] ?? [];

        // State and county codes from Census Tracts data.
        if (!empty($geos['Census Tracts'])) {
            $tract_data = $geos['Census Tracts'][0];
            $result['stateFips']  = $tract_data['STATE'] ?? null;
            $result['countyFips'] = $tract_data['COUNTY'] ?? null;
            $result['tract']      = $tract_data['TRACT'] ?? null;
        }

        // Prefer Counties geography for the actual county/parish name.
        if (!empty($geos['Counties'])) {
            $county_data = $geos['Counties'][0];
            if (empty($result['stateFips'])) {
                $result['stateFips'] = $county_data['STATE'] ?? null;
            }
            if (empty($result['countyFips'])) {
                $result['countyFips'] = $county_data['COUNTY'] ?? null;
            }
            $result['countyName'] = $county_data['NAME']
                ?? $county_data['BASENAME']
                ?? $result['countyName'];
        }

        // Tiger Line ID for precise geographic matching.
        $result['tigerLineId'] = $match['tigerLine']['tigerLineId'] ?? null;

        // Assess confidence.
        $result['confidence'] = self::assess_confidence($match, $normalized);

        // Keep raw for debugging.
        $result['raw'] = $match;

        return $result;
    }

    /**
     * Assess geocoding confidence based on match quality.
     *
     * @param  array $match      Census match data.
     * @param  array $normalized Original normalized address.
     * @return string 'high', 'medium', or 'low'
     */
    private static function assess_confidence(array $match, array $normalized): string
    {
        $matched = strtoupper($match['matchedAddress'] ?? '');
        $input   = strtoupper(($normalized['street'] ?? '') . ', ' . ($normalized['city'] ?? ''));

        // If matched address closely resembles input → high confidence.
        similar_text($matched, $input, $pct);

        if ($pct >= 70) {
            return 'high';
        } elseif ($pct >= 40) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Check if a geocode result has usable geographic data.
     */
    public static function has_geographies(array $geocode): bool
    {
        return !empty($geocode['stateFips']) && !empty($geocode['countyFips']);
    }

    /**
     * Get the full FIPS code (state + county) from geocode result.
     *
     * @param  array $geocode Geocode result.
     * @return string|null 5-digit FIPS code (e.g., '17031' for Cook County, IL).
     */
    public static function get_fips(array $geocode): ?string
    {
        if (empty($geocode['stateFips']) || empty($geocode['countyFips'])) {
            return null;
        }
        return $geocode['stateFips'] . $geocode['countyFips'];
    }
}
