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
     * Build the WHERE clause shared by the list, count, and id queries so they
     * can never drift out of sync.
     *
     * @param array<int,mixed> $params Collected placeholder values (by ref).
     */
    private function build_where(string $status, string $search, array &$params): string
    {
        global $wpdb;

        $where = [];
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

        return 'WHERE ' . implode(' AND ', $where);
    }

    /**
     * Issue IDs matching a status (and optional search), capped at $limit.
     * Used by the "trash all" / "ignore all" batched sweeps.
     *
     * @return array<int,int>
     */
    public function get_issue_ids(string $status, string $search, int $limit): array
    {
        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();

        $params = [];
        $where  = $this->build_where($status, $search, $params);

        $limit    = max(1, min(500, $limit));
        $params[] = $limit;

        $sql = "SELECT id FROM {$table} {$where} ORDER BY id ASC LIMIT %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col($wpdb->prepare($sql, $params));

        return array_map('intval', $ids);
    }

    /**
     * @return array{items:array<int,object>,total:int}
     */
    public function get_issues(string $status = 'active', int $page = 1, int $per_page = 25, string $search = ''): array
    {
        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();

        $params    = [];
        $where_sql = $this->build_where($status, $search, $params);

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

        // A folder already named after this row holds files left behind by an
        // older version. Re-home them first so the two can never mix.
        if (Media_Cleaner_Trash::bucket_has_files((string) $issue_id)) {
            $this->adopt_stranded();
            if (Media_Cleaner_Trash::bucket_has_files((string) $issue_id)) {
                return false;
            }
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

            if ((int) $issue->deleted === 1) {
                // Only delete the attachment while it is still hidden in the
                // trash. If it is a live attachment again (restored through
                // another result), this row is stale: drop the row, keep the
                // media.
                if ($post_id > 0 && get_post_type($post_id) === Media_Cleaner_Trash::SENTINEL_POST_TYPE) {
                    $paths = $this->core->get_paths_from_attachment($post_id);
                    $this->delete_attachment_keeping_uploads($post_id);
                    foreach ($paths as $path) {
                        Media_Cleaner_Trash::delete_from_trash($path, (string) $issue_id);
                    }
                }
                Media_Cleaner_Trash::prune_bucket((string) $issue_id);
            } elseif ($post_id > 0 && get_post_type($post_id) === 'attachment') {
                // Skip-trash mode: a normal WordPress delete, files included.
                wp_delete_attachment($post_id, true);
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

        // Already in the trash through another result (e.g. listed as both
        // Unused and Duplicate): this row is redundant, so drop it.
        if (get_post_type($post_id) === Media_Cleaner_Trash::SENTINEL_POST_TYPE) {
            $this->delete_row($issue_id);

            return true;
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

        // One attachment, one trash entry: other open results for the same
        // attachment are now meaningless.
        $this->delete_other_rows($post_id, $issue_id, 0);

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

        // Any other trash entry for this attachment (left by older versions)
        // must not be able to delete it later.
        $this->delete_other_rows($post_id, $issue_id, 1);

        return true;
    }

    /**
     * Delete an attachment's database records without letting WordPress
     * delete files at its uploads paths. Used for trashed media, whose files
     * live in the trash: a new upload may since have taken the old path, and
     * that file belongs to someone else.
     */
    private function delete_attachment_keeping_uploads(int $post_id): void
    {
        wp_update_post(['ID' => $post_id, 'post_type' => 'attachment']);

        $block = static function () {
            return '';
        };
        add_filter('wp_delete_file', $block, PHP_INT_MAX);
        wp_delete_attachment($post_id, true);
        remove_filter('wp_delete_file', $block, PHP_INT_MAX);
    }

    private function delete_row(int $issue_id): void
    {
        global $wpdb;
        $wpdb->delete(Media_Cleaner_Database::scan_table(), ['id' => $issue_id], ['%d']);
    }

    /**
     * Remove the other media rows for one attachment.
     *
     * @param int $deleted Which rows to remove: 0 = open results, 1 = trash entries.
     */
    private function delete_other_rows(int $post_id, int $keep_issue_id, int $deleted): void
    {
        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE type = %d AND post_id = %d AND id <> %d AND deleted = %d AND ignored = 0",
            self::TYPE_MEDIA,
            $post_id,
            $keep_issue_id,
            $deleted
        ));
    }

    /* ---------------------------------------------------------------------
     * Recovery of items whose results older versions lost
     * ------------------------------------------------------------------- */

    /**
     * Give every trashed item without a result row a new Trash entry.
     *
     * Versions before 1.55.1 emptied the results table on every scan, which
     * also removed the Trash entries: the files stayed in the trash folder and
     * trashed attachments stayed hidden, with nothing in the plugin pointing at
     * them. This finds them again, so they can be restored or deleted from the
     * Trash tab. Each one moves to a folder named after its new row, so old
     * and new folder numbers can never clash.
     *
     * @return int Number of entries recreated.
     */
    public function adopt_stranded(): int
    {
        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $trashed_ids = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$table} WHERE deleted = 1"));
        $trashed     = array_flip($trashed_ids);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $owned_posts = array_flip(array_map('intval', (array) $wpdb->get_col(
            $wpdb->prepare("SELECT post_id FROM {$table} WHERE deleted = 1 AND type = %d", self::TYPE_MEDIA)
        )));

        // Hidden attachments with no Trash entry, keyed by each file they own.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $sentinels = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
            Media_Cleaner_Trash::SENTINEL_POST_TYPE
        )));
        $lost_posts = [];
        $by_path    = [];
        foreach ($sentinels as $post_id) {
            if (isset($owned_posts[$post_id])) {
                continue;
            }
            $lost_posts[$post_id] = $this->core->get_paths_from_attachment($post_id);
            foreach ($lost_posts[$post_id] as $path) {
                $by_path[$path] = $post_id;
            }
        }

        $created = 0;
        $placed  = [];

        foreach (Media_Cleaner_Trash::buckets() as $bucket) {
            if (isset($trashed[$bucket])) {
                continue;
            }

            $files = Media_Cleaner_Trash::files_in_bucket((string) $bucket);
            if (empty($files)) {
                Media_Cleaner_Trash::prune_bucket((string) $bucket);
                continue;
            }

            $done = [];
            foreach ($files as $file) {
                if (isset($done[$file])) {
                    continue;
                }

                if (isset($by_path[$file]) && !isset($placed[$by_path[$file]])) {
                    $post_id = $by_path[$file];
                    $new_id  = $this->insert_trash_row(self::TYPE_MEDIA, $this->core->clean_uploaded_filename((string) get_attached_file($post_id)), $post_id, $lost_posts[$post_id], (string) $bucket);
                    if ($new_id <= 0) {
                        continue;
                    }
                    foreach ($lost_posts[$post_id] as $path) {
                        if (in_array($path, $files, true)) {
                            Media_Cleaner_Trash::move_between($path, (string) $bucket, (string) $new_id);
                            $done[$path] = true;
                        }
                    }
                    $placed[$post_id] = true;
                    $created++;
                    continue;
                }

                $new_id = $this->insert_trash_row(self::TYPE_FILE, $file, null, [$file], (string) $bucket);
                if ($new_id > 0 && Media_Cleaner_Trash::move_between($file, (string) $bucket, (string) $new_id)) {
                    $created++;
                } elseif ($new_id > 0) {
                    $this->delete_row($new_id);
                }
                $done[$file] = true;
            }
        }

        // Hidden attachments whose files were not found anywhere still get an
        // entry, so they can at least be brought back into the library.
        foreach ($lost_posts as $post_id => $paths) {
            if (isset($placed[$post_id])) {
                continue;
            }
            if ($this->insert_trash_row(self::TYPE_MEDIA, $paths[0] ?? ('#' . $post_id), $post_id, $paths) > 0) {
                $created++;
            }
        }

        $buckets = Media_Cleaner_Trash::buckets();
        Media_Cleaner_Database::ensure_next_id_above($buckets ? (int) max($buckets) : 0);

        return $created;
    }

    /**
     * @param array<int,string> $paths  Files the entry owns (for its size).
     * @param string|null       $bucket Trash folder the files sit in now.
     */
    private function insert_trash_row(int $type, string $path, ?int $post_id, array $paths, ?string $bucket = null): int
    {
        global $wpdb;

        $size = 0;
        if ($bucket !== null) {
            foreach ($paths as $rel) {
                $candidate = trailingslashit(Media_Cleaner_Trash::dir()) . absint($bucket) . '/' . ltrim($rel, '/');
                if (file_exists($candidate)) {
                    $size += (int) filesize($candidate);
                }
            }
        }

        $buckets = Media_Cleaner_Trash::buckets();
        Media_Cleaner_Database::ensure_next_id_above($buckets ? (int) max($buckets) : 0);

        $ok = $wpdb->insert(
            Media_Cleaner_Database::scan_table(),
            [
                'time'    => current_time('mysql'),
                'type'    => $type,
                'post_id' => $post_id,
                'path'    => $path,
                'size'    => $size,
                'deleted' => 1,
                'issue'   => self::TYPE_MEDIA === $type ? Media_Cleaner_Core::ISSUE_NO_CONTENT : Media_Cleaner_Core::ISSUE_ORPHAN_FILE,
            ],
            ['%s', '%d', '%d', '%s', '%d', '%d', '%s']
        );

        return $ok ? (int) $wpdb->insert_id : 0;
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
