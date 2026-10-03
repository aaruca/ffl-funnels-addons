<?php
/**
 * Customer request attachments.
 *
 * Photos (JPEG, PNG, and iPhone HEIC where the server can convert it), PDFs
 * and short videos (MP4, MOV). The content type must match the extension.
 * Photos are re-encoded through WordPress's image editor (max 2000 px), which
 * shrinks phone pictures and strips their metadata (GPS location, device);
 * HEIC becomes JPEG. Photos and PDFs live in the private `ffla_request_files`
 * table; videos, too big for a database row, live in a private folder in
 * uploads with an unguessable name and file names. Nothing is in the Media
 * Library, and files are streamed only to staff or to the customer who holds
 * the request's access.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Files
{
    const MAX_UPLOAD = 10 * 1048576;   // Photos and PDFs we accept from the browser.
    const MAX_STORED = 4 * 1048576;    // What we keep after processing.
    const MAX_VIDEO = 100 * 1048576;   // Per video, or less when the server accepts less.
    // No limit on files per message or, for staff, per request. Customers have
    // high ceilings per request only so a script cannot fill the database or disk.
    const MAX_PER_REQUEST = 200;
    const MAX_VIDEOS_PER_REQUEST = 10;
    const MAX_EDGE = 2000;

    const TYPES = [
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'application/pdf' => ['pdf'],
    ];

    const VIDEO_TYPES = [
        'video/mp4'       => ['mp4', 'm4v'],
        'video/quicktime' => ['mov'],
    ];

    const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'];

    const DIR_OPTION = 'ffla_requests_files_dir';

    /**
     * Files the server accepts in one upload (PHP `max_file_uploads`, usually
     * 20). Not our limit: past it PHP drops the extra files, so the forms check
     * it first and ask to send the rest with another message.
     */
    public static function per_upload(): int
    {
        $max = (int) ini_get('max_file_uploads');
        return $max > 0 ? $max : 20;
    }

    /** Bytes one upload may carry (PHP `post_max_size`); 0 = no limit. */
    public static function post_limit(): int
    {
        return function_exists('wp_convert_hr_to_bytes') ? (int) wp_convert_hr_to_bytes((string) ini_get('post_max_size')) : 0;
    }

    public static function videos_enabled(): bool
    {
        return FFLA_Customer_Operations_Settings::enabled('requests_videos');
    }

    /** Largest video accepted: 100 MB or the server's own upload limit, whichever is smaller. */
    public static function video_limit(): int
    {
        $server = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
        return $server > 0 ? min(self::MAX_VIDEO, $server) : self::MAX_VIDEO;
    }

    /** Whether this server's image editor can read iPhone HEIC photos (Imagick with libheif). */
    public static function heic_supported(): bool
    {
        static $supported = null;
        if (null === $supported) {
            $supported = function_exists('wp_image_editor_supports') && wp_image_editor_supports(['mime_type' => 'image/heic']);
        }
        return (bool) apply_filters('ffla_requests_heic_supported', $supported);
    }

    /** What the file pickers accept. */
    public static function accept(): string
    {
        $list = ['.jpg', '.jpeg', '.png', '.pdf', '.heic', '.heif', 'image/jpeg', 'image/png', 'image/heic', 'image/heif', 'application/pdf'];
        if (self::videos_enabled()) {
            $list = array_merge($list, ['.mp4', '.m4v', '.mov', 'video/mp4', 'video/quicktime']);
        }
        return implode(',', $list);
    }

    /** Limits and options the browser needs (request-files.js). */
    public static function client_config(): array
    {
        return [
            'perUpload'     => self::per_upload(),
            'postLimit'     => self::post_limit(),
            'maxFileBytes'  => self::MAX_UPLOAD,
            'maxVideoBytes' => self::videos_enabled() ? self::video_limit() : 0,
            'heicServer'    => self::heic_supported(),
            'accept'        => self::accept(),
        ];
    }

    /** Strings for the file picker (request-files.js). */
    public static function picker_strings(): array
    {
        return [
            'drop'          => __('Drag photos here, paste a screenshot, or', 'ffl-funnels-addons'),
            'choose'        => __('Choose files', 'ffl-funnels-addons'),
            /* translators: %s: file name */
            'remove'        => __('Remove %s', 'ffl-funnels-addons'),
            'preparing'     => __('Preparing photos…', 'ffl-funnels-addons'),
            'oneFile'       => __('1 file ready', 'ffl-funnels-addons'),
            /* translators: %d: number of files */
            'files'         => __('%d files ready', 'ffl-funnels-addons'),
            /* translators: %s: file name */
            'heicNoConvert' => __('%s is an iPhone HEIC photo this browser cannot convert. Share it from the iPhone as JPEG, or use Safari.', 'ffl-funnels-addons'),
            /* translators: %d: size in MB */
            'fileTooBig'    => __('Photos and PDFs can be up to %d MB.', 'ffl-funnels-addons'),
            /* translators: %d: size in MB */
            'videoTooBig'   => __('Videos can be up to %d MB. Trim it or send a shorter clip.', 'ffl-funnels-addons'),
            'videosOff'     => __('Videos are not accepted here.', 'ffl-funnels-addons'),
            /* translators: %s: file name */
            'badType'       => __('%s is not a photo, PDF or video we accept.', 'ffl-funnels-addons'),
        ];
    }

    /** Strings for the full-size photo viewer. */
    public static function viewer_strings(): array
    {
        return [
            'viewer'   => __('Photo viewer', 'ffl-funnels-addons'),
            'close'    => __('Close', 'ffl-funnels-addons'),
            'prev'     => __('Previous', 'ffl-funnels-addons'),
            'next'     => __('Next', 'ffl-funnels-addons'),
            'download' => __('Download', 'ffl-funnels-addons'),
            /* translators: 1: position, 2: total */
            'of'       => __('%1$d of %2$d', 'ffl-funnels-addons'),
        ];
    }

    /** Register the shared picker / viewer script and styles. */
    public static function register_assets(string $url, string $dir): void
    {
        wp_register_style('ffla-request-files', $url . 'request-files.css', [], (string) @filemtime($dir . 'request-files.css')); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        wp_register_script('ffla-request-files', $url . 'request-files.js', [], (string) @filemtime($dir . 'request-files.js'), true); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        wp_localize_script('ffla-request-files', 'fflaReqViewer', self::viewer_strings());
    }

    /**
     * Normalize $_FILES['field'] (single or multiple) into a list.
     *
     * @return array<int, array{name:string, tmp_name:string, error:int, size:int}>
     */
    public static function from_request(string $field): array
    {
        if (empty($_FILES[$field])) { // phpcs:ignore WordPress.Security.NonceVerification
            return [];
        }
        return self::normalize($_FILES[$field]); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
    }

    /** One $_FILES entry (single or multiple) as a list. */
    public static function normalize(array $raw): array
    {
        $out = [];
        if (is_array($raw['name'] ?? null)) {
            foreach (array_keys($raw['name']) as $i) {
                if (UPLOAD_ERR_NO_FILE === (int) $raw['error'][$i]) {
                    continue;
                }
                $out[] = ['name' => (string) $raw['name'][$i], 'tmp_name' => (string) $raw['tmp_name'][$i], 'error' => (int) $raw['error'][$i], 'size' => (int) $raw['size'][$i]];
            }
        } elseif (isset($raw['error']) && UPLOAD_ERR_NO_FILE !== (int) $raw['error']) {
            $out[] = ['name' => (string) $raw['name'], 'tmp_name' => (string) $raw['tmp_name'], 'error' => (int) $raw['error'], 'size' => (int) $raw['size']];
        }
        return $out;
    }

    /**
     * Validate every file before storing any.
     *
     * @param array    $files           From from_request() (or temporary files with $uploaded false).
     * @param int|null $existing        Files already on the request, for a customer upload; null for staff (no limit).
     * @param int      $existing_videos Videos already on the request (customers only).
     * @param bool     $uploaded        Files came from this HTTP request's upload.
     * @return array<int, array> Prepared files for store().
     * @throws InvalidArgumentException
     */
    public static function prepare(array $files, ?int $existing = 0, int $existing_videos = 0, bool $uploaded = true): array
    {
        if (null !== $existing && $existing + count($files) > self::MAX_PER_REQUEST) {
            throw new InvalidArgumentException(__('This request already has the maximum number of files.', 'ffl-funnels-addons'));
        }

        $prepared = [];
        $videos = 0;
        foreach ($files as $file) {
            $path = (string) $file['tmp_name'];
            $error = (int) $file['error'];
            if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                /* translators: %s: file name */
                throw new InvalidArgumentException(sprintf(__('%s is larger than this server accepts.', 'ffl-funnels-addons'), sanitize_file_name((string) $file['name'])));
            }
            if (UPLOAD_ERR_OK !== $error || ($uploaded ? !is_uploaded_file($path) : !is_file($path))) {
                throw new InvalidArgumentException(__('A file could not be uploaded. Please try again.', 'ffl-funnels-addons'));
            }
            $item = self::process($path, (string) $file['name']);
            if (!empty($item['video'])) {
                $videos++;
                if (null !== $existing && $existing_videos + $videos > self::MAX_VIDEOS_PER_REQUEST) {
                    /* translators: %d: number of videos */
                    throw new InvalidArgumentException(sprintf(__('A request can hold up to %d videos.', 'ffl-funnels-addons'), self::MAX_VIDEOS_PER_REQUEST));
                }
            }
            $prepared[] = $item;
        }

        return $prepared;
    }

    /**
     * Check one file on disk and return what will be stored: ['name','mime','bytes']
     * for photos and PDFs, ['name','mime','source','size','video' => true] for videos.
     */
    public static function process(string $path, string $original_name): array
    {
        $name = sanitize_file_name($original_name);
        $mime = function_exists('finfo_open') ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($path) : '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = (int) @filesize($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if (isset(self::VIDEO_TYPES[$mime]) || in_array($ext, ['mp4', 'm4v', 'mov'], true)) {
            return self::process_video($path, $name, $mime, $ext, $size);
        }

        if (self::is_heic($path, $mime, $ext)) {
            if ($size > self::MAX_UPLOAD) {
                throw new InvalidArgumentException(__('Each photo or PDF must be up to 10 MB.', 'ffl-funnels-addons'));
            }
            if (!self::heic_supported()) {
                throw new InvalidArgumentException(__('iPhone photos in HEIC format cannot be converted on this server. On the iPhone, share the photo as JPEG (or set Settings → Camera → Formats → Most Compatible), then attach it again.', 'ffl-funnels-addons'));
            }
            $bytes = self::reencode($path, 'image/jpeg');
            if (null === $bytes || strlen($bytes) > self::MAX_STORED) {
                throw new InvalidArgumentException(__('The image could not be processed. Try a smaller JPEG or PNG.', 'ffl-funnels-addons'));
            }
            $base = pathinfo($name, PATHINFO_FILENAME);
            return ['name' => ('' !== $base ? $base : 'photo') . '.jpg', 'mime' => 'image/jpeg', 'bytes' => $bytes];
        }

        if (!isset(self::TYPES[$mime]) || !in_array($ext, self::TYPES[$mime], true)) {
            throw new InvalidArgumentException(self::videos_enabled()
                ? __('Only photos (JPEG, PNG, HEIC), PDF files and MP4 or MOV videos are accepted, and the file must match its extension.', 'ffl-funnels-addons')
                : __('Only JPEG, PNG and PDF files are accepted, and the file must match its extension.', 'ffl-funnels-addons'));
        }
        if ($size > self::MAX_UPLOAD) {
            throw new InvalidArgumentException(__('Each photo or PDF must be up to 10 MB.', 'ffl-funnels-addons'));
        }

        if ('application/pdf' === $mime) {
            $bytes = (string) file_get_contents($path);
            if (strlen($bytes) > self::MAX_STORED || 0 !== strpos($bytes, '%PDF-')) {
                throw new InvalidArgumentException(__('PDF files must be valid and no larger than 4 MB.', 'ffl-funnels-addons'));
            }
            return ['name' => $name, 'mime' => $mime, 'bytes' => $bytes];
        }

        // Re-encode images: resizes large photos and drops metadata.
        $bytes = self::reencode($path, $mime);
        if (null === $bytes || strlen($bytes) > self::MAX_STORED) {
            throw new InvalidArgumentException(__('The image could not be processed. Try a smaller JPEG or PNG.', 'ffl-funnels-addons'));
        }

        return ['name' => $name, 'mime' => $mime, 'bytes' => $bytes];
    }

    private static function process_video(string $path, string $name, string $mime, string $ext, int $size): array
    {
        if (!self::videos_enabled()) {
            throw new InvalidArgumentException(__('Videos are not accepted here. Please attach photos instead.', 'ffl-funnels-addons'));
        }
        // MP4 and QuickTime files start with a size and the "ftyp" box.
        $head = (string) @file_get_contents($path, false, null, 0, 12); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $mime = isset(self::VIDEO_TYPES[$mime]) ? $mime : ('mov' === $ext ? 'video/quicktime' : 'video/mp4');
        if ('ftyp' !== substr($head, 4, 4) || !in_array($ext, self::VIDEO_TYPES[$mime], true)) {
            throw new InvalidArgumentException(__('Videos must be MP4 or MOV files, and the file must match its extension.', 'ffl-funnels-addons'));
        }
        if ($size <= 0 || $size > self::video_limit()) {
            /* translators: %d: size in MB */
            throw new InvalidArgumentException(sprintf(__('Each video must be up to %d MB. Trim it or send a shorter clip.', 'ffl-funnels-addons'), (int) floor(self::video_limit() / 1048576)));
        }
        return ['name' => $name, 'mime' => $mime, 'source' => $path, 'size' => $size, 'video' => true];
    }

    private static function is_heic(string $path, string $mime, string $ext): bool
    {
        if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true) || in_array($ext, ['heic', 'heif'], true)) {
            return true;
        }
        $head = (string) @file_get_contents($path, false, null, 0, 12); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        return 'ftyp' === substr($head, 4, 4) && in_array(substr($head, 8, 4), self::HEIC_BRANDS, true);
    }

    private static function reencode(string $path, string $mime): ?string
    {
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return null;
        }
        if (method_exists($editor, 'maybe_exif_rotate')) {
            $editor->maybe_exif_rotate(); // Upright before the metadata is dropped.
        }
        $size = $editor->get_size();
        if (!empty($size['width']) && ($size['width'] > self::MAX_EDGE || $size['height'] > self::MAX_EDGE)) {
            $editor->resize(self::MAX_EDGE, self::MAX_EDGE, false);
        }
        if (method_exists($editor, 'set_quality')) {
            $editor->set_quality(82);
        }
        $target = wp_tempnam('ffla-request');
        $saved = $editor->save($target, $mime);
        if (is_wp_error($saved) || empty($saved['path']) || !is_readable($saved['path'])) {
            return null;
        }
        $bytes = file_get_contents($saved['path']);
        @unlink($saved['path']); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if ($saved['path'] !== $target) {
            @unlink($target); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        return false === $bytes ? null : $bytes;
    }

    /* ── Private folder for videos ─────────────────────────────────────── */

    /**
     * Absolute path of the private folder ('' when it does not exist yet and
     * $create is false). Its name is random per site, files inside are named by
     * random tokens, and it holds deny rules for Apache and IIS; on nginx the
     * unguessable names are the protection.
     */
    public static function dir(bool $create = false): string
    {
        $name = get_option(self::DIR_OPTION, '');
        if (!is_string($name) || !preg_match('/^ffla-request-files-[a-z0-9]{16}$/', $name)) {
            if (!$create) {
                return '';
            }
            $name = 'ffla-request-files-' . strtolower(wp_generate_password(16, false, false));
            update_option(self::DIR_OPTION, $name, false);
        }
        $uploads = wp_upload_dir(null, false);
        $dir = trailingslashit(str_replace('\\', '/', $uploads['basedir'])) . $name;
        if ($create && !is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if ($create && is_dir($dir) && !file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n"); // phpcs:ignore
            @file_put_contents($dir . '/web.config', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n"); // phpcs:ignore
        }
        return is_dir($dir) ? $dir : '';
    }

    /** Absolute path of a stored file, or '' if the stored name is not one of ours. */
    private static function disk_path(string $relative): string
    {
        if (!preg_match('/^[a-f0-9]{32}\.(mp4|m4v|mov)$/', $relative)) {
            return '';
        }
        $dir = self::dir();
        return '' !== $dir ? $dir . '/' . $relative : '';
    }

    /* ── Storage ───────────────────────────────────────────────────────── */

    /**
     * Store prepared files on a request.
     *
     * @param object $request
     * @param array  $prepared From prepare().
     * @param array  $actor    ['type','id']
     * @param bool   $public   Visible to the customer.
     * @param int    $event_id Timeline event they belong to.
     * @return array<int, array{token:string, name:string}>
     */
    public static function store($request, array $prepared, array $actor, bool $public, int $event_id = 0, string $kind = ''): array
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $stored = [];
        foreach ($prepared as $file) {
            $token = FFLA_Requests::random_hex(16);
            $relative = '';
            $size = isset($file['bytes']) ? strlen($file['bytes']) : (int) ($file['size'] ?? 0);
            if (!empty($file['video'])) {
                $dir = self::dir(true);
                $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                $relative = $token . '.' . (in_array($ext, ['mp4', 'm4v', 'mov'], true) ? $ext : 'mp4');
                $target = $dir . '/' . $relative;
                $source = (string) $file['source'];
                $moved = '' !== $dir && (is_uploaded_file($source) ? @move_uploaded_file($source, $target) : (@rename($source, $target) || @copy($source, $target))); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                if (!$moved) {
                    continue;
                }
                @chmod($target, 0640); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
            $ok = $wpdb->insert($t['files'], [
                'request_id' => (int) $request->id,
                'event_id'   => $event_id,
                'token'      => $token,
                'name'       => substr((string) $file['name'], 0, 200),
                'mime'       => $file['mime'],
                'size'       => $size,
                'data'       => $file['bytes'] ?? '',
                'path'       => $relative,
                'is_public'  => $public ? 1 : 0,
                'kind'       => sanitize_key($kind),
                'actor_type' => $actor['type'],
                'actor_id'   => (int) ($actor['id'] ?? 0),
                'created_at' => current_time('mysql', true),
            ]);
            if ($ok) {
                $stored[] = ['token' => $token, 'name' => $file['name']];
            } elseif ('' !== $relative) {
                @unlink(self::disk_path($relative)); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
        return $stored;
    }

    /**
     * Delete files (rows and video files on disk). $where narrows the rows,
     * e.g. ['actor_type' => 'customer'].
     */
    public static function delete_for_request(int $request_id, array $where = []): void
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $sql = "SELECT path FROM {$t['files']} WHERE request_id = %d AND path <> ''";
        $args = [$request_id];
        foreach ($where as $column => $value) {
            if (in_array($column, ['actor_type', 'is_public', 'kind'], true)) {
                $sql .= " AND {$column} = %s";
                $args[] = (string) $value;
            }
        }
        foreach ((array) $wpdb->get_col($wpdb->prepare($sql, $args)) as $relative) { // phpcs:ignore
            $path = self::disk_path((string) $relative);
            if ('' !== $path && is_file($path)) {
                @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            }
        }
        $wpdb->delete($t['files'], ['request_id' => $request_id] + array_intersect_key($where, array_flip(['actor_type', 'is_public', 'kind'])));
    }

    /** Remove the private folder (uninstall), whatever is in it. */
    public static function remove_dir(): void
    {
        $dir = self::dir();
        if ('' !== $dir) {
            foreach ((array) @scandir($dir) as $entry) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
                if (is_string($entry) && '.' !== $entry && '..' !== $entry && is_file($dir . '/' . $entry)) {
                    @unlink($dir . '/' . $entry); // phpcs:ignore WordPress.PHP.NoSilencedErrors
                }
            }
            @rmdir($dir); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        delete_option(self::DIR_OPTION);
    }

    /**
     * File list (no contents) for a request.
     */
    public static function for_request(int $request_id, bool $public_only = false): array
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $sql = "SELECT id, request_id, event_id, token, name, mime, size, path, is_public, kind, actor_type, actor_id, created_at FROM {$t['files']} WHERE request_id = %d"
            . ($public_only ? ' AND is_public = 1' : '') . ' ORDER BY id ASC';
        return (array) $wpdb->get_results($wpdb->prepare($sql, $request_id)); // phpcs:ignore
    }

    public static function count(int $request_id): int
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['files']} WHERE request_id = %d", $request_id)); // phpcs:ignore
    }

    public static function count_videos(int $request_id): int
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['files']} WHERE request_id = %d AND mime LIKE 'video/%%'", $request_id)); // phpcs:ignore
    }

    /** "image", "video" or "file". */
    public static function kind_of($file): string
    {
        $mime = (string) (is_object($file) ? $file->mime : ($file['mime'] ?? ''));
        return 0 === strpos($mime, 'image/') ? 'image' : (0 === strpos($mime, 'video/') ? 'video' : 'file');
    }

    /** Contents of a stored photo or PDF ('' for videos, which live on disk). */
    private static function row(int $request_id, string $token, bool $public_only)
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        return $wpdb->get_row($wpdb->prepare( // phpcs:ignore
            "SELECT * FROM {$t['files']} WHERE request_id = %d AND token = %s" . ($public_only ? ' AND is_public = 1' : ''),
            $request_id,
            preg_replace('/[^a-f0-9]/', '', strtolower($token))
        ));
    }

    /**
     * Send one file. Caller has already authorized access to the request.
     * Videos support byte ranges, which browsers need to play and seek them.
     */
    public static function stream($request, string $token, bool $public_only): void
    {
        $file = self::row((int) $request->id, $token, $public_only);
        if (!$file) {
            wp_die(esc_html__('File not found.', 'ffl-funnels-addons'), '', ['response' => 404]);
        }
        $known = isset(self::TYPES[$file->mime]) || isset(self::VIDEO_TYPES[$file->mime]);
        $inline = 0 === strpos($file->mime, 'image/') || 0 === strpos($file->mime, 'video/');
        $download = !empty($_GET['download']); // phpcs:ignore WordPress.Security.NonceVerification

        if (function_exists('session_write_close')) {
            @session_write_close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'");
        header('Content-Type: ' . ($known ? $file->mime : 'application/octet-stream'));
        // Photos and videos open inline (preview); PDFs download.
        header('Content-Disposition: ' . ($inline && !$download ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', sanitize_file_name($file->name)) . '"');

        if ('' === (string) $file->path) {
            header('Content-Length: ' . strlen($file->data));
            echo $file->data; // phpcs:ignore WordPress.Security.EscapeOutput
            exit;
        }

        $path = self::disk_path((string) $file->path);
        if ('' === $path || !is_readable($path)) {
            status_header(404);
            exit;
        }
        $size = (int) filesize($path);
        $start = 0;
        $end = $size - 1;
        header('Accept-Ranges: bytes');
        $range = isset($_SERVER['HTTP_RANGE']) ? (string) wp_unslash($_SERVER['HTTP_RANGE']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m) && ('' !== $m[1] || '' !== $m[2])) {
            if ('' === $m[1]) {
                $start = max(0, $size - (int) $m[2]); // Last N bytes.
            } else {
                $start = (int) $m[1];
                $end = '' !== $m[2] ? min((int) $m[2], $size - 1) : $size - 1;
            }
            if ($start > $end || $start >= $size) {
                status_header(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            status_header(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        header('Content-Length: ' . ($end - $start + 1));
        $handle = fopen($path, 'rb'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ($handle) {
            fseek($handle, $start);
            $left = $end - $start + 1;
            while ($left > 0 && !feof($handle)) {
                $chunk = fread($handle, (int) min(1048576, $left)); // phpcs:ignore WordPress.WP.AlternativeFunctions
                if (false === $chunk) {
                    break;
                }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
                $left -= strlen($chunk);
                flush();
            }
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }
        exit;
    }

    /* ── ZIP downloads ─────────────────────────────────────────────────── */

    /** Whether ZIP downloads can be built on this server. */
    public static function zip_available(): bool
    {
        return class_exists('ZipArchive') || file_exists(ABSPATH . 'wp-admin/includes/class-pclzip.php');
    }

    /**
     * Download a ZIP of the request's files plus any extra entries
     * (['name' => contents]). Files are numbered in the order they were added
     * and named after who sent them, so a claim reviewer can follow them.
     *
     * @param object $request
     * @param array  $files  Rows from for_request().
     * @param string $name   ZIP file name.
     * @param array  $extra  Extra entries: path inside the ZIP => contents.
     * @param string $folder Folder for the files inside the ZIP ('' = top level).
     */
    public static function send_zip($request, array $files, string $name, array $extra = [], string $folder = ''): void
    {
        if (!self::zip_available()) {
            wp_die(esc_html__('This server cannot create ZIP files. Download the files one by one.', 'ffl-funnels-addons'));
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $work = wp_tempnam('ffla-request-zip');
        @unlink($work); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        wp_mkdir_p($work);
        $zip_path = $work . '.zip';
        $entries = []; // inside name => absolute source path
        $temps = [];
        $prefix = '' !== $folder ? trailingslashit($folder) : '';
        $used = [];

        foreach ($extra as $inside => $contents) {
            $source = $work . '/x-' . md5((string) $inside);
            file_put_contents($source, (string) $contents); // phpcs:ignore WordPress.WP.AlternativeFunctions
            $temps[] = $source;
            $entries[(string) $inside] = $source;
        }

        $who = [
            'customer' => __('customer', 'ffl-funnels-addons'),
            'staff'    => __('staff', 'ffl-funnels-addons'),
            'system'   => __('store', 'ffl-funnels-addons'),
        ];
        $i = 0;
        foreach ($files as $file) {
            $i++;
            $base = sanitize_file_name((string) $file->name);
            $label = $who[$file->actor_type] ?? sanitize_key((string) $file->actor_type);
            $inside = $prefix . sprintf('%02d-%s%s-%s', $i, $label, (int) $file->is_public ? '' : '-' . __('internal', 'ffl-funnels-addons'), '' !== $base ? $base : 'file');
            while (isset($used[$inside])) {
                $inside = preg_replace('/(\.[a-z0-9]+)?$/i', '-' . $i . '$1', $inside, 1);
            }
            $used[$inside] = true;
            if ('' !== (string) $file->path) {
                $source = self::disk_path((string) $file->path);
                if ('' === $source || !is_readable($source)) {
                    continue;
                }
            } else {
                $row = self::row((int) $request->id, (string) $file->token, false);
                if (!$row) {
                    continue;
                }
                $source = $work . '/f-' . $file->token;
                file_put_contents($source, $row->data); // phpcs:ignore WordPress.WP.AlternativeFunctions
                $temps[] = $source;
                unset($row);
            }
            $entries[$inside] = $source;
        }

        $ok = false;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if (true === $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                foreach ($entries as $inside => $source) {
                    $zip->addFile($source, $inside);
                    // Photos, videos and PDFs are already compressed.
                    if (method_exists($zip, 'setCompressionName') && preg_match('/\.(jpe?g|png|mp4|m4v|mov|pdf)$/i', $inside)) {
                        $zip->setCompressionName($inside, ZipArchive::CM_STORE);
                    }
                }
                $ok = $zip->close();
            }
        } else {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
            $archive = new PclZip($zip_path);
            $list = [];
            foreach ($entries as $inside => $source) {
                $list[] = [PCLZIP_ATT_FILE_NAME => $source, PCLZIP_ATT_FILE_NEW_FULL_NAME => $inside];
            }
            $ok = 0 !== $archive->create($list);
        }

        foreach ($temps as $temp) {
            @unlink($temp); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
        @rmdir($work); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        if (!$ok || !is_file($zip_path)) {
            @unlink($zip_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
            wp_die(esc_html__('The ZIP file could not be created. Please try again.', 'ffl-funnels-addons'));
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', sanitize_file_name($name)) . '"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path); // phpcs:ignore WordPress.WP.AlternativeFunctions
        @unlink($zip_path); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        exit;
    }
}
