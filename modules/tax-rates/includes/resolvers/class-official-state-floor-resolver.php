<?php
/**
 * Official State Floor Resolver.
 *
 * Provides a conservative statewide base rate for states where local or
 * district taxes still require a richer official address-specific integration.
 * This keeps national coverage functional while clearly signaling that the
 * determination is a state-rate floor rather than a final local-inclusive rate.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Official_State_Floor_Resolver extends Tax_Resolver_Base
{
    const HANDBOOK_BASE_URL = 'https://www.salestaxhandbook.com';
    const HANDBOOK_SOURCE_CODE = 'salestaxhandbook_fallback';
    const HANDBOOK_CACHE_TTL = 30 * DAY_IN_SECONDS;

    /**
     * SalesTaxHandbook state slug map for states using the official floor resolver.
     *
     * @var array<string,string>
     */
    const HANDBOOK_STATE_SLUGS = [
        'AL' => 'alabama',
        'AZ' => 'arizona',
        'CA' => 'california',
        'CO' => 'colorado',
        'FL' => 'florida',
        'ID' => 'idaho',
        'IL' => 'illinois',
        'MO' => 'missouri',
        'NM' => 'new-mexico',
        'NY' => 'new-york',
        'SC' => 'south-carolina',
    ];

    /**
     * @var array<string,array<string,mixed>>
     */
    const STATES = [
        'AL' => [
            'rate'        => 0.0400,
            'source_code' => 'al_revenue_floor',
            'source_url'  => 'https://www.revenue.alabama.gov/sales-use/state-sales-use-tax-rates/',
            'jurisdiction'=> 'Alabama State Floor',
            'note'        => 'Alabama state general sales tax rate is 4%; local sales taxes may apply by destination.',
        ],
        'AZ' => [
            'rate'        => 0.0560,
            'source_code' => 'az_dor_floor',
            'source_url'  => 'https://azdor.gov/individuals/income-tax-filing-assistance/understanding-use-tax',
            'jurisdiction'=> 'Arizona State Floor',
            'note'        => 'Arizona state transaction privilege/use tax rate is 5.6%; county and city taxes vary by location.',
        ],
        'CA' => [
            'rate'        => 0.0725,
            'source_code' => 'ca_cdtfa_floor',
            'source_url'  => 'https://www.cdtfa.ca.gov/taxes-and-fees/know-your-rate.htm',
            'jurisdiction'=> 'California State Floor',
            'note'        => 'California statewide base sales tax rate is 7.25%; district taxes vary by city/county/address.',
        ],
        'CO' => [
            'rate'        => 0.0290,
            'source_code' => 'co_dor_floor',
            'source_url'  => 'https://tax.colorado.gov/sales-tax-rate-changes',
            'jurisdiction'=> 'Colorado State Floor',
            'note'        => 'Colorado state sales tax rate is 2.9%; county, city, and district taxes vary by destination.',
        ],
        'FL' => [
            'rate'        => 0.0600,
            'source_code' => 'fl_dor_floor',
            'source_url'  => 'https://floridarevenue.com/taxes/taxesfees/Pages/sales_tax.aspx',
            'jurisdiction'=> 'Florida State Floor',
            'note'        => 'Florida general state sales tax rate is 6%; county discretionary surtax can apply.',
        ],
        'ID' => [
            'rate'        => 0.0600,
            'source_code' => 'id_stc_floor',
            'source_url'  => 'https://tax.idaho.gov/taxes/sales-use/online-guide/',
            'jurisdiction'=> 'Idaho State Floor',
            'note'        => 'Idaho sales tax rate is 6%; certain local resort taxes are not yet modeled here.',
        ],
        'IL' => [
            'rate'        => 0.0625,
            'source_code' => 'il_dor_floor',
            'source_url'  => 'https://tax.illinois.gov/questionsandanswers/answer.139.html',
            'jurisdiction'=> 'Illinois State Floor',
            'note'        => 'Illinois state general merchandise rate is 6.25%; local occupation taxes vary by destination.',
        ],
        'MO' => [
            'rate'        => 0.04225,
            'source_code' => 'mo_dor_floor',
            'source_url'  => 'https://dor.mo.gov/taxation/business/tax-types/sales-use/',
            'jurisdiction'=> 'Missouri State Floor',
            'note'        => 'Missouri state sales tax rate is 4.225%; county, city, and district taxes vary by location.',
        ],
        'NM' => [
            'rate'        => 0.04875,
            'source_code' => 'nm_trd_floor',
            'source_url'  => 'https://www.tax.newmexico.gov/governments/municipal-county-governments/local-option-taxes/',
            'jurisdiction'=> 'New Mexico State Floor',
            'note'        => 'New Mexico base state gross receipts tax rate is 4.875%; county and municipal gross receipts taxes vary by location.',
        ],
        'NY' => [
            'rate'        => 0.0400,
            'source_code' => 'ny_dtf_floor',
            'source_url'  => 'https://www.tax.ny.gov/bus/st/rates.htm',
            'jurisdiction'=> 'New York State Floor',
            'note'        => 'New York state sales tax rate is 4%; local taxes and the MCTD surcharge can apply by destination.',
        ],
        'SC' => [
            'rate'        => 0.0600,
            'source_code' => 'sc_dor_floor',
            'source_url'  => 'https://www.dor.sc.gov/sales-use-tax-index/sales-tax',
            'jurisdiction'=> 'South Carolina State Floor',
            'note'        => 'South Carolina statewide sales tax rate is 6%; county and municipal local taxes may apply.',
        ],
    ];

    public function get_id(): string
    {
        return 'official_state_floor';
    }

    public function get_name(): string
    {
        return 'Official State Floor Resolver';
    }

    public function get_source_code(): string
    {
        return 'official_state_floor';
    }

    public function get_supported_states(): array
    {
        return array_keys(self::STATES);
    }

    public function resolve(array $normalized, array $geocode): Tax_Quote_Result
    {
        $state_code = strtoupper((string) ($normalized['state'] ?? ''));
        $config = self::STATES[$state_code] ?? null;

        if (!$config) {
            return Tax_Quote_Result::unsupported($state_code, $normalized, $normalized);
        }

        $handbook_result = $this->resolve_handbook_fallback($normalized, $geocode, $config);
        if ($handbook_result instanceof Tax_Quote_Result) {
            return $handbook_result;
        }

        $result                    = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress    = $geocode['matchedAddress'] ?? null;
        $result->state             = $state_code;
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
        $result->determinationScope = 'state_rate_only';
        $result->resolutionMode    = 'official_state_floor';
        $result->source            = $config['source_code'];
        $result->sourceVersion     = 'current-law';
        $result->confidence        = Tax_Quote_Result::CONFIDENCE_MEDIUM;
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);
        $result->trace['sourceUrl'] = $config['source_url'];
        $result->limitations[] = $config['note'];
        $result->limitations[] = 'This result is a conservative statewide floor. Additional county, city, district, or regional taxes may apply and are not yet fully modeled for this state.';
        $result->add_breakdown('state', $config['jurisdiction'], (float) $config['rate']);
        $result->calculate_total();

        return $result;
    }

    /**
     * Attempt a city-level fallback using SalesTaxHandbook county pages.
     */
    private function resolve_handbook_fallback(array $normalized, array $geocode, array $config): ?Tax_Quote_Result
    {
        $state_code = strtoupper((string) ($normalized['state'] ?? ''));
        $state_slug = self::HANDBOOK_STATE_SLUGS[$state_code] ?? null;
        $city_key   = $this->normalize_place_key((string) ($normalized['city'] ?? ''));
        $county_name = (string) ($geocode['countyName'] ?? '');
        $county_key = $this->normalize_county_key($county_name);
        $county_slug = $this->normalize_county_slug($county_name);

        if ($state_slug === null || $city_key === '' || $county_key === '' || $county_slug === '') {
            return null;
        }

        $county_page = $this->fetch_handbook_county_page($state_slug, $county_slug);
        if (!$county_page || empty($county_page['html'])) {
            return null;
        }

        $city_rates = $this->parse_handbook_city_rates($county_page['html'], $county_key);
        if (empty($city_rates[$city_key])) {
            return null;
        }

        $match = $city_rates[$city_key];

        $result                    = new Tax_Quote_Result();
        $result->inputAddress      = $normalized;
        $result->normalizedAddress = $normalized;
        $result->matchedAddress    = $geocode['matchedAddress'] ?? null;
        $result->state             = $state_code;
        $result->coverageStatus    = Tax_Coverage::SUPPORTED_CONTEXT_REQUIRED;
        $result->determinationScope = 'city_rate_only';
        $result->resolutionMode    = 'handbook_city_fallback';
        $result->source            = self::HANDBOOK_SOURCE_CODE;
        $result->sourceVersion     = $county_page['updated'] ?: 'monthly-cache';
        $result->confidence        = $match['confidence'];
        $result->trace['resolver'] = $this->get_id();
        $result->trace['geocodeUsed'] = !empty($geocode['success']);
        $result->trace['sourceUrl'] = $county_page['url'];
        $result->trace['countyKey'] = $county_key;
        $result->trace['countySlug'] = $county_slug;
        $result->trace['fallbackSource'] = 'SalesTaxHandbook';
        $result->limitations[] = $config['note'];
        $result->limitations[] = 'Local city fallback came from SalesTaxHandbook because an official address-specific local dataset is not yet integrated for this state.';
        $result->limitations[] = 'SalesTaxHandbook is refreshed on a 30-day cache cycle and should be treated as a secondary source for edge cases.';
        $result->add_breakdown('city', $match['label'], $match['rate']);
        $result->calculate_total();

        return $result;
    }

    /**
     * Fetch a SalesTaxHandbook county page with a 30-day cache.
     *
     * @return array<string,string>|null
     */
    private function fetch_handbook_county_page(string $state_slug, string $county_key): ?array
    {
        $transient_key = 'ffla_tax_sth_' . md5($state_slug . '|' . $county_key);
        $cached = get_transient($transient_key);

        if (is_array($cached) && !empty($cached['html'])) {
            return $cached;
        }

        $url = trailingslashit(self::HANDBOOK_BASE_URL . '/' . $state_slug . '/rates') . $county_key . '-county';
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'text/html',
                'User-Agent' => 'FFL Funnels Tax Resolver/' . (defined('FFLA_VERSION') ? FFLA_VERSION : 'dev'),
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $html = wp_remote_retrieve_body($response);
        if (!is_string($html) || $html === '') {
            return null;
        }

        $payload = [
            'url'     => $url,
            'updated' => $this->extract_handbook_updated_label($html),
            'html'    => $html,
        ];

        set_transient($transient_key, $payload, self::HANDBOOK_CACHE_TTL);

        return $payload;
    }

    /**
     * Parse city total-rate rows from a SalesTaxHandbook county page.
     *
     * @return array<string,array<string,mixed>>
     */
    private function parse_handbook_city_rates(string $html, string $county_key): array
    {
        if (!preg_match('/<table class="table table-striped table-scrollable table-responsive">(.*?)<\/table>/is', $html, $table_match)) {
            return [];
        }

        if (!preg_match('/<tbody[^>]*>(.*?)<\/tbody>/is', $table_match[1], $body_match)) {
            return [];
        }

        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $body_match[1], $row_matches);

        $selected = [];

        foreach ($row_matches[1] as $row_html) {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/is', $row_html, $cell_matches);
            if (count($cell_matches[1]) < 3) {
                continue;
            }

            $city_label = $this->clean_handbook_text($cell_matches[1][0]);
            $rate       = $this->parse_handbook_percent($cell_matches[1][1]);
            $jurisdiction_label = $this->clean_handbook_text($cell_matches[1][2]);

            $city_key = $this->normalize_place_key($city_label);
            $jurisdiction_key = $this->normalize_place_key($jurisdiction_label);

            if ($city_key === '' || $rate <= 0 || $jurisdiction_key === '') {
                continue;
            }

            $priority = 0;
            $confidence = Tax_Quote_Result::CONFIDENCE_MEDIUM;

            if ($jurisdiction_key === $city_key) {
                $priority = 3;
                $confidence = Tax_Quote_Result::CONFIDENCE_HIGH;
            } elseif ($jurisdiction_key === $county_key) {
                $priority = 2;
            } elseif (strpos($jurisdiction_key, $county_key) !== false) {
                $priority = 1;
            } else {
                continue;
            }

            $candidate = [
                'rate'       => $rate,
                'label'      => $city_label . ' Total',
                'city'       => $city_label,
                'jurisdiction' => $jurisdiction_label,
                'confidence' => $confidence,
                'priority'   => $priority,
            ];

            if (!isset($selected[$city_key])
                || $candidate['priority'] > $selected[$city_key]['priority']
                || (
                    $candidate['priority'] === $selected[$city_key]['priority']
                    && $candidate['rate'] > $selected[$city_key]['rate']
                )
            ) {
                $selected[$city_key] = $candidate;
            }
        }

        return $selected;
    }

    /**
     * Extract the visible "Last updated ..." label from a handbook page.
     */
    private function extract_handbook_updated_label(string $html): string
    {
        if (preg_match('/Last updated\s+([^<]+)</i', $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        }

        if (preg_match('/current as of\s+([^<]+)</i', $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        }

        return '';
    }

    /**
     * Normalize a city/jurisdiction label for matching.
     */
    private function normalize_place_key(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = strtoupper(trim(wp_strip_all_tags($value)));
        $value = str_replace(['.', "'", '&'], ['', '', ' AND '], $value);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }

    /**
     * Normalize a county label for handbook matching.
     */
    private function normalize_county_key(string $value): string
    {
        $value = $this->normalize_place_key($value);
        $value = preg_replace('/\s+(COUNTY|PARISH|BOROUGH|CENSUS AREA|MUNICIPALITY|CITY AND BOROUGH)$/', '', (string) $value);

        return trim((string) $value);
    }

    /**
     * Normalize a county label to the handbook URL slug shape.
     */
    private function normalize_county_slug(string $value): string
    {
        $value = strtolower(str_replace(' ', '-', $this->normalize_county_key($value)));

        return trim((string) $value, '-');
    }

    /**
     * Parse a percentage string like "7.50%" to decimal form.
     */
    private function parse_handbook_percent(string $value): float
    {
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/[^0-9.]+/', '', $value);

        if ($value === '' || !is_numeric($value)) {
            return 0.0;
        }

        return ((float) $value) / 100;
    }

    /**
     * Strip markup and normalize handbook table text.
     */
    private function clean_handbook_text(string $value): string
    {
        $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8');
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }
}
