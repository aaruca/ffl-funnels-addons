<?php
/**
 * WSS Logger — Logs sync operations to the wss_log database table.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Logger
{
    /**
     * Get the log table name.
     */
    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wss_log';
    }

    /**
     * Log a sync operation.
     *
     * @param string $direction    'woo_to_sheet' or 'sheet_to_woo'.
     * @param int    $product_id   Parent product ID.
     * @param int    $variation_id Variation ID (same as product_id for simple products).
     * @param string $status       'success', 'error', or 'skipped'.
     * @param string $message      Description of what happened.
     */
    public function log(string $direction, int $product_id, int $variation_id, string $status, string $message): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $this->table(),
            [
                'direction'    => $direction,
                'product_id'   => $product_id,
                'variation_id' => $variation_id,
                'status'       => $status,
                'message'      => $message,
            ],
            ['%s', '%d', '%d', '%s', '%s']
        );
    }

    /**
     * Get recent log entries.
     *
     * @param int         $limit     Max entries to return.
     * @param string|null $status    Filter by status (null = all).
     * @param string|null $direction Filter by direction (null = all).
     * @return array
     */
    public function get_recent(int $limit = 50, ?string $status = null, ?string $direction = null): array
    {
        global $wpdb;

        $where  = [];
        $values = [];

        if ($status !== null) {
            $where[]  = 'status = %s';
            $values[] = $status;
        }

        if ($direction !== null) {
            $where[]  = 'direction = %s';
            $values[] = $direction;
        }

        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT * FROM {$this->table()} {$where_sql} ORDER BY created_at DESC LIMIT %d";
        $values[] = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A) ?: [];
    }

    /**
     * Clear all log entries.
     */
    public function clear(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query("TRUNCATE TABLE {$this->table()}");
    }

    /**
     * Get total count of log entries.
     */
    public function count(): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
    }
}
