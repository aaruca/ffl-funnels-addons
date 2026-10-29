<?php
/**
 * Offline check of the Product Reviews request emails: when they go out
 * (trigger status, delay in days or hours, per-country delay, sending hour
 * and weekdays, link validity, the second reminder), who gets them (guests,
 * roles, excluded addresses, one per customer, consent at checkout, the
 * account address) and for which products (categories, free products, most
 * per email), the HTML email (escaping, clickable stars, button, footer,
 * unsubscribe, open pixel, headers), tracking, requests sent by hand, the
 * order box, the bulk action, the test email and the request log.
 *
 * Run: php tests/smoke/product-reviews-requests-smoke.php
 */

require __DIR__ . '/product-reviews-stubs.php';

$GLOBALS['is_admin'] = true;

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
    'integrations/class-product-reviews-turnstile.php',
    'admin/class-product-reviews-order-tools.php',
    'admin/class-product-reviews-requests-log.php',
]);
Product_Reviews_Core::init();
Product_Reviews_Notifications::init();
Product_Reviews_Email::init();

function settings(array $overrides = []): void
{
    $GLOBALS['options']['ffla_product_reviews_settings'] = array_merge(['order_review_page_id' => '500', 'email_tracking' => '1'], $overrides);
}

function status_change(WC_Order $order, string $to): void
{
    $from          = $order->status;
    $order->status = $to;
    if ('completed' === $to) {
        $order->completed = time();
    }
    do_action('woocommerce_order_status_changed', $order->id, $from, $to, $order);
}

function bundle_queued(int $order_id): array
{
    return actions_for('ffla_send_order_review_bundle', [$order_id]);
}

function last_mail(): array
{
    return end($GLOBALS['mails']) ?: [];
}

function header_value(array $mail, string $name): string
{
    foreach ((array) $mail['headers'] as $h) {
        if (stripos($h, $name . ':') === 0) {
            return trim(substr($h, strlen($name) + 1));
        }
    }
    return '';
}

settings();

/* ── Trigger status ────────────────────────────────────────────────────── */

$o = make_order(5001, 'ann@example.com', [[101, 1]], ['status' => 'processing', 'completed' => null]);
status_change($o, 'completed');
check(1 === count(bundle_queued(5001)), 'default trigger: Completed schedules the email');

settings(['request_trigger_status' => 'delivered']);
$o = make_order(5002, 'bob@example.com', [[101, 1]], ['status' => 'processing', 'completed' => null]);
status_change($o, 'completed');
check([] === bundle_queued(5002), 'trigger Delivered: Completed does not schedule');
status_change($o, 'delivered');
check(1 === count(bundle_queued(5002)) && $o->get_meta('_ffla_review_trigger_reached') > 0, 'trigger Delivered: reaching it schedules and is remembered');
status_change($o, 'completed');
run_action('ffla_send_order_review_bundle', [5002]);
check('bob@example.com' === (last_mail()['to'] ?? ''), 'a status reached after the trigger still gets the email');

$o = make_order(5003, 'cy@example.com', [[101, 1]], ['status' => 'processing', 'completed' => null]);
status_change($o, 'delivered');
$o->status = 'on-hold';
$before = count($GLOBALS['mails']);
run_action('ffla_send_order_review_bundle', [5003]);
check(count($GLOBALS['mails']) === $before && '' === $o->get_meta('_ffla_review_request_scheduled'), 'back to On hold before the email: not sent, released');

settings(['request_trigger_status' => 'processing']);
$o = make_order(5004, 'dee@example.com', [[101, 1]], ['status' => 'pending', 'completed' => null]);
status_change($o, 'processing');
run_action('ffla_send_order_review_bundle', [5004]);
check('dee@example.com' === (last_mail()['to'] ?? ''), 'trigger Processing: a Processing order is emailed even though Processing comes before completion');
settings();

/* ── Delay, country, hour, weekdays, link validity ─────────────────────── */

