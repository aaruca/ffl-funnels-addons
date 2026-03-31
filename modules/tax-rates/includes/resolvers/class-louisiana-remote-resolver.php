<?php
/**
 * Louisiana Remote Resolver.
 *
 * Resolves Louisiana sales tax using the official Parish E-File lookup.
 * The resolver combines the statewide Louisiana DOR rate with the best
 * matching parish/local rate returned by the official parish lookup.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Louisiana_Remote_Resolver extends Tax_Resolver_Base
{
    const LOOKUP_URL = 'https://parishe-file.revenue.louisiana.gov/lookup/lookup.aspx';
    const STATE_RETURN_CODE = '19';
    const CACHE_TTL = 21600; // 6 hours.
    const REQUEST_TIMEOUT = 20;

    public function get_id(): string
    {
        return 'la_remote';
    }

    public function get_name(): string
    {
        return 'Louisiana Parish E-File Lookup';
    }

    public function get_source_code(): string
    {
        return 'la_parish_efile';
    }

    public function get_supported_states(): array
    {
        return ['LA'];
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $result                   = new Tax_Quote_Result();
        $result->inputAddress     = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress   = $geocode['matchedAddress'] ?? null;
        $result->state            = 'LA';
        $result->coverageStatus   = Tax_Coverage::SUPPORTED_WITH_REMOTE;
        $result->resolutionMode   = 'remote_lookup';
        $result->source           = $this->get_source_code();
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);

        $parish_name = $this->detect_parish_name($normalized, $geocode);
        if ($parish_name === null) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
                'Louisiana parish could not be determined from the geocoded address.'
            );
            return $result;
        }

        $lookup_form = $this->fetch_lookup_form();
        if (is_wp_error($lookup_form)) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                'Louisiana official lookup is currently unavailable: ' . $lookup_form->get_error_message()
            );
            return $result;
        }

        $parish_option = $this->resolve_parish_option($parish_name, $lookup_form['returns']);
        if ($parish_option === null) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
                "No official Louisiana return mapping was found for parish '{$parish_name}'."
            );
            return $result;
        }

        $state_lookup = $this->fetch_return_rates($lookup_form, self::STATE_RETURN_CODE);
        if (is_wp_error($state_lookup)) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                'Louisiana state rate lookup failed: ' . $state_lookup->get_error_message()
            );
            return $result;
        }

        $parish_lookup = $this->fetch_return_rates($lookup_form, $parish_option['code']);
        if (is_wp_error($parish_lookup)) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_SOURCE_UNAVAILABLE,
                'Louisiana parish rate lookup failed: ' . $parish_lookup->get_error_message()
            );
            return $result;
        }

        $state_row = $this->extract_state_row($state_lookup['rows']);
        if ($state_row === null) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
                'Louisiana state rate was not present in the official lookup response.'
            );
            return $result;
        }

        $local_row = $this->select_local_row(
            $parish_lookup['rows'],
            $normalized['city'] ?? '',
            $parish_name
        );

        if ($local_row === null) {
            $result->set_error(
                Tax_Quote_Result::OUTCOME_RATE_NOT_DETERMINABLE,
                "Louisiana local rate could not be selected for parish '{$parish_name}'."
            );
            return $result;
        }

        $result->sourceVersion  = 'period-' . ($parish_lookup['period_value'] ?: $state_lookup['period_value']);
        $result->effectiveDate  = $parish_lookup['effective_date'] ?: $state_lookup['effective_date'];
        $result->confidence     = $local_row['match_confidence'];
        $result->limitations[]  = 'Louisiana local rate selected from the official Parish E-File return for the matched parish.';
        $result->limitations[]  = 'Special district applicability can vary within a parish and may require manual review for edge cases.';
        $result->trace['parish'] = $parish_name;
        $result->trace['officialReturn'] = $parish_option['label'];
        $result->trace['officialReturnCode'] = $parish_option['code'];

        $result->add_breakdown('state', 'Louisiana State', $state_row['rate']);
        $result->add_breakdown($local_row['type'], $local_row['jurisdiction'], $local_row['rate']);
        $result->calculate_total();

        if ($local_row['match_confidence'] === Tax_Quote_Result::CONFIDENCE_HIGH) {
            $result->determinationScope = 'address_rate_only';
        } else {
            $result->determinationScope = 'parish_rate_only';
            $result->limitations[] = 'Returned local rate is the best parish-level match from the official source.';
        }

        return $result;
    }

    /**
     * Fetch the lookup page and parse hidden form fields and return options.
     *
     * @return array|WP_Error
     */
    private function fetch_lookup_form()
    {
        $cache_key = 'ffla_tax_la_lookup_form';
        $cached = get_transient($cache_key);
        if (is_array($cached) && !empty($cached['returns']) && !empty($cached['html'])) {
            return $cached;
        }

        $response = wp_remote_get(self::LOOKUP_URL, [
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => ['Accept' => 'text/html'],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('la_lookup_http', 'Official Louisiana lookup returned HTTP ' . wp_remote_retrieve_response_code($response) . '.');
        }

        $html = wp_remote_retrieve_body($response);
        if (!is_string($html) || $html === '') {
            return new WP_Error('la_lookup_empty', 'Official Louisiana lookup returned an empty page.');
        }

        $parsed = [
            'html'    => $html,
            'hidden'  => $this->extract_hidden_fields($html),
            'returns' => $this->extract_return_options($html),
        ];

        if (empty($parsed['hidden']['__VIEWSTATE']) || empty($parsed['returns'])) {
            return new WP_Error('la_lookup_parse', 'Official Louisiana lookup page could not be parsed.');
        }

        set_transient($cache_key, $parsed, self::CACHE_TTL);

        return $parsed;
    }

    /**
     * Fetch rates for a specific official return code.
     *
     * @param  array  $lookup_form Parsed form payload from fetch_lookup_form().
     * @param  string $return_code Official return code to query.
     * @return array|WP_Error
     */
    private function fetch_return_rates(array $lookup_form, string $return_code)
    {
        $cache_key = 'ffla_tax_la_return_' . md5($return_code);
        $cached = get_transient($cache_key);
        if (is_array($cached) && isset($cached['rows'])) {
            return $cached;
        }

        $body = array_merge($lookup_form['hidden'], [
            '__EVENTTARGET'                 => 'ctl00$main$ReturnsDropDownList',
            '__EVENTARGUMENT'               => '',
            '__LASTFOCUS'                   => '',
            'ctl00$main$ReturnsDropDownList' => $return_code,
            'ctl00$main$SelectedReturn'     => $return_code,
        ]);

        $response = wp_remote_post(self::LOOKUP_URL, [
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => [
                'Accept'       => 'text/html',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('la_lookup_post_http', 'Official Louisiana lookup returned HTTP ' . wp_remote_retrieve_response_code($response) . '.');
        }

        $html = wp_remote_retrieve_body($response);
        if (!is_string($html) || $html === '') {
            return new WP_Error('la_lookup_post_empty', 'Official Louisiana lookup returned an empty rate page.');
        }

        $period = $this->extract_selected_period($html);
        $payload = [
            'period_value'   => $period['value'],
            'effective_date' => $period['effective_date'],
            'rows'           => $this->extract_rate_rows($html),
        ];

        if (empty($payload['rows'])) {
            return new WP_Error('la_lookup_no_rows', 'No rate rows were returned by the official Louisiana lookup.');
        }

        set_transient($cache_key, $payload, HOUR_IN_SECONDS);

        return $payload;
    }

    /**
     * Extract hidden fields needed by the ASP.NET form.
     */
    private function extract_hidden_fields(string $html): array
    {
        $fields = [];
        preg_match_all('/<input[^>]+type="hidden"[^>]+name="([^"]+)"[^>]+value="([^"]*)"/i', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $fields[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
        }

        return $fields;
    }

    /**
     * Extract all return options from the dropdown.
     */
    private function extract_return_options(string $html): array
    {
        $options = [];
        if (!preg_match('/<select[^>]+id="main_ReturnsDropDownList"[^>]*>(.*?)<\/select>/is', $html, $select_match)) {
            return $options;
        }

        preg_match_all('/<option[^>]+value="([^"]*)"[^>]*>(.*?)<\/option>/is', $select_match[1], $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $code = trim($match[1]);
            $label = $this->clean_text($match[2]);
            if ($code === '' || $code === '-99' || $label === '') {
                continue;
            }

            $options[] = [
                'code'   => $code,
                'label'  => $label,
                'parish' => $this->extract_parish_key_from_label($label),
            ];
        }

        return $options;
    }

    /**
     * Extract the selected filing period and convert it to an effective date.
     */
    private function extract_selected_period(string $html): array
    {
        $period = [
            'value'          => null,
            'effective_date' => null,
        ];

        if (
            !preg_match(
                '/<select[^>]+id="main_PeriodDropDownList"[^>]*>(.*?)<\/select>/is',
                $html,
                $select_match
            )
        ) {
            return $period;
        }

        if (!preg_match('/<option[^>]+selected="selected"[^>]+value="([^"]+)"[^>]*>/i', $select_match[1], $match)) {
            return $period;
        }

        $period['value'] = trim($match[1]);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $period['value'], $date_match)) {
            $period['effective_date'] = sprintf(
                '%04d-%02d-%02d',
                (int) $date_match[1],
                (int) $date_match[2],
                (int) $date_match[3]
            );
        }

        return $period;
    }

    /**
     * Extract normalized rate rows from the official rate table.
     */
    private function extract_rate_rows(string $html): array
    {
        $rows = [];

        if (!preg_match('/<table[^>]+id="main_RateInformation"[^>]*>(.*?)<\/table>/is', $html, $table_match)) {
            return $rows;
        }

        preg_match_all('/<tr[^>]*class="text_small"[^>]*>(.*?)<\/tr>/is', $table_match[1], $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $match[1], $cells);
            if (count($cells[1]) < 3) {
                continue;
            }

            $description = $this->clean_text($cells[1][1]);
            $rate = $this->parse_percent($cells[1][2]);

            if ($description === '' || $rate === null) {
                continue;
            }

            $rows[] = [
                'description' => $description,
                'rate'        => $rate,
            ];
        }

        return $rows;
    }

    /**
     * Determine the best official return option for a parish.
     */
    private function resolve_parish_option(string $parish_name, array $options): ?array
    {
        $normalized_parish = $this->normalize_place_name($parish_name);

        $special_map = [
            'JEFFERSON'  => 'JEFFERSON GENERAL SALES',
            'ST TAMMANY' => 'ST TAMMANY SALES AND DELIVERIES',
        ];

        if (isset($special_map[$normalized_parish])) {
            $target = $special_map[$normalized_parish];
            foreach ($options as $option) {
                if ($this->normalize_place_name($option['label']) === $target) {
                    return $option;
                }
            }
        }

        foreach ($options as $option) {
            if ($option['parish'] === $normalized_parish) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Extract a general parish key from an official return label.
     */
    private function extract_parish_key_from_label(string $label): ?string
    {
        $normalized = $this->normalize_place_name($label);
        $raw = strtoupper(html_entity_decode($label, ENT_QUOTES, 'UTF-8'));
        $raw = str_replace(['.', ',', '&'], [' ', ' ', ' AND '], $raw);
        $raw = preg_replace('/\s+/', ' ', $raw);
        $raw = trim($raw);

        $blocked_terms = [
            'HOTEL', 'MOTEL', 'OCCUPANCY', 'WIRELESS', 'AIRPORT',
            'COASTAL', 'ANNEX', 'STATEWIDE', 'PREPAID', 'HOTELS',
            'STR', 'FOOD AND DRUG', 'VENDING MACHINES',
        ];

        foreach ($blocked_terms as $term) {
            if (strpos($normalized, $term) !== false) {
                return null;
            }
        }

        if ($normalized === 'LA DEPT OF REVENUE SALES AND USE TAX') {
            return 'STATE';
        }

        if ($normalized === 'JEFFERSON GENERAL SALES') {
            return 'JEFFERSON';
        }

        if ($normalized === 'ST TAMMANY SALES AND DELIVERIES') {
            return 'ST TAMMANY';
        }

        if (preg_match('/^(.+?) PARISH$/', $raw, $match)) {
            return $this->normalize_place_name($match[1]);
        }

        return null;
    }

    /**
     * Detect parish from geocoder output.
     */
    private function detect_parish_name(array $normalized, array $geocode): ?string
    {
        $county = $geocode['countyName'] ?? '';
        if ($county !== '') {
            return $county;
        }

        if (!empty($geocode['raw']['geographies']['Counties'][0]['NAME'])) {
            return $geocode['raw']['geographies']['Counties'][0]['NAME'];
        }

        return null;
    }

    /**
     * Extract the statewide Louisiana row.
     */
    private function extract_state_row(array $rows): ?array
    {
        foreach ($rows as $row) {
            if ($this->normalize_place_name($row['description']) === 'LOUISIANA STATE') {
                return [
                    'rate' => $row['rate'],
                ];
            }
        }

        return null;
    }

    /**
     * Select the best local row for the requested city and parish.
     */
    private function select_local_row(array $rows, string $city, string $parish_name): ?array
    {
        $city = $this->normalize_place_name($city);
        $parish = $this->normalize_place_name($parish_name);
        $best = null;

        foreach ($rows as $row) {
            $desc = $this->normalize_place_name($row['description']);
            $raw_desc = $this->normalize_raw_text($row['description']);
            $score = 0;
            $type = 'county';
            $confidence = Tax_Quote_Result::CONFIDENCE_MEDIUM;

            if ($city !== '' && preg_match('/\bCITY OF ' . preg_quote($city, '/') . '\b/', $desc)) {
                $score += 140;
                $type = 'city';
                $confidence = Tax_Quote_Result::CONFIDENCE_HIGH;
            } elseif ($city !== '' && preg_match('/\b' . preg_quote($city, '/') . '\b/', $desc)) {
                $score += 120;
                $type = 'city';
                $confidence = Tax_Quote_Result::CONFIDENCE_HIGH;
            }

            if ($parish !== '' && preg_match('/\b' . preg_quote($parish, '/') . '\b/', $desc)) {
                $score += 35;
            }

            if (strpos($raw_desc, 'PARISH OF') !== false || strpos($raw_desc, 'SCHOOL DISTRICT') !== false) {
                $score += 10;
            }

            if ($city === '' && $score < 50 && strpos($raw_desc, 'PARISH OF') !== false) {
                $score += 80;
            }

            if ($score === 0 && $best === null) {
                $score = 5;
            }

            if ($best === null || $score > $best['score']) {
                $best = [
                    'score'            => $score,
                    'rate'             => $row['rate'],
                    'jurisdiction'     => $row['description'],
                    'type'             => $type,
                    'match_confidence' => $confidence,
                ];
            }
        }

        if ($best === null || $best['score'] <= 0) {
            return null;
        }

        return $best;
    }

    /**
     * Normalize a location string for matching.
     */
    private function normalize_place_name(string $value): string
    {
        $value = $this->normalize_raw_text($value);
        $value = preg_replace('/\bPARISH\b/', '', $value);
        $value = preg_replace('/\bCOUNTY\b/', '', $value);
        $value = preg_replace('/\bSAINT\b/', 'ST', $value);
        $value = preg_replace('/\bST\b/', 'ST', $value);
        $value = preg_replace('/\bE\.B\.R\b/', 'E BR', $value);
        $value = preg_replace('/\bE B R\b/', 'E BR', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    /**
     * Normalize raw text without removing jurisdiction keywords.
     */
    private function normalize_raw_text(string $value): string
    {
        $value = strtoupper($value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = str_replace(['.', ',', '&'], [' ', ' ', ' AND '], $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    /**
     * Clean text extracted from HTML.
     */
    private function clean_text(string $html): string
    {
        $text = wp_strip_all_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Parse a percent value into decimal.
     */
    private function parse_percent(string $value): ?float
    {
        $value = $this->clean_text($value);
        $value = str_replace('%', '', $value);
        if (!is_numeric($value)) {
            return null;
        }

        return round(((float) $value) / 100, 6);
    }
}
