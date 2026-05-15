<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Cart
{
    const META_LOADOUT_ID      = '_ffla_loadout_id';
    const META_TIER_ID         = '_ffla_loadout_tier_id';
    const META_SOURCE          = '_ffla_loadout_source';     // 'widget' or 'product_tab'
    const META_PRODUCT_LOADOUT = '_ffla_product_loadout_id'; // product tab parent product id
    const META_SET_DISCOUNT    = '_ffla_set_discount_flag';  // 'pending' or 'applied'
    const META_DISCOUNT_PCT    = '_ffla_loadout_discount_pct';
    const META_IS_BONUS        = '_ffla_loadout_is_bonus';
    const META_ITEM_ID         = '_ffla_loadout_item_id';

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

        $cart_item_data[self::META_LOADOUT_ID] = $loadout_id;
        $cart_item_data[self::META_TIER_ID] = $tier_id;
        $cart_item_data[self::META_SOURCE] = $source ?: 'widget';
        $cart_item_data[self::META_PRODUCT_LOADOUT] = $product_loadout;
        $cart_item_data[self::META_DISCOUNT_PCT] = $discount_pct;
        $cart_item_data[self::META_ITEM_ID] = $item_id;
        $cart_item_data[self::META_IS_BONUS] = $is_bonus;

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
            self::META_LOADOUT_ID, self::META_TIER_ID, self::META_SOURCE,
            self::META_PRODUCT_LOADOUT, self::META_SET_DISCOUNT, self::META_DISCOUNT_PCT,
            self::META_IS_BONUS, self::META_ITEM_ID
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
        $tab_groups = []; // [product_loadout_id => [tier_id => [cart_keys, expected_item_ids]]]
        foreach ($cart->get_cart() as $cart_key => $item) {
            if (($item[self::META_SOURCE] ?? '') !== 'product_tab') {
                continue;
            }
            $product_loadout_id = (int) ($item[self::META_PRODUCT_LOADOUT] ?? 0);
            $tier_id = (int) ($item[self::META_TIER_ID] ?? 0);
            if (!$product_loadout_id || !$tier_id) {
                continue;
            }
            $key = $product_loadout_id . ':' . $tier_id;
            if (!isset($tab_groups[$key])) {
                $tab_groups[$key] = ['cart_keys' => [], 'tier_id' => $tier_id, 'product_loadout_id' => $product_loadout_id];
            }
            $tab_groups[$key]['cart_keys'][] = $cart_key;
        }

        $set_discount_groups = [];
        foreach ($tab_groups as $key => $group) {
            $tier = Loadout_Tier::get($group['tier_id']);
            if (!$tier) {
                continue;
            }
            $expected_items = $tier->get_items();
            $expected_product_ids = array_map(fn($i) => $i->get_product_id(), $expected_items);
            $cart_product_ids = [];
            foreach ($group['cart_keys'] as $ck) {
                $ci = $cart->cart_contents[$ck] ?? null;
                if ($ci) {
                    $cart_product_ids[] = (int) $ci['product_id'];
                }
            }
            $all_present = !empty($expected_product_ids) && empty(array_diff($expected_product_ids, $cart_product_ids));
            if ($all_present && $tier->get_set_discount_pct() > 0) {
                $set_discount_groups[$key] = [
                    'cart_keys' => $group['cart_keys'],
                    'pct' => $tier->get_set_discount_pct(),
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
                $key = $product_loadout . ':' . ($item[self::META_TIER_ID] ?? 0);
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
                    'name' => __('Loadout', 'ffl-funnels-addons'),
                    'value' => $loadout->get_name(),
                ];
            }
        }
        if (!empty($cart_item[self::META_IS_BONUS])) {
            $item_data[] = [
                'name' => __('Bonus', 'ffl-funnels-addons'),
                'value' => __('Free Gift', 'ffl-funnels-addons'),
            ];
        }
        return $item_data;
    }

    public static function save_order_line_item_meta($item, $cart_item_key, $values, $order): void
    {
        foreach ([
            self::META_LOADOUT_ID, self::META_TIER_ID, self::META_SOURCE,
            self::META_PRODUCT_LOADOUT, self::META_DISCOUNT_PCT,
            self::META_IS_BONUS, self::META_ITEM_ID
        ] as $key) {
            if (!empty($values[$key])) {
                $item->add_meta_data($key, $values[$key], true);
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

        $_POST['loadout_context'] = [
            'loadout_id' => $loadout_id,
            'tier_id' => $tier_id,
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

        wp_send_json_success([
            'cart_key' => $cart_key,
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
        ]);
    }

    public static function ajax_add_tier(): void
    {
        check_ajax_referer('loadout_frontend', 'nonce');

        $loadout_id = isset($_POST['loadout_id']) ? absint($_POST['loadout_id']) : 0;
        $tier_id = isset($_POST['tier_id']) ? absint($_POST['tier_id']) : 0;
        $source = isset($_POST['source']) ? sanitize_key($_POST['source']) : 'widget';
        $product_loadout_id = isset($_POST['product_loadout_id']) ? absint($_POST['product_loadout_id']) : 0;

        $tier = Loadout_Tier::get($tier_id);
        if (!$tier) {
            wp_send_json_error(['message' => __('Tier not found.', 'ffl-funnels-addons')]);
        }

        $items_added = 0;
        foreach ($tier->get_items() as $item) {
            $_POST['loadout_context'] = [
                'loadout_id' => $loadout_id,
                'tier_id' => $tier_id,
                'source' => $source,
                'product_loadout_id' => $product_loadout_id,
                'item_id' => $item->get_id(),
                'discount_pct' => $item->get_discount_pct(),
            ];
            $key = WC()->cart->add_to_cart($item->get_product_id(), $item->get_quantity());
            unset($_POST['loadout_context']);
            if ($key) {
                $items_added++;
            }
        }

        wp_send_json_success([
            'items_added' => $items_added,
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'cart_total' => WC()->cart->get_cart_total(),
        ]);
    }

    public static function ajax_get_cart_summary(): void
    {
        check_ajax_referer('loadout_frontend', 'nonce');

        $loadout_id = isset($_POST['loadout_id']) ? absint($_POST['loadout_id']) : 0;

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
            $regular = (float) $product->get_regular_price();
            $current = (float) $product->get_price();
            $savings += max(0, ($regular - $current) * $ci['quantity']);
            $loadout_count += (int) $ci['quantity'];

            $tid = (int) ($ci[self::META_TIER_ID] ?? 0);
            if ($tid) {
                $tier_id_count[$tid] = ($tier_id_count[$tid] ?? 0) + 1;
            }

            $items[] = [
                'name' => $product->get_name(),
                'quantity' => $ci['quantity'],
                'regular' => wc_price($regular),
                'current' => wc_price($current),
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
