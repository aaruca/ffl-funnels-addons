<?php
/**
 * Offline check of the Product Reviews settings screen.
 *
 * Run: php tests/smoke/product-reviews-admin-smoke.php
 *
 * Each settings tab is its own form. Saving one tab must store that tab's
 * fields (toggles left unticked become off, numbers are clamped, selects only
 * take listed values, criteria are re-serialised, the hub slug falls back) and
 * must not touch any setting that lives on another tab. Every storable field
 * renders an input with its own name, so nothing on a tab silently stops
 * saving.
 */

require __DIR__ . '/product-reviews-stubs.php';

$GLOBALS['is_admin'] = true;

load_module([
    'includes/class-product-reviews-frontend-render.php',
    'includes/class-product-reviews-order-hub.php',
    'includes/class-product-reviews-core.php',
    'includes/class-product-reviews-criteria.php',
    'includes/class-product-reviews-moderation.php',
    'includes/class-product-reviews-notifications.php',
    'includes/class-product-reviews-email.php',
    'integrations/class-product-reviews-turnstile.php',
    'admin/class-product-reviews-admin.php',
]);

$admin = new Product_Reviews_Admin();
$admin->init();

function save_tab(string $tab, array $post): array
{
    reset_request(1);
    $_POST = $post + [
        'action'                 => 'product_reviews_save_settings',
        'tab'                    => $tab,
        '_product_reviews_nonce' => 'nonce-product_reviews_save_settings_nonce',
    ];
    $_REQUEST = $_POST;
    $admin    = new Product_Reviews_Admin();

    return expect_redirect(function () use ($admin) {
        $admin->handle_settings_save();
    });
}

/* ── Every tab renders every storable field ────────────────────────────── */

foreach (Product_Reviews_Admin_Fields::tabs() as $tab => $label) {
    $_GET = ['page' => 'ffla-product-reviews', 'tab' => $tab];
    $html = capture(function () use ($admin) {
        $admin->render_settings_content();
    });
    check(strpos($html, 'name="tab" value="' . $tab . '"') !== false, "$tab: the form says which tab it saves");
    foreach (Product_Reviews_Admin_Fields::fields($tab) as $field) {
        $name = $field['key'] . ('multiselect' === ($field['type'] ?? '') ? '[]' : '');
        check(strpos($html, 'name="' . $name . '"') !== false, "$tab: field {$field['key']} renders an input");
    }
    check(substr_count($html, 'ffla-pr-tabs__tab is-current') === 1, "$tab: exactly one tab is marked current");
}

// An unknown ?tab= shows the first tab rather than an empty page.
$_GET = ['page' => 'ffla-product-reviews', 'tab' => 'nope'];
$html = capture(function () use ($admin) {
    $admin->render_settings_content();
});
check(strpos($html, 'name="tab" value="reviews"') !== false, 'unknown tab falls back to the first one');

// The same key never appears on two tabs: the second save would overwrite it.
$seen = [];
foreach (array_keys(Product_Reviews_Admin_Fields::tabs()) as $tab) {
    foreach (Product_Reviews_Admin_Fields::fields($tab) as $field) {
        check(!isset($seen[$field['key']]), "{$field['key']} lives on one tab only (also on {$tab})");
        $seen[$field['key']] = $tab;
    }
}

// Every stored key has a default, so get_setting() never falls back to ''.
$defaults = Product_Reviews_Core::get_default_settings();
foreach ($seen as $key => $tab) {
    $field = null;
    foreach (Product_Reviews_Admin_Fields::fields($tab) as $f) {
        if ($f['key'] === $key) {
            $field = $f;
        }
    }
    if ($field['store'] ?? true) {
        check(array_key_exists($key, $defaults), "$key has a default setting");
    }
}

/* ── Saving one tab leaves the others alone ────────────────────────────── */

set_setting('enable_helpful_votes', '1');
set_setting('forbidden_words', 'scam');
set_setting('email_subject', 'Rate us');
set_setting('request_delay_days', '9');

