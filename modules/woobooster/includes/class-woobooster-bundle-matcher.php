<?php
/**
 * WooBooster Bundle Matcher.
 *
 * Finds matching bundles for a given product and resolves their items
 * (static items + dynamic action-based items).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Bundle_Matcher
{

    /**
     * Last matched bundle (for analytics/debugging).
     *
     * @var object|null
     */
    public static $last_matched_bundle = null;

    /**
     * Get all matching bundles for a product (via index lookup + condition verification).
     *
     * Returns bundles ordered by priority (ASC).
     *
     * @param int $product_id The source product ID.
     * @return array Array of bundle objects with resolved items attached as ->resolved_items.
     */
    public function get_bundles_for_product($product_id)
    {
        $product_id = absint($product_id);
        if (!$product_id) {
            return array();
        }

        $role_signature = self::current_user_role_signature();
        $cache_key      = 'woobooster_bundles_match_v' . (int) get_option(WooBooster_Matcher::CACHE_VERSION, 0)
            . '_' . $product_id . '_r' . $role_signature;
        $bundles        = wp_cache_get($cache_key, WooBooster_Matcher::CACHE_GROUP);
        $terms          = null;

        if (false === $bundles) {
            $terms          = $this->get_product_terms($product_id);
            $condition_keys = $this->build_condition_keys($product_id, $terms);

            $bundles = $this->find_matching_bundles($condition_keys, $terms, $product_id);
            // Cache the *match decision* only; resolved items can depend on session state.
            wp_cache_set($cache_key, $bundles, WooBooster_Matcher::CACHE_GROUP, HOUR_IN_SECONDS);
        }

        if (null === $terms) {
            $terms = $this->get_product_terms($product_id);
        }

        foreach ($bundles as &$bundle) {
            $bundle->resolved_items = $this->resolve_bundle_items($bundle, $product_id, $terms);
        }
        unset($bundle);

        return $bundles;
    }

    /**
     * Get a specific bundle by ID (bypass condition matching).
     *
     * @param int $bundle_id  The bundle ID.
     * @param int $product_id The source product ID (for resolving dynamic items).
     * @return object|null Bundle with ->resolved_items, or null.
     */
    public function get_bundle_by_id($bundle_id, $product_id)
    {
        $bundle_id  = absint($bundle_id);
        $product_id = absint($product_id);

        if (!$bundle_id) {
            return null;
        }

        // Items from a visitor-specific source (Recently Viewed) must not be
        // shared through the cache: with a persistent object cache, one
        // shopper's history used to show up in everyone's bundle.
        // ("a2": items keep the viewed product when it is a static item.)
        $cacheable = !self::bundle_uses_visitor_data($bundle_id);
        $cache_key = 'woobooster_bundle_a2_v' . (int) get_option(WooBooster_Matcher::CACHE_VERSION, 0) . '_' . $bundle_id . '_' . $product_id;
        $cached    = $cacheable ? wp_cache_get($cache_key, WooBooster_Matcher::CACHE_GROUP) : false;
        if (false !== $cached) {
            return $cached;
        }

        $bundle = WooBooster_Bundle::get($bundle_id);
        if (!$bundle || !$bundle->status) {
            return null;
        }

        // Check scheduling.
        $now = current_time('mysql', true);
        if (!empty($bundle->start_date) && $now < $bundle->start_date) {
            return null;
        }
        if (!empty($bundle->end_date) && $now > $bundle->end_date) {
            return null;
        }

        $terms = $product_id ? $this->get_product_terms($product_id) : array();
        $bundle->resolved_items = $this->resolve_bundle_items($bundle, $product_id, $terms);

        if ($cacheable) {
            wp_cache_set($cache_key, $bundle, WooBooster_Matcher::CACHE_GROUP, HOUR_IN_SECONDS);
        }

        return $bundle;
    }

    /**
     * Whether any of a bundle's dynamic sources depends on the visitor.
     *
     * @param int $bundle_id Bundle ID.
     */
    public static function bundle_uses_visitor_data($bundle_id)
    {
        foreach ((array) WooBooster_Bundle::get_actions($bundle_id) as $actions) {
            foreach ((array) $actions as $action) {
                if (isset($action->action_source) && 'recently_viewed' === $action->action_source) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve all items for a bundle (static + dynamic actions merged).
     *
     * Static items always stay in the bundle, including the product being
     * viewed (it is listed first, as "This item"): a "rifle + scope + ammo"
     * bundle viewed on the rifle's page still sells the rifle. Only the
     * automatically picked (dynamic) results leave the viewed product out.
     *
     * @param object     $bundle     The bundle object.
     * @param int        $product_id Source product ID (0 = no product).
     * @param array|null $terms      Product terms; null reads them for $product_id.
     * @return array Array of product IDs.
     */
    public function resolve_bundle_items($bundle, $product_id, $terms = null)
    {
        $product_id = absint($product_id);
        if (!is_array($terms)) {
            // Sources such as Same Attribute need the viewed product's terms.
            $terms = $product_id ? $this->get_product_terms($product_id) : array();
        }

        // 1. Static items (the viewed product first when it is one of them).
        $static_ids = array();
        foreach (WooBooster_Bundle::get_items($bundle->id) as $item) {
            $static_ids[] = absint($item->product_id);
        }
        $static_ids = array_values(array_unique(array_filter($static_ids)));
        if ($product_id && in_array($product_id, $static_ids, true)) {
            $static_ids = array_merge(array($product_id), array_values(array_diff($static_ids, array($product_id))));
        }

        // 2. Dynamic actions (AI/algorithm-based), never the viewed product.
        $dynamic_ids   = array();
        $action_groups = WooBooster_Bundle::get_actions($bundle->id);
        if (!empty($action_groups)) {
            $matcher     = new WooBooster_Matcher();
            $dynamic_ids = $this->execute_action_groups($matcher, $product_id, $action_groups, $terms);
            $dynamic_ids = array_diff(array_map('absint', $dynamic_ids), array($product_id));
        }

        // Deduplicate (static entries win).
        $product_ids = array_values(array_unique(array_filter(array_merge($static_ids, $dynamic_ids))));

        // Validate: only published products.
        if (!empty($product_ids)) {
            $query = new WP_Query(array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'post__in'       => $product_ids,
                'posts_per_page' => count($product_ids),
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'orderby'        => 'post__in',
            ));
            $product_ids = $query->posts;
        }

        return array_values($product_ids);
    }

    /**
     * Execute dynamic action groups using the existing WooBooster_Matcher engine.
     *
     * Uses reflection to call the private execute_query method. If that's not
     * available, falls back to a simplified query.
     */
    private function execute_action_groups($matcher, $product_id, $action_groups, $terms)
    {
        $all_product_ids = array();

        foreach ($action_groups as $group_id => $actions) {
            if (empty($actions)) {
                continue;
            }

            $group_product_ids = array();
            $first_in_group    = true;

            foreach ($actions as $action) {
                $ids = $matcher->execute_query($product_id, $action, array(), $terms);

                if ($first_in_group) {
                    $group_product_ids = $ids;
                    $first_in_group    = false;
                } else {
                    $group_product_ids = array_intersect($group_product_ids, $ids);
                }
            }

            if (!empty($group_product_ids)) {
                $all_product_ids = array_merge($all_product_ids, $group_product_ids);
            }
        }

        return array_values(array_unique($all_product_ids));
    }

    /**
     * Find all matching bundles from the index (not just the first).
     *
     * @param array $condition_keys Product condition keys.
     * @param array $terms          Product terms.
     * @param int   $product_id     Product ID.
     * @return array Array of bundle objects.
     */
    private function find_matching_bundles($condition_keys, $terms, $product_id)
    {
        global $wpdb;

        $index_table   = $wpdb->prefix . 'woobooster_bundle_index';
        $bundles_table = $wpdb->prefix . 'woobooster_bundles';

        if (empty($condition_keys)) {
            return array();
        }

        $condition_keys = array_map('sanitize_text_field', $condition_keys);
        $placeholders   = implode(', ', array_fill(0, count($condition_keys), '%s'));

        // GROUP BY instead of DISTINCT + ORDER BY a column outside the select
        // list (rejected by some MySQL modes); the ID breaks priority ties so
        // the result is deterministic.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidate_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT bundle_id FROM {$index_table}
                WHERE condition_key IN ({$placeholders})
                GROUP BY bundle_id
                ORDER BY MIN(priority) ASC, bundle_id ASC",
                ...$condition_keys
            )
        );

        if (empty($candidate_ids)) {
            return array();
        }

        $candidate_ids = array_map('absint', $candidate_ids);
        $id_placeholders = implode(', ', array_fill(0, count($candidate_ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$bundles_table} WHERE id IN ({$id_placeholders}) AND status = 1 ORDER BY priority ASC, id ASC",
                ...$candidate_ids
            )
        );

        $bundles_by_id = array();
        foreach ($rows as $row) {
            $bundles_by_id[(int) $row->id] = $row;
        }

        // Batch-load all conditions for candidates in a single query.
        $all_conditions = WooBooster_Bundle::get_conditions_for_bundles($candidate_ids);

        $product_keys_set = array_flip($condition_keys);
        $matched_bundles  = array();
        $now              = current_time('mysql', true);

        foreach ($candidate_ids as $bundle_id) {
            if (!isset($bundles_by_id[$bundle_id])) {
                continue;
            }
            $bundle = $bundles_by_id[$bundle_id];

            if (!empty($bundle->start_date) && $now < $bundle->start_date) {
                continue;
            }
            if (!empty($bundle->end_date) && $now > $bundle->end_date) {
                continue;
            }

            $groups = $all_conditions[$bundle_id] ?? array();
            if (empty($groups)) {
                continue;
            }

            if ($this->condition_groups_match($groups, $product_keys_set, $terms, $product_id)) {
                $matched_bundles[] = $bundle;
            }
        }

        return $matched_bundles;
    }

    /**
     * Whether a bundle's Conditions allow it for a product and the current
     * viewer (User Role conditions included).
     *
     * A bundle without conditions (shown only by pinning it in Bricks) is
     * allowed anywhere, with or without a product. With conditions and no
     * product ($product_id = 0), only User Role and "All products" conditions
     * can match: a condition about the product cannot be met without one.
     *
     * Used by the add-to-cart request, so a crafted request cannot redeem a
     * bundle meant for other products or other roles (e.g. LE-only bundles).
     *
     * @param object $bundle     Bundle row.
     * @param int    $product_id Product the bundle is shown for (0 = none).
     * @return bool
     */
    public function bundle_matches_product($bundle, $product_id)
    {
        if (!$bundle || empty($bundle->id)) {
            return false;
        }

        $groups = WooBooster_Bundle::get_conditions($bundle->id);
        if (empty($groups)) {
            return true;
        }

        $product_id = absint($product_id);
        $terms      = $product_id ? $this->get_product_terms($product_id) : array();
        $keys       = $this->build_condition_keys($product_id, $terms);

        return $this->condition_groups_match($groups, array_flip($keys), $terms, $product_id);
    }

    /**
     * Condition keys describing a product and the current viewer.
     *
     * @param int   $product_id Product ID (0 = no product).
     * @param array $terms      The product's terms.
     * @return string[]
     */
    private function build_condition_keys($product_id, array $terms)
    {
        $condition_keys = array();
        foreach ($terms as $term) {
            $condition_keys[] = $term['taxonomy'] . ':' . $term['slug'];
        }
        if ($product_id) {
            $condition_keys[] = 'specific_product:' . absint($product_id);
        }
        $condition_keys[] = '__not_equals__:1';
        $condition_keys[] = '__store_all:1';

        // User-role keys (Phase 5 context conditions).
        foreach (self::current_user_roles() as $role) {
            $condition_keys[] = 'user_role:' . $role;
        }

        return $condition_keys;
    }

    /**
     * Evaluate condition groups: OR between groups, AND inside a group.
     *
     * @param array $groups           group_id => condition rows.
     * @param array $product_keys_set Condition keys of the product/viewer, as keys.
     * @param array $terms            The product's terms (for "include children").
     * @param int   $product_id       Product ID (0 = no product).
     * @return bool
     */
    private function condition_groups_match(array $groups, array $product_keys_set, array $terms, $product_id)
    {
        foreach ($groups as $conditions) {
            $group_satisfied = true;

            foreach ((array) $conditions as $cond) {
                $operator = isset($cond->condition_operator) ? $cond->condition_operator : 'equals';
                $attr     = sanitize_key($cond->condition_attribute);

                if ('__store_all' === $attr && '1' === sanitize_text_field($cond->condition_value)) {
                    if ('not_equals' === $operator) {
                        $group_satisfied = false;
                        break;
                    }
                    continue;
                }

                // Without a product, a condition about the product is never
                // met (not even "is not"), so only role conditions can pass.
                if (!$product_id && 'user_role' !== $attr) {
                    $group_satisfied = false;
                    break;
                }

                $key_matched = false;

                if ('specific_product' === $cond->condition_attribute && false !== strpos($cond->condition_value, ',')) {
                    $sp_ids = array_filter(array_map('absint', explode(',', $cond->condition_value)));
                    foreach ($sp_ids as $sp_id) {
                        if (isset($product_keys_set['specific_product:' . $sp_id])) {
                            $key_matched = true;
                            break;
                        }
                    }
                } else {
                    $cond_key = $attr . ':' . sanitize_text_field($cond->condition_value);

                    if (isset($product_keys_set[$cond_key])) {
                        $key_matched = true;
                    }

                    if (!$key_matched && !empty($cond->include_children)) {
                        $parent_term = get_term_by('slug', $cond->condition_value, $attr);

                        if ($parent_term && !is_wp_error($parent_term)) {
                            foreach ($terms as $term) {
                                if (
                                    $term['taxonomy'] === $attr &&
                                    term_is_ancestor_of((int) $parent_term->term_id, (int) $term['term_id'], $attr)
                                ) {
                                    $key_matched = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                $condition_satisfied = ('not_equals' === $operator) ? !$key_matched : $key_matched;

                if (!$condition_satisfied) {
                    $group_satisfied = false;
                    break;
                }
            }

            if ($group_satisfied) {
                return true;
            }
        }

        return false;
    }

    /**
     * Slugs representing the current viewer (logged-in roles or 'guest').
     *
     * @return string[]
     */
    public static function current_user_roles()
    {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return array('guest');
        }
        $user = wp_get_current_user();
        if (!$user || empty($user->roles)) {
            return array('guest');
        }
        return array_values(array_map('sanitize_key', (array) $user->roles));
    }

    /**
     * Stable short signature for caching by viewer role set.
     */
    private static function current_user_role_signature()
    {
        $roles = self::current_user_roles();
        sort($roles);
        return substr(md5(implode('|', $roles)), 0, 8);
    }

    /**
     * Get all taxonomy terms for a product.
     */
    private function get_product_terms($product_id)
    {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT tt.taxonomy, t.slug, t.term_id
                FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                WHERE tr.object_id = %d",
                $product_id
            ),
            ARRAY_A
        );

        return $results ? $results : array();
    }
}
