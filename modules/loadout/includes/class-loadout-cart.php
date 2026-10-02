<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Loadout cart integration: add-to-cart endpoints, pricing, the bonus product
 * and stock for "entire tier" bundle lines.
 *
 * Security model: nothing that affects a price is taken from the request.
 * The AJAX handlers validate what the browser sends against the stored
 * configuration and hand the result to add_cart_item_data() through a private
 * static ($pending_context) — never through $_POST, so a plain WooCommerce
 * add-to-cart request cannot attach loadout data. Prices are re-derived from
 * the stored configuration every time the cart is calculated.
 */
class Loadout_Cart
{
    const META_LOADOUT_ID      = '_ffla_loadout_id';
    const META_TIER_ID         = '_ffla_loadout_tier_id';
    const META_TIER_SLUG       = '_ffla_loadout_tier_slug'; // Per-product tiers (tier_id = 0).
    const META_SOURCE          = '_ffla_loadout_source';    // Always 'widget' now; kept for stored lines.
    const META_PRODUCT_LOADOUT = '_ffla_product_loadout_id'; // Product page the line was added from.
    const META_SET_DISCOUNT    = '_ffla_set_discount_flag';  // Legacy, no longer written.
    const META_DISCOUNT_PCT    = '_ffla_loadout_discount_pct'; // Legacy, never trusted.
    const META_IS_BONUS        = '_ffla_loadout_is_bonus';
    const META_ITEM_ID         = '_ffla_loadout_item_id';

    // "Add cart" (entire tier): one inseparable line holding the main product
    // and every tier item.
    const META_TIER_BUNDLE       = '_ffla_tier_bundle';        // flag = 1
    const META_TIER_BUNDLE_ITEMS = '_ffla_tier_bundle_items';  // array of items (price snapshot)
    const META_TIER_BUNDLE_TOTAL = '_ffla_tier_bundle_total';  // Legacy, never trusted.
    const META_TIER_BUNDLE_HASH  = '_ffla_tier_bundle_hash';   // unique key
    const META_TIER_BUNDLE_SIG   = '_ffla_tier_bundle_sig';    // server signature of the snapshot

    // Order line meta: component stock this module changed for a bundle line.
    const META_BUNDLE_STOCK = '_ffla_tier_bundle_stock';

    /** @var array|null Context for the next add_to_cart() made by this class. */
    private static $pending_context = null;

    /** @var bool Re-entrancy guard for sync_bonus_items(). */
    private static $syncing = false;