$r = save_tab('requests', [
    'request_delay_days'        => '-4',
    'request_email_mode'        => 'carrier-pigeon',
    'order_review_page_id'      => '500',
    'order_review_rewrite_slug' => '   ',
    // enable_requests, pretty URLs and hub criteria left unticked.
]);
$s = get_option('ffla_product_reviews_settings');
check('requests' === ($r['tab'] ?? ''), 'redirects back to the tab that was saved');
check('1' === ($r['settings-updated'] ?? ''), 'redirect says the settings were saved');
check('0' === $s['enable_requests'], 'an unticked toggle on the saved tab is switched off');
check('0' === $s['request_delay_days'], 'a negative delay is clamped to 0');
check('bundle' === $s['request_email_mode'], 'a select only takes one of its options (else its default)');
check('order-review' === $s['order_review_rewrite_slug'], 'an empty hub slug falls back to order-review');
check('500' === $s['order_review_page_id'], 'the hub page ID is stored');
check('1' === $s['enable_helpful_votes'], 'a toggle on another tab is untouched');
check('scam' === $s['forbidden_words'], 'a textarea on another tab is untouched');
check('Rate us' === $s['email_subject'], 'a text field on another tab is untouched');

save_tab('form', [
    'form_title'      => '',
    'enable_criteria' => '1',
    'review_criteria' => "fit|Fit and finish\nbad line|\n|Nameless\nfit|Duplicate\nvalue|Value",
]);
$s = get_option('ffla_product_reviews_settings');
check('Write a review' === $s['form_title'], 'an empty form title falls back to the default');
check("fit|Fit and finish\nvalue|Value" === $s['review_criteria'], 'criteria are re-serialised without malformed or duplicate lines');
check('0' === $s['allow_media_uploads'], 'uploads switched off when its toggle is unticked');

save_tab('display', ['show_rating_summary' => '1']);
$s = get_option('ffla_product_reviews_settings');
check('0' === $s['enable_helpful_votes'], 'helpful votes off once their own tab is saved without them');
check('1' === $s['show_rating_summary'], 'a ticked toggle is stored as 1');

/* ── Fields that are read but never stored ─────────────────────────────── */

Product_Reviews_Notifications::opt_out('back@example.com');
check(Product_Reviews_Notifications::is_opted_out('back@example.com'), 'precondition: address unsubscribed');
save_tab('emails', ['resubscribe_email' => 'back@example.com', 'email_subject' => 'How did we do?']);
$s = get_option('ffla_product_reviews_settings');
check(!Product_Reviews_Notifications::is_opted_out('back@example.com'), 're-subscribe takes the address off the list');
check(!array_key_exists('resubscribe_email', $s), 're-subscribe address is not stored in the settings');
check('How did we do?' === $s['email_subject'], 'email subject saved');

/* ── Rewrites are only flushed when the hub URL changes ────────────────── */

$flushed = $GLOBALS['rewrite_flushed'];
save_tab('requests', ['order_review_page_id' => '500', 'order_review_rewrite_slug' => 'order-review']);
check($GLOBALS['rewrite_flushed'] === $flushed, 'saving without a hub URL change does not flush rewrites');
save_tab('requests', ['order_review_page_id' => '500', 'order_review_pretty_urls' => '1', 'order_review_rewrite_slug' => 'rate']);
check($GLOBALS['rewrite_flushed'] === $flushed + 1, 'changing pretty URLs flushes rewrites once');

/* ── Guards ────────────────────────────────────────────────────────────── */

reset_request(7);
$_POST = ['tab' => 'reviews', '_product_reviews_nonce' => 'nonce-product_reviews_save_settings_nonce'];
try {
    $admin->handle_settings_save();
    check(false, 'a customer cannot save settings');
} catch (Died $e) {
    check(true, 'a customer cannot save settings');
}

reset_request(1);
$_POST = ['tab' => 'reviews', '_product_reviews_nonce' => 'forged'];
$_REQUEST = $_POST;
try {
    $admin->handle_settings_save();
    check(false, 'a forged nonce is refused');
} catch (Died $e) {
    check(true, 'a forged nonce is refused');
}

reset_request(1);
$_POST = ['tab' => 'nope', '_product_reviews_nonce' => 'nonce-product_reviews_save_settings_nonce'];
$_REQUEST = $_POST;
try {
    $admin->handle_settings_save();
    check(false, 'an unknown tab is refused');
} catch (Died $e) {
    check(true, 'an unknown tab is refused');
}

echo "OK: {$checks} product-reviews admin checks passed\n";
