<?php
/**
 * Offline check of the SEO additions: product identifiers (GTIN, MPN,
 * brand) in the product's structured data, WooCommerce's own GTIN and
 * Brands first, the product screen fields, and review photos in the review
 * markup.
 *
 * Run: php tests/smoke/product-reviews-seo-smoke.php
 */

require __DIR__ . '/product-reviews-stubs.php';

$GLOBALS['is_admin'] = true;

load_module([
    'includes/class-product-reviews-core.php',
    'includes/class-product-reviews-query.php',
    'includes/class-product-reviews-criteria.php',
    'includes/class-product-reviews-frontend-render.php',
    'includes/class-product-reviews-assets.php',
    'includes/class-product-reviews-notifications.php',
    'includes/class-product-reviews-structured-data.php',
    'includes/class-product-reviews-identifiers.php',
]);

function settings(array $overrides = []): void
{
    $GLOBALS['options']['ffla_product_reviews_settings'] = array_merge(['review_name_format' => 'full'], $overrides);
}

settings();

/* ── GTIN format ───────────────────────────────────────────────────────── */

foreach (['12345670' => '12345670', '012345678905' => '012345678905', '4006381333931' => '4006381333931', '10012345678902' => '10012345678902', '400-638 133393 1' => '4006381333931', '12345' => '', 'ABC4006381333' => '', '' => ''] as $in => $out) {
    check($out === Product_Reviews_Identifiers::clean_gtin((string) $in), "GTIN '$in'");
}

/* ── Where identifiers come from ───────────────────────────────────────── */

$GLOBALS['product_props'][101]['gtin'] = '4006381333931';
$GLOBALS['product_props'][101]['meta'] = ['_ffla_gtin' => '00000000', '_ffla_mpn' => 'SC-4X', '_ffla_brand' => 'Meta Brand'];
check(['gtin' => '4006381333931', 'mpn' => 'SC-4X', 'brand' => 'Meta Brand'] === Product_Reviews_Identifiers::for_product(wc_get_product(101)), 'WooCommerce\'s GTIN first; MPN and brand from the module\'s fields');

$GLOBALS['taxonomies'][] = 'product_brand';
$GLOBALS['product_props'][101]['brands'] = ['Vortex'];
check('Vortex' === Product_Reviews_Identifiers::for_product(wc_get_product(101))['brand'], 'WooCommerce Brands come before the module\'s brand');

$GLOBALS['product_props'][102]['meta'] = ['_ffla_gtin' => '012345678905'];
check('012345678905' === Product_Reviews_Identifiers::for_product(wc_get_product(102))['gtin'], 'without WooCommerce\'s GTIN, the module\'s field');
settings(['seo_default_brand' => 'House Brand']);
check('House Brand' === Product_Reviews_Identifiers::for_product(wc_get_product(102))['brand'], 'the default brand for products without one');
settings();

$GLOBALS['posts'][201] = ['product_variation', 'publish', 'Scope – 4x'];
$variation          = new WC_Product(201);
$variation->parent  = 101;
check('SC-4X' === Product_Reviews_Identifiers::for_product($variation)['mpn'], 'a variation uses its parent\'s identifiers');

/* ── Markup ────────────────────────────────────────────────────────────── */

$node = Product_Reviews_Identifiers::filter_markup(['@type' => 'Product', 'name' => 'Scope'], wc_get_product(101));
check('4006381333931' === $node['gtin13'] && 'SC-4X' === $node['mpn'] && ['@type' => 'Brand', 'name' => 'Vortex'] === $node['brand'], 'gtin13, mpn and brand added');
$node = Product_Reviews_Identifiers::filter_markup(['@type' => 'Product', 'gtin' => 'kept', 'mpn' => 'X', 'brand' => ['@type' => 'Brand', 'name' => 'SEO plugin']], wc_get_product(101));
check('kept' === $node['gtin'] && !isset($node['gtin13']) && 'X' === $node['mpn'] && 'SEO plugin' === $node['brand']['name'], 'values already in the markup are kept');
$node = Product_Reviews_Identifiers::apply(['@type' => 'Product'], ['gtin' => '12345670', 'mpn' => '', 'brand' => '']);
check(['@type' => 'Product', 'gtin8' => '12345670'] === $node, 'the property follows the GTIN length; empty values are not added');

/* ── Product screen ────────────────────────────────────────────────────── */

$GLOBALS['post'] = get_post(101);
$html = capture(['Product_Reviews_Identifiers', 'render_fields']);
check(strpos($html, 'name="_ffla_mpn" value="SC-4X"') !== false, 'MPN field');
check(strpos($html, 'name="_ffla_gtin"') === false, 'no GTIN field when WooCommerce has its own');
check(strpos($html, 'name="_ffla_brand"') === false, 'no Brand field when WooCommerce Brands exist');

reset_request(1);
$_POST = ['ffla_identifiers_nonce' => 'nonce-ffla_product_identifiers', '_ffla_gtin' => '12-34', '_ffla_mpn' => ' NEW-1 ', '_ffla_brand' => ''];
$product = wc_get_product(102);
Product_Reviews_Identifiers::save_fields($product);
check('' === $product->get_meta('_ffla_gtin') && 1 === count(WC_Admin_Meta_Boxes::$errors), 'an invalid GTIN is not saved, and says why');
check('NEW-1' === $product->get_meta('_ffla_mpn'), 'MPN saved, trimmed');
$_POST = ['ffla_identifiers_nonce' => 'forged', '_ffla_mpn' => 'HACK'];
Product_Reviews_Identifiers::save_fields($product);
check('NEW-1' === $product->get_meta('_ffla_mpn'), 'a forged nonce saves nothing');

/* ── Review photos in the review markup ────────────────────────────────── */

$img = add_test_attachment('https://store.test/uploads/review-1.jpg');
$vid = add_test_attachment('https://store.test/uploads/review-2.mp4', 'video/mp4');
$r   = add_test_comment(['comment_post_ID' => 101, 'comment_author' => 'Photo Pete', 'comment_content' => 'Sharp.', 'meta' => ['rating' => 5, 'ffla_review_media_ids' => [$img, $vid]]]);
$node = Product_Reviews_Structured_Data::with_reviews(['@type' => 'Product'], [get_comment($r)]);
check([['@type' => 'ImageObject', 'contentUrl' => 'https://store.test/uploads/review-1.jpg?size=large', 'url' => 'https://store.test/uploads/review-1.jpg?size=large']] === $node['review'][0]['associatedMedia'], 'the review lists its photo, not its video');
settings(['seo_review_images' => '0']);
$node = Product_Reviews_Structured_Data::with_reviews(['@type' => 'Product'], [get_comment($r)]);
check(!isset($node['review'][0]['associatedMedia']), 'review photos can be left out');
settings();

echo "OK: {$checks} product-reviews SEO checks passed\n";
