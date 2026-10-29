<?php
/**
 * Offline check of the Product Reviews lists: filters, sorting, paging,
 * store-wide and category lists, reviewer names and avatars, the lightbox
 * markup, grid and slider layouts and the store rating badge.
 *
 * Run: php tests/smoke/product-reviews-list-smoke.php
 *
 * The review SQL runs for real against an SQLite copy of the in-memory
 * comments (see Stub_Wpdb in product-reviews-stubs.php), so ordering, paging
 * and filters are checked on the queries the module actually sends.
 */

require __DIR__ . '/product-reviews-stubs.php';
load_bricks_stubs();

load_module([
    'includes/class-product-reviews-core.php',
    'includes/class-product-reviews-query.php',
    'includes/class-product-reviews-criteria.php',
    'includes/class-product-reviews-frontend-render.php',
    'includes/class-product-reviews-assets.php',
    'includes/class-product-reviews-moderation.php',
    'includes/class-product-reviews-notifications.php',
    'includes/class-product-reviews-structured-data.php',
    'includes/class-product-reviews-ajax.php',
    'integrations/class-product-reviews-dynamic-tags.php',
    'integrations/elements/reviews-list.php',
    'integrations/elements/store-rating-badge.php',
]);
Product_Reviews_Query::init();

function settings(array $overrides = []): void
{
    $GLOBALS['options']['ffla_product_reviews_settings'] = array_merge(
        ['enable_criteria' => '0', 'show_replies' => '1', 'order_review_page_id' => '500'],
        $overrides
    );
}

function review(int $product, string $author, int $rating, string $date, array $extra = [], array $meta = []): int
{
    return add_test_comment($extra + [
        'comment_post_ID'      => $product,
        'comment_author'       => $author,
        'comment_author_email' => strtolower(str_replace(' ', '.', $author)) . '@example.com',
        'comment_content'      => "Review by $author",
        'comment_date_gmt'     => $date,
        'meta'                 => ($rating > 0 ? ['rating' => $rating] : []) + $meta,
    ]);
}

function ids(array $reviews): array
{
    return array_map(function ($r) {
        return (int) $r->comment_ID;
    }, $reviews);
}

function list_html(int $product_id, array $settings): string
{
    return capture(function () use ($product_id, $settings) {
        Product_Reviews_Frontend_Render::render_reviews_list($product_id, $settings);
    });
}

function reset_assets(): void
{
    $GLOBALS['styles']  = ['registered' => [], 'enqueued' => []];
    $GLOBALS['scripts'] = ['registered' => [], 'enqueued' => [], 'localized' => []];
    set_static('Product_Reviews_Assets', 'script_enqueued', false);
}

settings();

// Product 105 is a draft: its reviews never show anywhere.
$GLOBALS['posts'][105]         = ['product', 'draft', 'Unreleased Optic'];
$GLOBALS['product_props'][105] = ['cats' => [20]];
// Child category of Optics.
$GLOBALS['terms'][23]          = ['product_cat', 'Red dots', 'red-dots', 20];
$GLOBALS['posts'][106]         = ['product', 'publish', 'Red Dot Sight'];
$GLOBALS['product_props'][106] = ['cats' => [23]];

$photo = add_test_attachment('https://store.test/uploads/photo-1.jpg');
$photo2 = add_test_attachment('https://store.test/uploads/photo-2.jpg');

