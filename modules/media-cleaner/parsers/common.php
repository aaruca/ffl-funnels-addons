<?php
/**
 * Media Cleaner parser — common WordPress content.
 *
 * Covers the references every site has regardless of theme or builder: post
 * content URLs, the classic gallery, wp-image-N classes, known media meta keys,
 * featured images, the theme (logo / header / background), the site icon, and
 * text/media widgets.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('ffla_mclean_scan_once', 'ffla_mclean_common_scan_once');
add_action('ffla_mclean_scan_post', 'ffla_mclean_common_scan_post', 10, 2);
add_action('ffla_mclean_scan_postmeta', 'ffla_mclean_common_scan_postmeta', 10, 1);
add_action('ffla_mclean_scan_widget', 'ffla_mclean_common_scan_widget', 10, 1);

/**
 * Site-wide references: theme logo/header/background and the site icon.
 */
function ffla_mclean_common_scan_once(): void
{
    global $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    $ids  = [];
    $urls = [];

    // Custom logo (attachment ID).
    $logo_id = get_theme_mod('custom_logo');
    if ($logo_id) {
        $ids[] = (int) $logo_id;
    }

    // Site icon (attachment ID).
    $site_icon = (int) get_option('site_icon');
    if ($site_icon > 0) {
        $ids[] = $site_icon;
    }

    // Header + background images (URLs).
    foreach ([get_header_image(), get_background_image()] as $url) {
        if ($url) {
            $clean = $ffla_mclean->clean_url($url);
            if ($clean !== null) {
                $urls[] = $clean;
            }
        }
    }

    // Any attachment IDs hiding in theme mods (child themes stash them here).
    $mods = get_theme_mods();
    if (is_array($mods)) {
        $ffla_mclean->get_from_meta($mods, ['id', 'url', 'image', 'background_image'], $ids, $urls);
    }

    $ffla_mclean->add_reference_id($ids, 'THEME');
    $ffla_mclean->add_reference_url($urls, 'THEME');
}

/**
 * References inside a post's rendered content.
 *
 * @param string $html
 * @param int    $post_id
 */
function ffla_mclean_common_scan_post($html, $post_id): void
{
    global $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    $post_id = (int) $post_id;
    $urls    = $ffla_mclean->get_urls_from_html($html);

    // The excerpt can carry its own imagery.
    $excerpt = get_post_field('post_excerpt', $post_id);
    if (!empty($excerpt)) {
        $urls = array_merge($urls, $ffla_mclean->get_urls_from_html($excerpt));
    }

    $refs = $ffla_mclean->get_shortcode_and_class_refs($html);

    // Classic [gallery] galleries.
    if (function_exists('get_post_galleries_images')) {
        foreach (get_post_galleries_images($post_id) as $gallery) {
            foreach ($gallery as $image_url) {
                $clean = $ffla_mclean->clean_url($image_url);
                if ($clean !== null) {
                    $urls[] = $clean;
                }
            }
        }
    }

    $ffla_mclean->add_reference_id($refs['ids'], 'Content', $post_id);
    $ffla_mclean->add_reference_url(array_merge($urls, $refs['urls']), 'Content', $post_id);
}

/**
 * References in a post's meta: the featured image and any gallery/ID keys.
 *
 * @param int $post_id
 */
function ffla_mclean_common_scan_postmeta($post_id): void
{
    global $wpdb, $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    $post_id = (int) $post_id;

    // Featured image: every size is kept, since WordPress does not record which
    // size a theme renders.
    $thumbnail_id = (int) get_post_thumbnail_id($post_id);
    if ($thumbnail_id > 0) {
        $ffla_mclean->add_reference_id($thumbnail_id, 'Featured Image', $post_id);
        $ffla_mclean->add_reference_url($ffla_mclean->get_thumbnail_urls($thumbnail_id), 'Featured Image', $post_id);
    }

    // Known media-bearing meta keys: _thumbnail_id and anything that looks like
    // a gallery or an ID list.
    $sql = $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta}
         WHERE post_id = %d
           AND ( meta_key = %s OR meta_key LIKE %s OR meta_key LIKE %s )",
        $post_id,
        '_thumbnail_id',
        '%gallery%',
        '%_ids'
    );
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $metas = $wpdb->get_col($sql);

    if (!empty($metas)) {
        $ids  = [];
        $urls = [];
        foreach ($metas as $meta) {
            if (is_numeric($meta)) {
                if ((int) $meta > 0) {
                    $ids[] = (int) $meta;
                }
                continue;
            }
            if (is_serialized($meta)) {
                $decoded = @unserialize($meta);
                if (is_array($decoded)) {
                    $ffla_mclean->array_to_ids_or_urls($decoded, $ids, $urls);
                    continue;
                }
            }
            $ffla_mclean->array_to_ids_or_urls(explode(',', (string) $meta), $ids, $urls);
        }
        $ffla_mclean->add_reference_id($ids, 'Post Meta', $post_id);
        $ffla_mclean->add_reference_url($urls, 'Post Meta', $post_id);
    }
}

/**
 * Text / media / image widgets.
 *
 * @param array<string,mixed> $widget
 */
function ffla_mclean_common_scan_widget($widget): void
{
    global $ffla_mclean;
    if (!$ffla_mclean || !is_array($widget)) {
        return;
    }

    if (empty($widget['callback'][0]) || !is_object($widget['callback'][0])) {
        return;
    }

    $obj = $widget['callback'][0];
    if (empty($obj->option_name) || empty($widget['params'][0]['number'])) {
        return;
    }

    $instances = get_option($obj->option_name);
    $number    = $widget['params'][0]['number'];
    if (!is_array($instances) || empty($instances[$number]) || !is_array($instances[$number])) {
        return;
    }

    $data = $instances[$number];
    $ids  = [];
    $urls = [];

    foreach (['attachment_id', 'thumbnail'] as $k) {
        if (!empty($data[$k]) && is_numeric($data[$k])) {
            $ids[] = (int) $data[$k];
        }
    }
    if (!empty($data['ids']) && is_array($data['ids'])) {
        foreach ($data['ids'] as $id) {
            $ids[] = (int) $id;
        }
    }
    if (!empty($data['text'])) {
        $urls = array_merge($urls, $ffla_mclean->get_urls_from_html((string) $data['text']));
    }
    // Block-based widgets (WordPress 5.8+ default) store their markup — Image
    // blocks, galleries, cover backgrounds — under 'content'. Without this, an
    // image used only in a sidebar/footer block widget is flagged as unused.
    if (!empty($data['content'])) {
        $html = (string) $data['content'];
        $urls = array_merge($urls, $ffla_mclean->get_urls_from_html($html));
        $refs = $ffla_mclean->get_shortcode_and_class_refs($html);
        $ids  = array_merge($ids, $refs['ids']);
        $urls = array_merge($urls, $refs['urls']);
    }
    if (!empty($data['url']) && $ffla_mclean->is_url($data['url'])) {
        $clean = $ffla_mclean->clean_url($data['url']);
        if ($clean !== null) {
            $urls[] = $clean;
        }
    }

    $ffla_mclean->add_reference_id($ids, 'Widget');
    $ffla_mclean->add_reference_url($urls, 'Widget');
}
