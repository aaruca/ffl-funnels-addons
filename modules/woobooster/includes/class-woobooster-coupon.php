<?php
/**
 * WooBooster Coupon — Auto-apply/remove WooCommerce coupons.
 *
 * When rules with 'apply_coupon' actions are matched, this class handles
 * automatically applying and removing the corresponding WooCommerce coupons
 * based on current cart contents.
 *
 * An automatic coupon must never block checkout. WooCommerce adds an error
 * notice whenever it refuses a coupon, and the classic checkout refuses to
 * create the order while an error notice exists, so:
 * - a coupon is checked silently (WC_Discounts, individual use) before it is
 *   applied, and any error notice a refused apply_coupon() still adds is
 *   taken back;
 * - a refusal is remembered for that coupon and cart, so it is not retried on
 *   every totals calculation;
 * - a coupon the shopper removes is not applied again until the products in
 *   the cart change;
 * - at checkout, a coupon the billing email may not use is removed with an
 *   informational notice instead of WooCommerce's error.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Coupon
{
    /**
     * Session key for tracking auto-applied coupons.
     */
    const SESSION_KEY = 'woobooster_auto_coupons';

    /**
     * Session key for coupons not to apply for now, code (lowercase) =>
     * ['why' => 'refused'|'removed', 'sig' => cart signature].
     *
     * 'refused': WooCommerce would not accept it for this cart, customer and
     * set of other coupons. 'removed': the shopper removed it while the cart
     * held these products.
     */
    const SKIP_KEY = 'woobooster_coupon_skips';

    /**
     * Coupons being auto-applied in this request, code (lowercase) => message.
     *
     * WC_Cart::apply_coupon() fires `woocommerce_applied_coupon` before
     * maybe_apply_coupons() has saved the coupon to the session, so the
     * notice handler reads this list instead.
     *
     * @var array<string, string>
     */
    private $applying = array();

    /**
     * Codes WooBooster is removing right now (lowercase => true), so the
     * `woocommerce_removed_coupon` handler does not take them for the
     * shopper's own removal.
     *
     * @var array<string, bool>
     */
    private $removing = array();

    /**
     * Codes WooCommerce is about to remove because the shopper applied an
     * individual-use coupon (lowercase => true).
     *
     * @var array<string, bool>
     */
    private $displaced = array();

    /**
     * Set while WooCommerce applies an individual-use coupon. Removing the
     * other coupons recalculates the totals before that coupon is in the
     * cart, and the engine must not put its own coupon back in between.
     *
     * @var bool
     */
    private $incoming_individual = false;

    /**
     * True while this class itself asks the individual-use filter, so
     * note_displaced_coupons() ignores that call.
     *
     * @var bool
     */
    private $dry_run = false;

    /**
     * Re-entry guard: applying or removing a coupon recalculates the totals.
     *
     * @var bool
     */
    private $running = false;

    /**
     * Per-request validation results, code => [signature => true|WP_Error].
     *
     * @var array<string, array<string, true|WP_Error>>
     */
    private $checked = array();

    /**
     * Active coupon rules (null = not loaded yet this request).
     *
     * @var array|null
     */
    private $coupon_rules = null;

    /**
     * Product terms per product ID for this request.
     *
     * @var array<int, array>
     */
    private $term_cache = array();

    /**
     * Register WooCommerce hooks.
     */
    public function init()
    {
        // Auto-apply/remove coupons once the totals are known. Inside
        // woocommerce_before_calculate_totals the cart subtotal has just been
        // reset to 0, so every minimum-spend coupon looked unmet there.
        // Applying or removing a coupon recalculates the totals, so this runs
        // after the other listeners (and before WooCommerce saves the cart to
        // the session at 1000): none of them runs twice in the outer pass.
        add_action('woocommerce_after_calculate_totals', array($this, 'maybe_apply_coupons'), 999);

        // Show notice when coupon is auto-applied.
        add_action('woocommerce_applied_coupon', array($this, 'show_coupon_notice'));
        add_action('woocommerce_applied_coupon', array($this, 'end_incoming_individual'), 1, 0);

        // A coupon the shopper removes stays removed (before WooCommerce
        // recalculates the totals at priority 20).
        add_action('woocommerce_removed_coupon', array($this, 'on_removed_coupon'), 5);

        // Last, so it sees every coupon another filter keeps.
        add_filter('woocommerce_apply_individual_use_coupon', array($this, 'note_displaced_coupons'), PHP_INT_MAX, 3);

        // Billing email checks: before WooCommerce's own (priority 1) on the
        // classic checkout, and before the Store API builds the order.
        add_action('woocommerce_after_checkout_validation', array($this, 'check_checkout_email'), 0, 2);
        add_action('woocommerce_store_api_checkout_update_customer_from_request', array($this, 'check_store_api_email'), 10, 2);

        // Clean up session on cart empty.
        add_action('woocommerce_cart_emptied', array($this, 'clear_session'));
    }

    /**
     * Evaluate cart contents against rules and auto-apply/remove coupons.
     *
     * Hooked to woocommerce_after_calculate_totals (priority 999).
     *
     * @param WC_Cart $cart The WC cart object.
     */
    public function maybe_apply_coupons($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Applying or removing a coupon recalculates the totals. While the
        // shopper's individual-use coupon is going in, wait for it.
        if ($this->running || $this->incoming_individual || !$cart) {
            return;
        }

        $session = WC()->session;
        if (!$session) {
            return;
        }

        // Get currently tracked auto-applied coupons.
        $auto_coupons = $session->get(self::SESSION_KEY, array());
        $auto_coupons = is_array($auto_coupons) ? $auto_coupons : array();

        // Nothing to apply and nothing previously applied to remove — skip the
        // condition evaluation entirely. Most stores define no coupon rules and
        // were paying for the full match pass on every totals recalculation.
        if (empty($auto_coupons) && empty($this->get_coupon_rules())) {
            return;
        }

        $this->running = true;
        try {
            $this->sync_coupons($cart, $session, $auto_coupons);
        } finally {
            $this->running = false;
        }
    }

    /**
     * Bring the cart's automatic coupons in line with the matching rules.
     *
     * @param WC_Cart    $cart         Cart.
     * @param WC_Session $session      Session.
     * @param array      $auto_coupons coupon_id => ['code', 'message'] applied earlier.
     */
    private function sync_coupons($cart, $session, array $auto_coupons)
    {
        $skips = $this->get_skips();
        $tracked_before = $auto_coupons;
        $skips_before = $skips;

        // Find all coupon IDs that should be applied based on current cart.
        // Returns coupon_id => message pairs.
        $should_apply = $this->get_matching_coupon_ids($cart);
        $wanted_codes = array();

        // Coupons applied earlier: remove those no rule wants any more and
        // those WooCommerce would now refuse (e.g. the subtotal fell below the
        // minimum spend), quietly, before WooCommerce's own checks add an
        // error for them.
        foreach ($auto_coupons as $coupon_id => $data) {
            $code = is_array($data) ? (string) ($data['code'] ?? '') : (string) $data;

            if ('' === $code || !$cart->has_discount($code)) {
                unset($auto_coupons[$coupon_id]);
                continue;
            }

            if (!isset($should_apply[$coupon_id])) {
                $this->remove_ours($cart, $code);
                unset($auto_coupons[$coupon_id]);
                continue;
            }

            $wanted_codes[self::key($code)] = true;

            $coupon = new WC_Coupon($coupon_id);
            if (true !== $this->check_coupon($coupon, $cart)) {
                $skips[self::key($code)] = array('why' => 'refused', 'sig' => $this->refusal_signature($cart, $coupon));
                $this->remove_ours($cart, $code);
                unset($auto_coupons[$coupon_id]);
                $this->notify_removed($code);
            }
        }

        // Apply new coupons.
        foreach ($should_apply as $coupon_id => $message) {
            if (isset($auto_coupons[$coupon_id])) {
                continue;
            }

            $coupon = new WC_Coupon($coupon_id);
            $code = (string) $coupon->get_code();
            if ('' === $code) {
                continue;
            }

            $key = self::key($code);
            $wanted_codes[$key] = true;

            // Applied by the shopper: theirs, never removed by WooBooster.
            if ($cart->has_discount($code)) {
                continue;
            }

            if (isset($skips[$key])) {
                if ($this->skip_still_applies($skips[$key], $cart, $coupon)) {
                    continue;
                }
                unset($skips[$key]);
            }

            if (true !== $this->check_coupon($coupon, $cart) || $this->individual_use_conflict($coupon, $cart)) {
                $skips[$key] = array('why' => 'refused', 'sig' => $this->refusal_signature($cart, $coupon));
                continue;
            }

            $errors_before = $this->error_notices();
            $this->applying[$key] = (string) $message;
            $applied = $cart->apply_coupon($code);
            unset($this->applying[$key]);
            $this->incoming_individual = false;

            // Only track coupons WooCommerce actually accepted, so a
            // refused one is not "removed" later as if we had added it.
            if ($applied) {
                $auto_coupons[$coupon_id] = array('code' => $code, 'message' => $message);
            } else {
                // Refused after all (e.g. by a plugin filter): take back the
                // errors it added, they would block checkout.
                $this->restore_error_notices($errors_before);
                $skips[$key] = array('why' => 'refused', 'sig' => $this->refusal_signature($cart, $coupon));
            }
        }

        // Forget refusals of coupons no rule wants now, and removals once the
        // products in the cart have changed.
        $lines = null;
        foreach ($skips as $key => $skip) {
            $why = is_array($skip) ? ($skip['why'] ?? '') : '';
            if ('removed' === $why) {
                if (null === $lines) {
                    $lines = $this->lines_signature($cart);
                }
                if (($skip['sig'] ?? '') !== $lines) {
                    unset($skips[$key]);
                }
            } elseif (!isset($wanted_codes[$key])) {
                unset($skips[$key]);
            }
        }

        if ($auto_coupons !== $tracked_before) {
            $session->set(self::SESSION_KEY, $auto_coupons);
        }
        if ($skips !== $skips_before) {
            $session->set(self::SKIP_KEY, $skips);
        }
    }

    /**
     * Remove a coupon WooBooster applied, without it counting as the
     * shopper's removal.
     *
     * @param WC_Cart $cart Cart.
     * @param string  $code Coupon code.
     */
    private function remove_ours($cart, $code)
    {
        $key = self::key($code);
        $this->removing[$key] = true;
        try {
            $cart->remove_coupon($code);
        } finally {
            unset($this->removing[$key]);
        }
    }

    /**
     * Whether a remembered refusal or removal still holds for this cart.
     *
     * @param array     $skip   ['why', 'sig'].
     * @param WC_Cart   $cart   Cart.
     * @param WC_Coupon $coupon Coupon.
     * @return bool
     */
    private function skip_still_applies($skip, $cart, $coupon)
    {
        if (!is_array($skip) || empty($skip['sig'])) {
            return false;
        }

        if ('removed' === ($skip['why'] ?? '')) {
            return $skip['sig'] === $this->lines_signature($cart);
        }

        return $skip['sig'] === $this->refusal_signature($cart, $coupon);
    }

    /**
     * The products in the cart (not their quantities): a coupon the shopper
     * removed comes back only when this changes.
     *
     * @param WC_Cart $cart Cart.
     * @return string
     */
    private function lines_signature($cart)
    {
        $lines = array();
        foreach ($cart->get_cart() as $item) {
            $lines[] = absint($item['product_id'] ?? 0) . ':' . absint($item['variation_id'] ?? 0);
        }
        $lines = array_values(array_unique($lines));
        sort($lines);

        return md5(implode(',', $lines));
    }

    /**
     * Everything WooCommerce's verdict on a coupon depends on: the cart lines
     * and quantities, the subtotal, the other coupons, who the customer is
     * and the coupon itself. A refusal is retried once any of it changes.
     *
     * @param WC_Cart   $cart   Cart.
     * @param WC_Coupon $coupon Coupon.
     * @return string
     */
    private function refusal_signature($cart, $coupon)
    {
        $lines = array();
        foreach ($cart->get_cart() as $item) {
            $lines[] = absint($item['product_id'] ?? 0) . ':' . absint($item['variation_id'] ?? 0) . 'x' . (float) ($item['quantity'] ?? 0);
        }
        sort($lines);

        $own = self::key($coupon->get_code());
        $others = array();
        foreach ((array) $cart->get_applied_coupons() as $applied) {
            if (self::key($applied) !== $own) {
                $others[] = self::key($applied);
            }
        }
        sort($others);

        $customer = is_callable(array($cart, 'get_customer')) ? $cart->get_customer() : null;
        $email = $customer && is_callable(array($customer, 'get_billing_email')) ? (string) $customer->get_billing_email() : '';
        $modified = is_callable(array($coupon, 'get_date_modified')) ? $coupon->get_date_modified() : null;

        return md5(wp_json_encode(array(
            $lines,
            round((float) $cart->get_subtotal(), 2),
            $others,
            self::key($email),
            (int) get_current_user_id(),
            (int) $coupon->get_id(),
            $modified && is_callable(array($modified, 'getTimestamp')) ? (int) $modified->getTimestamp() : 0,
        )));
    }

    /**
     * Get all coupon IDs that should be applied based on current cart contents.
     *
     * Scans each cart item, builds condition keys, and matches against rules
     * that have 'apply_coupon' actions.
     *
     * @param WC_Cart $cart The WC cart object.
     * @return array Associative array of coupon_id => custom_message.
     */
    private function get_matching_coupon_ids($cart)
    {
        $coupon_ids = array();

        if (!$cart || $cart->is_empty()) {
            return $coupon_ids;
        }

        // Get all active rules with 'apply_coupon' actions.
        $rules = $this->get_coupon_rules();

        if (empty($rules)) {
            return $coupon_ids;
        }

        // Build a list of all condition keys from items in the cart.
        $cart_keys = array();
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            // Get product terms for condition matching.
            $terms = $this->get_cached_product_terms($product_id);

            foreach ($terms as $term) {
                $cart_keys[] = sanitize_key($term->taxonomy) . ':' . sanitize_text_field($term->slug);
            }

            // Add specific product key.
            $cart_keys[] = 'specific_product:' . $product_id;
        }

        $cart_keys = array_unique($cart_keys);

        // Match against rules.
        $now = current_time('mysql', true);
        foreach ($rules as $rule) {
            // Check scheduling dates — skip if rule is outside its active window.
            if (!empty($rule->start_date) && $now < $rule->start_date) {
                continue;
            }
            if (!empty($rule->end_date) && $now > $rule->end_date) {
                continue;
            }

            if ($this->rule_matches_cart($rule, $cart_keys, $cart)) {
                // Get 'apply_coupon' actions from this rule.
                $action_groups = WooBooster_Rule::get_actions($rule->id);
                foreach ($action_groups as $group_id => $group_actions) {
                    foreach ($group_actions as $action) {
                        if ('apply_coupon' === $action->action_source && !empty($action->action_coupon_id)) {
                            $msg = isset($action->action_coupon_message) ? $action->action_coupon_message : '';
                            $coupon_ids[absint($action->action_coupon_id)] = $msg;
                        }
                    }
                }
            }
        }

        return $coupon_ids;
    }

    /**
     * Cached product terms to avoid N+1 in cart loops.
     */
    private function get_cached_product_terms($product_id)
    {
        if (!isset($this->term_cache[$product_id])) {
            $terms = wp_get_post_terms($product_id, get_object_taxonomies('product'), array('fields' => 'all'));
            $this->term_cache[$product_id] = is_wp_error($terms) ? array() : $terms;
        }
        return $this->term_cache[$product_id];
    }

    /**
     * Get all active rules that have at least one 'apply_coupon' action.
     *
     * @return array Array of rule objects.
     */
    private function get_coupon_rules()
    {
        // WooCommerce recalculates totals several times per request, and this
        // JOIN ran on every pass. Rules only change from the admin, never midway
        // through a front-end request, so a per-request memo cannot go stale.
        if (null !== $this->coupon_rules) {
            return $this->coupon_rules;
        }

        global $wpdb;

        $rules_table = $wpdb->prefix . 'woobooster_rules';
        $actions_table = $wpdb->prefix . 'woobooster_rule_actions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rules = $wpdb->get_results(
            "SELECT DISTINCT r.* FROM {$rules_table} r
             INNER JOIN {$actions_table} a ON r.id = a.rule_id
             WHERE r.status = 1 AND a.action_source = 'apply_coupon'
             ORDER BY r.priority ASC"
        );

        $this->coupon_rules = is_array($rules) ? $rules : array();

        return $this->coupon_rules;
    }

    /**
     * Check if a rule's conditions match items currently in the cart.
     *
     * @param object $rule      The rule object.
     * @param array  $cart_keys Condition keys from cart items.
     * @param WC_Cart $cart     The WC cart object.
     * @return bool True if rule conditions are satisfied.
     */
    private function rule_matches_cart($rule, $cart_keys, $cart)
    {
        $conditions = WooBooster_Rule::get_conditions($rule->id);

        if (empty($conditions)) {
            return false;
        }

        // Build per-item data for quantity counting and exclusion checks.
        $cart_items = array();
        foreach ($cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }

            $terms = $this->get_cached_product_terms($product_id);

            $item_keys = array();
            $item_cat_ids = array();
            foreach ($terms as $term) {
                $item_keys[] = sanitize_key($term->taxonomy) . ':' . sanitize_text_field($term->slug);
                if ('product_cat' === $term->taxonomy) {
                    $item_cat_ids[] = $term->term_id;
                }
            }
            $item_keys[] = 'specific_product:' . $product_id;

            $cart_items[] = array(
                'product_id' => $product_id,
                'quantity'   => $cart_item['quantity'],
                'price'      => (float) $product->get_price(),
                'keys'       => $item_keys,
                'cat_ids'    => $item_cat_ids,
            );
        }

        // OR between groups — at least one group must fully match.
        foreach ($conditions as $group_conditions) {
            $group_match = true;

            foreach ($group_conditions as $cond) {
                $attr = $cond->condition_attribute;
                $value = $cond->condition_value;
                $operator = isset($cond->condition_operator) ? $cond->condition_operator : 'equals';
                $min_qty = isset($cond->min_quantity) ? max(1, (int) $cond->min_quantity) : 1;

                // Build exclusion filters from condition.
                $ex_product_ids = array();
                if (!empty($cond->exclude_products)) {
                    $ex_product_ids = array_filter(array_map('absint', explode(',', $cond->exclude_products)));
                }
                $ex_cat_ids = array();
                if (!empty($cond->exclude_categories)) {
                    $slugs = array_filter(array_map('trim', explode(',', $cond->exclude_categories)));
                    foreach ($slugs as $slug) {
                        $term = get_term_by('slug', $slug, 'product_cat');
                        if ($term && !is_wp_error($term)) {
                            $ex_cat_ids[] = $term->term_id;
                        }
                    }
                }
                $ex_price_min = isset($cond->exclude_price_min) && '' !== $cond->exclude_price_min && null !== $cond->exclude_price_min
                    ? (float) $cond->exclude_price_min : null;
                $ex_price_max = isset($cond->exclude_price_max) && '' !== $cond->exclude_price_max && null !== $cond->exclude_price_max
                    ? (float) $cond->exclude_price_max : null;

                // Count qualifying quantity across all non-excluded cart items.
                $qualifying_qty = 0;

                foreach ($cart_items as $item) {
                    // Check exclusions — skip excluded items.
                    if (in_array((int) $item['product_id'], $ex_product_ids, true)) {
                        continue;
                    }
                    if (!empty($ex_cat_ids) && array_intersect($item['cat_ids'], $ex_cat_ids)) {
                        continue;
                    }
                    if (null !== $ex_price_min && $item['price'] < $ex_price_min) {
                        continue;
                    }
                    if (null !== $ex_price_max && $item['price'] > $ex_price_max) {
                        continue;
                    }

                    // Check if this item matches the condition.
                    $item_matches = false;

                    if ('__store_all' === sanitize_key($attr)) {
                        // "Entire store": every (non-excluded) cart item counts.
                        $item_matches = true;
                    } elseif ('specific_product' === $attr) {
                        $product_ids = array_filter(array_map('absint', explode(',', $value)));
                        foreach ($product_ids as $pid) {
                            if (in_array('specific_product:' . $pid, $item['keys'], true)) {
                                $item_matches = true;
                                break;
                            }
                        }
                    } else {
                        $key = sanitize_key($attr) . ':' . sanitize_text_field($value);
                        if (in_array($key, $item['keys'], true)) {
                            $item_matches = true;
                        }

                        // Check include_children.
                        if (!$item_matches && !empty($cond->include_children)) {
                            foreach ($item['keys'] as $ik) {
                                $parts = explode(':', $ik, 2);
                                if ($parts[0] === $attr) {
                                    $child_term = get_term_by('slug', $parts[1], $attr);
                                    $parent_term = get_term_by('slug', $value, $attr);
                                    if ($child_term && $parent_term && !is_wp_error($child_term) && !is_wp_error($parent_term)) {
                                        if (term_is_ancestor_of($parent_term->term_id, $child_term->term_id, $attr)) {
                                            $item_matches = true;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if ('not_equals' === $operator) {
                        $item_matches = !$item_matches;
                    }

                    if ($item_matches) {
                        $qualifying_qty += $item['quantity'];
                    }
                }

                if ($qualifying_qty < $min_qty) {
                    $group_match = false;
                    break;
                }
            }

            if ($group_match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether WooCommerce would accept the coupon for this cart, checked the
     * way apply_coupon() checks it but without adding a notice: published,
     * then WC_Discounts (expiry, usage limits, minimum/maximum spend, product
     * and category restrictions, sale items, allowed emails and the
     * `woocommerce_coupon_is_valid` filter).
     *
     * @param WC_Coupon $coupon The coupon object.
     * @param WC_Cart   $cart   The cart.
     * @return true|WP_Error
     */
    private function check_coupon($coupon, $cart)
    {
        if (!$coupon || !$coupon->get_id() || 'publish' !== get_post_status($coupon->get_id())) {
            return new WP_Error('woobooster_coupon_unavailable', 'Coupon is not published.');
        }

        if (function_exists('wc_coupons_enabled') && !wc_coupons_enabled()) {
            return new WP_Error('woobooster_coupons_disabled', 'Coupons are disabled.');
        }

        // The cart is recalculated several times per request; check each
        // coupon once per cart state.
        $code = self::key($coupon->get_code());
        $sig = $this->refusal_signature($cart, $coupon);
        if (isset($this->checked[$code][$sig])) {
            return $this->checked[$code][$sig];
        }

        $valid = (new WC_Discounts($cart))->is_coupon_valid($coupon);
        if (true !== $valid) {
            $valid = is_wp_error($valid) ? $valid : new WP_Error('woobooster_coupon_invalid', 'Coupon is not valid.');
        }

        $this->checked[$code] = array($sig => $valid);

        return $valid;
    }

    /**
     * Whether applying the coupon would clash with individual use: the cart
     * holds an individual-use coupon, or this one is individual use and
     * WooCommerce would remove the coupons already applied (the shopper's
     * own included). Mirrors WC_Cart::apply_coupon() and its filters.
     *
     * @param WC_Coupon $coupon Coupon WooBooster wants to apply.
     * @param WC_Cart   $cart   Cart.
     * @return bool
     */
    private function individual_use_conflict($coupon, $cart)
    {
        $applied = array_values((array) $cart->get_applied_coupons());
        if (empty($applied)) {
            return false;
        }

        if ($coupon->get_individual_use()) {
            $this->dry_run = true;
            try {
                $keep = (array) apply_filters('woocommerce_apply_individual_use_coupon', array(), $coupon, $applied);
            } finally {
                $this->dry_run = false;
            }
            foreach ($applied as $code) {
                if (!in_array($code, $keep, true)) {
                    return true;
                }
            }
        }

        foreach ($applied as $code) {
            $other = new WC_Coupon($code);
            if ($other->get_individual_use() && false === apply_filters('woocommerce_apply_with_individual_use_coupon', false, $coupon, $other, $applied)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Note the coupons WooCommerce is about to remove for an individual-use
     * coupon. An automatic coupon pushed out this way comes back once that
     * coupon is removed, unlike one the shopper removed.
     *
     * Hooked to woocommerce_apply_individual_use_coupon (last).
     *
     * @param array     $keep    Codes WooCommerce keeps.
     * @param WC_Coupon $coupon  Coupon being applied.
     * @param array     $applied Codes applied now.
     * @return array Unchanged.
     */
    public function note_displaced_coupons($keep, $coupon = null, $applied = array())
    {
        if (!$this->dry_run) {
            $this->incoming_individual = true;
            foreach ((array) $applied as $code) {
                if (!in_array($code, (array) $keep, true)) {
                    $this->displaced[self::key($code)] = true;
                }
            }
        }

        return $keep;
    }

    /**
     * The individual-use coupon is in the cart: the recalculation that
     * follows (priority 20) may sync the automatic coupons again.
     *
     * Hooked to woocommerce_applied_coupon (priority 1).
     */
    public function end_incoming_individual()
    {
        $this->incoming_individual = false;
    }

    /**
     * Stop tracking an automatic coupon that someone else removed, and do
     * not apply it again for now: until the products in the cart change when
     * the shopper removed it, until the cart changes when WooCommerce removed
     * it as invalid.
     *
     * Hooked to woocommerce_removed_coupon (priority 5).
     *
     * @param string $coupon_code Removed code.
     */
    public function on_removed_coupon($coupon_code)
    {
        $key = self::key($coupon_code);
        if (isset($this->removing[$key])) {
            return;
        }

        $displaced = isset($this->displaced[$key]);
        unset($this->displaced[$key]);

        $session = WC()->session;
        if (!$session) {
            return;
        }

        $auto_coupons = $session->get(self::SESSION_KEY, array());
        $auto_coupons = is_array($auto_coupons) ? $auto_coupons : array();
        $coupon_id = null;
        foreach ($auto_coupons as $id => $data) {
            $code = is_array($data) ? (string) ($data['code'] ?? '') : (string) $data;
            if (self::key($code) === $key) {
                $coupon_id = $id;
                break;
            }
        }

        // The shopper's own coupon: nothing to do.
        if (null === $coupon_id) {
            return;
        }

        unset($auto_coupons[$coupon_id]);
        $session->set(self::SESSION_KEY, $auto_coupons);

        $cart = WC()->cart;
        if ($displaced || !$cart) {
            return;
        }

        $coupon = new WC_Coupon($coupon_id);
        $skips = $this->get_skips();
        $skips[$key] = true === $this->check_coupon($coupon, $cart)
            ? array('why' => 'removed', 'sig' => $this->lines_signature($cart))
            : array('why' => 'refused', 'sig' => $this->refusal_signature($cart, $coupon));
        $session->set(self::SKIP_KEY, $skips);
    }

    /**
     * Classic checkout: remove automatic coupons the posted billing email may
     * not use, before WooCommerce adds an error for them (priority 1).
     *
     * Hooked to woocommerce_after_checkout_validation (priority 0).
     *
     * @param array    $data   Posted checkout data.
     * @param WP_Error $errors Checkout errors.
     */
    public function check_checkout_email($data, $errors = null)
    {
        $email = is_array($data) && isset($data['billing_email']) ? (string) $data['billing_email'] : '';
        // Like WooCommerce here, the per-customer limit is checked by email
        // for guests only; a signed-in customer's was checked by user ID.
        $this->drop_coupons_for_email($email, false);
    }

    /**
     * Block checkout (Store API): the same, before the order is built from
     * the cart. The Store API also counts a signed-in customer's billing
     * email against the per-customer limit.
     *
     * Hooked to woocommerce_store_api_checkout_update_customer_from_request.
     *
     * @param WC_Customer     $customer Customer with the posted addresses.
     * @param WP_REST_Request $request  Request.
     */
    public function check_store_api_email($customer, $request = null)
    {
        $email = is_object($customer) && is_callable(array($customer, 'get_billing_email')) ? (string) $customer->get_billing_email() : '';
        $this->drop_coupons_for_email($email, true);
    }

    /**
     * Remove the automatic coupons a billing email may not use (allowed
     * emails, per-customer usage limit), with an informational notice.
     *
     * @param string $billing_email   Billing email.
     * @param bool   $count_signed_in Also check a signed-in customer's billing email usage.
     */
    private function drop_coupons_for_email($billing_email, $count_signed_in)
    {
        $wc = WC();
        $session = $wc->session;
        $cart = $wc->cart;
        if (!$session || !$cart) {
            return;
        }

        $auto_coupons = $session->get(self::SESSION_KEY, array());
        if (empty($auto_coupons) || !is_array($auto_coupons)) {
            return;
        }

        $billing_email = self::key(sanitize_email($billing_email));
        $user = wp_get_current_user();
        $user_email = $user && !empty($user->user_email) ? self::key(sanitize_email($user->user_email)) : '';
        $check_emails = array_values(array_unique(array_filter(array($billing_email, $user_email))));

        foreach ($auto_coupons as $coupon_id => $data) {
            $code = is_array($data) ? (string) ($data['code'] ?? '') : (string) $data;
            if ('' === $code || !$cart->has_discount($code)) {
                continue;
            }

            $coupon = new WC_Coupon($coupon_id);
            if (!$this->email_refuses($coupon, $check_emails, $billing_email, $count_signed_in)) {
                continue;
            }

            // Saved before removing: the removal recalculates the totals, and
            // that pass must not apply the coupon again.
            $skips = $this->get_skips();
            $skips[self::key($code)] = array('why' => 'refused', 'sig' => $this->refusal_signature($cart, $coupon));
            $session->set(self::SKIP_KEY, $skips);
            unset($auto_coupons[$coupon_id]);
            $session->set(self::SESSION_KEY, $auto_coupons);

            $this->remove_ours($cart, $code);
            $this->notify_removed($code);
        }
    }

    /**
     * Whether the coupon's allowed emails or per-customer usage limit rule
     * out this billing email (WC_Cart::check_customer_coupons()).
     *
     * @param WC_Coupon $coupon          Coupon.
     * @param string[]  $check_emails    Billing and account emails, lowercase.
     * @param string    $billing_email   Billing email, lowercase.
     * @param bool      $count_signed_in Check usage by email for signed-in customers too.
     * @return bool
     */
    private function email_refuses($coupon, array $check_emails, $billing_email, $count_signed_in)
    {
        $restrictions = $coupon->get_email_restrictions();
        if (is_array($restrictions) && !empty($restrictions) && !self::emails_allowed($check_emails, $restrictions)) {
            return true;
        }

        $limit = (int) $coupon->get_usage_limit_per_user();
        if ($limit > 0 && '' !== $billing_email && ($count_signed_in || 0 === (int) get_current_user_id())) {
            $data_store = $coupon->get_data_store();
            if ($data_store && is_callable(array($data_store, 'get_usage_by_email'))
                && (int) $data_store->get_usage_by_email($coupon, $billing_email) >= $limit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any of the emails is allowed by the coupon's list (wildcards
     * included), as WooCommerce's DiscountsUtil decides.
     *
     * @param string[] $check_emails Emails, lowercase.
     * @param string[] $restrictions Allowed emails.
     * @return bool
     */
    private static function emails_allowed(array $check_emails, array $restrictions)
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\DiscountsUtil')) {
            return \Automattic\WooCommerce\Utilities\DiscountsUtil::is_coupon_emails_allowed($check_emails, $restrictions);
        }

        foreach ($check_emails as $email) {
            if (in_array($email, $restrictions, true)) {
                return true;
            }
            foreach ($restrictions as $restriction) {
                if (preg_match('/^' . str_replace('*', '(.+)?', (string) $restriction) . '$/', $email)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Tell the shopper an automatic coupon was taken off — a notice, never an
     * error, which would stop checkout.
     *
     * @param string $code Coupon code.
     */
    private function notify_removed($code)
    {
        if (!function_exists('wc_add_notice')) {
            return;
        }

        $message = sprintf(
            /* translators: %s: coupon code */
            __('Coupon "%s" no longer applies and was removed.', 'ffl-funnels-addons'),
            esc_html(strtoupper($code))
        );

        if (function_exists('wc_has_notice') && wc_has_notice($message, 'notice')) {
            return;
        }

        wc_add_notice($message, 'notice');
    }

    /**
     * Current error notices.
     *
     * @return array
     */
    private function error_notices()
    {
        return function_exists('wc_get_notices') ? (array) wc_get_notices('error') : array();
    }

    /**
     * Put the error notices back as they were before a refused
     * apply_coupon(), dropping only what it added.
     *
     * @param array $before Error notices before the attempt.
     */
    private function restore_error_notices(array $before)
    {
        if (!function_exists('wc_get_notices') || !function_exists('wc_set_notices')) {
            return;
        }

        $all = (array) wc_get_notices();
        $errors = isset($all['error']) ? (array) $all['error'] : array();
        if (count($errors) <= count($before)) {
            return;
        }

        if (empty($before)) {
            unset($all['error']);
        } else {
            $all['error'] = $before;
        }
        wc_set_notices($all);
    }

    /**
     * Remembered refusals and removals.
     *
     * @return array
     */
    private function get_skips()
    {
        $session = WC()->session;
        $skips = $session ? $session->get(self::SKIP_KEY, array()) : array();

        return is_array($skips) ? $skips : array();
    }

    /**
     * Lowercase coupon code (or email) for comparisons.
     *
     * @param string $code Code.
     * @return string
     */
    private static function key($code)
    {
        $code = trim((string) $code);

        return function_exists('wc_strtolower') ? wc_strtolower($code) : strtolower($code);
    }

    /**
     * Show a notice when WooBooster auto-applies a coupon.
     *
     * @param string $coupon_code The coupon code that was applied.
     */
    public function show_coupon_notice($coupon_code)
    {
        $session = WC()->session;
        if (!$session) {
            return;
        }

        $auto_coupons = $session->get(self::SESSION_KEY, array());
        $auto_coupons = is_array($auto_coupons) ? $auto_coupons : array();
        $is_auto = false;
        $custom_message = '';

        // Being applied right now by maybe_apply_coupons()?
        $code_key = self::key($coupon_code);
        if (array_key_exists($code_key, $this->applying)) {
            $is_auto = true;
            $custom_message = $this->applying[$code_key];
            $auto_coupons = array();
        }

        // Check if this coupon was auto-applied by WooBooster.
        foreach ($auto_coupons as $id => $data) {
            $code = is_array($data) ? $data['code'] : $data;
            if (self::key($code) === $code_key) {
                $is_auto = true;
                $custom_message = is_array($data) && !empty($data['message']) ? $data['message'] : '';
                break;
            }
        }

        if ($is_auto) {
            $notices = function_exists('wc_get_notices') ? wc_get_notices('success') : array();
            if (!empty($notices)) {
                $filtered = array();
                foreach ($notices as $notice) {
                    $text = is_array($notice) && isset($notice['notice']) ? $notice['notice'] : (string) $notice;
                    if (false === stripos($text, 'coupon')) {
                        $filtered[] = $notice;
                    }
                }
                if (function_exists('wc_set_notices')) {
                    $all = wc_get_notices();
                    $all['success'] = $filtered;
                    wc_set_notices($all);
                }
            }

            if (!empty($custom_message)) {
                wc_add_notice(esc_html($custom_message), 'success');
            } else {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: coupon code */
                        __('Coupon "%s" has been automatically applied based on your cart!', 'ffl-funnels-addons'),
                        esc_html(strtoupper($coupon_code))
                    ),
                    'success'
                );
            }
        }
    }

    /**
     * Clear auto-coupon tracking when cart is emptied.
     */
    public function clear_session()
    {
        $session = WC()->session;
        if ($session) {
            $session->set(self::SESSION_KEY, array());
            $session->set(self::SKIP_KEY, array());
        }
    }
}
