<?php
/**
 * Media Cleaner — core: settings, reference cache, and the matching primitives.
 *
 * A media file is "in use" when its attachment ID or any of its file paths
 * (resolution-stripped) is present in the reference cache built by the parsers.
 * All URLs and paths are normalised to be relative to the uploads directory
 * and scheme/domain agnostic, so `https://…`, `http://…`, and `//…` all match.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Core
{
    const OPTION = 'ffla_media_cleaner_settings';

    /** Issue codenames. */
    const ISSUE_NO_CONTENT  = 'NO_CONTENT';   // Attachment referenced nowhere.
    const ISSUE_ORPHAN      = 'ORPHAN_MEDIA'; // Attachment row exists, file missing.
    const ISSUE_ORPHAN_FILE = 'ORPHAN_FILE';  // File on disk, not in the library.
    const ISSUE_DUPLICATE   = 'DUPLICATE';    // Byte-identical to another file.

    /** Reference origin currently being scanned. */
    const METHOD_CONTENT = 'content';
    const METHOD_MEDIA   = 'media';
    const METHOD_FILES   = 'files';

    /** @var string Absolute path to the uploads dir, no trailing slash. */
    public $upload_path;

    /** @var string Uploads path relative to the host, e.g. /wp-content/uploads. */
    public $upload_url;

    /** @var string Current scan method (one of the METHOD_* constants). */
    public $current_method = self::METHOD_CONTENT;

    /** @var array<int,array{id:?int,url:?string,type:string,origin:?int}> */
    private $refcache = [];

    /** @var array<string,mixed>|null */
    private static $settings_memo = null;

    public function __construct()
    {
        $uploads = wp_upload_dir();
        $this->upload_path = untrailingslashit($uploads['basedir']);

        $parsed = wp_parse_url($uploads['baseurl']);
        // Path portion only ("/wp-content/uploads") so matching ignores the
        // scheme and host — builders store a mix of all three.
        $this->upload_url = isset($parsed['path']) ? untrailingslashit($parsed['path']) : '';
    }

    /* =====================================================================
     * Settings
     * ================================================================== */

    public static function get_default_settings(): array
    {
        return [
            'scan_media_library'    => '1',
            'scan_filesystem'       => '0',
            'scan_content'          => '1',
            'detect_duplicates'     => '0',
            'skip_trash'            => '0',
            'trash_auto_empty_days' => '0',
            'posts_per_batch'       => '30',
            'media_per_batch'       => '80',
            'files_per_batch'       => '100',
            'refs_buffer'           => '500',
            'debug_logs'            => '0',
        ];
    }

    public static function get_settings(): array
    {
        if (self::$settings_memo !== null) {
            return self::$settings_memo;
        }

        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        self::$settings_memo = wp_parse_args($stored, self::get_default_settings());

        return self::$settings_memo;
    }

    public static function get_setting(string $key, $default = '')
    {
        $settings = self::get_settings();

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public static function flush_settings_memo(): void
    {
        self::$settings_memo = null;
    }

    public function uses_trash(): bool
    {
        return '1' !== self::get_setting('skip_trash', '0');
    }

    /* =====================================================================
     * URL / path normalisation
     * ================================================================== */

    public function is_url($url): bool
    {
        return is_string($url)
            && strlen($url) > 4
            && ('http' === strtolower(substr($url, 0, 4)) || '/' === $url[0]);
    }

    /**
     * Strip a size suffix ("-300x200") so a thumbnail maps to its parent file.
     */
    public function strip_resolution(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        return preg_replace('/[_-]\d+x\d+(?=\.[a-zA-Z0-9]{2,5}$)/', '', $url);
    }

    /**
     * Path prefixes that mark the start of an uploads-relative path inside a
     * URL. Defaults to the site's uploads path; a site that serves media from
     * an offload CDN that rewrites the path (e.g. cdn.example.com/2024/03/…)
     * can register its own anchor here so those references still match.
     *
     * @return array<int,string>
     */
    private function url_anchors(): array
    {
        $anchors = apply_filters('ffla_mclean_url_anchors', [$this->upload_url]);

        return array_values(array_filter(array_unique((array) $anchors), static function ($a) {
            return is_string($a) && $a !== '';
        }));
    }

    /**
     * A URL pointing into uploads → path relative to the uploads dir
     * ("2024/03/pic.jpg"), or null when the URL is not an uploads URL.
     */
    public function clean_url(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        foreach ($this->url_anchors() as $anchor) {
            $pos = strpos($url, $anchor);
            if ($pos !== false) {
                $relative = substr($url, $pos + strlen($anchor));
                $relative = ltrim(urldecode($relative), '/');

                return $relative !== '' ? $relative : null;
            }
        }

        return null;
    }

    /**
     * An absolute filesystem path under uploads → the same relative form.
     */
    public function clean_uploaded_filename(string $fullpath): string
    {
        // Normalise separators so this works when the filesystem hands back
        // Windows-style backslashes but upload_path uses forward slashes.
        $fullpath = str_replace('\\', '/', $fullpath);
        $base     = trailingslashit(str_replace('\\', '/', $this->upload_path));

        if (strpos($fullpath, $base) === 0) {
            $fullpath = substr($fullpath, strlen($base));
        }

        return ltrim($fullpath, '/');
    }

    /* =====================================================================
     * Extraction helpers (used by parsers)
     * ================================================================== */

    /**
     * Every uploads URL mentioned anywhere in a blob of text — HTML, inline
     * CSS, srcset, or builder JSON. Returned as cleaned relative paths.
     *
     * A regex over the uploads path is deliberately used instead of DOM
     * parsing: builder markup is often JSON or escaped, where the DOM approach
     * silently misses references and a missed reference means a wrongly-deleted
     * file.
     *
     * @return array<int,string>
     */
    public function get_urls_from_html(?string $html): array
    {
        if (empty($html)) {
            return [];
        }

        // Builders (Bricks, Elementor, …) store their markup as JSON, where
        // every slash is escaped as "\/". Unescape first, otherwise the uploads
        // anchor never matches and the whole builder-content sweep is inert —
        // and a missed reference means a wrongly-deleted file.
        $html = str_replace('\\/', '/', $html);

        $urls = [];
        foreach ($this->url_anchors() as $anchor) {
            $quoted  = preg_quote($anchor, '#');
            // Anchor on the uploads path, then capture the relative remainder up
            // to the first delimiter (space, quote, paren, query-string, angle
            // bracket). Requires a file extension so bare directory URLs are
            // ignored.
            $pattern = '#' . $quoted . '(/[^\s"\'\\\\)?<>]+\.[a-zA-Z0-9]{2,5})#';

            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $raw) {
                    $clean = ltrim(urldecode($raw), '/');
                    if ($clean !== '') {
                        $urls[] = $clean;
                    }
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Recursively pull attachment IDs and URLs out of arbitrary meta, matching
     * on the given key names (e.g. 'id', 'url', 'background_image').
     *
     * @param mixed             $meta
     * @param array<int,string> $look_for
     * @param array<int,int>    $ids
     * @param array<int,string> $urls
     */
    public function get_from_meta($meta, array $look_for, array &$ids, array &$urls): void
    {
        if (!is_array($meta) && !is_object($meta)) {
            return;
        }

        foreach ($meta as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $this->get_from_meta($value, $look_for, $ids, $urls);
                continue;
            }

            if (!in_array($key, $look_for, true) || $value === '' || $value === null) {
                continue;
            }

            $this->classify_scalar($value, $ids, $urls);
        }
    }

    /**
     * Classify a flat list of scalars into IDs and URLs.
     *
     * @param array<int,mixed>  $values
     * @param array<int,int>    $ids
     * @param array<int,string> $urls
     */
    public function array_to_ids_or_urls(array $values, array &$ids, array &$urls): void
    {
        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                $this->get_from_meta($value, ['id', 'url', 'ids', 'src'], $ids, $urls);
                continue;
            }
            $this->classify_scalar($value, $ids, $urls);
        }
    }

    /**
     * @param array<int,int>    $ids
     * @param array<int,string> $urls
     */
    private function classify_scalar($value, array &$ids, array &$urls): void
    {
        if (is_numeric($value)) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
            return;
        }

        if (!is_string($value)) {
            return;
        }

        if ($this->is_url($value)) {
            $clean = $this->clean_url($value);
            if ($clean !== null) {
                $urls[] = $clean;
            }
            return;
        }

        // "20,13,7" — a comma-joined ID list.
        if (strpos($value, ',') !== false) {
            foreach (explode(',', $value) as $piece) {
                $piece = trim($piece);
                if (is_numeric($piece) && (int) $piece > 0) {
                    $ids[] = (int) $piece;
                }
            }
        }
    }

    /**
     * Pull id/ids and url/link attributes out of every shortcode in the HTML,
     * plus the classic [gallery ids="…"] and wp-image-N body classes.
     *
     * @return array{ids:array<int,int>,urls:array<int,string>}
     */
    public function get_shortcode_and_class_refs(string $html): array
    {
        $ids  = [];
        $urls = [];

        if (preg_match_all('/wp-image-(\d+)/', $html, $m)) {
            foreach ($m[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        if (function_exists('get_shortcode_regex')) {
            $regex = get_shortcode_regex();
            if ($regex && preg_match_all('/' . $regex . '/s', $html, $matches) && !empty($matches[3])) {
                foreach ($matches[3] as $attr_string) {
                    $atts = shortcode_parse_atts($attr_string);
                    if (!is_array($atts)) {
                        continue;
                    }
                    foreach (['id', 'ids', 'image', 'images', 'include'] as $k) {
                        if (!empty($atts[$k])) {
                            foreach (explode(',', (string) $atts[$k]) as $piece) {
                                if (is_numeric(trim($piece))) {
                                    $ids[] = (int) trim($piece);
                                }
                            }
                        }
                    }
                    foreach (['url', 'link', 'src', 'image_url'] as $k) {
                        if (!empty($atts[$k]) && $this->is_url($atts[$k])) {
                            $clean = $this->clean_url($atts[$k]);
                            if ($clean !== null) {
                                $urls[] = $clean;
                            }
                        }
                    }
                }
            }
        }

        return ['ids' => $ids, 'urls' => $urls];
    }

    /* =====================================================================
     * Attachment paths
     * ================================================================== */

    /**
     * Every file path an attachment owns — main file, the untouched original
     * (when WordPress kept one), and each generated size — relative to uploads.
     *
     * @return array<int,string>
     */
    public function get_paths_from_attachment(int $attachment_id): array
    {
        $fullpath = get_attached_file($attachment_id);
        if (empty($fullpath)) {
            return [];
        }

        $paths    = [];
        $mainfile = $this->clean_uploaded_filename($fullpath);
        $paths[]  = $mainfile;

        $subdir = trailingslashit(dirname($mainfile));
        if ($subdir === './' || $subdir === '/') {
            $subdir = '';
        }

        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta)) {
            if (!empty($meta['original_image'])) {
                $paths[] = $subdir . $meta['original_image'];
            }
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $size) {
                    if (!empty($size['file'])) {
                        $paths[] = $subdir . $size['file'];
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * All URLs (main + every size) for a thumbnail — used to keep every size of
     * a featured image alive, since WordPress does not record which size a
     * theme renders.
     *
     * @return array<int,string>
     */
    public function get_thumbnail_urls(int $attachment_id): array
    {
        if ($attachment_id <= 0) {
            return [];
        }

        $paths = $this->get_paths_from_attachment($attachment_id);
        // Paths are already uploads-relative — exactly the reference form.
        return $paths;
    }

    /* =====================================================================
     * Reference cache
     * ================================================================== */

    public function reset_references(): void
    {
        $this->refcache = [];
        Media_Cleaner_Database::truncate_refs();
    }

    /**
     * @param int|array<int,int> $id_or_ids
     */
    public function add_reference_id($id_or_ids, string $type, ?int $origin = null): void
    {
        foreach ((array) $id_or_ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $this->refcache[] = ['id' => $id, 'url' => null, 'type' => $type, 'origin' => $origin];
            }
        }
    }

    /**
     * @param string|array<int,string> $url_or_urls
     */
    public function add_reference_url($url_or_urls, string $type, ?int $origin = null): void
    {
        foreach ((array) $url_or_urls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }

            // A reference must already be uploads-relative. Reject absolute
            // URLs that slipped through un-cleaned.
            if (preg_match('#^(https?:|//|javascript:)#i', $url)) {
                continue;
            }

            // Store the exact path and its resolution-stripped parent, so a
            // reference to any single size keeps the whole attachment alive.
            $this->refcache[] = ['id' => null, 'url' => $url, 'type' => $type, 'origin' => $origin];

            $parent = $this->strip_resolution($url);
            if ($parent !== null && $parent !== $url) {
                $this->refcache[] = ['id' => null, 'url' => $parent, 'type' => $type, 'origin' => $origin];
            }
        }
    }

    /**
     * Flush the in-memory cache to the refs table, de-duplicated by hash.
     */
    public function write_references(): void
    {
        if (empty($this->refcache)) {
            return;
        }

        global $wpdb;
        $table  = Media_Cleaner_Database::refs_table();
        $buffer = max(1, (int) self::get_setting('refs_buffer', '500'));

        $rows = [];
        foreach ($this->refcache as $ref) {
            if ($ref['id'] !== null) {
                $hash = md5('id|' . $ref['id'] . '|' . $ref['type'] . '|' . $ref['origin']);
                $rows[$hash] = [
                    'media_id'    => $ref['id'],
                    'media_url'   => null,
                    'origin_type' => $ref['type'],
                    'origin'      => $ref['origin'],
                    'ref_hash'    => $hash,
                ];
            } elseif ($ref['url'] !== null) {
                $hash = md5('url|' . $ref['url'] . '|' . $ref['type'] . '|' . $ref['origin']);
                $rows[$hash] = [
                    'media_id'    => null,
                    'media_url'   => $ref['url'],
                    'origin_type' => $ref['type'],
                    'origin'      => $ref['origin'],
                    'ref_hash'    => $hash,
                ];
            }
        }

        $this->refcache = [];

        foreach (array_chunk($rows, $buffer, true) as $chunk) {
            $values       = [];
            $placeholders = [];
            foreach ($chunk as $row) {
                $placeholders[] = '(%d, %s, %s, %d, %s)';
                $values[] = $row['media_id'];
                $values[] = $row['media_url'];
                $values[] = $row['origin_type'];
                $values[] = $row['origin'];
                $values[] = $row['ref_hash'];
            }

            // INSERT IGNORE so the ref_hash unique key silently drops dupes.
            $sql = "INSERT IGNORE INTO {$table} (media_id, media_url, origin_type, origin, ref_hash) VALUES "
                . implode(', ', $placeholders);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare($sql, $values));
        }
    }

    /**
     * Is this file path or attachment ID present in the reference cache?
     *
     * @return string|false The origin_type when found, false when not.
     */
    public function reference_exists(?string $file, ?int $media_id)
    {
        global $wpdb;
        $table = Media_Cleaner_Database::refs_table();

        if (!empty($media_id)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $type = $wpdb->get_var($wpdb->prepare(
                "SELECT origin_type FROM {$table} WHERE media_id = %d LIMIT 1",
                $media_id
            ));
            if ($type !== null) {
                return $type;
            }
        }

        if (!empty($file)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $type = $wpdb->get_var($wpdb->prepare(
                "SELECT origin_type FROM {$table} WHERE media_url = %s LIMIT 1",
                $file
            ));
            if ($type !== null) {
                return $type;
            }
        }

        return false;
    }

    /* =====================================================================
     * Issues
     * ================================================================== */

    /**
     * Record an issue in the scan table.
     */
    public function add_issue(string $issue, int $type, ?string $path, ?int $post_id, int $size = 0, ?int $parent_id = null): void
    {
        global $wpdb;

        $wpdb->insert(
            Media_Cleaner_Database::scan_table(),
            [
                'time'      => current_time('mysql'),
                'type'      => $type,
                'post_id'   => $post_id,
                'path'      => $path,
                'size'      => $size,
                'issue'     => $issue,
                'parent_id' => $parent_id,
            ],
            ['%s', '%d', '%d', '%s', '%d', '%s', '%d']
        );
    }

    public function get_issue(int $id): ?object
    {
        global $wpdb;
        $table = Media_Cleaner_Database::scan_table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $issue = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));

        return $issue ?: null;
    }

    /**
     * An attachment is treated as used when it carries the ignore flag, so
     * ignoring a false positive also protects it from the next scan.
     */
    public function is_media_ignored(int $attachment_id): bool
    {
        return (bool) get_post_meta($attachment_id, '_ffla_mclean_ignored', true);
    }

    public function set_media_ignored(int $attachment_id, bool $ignored): void
    {
        if ($ignored) {
            update_post_meta($attachment_id, '_ffla_mclean_ignored', 1);
        } else {
            delete_post_meta($attachment_id, '_ffla_mclean_ignored');
        }
    }
}
