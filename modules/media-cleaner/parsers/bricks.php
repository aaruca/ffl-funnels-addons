<?php
/**
 * Media Cleaner parser — Bricks Builder (superset).
 *
 * Bricks keeps its layout in post meta and in a handful of site-wide options,
 * none of which appear in post_content — so an image used only in a Bricks
 * design is invisible to a naive scan and would be flagged as unused.
 *
 * This parser reads the full Bricks surface:
 *   - page content, header, and footer meta on every post/template
 *   - the bricks_template CPT (scanned as a post type by the engine)
 *   - global settings, theme styles, colour palettes, global classes,
 *     global (reusable) elements, and components
 *
 * Image references in Bricks live as numeric `id` / URL `url`,`full` fields
 * inside element settings and `_background.image` structures; walking the tree
 * for those keys collects them wherever they sit. Element IDs are short
 * non-numeric hashes and are ignored automatically.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('ffla_mclean_scan_postmeta', 'ffla_mclean_bricks_scan_postmeta', 10, 1);
add_action('ffla_mclean_scan_once', 'ffla_mclean_bricks_scan_once', 10, 0);
add_filter('ffla_mclean_post_html', 'ffla_mclean_bricks_affect_html', 10, 2);

/**
 * The post-meta keys Bricks stores layout under.
 *
 * @return array<int,string>
 */
function ffla_mclean_bricks_meta_keys(): array
{
    return apply_filters('ffla_mclean_bricks_meta_keys', [
        '_bricks_page_content_2',
        '_bricks_page_header_2',
        '_bricks_page_footer_2',
    ]);
}

/**
 * Attributes inside Bricks element settings that carry an attachment.
 *
 * @return array<int,string>
 */
function ffla_mclean_bricks_look_for(): array
{
    return apply_filters('ffla_mclean_bricks_look_for', [
        'id', 'url', 'full', 'image', 'images', 'src', 'large', 'thumbnail',
        'background', 'bg', 'poster', 'backgroundImage', 'posterImage', 'file',
    ]);
}

/**
 * Normalise a Bricks meta value to an array. Bricks stores an array (WordPress
 * serialises it), but some migrations leave a JSON string behind.
 *
 * @param mixed $value
 * @return array<mixed>|null
 */
function ffla_mclean_bricks_to_array($value): ?array
{
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value) && $value !== '') {
        // A migration artifact may leave a PHP-serialized string.
        if (is_serialized($value)) {
            $unser = @unserialize($value);
            if (is_array($unser)) {
                return $unser;
            }
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

/**
 * @param int $post_id
 */
function ffla_mclean_bricks_scan_postmeta($post_id): void
{
    global $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    $post_id  = (int) $post_id;
    $look_for = ffla_mclean_bricks_look_for();
    $ids      = [];
    $urls     = [];

    foreach (ffla_mclean_bricks_meta_keys() as $key) {
        $data = ffla_mclean_bricks_to_array(get_post_meta($post_id, $key, true));
        if ($data !== null) {
            $ffla_mclean->get_from_meta($data, $look_for, $ids, $urls);
        }
    }

    $ffla_mclean->add_reference_id($ids, 'Bricks (ID)', $post_id);
    $ffla_mclean->add_reference_url($urls, 'Bricks (URL)', $post_id);
}

/**
 * Site-wide Bricks data lives in options, not in any post.
 */
function ffla_mclean_bricks_scan_once(): void
{
    global $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    $option_keys = apply_filters('ffla_mclean_bricks_option_keys', [
        'bricks_global_settings',
        'bricks_theme_styles',
        'bricks_color_palette',
        'bricks_global_classes',
        'bricks_global_elements',
        'bricks_components',
    ]);

    $look_for = ffla_mclean_bricks_look_for();
    $ids      = [];
    $urls     = [];

    foreach ($option_keys as $key) {
        $value = get_option($key);
        $data  = ffla_mclean_bricks_to_array($value);
        if ($data !== null) {
            $ffla_mclean->get_from_meta($data, $look_for, $ids, $urls);
        }
    }

    $ffla_mclean->add_reference_id($ids, 'Bricks Global (ID)');
    $ffla_mclean->add_reference_url($urls, 'Bricks Global (URL)');
}

/**
 * Append Bricks markup to the HTML blob so the generic URL sweep sees any
 * uploads URL a design references directly.
 *
 * @param string $html
 * @param int    $post_id
 * @return string
 */
function ffla_mclean_bricks_affect_html($html, $post_id): string
{
    $post_id = (int) $post_id;
    foreach (ffla_mclean_bricks_meta_keys() as $key) {
        $data = get_post_meta($post_id, $key, true);
        if (!empty($data)) {
            $html .= ' ' . wp_json_encode($data);
        }
    }

    return $html;
}
