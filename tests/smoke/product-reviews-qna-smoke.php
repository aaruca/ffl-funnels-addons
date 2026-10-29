<?php
/**
 * Offline check of product questions and answers: asking (guests, signed-in
 * only, honeypot, nonce, limits, held by default), answering (who may, store
 * answers labelled and never held, verified buyers), the product tab and its
 * count, the list (answers order, votes, paging and search over AJAX), the
 * admin Questions page (answer, approve, trash), the emails to the store and
 * the asker, and that Q&A never mixes with reviews (rating rules, votes,
 * WooCommerce's review list, the review query, coupons).
 *
 * Run: php tests/smoke/product-reviews-qna-smoke.php
 */

require __DIR__ . '/product-reviews-stubs.php';
load_bricks_stubs();

load_module([
    'includes/class-product-reviews-core.php',
    'includes/class-product-reviews-query.php',
    'includes/class-product-reviews-criteria.php',
    'includes/class-product-reviews-frontend-render.php',
    'includes/class-product-reviews-order-hub.php',
    'includes/class-product-reviews-assets.php',
    'includes/class-product-reviews-moderation.php',
    'includes/class-product-reviews-notifications.php',
    'includes/class-product-reviews-ajax.php',
    'includes/class-product-reviews-email-template.php',
    'includes/class-product-reviews-request-rules.php',
    'includes/class-product-reviews-consent.php',
    'includes/class-product-reviews-tracking.php',
    'includes/class-product-reviews-email.php',
    'includes/class-product-reviews-coupons.php',
    'includes/class-product-reviews-qna.php',
    'integrations/class-product-reviews-turnstile.php',
    'integrations/class-product-reviews-dynamic-tags.php',
    'integrations/elements/product-qna.php',
    'admin/class-product-reviews-qna-admin.php',
]);
Product_Reviews_Core::init();
Product_Reviews_Notifications::init();
Product_Reviews_Coupons::init();
Product_Reviews_Qna::init();

function settings(array $overrides = []): void
{
    $GLOBALS['options']['ffla_product_reviews_settings'] = array_merge(
        ['order_review_page_id' => '500', 'qna_enabled' => '1', 'coupon_enabled' => '1', 'moderate_all_reviews' => '1'],
        $overrides
    );
    // Parsed settings are memoized per request; each change here is a new one.
    Product_Reviews_Moderation::flush_memo();
    Product_Reviews_Criteria::flush_memo();
}

function ask(array $post, int $user = 0): array
{
    reset_request($user);
    $_POST = $post + ['action' => 'ffla_submit_question', 'product_id' => '101', 'question' => 'Does it fit a 30mm tube?', 'redirect_to' => 'https://store.test/?p=101'];
    if ($user > 0 && !isset($post['ffla_qna_nonce'])) {
        $_POST['ffla_qna_nonce'] = 'nonce-ffla_submit_question';
    }
    return expect_redirect(['Product_Reviews_Qna', 'handle_ask']);
}

function answer(int $question_id, array $post, int $user = 0): array
{
    reset_request($user);
    $_POST = $post + ['action' => 'ffla_submit_answer', 'question_id' => (string) $question_id, 'answer' => 'Yes, it does.', 'redirect_to' => 'https://store.test/?p=101'];
    if ($user > 0 && !isset($post['ffla_qna_nonce'])) {
        $_POST['ffla_qna_nonce'] = 'nonce-ffla_submit_answer';
    }
    return expect_redirect(['Product_Reviews_Qna', 'handle_answer']);
}

function last_comment(): WP_Comment
{
    return end($GLOBALS['comments']);
}

settings();

/* ── Asking ────────────────────────────────────────────────────────────── */

$r = ask(['author' => 'Gail Guest', 'email' => 'gail@example.com']);
$q1 = last_comment();
check('asked' === $r['ffla_qna_status'] && strpos($r['__url'], '#qna') !== false, 'guest question accepted, back to the Q&A');
check('ffla_question' === $q1->comment_type && 0 === (int) $q1->comment_parent && 101 === (int) $q1->comment_post_ID, 'stored as a question on the product');
check('0' === (string) $q1->comment_approved, 'held for approval by default');
check('' === (string) get_comment_meta($q1->comment_ID, 'rating', true) && '' === (string) get_comment_meta($q1->comment_ID, 'ffla_verified_purchase', true), 'no review meta on a question');
$store_mail = end($GLOBALS['mails']);
check('admin@store.test' === $store_mail['to'] && strpos($store_mail['subject'], 'New question about Hunting Rifle Scope') === 0, 'the store hears about it');
check(strpos($store_mail['body'], 'waits for approval') !== false && strpos($store_mail['body'], 'page=ffla-product-reviews-questions') !== false, 'with its status and a link to answer it');

