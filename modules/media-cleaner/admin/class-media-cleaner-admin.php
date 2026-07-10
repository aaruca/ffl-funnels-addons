<?php
/**
 * Media Cleaner — admin screen.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Admin
{
    const PAGE_SLUG = 'ffla-media-cleaner';

    public function init(): void
    {
        add_action('admin_post_ffla_mclean_save_settings', [$this, 'handle_settings_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets($hook): void
    {
        if (!isset($_GET['page']) || sanitize_key(wp_unslash($_GET['page'])) !== self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'ffla-media-cleaner',
            FFLA_URL . 'modules/media-cleaner/admin/css/media-cleaner-admin.css',
            [],
            FFLA_VERSION
        );

        wp_enqueue_script(
            'ffla-media-cleaner',
            FFLA_URL . 'modules/media-cleaner/admin/js/media-cleaner-admin.js',
            [],
            FFLA_VERSION,
            true
        );

        wp_localize_script('ffla-media-cleaner', 'fflaMediaCleaner', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(Media_Cleaner_Ajax::NONCE),
            'i18n'    => [
                'confirmTrash'      => __('Move the selected items to the media trash? This is reversible.', 'ffl-funnels-addons'),
                'confirmDelete'     => __('Permanently delete the selected items? This cannot be undone.', 'ffl-funnels-addons'),
                'confirmEmptyTrash' => __('Permanently delete everything in the trash? This cannot be undone.', 'ffl-funnels-addons'),
                'scanning'          => __('Scanning…', 'ffl-funnels-addons'),
                'scanComplete'      => __('Scan complete.', 'ffl-funnels-addons'),
                'scanError'         => __('The scan hit an error. It has been stopped.', 'ffl-funnels-addons'),
                'nothingSelected'   => __('Select at least one item first.', 'ffl-funnels-addons'),
                'working'           => __('Working…', 'ffl-funnels-addons'),
            ],
        ]);
    }

    /* =====================================================================
     * Page
     * ================================================================== */

    public function render_settings_content(): void
    {
        $settings = Media_Cleaner_Core::get_settings();
        $saved    = isset($_GET['settings-updated']) && '1' === sanitize_text_field(wp_unslash($_GET['settings-updated']));

        if ($saved) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        $this->render_intro();
        $this->render_scan_panel();
        $this->render_results_panel();
        $this->render_settings_form($settings);
    }

    private function render_intro(): void
    {
        echo '<div class="wb-card">';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__(
            'Find media that no page, product, template, or design references, then move it to a reversible trash. This scanner understands Bricks (content, headers, footers, templates, and global styles) and this plugin\'s own data — customer review photos, loadout images, and bundle images — so those are never flagged by mistake.',
            'ffl-funnels-addons'
        ) . '</p>';
        echo '<p class="wb-field__desc"><strong>' . esc_html__('Always back up your database and uploads before deleting media in bulk.', 'ffl-funnels-addons') . '</strong> '
            . esc_html__('Trashed files are recoverable until you empty the trash, but a backup is your real safety net.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';
    }

    private function render_scan_panel(): void
    {
        echo '<div class="wb-card ffla-mclean-scan">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Scan', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        echo '<div class="ffla-mclean-stats" id="ffla-mclean-stats">';
        echo self::render_stats((new Media_Cleaner_Manager(new Media_Cleaner_Core()))->get_stats()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';

        echo '<div class="ffla-mclean-scan__controls">';
        echo '<button type="button" class="wb-btn wb-btn--primary" id="ffla-mclean-scan-btn">' . esc_html__('Start scan', 'ffl-funnels-addons') . '</button>';
        echo '<button type="button" class="wb-btn" id="ffla-mclean-abort-btn" hidden>' . esc_html__('Stop', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '<div class="ffla-mclean-progress" id="ffla-mclean-progress" hidden>';
        echo '<div class="ffla-mclean-progress__bar"><span id="ffla-mclean-progress-fill"></span></div>';
        echo '<p class="ffla-mclean-progress__label" id="ffla-mclean-progress-label"></p>';
        echo '</div>';

        echo '</div></div>';
    }

    private function render_results_panel(): void
    {
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Results', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        // Tabs.
        echo '<div class="ffla-mclean-tabs" id="ffla-mclean-tabs">';
        foreach ([
            'active'  => __('Issues', 'ffl-funnels-addons'),
            'ignored' => __('Ignored', 'ffl-funnels-addons'),
            'trashed' => __('Trash', 'ffl-funnels-addons'),
        ] as $key => $label) {
            $cls = 'ffla-mclean-tab' . ('active' === $key ? ' is-active' : '');
            echo '<button type="button" class="' . esc_attr($cls) . '" data-status="' . esc_attr($key) . '">' . esc_html($label) . '</button>';
        }
        echo '</div>';

        // Toolbar.
        echo '<div class="ffla-mclean-toolbar">';
        echo '<input type="search" id="ffla-mclean-search" class="wb-input" placeholder="' . esc_attr__('Search by path…', 'ffl-funnels-addons') . '">';
        echo '<span class="ffla-mclean-toolbar__actions" id="ffla-mclean-bulk-actions"></span>';
        echo '<button type="button" class="wb-btn wb-btn--danger" id="ffla-mclean-empty-trash" hidden>' . esc_html__('Empty trash', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        // Table.
        echo '<table class="wp-list-table widefat fixed striped ffla-mclean-table">';
        echo '<thead><tr>';
        echo '<td class="check-column"><input type="checkbox" id="ffla-mclean-check-all"></td>';
        echo '<th>' . esc_html__('File', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Issue', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Size', 'ffl-funnels-addons') . '</th>';
        echo '<th>' . esc_html__('Actions', 'ffl-funnels-addons') . '</th>';
        echo '</tr></thead>';
        echo '<tbody id="ffla-mclean-rows"><tr><td colspan="5">' . esc_html__('Run a scan to see results.', 'ffl-funnels-addons') . '</td></tr></tbody>';
        echo '</table>';

        echo '<div class="ffla-mclean-pagination" id="ffla-mclean-pagination"></div>';

        echo '</div></div>';
    }

    private function render_settings_form(array $settings): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="ffla_mclean_save_settings">';
        wp_nonce_field('ffla_mclean_save_settings_nonce', '_ffla_mclean_nonce');

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('What to scan', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Scan the media library for unused media', 'ffl-funnels-addons'),
            'scan_media_library',
            $settings['scan_media_library'],
            __('Flags attachments that no content references, and attachments whose file is missing.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Check references in content', 'ffl-funnels-addons'),
            'scan_content',
            $settings['scan_content'],
            __('When off, only broken (missing-file) media is reported, and nothing is judged "unused". Leave on for a real cleanup.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Scan the uploads folder for orphan files', 'ffl-funnels-addons'),
            'scan_filesystem',
            $settings['scan_filesystem'],
            __('Finds files on disk that are not in the media library at all. More thorough, and higher risk — some plugins keep legitimate files outside the library.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Detect duplicate files', 'ffl-funnels-addons'),
            'detect_duplicates',
            $settings['detect_duplicates'],
            __('Flags byte-for-byte identical files, keeping the first as the original.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Deletion', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Skip the trash (delete immediately)', 'ffl-funnels-addons'),
            'skip_trash',
            $settings['skip_trash'],
            __('Strongly discouraged. When on, "Trash" deletes permanently with no recovery. Leave off so removals move to a reversible trash first.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_select_field(
            __('Auto-empty the trash', 'ffl-funnels-addons'),
            'trash_auto_empty_days',
            (string) $settings['trash_auto_empty_days'],
            [
                '0'  => __('Never (empty it manually)', 'ffl-funnels-addons'),
                '7'  => __('After 7 days', 'ffl-funnels-addons'),
                '30' => __('After 30 days', 'ffl-funnels-addons'),
                '90' => __('After 90 days', 'ffl-funnels-addons'),
            ],
            __('How long trashed media is kept before it is deleted for good.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h3>' . esc_html__('Performance', 'ffl-funnels-addons') . '</h3></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__('How much work each scan batch does. Lower these if a scan times out on a small host; raise them to scan faster on a strong one.', 'ffl-funnels-addons') . '</p>';

        FFLA_Admin::render_text_field(__('Posts per batch', 'ffl-funnels-addons'), 'posts_per_batch', (string) $settings['posts_per_batch'], '');
        FFLA_Admin::render_text_field(__('Media per batch', 'ffl-funnels-addons'), 'media_per_batch', (string) $settings['media_per_batch'], '');
        FFLA_Admin::render_text_field(__('Files per batch', 'ffl-funnels-addons'), 'files_per_batch', (string) $settings['files_per_batch'], '');

        echo '</div></div>';

        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        echo '</form>';
    }

    /* =====================================================================
     * Fragments rendered here and returned by AJAX
     * ================================================================== */

    /**
     * @param array{active:int,active_size:int,trashed:int,ignored:int} $stats
     */
    public static function render_stats(array $stats): string
    {
        $cards = [
            ['label' => __('Issues found', 'ffl-funnels-addons'), 'value' => number_format_i18n($stats['active'])],
            ['label' => __('Reclaimable', 'ffl-funnels-addons'), 'value' => size_format($stats['active_size'], 1) ?: '0 B'],
            ['label' => __('In trash', 'ffl-funnels-addons'), 'value' => number_format_i18n($stats['trashed'])],
            ['label' => __('Ignored', 'ffl-funnels-addons'), 'value' => number_format_i18n($stats['ignored'])],
        ];

        $html = '';
        foreach ($cards as $card) {
            $html .= '<div class="ffla-mclean-stat">'
                . '<span class="ffla-mclean-stat__value">' . esc_html($card['value']) . '</span>'
                . '<span class="ffla-mclean-stat__label">' . esc_html($card['label']) . '</span>'
                . '</div>';
        }

        return $html;
    }

    /**
     * @param array<int,object> $items
     */
    public static function render_rows(array $items, string $status): string
    {
        if (empty($items)) {
            return '<tr><td colspan="5">' . esc_html__('Nothing here.', 'ffl-funnels-addons') . '</td></tr>';
        }

        $html = '';
        foreach ($items as $issue) {
            $id      = (int) $issue->id;
            $path    = (string) $issue->path;
            $size    = (int) $issue->size;
            $post_id = (int) $issue->post_id;

            $html .= '<tr data-id="' . esc_attr((string) $id) . '">';
            $html .= '<th scope="row" class="check-column"><input type="checkbox" class="ffla-mclean-cb" value="' . esc_attr((string) $id) . '"></th>';

            // File cell — thumbnail (when it is a real image still on disk) + path.
            $html .= '<td class="ffla-mclean-file">';
            $thumb = self::row_thumbnail($issue);
            if ($thumb !== '') {
                $html .= $thumb;
            }
            $html .= '<span class="ffla-mclean-file__path">' . esc_html($path) . '</span>';
            if ($post_id > 0) {
                $edit = get_edit_post_link($post_id);
                if ($edit) {
                    $html .= ' <a href="' . esc_url($edit) . '" target="_blank" rel="noopener" class="ffla-mclean-file__link">#' . esc_html((string) $post_id) . '</a>';
                }
            }
            $html .= '</td>';

            // Issue badge.
            $html .= '<td>' . self::issue_badge((string) $issue->issue) . '</td>';

            // Size.
            $html .= '<td>' . esc_html($size > 0 ? size_format($size, 1) : '—') . '</td>';

            // Row actions.
            $html .= '<td class="ffla-mclean-actions">' . self::row_actions($status) . '</td>';

            $html .= '</tr>';
        }

        return $html;
    }

    private static function row_thumbnail(object $issue): string
    {
        $post_id = (int) $issue->post_id;
        if ($post_id > 0 && get_post($post_id) && wp_attachment_is_image($post_id)) {
            $img = wp_get_attachment_image($post_id, [40, 40], true, ['class' => 'ffla-mclean-thumb', 'loading' => 'lazy']);
            if ($img) {
                return $img;
            }
        }

        return '<span class="ffla-mclean-thumb ffla-mclean-thumb--placeholder" aria-hidden="true"></span>';
    }

    private static function issue_badge(string $issue): string
    {
        $map = [
            Media_Cleaner_Core::ISSUE_NO_CONTENT  => ['label' => __('Unused', 'ffl-funnels-addons'), 'cls' => 'is-unused'],
            Media_Cleaner_Core::ISSUE_ORPHAN      => ['label' => __('Broken (file missing)', 'ffl-funnels-addons'), 'cls' => 'is-broken'],
            Media_Cleaner_Core::ISSUE_ORPHAN_FILE => ['label' => __('Orphan file', 'ffl-funnels-addons'), 'cls' => 'is-orphan'],
            Media_Cleaner_Core::ISSUE_DUPLICATE   => ['label' => __('Duplicate', 'ffl-funnels-addons'), 'cls' => 'is-dup'],
        ];
        $meta = $map[$issue] ?? ['label' => $issue, 'cls' => ''];

        return '<span class="ffla-mclean-badge ' . esc_attr($meta['cls']) . '">' . esc_html($meta['label']) . '</span>';
    }

    private static function row_actions(string $status): string
    {
        $btn = static function (string $op, string $label, string $extra = ''): string {
            return '<button type="button" class="button-link ffla-mclean-row-action ' . esc_attr($extra) . '" data-op="' . esc_attr($op) . '">' . esc_html($label) . '</button>';
        };

        if ($status === 'trashed') {
            return $btn('restore', __('Restore', 'ffl-funnels-addons'))
                . ' | ' . $btn('delete', __('Delete permanently', 'ffl-funnels-addons'), 'is-danger');
        }

        if ($status === 'ignored') {
            return $btn('unignore', __('Stop ignoring', 'ffl-funnels-addons'));
        }

        return $btn('trash', __('Trash', 'ffl-funnels-addons'))
            . ' | ' . $btn('ignore', __('Ignore', 'ffl-funnels-addons'));
    }

    /* =====================================================================
     * Settings save
     * ================================================================== */

    public function handle_settings_save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }
        check_admin_referer('ffla_mclean_save_settings_nonce', '_ffla_mclean_nonce');

        $current = Media_Cleaner_Core::get_settings();
        $new     = $current;

        foreach (['scan_media_library', 'scan_content', 'scan_filesystem', 'detect_duplicates', 'skip_trash'] as $flag) {
            $new[$flag] = isset($_POST[$flag]) ? '1' : '0';
        }

        $allowed_auto = ['0', '7', '30', '90'];
        $auto = isset($_POST['trash_auto_empty_days']) ? sanitize_text_field(wp_unslash($_POST['trash_auto_empty_days'])) : '0';
        $new['trash_auto_empty_days'] = in_array($auto, $allowed_auto, true) ? $auto : '0';

        $new['posts_per_batch'] = (string) max(1, min(500, isset($_POST['posts_per_batch']) ? absint($_POST['posts_per_batch']) : 30));
        $new['media_per_batch'] = (string) max(1, min(500, isset($_POST['media_per_batch']) ? absint($_POST['media_per_batch']) : 80));
        $new['files_per_batch'] = (string) max(1, min(1000, isset($_POST['files_per_batch']) ? absint($_POST['files_per_batch']) : 100));

        update_option(Media_Cleaner_Core::OPTION, $new, false);
        Media_Cleaner_Core::flush_settings_memo();

        Media_Cleaner_Cron::reschedule();

        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'settings-updated' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }
}
