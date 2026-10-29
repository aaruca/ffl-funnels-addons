<?php
/**
 * Offline check of the review form extras and admin tools: CAPTCHA with the
 * store's own keys (Turnstile, hCaptcha, reCAPTCHA v2 / v3), the terms
 * checkbox, required rating criteria, questions about the reviewer, the
 * media limits, the "log in" links, the email to the store about new
 * reviews, and the Photos and videos box on a review's edit screen.
 *
 * Run: php tests/smoke/product-reviews-form-smoke.php
 */

require __DIR__ . '/product-reviews-stubs.php';

load_module([
    'includes/class-product-reviews-core.php',
    'includes/class-product-reviews-query.php',
    'includes/class-product-reviews-criteria.php',
    'includes/class-product-reviews-frontend-render.php',
    'includes/class-product-reviews-order-hub.php',
    'includes/class-product-reviews-image-privacy.php',
    'includes/class-product-reviews-assets.php',
    'includes/class-product-reviews-moderation.php',
    'includes/class-product-reviews-notifications.php',
    'includes/class-product-reviews-ajax.php',
    'includes/class-product-reviews-email-template.php',
    'includes/class-product-reviews-request-rules.php',
    'includes/class-product-reviews-consent.php',
    'includes/class-product-reviews-tracking.php',
    'includes/class-product-reviews-email.php',
    'includes/class-product-reviews-qna.php',
    'includes/class-product-reviews-form-extras.php',
    'integrations/class-product-reviews-turnstile.php',
    'includes/class-product-reviews-captcha.php',
    'admin/class-product-reviews-review-media-admin.php',
]);
Product_Reviews_Core::init();
Product_Reviews_Notifications::init();
Product_Reviews_Qna::init();
Product_Reviews_Form_Extras::init();

const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
const RECAPTCHA_VERIFY = 'https://www.google.com/recaptcha/api/siteverify';
const HCAPTCHA_VERIFY  = 'https://api.hcaptcha.com/siteverify';

function settings(array $overrides = []): void
{
    $GLOBALS['options']['ffla_product_reviews_settings'] = array_merge(['order_review_page_id' => '500', 'enable_criteria' => '1', 'qna_enabled' => '1'], $overrides);
    Product_Reviews_Criteria::flush_memo();
    Product_Reviews_Moderation::flush_memo();
    Product_Reviews_Form_Extras::flush_memo();
    Product_Reviews_Captcha::reset();
}

function submit(array $post, int $user = 0): array
{
    reset_request($user);
    set_static('Product_Reviews_Core', 'form_checks_passed', false);
    set_static('Product_Reviews_Core', 'token_order', null);
    Product_Reviews_Captcha::reset();
    // Every submission here comes from one IP; the guest hourly limit is checked elsewhere.
    $GLOBALS['transients'] = [];
    $_POST = $post + ['action' => 'ffla_submit_product_review', 'comment_post_ID' => '102', 'rating' => '5', 'comment' => 'Solid bag.', 'author' => 'Guest Gwen', 'email' => 'gwen' . mt_rand() . '@example.com'];
    if ($user > 0 && !isset($post['ffla_review_form_nonce'])) {
        $_POST['ffla_review_form_nonce'] = 'nonce-ffla_review_form';
    }
    return expect_redirect(['Product_Reviews_Core', 'handle_form_submission']);
}

function form_html(int $product_id = 102, array $settings = []): string
{
    return capture(function () use ($product_id, $settings) {
        Product_Reviews_Frontend_Render::render_review_form($product_id, $settings);
    });
}

function last_comment(): WP_Comment
{
    return end($GLOBALS['comments']);
}

settings();

/* ── CAPTCHA with the store's own keys ─────────────────────────────────── */

check('plugin' === Product_Reviews_Captcha::provider() && !Product_Reviews_Captcha::is_available() && Product_Reviews_Captcha::passes(), 'default: the Turnstile plugin, absent here, so nothing to check');

settings(['captcha_provider' => 'turnstile', 'captcha_site_key' => 'site-123', 'captcha_secret_key' => '']);
check(!Product_Reviews_Captcha::is_available(), 'own keys: needs both keys');
settings(['captcha_provider' => 'turnstile', 'captcha_site_key' => 'site-123', 'captcha_secret_key' => 'secret-xyz']);
$html = form_html();
check(strpos($html, 'class="cf-turnstile" data-sitekey="site-123"') !== false, 'Turnstile widget with the site key');
check(isset($GLOBALS['scripts']['enqueued']['ffla-review-captcha-turnstile']), 'the provider script loads with the form');
check(strpos($html, 'secret-xyz') === false, 'the secret never reaches the page');

