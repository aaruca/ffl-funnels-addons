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
            'card_background'=>'', 'card_text'=>'', 'selected_text'=>'', 'border_color'=>'',
            'container_radius'=>'', 'card_radius'=>'', 'card_gap'=>'16px',
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
    public static function appearance_fields(): array
    {
        return [
            'background'=>['label'=>__('Container background','ffl-funnels-addons'),'property'=>'--ffla-delivery-bg','type'=>'color','example'=>'var(--surface)'],
            'text_color'=>['label'=>__('Heading and container text','ffl-funnels-addons'),'property'=>'--ffla-delivery-text','type'=>'color','example'=>'var(--text)'],
            'card_background'=>['label'=>__('Unselected card background','ffl-funnels-addons'),'property'=>'--ffla-delivery-card-bg','type'=>'color','example'=>'var(--surface-alt)'],
            'card_text'=>['label'=>__('Unselected card text','ffl-funnels-addons'),'property'=>'--ffla-delivery-card-text','type'=>'color','example'=>'var(--text)'],
            'accent'=>['label'=>__('Selected card background','ffl-funnels-addons'),'property'=>'--ffla-delivery-accent','type'=>'color','example'=>'var(--primary)'],
            'selected_text'=>['label'=>__('Selected card text','ffl-funnels-addons'),'property'=>'--ffla-delivery-selected-text','type'=>'color','example'=>'#ffffff'],
            'border_color'=>['label'=>__('Container and unselected card border','ffl-funnels-addons'),'property'=>'--ffla-delivery-border','type'=>'color','example'=>'var(--border-color)'],
            'container_radius'=>['label'=>__('Container border radius','ffl-funnels-addons'),'property'=>'--ffla-delivery-radius','type'=>'radius','example'=>'0px or var(--radius)'],
            'card_radius'=>['label'=>__('Card border radius','ffl-funnels-addons'),'property'=>'--ffla-delivery-card-radius','type'=>'radius','example'=>'0px or var(--radius)'],
            'card_gap'=>['label'=>__('Gap between cards','ffl-funnels-addons'),'property'=>'--ffla-delivery-gap','type'=>'spacing','example'=>'16px or var(--space-m)'],
        ];
    }
    public static function styles(array $s): string
    {
        $styles = [];
        foreach (self::appearance_fields() as $key=>$field) {
            $value = self::appearance_value($s[$key] ?? '', $field['type']);
            if ($value !== '') { $styles[] = $field['property'] . ':' . $value; }
        }
        return implode(';', $styles);
    }
    /** Keep variables live; reject arbitrary declarations, URLs and expressions. */
    public static function color($value, int $depth = 0): string
    {
        return self::appearance_value($value, 'color', $depth);
    }
    public static function appearance_value($value, string $type, int $depth = 0): string
    {
        if (!is_string($value) || strlen($value) > 256 || $depth > 4) { return ''; }
        $value = trim($value);
        if ($type === 'color' && (preg_match('/\A#(?:[a-f0-9]{3,4}|[a-f0-9]{6}|[a-f0-9]{8})\z/i', $value) || in_array(strtolower($value), ['transparent','currentcolor'], true))) { return $value; }
        if (in_array($type, ['radius','spacing'], true) && preg_match('/\A(?:0|(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)(?:px|rem|em|%))\z/', $value)) { return $value; }
        if (preg_match('/\A--[a-zA-Z0-9_-]+\z/', $value)) { return 'var(' . $value . ')'; }
        if (!preg_match('/\Avar\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,\s*(.+))?\)\z/', $value, $match)) { return ''; }
        if (!isset($match[2])) { return 'var(' . $match[1] . ')'; }
        $fallback = self::appearance_value($match[2], $type, $depth + 1);
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
        foreach (self::appearance_fields() as $key=>$field) { $s[$key] = self::appearance_value($input[$key] ?? $s[$key], $field['type']); }
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
