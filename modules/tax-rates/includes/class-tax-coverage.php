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
    /* Coverage status constants. */
    const UNSUPPORTED                 = 'UNSUPPORTED';
    const SUPPORTED_ADDRESS_RATE      = 'SUPPORTED_ADDRESS_RATE';
    const SUPPORTED_WITH_REMOTE       = 'SUPPORTED_WITH_REMOTE_LOOKUP';
    const SUPPORTED_CONTEXT_REQUIRED  = 'SUPPORTED_BUT_CONTEXT_REQUIRED';
    const DEGRADED                    = 'DEGRADED';
    const NO_SALES_TAX                = 'NO_SALES_TAX';

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

        foreach ($matrix as $row) {
            $states[$row['state_code']] = [
                'state'           => $row['state_code'],
                'status'          => $row['coverage_status'],
                'resolver'        => $row['resolver_name'] ?: null,
                'effectiveStart'  => $row['effective_start'],
                'effectiveEnd'    => $row['effective_end'],
                'notes'           => $row['notes'],
            ];
        }

        // Summary counts.
        $counts = [
            'total'          => count($states),
            'supported'      => 0,
            'unsupported'    => 0,
            'no_sales_tax'   => 0,
            'degraded'       => 0,
        ];

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
        }

        return [
            'generatedAt' => current_time('c'),
            'summary'     => $counts,
            'states'      => $states,
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
}
