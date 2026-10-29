<?php
/**
 * CSV export and import of reviews and Q&A, and the one-time copy of photos
 * and votes from Customer Reviews for WooCommerce.
 *
 * Exports stream the file row by row. Imports run in the background: the
 * uploaded file is kept in a private folder under uploads, read 100 rows at
 * a time by an Action Scheduler (or WP-Cron) job, and deleted when the job
 * ends. Imported rows are saved with wp_insert_comment(), so they skip the
 * submission rules and send no emails and earn no coupons. A row already
 * imported (same product, email, text and date) is skipped, so a file can
 * be imported again safely.
 *
 * Column names follow the export; the names Customer Reviews for
 * WooCommerce uses (review_content, review_score, display_name, parent,
 * date, media) are understood too.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Import
{
    const JOB_OPTION = 'ffla_review_import_job';

    const ACTION_BATCH = 'ffla_review_import_batch';

    const ACTION_MIGRATE = 'ffla_review_migrate_cusrev';

    const MIGRATE_OPTION = 'ffla_review_migrate_cusrev';

    const BATCH_SIZE = 100;

    const MAX_MEDIA_PER_REVIEW = 5;

    const MAX_FILE_BYTES = 20971520;

    const HASH_META = '_ffla_import_hash';

    const REVIEW_COLUMNS = ['id', 'parent_id', 'product_id', 'product_sku', 'product_name', 'status', 'date_gmt', 'author', 'email', 'user_id', 'rating', 'verified', 'pinned', 'store_reply', 'content', 'criteria', 'media', 'helpful', 'not_helpful', 'order_id'];

    const QNA_COLUMNS = ['id', 'parent_id', 'type', 'product_id', 'product_sku', 'product_name', 'status', 'date_gmt', 'author', 'email', 'user_id', 'store_answer', 'content', 'helpful'];

    /** Other names for our columns (Customer Reviews for WooCommerce, common exports). */
    const ALIASES = [
        'review_content' => 'content',
        'comment'        => 'content',
        'review'         => 'content',
        'review_score'   => 'rating',
        'score'          => 'rating',
        'stars'          => 'rating',
        'parent'         => 'parent_id',
        'display_name'   => 'author',
        'author_name'    => 'author',
        'name'           => 'author',
        'author_email'   => 'email',
        'date'           => 'date_local',
        'review_date'    => 'date_local',
        'date_created'   => 'date_local',
        'sku'            => 'product_sku',
        'images'         => 'media',
        'photos'         => 'media',
        'review_title'   => 'title',
    ];

    public static function init(): void
    {
        add_action(self::ACTION_BATCH, [__CLASS__, 'run_batch']);
        add_action(self::ACTION_MIGRATE, [__CLASS__, 'run_migration_batch']);
    }

    /* ---------------------------------------------------------------------
     * Export
     * ------------------------------------------------------------------- */

    /**
     * A cell a spreadsheet will not run as a formula.
     *
     * @param mixed $value
     */
    public static function cell($value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($value)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Undo cell(): a value our export protected gets its text back, so an
     * export imports exactly as it was.
     */
    public static function uncell(string $value): string
    {
        if (strlen($value) > 1 && "'" === $value[0] && in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return substr($value, 1);
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $row
     * @param resource          $handle
     */
    private static function put($handle, array $row): void
    {
        fputcsv($handle, array_map([__CLASS__, 'cell'], $row), ',', '"', '\\');
    }

    private static function status_name(\WP_Comment $comment): string
    {
        return '1' === (string) $comment->comment_approved ? 'approved' : 'pending';
    }

    /**
     * Every review and reply on products (approved and pending; spam and
     * trash are left out), oldest first, so replies follow their review.
     *
     * @param resource $handle
     */
    public static function export_reviews($handle): int
    {
        self::put($handle, self::REVIEW_COLUMNS);

        $rows   = 0;
        $offset = 0;
        $skus   = [];

        do {
            $comments = get_comments([
                'post_type' => 'product',
                'status'    => 'all',
                'type__in'  => ['review', 'comment'],
                'orderby'   => 'comment_ID',
                'order'     => 'ASC',
                'number'    => 500,
                'offset'    => $offset,
            ]);
            $offset += 500;

            foreach ((array) $comments as $comment) {
                if (!$comment instanceof \WP_Comment || in_array((string) $comment->comment_approved, ['spam', 'trash'], true)) {
                    continue;
                }

                $id         = (int) $comment->comment_ID;
                $product_id = (int) $comment->comment_post_ID;
                if (!isset($skus[$product_id])) {
                    $product          = wc_get_product($product_id);
                    $skus[$product_id] = $product ? [(string) $product->get_sku(), (string) $product->get_name()] : ['', get_the_title($product_id)];
                }

                $criteria = [];
                if (class_exists('Product_Reviews_Criteria')) {
                    foreach (Product_Reviews_Criteria::get_criteria() as $criterion) {
                        $score = (int) get_comment_meta($id, Product_Reviews_Criteria::meta_key($criterion['slug']), true);
                        if ($score > 0) {
                            $criteria[] = $criterion['slug'] . '=' . $score;
                        }
                    }
                }

                $media = [];
                $ids   = get_comment_meta($id, 'ffla_review_media_ids', true);
                foreach (is_array($ids) ? $ids : [] as $media_id) {
                    $url = wp_get_attachment_url((int) $media_id);
                    if ($url) {
                        $media[] = $url;
                    }
                }

                $is_reply = (int) $comment->comment_parent > 0;
                $rating   = (int) get_comment_meta($id, 'rating', true);

                self::put($handle, [
                    $id,
                    $is_reply ? (int) $comment->comment_parent : '',
                    $product_id,
                    $skus[$product_id][0],
                    $skus[$product_id][1],
                    self::status_name($comment),
                    (string) $comment->comment_date_gmt,
                    (string) $comment->comment_author,
                    (string) $comment->comment_author_email,
                    (int) $comment->user_id,
                    $is_reply || $rating < 1 ? '' : $rating,
                    $is_reply ? '' : (Product_Reviews_Core::is_verified_purchase($id) ? 1 : 0),
                    (int) $comment->comment_karma === 1 ? 1 : 0,
                    $is_reply ? (1 === (int) get_comment_meta($id, Product_Reviews_Notifications::STORE_REPLY_META, true) ? 1 : 0) : '',
                    (string) $comment->comment_content,
                    implode(';', $criteria),
                    implode(' | ', $media),
                    (int) get_comment_meta($id, 'ffla_helpful_yes', true),
                    (int) get_comment_meta($id, 'ffla_helpful_no', true),
                    (int) get_comment_meta($id, 'ffla_review_order_id', true) ?: '',
                ]);
                $rows++;
            }
        } while (!empty($comments) && count($comments) === 500);

        return $rows;
    }

    /**
     * Every question and answer (approved and pending), questions before
     * their answers.
     *
     * @param resource $handle
     */
    public static function export_qna($handle): int
    {
        self::put($handle, self::QNA_COLUMNS);

        $rows     = 0;
        $comments = get_comments([
            'type__in' => Product_Reviews_Core::QNA_TYPES,
            'status'   => 'all',
            'orderby'  => 'comment_ID',
            'order'    => 'ASC',
        ]);

        foreach ((array) $comments as $comment) {
            if (!$comment instanceof \WP_Comment || in_array((string) $comment->comment_approved, ['spam', 'trash'], true)) {
                continue;
            }

            $id         = (int) $comment->comment_ID;
            $product_id = (int) $comment->comment_post_ID;
            $product    = wc_get_product($product_id);
            $answer     = 'ffla_answer' === $comment->comment_type;

            self::put($handle, [
                $id,
                $answer ? (int) $comment->comment_parent : '',
                $answer ? 'answer' : 'question',
                $product_id,
                $product ? (string) $product->get_sku() : '',
                $product ? (string) $product->get_name() : get_the_title($product_id),
                self::status_name($comment),
                (string) $comment->comment_date_gmt,
                (string) $comment->comment_author,
                (string) $comment->comment_author_email,
                (int) $comment->user_id,
                $answer ? (1 === (int) get_comment_meta($id, 'ffla_is_store_answer', true) ? 1 : 0) : '',
                (string) $comment->comment_content,
                (int) get_comment_meta($id, 'ffla_helpful_yes', true),
            ]);
            $rows++;
        }

        return $rows;
    }

    /* ---------------------------------------------------------------------
     * Import jobs
     * ------------------------------------------------------------------- */

    /**
     * The private folder uploaded files wait in. Not reachable from the
     * web on Apache (deny rule); the name is random everywhere else.
     */
    public static function storage_dir(): string
    {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit((string) $uploads['basedir']) . 'ffla-review-imports';

        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }

        return $dir;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function job(): ?array
    {
        $job = get_option(self::JOB_OPTION, null);

        return is_array($job) ? $job : null;
    }

    public static function job_running(): bool
    {
        $job = self::job();

        return $job !== null && in_array($job['status'] ?? '', ['queued', 'running'], true);
    }

    /**
     * Normalise a header row to our column names.
     *
     * @param array<int, mixed> $header
     * @return array<int, string>
     */
    public static function map_header(array $header): array
    {
        $out = [];
        foreach ($header as $i => $name) {
            $name = strtolower(trim((string) $name));
            if (0 === $i) {
                $name = preg_replace('/^\xEF\xBB\xBF/', '', $name);
            }
            $name    = trim((string) $name, " \t\"'");
            $name    = preg_replace('/[^a-z0-9_]+/', '_', $name);
            $out[$i] = self::ALIASES[$name] ?? $name;
        }

        return $out;
    }

    /**
     * Take an uploaded CSV and queue its import. The file is moved to the
     * private folder; the first row must name the columns.
     *
     * @param array<string, mixed> $upload An entry of $_FILES.
     * @return array{ok:bool,message:string}
     */
    public static function start_job(array $upload, string $kind, string $mode): array
    {
        if (self::job_running()) {
            return ['ok' => false, 'message' => __('An import is still running. Wait for it to finish, or cancel it.', 'ffl-funnels-addons')];
        }

        $kind = 'qna' === $kind ? 'qna' : 'reviews';
        $mode = 'pending' === $mode ? 'pending' : 'keep';

        if (!empty($upload['error']) || empty($upload['tmp_name']) || !is_uploaded_file((string) $upload['tmp_name'])) {
            return ['ok' => false, 'message' => __('Choose a CSV file to import.', 'ffl-funnels-addons')];
        }
        if ((int) ($upload['size'] ?? 0) > self::MAX_FILE_BYTES) {
            return ['ok' => false, 'message' => __('The file is larger than 20 MB. Split it into smaller files.', 'ffl-funnels-addons')];
        }
        if ('csv' !== strtolower((string) pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION))) {
            return ['ok' => false, 'message' => __('The file must be a .csv file.', 'ffl-funnels-addons')];
        }

        $target = self::storage_dir() . '/' . $kind . '-' . wp_generate_password(20, false, false) . '.csv';
        if (!@move_uploaded_file((string) $upload['tmp_name'], $target)) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
            return ['ok' => false, 'message' => __('The file could not be saved for import.', 'ffl-funnels-addons')];
        }

        return self::queue_file($target, $kind, $mode);
    }

    /**
     * Read the header of a file already in the private folder and queue it.
     *
     * @return array{ok:bool,message:string}
     */
    public static function queue_file(string $path, string $kind, string $mode): array
    {
        if (self::job_running()) {
            @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

            return ['ok' => false, 'message' => __('An import is still running. Wait for it to finish, or cancel it.', 'ffl-funnels-addons')];
        }

        $handle = @fopen($path, 'r'); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        $header = $handle ? fgetcsv($handle, 0, ',', '"', '\\') : false;
        $offset = $handle ? (int) ftell($handle) : 0;
        if ($handle) {
            fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions
        }

        $columns = is_array($header) ? self::map_header($header) : [];
        $has_product = in_array('product_id', $columns, true) || in_array('product_sku', $columns, true);
        if (!$has_product || !in_array('content', $columns, true)) {
            @unlink($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

            return ['ok' => false, 'message' => __('The first row must name the columns, with at least content and product_id or product_sku (see the export for the full list).', 'ffl-funnels-addons')];
        }

        update_option(self::JOB_OPTION, [
            'kind'     => $kind,
            'mode'     => $mode,
            'file'     => $path,
            'columns'  => $columns,
            'offset'   => $offset,
            'line'     => 1,
            'imported' => 0,
            'skipped'  => 0,
            'messages' => [],
            'map'      => [],
            'products' => [],
            'status'   => 'queued',
            'started'  => time(),
            'finished' => 0,
        ], false);

        self::schedule_batch();

        return ['ok' => true, 'message' => __('Import started. It runs in the background; this page shows its progress.', 'ffl-funnels-addons')];
    }

    private static function schedule_batch(): void
    {
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION_BATCH, [], Product_Reviews_Email::GROUP);

            return;
        }
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), self::ACTION_BATCH, [], Product_Reviews_Email::GROUP);

            return;
        }

        wp_schedule_single_event(time(), self::ACTION_BATCH);
    }

    public static function cancel_job(): void
    {
        $job = self::job();
        if ($job === null) {
            return;
        }

        if (!empty($job['file']) && file_exists((string) $job['file'])) {
            @unlink((string) $job['file']); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }

        if (in_array($job['status'] ?? '', ['queued', 'running'], true)) {
            $job['status']   = 'cancelled';
            $job['finished'] = time();
            $job['file']     = '';
            update_option(self::JOB_OPTION, $job, false);
        } else {
            delete_option(self::JOB_OPTION);
        }
    }

    /**
     * Import the next rows of the queued file, then queue the next batch or
     * finish.
     */
    public static function run_batch(): void
    {
        $job = self::job();
        if ($job === null || !in_array($job['status'] ?? '', ['queued', 'running'], true)) {
            return;
        }

        $handle = !empty($job['file']) ? @fopen((string) $job['file'], 'r') : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        if (!$handle) {
            $job['status']     = 'failed';
            $job['finished']   = time();
            $job['messages'][] = __('The uploaded file is gone.', 'ffl-funnels-addons');
            update_option(self::JOB_OPTION, $job, false);

            return;
        }

        $job['status'] = 'running';
        fseek($handle, (int) $job['offset']);

        for ($n = 0; $n < self::BATCH_SIZE; $n++) {
            $row = fgetcsv($handle, 0, ',', '"', '\\');
            if (false === $row) {
                break;
            }
            $job['line']++;
            $job['offset'] = (int) ftell($handle);

            if ([null] === $row || (1 === count($row) && trim((string) $row[0]) === '')) {
                continue;
            }

            $data = [];
            foreach ($job['columns'] as $i => $name) {
                $data[$name] = isset($row[$i]) ? self::uncell(self::to_utf8(trim((string) $row[$i]))) : '';
            }

            $result = 'qna' === $job['kind'] ? self::import_qna_row($data, $job) : self::import_review_row($data, $job);
            if ($result['id'] > 0) {
                $job['imported']++;
                $job['products'][$result['product']] = true;
            } else {
                $job['skipped']++;
            }
            if ($result['map_id'] > 0 && $result['csv_id'] !== '') {
                $job['map'][$result['csv_id']] = $result['map_id'];
            }
            if ($result['message'] !== '' && count($job['messages']) < 100) {
                $job['messages'][] = sprintf(
                    /* translators: 1: line number, 2: message */
                    __('Line %1$d: %2$s', 'ffl-funnels-addons'),
                    $job['line'],
                    $result['message']
                );
            }
        }

        // One more row? The saved offset still points before it.
        $eof = false === fgetcsv($handle, 0, ',', '"', '\\');
        fclose($handle); // phpcs:ignore WordPress.WP.AlternativeFunctions

        if ($eof) {
            self::finish($job);

            return;
        }

        update_option(self::JOB_OPTION, $job, false);
        self::schedule_batch();
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function finish(array $job): void
    {
        // WooCommerce's stored rating counts for every product touched.
        if ('reviews' === $job['kind'] && class_exists('WC_Comments') && method_exists('WC_Comments', 'clear_transients')) {
            foreach (array_keys((array) $job['products']) as $product_id) {
                \WC_Comments::clear_transients((int) $product_id);
            }
        }
        if (class_exists('Product_Reviews_Query')) {
            Product_Reviews_Query::bump_cache_version();
        }

        if (!empty($job['file']) && file_exists((string) $job['file'])) {
            @unlink((string) $job['file']); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
        }

        $job['status']   = 'done';
        $job['file']     = '';
        $job['map']      = [];
        $job['finished'] = time();
        update_option(self::JOB_OPTION, $job, false);
    }

    private static function to_utf8(string $value): string
    {
        if ($value === '' || !function_exists('mb_check_encoding') || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /* ---------------------------------------------------------------------
     * Rows
     * ------------------------------------------------------------------- */

    /**
     * @param array<string, string> $data
     */
    private static function resolve_product(array $data): int
    {
        $id = (int) ($data['product_id'] ?? 0);
        if ($id > 0 && in_array(get_post_type($id), ['product', 'product_variation'], true)) {
            $product = wc_get_product($id);
            if ($product && $product->is_type('variation')) {
                return (int) $product->get_parent_id();
            }

            return $id;
        }

        $sku = trim((string) ($data['product_sku'] ?? ''));
        if ($sku !== '' && function_exists('wc_get_product_id_by_sku')) {
            $id = (int) wc_get_product_id_by_sku($sku);
            if ($id > 0) {
                $product = wc_get_product($id);

                return $product && $product->is_type('variation') ? (int) $product->get_parent_id() : $id;
            }
        }

        return 0;
    }

    /**
     * GMT date from date_gmt, else a local date (date / review_date), else
     * now.
     *
     * @param array<string, string> $data
     */
    private static function date_gmt(array $data): string
    {
        if (!empty($data['date_gmt'])) {
            $ts = strtotime($data['date_gmt'] . ' UTC');
            if (false !== $ts) {
                return gmdate('Y-m-d H:i:s', $ts);
            }
        }

        if (!empty($data['date_local'])) {
            try {
                $date = new \DateTimeImmutable($data['date_local'], wp_timezone());

                return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                return gmdate('Y-m-d H:i:s');
            }
        }

        return gmdate('Y-m-d H:i:s');
    }

    /**
     * @param array<string, string> $data
     */
    private static function approved(array $data, string $mode): string
    {
        if ('pending' === $mode) {
            return '0';
        }

        $status = strtolower(trim((string) ($data['status'] ?? '')));

        return in_array($status, ['pending', 'hold', 'unapproved', '0'], true) ? '0' : '1';
    }

    /**
     * The row's own id is a comment of this site on the same product with
     * the same text: the file is this store's export, imported again.
     */
    private static function same_site_comment(string $csv_id, int $product_id, string $content): int
    {
        if ($csv_id === '' || !ctype_digit($csv_id)) {
            return 0;
        }

        $existing = get_comment((int) $csv_id);
        if ($existing instanceof \WP_Comment && (int) $existing->comment_post_ID === $product_id
            && trim(wp_strip_all_tags((string) $existing->comment_content)) === trim(wp_strip_all_tags($content))) {
            return (int) $existing->comment_ID;
        }

        return 0;
    }

    private static function find_by_hash(string $hash): int
    {
        $found = get_comments([
            'meta_key'   => self::HASH_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'status'     => 'all',
            'number'     => 1,
            'fields'     => 'ids',
        ]);

        return !empty($found) ? (int) $found[0] : 0;
    }

    /**
     * The new comment for a parent given in the file: a row imported in this
     * job (by its id column), or an existing comment on the same product.
     *
     * @param array<string, mixed> $job
     */
    private static function resolve_parent(string $parent, int $product_id, array $job): int
    {
        $parent = trim($parent);
        if ($parent === '' || '0' === $parent) {
            return 0;
        }

        if (isset($job['map'][$parent])) {
            return (int) $job['map'][$parent];
        }

        $existing = get_comment((int) $parent);

        return $existing instanceof \WP_Comment && (int) $existing->comment_post_ID === $product_id ? (int) $existing->comment_ID : -1;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, mixed>  $job
     * @return array{id:int,map_id:int,csv_id:string,product:int,message:string}
     */
    public static function import_review_row(array $data, array $job): array
    {
        $csv_id = trim((string) ($data['id'] ?? ''));
        $result = ['id' => 0, 'map_id' => 0, 'csv_id' => $csv_id, 'product' => 0, 'message' => ''];

        if ('-1' === trim((string) ($data['product_id'] ?? ''))) {
            $result['message'] = __('store reviews (product_id -1) are not imported.', 'ffl-funnels-addons');

            return $result;
        }

        $product_id = self::resolve_product($data);
        if ($product_id <= 0) {
            $result['message'] = __('product not found (check product_id or product_sku).', 'ffl-funnels-addons');

            return $result;
        }
        $result['product'] = $product_id;

        $content = trim(wp_kses((string) ($data['content'] ?? ''), []));
        if (!empty($data['title'])) {
            $content = trim(wp_kses((string) $data['title'], [])) . ($content !== '' ? "\n\n" . $content : '');
        }
        if ($content === '') {
            $result['message'] = __('the review text is empty.', 'ffl-funnels-addons');

            return $result;
        }

        $parent = self::resolve_parent((string) ($data['parent_id'] ?? ''), $product_id, $job);
        if ($parent < 0) {
            $result['message'] = __('its parent review was not found; the reply is skipped.', 'ffl-funnels-addons');

            return $result;
        }

        $email  = sanitize_email((string) ($data['email'] ?? ''));
        $email  = is_email($email) ? $email : '';
        $date   = self::date_gmt($data);
        $author = sanitize_text_field((string) ($data['author'] ?? ''));
        if ($author === '') {
            $author = __('Customer', 'ffl-funnels-addons');
        }

        $hash = md5($product_id . '|' . $parent . '|' . strtolower($email) . '|' . $content . '|' . $date);
        $dup  = self::same_site_comment($csv_id, $product_id, $content) ?: self::find_by_hash($hash);
        if ($dup > 0) {
            $result['message'] = __('already imported; skipped.', 'ffl-funnels-addons');
            // Replies further down the file still find their review.
            $result['map_id'] = $dup;

            return $result;
        }

        $user_id = absint($data['user_id'] ?? 0);
        if ($user_id > 0 && !get_userdata($user_id)) {
            $user_id = 0;
        }

        $id = wp_insert_comment([
            'comment_post_ID'      => $product_id,
            'comment_parent'       => $parent,
            'comment_type'         => $parent > 0 ? 'comment' : 'review',
            'comment_content'      => $content,
            'comment_author'       => $author,
            'comment_author_email' => $email,
            'comment_author_url'   => '',
            'user_id'              => $user_id,
            'comment_approved'     => self::approved($data, (string) $job['mode']),
            'comment_date_gmt'     => $date,
            'comment_date'         => get_date_from_gmt($date),
            'comment_karma'        => $parent <= 0 && '1' === trim((string) ($data['pinned'] ?? '')) ? 1 : 0,
        ]);

        if (!$id) {
            $result['message'] = __('WordPress refused to save it.', 'ffl-funnels-addons');

            return $result;
        }

        $id = (int) $id;
        update_comment_meta($id, self::HASH_META, $hash);

        if ($parent > 0) {
            if ('1' === trim((string) ($data['store_reply'] ?? ''))) {
                update_comment_meta($id, Product_Reviews_Notifications::STORE_REPLY_META, 1);
            }
        } else {
            $rating = (int) ($data['rating'] ?? 0);
            if ($rating >= 1 && $rating <= 5) {
                update_comment_meta($id, 'rating', $rating);
            }

            $verified = '1' === trim((string) ($data['verified'] ?? '')) ? 1 : 0;
            update_comment_meta($id, 'ffla_verified_purchase', $verified);
            update_comment_meta($id, 'verified', $verified);
            update_comment_meta($id, 'ffla_helpful_yes', absint($data['helpful'] ?? 0));
            update_comment_meta($id, 'ffla_helpful_no', absint($data['not_helpful'] ?? 0));

            $order_id = absint($data['order_id'] ?? 0);
            if ($order_id > 0) {
                update_comment_meta($id, 'ffla_review_order_id', $order_id);
            }

            self::import_criteria($id, (string) ($data['criteria'] ?? ''));

            $warnings = self::import_media($id, (string) ($data['media'] ?? ''));
            if ($warnings !== '') {
                $result['message'] = $warnings;
            }
        }

        $result['id']     = $id;
        $result['map_id'] = $id;

        return $result;
    }

    /**
     * `quality=5;value=4` (also `:` and `,`). Only configured criteria.
     */
    private static function import_criteria(int $comment_id, string $raw): void
    {
        if (trim($raw) === '' || !class_exists('Product_Reviews_Criteria')) {
            return;
        }

        $known = [];
        foreach (Product_Reviews_Criteria::get_criteria() as $criterion) {
            $known[$criterion['slug']] = true;
        }

        foreach (preg_split('/[;,]/', $raw) ?: [] as $pair) {
            if (!preg_match('/^\s*([a-z0-9_-]+)\s*[=:]\s*([1-5])\s*$/i', $pair, $m)) {
                continue;
            }
            $slug = sanitize_key($m[1]);
            if (isset($known[$slug])) {
                update_comment_meta($comment_id, Product_Reviews_Criteria::meta_key($slug), (int) $m[2]);
            }
        }
    }

    /**
     * Download the review's photos and videos into the Media Library (at most
     * five; photos lose their location data as uploads do). Returns what
     * could not be imported, or ''.
     */
    private static function import_media(int $comment_id, string $raw): string
    {
        $urls = array_values(array_filter(array_map('trim', preg_split('/\s*\|\s*|\s*,\s*|\R/', $raw) ?: [])));
        if (empty($urls)) {
            return '';
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ids    = [];
        $failed = 0;
        foreach (array_slice($urls, 0, self::MAX_MEDIA_PER_REVIEW) as $url) {
            $id = self::import_media_url($url);
            if ($id > 0) {
                $ids[] = $id;
            } else {
                $failed++;
            }
        }

        if (!empty($ids)) {
            update_comment_meta($comment_id, 'ffla_review_media_ids', $ids);
        }

        $extra = max(0, count($urls) - self::MAX_MEDIA_PER_REVIEW);
        $notes = [];
        if ($failed > 0) {
            $notes[] = sprintf(
                /* translators: %d: number of files */
                _n('%d photo or video could not be downloaded.', '%d photos or videos could not be downloaded.', $failed, 'ffl-funnels-addons'),
                $failed
            );
        }
        if ($extra > 0) {
            $notes[] = sprintf(
                /* translators: %d: number of files */
                _n('%d file over the limit of five was left out.', '%d files over the limit of five were left out.', $extra, 'ffl-funnels-addons'),
                $extra
            );
        }

        return implode(' ', $notes);
    }

    /**
     * @return int Attachment ID, 0 when the file could not be imported.
     */
    public static function import_media_url(string $url): int
    {
        $url = esc_url_raw($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return 0;
        }

        // wp_safe_remote_get() inside: local and private addresses are refused.
        $tmp = download_url($url, 20);
        if (is_wp_error($tmp) || !is_string($tmp)) {
            return 0;
        }

        $name  = sanitize_file_name((string) basename((string) parse_url($url, PHP_URL_PATH)));
        $mimes = [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'gif'      => 'image/gif',
            'webp'     => 'image/webp',
            'mp4'      => 'video/mp4',
            'webm'     => 'video/webm',
        ];
        $check = wp_check_filetype_and_ext($tmp, $name !== '' ? $name : 'review-media', $mimes);
        $size  = (int) @filesize($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors

        if (empty($check['ext']) || empty($check['type']) || $size <= 0 || $size > 25 * 1024 * 1024) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

            return 0;
        }

        if (strpos((string) $check['type'], 'image/') === 0 && !Product_Reviews_Image_Privacy::strip_file($tmp, (string) $check['type'])) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

            return 0;
        }

        $file = ['name' => $name !== '' && !empty($check['proper_filename']) ? (string) $check['proper_filename'] : ($name !== '' ? $name : 'review-media.' . $check['ext']), 'tmp_name' => $tmp];
        $id   = media_handle_sideload($file, 0, null, ['test_form' => false, 'mimes' => $mimes]);
        if (is_wp_error($id)) {
            @unlink($tmp); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

            return 0;
        }

        return (int) $id;
    }

    /**
     * @param array<string, string> $data
     * @param array<string, mixed>  $job
     * @return array{id:int,map_id:int,csv_id:string,product:int,message:string}
     */
    public static function import_qna_row(array $data, array $job): array
    {
        $csv_id = trim((string) ($data['id'] ?? ''));
        $result = ['id' => 0, 'map_id' => 0, 'csv_id' => $csv_id, 'product' => 0, 'message' => ''];

        $product_id = self::resolve_product($data);
        if ($product_id <= 0) {
            $result['message'] = __('product not found (check product_id or product_sku).', 'ffl-funnels-addons');

            return $result;
        }
        $result['product'] = $product_id;

        $content = trim(wp_kses((string) ($data['content'] ?? ''), []));
        if ($content === '') {
            $result['message'] = __('the text is empty.', 'ffl-funnels-addons');

            return $result;
        }

        $type   = strtolower(trim((string) ($data['type'] ?? '')));
        $parent = self::resolve_parent((string) ($data['parent_id'] ?? ''), $product_id, $job);
        if ('answer' === $type || $parent > 0) {
            $question = $parent > 0 ? get_comment($parent) : null;
            if (!$question instanceof \WP_Comment || Product_Reviews_Qna::QUESTION !== $question->comment_type) {
                $result['message'] = __('its question was not found; the answer is skipped.', 'ffl-funnels-addons');

                return $result;
            }
            $type = 'answer';
        } else {
            $type   = 'question';
            $parent = 0;
        }

        $email = sanitize_email((string) ($data['email'] ?? ''));
        $email = is_email($email) ? $email : '';
        $date  = self::date_gmt($data);
        $hash  = md5('qna|' . $product_id . '|' . $parent . '|' . strtolower($email) . '|' . $content . '|' . $date);
        $dup   = self::same_site_comment($csv_id, $product_id, $content) ?: self::find_by_hash($hash);
        if ($dup > 0) {
            $result['message'] = __('already imported; skipped.', 'ffl-funnels-addons');
            $result['map_id']  = $dup;

            return $result;
        }

        $author = sanitize_text_field((string) ($data['author'] ?? ''));
        $user   = absint($data['user_id'] ?? 0);

        $id = wp_insert_comment([
            'comment_post_ID'      => $product_id,
            'comment_parent'       => $parent,
            'comment_type'         => 'answer' === $type ? Product_Reviews_Qna::ANSWER : Product_Reviews_Qna::QUESTION,
            'comment_content'      => $content,
            'comment_author'       => $author !== '' ? $author : __('Customer', 'ffl-funnels-addons'),
            'comment_author_email' => $email,
            'comment_author_url'   => '',
            'user_id'              => $user > 0 && get_userdata($user) ? $user : 0,
            'comment_approved'     => self::approved($data, (string) $job['mode']),
            'comment_date_gmt'     => $date,
            'comment_date'         => get_date_from_gmt($date),
        ]);
        if (!$id) {
            $result['message'] = __('WordPress refused to save it.', 'ffl-funnels-addons');

            return $result;
        }

        $id = (int) $id;
        update_comment_meta($id, self::HASH_META, $hash);
        // Imported answers were sent long ago: never email the asker now.
        update_comment_meta($id, Product_Reviews_Qna::ASKER_NOTIFIED_META, 1);
        if ('answer' === $type) {
            if ('1' === trim((string) ($data['store_answer'] ?? ''))) {
                update_comment_meta($id, Product_Reviews_Qna::STORE_ANSWER_META, 1);
            }
            update_comment_meta($id, 'ffla_helpful_yes', absint($data['helpful'] ?? 0));
        } else {
            update_comment_meta($id, Product_Reviews_Qna::STORE_NOTIFIED_META, 1);
        }
        wp_cache_delete('qna_count_' . $product_id, 'ffla_product_reviews');

        $result['id']     = $id;
        $result['map_id'] = $id;

        return $result;
    }

    /* ---------------------------------------------------------------------
     * Photos and votes from Customer Reviews for WooCommerce
     * ------------------------------------------------------------------- */

    /**
     * Reviews that carry that plugin's own photos, videos or votes.
     */
    public static function cusrev_candidates(int $limit = 0): array
    {
        $args = [
            'post_type'  => 'product',
            'status'     => 'all',
            'type'       => 'review',
            'fields'     => 'ids',
            'orderby'    => 'comment_ID',
            'order'      => 'ASC',
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'relation' => 'OR',
                ['key' => 'ivole_review_image2', 'compare' => 'EXISTS'],
                ['key' => 'ivole_review_video2', 'compare' => 'EXISTS'],
                ['key' => 'ivole_review_reg_upvoters', 'compare' => 'EXISTS'],
                ['key' => 'ivole_review_unreg_upvoters', 'compare' => 'EXISTS'],
            ],
        ];
        if ($limit > 0) {
            $args['number'] = $limit;
        }

        return array_map('intval', (array) get_comments($args));
    }

    /**
     * That plugin keeps voters as a list per review.
     */
    private static function count_list(int $comment_id, string $key): int
    {
        $list = get_comment_meta($comment_id, $key, true);

        return is_array($list) ? count($list) : 0;
    }

    public static function start_migration(): void
    {
        update_option(self::MIGRATE_OPTION, ['status' => 'running', 'last_id' => 0, 'reviews' => 0, 'media' => 0, 'votes' => 0, 'started' => time()], false);
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ACTION_MIGRATE, [], Product_Reviews_Email::GROUP);
        } else {
            wp_schedule_single_event(time(), self::ACTION_MIGRATE);
        }
    }

    /**
     * Copy photos (the attachment IDs, not new files) and vote counts onto
     * the module's own fields, 200 reviews per run. Photos and votes the
     * module already has are kept; running it again changes nothing.
     */
    public static function run_migration_batch(): void
    {
        $state = get_option(self::MIGRATE_OPTION, []);
        if (!is_array($state) || 'running' !== ($state['status'] ?? '')) {
            return;
        }

        $todo = array_values(array_filter(self::cusrev_candidates(), static function (int $id) use ($state): bool {
            return $id > (int) $state['last_id'];
        }));
        $batch = array_slice($todo, 0, 200);

        foreach ($batch as $review_id) {
            $state['last_id'] = $review_id;
            $state['reviews']++;

            $current = get_comment_meta($review_id, 'ffla_review_media_ids', true);
            $current = is_array($current) ? array_map('intval', $current) : [];
            $theirs  = array_merge(
                array_map('intval', (array) get_comment_meta($review_id, 'ivole_review_image2', false)),
                array_map('intval', (array) get_comment_meta($review_id, 'ivole_review_video2', false))
            );
            $theirs = array_values(array_filter($theirs, static function (int $id): bool {
                return $id > 0 && false !== wp_get_attachment_url($id);
            }));
            $merged = array_values(array_unique(array_merge($current, $theirs)));
            if ($merged !== $current) {
                update_comment_meta($review_id, 'ffla_review_media_ids', $merged);
                $state['media'] += count($merged) - count($current);
            }

            $up   = self::count_list($review_id, 'ivole_review_reg_upvoters') + self::count_list($review_id, 'ivole_review_unreg_upvoters');
            $down = self::count_list($review_id, 'ivole_review_reg_downvoters') + self::count_list($review_id, 'ivole_review_unreg_downvoters');

            if ($up > 0 && (int) get_comment_meta($review_id, 'ffla_helpful_yes', true) < $up) {
                update_comment_meta($review_id, 'ffla_helpful_yes', $up);
                $state['votes']++;
            }
            if ($down > 0 && (int) get_comment_meta($review_id, 'ffla_helpful_no', true) < $down) {
                update_comment_meta($review_id, 'ffla_helpful_no', $down);
            }
        }

        if (count($todo) > count($batch)) {
            update_option(self::MIGRATE_OPTION, $state, false);
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(self::ACTION_MIGRATE, [], Product_Reviews_Email::GROUP);
            } else {
                wp_schedule_single_event(time(), self::ACTION_MIGRATE);
            }

            return;
        }

        $state['status']   = 'done';
        $state['finished'] = time();
        update_option(self::MIGRATE_OPTION, $state, false);
    }
}
