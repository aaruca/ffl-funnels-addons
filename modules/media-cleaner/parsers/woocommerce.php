<?php
/**
 * Media Cleaner parser — WooCommerce.
 *
 * Product galleries, variation images, category / tag / brand thumbnails, and
 * the shop placeholder — none of which sit in post_content.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

// Only meaningful with WooCommerce present.
if (!defined('WC_PLUGIN_FILE') && !class_exists('WooCommerce')) {
    return;
}

add_filter('ffla_mclean_scanned_post_types', 'ffla_mclean_woo_scanned_post_types');
add_action('ffla_mclean_scan_postmeta', 'ffla_mclean_woo_scan_postmeta', 10, 1);
add_action('ffla_mclean_scan_once', 'ffla_mclean_woo_scan_once', 10, 0);

/**
 * Variations are their own post type and hold a featured image; make sure the
 * engine walks them.
 *
 * @param array<int,string> $types
 * @return array<int,string>
 */
function ffla_mclean_woo_scanned_post_types($types): array
{
    if (!in_array('product_variation', $types, true)) {
        $types[] = 'product_variation';
    }

    return $types;
}

/**
 * @param int $post_id
 */
function ffla_mclean_woo_scan_postmeta($post_id): void
{
    global $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    $post_id = (int) $post_id;

    // Product image gallery — comma-separated attachment IDs.
    $gallery = get_post_meta($post_id, '_product_image_gallery', true);
    if (!empty($gallery)) {
        $ids = array_filter(array_map('intval', explode(',', (string) $gallery)));
        if (!empty($ids)) {
            $ffla_mclean->add_reference_id($ids, 'WooCommerce Gallery', $post_id);
        }
    }
}

/**
 * Term thumbnails (product_cat / product_tag / product_brand) and the shop
 * placeholder image.
 */
function ffla_mclean_woo_scan_once(): void
{
    global $wpdb, $ffla_mclean;
    if (!$ffla_mclean) {
        return;
    }

    // Placeholder image (option holds an attachment ID).
    $placeholder = (int) get_option('woocommerce_placeholder_image', 0);
    if ($placeholder > 0) {
        $ffla_mclean->add_reference_id($placeholder, 'WooCommerce Placeholder');
    }

    // Every term thumbnail across product taxonomies, in one query.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s",
        'thumbnail_id'
    ));

    $clean = array_filter(array_map('intval', (array) $ids));
    if (!empty($clean)) {
        $ffla_mclean->add_reference_id($clean, 'WooCommerce Term');
    }
}
