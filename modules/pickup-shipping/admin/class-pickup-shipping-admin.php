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
        wp_enqueue_style('ffla-delivery-admin',$base . 'admin.css',[],FFLA_VERSION . '.1');
        wp_enqueue_style('ffla-delivery-preview',$base . 'delivery.css',[],FFLA_VERSION . '.1');
        wp_enqueue_script('ffla-delivery-admin',$base . 'admin.js',[],FFLA_VERSION . '.2',true);
    }
    public static function save(): void
    {
        if (!current_user_can('manage_woocommerce')) { wp_die(esc_html__('Permission denied.','ffl-funnels-addons')); return; }
        check_admin_referer('ffla_pickup_shipping_save');
        $input = isset($_POST['ps']) && is_array($_POST['ps']) ? wp_unslash($_POST['ps']) : [];
        $settings = Pickup_Shipping_Settings::sanitize($input,Pickup_Shipping_Settings::methods());
        // Surface rejected/removed methods or malformed FFL rows, never quietly
        // claim a fully usable configuration after dropping invalid input.
        $invalid = count(array_filter((array)($input['locations'] ?? []),static function($r){return is_array($r) && (!empty($r['license']) || !empty($r['name']));})) !== count($settings['locations']);
        update_option(Pickup_Shipping_Settings::OPTION,$settings,false);
        wp_safe_redirect(add_query_arg(['page'=>'ffla-pickup-shipping','saved'=>$invalid ? 'review' : '1'],admin_url('admin.php')));
        exit;
    }
    private static function field(string $key, string $label, array $s, bool $area = false): void
    {
        echo '<label class="ffla-ps-field"><span>' . esc_html($label) . '</span>';
        if ($area) { echo '<textarea rows="3" name="ps[' . esc_attr($key) . ']">' . esc_textarea($s[$key]) . '</textarea>'; }
        else { echo '<input type="text" name="ps[' . esc_attr($key) . ']" value="' . esc_attr($s[$key]) . '">'; }
        echo '</label>';
    }
    private static function select(string $key, string $label, array $values, array $s): void
    {
        echo '<label class="ffla-ps-field"><span>' . esc_html($label) . '</span><select name="ps[' . esc_attr($key) . ']">';
        foreach ($values as $value=>$text) { echo '<option value="' . esc_attr($value) . '" ' . selected($s[$key],$value,false) . '>' . esc_html($text) . '</option>'; }
        echo '</select></label>';
    }
    public static function location_row($i, array $row, array $catalog): void
    {
        echo '<div class="ffla-ps-location">';
        foreach (['name'=>__('Location name','ffl-funnels-addons'),'license'=>__('FFL license number','ffl-funnels-addons'),'address'=>__('Pickup address','ffl-funnels-addons'),'instructions'=>__('Pickup instructions','ffl-funnels-addons')] as $key=>$label) {
            echo '<label class="ffla-ps-field"><span>' . esc_html($label) . '</span><input name="ps[locations][' . esc_attr((string)$i) . '][' . esc_attr($key) . ']" value="' . esc_attr($row[$key] ?? '') . '"></label>';
        }
        echo '<label class="ffla-ps-field"><span>' . esc_html__('Pickup method','ffl-funnels-addons') . '</span><select name="ps[locations][' . esc_attr((string)$i) . '][method]"><option value="">' . esc_html__('Select a pickup method','ffl-funnels-addons') . '</option>';
        foreach ($catalog as $id=>$method) {
            if ($method['pickup']) { echo '<option value="' . esc_attr($id) . '" ' . selected($row['method'] ?? '',$id,false) . '>' . esc_html($method['label']) . '</option>'; }
        }
        echo '</select></label><button type="button" class="button ffla-ps-remove">' . esc_html__('Remove location','ffl-funnels-addons') . '</button></div>';
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
            <?php if (isset($_GET['saved'])): ?><p class="ffla-ps-alert" role="status"><?php echo esc_html($_GET['saved'] === 'review' ? __('Settings saved, but some FFL rows were invalid, duplicated or not linked to a selected pickup method. Review the list below.','ffl-funnels-addons') : __('Settings saved.','ffl-funnels-addons')); ?></p><?php endif; ?>
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
                    <p><?php esc_html_e('Requires g-FFL Checkout. Our FFL → its pickup method. Other FFL → shipping only. Dealer names and browser cookies never authorize local pickup. License verification remains the responsibility of your FFL provider.','ffl-funnels-addons'); ?></p>
                    <p><?php esc_html_e('Select each associated pickup method in General as well. This module does not change the provider’s local-pickup button configuration. Mixed carts must already have separate FFL/customer packages and destinations.','ffl-funnels-addons'); ?></p>
                    <div id="ffla-ps-locations"><?php foreach ($s['locations'] as $i=>$row) { self::location_row($i,$row,$catalog); } ?></div>
                    <button type="button" class="button" id="ffla-ps-add-location"><?php esc_html_e('Add store FFL','ffl-funnels-addons'); ?></button>
                    <template id="ffla-ps-location-template"><?php self::location_row('__INDEX__',[],$catalog); ?></template>
                </section>
                <section class="ffla-ps-panel" id="ps-panel-appearance" data-ps-panel="appearance">
                    <h3><?php esc_html_e('Text and appearance','ffl-funnels-addons'); ?></h3>
                    <div class="ffla-ps-grid">
                        <?php foreach (['title'=>__('Selector heading','ffl-funnels-addons'),'pickup_title'=>__('Pickup title','ffl-funnels-addons'),'pickup_description'=>__('Pickup description','ffl-funnels-addons'),'ship_title'=>__('Shipping title','ffl-funnels-addons'),'ship_description'=>__('Shipping description','ffl-funnels-addons')] as $key=>$label) { self::field($key,$label,$s); } ?>
                        <?php foreach (['accent'=>__('Accent color','ffl-funnels-addons'),'background'=>__('Background color','ffl-funnels-addons'),'text_color'=>__('Text color','ffl-funnels-addons')] as $key=>$label) { self::field($key,$label . ' — ' . __('HEX or CSS variable','ffl-funnels-addons'),$s); } ?>
                    </div>
                    <p><?php esc_html_e('Colors accept #2271b1, var(--primary), --primary, or var(--primary, #2271b1) with a fallback. Site variables update automatically with your theme. Variables loaded only on the storefront will not resolve in this admin preview; use a fallback to preview them here.','ffl-funnels-addons'); ?></p>
                    <p><?php esc_html_e('Leave colors blank to inherit the site style. Prices are not inferred from descriptions: do not promise free pickup unless your WooCommerce rate is free.','ffl-funnels-addons'); ?></p>
                    <div class="ffla-ps-preview-tools">
                        <strong><?php esc_html_e('Preview','ffl-funnels-addons'); ?></strong>
                        <button type="button" class="button" data-preview-width="desktop" aria-pressed="true"><?php esc_html_e('Desktop','ffl-funnels-addons'); ?></button>
                        <button type="button" class="button" data-preview-width="mobile" aria-pressed="false"><?php esc_html_e('Mobile','ffl-funnels-addons'); ?></button>
                    </div>
                    <div class="ffla-delivery" id="ffla-ps-preview">
                        <h3 data-preview-text="title"><?php echo esc_html($s['title']); ?></h3>
                        <div class="ffla-delivery__options">
                            <?php foreach (['pickup','ship'] as $choice): ?>
                            <label class="ffla-delivery__option"><input type="radio" name="ps_preview" <?php checked($choice,'pickup'); ?>><span class="ffla-delivery__card"><strong data-preview-text="<?php echo esc_attr($choice . '_title'); ?>"><?php echo esc_html($s[$choice . '_title']); ?></strong><span data-preview-text="<?php echo esc_attr($choice . '_description'); ?>"><?php echo esc_html($s[$choice . '_description']); ?></span></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
                <div class="ffla-ps-save"><button type="submit" class="button button-primary"><?php esc_html_e('Save settings','ffl-funnels-addons'); ?></button></div>
                <noscript><p><?php esc_html_e('All settings sections are shown. JavaScript enables tabs, preview updates and adding new FFL rows.','ffl-funnels-addons'); ?></p></noscript>
            </form>
        </div>
        <?php
    }
}
