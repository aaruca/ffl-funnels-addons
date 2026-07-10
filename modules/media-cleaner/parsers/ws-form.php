<?php
/**
 * Media Cleaner parser — WS Form.
 *
 * WS Form keeps its form configuration — fields, and actions such as a
 * "Redirect" that can point at an uploaded PDF — in its own custom tables as
 * JSON, none of which the generic scan reads. Without this, a document linked
 * only from a form redirect is reported as unused.
 *
 * The exact column that holds a given URL varies across WS Form versions and
 * action types, so rather than hard-code a schema this sweeps every text value
 * in the form-configuration tables for uploads URLs. The large submission and
 * log tables are skipped.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('ffla_mclean_scan_once', 'ffla_mclean_wsform_scan_once', 10, 0);

function ffla_mclean_wsform_scan_once(): void
{
    global $wpdb, $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    // WS Form's tables are prefixed wsf_. Find them without assuming exact
    // names. SHOW TABLES needs no information_schema privilege.
    $like = $wpdb->esc_like($wpdb->prefix . 'wsf_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
    $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

    if (empty($tables)) {
        return;
    }

    $urls = [];

    foreach ($tables as $table) {
        $lower = strtolower($table);
        // Skip the high-volume tables: submissions, logs, errors. Form config
        // (labels, fields, actions) lives in the small tables.
        if (strpos($lower, 'submit') !== false
            || strpos($lower, 'log') !== false
            || strpos($lower, 'error') !== false) {
            continue;
        }

        // Table name comes from information_schema, not user input; still
        // constrain it to the expected shape before interpolating.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            continue;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 5000", ARRAY_A);
        if (empty($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_string($value) && $value !== '' && strpos($value, '/') !== false) {
                    $found = $ffla_mclean->get_urls_from_html($value);
                    if (!empty($found)) {
                        $urls = array_merge($urls, $found);
                    }
                }
            }
        }
    }

    if (!empty($urls)) {
        $ffla_mclean->add_reference_url(array_values(array_unique($urls)), 'WS Form');
    }
}