$GLOBALS['http_responses'][TURNSTILE_VERIFY] = ['code' => 200, 'body' => '{"success":false}'];
$r = submit(['cf-turnstile-response' => 'bad-token']);
check('error' === $r['ffla_review_status'] && strpos($r['ffla_review_message'], 'validation failed') !== false, 'a failed check refuses the review');
$req = end($GLOBALS['http_requests']);
check(TURNSTILE_VERIFY === $req['url'] && 'secret-xyz' === $req['args']['body']['secret'] && 'bad-token' === $req['args']['body']['response'], 'verified server-side with the secret');
$count = count($GLOBALS['http_requests']);
$r = submit([]);
check('error' === $r['ffla_review_status'] && count($GLOBALS['http_requests']) === $count, 'no token: refused without calling the provider');
$GLOBALS['http_responses'][TURNSTILE_VERIFY] = ['code' => 200, 'body' => '{"success":true}'];
$r = submit(['cf-turnstile-response' => 'good-token']);
check('success' === $r['ffla_review_status'], 'a passed check sends the review');
check(1 === count(array_filter(array_slice($GLOBALS['http_requests'], $count), function ($q) {
    return TURNSTILE_VERIFY === $q['url'];
})), 'the token is verified once per request');
unset($GLOBALS['http_responses'][TURNSTILE_VERIFY]);
$r = submit(['cf-turnstile-response' => 'good-token']);
check('error' === $r['ffla_review_status'] && strpos(implode("\n", $GLOBALS['log']), 'could not reach the provider') !== false, 'provider unreachable: refused and logged');

// The hub's signed link is proof enough.
$order = make_order(8001, 'hub@example.com', [[102, 1]]);
$r     = submit(['ffla_order_review_token' => Product_Reviews_Core::build_order_review_token(8001, 'hub@example.com')]);
check('success' === $r['ffla_review_status'], 'no CAPTCHA on a signed review link');

settings(['captcha_provider' => 'recaptcha_v3', 'captcha_site_key' => 'v3-site', 'captcha_secret_key' => 'v3-secret', 'captcha_v3_threshold' => '0.7']);
$html = form_html();
check(strpos($html, 'data-ffla-recaptcha-v3="v3-site"') !== false && strpos($html, 'name="g-recaptcha-response"') !== false, 'reCAPTCHA v3: hidden token field filled by the script');
check(isset($GLOBALS['scripts']['enqueued']['ffla-review-captcha-recaptcha_v3']), 'v3 script');
check(strpos($html, 'data-action="ffla_product_review"') !== false, 'the token is asked for the review form\'s action');
$GLOBALS['http_responses'][RECAPTCHA_VERIFY] = ['code' => 200, 'body' => '{"success":true,"score":0.6,"action":"ffla_product_review"}'];
$r = submit(['g-recaptcha-response' => 'v3-token']);
check('error' === $r['ffla_review_status'], 'a score under the minimum refuses');
$GLOBALS['http_responses'][RECAPTCHA_VERIFY] = ['code' => 200, 'body' => '{"success":true,"score":0.9,"action":"login"}'];
$r = submit(['g-recaptcha-response' => 'v3-token']);
check('error' === $r['ffla_review_status'], 'a token issued for another form on the site (action login) refuses');
$GLOBALS['http_responses'][RECAPTCHA_VERIFY] = ['code' => 200, 'body' => '{"success":true,"score":0.9,"action":"ffla_product_review"}'];
$r = submit(['g-recaptcha-response' => 'v3-token']);
check('success' === $r['ffla_review_status'], 'a score over the minimum passes');

settings(['captcha_provider' => 'hcaptcha', 'captcha_site_key' => 'h-site', 'captcha_secret_key' => 'h-secret']);
check(strpos(form_html(), 'class="h-captcha" data-sitekey="h-site"') !== false, 'hCaptcha widget');
$GLOBALS['http_responses'][HCAPTCHA_VERIFY] = ['code' => 200, 'body' => '{"success":true}'];
$r = submit(['h-captcha-response' => 'h-token']);
check('success' === $r['ffla_review_status'] && HCAPTCHA_VERIFY === end($GLOBALS['http_requests'])['url'], 'hCaptcha verified');
settings(['captcha_provider' => 'recaptcha_v2', 'captcha_site_key' => 'v2-site', 'captcha_secret_key' => 'v2-secret']);
check(strpos(form_html(), 'class="g-recaptcha" data-sitekey="v2-site"') !== false, 'reCAPTCHA v2 widget');
settings(['captcha_provider' => 'none']);
check(strpos(form_html(), 'captcha') === false && Product_Reviews_Captcha::passes(), 'none: no widget, nothing checked');

