<?php
/**
 * Tax Resolver Database - Schema management.
 *
 * Creates and manages custom tables for the Tax Address Resolver:
 *   - coverage_rules: per-state coverage status and resolver mapping
 *   - dataset_versions: immutable dataset version records
 *   - jurisdiction_rates: imported local tax rates by jurisdiction
 *   - quotes_audit: full audit trail of every query
 *   - address_cache: normalized address cache
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Resolver_DB
{
    const DB_VERSION = '1.4.0';
    const DB_VERSION_OPT = 'ffla_tax_resolver_db_version';

    /**
     * Get table name with WP prefix.
     */
    public static function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ffla_tax_' . $name;
    }

    /**
     * Create or update all tables.
     */
    public static function install(): void
    {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $t_coverage = self::table('coverage_rules');
        $sql_coverage = "CREATE TABLE {$t_coverage} (
            state_code         CHAR(2) NOT NULL,
            resolver_name      VARCHAR(100) NOT NULL DEFAULT '',
            coverage_status    VARCHAR(50) NOT NULL DEFAULT 'UNSUPPORTED',
            effective_start    DATE DEFAULT NULL,
            effective_end      DATE DEFAULT NULL,
            notes              TEXT,
            updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (state_code)
        ) {$charset};";

        $t_datasets = self::table('dataset_versions');
        $sql_datasets = "CREATE TABLE {$t_datasets} (
            id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_code        VARCHAR(100) NOT NULL,
            state_code         CHAR(2) DEFAULT NULL,
            version_label      VARCHAR(100) NOT NULL,
            effective_date     DATE NOT NULL,
            loaded_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            checksum           VARCHAR(128) DEFAULT NULL,
            freshness_policy   VARCHAR(50) NOT NULL DEFAULT '90d',
            status             VARCHAR(20) NOT NULL DEFAULT 'pending',
            storage_uri        VARCHAR(500) DEFAULT NULL,
            row_count          INT UNSIGNED DEFAULT 0,
            notes              TEXT,
            PRIMARY KEY (id),
            KEY idx_source_state_status (source_code, state_code, status),
            KEY idx_effective (effective_date)
        ) {$charset};";

        $t_rates = self::table('jurisdiction_rates');
        $sql_rates = "CREATE TABLE {$t_rates} (
            id                     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            dataset_version_id     BIGINT(20) UNSIGNED NOT NULL,
            state_code             CHAR(2) NOT NULL,
            jurisdiction_fips      VARCHAR(20) DEFAULT NULL,
            jurisdiction_code      VARCHAR(50) NOT NULL,
            jurisdiction_type      VARCHAR(30) NOT NULL,
            jurisdiction_name      VARCHAR(200) NOT NULL,
            rate                   DECIMAL(8,6) NOT NULL,
            rate_type              VARCHAR(30) NOT NULL DEFAULT 'general',
            effective_date         DATE NOT NULL,
            expires_at             DATE DEFAULT NULL,
            zip_codes              TEXT,
            city_names             TEXT,
            notes                  TEXT,
            PRIMARY KEY (id),
            KEY idx_state_type (state_code, jurisdiction_type),
            KEY idx_dataset (dataset_version_id),
            KEY idx_fips (jurisdiction_fips),
            KEY idx_state_effective (state_code, effective_date)
        ) {$charset};";

        $t_audit = self::table('quotes_audit');
        $sql_audit = "CREATE TABLE {$t_audit} (
            query_id           VARCHAR(36) NOT NULL,
            requested_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            input_json         TEXT NOT NULL,
            normalized_json    TEXT,
            matched_address    VARCHAR(500) DEFAULT NULL,
            state_code         CHAR(2) DEFAULT NULL,
            resolver_name      VARCHAR(100) DEFAULT NULL,
            source_code        VARCHAR(100) DEFAULT NULL,
            source_version     VARCHAR(100) DEFAULT NULL,
            coverage_status    VARCHAR(50) DEFAULT NULL,
            outcome_code       VARCHAR(50) NOT NULL,
            confidence         VARCHAR(20) DEFAULT NULL,
            total_rate         DECIMAL(8,6) DEFAULT NULL,
            response_json      LONGTEXT,
            duration_ms        INT UNSIGNED DEFAULT NULL,
            cache_hit          TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (query_id),
            KEY idx_requested (requested_at),
            KEY idx_state (state_code),
            KEY idx_outcome (outcome_code)
        ) {$charset};";

        $t_cache = self::table('address_cache');
        $sql_cache = "CREATE TABLE {$t_cache} (
            cache_key          VARCHAR(64) NOT NULL,
            state_code         CHAR(2) DEFAULT NULL,
            normalized_json    TEXT,
            geocode_json       TEXT,
            quote_json         LONGTEXT,
            created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at         DATETIME NOT NULL,
            PRIMARY KEY (cache_key),
            KEY idx_expires (expires_at)
        ) {$charset};";

        dbDelta($sql_coverage);
        dbDelta($sql_datasets);
        dbDelta($sql_rates);
        dbDelta($sql_audit);
        dbDelta($sql_cache);

        $wpdb->query(
            "UPDATE {$t_datasets}
             SET state_code = UPPER(LEFT(version_label, 2))
             WHERE state_code IS NULL
               AND version_label REGEXP '^[A-Za-z]{2}-'"
        );

        self::seed_coverage_rules();

        update_option(self::DB_VERSION_OPT, self::DB_VERSION);
    }

    /**
     * Check if tables need updating.
     */
    public static function needs_upgrade(): bool
    {
        return get_option(self::DB_VERSION_OPT, '') !== self::DB_VERSION;
    }

    /**
     * Seed initial coverage rules.
     */
    private static function seed_coverage_rules(): void
    {
        global $wpdb;

        $table = self::table('coverage_rules');
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        $states = [
            'AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL',
            'GA','HI','ID','IL','IN','IA','KS','KY','LA','ME',
            'MD','MA','MI','MN','MS','MO','MT','NE','NV','NH',
            'NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI',
            'SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
        ];

        foreach ($states as $code) {
            $wpdb->insert($table, [
                'state_code'      => $code,
                'resolver_name'   => '',
                'coverage_status' => 'UNSUPPORTED',
                'notes'           => null,
                'updated_at'      => current_time('mysql'),
            ]);
        }
    }

    /**
     * Drop all custom tables.
     */
    public static function uninstall(): void
    {
        global $wpdb;

        $tables = [
            self::table('coverage_rules'),
            self::table('dataset_versions'),
            self::table('jurisdiction_rates'),
            self::table('manual_overrides'),
            self::table('quotes_audit'),
            self::table('address_cache'),
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        delete_option(self::DB_VERSION_OPT);
    }

    /**
     * Clean expired cache entries.
     */
    public static function cleanup_cache(): void
    {
        global $wpdb;

        $table = self::table('address_cache');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE expires_at < %s",
            current_time('mysql')
        ));
    }

    /**
     * Purge old audit entries.
     */
    public static function purge_audit(int $days = 90): void
    {
        global $wpdb;

        $table = self::table('quotes_audit');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE requested_at < DATE_SUB(%s, INTERVAL %d DAY)",
            current_time('mysql'),
            $days
        ));
    }

    /**
     * Flush every entry in the address_cache table.
     *
     * Called when settings change in a way that would otherwise surface stale
     * quotes — for example toggling the USGeocoder key or editing the
     * restrict-states list, both of which change the resolver that should
     * produce quotes for a given address.
     *
     * @return int Number of rows removed.
     */
    public static function flush_address_cache(): int
    {
        global $wpdb;

        $table   = self::table('address_cache');
        $deleted = (int) $wpdb->query("DELETE FROM {$table}");

        return max(0, $deleted);
    }

    /**
     * Clear cached quote entries for a state.
     */
    public static function clear_state_cache(string $state_code): void
    {
        global $wpdb;

        $table = self::table('address_cache');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE state_code = %s",
            strtoupper($state_code)
        ));
    }

    /**
     * Upsert a manual override row for a state/city or state floor.
     */
    public static function save_manual_override(
        string $state_code,
        string $scope,
        string $city_key,
        string $city_label,
        float $manual_rate,
        bool $lock_on_resync,
        string $reason,
        int $updated_by = 0
    ): int {
        global $wpdb;

        $table = self::table('manual_overrides');
        $state_code = strtoupper($state_code);
        $scope = $scope === 'state' ? 'state' : 'city';
        $city_key = $scope === 'state' ? '' : $city_key;
        $city_label = $scope === 'state' ? '' : $city_label;

        $existing_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id
             FROM {$table}
             WHERE state_code = %s
               AND override_scope = %s
               AND city_key = %s
             LIMIT 1",
            $state_code,
            $scope,
            $city_key
        ));

        $payload = [
            'state_code'     => $state_code,
            'override_scope' => $scope,
            'city_key'       => $city_key,
            'city_label'     => $city_label !== '' ? $city_label : null,
            'manual_rate'    => $manual_rate,
            'lock_on_resync' => $lock_on_resync ? 1 : 0,
            'reason'         => $reason !== '' ? $reason : null,
            'updated_by'     => $updated_by > 0 ? $updated_by : null,
            'updated_at'     => current_time('mysql'),
        ];

        if ($existing_id > 0) {
            $wpdb->update(
                $table,
                $payload,
                ['id' => $existing_id],
                ['%s', '%s', '%s', '%s', '%f', '%d', '%s', '%d', '%s'],
                ['%d']
            );

            return $existing_id;
        }

        $wpdb->insert(
            $table,
            $payload,
            ['%s', '%s', '%s', '%s', '%f', '%d', '%s', '%d', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Delete one manual override by id.
     */
    public static function delete_manual_override(int $override_id): bool
    {
        global $wpdb;

        $table = self::table('manual_overrides');

        return (bool) $wpdb->delete($table, ['id' => $override_id], ['%d']);
    }

    /**
     * Fetch one manual override by state/scope/city key.
     *
     * @return array<string,mixed>|null
     */
    public static function get_manual_override(string $state_code, string $scope, string $city_key = ''): ?array
    {
        global $wpdb;

        $table = self::table('manual_overrides');
        $scope = $scope === 'state' ? 'state' : 'city';
        $city_key = $scope === 'state' ? '' : $city_key;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE state_code = %s
               AND override_scope = %s
               AND city_key = %s
             LIMIT 1",
            strtoupper($state_code),
            $scope,
            $city_key
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Fetch one manual override by id.
     *
     * @return array<string,mixed>|null
     */
    public static function get_manual_override_by_id(int $override_id): ?array
    {
        global $wpdb;

        $table = self::table('manual_overrides');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE id = %d
             LIMIT 1",
            $override_id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * List manual overrides for admin.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list_manual_overrides(?string $state_code = null): array
    {
        global $wpdb;

        $table = self::table('manual_overrides');

        if ($state_code !== null && $state_code !== '') {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT *
                 FROM {$table}
                 WHERE state_code = %s
                 ORDER BY state_code ASC, override_scope ASC, city_label ASC, updated_at DESC",
                strtoupper($state_code)
            ), ARRAY_A) ?: [];
        }

        return $wpdb->get_results(
            "SELECT *
             FROM {$table}
             ORDER BY state_code ASC, override_scope ASC, city_label ASC, updated_at DESC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Delete overrides for a state that are not locked against resync.
     *
     * @return int Number of deleted rows.
     */
    public static function clear_resyncable_overrides(string $state_code): int
    {
        global $wpdb;

        $table = self::table('manual_overrides');
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table}
             WHERE state_code = %s
               AND lock_on_resync = 0",
            strtoupper($state_code)
        ));

        return (int) $wpdb->rows_affected;
    }

    /**
     * Count manual overrides for health/admin summaries.
     */
    public static function count_manual_overrides(): int
    {
        global $wpdb;

        $table = self::table('manual_overrides');

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Delete legacy local-dataset records from the old tax flow.
     *
     * @return array<string,int>
     */
    public static function purge_legacy_local_data(): array
    {
        global $wpdb;

        $datasets_table = self::table('dataset_versions');
        $rates_table    = self::table('jurisdiction_rates');
        $cache_table    = self::table('address_cache');
        $audit_table    = self::table('quotes_audit');

        $result = [
            'dataset_versions_deleted' => 0,
            'jurisdiction_rates_deleted' => 0,
            'address_cache_deleted' => 0,
            'quotes_audit_deleted' => 0,
        ];

        $result['dataset_versions_deleted'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$datasets_table}");
        $result['jurisdiction_rates_deleted'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rates_table}");
        $result['address_cache_deleted'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$cache_table}");
        $result['quotes_audit_deleted'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$audit_table}");

        $wpdb->query("DELETE FROM {$rates_table}");
        $wpdb->query("DELETE FROM {$datasets_table}");
        $wpdb->query("DELETE FROM {$cache_table}");
        $wpdb->query("DELETE FROM {$audit_table}");

        return $result;
    }
}