$count = count($GLOBALS['comments']);
$r = ask(['author' => 'Bot', 'email' => 'bot@example.com', 'ffla_hp' => 'spam']);
check('error' === $r['ffla_qna_status'] && count($GLOBALS['comments']) === $count, 'honeypot refuses');
$r = ask(['author' => '', 'email' => 'x']);
check('error' === $r['ffla_qna_status'], 'a guest needs a name and email');
$r = ask(['question' => 'Hi'], 7);
check('error' === $r['ffla_qna_status'] && 'Please write your question.' === $r['ffla_qna_message'], 'a question needs some text');
$r = ask(['ffla_qna_nonce' => 'stale'], 7);
check('error' === $r['ffla_qna_status'] && strpos($r['ffla_qna_message'], 'Security check') === 0, 'a signed-in user needs a valid nonce');
$r = ask(['product_id' => '105']);
check('error' === $r['ffla_qna_status'], 'no questions on unpublished products');
$GLOBALS['posts'][105] = ['product', 'draft', 'Unreleased'];
$r = ask(['product_id' => '105']);
check('error' === $r['ffla_qna_status'], 'not on a draft product either');

settings(['qna_who_asks' => 'logged_in']);
$r = ask(['author' => 'Gus', 'email' => 'gus@example.com']);
check('error' === $r['ffla_qna_status'] && 'Please log in to ask a question.' === $r['ffla_qna_message'], 'signed-in only: guests are refused');
$r = ask(['question' => 'Is the reticle illuminated?'], 7);
$q2 = last_comment();
check('asked' === $r['ffla_qna_status'] && 7 === (int) $q2->user_id && 'buyer@example.com' === $q2->comment_author_email, 'signed-in question under the account');
settings();

settings(['qna_moderate_questions' => '0']);
ask(['author' => 'Pat Public', 'email' => 'pat@example.com', 'question' => 'What is the eye relief?']);
$q3 = last_comment();
check('1' === (string) $q3->comment_approved, 'moderation off: published at once');
settings(['qna_moderate_questions' => '0', 'forbidden_words' => 'scam']);
ask(['author' => 'Rude Ron', 'email' => 'ron@example.com', 'question' => 'Is this a scam product?']);
check('0' === (string) last_comment()->comment_approved, 'a forbidden word holds it anyway');
settings();

$GLOBALS['transients'] = [];
for ($i = 0; $i < 5; $i++) {
    ask(['author' => 'Flood ' . $i, 'email' => "flood$i@example.com", 'question' => "Question number $i here?"]);
}
$r = ask(['author' => 'Flood 6', 'email' => 'flood6@example.com']);
check('error' === $r['ffla_qna_status'] && strpos($r['ffla_qna_message'], 'Too many') === 0, 'five guest messages an hour per connection');
$GLOBALS['transients'] = [];

settings(['qna_enabled' => '0']);
$r = ask(['author' => 'Off', 'email' => 'off@example.com']);
check('error' === $r['ffla_qna_status'], 'Q&A off: nothing accepted');
settings();

/* ── Answering ─────────────────────────────────────────────────────────── */

wp_set_comment_status($q1->comment_ID, 'approve');
$r = answer((int) $q1->comment_ID, ['author' => 'Random', 'email' => 'random@example.com']);
check('error' === $r['ffla_qna_status'] && 'Only the store can answer questions here.' === $r['ffla_qna_message'], 'by default only the store answers');

$sent_before = count($GLOBALS['mails']);
$r = answer((int) $q1->comment_ID, ['answer' => 'Yes: 30mm rings fit.'], 9);
$a1 = last_comment();
check('answered' === $r['ffla_qna_status'] && 'ffla_answer' === $a1->comment_type && (int) $q1->comment_ID === (int) $a1->comment_parent, 'staff answer stored under the question');
check('1' === (string) $a1->comment_approved && 1 === (int) get_comment_meta($a1->comment_ID, 'ffla_is_store_answer', true), 'store answers are published and labelled');
check('' === (string) get_comment_meta($a1->comment_ID, 'ffla_is_store_reply', true), 'not mistaken for a review reply');
$asker = end($GLOBALS['mails']);
check(count($GLOBALS['mails']) === $sent_before + 1 && 'gail@example.com' === $asker['to'] && strpos($asker['body'], 'Yes: 30mm rings fit.') !== false, 'the asker is emailed the answer');
check(strpos($asker['body'], '#question-' . $q1->comment_ID) !== false, 'with a link to the question');

