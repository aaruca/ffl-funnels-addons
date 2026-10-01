<?php
/**
 * Smart Coupons — how much each coupon takes off.
 *
 * - Protected items (firearms, chosen categories/tags) are removed from the
 *   items every coupon applies to — fixed cart coupons included.
 * - MAP floor and a per-coupon cap are enforced on each item's discount,
 *   tracking what earlier coupons already took off in the same calculation.
 * - "Spend tiers" and "Buy X get Y" are custom discount types whose whole
 *   allocation is computed here.
 * - A coupon can add a free gift to the cart.
 *
 * Store credit coupons skip the guardrails: credit is money the store owes,
 * not a price reduction.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Coupon_Discounts
{
    const TIERED = 'ffla_tiered';
    const BXGY = 'ffla_bxgy';

    /** @var array<string, float> Promotional discount already applied per cart item (for MAP). */
    private static $promo = [];

    /** @var array<string, float> All discounts already applied per cart item. */
    private static $total = [];

    /** @var array<string, float> Discount applied per coupon (for caps). */
    private static $by_coupon = [];

    public static function boot(): void
    {
        add_filter('woocommerce_coupon_discount_types', [__CLASS__, 'types']);
        add_filter('woocommerce_product_coupon_types', [__CLASS__, 'product_types']);
        add_filter('woocommerce_coupon_get_items_to_apply', [__CLASS__, 'items_to_apply'], 10, 3);
        add_filter('woocommerce_coupon_is_valid_for_product', [__CLASS__, 'valid_for_product'], 20, 4);
        add_filter('woocommerce_coupon_get_discount_amount', [__CLASS__, 'limit_item_discount'], 50, 5);
        add_filter('woocommerce_coupon_custom_discounts_array', [__CLASS__, 'custom_discounts'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'reset'], 1);

        // Free gifts.
        add_action('woocommerce_applied_coupon', [__CLASS__, 'add_gift']);
        add_action('woocommerce_removed_coupon', [__CLASS__, 'remove_gift']);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'gift_prices'], 20);
        add_filter('woocommerce_cart_item_quantity', [__CLASS__, 'gift_quantity'], 10, 3);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'gift_label'], 10, 2);
    }

    public static function types($types)
    {
        $types[self::TIERED] = __('Spend tiers', 'ffl-funnels-addons');
        $types[self::BXGY] = __('Buy X get Y', 'ffl-funnels-addons');
        return $types;
    }

    /** Our types honour WooCommerce's product / category restrictions like percent coupons. */
    public static function product_types($types)
    {
        $types[] = self::TIERED;
        $types[] = self::BXGY;
        return array_values(array_unique($types));
    }

    public static function reset(): void
    {
        self::$promo = [];
        self::$total = [];
        self::$by_coupon = [];
    }

    /* ── Guardrails ────────────────────────────────────────────────────── */

    /** Whether this coupon may discount firearms / protected items. */
    public static function allows_protected(WC_Coupon $coupon): bool
    {
        if (FFLA_Coupon_Settings::is_credit($coupon) || FFLA_Coupon_Settings::coupon($coupon)['allow_protected']) {
            return true;
        }
        return class_exists('FFLA_Coupon_Categories') && FFLA_Coupon_Categories::rule($coupon, 'allow_protected');
    }

    /** The cap on what this coupon takes off in total (0 = none). */
    public static function cap(WC_Coupon $coupon): float
    {
        $caps = array_filter([
            (float) FFLA_Coupon_Settings::coupon($coupon)['max_discount'],
            class_exists('FFLA_Coupon_Categories') ? (float) FFLA_Coupon_Categories::rule($coupon, 'max_discount') : 0.0,
        ]);
        return $caps ? min($caps) : 0.0;
    }

    public static function items_to_apply($items, $coupon, $discounts = null)
    {
        if (!is_array($items) || !$coupon instanceof WC_Coupon || self::allows_protected($coupon)) {
            return $items;
        }
        return array_values(array_filter($items, static function ($item) {
            return !(isset($item->product) && FFLA_Coupon_Settings::is_protected($item->product));
        }));
    }

    public static function valid_for_product($valid, $product, $coupon, $values = [])
    {
        if ($valid && $coupon instanceof WC_Coupon && !self::allows_protected($coupon) && FFLA_Coupon_Settings::is_protected($product)) {
            return false;
        }
        return $valid;
    }

    /**
     * MAP floor and coupon cap for core coupon types (percent, fixed
     * product, fixed cart), item by item.
     */
    public static function limit_item_discount($discount, $discounting_amount, $cart_item, $single, $coupon)
    {
        if (!$coupon instanceof WC_Coupon || !is_array($cart_item) || empty($cart_item['key'])) {
            return $discount;
        }
        if ($coupon->is_type([self::TIERED, self::BXGY])) {
            return 0; // Computed for the whole cart in custom_discounts().
        }
        return self::limit($coupon, $cart_item, (float) $discount);
    }

    /** Apply MAP headroom, the coupon cap and the line's remaining price; record it. */
    private static function limit(WC_Coupon $coupon, array $cart_item, float $discount): float
    {
        $key = (string) $cart_item['key'];
        $product = $cart_item['data'] ?? null;
        $qty = max(1, (int) ($cart_item['quantity'] ?? 1));
        $line = $product instanceof WC_Product ? (float) $product->get_price() * $qty : 0.0;
        $credit = FFLA_Coupon_Settings::is_credit($coupon);

        if (!$credit && FFLA_Coupon_Settings::value('enforce_map') && $product instanceof WC_Product) {
            $map = FFLA_Coupon_Settings::map_price($product);
            if ($map > 0) {
                $headroom = max(0.0, ((float) $product->get_price() - $map) * $qty - (self::$promo[$key] ?? 0.0));
                $discount = min($discount, $headroom);
            }
        }
        $cap = self::cap($coupon);
        if ($cap > 0) {
            $discount = min($discount, max(0.0, $cap - (self::$by_coupon[$coupon->get_code()] ?? 0.0)));
        }
        if ($line > 0) {
            $discount = min($discount, max(0.0, $line - (self::$total[$key] ?? 0.0)));
        }
        $discount = max(0.0, $discount);

        if (!$credit) {
            self::$promo[$key] = (self::$promo[$key] ?? 0.0) + $discount;
        }
        self::$total[$key] = (self::$total[$key] ?? 0.0) + $discount;
        self::$by_coupon[$coupon->get_code()] = (self::$by_coupon[$coupon->get_code()] ?? 0.0) + $discount;
        return $discount;
    }

    /* ── Spend tiers and Buy X get Y ───────────────────────────────────── */

    /**
     * Eligible cart items for a coupon: [key => ['item' => cart item, 'unit' => price, 'qty' => n]].
     */
    public static function eligible_items(WC_Coupon $coupon, WC_Cart $cart): array
    {
        $out = [];
        foreach ($cart->get_cart() as $key => $item) {
            $product = $item['data'] ?? null;
            if (!$product instanceof WC_Product || !empty($item['ffla_gift']) || (float) $product->get_price() <= 0) {
                continue;
            }
            if (!self::allows_protected($coupon) && FFLA_Coupon_Settings::is_protected($product)) {
                continue;
            }
            if (!$coupon->is_valid_for_product($product, $item)) {
                continue;
            }
            $out[$key] = ['item' => $item + ['key' => $key], 'unit' => (float) $product->get_price(), 'qty' => (int) $item['quantity']];
        }
        return $out;
    }

    /** Subtotal of eligible items and the tier it reaches (or null). */
    public static function tier(WC_Coupon $coupon, WC_Cart $cart): array
    {
        $items = self::eligible_items($coupon, $cart);
        $subtotal = 0.0;
        foreach ($items as $row) {
            $subtotal += $row['unit'] * $row['qty'];
        }
        $reached = null;
        $next = null;
        foreach (FFLA_Coupon_Settings::coupon($coupon)['tiers'] as $tier) {
            if ($subtotal >= $tier['min']) {
                $reached = $tier;
            } elseif (null === $next) {
                $next = $tier;
            }
        }
        return ['subtotal' => $subtotal, 'tier' => $reached, 'next' => $next, 'items' => $items];
    }

    /**
     * Buy X get Y allocation: which units are discounted.
     *
     * @return array{free:int, units:array<int, array{key:string, unit:float}>, buy_units:int}
     */
    public static function bxgy(WC_Coupon $coupon, WC_Cart $cart): array
    {
        $o = FFLA_Coupon_Settings::coupon($coupon);
        $items = self::eligible_items($coupon, $cart);
        $in = static function ($row, array $cats, array $products): bool {
            if (!$cats && !$products) {
                return true;
            }
            $product = $row['item']['data'];
            return in_array($product->get_id(), $products, true) || in_array($product->get_parent_id(), $products, true)
                || ($cats && FFLA_Coupon_Settings::in_categories($product, $cats));
        };
        $buy = $get = [];
        foreach ($items as $key => $row) {
            if ($in($row, $o['buy_cats'], $o['buy_products'])) {
                $buy[$key] = $row;
            }
            $get_cats = $o['get_cats'] || $o['get_products'] ? $o['get_cats'] : $o['buy_cats'];
            $get_products = $o['get_cats'] || $o['get_products'] ? $o['get_products'] : $o['buy_products'];
            if ($in($row, $get_cats, $get_products)) {
                $get[$key] = $row;
            }
        }

        $x = max(1, (int) $o['buy_qty']);
        $y = max(1, (int) $o['get_qty']);
        $buy_units = array_sum(array_column($buy, 'qty'));
        $same = array_keys($buy) === array_keys($get);
        $groups = $same ? intdiv($buy_units, $x + $y) : intdiv($buy_units, $x);
        if (!$o['repeat']) {
            $groups = min(1, $groups);
        }
        $free = $groups * $y;

        // Cheapest units get the discount.
        $units = [];
        foreach ($get as $key => $row) {
            for ($i = 0; $i < $row['qty']; $i++) {
                $units[] = ['key' => $key, 'unit' => $row['unit']];
            }
        }
        usort($units, static function ($a, $b) {
            return $a['unit'] <=> $b['unit'];
        });

        return ['free' => min($free, count($units)), 'units' => array_slice($units, 0, $free), 'buy_units' => $buy_units, 'items' => $items];
    }

    /**
     * @param array     $discounts Cart item key => cents.
     * @param WC_Coupon $coupon
     */
    public static function custom_discounts($discounts, $coupon)
    {
        if (!$coupon instanceof WC_Coupon || !$coupon->is_type([self::TIERED, self::BXGY]) || !function_exists('WC') || !WC()->cart) {
            return $discounts;
        }
        $cart = WC()->cart;
        $amounts = []; // key => currency amount before limits.
        $redistribute = 0.0;

        if ($coupon->is_type(self::TIERED)) {
            $t = self::tier($coupon, $cart);
            if ($t['tier'] && $t['subtotal'] > 0) {
                $total = $t['tier']['percent'] ? $t['subtotal'] * $t['tier']['amount'] / 100 : min($t['tier']['amount'], $t['subtotal']);
                foreach ($t['items'] as $key => $row) {
                    $amounts[$key] = $total * ($row['unit'] * $row['qty']) / $t['subtotal'];
                }
                // A fixed "$50 off" moves what MAP / caps hold back onto items with room.
                $redistribute = $t['tier']['percent'] ? 0.0 : $total;
            }
            $items = $t['items'];
        } else {
            $b = self::bxgy($coupon, $cart);
            $pct = (float) FFLA_Coupon_Settings::coupon($coupon)['get_pct'] / 100;
            foreach ($b['units'] as $unit) {
                $amounts[$unit['key']] = ($amounts[$unit['key']] ?? 0.0) + $unit['unit'] * $pct;
            }
            $items = $b['items'];
        }

        foreach (array_keys((array) $discounts) as $key) {
            $discounts[$key] = 0;
        }
        $given = [];
        $full = []; // Items that took their whole share and may take more.
        foreach ($amounts as $key => $amount) {
            if (!isset($items[$key])) {
                continue;
            }
            $wanted = round($amount, wc_get_price_decimals());
            $given[$key] = self::limit($coupon, $items[$key]['item'], $wanted);
            if ($given[$key] >= $wanted - 0.001) {
                $full[$key] = $items[$key]['unit'] * $items[$key]['qty'];
            }
        }
        for ($pass = 0; $pass < 3 && $redistribute > 0 && $full; $pass++) {
            $left = round($redistribute - array_sum($given), wc_get_price_decimals());
            if ($left < 0.01) {
                break;
            }
            $weight = array_sum($full);
            foreach ($full as $key => $value) {
                $wanted = round($left * $value / $weight, wc_get_price_decimals());
                $extra = self::limit($coupon, $items[$key]['item'], $wanted);
                $given[$key] += $extra;
                if ($extra < $wanted - 0.001) {
                    unset($full[$key]);
                }
            }
        }
        foreach ($given as $key => $amount) {
            $discounts[$key] = (int) round(wc_add_number_precision($amount));
        }
        return $discounts;
    }

    /* ── Free gift ─────────────────────────────────────────────────────── */

    public static function add_gift($code): void
    {
        $coupon = new WC_Coupon($code);
        $o = FFLA_Coupon_Settings::coupon($coupon);
        $product = $o['gift_product'] ? wc_get_product($o['gift_product']) : null;
        if (!$product || !$product->is_purchasable() || !$product->is_in_stock() || !WC()->cart) {
            return;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if (($item['ffla_gift'] ?? '') === $coupon->get_code()) {
                return;
            }
        }
        if ($product->is_type('variation')) {
            WC()->cart->add_to_cart($product->get_parent_id(), $o['gift_qty'], $product->get_id(), $product->get_variation_attributes(), ['ffla_gift' => $coupon->get_code()]);
        } else {
            WC()->cart->add_to_cart($product->get_id(), $o['gift_qty'], 0, [], ['ffla_gift' => $coupon->get_code()]);
        }
    }

    public static function remove_gift($code): void
    {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        foreach (WC()->cart->get_cart() as $key => $item) {
            if (($item['ffla_gift'] ?? '') === wc_format_coupon_code($code)) {
                WC()->cart->remove_cart_item($key);
            }
        }
    }

    /** Gifts are free while their coupon is applied, and leave with it. */
    public static function gift_prices($cart): void
    {
        if (!$cart instanceof WC_Cart) {
            return;
        }
        $applied = $cart->get_applied_coupons();
        foreach ($cart->get_cart() as $key => $item) {
            if (empty($item['ffla_gift'])) {
                continue;
            }
            if (!in_array($item['ffla_gift'], $applied, true)) {
                $cart->remove_cart_item($key);
                continue;
            }
            $item['data']->set_price(0);
            $qty = FFLA_Coupon_Settings::coupon(new WC_Coupon($item['ffla_gift']))['gift_qty'];
            if ((int) $item['quantity'] !== $qty) {
                $cart->cart_contents[$key]['quantity'] = $qty;
            }
        }
    }

    public static function gift_quantity($html, $key, $item = [])
    {
        return !empty($item['ffla_gift']) ? (string) (int) $item['quantity'] : $html;
    }

    public static function gift_label($data, $item)
    {
        if (!empty($item['ffla_gift'])) {
            $data[] = ['key' => __('Free gift', 'ffl-funnels-addons'), 'value' => strtoupper((string) $item['ffla_gift'])];
        }
        return $data;
    }
}
