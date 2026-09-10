<?php
defined('ABSPATH') || exit;
class Pickup_Shipping_Settings
{
    const OPTION = 'ffla_pickup_shipping';
    public static function defaults(): array
    {
        return [
            'delivery'=>'both', 'default'=>'none', 'placement'=>'automatic',
            'pickup_methods'=>[], 'shipping_methods'=>[], 'ffl_enabled'=>false, 'locations'=>[],
            'store_name'=>'', 'store_address'=>'', 'instructions'=>'',
            'title'=>__('How would you like to receive your order?', 'ffl-funnels-addons'),
            'pickup_title'=>__('Pick up in store', 'ffl-funnels-addons'),
            'pickup_description'=>__('Collect your order at our store.', 'ffl-funnels-addons'),
            'ship_title'=>__('Ship my order', 'ffl-funnels-addons'),
            'ship_description'=>__('Deliver to your shipping address.', 'ffl-funnels-addons'),
            'accent'=>'', 'background'=>'', 'text_color'=>'',
        ];
    }
    public static function get(): array
    {
        $raw = get_option(self::OPTION, []);
        $s = array_merge(self::defaults(), is_array($raw) ? $raw : []);
        // Read on every request, never copy the provider's identity into our option.
        $s['ffl_pickup_license'] = self::provider_license();
        return $s;
    }
    public static function provider_license(): string
    {
        return function_exists('order_requires_ffl_selector') ? self::license(get_option('ffl_local_pickup', '')) : '';
    }
    public static function license($value): string
    {
        if (!is_scalar($value)) { return ''; }
        $value = strtoupper(trim((string) $value));
        // Formatting normalization only; this does not verify a license.
        $value = preg_replace('/[\s-]/', '', $value);
        return preg_match('/^[0-9]{9}[A-Z][0-9]{5}$/', $value) ? $value : '';
    }
    /** Keep site variables unresolved; only allow HEX and bounded var() references. */
    public static function color($value, int $depth = 0): string
    {
        if (!is_string($value) || strlen($value) > 256 || $depth > 4) { return ''; }
        $value = trim($value);
        if (preg_match('/\A#(?:[a-f0-9]{3}|[a-f0-9]{6})\z/i', $value)) { return $value; }
        if (preg_match('/\A--[a-zA-Z0-9_-]+\z/', $value)) { return 'var(' . $value . ')'; }
        if (!preg_match('/\Avar\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,\s*(.+))?\)\z/', $value, $match)) { return ''; }
        if (!isset($match[2])) { return 'var(' . $match[1] . ')'; }
        $fallback = self::color($match[2], $depth + 1);
        return $fallback !== '' ? 'var(' . $match[1] . ', ' . $fallback . ')' : '';
    }
    public static function methods(): array
    {
        if (!class_exists('WC_Shipping_Zones')) { return []; }
        $zones = WC_Shipping_Zones::get_zones();
        $zones[] = ['zone_id'=>0, 'zone_name'=>__('Rest of the world', 'ffl-funnels-addons')];
        $result = [];
        $pickup = (array) apply_filters('woocommerce_local_pickup_methods', ['local_pickup','legacy_local_pickup']);
        foreach ($zones as $zone) {
            foreach (WC_Shipping_Zones::get_zone($zone['zone_id'])->get_shipping_methods(true) as $method) {
                $id = $method->id . ':' . $method->get_instance_id();
                $result[$id] = [
                    'label'=>$zone['zone_name'] . ' — ' . $method->get_title() . ' (' . $id . ')',
                    'pickup'=>in_array($method->id, $pickup, true) || $method->supports('local-pickup'),
                ];
            }
        }
        return $result;
    }
    public static function sanitize(array $input, array $catalog): array
    {
        $s = self::defaults();
        foreach (['delivery'=>['both','pickup','ship'], 'default'=>['none','pickup','ship'], 'placement'=>['automatic','shortcode']] as $key=>$allowed) {
            if (isset($input[$key]) && in_array($input[$key], $allowed, true)) { $s[$key] = $input[$key]; }
        }
        foreach (['pickup_methods'=>true,'shipping_methods'=>false] as $key=>$pickup) {
            foreach (array_slice(is_array($input[$key] ?? null) ? $input[$key] : [], 0, 100) as $id) {
                if (is_string($id) && isset($catalog[$id]) && $catalog[$id]['pickup'] === $pickup) { $s[$key][] = $id; }
            }
            $s[$key] = array_values(array_unique($s[$key]));
        }
        $s['ffl_enabled'] = !empty($input['ffl_enabled']);
        foreach (['store_name','title','pickup_title','pickup_description','ship_title','ship_description'] as $key) {
            if (is_scalar($input[$key] ?? null)) { $s[$key] = substr(sanitize_text_field($input[$key]), 0, 400); }
        }
        foreach (['store_address','instructions'] as $key) {
            if (is_scalar($input[$key] ?? null)) { $s[$key] = substr(sanitize_textarea_field($input[$key]), 0, 1500); }
        }
        foreach (['accent','background','text_color'] as $key) { $s[$key] = self::color($input[$key] ?? ''); }
        $seen = [];
        foreach (array_slice(is_array($input['locations'] ?? null) ? $input['locations'] : [], 0, 25) as $row) {
            if (!is_array($row)) { continue; }
            $license = self::license($row['license'] ?? '');
            $method = is_string($row['method'] ?? null) ? $row['method'] : '';
            if (!$license || isset($seen[$license]) || !in_array($method, $s['pickup_methods'], true)) { continue; }
            $seen[$license] = true;
            $s['locations'][] = [
                'license'=>$license, 'method'=>$method,
                'name'=>is_scalar($row['name'] ?? null) ? substr(sanitize_text_field($row['name']),0,200) : '',
                'address'=>is_scalar($row['address'] ?? null) ? substr(sanitize_textarea_field($row['address']),0,800) : '',
                'instructions'=>is_scalar($row['instructions'] ?? null) ? substr(sanitize_textarea_field($row['instructions']),0,800) : '',
            ];
        }
        return $s;
    }
    public static function configured(array $s): bool
    {
        return ($s['delivery'] === 'ship' || !empty($s['pickup_methods']))
            && ($s['delivery'] === 'pickup' || !empty($s['shipping_methods']))
            && (!$s['ffl_enabled'] || function_exists('order_requires_ffl_selector'));
    }
}