// Q&A forms use it too (guests).
settings(['captcha_provider' => 'turnstile', 'captcha_site_key' => 'site-123', 'captcha_secret_key' => 'secret-xyz']);
$GLOBALS['http_responses'][TURNSTILE_VERIFY] = ['code' => 200, 'body' => '{"success":false}'];
reset_request();
Product_Reviews_Captcha::reset();
$_POST = ['product_id' => '102', 'question' => 'Is it waterproof?', 'author' => 'Q', 'email' => 'q@example.com', 'redirect_to' => 'https://store.test/?p=102'];
$r = expect_redirect(['Product_Reviews_Qna', 'handle_ask']);
check('error' === $r['ffla_qna_status'], 'a guest question needs the CAPTCHA');
settings();

/* ── Terms checkbox ────────────────────────────────────────────────────── */

$GLOBALS['posts'][600] = ['page', 'publish', 'Review guidelines'];
settings(['terms_on_reviews' => '1', 'terms_page_id' => '600', 'terms_text' => 'I accept the {terms} and want this published.']);
$html = form_html();
check(strpos($html, 'name="ffla_terms" value="1" required') !== false, 'terms checkbox on the review form');
check(strpos($html, 'I accept the <a href="https://store.test/?p=600" target="_blank" rel="noopener">Review guidelines</a> and want this published.') !== false, '{terms} becomes the page link');
$r = submit([]);
check('error' === $r['ffla_review_status'] && 'Please accept the terms to continue.' === $r['ffla_review_message'], 'not ticked: refused');
$r = submit(['ffla_terms' => '1']);
check('success' === $r['ffla_review_status'], 'ticked: sent');
$hub = make_order(8002, 'terms@example.com', [[103, 1]]);
reset_request();
$_GET = ['ffla_ro' => Product_Reviews_Core::build_order_review_token(8002, 'terms@example.com')];
check(strpos(Product_Reviews_Order_Hub::render_hub(false), 'name="ffla_terms"') !== false, 'terms checkbox on the hub forms too');
settings(['terms_on_qna' => '1']);
reset_request();
$_POST = ['product_id' => '102', 'question' => 'Is it waterproof?', 'author' => 'Q', 'email' => 'q2@example.com', 'redirect_to' => 'https://store.test/?p=102'];
$r = expect_redirect(['Product_Reviews_Qna', 'handle_ask']);
check('error' === $r['ffla_qna_status'] && 'Please accept the terms to continue.' === $r['ffla_qna_message'], 'Q&A: terms required when on');
$_POST['ffla_terms'] = '1';
$r = expect_redirect(['Product_Reviews_Qna', 'handle_ask']);
check('asked' === $r['ffla_qna_status'], 'Q&A: ticked');
settings(['terms_on_reviews' => '1']);
$GLOBALS['options']['wp_page_for_privacy_policy'] = 600;
check(strpos(Product_Reviews_Form_Extras::terms_label_html(), 'Review guidelines</a>') !== false, 'no page chosen: the privacy policy page');
settings();

/* ── Required rating criteria ──────────────────────────────────────────── */

settings(['review_criteria' => "fit|Fit|required\nlooks|Looks"]);
check([['slug' => 'fit', 'label' => 'Fit', 'required' => true], ['slug' => 'looks', 'label' => 'Looks', 'required' => false]] === Product_Reviews_Criteria::get_criteria(), 'criteria parsed with the required flag');
check("fit|Fit|required\nlooks|Looks" === Product_Reviews_Criteria::serialize_definition(Product_Reviews_Criteria::get_criteria()), 'and written back');
$html = form_html();
check(strpos($html, '<legend class="ffla-review-form__legend">Fit</legend>') !== false && strpos($html, 'Looks (optional)') !== false, 'a required criterion is not marked optional');
check(strpos($html, 'name="ffla_criteria_shown" value="1"') !== false, 'the form says it showed the criteria');
$r = submit(['ffla_criteria_shown' => '1', 'ffla_review_criteria' => ['looks' => '4']]);
check('error' === $r['ffla_review_status'] && 'Please rate Fit.' === $r['ffla_review_message'], 'a required criterion left out: refused');
$r = submit(['ffla_criteria_shown' => '1', 'ffla_review_criteria' => ['fit' => '5']]);
check('success' === $r['ffla_review_status'] && 5 === (int) get_comment_meta(last_comment()->comment_ID, 'ffla_review_criteria_fit', true), 'rated: sent and saved');
$r = submit([]);
check('success' === $r['ffla_review_status'], 'a form that did not show the criteria (a Bricks form hiding them) is not held to them');
check(12 === Product_Reviews_Criteria::MAX_CRITERIA, 'up to 12 criteria');
settings();