// Product 101: seven approved reviews plus noise that must never show.
$a = review(101, 'Ann Buyer', 5, '2026-03-01 10:00:00', [], ['ffla_helpful_yes' => 2, 'ffla_review_media_ids' => [$photo, $photo2], 'ffla_verified_purchase' => 1]);
$b = review(101, 'Bob Stone', 4, '2026-03-02 10:00:00', [], ['ffla_helpful_yes' => 9, 'ffla_helpful_no' => 1, 'ffla_verified_purchase' => 0, 'verified' => 1]);
$c = review(101, 'Cy Rivers', 1, '2026-03-03 10:00:00', [], ['ffla_helpful_yes' => 40, 'ffla_helpful_no' => 39]);
$d = review(101, 'Dee Lake', 3, '2026-03-04 10:00:00', ['comment_karma' => 1], ['verified' => 1]);
$e = review(101, 'Eve Wood', 0, '2026-03-05 10:00:00', ['comment_content' => 'No stars, just words about the reticle']);
$f = review(101, 'Fay Glen', 5, '2026-03-06 10:00:00', [], ['ffla_review_media_ids' => []]);
$g = review(101, 'Gus Hill', 2, '2026-03-07 10:00:00');
review(101, 'Pending Pat', 5, '2026-03-08 10:00:00', ['comment_approved' => '0']);
review(101, 'Spam Sam', 5, '2026-03-09 10:00:00', ['comment_approved' => 'spam']);
review(101, 'Plain Comment', 5, '2026-03-10 10:00:00', ['comment_type' => 'comment']);
$reply = add_test_comment(['comment_post_ID' => 101, 'comment_parent' => $a, 'comment_author' => 'Store Staff', 'comment_content' => 'Thanks Ann!', 'user_id' => 9, 'comment_date_gmt' => '2026-03-02 09:00:00', 'meta' => ['ffla_is_store_reply' => 1]]);
// Other products.
$h = review(102, 'Hal Brook', 4, '2026-02-01 10:00:00');
$i = review(103, 'Ivy Moss', 5, '2026-02-02 10:00:00', [], ['ffla_verified_purchase' => 1]);
$j = review(106, 'Jo Ridge', 3, '2026-02-03 10:00:00');
$draft_review = review(105, 'Draft Dan', 5, '2026-02-04 10:00:00');

// WooCommerce's stored aggregates for product 101, as it keeps them.
$GLOBALS['product_props'][101] += ['rating_counts' => [5 => 2, 4 => 1, 3 => 1, 2 => 1, 1 => 1], 'average' => '3.33', 'review_count' => 7];

/* ── Query: order, filters, paging ─────────────────────────────────────── */

$all101 = Product_Reviews_Query::find(['products' => [101], 'perPage' => 50]);
check($all101['ids'] === [$d, $g, $f, $e, $c, $b, $a], 'newest first, pinned review leads: ' . json_encode($all101['ids']));
check(7 === $all101['total'], 'only approved top-level reviews are counted (no pending, spam, plain comments or replies)');

$helpful = Product_Reviews_Query::find(['products' => [101], 'orderBy' => 'helpful', 'perPage' => 50]);
check($helpful['ids'][0] === $d, 'pinned first under Most helpful too');
check(array_slice($helpful['ids'], 1, 3) === [$b, $a, $c], 'Most helpful uses net votes: 9-1 beats 2, which beats 40-39');

$highest = Product_Reviews_Query::find(['products' => [101], 'orderBy' => 'highest', 'perPage' => 50, 'pinnedFirst' => false]);
check(array_slice($highest['ids'], 0, 2) === [$f, $a], 'Highest: 5-star reviews first, newest first among them');
$lowest = Product_Reviews_Query::find(['products' => [101], 'orderBy' => 'lowest', 'perPage' => 50, 'pinnedFirst' => false]);
check($lowest['ids'][0] === $c && end($lowest['ids']) === $e, 'Lowest: 1 star first, the review without stars last');

$five = Product_Reviews_Query::find(['products' => [101], 'rating' => 5]);
check($five['ids'] === [$f, $a] && 2 === $five['total'], 'star filter returns only that rating');
$min4 = Product_Reviews_Query::find(['products' => [101], 'minRating' => 4, 'perPage' => 50]);
check(3 === $min4['total'] && !in_array($d, $min4['ids'], true), 'minimum rating keeps 4 and 5 stars only');
$media = Product_Reviews_Query::find(['products' => [101], 'withMedia' => true]);
check($media['ids'] === [$a], 'With photos ignores an empty media list');
$search = Product_Reviews_Query::find(['products' => [101], 'search' => 'reticle']);
check($search['ids'] === [$e], 'search matches the review text');
$search = Product_Reviews_Query::find(['products' => [101], 'search' => 'stone']);
check($search['ids'] === [$b], 'search matches the reviewer name, case-insensitive');

