<?php
/**
 * Smart Coupons "Uses per person" on store pickup orders: their ship-to is the
 * store (Pickup & Shipping), so they are matched by billing address instead.
 *
 * Run: php tests/smoke/smart-coupons-pickup-address-smoke.php
 */
ob_start();
require __DIR__ . '/smart-coupons-schedule-smoke.php';
ob_end_clean();
$start = $checks;

class Pickup_Address_Order
{
    public $data;
    public function __construct(array $data) { $this->data = $data; }
    public function get_meta($key) { return $this->data['meta'][$key] ?? ''; }
    public function get_status() { return $this->data['status'] ?? 'processing'; }
    public function __call($name, $args)
    {
        if (preg_match('/^get_(billing|shipping)_(\w+)$/', $name, $m)) { return $this->data[$m[1]][$m[2]] ?? ''; }
        throw new BadMethodCallException($name);
    }
}
$orders = [];
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? false; }
$wpdb = new class {
    public $prefix = 'wp_';
    public function prepare($query, ...$args) { return $query; }
    public function get_col($query) { return array_keys($GLOBALS['orders']); }
};

$home = ['address_1' => '100 Main Street', 'postcode' => '62701', 'email' => 'pat@example.test', 'phone' => '5555550100'];
$store = ['address_1' => '200 Sample Avenue', 'postcode' => '75001'];
$homeKey = FFLA_Coupon_Rules::address_key('100 Main Street', '62701');
$storeKey = FFLA_Coupon_Rules::address_key('200 Sample Avenue', '75001');

$pickup = new Pickup_Address_Order(['billing' => $home, 'shipping' => $store, 'meta' => ['_ffla_pickup_ship_to' => 'store']]);
$shipped = new Pickup_Address_Order(['billing' => $home, 'shipping' => ['address_1' => '9 Other Road', 'postcode' => '10001']]);
$legacyPickup = new Pickup_Address_Order(['billing' => $home, 'shipping' => $home]);

check($homeKey === FFLA_Coupon_Rules::order_address_key($pickup), 'store pickup order is matched by its billing address');
check(FFLA_Coupon_Rules::address_key('9 Other Road', '10001') === FFLA_Coupon_Rules::order_address_key($shipped), 'shipped order is still matched by its ship-to');
check($homeKey === FFLA_Coupon_Rules::order_address_key($legacyPickup), 'older pickup orders (billing copied into shipping) unchanged');

// Same home, new email and phone: the earlier pickup use still counts.
$orders = [11 => $pickup];
check(1 === FFLA_Coupon_Rules::uses_by_person('once', ['email' => 'new@example.test', 'phone' => '', 'address' => $homeKey]), 'earlier pickup use found by the customer home address');
// Somebody else whose address is the store must not inherit every pickup use.
check(0 === FFLA_Coupon_Rules::uses_by_person('once', ['email' => 'other@example.test', 'phone' => '', 'address' => $storeKey]), 'store address does not match other customers\' pickup uses');
$orders = [12 => new Pickup_Address_Order(['billing' => $home, 'shipping' => $store, 'meta' => ['_ffla_pickup_ship_to' => 'store'], 'status' => 'failed'])];
check(0 === FFLA_Coupon_Rules::uses_by_person('once', ['email' => '', 'phone' => '', 'address' => $homeKey]), 'failed orders still not counted');

echo ($checks - $start) . " Smart Coupons pickup address checks passed.\n";