/* ── Questions about the reviewer ──────────────────────────────────────── */

$definition = implode("\n", [
    'experience|Shooting experience|select|Beginner,Intermediate,Expert|required,public',
    'use|Main use|checkbox|Hunting,Range,Home defense|public',
    'tip|Tip for other buyers|text||',
    'owned|How long have you owned it?|radio|A week,A month,A year|hub',
    'broken line without enough parts',
    'nooptions|No options|select||',
    'experience|Duplicate|text||',
    'bad|Bad type|slider|1,2|',
]);
$parsed = Product_Reviews_Form_Extras::parse($definition);
check(['experience', 'use', 'tip', 'owned'] === array_column($parsed, 'slug'), 'malformed, duplicate and unknown-type lines are dropped');
check(true === $parsed[0]['required'] && true === $parsed[0]['public'] && 'both' === $parsed[0]['where'] && 'hub' === $parsed[3]['where'], 'flags parsed');
check($definition !== Product_Reviews_Form_Extras::serialize($parsed) && 4 === count(Product_Reviews_Form_Extras::parse(Product_Reviews_Form_Extras::serialize($parsed))), 'written back without the bad lines');
$many = '';
for ($i = 0; $i < 15; $i++) {
    $many .= "q$i|Question $i|text||\n";
}
check(10 === count(Product_Reviews_Form_Extras::parse($many)), 'at most 10 questions');

settings(['review_questions' => $definition]);
$html = form_html();
check(strpos($html, 'name="ffla_review_q[experience]" required') !== false && strpos($html, '<option value="Expert">Expert</option>') !== false, 'select question, required');
check(substr_count($html, 'name="ffla_review_q[use][]"') === 3 && strpos($html, 'Main use (optional)') !== false, 'checkbox question with three choices, optional');
check(strpos($html, 'name="ffla_review_q[tip]"') !== false, 'text question');
check(strpos($html, 'ffla_review_q[owned]') === false, 'a hub-only question is not on the product form');
check(strpos($html, 'name="ffla_questions_shown" value="product"') !== false, 'the form says which questions it showed');

$r = submit(['ffla_questions_shown' => 'product']);
check('error' === $r['ffla_review_status'] && 'Please answer: Shooting experience' === $r['ffla_review_message'], 'a required question left out: refused');
$r = submit(['ffla_questions_shown' => 'product', 'ffla_review_q' => ['experience' => 'Wizard']]);
check('error' === $r['ffla_review_status'], 'an answer that is not one of the choices does not count');
$r = submit(['ffla_questions_shown' => 'product', 'ffla_review_q' => ['experience' => 'Expert', 'use' => ['Range', 'Hunting', 'Hacking'], 'tip' => '  Buy the bigger one  ']]);
$answered = last_comment();
check('success' === $r['ffla_review_status'], 'answered: sent');
check('Expert' === get_comment_meta($answered->comment_ID, 'ffla_review_q_experience', true), 'single choice saved');
check('Hunting, Range' === get_comment_meta($answered->comment_ID, 'ffla_review_q_use', true), 'several choices saved in their order, unknown ones dropped');
check('Buy the bigger one' === get_comment_meta($answered->comment_ID, 'ffla_review_q_tip', true), 'text saved, trimmed');

$card = Product_Reviews_Form_Extras::card_answers('', $answered);
check(strpos($card, '<dt>Shooting experience</dt><dd>Expert</dd>') !== false && strpos($card, '<dt>Main use</dt><dd>Hunting, Range</dd>') !== false, 'public answers on the card');
check(strpos($card, 'Buy the bigger one') === false, 'a private answer stays off the card');
check(['Shooting experience' => 'Expert', 'Main use' => 'Hunting, Range', 'Tip for other buyers' => 'Buy the bigger one'] === Product_Reviews_Form_Extras::answers((int) $answered->comment_ID), 'every answer for the admin');
$list = capture(function () {
    Product_Reviews_Frontend_Render::render_reviews_list(102, ['perPage' => 50]);
});
check(strpos($list, 'ffla-review-card__answers') !== false, 'the list shows public answers');