$p1 = Product_Reviews_Query::find(['products' => [101], 'perPage' => 3, 'page' => 1]);
$p2 = Product_Reviews_Query::find(['products' => [101], 'perPage' => 3, 'page' => 2]);
$p3 = Product_Reviews_Query::find(['products' => [101], 'perPage' => 3, 'page' => 3]);
check(array_merge($p1['ids'], $p2['ids'], $p3['ids']) === $all101['ids'], 'pages follow each other without repeats or gaps');
check([] === Product_Reviews_Query::find(['products' => [101], 'perPage' => 3, 'page' => 4])['ids'], 'a page past the end is empty');

$store = Product_Reviews_Query::find(['perPage' => 50]);
check(10 === $store['total'] && !in_array($draft_review, $store['ids'], true), 'store-wide: every published product, never a draft product');
$draft = Product_Reviews_Query::find(['products' => [105]]);
check([] === $draft['ids'], 'asking for a draft product by ID returns nothing');
$optics = Product_Reviews_Query::find(['categories' => [20], 'perPage' => 50]);
check(in_array($j, $optics['ids'], true) && in_array($a, $optics['ids'], true) && !in_array($h, $optics['ids'], true), 'a category includes its subcategories');

$q = Product_Reviews_Query::normalize(['orderBy' => "helpful'; DROP TABLE wp_comments;--", 'perPage' => 999, 'page' => -3, 'rating' => 9, 'search' => str_repeat('x', 300), 'products' => '101, abc, 102']);
check('recent' === $q['orderBy'] && 50 === $q['perPage'] && 1 === $q['page'] && 0 === $q['rating'], 'arguments are clamped: unknown sort, page size, page, rating');
check(100 === strlen($q['search']) && [101, 102] === $q['products'], 'search is cut to 100 characters; product IDs are parsed from a list');

/* ── Scope numbers and their cache ─────────────────────────────────────── */

$dist = Product_Reviews_Query::distribution(['products' => [101]]);
check([5 => 2, 4 => 1, 3 => 1, 2 => 1, 1 => 1] === $dist['counts'] && 6 === $dist['total'] && 7 === $dist['review_count'], 'rating distribution of a scope');
check(abs($dist['average'] - 3.33) < 0.01, 'scope average from the ratings');

$before = count($GLOBALS['wpdb']->queries);
Product_Reviews_Query::distribution(['products' => [101]]);
check(count($GLOBALS['wpdb']->queries) === $before, 'scope numbers are cached');

$k = review(101, 'Kim Late', 5, '2026-03-11 10:00:00');
do_action('wp_update_comment_count', 101, 8, 7);
$dist = Product_Reviews_Query::distribution(['products' => [101]]);
check(3 === $dist['counts'][5], 'a review change retires the cached numbers');
wp_delete_comment($k);
do_action('wp_update_comment_count', 101, 7, 8);
do_action('wp_update_comment_count', 500, 1, 0);
$version = (int) get_option(Product_Reviews_Query::CACHE_VERSION_OPTION);
do_action('wp_update_comment_count', 500, 2, 1);
check($version === (int) get_option(Product_Reviews_Query::CACHE_VERSION_OPTION), 'comments on pages do not retire the review cache');

$stats = Product_Reviews_Query::store_stats();
check(10 === $stats['review_count'], 'store stats count every published product review');
// Verified: Ann (module flag), Ivy (module flag), Dee (WooCommerce flag, no module verdict). Bob's module "no" wins over WooCommerce.
check(3 === $stats['verified'] && 30 === $stats['verified_percent'], 'store verified share follows is_verified_purchase(): ' . $stats['verified']);

/* ── Reviewer names, avatars, verified label ───────────────────────────── */

