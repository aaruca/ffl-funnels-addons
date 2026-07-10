<?php
/**
 * Media Cleaner parser — FFL Funnels Addons' own modules.
 *
 * These references live in custom tables and comment meta, where no generic
 * media cleaner (including the one this module is modelled on) can see them.
 * Without this parser, a scan would report customer-uploaded review photos,
 * loadout hero images, brand logos, and bundle images as "unused" and offer
 * to delete them. That is the whole reason this cleaner is built in-house.
 *
 * Keyed on table existence, not module activation: a deactivated module keeps
 * its data, and that data must still protect its images.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('ffla_mclean_scan_once', 'ffla_mclean_self_scan_once', 10, 0);

function ffla_mclean_self_table_exists(string $table): bool
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
}

/**
 * Collect every attachment ID referenced from a set of columns on a table.
 *
 * @param array<int,string> $columns
 * @return array<int,int>
 */
function ffla_mclean_self_ids_from_table(string $table, array $columns): array
{
    global $wpdb;

    $select = implode(', ', array_map(static function ($c) {
        return '`' . str_replace('`', '', $c) . '`';
    }, $columns));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results("SELECT {$select} FROM {$table}", ARRAY_N);

    $ids = [];
    foreach ((array) $rows as $row) {
        foreach ($row as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
    }

    return $ids;
}

function ffla_mclean_self_scan_once(): void
{
    global $wpdb, $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    // --- Product Reviews: customer-uploaded photos/videos (comment meta). ---
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $review_meta = $wpdb->get_col($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s",
        'ffla_review_media_ids'
    ));
    $review_ids = [];
    foreach ((array) $review_meta as $meta) {
        $decoded = is_serialized($meta) ? @unserialize($meta) : $meta;
        if (is_array($decoded)) {
            foreach ($decoded as $id) {
                if ((int) $id > 0) {
                    $review_ids[] = (int) $id;
                }
            }
        } elseif ((int) $decoded > 0) {
            $review_ids[] = (int) $decoded;
        }
    }
    if (!empty($review_ids)) {
        $ffla_mclean->add_reference_id($review_ids, 'FFL Review Media');
    }

    // --- Loadout: hero image, brand logo, per-tier-item images, cross-sells. ---
    $loadouts = $wpdb->prefix . 'ffla_loadouts';
    if (ffla_mclean_self_table_exists($loadouts)) {
        $ids = ffla_mclean_self_ids_from_table($loadouts, ['hero_image_id', 'brand_logo_id']);
        if (!empty($ids)) {
            $ffla_mclean->add_reference_id($ids, 'FFL Loadout');
        }
    }

    $loadout_items = $wpdb->prefix . 'ffla_loadout_tier_items';
    if (ffla_mclean_self_table_exists($loadout_items)) {
        $ids = ffla_mclean_self_ids_from_table($loadout_items, ['image_id']);
        if (!empty($ids)) {
            $ffla_mclean->add_reference_id($ids, 'FFL Loadout Item');
        }
    }

    $loadout_cross = $wpdb->prefix . 'ffla_loadout_cross_sells';
    if (ffla_mclean_self_table_exists($loadout_cross)) {
        $ids = ffla_mclean_self_ids_from_table($loadout_cross, ['image_id']);
        if (!empty($ids)) {
            $ffla_mclean->add_reference_id($ids, 'FFL Loadout Cross-sell');
        }
    }

    // --- WooBooster: bundle images. ---
    $bundles = $wpdb->prefix . 'woobooster_bundles';
    if (ffla_mclean_self_table_exists($bundles)) {
        $ids = ffla_mclean_self_ids_from_table($bundles, ['image_id']);
        if (!empty($ids)) {
            $ffla_mclean->add_reference_id($ids, 'FFL Bundle');
        }
    }

    // Let other/future modules protect their own media.
    do_action('ffla_mclean_scan_self', $ffla_mclean);
}
