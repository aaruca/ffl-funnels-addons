<?php
defined('ABSPATH') || exit;
class Pickup_Shipping_Admin
{
    public static function init(): void
    {
        add_action('admin_post_ffla_pickup_shipping_save',[__CLASS__,'save']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
    }
    public static function assets(): void
    {
        if (($_GET['page'] ?? '') !== 'ffla-pickup-shipping') { return; }
        $base = FFLA_URL . 'modules/pickup-shipping/assets/';
        wp_enqueue_style('ffla-delivery-admin',$base . 'admin.css',[],FFLA_VERSION . '.4');
        wp_enqueue_style('ffla-delivery-preview',$base . 'delivery.css',[],FFLA_VERSION . '.4');
        wp_enqueue_script('ffla-delivery-admin',$base . 'admin.js',[],FFLA_VERSION . '.4',true);
    }
    public static function save(): void
    {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('Permission denied.','ffl-funnels-addons')); return; }
        check_admin_referer('ffla_pickup_shipping_save');
        $input = isset($_POST['ps']) && is_array($_POST['ps']) ? wp_unslash($_POST['ps']) : [];
        $settings = Pickup_Shipping_Settings::sanitize($input,Pickup_Shipping_Settings::methods());
        // Retain legacy mappings for rollback only. They no longer authorize pickup.
        $previous = Pickup_Shipping_Settings::get();
        $settings['locations'] = $previous['locations'];
        update_option(Pickup_Shipping_Settings::OPTION,$settings,false);
        wp_safe_redirect(add_query_arg(['page'=>'ffla-pickup-shipping','saved'=>'1'],admin_url('admin.php')));
        exit;
    }
    private static function field(string $key, string $label, array $s, bool $area = false): void
    {
        echo '<label class="ffla-ps-field"><span>' . esc_html($label) . '</span>';
        if ($area) { echo '<textarea rows="3" name="ps[' . esc_attr($key) . ']">' . esc_textarea($s[$key]) . '</textarea>'; }
        else {
            $field = Pickup_Shipping_Settings::appearance_fields()[$key] ?? null;
            $attrs = $field ? ' maxlength="256" data-ps-css="' . esc_attr($field['property']) . '" data-ps-type="' . esc_attr($field['type']) . '" placeholder="' . esc_attr($field['example']) . '" aria-describedby="ffla-ps-style-help"' : '';
            echo '<input type="text" name="ps[' . esc_attr($key) . ']" value="' . esc_attr($s[$key]) . '"' . $attrs . '>';
        }
        echo '</label>';
    }
    private static function select(string $key, string $label, array $values, array $s): void
    {
        echo '<label class="ffla-ps-field"><span>' . esc_html($label) . '</span><select name="ps[' . esc_attr($key) . ']">';
        foreach ($values as $value=>$text) { echo '<option value="' . esc_attr($value) . '" ' . selected($s[$key],$value,false) . '>' . esc_html($text) . '</option>'; }
        echo '</select></label>';
    }
    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) { return; }
        $s = Pickup_Shipping_Settings::get();
        $catalog = Pickup_Shipping_Settings::methods();
        ?>
        <div class="ffla-ps-admin">
            <h2><?php esc_html_e('Pickup & Shipping','ffl-funnels-addons'); ?></h2>
            <p><?php esc_html_e('Configure how customers receive their orders. WooCommerce remains responsible for prices, addresses and taxes.','ffl-funnels-addons'); ?></p>
            <?php if (isset($_GET['saved'])): ?><p class="ffla-ps-alert" role="status"><?php esc_html_e('Settings saved.','ffl-funnels-addons'); ?></p><?php endif; ?>
            <?php if (!Pickup_Shipping_Settings::configured($s)): ?><p class="ffla-ps-alert"><?php esc_html_e('Setup incomplete. Checkout is unchanged until required methods and FFL settings are configured.','ffl-funnels-addons'); ?></p><?php endif; ?>
            <?php foreach (array_merge($s['pickup_methods'],$s['shipping_methods']) as $id): if (!isset($catalog[$id])): ?>
                <p class="ffla-ps-alert"><?php echo esc_html(sprintf(__('A selected method is disabled or missing: %s. Review shipping settings.','ffl-funnels-addons'),$id)); ?></p>
            <?php endif; endforeach; ?>
            <p><a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')); ?>"><?php esc_html_e('Manage WooCommerce shipping zones and prices →','ffl-funnels-addons'); ?></a></p>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" id="ffla-ps-form">
                <input type="hidden" name="action" value="ffla_pickup_shipping_save">
                <?php wp_nonce_field('ffla_pickup_shipping_save'); ?>
                <div class="ffla-ps-tabs" aria-label="<?php esc_attr_e('Delivery settings','ffl-funnels-addons'); ?>">
                    <?php foreach (['general'=>__('General','ffl-funnels-addons'),'ffl'=>__('FFL Integration','ffl-funnels-addons'),'appearance'=>__('Appearance & Text','ffl-funnels-addons')] as $tab=>$label): ?>
                    <button type="button" id="ps-tab-<?php echo esc_attr($tab); ?>" data-ps-tab="<?php echo esc_attr($tab); ?>"><?php echo esc_html($label); ?></button>
                    <?php endforeach; ?>
                </div>
                <section class="ffla-ps-panel" id="ps-panel-general" data-ps-panel="general">
                    <h3><?php esc_html_e('Delivery options','ffl-funnels-addons'); ?></h3>
                    <div class="ffla-ps-grid">
                        <?php self::select('delivery',__('Available delivery options','ffl-funnels-addons'),['both'=>__('Pickup & Shipping','ffl-funnels-addons'),'pickup'=>__('Pickup only','ffl-funnels-addons'),'ship'=>__('Shipping only','ffl-funnels-addons')],$s); ?>
                        <?php self::select('default',__('Default selection','ffl-funnels-addons'),['none'=>__('Ask the customer','ffl-funnels-addons'),'pickup'=>__('Pickup','ffl-funnels-addons'),'ship'=>__('Shipping','ffl-funnels-addons')],$s); ?>
                        <?php self::select('placement',__('Selector placement','ffl-funnels-addons'),['automatic'=>__('Before billing fields','ffl-funnels-addons'),'shortcode'=>__('Shortcode in a custom checkout','ffl-funnels-addons')],$s); ?>
                    </div>
                    <p><code>[ffla_delivery_choice]</code> — <?php esc_html_e('Place inside the classic checkout form. Choose shortcode placement to prevent automatic output.','ffl-funnels-addons'); ?></p>
                    <div class="ffla-ps-grid">
                        <?php foreach (['pickup_methods'=>__('Pickup methods','ffl-funnels-addons'),'shipping_methods'=>__('Shipping methods','ffl-funnels-addons')] as $key=>$label): ?>
                        <fieldset class="ffla-ps-methods"><legend><?php echo esc_html($label); ?></legend>
                            <label class="ffla-ps-field"><span><?php esc_html_e('Search methods or zones','ffl-funnels-addons'); ?></span><input type="search" data-method-search></label>
                            <div class="ffla-ps-method-list">
                            <?php foreach ($catalog as $id=>$method): if ($method['pickup'] !== ($key === 'pickup_methods')) { continue; } ?>
                                <label data-method-row><input type="checkbox" name="ps[<?php echo esc_attr($key); ?>][]" value="<?php echo esc_attr($id); ?>" <?php checked(in_array($id,$s[$key],true)); ?>> <?php echo esc_html($method['label']); ?></label>
                            <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <?php endforeach; ?>
                    </div>
                    <h3><?php esc_html_e('Pickup information','ffl-funnels-addons'); ?></h3>
                    <div class="ffla-ps-grid">
                        <?php self::field('store_name',__('Store name','ffl-funnels-addons'),$s); self::field('store_address',__('Pickup address','ffl-funnels-addons'),$s,true); self::field('instructions',__('Pickup instructions','ffl-funnels-addons'),$s,true); ?>
                    </div>
                </section>
                <section class="ffla-ps-panel" id="ps-panel-ffl" data-ps-panel="ffl">
                    <h3><?php esc_html_e('FFL delivery rules','ffl-funnels-addons'); ?></h3>
                    <label><input type="checkbox" name="ps[ffl_enabled]" value="1" <?php checked($s['ffl_enabled']); ?>> <?php esc_html_e('Enable FFL delivery rules','ffl-funnels-addons'); ?></label>
                    <p><?php esc_html_e('Local pickup comes directly from the Local Pickup FFL configured in FFL Checkout. Select it using the existing FFL Checkout selector: our addon allows pickup only. Select another FFL: shipping only. No duplicate dealer setup is needed here.','ffl-funnels-addons'); ?></p>
                    <div class="ffla-ps-alert" id="ffla-ps-provider-pickup">
                        <strong><?php esc_html_e('Local Pickup FFL — managed by FFL Checkout','ffl-funnels-addons'); ?></strong>
                        <?php if ($s['ffl_pickup_license'] !== ''): ?>
                            <p><code><?php echo esc_html($s['ffl_pickup_license']); ?></code></p>
                        <?php else: ?>
                            <p><?php esc_html_e('No valid Local Pickup FFL detected. Configure it in FFL Checkout to enable FFL pickup. Other selected dealers use shipping; saved legacy locations do not enable pickup.','ffl-funnels-addons'); ?></p>
                        <?php endif; ?>
                    </div>
                    <p><?php esc_html_e('Changes in FFL Checkout are read automatically. Select the corresponding WooCommerce pickup and shipping methods in General; their prices stay unchanged. Mixed carts must already have separate FFL/customer packages and destinations. Dealer names, cookies and old addon mappings never authorize pickup. FFL Checkout retains its own validation.','ffl-funnels-addons'); ?></p>
                    <p><?php esc_html_e('The store-search button starts the native FFL selection; pickup is applied only when a dealer is selected. After each change, the addon refreshes that cart\'s cached rates and synchronizes the available method with WooCommerce before totals. Each selected method must be enabled and available in the package\'s matching WooCommerce shipping zone. If pickup is missing, check the zone and method conditions; selecting an instance here does not make it available in other zones.','ffl-funnels-addons'); ?></p>
                </section>
                <section class="ffla-ps-panel" id="ps-panel-appearance" data-ps-panel="appearance">
                    <h3><?php esc_html_e('Text and appearance','ffl-funnels-addons'); ?></h3>
                    <div class="ffla-ps-grid">
                        <?php foreach (['title'=>__('Selector heading','ffl-funnels-addons'),'pickup_title'=>__('Pickup title','ffl-funnels-addons'),'pickup_description'=>__('Pickup description','ffl-funnels-addons'),'ship_title'=>__('Shipping title','ffl-funnels-addons'),'ship_description'=>__('Shipping description','ffl-funnels-addons')] as $key=>$label) { self::field($key,$label,$s); } ?>
                    </div>
                    <h3><?php esc_html_e('Colors, corners and spacing','ffl-funnels-addons'); ?></h3>
                    <div class="ffla-ps-grid">
                        <?php foreach (Pickup_Shipping_Settings::appearance_fields() as $key=>$field) { self::field($key,$field['label'],$s); } ?>
                    </div>
                    <p id="ffla-ps-style-help"><?php esc_html_e('Use HEX, transparent, currentColor or a full-color CSS variable such as var(--primary, #2271b1). Radius and gap accept 0, px, rem, em, % or variables such as var(--radius, 0px) and var(--space-m, 16px). Bare --variable names also work. Variables must exist on the storefront; theme variables not loaded in wp-admin only show their fallback in this preview.','ffl-funnels-addons'); ?></p>
                    <p data-ps-style-error hidden role="status"><?php esc_html_e('Some style values are invalid. Use the formats shown above; CSS declarations and URLs are not allowed.','ffl-funnels-addons'); ?></p>
                    <p><?php esc_html_e('Leave colors blank to inherit the site style. Prices are not inferred from descriptions: do not promise free pickup unless your WooCommerce rate is free.','ffl-funnels-addons'); ?></p>
                    <div class="ffla-ps-preview-tools">
                        <strong><?php esc_html_e('Preview','ffl-funnels-addons'); ?></strong>
                        <button type="button" class="button" data-preview-width="desktop" aria-pressed="true"><?php esc_html_e('Desktop','ffl-funnels-addons'); ?></button>
                        <button type="button" class="button" data-preview-width="mobile" aria-pressed="false"><?php esc_html_e('Mobile','ffl-funnels-addons'); ?></button>
                    </div>
                    <div class="ffla-delivery" id="ffla-ps-preview" style="<?php echo esc_attr(Pickup_Shipping_Settings::styles($s)); ?>">
                        <h3 data-preview-text="title"><?php echo esc_html($s['title']); ?></h3>
                        <div class="ffla-delivery__options">
                            <?php foreach (['pickup','ship'] as $choice): ?>
                            <label class="ffla-delivery__option"><input type="radio" name="ps_preview" <?php checked($choice,'pickup'); ?>><span class="ffla-delivery__card"><strong data-preview-text="<?php echo esc_attr($choice . '_title'); ?>"><?php echo esc_html($s[$choice . '_title']); ?></strong><span data-preview-text="<?php echo esc_attr($choice . '_description'); ?>"><?php echo esc_html($s[$choice . '_description']); ?></span></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
                <div class="ffla-ps-save"><button type="submit" class="button button-primary"><?php esc_html_e('Save settings','ffl-funnels-addons'); ?></button></div>
                <noscript><p><?php esc_html_e('All settings sections are shown. JavaScript enables tabs and live preview updates.','ffl-funnels-addons'); ?></p></noscript>
            </form>
        </div>
        <?php
    }
}
