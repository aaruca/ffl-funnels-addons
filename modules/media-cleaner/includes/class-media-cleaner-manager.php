<?php
/**
 * Media Cleaner — issue lifecycle and queries.
 *
 * Turns a scan-table row into a reversible action. Two kinds of issue:
 *   - type 1 (media): an attachment. Trashing hides it behind a sentinel
 *     post_type and moves its files aside; restoring reverses both.
 *   - type 0 (file): a loose file under uploads with no attachment. Trashing
 *     moves the single file; restoring moves it back.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Manager
{
    const TYPE_FILE  = 0;
    const TYPE_MEDIA = 1;

    /** @var Media_Cleaner_Core */
    private $core;

    public function __construct(Media_Cleaner_Core $core)
    {
        $this->core = $core;
    }

    /* =====================================================================
     * Queries
     * ================================================================== */

    /**
     * @return array{items:array<int,object>,total:int}
     */
    public function get_issues(string $status = 'active', int $page = 1, int $per_page = 25, string $search = ''): array
    {
        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();

        $where  = [];
        $params = [];

        switch ($status) {
            case 'trashed':
                $where[] = 'deleted = 1';
                break;
            case 'ignored':
                $where[] = 'ignored = 1 AND deleted = 0';
                break;
            case 'active':
            default:
                $where[] = 'deleted = 0 AND ignored = 0';
                break;
        }

        if ($search !== '') {
            $where[]  = 'path LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $per_page = max(1, min(200, $per_page));
        $offset   = max(0, ($page - 1) * $per_page);

        // Count.
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        // Page.
        $page_params   = $params;
        $page_params[] = $per_page;
        $page_params[] = $offset;
        $list_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY size DESC, id DESC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $items = $wpdb->get_results($wpdb->prepare($list_sql, $page_params));

        return ['items' => $items ?: [], 'total' => $total];
    }

    /**
     * @return array{active:int,active_size:int,trashed:int,ignored:int}
     */
    public function get_stats(): array
    {
        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN deleted = 0 AND ignored = 0 THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN deleted = 0 AND ignored = 0 THEN size ELSE 0 END) AS active_size,
                SUM(CASE WHEN deleted = 1 THEN 1 ELSE 0 END) AS trashed,
                SUM(CASE WHEN ignored = 1 AND deleted = 0 THEN 1 ELSE 0 END) AS ignored
             FROM {$table}",
            ARRAY_A
        );

        if (!is_array($row)) {
            $row = [];
        }

        return [
            'active'      => (int) ($row['active'] ?? 0),
            'active_size' => (int) ($row['active_size'] ?? 0),
            'trashed'     => (int) ($row['trashed'] ?? 0),
            'ignored'     => (int) ($row['ignored'] ?? 0),
        ];
    }

    /* =====================================================================
     * Actions
     * ================================================================== */

    /**
     * Trash an issue. First step of removal — always reversible.
     */
    public function trash(int $issue_id): bool
    {
        $issue = $this->core->get_issue($issue_id);
        if (!$issue || (int) $issue->deleted === 1) {
            return false;
        }

        // With trash disabled, "trash" means permanent removal straight away.
        if (!$this->core->uses_trash()) {
            return $this->delete_permanently($issue_id);
        }

        if ((int) $issue->type === self::TYPE_MEDIA) {
            return $this->trash_media((int) $issue->post_id, $issue_id);
        }

        return $this->trash_file((string) $issue->path, $issue_id);
    }

    public function restore(int $issue_id): bool
    {
        $issue = $this->core->get_issue($issue_id);
        if (!$issue || (int) $issue->deleted !== 1) {
            return false;
        }

        if ((int) $issue->type === self::TYPE_MEDIA) {
            return $this->restore_media((int) $issue->post_id, $issue_id);
        }

        return $this->restore_file((string) $issue->path, $issue_id);
    }

    /**
     * Permanently delete. For a trashed item, removes it for good; for an
     * active item with trash disabled, deletes directly.
     */
    public function delete_permanently(int $issue_id): bool
    {
        $issue = $this->core->get_issue($issue_id);
        if (!$issue) {
            return false;
        }

        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();

        if ((int) $issue->type === self::TYPE_MEDIA) {
            $post_id = (int) $issue->post_id;

            // If trashed, bring the files back first so WordPress can delete
            // them through its normal path (which also clears intermediate
            // sizes). Best-effort: the bucket is pruned afterwards regardless,
            // so nothing is left stranded in the trash.
            if ((int) $issue->deleted === 1) {
                $this->move_media_files($post_id, false, $issue_id);
                if (get_post_type($post_id) === Media_Cleaner_Trash::SENTINEL_POST_TYPE) {
                    wp_update_post(['ID' => $post_id, 'post_type' => 'attachment']);
                }
            }

            if ($post_id > 0 && get_post($post_id)) {
                wp_delete_attachment($post_id, true);
            }

            // Remove any files that could not be moved back out of the bucket.
            if ((int) $issue->deleted === 1) {
                Media_Cleaner_Trash::prune_bucket((string) $issue_id);
            }

            $wpdb->delete($table, ['id' => $issue_id], ['%d']);

            return true;
        }

        // Filesystem file.
        $path = $this->clean_issue_path((string) $issue->path);
        if ((int) $issue->deleted === 1) {
            Media_Cleaner_Trash::delete_from_trash($path, (string) $issue_id);
            Media_Cleaner_Trash::prune_bucket((string) $issue_id);
        } else {
            $uploads = wp_upload_dir();
            $full    = trailingslashit($uploads['basedir']) . $path;
            if ($path !== '' && strpos($path, '..') === false && file_exists($full)) {
                @unlink($full);
            }
        }

        $wpdb->delete($table, ['id' => $issue_id], ['%d']);

        return true;
    }

    public function ignore(int $issue_id, bool $ignore): bool
    {
        $issue = $this->core->get_issue($issue_id);
        if (!$issue) {
            return false;
        }

        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();

        // Ignoring a trashed item first brings it back.
        if ($ignore && (int) $issue->deleted === 1) {
            $this->restore($issue_id);
        }

        $wpdb->update(
            $table,
            ['ignored' => $ignore ? 1 : 0],
            ['id' => $issue_id],
            ['%d'],
            ['%d']
        );

        // For a media issue, also flag the attachment so the next scan skips it.
        if ((int) $issue->type === self::TYPE_MEDIA && (int) $issue->post_id > 0) {
            $this->core->set_media_ignored((int) $issue->post_id, $ignore);
        }

        return true;
    }

    /**
     * Empty the trash: delete every trashed issue for good.
     *
     * @return int Number of issues removed.
     */
    public function empty_trash(): int
    {
        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col("SELECT id FROM {$table} WHERE deleted = 1");
        $count = 0;
        foreach ($ids as $id) {
            if ($this->delete_permanently((int) $id)) {
                $count++;
            }
        }

        // Deliberately NOT a blind wipe of the trash directory. Deletion happens
        // per issue row, so a file that is somehow in the trash without a
        // deleted=1 row pointing at it (e.g. left by a failed move) is left
        // untouched rather than destroyed — the safe direction.

        return $count;
    }

    /* ---------------------------------------------------------------------
     * Media (attachment) internals
     * ------------------------------------------------------------------- */

    private function trash_media(int $post_id, int $issue_id): bool
    {
        if ($post_id <= 0 || !get_post($post_id)) {
            return false;
        }

        // All-or-nothing: if any file cannot be moved, the rest are put back and
        // the attachment stays live, so no file is ever stranded in the trash
        // while its row still reads as active.
        if (!$this->move_media_files($post_id, true, $issue_id)) {
            return false;
        }

        // Hide from the library without destroying the row.
        wp_update_post(['ID' => $post_id, 'post_type' => Media_Cleaner_Trash::SENTINEL_POST_TYPE]);
        $this->mark_deleted($issue_id, true);

        return true;
    }

    private function restore_media(int $post_id, int $issue_id): bool
    {
        if ($post_id <= 0 || !get_post($post_id)) {
            return false;
        }

        // Honour the move result: if the files cannot all be brought back, leave
        // the row trashed and the attachment hidden. Marking it restored here
        // would let the next "empty trash" destroy files the UI claims are live.
        if (!$this->move_media_files($post_id, false, $issue_id)) {
            return false;
        }

        wp_update_post(['ID' => $post_id, 'post_type' => 'attachment']);
        $this->mark_deleted($issue_id, false);

        return true;
    }

    /**
     * Move every file an attachment owns into (or out of) its trash bucket,
     * atomically. On any failure, whatever was moved is moved back and the
     * method returns false, leaving the filesystem exactly as it started.
     *
     * @param bool $into true = uploads → trash, false = trash → uploads.
     */
    private function move_media_files(int $post_id, bool $into, int $issue_id): bool
    {
        $paths = $this->core->get_paths_from_attachment($post_id);
        if (empty($paths)) {
            // Orphan attachment (no files). Nothing to move; not a failure.
            return true;
        }

        $bucket = (string) $issue_id;
        $moved  = [];

        foreach ($paths as $path) {
            $ok = $into
                ? Media_Cleaner_Trash::move_in($path, $bucket)
                : Media_Cleaner_Trash::move_out($path, $bucket);

            if (!$ok) {
                // Roll back to the original location.
                foreach ($moved as $done) {
                    if ($into) {
                        Media_Cleaner_Trash::move_out($done, $bucket);
                    } else {
                        Media_Cleaner_Trash::move_in($done, $bucket);
                    }
                }

                return false;
            }

            $moved[] = $path;
        }

        return true;
    }

    /* ---------------------------------------------------------------------
     * Filesystem file internals
     * ------------------------------------------------------------------- */

    private function trash_file(string $path, int $issue_id): bool
    {
        $path = $this->clean_issue_path($path);
        if ($path === '') {
            return false;
        }

        if (!Media_Cleaner_Trash::move_in($path, (string) $issue_id)) {
            return false;
        }

        $this->mark_deleted($issue_id, true);

        return true;
    }

    private function restore_file(string $path, int $issue_id): bool
    {
        $path = $this->clean_issue_path($path);
        if ($path === '') {
            return false;
        }

        if (!Media_Cleaner_Trash::move_out($path, (string) $issue_id)) {
            return false;
        }

        $this->mark_deleted($issue_id, false);

        return true;
    }

    /* ---------------------------------------------------------------------
     * Shared
     * ------------------------------------------------------------------- */

    private function mark_deleted(int $issue_id, bool $deleted): void
    {
        global $wpdb;
        $wpdb->update(
            Media_Cleaner_Database::scan_table(),
            ['deleted' => $deleted ? 1 : 0, 'ignored' => 0, 'time' => current_time('mysql')],
            ['id' => $issue_id],
            ['%d', '%d', '%s'],
            ['%d']
        );
    }

    /**
     * Normalise a filesystem-issue (type 0) path.
     *
     * Only type-0 rows reach here, and their `path` is the exact uploads-
     * relative filename written by the scanner — never the " (+ N thumbnails)"
     * label, which is applied to media (type 1) rows whose filesystem
     * operations key off the attachment ID, not this column. So no annotation
     * stripping is done, and a real filename containing "(+" is preserved.
     */
    private function clean_issue_path(string $path): string
    {
        return trim($path);
    }
}
