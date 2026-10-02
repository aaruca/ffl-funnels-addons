<?php
/**
 * Media Cleaner — WP-CLI commands.
 *
 * Usage:
 *   wp ffla-media scan
 *   wp ffla-media status
 *   wp ffla-media list [--status=active|ignored|trashed] [--limit=50]
 *   wp ffla-media trash --all [--yes]
 *   wp ffla-media empty-trash --yes   (also: empty_trash)
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

class Media_Cleaner_CLI
{
    private function factory(): array
    {
        $core = new Media_Cleaner_Core();
        $GLOBALS['ffla_mclean'] = $core;

        return [
            'core'    => $core,
            'scanner' => new Media_Cleaner_Scanner(new Media_Cleaner_Engine($core)),
            'manager' => new Media_Cleaner_Manager($core),
        ];
    }

    /**
     * Run a full scan to completion.
     *
     * [--force]
     * : Start even if a scan from the admin screen is still running.
     */
    public function scan($args, $assoc_args): void
    {
        Media_Cleaner_Core::flush_settings_memo();
        $f = $this->factory();

        $progress = $f['scanner']->start(!empty($assoc_args['force']));
        if (!empty($progress['busy'])) {
            \WP_CLI::error('Another scan is still running (admin screen or another WP-CLI process). Wait for it, or pass --force to start over.');
        }
        \WP_CLI::log('Scanning…');

        $guard = 0;
        do {
            $progress = $f['scanner']->step();
            if (!empty($progress['phase'])) {
                \WP_CLI::log(sprintf('  [%3d%%] %s', (int) ($progress['percent'] ?? 0), $progress['phase']));
            }
            $guard++;
        } while (empty($progress['done']) && $guard < 100000);

        $stats = $f['manager']->get_stats();
        \WP_CLI::success(sprintf(
            'Scan complete. %d issue(s), %s reclaimable.',
            $stats['active'],
            size_format($stats['active_size'], 1)
        ));
    }

    /**
     * Show counts.
     */
    public function status($args, $assoc_args): void
    {
        $f     = $this->factory();
        $stats = $f['manager']->get_stats();

        \WP_CLI::log('Issues:      ' . $stats['active']);
        \WP_CLI::log('Reclaimable: ' . size_format($stats['active_size'], 1));
        \WP_CLI::log('In trash:    ' . $stats['trashed']);
        \WP_CLI::log('Ignored:     ' . $stats['ignored']);
    }

    /**
     * List issues.
     *
     * [--status=<status>]
     * [--limit=<n>]
     */
    public function list($args, $assoc_args): void
    {
        $f      = $this->factory();
        $status = $assoc_args['status'] ?? 'active';
        $limit  = isset($assoc_args['limit']) ? max(1, (int) $assoc_args['limit']) : 50;

        $data = $f['manager']->get_issues($status, 1, $limit);
        if (empty($data['items'])) {
            \WP_CLI::log('No issues.');
            return;
        }

        $rows = [];
        foreach ($data['items'] as $issue) {
            $rows[] = [
                'id'    => $issue->id,
                'issue' => $issue->issue,
                'size'  => size_format((int) $issue->size, 1),
                'path'  => $issue->path,
            ];
        }
        \WP_CLI\Utils\format_items('table', $rows, ['id', 'issue', 'size', 'path']);
        \WP_CLI::log(sprintf('Showing %d of %d.', count($rows), $data['total']));
    }

    /**
     * Move issues to the trash (deletes them when Skip the trash is on).
     *
     * With "Skip the trash" on, asks for confirmation first (or pass --yes).
     *
     * [<ids>...]
     * [--all]
     * [--yes]
     */
    public function trash($args, $assoc_args): void
    {
        $f   = $this->factory();
        $ids = $this->resolve_ids($args, $assoc_args, $f['manager'], 'active');

        if (!$f['core']->uses_trash()) {
            \WP_CLI::confirm(sprintf('"Skip the trash" is on: %d item(s) will be deleted permanently. Continue?', count($ids)), $assoc_args);
        }

        $done = 0;
        foreach ($ids as $id) {
            if ($f['manager']->trash((int) $id)) {
                $done++;
            }
        }
        \WP_CLI::success(sprintf($f['core']->uses_trash() ? 'Trashed %d item(s).' : 'Deleted %d item(s).', $done));
    }

    /**
     * Empty the trash (permanent).
     *
     * [--yes]
     *
     * @subcommand empty-trash
     * @alias empty_trash
     */
    public function empty_trash($args, $assoc_args): void
    {
        \WP_CLI::confirm('Permanently delete everything in the trash?', $assoc_args);
        $f     = $this->factory();
        $count = $f['manager']->empty_trash();
        \WP_CLI::success(sprintf('Deleted %d item(s).', $count));
    }

    /**
     * @return array<int,int>
     */
    private function resolve_ids(array $args, array $assoc_args, Media_Cleaner_Manager $manager, string $status): array
    {
        if (!empty($assoc_args['all'])) {
            $ids  = [];
            $page = 1;
            do {
                $data = $manager->get_issues($status, $page, 200);
                foreach ($data['items'] as $issue) {
                    $ids[] = (int) $issue->id;
                }
                $page++;
            } while (count($ids) < $data['total'] && !empty($data['items']));

            return $ids;
        }

        return array_map('intval', $args);
    }
}

\WP_CLI::add_command('ffla-media', 'Media_Cleaner_CLI');