$ann = get_comment($a);
check('Ann B.' === Product_Reviews_Frontend_Render::display_name($ann), 'default name: first name and last initial');
foreach (['full' => 'Ann Buyer', 'first' => 'Ann', 'initials' => 'A. B.', 'anonymous' => 'Customer'] as $format => $expected) {
    settings(['review_name_format' => $format]);
    check($expected === Product_Reviews_Frontend_Render::display_name($ann), "name format $format");
}
settings();
$solo = get_comment(add_test_comment(['comment_author' => 'Madonna', 'comment_post_ID' => 999]));
check('Madonna' === Product_Reviews_Frontend_Render::display_name($solo), 'a one-word name is shown as is');
$accent = get_comment(add_test_comment(['comment_author' => 'Ángel  Núñez', 'comment_post_ID' => 999]));
check('Ángel N.' === Product_Reviews_Frontend_Render::display_name($accent), 'multibyte names keep their first letter');

$html = list_html(101, ['perPage' => 3]);
check(strpos($html, 'ffla-review-card__avatar--initials') !== false && strpos($html, '>DL</span>') !== false, 'initials avatar by default (Dee L. → DL)');
check(strpos($html, 'ffla-review-card__author">Dee L.<') !== false, 'cards use the display name');
check(strpos($html, 'Ann Buyer') === false, 'the full name is not printed with the default format');
settings(['review_avatars' => 'gravatar']);
$html = list_html(101, ['perPage' => 3]);
check(strpos($html, 'gravatar.com/avatar/') !== false, 'Gravatar avatar when chosen');
settings(['review_avatars' => 'none', 'verified_label' => 'Bought here']);
$html = list_html(101, ['perPage' => 50]);
check(strpos($html, 'ffla-review-card__avatar') === false, 'no avatar when switched off');
check(strpos($html, '>Bought here<') !== false && strpos($html, 'Verified buyer') === false, 'custom verified label');
settings();

/* ── List markup: toolbar, Show more, status, lightbox ─────────────────── */

reset_assets();
$html = list_html(101, ['perPage' => 3]);
check(strpos($html, 'data-ffla-toolbar hidden') !== false, 'toolbar printed hidden (the script reveals it)');
check(strpos($html, 'data-ffla-rating="5"') !== false && strpos($html, 'data-ffla-rating="2"') !== false, 'a chip per star level that has reviews');
check(strpos($html, 'data-ffla-media-filter') !== false && strpos($html, 'data-ffla-sort') !== false && strpos($html, 'data-ffla-search') !== false, 'With photos, sort and search');
check(strpos($html, 'data-ffla-more>') !== false && strpos($html, 'data-ffla-more hidden') === false, 'Show more visible when there are more reviews');
check(strpos($html, 'Showing 3 of 7 reviews') !== false, 'status line with WooCommerce-free total: ' . (preg_match('/Showing[^<]+/', $html, $m) ? $m[0] : 'none'));
check(strpos($html, 'data-ffla-filter-rating="5"') !== false, 'summary bars filter the list when the toolbar is on');
check(isset($GLOBALS['scripts']['enqueued']['ffla-product-reviews']), 'interactive list loads the script');
preg_match('/data-ffla-reviews="([^"]+)"/', $html, $m);
$client = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
check([101] === $client['products'] && 3 === $client['perPage'] && 'recent' === $client['orderBy'], 'list tells the script its scope and page size');

$html = list_html(101, ['perPage' => 50]);
check(strpos($html, 'data-ffla-more hidden') !== false, 'Show more hidden when everything fits');
check(substr_count($html, 'data-ffla-lightbox="' . $a . '"') === 2, 'each review photo opens in the lightbox, grouped by review');
check(strpos($html, 'https://store.test/uploads/photo-1.jpg?size=large') !== false, 'lightbox opens the large size');
check(strpos($html, 'Thanks Ann!') !== false && strpos($html, 'Store response') !== false, 'store reply still nested under its review');
settings(['review_lightbox' => '0']);
$html = list_html(101, ['perPage' => 50]);
check(strpos($html, 'data-ffla-lightbox') === false && strpos($html, 'photo-1.jpg') !== false, 'lightbox off: plain image');
settings(['list_toolbar' => '0', 'list_show_more' => '0']);
$html = list_html(101, ['perPage' => 3]);
check(strpos($html, 'data-ffla-toolbar') === false && strpos($html, 'data-ffla-more') === false, 'module settings switch the toolbar and Show more off');
check(strpos($html, 'data-ffla-filter-rating') === false, 'bars are not clickable without the toolbar');
$html = list_html(101, ['perPage' => 3, 'showSort' => 'show', 'showMore' => 'show']);
check(strpos($html, 'data-ffla-sort') !== false && strpos($html, 'data-ffla-rating=') === false && strpos($html, 'data-ffla-more') !== false, 'an element can switch parts back on one by one');
settings();

