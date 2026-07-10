<?php
/**
 * Media Cleaner parser — page builders that store layout in post meta.
 *
 * Elementor, Beaver Builder, and Oxygen keep their designs in meta the generic
 * scan does not read. Builders that keep their markup in post_content (WPBakery,
 * Divi shortcodes, Gutenberg / Kadence / Spectra blocks) are already covered by
 * the common parser's HTML and shortcode sweep.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('ffla_mclean_scan_postmeta', 'ffla_mclean_builders_scan_postmeta', 10, 1);

/**
 * @param int $post_id
 */
function ffla_mclean_builders_scan_postmeta($post_id): void
{
    global $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    $post_id = (int) $post_id;

    ffla_mclean_builder_elementor($post_id);
    ffla_mclean_builder_beaver($post_id);
    ffla_mclean_builder_oxygen($post_id);
}

/**
 * Elementor: JSON in `_elementor_data`, plus page settings.
 */
function ffla_mclean_builder_elementor(int $post_id): void
{
    global $ffla_mclean;

    $data = get_post_meta($post_id, '_elementor_data', true);
    if (empty($data)) {
        return;
    }

    $ids  = [];
    $urls = [];

    if (is_string($data)) {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            $ffla_mclean->get_from_meta($decoded, ['id', 'url', 'background_image', 'image', '_image'], $ids, $urls);
        } else {
            // Fall back to a raw URL sweep of the JSON string.
            $urls = array_merge($urls, $ffla_mclean->get_urls_from_html($data));
        }
    } elseif (is_array($data)) {
        $ffla_mclean->get_from_meta($data, ['id', 'url', 'background_image', 'image', '_image'], $ids, $urls);
    }

    $settings = get_post_meta($post_id, '_elementor_page_settings', true);
    if (is_array($settings)) {
        $ffla_mclean->get_from_meta($settings, ['id', 'url', 'background_image', 'image'], $ids, $urls);
    }

    $ffla_mclean->add_reference_id($ids, 'Elementor (ID)', $post_id);
    $ffla_mclean->add_reference_url($urls, 'Elementor (URL)', $post_id);
}

/**
 * Beaver Builder: serialized node tree in `_fl_builder_data`.
 */
function ffla_mclean_builder_beaver(int $post_id): void
{
    global $ffla_mclean;

    $data = get_post_meta($post_id, '_fl_builder_data', true);
    if (empty($data) || !is_array($data)) {
        return;
    }

    $ids  = [];
    $urls = [];
    $ffla_mclean->get_from_meta(
        $data,
        ['id', 'url', 'photo', 'photo_src', 'photo_url', 'image', 'bg_image', 'bg_image_src'],
        $ids,
        $urls
    );

    $ffla_mclean->add_reference_id($ids, 'Beaver Builder (ID)', $post_id);
    $ffla_mclean->add_reference_url($urls, 'Beaver Builder (URL)', $post_id);
}

/**
 * Oxygen: shortcode blob in `ct_builder_shortcodes`.
 */
function ffla_mclean_builder_oxygen(int $post_id): void
{
    global $ffla_mclean;

    $shortcodes = get_post_meta($post_id, 'ct_builder_shortcodes', true);
    if (empty($shortcodes) || !is_string($shortcodes)) {
        return;
    }

    $urls = $ffla_mclean->get_urls_from_html($shortcodes);
    $refs = $ffla_mclean->get_shortcode_and_class_refs($shortcodes);

    $ffla_mclean->add_reference_id($refs['ids'], 'Oxygen (ID)', $post_id);
    $ffla_mclean->add_reference_url(array_merge($urls, $refs['urls']), 'Oxygen (URL)', $post_id);
}
