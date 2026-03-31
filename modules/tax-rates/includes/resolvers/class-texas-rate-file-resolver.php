<?php
/**
 * Texas Rate File Resolver.
 *
 * Uses the official Texas Comptroller sales tax EDI rate file.
 * This source is authoritative for state, city, county, and many
 * special district rates, but it does not provide parcel boundaries,
 * so ambiguous special district matches are intentionally degraded.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Texas_Rate_File_Resolver extends Tax_Resolver_Base
{
    const RATE_FILE_URL = 'https://comptroller.texas.gov/data/edi/sales-tax/taxrates.txt';
    const CACHE_TTL = 43200; // 12 hours.
    const DOWNLOAD_TTL = 86400; // 24 hours.
    const REQUEST_TIMEOUT = 20;

    public function get_id(): string
    {
        return 'tx_rate_file';
    }

    public function get_name(): string
    {
        return 'Texas Comptroller Sales Tax Rate File';
    }

    public function get_source_code(): string
    {
        return 'tx_sales_tax_rate_file';
    }

    public function get_supported_states(): array
    {
        return ['TX'];
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $result                   = new Tax_Quote_Result();
        $result->inputAddress     = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress   = $geocode['matchedAddress'] ?? null;
        $result->state            = 'TX';
        $result->coverageStatus   = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
        $result->resolutionMode   = 'official_rate_file_match';
        $result->source           = $this->get_source_code();
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);

        $dataset = $this->load_rate_file();
        if (is_wp_error($dataset)) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                'Texas official rate file is currently unavailable: ' . $dataset->get_error_message()
            );
            return $result;
        }

        $city = $this->normalize_name($normalized['city'] ?? '');
        $county = $this->resolve_county_name($dataset['rows'], $geocode);

        if ($county === '') {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
                'Texas county could not be determined from the geocoded address.'
            );
            return $result;
        }

        $selection = $this->select_bundle($dataset['rows'], $city, $county);
        if ($selection === null) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
                "Texas rate could not be matched for city '{$normalized['city']}' in county '{$geocode['countyName']}'."
            );
            return $result;
        }

        $result->sourceVersion = $dataset['version_label'];
        $result->effectiveDate = $dataset['effective_date'];
        $result->confidence    = $selection['confidence'];
        $result->trace['county'] = $county;
        $result->trace['txMatchType'] = $selection['match_type'];

        $result->add_breakdown('state', 'Texas State', $dataset['state_rate']);

        if ($selection['county_rate'] > 0) {
            $result->add_breakdown('county', $selection['county_name'], $selection['county_rate']);
        }

        if ($selection['city_rate'] > 0) {
            $result->add_breakdown('city', $selection['city_name'], $selection['city_rate']);
        }

        foreach ($selection['specials'] as $special) {
            if ($special['rate'] > 0) {
                $result->add_breakdown('special', $special['name'], $special['rate']);
            }
        }

        $result->calculate_total();

        $result->limitations[] = 'Texas rate resolved from the official Comptroller EDI sales tax rate file.';
        $result->limitations[] = 'Special purpose district boundaries are not parcel-level in this source and may require manual review.';

        if ($selection['match_type'] === 'exact') {
            $result->determinationScope = 'address_rate_only';
        } elseif ($selection['match_type'] === 'base_city_county') {
            $result->determinationScope = 'city_county_rate_only';
            $result->limitations[] = 'Only the common city and county components could be determined confidently for this location.';
        } else {
            $result->determinationScope = 'county_rate_only';
            $result->limitations[] = 'Location appears outside an incorporated city or could only be matched at the county level.';
        }

        return $result;
    }

    /**
     * Load and parse the current Texas official rate file.
     *
     * @return array|WP_Error
     */
    private function load_rate_file()
    {
        $cached = get_transient('ffla_tax_tx_rate_file');
        if (is_array($cached) && !empty($cached['rows'])) {
            return $cached;
        }

        $storage_dir = Tax_Dataset_Pipeline::get_storage_dir();
        $file_path   = $storage_dir . 'TX_taxrates.txt';

        if (!file_exists($file_path) || (time() - filemtime($file_path)) > self::DOWNLOAD_TTL) {
            $response = wp_remote_get(self::RATE_FILE_URL, [
                'timeout' => self::REQUEST_TIMEOUT,
                'headers' => ['Accept' => 'text/plain'],
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            if (wp_remote_retrieve_response_code($response) !== 200) {
                return new WP_Error('tx_rate_http', 'Official Texas rate file returned HTTP ' . wp_remote_retrieve_response_code($response) . '.');
            }

            $body = wp_remote_retrieve_body($response);
            if (!is_string($body) || trim($body) === '') {
                return new WP_Error('tx_rate_empty', 'Official Texas rate file returned empty content.');
            }

            file_put_contents($file_path, $body);
        }

        $content = file_get_contents($file_path);
        if (!is_string($content) || trim($content) === '') {
            return new WP_Error('tx_rate_read', 'Texas rate file could not be read from local storage.');
        }

        $parsed = $this->parse_rate_file($content);
        if (empty($parsed['rows'])) {
            return new WP_Error('tx_rate_parse', 'Texas rate file could not be parsed.');
        }

        set_transient('ffla_tax_tx_rate_file', $parsed, self::CACHE_TTL);

        return $parsed;
    }

    /**
     * Parse the official TXT file into bundles keyed by city/county/special rates.
     */
    private function parse_rate_file(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $rows  = [];

        $version_label = null;
        $effective_date = null;
        $state_rate = 0.0625;

        foreach ($lines as $index => $line) {
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t/', $line);
            $parts = array_map('trim', $parts);

            if ($index === 0 && count($parts) >= 5 && preg_match('/^\d{5}$/', $parts[0])) {
                $version_label = $parts[0];
                if (is_numeric($parts[4])) {
                    $state_rate = (float) $parts[4];
                }

                if (preg_match('/^(\d{4})(\d)$/', $parts[0], $match)) {
                    $quarter_month = [
                        '1' => '01',
                        '2' => '04',
                        '3' => '07',
                        '4' => '10',
                    ];
                    $effective_date = $match[1] . '-' . ($quarter_month[$match[2]] ?? '01') . '-01';
                }
                continue;
            }

            if (count($parts) < 12) {
                continue;
            }

            if (!is_numeric($parts[2]) || !is_numeric($parts[5]) || !is_numeric($parts[8]) || !is_numeric($parts[11])) {
                continue;
            }

            $rows[] = [
                'city_name'    => $parts[0],
                'city_name_key'=> $this->normalize_name($parts[0]),
                'city_rate'    => (float) $parts[2],
                'county_name'  => $parts[3],
                'county_name_key' => $this->normalize_name($parts[3]),
                'county_rate'  => (float) $parts[5],
                'specials'     => $this->build_specials([
                    ['name' => $parts[6], 'rate' => (float) $parts[8]],
                    ['name' => $parts[9], 'rate' => (float) $parts[11]],
                ]),
            ];
        }

        return [
            'version_label' => $version_label ?: 'tx-current',
            'effective_date'=> $effective_date ?: wp_date('Y-m-d'),
            'state_rate'    => $state_rate,
            'rows'          => $rows,
        ];
    }

    /**
     * Normalize and filter special district entries.
     */
    private function build_specials(array $specials): array
    {
        $result = [];

        foreach ($specials as $special) {
            $name = trim($special['name']);
            $rate = (float) $special['rate'];

            if ($name === '' || strtolower($name) === 'n/a' || $rate <= 0) {
                continue;
            }

            $result[] = [
                'name' => $name,
                'rate' => $rate,
            ];
        }

        return $result;
    }

    /**
     * Select the best city/county bundle from the official file.
     */
    private function select_bundle(array $rows, string $city, string $county): ?array
    {
        $county_rows = array_values(array_filter($rows, function ($row) use ($county) {
            return $row['county_name_key'] === $county;
        }));

        if (empty($county_rows)) {
            return null;
        }

        $exact_city_rows = array_values(array_filter($county_rows, function ($row) use ($city) {
            return $city !== '' && $row['city_name_key'] === $city;
        }));

        if (count($exact_city_rows) === 1) {
            return $this->finalize_bundle($exact_city_rows[0], 'exact');
        }

        if (count($exact_city_rows) > 1) {
            $base = array_values(array_filter($exact_city_rows, function ($row) {
                return empty($row['specials']);
            }));

            if (count($base) === 1) {
                return $this->finalize_bundle($base[0], 'exact');
            }

            return $this->build_common_bundle($exact_city_rows, 'base_city_county');
        }

        $county_only_rows = array_values(array_filter($county_rows, function ($row) {
            return $row['city_name_key'] === 'N A';
        }));

        if (count($county_only_rows) === 1) {
            return $this->finalize_bundle($county_only_rows[0], 'county_only');
        }

        if (count($county_only_rows) > 1) {
            return $this->build_common_bundle($county_only_rows, 'county_only');
        }

        $partial_rows = array_values(array_filter($county_rows, function ($row) use ($city) {
            return $city !== '' && strpos($row['city_name_key'], $city) !== false;
        }));

        if (count($partial_rows) === 1) {
            return $this->finalize_bundle($partial_rows[0], 'base_city_county');
        }

        if (count($partial_rows) > 1) {
            return $this->build_common_bundle($partial_rows, 'base_city_county');
        }

        return null;
    }

    /**
     * Resolve a usable county name from geocode data or the official file.
     */
    private function resolve_county_name(array $rows, array $geocode): string
    {
        $county = $this->normalize_name($geocode['countyName'] ?? '');
        if ($county !== '') {
            return $county;
        }

        $county_fips = str_pad((string) ($geocode['countyFips'] ?? ''), 3, '0', STR_PAD_LEFT);
        if ($county_fips === '') {
            return '';
        }

        $fips_map = [
            '001' => 'Anderson', '003' => 'Andrews', '005' => 'Angelina', '007' => 'Aransas',
            '009' => 'Archer', '011' => 'Armstrong', '013' => 'Atascosa', '015' => 'Austin',
            '017' => 'Bailey', '019' => 'Bandera', '021' => 'Bastrop', '023' => 'Baylor',
            '025' => 'Bee', '027' => 'Bell', '029' => 'Bexar', '031' => 'Blanco',
            '033' => 'Borden', '035' => 'Bosque', '037' => 'Bowie', '039' => 'Brazoria',
            '041' => 'Brazos', '043' => 'Brewster', '045' => 'Briscoe', '047' => 'Brooks',
            '049' => 'Brown', '051' => 'Burleson', '053' => 'Burnet', '055' => 'Caldwell',
            '057' => 'Calhoun', '059' => 'Callahan', '061' => 'Cameron', '063' => 'Camp',
            '065' => 'Carson', '067' => 'Cass', '069' => 'Castro', '071' => 'Chambers',
            '073' => 'Cherokee', '075' => 'Childress', '077' => 'Clay', '079' => 'Cochran',
            '081' => 'Coke', '083' => 'Coleman', '085' => 'Collin', '087' => 'Collingsworth',
            '089' => 'Colorado', '091' => 'Comal', '093' => 'Comanche', '095' => 'Concho',
            '097' => 'Cooke', '099' => 'Coryell', '101' => 'Cottle', '103' => 'Crane',
            '105' => 'Crockett', '107' => 'Crosby', '109' => 'Culberson', '111' => 'Dallam',
            '113' => 'Dallas', '115' => 'Dawson', '117' => 'Deaf Smith', '119' => 'Delta',
            '121' => 'Denton', '123' => 'DeWitt', '125' => 'Dickens', '127' => 'Dimmit',
            '129' => 'Donley', '131' => 'Duval', '133' => 'Eastland', '135' => 'Ector',
            '137' => 'Edwards', '139' => 'Ellis', '141' => 'El Paso', '143' => 'Erath',
            '145' => 'Falls', '147' => 'Fannin', '149' => 'Fayette', '151' => 'Fisher',
            '153' => 'Floyd', '155' => 'Foard', '157' => 'Fort Bend', '159' => 'Franklin',
            '161' => 'Freestone', '163' => 'Frio', '165' => 'Gaines', '167' => 'Galveston',
            '169' => 'Garza', '171' => 'Gillespie', '173' => 'Glasscock', '175' => 'Goliad',
            '177' => 'Gonzales', '179' => 'Gray', '181' => 'Grayson', '183' => 'Gregg',
            '185' => 'Grimes', '187' => 'Guadalupe', '189' => 'Hale', '191' => 'Hall',
            '193' => 'Hamilton', '195' => 'Hansford', '197' => 'Hardeman', '199' => 'Hardin',
            '201' => 'Harris', '203' => 'Harrison', '205' => 'Hartley', '207' => 'Haskell',
            '209' => 'Hays', '211' => 'Hemphill', '213' => 'Henderson', '215' => 'Hidalgo',
            '217' => 'Hill', '219' => 'Hockley', '221' => 'Hood', '223' => 'Hopkins',
            '225' => 'Houston', '227' => 'Howard', '229' => 'Hudspeth', '231' => 'Hunt',
            '233' => 'Hutchinson', '235' => 'Irion', '237' => 'Jack', '239' => 'Jackson',
            '241' => 'Jasper', '243' => 'Jeff Davis', '245' => 'Jefferson', '247' => 'Jim Hogg',
            '249' => 'Jim Wells', '251' => 'Johnson', '253' => 'Jones', '255' => 'Karnes',
            '257' => 'Kaufman', '259' => 'Kendall', '261' => 'Kenedy', '263' => 'Kent',
            '265' => 'Kerr', '267' => 'Kimble', '269' => 'King', '271' => 'Kinney',
            '273' => 'Kleberg', '275' => 'Knox', '277' => 'Lamar', '279' => 'Lamb',
            '281' => 'Lampasas', '283' => 'La Salle', '285' => 'Lavaca', '287' => 'Lee',
            '289' => 'Leon', '291' => 'Liberty', '293' => 'Limestone', '295' => 'Lipscomb',
            '297' => 'Live Oak', '299' => 'Llano', '301' => 'Loving', '303' => 'Lubbock',
            '305' => 'Lynn', '307' => 'McCulloch', '309' => 'McLennan', '311' => 'McMullen',
            '313' => 'Madison', '315' => 'Marion', '317' => 'Martin', '319' => 'Mason',
            '321' => 'Matagorda', '323' => 'Maverick', '325' => 'Medina', '327' => 'Menard',
            '329' => 'Midland', '331' => 'Milam', '333' => 'Mills', '335' => 'Mitchell',
            '337' => 'Montague', '339' => 'Montgomery', '341' => 'Moore', '343' => 'Morris',
            '345' => 'Motley', '347' => 'Nacogdoches', '349' => 'Navarro', '351' => 'Newton',
            '353' => 'Nolan', '355' => 'Nueces', '357' => 'Ochiltree', '359' => 'Oldham',
            '361' => 'Orange', '363' => 'Palo Pinto', '365' => 'Panola', '367' => 'Parker',
            '369' => 'Parmer', '371' => 'Pecos', '373' => 'Polk', '375' => 'Potter',
            '377' => 'Presidio', '379' => 'Rains', '381' => 'Randall', '383' => 'Reagan',
            '385' => 'Real', '387' => 'Red River', '389' => 'Reeves', '391' => 'Refugio',
            '393' => 'Roberts', '395' => 'Robertson', '397' => 'Rockwall', '399' => 'Runnels',
            '401' => 'Rusk', '403' => 'Sabine', '405' => 'San Augustine', '407' => 'San Jacinto',
            '409' => 'San Patricio', '411' => 'San Saba', '413' => 'Schleicher', '415' => 'Scurry',
            '417' => 'Shackelford', '419' => 'Shelby', '421' => 'Sherman', '423' => 'Smith',
            '425' => 'Somervell', '427' => 'Starr', '429' => 'Stephens', '431' => 'Sterling',
            '433' => 'Stonewall', '435' => 'Sutton', '437' => 'Swisher', '439' => 'Tarrant',
            '441' => 'Taylor', '443' => 'Terrell', '445' => 'Terry', '447' => 'Throckmorton',
            '449' => 'Titus', '451' => 'Tom Green', '453' => 'Travis', '455' => 'Trinity',
            '457' => 'Tyler', '459' => 'Upshur', '461' => 'Upton', '463' => 'Uvalde',
            '465' => 'Val Verde', '467' => 'Van Zandt', '469' => 'Victoria', '471' => 'Walker',
            '473' => 'Waller', '475' => 'Ward', '477' => 'Washington', '479' => 'Webb',
            '481' => 'Wharton', '483' => 'Wheeler', '485' => 'Wichita', '487' => 'Wilbarger',
            '489' => 'Willacy', '491' => 'Williamson', '493' => 'Wilson', '495' => 'Winkler',
            '497' => 'Wise', '499' => 'Wood', '501' => 'Yoakum', '503' => 'Young',
            '505' => 'Zapata', '507' => 'Zavala',
        ];

        if (isset($fips_map[$county_fips])) {
            return $this->normalize_name($fips_map[$county_fips]);
        }

        foreach ($rows as $row) {
            if (!empty($row['county_name_key'])) {
                return $row['county_name_key'];
            }
        }

        return '';
    }

    /**
     * Build a final bundle response.
     */
    private function finalize_bundle(array $row, string $match_type): array
    {
        return [
            'city_name'   => $row['city_name'],
            'city_rate'   => $row['city_rate'],
            'county_name' => $row['county_name'],
            'county_rate' => $row['county_rate'],
            'specials'    => $row['specials'],
            'match_type'  => $match_type,
            'confidence'  => empty($row['specials'])
                ? Tax_Quote_Result::CONFIDENCE_HIGH
                : Tax_Quote_Result::CONFIDENCE_MEDIUM,
        ];
    }

    /**
     * Build a conservative bundle from common city/county components only.
     */
    private function build_common_bundle(array $rows, string $match_type): ?array
    {
        if (empty($rows)) {
            return null;
        }

        $first = $rows[0];
        $same_city_rate = true;
        $same_county_rate = true;

        foreach ($rows as $row) {
            if (abs($row['city_rate'] - $first['city_rate']) > 0.000001) {
                $same_city_rate = false;
            }
            if (abs($row['county_rate'] - $first['county_rate']) > 0.000001) {
                $same_county_rate = false;
            }
        }

        if (!$same_city_rate || !$same_county_rate) {
            return null;
        }

        return [
            'city_name'   => $first['city_name'],
            'city_rate'   => $first['city_rate'],
            'county_name' => $first['county_name'],
            'county_rate' => $first['county_rate'],
            'specials'    => [],
            'match_type'  => $match_type,
            'confidence'  => Tax_Quote_Result::CONFIDENCE_MEDIUM,
        ];
    }

    /**
     * Normalize city/county labels for matching.
     */
    private function normalize_name(string $value): string
    {
        $value = strtoupper($value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = str_replace(['.', ',', '&', '/'], [' ', ' ', ' AND ', ' '], $value);
        $value = preg_replace('/\bCOUNTY\b/', '', $value);
        $value = preg_replace('/\bCO\b/', '', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }
}
