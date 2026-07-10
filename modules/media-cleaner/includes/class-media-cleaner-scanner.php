<?php
/**
 * Media Cleaner — scan orchestration.
 *
 * Holds the scan as a resumable job in an option and advances it one bounded
 * batch per step(), so a browser (or WP-CLI) can drive a large library to
 * completion without any single request timing out.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Cleaner_Scanner
{
    const JOB_OPTION = 'ffla_mclean_job';

    /** @var Media_Cleaner_Engine */
    private $engine;

    public function __construct(Media_Cleaner_Engine $engine)
    {
        $this->engine = $engine;
    }

    /* =====================================================================
     * Lifecycle
     * ================================================================== */

    /**
     * Plan a scan from the current settings and store it as the active job.
     *
     * @return array The initial job/progress payload.
     */
    public function start(): array
    {
        $library    = '1' === Media_Cleaner_Core::get_setting('scan_media_library', '1');
        $filesystem = '1' === Media_Cleaner_Core::get_setting('scan_filesystem', '0');
        $duplicates = '1' === Media_Cleaner_Core::get_setting('detect_duplicates', '0');
        $content    = '1' === Media_Cleaner_Core::get_setting('scan_content', '1');

        if (!$library && !$filesystem && !$duplicates) {
            // Nothing selected — default to the safe library scan.
            $library = true;
        }

        $posts_batch = max(1, (int) Media_Cleaner_Core::get_setting('posts_per_batch', '30'));
        $media_batch = max(1, (int) Media_Cleaner_Core::get_setting('media_per_batch', '80'));
        $files_batch = max(1, (int) Media_Cleaner_Core::get_setting('files_per_batch', '100'));

        $post_total  = $this->engine->count_posts();
        $media_total = $this->engine->count_media();

        // Content references are needed whenever we decide "unused": for an
        // unused-media scan, or to know which loose files content still points
        // at. A pure broken-only or duplicates run can skip them.
        $need_content_refs = $filesystem || ($library && $content);

        $phases = [];
        $phases[] = ['key' => 'reset', 'label' => __('Preparing', 'ffl-funnels-addons'), 'kind' => 'single'];

        if ($need_content_refs) {
            $phases[] = ['key' => 'refs_content', 'label' => __('Reading content', 'ffl-funnels-addons'), 'kind' => 'paged', 'total' => $post_total, 'batch' => $posts_batch, 'offset' => 0];
        }
        if ($filesystem) {
            $phases[] = ['key' => 'refs_library', 'label' => __('Indexing the library', 'ffl-funnels-addons'), 'kind' => 'paged', 'total' => $media_total, 'batch' => $media_batch, 'offset' => 0];
        }
        if ($library) {
            $phases[] = ['key' => 'check_media', 'label' => __('Checking the media library', 'ffl-funnels-addons'), 'kind' => 'paged', 'total' => $media_total, 'batch' => $media_batch, 'offset' => 0];
        }
        if ($filesystem) {
            $phases[] = ['key' => 'build_files', 'label' => __('Listing files', 'ffl-funnels-addons'), 'kind' => 'single'];
            $phases[] = ['key' => 'check_files', 'label' => __('Checking files on disk', 'ffl-funnels-addons'), 'kind' => 'paged', 'total' => 0, 'batch' => $files_batch, 'offset' => 0];
        }
        if ($duplicates) {
            $phases[] = ['key' => 'check_duplicates', 'label' => __('Finding duplicates', 'ffl-funnels-addons'), 'kind' => 'paged', 'total' => $media_total, 'batch' => $media_batch, 'offset' => 0];
        }
        if ($filesystem) {
            $phases[] = ['key' => 'cleanup', 'label' => __('Finishing', 'ffl-funnels-addons'), 'kind' => 'single'];
        }

        $job = [
            'running'     => true,
            'phase_index' => 0,
            'phases'      => $phases,
            'started'     => time(),
            'found'       => 0,
        ];

        update_option(self::JOB_OPTION, $job, false);

        return $this->progress($job);
    }

    public function abort(): void
    {
        $this->engine->clear_file_list();
        delete_option(self::JOB_OPTION);
    }

    public function get_job(): ?array
    {
        $job = get_option(self::JOB_OPTION, null);

        return is_array($job) ? $job : null;
    }

    public function is_running(): bool
    {
        $job = $this->get_job();

        return $job && !empty($job['running']);
    }

    /* =====================================================================
     * Stepping
     * ================================================================== */

    /**
     * Run one batch and advance. Returns a progress payload.
     */
    public function step(): array
    {
        $job = $this->get_job();
        if (!$job || empty($job['running'])) {
            return ['running' => false, 'done' => true, 'percent' => 100, 'message' => __('No scan in progress.', 'ffl-funnels-addons')];
        }

        $index = (int) $job['phase_index'];
        if (!isset($job['phases'][$index])) {
            return $this->finish($job);
        }

        $phase = &$job['phases'][$index];

        if ($phase['kind'] === 'single') {
            $this->run_single($phase['key'], $job);
            $job['phase_index'] = $index + 1;
        } else {
            $offset = (int) $phase['offset'];
            $batch  = (int) $phase['batch'];
            $this->run_paged($phase['key'], $offset, $batch);

            $phase['offset'] = $offset + $batch;
            if ($phase['offset'] >= (int) $phase['total']) {
                $job['phase_index'] = $index + 1;
            }
        }

        // Reached the end?
        if ((int) $job['phase_index'] >= count($job['phases'])) {
            return $this->finish($job);
        }

        update_option(self::JOB_OPTION, $job, false);

        return $this->progress($job);
    }

    private function run_single(string $key, array &$job): void
    {
        switch ($key) {
            case 'reset':
                $this->engine->reset();
                break;

            case 'build_files':
                $count = $this->engine->build_file_list();
                // Feed the count into the check_files phase that follows.
                foreach ($job['phases'] as &$p) {
                    if ($p['key'] === 'check_files') {
                        $p['total'] = $count;
                        break;
                    }
                }
                unset($p);
                break;

            case 'cleanup':
                $this->engine->clear_file_list();
                break;
        }
    }

    private function run_paged(string $key, int $offset, int $batch): void
    {
        switch ($key) {
            case 'refs_content':
                $this->engine->refs_from_content_batch($offset, $batch);
                break;
            case 'refs_library':
                $this->engine->refs_from_library_batch($offset, $batch);
                break;
            case 'check_media':
                $this->engine->check_media_batch($offset, $batch);
                break;
            case 'check_files':
                $this->engine->check_files_batch($offset, $batch);
                break;
            case 'check_duplicates':
                $this->engine->check_duplicates_batch($offset, $batch);
                break;
        }
    }

    private function finish(array $job): array
    {
        $this->engine->clear_file_list();
        $job['running'] = false;
        delete_option(self::JOB_OPTION);

        return [
            'running' => false,
            'done'    => true,
            'percent' => 100,
            'phase'   => __('Done', 'ffl-funnels-addons'),
            'message' => __('Scan complete.', 'ffl-funnels-addons'),
        ];
    }

    /* =====================================================================
     * Progress
     * ================================================================== */

    private function progress(array $job): array
    {
        $phases = $job['phases'];
        $count  = count($phases);
        $index  = (int) $job['phase_index'];

        // Whole phases done + fractional progress of the current one.
        $intra = 0.0;
        $label = __('Working…', 'ffl-funnels-addons');
        if (isset($phases[$index])) {
            $label = $phases[$index]['label'];
            if ($phases[$index]['kind'] === 'paged') {
                $total = max(1, (int) $phases[$index]['total']);
                $intra = min(1, (int) $phases[$index]['offset'] / $total);
            }
        }

        $percent = $count > 0 ? (int) round((($index + $intra) / $count) * 100) : 100;
        $percent = max(1, min(99, $percent));

        return [
            'running' => true,
            'done'    => false,
            'percent' => $percent,
            'phase'   => $label,
            'message' => $label . '…',
        ];
    }
}