settings(['qna_who_answers' => 'buyers']);
$r = answer((int) $q1->comment_ID, [], 8);
check('error' === $r['ffla_qna_status'], 'buyers only: a non-buyer cannot answer');
$r = answer((int) $q1->comment_ID, ['answer' => 'Mine fits fine.'], 7);
$a2 = last_comment();
check('answered' === $r['ffla_qna_status'] && '0' === (string) $a2->comment_approved, 'a buyer answers; held for approval');
$sent_before = count($GLOBALS['mails']);
wp_set_comment_status($a2->comment_ID, 'approve');
check(count($GLOBALS['mails']) === $sent_before + 1, 'the asker is emailed when the held answer is approved');
wp_set_comment_status($a2->comment_ID, 'hold');
wp_set_comment_status($a2->comment_ID, 'approve');
check(count($GLOBALS['mails']) === $sent_before + 1, 'only once per answer');
settings(['qna_who_answers' => 'anyone', 'qna_moderate_answers' => '0']);
$r = answer((int) $q1->comment_ID, ['author' => 'Ann Other', 'email' => 'gail@example.com', 'answer' => 'I asked this too.']);
check('1' === (string) last_comment()->comment_approved, 'anyone may answer; moderation off publishes it');
check($sent_before + 1 === count($GLOBALS['mails']), 'the asker is not emailed about their own answer');
$r = answer((int) $q2->comment_ID, ['author' => 'Early', 'email' => 'early@example.com']);
check('error' === $r['ffla_qna_status'], 'no answers to a question that is not published');
settings();

/* ── List, tab, count ──────────────────────────────────────────────────── */

wp_set_comment_status($q2->comment_ID, 'approve');
add_comment_meta((int) $a2->comment_ID, 'ffla_helpful_yes', 4, true);
$html = capture(function () {
    Product_Reviews_Qna::render(101);
});
check(strpos($html, 'id="question-' . $q1->comment_ID . '"') !== false && strpos($html, 'Is the reticle illuminated?') !== false, 'published questions are listed');
check(strpos($html, 'Is this a scam product?') === false, 'held questions are not');
preg_match('#id="question-' . $q1->comment_ID . '">(.*?)</article>#s', $html, $m);
$pos_store = strpos($m[1], 'Yes: 30mm rings fit.');
$pos_buyer = strpos($m[1], 'Mine fits fine.');
check($pos_store !== false && $pos_buyer !== false && $pos_store < $pos_buyer, 'the store answer comes first');
check(strpos($m[1], 'Store answer') !== false && strpos($m[1], 'Gun &amp; Gear') !== false, 'store answers carry the store name and label');
check(strpos($m[1], 'Verified buyer') !== false, 'a buyer\'s answer shows Verified buyer');
check(strpos($html, 'Asked by Gail G.') !== false, 'askers are named like reviewers');
check(strpos($html, 'data-vote="yes" data-comment-id="' . $a1->comment_ID . '"') !== false && strpos($html, 'data-vote="no"') === false, 'helpful votes on answers, no downvotes');
check(strpos($html, 'Ask a question') !== false && strpos($html, 'name="ffla_hp"') !== false, 'ask form with honeypot');
check(strpos($html, 'Answer this question') === false, 'shoppers do not get an answer form when only the store answers');

$GLOBALS['user'] = 9;
$html = capture(function () {
    Product_Reviews_Qna::render(101);
});
check(strpos($html, 'Answer this question') !== false, 'staff get an answer form under each question');
$GLOBALS['user'] = 0;

