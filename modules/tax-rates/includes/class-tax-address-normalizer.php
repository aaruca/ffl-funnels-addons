<?php
/**
 * Tax Address Normalizer.
 *
 * Parses and normalizes US address input into canonical components.
 * Generates deterministic cache keys for deduplication.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Address_Normalizer
{
    /* Common street type abbreviations → canonical form. */
    private static $street_abbr = [
        'ST'    => 'ST', 'STREET' => 'ST',
        'AVE'   => 'AVE', 'AVENUE' => 'AVE',
        'BLVD'  => 'BLVD', 'BOULEVARD' => 'BLVD',
        'DR'    => 'DR', 'DRIVE' => 'DR',
        'LN'    => 'LN', 'LANE' => 'LN',
        'RD'    => 'RD', 'ROAD' => 'RD',
        'CT'    => 'CT', 'COURT' => 'CT',
        'PL'    => 'PL', 'PLACE' => 'PL',
        'CIR'   => 'CIR', 'CIRCLE' => 'CIR',
        'WAY'   => 'WAY',
        'PKWY'  => 'PKWY', 'PARKWAY' => 'PKWY',
        'HWY'   => 'HWY', 'HIGHWAY' => 'HWY',
        'TRL'   => 'TRL', 'TRAIL' => 'TRL',
        'SQ'    => 'SQ', 'SQUARE' => 'SQ',
    ];

    /* Directional abbreviations. */
    private static $directional = [
        'NORTH' => 'N', 'SOUTH' => 'S', 'EAST' => 'E', 'WEST' => 'W',
        'NORTHEAST' => 'NE', 'NORTHWEST' => 'NW',
        'SOUTHEAST' => 'SE', 'SOUTHWEST' => 'SW',
        'N' => 'N', 'S' => 'S', 'E' => 'E', 'W' => 'W',
        'NE' => 'NE', 'NW' => 'NW', 'SE' => 'SE', 'SW' => 'SW',
    ];

    /* Unit type abbreviations. */
    private static $unit_types = [
        'APARTMENT' => 'APT', 'APT' => 'APT',
        'SUITE'     => 'STE', 'STE' => 'STE',
        'UNIT'      => 'UNIT',
        'FLOOR'     => 'FL', 'FL' => 'FL',
        'ROOM'      => 'RM', 'RM' => 'RM',
        'BUILDING'  => 'BLDG', 'BLDG' => 'BLDG',
    ];

    /**
     * Normalize an address input into canonical components.
     *
     * @param  array $input Raw address input ['street', 'city', 'state', 'zip'] or ['address' => freeform].
     * @return array Normalized address components + cache key.
     */
    public static function normalize(array $input): array
    {
        // If freeform, attempt to parse.
        if (!empty($input['address']) && empty($input['street'])) {
            $parsed = self::parse_freeform($input['address']);
            $input  = array_merge($input, $parsed);
        }

        $street = self::normalize_street($input['street'] ?? '');
        $city   = self::normalize_city($input['city'] ?? '');
        $state  = self::normalize_state($input['state'] ?? '');
        $zip    = self::normalize_zip($input['zip'] ?? '');

        $normalized = [
            'street' => $street,
            'city'   => $city,
            'state'  => $state,
            'zip'    => $zip,
        ];

        $normalized['key']    = self::build_key($normalized);
        $normalized['valid']  = self::validate($normalized);
        $normalized['errors'] = self::get_errors($normalized);

        return $normalized;
    }

    /**
     * Attempt to parse a freeform address string.
     *
     * Handles formats like:
     *   "123 Main St, Chicago, IL 60601"
     *   "123 Main Street, Chicago, Illinois, 60601"
     *
     * @param  string $address Freeform address string.
     * @return array  Parsed components.
     */
    public static function parse_freeform(string $address): array
    {
        $result = ['street' => '', 'city' => '', 'state' => '', 'zip' => ''];

        $address = trim($address);
        if (empty($address)) {
            return $result;
        }

        // Try to extract ZIP code (5 or 5+4 digit pattern at end).
        if (preg_match('/\b(\d{5}(?:-\d{4})?)\s*$/', $address, $m)) {
            $result['zip'] = $m[1];
            $address = trim(substr($address, 0, -strlen($m[0])));
        }

        // Split by comma.
        $parts = array_map('trim', explode(',', $address));

        if (count($parts) >= 3) {
            $result['street'] = $parts[0];
            $result['city']   = $parts[1];
            // Last part might be "IL" or "IL 60601" or "Illinois".
            $state_part = trim($parts[count($parts) - 1]);
            // If it contains digits, try to extract state before zip.
            if (preg_match('/^([A-Za-z]+(?:\s+[A-Za-z]+)?)\s+\d/', $state_part, $sm)) {
                $state_part = $sm[1];
            }
            $result['state'] = $state_part;
        } elseif (count($parts) === 2) {
            $result['street'] = $parts[0];
            // Second part: "City, ST ZIP" or "City ST ZIP".
            $city_state = $parts[1];
            if (preg_match('/^(.+?)\s+([A-Z]{2})\s*$/i', $city_state, $csm)) {
                $result['city']  = $csm[1];
                $result['state'] = $csm[2];
            } else {
                $result['city'] = $city_state;
            }
        } else {
            // Single line: try "123 Main St City ST ZIP".
            if (preg_match('/^(.+?)\s+([A-Z]{2})\s*$/i', $address, $slm)) {
                $before_state = $slm[1];
                $result['state'] = $slm[2];
                // Try to separate street from city (heuristic: last word group before state).
                $result['street'] = $before_state;
            } else {
                $result['street'] = $address;
            }
        }

        return $result;
    }

    /**
     * Normalize a street address string.
     */
    public static function normalize_street(string $street): string
    {
        $street = strtoupper(trim($street));

        // Remove extra whitespace.
        $street = preg_replace('/\s+/', ' ', $street);

        // Remove periods (e.g., "St." → "ST").
        $street = str_replace('.', '', $street);

        // Normalize directionals.
        $words = explode(' ', $street);
        foreach ($words as &$word) {
            if (isset(self::$directional[$word])) {
                $word = self::$directional[$word];
            }
            if (isset(self::$street_abbr[$word])) {
                $word = self::$street_abbr[$word];
            }
            if (isset(self::$unit_types[$word])) {
                $word = self::$unit_types[$word];
            }
        }

        return implode(' ', $words);
    }

    /**
     * Normalize a city name.
     */
    public static function normalize_city(string $city): string
    {
        $city = strtoupper(trim($city));
        $city = preg_replace('/\s+/', ' ', $city);
        $city = str_replace('.', '', $city);
        return $city;
    }

    /**
     * Normalize a state code or name to 2-letter code.
     */
    public static function normalize_state(string $state): string
    {
        $state = strtoupper(trim($state));
        $state = str_replace('.', '', $state);

        // If already 2-letter code, return.
        if (preg_match('/^[A-Z]{2}$/', $state)) {
            return $state;
        }

        // Try name → code mapping.
        $map = self::get_state_names();
        return $map[$state] ?? $state;
    }

    /**
     * Normalize a ZIP code.
     */
    public static function normalize_zip(string $zip): string
    {
        $zip = trim($zip);
        // Extract 5-digit or 5+4 format.
        if (preg_match('/(\d{5}(?:-\d{4})?)/', $zip, $m)) {
            return $m[1];
        }
        return $zip;
    }

    /**
     * Build a deterministic cache key from normalized components.
     */
    public static function build_key(array $normalized): string
    {
        $raw = implode('|', [
            $normalized['street'] ?? '',
            $normalized['city'] ?? '',
            $normalized['state'] ?? '',
            substr($normalized['zip'] ?? '', 0, 5),
        ]);

        return hash('sha256', $raw);
    }

    /**
     * Validate minimal address requirements.
     */
    public static function validate(array $normalized): bool
    {
        return !empty($normalized['state'])
            && preg_match('/^[A-Z]{2}$/', $normalized['state'])
            && (!empty($normalized['street']) || !empty($normalized['zip']));
    }

    /**
     * Get validation errors.
     */
    public static function get_errors(array $normalized): array
    {
        $errors = [];

        if (empty($normalized['state']) || !preg_match('/^[A-Z]{2}$/', $normalized['state'] ?? '')) {
            $errors[] = 'Invalid or missing state code.';
        }
        if (empty($normalized['street'])) {
            $errors[] = 'Street address is missing.';
        }
        if (empty($normalized['zip'])) {
            $errors[] = 'ZIP code is missing (recommended for accuracy).';
        }
        if (empty($normalized['city'])) {
            $errors[] = 'City is missing (recommended for accuracy).';
        }

        return $errors;
    }

    /**
     * State name → code mapping.
     */
    private static function get_state_names(): array
    {
        return [
            'ALABAMA' => 'AL', 'ALASKA' => 'AK', 'ARIZONA' => 'AZ', 'ARKANSAS' => 'AR',
            'CALIFORNIA' => 'CA', 'COLORADO' => 'CO', 'CONNECTICUT' => 'CT', 'DELAWARE' => 'DE',
            'DISTRICT OF COLUMBIA' => 'DC', 'FLORIDA' => 'FL', 'GEORGIA' => 'GA', 'HAWAII' => 'HI',
            'IDAHO' => 'ID', 'ILLINOIS' => 'IL', 'INDIANA' => 'IN', 'IOWA' => 'IA',
            'KANSAS' => 'KS', 'KENTUCKY' => 'KY', 'LOUISIANA' => 'LA', 'MAINE' => 'ME',
            'MARYLAND' => 'MD', 'MASSACHUSETTS' => 'MA', 'MICHIGAN' => 'MI', 'MINNESOTA' => 'MN',
            'MISSISSIPPI' => 'MS', 'MISSOURI' => 'MO', 'MONTANA' => 'MT', 'NEBRASKA' => 'NE',
            'NEVADA' => 'NV', 'NEW HAMPSHIRE' => 'NH', 'NEW JERSEY' => 'NJ', 'NEW MEXICO' => 'NM',
            'NEW YORK' => 'NY', 'NORTH CAROLINA' => 'NC', 'NORTH DAKOTA' => 'ND', 'OHIO' => 'OH',
            'OKLAHOMA' => 'OK', 'OREGON' => 'OR', 'PENNSYLVANIA' => 'PA', 'RHODE ISLAND' => 'RI',
            'SOUTH CAROLINA' => 'SC', 'SOUTH DAKOTA' => 'SD', 'TENNESSEE' => 'TN', 'TEXAS' => 'TX',
            'UTAH' => 'UT', 'VERMONT' => 'VT', 'VIRGINIA' => 'VA', 'WASHINGTON' => 'WA',
            'WEST VIRGINIA' => 'WV', 'WISCONSIN' => 'WI', 'WYOMING' => 'WY',
        ];
    }
}