reset_request();
$_GET = ['ffla_ro' => Product_Reviews_Core::build_order_review_token(8002, 'terms@example.com')];
$hub = Product_Reviews_Order_Hub::render_hub(false);
check(strpos($hub, 'ffla_review_q[owned]') !== false && strpos($hub, 'name="ffla_questions_shown" value="hub"') !== false, 'the hub shows its own questions');
$r = submit(['comment_post_ID' => '103', 'ffla_order_review_token' => Product_Reviews_Core::build_order_review_token(8002, 'terms@example.com'), 'ffla_questions_shown' => 'hub', 'ffla_review_q' => ['experience' => 'Beginner', 'owned' => 'A month']]);
check('success' === $r['ffla_review_status'] && 'A month' === get_comment_meta(last_comment()->comment_ID, 'ffla_review_q_owned', true), 'hub answers saved');
check('Beginner' === get_comment_meta(last_comment()->comment_ID, 'ffla_review_q_experience', true), 'a question shown on both forms is saved from the hub too');

// Answers are only taken from the FFL form.
$native = add_test_comment(['comment_post_ID' => 102, 'comment_author' => 'Native']);
$_POST = ['ffla_review_q' => ['experience' => 'Expert']];
Product_Reviews_Form_Extras::save_answers($native);
check('' === (string) get_comment_meta($native, 'ffla_review_q_experience', true), 'not from other forms');
settings();

/* ── Media limits ──────────────────────────────────────────────────────── */

settings(['media_max_files' => '6', 'media_max_mb' => '12']);
set_static('Product_Reviews_Assets', 'script_enqueued', false);
$GLOBALS['scripts']['localized'] = [];
$html = form_html();
$cfg  = $GLOBALS['scripts']['localized']['fflaProductReviews']['fileUpload'];
check(6 === $cfg['maxCount'] && 12 * 1024 * 1024 === $cfg['maxBytes'] && 'You can attach up to 6 files.' === $cfg['maxFiles'], 'the script gets the limits');
check(strpos($html, 'Up to 6 files, 12 MB each.') !== false, 'the help text says them');
settings(['media_max_files' => '99', 'media_max_mb' => '0']);
check(10 === Product_Reviews_Core::media_max_files() && 5 === Product_Reviews_Core::media_max_mb(), 'limits are clamped');
settings();

/* ── Log in links ──────────────────────────────────────────────────────── */

$GLOBALS['options']['comment_registration'] = 1;
$html = form_html(102);
check(strpos($html, 'href="https://store.test/myaccount/?ffla_return=') !== false && strpos($html, '>Log in</a>') !== false, 'log in link to My Account, with where to come back to');
// WooCommerce sends a My Account login to the form's referer: the link itself.
preg_match('/href="(https:\/\/store\.test\/myaccount\/\?ffla_return=[^"]+)"/', $html, $m);
$referer = html_entity_decode($m[1], ENT_QUOTES);
$after   = apply_filters('woocommerce_login_redirect', $referer, null);
check(0 === strpos($after, 'https://store.test/') && false === strpos($after, 'myaccount'), 'after logging in on My Account, the shopper is back on the product: ' . $after);
check('https://store.test/myaccount/' === apply_filters('woocommerce_login_redirect', 'https://store.test/myaccount/', null), 'other My Account logins are left alone');
check('https://store.test/myaccount/?ffla_return=' . rawurlencode('https://evil.test/') === apply_filters('woocommerce_login_redirect', 'https://store.test/myaccount/?ffla_return=' . rawurlencode('https://evil.test/'), null), 'never to another site');
$GLOBALS['wc_pages']['myaccount'] = 0;
check(strpos(form_html(102), 'href="https://store.test/wp-login.php?redirect_to=') !== false, 'without a My Account page, the WordPress login (not the home page)');
unset($GLOBALS['wc_pages']['myaccount']);
settings(['login_url' => 'https://store.test/sign-in/']);
check(strpos(form_html(102), 'href="https://store.test/sign-in/?redirect_to=') !== false, 'a custom log in page');
$GLOBALS['options']['comment_registration'] = 0;
settings();

/* ── New review emails to the store ────────────────────────────────────── */

