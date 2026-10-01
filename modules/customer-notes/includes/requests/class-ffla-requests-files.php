<?php
/**
 * Customer request attachments.
 *
 * JPEG, PNG and PDF only; the content type must match the extension. Photos
 * are re-encoded through WordPress's image editor (max 2000 px), which shrinks
 * phone pictures and strips their metadata (GPS location, device). Files live
 * in the private `ffla_request_files` table — never in the Media Library or a
 * public uploads URL — and are streamed only to staff or to the customer who
 * holds the request's access.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Files
{
    const MAX_UPLOAD = 10 * 1048576;   // What we accept from the browser.
    const MAX_STORED = 4 * 1048576;    // What we keep after processing.
    const MAX_PER_MESSAGE = 3;
    const MAX_PER_REQUEST = 20;
    const MAX_EDGE = 2000;

    const TYPES = [
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'application/pdf' => ['pdf'],
    ];

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
        $raw = $_FILES[$field]; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        $out = [];
        if (is_array($raw['name'])) {
            foreach (array_keys($raw['name']) as $i) {
                if (UPLOAD_ERR_NO_FILE === (int) $raw['error'][$i]) {
                    continue;
                }
                $out[] = ['name' => (string) $raw['name'][$i], 'tmp_name' => (string) $raw['tmp_name'][$i], 'error' => (int) $raw['error'][$i], 'size' => (int) $raw['size'][$i]];
            }
        } elseif (UPLOAD_ERR_NO_FILE !== (int) $raw['error']) {
            $out[] = ['name' => (string) $raw['name'], 'tmp_name' => (string) $raw['tmp_name'], 'error' => (int) $raw['error'], 'size' => (int) $raw['size']];
        }
        return $out;
    }

    /**
     * Validate every file before storing any.
     *
     * @param array $files From from_request().
     * @param int   $existing Files already on the request.
     * @return array<int, array{name:string, mime:string, bytes:string}>
     * @throws InvalidArgumentException
     */
    public static function prepare(array $files, int $existing = 0): array
    {
        if (count($files) > self::MAX_PER_MESSAGE) {
            /* translators: %d: number of files */
            throw new InvalidArgumentException(sprintf(__('Attach up to %d files at a time.', 'ffl-funnels-addons'), self::MAX_PER_MESSAGE));
        }
        if ($existing + count($files) > self::MAX_PER_REQUEST) {
            throw new InvalidArgumentException(__('This request already has the maximum number of files.', 'ffl-funnels-addons'));
        }

        $prepared = [];
        foreach ($files as $file) {
            $path = $file['tmp_name'];
            if (UPLOAD_ERR_OK !== $file['error'] || !is_uploaded_file($path) || filesize($path) > self::MAX_UPLOAD) {
                throw new InvalidArgumentException(__('Each file must be a JPEG, PNG or PDF of up to 10 MB.', 'ffl-funnels-addons'));
            }
            $prepared[] = self::process($path, (string) $file['name']);
        }

        return $prepared;
    }

    /**
     * Check one file on disk and return what will be stored.
     *
     * @return array{name:string, mime:string, bytes:string}
     */
    public static function process(string $path, string $original_name): array
    {
        $name = sanitize_file_name($original_name);
        $mime = function_exists('finfo_open') ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($path) : '';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!isset(self::TYPES[$mime]) || !in_array($ext, self::TYPES[$mime], true)) {
            throw new InvalidArgumentException(__('Only JPEG, PNG and PDF files are accepted, and the file must match its extension.', 'ffl-funnels-addons'));
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

    private static function reencode(string $path, string $mime): ?string
    {
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor)) {
            return null;
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
    public static function store($request, array $prepared, array $actor, bool $public, int $event_id = 0): array
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $stored = [];
        foreach ($prepared as $file) {
            $token = FFLA_Requests::random_hex(16);
            $ok = $wpdb->insert($t['files'], [
                'request_id' => (int) $request->id,
                'event_id'   => $event_id,
                'token'      => $token,
                'name'       => substr($file['name'], 0, 200),
                'mime'       => $file['mime'],
                'size'       => strlen($file['bytes']),
                'data'       => $file['bytes'],
                'is_public'  => $public ? 1 : 0,
                'actor_type' => $actor['type'],
                'actor_id'   => (int) ($actor['id'] ?? 0),
                'created_at' => current_time('mysql', true),
            ]);
            if ($ok) {
                $stored[] = ['token' => $token, 'name' => $file['name']];
            }
        }
        return $stored;
    }

    /**
     * File list (no contents) for a request.
     */
    public static function for_request(int $request_id, bool $public_only = false): array
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $sql = "SELECT id, request_id, event_id, token, name, mime, size, is_public, actor_type, actor_id, created_at FROM {$t['files']} WHERE request_id = %d"
            . ($public_only ? ' AND is_public = 1' : '') . ' ORDER BY id ASC';
        return (array) $wpdb->get_results($wpdb->prepare($sql, $request_id)); // phpcs:ignore
    }

    public static function count(int $request_id): int
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['files']} WHERE request_id = %d", $request_id)); // phpcs:ignore
    }

    /**
     * Send one file. Caller has already authorized access to the request.
     */
    public static function stream($request, string $token, bool $public_only): void
    {
        global $wpdb;
        $t = FFLA_Requests::tables();
        $file = $wpdb->get_row($wpdb->prepare( // phpcs:ignore
            "SELECT * FROM {$t['files']} WHERE request_id = %d AND token = %s" . ($public_only ? ' AND is_public = 1' : ''),
            (int) $request->id,
            preg_replace('/[^a-f0-9]/', '', strtolower($token))
        ));
        if (!$file) {
            wp_die(esc_html__('File not found.', 'ffl-funnels-addons'), '', ['response' => 404]);
        }

        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
        header('Content-Type: ' . (isset(self::TYPES[$file->mime]) ? $file->mime : 'application/octet-stream'));
        // Images open inline (preview); PDFs download.
        $disposition = 0 === strpos($file->mime, 'image/') ? 'inline' : 'attachment';
        header('Content-Disposition: ' . $disposition . '; filename="' . str_replace(['"', "\r", "\n"], '', sanitize_file_name($file->name)) . '"');
        header('Content-Length: ' . strlen($file->data));
        echo $file->data; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }
}
