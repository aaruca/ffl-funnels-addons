<?php
/**
 * Media Cleaner — filesystem trash mechanics.
 *
 * Trashing is reversible by design. Files move to
 * uploads/ffla-media-trash/<issue-id>/<relative-path>; attachments additionally
 * have their post_type flipped to a sentinel so they vanish from the Media
 * Library without the row (or its metadata) being destroyed. Permanent deletion
 * is a deliberate second step, never a side effect of the first.
 *
 * Every trashed file lives under a per-issue bucket. That is what makes restore
 * and permanent-delete safe: an uploads-relative path can be reused over time
 * (delete a file, upload a new one to the same name), so a flat trash keyed only
 * by that path would cross-wire two different files. The issue-id bucket is
 * unique, so no collision is possible and no de-duplication guesswork is needed.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Trash
{
    const TRASH_DIRNAME = 'ffla-media-trash';

    /** Hidden attachments wear this post_type while trashed. */
    const SENTINEL_POST_TYPE = 'ffla_mclean_trash';

    public static function dir(): string
    {
        $uploads = wp_upload_dir();

        return trailingslashit($uploads['basedir']) . self::TRASH_DIRNAME;
    }

    /**
     * Absolute path to a bucket's root inside the trash.
     */
    private static function bucket_dir(string $bucket): string
    {
        return trailingslashit(self::dir()) . self::sanitize_bucket($bucket);
    }

    /**
     * Create the trash dir and make it non-browsable and non-served. It lives
     * inside uploads, so it must not become a public mirror of the files a shop
     * just decided were sensitive enough to remove.
     */
    public static function ensure_dir(): bool
    {
        $dir = self::dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        $htaccess = trailingslashit($dir) . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }

        $index = trailingslashit($dir) . 'index.php';
        if (!file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }

        return true;
    }

    /**
     * Move an uploads-relative file into a bucket in the trash.
     *
     * Returns true when the source is gone from uploads (including the benign
     * case where it was already missing).
     */
    public static function move_in(string $relative_path, string $bucket): bool
    {
        $relative_path = self::normalize($relative_path);
        if ($relative_path === '' || !self::ensure_dir()) {
            return false;
        }

        $uploads = wp_upload_dir();
        $source  = trailingslashit($uploads['basedir']) . $relative_path;
        $target  = trailingslashit(self::bucket_dir($bucket)) . $relative_path;

        if (!file_exists($source)) {
            // Nothing to move — a broken/orphan entry. Success so the issue can
            // still be cleared.
            return true;
        }

        $target_dir = dirname($target);
        if (!is_dir($target_dir) && !wp_mkdir_p($target_dir)) {
            return false;
        }

        return @rename($source, $target);
    }

    /**
     * Move a file back out of its bucket to its original uploads location.
     */
    public static function move_out(string $relative_path, string $bucket): bool
    {
        $relative_path = self::normalize($relative_path);
        if ($relative_path === '') {
            return false;
        }

        $uploads = wp_upload_dir();
        $source  = trailingslashit(self::bucket_dir($bucket)) . $relative_path;
        $target  = trailingslashit($uploads['basedir']) . $relative_path;

        if (!file_exists($source)) {
            // Already restored, or an orphan with no file. Non-fatal.
            return true;
        }

        // Refuse to clobber a live file that now occupies the target — that file
        // belongs to a different, current attachment.
        if (file_exists($target)) {
            return false;
        }

        $target_dir = dirname($target);
        if (!is_dir($target_dir) && !wp_mkdir_p($target_dir)) {
            return false;
        }

        return @rename($source, $target);
    }

    /**
     * Permanently remove a single file from a bucket.
     */
    public static function delete_from_trash(string $relative_path, string $bucket): bool
    {
        $relative_path = self::normalize($relative_path);
        if ($relative_path === '') {
            return false;
        }

        $path = trailingslashit(self::bucket_dir($bucket)) . $relative_path;
        if (!file_exists($path)) {
            return true;
        }

        $ok = @unlink($path);
        if ($ok) {
            self::prune_empty_dirs(dirname($path));
        }

        return $ok;
    }

    /**
     * Remove an entire bucket (used when an issue is permanently deleted).
     * Only ever touches paths under the trash root.
     */
    public static function prune_bucket(string $bucket): void
    {
        $dir = self::bucket_dir($bucket);
        if (is_dir($dir) && strpos($dir, self::dir()) === 0 && $dir !== self::dir()) {
            self::delete_tree($dir);
        }
    }

    /* ---------------------------------------------------------------------
     * Internals
     * ------------------------------------------------------------------- */

    private static function sanitize_bucket(string $bucket): string
    {
        // Buckets are issue IDs — force to a bare integer so a bucket can never
        // contain a separator or traversal sequence.
        return (string) absint($bucket);
    }

    /**
     * Guard against path traversal: a stored path must stay inside the uploads
     * tree. Rejects anything containing "..".
     */
    private static function normalize(string $relative_path): string
    {
        $relative_path = str_replace('\\', '/', $relative_path);
        $relative_path = ltrim($relative_path, '/');

        if ($relative_path === '' || strpos($relative_path, '..') !== false) {
            return '';
        }

        return $relative_path;
    }

    private static function prune_empty_dirs(string $dir): void
    {
        $trash_root = self::dir();
        $dir = rtrim(str_replace('\\', '/', $dir), '/');

        while ($dir !== '' && strpos($dir, $trash_root) === 0 && $dir !== $trash_root) {
            if (!is_dir($dir)) {
                break;
            }
            $entries = @scandir($dir);
            if ($entries === false || count(array_diff($entries, ['.', '..'])) > 0) {
                break;
            }
            @rmdir($dir);
            $dir = dirname($dir);
        }
    }

    /**
     * Delete a directory and everything under it. Caller guarantees the path is
     * within the trash root.
     */
    private static function delete_tree(string $dir): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $path = trailingslashit($dir) . $entry;
            if (is_dir($path)) {
                self::delete_tree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