$tabs = Product_Reviews_Qna::add_product_tab(['reviews' => ['title' => 'Reviews']]);
$GLOBALS['queried'] = 101;
$GLOBALS['is_product'] = true;
wp_cache_delete('qna_count_101', 'ffla_product_reviews');
$tabs = Product_Reviews_Qna::add_product_tab(['reviews' => ['title' => 'Reviews']]);
check('Questions & Answers (3)' === $tabs['ffla_qna']['title'], 'product tab with the number of published questions: ' . $tabs['ffla_qna']['title']);
settings(['qna_tab' => '0']);
check(!isset(Product_Reviews_Qna::add_product_tab([])['ffla_qna']), 'tab can be switched off');
settings();
check('3' === Product_Reviews_Dynamic_Tags::render_content('{ffla_question_count}'), 'question count dynamic tag');

/* ── Paging and search over AJAX ───────────────────────────────────────── */

for ($i = 0; $i < 6; $i++) {
    add_test_comment(['comment_post_ID' => 102, 'comment_type' => 'ffla_question', 'comment_author' => "Asker $i", 'comment_content' => "Bag question $i" . (3 === $i ? ' about the zipper' : ''), 'comment_date_gmt' => sprintf('2026-04-%02d 10:00:00', $i + 1)]);
}
reset_request();
$_POST = ['product' => '102', 'perPage' => '4', 'page' => '1'];
[$ok, $page1] = expect_json(['Product_Reviews_Qna', 'ajax_load']);
check($ok && 4 === substr_count($page1['html'], '<article') && $page1['hasMore'], 'first page of questions, more to come');
check(strpos($page1['html'], 'Bag question 5') < strpos($page1['html'], 'Bag question 4'), 'newest first');
$_POST = ['product' => '102', 'perPage' => '4', 'page' => '2'];
[$ok, $page2] = expect_json(['Product_Reviews_Qna', 'ajax_load']);
check(2 === substr_count($page2['html'], '<article') && !$page2['hasMore'], 'second page with the rest');
$_POST = ['product' => '102', 'search' => 'zipper'];
[$ok, $found] = expect_json(['Product_Reviews_Qna', 'ajax_load']);
check(1 === substr_count($found['html'], '<article') && strpos($found['html'], 'about the zipper') !== false, 'search');
$_POST = ['product' => '102', 'search' => 'nothing-like-this'];
[$ok, $none] = expect_json(['Product_Reviews_Qna', 'ajax_load']);
check('' === $none['html'] && 'No questions match your search.' === $none['status'], 'no match');
$_POST = ['product' => '105'];
[$ok] = expect_json(['Product_Reviews_Qna', 'ajax_load']);
check(!$ok, 'no Q&A for an unpublished product');

/* ── Votes on answers ──────────────────────────────────────────────────── */

reset_request();
$_POST = ['comment_id' => (string) $a1->comment_ID, 'vote' => 'yes'];
[$ok, $vote] = expect_json(['Product_Reviews_Ajax', 'vote_review_helpful']);
check($ok && 1 === $vote['count'], 'helpful vote on an answer');
settings(['qna_votes' => '0']);
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
[$ok] = expect_json(['Product_Reviews_Ajax', 'vote_review_helpful']);
check(!$ok, 'answer votes can be switched off on their own');
settings(['enable_helpful_votes' => '0']);
$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
[$ok] = expect_json(['Product_Reviews_Ajax', 'vote_review_helpful']);
check($ok, 'switching review votes off leaves answer votes alone');
settings();
$_POST = ['comment_id' => (string) $q1->comment_ID, 'vote' => 'yes'];
$_SERVER['REMOTE_ADDR'] = '198.51.100.11';
[$ok] = expect_json(['Product_Reviews_Ajax', 'vote_review_helpful']);
check(!$ok, 'questions themselves take no votes');

/* ── Q&A never mixes with reviews ──────────────────────────────────────── */

$args = Product_Reviews_Qna::exclude_from_review_list(['post_id' => 101]);
check(['ffla_question', 'ffla_answer'] === $args['type__not_in'], 'WooCommerce\'s own review list leaves Q&A out');
check(['post_id' => 500] === Product_Reviews_Qna::exclude_from_review_list(['post_id' => 500]), 'other posts\' comments are untouched');
$found = Product_Reviews_Query::find(['products' => [101], 'perPage' => 50]);
check([] === $found['ids'], 'the review query never returns Q&A');
check('' === (string) get_comment_meta($a1->comment_ID, '_ffla_review_coupon_checked', true), 'no coupon decision for answers');
check('1' === (string) $q3->comment_approved, 'Hold all new reviews does not hold questions (Q&A has its own setting)');

/* ── Admin Questions page ──────────────────────────────────────────────── */

