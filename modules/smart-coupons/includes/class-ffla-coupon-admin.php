<?php
/**
 * Smart Coupons — admin: settings page, the coupon "Smart Coupons" tab and
 * the product "Minimum price (MAP)" field.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Coupon_Admin
{
    const FLASH = 'ffla_cpn_flash_';

    public static function boot(): void
    {
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets'], 20); // After WooCommerce registers its scripts.
        add_action('admin_post_ffla_cpn_settings', [__CLASS__, 'save_settings']);
        add_filter('woocommerce_coupon_data_tabs', [__CLASS__, 'tab']);
        add_action('woocommerce_coupon_data_panels', [__CLASS__, 'panel'], 10, 2);
        add_action('woocommerce_coupon_options_save', [__CLASS__, 'save_coupon'], 10, 2);
        add_action('woocommerce_product_options_pricing', [__CLASS__, 'map_field']);
        add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_map']);
        add_action('woocommerce_variation_options_pricing', [__CLASS__, 'variation_map_field'], 10, 3);
        add_action('woocommerce_save_product_variation', [__CLASS__, 'save_variation_map'], 10, 2);
    }

    public static function assets(): void
    {
        $screen = get_current_screen();
        $id = $screen ? $screen->id : '';
        if (!in_array($id, ['shop_coupon', 'edit-shop_coupon', 'edit-' . FFLA_Coupon_Categories::TAX], true) && false === strpos($id, 'ffla-coupons')) {
            return;
        }
        $dir = dirname(__DIR__) . '/assets/';
        $url = FFLA_URL . 'modules/smart-coupons/assets/';
        wp_enqueue_style('ffla-coupons-admin', $url . 'coupons-admin.css', [], (string) @filemtime($dir . 'coupons-admin.css')); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        $deps = ['jquery'];
        if (wp_script_is('wc-enhanced-select', 'registered')) {
            // Searchable multi-selects on the category and module screens too.
            $deps[] = 'wc-enhanced-select';
            wp_enqueue_style('woocommerce_admin_styles');
        }
        wp_enqueue_script('ffla-coupons-admin', $url . 'coupons-admin.js', $deps, (string) @filemtime($dir . 'coupons-admin.js'), true); // phpcs:ignore WordPress.PHP.NoSilencedErrors
    }

    /* ── Notices ───────────────────────────────────────────────────────── */

    public static function flash(string $type, string $message): void
    {
        set_transient(self::FLASH . get_current_user_id(), [$type, $message], 120);
    }

    public static function notices(): void
    {
        $flash = get_transient(self::FLASH . get_current_user_id());
        if (is_array($flash)) {
            delete_transient(self::FLASH . get_current_user_id());
            echo '<div class="notice notice-' . ('error' === $flash[0] ? 'error' : 'success') . ' inline ffla-cpn-notice"><p>' . esc_html($flash[1]) . '</p></div>';
        }
    }

    /* ── Module settings ───────────────────────────────────────────────── */

    public static function settings_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $s = FFLA_Coupon_Settings::get();
        self::notices();
        $check = static function (string $key, string $label, string $help) use ($s): string {
            return '<p><label><input type="checkbox" name="s[' . esc_attr($key) . ']" value="1"' . checked(!empty($s[$key]), true, false) . '> <strong>' . esc_html($label) . '</strong></label><br><span class="description">' . esc_html($help) . '</span></p>';
        };

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ffla-cpn-form"><input type="hidden" name="action" value="ffla_cpn_settings">';
        wp_nonce_field('ffla_cpn_settings');

        echo '<div class="wb-card"><div class="wb-card__header"><h2>' . esc_html__('Guardrails', 'ffl-funnels-addons') . '</h2></div><div class="wb-card__body">';
        echo $check('protect_firearms', __('Never discount firearms', 'ffl-funnels-addons'), __('Products marked as firearms (the same “firearm product” flag the FFL checkout uses) are left out of every coupon unless the coupon, or its category, allows them. Store credit is never limited.', 'ffl-funnels-addons')); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<p><label for="ffla-cpn-protected"><strong>' . esc_html__('Also never discount', 'ffl-funnels-addons') . '</strong></label><br>' . self::term_select('s[protected_terms][]', 'ffla-cpn-protected', $s['protected_terms']) // phpcs:ignore WordPress.Security.EscapeOutput
            . '<br><span class="description">' . esc_html__('For example NFA items, FFL transfer fees, special orders. Categories include their subcategories.', 'ffl-funnels-addons') . '</span></p>';
        echo $check('enforce_map', __('Respect minimum (MAP) prices', 'ffl-funnels-addons'), __('Set “Minimum price (MAP)” on a product (Product data → General) and no coupon will take it below that price; the rest of the discount is dropped.', 'ffl-funnels-addons')); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</div></div>';

        echo '<div class="wb-card"><div class="wb-card__header"><h2>' . esc_html__('Applying coupons', 'ffl-funnels-addons') . '</h2></div><div class="wb-card__body">';
        echo $check('links', __('Coupon links', 'ffl-funnels-addons'), __('Any link with ?coupon=CODE applies the coupon — for emails, social posts and QR codes. Each coupon shows its link in the Smart Coupons tab.', 'ffl-funnels-addons')); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<p><label for="ffla-cpn-param">' . esc_html__('Link parameter', 'ffl-funnels-addons') . '</label> <input type="text" id="ffla-cpn-param" name="s[link_param]" value="' . esc_attr($s['link_param']) . '" class="regular-text" style="max-width:220px"></p>';
        echo $check('best_wins', __('Best discount wins', 'ffl-funnels-addons'), __('When a customer adds a coupon that cannot be combined with one already applied, keep whichever gives the bigger discount instead of showing an error.', 'ffl-funnels-addons')); // phpcs:ignore WordPress.Security.EscapeOutput
        echo $check('throttle', __('Block coupon guessing', 'ffl-funnels-addons'), __('After too many unknown codes from one visitor in 10 minutes, every coupon attempt is refused for 10 minutes with the same message, so codes cannot be discovered by trial and error.', 'ffl-funnels-addons')); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<p><label for="ffla-cpn-throttle">' . esc_html__('Unknown codes allowed per 10 minutes', 'ffl-funnels-addons') . '</label> <input type="number" id="ffla-cpn-throttle" name="s[throttle_max]" min="3" max="100" value="' . esc_attr((string) $s['throttle_max']) . '" class="small-text"></p>';
        echo '</div></div>';

        echo '<div class="wb-card"><div class="wb-card__header"><h2>' . esc_html__('Store credit', 'ffl-funnels-addons') . '</h2></div><div class="wb-card__body">';
        echo '<p><label for="ffla-cpn-prefix">' . esc_html__('Code prefix', 'ffl-funnels-addons') . '</label> <input type="text" id="ffla-cpn-prefix" name="s[credit_prefix]" value="' . esc_attr($s['credit_prefix']) . '" class="regular-text" style="max-width:220px" maxlength="12"></p>';
        echo '<p><label for="ffla-cpn-days">' . esc_html__('Default validity (days)', 'ffl-funnels-addons') . '</label> <input type="number" id="ffla-cpn-days" name="s[credit_days]" min="1" max="3650" value="' . esc_attr((string) $s['credit_days']) . '" class="small-text"></p>';
        echo $check('credit_notice', __('Remind customers of their credit', 'ffl-funnels-addons'), __('Signed-in customers with store credit see their balance and an “Apply it” button in the cart and checkout.', 'ffl-funnels-addons')); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '</div></div>';

        echo '<p><button type="submit" class="wb-btn wb-btn--primary button button-primary">' . esc_html__('Save settings', 'ffl-funnels-addons') . '</button> '
            . '<a class="button" href="' . esc_url(admin_url('edit-tags.php?taxonomy=' . FFLA_Coupon_Categories::TAX . '&post_type=shop_coupon')) . '">' . esc_html__('Coupon categories', 'ffl-funnels-addons') . '</a> '
            . '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=shop_coupon')) . '">' . esc_html__('All coupons', 'ffl-funnels-addons') . '</a></p></form>';
    }

    private static function term_select(string $name, string $id, array $selected): string
    {
        $html = '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '" multiple class="wc-enhanced-select" style="min-width:420px">';
        foreach (['product_cat' => __('Categories', 'ffl-funnels-addons'), 'product_tag' => __('Tags', 'ffl-funnels-addons')] as $taxonomy => $label) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 1000, 'orderby' => 'name']);
            if (is_wp_error($terms) || !$terms) {
                continue;
            }
            $html .= '<optgroup label="' . esc_attr($label) . '">';
            foreach ($terms as $term) {
                $value = $taxonomy . ':' . $term->term_id;
                $html .= '<option value="' . esc_attr($value) . '"' . selected(in_array($value, $selected, true), true, false) . '>' . esc_html($term->name) . '</option>';
            }
            $html .= '</optgroup>';
        }
        return $html . '</select>';
    }

    public static function save_settings(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        check_admin_referer('ffla_cpn_settings');
        update_option(FFLA_Coupon_Settings::OPTION, FFLA_Coupon_Settings::sanitize((array) wp_unslash($_POST['s'] ?? [])), false);
        self::flash('success', __('Settings saved.', 'ffl-funnels-addons'));
        wp_safe_redirect(admin_url('admin.php?page=' . Smart_Coupons_Module::PAGE_SETTINGS));
        exit;
    }

    /* ── Coupon tab ────────────────────────────────────────────────────── */

    public static function tab($tabs)
    {
        $tabs['ffla_smart'] = ['label' => __('Smart Coupons', 'ffl-funnels-addons'), 'target' => 'ffla_coupon_data', 'class' => ''];
        return $tabs;
    }

    private static function select(string $key, array $options, $selected, bool $multiple = false, string $class = 'wc-enhanced-select'): string
    {
        $html = '<select id="ffla_' . esc_attr($key) . '" name="ffla[' . esc_attr($key) . ']' . ($multiple ? '[]' : '') . '"' . ($multiple ? ' multiple' : '') . ' class="' . esc_attr($class) . '" style="width:50%">';
        foreach ($options as $value => $label) {
            $on = $multiple ? in_array((string) $value, array_map('strval', (array) $selected), true) : (string) $value === (string) $selected;
            $html .= '<option value="' . esc_attr((string) $value) . '"' . selected($on, true, false) . '>' . esc_html($label) . '</option>';
        }
        return $html . '</select>';
    }

    private static function product_select(string $key, array $ids, bool $multiple = true): string
    {
        $html = '<select id="ffla_' . esc_attr($key) . '" name="ffla[' . esc_attr($key) . ']' . ($multiple ? '[]' : '') . '"' . ($multiple ? ' multiple' : '') . ' class="wc-product-search" style="width:50%" data-placeholder="' . esc_attr__('Search for a product…', 'ffl-funnels-addons') . '" data-action="woocommerce_json_search_products_and_variations"' . ($multiple ? '' : ' data-allow_clear="true"') . '>';
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if ($product) {
                $html .= '<option value="' . (int) $id . '" selected>' . esc_html(wp_strip_all_tags($product->get_formatted_name())) . '</option>';
            }
        }
        return $html . '</select>';
    }

    private static function row(string $label, string $control, string $help = '', string $class = ''): void
    {
        echo '<p class="form-field ' . esc_attr($class) . '"><label>' . esc_html($label) . '</label>' . $control // phpcs:ignore WordPress.Security.EscapeOutput
            . ('' !== $help ? '<span class="description" style="display:block;clear:both;margin-left:150px">' . esc_html($help) . '</span>' : '') . '</p>';
    }

    public static function panel($coupon_id, $coupon = null): void
    {
        $coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon((int) $coupon_id);
        $o = FFLA_Coupon_Settings::coupon($coupon);
        $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1000, 'orderby' => 'name']);
        $cats = is_wp_error($cats) ? [] : wp_list_pluck($cats, 'name', 'term_id');
        $roles = array_merge(['guest' => __('Guests (not signed in)', 'ffl-funnels-addons')], wp_roles()->get_names());
        $gateways = [];
        foreach (WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [] as $gid => $gateway) {
            $gateways[$gid] = wp_strip_all_tags($gateway->get_title()) ?: $gid;
        }
        $num = static function (string $key, $value, string $step = '1', string $min = '0'): string {
            return '<input type="number" class="short" id="ffla_' . esc_attr($key) . '" name="ffla[' . esc_attr($key) . ']" value="' . esc_attr((string) $value) . '" step="' . esc_attr($step) . '" min="' . esc_attr($min) . '">';
        };

        echo '<div id="ffla_coupon_data" class="panel woocommerce_options_panel ffla-cpn-panel">';
        wp_nonce_field('ffla_coupon_save', '_ffla_coupon_nonce');

        echo '<div class="options_group"><h4>' . esc_html__('Guardrails', 'ffl-funnels-addons') . '</h4>';
        self::row(__('Firearms & protected', 'ffl-funnels-addons'), '<input type="checkbox" name="ffla[allow_protected]" value="1"' . checked($o['allow_protected'], true, false) . '> ' . esc_html__('This coupon may discount firearms and protected items', 'ffl-funnels-addons'), __('Only if your manufacturer and distributor agreements allow it. MAP prices still apply.', 'ffl-funnels-addons'));
        self::row(__('Maximum discount', 'ffl-funnels-addons'), $num('max_discount', $o['max_discount'] ?: '', '0.01'), __('Caps the total this coupon takes off, e.g. 20% off up to $100. Empty = no cap.', 'ffl-funnels-addons'));
        echo '</div>';

        echo '<div class="options_group"><h4>' . esc_html__('When it works', 'ffl-funnels-addons') . '</h4>';
        self::row(__('Starts on', 'ffl-funnels-addons'), '<input type="date" class="short" name="ffla[starts]" value="' . esc_attr($o['starts']) . '">', __('Not usable before this date (store time). Set the end in General → Coupon expiry date.', 'ffl-funnels-addons'));
        self::row(__('First order only', 'ffl-funnels-addons'), '<input type="checkbox" name="ffla[first_order]" value="1"' . checked($o['first_order'], true, false) . '> ' . esc_html__('Only for customers with no previous paid order (by account or email)', 'ffl-funnels-addons'));
        self::row(__('Only for', 'ffl-funnels-addons'), self::select('roles', $roles, $o['roles'], true), __('Customer roles, e.g. members or employees. Empty = everyone.', 'ffl-funnels-addons'));
        self::row(__('Uses per person', 'ffl-funnels-addons'), $num('per_customer', $o['per_customer'] ?: ''), __('Counted by email (Gmail dots and +tags ignored), phone and shipping address — not just the account, so new accounts do not get it again.', 'ffl-funnels-addons'));
        self::row(__('Minimum quantity', 'ffl-funnels-addons'), $num('min_qty', $o['min_qty'] ?: ''), __('Items the cart must hold, e.g. at least 3 boxes of ammunition. Empty = no minimum.', 'ffl-funnels-addons'));
        self::row(__('Counted from', 'ffl-funnels-addons'), self::select('qty_cats', $cats, $o['qty_cats'], true), __('Only items in these product categories count toward the minimum. Empty = any item.', 'ffl-funnels-addons'));
        self::row(__('Delivery', 'ffl-funnels-addons'), self::select('delivery', ['' => __('Any', 'ffl-funnels-addons'), 'pickup' => __('In-store pickup only', 'ffl-funnels-addons'), 'shipping' => __('Shipped orders only', 'ffl-funnels-addons')], $o['delivery'], false, ''));
        self::row(__('States', 'ffl-funnels-addons'), '<input type="text" class="short" name="ffla[states]" value="' . esc_attr(implode(', ', $o['states'])) . '" placeholder="FL, GA">', __('Two-letter codes of the shipping (or billing) state. Empty = all states.', 'ffl-funnels-addons'));
        self::row(__('Payment methods', 'ffl-funnels-addons'), self::select('payments', $gateways, $o['payments'], true), __('E.g. a cash / ACH discount. Checked again when the order is placed.', 'ffl-funnels-addons'));
        echo '</div>';

        echo '<div class="options_group"><h4>' . esc_html__('Combining', 'ffl-funnels-addons') . '</h4>';
        self::row(__('Combine with', 'ffl-funnels-addons'), self::select('stack', ['any' => __('Any other coupon', 'ffl-funnels-addons'), 'none' => __('No other coupons', 'ffl-funnels-addons'), 'only' => __('Only the coupons listed', 'ffl-funnels-addons')], $o['stack'], false, ''));
        self::row(__('Allowed coupons', 'ffl-funnels-addons'), '<input type="text" class="short" name="ffla[stack_codes]" value="' . esc_attr(strtoupper(implode(', ', $o['stack_codes']))) . '" placeholder="VIP10, FREESHIP">', __('Used with “Only the coupons listed”. Store credit always combines. Category rules also apply.', 'ffl-funnels-addons'));
        echo '</div>';

        echo '<div class="options_group ffla-show-tiered"><h4>' . esc_html__('Spend tiers', 'ffl-funnels-addons') . '</h4>';
        self::row(__('Tiers', 'ffl-funnels-addons'), '<textarea name="ffla[tiers]" rows="4" style="width:50%" placeholder="500=25&#10;1000=75&#10;2000=10%">' . esc_textarea(FFLA_Coupon_Settings::tiers_text($o['tiers'])) . '</textarea>', __('One per line: spend=discount. “500=25” is $25 off from $500; “2000=10%” is 10% off from $2,000. The highest tier reached applies, on qualifying items.', 'ffl-funnels-addons'));
        echo '</div>';

        echo '<div class="options_group ffla-show-bxgy"><h4>' . esc_html__('Buy X get Y', 'ffl-funnels-addons') . '</h4>';
        self::row(__('Buy quantity', 'ffl-funnels-addons'), $num('buy_qty', $o['buy_qty'], '1', '1'), __('How many qualifying items the customer buys, e.g. 2.', 'ffl-funnels-addons'));
        self::row(__('Buy from categories', 'ffl-funnels-addons'), self::select('buy_cats', $cats, $o['buy_cats'], true));
        self::row(__('…or products', 'ffl-funnels-addons'), self::product_select('buy_products', $o['buy_products']), __('Leave both empty for any qualifying item.', 'ffl-funnels-addons'));
        self::row(__('Get quantity', 'ffl-funnels-addons'), $num('get_qty', $o['get_qty'], '1', '1'), __('How many items get the discount, e.g. 1.', 'ffl-funnels-addons'));
        self::row(__('Get discount (%)', 'ffl-funnels-addons'), $num('get_pct', $o['get_pct'], '1', '1'), __('100 = free, 50 = half price.', 'ffl-funnels-addons'));
        self::row(__('Get from categories', 'ffl-funnels-addons'), self::select('get_cats', $cats, $o['get_cats'], true));
        self::row(__('…or products', 'ffl-funnels-addons'), self::product_select('get_products', $o['get_products']), __('Leave both empty to use the “buy” items (e.g. buy 2 boxes, get the 3rd free). The cheapest qualifying units are discounted.', 'ffl-funnels-addons'));
        self::row(__('Repeat', 'ffl-funnels-addons'), '<input type="checkbox" name="ffla[repeat]" value="1"' . checked($o['repeat'], true, false) . '> ' . esc_html__('Apply again for every X bought', 'ffl-funnels-addons'));
        echo '</div>';

        echo '<div class="options_group"><h4>' . esc_html__('Free gift', 'ffl-funnels-addons') . '</h4>';
        self::row(__('Gift product', 'ffl-funnels-addons'), self::product_select('gift_product', $o['gift_product'] ? [$o['gift_product']] : [], false), __('Added to the cart for free while the coupon is applied (not firearms). Works with any discount type; use a $0 amount for a gift-only coupon.', 'ffl-funnels-addons'));
        self::row(__('Gift quantity', 'ffl-funnels-addons'), $num('gift_qty', $o['gift_qty'], '1', '1'));
        echo '</div>';

        if (FFLA_Coupon_Settings::value('links') && $coupon->get_code()) {
            $link = FFLA_Coupon_Codes::link_url($coupon->get_code());
            echo '<div class="options_group"><h4>' . esc_html__('Coupon link', 'ffl-funnels-addons') . '</h4>';
            self::row(__('Link', 'ffl-funnels-addons'), '<input type="text" readonly value="' . esc_attr($link) . '" style="width:50%" onclick="this.select()"> <button type="button" class="button" data-ffla-copy="' . esc_attr($link) . '">' . esc_html__('Copy', 'ffl-funnels-addons') . '</button>', __('Opens the store with this coupon applied (or applied when the first product is added).', 'ffl-funnels-addons'));
            echo '</div>';
        }
        echo '</div>';
    }

    public static function save_coupon($post_id, $coupon = null): void
    {
        if (!isset($_POST['_ffla_coupon_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ffla_coupon_nonce'])), 'ffla_coupon_save')) {
            return;
        }
        $coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon((int) $post_id);
        $options = FFLA_Coupon_Settings::sanitize_coupon((array) wp_unslash($_POST['ffla'] ?? []));
        if ($options['gift_product'] && FFLA_Coupon_Settings::product_is_firearm(wc_get_product($options['gift_product']))) {
            $options['gift_product'] = 0; // A firearm is never a free gift.
        }
        $coupon->update_meta_data(FFLA_Coupon_Settings::META, $options);
        $coupon->save_meta_data();
    }

    /* ── MAP price on products ─────────────────────────────────────────── */

    public static function map_field(): void
    {
        woocommerce_wp_text_input([
            'id'          => '_ffla_map_price',
            'label'       => __('Minimum price (MAP)', 'ffl-funnels-addons') . ' (' . get_woocommerce_currency_symbol() . ')',
            'data_type'   => 'price',
            'desc_tip'    => true,
            'description' => __('Coupons never take this product below this price. Leave empty for no minimum.', 'ffl-funnels-addons'),
        ]);
    }

    public static function save_map($product): void
    {
        if (isset($_POST['_ffla_map_price'])) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the product form.
            $value = wc_format_decimal(sanitize_text_field(wp_unslash($_POST['_ffla_map_price']))); // phpcs:ignore WordPress.Security.NonceVerification
            '' === $value ? $product->delete_meta_data('_ffla_map_price') : $product->update_meta_data('_ffla_map_price', $value);
        }
    }

    public static function variation_map_field($loop, $variation_data, $variation): void
    {
        woocommerce_wp_text_input([
            'id'            => '_ffla_map_price_' . $loop,
            'name'          => '_ffla_map_price[' . $loop . ']',
            'value'         => wc_format_localized_price(get_post_meta($variation->ID, '_ffla_map_price', true)),
            'label'         => __('Minimum price (MAP)', 'ffl-funnels-addons') . ' (' . get_woocommerce_currency_symbol() . ')',
            'data_type'     => 'price',
            'wrapper_class' => 'form-row form-row-first',
        ]);
    }

    public static function save_variation_map($variation_id, $i): void
    {
        if (isset($_POST['_ffla_map_price'][$i])) { // phpcs:ignore WordPress.Security.NonceVerification
            $value = wc_format_decimal(sanitize_text_field(wp_unslash($_POST['_ffla_map_price'][$i]))); // phpcs:ignore WordPress.Security.NonceVerification
            '' === $value ? delete_post_meta($variation_id, '_ffla_map_price') : update_post_meta($variation_id, '_ffla_map_price', $value);
        }
    }
}
