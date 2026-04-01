<?php
/**
 * Tax Coverage Matrix.
 *
 * Manages per-state coverage status and provides structured
 * coverage data for API and admin UI consumption.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Coverage
{
    const SETTINGS_KEY                = 'ffla_tax_resolver_settings';

    /* Coverage status constants. */
    const UNSUPPORTED                 = 'UNSUPPORTED';
    const SUPPORTED_ADDRESS_RATE      = 'SUPPORTED_ADDRESS_RATE';
    const SUPPORTED_WITH_REMOTE       = 'SUPPORTED_WITH_REMOTE_LOOKUP';
    const SUPPORTED_CONTEXT_REQUIRED  = 'SUPPORTED_BUT_CONTEXT_REQUIRED';
    const DEGRADED                    = 'DEGRADED';
    const NO_SALES_TAX                = 'NO_SALES_TAX';

    /**
     * Resolver/source strategy labels shown in admin and REST responses.
     *
     * These describe the tax-source family used by a state, not helper
     * infrastructure like the Census geocoder.
     */
    const SOURCE_STRATEGY_NONE = 'none';
    const SOURCE_STRATEGY_OFFICIAL = 'official';
    const SOURCE_STRATEGY_HANDBOOK = 'handbook_city_table';
    const SOURCE_STRATEGY_NO_TAX = 'official_no_tax_law';

    /**
     * Canonical US state list including DC.
     *
     * @var string[]
     */
    const ALL_STATES = [
        'AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL',
        'GA','HI','ID','IL','IN','IA','KS','KY','LA','ME',
        'MD','MA','MI','MN','MS','MO','MT','NE','NV','NH',
        'NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI',
        'SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
    ];

    /**
     * Get coverage rule for a single state.
     *
     * @param  string $state_code Two-letter state code.
     * @return array|null
     */
    public static function get_state(string $state_code): ?array
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('coverage_rules');
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE state_code = %s",
            strtoupper($state_code)
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Get full coverage matrix (all states).
     *
     * @return array[] Array of coverage rule rows.
     */
    public static function get_matrix(): array
    {
        global $wpdb;

        $table  = Tax_Resolver_DB::table('coverage_rules');
        $rows   = $wpdb->get_results("SELECT * FROM {$table} ORDER BY state_code ASC", ARRAY_A);

        return $rows ?: [];
    }

    /**
     * Get coverage matrix as a structured API response.
     *
     * @return array
     */
    public static function get_api_response(): array
    {
        $matrix = self::get_matrix();
        $states = [];

        // Summary counts.
        $counts = [
            'total'          => count($matrix),
            'supported'      => 0,
            'unsupported'    => 0,
            'no_sales_tax'   => 0,
            'degraded'       => 0,
            'enabled_for_store'  => 0,
            'disabled_for_store' => 0,
            'official_source_states' => 0,
            'handbook_source_states' => 0,
        ];

        foreach ($matrix as $row) {
            $strategy = self::build_source_strategy($row['state_code'], $row);

            $states[$row['state_code']] = [
                'state'           => $row['state_code'],
                'status'          => $row['coverage_status'],
                'resolver'        => $row['resolver_name'] ?: null,
                'effectiveStart'  => $row['effective_start'],
                'effectiveEnd'    => $row['effective_end'],
                'notes'           => $row['notes'],
                'enabledForStore' => self::is_enabled_for_store($row['state_code']),
                'sourceStrategy'  => $strategy,
            ];
        }

        foreach ($states as $s) {
            switch ($s['status']) {
                case self::SUPPORTED_ADDRESS_RATE:
                case self::SUPPORTED_WITH_REMOTE:
                case self::SUPPORTED_CONTEXT_REQUIRED:
                    $counts['supported']++;
                    break;
                case self::NO_SALES_TAX:
                    $counts['no_sales_tax']++;
                    break;
                case self::DEGRADED:
                    $counts['degraded']++;
                    break;
                default:
                    $counts['unsupported']++;
            }

            if (!empty($s['enabledForStore'])) {
                $counts['enabled_for_store']++;
            } else {
                $counts['disabled_for_store']++;
            }

            if (($s['sourceStrategy']['family'] ?? null) === self::SOURCE_STRATEGY_HANDBOOK) {
                $counts['handbook_source_states']++;
            } elseif (in_array(($s['sourceStrategy']['family'] ?? null), [self::SOURCE_STRATEGY_OFFICIAL, self::SOURCE_STRATEGY_NO_TAX], true)) {
                $counts['official_source_states']++;
            }
        }

        return [
            'generatedAt'             => current_time('c'),
            'summary'                 => $counts,
            'stateFilterActive'       => self::has_state_filter(),
            'enabledStatesConfigured' => self::get_enabled_states(),
            'states'                  => $states,
        ];
    }

    /**
     * Check if a state is supported (any supported level).
     *
     * @param  string $state_code
     * @return bool
     */
    public static function is_supported(string $state_code): bool
    {
        $rule = self::get_state($state_code);
        if (!$rule) {
            return false;
        }

        return in_array($rule['coverage_status'], [
            self::SUPPORTED_ADDRESS_RATE,
            self::SUPPORTED_WITH_REMOTE,
            self::SUPPORTED_CONTEXT_REQUIRED,
        ], true);
    }

    /**
     * Check if a state has no sales tax.
     */
    public static function has_no_tax(string $state_code): bool
    {
        $rule = self::get_state($state_code);
        return $rule && $rule['coverage_status'] === self::NO_SALES_TAX;
    }

    /**
     * Check whether the store is restricted to a selected subset of states.
     */
    public static function has_state_filter(): bool
    {
        $settings = get_option(self::SETTINGS_KEY, []);
        return !empty($settings['restrict_states']) && (string) $settings['restrict_states'] === '1';
    }

    /**
     * Get the configured list of enabled states for this store.
     *
     * @return string[]
     */
    public static function get_enabled_states(): array
    {
        $settings = get_option(self::SETTINGS_KEY, []);
        $raw      = $settings['enabled_states'] ?? [];
        $states   = [];

        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $state_code) {
            $state_code = strtoupper(sanitize_text_field(wp_unslash((string) $state_code)));
            if (in_array($state_code, self::ALL_STATES, true)) {
                $states[] = $state_code;
            }
        }

        $states = array_values(array_unique($states));
        sort($states);

        return $states;
    }

    /**
     * Check whether a state is enabled for active store use.
     */
    public static function is_enabled_for_store(string $state_code): bool
    {
        $state_code = strtoupper($state_code);

        if (!in_array($state_code, self::ALL_STATES, true)) {
            return false;
        }

        if (!self::has_state_filter()) {
            return true;
        }

        return in_array($state_code, self::get_enabled_states(), true);
    }

    /**
     * Update coverage status for a state.
     */
    public static function update_state(string $state_code, string $status, string $resolver = '', ?string $notes = null): bool
    {
        global $wpdb;

        $table = Tax_Resolver_DB::table('coverage_rules');

        return (bool) $wpdb->update(
            $table,
            [
                'coverage_status' => $status,
                'resolver_name'   => $resolver,
                'notes'           => $notes,
                'updated_at'      => current_time('mysql'),
            ],
            ['state_code' => strtoupper($state_code)],
            ['%s', '%s', '%s', '%s'],
            ['%s']
        );
    }

    /**
     * Get the resolver name for a state.
     *
     * @return string|null Resolver class name or null.
     */
    public static function get_resolver_name(string $state_code): ?string
    {
        $rule = self::get_state($state_code);
        return ($rule && !empty($rule['resolver_name'])) ? $rule['resolver_name'] : null;
    }

    /**
     * Get the tax-source strategy used for a state.
     *
     * This exposes the real source-of-truth family: official sources or the
     * SalesTaxHandbook state city table fallback.
     */
    public static function get_source_strategy(string $state_code): array
    {
        $state_code = strtoupper($state_code);

        return self::build_source_strategy($state_code, self::get_state($state_code));
    }

    /**
     * Build a source strategy payload from a coverage-rule row.
     *
     * @param  string     $state_code
     * @param  array|null $rule
     * @return array<string,mixed>
     */
    private static function build_source_strategy(string $state_code, ?array $rule): array
    {
        $state_code = strtoupper($state_code);
        $status = $rule['coverage_status'] ?? self::UNSUPPORTED;
        $resolver_name = $rule['resolver_name'] ?? '';
        $requires_geocode = self::resolver_requires_geocode($state_code, $resolver_name, $status);

        if ($status === self::NO_SALES_TAX) {
            return [
                'key'             => self::SOURCE_STRATEGY_NO_TAX,
                'family'          => self::SOURCE_STRATEGY_NO_TAX,
                'label'           => 'Official state no-tax law',
                'shortLabel'      => 'No Tax Law',
                'primary'         => 'official_no_tax_law',
                'primaryLabel'    => 'Official state no-tax law',
                'fallback'        => null,
                'fallbackLabel'   => null,
                'requiresGeocode' => false,
            ];
        }

        if ($resolver_name === 'handbook_city_dataset') {
            return [
                'key'             => 'handbook_city_dataset',
                'family'          => self::SOURCE_STRATEGY_HANDBOOK,
                'label'           => 'SalesTaxHandbook imported city table dataset',
                'shortLabel'      => 'Handbook',
                'primary'         => 'handbook_city_dataset',
                'primaryLabel'    => 'SalesTaxHandbook city table imported to local datasets',
                'fallback'        => null,
                'fallbackLabel'   => null,
                'requiresGeocode' => $requires_geocode,
            ];
        }

        return [
            'key'             => self::SOURCE_STRATEGY_NONE,
            'family'          => self::SOURCE_STRATEGY_NONE,
            'label'           => 'No active source strategy',
            'shortLabel'      => 'None',
            'primary'         => null,
            'primaryLabel'    => null,
            'fallback'        => null,
            'fallbackLabel'   => null,
            'requiresGeocode' => false,
        ];
    }

    /**
     * Determine whether the active resolver for a state requires geocoding.
     */
    private static function resolver_requires_geocode(string $state_code, string $resolver_name, string $status): bool
    {
        if (in_array($status, [self::UNSUPPORTED, self::NO_SALES_TAX], true)) {
            return false;
        }

        if (class_exists('Tax_Resolver_Router')) {
            $resolver = Tax_Resolver_Router::route($state_code);
            if (is_object($resolver) && method_exists($resolver, 'requires_geocode')) {
                return (bool) $resolver->requires_geocode();
            }
        }

        return !in_array($resolver_name, ['handbook_city_dataset'], true);
    }
}
