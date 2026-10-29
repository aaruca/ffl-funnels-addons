<?php
/**
 * Offline check of coupons for reviews: who earns one (verified buyers, or
 * only reviews through the email link; roles), one per order whatever the
 * rating, held reviews earning theirs on approval, tiers by number of
 * photos, new unique coupons and their restrictions, existing codes, the
 * coupon email (HTML, plain, escaping, failures and retries), the test send
 * and the disclosure badge.
 *
 * Run: php tests/smoke/product-reviews-coupons-smoke.php
 */

require __DIR__ . '/product-reviews-stubs.php';

load_module([
    'includes/class-product-reviews-core.php',
    'includes/class-product-reviews-query.php',
    'includes/class-product-reviews-criteria.php',
    'includes/class-product-reviews-frontend-render.php',
    'includes/class-product-reviews-order-hub.php',
    'includes/class-product-reviews-assets.php',
    'includes/class-product-reviews-moderation.php',
    'includes/class-product-reviews-notifications.php',
    'includes/class-product-reviews-email-template.php',
    'includes/class-product-reviews-request-rules.php',
    'includes/class-product-reviews-consent.php',
    'includes/class-product-reviews-tracking.php',
    'includes/class-product-reviews-email.php',
    'includes/class-product-reviews-coupons.php',
    'integrations/class-product-reviews-turnstile.php',
]);
Product_Reviews_Core::init();
Product_Reviews_Notifications::init();
Product_Reviews_Coupons::init();

function settings(array $overrides = []): void
{
    $GLOBALS['options']['ffla_product_reviews_settings'] = array_merge(
        ['order_review_page_id' => '500', 'coupon_enabled' => '1', 'notify_on_approved' => '0'],
        $overrides
    );
}

/** A review through the order's signed email link, as the hub sends it. */
function hub_review(WC_Order $order, int $product_id, int $rating = 5): int
{
    reset_request();
    set_static('Product_Reviews_Core', 'form_checks_passed', false);
    set_static('Product_Reviews_Core', 'token_order', null);
    $_POST = [
        'action'                  => 'ffla_submit_product_review',
        'comment_post_ID'         => (string) $product_id,
        'rating'                  => (string) $rating,
        'comment'                 => 'Review of ' . $product_id,
        'ffla_order_review_token' => Product_Reviews_Core::build_order_review_token($order->id, $order->email),
    ];
    expect_redirect(['Product_Reviews_Core', 'handle_form_submission']);
    $last = end($GLOBALS['comments']);
    return (int) $last->comment_ID;
}

function coupon_by_code(string $code): ?WC_Coupon
{
    $id = wc_get_coupon_id_by_code($code);
    return $id ? $GLOBALS['coupons'][$id] : null;
}

function mails_to(string $to): array
{
    return array_values(array_filter($GLOBALS['mails'], function ($m) use ($to) {
        return $m['to'] === $to;
    }));
}

function last_mail_to(string $to): array
{
    $mails = mails_to($to);
    return $mails ? $mails[count($mails) - 1] : [];
}

/* ── Off by default ────────────────────────────────────────────────────── */

settings(['coupon_enabled' => '0']);
$o = make_order(6001, 'off@example.com', [[101, 1]]);
$r = hub_review($o, 101);
check('' === (string) get_comment_meta($r, 'ffla_review_coupon_code', true) && [] === $GLOBALS['coupons'], 'coupons off: nothing made');

/* ── A review through the email link earns a coupon ────────────────────── */

