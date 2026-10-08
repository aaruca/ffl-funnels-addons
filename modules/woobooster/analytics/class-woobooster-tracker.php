<?php
/**
 * WooBooster Tracker — Attribution & Conversion Tracking.
 *
 * Tracks which WooBooster recommendations lead to add-to-cart and purchases.
 * Attribution is session-based: products shown by a rule are remembered in the
 * WooCommerce session and tagged when they are added to the cart.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Tracker
{

    const SESSION_KEY = 'woobooster_recommendations';
    const META_SOURCE_RULE = '_wb_source_rule';
    const META_ATTRIBUTION = '_wb_attribution_quantities';

    /**
     * Decimal places kept for attributed quantities. Proportional reductions
     * produce repeating decimals; four places keeps stored meta readable.
     */
    const QUANTITY_PRECISION = 4;

    /**
     * Option key for the add-to-cart counter.
     *
     * Shape: date key => [rule_id => count]. New counts use day keys
     * ('Y-m-d'); older versions wrote month keys ('Y-m'), which stay readable.
     */
    const COUNTER_OPTION = 'woobooster_atc_counter';

    /**
     * Day keys kept in the counter (older ones are pruned on write).
     */
    const COUNTER_DAYS_KEPT = 400;

    /**
     * Pseudo rule ID for Smart Recommendations served by the Bricks
     * `woobooster_smart` query type (no backing rule).
     *
     * Rolled up as a single row in the analytics dashboard so the panel
     * stays clean regardless of how many Smart loops a designer adds.
     */
    const SMART_PSEUDO_RULE_ID = -1;

    /**
     * Initialize hooks.
     */
    public function init()
    {
        // Attribution must not participate in WooCommerce's cart identity.
        // Attach it after the add succeeds, before WC persists the cart (10)
        // and calculates totals (20). Works for both new and merged lines.
        add_action('woocommerce_add_to_cart', array($this, 'track_add_to_cart'), 5, 6);
        add_action('woocommerce_after_cart_item_quantity_update', array($this, 'update_attributed_quantity'), 5, 4);

        // Order: persist attribution to order line item meta.
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'persist_order_item_meta'), 10, 4);
    }

    /**
     * Register a recommendation set (called from Bricks, Frontend, Shortcode).
     *
     * @param int   $rule_id     The matched rule ID.
     * @param array $product_ids The recommended product IDs.
     */
    public static function register_recommendation($rule_id, $product_ids)
    {
        $rule_id = (int) $rule_id;

        if (0 === $rule_id || empty($product_ids)) {
            return;
        }

        $pids = array_map('absint', $product_ids);

        if (function_exists('WC') && WC()->session) {
            $stored = WC()->session->get(self::SESSION_KEY, array());
            if (!is_array($stored)) {
                $stored = array();
            }
            foreach ($pids as $pid) {
                $stored[(int) $pid] = $rule_id;
            }
            if (count($stored) > 500) {
                $stored = array_slice($stored, -500, null, true);
            }
            WC()->session->set(self::SESSION_KEY, $stored);
        }
    }

    /**
     * Read attributed quantities from cart item values or order item meta.
     *
     * An explicit map takes precedence over the legacy tag, even when empty or
     * malformed. A legacy tag alone credits the whole line. Organic units are
     * implicit: line quantity minus the sum of the attributed quantities.
     *
     * @param array $values   Cart or order attribution metadata.
     * @param float $quantity Maximum attributable line quantity.
     * @return array Rule ID => attributed quantity.
     */
    public static function get_attribution_quantities($values, $quantity)
    {
        if (array_key_exists(self::META_ATTRIBUTION, $values)) {
            $raw = $values[self::META_ATTRIBUTION];
        } else {
            $rule_id = isset($values[self::META_SOURCE_RULE]) ? (int) $values[self::META_SOURCE_RULE] : 0;
            $raw = $rule_id ? array($rule_id => $quantity) : array();
        }
        return self::normalize_quantities($raw, $quantity);
    }

    /**
     * Attributed quantities of an order line; see get_attribution_quantities().
     *
     * @param WC_Order_Item_Product $item Order line item.
     * @return array Rule ID => attributed quantity.
     */
    public static function get_order_item_attribution($item)
    {
        $values = array(self::META_SOURCE_RULE => $item->get_meta(self::META_SOURCE_RULE));
        if ($item->meta_exists(self::META_ATTRIBUTION)) {
            $values[self::META_ATTRIBUTION] = $item->get_meta(self::META_ATTRIBUTION);
        }
        return self::get_attribution_quantities($values, $item->get_quantity());
    }

    /**
     * Validate a rule => quantity map and cap it to the line quantity.
     *
     * Drops zero or malformed rule IDs and non-positive or non-finite
     * quantities, rounds to QUANTITY_PRECISION, and scales the map down
     * proportionally when its sum exceeds the line.
     *
     * @param mixed $raw      Rule ID => quantity map; anything else is empty.
     * @param float $quantity Maximum attributable line quantity.
     * @return array Rule ID => attributed quantity.
     */
    public static function normalize_quantities($raw, $quantity)
    {
        $quantity = (float) $quantity;
        if (!is_array($raw) || !is_finite($quantity) || $quantity <= 0) {
            return array();
        }

        $quantities = array();
        foreach ($raw as $rule_id => $qty) {
            // Rule IDs can be positive or the Smart pseudo-ID (-1), never zero.
            if (!is_numeric($rule_id) || (string) (int) $rule_id !== (string) $rule_id || !is_numeric($qty)) {
                continue;
            }
            $rule_id = (int) $rule_id;
            $qty = (float) $qty;
            if (0 === $rule_id || !is_finite($qty) || $qty <= 0) {
                continue;
            }
            $qty = round(min($qty, $quantity), self::QUANTITY_PRECISION);
            if ($qty > 0) {
                $quantities[$rule_id] = $qty;
            }
        }

        $total = array_sum($quantities);
        if ($total > $quantity) {
            foreach ($quantities as $rule_id => $qty) {
                $quantities[$rule_id] = round($qty * ($quantity / $total), self::QUANTITY_PRECISION);
            }
            $quantities = array_filter($quantities);
        }
        return $quantities;
    }

    /**
     * Rule credited with the most units (first on a tie); the legacy tag.
     *
     * @param array $quantities Non-empty rule ID => attributed quantity map.
     * @return int
     */
    private static function primary_rule($quantities)
    {
        return (int) array_search(max($quantities), $quantities, true);
    }

    /**
     * Current session attribution; a displayed recommendation, not a click.
     */
    private function get_session_rule_id($product_id, $variation_id)
    {
        if (!function_exists('WC') || !WC()->session) {
            return 0;
        }
        $stored = WC()->session->get(self::SESSION_KEY, array());
        if (!is_array($stored)) {
            return 0;
        }
        if ($variation_id && isset($stored[(int) $variation_id])) {
            return (int) $stored[(int) $variation_id];
        }
        return isset($stored[(int) $product_id]) ? (int) $stored[(int) $product_id] : 0;
    }

    /**
     * Keep the legacy tag for compatibility; the quantity map is authoritative.
     */
    private function set_cart_attribution($cart, $cart_item_key, $quantities)
    {
        $cart->cart_contents[$cart_item_key][self::META_ATTRIBUTION] = $quantities;
        if ($quantities) {
            $cart->cart_contents[$cart_item_key][self::META_SOURCE_RULE] = self::primary_rule($quantities);
        } else {
            unset($cart->cart_contents[$cart_item_key][self::META_SOURCE_RULE]);
        }
    }

    /**
     * Credit a successful add to the recommendation the shopper was shown.
     *
     * Runs after WooCommerce has chosen the cart line (new or merged), so
     * attribution never changes cart-item identity. Only the units added now
     * are credited, and the rule gets one add-to-cart event.
     *
     * @param string $cart_item_key Cart item key.
     * @param int    $product_id    Product ID.
     * @param int    $quantity      Quantity.
     * @param int    $variation_id  Variation ID.
     * @param array  $variation     Variation data.
     * @param array  $cart_item_data Cart item data.
     */
    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data)
    {
        if (!function_exists('WC') || !WC()->cart || !isset(WC()->cart->cart_contents[$cart_item_key])) {
            return;
        }

        $rule_id = $this->get_session_rule_id($product_id, $variation_id);
        $added_quantity = $this->attribute_added_units(WC()->cart, $cart_item_key, $quantity, $rule_id);
        if (0 !== $rule_id && $added_quantity > 0) {
            $this->count_add_to_cart($rule_id);
        }
    }

    /**
     * Credit the units just added to $rule_id (0 = organic); existing credits stay.
     *
     * @return float Units added by this event.
     */
    private function attribute_added_units($cart, $cart_item_key, $quantity, $rule_id)
    {
        $values = $cart->cart_contents[$cart_item_key];
        $line_quantity = (float) $values['quantity'];
        $added_quantity = min(max(0, (float) $quantity), $line_quantity);
        // A legacy tag describes only the units that existed before this add.
        $quantities = self::get_attribution_quantities($values, $line_quantity - $added_quantity);
        if (0 !== $rule_id && $added_quantity > 0) {
            $quantities[$rule_id] = (isset($quantities[$rule_id]) ? $quantities[$rule_id] : 0) + $added_quantity;
        }

        if ($quantities || array_key_exists(self::META_ATTRIBUTION, $values) || isset($values[self::META_SOURCE_RULE])) {
            $this->set_cart_attribution($cart, $cart_item_key, $quantities);
        }
        return $added_quantity;
    }

    /**
     * Count one add-to-cart event for a rule, per store day.
     */
    private function count_add_to_cart($rule_id)
    {
        $counter = get_option(self::COUNTER_OPTION, array());
        if (!is_array($counter)) {
            $counter = array();
        }

        // Count per day (store time) so the analytics date range can be exact;
        // month totals were all that older versions could report.
        $day_key = wp_date('Y-m-d');

        if (!isset($counter[$day_key])) {
            $counter[$day_key] = array();

            // Prune day keys past the retention window (month keys are kept).
            $cutoff = wp_date('Y-m-d', time() - self::COUNTER_DAYS_KEPT * DAY_IN_SECONDS);
            foreach (array_keys($counter) as $key) {
                if (10 === strlen((string) $key) && (string) $key < $cutoff) {
                    unset($counter[$key]);
                }
            }
        }
        if (!isset($counter[$day_key][$rule_id])) {
            $counter[$day_key][$rule_id] = 0;
        }

        $counter[$day_key][$rule_id]++;
        update_option(self::COUNTER_OPTION, $counter, false);
    }

    /**
     * Keep a line's credits in step with quantity changes that are not
     * recommendation events.
     *
     * Fires for cart edits and also when an add merges into an existing line:
     * WC_Cart::add_to_cart() calls set_quantity() before woocommerce_add_to_cart,
     * so the increase is left organic here and track_add_to_cart() then credits
     * the added units. Decreases keep the same proportions (units are fungible);
     * a later increase never resurrects credit that was removed.
     */
    public function update_attributed_quantity($cart_item_key, $quantity, $old_quantity, $cart)
    {
        if (!isset($cart->cart_contents[$cart_item_key])) {
            return;
        }
        $values = $cart->cart_contents[$cart_item_key];
        if (!array_key_exists(self::META_ATTRIBUTION, $values) && !isset($values[self::META_SOURCE_RULE])) {
            return;
        }
        $quantities = self::get_attribution_quantities($values, $old_quantity);
        $ratio = $old_quantity > 0 ? min(1, max(0, (float) $quantity) / $old_quantity) : 0;
        foreach ($quantities as $rule_id => $qty) {
            $quantities[$rule_id] = $qty * $ratio;
        }
        $this->set_cart_attribution($cart, $cart_item_key, self::normalize_quantities($quantities, $quantity));
    }

    /**
     * Persist the attribution to order line item meta.
     *
     * @param WC_Order_Item_Product $item          Order line item.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values        Cart item data.
     * @param WC_Order              $order         The order.
     */
    public function persist_order_item_meta($item, $cart_item_key, $values, $order)
    {
        $quantities = self::get_attribution_quantities($values, $item->get_quantity());
        if (!$quantities) {
            return;
        }

        $item->add_meta_data(self::META_SOURCE_RULE, self::primary_rule($quantities), true);
        $item->add_meta_data(self::META_ATTRIBUTION, $quantities, true);
    }

    /**
     * Human label for a rule ID. Handles the Smart pseudo-ID transparently.
     *
     * @param int $rule_id
     * @return string
     */
    public static function get_rule_label(int $rule_id): string
    {
        if (self::SMART_PSEUDO_RULE_ID === $rule_id) {
            return __('Smart (all)', 'ffl-funnels-addons');
        }

        if (class_exists('WooBooster_Rule')) {
            $rule = WooBooster_Rule::get($rule_id);
            if ($rule && !empty($rule->name)) {
                return $rule->name;
            }
        }

        /* translators: %d: rule ID */
        return sprintf(__('Rule #%d (deleted)', 'ffl-funnels-addons'), $rule_id);
    }
}
