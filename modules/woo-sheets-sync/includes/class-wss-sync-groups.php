<?php
/**
 * WSS Sync Groups — Sheet tab groups, membership resolution, migration, meta sync.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Sync_Groups
{
    public const SETTINGS_KEY = 'wss_settings';

    /**
     * Return normalized sync groups from settings (may be empty before migration).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function get_groups(): array
    {
        $settings = get_option(self::SETTINGS_KEY, []);
        $groups    = $settings['sync_groups'] ?? [];

        return is_array($groups) ? self::sanitize_groups($groups) : [];
    }

    /**
     * Persist sync groups into wss_settings and sync first tab to legacy tab_name.
     *
     * @param array<int,array<string,mixed>> $groups
     */
    public static function save_groups(array $groups): void
    {
        $settings = get_option(self::SETTINGS_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['sync_groups'] = self::sanitize_groups($groups);

        if (!empty($settings['sync_groups'][0]['tab_name'])) {
            $settings['tab_name'] = (string) $settings['sync_groups'][0]['tab_name'];
        }

        update_option(self::SETTINGS_KEY, $settings, false);
        self::sync_enabled_meta_from_groups();
    }

    /**
     * One-time migration: empty sync_groups + existing sheet → one group from tab_name + _wss_sync_enabled products.
     */
    public static function ensure_migrated(): void
    {
        $settings = get_option(self::SETTINGS_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $existing = $settings['sync_groups'] ?? null;
        if (is_array($existing) && $existing !== []) {
            return;
        }

        $tab_name = isset($settings['tab_name']) ? sanitize_text_field((string) $settings['tab_name']) : '';
        if ($tab_name === '') {
            $tab_name = 'Inventory';
        }

        $linked = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'meta_key'       => '_wss_sync_enabled',
            'meta_value'     => '1',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);

        $settings['sync_groups'] = [
            [
                'id'            => wp_generate_uuid4(),
                'tab_name'      => $tab_name,
                'product_ids'   => array_map('intval', $linked ?: []),
                'category_ids'  => [],
                'tag_ids'       => [],
            ],
        ];

        update_option(self::SETTINGS_KEY, $settings, false);
    }

    /**
     * Resolve parent product IDs for a single group (explicit + category + tag).
     *
     * @param array<string,mixed> $group
     * @return int[]
     */
    public static function resolve_parent_product_ids(array $group): array
    {
        $ids = [];

        foreach ($group['product_ids'] ?? [] as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }

        foreach ($group['category_ids'] ?? [] as $term_id) {
            $ids = array_merge($ids, self::get_product_ids_by_taxonomy('product_cat', (int) $term_id));
        }

        foreach ($group['tag_ids'] ?? [] as $term_id) {
            $ids = array_merge($ids, self::get_product_ids_by_taxonomy('product_tag', (int) $term_id));
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids;
    }

    /**
     * Union of all parent product IDs across all groups.
     *
     * @param array<int,array<string,mixed>> $groups
     * @return int[]
     */
    public static function resolve_all_linked_parent_ids(array $groups): array
    {
        $all = [];
        foreach ($groups as $group) {
            $all = array_merge($all, self::resolve_parent_product_ids($group));
        }

        return array_values(array_unique(array_filter(array_map('intval', $all))));
    }

    /**
     * Tab names (non-empty) that include this parent product ID.
     *
     * @return string[]
     */
    public static function get_tab_names_for_product_id(int $product_id): array
    {
        $product_id = (int) $product_id;
        if ($product_id <= 0) {
            return [];
        }

        $tabs = [];
        foreach (self::get_groups() as $group) {
            $tab = trim((string) ($group['tab_name'] ?? ''));
            if ($tab === '') {
                continue;
            }

            $resolved = self::resolve_parent_product_ids($group);
            if (in_array($product_id, $resolved, true)) {
                $tabs[] = $tab;
            }
        }

        return array_values(array_unique($tabs));
    }

    /**
     * Set _wss_sync_enabled for every product in the union of groups; remove for others that were enabled.
     */
    public static function sync_enabled_meta_from_groups(): void
    {
        $union = self::resolve_all_linked_parent_ids(self::get_groups());

        foreach ($union as $pid) {
            update_post_meta((int) $pid, '_wss_sync_enabled', '1');
        }

        $enabled_posts = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'meta_key'       => '_wss_sync_enabled',
            'meta_value'     => '1',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ]);

        foreach ($enabled_posts as $pid) {
            if (!in_array((int) $pid, $union, true)) {
                delete_post_meta((int) $pid, '_wss_sync_enabled');
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     * @return array<int,array<string,mixed>>
     */
    public static function sanitize_groups(array $groups): array
    {
        $out = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $id = isset($group['id']) ? sanitize_text_field((string) $group['id']) : '';
            if ($id === '') {
                $id = wp_generate_uuid4();
            }

            $tab = isset($group['tab_name']) ? trim(wp_strip_all_tags((string) $group['tab_name'])) : '';
            $tab = preg_replace('/[\[\]\*\/\\\?\:]/', '', $tab) ?? '';
            $tab = trim((string) $tab);
            if ($tab === '') {
                $tab = 'Inventory';
            }

            $out[] = [
                'id'            => $id,
                'tab_name'      => $tab,
                'product_ids'   => self::sanitize_id_list($group['product_ids'] ?? []),
                'category_ids'  => self::sanitize_id_list($group['category_ids'] ?? []),
                'tag_ids'       => self::sanitize_id_list($group['tag_ids'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param array<int|string> $list
     * @return int[]
     */
    private static function sanitize_id_list($list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $ids = [];
        foreach ($list as $v) {
            $i = (int) $v;
            if ($i > 0) {
                $ids[] = $i;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return int[]
     */
    private static function get_product_ids_by_taxonomy(string $taxonomy, int $term_id): array
    {
        if ($term_id <= 0 || !taxonomy_exists($taxonomy)) {
            return [];
        }

        $posts = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_tax_query_meta_query
                [
                    'taxonomy' => $taxonomy,
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ],
            ],
        ]);

        return array_map('intval', $posts ?: []);
    }
}
