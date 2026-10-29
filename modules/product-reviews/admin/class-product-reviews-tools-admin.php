<?php
/**
 * FFL Funnels → Product Reviews → Import / Export.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Tools_Admin
{
    const PAGE = 'ffla-product-reviews-tools';

    const EXPORT_ACTION = 'ffla_review_export';

    const IMPORT_ACTION = 'ffla_review_import';

    const CANCEL_ACTION = 'ffla_review_import_cancel';

    const MIGRATE_ACTION = 'ffla_review_migrate';

    public function init(): void
    {
        add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'handle_export']);
        add_action('admin_post_' . self::IMPORT_ACTION, [$this, 'handle_import']);
        add_action('admin_post_' . self::CANCEL_ACTION, [$this, 'handle_cancel']);
        add_action('admin_post_' . self::MIGRATE_ACTION, [$this, 'handle_migrate']);
    }

    public static function page_url(array $args = []): string
    {
        return add_query_arg(['page' => self::PAGE] + $args, admin_url('admin.php'));
    }

    private static function deny(): void
    {
        wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
        $notice = isset($_GET['tools_notice']) ? sanitize_text_field(wp_unslash($_GET['tools_notice'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $type   = isset($_GET['tools_type']) && 'error' === $_GET['tools_type'] ? 'error' : 'success';
        if ($notice !== '') {
            FFLA_Admin::render_notice($type, esc_html($notice));
        }

        // Export.
        $export = static function (string $kind): string {
            return wp_nonce_url(add_query_arg(['action' => self::EXPORT_ACTION, 'kind' => $kind], admin_url('admin-post.php')), self::EXPORT_ACTION);
        };
        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Export', 'ffl-funnels-addons') . '</h3>'
            . '<p>' . esc_html__('A CSV file you can open in a spreadsheet, keep as a backup or import into another store. Spam and trash are left out.', 'ffl-funnels-addons') . '</p></div>';
        echo '<div class="wb-card__body"><div class="ffla-pr-inline-actions">';
        echo '<a class="wb-btn wb-btn--primary" href="' . esc_url($export('reviews')) . '">' . esc_html__('Export reviews', 'ffl-funnels-addons') . '</a>';
        echo '<a class="wb-btn wb-btn--subtle" href="' . esc_url($export('qna')) . '">' . esc_html__('Export questions and answers', 'ffl-funnels-addons') . '</a>';
        echo '</div>';
        echo '<p class="wb-field__desc">' . esc_html(sprintf(
            /* translators: %s: column names */
            __('Review columns: %s. Replies are rows with parent_id; photos and videos are URLs separated by “|”; criteria look like quality=5;value=4.', 'ffl-funnels-addons'),
            implode(', ', Product_Reviews_Import::REVIEW_COLUMNS)
        )) . '</p>';
        echo '<p class="wb-field__desc">' . esc_html__('The file holds customers’ names and email addresses: store it like other customer data.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';

        // Import.
        $job = Product_Reviews_Import::job();
        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Import', 'ffl-funnels-addons') . '</h3>'
            . '<p>' . esc_html__('Add reviews or questions from a CSV file, for example from another store or another reviews plugin. The first row names the columns.', 'ffl-funnels-addons') . '</p></div>';
        echo '<div class="wb-card__body">';

        if ($job !== null) {
            self::render_job($job);
        }

        if (!Product_Reviews_Import::job_running()) {
            echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::IMPORT_ACTION) . '">';
            wp_nonce_field(self::IMPORT_ACTION);

            echo '<div class="wb-field"><label class="wb-field__label" for="ffla-import-kind">' . esc_html__('What the file holds', 'ffl-funnels-addons') . '</label><div class="wb-field__control">';
            echo '<select class="wb-select" id="ffla-import-kind" name="kind"><option value="reviews">' . esc_html__('Reviews (and replies)', 'ffl-funnels-addons') . '</option><option value="qna">' . esc_html__('Questions and answers', 'ffl-funnels-addons') . '</option></select>';
            echo '</div></div>';

            echo '<div class="wb-field"><label class="wb-field__label" for="ffla-import-mode">' . esc_html__('Status', 'ffl-funnels-addons') . '</label><div class="wb-field__control">';
            echo '<select class="wb-select" id="ffla-import-mode" name="mode"><option value="keep">' . esc_html__('As in the file (approved when the status column is empty)', 'ffl-funnels-addons') . '</option><option value="pending">' . esc_html__('Everything waits for approval', 'ffl-funnels-addons') . '</option></select>';
            echo '</div></div>';

            echo '<div class="wb-field"><label class="wb-field__label" for="ffla-import-file">' . esc_html__('CSV file', 'ffl-funnels-addons') . '</label><div class="wb-field__control">';
            echo '<input type="file" id="ffla-import-file" name="file" accept=".csv,text/csv" required>';
            echo '<p class="wb-field__desc">' . esc_html__('Up to 20 MB. Needed: content and product_id or product_sku. Understood too: rating, author, email, date or date_gmt, status, verified, pinned, criteria, media (URLs, downloaded into the Media Library), helpful, not_helpful, parent_id with store_reply for replies, and the column names of Customer Reviews for WooCommerce exports (review_content, review_score, display_name, parent, date, media). Rows already imported are skipped, so a file can be imported again.', 'ffl-funnels-addons') . '</p>';
            echo '</div></div>';

            echo '<div class="wb-actions-bar"><button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Import', 'ffl-funnels-addons') . '</button></div>';
            echo '</form>';
        }
        echo '</div></div>';

        // Migration.
        $candidates = count(Product_Reviews_Import::cusrev_candidates(1));
        $state      = get_option(Product_Reviews_Import::MIGRATE_OPTION, []);
        if ($candidates > 0 || is_array($state) && !empty($state)) {
            echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('From Customer Reviews for WooCommerce', 'ffl-funnels-addons') . '</h3>'
                . '<p>' . esc_html__('Reviews are WooCommerce reviews in both plugins, so they already show here with their stars. This copies the photos and videos (the same files, nothing is downloaded) and the helpful votes that plugin kept on its own.', 'ffl-funnels-addons') . '</p></div><div class="wb-card__body">';
            if (is_array($state) && !empty($state)) {
                echo '<p>' . esc_html(sprintf(
                    /* translators: 1: status, 2: reviews, 3: media files, 4: reviews with votes */
                    __('%1$s: %2$d reviews checked, %3$d photos or videos and %4$d vote counts copied.', 'ffl-funnels-addons'),
                    'done' === ($state['status'] ?? '') ? __('Done', 'ffl-funnels-addons') : __('Running', 'ffl-funnels-addons'),
                    (int) ($state['reviews'] ?? 0),
                    (int) ($state['media'] ?? 0),
                    (int) ($state['votes'] ?? 0)
                )) . '</p>';
            }
            if ('running' !== ($state['status'] ?? '')) {
                $url = wp_nonce_url(add_query_arg(['action' => self::MIGRATE_ACTION], admin_url('admin-post.php')), self::MIGRATE_ACTION);
                echo '<p><a class="wb-btn wb-btn--subtle" href="' . esc_url($url) . '">' . esc_html__('Copy photos and votes', 'ffl-funnels-addons') . '</a></p>';
                echo '<p class="wb-field__desc">' . esc_html__('Safe to run more than once; photos and votes already here are kept.', 'ffl-funnels-addons') . '</p>';
            }
            echo '</div></div>';
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private static function render_job(array $job): void
    {
        $labels = [
            'queued'    => __('Waiting to start', 'ffl-funnels-addons'),
            'running'   => __('Importing', 'ffl-funnels-addons'),
            'done'      => __('Finished', 'ffl-funnels-addons'),
            'cancelled' => __('Cancelled', 'ffl-funnels-addons'),
            'failed'    => __('Stopped', 'ffl-funnels-addons'),
        ];
        $status  = (string) ($job['status'] ?? '');
        $running = in_array($status, ['queued', 'running'], true);

        echo '<div class="ffla-pr-import-status">';
        echo '<p><strong>' . esc_html($labels[$status] ?? $status) . '</strong> · '
            . esc_html('qna' === ($job['kind'] ?? '') ? __('Questions and answers', 'ffl-funnels-addons') : __('Reviews', 'ffl-funnels-addons')) . ' · '
            . esc_html(sprintf(
                /* translators: 1: imported rows, 2: skipped rows */
                __('%1$d imported, %2$d skipped', 'ffl-funnels-addons'),
                (int) ($job['imported'] ?? 0),
                (int) ($job['skipped'] ?? 0)
            )) . '</p>';

        if (!empty($job['messages'])) {
            echo '<details' . ($running ? '' : ' open') . '><summary>' . esc_html__('Notes', 'ffl-funnels-addons') . '</summary><ul class="ffla-pr-import-notes">';
            foreach ((array) $job['messages'] as $message) {
                echo '<li>' . esc_html((string) $message) . '</li>';
            }
            echo '</ul></details>';
        }

        $cancel = wp_nonce_url(add_query_arg(['action' => self::CANCEL_ACTION], admin_url('admin-post.php')), self::CANCEL_ACTION);
        if ($running) {
            // Reload while it runs, so the numbers move.
            echo '<meta http-equiv="refresh" content="5">';
            echo '<p class="wb-field__desc">' . esc_html__('This page reloads every few seconds. The import continues if you leave it.', 'ffl-funnels-addons') . '</p>';
            echo '<p><a href="' . esc_url($cancel) . '">' . esc_html__('Cancel the import', 'ffl-funnels-addons') . '</a></p>';
        } else {
            echo '<p><a href="' . esc_url($cancel) . '">' . esc_html__('Clear this result', 'ffl-funnels-addons') . '</a></p>';
        }
        echo '</div>';
    }

    public function handle_export(): void
    {
        check_admin_referer(self::EXPORT_ACTION);
        if (!current_user_can('manage_woocommerce')) {
            self::deny();
        }

        $kind = isset($_GET['kind']) && 'qna' === $_GET['kind'] ? 'qna' : 'reviews';
        $name = ('qna' === $kind ? 'product-questions-' : 'product-reviews-') . gmdate('Y-m-d') . '.csv';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');

        $out = fopen('php://output', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        // A BOM so Excel reads the file as UTF-8.
        fwrite($out, "\xEF\xBB\xBF"); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ('qna' === $kind) {
            Product_Reviews_Import::export_qna($out);
        } else {
            Product_Reviews_Import::export_reviews($out);
        }
        fclose($out); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    public function handle_import(): void
    {
        check_admin_referer(self::IMPORT_ACTION);
        if (!current_user_can('manage_woocommerce')) {
            self::deny();
        }

        $kind   = isset($_POST['kind']) ? sanitize_key(wp_unslash($_POST['kind'])) : 'reviews';
        $mode   = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'keep';
        $upload = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in start_job().

        $result = Product_Reviews_Import::start_job($upload, $kind, $mode);

        wp_safe_redirect(self::page_url(['tools_notice' => $result['message'], 'tools_type' => $result['ok'] ? 'success' : 'error']));
        exit;
    }

    public function handle_cancel(): void
    {
        check_admin_referer(self::CANCEL_ACTION);
        if (!current_user_can('manage_woocommerce')) {
            self::deny();
        }

        Product_Reviews_Import::cancel_job();
        wp_safe_redirect(self::page_url());
        exit;
    }

    public function handle_migrate(): void
    {
        check_admin_referer(self::MIGRATE_ACTION);
        if (!current_user_can('manage_woocommerce')) {
            self::deny();
        }

        Product_Reviews_Import::start_migration();
        wp_safe_redirect(self::page_url(['tools_notice' => __('Copying photos and votes in the background.', 'ffl-funnels-addons')]));
        exit;
    }
}
