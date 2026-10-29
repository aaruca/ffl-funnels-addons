<?php
/**
 * Fixture for product-reviews-list-ui-smoke.js: the real renderer and AJAX
 * endpoint over the in-memory stubs, with a fixed set of reviews.
 *
 *   php product-reviews-list-ui-fixture.php page '<list settings JSON>'
 *   php product-reviews-list-ui-fixture.php qna '{}'
 *   php product-reviews-list-ui-fixture.php ajax '<POST fields JSON>'
 *
 * `page` prints the list markup; `ajax` prints what admin-ajax.php would
 * answer ({"success":…,"data":…}).
 */

require __DIR__ . '/product-reviews-stubs.php';

load_module([
    'includes/class-product-reviews-core.php',
    'includes/class-product-reviews-query.php',
    'includes/class-product-reviews-criteria.php',
    'includes/class-product-reviews-frontend-render.php',
    'includes/class-product-reviews-assets.php',
    'includes/class-product-reviews-moderation.php',
    'includes/class-product-reviews-notifications.php',
    'includes/class-product-reviews-ajax.php',
    'includes/class-product-reviews-email-template.php',
    'includes/class-product-reviews-email.php',
    'includes/class-product-reviews-qna.php',
]);

$GLOBALS['options']['ffla_product_reviews_settings'] = ['enable_criteria' => '0', 'enable_not_helpful_votes' => '1', 'qna_enabled' => '1', 'qna_per_page' => '3'];

$photos = [];
for ($n = 1; $n <= 3; $n++) {
    $photos[] = add_test_attachment('https://store.test/uploads/photo-' . $n . '.svg');
}

// Twelve reviews of product 101, newest last in this list.
$people  = ['Ann Buyer', 'Bob Stone', 'Cy Rivers', 'Dee Lake', 'Eve Wood', 'Fay Glen', 'Gus Hill', 'Hal Brook', 'Ivy Moss', 'Jo Ridge', 'Kim Vale', 'Lou Park'];
$ratings = [5, 4, 1, 3, 5, 2, 5, 4, 5, 3, 5, 1];
foreach ($people as $i => $name) {
    $meta = ['rating' => $ratings[$i], 'ffla_helpful_yes' => $i, 'ffla_helpful_no' => 0];
    if (0 === $i) {
        $meta['ffla_review_media_ids'] = [$photos[0], $photos[1], $photos[2]];
    }
    if (4 === $i) {
        $meta['ffla_review_media_ids'] = [$photos[1]];
    }
    add_test_comment([
        'comment_post_ID'      => 101,
        'comment_author'       => $name,
        'comment_author_email' => strtolower(str_replace(' ', '.', $name)) . '@example.com',
        'comment_content'      => 'Review by ' . $name . (5 === $i ? ' about the turret clicks' : ''),
        'comment_date_gmt'     => sprintf('2026-03-%02d 10:00:00', $i + 1),
        'meta'                 => $meta,
    ]);
}
$GLOBALS['product_props'][101] += ['rating_counts' => [5 => 5, 4 => 2, 3 => 2, 2 => 1, 1 => 2], 'average' => '3.58', 'review_count' => 12];

// Seven questions on product 101, every other one answered by the store.
for ($i = 0; $i < 7; $i++) {
    $q = add_test_comment([
        'comment_post_ID'  => 101,
        'comment_type'     => 'ffla_question',
        'comment_author'   => 'Asker ' . $i,
        'comment_content'  => 'Question ' . $i . (4 === $i ? ' about the turret caps' : '') . '?',
        'comment_date_gmt' => sprintf('2026-04-%02d 10:00:00', $i + 1),
    ]);
    if (0 === $i % 2) {
        add_test_comment([
            'comment_post_ID'  => 101,
            'comment_type'     => 'ffla_answer',
            'comment_parent'   => $q,
            'comment_author'   => 'Store Staff',
            'user_id'          => 9,
            'comment_content'  => 'Answer to question ' . $i,
            'comment_date_gmt' => sprintf('2026-04-%02d 12:00:00', $i + 1),
            'meta'             => ['ffla_is_store_answer' => 1, 'ffla_helpful_yes' => 1],
        ]);
    }
}

$mode = $argv[1] ?? 'page';
$args = json_decode($argv[2] ?? '{}', true) ?: [];

if ('ajax' === $mode) {
    $_POST = $args;
    try {
        if (($args['action'] ?? '') === 'ffla_vote_review_helpful') {
            Product_Reviews_Ajax::vote_review_helpful();
        } elseif (($args['action'] ?? '') === 'ffla_load_questions') {
            Product_Reviews_Qna::ajax_load();
        } else {
            Product_Reviews_Ajax::load_reviews();
        }
    } catch (JsonSent $j) {
        echo json_encode(['success' => $j->success, 'data' => $j->payload]);
    }
    exit(0);
}

$product = isset($args['product']) ? (int) $args['product'] : 101;
unset($args['product']);
if ('qna' === $mode) {
    Product_Reviews_Qna::render($product, $args);
} else {
    Product_Reviews_Frontend_Render::render_reviews_list($product, $args);
}
echo "\n<script>window.fflaProductReviews = " . json_encode($GLOBALS['scripts']['localized']['fflaProductReviews'] ?? null) . ";</script>\n";