$admin = new Product_Reviews_Qna_Admin();
$admin->init();
reset_request(9);
$counts = Product_Reviews_Qna_Admin::counts();
check($counts['pending'] >= 1 && $counts['answered'] >= 1 && $counts['unanswered'] >= 1, 'counts per view: ' . json_encode($counts));

$_GET = ['page' => 'ffla-product-reviews-questions', 'view' => 'pending'];
$page = capture(['Product_Reviews_Qna_Admin', 'render']);
check(strpos($page, 'Is this a scam product?') !== false && strpos($page, 'Approve and answer') !== false, 'pending view with Approve and answer');
$_GET = ['page' => 'ffla-product-reviews-questions', 'view' => 'unanswered'];
$page = capture(['Product_Reviews_Qna_Admin', 'render']);
check(strpos($page, 'What is the eye relief?') !== false && strpos($page, 'Does it fit a 30mm tube?') === false, 'unanswered view leaves answered questions out');

$held = null;
foreach ($GLOBALS['comments'] as $c) {
    if ('Is this a scam product?' === $c->comment_content) {
        $held = $c;
    }
}
reset_request(9);
$_POST = ['action' => 'ffla_qna_admin_answer', 'question_id' => (string) $held->comment_ID, 'answer' => 'No, it is genuine.', 'view' => 'pending', '_wpnonce' => 'nonce-ffla_qna_admin_answer_' . $held->comment_ID];
$_REQUEST = $_POST;
$back = expect_redirect([$admin, 'handle_answer']);
check('answered' === $back['qna_notice'] && '1' === (string) $held->comment_approved, 'answering a held question publishes it');
check('No, it is genuine.' === last_comment()->comment_content && 1 === (int) get_comment_meta(last_comment()->comment_ID, 'ffla_is_store_answer', true), 'the admin answer is a store answer');

reset_request(9);
$_GET = ['action' => 'ffla_qna_admin_status', 'comment_id' => (string) $q3->comment_ID, 'to' => 'trash', '_wpnonce' => 'nonce-ffla_qna_admin_status_' . $q3->comment_ID];
$_REQUEST = $_GET;
$back = expect_redirect([$admin, 'handle_status']);
check('trashed' === $back['qna_notice'] && 'trash' === $q3->comment_approved, 'trash from the Questions page');

reset_request(7);
$_GET = ['comment_id' => (string) $q1->comment_ID, 'to' => 'trash', '_wpnonce' => 'nonce-ffla_qna_admin_status_' . $q1->comment_ID];
$_REQUEST = $_GET;
try {
    $admin->handle_status();
    check(false, 'customers cannot moderate questions');
} catch (Died $d) {
    check(403 === $d->status, 'customers cannot moderate questions');
}
reset_request(9);
$_GET = ['comment_id' => (string) $a1->comment_ID + 1000, 'to' => 'trash', '_wpnonce' => 'nonce-ffla_qna_admin_status_' . ((int) $a1->comment_ID + 1000)];
$_REQUEST = $_GET;
try {
    $admin->handle_status();
    check(false, 'only Q&A comments are moderated here');
} catch (Died $d) {
    check(404 === $d->status, 'only Q&A comments are moderated here');
}

/* ── Bricks element ────────────────────────────────────────────────────── */

$element = new \Bricks\FFLA_Product_Qna(['productId' => '101', 'title' => 'Ask us', 'showForm' => 'hide', 'showSearch' => 'hide']);
$html = capture(function () use ($element) {
    $element->render();
});
check(strpos($html, 'class="ffla-qna" id="qna"') !== false && strpos($html, '<h3 class="ffla-qna__title">Ask us</h3>') !== false, 'element with its title');
check(strpos($html, 'Ask a question') === false && strpos($html, 'data-ffla-qna-search') === false, 'form and search can be hidden');
settings(['qna_enabled' => '0']);
$html = $element->render();
check(strpos((string) $html, 'switched off') !== false, 'element says when Q&A is off');
settings();

// Backslashes survive (comment data is slashed into wp_new_comment()).
ask(['author' => 'Path Paul', 'email' => 'paul@example.com', 'question' => 'Does it work with C:\\Scopes\\ mounts?']);
check('Does it work with C:\\Scopes\\ mounts?' === last_comment()->comment_content, 'backslashes in a question are kept');

echo "OK: {$checks} product-reviews Q&A checks passed\n";
