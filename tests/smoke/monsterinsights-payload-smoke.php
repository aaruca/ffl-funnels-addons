<?php
/**
 * Offline checks for MonsterInsights Compatibility payload normalization.
 * No WordPress, network or credentials.
 *
 * php tests/smoke/monsterinsights-payload-smoke.php
 *
 * Fixtures mirror the Measurement Protocol bodies built by the MonsterInsights
 * eCommerce Addon's WooCommerce provider (track_add_to_cart_v4,
 * track_remove_from_cart_v4, track_begin_checkout_v4, track_purchase_v4).
 */
define('ABSPATH', __DIR__);
require __DIR__ . '/../../modules/ga4-bridge/includes/class-ga4-bridge-payload.php';

$checks = 0;
function check($condition, $label)
{
    global $checks;
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: $label\n");
        exit(1);
    }
}

// Item text is decoded: names come from get_the_title() HTML-encoded.
check(Ga4_Bridge_Payload::clean_text('H&amp;R 1871 Pardner Pump 12 GA 22&#8243; BBL') === "H&R 1871 Pardner Pump 12 GA 22\u{2033} BBL", 'entities decoded');
check(Ga4_Bridge_Payload::clean_text('Smith &#038; Wesson') === 'Smith & Wesson', 'numeric ampersand decoded');
check(Ga4_Bridge_Payload::clean_text("  <b>Glock</b>\n 19  ") === 'Glock 19', 'tags stripped, whitespace collapsed');
check(Ga4_Bridge_Payload::clean_text('&lt;Sale&gt;') === '<Sale>', 'encoded brackets survive as text');
check(Ga4_Bridge_Payload::clean_text(165248) === 165248, 'non-strings untouched');

// add_to_cart: MonsterInsights computes value as (int) price * quantity.
$add = [
    'client_id' => '123.456',
    'events'    => [[
        'name'   => 'add_to_cart',
        'params' => [
            'value'      => 174,
            'currency'   => 'USD',
            'session_id' => '1792449000',
            'items'      => [[
                'item_id'       => 165248,
                'item_name'     => 'H&amp;R 1871 Pardner Pump 12 GA 22&#8243; BBL New England Firearms',
                'item_category' => 'PRE-OWNED &amp; CONSIGNMENT',
                'item_variant'  => '',
                'price'         => '174.99',
                'quantity'      => 2,
            ]],
        ],
    ]],
    'consent'   => ['ad_personalization' => 'GRANTED'],
];
$out = Ga4_Bridge_Payload::normalize_body($add, 'USD', 2);
$params = $out['events'][0]['params'];
check($params['value'] === 349.98, 'add_to_cart value keeps the cents (349.98)');
check($params['currency'] === 'USD', 'add_to_cart currency kept');
check($params['items'][0]['item_name'] === "H&R 1871 Pardner Pump 12 GA 22\u{2033} BBL New England Firearms", 'add_to_cart item_name decoded');
check($params['items'][0]['item_category'] === 'PRE-OWNED & CONSIGNMENT', 'add_to_cart item_category decoded');
check($params['items'][0]['price'] === '174.99' && $params['items'][0]['quantity'] === 2, 'item price/quantity untouched');
check($params['session_id'] === '1792449000', 'session_id untouched');
check($out['client_id'] === '123.456' && $out['consent'] === $add['consent'], 'top-level keys untouched');

// remove_from_cart: same integer defect.
$remove = $add;
$remove['events'][0]['name'] = 'remove_from_cart';
$remove['events'][0]['params']['items'][0]['quantity'] = 1;
$out = Ga4_Bridge_Payload::normalize_body($remove, 'USD', 2);
check($out['events'][0]['params']['value'] === 174.99, 'remove_from_cart value keeps the cents');

// begin_checkout: MonsterInsights sends items only; value and currency are filled.
$checkout = ['client_id' => '1.2', 'events' => [[
    'name'   => 'begin_checkout',
    'params' => ['items' => [
        ['item_id' => 1, 'item_name' => 'A &amp; B', 'price' => '10.50', 'quantity' => 2],
        ['item_id' => 2, 'item_name' => 'C', 'price' => '5', 'quantity' => 1],
    ]],
]]];
$out = Ga4_Bridge_Payload::normalize_body($checkout, 'USD', 2);
check($out['events'][0]['params']['value'] === 26.0, 'begin_checkout value filled (26.00)');
check($out['events'][0]['params']['currency'] === 'USD', 'begin_checkout currency filled');
check($out['events'][0]['params']['items'][0]['item_name'] === 'A & B', 'begin_checkout item_name decoded');

// begin_checkout with a value already present is left alone.
$checkout['events'][0]['params']['value'] = 99;
$out = Ga4_Bridge_Payload::normalize_body($checkout, 'USD', 2);
check($out['events'][0]['params']['value'] === 99, 'existing begin_checkout value kept');

// purchase: order totals are correct upstream and must not change; names are decoded.
$purchase = ['client_id' => '1.2', 'events' => [[
    'name'   => 'purchase',
    'params' => [
        'transaction_id' => '164284',
        'value'          => '665.67',
        'tax'            => '41.18',
        'shipping'       => '25.00',
        'currency'       => 'USD',
        'items'          => [['item_id' => 1, 'item_name' => 'H&amp;R', 'price' => 599.49, 'quantity' => 1]],
    ],
]]];
$out = Ga4_Bridge_Payload::normalize_body($purchase, 'USD', 2);
$params = $out['events'][0]['params'];
check($params['value'] === '665.67' && $params['tax'] === '41.18' && $params['shipping'] === '25.00', 'purchase totals untouched');
check($params['items'][0]['item_name'] === 'H&R', 'purchase item_name decoded');

// An item without a numeric price: the value is not guessed.
$noPrice = $add;
unset($noPrice['events'][0]['params']['items'][0]['price']);
$out = Ga4_Bridge_Payload::normalize_body($noPrice, 'USD', 2);
check($out['events'][0]['params']['value'] === 174, 'value left as sent when an item has no price');

// Zero-decimal currencies round to whole units.
$out = Ga4_Bridge_Payload::normalize_body($add, 'JPY', 0);
check($out['events'][0]['params']['value'] === 350.0, 'rounding follows store decimals');

// Non-ecommerce and malformed bodies pass through unchanged.
$plain = ['client_id' => '1.2', 'events' => [['name' => 'form_submit', 'params' => ['form_id' => 'x']]]];
check(Ga4_Bridge_Payload::normalize_body($plain, 'USD', 2) === $plain, 'non-ecommerce event unchanged');
check(Ga4_Bridge_Payload::normalize_body('raw', 'USD', 2) === 'raw', 'string body unchanged');
check(Ga4_Bridge_Payload::normalize_body(['events' => 'x'], 'USD', 2) === ['events' => 'x'], 'malformed events unchanged');
check(Ga4_Bridge_Payload::normalize_body(['events' => [['name' => 'add_to_cart']]], 'USD', 2) === ['events' => [['name' => 'add_to_cart']]], 'event without params unchanged');

echo "MonsterInsights payload smoke checks passed ($checks checks).\n";
