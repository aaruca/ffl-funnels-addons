<?php
/**
 * Standalone regression harness for WooBooster bundle pricing.
 *
 * Run: php tests/smoke/woobooster-bundle-pricing-smoke.php
 *
 * Covers the pure price math behind the bundle widget and the add-to-cart
 * snapshot (WooBooster_Bundle::apply_discount_to_prices() and
 * ::apply_fixed_bundle_price()). The cart charges each item's discounted unit
 * price × its quantity, so a fixed discount or fixed bundle price must cover
 * the whole set including quantities — previously a $10 discount on
 * "2 × A + B" saved $15, and a $100 fixed price charged more than $100.
 */

define('ABSPATH', __DIR__);

$checks = 0;
function check($test, $message)
{
    global $checks;
    $checks++;
    if (!$test) {
        throw new RuntimeException("FAIL: $message");
    }
}

function near($a, $b, $tolerance = 0.011)
{
    return abs((float) $a - (float) $b) <= $tolerance;
}

/**
 * What the cart charges for the set: sum of discounted unit price × quantity.
 */
function cart_total(array $out, array $qty)
{
    $total = 0.0;
    foreach ($out as $pid => $row) {
        $total += $row['discounted'] * (isset($qty[$pid]) ? $qty[$pid] : 1);
    }
    return $total;
}

require dirname(__DIR__, 2) . '/modules/woobooster/includes/class-woobooster-bundle.php';

$prices = array(1 => 50.0, 2 => 30.0);
$qty    = array(1 => 2, 2 => 1); // Set: 2 × $50 + 1 × $30 = $130.

/* ── Fixed amount off the set ──────────────────────────────────────────── */

$out = WooBooster_Bundle::apply_discount_to_prices($prices, 'fixed', 10, 2, $qty);
check(near(cart_total($out, $qty), 120.0), 'fixed $10 off a $130 set (with quantities) charges $120');
check($out[1]['original'] === 50.0 && $out[2]['original'] === 30.0, 'originals are unit prices');
check($out[1]['discounted'] < 50.0 && $out[2]['discounted'] < 30.0, 'every item shares the discount');

$out = WooBooster_Bundle::apply_discount_to_prices($prices, 'fixed', 500, 2, $qty);
check(near(cart_total($out, $qty), 0.0), 'a discount larger than the set floors the set at $0');

/* ── Fixed bundle price ────────────────────────────────────────────────── */

$out = WooBooster_Bundle::apply_fixed_bundle_price($prices, 100, 2, $qty);
check(near(cart_total($out, $qty), 100.0), 'fixed price $100 for a $130 set (with quantities) charges $100');

$out = WooBooster_Bundle::apply_fixed_bundle_price($prices, 130, 2, $qty);
check($out[1]['discounted'] === 50.0 && $out[2]['discounted'] === 30.0, 'a fixed price equal to the set price gives no discount');

$out = WooBooster_Bundle::apply_fixed_bundle_price($prices, 120, 2, $qty);
check(near(cart_total($out, $qty), 120.0), 'fixed price between unit sum ($80) and set price ($130) is still a discount');

/* ── Percentage is per unit, independent of quantity ───────────────────── */

$out = WooBooster_Bundle::apply_discount_to_prices($prices, 'percentage', 10, 2, $qty);
check($out[1]['discounted'] === 45.0 && $out[2]['discounted'] === 27.0, '10% off each unit');
check(near(cart_total($out, $qty), 117.0), '10% off a $130 set charges $117');

/* ── Without quantities the old behaviour is unchanged ─────────────────── */

$out = WooBooster_Bundle::apply_discount_to_prices($prices, 'fixed', 10, 2);
check(near(cart_total($out, array()), 70.0), 'fixed $10 off one of each ($80) charges $70');

$out = WooBooster_Bundle::apply_fixed_bundle_price($prices, 60, 2);
check(near(cart_total($out, array()), 60.0), 'fixed price $60 for one of each charges $60');

$out = WooBooster_Bundle::apply_discount_to_prices($prices, 'none', 10, 2, $qty);
check($out[1]['discounted'] === 50.0 && $out[2]['discounted'] === 30.0, 'no discount type leaves prices alone');

$out = WooBooster_Bundle::apply_discount_to_prices(array(), 'fixed', 10, 2, $qty);
check(array() === $out, 'empty price map stays empty');

echo $checks . " checks passed (woobooster bundle pricing).\n";