$html = list_html(101, ['perPage' => 3, 'orderBy' => 'lowest']);
preg_match_all('/id="review-(\d+)"/', $html, $m);
check(array_map('intval', $m[1]) === array_slice(Product_Reviews_Query::find(['products' => [101], 'orderBy' => 'lowest', 'perPage' => 3])['ids'], 0, 3), 'first page honours the chosen sort');

/* ── Layouts and store-wide lists ──────────────────────────────────────── */

$html = list_html(0, ['source' => 'all', 'layout' => 'grid', 'columns' => 4, 'perPage' => 4]);
check(strpos($html, 'ffla-reviews-list--grid') !== false && strpos($html, '--ffla-columns:4') !== false, 'grid layout with its column count');
check(substr_count($html, 'ffla-review-card__product"') === 4 && strpos($html, 'ffla-review-card__product-name">Hunting Rifle Scope<') !== false, 'every store-wide card names its product');
check(strpos($html, 'Draft Dan') === false, 'store-wide list leaves out draft products');
check(strpos($html, '10 reviews') !== false, 'store-wide summary counts the whole store');
check(Product_Reviews_Frontend_Render::rendered_reviews(0) === null, 'store-wide lists are not taken as one product\'s reviews (structured data)');

$html = list_html(0, ['source' => 'all', 'layout' => 'slider', 'perPage' => 5]);
check(strpos($html, 'data-ffla-slider') !== false && strpos($html, 'data-ffla-slider-next hidden') !== false, 'slider with arrows (shown by the script)');
check(strpos($html, 'data-ffla-toolbar') === false && strpos($html, 'data-ffla-more') === false, 'slider: no toolbar, no Show more by default');

$html = list_html(0, ['source' => 'categories', 'categories' => [21]]);
check(strpos($html, 'Hal B.') !== false && strpos($html, 'Ann B.') === false, 'category list shows only that category');
$html = list_html(0, ['source' => 'categories', 'categories' => []]);
check(strpos($html, 'Dee L.') !== false && strpos($html, 'Hal B.') === false, 'category source without categories falls back to the whole store (newest 5, pinned first)');
$html = list_html(101, ['showProduct' => 'show', 'perPage' => 1]);
check(strpos($html, 'ffla-review-card__product') !== false, 'product link can be forced on for a product list');

/* ── AJAX pages ────────────────────────────────────────────────────────── */

function ajax(array $post): array
{
    reset_request();
    $_POST = $post + ['action' => 'ffla_load_reviews'];
    [$ok, $data] = expect_json(['Product_Reviews_Ajax', 'load_reviews']);
    check($ok, 'load_reviews answers with success');
    return $data;
}

$page2 = ajax(['products' => ['101'], 'perPage' => '3', 'page' => '2']);
preg_match_all('/id="review-(\d+)"/', $page2['html'], $m);
check(array_map('intval', $m[1]) === $p2['ids'], 'page 2 continues where the first page ended');
check($page2['hasMore'] && 7 === $page2['total'] && 6 === $page2['count'], 'page 2 says more are left');
check('Showing 6 of 7 reviews' === $page2['status'], 'status for page 2');

$stars = ajax(['products' => ['101'], 'rating' => '5']);
check(substr_count($stars['html'], '<article') === 2 && !$stars['hasMore'], 'star filter over AJAX');
$none = ajax(['products' => ['101'], 'search' => 'zzzz-nothing']);
check('' === $none['html'] && 'No reviews match your filters.' === $none['status'], 'no match: empty page and a message');
$capped = ajax(['perPage' => '5000']);
check(substr_count($capped['html'], '<article') === 10, 'page size is capped (and the store has 10 reviews)');
$draft = ajax(['products' => ['105']]);
check('' === $draft['html'], 'a draft product\'s reviews never come back');
$withproduct = ajax(['showProduct' => '1', 'perPage' => '1']);
check(strpos($withproduct['html'], 'ffla-review-card__product') !== false, 'store-wide pages keep the product on each card');
$pending = ajax(['products' => ['101'], 'perPage' => '50']);
check(strpos($pending['html'], 'Pending Pat') === false && strpos($pending['html'], 'Spam Sam') === false, 'unapproved reviews never come back');

