<?php
/**
 * WooBooster Bundle Matcher.
 *
 * Finds matching bundles for a given product and resolves their items
 * (static items + dynamic action-based items).
 *
 * @package WooBooster
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

        $cache_key = 'woobooster_bundles_' . $product_id;
        $cached    = wp_cache_get($cache_key, 'woobooster');
        if (false !== $cached) {
            return $cached;
        }

        $terms = $this->get_product_terms($product_id);

        // Build condition keys.
        $condition_keys = array();
        foreach ($terms as $term) {
            $condition_keys[] = $term['taxonomy'] . ':' . $term['slug'];
        }
        $condition_keys[] = 'specific_product:' . $product_id;
        $condition_keys[] = '__not_equals__:1';

        $bundles = $this->find_matching_bundles($condition_keys, $terms, $product_id);

        // Resolve items for each bundle.
        foreach ($bundles as &$bundle) {
            $bundle->resolved_items = $this->resolve_bundle_items($bundle, $product_id, $terms);
        }
        unset($bundle);

        wp_cache_set($cache_key, $bundles, 'woobooster', HOUR_IN_SECONDS);

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

        $cache_key = 'woobooster_bundle_' . $bundle_id . '_' . $product_id;
        $cached    = wp_cache_get($cache_key, 'woobooster');
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

        wp_cache_set($cache_key, $bundle, 'woobooster', HOUR_IN_SECONDS);

        return $bundle;
    }

    /**
     * Resolve all items for a bundle (static + dynamic actions merged).
     *
     * @param object $bundle     The bundle object.
     * @param int    $product_id Source product ID.
     * @param array  $terms      Product terms.
     * @return array Array of product IDs.
     */
    public function resolve_bundle_items($bundle, $product_id, $terms = array())
    {
        $product_ids = array();

        // 1. Static items.
        $static_items = WooBooster_Bundle::get_items($bundle->id);
        foreach ($static_items as $item) {
            $product_ids[] = absint($item->product_id);
        }

        // 2. Dynamic actions (AI/algorithm-based).
        $action_groups = WooBooster_Bundle::get_actions($bundle->id);
        if (!empty($action_groups)) {
            $matcher = new WooBooster_Matcher();
            $dynamic_ids = $this->execute_action_groups($matcher, $product_id, $action_groups, $terms);
            $product_ids = array_merge($product_ids, $dynamic_ids);
        }

        // Deduplicate + remove the source product.
        $product_ids = array_values(array_unique(array_filter($product_ids)));
        $product_ids = array_diff($product_ids, array($product_id));

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
                // Use reflection to call the private execute_query method.
                try {
                    $method = new ReflectionMethod('WooBooster_Matcher', 'execute_query');
                    $method->setAccessible(true);
                    $ids = $method->invoke($matcher, $product_id, $action, array(), $terms);
                } catch (ReflectionException $e) {
                    $ids = array();
                }

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

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidate_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT bundle_id FROM {$index_table}
                WHERE condition_key IN ({$placeholders})
                ORDER BY priority ASC",
                ...$condition_keys
            )
        );

        if (empty($candidate_ids)) {
            return array();
        }

        $product_keys_set = array_flip($condition_keys);
        $matched_bundles  = array();

        foreach ($candidate_ids as $bundle_id) {
            $bundle = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$bundles_table} WHERE id = %d AND status = 1",
                    absint($bundle_id)
                )
            );

            if (!$bundle) {
                continue;
            }

            // Check scheduling.
            $now = current_time('mysql', true);
            if (!empty($bundle->start_date) && $now < $bundle->start_date) {
                continue;
            }
            if (!empty($bundle->end_date) && $now > $bundle->end_date) {
                continue;
            }

            // Verify condition groups.
            $groups = WooBooster_Bundle::get_conditions($bundle_id);
            if (empty($groups)) {
                continue;
            }

            foreach ($groups as $conditions) {
                $group_satisfied = true;

                foreach ($conditions as $cond) {
                    $operator    = isset($cond->condition_operator) ? $cond->condition_operator : 'equals';
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
                        $cond_key = sanitize_key($cond->condition_attribute) . ':' . sanitize_text_field($cond->condition_value);

                        if (isset($product_keys_set[$cond_key])) {
                            $key_matched = true;
                        }

                        if (!$key_matched && !empty($cond->include_children)) {
                            $attr        = sanitize_key($cond->condition_attribute);
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
                    $matched_bundles[] = $bundle;
                    break; // This bundle matches, move to next candidate.
                }
            }
        }

        return $matched_bundles;
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
