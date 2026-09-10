<?php
defined('ABSPATH') || exit;
class Pickup_Shipping_Checkout
{
    const SESSION = 'ffla_pickup_shipping_state';
    const AVAILABLE = 'ffla_pickup_shipping_available';
    private static $posted = [];
    private static $rendered = false;
    public static function init(): void
    {
        add_action('woocommerce_checkout_update_order_review', [__CLASS__,'update'], 20);
        add_action('woocommerce_checkout_process', [__CLASS__,'process'], 1);
        add_filter('woocommerce_cart_shipping_packages', [__CLASS__,'packages'], 999);
        add_filter('woocommerce_package_rates', [__CLASS__,'rates'], 999, 2);
        add_action('woocommerce_after_checkout_validation', [__CLASS__,'validate'], 999, 2);
        add_action('woocommerce_checkout_create_order_shipping_item', [__CLASS__,'shipping_meta'], 20, 4);
        add_action('woocommerce_checkout_billing', [__CLASS__,'automatic'], 5);
        add_shortcode('ffla_delivery_choice', [__CLASS__,'shortcode']);
        add_filter('woocommerce_update_order_review_fragments', [__CLASS__,'fragments']);
        add_action('wp_enqueue_scripts', [__CLASS__,'assets'], 40);
        add_action('woocommerce_cart_emptied', [__CLASS__,'clear']);
        add_action('woocommerce_cart_updated', [__CLASS__,'cart_updated']);
        add_action('woocommerce_checkout_order_processed', [__CLASS__,'clear'], 999);
        add_action('admin_notices', [__CLASS__,'notice']);
        add_action('woocommerce_email_after_order_table', [__CLASS__,'email_summary'], 20, 4);
        add_action('woocommerce_order_details_after_order_table', [__CLASS__,'order_summary']);
        add_action('woocommerce_admin_order_data_after_shipping_address', [__CLASS__,'order_summary']);
    }
    public static function blocks(): bool
    {
        return function_exists('has_block') && function_exists('wc_get_page_id')
            && has_block('woocommerce/checkout', wc_get_page_id('checkout'));
    }
    public static function supported(): bool
    {
        return !(defined('REST_REQUEST') && REST_REQUEST) && !self::blocks()
            && !function_exists('cgs_filter_ffl_shipping_rates')
            && function_exists('WC') && WC()->session && WC()->cart;
    }
    public static function active(): bool
    {
        if (!self::supported() || (is_admin() && !wp_doing_ajax())) { return false; }
        $endpoint = is_scalar($_GET['wc-ajax'] ?? null) ? sanitize_key(wp_unslash($_GET['wc-ajax'])) : '';
        $checkout = (is_checkout() && !is_order_received_page() && !is_wc_endpoint_url('order-pay'))
            || in_array($endpoint, ['update_order_review','checkout'], true);
        return $checkout && WC()->cart->needs_shipping() && Pickup_Shipping_Settings::configured(Pickup_Shipping_Settings::get());
    }
    public static function fingerprint(): string
    {
        $items = [];
        foreach (WC()->cart->get_cart() as $key=>$item) { $items[$key] = [$item['product_id'] ?? 0,$item['variation_id'] ?? 0,$item['quantity'] ?? 0]; }
        return md5(wp_json_encode($items));
    }
    public static function state(): array
    {
        $state = WC()->session->get(self::SESSION, []);
        return is_array($state) && ($state['cart'] ?? '') === self::fingerprint() ? $state : [];
    }
    public static function clear(): void
    {
        if (!function_exists('WC') || !WC()->session) { return; }
        WC()->session->__unset(self::SESSION);
        WC()->session->__unset(self::AVAILABLE);
    }
    public static function cart_updated(): void
    {
        if (!function_exists('WC') || !WC()->session || !WC()->cart) { return; }
        $state = WC()->session->get(self::SESSION, []);
        if (is_array($state) && ($state['cart'] ?? '') !== self::fingerprint()) { self::clear(); }
    }
    public static function parse($raw): array
    {
        if (is_string($raw)) { parse_str($raw, $raw); }
        if (!is_array($raw)) { return []; }
        $data = [];
        foreach (['ffla_delivery_mode','shipping_fflno','compliance_mode','ammo_compliance','non_firearms_compliance'] as $key) {
            if (isset($raw[$key]) && is_scalar($raw[$key])) { $data[$key] = sanitize_text_field(wp_unslash($raw[$key])); }
        }
        return $data;
    }
    public static function update($raw): void
    {
        if (!self::supported()) { return; }
        self::$posted = self::parse($raw);
        $state = self::state();
        $s = Pickup_Shipping_Settings::get();
        $state['mode'] = $state['mode'] ?? $s['default'];
        if (array_key_exists('ffla_delivery_mode',self::$posted)) {
            $state['mode'] = in_array(self::$posted['ffla_delivery_mode'],['pickup','ship'],true) ? self::$posted['ffla_delivery_mode'] : 'none';
        }
        // Missing is not equivalent to explicitly clearing the selected dealer.
        if (array_key_exists('shipping_fflno', self::$posted)) { $state['license'] = Pickup_Shipping_Settings::license(self::$posted['shipping_fflno']); }
        if (!self::requires_ffl()) { unset($state['license']); }
        $state['cart'] = self::fingerprint();
        WC()->session->set(self::SESSION, $state);
    }
    public static function process(): void
    {
        self::update($_POST);
        // Never authorize a final FFL choice using a stale session/cookie.
        if (self::supported() && self::requires_ffl()) {
            $state = self::state();
            $state['license'] = Pickup_Shipping_Settings::license(self::$posted['shipping_fflno'] ?? '');
            WC()->session->set(self::SESSION, $state);
        }
    }
    public static function requires_ffl(): bool
    {
        if (!function_exists('WC') || !WC()->cart) { return false; }
        if (function_exists('order_requires_ffl_selector') && order_requires_ffl_selector()) { return true; }
        if (function_exists('ffl_get_checkout_compliance_type') && ffl_get_checkout_compliance_type()) { return true; }
        foreach (WC()->cart->get_cart() as $item) {
            if (Pickup_Shipping_Engine::product_requires_ffl($item['data'] ?? null)) { return true; }
        }
        // Preserve compliance hints when review AJAX nests them in post_data.
        $data = self::$posted;
        return !empty($data['compliance_mode']) || ($data['ammo_compliance'] ?? '') === '1' || ($data['non_firearms_compliance'] ?? '') === '1';
    }
    public static function scope(array $package): string
    {
        $s = Pickup_Shipping_Settings::get();
        if (!$s['ffl_enabled']) { return 'regular'; }
        $firearms = false;
        foreach (WC()->cart->get_cart() as $item) {
            if (Pickup_Shipping_Engine::product_requires_ffl($item['data'] ?? null)) { $firearms = true; break; }
        }
        return Pickup_Shipping_Engine::package_scope($package,self::requires_ffl(),$firearms);
    }
    public static function packages(array $packages): array
    {
        if (!self::supported()) { return $packages; }
        // Include policy/context in Woo's package hash, even on cart->checkout
        // transitions and after disabling this module's configuration.
        $active = self::active();
        $settings = Pickup_Shipping_Settings::get();
        foreach ($packages as $key=>&$package) {
            $scope = $active ? self::scope($package) : 'regular';
            $decision = $active ? Pickup_Shipping_Engine::decision($settings,$scope,self::state()) : [];
            $package['ffla_delivery'] = [
                'active'=>$active, 'scope'=>$scope, 'decision'=>$decision,
                'version'=>md5(wp_json_encode($settings)), 'package_key'=>(string)$key,
                'dealer'=>self::state()['license'] ?? '',
            ];
        }
        unset($package);
        return $packages;
    }
    public static function rates(array $rates, array $package): array
    {
        if (!self::active() || empty($package['ffla_delivery']['active'])) { return $rates; }
        $s = Pickup_Shipping_Settings::get();
        $key = $package['ffla_delivery']['package_key'];
        $availability = WC()->session->get(self::AVAILABLE, []);
        $availability = is_array($availability) ? $availability : [];
        // Store only method IDs, never another copy of customer addresses.
        $availability[$key] = [
            'pickup'=>(bool) Pickup_Shipping_Engine::filter($rates,['methods'=>$s['pickup_methods']]),
            'ship'=>(bool) Pickup_Shipping_Engine::filter($rates,['methods'=>$s['shipping_methods']]),
        ];
        WC()->session->set(self::AVAILABLE, $availability);
        return Pickup_Shipping_Engine::filter($rates,$package['ffla_delivery']['decision']);
    }
    public static function message(array $decision): string
    {
        if (($decision['reason'] ?? '') === 'mixed') { return __('This cart needs separate FFL and customer shipping packages. Please contact the store or place separate orders.', 'ffl-funnels-addons'); }
        if (($decision['mode'] ?? '') === 'pending') { return __('Select your FFL dealer to see the available delivery methods.', 'ffl-funnels-addons'); }
        return __('No compatible delivery method is available for this package. Review your address and selection, or contact the store.', 'ffl-funnels-addons');
    }
    public static function validate($data, $errors): void
    {
        if (!self::active()) { return; }
        $s = Pickup_Shipping_Settings::get();
        $posted = self::parse($_POST);
        $state = self::state();
        if (self::requires_ffl() && $s['ffl_enabled']) { $state['license'] = Pickup_Shipping_Settings::license($posted['shipping_fflno'] ?? ($data['shipping_fflno'] ?? '')); }
        foreach (WC()->shipping()->get_packages() as $key=>$package) {
            $decision = Pickup_Shipping_Engine::decision($s,self::scope($package),$state);
            if (in_array($decision['mode'],['blocked','pending','none'],true)) {
                $errors->add('ffla_delivery_' . $key, $decision['mode'] === 'none' ? __('Choose pickup or shipping before placing your order.', 'ffl-funnels-addons') : self::message($decision));
                continue;
            }
            $selected = is_array($data['shipping_method'] ?? null) ? ($data['shipping_method'][$key] ?? '') : '';
            if (!is_string($selected) || !Pickup_Shipping_Engine::allows($selected,$decision['methods']) || !isset($package['rates'][$selected])) {
                $errors->add('ffla_delivery_' . $key,self::message($decision));
            }
        }
        // g-FFL's license/address/restriction validators remain registered.
    }
    public static function shipping_meta($item, $key, $package, $order): void
    {
        if (!self::active()) { return; }
        $s = Pickup_Shipping_Settings::get();
        $decision = Pickup_Shipping_Engine::decision($s,self::scope($package),self::state());
        $mode = $decision['mode'];
        if (!in_array($mode,['pickup','ship'],true)) { return; }
        $item->add_meta_data('_ffla_delivery', [
            'mode'=>$mode,
            'name'=>$mode === 'pickup' ? ($decision['location']['name'] ?? $s['store_name']) : '',
            'address'=>$mode === 'pickup' ? ($decision['location']['address'] ?? $s['store_address']) : '',
            'instructions'=>$mode === 'pickup' ? ($decision['location']['instructions'] ?? $s['instructions']) : '',
        ],true);
        $item->add_meta_data(__('Delivery', 'ffl-funnels-addons'),$mode === 'pickup' ? __('Store pickup', 'ffl-funnels-addons') : __('Shipping', 'ffl-funnels-addons'),true);
        if ($mode === 'pickup') {
            $location = $decision['location'] ?? null;
            foreach (['name'=>'store_name','address'=>'store_address','instructions'=>'instructions'] as $key=>$setting) {
                $value = $location ? $location[$key] : $s[$setting];
                if ($value !== '') {
                    $labels = ['name'=>__('Pickup location', 'ffl-funnels-addons'),'address'=>__('Pickup address', 'ffl-funnels-addons'),'instructions'=>__('Pickup instructions', 'ffl-funnels-addons')];
                    $item->add_meta_data($labels[$key],$value,true);
                }
            }
        }
    }
    public static function automatic(): void
    {
        if (Pickup_Shipping_Settings::get()['placement'] === 'automatic') { echo self::shortcode(); }
    }
    private static function summary($order, bool $plain): void
    {
        if (!is_object($order) || !is_callable([$order,'get_shipping_methods'])) { return; }
        $lines = [];
        foreach ($order->get_shipping_methods() as $item) {
            $data = $item->get_meta('_ffla_delivery');
            if (!is_array($data) || !in_array($data['mode'] ?? '',['pickup','ship'],true)) { continue; }
            $line = $item->get_name() . ': ' . ($data['mode'] === 'pickup' ? __('Store pickup','ffl-funnels-addons') : __('Shipping','ffl-funnels-addons'));
            foreach (['name','address','instructions'] as $key) { if (!empty($data[$key]) && is_scalar($data[$key])) { $line .= "\n" . $data[$key]; } }
            $lines[] = $line;
        }
        if (!$lines) { return; }
        if ($plain) { echo "\n" . wp_strip_all_tags(implode("\n\n",$lines)) . "\n"; }
        else { echo '<div class="ffla-delivery-summary"><h3>' . esc_html__('Delivery information','ffl-funnels-addons') . '</h3><p>' . nl2br(esc_html(implode("\n\n",$lines))) . '</p></div>'; }
    }
    public static function email_summary($order, $sent_to_admin, $plain_text, $email): void { self::summary($order,(bool)$plain_text); }
    public static function order_summary($order): void { self::summary($order,false); }
    public static function shortcode(): string
    {
        if (!self::active() || self::$rendered) { return ''; }
        self::$rendered = true;
        return self::html();
    }
    public static function fragments(array $fragments): array
    {
        if (self::active()) { $fragments['#ffla-delivery-choice'] = self::html(); }
        return $fragments;
    }
    public static function html(): string
    {
        $s = Pickup_Shipping_Settings::get();
        $packages = WC()->shipping()->get_packages();
        $regular = !($s['ffl_enabled'] && self::requires_ffl());
        foreach ($packages as $package) {
            $scope = self::scope($package);
            if ($scope === 'regular') { $regular = true; }
        }
        // FFL Checkout owns the dealer UI. Keep only an empty fragment anchor so
        // AJAX can restore the regular-item selector if the cart changes later.
        // Rate filtering and final validation remain independent of this markup.
        if (!$regular) { return '<div id="ffla-delivery-choice" hidden aria-hidden="true" style="display:none!important"></div>'; }
        $styles = Pickup_Shipping_Settings::styles($s);
        $mode = self::state()['mode'] ?? $s['default'];
        if ($s['delivery'] !== 'both') { $mode = $s['delivery']; }
        $available = WC()->session->get(self::AVAILABLE, []);
        ob_start(); ?>
        <section id="ffla-delivery-choice" class="ffla-delivery" style="<?php echo esc_attr($styles); ?>" aria-labelledby="ffla-delivery-title">
            <h3 id="ffla-delivery-title"><?php echo esc_html($s['title']); ?></h3>
            <?php if ($regular): ?>
            <div class="ffla-delivery__options" role="radiogroup" aria-labelledby="ffla-delivery-title">
                <?php foreach (['pickup','ship'] as $choice):
                    if ($s['delivery'] !== 'both' && $s['delivery'] !== $choice) { continue; }
                    $enabled = true;
                    foreach ($packages as $key=>$package) {
                        if (self::scope($package) === 'regular' && isset($available[$key][$choice]) && !$available[$key][$choice]) { $enabled = false; }
                    }
                    ?>
                    <label class="ffla-delivery__option">
                        <input type="radio" name="ffla_delivery_mode" value="<?php echo esc_attr($choice); ?>" <?php checked($mode,$choice); disabled(!$enabled); ?>>
                        <span class="ffla-delivery__card">
                            <strong><?php echo esc_html($s[$choice . '_title']); ?></strong>
                            <span><?php echo esc_html($s[$choice . '_description']); ?></span>
                            <?php if (!$enabled): ?><small><?php esc_html_e('Unavailable for the current address or package.', 'ffl-funnels-addons'); ?></small><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($mode === 'pickup' && $s['store_address']): ?><p class="ffla-delivery__details"><?php echo nl2br(esc_html(trim($s['store_name'] . "\n" . $s['store_address'] . "\n" . $s['instructions']))); ?></p><?php endif; ?>
            <?php endif; ?>
            <p class="ffla-delivery__status" aria-live="polite"></p>
        </section>
        <?php return ob_get_clean();
    }
    public static function assets(): void
    {
        if (!self::active()) { return; }
        $base = FFLA_URL . 'modules/pickup-shipping/assets/';
        wp_enqueue_style('ffla-delivery',$base . 'delivery.css',[],FFLA_VERSION . '.4');
        wp_enqueue_script('ffla-delivery',$base . 'delivery.js',['jquery','wc-checkout'],FFLA_VERSION . '.4',true);
        wp_localize_script('ffla-delivery','fflaDelivery',[
            'updating'=>__('Updating delivery options…','ffl-funnels-addons'),
            'error'=>__('Delivery could not be updated. Please try again before placing your order.','ffl-funnels-addons'),
        ]);
    }
    public static function notice(): void
    {
        if (!current_user_can('manage_woocommerce')) { return; }
        $message = '';
        if (self::blocks()) { $message = __('Pickup & Shipping supports classic checkout and its shortcode only. Checkout Blocks are unchanged.', 'ffl-funnels-addons'); }
        elseif (function_exists('cgs_filter_ffl_shipping_rates')) { $message = __('Pickup & Shipping is paused: disable the old Camarillo pickup/shipping snippet before using this module. Keep any separate tax code.', 'ffl-funnels-addons'); }
        elseif (!Pickup_Shipping_Settings::configured(Pickup_Shipping_Settings::get())) { $message = __('Pickup & Shipping needs its delivery methods and optional FFL integration configured. Checkout remains unchanged until configuration is complete.', 'ffl-funnels-addons'); }
        if ($message) { echo '<div class="notice notice-warning"><p>' . esc_html($message) . ' <a href="' . esc_url(admin_url('admin.php?page=ffla-pickup-shipping')) . '">' . esc_html__('Settings','ffl-funnels-addons') . '</a></p></div>'; }
    }
}
