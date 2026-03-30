<?php
/**
 * Tax Rates Importer.
 *
 * Researches current US state/county sales tax rates via Tavily + OpenAI
 * and inserts them into WooCommerce's native tax rate tables.
 *
 * Rates are prefixed with "FFLA_" so they can be cleanly replaced on
 * each import without touching manually-created WC rates.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Rates_Importer
{
    /**
     * Register AJAX hook (admin-only).
     */
    public static function init(): void
    {
        add_action('wp_ajax_ffla_import_tax_state', [__CLASS__, 'ajax_import_state']);
    }

    /* ── AJAX Handler ──────────────────────────────────────────────── */

    /**
     * Import tax rates for a single US state.
     * Called once per state by the JS loop in the admin UI.
     */
    public static function ajax_import_state(): void
    {
        check_ajax_referer('ffla_tax_rates_nonce', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied.');
        }

        $state_code = strtoupper(sanitize_text_field(wp_unslash($_POST['state'] ?? '')));
        $depth      = sanitize_text_field(wp_unslash($_POST['depth'] ?? 'county'));

        if (empty($state_code) || !array_key_exists($state_code, self::get_us_states())) {
            wp_send_json_error('Invalid state code.');
        }

        $result = self::import_state($state_code, $depth);

        if (is_wp_error($result)) {
            // Save error log.
            update_option('ffla_tax_import_' . $state_code, [
                'imported_at' => current_time('mysql'),
                'count'       => 0,
                'status'      => 'error',
                'error'       => $result->get_error_message(),
            ], false);
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success([
            'state' => $state_code,
            'count' => $result,
        ]);
    }

    /* ── Core Import Logic ─────────────────────────────────────────── */

    /**
     * Research and import tax rates for one state.
     *
     * @param string $state_code Two-letter state code (e.g. 'CA').
     * @param string $depth      'state' or 'county'.
     * @return int|WP_Error Number of rates inserted, or WP_Error on failure.
     */
    public static function import_state(string $state_code, string $depth = 'county')
    {
        $states     = self::get_us_states();
        $state_name = $states[$state_code] ?? $state_code;

        $wb_settings = get_option('woobooster_settings', []);
        $openai_key  = trim($wb_settings['openai_key'] ?? '');
        $tavily_key  = trim($wb_settings['tavily_key'] ?? '');

        if (empty($openai_key)) {
            return new WP_Error('no_openai', 'OpenAI API key is not configured in WooBooster settings.');
        }

        // Step 1: Research current rates via Tavily.
        $search_content = self::search_tax_rates($state_name, $state_code, $depth, $tavily_key);

        // Step 2: Structure data with OpenAI.
        $rates = self::parse_rates_with_ai($state_name, $state_code, $depth, $search_content, $openai_key);

        if (is_wp_error($rates)) {
            return $rates;
        }

        if (empty($rates)) {
            return new WP_Error('no_rates', "No tax rates could be extracted for {$state_name}.");
        }

        // Step 3: Delete previous FFLA rates for this state.
        self::delete_ffla_rates($state_code);

        // Step 4: Insert new rates into WooCommerce.
        $count = self::insert_wc_rates($state_code, $rates);

        // Step 5: Save log.
        update_option('ffla_tax_import_' . $state_code, [
            'imported_at' => current_time('mysql'),
            'count'       => $count,
            'status'      => 'ok',
        ], false);

        return $count;
    }

    /* ── Tavily Search ─────────────────────────────────────────────── */

    /**
     * Search for current tax rate data using Tavily.
     * Returns raw search content (answer + snippets).
     */
    private static function search_tax_rates(string $state_name, string $state_code, string $depth, string $tavily_key): string
    {
        $year  = wp_date('Y');
        $month = wp_date('F');

        if ($depth === 'county') {
            $query = "{$state_name} sales tax rates by county {$month} {$year} current official";
        } else {
            $query = "{$state_name} state sales tax rate {$month} {$year} current official";
        }

        // No Tavily key — OpenAI will use its training data (still useful for stable state rates).
        if (empty($tavily_key)) {
            return "No web search available. Use training knowledge for {$state_name} ({$state_code}) sales tax rates as of {$month} {$year}.";
        }

        $response = wp_remote_post('https://api.tavily.com/search', [
            'body' => wp_json_encode([
                'api_key'        => $tavily_key,
                'query'          => $query,
                'search_depth'   => 'advanced',
                'include_answer' => true,
                'max_results'    => 8,
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return "Web search failed. Use training knowledge for {$state_name} ({$state_code}) sales tax rates as of {$month} {$year}.";
        }

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $parts  = [];

        if (!empty($body['answer'])) {
            $parts[] = $body['answer'];
        }

        if (!empty($body['results'])) {
            foreach (array_slice($body['results'], 0, 5) as $r) {
                if (!empty($r['content'])) {
                    $parts[] = $r['content'];
                }
            }
        }

        return implode("\n\n---\n\n", $parts) ?: "No results. Use training knowledge for {$state_name} tax rates as of {$month} {$year}.";
    }

    /* ── OpenAI Parsing ────────────────────────────────────────────── */

    /**
     * Use OpenAI to extract structured tax rate data from search results.
     *
     * @return array[]|WP_Error Array of rate entries or WP_Error.
     */
    private static function parse_rates_with_ai(string $state_name, string $state_code, string $depth, string $search_content, string $openai_key)
    {
        $year  = date('Y');
        $month = date('F');

        if ($depth === 'county') {
            $instructions = "Extract ALL county-level combined sales tax rates (state + county) for {$state_name} ({$state_code}).";
            $format       = 'Each entry: {"county": "string", "city": null, "rate": 8.25, "zip_patterns": ["123*"]}. The rate field must be a float (percentage, e.g. 8.25 not 0.0825). zip_patterns is an array of WooCommerce postcode wildcards if you know them, otherwise an empty array.';
        } else {
            $instructions = "Extract the single statewide sales tax rate for {$state_name} ({$state_code}).";
            $format       = 'Return a single entry: [{"county": "Statewide", "city": null, "rate": 6.0, "zip_patterns": []}].';
        }

        $system = "You are a US tax data expert. Today is {$month} {$year}. Extract structured sales tax data from the provided research content. Return ONLY a valid JSON array, no markdown, no explanation.";

        $user = "{$instructions}\n\nFormat: {$format}\n\nResearch content:\n\n{$search_content}";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'body' => wp_json_encode([
                'model'       => 'gpt-4o-mini',
                'messages'    => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
                'temperature' => 0,
            ]),
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $openai_key,
            ],
            'timeout' => 45,
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('openai_error', 'OpenAI request failed: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data['error'])) {
            return new WP_Error('openai_api_error', $data['error']['message'] ?? 'OpenAI API error.');
        }

        $content = $data['choices'][0]['message']['content'] ?? '';

        // Strip markdown code fences if present.
        $content = preg_replace('/^```(?:json)?\s*/i', '', trim($content));
        $content = preg_replace('/\s*```$/', '', $content);

        $rates = json_decode(trim($content), true);

        if (!is_array($rates)) {
            return new WP_Error('parse_error', 'Could not parse AI response as JSON.');
        }

        // Validate and sanitize each entry.
        $clean = [];
        foreach ($rates as $r) {
            if (!isset($r['rate']) || !is_numeric($r['rate'])) {
                continue;
            }
            $rate_val = floatval($r['rate']);
            // Sanity check: rates above 20% are almost certainly wrong (stored as decimal 0.xx).
            if ($rate_val < 1 && $rate_val > 0) {
                $rate_val = round($rate_val * 100, 4);
            }
            if ($rate_val <= 0 || $rate_val > 20) {
                continue;
            }
            $clean[] = [
                'county'       => sanitize_text_field($r['county'] ?? 'Statewide'),
                'city'         => isset($r['city']) && $r['city'] !== null ? sanitize_text_field($r['city']) : null,
                'rate'         => $rate_val,
                'zip_patterns' => array_map('sanitize_text_field', (array) ($r['zip_patterns'] ?? [])),
            ];
        }

        return $clean;
    }

    /* ── WooCommerce Rate Operations ───────────────────────────────── */

    /**
     * Delete all FFLA-prefixed tax rates for a given state.
     */
    private static function delete_ffla_rates(string $state_code): void
    {
        global $wpdb;

        $rate_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
             WHERE tax_rate_country = 'US'
               AND tax_rate_state   = %s
               AND tax_rate_name LIKE 'FFLA\_%'",
            $state_code
        ));

        foreach ($rate_ids as $rate_id) {
            WC_Tax::_delete_tax_rate(absint($rate_id));
        }
    }

    /**
     * Insert parsed rates into WooCommerce tax tables.
     *
     * @param  string  $state_code Two-letter state code.
     * @param  array[] $rates      Parsed rate entries.
     * @return int     Number of rates inserted.
     */
    private static function insert_wc_rates(string $state_code, array $rates): int
    {
        $count = 0;

        foreach ($rates as $order => $rate) {
            $rate_id = WC_Tax::_insert_tax_rate([
                'tax_rate_country'  => 'US',
                'tax_rate_state'    => $state_code,
                'tax_rate'          => number_format($rate['rate'], 4, '.', ''),
                'tax_rate_name'     => 'FFLA_' . $rate['county'],
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 1,
                'tax_rate_order'    => $order,
                'tax_rate_class'    => '',
            ]);

            if ($rate_id && !empty($rate['zip_patterns'])) {
                WC_Tax::_update_tax_rate_postcodes($rate_id, implode(';', $rate['zip_patterns']));
            }

            if ($rate_id && !empty($rate['city'])) {
                WC_Tax::_update_tax_rate_cities($rate_id, $rate['city']);
            }

            if ($rate_id) {
                $count++;
            }
        }

        return $count;
    }

    /* ── US States Map ─────────────────────────────────────────────── */

    /**
     * Full list of US states + DC.
     *
     * @return array<string, string> state_code => state_name
     */
    public static function get_us_states(): array
    {
        return [
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
            'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
            'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
            'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
            'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
            'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
            'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
            'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
            'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
            'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        ];
    }
}
