<?php
/**
 * Tax Resolver Database — Schema management.
 *
 * Creates and manages custom tables for the Tax Address Resolver:
 *   - coverage_rules: per-state coverage status and resolver mapping
 *   - dataset_versions: immutable dataset version records
 *   - jurisdiction_rates: official tax rates by jurisdiction
 *   - quotes_audit: full audit trail of every query
 *   - address_cache: normalized address + geocode cache
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Resolver_DB
{
    const DB_VERSION      = '1.1.0';
    const DB_VERSION_OPT  = 'ffla_tax_resolver_db_version';

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

        // ── Coverage Rules ──────────────────────────────────────────
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

        // ── Dataset Versions ────────────────────────────────────────
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

        // ── Jurisdiction Rates ──────────────────────────────────────
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
            PRIMARY KEY (id),
            KEY idx_state_type (state_code, jurisdiction_type),
            KEY idx_dataset (dataset_version_id),
            KEY idx_fips (jurisdiction_fips),
            KEY idx_state_effective (state_code, effective_date)
        ) {$charset};";

        // ── Quotes Audit ────────────────────────────────────────────
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

        // ── Address Cache ───────────────────────────────────────────
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

        // Backfill state_code for earlier resolver builds that stored per-state
        // datasets without the explicit state_code column.
        $wpdb->query(
            "UPDATE {$t_datasets}
             SET state_code = UPPER(LEFT(version_label, 2))
             WHERE state_code IS NULL
               AND version_label REGEXP '^[A-Za-z]{2}-'"
        );

        // Seed coverage rules for all 50 states + DC.
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
     * Seed initial coverage rules (all UNSUPPORTED).
     */
    private static function seed_coverage_rules(): void
    {
        global $wpdb;

        $table = self::table('coverage_rules');

        // Only seed if table is empty.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        // No-sales-tax states.
        $no_tax = ['AK', 'DE', 'MT', 'NH', 'OR'];

        $states = [
            'AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL',
            'GA','HI','ID','IL','IN','IA','KS','KY','LA','ME',
            'MD','MA','MI','MN','MS','MO','MT','NE','NV','NH',
            'NJ','NM','NY','NC','ND','OH','OK','OR','PA','RI',
            'SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
        ];

        foreach ($states as $code) {
            $status = in_array($code, $no_tax, true) ? 'NO_SALES_TAX' : 'UNSUPPORTED';
            $note   = in_array($code, $no_tax, true) ? 'State does not levy a general sales tax.' : null;

            $wpdb->insert($table, [
                'state_code'      => $code,
                'resolver_name'   => '',
                'coverage_status' => $status,
                'notes'           => $note,
                'updated_at'      => current_time('mysql'),
            ]);
        }
    }

    /**
     * Drop all custom tables (for uninstall).
     */
    public static function uninstall(): void
    {
        global $wpdb;

        $tables = [
            self::table('coverage_rules'),
            self::table('dataset_versions'),
            self::table('jurisdiction_rates'),
            self::table('quotes_audit'),
            self::table('address_cache'),
        ];

        foreach ($tables as $t) {
            $wpdb->query("DROP TABLE IF EXISTS {$t}");
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
     * Purge old audit entries (older than N days).
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
     * Clear cached quote entries for a state after dataset refresh.
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
}
