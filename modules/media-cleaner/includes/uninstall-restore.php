<?php
/**
 * Media Cleaner — put everything in the trash back before the plugin is
 * deleted.
 *
 * Loaded by uninstall.php only, without the module's classes, so it depends on
 * WordPress functions alone. Without this, deleting the plugin would leave
 * trashed attachments hidden (post type `ffla_mclean_trash`) and their files in
 * the trash folder, with nothing left to bring them back.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @return array{restored:int,left:int}
 */
function ffla_mclean_uninstall_restore(): array
{
    global $wpdb;

    $uploads = wp_upload_dir();
    $base    = trailingslashit(str_replace('\\', '/', $uploads['basedir']));
    $name    = get_option('ffla_mclean_trash_dirname', '');
    if (!is_string($name) || !preg_match('/^ffla-media-trash(-[a-z0-9]{6,32})?$/', $name)) {
        $name = 'ffla-media-trash';
    }
    $trash = $base . $name;

    $restored = 0;
    $left     = 0;

    $clean = static function (string $rel): string {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');

        return (strpos($rel, '..') !== false) ? '' : $rel;
    };

    // Move one file from a bucket back to uploads. True when it is back (or
    // was never in the trash); false when it cannot go back.
    $move_back = static function (int $bucket, string $rel) use ($base, $trash, $clean): bool {
        $rel = $clean($rel);
        if ($rel === '') {
            return true;
        }
        $source = $trash . '/' . $bucket . '/' . $rel;
        $target = $base . $rel;
        if (!file_exists($source)) {
            return true;
        }
        if (file_exists($target)) {
            return false; // A newer file took this name: leave the old one in the trash.
        }
        if (!is_dir(dirname($target)) && !wp_mkdir_p(dirname($target))) {
            return false;
        }

        return @rename($source, $target);
    };

    $attachment_paths = static function (int $post_id) use ($base): array {
        $file = get_post_meta($post_id, '_wp_attached_file', true);
        if (!is_string($file) || $file === '') {
            return [];
        }
        $file   = ltrim(str_replace('\\', '/', $file), '/');
        if (strpos($file, $base) === 0) {
            $file = substr($file, strlen($base));
        }
        $paths  = [$file];
        $subdir = dirname($file) === '.' ? '' : trailingslashit(dirname($file));
        $meta   = get_post_meta($post_id, '_wp_attachment_metadata', true);
        if (is_array($meta)) {
            if (!empty($meta['original_image'])) {
                $paths[] = $subdir . $meta['original_image'];
            }
            foreach ((array) ($meta['sizes'] ?? []) as $size) {
                if (is_array($size) && !empty($size['file'])) {
                    $paths[] = $subdir . $size['file'];
                }
            }
        }
        $backups = get_post_meta($post_id, '_wp_attachment_backup_sizes', true);
        if (is_array($backups)) {
            foreach ($backups as $backup) {
                if (is_array($backup) && !empty($backup['file'])) {
                    $paths[] = $subdir . basename((string) $backup['file']);
                }
            }
        }

        return array_values(array_unique($paths));
    };

    $unhide = static function (int $post_id) use ($wpdb): void {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update($wpdb->posts, ['post_type' => 'attachment'], ['ID' => $post_id, 'post_type' => 'ffla_mclean_trash']);
        clean_post_cache($post_id);
    };

    $handled_posts = [];
    $table         = $wpdb->prefix . 'ffla_mclean_scan';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results("SELECT id, type, post_id, path FROM {$table} WHERE deleted = 1");
        foreach ((array) $rows as $row) {
            $ok = true;
            if ((int) $row->type === 1 && (int) $row->post_id > 0) {
                $post_id = (int) $row->post_id;
                foreach ($attachment_paths($post_id) as $rel) {
                    $ok = $move_back((int) $row->id, $rel) && $ok;
                }
                $unhide($post_id);
                $handled_posts[$post_id] = true;
            } elseif ((int) $row->type === 0) {
                $ok = $move_back((int) $row->id, (string) $row->path);
            }
            $ok ? $restored++ : $left++;
        }
    }

    // Hidden attachments with no Trash entry (lost by older versions): look
    // for their files in every bucket, then bring them back into the library.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $hidden  = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'ffla_mclean_trash'");
    $buckets = [];
    if (is_dir($trash)) {
        foreach ((array) @scandir($trash) as $entry) {
            if (is_string($entry) && ctype_digit($entry)) {
                $buckets[] = (int) $entry;
            }
        }
    }
    foreach ((array) $hidden as $post_id) {
        $post_id = (int) $post_id;
        if (isset($handled_posts[$post_id])) {
            continue;
        }
        $ok = true;
        foreach ($attachment_paths($post_id) as $rel) {
            foreach ($buckets as $bucket) {
                if (file_exists($trash . '/' . $bucket . '/' . $rel)) {
                    $ok = $move_back($bucket, $rel) && $ok;
                    break;
                }
            }
        }
        $unhide($post_id);
        $ok ? $restored++ : $left++;
    }

    // Remove the trash folder when only empty folders and guard files remain.
    if (is_dir($trash)) {
        $has_files = false;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($trash, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            if ($item->isFile() && !in_array($item->getFilename(), ['.htaccess', 'index.php', 'web.config'], true)) {
                $has_files = true;
                break;
            }
        }
        if (!$has_files) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($trash, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($trash);
        }
    }

    return ['restored' => $restored, 'left' => $left];
}
