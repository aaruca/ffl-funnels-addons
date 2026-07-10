<?php
/**
 * Media Cleaner parser — Advanced Custom Fields.
 *
 * ACF stores an image/gallery/file value under the field's own meta key, with a
 * companion `_key` meta pointing at the field definition. Reading the field
 * definitions tells us exactly which keys hold attachments — including inside
 * repeaters and flexible content, where every sub-value carries its own
 * companion — so we reference those and never guess from bare numbers.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

// No ACF, nothing to do.
if (!class_exists('ACF') && !function_exists('acf')) {
    return;
}

add_action('ffla_mclean_scan_postmeta', 'ffla_mclean_acf_scan_postmeta', 10, 1);
add_action('ffla_mclean_scan_once', 'ffla_mclean_acf_scan_options', 10, 0);

/**
 * field_key => type, for image/gallery/file fields only. Built once per request.
 *
 * @return array<string,string>
 */
function ffla_mclean_acf_field_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    global $wpdb;
    $map = [];

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results(
        "SELECT post_name, post_content FROM {$wpdb->posts} WHERE post_type = 'acf-field'"
    );

    foreach ((array) $rows as $row) {
        $def = @unserialize($row->post_content);
        if (!is_array($def) || empty($def['type'])) {
            continue;
        }
        if (in_array($def['type'], ['image', 'gallery', 'file'], true)) {
            $map[$row->post_name] = $def['type'];
        }
    }

    return $map;
}

/**
 * @param mixed             $value  Raw meta value.
 * @param string            $type   ACF field type.
 * @param array<int,int>    $ids
 * @param array<int,string> $urls
 */
function ffla_mclean_acf_collect($value, string $type, array &$ids, array &$urls): void
{
    global $ffla_mclean;

    if (is_serialized($value)) {
        $value = @unserialize($value);
    }

    if (is_array($value)) {
        // Gallery (array of IDs), or an image/file returned as an array.
        $ffla_mclean->array_to_ids_or_urls($value, $ids, $urls);
        return;
    }

    if (is_numeric($value)) {
        if ((int) $value > 0) {
            $ids[] = (int) $value;
        }
        return;
    }

    if (is_string($value) && $ffla_mclean->is_url($value)) {
        $clean = $ffla_mclean->clean_url($value);
        if ($clean !== null) {
            $urls[] = $clean;
        }
    }
}

/**
 * @param int $post_id
 */
function ffla_mclean_acf_scan_postmeta($post_id): void
{
    global $wpdb, $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    $map = ffla_mclean_acf_field_map();
    if (empty($map)) {
        return;
    }

    $post_id = (int) $post_id;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $meta = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
        $post_id
    ), OBJECT_K);

    if (empty($meta)) {
        return;
    }

    $ids  = [];
    $urls = [];

    foreach ($meta as $key => $row) {
        // Companion keys start with '_' and hold the field key.
        if ($key === '' || $key[0] !== '_') {
            continue;
        }
        $field_key = $row->meta_value;
        if (!is_string($field_key) || !isset($map[$field_key])) {
            continue;
        }

        $real_key = substr($key, 1);
        if (!isset($meta[$real_key])) {
            continue;
        }

        ffla_mclean_acf_collect($meta[$real_key]->meta_value, $map[$field_key], $ids, $urls);
    }

    $ffla_mclean->add_reference_id($ids, 'ACF (ID)', $post_id);
    $ffla_mclean->add_reference_url($urls, 'ACF (URL)', $post_id);
}

/**
 * ACF options-page values live in wp_options as options_<name> / _options_<name>.
 */
function ffla_mclean_acf_scan_options(): void
{
    global $wpdb, $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    $map = ffla_mclean_acf_field_map();
    if (empty($map)) {
        return;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows = $wpdb->get_results(
        "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '\_options\_%'"
    );

    if (empty($rows)) {
        return;
    }

    // Map option_name => value for the non-underscore companions.
    $values = [];
    foreach ($rows as $row) {
        $values[$row->option_name] = $row->option_value;
    }

    $ids  = [];
    $urls = [];

    foreach ($rows as $row) {
        $field_key = $row->option_value;
        if (!is_string($field_key) || !isset($map[$field_key])) {
            continue;
        }
        // "_options_hero" -> "options_hero"
        $real_name = substr($row->option_name, 1);
        $value     = get_option($real_name);
        if ($value !== false) {
            ffla_mclean_acf_collect($value, $map[$field_key], $ids, $urls);
        }
    }

    $ffla_mclean->add_reference_id($ids, 'ACF Options (ID)');
    $ffla_mclean->add_reference_url($urls, 'ACF Options (URL)');
}
