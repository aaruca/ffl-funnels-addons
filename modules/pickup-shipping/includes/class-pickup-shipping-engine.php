<?php
defined('ABSPATH') || exit;
/** Pure policy helpers: they only remove rates, never create/free/retax a rate. */
class Pickup_Shipping_Engine
{
    public static function licensee_key($license): string
    {
        $license = Pickup_Shipping_Settings::license($license);
        // ATF's abbreviated FFL identity is the first three and last five
        // digits. The middle expiration segment can change on renewal.
        return $license === '' ? '' : substr($license,0,3) . substr($license,-5);
    }
    public static function same_licensee($first, $second): bool
    {
        $first = self::licensee_key($first);
        $second = self::licensee_key($second);
        return $first !== '' && $first === $second;
    }
    public static function location(array $s, string $license): ?array
    {
        if (self::same_licensee($license,$s['ffl_pickup_license'] ?? '')) {
            return ['license'=>$license, 'name'=>$s['store_name'], 'address'=>$s['store_address'], 'instructions'=>$s['instructions']];
        }
        return null;
    }
    public static function decision(array $s, string $scope, array $state): array
    {
        if ($scope === 'mixed') { return ['mode'=>'blocked','methods'=>[], 'reason'=>'mixed']; }
        if ($scope === 'ffl' && $s['ffl_enabled']) {
            $license = Pickup_Shipping_Settings::license($state['license'] ?? '');
            if (!$license) { return ['mode'=>'pending','methods'=>[], 'reason'=>'dealer']; }
            $local = self::location($s, $license);
            $mode = $local ? 'pickup' : 'ship';
            $methods = $local ? $s['pickup_methods'] : $s['shipping_methods'];
            // FFL rules do not silently override a site's pickup-only/ship-only policy.
            if ($s['delivery'] !== 'both' && $s['delivery'] !== $mode) { $methods = []; }
            return ['mode'=>$mode, 'methods'=>$methods, 'reason'=>'ffl', 'location'=>$local];
        }
        $mode = $s['delivery'] !== 'both' ? $s['delivery'] : ($state['mode'] ?? $s['default']);
        if (!in_array($mode, ['pickup','ship'], true)) { $mode = 'none'; }
        return ['mode'=>$mode, 'methods'=>$mode === 'none' ? array_merge($s['pickup_methods'],$s['shipping_methods']) : $s[$mode === 'ship' ? 'shipping_methods' : 'pickup_methods'], 'reason'=>'customer'];
    }
    public static function filter(array $rates, array $decision): array
    {
        $filtered = [];
        foreach ($rates as $id=>$rate) { if (self::allows((string)$id,$decision['methods'])) { $filtered[$id] = $rate; } }
        return $filtered;
    }
    public static function allows(string $rate_id, array $methods): bool
    {
        foreach ($methods as $method) {
            // Carriers can return method:instance:service IDs. Keep all actual
            // services belonging to the configured instance, never adjacent IDs.
            if ($rate_id === $method || strpos($rate_id,$method . ':') === 0) { return true; }
        }
        return false;
    }
    public static function product_requires_ffl($product): bool
    {
        if (!is_object($product) || !is_callable([$product,'get_meta'])) { return false; }
        if (function_exists('product_is_firearm') && product_is_firearm($product)) { return true; }
        $parent_id = $product->get_parent_id();
        $parent = $parent_id ? wc_get_product($parent_id) : null;
        return strtolower(trim((string) ($parent ? $parent->get_meta('_firearm_product') : $product->get_meta('_firearm_product')))) === 'yes';
    }
    public static function package_scope(array $package, bool $requires_ffl, bool $has_firearms): string
    {
        if (!$requires_ffl) { return 'regular'; }
        // Canonical provider compliance-only carts are entirely FFL scoped.
        if (!$has_firearms) { return 'ffl'; }
        $ffl = $regular = false;
        foreach ($package['contents'] ?? [] as $item) {
            $product = $item['data'] ?? null;
            if (!$product || !$product->needs_shipping()) { continue; }
            if (self::product_requires_ffl($product)) { $ffl = true; } else { $regular = true; }
        }
        $scope = $ffl && $regular ? 'mixed' : ($ffl ? 'ffl' : 'regular');
        // A provider can mark its already-separated packages server-side.
        $scope = apply_filters('ffla_pickup_shipping_package_scope', $scope, $package);
        return in_array($scope,['ffl','regular','mixed'],true) ? $scope : 'mixed';
    }
}