settings();
$o = make_order(6002, 'ann@example.com', [[101, 1], [102, 1]], ['first' => 'Ann']);
$r = hub_review($o, 101, 1);
$code = (string) get_comment_meta($r, 'ffla_review_coupon_code', true);
check(preg_match('/^REVIEW-[A-Z0-9]{8}$/', $code) === 1, 'a unique code with the prefix: ' . $code);
check($code === $o->get_meta('_ffla_review_coupon'), 'the order remembers its coupon');
check(1 === (int) get_comment_meta($r, 'ffla_incentivized', true), 'the review is marked as rewarded');
$c = coupon_by_code($code);
check($c && 'percent' === $c->props['discount_type'] && 10.0 === (float) $c->props['amount'], 'tier 1: 10% off');
check(['ann@example.com'] === $c->props['email_restrictions'] && 1 === $c->props['usage_limit'] && 1 === $c->props['usage_limit_per_user'], 'only for the reviewer, once');
check(true === $c->props['individual_use'], 'individual use by default');
check(abs($c->props['date_expires']->getTimestamp() - (time() + 30 * DAY_IN_SECONDS)) < 5, 'valid for 30 days');
check($r === (int) $c->meta['_ffla_review_coupon_review'], 'the coupon remembers its review');
$mail = mails_to('ann@example.com');
check(1 === count($mail) && strpos($mail[0]['body'], $code) !== false, 'the coupon email carries the code');
check('Thanks for your review: a coupon for you' === $mail[0]['subject'], 'default subject');
check(strpos($mail[0]['body'], '10% off your next order') !== false && strpos($mail[0]['body'], 'https://store.test/shop/') !== false, 'amount and a link to the shop');
check(strpos($mail[0]['body'], 'Unsubscribe') === false, 'a coupon email is not a request: no unsubscribe link');
check(get_comment_meta($r, '_ffla_review_coupon_emailed', true) > 0, 'email marked as sent');

$r2 = hub_review($o, 102);
check('order_rewarded' === get_comment_meta($r2, '_ffla_review_coupon_checked', true) && 1 === count(mails_to('ann@example.com')), 'one coupon per order');
check(1 === count($GLOBALS['coupons']), 'still one coupon');

/* ── Badge ─────────────────────────────────────────────────────────────── */

$badge = Product_Reviews_Coupons::card_badge('', get_comment($r));
check(strpos($badge, 'Received a coupon for this review') !== false, 'disclosure badge on the rewarded review');
check('' === Product_Reviews_Coupons::card_badge('', get_comment($r2)), 'no badge on reviews without a coupon');
settings(['coupon_badge_text' => 'Incentivized review']);
check(strpos(Product_Reviews_Coupons::card_badge('', get_comment($r)), 'Incentivized review') !== false, 'custom badge text');
settings(['coupon_badge' => '0']);
check('' === Product_Reviews_Coupons::card_badge('', get_comment($r)), 'badge can be switched off');
settings();
$html = capture(function () {
    Product_Reviews_Frontend_Render::render_reviews_list(101, ['perPage' => 10]);
});
check(substr_count($html, 'ffla-review-card__incentivized') === 1, 'the list shows the badge on the rewarded card only');

/* ── Who earns one ─────────────────────────────────────────────────────── */

// A guest typing a buyer's email on the product page: not verified.
reset_request();
set_static('Product_Reviews_Core', 'form_checks_passed', false);
set_static('Product_Reviews_Core', 'token_order', null);
$_POST = ['action' => 'ffla_submit_product_review', 'comment_post_ID' => '103', 'rating' => '5', 'comment' => 'Typed', 'author' => 'Faker', 'email' => 'buyer@example.com'];
expect_redirect(['Product_Reviews_Core', 'handle_form_submission']);
$guest = (int) end($GLOBALS['comments'])->comment_ID;
check('not_eligible' === get_comment_meta($guest, '_ffla_review_coupon_checked', true), 'an unverified guest review earns nothing');

// A signed-in buyer reviewing on the product page: the newest order with the product.
make_order(6003, 'buyer@example.com', [[103, 1]], ['user_id' => 7, 'created' => time() - 5 * DAY_IN_SECONDS]);
$newest = make_order(6004, 'buyer@example.com', [[103, 1]], ['user_id' => 7, 'created' => time() - DAY_IN_SECONDS]);
$account = add_test_comment(['comment_post_ID' => 103, 'comment_author' => 'Buyer Account', 'comment_author_email' => 'buyer@example.com', 'user_id' => 7, 'comment_approved' => '0', 'meta' => ['rating' => 4, 'ffla_verified_purchase' => 1]]);
wp_set_comment_status($account, 'approve');
check('' !== $newest->get_meta('_ffla_review_coupon'), 'an account buyer\'s review is tied to their newest order with the product');

