<?php
/**
 * WooBooster Coupon — Auto-apply/remove WooCommerce coupons.
 *
 * When rules with 'apply_coupon' actions are matched, this class handles
 * automatically applying and removing the corresponding WooCommerce coupons
 * based on current cart contents.
 *
 * @package WooBooster
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
     * Register WooCommerce hooks.
     */
    public function init()
    {
        // Auto-apply/remove coupons when cart is updated.
        add_action('woocommerce_before_calculate_totals', array($this, 'maybe_apply_coupons'), 20);

        // Show notice when coupon is auto-applied.
        add_action('woocommerce_applied_coupon', array($this, 'show_coupon_notice'));

        // Clean up session on cart empty.
        add_action('woocommerce_cart_emptied', array($this, 'clear_session'));
    }

    /**
     * Evaluate cart contents against rules and auto-apply/remove coupons.
     *
     * Hooked to woocommerce_before_calculate_totals (priority 20).
     *
     * @param WC_Cart $cart The WC cart object.
     */
    public function maybe_apply_coupons($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        // Prevent infinite recursion.
        static $running = false;
        if ($running) {
            return;
        }
        $running = true;

        $session = WC()->session;
        if (!$session) {
            $running = false;
            return;
        }

        // Get currently tracked auto-applied coupons.
        $auto_coupons = $session->get(self::SESSION_KEY, array());

        // Find all coupon IDs that should be applied based on current cart.
        // Returns coupon_id => message pairs.
        $should_apply = $this->get_matching_coupon_ids($cart);

        // Apply new coupons.
        foreach ($should_apply as $coupon_id => $message) {
            $coupon = new WC_Coupon($coupon_id);
            $code = $coupon->get_code();

            if (!$this->is_coupon_valid($coupon)) {
                continue;
            }

            if (!$cart->has_discount($code)) {
                $cart->apply_coupon($code);
                $auto_coupons[$coupon_id] = array('code' => $code, 'message' => $message);
            }
        }

        // Remove coupons that no longer match.
        foreach ($auto_coupons as $coupon_id => $data) {
            $code = is_array($data) ? $data['code'] : $data;
            if (!isset($should_apply[$coupon_id])) {
                $cart->remove_coupon($code);
                unset($auto_coupons[$coupon_id]);
            }
        }

        $session->set(self::SESSION_KEY, $auto_coupons);

        $running = false;
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
            $terms = wp_get_post_terms($product_id, get_object_taxonomies('product'), array('fields' => 'all'));
            if (is_wp_error($terms)) {
                $terms = array();
            }

            foreach ($terms as $term) {
                $cart_keys[] = sanitize_key($term->taxonomy) . ':' . sanitize_text_field($term->slug);
            }

            // Add specific product key.
            $cart_keys[] = 'specific_product:' . $product_id;
        }

        $cart_keys = array_unique($cart_keys);

        // Match against rules.
        $now = current_time('mysql');
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
     * Get all active rules that have at least one 'apply_coupon' action.
     *
     * @return array Array of rule objects.
     */
    private function get_coupon_rules()
    {
        global $wpdb;

        $rules_table = $wpdb->prefix . 'woobooster_rules';
        $actions_table = $wpdb->prefix . 'woobooster_rule_actions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            "SELECT DISTINCT r.* FROM {$rules_table} r
             INNER JOIN {$actions_table} a ON r.id = a.rule_id
             WHERE r.status = 1 AND a.action_source = 'apply_coupon'
             ORDER BY r.priority ASC"
        );
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

            $terms = wp_get_post_terms($product_id, get_object_taxonomies('product'), array('fields' => 'all'));
            if (is_wp_error($terms)) {
                $terms = array();
            }

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
                    $slugs = array_filter(explode(',', $cond->exclude_categories));
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
                    if (in_array($item['product_id'], $ex_product_ids, true)) {
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

                    if ('specific_product' === $attr) {
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
     * Check if a coupon is valid for auto-application.
     *
     * Guards against expired, depleted, or disabled coupons.
     *
     * @param WC_Coupon $coupon The coupon object.
     * @return bool True if the coupon can be applied.
     */
    private function is_coupon_valid($coupon)
    {
        if (!$coupon || !$coupon->get_id()) {
            return false;
        }

        // Check if coupon exists and is published.
        $status = get_post_status($coupon->get_id());
        if ('publish' !== $status) {
            return false;
        }

        // Check expiry date.
        $expiry = $coupon->get_date_expires();
        if ($expiry && $expiry->getTimestamp() < time()) {
            return false;
        }

        // Check usage limits.
        $usage_limit = $coupon->get_usage_limit();
        if ($usage_limit > 0 && $coupon->get_usage_count() >= $usage_limit) {
            return false;
        }

        return true;
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
        $is_auto = false;
        $custom_message = '';

        // Check if this coupon was auto-applied by WooBooster.
        foreach ($auto_coupons as $id => $data) {
            $code = is_array($data) ? $data['code'] : $data;
            if (strtolower($code) === strtolower($coupon_code)) {
                $is_auto = true;
                $custom_message = is_array($data) && !empty($data['message']) ? $data['message'] : '';
                break;
            }
        }

        if ($is_auto) {
            // Remove WooCommerce's default "Coupon code applied successfully" notice.
            wc_clear_notices();

            if (!empty($custom_message)) {
                wc_add_notice(esc_html($custom_message), 'success');
            } else {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: coupon code */
                        __('Coupon "%s" has been automatically applied based on your cart!', 'woobooster'),
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
        }
    }
}