/* ── Structured data uses the shown name ───────────────────────────────── */

$node = Product_Reviews_Structured_Data::with_reviews(['@type' => 'Product'], [get_comment($a), get_comment($e)]);
check('Ann B.' === $node['review'][0]['author']['name'], 'structured data names the reviewer as the card does');
check(1 === count($node['review']), 'a review without stars stays out of the markup');

/* ── Bricks Reviews List element ───────────────────────────────────────── */

$element = new \Bricks\FFLA_Reviews_List(['source' => 'all', 'layout' => 'grid', 'columns' => '2', 'withMedia' => 'only', 'perPage' => 10]);
$html = capture(function () use ($element) {
    $element->render();
});
check(strpos($html, 'class="ffla-reviews-list ffla-reviews-list--grid"') !== false, 'element root carries the layout class');
check(substr_count($html, '<article') === 1 && strpos($html, 'Ann B.') !== false, 'element: store-wide, photos only');
$element = new \Bricks\FFLA_Reviews_List(['productId' => '102']);
$html = capture(function () use ($element) {
    $element->render();
});
check(strpos($html, 'Hal B.') !== false && strpos($html, 'ffla-review-card__product') === false, 'element: one product, no product link');
$element->set_controls();
check(isset($element->controls['source'], $element->controls['layout'], $element->controls['showMore']), 'element has source, layout and paging controls');
check('.ffla-reviews-list__items' === $element->controls['listGap']['css'][0]['selector'], 'list gap spaces the cards inside the new items wrapper');

/* ── Store rating badge and dynamic tags ───────────────────────────────── */

$badge = Product_Reviews_Frontend_Render::render_store_badge([]);
check(strpos($badge, 'ffla-store-badge--card') !== false && strpos($badge, '10 reviews') !== false, 'store badge with review count');
check(strpos($badge, '30% verified buyers') !== false, 'store badge shows the verified share');
check(strpos($badge, '>3.6<') !== false, 'store badge average: ' . (preg_match('/__average">([^<]+)/', $badge, $m) ? $m[1] : '?'));
$badge = Product_Reviews_Frontend_Render::render_store_badge(['style' => 'compact', 'theme' => 'dark', 'showVerified' => false]);
check(strpos($badge, 'ffla-store-badge--compact ffla-store-badge--dark') !== false && strpos($badge, 'verified') === false, 'compact dark badge without the verified share');
$element = new \Bricks\FFLA_Store_Rating_Badge(['link' => ['url' => 'https://store.test/reviews/']]);
$html = capture(function () use ($element) {
    $element->render();
});
check(strpos($html, '<a class="ffla-store-badge ffla-store-badge--card ffla-store-badge--light" href="https://store.test/reviews/">') === 0, 'badge with a link is an <a>: ' . substr($html, 0, 120));
check('3.6' === Product_Reviews_Dynamic_Tags::render_content('{ffla_store_review_average}'), 'store average dynamic tag');
check('10 / 30%' === Product_Reviews_Dynamic_Tags::render_content('{ffla_store_review_count} / {ffla_store_review_verified}%'), 'store count and verified dynamic tags');

// No reviews at all: the badge can hide itself.
$saved = $GLOBALS['comments'];
$GLOBALS['comments'] = [];
do_action('wp_update_comment_count', 101, 0, 7);
check('' === Product_Reviews_Frontend_Render::render_store_badge(['hideIfEmpty' => true]), 'badge hidden with no reviews when asked');
$GLOBALS['comments'] = $saved;
do_action('wp_update_comment_count', 101, 7, 0);

echo "OK: {$checks} product-reviews list checks passed\n";