settings(['coupon_who' => 'link']);
make_order(6005, 'other@example.com', [[102, 1]], ['user_id' => 8]);
$GLOBALS['buyers'][102]['users'][] = 8;
$linkonly = add_test_comment(['comment_post_ID' => 102, 'comment_author' => 'Other', 'comment_author_email' => 'other@example.com', 'user_id' => 8, 'comment_approved' => '0', 'meta' => ['rating' => 5, 'ffla_verified_purchase' => 1]]);
wp_set_comment_status($linkonly, 'approve');
check('not_eligible' === get_comment_meta($linkonly, '_ffla_review_coupon_checked', true), 'link-only mode: an account review on the product page earns nothing');
settings();

settings(['coupon_roles_mode' => 'exclude', 'coupon_roles' => ['customer']]);
make_order(6006, 'buyer@example.com', [[102, 1]], ['user_id' => 7]);
$excluded = add_test_comment(['comment_post_ID' => 102, 'comment_author' => 'Buyer Account', 'comment_author_email' => 'buyer@example.com', 'user_id' => 7, 'comment_approved' => '0', 'meta' => ['rating' => 5, 'ffla_verified_purchase' => 1]]);
wp_set_comment_status($excluded, 'approve');
check('not_eligible' === get_comment_meta($excluded, '_ffla_review_coupon_checked', true), 'an excluded role earns nothing');
settings(['coupon_roles_mode' => 'include', 'coupon_roles' => ['guest']]);
$g = make_order(6007, 'guesthub@example.com', [[101, 1]]);
$gr = hub_review($g, 101);
check('rewarded' === get_comment_meta($gr, '_ffla_review_coupon_checked', true), 'guests through the email link can be the only ones rewarded');
settings();

/* ── Held reviews, tiers by photos ─────────────────────────────────────── */

settings(['coupon_tier2_type' => 'new', 'coupon_tier2_min_media' => '1', 'coupon_tier2_amount' => '15', 'coupon_tier3_type' => 'new', 'coupon_tier3_min_media' => '3', 'coupon_tier3_discount_type' => 'fixed_cart', 'coupon_tier3_amount' => '25']);
$m1 = make_order(6010, 'photo1@example.com', [[101, 1]]);
$held = add_test_comment(['comment_post_ID' => 101, 'comment_author' => 'Photo One', 'comment_author_email' => 'photo1@example.com', 'comment_approved' => '0', 'meta' => ['rating' => 3, 'ffla_review_order_id' => 6010, 'ffla_review_media_ids' => [901]]]);
do_action('comment_post', $held, 0, []);
check('' === (string) get_comment_meta($held, 'ffla_review_coupon_code', true), 'a held review earns nothing yet');
wp_set_comment_status($held, 'approve');
$c = coupon_by_code((string) get_comment_meta($held, 'ffla_review_coupon_code', true));
check($c && 15.0 === (float) $c->props['amount'], 'approved with one photo: tier 2 (15%)');

$m3 = make_order(6011, 'photo3@example.com', [[101, 1]]);
$three = add_test_comment(['comment_post_ID' => 101, 'comment_author' => 'Photo Three', 'comment_author_email' => 'photo3@example.com', 'comment_approved' => '0', 'meta' => ['rating' => 5, 'ffla_review_order_id' => 6011, 'ffla_review_media_ids' => [901, 902, 903]]]);
wp_set_comment_status($three, 'approve');
$c = coupon_by_code((string) get_comment_meta($three, 'ffla_review_coupon_code', true));
check($c && 'fixed_cart' === $c->props['discount_type'] && 25.0 === (float) $c->props['amount'], 'three photos: tier 3 ($25 off)');
$mail = last_mail_to('photo3@example.com');
check(strpos($mail['body'], '$25.00 off your next order') !== false, 'fixed amounts shown in the store currency');

wp_set_comment_status($three, 'hold');
wp_set_comment_status($three, 'approve');
check(1 === count(mails_to('photo3@example.com')), 'approving again sends nothing more');

