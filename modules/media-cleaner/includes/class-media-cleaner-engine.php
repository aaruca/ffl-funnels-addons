<?php
/**
 * Media Cleaner — the scan engine.
 *
 * Every method here processes one bounded batch and returns quickly, so the
 * AJAX/CLI driver can page through a large library without tripping the PHP
 * time limit. Two independent passes feed one decision:
 *
 *   1. Reference passes walk content, post meta, the theme, widgets, and (for a
 *      filesystem scan) the library, recording every media ID/URL in use.
 *   2. Check passes walk the library (or the uploads folder) and flag anything
 *      absent from the references built in pass 1.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Engine
{
    /** @var Media_Cleaner_Core */
    private $core;

    public function __construct(Media_Cleaner_Core $core)
    {
        $this->core = $core;
    }

    /* =====================================================================
     * Setup
     * ================================================================== */

    /**
     * Wipe both tables and prime the reference cache for a new run.
     */
    public function reset(): void
    {
        Media_Cleaner_Database::truncate_scan();
        $this->core->reset_references();
    }

    /* =====================================================================
     * Pass 1 — references from content
     * ================================================================== */

    public function count_posts(): int
    {
        global $wpdb;
        $types  = $this->post_types_sql();
        $status = $this->statuses_sql();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN ({$types}) AND post_status IN ({$status})"
        );
    }

    /**
     * @return array<int,int>
     */
    private function get_posts(int $offset, int $limit): array
    {
        global $wpdb;
        $types  = $this->post_types_sql();
        $status = $this->statuses_sql();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ({$types}) AND post_status IN ({$status})
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));

        return array_map('intval', $ids);
    }

    /**
     * Process one batch of posts for references.
     */
    public function refs_from_content_batch(int $offset, int $limit): void
    {
        $this->core->current_method = Media_Cleaner_Core::METHOD_CONTENT;

        // On the first batch, collect the site-wide references once.
        if ($offset === 0) {
            do_action('ffla_mclean_scan_once');
            $this->scan_widgets();
        }

        foreach ($this->get_posts($offset, $limit) as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            $html = (string) $post->post_content;

            // Builders keep their markup outside post_content. Let their parsers
            // append it so the generic URL sweep sees it too.
            $html = (string) apply_filters('ffla_mclean_post_html', $html, $post_id);

            do_action('ffla_mclean_scan_postmeta', $post_id);
            do_action('ffla_mclean_scan_post', $html, $post_id);
        }

        $this->core->write_references();
    }

    private function scan_widgets(): void
    {
        global $wp_registered_widgets;
        if (!is_array($wp_registered_widgets)) {
            return;
        }

        foreach ($wp_registered_widgets as $widget) {
            do_action('ffla_mclean_scan_widget', $widget);
        }

        $this->core->write_references();
    }

    /* =====================================================================
     * Pass 1b — references from the library (filesystem scan only)
     * ================================================================== */

    public function count_media(): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
        );
    }

    /**
     * @return array<int,int>
     */
    private function get_media(int $offset, int $limit): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID ASC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));

        return array_map('intval', $ids);
    }

    /**
     * Register every attachment's files as "in use by the library", so a
     * filesystem scan only flags files that belong to no attachment at all.
     */
    public function refs_from_library_batch(int $offset, int $limit): void
    {
        $this->core->current_method = Media_Cleaner_Core::METHOD_FILES;

        foreach ($this->get_media($offset, $limit) as $media_id) {
            $paths = $this->core->get_paths_from_attachment($media_id);
            if (!empty($paths)) {
                $this->core->add_reference_url($paths, 'MEDIA LIBRARY');
            }
        }

        $this->core->write_references();
    }

    /* =====================================================================
     * Pass 2 — check the library
     * ================================================================== */

    /**
     * Flag attachments that are referenced nowhere (or whose file is missing).
     */
    public function check_media_batch(int $offset, int $limit): void
    {
        $this->core->current_method = Media_Cleaner_Core::METHOD_MEDIA;
        $scan_content = '1' === Media_Cleaner_Core::get_setting('scan_content', '1');

        foreach ($this->get_media($offset, $limit) as $media_id) {
            if ($this->core->is_media_ignored($media_id)) {
                continue;
            }

            $fullpath  = get_attached_file($media_id);
            $is_broken = empty($fullpath) || !file_exists($fullpath);

            // Broken-only scan: only missing files matter.
            if (!$scan_content) {
                if ($is_broken) {
                    $this->core->add_issue(Media_Cleaner_Core::ISSUE_ORPHAN, 1, $this->media_label($media_id, $fullpath), $media_id, 0);
                }
                continue;
            }

            // ID reference (wp-image-N, gallery ids, ACF id fields, …).
            if ($this->core->reference_exists(null, $media_id) !== false) {
                continue;
            }

            // Any file path (main / original / any size) referenced anywhere.
            $paths = $this->core->get_paths_from_attachment($media_id);
            $used  = false;
            $size  = 0;
            $count = 0;

            foreach ($paths as $path) {
                if (!$used && $this->core->reference_exists($path, null) !== false) {
                    $used = true;
                }
                $filepath = trailingslashit($this->core->upload_path) . $path;
                if (file_exists($filepath)) {
                    $size += (int) filesize($filepath);
                }
                $count++;
            }

            if ($used) {
                continue;
            }

            $issue = $is_broken ? Media_Cleaner_Core::ISSUE_ORPHAN : Media_Cleaner_Core::ISSUE_NO_CONTENT;
            $this->core->add_issue($issue, 1, $this->media_label($media_id, $fullpath, $count), $media_id, $size);
        }
    }

    /**
     * A human-readable path label carrying the thumbnail count.
     */
    private function media_label(int $media_id, $fullpath, int $count = 0): string
    {
        if (empty($fullpath)) {
            $file = get_post_meta($media_id, '_wp_attached_file', true);
            $label = $file ? (string) $file : ('#' . $media_id);
        } else {
            $label = $this->core->clean_uploaded_filename($fullpath);
        }

        if ($count > 1) {
            $thumbs = $count - 1;
            $label .= sprintf(' (+ %d %s)', $thumbs, _n('thumbnail', 'thumbnails', $thumbs, 'ffl-funnels-addons'));
        }

        return $label;
    }

    /* =====================================================================
     * Pass 2 — check the filesystem
     * ================================================================== */

    /**
     * All files under uploads (minus the trash dir and guard files), as
     * uploads-relative paths. Materialised once per scan into an option so the
     * batched checker can page through a stable list.
     *
     * @return array<int,string>
     */
    public function build_file_list(): int
    {
        $files    = [];
        $base     = $this->core->upload_path;
        $trash    = Media_Cleaner_Trash::TRASH_DIRNAME;

        if (!is_dir($base)) {
            update_option('ffla_mclean_file_list', [], false);
            return 0;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = $this->core->clean_uploaded_filename($file->getPathname());

            // Skip the trash tree and hidden/guard files.
            if (strpos($rel, $trash . '/') === 0 || $rel === $trash) {
                continue;
            }
            $basename = basename($rel);
            if ($basename === '' || $basename[0] === '.' || $basename === 'index.php') {
                continue;
            }

            $files[] = $rel;
        }

        update_option('ffla_mclean_file_list', $files, false);

        return count($files);
    }

    public function check_files_batch(int $offset, int $limit): void
    {
        $files = get_option('ffla_mclean_file_list', []);
        if (!is_array($files) || empty($files)) {
            return;
        }

        $slice = array_slice($files, $offset, $limit);
        foreach ($slice as $rel) {
            // Referenced directly, or via its resolution-stripped parent.
            if ($this->core->reference_exists($rel, null) !== false) {
                continue;
            }
            $parent = $this->core->strip_resolution($rel);
            if ($parent !== null && $parent !== $rel && $this->core->reference_exists($parent, null) !== false) {
                continue;
            }

            $full = trailingslashit($this->core->upload_path) . $rel;
            $size = file_exists($full) ? (int) filesize($full) : 0;

            $this->core->add_issue(Media_Cleaner_Core::ISSUE_ORPHAN_FILE, 0, $rel, null, $size);
        }
    }

    public function clear_file_list(): void
    {
        delete_option('ffla_mclean_file_list');
    }

    /* =====================================================================
     * Duplicates
     * ================================================================== */

    /**
     * Single-pass duplicate detection: the first attachment carrying a given
     * content hash is the keeper; later matches are flagged, pointing back to
     * the keeper via parent_id.
     */
    public function check_duplicates_batch(int $offset, int $limit): void
    {
        global $wpdb;
        $refs = Media_Cleaner_Database::refs_table();
        $max  = 96 * 1024 * 1024; // Skip hashing very large files.

        foreach ($this->get_media($offset, $limit) as $media_id) {
            $fullpath = get_attached_file($media_id);
            if (empty($fullpath) || !file_exists($fullpath)) {
                continue;
            }
            if ((int) filesize($fullpath) > $max) {
                continue;
            }

            $hash = @md5_file($fullpath);
            if ($hash === false) {
                continue;
            }

            // Has this hash been seen (by a different attachment)?
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $keeper = $wpdb->get_var($wpdb->prepare(
                "SELECT origin FROM {$refs} WHERE origin_type = 'HASHDUP' AND media_url = %s LIMIT 1",
                $hash
            ));

            if ($keeper !== null && (int) $keeper !== $media_id) {
                $size = (int) filesize($fullpath);
                $this->core->add_issue(Media_Cleaner_Core::ISSUE_DUPLICATE, 1, $this->media_label($media_id, $fullpath), $media_id, $size, (int) $keeper);
                continue;
            }

            // Record this attachment as the keeper for its hash.
            $wpdb->insert(
                $refs,
                [
                    'media_id'    => null,
                    'media_url'   => $hash,
                    'origin_type' => 'HASHDUP',
                    'origin'      => $media_id,
                    'ref_hash'    => md5('hashdup|' . $hash),
                ],
                ['%d', '%s', '%s', '%d', '%s']
            );
        }
    }

    /* =====================================================================
     * SQL helpers
     * ================================================================== */

    private function post_types_sql(): string
    {
        $types = get_post_types(['public' => true], 'names');

        // Builder templates are not "public" but hold layout that references
        // media (Bricks headers/footers/templates, reusable blocks, …).
        foreach (['bricks_template', 'wp_template', 'wp_template_part', 'wp_block', 'elementor_library'] as $extra) {
            $types[$extra] = $extra;
        }

        // Attachments are checked separately, never as a reference source.
        unset($types['attachment']);

        $types = apply_filters('ffla_mclean_scanned_post_types', array_values($types));

        global $wpdb;
        $escaped = array_map(static function ($t) use ($wpdb) {
            return $wpdb->prepare('%s', $t);
        }, $types);

        return implode(', ', $escaped) ?: "''";
    }

    private function statuses_sql(): string
    {
        // Anything a visitor or editor could still surface. Excludes only
        // auto-draft and trash, where a referenced image is genuinely dead.
        $statuses = apply_filters('ffla_mclean_scanned_statuses', [
            'publish', 'private', 'draft', 'pending', 'future', 'inherit',
        ]);

        global $wpdb;
        $escaped = array_map(static function ($s) use ($wpdb) {
            return $wpdb->prepare('%s', $s);
        }, $statuses);

        return implode(', ', $escaped) ?: "''";
    }
}
