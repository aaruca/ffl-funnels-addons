<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Cart
{
    const META_LOADOUT_ID      = '_ffla_loadout_id';
    const META_TIER_ID         = '_ffla_loadout_tier_id';
    const META_TIER_SLUG       = '_ffla_loadout_tier_slug'; // For custom tiers (tier_id=0)
    const META_SOURCE          = '_ffla_loadout_source';     // 'widget' or 'product_tab'
    const META_PRODUCT_LOADOUT = '_ffla_product_loadout_id'; // product tab parent product id
    const META_SET_DISCOUNT    = '_ffla_set_discount_flag';  // 'pending' or 'applied'
    const META_DISCOUNT_PCT    = '_ffla_loadout_discount_pct';
    const META_IS_BONUS        = '_ffla_loadout_is_bonus';
    const META_ITEM_ID         = '_ffla_loadout_item_id';

    // Bundle-style "Add Entire Tier" — one synthetic cart line containing
    // the anchor product + all tier items (like WooBooster Bundle).
    const META_TIER_BUNDLE       = '_ffla_tier_bundle';        // flag = 1
    const META_TIER_BUNDLE_ITEMS = '_ffla_tier_bundle_items';  // array of items
    const META_TIER_BUNDLE_TOTAL = '_ffla_tier_bundle_total';  // computed total
    const META_TIER_BUNDLE_HASH  = '_ffla_tier_bundle_hash';   // unique key

    public static function init(): void
    {
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'restore_cart_item_meta'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_loadout_pricing'], 20);
        add_action('woocommerce_cart_loaded_from_session', [__CLASS__, 'sync_bonus_items'], 10);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_cart_item_meta'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_order_line_item_meta'], 10, 4);
        add_action('woocommerce_cart_item_removed', [__CLASS__, 'on_cart_item_removed'], 10, 2);

        // AJAX handlers for frontend.
        add_action('wp_ajax_loadout_add_item', [__CLASS__, 'ajax_add_item']);
        add_action('wp_ajax_nopriv_loadout_add_item', [__CLASS__, 'ajax_add_item']);
        add_action('wp_ajax_loadout_add_tier', [__CLASS__, 'ajax_add_tier']);
        add_action('wp_ajax_nopriv_loadout_add_tier', [__CLASS__, 'ajax_add_tier']);
        add_action('wp_ajax_loadout_get_cart_summary', [__CLASS__, 'ajax_get_cart_summary']);
        add_action('wp_ajax_nopriv_loadout_get_cart_summary', [__CLASS__, 'ajax_get_cart_summary']);
    }

    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        $context = $_POST['loadout_context'] ?? '';
        if (!is_array($context)) {
            return $cart_item_data;
        }

        $loadout_id = isset($context['loadout_id']) ? absint($context['loadout_id']) : 0;
        $tier_id = isset($context['tier_id']) ? absint($context['tier_id']) : 0;
        $source = isset($context['source']) ? sanitize_key($context['source']) : '';
        $product_loadout = isset($context['product_loadout_id']) ? absint($context['product_loadout_id']) : 0;
        $discount_pct = isset($context['discount_pct']) ? floatval($context['discount_pct']) : 0;
        $item_id = isset($context['item_id']) ? absint($context['item_id']) : 0;
        $is_bonus = !empty($context['is_bonus']) ? 1 : 0;

        if (!$loadout_id && !$product_loadout) {
            return $cart_item_data;
        }

        $tier_slug = isset($context['tier_slug']) ? sanitize_title($context['tier_slug']) : '';

        $cart_item_data[self::META_LOADOUT_ID] = $loadout_id;
        $cart_item_data[self::META_TIER_ID] = $tier_id;
        $cart_item_data[self::META_TIER_SLUG] = $tier_slug;
        $cart_item_data[self::META_SOURCE] = $source ?: 'widget';
        $cart_item_data[self::META_PRODUCT_LOADOUT] = $product_loadout;
        $cart_item_data[self::META_DISCOUNT_PCT] = $discount_pct;
        $cart_item_data[self::META_ITEM_ID] = $item_id;
        $cart_item_data[self::META_IS_BONUS] = $is_bonus;

        // Bundle-line metadata (only set by ajax_add_tier).
        if (!empty($context['tier_bundle'])) {
            $cart_item_data[self::META_TIER_BUNDLE]       = 1;
            $cart_item_data[self::META_TIER_BUNDLE_ITEMS] = $context['bundle_items'] ?? [];
            $cart_item_data[self::META_TIER_BUNDLE_TOTAL] = $context['bundle_total'] ?? 0;
            $cart_item_data[self::META_TIER_BUNDLE_HASH]  = $context['bundle_hash'] ?? wp_generate_password(16, false, false);
            // Inseparable: hash is the unique key so it can never merge with another line.
            $cart_item_data['unique_key'] = $cart_item_data[self::META_TIER_BUNDLE_HASH];
            return $cart_item_data;
        }

        if ($source === 'product_tab') {
            $cart_item_data[self::META_SET_DISCOUNT] = 'pending';
        }

        // Make each loadout add a unique cart line so items don't merge.
        $cart_item_data['unique_key'] = md5(wp_json_encode([
            $loadout_id, $tier_id, $product_loadout, $item_id, $is_bonus, microtime()
        ]));

        return $cart_item_data;
    }

    public static function restore_cart_item_meta($cart_item, $values)
    {
        foreach ([
            self::META_LOADOUT_ID, self::META_TIER_ID, self::META_TIER_SLUG, self::META_SOURCE,
            self::META_PRODUCT_LOADOUT, self::META_SET_DISCOUNT, self::META_DISCOUNT_PCT,
            self::META_IS_BONUS, self::META_ITEM_ID,
            self::META_TIER_BUNDLE, self::META_TIER_BUNDLE_ITEMS,
            self::META_TIER_BUNDLE_TOTAL, self::META_TIER_BUNDLE_HASH,
        ] as $key) {
            if (isset($values[$key])) {
                $cart_item[$key] = $values[$key];
            }
        }
        return $cart_item;
    }

    public static function apply_loadout_pricing($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }

        // First pass: compute set discount eligibility per product tab loadout.
        $tab_groups = [];
        foreach ($cart->get_cart() as $cart_key => $item) {
            if (($item[self::META_SOURCE] ?? '') !== 'product_tab') {
                continue;
            }
            $product_loadout_id = (int) ($item[self::META_PRODUCT_LOADOUT] ?? 0);
            $tier_id = (int) ($item[self::META_TIER_ID] ?? 0);
            $tier_slug = (string) ($item[self::META_TIER_SLUG] ?? '');
            if (!$product_loadout_id || (!$tier_id && !$tier_slug)) {
                continue;
            }
            $key = $product_loadout_id . ':' . ($tier_id ?: $tier_slug);
            if (!isset($tab_groups[$key])) {
                $tab_groups[$key] = [
                    'cart_keys' => [],
                    'tier_id' => $tier_id,
                    'tier_slug' => $tier_slug,
                    'product_loadout_id' => $product_loadout_id,
                ];
            }
            $tab_groups[$key]['cart_keys'][] = $cart_key;
        }

        $set_discount_groups = [];
        foreach ($tab_groups as $key => $group) {
            $expected_product_ids = [];
            $set_pct = 0;

            if ($group['tier_id']) {
                $tier = Loadout_Tier::get($group['tier_id']);
                if (!$tier) {
                    continue;
                }
                $expected_items = $tier->get_items();
                $expected_product_ids = array_map(fn($i) => $i->get_product_id(), $expected_items);
                $set_pct = $tier->get_set_discount_pct();
            } else {
                // Custom tier: look up in product meta.
                $custom_json = get_post_meta($group['product_loadout_id'], Loadout_Product_Admin::META_CUSTOM_TIERS, true);
                $custom_tiers = $custom_json ? json_decode($custom_json, true) : [];
                foreach ($custom_tiers as $ct) {
                    if (($ct['slug'] ?? sanitize_title($ct['name'] ?? '')) === $group['tier_slug']) {
                        $set_pct = floatval($ct['set_discount_pct'] ?? 0);
                        foreach ($ct['items'] ?? [] as $ci) {
                            $expected_product_ids[] = (int) ($ci['product_id'] ?? 0);
                        }
                        break;
                    }
                }
            }

            $cart_product_ids = [];
            foreach ($group['cart_keys'] as $ck) {
                $ci = $cart->cart_contents[$ck] ?? null;
                if ($ci) {
                    $cart_product_ids[] = (int) $ci['product_id'];
                }
            }
            $all_present = !empty($expected_product_ids) && empty(array_diff($expected_product_ids, $cart_product_ids));
            if ($all_present && $set_pct > 0) {
                $set_discount_groups[$key] = [
                    'cart_keys' => $group['cart_keys'],
                    'pct' => $set_pct,
                ];
            }
        }

        // Apply per-item pricing.
        foreach ($cart->get_cart() as $cart_key => $item) {
            $loadout_id = (int) ($item[self::META_LOADOUT_ID] ?? 0);
            $product_loadout = (int) ($item[self::META_PRODUCT_LOADOUT] ?? 0);
            if (!$loadout_id && !$product_loadout) {
                continue;
            }

            $product = $item['data'];
            if (!$product) {
                continue;
            }

            // Bundle line: price is the pre-computed total of (anchor + tier items
            // with all discounts already applied). One inseparable line.
            if (!empty($item[self::META_TIER_BUNDLE])) {
                $bundle_total = (float) ($item[self::META_TIER_BUNDLE_TOTAL] ?? 0);
                $product->set_price(max(0, $bundle_total));
                continue;
            }

            // Bonus items are zero-priced.
            if (!empty($item[self::META_IS_BONUS])) {
                $product->set_price(0);
                continue;
            }

            $base_price = (float) $product->get_regular_price();
            if ($base_price <= 0) {
                $base_price = (float) $product->get_price();
            }

            $discount_pct = (float) ($item[self::META_DISCOUNT_PCT] ?? 0);
            $source = $item[self::META_SOURCE] ?? 'widget';

            // Widget items also get the tier accessory discount on top of any per-item discount.
            if ($source === 'widget' && !empty($item[self::META_TIER_ID])) {
                $tier = Loadout_Tier::get((int) $item[self::META_TIER_ID]);
                if ($tier) {
                    $accessory = (float) $tier->get_accessory_discount();
                    if ($accessory > 0) {
                        // Combine: per-item + accessory discounts (additive, capped at 100).
                        $discount_pct = min(100, $discount_pct + $accessory);
                    }
                }
            }

            // Set discount overrides for product tab when all tier items present.
            if ($source === 'product_tab') {
                $tier_id_val = (int) ($item[self::META_TIER_ID] ?? 0);
                $tier_slug_val = (string) ($item[self::META_TIER_SLUG] ?? '');
                $key = $product_loadout . ':' . ($tier_id_val ?: $tier_slug_val);
                if (isset($set_discount_groups[$key]) && in_array($cart_key, $set_discount_groups[$key]['cart_keys'], true)) {
                    $set_pct = $set_discount_groups[$key]['pct'];
                    $discount_pct = min(100, $discount_pct + $set_pct);
                }
            }

            if ($discount_pct > 0 && $discount_pct <= 100) {
                $final_price = $base_price * (1 - $discount_pct / 100);
                $product->set_price(max(0, $final_price));
            }
        }
    }

    public static function sync_bonus_items($cart): void
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Group widget items by loadout+tier and compute counts.
        $groups = []; // [loadout_id:tier_id => count]
        $bonus_keys_by_group = []; // [loadout_id:tier_id => cart_key]
        foreach ($cart->get_cart() as $cart_key => $item) {
            if (($item[self::META_SOURCE] ?? '') !== 'widget') {
                continue;
            }
            $loadout_id = (int) ($item[self::META_LOADOUT_ID] ?? 0);
            $tier_id = (int) ($item[self::META_TIER_ID] ?? 0);
            if (!$loadout_id || !$tier_id) {
                continue;
            }
            $key = $loadout_id . ':' . $tier_id;
            if (!empty($item[self::META_IS_BONUS])) {
                $bonus_keys_by_group[$key] = $cart_key;
            } else {
                $groups[$key] = ($groups[$key] ?? 0) + (int) $item['quantity'];
            }
        }

        foreach ($groups as $key => $count) {
            [$loadout_id, $tier_id] = array_map('intval', explode(':', $key));
            $tier = Loadout_Tier::get($tier_id);
            if (!$tier || !$tier->get_bonus_product_id() || !$tier->get_threshold_items()) {
                // Remove any stale bonus.
                if (isset($bonus_keys_by_group[$key])) {
                    $cart->remove_cart_item($bonus_keys_by_group[$key]);
                }
                continue;
            }

            $threshold_met = $count >= $tier->get_threshold_items();
            $has_bonus = isset($bonus_keys_by_group[$key]);

            if ($threshold_met && !$has_bonus) {
                // Add bonus.
                $_POST['loadout_context'] = [
                    'loadout_id' => $loadout_id,
                    'tier_id' => $tier_id,
                    'source' => 'widget',
                    'is_bonus' => 1,
                ];
                $cart->add_to_cart($tier->get_bonus_product_id(), 1);
                unset($_POST['loadout_context']);
            } elseif (!$threshold_met && $has_bonus) {
                $cart->remove_cart_item($bonus_keys_by_group[$key]);
            }
        }
    }

    public static function display_cart_item_meta($item_data, $cart_item)
    {
        if (!empty($cart_item[self::META_LOADOUT_ID])) {
            $loadout = Loadout::get((int) $cart_item[self::META_LOADOUT_ID]);
            if ($loadout) {
                $item_data[] = [
                    'name'  => __('Loadout', 'ffl-funnels-addons'),
                    'value' => $loadout->get_name(),
                ];
            }
        }
        if (!empty($cart_item[self::META_IS_BONUS])) {
            $item_data[] = [
                'name'  => __('Bonus', 'ffl-funnels-addons'),
                'value' => __('Free Gift', 'ffl-funnels-addons'),
            ];
        }

        // Bundle line: render "Includes:" list of all contained products.
        if (!empty($cart_item[self::META_TIER_BUNDLE]) && !empty($cart_item[self::META_TIER_BUNDLE_ITEMS])) {
            $items = (array) $cart_item[self::META_TIER_BUNDLE_ITEMS];
            $plain = [];
            $html_lines = [];
            $total_savings = 0.0;

            foreach ($items as $bi) {
                $pid       = isset($bi['product_id']) ? (int) $bi['product_id'] : 0;
                $name      = isset($bi['name']) ? (string) $bi['name'] : '';
                $qty       = isset($bi['quantity']) ? max(1, (int) $bi['quantity']) : 1;
                $original  = isset($bi['original']) ? (float) $bi['original'] : 0;
                $final     = isset($bi['final']) ? (float) $bi['final'] : $original;
                $is_anchor = !empty($bi['is_anchor']);

                if ($name === '') {
                    continue;
                }

                $line_original = $original * $qty;
                $line_final    = $final * $qty;
                $line_savings  = max(0, $line_original - $line_final);
                $total_savings += $line_savings;

                // Plain text version for emails / non-HTML contexts.
                $plain_label = $qty > 1 ? sprintf('%s × %d', $name, $qty) : $name;
                $plain[]     = $plain_label . ' — ' . wp_strip_all_tags(wc_price($line_final));

                // HTML version with link + prices + savings.
                $link = $pid ? get_permalink($pid) : '';

                $html  = '<li class="ffla-bundle-item" style="padding:8px 0;border-bottom:1px solid rgba(128,128,128,0.25);display:flex;flex-wrap:wrap;gap:6px 12px;align-items:baseline;">';
                $html .= '<span class="ffla-bundle-item__name" style="flex:1 1 60%;min-width:160px;">';
                if ($link) {
                    $html .= '<a href="' . esc_url($link) . '" style="text-decoration:none;color:inherit;font-weight:600;">' . esc_html($name) . '</a>';
                } else {
                    $html .= '<strong>' . esc_html($name) . '</strong>';
                }
                if ($qty > 1) {
                    $html .= ' <span class="ffla-bundle-item__qty" style="opacity:0.7;">× ' . esc_html($qty) . '</span>';
                }
                if ($is_anchor) {
                    $html .= ' <span class="ffla-bundle-item__badge" style="margin-left:6px;padding:1px 6px;background:var(--primary,#d4a017);color:#fff;border-radius:3px;font-size:10px;text-transform:uppercase;letter-spacing:0.5px;">' . esc_html__('Main', 'ffl-funnels-addons') . '</span>';
                }
                $html .= '</span>';

                $html .= '<span class="ffla-bundle-item__price" style="white-space:nowrap;">';
                if ($line_savings > 0) {
                    $html .= '<s style="opacity:0.6;margin-right:6px;">' . wp_kses_post(wc_price($line_original)) . '</s>';
                }
                $html .= '<strong>' . wp_kses_post(wc_price($line_final)) . '</strong>';
                $html .= '</span>';

                if ($line_savings > 0) {
                    $html .= '<span class="ffla-bundle-item__savings" style="white-space:nowrap;color:var(--success,#2e7d32);font-size:12px;font-weight:600;">'
                          . sprintf(
                              /* translators: %s: savings amount */
                              esc_html__('Save %s', 'ffl-funnels-addons'),
                              wp_kses_post(wc_price($line_savings))
                          )
                          . '</span>';
                }
                $html .= '</li>';

                $html_lines[] = $html;
            }

            if (!empty($html_lines)) {
                $display  = '<ul class="ffla-loadout-bundle-includes" style="margin:6px 0 0;padding:0;list-style:none;font-size:13px;">';
                $display .= implode('', $html_lines);
                $display .= '</ul>';
                if ($total_savings > 0) {
                    $display .= '<p class="ffla-loadout-bundle-total-savings" style="margin:8px 0 0;font-size:13px;font-weight:700;color:var(--success,#2e7d32);">'
                              . sprintf(
                                  /* translators: %s: total savings amount */
                                  esc_html__('Total Loadout Savings: %s', 'ffl-funnels-addons'),
                                  wp_kses_post(wc_price($total_savings))
                              )
                              . '</p>';
                }

                $item_data[] = [
                    'name'    => __('Includes', 'ffl-funnels-addons'),
                    'value'   => implode(', ', $plain),
                    'display' => $display,
                ];
            }
        }

        return $item_data;
    }

    public static function save_order_line_item_meta($item, $cart_item_key, $values, $order): void
    {
        foreach ([
            self::META_LOADOUT_ID, self::META_TIER_ID, self::META_TIER_SLUG, self::META_SOURCE,
            self::META_PRODUCT_LOADOUT, self::META_DISCOUNT_PCT,
            self::META_IS_BONUS, self::META_ITEM_ID,
            self::META_TIER_BUNDLE, self::META_TIER_BUNDLE_ITEMS, self::META_TIER_BUNDLE_TOTAL,
        ] as $key) {
            if (!empty($values[$key])) {
                $item->add_meta_data($key, $values[$key], true);
            }
        }

        // Human-readable "Includes" line item meta for bundle orders.
        if (!empty($values[self::META_TIER_BUNDLE]) && !empty($values[self::META_TIER_BUNDLE_ITEMS])) {
            $names = [];
            foreach ((array) $values[self::META_TIER_BUNDLE_ITEMS] as $bi) {
                $n = isset($bi['name']) ? (string) $bi['name'] : '';
                $q = isset($bi['quantity']) ? (int) $bi['quantity'] : 1;
                if ($n) {
                    $names[] = $q > 1 ? sprintf('%s × %d', $n, $q) : $n;
                }
            }
            if (!empty($names)) {
                $item->add_meta_data(__('Includes', 'ffl-funnels-addons'), implode(', ', $names), true);
            }
        }
    }

    public static function on_cart_item_removed($cart_item_key, $cart): void
    {
        // Trigger bonus sync after removal.
        if (function_exists('WC') && WC()->cart) {
            self::sync_bonus_items(WC()->cart);
        }
    }

    /**
     * Build the standard WooCommerce add-to-cart AJAX response payload:
     * - fragments (mini-cart HTML and any other registered fragments)
     * - cart_hash (so wc-add-to-cart-fragments.js knows to swap)
     * - cart_count, cart_total
     *
     * Without these, the mini-cart widget never refreshes after our AJAX
     * calls and the customer has to reload the page to see new items.
     */
    private static function build_cart_response(array $extra = []): array
    {
        if (!function_exists('WC') || !WC()->cart) {
            return $extra;
        }

        WC()->cart->calculate_totals();
        WC()->cart->maybe_set_cart_cookies();

        ob_start();
        if (function_exists('woocommerce_mini_cart')) {
            woocommerce_mini_cart();
        }
        $mini_cart = ob_get_clean();

        $fragments = apply_filters('woocommerce_add_to_cart_fragments', [
            'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
        ]);

        return array_merge($extra, [
            'fragments'  => $fragments,
            'cart_hash'  => WC()->cart->get_cart_hash(),
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
            'cart_url'   => wc_get_cart_url(),
        ]);
    }

    public static function ajax_add_item(): void
    {
        check_ajax_referer('loadout_frontend', 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;
        $loadout_id = isset($_POST['loadout_id']) ? absint($_POST['loadout_id']) : 0;
        $tier_id = isset($_POST['tier_id']) ? absint($_POST['tier_id']) : 0;
        $source = isset($_POST['source']) ? sanitize_key($_POST['source']) : 'widget';
        $product_loadout_id = isset($_POST['product_loadout_id']) ? absint($_POST['product_loadout_id']) : 0;
        $item_id = isset($_POST['item_id']) ? absint($_POST['item_id']) : 0;
        $discount_pct = isset($_POST['discount_pct']) ? floatval($_POST['discount_pct']) : 0;

        if (!$product_id) {
            wp_send_json_error(['message' => __('Invalid product.', 'ffl-funnels-addons')]);
        }

        $tier_slug = isset($_POST['tier_slug']) ? sanitize_title(wp_unslash($_POST['tier_slug'])) : '';

        $_POST['loadout_context'] = [
            'loadout_id' => $loadout_id,
            'tier_id' => $tier_id,
            'tier_slug' => $tier_slug,
            'source' => $source,
            'product_loadout_id' => $product_loadout_id,
            'item_id' => $item_id,
            'discount_pct' => $discount_pct,
        ];

        $cart_key = WC()->cart->add_to_cart($product_id, $quantity);
        unset($_POST['loadout_context']);

        if (!$cart_key) {
            wp_send_json_error(['message' => __('Could not add to cart.', 'ffl-funnels-addons')]);
        }

        wp_send_json_success(self::build_cart_response([
            'cart_key' => $cart_key,
        ]));
    }

    /**
     * Add the entire tier as a SINGLE inseparable cart line (bundle-style).
     *
     * Contents of the line:
     *  - The anchor / main product (loadout's anchor_product_id, or the
     *    product the tab is on for product_tab source)
     *  - All tier items at configured quantities, with per-item and
     *    accessory/set discounts applied
     *
     * The line price is the sum of all discounted item prices. Removing the
     * line removes the whole tier-bundle (one click, one inseparable unit).
     */
    public static function ajax_add_tier(): void
    {
        check_ajax_referer('loadout_frontend', 'nonce');

        $loadout_id         = isset($_POST['loadout_id']) ? absint($_POST['loadout_id']) : 0;
        $tier_id            = isset($_POST['tier_id']) ? absint($_POST['tier_id']) : 0;
        $source             = isset($_POST['source']) ? sanitize_key($_POST['source']) : 'widget';
        $product_loadout_id = isset($_POST['product_loadout_id']) ? absint($_POST['product_loadout_id']) : 0;
        $tier_slug          = isset($_POST['tier_slug']) ? sanitize_title(wp_unslash($_POST['tier_slug'])) : '';

        // Build the tier item list and look up tier-level discount settings.
        $items_raw          = [];
        $accessory_discount = 0;
        $set_discount       = 0;
        $tier_name          = '';

        if ($tier_id) {
            $tier = Loadout_Tier::get($tier_id);
            if (!$tier) {
                wp_send_json_error(['message' => __('Tier not found.', 'ffl-funnels-addons')]);
            }
            $tier_name          = $tier->get_name();
            $accessory_discount = (float) $tier->get_accessory_discount();
            $set_discount       = (float) $tier->get_set_discount_pct();
            foreach ($tier->get_items() as $item) {
                $items_raw[] = [
                    'product_id'   => $item->get_product_id(),
                    'quantity'     => $item->get_quantity(),
                    'discount_pct' => $item->get_discount_pct(),
                    'item_id'      => $item->get_id(),
                ];
            }
        } elseif ($product_loadout_id && $tier_slug) {
            // Custom (per-product) tier from product meta.
            $custom_json  = get_post_meta($product_loadout_id, Loadout_Product_Admin::META_CUSTOM_TIERS, true);
            $custom_tiers = $custom_json ? json_decode($custom_json, true) : [];
            foreach ($custom_tiers as $ct) {
                if (($ct['slug'] ?? sanitize_title($ct['name'] ?? '')) === $tier_slug) {
                    $tier_name          = $ct['name'] ?? '';
                    $accessory_discount = floatval($ct['accessory_discount'] ?? 0);
                    $set_discount       = floatval($ct['set_discount_pct'] ?? 0);
                    foreach ($ct['items'] ?? [] as $ci) {
                        $items_raw[] = [
                            'product_id'   => (int) ($ci['product_id'] ?? 0),
                            'quantity'     => (int) ($ci['quantity'] ?? 1),
                            'discount_pct' => floatval($ci['discount_pct'] ?? 0),
                            'item_id'      => 0,
                        ];
                    }
                    break;
                }
            }
        }

        if (empty($items_raw)) {
            wp_send_json_error(['message' => __('No items to add.', 'ffl-funnels-addons')]);
        }

        // Determine the anchor product (the "main product").
        // - For widget source: from the global Loadout config
        // - For product_tab source: the product the tab is on
        $anchor_id = 0;
        if ($source === 'product_tab' && $product_loadout_id) {
            $anchor_id = $product_loadout_id;
        } elseif ($loadout_id) {
            $loadout = Loadout::get($loadout_id);
            if ($loadout) {
                $anchor_id = (int) $loadout->get_anchor_product_id();
            }
        }

        // Compose the bundle items: anchor first (rep product), then tier items.
        // Set-discount applies on top of per-item + accessory discount (since
        // bundle = all items, the "set" condition is always met).
        $bundle_items = [];
        $bundle_total = 0.0;

        $add_item_to_bundle = function ($pid, $qty, $per_item_discount, $is_anchor = false) use (&$bundle_items, &$bundle_total, $accessory_discount, $set_discount) {
            $product = wc_get_product($pid);
            if (!$product || !$product->is_purchasable()) {
                return false;
            }
            $regular = (float) $product->get_regular_price();
            if ($regular <= 0) {
                $regular = (float) $product->get_price();
            }
            // Anchor product never gets accessory/set discount — only the tier items do.
            $discount_pct = $is_anchor
                ? (float) $per_item_discount
                : min(100, (float) $per_item_discount + $accessory_discount + $set_discount);
            $final = $discount_pct > 0 ? $regular * (1 - $discount_pct / 100) : $regular;
            $line_total = $final * $qty;
            $bundle_items[] = [
                'product_id'   => (int) $pid,
                'name'         => $product->get_name(),
                'quantity'     => (int) $qty,
                'original'     => $regular,
                'discount_pct' => $discount_pct,
                'final'        => $final,
                'is_anchor'    => $is_anchor ? 1 : 0,
            ];
            $bundle_total += $line_total;
            return true;
        };

        if ($anchor_id) {
            $add_item_to_bundle($anchor_id, 1, 0, true);
        }
        foreach ($items_raw as $i) {
            if (!$i['product_id']) {
                continue;
            }
            // Skip if anchor was already added and is the same product.
            if ($anchor_id && (int) $i['product_id'] === (int) $anchor_id) {
                continue;
            }
            $add_item_to_bundle($i['product_id'], $i['quantity'], $i['discount_pct'], false);
        }

        if (empty($bundle_items)) {
            wp_send_json_error(['message' => __('No purchasable items in this tier.', 'ffl-funnels-addons')]);
        }

        // Representative product = anchor if present, else the first tier item.
        $representative = $bundle_items[0]['product_id'];

        $bundle_hash = wp_generate_password(16, false, false);
        $_POST['loadout_context'] = [
            'loadout_id'         => $loadout_id,
            'tier_id'            => $tier_id,
            'tier_slug'          => $tier_slug,
            'source'             => $source,
            'product_loadout_id' => $product_loadout_id,
            'discount_pct'       => 0,                // baked into bundle_total
            'tier_bundle'        => 1,
            'bundle_items'       => $bundle_items,
            'bundle_total'       => $bundle_total,
            'bundle_hash'        => $bundle_hash,
        ];
        $cart_key = WC()->cart->add_to_cart($representative, 1);
        unset($_POST['loadout_context']);

        if (!$cart_key) {
            wp_send_json_error(['message' => __('Could not add tier bundle to cart.', 'ffl-funnels-addons')]);
        }

        wp_send_json_success(self::build_cart_response([
            'cart_key'     => $cart_key,
            'items_added'  => count($bundle_items),
            'bundle_total' => wc_price($bundle_total),
            'tier_name'    => $tier_name,
        ]));
    }

    public static function ajax_get_cart_summary(): void
    {
        check_ajax_referer('loadout_frontend', 'nonce');

        $loadout_id = isset($_POST['loadout_id']) ? absint($_POST['loadout_id']) : 0;

        // Run pricing filters so single-line items reflect their discounted price.
        WC()->cart->calculate_totals();

        $items = [];
        $savings = 0;
        $loadout_count = 0;
        $tier_id_count = [];

        foreach (WC()->cart->get_cart() as $cart_key => $ci) {
            $item_loadout_id = (int) ($ci[self::META_LOADOUT_ID] ?? 0);
            if ($loadout_id && $item_loadout_id !== $loadout_id) {
                continue;
            }

            $product = $ci['data'];
            $qty = (int) $ci['quantity'];

            // Tier-bundle line: savings live inside the bundle items metadata
            // (each entry has its own 'original' and 'final' price).
            if (!empty($ci[self::META_TIER_BUNDLE]) && !empty($ci[self::META_TIER_BUNDLE_ITEMS])) {
                foreach ((array) $ci[self::META_TIER_BUNDLE_ITEMS] as $bi) {
                    $bo = (float) ($bi['original'] ?? 0);
                    $bf = (float) ($bi['final'] ?? $bo);
                    $bq = (int) ($bi['quantity'] ?? 1);
                    $savings += max(0, ($bo - $bf) * $bq * $qty);
                }
            } else {
                $regular = (float) $product->get_regular_price();
                if ($regular <= 0) {
                    $regular = (float) $product->get_price();
                }
                $current = (float) $product->get_price();
                $savings += max(0, ($regular - $current) * $qty);
            }

            $loadout_count += $qty;

            $tid = (int) ($ci[self::META_TIER_ID] ?? 0);
            if ($tid) {
                $tier_id_count[$tid] = ($tier_id_count[$tid] ?? 0) + 1;
            }

            $items[] = [
                'name' => $product->get_name(),
                'quantity' => $qty,
                'regular' => wc_price((float) $product->get_regular_price()),
                'current' => wc_price((float) $product->get_price()),
                'is_bonus' => !empty($ci[self::META_IS_BONUS]),
                'cart_key' => $cart_key,
            ];
        }

        wp_send_json_success([
            'items' => $items,
            'savings' => wc_price($savings),
            'count' => $loadout_count,
            'tier_counts' => $tier_id_count,
            'total' => WC()->cart->get_cart_total(),
        ]);
    }
}
