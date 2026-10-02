<?php
/**
 * Media Cleaner — database schema.
 *
 * Two tables, mirroring the proven Media-Cleaner model:
 *   - refs:  a cache of every media ID / URL currently in use, rebuilt each scan
 *   - scan:  the issues found (unused / orphaned / duplicate), persistent
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Database
{
    const DB_VERSION = '1.0.0';

    const DB_VERSION_OPTION = 'ffla_media_cleaner_db_version';

    public static function refs_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ffla_mclean_refs';
    }

    public static function scan_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'ffla_mclean_scan';
    }

    /**
     * Create or upgrade both tables. Idempotent (dbDelta).
     */
    public static function install(): void
    {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $scan  = self::scan_table();
        $refs  = self::refs_table();

        // Issues found by a scan. `type` 0 = filesystem file, 1 = media entry.
        // `issue` is a short codename (NO_CONTENT, ORPHAN_MEDIA, DUPLICATE, ...).
        $sql_scan = "CREATE TABLE {$scan} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            time DATETIME DEFAULT '1970-01-01 00:00:00' NOT NULL,
            type TINYINT(1) NOT NULL DEFAULT 0,
            post_id BIGINT(20) UNSIGNED NULL,
            path TEXT NULL,
            size BIGINT(20) UNSIGNED NULL DEFAULT 0,
            ignored TINYINT(1) NOT NULL DEFAULT 0,
            deleted TINYINT(1) NOT NULL DEFAULT 0,
            issue VARCHAR(32) NOT NULL DEFAULT '',
            parent_id BIGINT(20) UNSIGNED NULL,
            PRIMARY KEY  (id),
            KEY post_id_idx (post_id),
            KEY ignored_idx (ignored),
            KEY deleted_idx (deleted),
            KEY issue_idx (issue)
        ) {$charset_collate};";

        // Reference cache. A media is "in use" if its ID or any of its file
        // paths (resolution-stripped) appears here. ref_hash dedupes inserts.
        // mediaUrl is indexed via a prefix — TEXT columns need a key length.
        $sql_refs = "CREATE TABLE {$refs} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            media_id BIGINT(20) UNSIGNED NULL,
            media_url TEXT NULL,
            origin_type VARCHAR(64) NOT NULL DEFAULT '',
            origin BIGINT(20) UNSIGNED NULL,
            parent_id BIGINT(20) UNSIGNED NULL,
            ref_hash CHAR(32) NULL,
            PRIMARY KEY  (id),
            KEY media_id_idx (media_id),
            KEY media_url_idx (media_url(191)),
            UNIQUE KEY ref_hash_uniq (ref_hash)
        ) {$charset_collate};";

        dbDelta($sql_scan);
        dbDelta($sql_refs);

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /**
     * Empty the reference cache before a fresh scan. TRUNCATE, not DELETE:
     * the cache is disposable and rebuilt every run.
     */
    public static function truncate_refs(): void
    {
        global $wpdb;
        $refs = self::refs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("TRUNCATE TABLE {$refs}");
    }

    /**
     * Clear the open results before a new scan.
     *
     * Only rows that are neither trashed nor ignored are removed. Trashed rows
     * are the only map from a trash folder back to its attachment or file, and
     * ignored rows are the user's decisions, so both must survive a rescan.
     * DELETE (not TRUNCATE) also keeps AUTO_INCREMENT moving forward: row IDs
     * name the trash folders, so an ID must never be handed out twice.
     */
    public static function clear_open_issues(): void
    {
        global $wpdb;
        $scan = self::scan_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DELETE FROM {$scan} WHERE deleted = 0 AND ignored = 0");
    }

    /**
     * Kept for backwards compatibility; now clears only the open results.
     *
     * @deprecated 1.55.1 Use clear_open_issues().
     */
    public static function truncate_scan(): void
    {
        self::clear_open_issues();
    }

    /**
     * Make sure the next row ID is above $floor, so a new result can never be
     * given the number of a trash folder that already exists on disk.
     */
    public static function ensure_next_id_above(int $floor): void
    {
        if ($floor <= 0) {
            return;
        }

        global $wpdb;
        $scan = self::scan_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $max = (int) $wpdb->get_var("SELECT MAX(id) FROM {$scan}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $next = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $scan
        ));

        $want = max($floor, $max) + 1;
        if ($next < $want) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE {$scan} AUTO_INCREMENT = " . (int) $want);
        }
    }

    /**
     * Drop both tables. Called from uninstall only.
     */
    public static function drop(): void
    {
        global $wpdb;
        $scan = self::scan_table();
        $refs = self::refs_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("DROP TABLE IF EXISTS {$scan}, {$refs}");
        delete_option(self::DB_VERSION_OPTION);
    }
}