settings(['request_delay_days' => '5', 'request_delay_unit' => 'hours']);
$o = make_order(5010, 'eve@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5010);
$ts = bundle_queued(5010)[0]['ts'];
check(abs($ts - (time() + 5 * HOUR_IN_SECONDS)) < 5, 'delay in hours');
check($ts === (int) $o->get_meta('_ffla_review_scheduled_for'), 'scheduled time stored on the order');

settings(['request_delay_days' => '7', 'request_country_delays' => "CA|14\nmx: 21\nbad line"]);
check(['CA' => 14, 'MX' => 21] === Product_Reviews_Email::country_delays(), 'country delays parsed, bad lines ignored');
$ca = make_order(5011, 'fay@example.com', [[101, 1]], ['country' => 'CA']);
Product_Reviews_Email::schedule_requests_for_order(5011);
check(abs(bundle_queued(5011)[0]['ts'] - (time() + 14 * DAY_IN_SECONDS)) < 5, 'Canada waits 14 days');
$us = make_order(5012, 'gus@example.com', [[101, 1]], ['country' => 'US']);
Product_Reviews_Email::schedule_requests_for_order(5012);
check(abs(bundle_queued(5012)[0]['ts'] - (time() + 7 * DAY_IN_SECONDS)) < 5, 'a country not listed uses the normal delay');

settings(['request_send_hour' => '10']);
$at = Product_Reviews_Email::align_to_window(strtotime('2026-11-03 11:30:00 UTC'));
check('2026-11-04 10:00' === gmdate('Y-m-d H:i', $at), 'past 10:00: held until 10:00 the next day');
$at = Product_Reviews_Email::align_to_window(strtotime('2026-11-03 08:15:00 UTC'));
check('2026-11-03 10:00' === gmdate('Y-m-d H:i', $at), 'before 10:00: sent at 10:00 the same day');
settings(['request_send_days' => ['1', '2', '3', '4', '5']]);
$at = Product_Reviews_Email::align_to_window(strtotime('2026-11-07 15:00:00 UTC')); // Saturday
check('2026-11-09 15:00' === gmdate('Y-m-d H:i', $at), 'weekdays only: a Saturday email waits for Monday, same time');
settings(['request_send_days' => ['1'], 'request_send_hour' => '9']);
$at = Product_Reviews_Email::align_to_window(strtotime('2026-11-03 08:00:00 UTC')); // Tuesday
check('2026-11-09 09:00' === gmdate('Y-m-d H:i', $at), 'Mondays at 09:00');
settings(['request_send_days' => ['1', '2', '3', '4', '5', '6', '7']]);
check(1234567 === Product_Reviews_Email::align_to_window(1234567), 'every day ticked and any time: unchanged');

settings(['review_link_days' => '30']);
$token = Product_Reviews_Core::build_order_review_token(5001, 'ann@example.com');
$data  = Product_Reviews_Core::parse_order_review_token_payload($token);
check(abs($data['expires'] - (time() + 30 * DAY_IN_SECONDS)) < 5, 'link valid for the configured days');
settings(['review_link_days' => '9999']);
check(365 === Product_Reviews_Core::review_link_days(), 'link validity is capped at a year');
settings();

/* ── Who gets requests ─────────────────────────────────────────────────── */

settings(['request_guests' => '0']);
$g = make_order(5020, 'guest@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5020);
check([] === bundle_queued(5020) && 'guest' === $g->get_meta('_ffla_review_skipped'), 'guests off: guest order skipped, reason stored');
$m = make_order(5021, 'buyer@example.com', [[101, 1]], ['user_id' => 7]);
Product_Reviews_Email::schedule_requests_for_order(5021);
check(1 === count(bundle_queued(5021)), 'guests off: account orders still scheduled');

settings(['request_roles_mode' => 'include', 'request_roles' => ['customer']]);
$sub = make_order(5022, 'other@example.com', [[101, 1]], ['user_id' => 8]);
Product_Reviews_Email::schedule_requests_for_order(5022);
check('role' === $sub->get_meta('_ffla_review_skipped'), 'only customers: a subscriber is skipped');
settings(['request_roles_mode' => 'exclude', 'request_roles' => ['customer']]);
$cus = make_order(5023, 'buyer@example.com', [[102, 1]], ['user_id' => 7]);
Product_Reviews_Email::schedule_requests_for_order(5023);
check('role' === $cus->get_meta('_ffla_review_skipped'), 'everyone but customers: a customer is skipped');

settings(['request_excluded_emails' => "staff@store.test\n@relay.marketplace.test"]);
foreach (['staff@store.test' => true, 'buyer@relay.marketplace.test' => true, 'buyer@example.com' => false, 'x@notrelay.marketplace.test.evil' => false] as $email => $blocked) {
    check($blocked === Product_Reviews_Request_Rules::address_excluded($email), "excluded address check: $email");
}
$x = make_order(5024, 'buyer@relay.marketplace.test', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5024);
check('excluded' === $x->get_meta('_ffla_review_skipped'), 'an excluded domain is skipped');

settings(['request_customer_window_days' => '30']);
$first = make_order(5025, 'repeat@example.com', [[101, 1]]);
$first->meta['_ffla_review_last_sent'] = time() - 5 * DAY_IN_SECONDS;
$again = make_order(5026, 'repeat@example.com', [[102, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5026);
check('recent_request' === $again->get_meta('_ffla_review_skipped'), 'one request per customer per 30 days');
$first->meta['_ffla_review_last_sent'] = time() - 40 * DAY_IN_SECONDS;
Product_Reviews_Email::schedule_requests_for_order(5026);
check(1 === count(bundle_queued(5026)) && '' === $again->get_meta('_ffla_review_skipped'), 'after the window the next order gets one (and the skip reason is cleared)');
settings();

/* ── Consent at checkout ───────────────────────────────────────────────── */

settings(['request_consent' => 'optin']);
$no = make_order(5030, 'noconsent@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5030);
check('consent' === $no->get_meta('_ffla_review_skipped'), 'opt-in: no tick, no request');

reset_request();
$_POST = ['ffla_review_consent' => '1'];
$yes = make_order(5031, 'consent@example.com', [[101, 1]]);
Product_Reviews_Consent::save_classic_field($yes);
check('yes' === $yes->get_meta('_ffla_review_consent'), 'classic checkout saves the tick');
Product_Reviews_Email::schedule_requests_for_order(5031);
check(1 === count(bundle_queued(5031)), 'opt-in ticked: scheduled');
$_POST = [];
$unticked = make_order(5032, 'unticked@example.com', [[101, 1]]);
Product_Reviews_Consent::save_classic_field($unticked);
check('no' === $unticked->get_meta('_ffla_review_consent'), 'classic checkout saves an unticked box as no');
$block = make_order(5033, 'block@example.com', [[101, 1]], ['meta' => ['_wc_other/ffla/review-consent' => '1']]);
check(Product_Reviews_Consent::order_consented($block), 'Checkout block field counts as consent');
$html = capture(['Product_Reviews_Consent', 'render_classic_field']);
check(strpos($html, 'name="ffla_review_consent"') !== false && strpos($html, 'checked') === false, 'the checkbox starts unticked');
settings();

/* ── Which products ────────────────────────────────────────────────────── */

$mixed = make_order(5040, 'mixed@example.com', [[101, 1], [102, 1], [103, 1], [104, 1]]);
$mixed->items[50400]->total = 249.99;
$mixed->items[50401]->total = 59.0;
$mixed->items[50402]->total = 19.5;
$mixed->items[50403]->total = 0.0;

settings(['request_categories_mode' => 'include', 'request_categories' => ['20']]);
check([101] === Product_Reviews_Email::pending_product_ids($mixed, 'mixed@example.com'), 'only Optics');
$GLOBALS['terms'][23] = ['product_cat', 'Red dots', 'red-dots', 20];
$GLOBALS['product_props'][103]['cats'] = [23];
check([101, 103] === Product_Reviews_Email::pending_product_ids($mixed, 'mixed@example.com'), 'a subcategory of an included category counts');
$GLOBALS['product_props'][103]['cats'] = [21];
settings(['request_categories_mode' => 'exclude', 'request_categories' => ['20']]);
check([102, 103, 104] === Product_Reviews_Email::pending_product_ids($mixed, 'mixed@example.com'), 'everything except Optics');
settings(['request_skip_free' => '1']);
check([101, 102, 103] === Product_Reviews_Email::pending_product_ids($mixed, 'mixed@example.com'), 'free lines left out');
settings(['request_max_products' => '2']);
check([101, 102] === Product_Reviews_Email::pending_product_ids($mixed, 'mixed@example.com'), 'at most 2, most expensive first');
settings();

/* ── HTML email ────────────────────────────────────────────────────────── */

$GLOBALS['options']['woocommerce_email_header_image'] = 'https://store.test/logo.png';
$GLOBALS['options']['woocommerce_email_base_color']   = '#7f54b3';
$GLOBALS['options']['woocommerce_store_address']      = '1 Range Rd';
$GLOBALS['options']['woocommerce_store_city']         = 'Austin';
$GLOBALS['posts'][107] = ['product', 'publish', 'Grip <script>alert(1)</script>'];
settings(['email_bcc' => 'copy@store.test', 'email_reply_to' => 'help@store.test']);
$h = make_order(5050, 'html@example.com', [[101, 1], [107, 1]], ['first' => 'Ann <b>']);
Product_Reviews_Email::schedule_requests_for_order(5050);
run_action('ffla_send_order_review_bundle', [5050]);
$mail = last_mail();
$body = $mail['body'];
check('html@example.com' === $mail['to'] && strpos($body, '<!DOCTYPE html>') === 0, 'HTML by default');
check('text/html; charset=UTF-8' === header_value($mail, 'Content-Type'), 'sent as HTML');
check('copy@store.test' === header_value($mail, 'Bcc') && 'help@store.test' === header_value($mail, 'Reply-To'), 'BCC copy and the module\'s Reply-To');
check(1 === substr_count(implode("\n", $mail['headers']), 'Reply-To:'), 'only one Reply-To header');
check(strpos($body, 'src="https://store.test/logo.png"') !== false, 'logo falls back to WooCommerce\'s header image');
check(strpos($body, 'background-color:#7f54b3') !== false, 'button colour falls back to WooCommerce\'s base colour');
check(strpos($body, '1 Range Rd, Austin') !== false, 'footer carries the store address');
check(strpos($body, '<script>') === false && strpos($body, 'Grip &lt;script&gt;') !== false, 'product names are escaped');
check(strpos($body, 'Ann &lt;b&gt;') !== false, 'customer name is escaped in the text');
check(preg_match('#href="([^"]*ffla_rate%5B101%5D=5[^"]*)"#', $body, $m) === 1 && strpos($m[1], '#ffla-order-review-101') !== false, 'five-star link for product 101 opens its form with 5 picked');
check(10 === substr_count($body, 'ffla_rate%5B'), 'five stars per product');
check(preg_match('#Unsubscribe from review requests#', $body) === 1 && strpos($body, 'action=ffla_review_unsubscribe') !== false, 'unsubscribe link in the footer');
check(preg_match('#src="([^"]*action=ffla_review_open[^"]*)"#', $body, $pm) === 1, 'open pixel in the email');
check('How was your purchase?' === $mail['subject'], 'default subject');

settings(['email_tracking' => '0']);
$t = make_order(5051, 'notrack@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5051);
run_action('ffla_send_order_review_bundle', [5051]);
check(strpos(last_mail()['body'], 'ffla_review_open') === false, 'tracking off: no pixel');

settings(['email_show_products' => '0', 'email_template' => '<p>Hello {customer_name}, rate us at <a href="{review_url}">this link</a>.</p>', 'email_subject' => 'Order {order_number} – {store_name}']);
$c = make_order(5052, 'custom@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5052);
run_action('ffla_send_order_review_bundle', [5052]);
$mail = last_mail();
check(strpos($mail['body'], 'ffla_rate%5B') === false, 'product rows can be switched off');
check(preg_match('#<a href="https://store\\.test/\\?p=500&(amp;|\\#038;)ffla_ro=[^"]+">this link</a>#', $mail['body']) === 1, 'the store\'s own link gets the signed URL');
check('Order 5052 – Gun & Gear' === $mail['subject'], 'subject placeholders');
settings();

/* ── Tracking ──────────────────────────────────────────────────────────── */

parse_str((string) parse_url(html_entity_decode($pm[1]), PHP_URL_QUERY), $pq);
check(Product_Reviews_Tracking::record_open((int) $pq['o'], (string) $pq['s']), 'a signed open is recorded');
check($h->get_meta('_ffla_review_opened') > 0 && 1 === $h->get_meta('_ffla_review_open_count'), 'open time and count on the order');
check(!Product_Reviews_Tracking::record_open(5050, 'forged'), 'a forged open is ignored');
check(!Product_Reviews_Tracking::record_open(5051, (string) $pq['s']), 'a signature for another order is ignored');

$hub_token = Product_Reviews_Core::build_order_review_token(5050, 'html@example.com');
reset_request();
$_GET = ['ffla_ro' => $hub_token, 'ffla_rate' => ['101' => '4', '107' => '9']];
$hub = Product_Reviews_Order_Hub::render_hub(false);
check($h->get_meta('_ffla_review_clicked') > 0 && 1 === $h->get_meta('_ffla_review_click_count'), 'opening the review page is recorded');
Product_Reviews_Order_Hub::render_hub(false);
check(1 === $h->get_meta('_ffla_review_click_count'), 'one click per page load, however many hubs');
preg_match('#<section class="ffla-order-reviews__product" id="ffla-order-review-101">(.*?)</section>#s', $hub, $sec);
check(preg_match('#value="4" required checked|value="4" checked#', $sec[1]) === 1, 'the star clicked in the email is picked on that product\'s form');
preg_match('#id="ffla-order-review-107">(.*?)</section>#s', $hub, $sec);
check(strpos($sec[1], 'checked') === false, 'an out-of-range rating picks nothing');

/* ── Second reminder ───────────────────────────────────────────────────── */

settings(['request_reminder' => '1', 'request_reminder_days' => '3']);
$r = make_order(5060, 'remind@example.com', [[101, 1], [102, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5060);
run_action('ffla_send_order_review_bundle', [5060]);
$queued = actions_for('ffla_send_order_review_reminder', [5060]);
check(1 === count($queued) && abs($queued[0]['ts'] - (time() + 3 * DAY_IN_SECONDS)) < 5, 'reminder queued 3 days after the first email');
$rv = add_test_comment(['comment_post_ID' => 101, 'comment_author_email' => 'remind@example.com', 'meta' => ['ffla_verified_purchase' => 1]]);
run_action('ffla_send_order_review_reminder', [5060]);
$mail = last_mail();
check('A quick reminder: how was your order?' === $mail['subject'], 'reminder subject');
check(strpos($mail['body'], 'Range Bag') !== false && strpos($mail['body'], 'Hunting Rifle Scope') === false, 'reminder lists only what is still not reviewed');
check($r->get_meta('_ffla_review_reminder_sent') > 0, 'reminder marked as sent');
$count = count($GLOBALS['mails']);
Product_Reviews_Email::send_reminder(5060);
check(count($GLOBALS['mails']) === $count, 'only one reminder');

$all = make_order(5061, 'done@example.com', [[103, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5061);
run_action('ffla_send_order_review_bundle', [5061]);
add_test_comment(['comment_post_ID' => 103, 'comment_author_email' => 'done@example.com', 'meta' => ['ffla_verified_purchase' => 1]]);
$count = count($GLOBALS['mails']);
run_action('ffla_send_order_review_reminder', [5061]);
check(count($GLOBALS['mails']) === $count, 'everything reviewed: no reminder');
settings(['request_reminder' => '0']);
$off = make_order(5062, 'nomore@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5062);
run_action('ffla_send_order_review_bundle', [5062]);
check([] === actions_for('ffla_send_order_review_reminder', [5062]), 'reminders off: none queued');
settings();

/* ── Account email ─────────────────────────────────────────────────────── */

settings(['request_use_account_email' => '1']);
$acct = make_order(5070, 'billing-only@example.com', [[103, 1]], ['user_id' => 8]);
Product_Reviews_Email::schedule_requests_for_order(5070);
run_action('ffla_send_order_review_bundle', [5070]);
$mail = last_mail();
check('other@example.com' === $mail['to'], 'sent to the account email');
preg_match('#ffla_ro=([A-Za-z0-9._~-]+)#', html_entity_decode($mail['body']), $tm);
check(Product_Reviews_Core::get_order_for_review_token(rawurldecode($tm[1])) instanceof WC_Order, 'the link still opens the order (signed for its billing email)');
check(strpos(html_entity_decode($mail['body']), 'ffla_review_unsubscribe') !== false, 'unsubscribe link for the address that got it');
settings();

/* ── Requests sent by hand ─────────────────────────────────────────────── */

settings(['enable_requests' => '0']);
$man = make_order(5080, 'manual@example.com', [[101, 1], [102, 1]], ['status' => 'processing', 'completed' => null]);
$res = Product_Reviews_Email::send_now(5080);
check($res['sent'] && 'manual@example.com' === last_mail()['to'], 'sent by hand while automatic requests are off, whatever the status');
check($man->get_meta('_ffla_review_manual_sent') > 0 && $man->get_meta('_ffla_review_bundle_sent') > 0, 'marked as sent by hand');
check(!empty($man->notes) && strpos(end($man->notes), 'manual@example.com') !== false, 'order note says who got it');
settings();

$sch = make_order(5081, 'sched@example.com', [[101, 1]]);
Product_Reviews_Email::schedule_requests_for_order(5081);
check(1 === count(bundle_queued(5081)), 'precondition: automatic email queued');
Product_Reviews_Email::send_now(5081);
check([] === bundle_queued(5081), 'sending by hand drops the queued automatic email');
Product_Reviews_Email::schedule_requests_for_order(5081);
check([] === bundle_queued(5081), 'and it is not scheduled again later');

Product_Reviews_Notifications::opt_out('gone@example.com');
make_order(5082, 'gone@example.com', [[101, 1]]);
$res = Product_Reviews_Email::send_now(5082);
check(!$res['sent'] && 'The customer unsubscribed from review requests.' === $res['message'], 'not by hand to an unsubscribed address');
settings(['request_consent' => 'optin']);
make_order(5083, 'nope@example.com', [[101, 1]]);
$res = Product_Reviews_Email::send_now(5083);
check(!$res['sent'] && 'No consent at checkout' === $res['message'], 'not by hand without consent');
settings(['order_review_page_id' => '0']);
$res = Product_Reviews_Email::send_now(5080);
check(!$res['sent'] && strpos($res['message'], 'Hub page') !== false, 'not by hand without a hub page');
settings();
make_order(5084, 'refunded@example.com', [[101, 1]], ['status' => 'refunded']);
$res = Product_Reviews_Email::send_now(5084);
check(!$res['sent'] && strpos($res['message'], 'Refunded') === 0, 'not by hand for a refunded order');

$tools = new Product_Reviews_Order_Tools();
$tools->init();
make_order(5085, 'bulk1@example.com', [[101, 1]]);
make_order(5086, 'bulk2@example.com', [[102, 1]]);
reset_request(1);
$back = $tools->handle_bulk_action('https://store.test/wp-admin/admin.php?page=wc-orders', 'ffla_send_review_request', ['5085', '5086']);
check('https://store.test/wp-admin/admin.php?page=wc-orders' === $back, 'bulk action returns to the list');
check(1 === count(actions_for('ffla_send_manual_review_request', [5085])) && 1 === count(actions_for('ffla_send_manual_review_request', [5086])), 'bulk action queues one request per order');
run_action('ffla_send_manual_review_request', [5085]);
check('bulk1@example.com' === last_mail()['to'], 'queued request goes out');
check('dummy' === $tools->handle_bulk_action('dummy', 'mark_completed', [1]), 'other bulk actions are left alone');
check(isset($tools->add_bulk_action([])['ffla_send_review_request']), 'bulk action offered while the hub page is ready');

/* ── Order box ─────────────────────────────────────────────────────────── */

$box = make_order(5090, 'box@example.com', [[101, 1]], ['phone' => '(512) 555-0142', 'country' => 'US']);
Product_Reviews_Email::schedule_requests_for_order(5090);
$html = Product_Reviews_Order_Tools::meta_box_html($box);
check(strpos($html, 'Scheduled') !== false && strpos($html, 'Cancel the scheduled email') !== false, 'box shows the schedule and a cancel link');
check(preg_match('#data-ffla-review-link="([^"]+)"#', $html, $lm) === 1, 'box carries the review link for Copy and QR');
preg_match('#ffla_ro=([^&"]+)#', html_entity_decode($lm[1]), $tm);
check(Product_Reviews_Core::get_order_for_review_token(rawurldecode($tm[1])) instanceof WC_Order, 'the copied link opens this order\'s review page');
check(strpos($html, 'https://wa.me/15125550142?text=') !== false, 'WhatsApp link with the country calling code');
check('447700900123' === Product_Reviews_Order_Tools::whatsapp_phone(make_order(5091, 'uk@example.com', [], ['phone' => '+44 7700 900123', 'country' => 'GB'])), 'an international number is kept');
check('5215512345678' === Product_Reviews_Order_Tools::whatsapp_phone(make_order(5092, 'mx@example.com', [], ['phone' => '0052 1 55 1234 5678', 'country' => 'MX'])), '00 prefix is read as international');
check('' === Product_Reviews_Order_Tools::whatsapp_phone(make_order(5093, 'none@example.com', [], ['phone' => '', 'country' => 'US'])), 'no phone, no WhatsApp button');
$wa = Product_Reviews_Order_Tools::whatsapp_url($box, 'https://store.test/r/x');
check(strpos(rawurldecode($wa), 'Hi Ann, thank you for your order from Gun & Gear! Would you take a minute to review it? https://store.test/r/x') !== false, 'WhatsApp message filled in');

reset_request(1);
$_GET = ['order_id' => '5090', '_wpnonce' => 'nonce-ffla_review_cancel_5090'];
$_REQUEST = $_GET;
expect_redirect([$tools, 'handle_cancel']);
check([] === bundle_queued(5090) && 'cancelled' === $box->get_meta('_ffla_review_skipped'), 'cancel drops the queued email');
Product_Reviews_Email::schedule_requests_for_order(5090);
check([] === bundle_queued(5090), 'a cancelled request is not scheduled again');

reset_request(7);
$_GET = ['order_id' => '5090', '_wpnonce' => 'nonce-ffla_review_send_now_5090'];
$_REQUEST = $_GET;
try {
    $tools->handle_send_now();
    check(false, 'a customer cannot send from the order screen');
} catch (Died $d) {
    check(403 === $d->status, 'a customer cannot send from the order screen');
}
reset_request(9);
$_GET = ['order_id' => '5090', '_wpnonce' => 'nonce-ffla_review_send_now_5090'];
$_REQUEST = $_GET;
expect_redirect([$tools, 'handle_send_now']);
check('box@example.com' === last_mail()['to'], 'a shop manager can send from the order screen');
$notice = capture([$tools, 'render_notice']);
check(strpos($notice, 'Review request sent to box@example.com.') !== false, 'result shown as a notice after the redirect');
check('' === capture([$tools, 'render_notice']), 'the notice shows once');

$GLOBALS['meta_boxes'] = [];
$tools->register_meta_box();
check(['shop_order', 'woocommerce_page_wc-orders'] === array_column($GLOBALS['meta_boxes'], 'screen'), 'box on both order screens (posts and HPOS)');

/* ── Status ────────────────────────────────────────────────────────────── */

check('opened' === Product_Reviews_Email::request_status($h)['key'] || 'clicked' === Product_Reviews_Email::request_status($h)['key'], 'opened/clicked order status');
check('clicked' === Product_Reviews_Email::request_status($h)['key'], 'a click outranks an open');
Product_Reviews_Tracking::record_review($h);
check('reviewed' === Product_Reviews_Email::request_status($h)['key'] && 1 === Product_Reviews_Email::request_status($h)['reviewed'], 'a review through the link outranks everything');
check('skipped' === Product_Reviews_Email::request_status($g)['key'] && 'Guest order (guests are off)' === Product_Reviews_Email::request_status($g)['label'], 'skipped with the reason');

// A review through the signed link counts on its order.
reset_request();
$_POST = ['action' => 'ffla_submit_product_review', 'comment_post_ID' => '107', 'rating' => '5', 'comment' => 'Great grip', 'ffla_order_review_token' => $hub_token];
expect_redirect(['Product_Reviews_Core', 'handle_form_submission']);
check(2 === $h->get_meta('_ffla_review_reviewed'), 'a review sent through the hub link is counted on the order');
$last = end($GLOBALS['comments']);
check(5050 === (int) get_comment_meta($last->comment_ID, 'ffla_review_order_id', true), 'the review remembers its order');

/* ── Test email and preview ────────────────────────────────────────────── */

reset_request(1);
$_POST = ['nonce' => 'nonce-ffla_review_send_test', 'to' => 'me@store.test', 'kind' => 'reminder'];
$_REQUEST = $_POST;
[$ok, $payload] = expect_json([$tools, 'ajax_send_test']);
check($ok && 'me@store.test' === last_mail()['to'] && 0 === strpos(last_mail()['subject'], '[Test] A quick reminder'), 'test reminder sent with [Test] in the subject');
$_POST['to'] = 'nope';
$_REQUEST = $_POST;
[$ok, $payload] = expect_json([$tools, 'ajax_send_test']);
check(!$ok && 'Enter a valid email address.' === $payload['message'], 'test refuses a bad address');
reset_request(7);
$_POST = ['nonce' => 'nonce-ffla_review_send_test', 'to' => 'me@store.test'];
$_REQUEST = $_POST;
[$ok, , $status] = expect_json([$tools, 'ajax_send_test']);
check(!$ok && 403 === $status, 'customers cannot send tests');

reset_request(1);
$preview = Product_Reviews_Order_Tools::preview_html('request');
check(strpos($preview, '<!DOCTYPE html>') === 0 && strpos($preview, 'ffla_review_open') === false, 'preview is the HTML email, without the open pixel');
settings(['email_format' => 'plain']);
$preview = Product_Reviews_Order_Tools::preview_html('request');
check(strpos($preview, '<pre') !== false && strpos($preview, 'Do not want these emails?') !== false, 'plain text preview');
settings();

/* ── Request log ───────────────────────────────────────────────────────── */

$stats = Product_Reviews_Requests_Log::stats();
check($stats['sent'] >= 10 && 1 === $stats['reviewed'] && $stats['skipped'] >= 5, 'log totals: ' . json_encode($stats));
reset_request(1);
$_GET = ['page' => 'ffla-product-reviews-requests', 'request_status' => 'skipped'];
$log = capture(['Product_Reviews_Requests_Log', 'render']);
check(strpos($log, '#5020') !== false && strpos($log, '#5050') === false, 'log filter: skipped only');
check(strpos($log, 'Guest order (guests are off)') !== false, 'log shows why it was skipped');
$_GET = ['page' => 'ffla-product-reviews-requests'];
$log = capture(['Product_Reviews_Requests_Log', 'render']);
check(strpos($log, '#5050') !== false && strpos($log, 'ffla-pr-status--reviewed') !== false, 'log lists sent and reviewed orders');

echo "OK: {$checks} product-reviews request checks passed\n";
