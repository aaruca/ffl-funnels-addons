<?php
/**
 * WSS Sync Orchestrator — Runs bidirectional sync per sheet tab group in order.
 *
 * Sheet→Woo order: groups are processed in array order; later groups overwrite Woo
 * if the same variation_id appears in multiple tabs (last tab wins).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Sync_Orchestrator
{
    /**
     * Run full sync for all configured groups.
     *
     * @return array<string,mixed>
     */
    public static function run_all(WSS_Google_Sheets $sheets, WSS_Logger $logger): array
    {
        WSS_Sync_Groups::ensure_migrated();

        $settings = get_option('wss_settings', []);
        if (empty($settings['sheet_id'])) {
            return ['error' => __('No Google Sheet ID configured.', 'ffl-funnels-addons')];
        }

        $groups = WSS_Sync_Groups::get_groups();
        if ($groups === []) {
            return ['error' => __('No sheet tab groups configured. Add a tab group on the WSS Dashboard.', 'ffl-funnels-addons')];
        }

        $group_results = [];
        $totals_w       = ['updated' => 0, 'appended' => 0, 'skipped' => 0, 'errors' => 0];
        $totals_s       = ['updated' => 0, 'created' => 0, 'skipped' => 0, 'errors' => 0];

        // Skip duplicate tab targets in the same run so we never issue two
        // full Google Sheets reads against the same spreadsheet+tab.
        $processed_tabs = [];

        foreach ($groups as $group) {
            $tab = trim((string) ($group['tab_name'] ?? ''));
            if ($tab === '') {
                continue;
            }

            $tab_key = strtolower($tab);
            if (isset($processed_tabs[$tab_key])) {
                $group_results[] = [
                    'group_id' => (string) ($group['id'] ?? ''),
                    'tab_name' => $tab,
                    'skipped'  => true,
                    'reason'   => sprintf(
                        /* translators: %s: tab name. */
                        __('Skipped duplicate sync for tab "%s" (already processed in this run).', 'ffl-funnels-addons'),
                        $tab
                    ),
                ];
                continue;
            }
            $processed_tabs[$tab_key] = true;

            $allowed = WSS_Sync_Groups::resolve_parent_product_ids($group);
            $gid     = (string) ($group['id'] ?? '');

            $engine = new WSS_Sync_Engine($sheets, $logger, $settings, [
                'tab_name'                 => $tab,
                'allowed_parent_product_ids' => $allowed,
                'group_id'                 => $gid,
                'persist_last_sync'        => false,
            ]);

            $result = $engine->run();

            if (isset($result['error'])) {
                $group_results[] = [
                    'group_id'   => $gid,
                    'tab_name'   => $tab,
                    'error'      => $result['error'],
                ];

                update_option('wss_last_sync', [
                    'time'          => current_time('mysql'),
                    'error'         => $result['error'],
                    'groups'        => $group_results,
                    'woo_to_sheet'  => $totals_w,
                    'sheet_to_woo'  => $totals_s,
                ], false);

                return [
                    'error'  => $result['error'],
                    'groups' => $group_results,
                ];
            }

            $w = $result['woo_to_sheet'] ?? [];
            $s = $result['sheet_to_woo'] ?? [];

            foreach (['updated', 'appended', 'skipped', 'errors'] as $k) {
                $totals_w[$k] += (int) ($w[$k] ?? 0);
            }
            foreach (['updated', 'created', 'skipped', 'errors'] as $k) {
                $totals_s[$k] += (int) ($s[$k] ?? 0);
            }

            $group_results[] = [
                'group_id'     => $gid,
                'tab_name'     => $tab,
                'woo_to_sheet' => $w,
                'sheet_to_woo' => $s,
            ];
        }

        update_option('wss_last_sync', [
            'time'         => current_time('mysql'),
            'groups'       => $group_results,
            'woo_to_sheet' => $totals_w,
            'sheet_to_woo' => $totals_s,
        ], false);

        return [
            'woo_to_sheet' => $totals_w,
            'sheet_to_woo' => $totals_s,
            'groups'       => $group_results,
        ];
    }
}