settings(['coupon_tier1_type' => 'off']);
$nophoto = make_order(6012, 'nophoto@example.com', [[101, 1]]);
$np = hub_review($nophoto, 101);
check('no_tier' === get_comment_meta($np, '_ffla_review_coupon_checked', true), 'tier 1 off: reviews without photos earn nothing');
settings();

/* ── Existing codes, coupon options ────────────────────────────────────── */

$existing = new WC_Coupon();
$existing->set_code('THANKS10');
$existing->set_discount_type('percent');
$existing->set_amount(10);
$existing->save();
settings(['coupon_tier1_type' => 'existing', 'coupon_tier1_existing' => 'thanks10']);
$e = make_order(6020, 'existing@example.com', [[101, 1]]);
$er = hub_review($e, 101);
check('thanks10' === get_comment_meta($er, 'ffla_review_coupon_code', true) && strpos(last_mail_to('existing@example.com')['body'], 'thanks10') !== false, 'an existing code is sent as entered');
$count = count($GLOBALS['coupons']);
settings(['coupon_tier1_type' => 'existing', 'coupon_tier1_existing' => 'GONE']);
$gone = make_order(6021, 'gone@example.com', [[101, 1]]);
$gr = hub_review($gone, 101);
check('coupon_failed' === get_comment_meta($gr, '_ffla_review_coupon_checked', true) && count($GLOBALS['coupons']) === $count, 'a missing existing coupon: nothing sent, logged');
check('' === $gone->get_meta('_ffla_review_coupon'), 'and the order can still earn one later');

settings(['coupon_prefix' => 'THX-', 'coupon_expiry_days' => '0', 'coupon_shareable' => '1', 'coupon_usage_limit' => '3', 'coupon_minimum_spend' => '50', 'coupon_free_shipping' => '1', 'coupon_individual_use' => '0', 'coupon_excluded_categories' => ['22'], 'coupon_tier1_amount' => '150']);
$opt = make_order(6022, 'options@example.com', [[101, 1]]);
$or = hub_review($opt, 101);
$c = coupon_by_code((string) get_comment_meta($or, 'ffla_review_coupon_code', true));
check(0 === strpos($c->props['code'], 'THX-'), 'custom prefix');
check(null === $c->props['date_expires'] && [] === $c->props['email_restrictions'] && 3 === $c->props['usage_limit'], 'no expiry, shareable, 3 uses');
check(50.0 === (float) $c->props['minimum_amount'] && true === $c->props['free_shipping'] && false === $c->props['individual_use'], 'minimum spend, free shipping, combinable');
check([22] === $c->props['excluded_product_categories'], 'excluded categories');
check(100.0 === (float) $c->props['amount'], 'a percentage is capped at 100');
settings();

/* ── Email: failures, plain text, escaping, test ───────────────────────── */

$GLOBALS['mail_ok'] = false;
$f = make_order(6030, 'fail@example.com', [[101, 1]]);
$fr = hub_review($f, 101);
check('' === (string) get_comment_meta($fr, '_ffla_review_coupon_emailed', true) && 1 === (int) get_comment_meta($fr, '_ffla_review_coupon_attempts', true), 'a failed coupon email is not marked as sent');
check(1 === count(actions_for('ffla_send_review_coupon_email', [$fr])), 'and is retried in an hour');
check('' !== $f->get_meta('_ffla_review_coupon'), 'the coupon itself is kept for the retry');
$GLOBALS['mail_ok'] = true;
$tries = count(mails_to('fail@example.com')); // the stub records the failed try too
run_action('ffla_send_review_coupon_email', [$fr]);
check(get_comment_meta($fr, '_ffla_review_coupon_emailed', true) > 0 && $tries + 1 === count(mails_to('fail@example.com')), 'the retry sends it');
Product_Reviews_Coupons::send_email($fr);
check($tries + 1 === count(mails_to('fail@example.com')), 'never twice');