settings(['notify_store_new_review' => '1', 'notify_store_email' => 'reviews@store.test']);
$before = count($GLOBALS['mails']);
submit(['rating' => '2', 'comment' => 'Strap broke.']);
$mail = end($GLOBALS['mails']);
check(count($GLOBALS['mails']) === $before + 1 && 'reviews@store.test' === $mail['to'], 'the store is emailed about a new review');
check('New 2-star review of Range Bag' === $mail['subject'] && strpos($mail['body'], 'Rating: 2 of 5 stars') !== false && strpos($mail['body'], 'Strap broke.') !== false, 'with the rating and text');
check(strpos($mail['body'], 'Verified buyer: no') !== false && strpos($mail['body'], 'It is published:') !== false, 'with Verified buyer and where it is');
settings(['notify_store_new_review' => '1', 'notify_store_max_rating' => '3', 'moderate_all_reviews' => '1']);
$before = count($GLOBALS['mails']);
submit(['rating' => '5']);
check(count($GLOBALS['mails']) === $before, 'low ratings only: a 5-star review is not emailed');
submit(['rating' => '1', 'comment' => 'Awful.']);
check(count($GLOBALS['mails']) === $before + 1 && strpos(end($GLOBALS['mails'])['body'], 'It waits for your approval:') !== false, 'a held 1-star review is, with the approval link');
settings(['notify_store_new_review' => '0']);
$before = count($GLOBALS['mails']);
submit([]);
check(count($GLOBALS['mails']) === $before, 'off by default');
settings();

/* ── Photos and videos box on the review edit screen ───────────────────── */

$box = new Product_Reviews_Review_Media_Admin();
$img = add_test_attachment('https://store.test/uploads/admin-1.jpg');
$vid = add_test_attachment('https://store.test/uploads/admin-2.mp4', 'video/mp4');
$doc = add_test_attachment('https://store.test/uploads/manual.pdf', 'application/pdf');
$rev = add_test_comment(['comment_post_ID' => 101, 'comment_author' => 'Editee', 'meta' => ['rating' => 4, 'ffla_review_media_ids' => [$img]]]);
$html = Product_Reviews_Review_Media_Admin::box_html(get_comment($rev));
check(strpos($html, 'value="' . $img . '" data-ffla-media-ids') !== false && strpos($html, 'data-ffla-media-remove="' . $img . '"') !== false, 'box lists the review\'s photos');

reset_request(9);
$_POST = ['ffla_review_media_nonce' => 'nonce-ffla_review_media_admin_' . $rev, 'ffla_review_media_ids' => "$img,$vid,$doc,424242"];
$box->save($rev);
check([$img, $vid] === get_comment_meta($rev, 'ffla_review_media_ids', true), 'photos and videos added; other files and unknown IDs dropped');
$_POST['ffla_review_media_ids'] = '';
$box->save($rev);
check(!metadata_exists('comment', $rev, 'ffla_review_media_ids'), 'removing every file clears the field');
reset_request(7);
$_POST = ['ffla_review_media_nonce' => 'nonce-ffla_review_media_admin_' . $rev, 'ffla_review_media_ids' => (string) $img];
$box->save($rev);
check(!metadata_exists('comment', $rev, 'ffla_review_media_ids'), 'customers cannot change it');
reset_request(9);
$_POST = ['ffla_review_media_nonce' => 'forged', 'ffla_review_media_ids' => (string) $img];
$box->save($rev);
check(!metadata_exists('comment', $rev, 'ffla_review_media_ids'), 'a forged nonce changes nothing');

// Deleting a review for good deletes the files uploaded with it, never a file
// picked from the Media Library (a product photo, another review's photo).
$own    = add_test_attachment('https://store.test/uploads/own.jpg');
$picked = add_test_attachment('https://store.test/uploads/product-main.jpg');
$doomed = add_test_comment(['comment_post_ID' => 101, 'comment_author' => 'Doomed', 'meta' => ['rating' => 3, 'ffla_review_media_ids' => [$own, $picked]]]);
Product_Reviews_Core::mark_review_upload($own, $doomed);
Product_Reviews_Core::mark_review_upload($picked, $rev);
wp_delete_comment($doomed, true);
check(!isset($GLOBALS['attachments'][$own]), 'the review\'s own upload is deleted with it');
check(isset($GLOBALS['attachments'][$picked]), 'a file uploaded for another review, only attached to this one, is kept');

echo "OK: {$checks} product-reviews form checks passed\n";