    public static function init(): void
    {
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_cart_item_from_session', [__CLASS__, 'restore_cart_item_meta'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_loadout_pricing'], 20);
        add_action('woocommerce_cart_loaded_from_session', [__CLASS__, 'sync_bonus_items'], 10);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_cart_item_meta'], 10, 2);
        add_filter('woocommerce_cart_item_quantity', [__CLASS__, 'lock_bonus_quantity'], 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_order_line_item_meta'], 10, 4);
        add_action('woocommerce_cart_item_removed', [__CLASS__, 'on_cart_item_removed'], 10, 2);
        add_action('woocommerce_after_cart_item_quantity_update', [__CLASS__, 'on_cart_item_quantity_updated'], 10, 4);

        // Component stock for tier-bundle lines.
        add_action('woocommerce_reduce_order_stock', [__CLASS__, 'reduce_component_stock'], 10, 1);
        add_action('woocommerce_restore_order_stock', [__CLASS__, 'restore_component_stock'], 10, 1);
        add_action('woocommerce_refund_created', [__CLASS__, 'restock_refunded_components'], 10, 2);

        // AJAX handlers for the storefront.
        add_action('wp_ajax_loadout_add_item', [__CLASS__, 'ajax_add_item']);
        add_action('wp_ajax_nopriv_loadout_add_item', [__CLASS__, 'ajax_add_item']);
        add_action('wp_ajax_loadout_add_tier', [__CLASS__, 'ajax_add_tier']);
        add_action('wp_ajax_nopriv_loadout_add_tier', [__CLASS__, 'ajax_add_tier']);
        add_action('wp_ajax_loadout_get_cart_summary', [__CLASS__, 'ajax_get_cart_summary']);
        add_action('wp_ajax_nopriv_loadout_get_cart_summary', [__CLASS__, 'ajax_get_cart_summary']);
    }

    /* ── Cart line data ────────────────────────────────────────────────── */

    /**
     * Attach loadout data to a new cart line. Only lines added by this class
     * (with a validated $pending_context) get any; the request is never read.
     */
    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        $context = self::$pending_context;
        if (!is_array($context)) {
            return $cart_item_data;
        }

        $loadout_id      = (int) ($context['loadout_id'] ?? 0);
        $product_loadout = (int) ($context['product_loadout_id'] ?? 0);
        if (!$loadout_id && !$product_loadout) {
            return $cart_item_data;
        }

        $cart_item_data[self::META_LOADOUT_ID]      = $loadout_id;
        $cart_item_data[self::META_TIER_ID]         = (int) ($context['tier_id'] ?? 0);
        $cart_item_data[self::META_TIER_SLUG]       = (string) ($context['tier_slug'] ?? '');
        $cart_item_data[self::META_SOURCE]          = 'widget';
        $cart_item_data[self::META_PRODUCT_LOADOUT] = $product_loadout;
        $cart_item_data[self::META_ITEM_ID]         = (int) ($context['item_id'] ?? 0);
        $cart_item_data[self::META_IS_BONUS]        = !empty($context['is_bonus']) ? 1 : 0;

        if (!empty($context['tier_bundle'])) {
            $items = array_values((array) ($context['bundle_items'] ?? []));
            $cart_item_data[self::META_TIER_BUNDLE]       = 1;
            $cart_item_data[self::META_TIER_BUNDLE_ITEMS] = $items;
            $cart_item_data[self::META_TIER_BUNDLE_SIG]   = self::bundle_signature($items);
            $cart_item_data[self::META_TIER_BUNDLE_HASH]  = (string) ($context['bundle_hash'] ?? wp_generate_password(16, false, false));
            // Inseparable: the hash is the unique key so it never merges with another line.
            $cart_item_data['unique_key'] = $cart_item_data[self::META_TIER_BUNDLE_HASH];
            return $cart_item_data;
        }

        // Every loadout add is its own line so items never merge.
        $cart_item_data['unique_key'] = md5(wp_json_encode([
            $loadout_id, $cart_item_data[self::META_TIER_ID], $product_loadout,
            $cart_item_data[self::META_ITEM_ID], $cart_item_data[self::META_IS_BONUS], microtime(),
        ]));

        return $cart_item_data;
    }

    public static function restore_cart_item_meta($cart_item, $values)
    {
        foreach ([
            self::META_LOADOUT_ID, self::META_TIER_ID, self::META_TIER_SLUG, self::META_SOURCE,
            self::META_PRODUCT_LOADOUT, self::META_SET_DISCOUNT,
            self::META_IS_BONUS, self::META_ITEM_ID,
            self::META_TIER_BUNDLE, self::META_TIER_BUNDLE_ITEMS,
            self::META_TIER_BUNDLE_HASH, self::META_TIER_BUNDLE_SIG,
        ] as $key) {
            if (isset($values[$key])) {
                $cart_item[$key] = $values[$key];
            }
        }
        return $cart_item;
    }

    /* ── Configuration lookups ─────────────────────────────────────────── */

    /**
     * A global tier together with its loadout, only when the loadout is active.
     *
     * @return array{tier: Loadout_Tier, loadout: Loadout}|null
     */
    private static function active_global_tier(int $tier_id): ?array
    {
        if ($tier_id <= 0) {
            return null;
        }
        $tier = Loadout_Tier::get($tier_id);
        if (!$tier) {
            return null;
        }
        $loadout = Loadout::get((int) $tier->get_loadout_id());
        if (!$loadout || !$loadout->get_status()) {
            return null;
        }
        return ['tier' => $tier, 'loadout' => $loadout];
    }

    /**
     * True when the product page $product_id is linked to the active global
     * loadout $loadout_id.
     */
    private static function product_context_matches(int $product_id, int $loadout_id): bool
    {
        if ($product_id <= 0 || $loadout_id <= 0) {
            return false;
        }
        $config = Loadout_Product_Admin::get_product_config($product_id);
        return $config['type'] === 'global'
            && $config['loadout'] instanceof Loadout
            && (int) $config['loadout']->get_id() === $loadout_id;
    }

    /**
     * The configured item for $product_id in a per-product tier array.
     */
    private static function custom_tier_item(array $tier, int $product_id): ?array
    {
        foreach ((array) ($tier['items'] ?? []) as $item) {
            if ((int) ($item['product_id'] ?? 0) === $product_id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Product ID of a cart line (the variation when there is one).
     */
    private static function line_product_id(array $item): int
    {
        if (isset($item['data']) && is_object($item['data']) && method_exists($item['data'], 'get_id')) {
            return (int) $item['data']->get_id();
        }
        $variation = (int) ($item['variation_id'] ?? 0);
        return $variation ?: (int) ($item['product_id'] ?? 0);
    }

    private static function is_loadout_line(array $item): bool
    {
        return !empty($item[self::META_LOADOUT_ID]) || !empty($item[self::META_PRODUCT_LOADOUT]);
    }

    /**
     * Key of the tier a cart line belongs to: "g:<tier id>" for global tiers,
     * "p:<product id>:<slug>" for per-product tiers, '' for none.
     */
    public static function tier_key(array $item): string
    {
        $loadout_id = (int) ($item[self::META_LOADOUT_ID] ?? 0);
        $tier_id    = (int) ($item[self::META_TIER_ID] ?? 0);
        if ($loadout_id && $tier_id) {
            return 'g:' . $tier_id;
        }
        $product_loadout = (int) ($item[self::META_PRODUCT_LOADOUT] ?? 0);
        $slug            = (string) ($item[self::META_TIER_SLUG] ?? '');
        if (!$loadout_id && $product_loadout && $slug !== '') {
            return 'p:' . $product_loadout . ':' . $slug;
        }
        return '';
    }

    /**
     * Units a (non-bonus) line contributes to its tier: the line quantity, or
     * for an "entire tier" line the quantity of every tier item it holds
     * (main product excluded) times the line quantity.
     */
    private static function line_units(array $item): int
    {
        $qty = max(0, (int) ($item['quantity'] ?? 0));
        if (!empty($item[self::META_TIER_BUNDLE])) {
            $per_bundle = 0;
            foreach ((array) ($item[self::META_TIER_BUNDLE_ITEMS] ?? []) as $component) {
                if (empty($component['is_anchor'])) {
                    $per_bundle += max(1, (int) ($component['quantity'] ?? 1));
                }
            }
            return $per_bundle * $qty;
        }
        return $qty;
    }

    /**
     * Units in the cart per tier key (bonus lines excluded).
     *
     * @return array<string,int>
     */
    public static function tier_units($cart): array
    {
        $units = [];
        foreach ($cart->get_cart() as $item) {
            if (!empty($item[self::META_IS_BONUS])) {
                continue;
            }
            $key = self::tier_key($item);
            if ($key === '') {
                continue;
            }
            $units[$key] = ($units[$key] ?? 0) + self::line_units($item);
        }
        return $units;
    }

    /**
     * Bonus rule of a tier key: ['product_id' => int, 'threshold' => int] or null.
     */
    private static function bonus_rule(string $tier_key): ?array
    {
        if (strpos($tier_key, 'g:') === 0) {
            $global = self::active_global_tier((int) substr($tier_key, 2));
            if (!$global) {
                return null;
            }
            return [
                'product_id' => (int) $global['tier']->get_bonus_product_id(),
                'threshold'  => (int) $global['tier']->get_threshold_items(),
            ];
        }
        if (strpos($tier_key, 'p:') === 0) {
            $parts = explode(':', $tier_key, 3);
            if (count($parts) !== 3) {
                return null;
            }
            $tier = Loadout_Product_Admin::find_custom_tier((int) $parts[1], $parts[2]);
            if (!$tier) {
                return null;
            }
            return [
                'product_id' => (int) ($tier['bonus_product_id'] ?? 0),
                'threshold'  => (int) ($tier['threshold_items'] ?? 0),
            ];
        }
        return null;
    }

    /**
     * Whether the bonus of $tier_key is earned with $units in the cart.
     */
    private static function bonus_earned(?array $rule, int $units): bool
    {
        return $rule !== null
            && $rule['product_id'] > 0
            && $rule['threshold'] > 0
            && $units >= $rule['threshold'];
    }

    /**
     * Loadout discount (percent) for an individually added line, re-derived
     * from the stored configuration. 0 when the line does not match it.
     */
    public static function resolve_item_discount(array $line, int $product_id): float
    {
        $item_id = (int) ($line[self::META_ITEM_ID] ?? 0);
        if ($item_id) {
            $item = Loadout_Tier_Item::get($item_id);
            if (!$item || (int) $item->get_product_id() !== $product_id) {
                return 0.0;
            }
            $global = self::active_global_tier((int) $item->get_tier_id());
            if (!$global) {
                return 0.0;
            }
            return Loadout_Pricing::combine($item->get_discount_pct(), $global['tier']->get_accessory_discount());
        }

        $loadout_id      = (int) ($line[self::META_LOADOUT_ID] ?? 0);
        $product_loadout = (int) ($line[self::META_PRODUCT_LOADOUT] ?? 0);
        $slug            = (string) ($line[self::META_TIER_SLUG] ?? '');
        if (!$loadout_id && $product_loadout && $slug !== '') {
            $tier = Loadout_Product_Admin::find_custom_tier($product_loadout, $slug);
            $item = $tier ? self::custom_tier_item($tier, $product_id) : null;
            if (!$item) {
                return 0.0;
            }
            return Loadout_Pricing::combine($item['discount_pct'] ?? 0, $tier['accessory_discount'] ?? 0);
        }

        return 0.0;
    }

    /**
     * Validate an "Add" request against the stored configuration.
     *
     * @param array $req Raw request values: loadout_id, tier_id, tier_slug, item_id, product_loadout_id.
     * @return array|WP_Error Context for add_cart_item_data().
     */
    public static function validate_item_request(array $req, int $product_id)
    {
        $item_id         = absint($req['item_id'] ?? 0);
        $loadout_id      = absint($req['loadout_id'] ?? 0);
        $tier_id         = absint($req['tier_id'] ?? 0);
        $tier_slug       = sanitize_title((string) ($req['tier_slug'] ?? ''));
        $product_loadout = absint($req['product_loadout_id'] ?? 0);

        if ($product_id <= 0) {
            return new WP_Error('loadout_invalid', __('Invalid product.', 'ffl-funnels-addons'));
        }
        $mismatch    = new WP_Error('loadout_mismatch', __('This product is not part of that loadout.', 'ffl-funnels-addons'));
        $unavailable = new WP_Error('loadout_unavailable', __('This loadout is not available.', 'ffl-funnels-addons'));

        // 1. An item of a global tier.
        if ($item_id) {
            $item = Loadout_Tier_Item::get($item_id);
            if (!$item || (int) $item->get_product_id() !== $product_id) {
                return $mismatch;
            }
            $global = self::active_global_tier((int) $item->get_tier_id());
            if (!$global) {
                return $unavailable;
            }
            $real_loadout = (int) $global['loadout']->get_id();
            $real_tier    = (int) $global['tier']->get_id();
            if (($tier_id && $tier_id !== $real_tier) || ($loadout_id && $loadout_id !== $real_loadout)) {
                return $mismatch;
            }
            return [
                'loadout_id'         => $real_loadout,
                'tier_id'            => $real_tier,
                'tier_slug'          => (string) $global['tier']->get_slug(),
                'product_loadout_id' => self::product_context_matches($product_loadout, $real_loadout) ? $product_loadout : 0,
                'item_id'            => $item_id,
                'is_bonus'           => 0,
            ];
        }

        // 2. An item of a per-product tier.
        if ($tier_slug !== '' && $product_loadout && !$loadout_id && !$tier_id) {
            $tier = Loadout_Product_Admin::find_custom_tier($product_loadout, $tier_slug);
            if (!$tier || !self::custom_tier_item($tier, $product_id)) {
                return $mismatch;
            }
            return [
                'loadout_id'         => 0,
                'tier_id'            => 0,
                'tier_slug'          => (string) $tier['slug'],
                'product_loadout_id' => $product_loadout,
                'item_id'            => 0,
                'is_bonus'           => 0,
            ];
        }

        // 3. The hero (anchor) product of a global loadout.
        if ($loadout_id && !$tier_id) {
            $loadout = Loadout::get($loadout_id);
            if (!$loadout || !$loadout->get_status()) {
                return $unavailable;
            }
            if ((int) $loadout->get_anchor_product_id() !== $product_id) {
                return $mismatch;
            }
            return [
                'loadout_id'         => $loadout_id,
                'tier_id'            => 0,
                'tier_slug'          => '',
                'product_loadout_id' => 0,
                'item_id'            => 0,
                'is_bonus'           => 0,
            ];
        }

        return $mismatch;
    }

    /**
     * Validate an "Add cart" (entire tier) request and load the tier.
     *
     * The main product is the product page the widget is on (when that page
     * resolves to this tier), otherwise the global loadout's hero product.
     *
     * @return array|WP_Error
     */
    public static function resolve_tier_request(array $req)
    {
        $loadout_id      = absint($req['loadout_id'] ?? 0);
        $tier_id         = absint($req['tier_id'] ?? 0);
        $tier_slug       = sanitize_title((string) ($req['tier_slug'] ?? ''));
        $product_loadout = absint($req['product_loadout_id'] ?? 0);

        $not_found = new WP_Error('loadout_tier_not_found', __('Tier not found.', 'ffl-funnels-addons'));

        if ($tier_id) {
            $global = self::active_global_tier($tier_id);
            if (!$global) {
                return $not_found;
            }
            $real_loadout = (int) $global['loadout']->get_id();
            if ($loadout_id && $loadout_id !== $real_loadout) {
                return $not_found;
            }
            $in_context = self::product_context_matches($product_loadout, $real_loadout);
            $items      = [];
            foreach ($global['tier']->get_items() as $item) {
                $items[] = [
                    'product_id'   => (int) $item->get_product_id(),
                    'quantity'     => max(1, (int) $item->get_quantity()),
                    'discount_pct' => (float) $item->get_discount_pct(),
                ];
            }
            return [
                'loadout_id'         => $real_loadout,
                'tier_id'            => $tier_id,
                'tier_slug'          => (string) $global['tier']->get_slug(),
                'product_loadout_id' => $in_context ? $product_loadout : 0,
                'anchor_id'          => $in_context ? $product_loadout : (int) $global['loadout']->get_anchor_product_id(),
                'tier_name'          => (string) $global['tier']->get_name(),
                'accessory'          => (float) $global['tier']->get_accessory_discount(),
                'set'                => (float) $global['tier']->get_set_discount_pct(),
                'items'              => $items,
            ];
        }

        if ($product_loadout && $tier_slug !== '' && !$loadout_id) {
            $tier = Loadout_Product_Admin::find_custom_tier($product_loadout, $tier_slug);
            if (!$tier) {
                return $not_found;
            }
            $items = [];
            foreach ((array) ($tier['items'] ?? []) as $item) {
                $items[] = [
                    'product_id'   => (int) ($item['product_id'] ?? 0),
                    'quantity'     => max(1, (int) ($item['quantity'] ?? 1)),
                    'discount_pct' => (float) ($item['discount_pct'] ?? 0),
                ];
            }
            return [
                'loadout_id'         => 0,
                'tier_id'            => 0,
                'tier_slug'          => (string) $tier['slug'],
                'product_loadout_id' => $product_loadout,
                'anchor_id'          => $product_loadout,
                'tier_name'          => (string) ($tier['name'] ?? ''),
                'accessory'          => (float) ($tier['accessory_discount'] ?? 0),
                'set'                => (float) ($tier['set_discount_pct'] ?? 0),
                'items'              => $items,
            ];
        }

        return $not_found;
    }

    /**
     * Whether a product can be added on its own at $qty units.
     */
    public static function is_addable($product, int $qty): bool
    {
        if (!$product || !$product->is_purchasable() || !$product->is_in_stock() || !$product->has_enough_stock($qty)) {
            return false;
        }
        if ($product->is_type('variable')) {
            return false; // A parent needs a chosen variation.
        }
        if ($product->is_type('variation') && in_array('', (array) $product->get_variation_attributes(), true)) {
            return false; // "Any" attribute: WooCommerce needs the customer to pick it.
        }
        return true;
    }

    /**
     * Price snapshot for an "entire tier" line.
     *
     * Items that cannot be bought (not purchasable, out of stock, not enough
     * stock) are left out and listed in 'skipped'. Set Discount % is only
     * given when every tier item made it into the line.
     *
     * @param array $ctx Result of resolve_tier_request().
     * @return array{items: array, total: float, complete: bool, skipped: string[]}|WP_Error
     */
    public static function build_tier_bundle(array $ctx)
    {
        $anchor_id = (int) ($ctx['anchor_id'] ?? 0);
        $anchor    = $anchor_id ? wc_get_product($anchor_id) : null;
        $anchor_ok = $anchor && self::is_addable($anchor, 1);

        $rows     = [];
        $skipped  = [];
        foreach ((array) ($ctx['items'] ?? []) as $item) {
            $pid = (int) ($item['product_id'] ?? 0);
            if ($pid <= 0 || ($anchor_id && $pid === $anchor_id)) {
                continue; // The main product is never added twice.
            }
            $qty     = max(1, (int) ($item['quantity'] ?? 1));
            $product = wc_get_product($pid);
            if (!self::is_addable($product, $qty)) {
                $skipped[] = $product ? $product->get_name() : ('#' . $pid);
                continue;
            }
            $rows[] = [$product, $qty, (float) ($item['discount_pct'] ?? 0)];
        }
        $complete = empty($skipped);

        $bundle = [];
        $total  = 0.0;
        $push   = function ($product, int $qty, float $pct, bool $is_anchor) use (&$bundle, &$total) {
            $prices = Loadout_Pricing::product_prices($product);
            $final  = Loadout_Pricing::unit_price($prices['regular'], $prices['current'], $pct);
            $bundle[] = [
                'product_id'   => (int) $product->get_id(),
                'name'         => $product->get_name(),
                'quantity'     => $qty,
                'original'     => Loadout_Pricing::reference_price($prices['regular'], $prices['current']),
                'discount_pct' => $pct,
                'final'        => $final,
                'is_anchor'    => $is_anchor ? 1 : 0,
            ];
            $total += $final * $qty;
        };

        if ($anchor_ok) {
            // The main product keeps its own price: no tier discounts.
            $push($anchor, 1, 0.0, true);
        }
        $tier_pct = Loadout_Pricing::combine($ctx['accessory'] ?? 0, $complete ? ($ctx['set'] ?? 0) : 0);
        foreach ($rows as [$product, $qty, $item_pct]) {
            $push($product, $qty, Loadout_Pricing::combine($item_pct, $tier_pct), false);
        }

        if (empty($bundle) || ($anchor_ok && count($bundle) === 1)) {
            return new WP_Error('loadout_empty_tier', __('No purchasable items in this tier.', 'ffl-funnels-addons'));
        }

        return [
            'items'    => $bundle,
            'total'    => max(0.0, $total),
            'complete' => $complete,
            'skipped'  => $skipped,
        ];
    }

    /**
     * Server signature of a bundle price snapshot.
     */
    public static function bundle_signature(array $items): string
    {
        return wp_hash('ffla_loadout_bundle|' . wp_json_encode(array_values($items)));
    }

    /**
     * Snapshot re-priced at today's prices with no loadout discount. Used for
     * bundle lines whose snapshot cannot be verified or rebuilt.
     */
    private static function reprice_at_current(array $items): array
    {
        $out = [];
        foreach ($items as $component) {
            $pid     = (int) ($component['product_id'] ?? 0);
            $product = $pid > 0 ? wc_get_product($pid) : null;
            if (!$product) {
                continue;
            }
            $prices = Loadout_Pricing::product_prices($product);
            $out[]  = [
                'product_id'   => (int) $product->get_id(),
                'name'         => $product->get_name(),
                'quantity'     => max(1, (int) ($component['quantity'] ?? 1)),
                'original'     => Loadout_Pricing::reference_price($prices['regular'], $prices['current']),
                'discount_pct' => 0.0,
                'final'        => Loadout_Pricing::unit_price($prices['regular'], $prices['current'], 0),
                'is_anchor'    => !empty($component['is_anchor']) ? 1 : 0,
            ];
        }
        return $out;
    }

    /* ── Pricing ───────────────────────────────────────────────────────── */

    /**
     * Set loadout prices on cart lines. Idempotent: it may run several times
     * per request (WooCommerce recalculates after every add).
     */
    public static function apply_loadout_pricing($cart)
    {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        if (!$cart || !method_exists($cart, 'get_cart')) {
            return;
        }

        $units = self::tier_units($cart);

        foreach ($cart->get_cart() as $cart_key => $item) {
            if (!self::is_loadout_line($item)) {
                continue;
            }
            $product = $item['data'] ?? null;
            if (!is_object($product)) {
                continue;
            }

            if (!empty($item[self::META_TIER_BUNDLE])) {
                self::price_bundle_line($cart, $cart_key, $item);
                continue;
            }

            $product_id = self::line_product_id($item);

            if (!empty($item[self::META_IS_BONUS])) {
                $key  = self::tier_key($item);
                $rule = self::bonus_rule($key);
                if (self::bonus_earned($rule, $units[$key] ?? 0) && $rule['product_id'] === $product_id) {
                    // One free unit per tier.
                    if ((int) ($item['quantity'] ?? 1) !== 1) {
                        $cart->cart_contents[$cart_key]['quantity'] = 1;
                    }
                    $product->set_price(0);
                }
                // A bonus that is not (or no longer) earned keeps its normal price
                // until sync_bonus_items() removes it.
                continue;
            }

            $pct = self::resolve_item_discount($item, $product_id);
            if ($pct > 0) {
                $prices = Loadout_Pricing::product_prices($product);
                $product->set_price(Loadout_Pricing::unit_price($prices['regular'], $prices['current'], $pct));
            }
        }
    }

    /**
     * Price an "entire tier" line from its server-made snapshot. A snapshot
     * without a valid signature (lines from before this check existed) is
     * rebuilt from the configuration, or charged at today's full prices.
     */
    private static function price_bundle_line($cart, string $cart_key, array $item): void
    {
        $items = array_values((array) ($item[self::META_TIER_BUNDLE_ITEMS] ?? []));
        $sig   = (string) ($item[self::META_TIER_BUNDLE_SIG] ?? '');

        if ($sig === '' || !hash_equals(self::bundle_signature($items), $sig)) {
            $items = [];
            $ctx   = self::resolve_tier_request([
                'loadout_id'         => $item[self::META_LOADOUT_ID] ?? 0,
                'tier_id'            => $item[self::META_TIER_ID] ?? 0,
                'tier_slug'          => $item[self::META_TIER_SLUG] ?? '',
                'product_loadout_id' => $item[self::META_PRODUCT_LOADOUT] ?? 0,
            ]);
            if (!is_wp_error($ctx)) {
                $built = self::build_tier_bundle($ctx);
                if (!is_wp_error($built) && (int) $built['items'][0]['product_id'] === self::line_product_id($item)) {
                    $items = $built['items'];
                }
            }
            if (empty($items)) {
                $items = self::reprice_at_current((array) ($item[self::META_TIER_BUNDLE_ITEMS] ?? []));
            }
            $cart->cart_contents[$cart_key][self::META_TIER_BUNDLE_ITEMS] = $items;
            $cart->cart_contents[$cart_key][self::META_TIER_BUNDLE_SIG]   = self::bundle_signature($items);
        }

        $total = 0.0;
        foreach ($items as $component) {
            $total += (float) ($component['final'] ?? 0) * max(1, (int) ($component['quantity'] ?? 1));
        }
        $item['data']->set_price(max(0.0, $total));
    }

    /* ── Bonus product ─────────────────────────────────────────────────── */

    /**
     * Add the bonus product of every tier whose Perk Threshold is reached,
     * remove bonuses that are no longer earned, and keep each at quantity 1.
     */
    public static function sync_bonus_items($cart = null): void
    {
        if (self::$syncing) {
            return;
        }
        if (!$cart && function_exists('WC')) {
            $cart = WC()->cart;
        }
        if (!$cart || !method_exists($cart, 'get_cart') || (is_admin() && !wp_doing_ajax())) {
            return;
        }

        self::$syncing = true;
        try {
            $units       = self::tier_units($cart);
            $bonus_lines = [];
            foreach ($cart->get_cart() as $cart_key => $item) {
                if (!empty($item[self::META_IS_BONUS])) {
                    $bonus_lines[self::tier_key($item)][] = $cart_key;
                }
            }

            $keys = array_unique(array_merge(array_keys($units), array_keys($bonus_lines)));
            foreach ($keys as $key) {
                $rule   = $key === '' ? null : self::bonus_rule($key);
                $earned = self::bonus_earned($rule, $units[$key] ?? 0);
                $kept   = null;

                foreach ($bonus_lines[$key] ?? [] as $cart_key) {
                    $line = $cart->cart_contents[$cart_key] ?? null;
                    if (!$line) {
                        continue;
                    }
                    if ($earned && $kept === null && self::line_product_id($line) === $rule['product_id']) {
                        $kept = $cart_key;
                        if ((int) $line['quantity'] !== 1) {
                            $cart->cart_contents[$cart_key]['quantity'] = 1;
                        }
                        continue;
                    }
                    $cart->remove_cart_item($cart_key);
                }

                if ($earned && $kept === null) {
                    $bonus = wc_get_product($rule['product_id']);
                    if (!self::is_addable($bonus, 1)) {
                        continue;
                    }
                    $context = ['is_bonus' => 1, 'item_id' => 0];
                    if (strpos($key, 'g:') === 0) {
                        $tier = Loadout_Tier::get((int) substr($key, 2));
                        $context += [
                            'loadout_id'         => $tier ? (int) $tier->get_loadout_id() : 0,
                            'tier_id'            => (int) substr($key, 2),
                            'tier_slug'          => $tier ? (string) $tier->get_slug() : '',
                            'product_loadout_id' => 0,
                        ];
                    } else {
                        $parts    = explode(':', $key, 3);
                        $context += [
                            'loadout_id'         => 0,
                            'tier_id'            => 0,
                            'tier_slug'          => $parts[2],
                            'product_loadout_id' => (int) $parts[1],
                        ];
                    }
                    self::$pending_context = $context;
                    try {
                        $cart->add_to_cart($rule['product_id'], 1);
                    } finally {
                        self::$pending_context = null;
                    }
                }
            }
        } finally {
            self::$syncing = false;
        }
    }

    /**
     * The bonus is one free unit: show a fixed quantity in the cart.
     */
    public static function lock_bonus_quantity($product_quantity, $cart_item_key, $cart_item = [])
    {
        if (!empty($cart_item[self::META_IS_BONUS])) {
            return sprintf('1 <input type="hidden" name="cart[%s][qty]" value="1" />', esc_attr($cart_item_key));
        }
        return $product_quantity;
    }

    public static function on_cart_item_removed($cart_item_key, $cart): void
    {
        self::sync_bonus_items($cart);
    }

    public static function on_cart_item_quantity_updated($cart_item_key, $quantity, $old_quantity, $cart): void
    {
        self::sync_bonus_items($cart);
    }

    /* ── Display and order meta ────────────────────────────────────────── */

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

        // Bundle line: "Includes:" list of all contained products.
        if (!empty($cart_item[self::META_TIER_BUNDLE]) && !empty($cart_item[self::META_TIER_BUNDLE_ITEMS])) {
            $items         = (array) $cart_item[self::META_TIER_BUNDLE_ITEMS];
            $plain         = [];
            $html_lines    = [];
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

                $line_original  = $original * $qty;
                $line_final     = $final * $qty;
                $line_savings   = max(0, $line_original - $line_final);
                $total_savings += $line_savings;

                // Plain text version for emails / non-HTML contexts.
                $plain_label = $qty > 1 ? sprintf('%s × %d', $name, $qty) : $name;
                $plain[]     = $plain_label . ' — ' . wp_strip_all_tags(wc_price($line_final));

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
            self::META_PRODUCT_LOADOUT,
            self::META_IS_BONUS, self::META_ITEM_ID,
            self::META_TIER_BUNDLE, self::META_TIER_BUNDLE_ITEMS,
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

    /* ── Component stock for "entire tier" lines ──────────────────────── */

    /**
     * Units of each component this module must move for a bundle line, on top
     * of what WooCommerce moves for the line's own product (one unit per line
     * quantity of the first component).
     *
     * @return array<int,int> product ID => units
     */
    public static function bundle_stock_plan(array $bundle_items, int $line_product_id, int $line_qty): array
    {
        $line_qty = max(1, $line_qty);
        $plan     = [];
        $own_seen = false;
        foreach ($bundle_items as $component) {
            $pid = (int) ($component['product_id'] ?? 0);
            $qty = (int) ($component['quantity'] ?? 1);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $units = $qty * $line_qty;
            if (!$own_seen && $pid === $line_product_id) {
                $units   -= $line_qty; // WooCommerce already handles these.
                $own_seen = true;
            }
            if ($units > 0) {
                $plan[$pid] = ($plan[$pid] ?? 0) + $units;
            }
        }
        return $plan;
    }

    /**
     * What orders placed before the stock fix had reduced: every component at
     * its stored quantity (line quantity ignored), the line's own product
     * included. Used to undo those reductions exactly.
     *
     * @return array<int,int>
     */
    private static function legacy_stock_plan(array $bundle_items): array
    {
        $plan = [];
        foreach ($bundle_items as $component) {
            $pid = (int) ($component['product_id'] ?? 0);
            $qty = (int) ($component['quantity'] ?? 1);
            if ($pid > 0 && $qty > 0) {
                $product = wc_get_product($pid);
                if ($product && $product->managing_stock()) {
                    $plan[$pid] = ($plan[$pid] ?? 0) + $qty;
                }
            }
        }
        return $plan;
    }

    private static function line_item_product_id($item): int
    {
        $variation = (int) $item->get_variation_id();
        return $variation ?: (int) $item->get_product_id();
    }

    /**
     * "Name (#id) -2, Other (#id) -1" for order notes.
     */
    private static function stock_note(array $changes, string $sign): string
    {
        $parts = [];
        foreach ($changes as $pid => $qty) {
            $product = wc_get_product($pid);
            $parts[] = sprintf('%s (#%d) %s%d', $product ? $product->get_name() : '', $pid, $sign, $qty);
        }
        return implode(', ', $parts);
    }

    /**
     * Reduce component stock when the order's stock is reduced. Records what
     * was changed on the line so restore and refunds undo exactly that.
     */
    public static function reduce_component_stock($order): void
    {
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }

        $changed = [];
        foreach ($order->get_items() as $item) {
            $bundle_items = $item->get_meta(self::META_TIER_BUNDLE_ITEMS);
            if (empty($bundle_items)) {
                continue;
            }
            $record = $item->get_meta(self::META_BUNDLE_STOCK);
            if (is_array($record) && ($record['state'] ?? 'reduced') === 'reduced') {
                continue; // Already reduced for this order.
            }

            $plan = self::bundle_stock_plan((array) $bundle_items, self::line_item_product_id($item), (int) $item->get_quantity());
            $done = [];
            foreach ($plan as $pid => $qty) {
                $product = wc_get_product($pid);
                if ($product && $product->managing_stock()) {
                    wc_update_product_stock($product, $qty, 'decrease');
                    $done[$pid] = $qty;
                }
            }

            $item->update_meta_data(self::META_BUNDLE_STOCK, [
                'state'     => 'reduced',
                'reduced'   => $done,
                'restocked' => [],
                'line_qty'  => max(1, (int) $item->get_quantity()),
                'refunded'  => 0,
            ]);
            $item->save();
            foreach ($done as $pid => $qty) {
                $changed[$pid] = ($changed[$pid] ?? 0) + $qty;
            }
        }

        if ($changed) {
            /* translators: %s: list of products and quantities */
            $order->add_order_note(sprintf(__('Loadout bundle stock reduced: %s', 'ffl-funnels-addons'), self::stock_note($changed, '-')));
        }
    }

    /**
     * Give component stock back when the order's stock is restored (cancel,
     * failed payment, back to pending).
     */
    public static function restore_component_stock($order): void
    {
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }

        $changed = [];
        foreach ($order->get_items() as $item) {
            $bundle_items = $item->get_meta(self::META_TIER_BUNDLE_ITEMS);
            if (empty($bundle_items)) {
                continue;
            }

            $record = $item->get_meta(self::META_BUNDLE_STOCK);
            if (is_array($record) && ($record['state'] ?? 'reduced') === 'restored') {
                continue; // Already given back.
            }
            if (is_array($record)) {
                $back = [];
                foreach ((array) ($record['reduced'] ?? []) as $pid => $qty) {
                    $give = (int) $qty - (int) ($record['restocked'][$pid] ?? 0);
                    if ($give > 0) {
                        $back[(int) $pid] = $give;
                    }
                }
            } else {
                // Order placed before the stock fix: undo the old reduction.
                $back = self::legacy_stock_plan((array) $bundle_items);
            }

            foreach ($back as $pid => $qty) {
                $product = wc_get_product($pid);
                if ($product && $product->managing_stock()) {
                    wc_update_product_stock($product, $qty, 'increase');
                    $changed[$pid] = ($changed[$pid] ?? 0) + $qty;
                }
            }

            // Marked restored: a repeated restore does nothing, and a later
            // stock reduction (order reopened) runs again.
            $item->update_meta_data(self::META_BUNDLE_STOCK, [
                'state'     => 'restored',
                'reduced'   => [],
                'restocked' => [],
                'line_qty'  => max(1, (int) $item->get_quantity()),
                'refunded'  => 0,
            ]);
            $item->save();
        }

        if ($changed) {
            /* translators: %s: list of products and quantities */
            $order->add_order_note(sprintf(__('Loadout bundle stock restored: %s', 'ffl-funnels-addons'), self::stock_note($changed, '+')));
        }
    }

    /**
     * Restock components when a bundle line is refunded with "Restock refunded
     * items". Proportional to the refunded quantity; the last refunded unit
     * returns whatever is left.
     *
     * @param int   $refund_id
     * @param array $args Arguments passed to wc_create_refund().
     */
    public static function restock_refunded_components($refund_id, $args): void
    {
        if (empty($args['restock_items']) || empty($args['line_items']) || !is_array($args['line_items'])) {
            return;
        }
        $refund = wc_get_order($refund_id);
        $order  = $refund ? wc_get_order($refund->get_parent_id()) : null;
        if (!$order || !is_a($order, 'WC_Order')) {
            return;
        }

        $changed = [];
        foreach ($args['line_items'] as $item_id => $line) {
            $refund_qty = (int) ($line['qty'] ?? 0);
            if ($refund_qty <= 0) {
                continue;
            }
            $item = $order->get_item($item_id);
            if (!$item || !is_callable([$item, 'get_quantity'])) {
                continue;
            }
            $bundle_items = $item->get_meta(self::META_TIER_BUNDLE_ITEMS);
            if (empty($bundle_items)) {
                continue;
            }

            $record = $item->get_meta(self::META_BUNDLE_STOCK);
            if (is_array($record) && ($record['state'] ?? 'reduced') === 'restored') {
                continue; // Nothing is out of stock for this line any more.
            }
            if (!is_array($record)) {
                $stock_reduced = (bool) $order->get_data_store()->get_stock_reduced($order->get_id());
                if (!$stock_reduced) {
                    continue;
                }
                $record = [
                    'state'     => 'reduced',
                    'reduced'   => self::legacy_stock_plan((array) $bundle_items),
                    'restocked' => [],
                    'line_qty'  => max(1, (int) $item->get_quantity()),
                    'refunded'  => 0,
                ];
            }

            $line_qty = max(1, (int) ($record['line_qty'] ?? 1));
            $refunded = min($line_qty, (int) ($record['refunded'] ?? 0) + $refund_qty);
            foreach ((array) ($record['reduced'] ?? []) as $pid => $total) {
                $total  = (int) $total;
                $target = $refunded >= $line_qty ? $total : intdiv($total * $refunded, $line_qty);
                $done   = (int) ($record['restocked'][$pid] ?? 0);
                $give   = $target - $done;
                if ($give <= 0) {
                    continue;
                }
                $product = wc_get_product((int) $pid);
                if ($product && $product->managing_stock()) {
                    wc_update_product_stock($product, $give, 'increase');
                    $changed[(int) $pid] = ($changed[(int) $pid] ?? 0) + $give;
                }
                $record['restocked'][$pid] = $done + $give;
            }
            $record['refunded'] = $refunded;
            $item->update_meta_data(self::META_BUNDLE_STOCK, $record);
            $item->save();
        }

        if ($changed) {
            /* translators: %s: list of products and quantities */
            $order->add_order_note(sprintf(__('Loadout bundle stock restocked after refund: %s', 'ffl-funnels-addons'), self::stock_note($changed, '+')));
        }
    }

    /* ── AJAX ──────────────────────────────────────────────────────────── */

    /**
     * Standard WooCommerce add-to-cart response payload (fragments, hash,
     * count, total) so the mini-cart refreshes without a reload.
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

    /**
     * First WooCommerce error notice (e.g. "not enough stock"), cleared so it
     * does not show again on the next page.
     */
    private static function take_error_notice(string $fallback): string
    {
        if (!function_exists('wc_get_notices')) {
            return $fallback;
        }
        $errors = wc_get_notices('error');
        if (empty($errors)) {
            return $fallback;
        }
        wc_clear_notices();
        $first = reset($errors);
        $text  = is_array($first) ? ($first['notice'] ?? '') : (string) $first;
        $text  = trim(wp_strip_all_tags((string) $text));
        return $text !== '' ? $text : $fallback;
    }

    private static function request_values(): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked by the caller.
        return [
            'loadout_id'         => isset($_POST['loadout_id']) ? absint($_POST['loadout_id']) : 0,
            'tier_id'            => isset($_POST['tier_id']) ? absint($_POST['tier_id']) : 0,
            'tier_slug'          => isset($_POST['tier_slug']) ? sanitize_title(wp_unslash($_POST['tier_slug'])) : '',
            'item_id'            => isset($_POST['item_id']) ? absint($_POST['item_id']) : 0,
            'product_loadout_id' => isset($_POST['product_loadout_id']) ? absint($_POST['product_loadout_id']) : 0,
        ];
        // phpcs:enable
    }

    public static function ajax_add_item(): void
    {
        check_ajax_referer('loadout_frontend', 'nonce');

        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $quantity   = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;

        $context = self::validate_item_request(self::request_values(), $product_id);
        if (is_wp_error($context)) {
            wp_send_json_error(['message' => $context->get_error_message()]);
        }

        self::$pending_context = $context;
        try {
            $cart_key = WC()->cart->add_to_cart($product_id, $quantity);
        } finally {
            self::$pending_context = null;
        }

        if (!$cart_key) {
            wp_send_json_error(['message' => self::take_error_notice(__('Could not add to cart.', 'ffl-funnels-addons'))]);
        }

        self::sync_bonus_items(WC()->cart);

        wp_send_json_success(self::build_cart_response([
            'cart_key' => $cart_key,
        ]));
    }

    /**
     * Add the entire tier as a single inseparable cart line: the main product
     * plus every available tier item, priced from the stored configuration.
     */
    public static function ajax_add_tier(): void
    {
        check_ajax_referer('loadout_frontend', 'nonce');

        $context = self::resolve_tier_request(self::request_values());
        if (is_wp_error($context)) {
            wp_send_json_error(['message' => $context->get_error_message()]);
        }

        $built = self::build_tier_bundle($context);
        if (is_wp_error($built)) {
            wp_send_json_error(['message' => $built->get_error_message()]);
        }

        $representative = (int) $built['items'][0]['product_id'];

        self::$pending_context = [
            'loadout_id'         => $context['loadout_id'],
            'tier_id'            => $context['tier_id'],
            'tier_slug'          => $context['tier_slug'],
            'product_loadout_id' => $context['product_loadout_id'],
            'item_id'            => 0,
            'is_bonus'           => 0,
            'tier_bundle'        => 1,
            'bundle_items'       => $built['items'],
            'bundle_hash'        => wp_generate_password(16, false, false),
        ];
        try {
            $cart_key = WC()->cart->add_to_cart($representative, 1);
        } finally {
            self::$pending_context = null;
        }

        if (!$cart_key) {
            wp_send_json_error(['message' => self::take_error_notice(__('Could not add tier bundle to cart.', 'ffl-funnels-addons'))]);
        }

        self::sync_bonus_items(WC()->cart);

        wp_send_json_success(self::build_cart_response([
            'cart_key'     => $cart_key,
            'items_added'  => count($built['items']),
            'bundle_total' => wc_price($built['total']),
            'tier_name'    => $context['tier_name'],
            'skipped'      => $built['skipped'],
        ]));
    }

    /**
     * Cart panel / Cart Mirror / progress bar data.
     *
     * Scope: lines of one global loadout (loadout_id), of one product page's
     * loadout (product_loadout_id), or every loadout line (neither). Lines
     * that are not loadout lines are never listed.
     */
    public static function ajax_get_cart_summary(): void
    {
        check_ajax_referer('loadout_frontend', 'nonce');

        $loadout_id      = isset($_POST['loadout_id']) ? absint($_POST['loadout_id']) : 0;
        $product_loadout = isset($_POST['product_loadout_id']) ? absint($_POST['product_loadout_id']) : 0;

        // Run pricing so lines reflect their loadout price.
        WC()->cart->calculate_totals();

        $items       = [];
        $savings     = 0.0;
        $count       = 0;
        $tier_counts = [];
        $slug_counts = [];

        foreach (WC()->cart->get_cart() as $cart_key => $ci) {
            if (!self::is_loadout_line($ci)) {
                continue;
            }
            $line_loadout = (int) ($ci[self::META_LOADOUT_ID] ?? 0);
            $line_product = (int) ($ci[self::META_PRODUCT_LOADOUT] ?? 0);
            if ($loadout_id && $line_loadout !== $loadout_id) {
                continue;
            }
            if (!$loadout_id && $product_loadout && ($line_loadout || $line_product !== $product_loadout)) {
                continue;
            }

            $product = $ci['data'];
            $qty     = (int) $ci['quantity'];
            $prices  = Loadout_Pricing::product_prices($product);
            $current = (float) $prices['current'];

            if (!empty($ci[self::META_TIER_BUNDLE]) && !empty($ci[self::META_TIER_BUNDLE_ITEMS])) {
                $reference = 0.0;
                foreach ((array) $ci[self::META_TIER_BUNDLE_ITEMS] as $bi) {
                    $bo = (float) ($bi['original'] ?? 0);
                    $bf = (float) ($bi['final'] ?? $bo);
                    $bq = max(1, (int) ($bi['quantity'] ?? 1));
                    $savings   += max(0, ($bo - $bf) * $bq * $qty);
                    $reference += $bo * $bq;
                }
            } else {
                $reference = Loadout_Pricing::reference_price($prices['regular'], $prices['current']);
                $savings  += max(0, ($reference - $current) * $qty);
            }

            $count += $qty;

            if (empty($ci[self::META_IS_BONUS])) {
                $units   = self::line_units($ci);
                $tier_id = (int) ($ci[self::META_TIER_ID] ?? 0);
                if ($line_loadout && $tier_id) {
                    $tier_counts[$tier_id] = ($tier_counts[$tier_id] ?? 0) + $units;
                } elseif (!$line_loadout && !empty($ci[self::META_TIER_SLUG])) {
                    $slug = (string) $ci[self::META_TIER_SLUG];
                    $slug_counts[$slug] = ($slug_counts[$slug] ?? 0) + $units;
                }
            }

            $items[] = [
                'name'     => $product->get_name(),
                'quantity' => $qty,
                'regular'  => wc_price($reference),
                'current'  => wc_price($current),
                'is_bonus' => !empty($ci[self::META_IS_BONUS]),
                'cart_key' => $cart_key,
            ];
        }

        wp_send_json_success([
            'items'       => $items,
            'savings'     => wc_price($savings),
            'count'       => $count,
            'tier_counts' => $tier_counts,
            'slug_counts' => $slug_counts,
            'total'       => WC()->cart->get_cart_total(),
        ]);
    }
}