settings(['email_format' => 'plain']);
$p = make_order(6031, 'plain@example.com', [[101, 1]], ['first' => 'Pat']);
$pr = hub_review($p, 101);
$mail = last_mail_to('plain@example.com');
check(strpos($mail['body'], 'Your code: REVIEW-') !== false && strpos($mail['body'], '<') === false, 'plain text coupon email with the code');
check(in_array('Content-Type: text/plain; charset=UTF-8', $mail['headers'], true), 'sent as plain text');
settings();

$x = make_order(6032, 'xss@example.com', [[101, 1]], ['first' => '<img src=x onerror=1>', 'last' => 'Smith']);
$xr = hub_review($x, 101);
$mail = last_mail_to('xss@example.com');
check(strpos($mail['body'], '<img src=x') === false && strpos($mail['body'], 'Hi &lt;img,') !== false, 'the reviewer name (first word) is escaped in the email');

settings(['coupon_email_subject' => '{store_name}: {coupon_amount} for {customer_name}']);
check(Product_Reviews_Coupons::send_test('me@store.test'), 'test send works');
$mail = last_mail_to('me@store.test');
check('[Test] Gun & Gear: 10% for Customer' === $mail['subject'] && strpos($mail['body'], 'REVIEW-TEST1234') !== false, 'test email with a made-up code and placeholders in the subject');
check(count($GLOBALS['coupons']) === count(array_unique(array_map(function ($c) { return $c->props['code']; }, $GLOBALS['coupons']))), 'every coupon code is unique');
settings();

/* ── Reviews written before coupons were switched on ──────────────────── */

// A held review from before, approved after coupons are switched on, earns none.
$backlog_order = make_order(6040, 'backlog@example.com', [[101, 1]]);
$backlog = add_test_comment(['comment_post_ID' => 101, 'comment_type' => 'review', 'comment_author' => 'Backlog', 'comment_author_email' => 'backlog@example.com', 'comment_approved' => '0', 'comment_date_gmt' => gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS), 'meta' => ['rating' => 5, 'ffla_verified_purchase' => 1, 'ffla_review_order_id' => 6040]]);
$off = Product_Reviews_Core::get_settings();
$off['coupon_enabled'] = '0';
$on = $off;
$on['coupon_enabled'] = '1';
do_action('ffla_product_reviews_settings_saved', 'coupons', $on, $off);
check((int) get_option(Product_Reviews_Coupons::SINCE_OPTION) >= time() - 5, 'switching coupons on remembers when');
wp_set_comment_status($backlog, 'approve');
check('before_enabled' === get_comment_meta($backlog, '_ffla_review_coupon_checked', true) && '' === (string) $backlog_order->get_meta('_ffla_review_coupon'), 'a review written before that earns no coupon when approved later');
$since = time() - 4 * DAY_IN_SECONDS;
update_option(Product_Reviews_Coupons::SINCE_OPTION, $since);
do_action('ffla_product_reviews_settings_saved', 'coupons', $on, $on);
check($since === (int) get_option(Product_Reviews_Coupons::SINCE_OPTION), 'saving the tab again (coupons already on) does not move the date');
update_option(Product_Reviews_Coupons::SINCE_OPTION, time() - 60);
$fresh_order = make_order(6041, 'fresh@example.com', [[101, 1]]);
$fresh = hub_review($fresh_order, 101);
check('' !== (string) get_comment_meta($fresh, 'ffla_review_coupon_code', true), 'a review written after it earns one as usual');
delete_option(Product_Reviews_Coupons::SINCE_OPTION);

// The request emails' own Reply-To and BCC are not used for coupon emails.
settings(['email_bcc' => 'copies@store.test', 'email_reply_to' => 'reviews@store.test']);
$hdr_order = make_order(6042, 'headers@example.com', [[101, 1]]);
hub_review($hdr_order, 101);
$coupon_mail = last_mail_to('headers@example.com');
check($coupon_mail && false === stripos(implode("\n", (array) $coupon_mail['headers']), 'copies@store.test') && false === stripos(implode("\n", (array) $coupon_mail['headers']), 'reviews@store.test'), 'coupon emails carry the store\'s headers, not the request BCC and Reply-To');
settings();

echo "OK: {$checks} product-reviews coupon checks passed\n";
