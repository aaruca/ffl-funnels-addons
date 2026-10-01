<?php
/**
 * Standalone regression harness for WooBooster's Smart scoring.
 *
 * Run: php tests/smoke/woobooster-smart-smoke.php
 *
 * Covers the pure ranking functions behind "Bought Together"
 * (WooBooster_Copurchase::rank_pairs) and "Trending"
 * (WooBooster_Trending::score_rows).
 */

define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);

$checks = 0;
function check($test, $message)
{
    global $checks;
    $checks++;
    if (!$test) {
        throw new RuntimeException("FAIL: $message");
    }
}

function absint($v) { return abs((int) $v); }

require dirname(__DIR__, 2) . '/modules/woobooster/includes/class-woobooster-copurchase.php';
require dirname(__DIR__, 2) . '/modules/woobooster/includes/class-woobooster-trending.php';

/* ── Bought together: lift beats raw popularity ───────────────────────── */

// 200 orders. Gun (1) is in 30; its holster (2) is in 12 of them and nowhere
// else; ammo (3) is in 25 of the gun orders but also in 150 other orders.
$orders_with = array(1 => 30, 2 => 12, 3 => 175, 4 => 3);
$pair_weight = array(
    1 => array(2 => 12.0, 3 => 25.0, 4 => 1.0),
    2 => array(1 => 12.0),
    3 => array(1 => 25.0),
    4 => array(1 => 1.0),
);
$pair_count = array(
    1 => array(2 => 12, 3 => 25, 4 => 1),
    2 => array(1 => 12),
    3 => array(1 => 25),
    4 => array(1 => 1),
);

$index = WooBooster_Copurchase::rank_pairs($pair_weight, $pair_count, $orders_with, 200, 1, 20);
check(2 === $index[1][0], 'holster bought with the gun ranks above ammo that is in every cart');
check(!in_array(3, $index[1], true), 'ammo (no lift: in 87% of orders, 83% of gun orders) is not a "bought together" signal');
check(in_array(4, $index[1], true), 'a rare companion with real lift is kept');

$index = WooBooster_Copurchase::rank_pairs($pair_weight, $pair_count, $orders_with, 200, 2, 20);
check(!in_array(4, $index[1], true), 'min support 2 drops a pair seen in a single order');
check(!isset($index[4]), 'a product with no qualifying pair gets no list (its old list is cleared)');

$index = WooBooster_Copurchase::rank_pairs($pair_weight, $pair_count, $orders_with, 200, 1, 1);
check(array(2) === $index[1], 'max relations caps the list');

check(array() === WooBooster_Copurchase::rank_pairs(array(), array(), array(), 0, 1, 20), 'no orders, no index');

/* ── Trending: recent distinct orders, decayed ────────────────────────── */

$now = strtotime('2026-10-01 12:00:00 UTC');
$day = static function (int $ago) use ($now) {
    return gmdate('Y-m-d', $now - $ago * DAY_IN_SECONDS);
};

$rows = array(
    array('product_id' => '10', 'day' => $day(1), 'orders' => '3'),   // New and selling now.
    array('product_id' => '20', 'day' => $day(60), 'orders' => '10'), // Sold more, two months ago.
    array('product_id' => '30', 'day' => $day(0), 'orders' => '1'),
    array('product_id' => '99', 'day' => $day(0), 'orders' => '50'),  // Excluded (fee product).
    array('product_id' => '0', 'day' => $day(0), 'orders' => '5'),    // Invalid row.
);

$scores = WooBooster_Trending::score_rows($rows, $now, 14.0, array(99));
check($scores[10] > $scores[20], '3 orders yesterday outrank 10 orders two months ago');
check(abs($scores[30] - 1.0) < 1e-9, 'a sale today counts fully');
check(abs($scores[20] - 10 * pow(0.5, 60 / 14)) < 1e-9, 'weight halves every 14 days');
check(!isset($scores[99]) && !isset($scores[0]), 'excluded and invalid products are skipped');

$scores = WooBooster_Trending::score_rows(array(
    array('product_id' => '10', 'day' => $day(14), 'orders' => '4'),
), $now, 14.0);
check(abs($scores[10] - 2.0) < 1e-9, 'a 14-day-old sale counts half with the default half-life');

echo $checks . " checks passed (woobooster smart).\n";
