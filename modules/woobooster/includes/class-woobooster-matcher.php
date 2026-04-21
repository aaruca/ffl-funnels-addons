<?php
/**
 * WooBooster Matcher — Core Matching Engine.
 *
 * Resolves the winning rule for a given product and executes the product query.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Matcher
{

    /**
     * Last matched rule object (set by get_recommendations).
     *
     * @var object|null
     */
    public static $last_matched_rule = null;

    /**
     * Per-request cache for get_term_by() lookups.
     *
     * @var array
     */
    private static $term_slug_cache = [];

    /**
     * Per-request cache of rule rows keyed by id. Populated in bulk before
     * the candidate loop so each candidate does not fire its own SELECT.
     *
     * @var array<int,object|null>
     */
    private static $rule_row_cache = [];

    /**
     * Per-request cache of conditions per rule id.
     *
     * @var array<int,array>
     */
    private static $conditions_cache = [];

    const CACHE_GROUP   = 'ffl-funnels-addons';
    const CACHE_VERSION = 'woobooster_cache_version';

    public static function invalidate_recommendation_cache(): void
    {
        $version = (int) get_option(self::CACHE_VERSION, 0);
        update_option(self::CACHE_VERSION, $version + 1, false);
    }

    private static function cache_version(): int
    {
        static $v = null;
        if (null === $v) {
            $v = (int) get_option(self::CACHE_VERSION, 0);
        }
        return $v;
    }

    /**
     * Cached get_term_by() to avoid N+1 queries in condition loops.
     */
    private static function get_term_cached(string $slug, string $taxonomy)
    {
        $key = $taxonomy . ':' . $slug;
        if (!isset(self::$term_slug_cache[$key])) {
            self::$term_slug_cache[$key] = get_term_by('slug', $slug, $taxonomy);
        }
        return self::$term_slug_cache[$key];
    }

    /**
     * Flush the per-request caches. Useful when rules/conditions change
     * mid-request (e.g. during rebuild).
     */
    public static function flush_request_cache(): void
    {
        self::$term_slug_cache  = [];
        self::$rule_row_cache   = [];
        self::$conditions_cache = [];
    }

    /**
     * Bulk-load active rules by id into the per-request cache.
     *
     * @param int[] $ids
     */
    private function prefetch_rules(array $ids): void
    {
        $ids = array_values(array_unique(array_map('absint', $ids)));
        $ids = array_values(array_filter($ids, function ($id) {
            return $id > 0 && !array_key_exists($id, self::$rule_row_cache);
        }));

        if (empty($ids)) {
            return;
        }

        global $wpdb;
        $rules_table = $wpdb->prefix . 'woobooster_rules';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$rules_table} WHERE status = 1 AND id IN ({$placeholders})",
                ...$ids
            )
        );

        foreach ($ids as $id) {
            self::$rule_row_cache[$id] = null;
        }
        if ($rows) {
            foreach ($rows as $row) {
                self::$rule_row_cache[(int) $row->id] = $row;
            }
        }
    }

    /**
     * Fetch conditions for a rule with per-request caching.
     *
     * @param int $rule_id
     * @return array
     */
    private function get_conditions_cached(int $rule_id): array
    {
        if (array_key_exists($rule_id, self::$conditions_cache)) {
            return self::$conditions_cache[$rule_id];
        }
        $groups = WooBooster_Rule::get_conditions($rule_id);
        self::$conditions_cache[$rule_id] = is_array($groups) ? $groups : [];
        return self::$conditions_cache[$rule_id];
    }

    /**
     * Get recommended product IDs for a given product.
     *
     * @param int   $product_id The source product ID.
     * @param array $args       Optional overrides: limit, exclude_outofstock.
     * @return array Array of product IDs.
     */
    public function get_recommendations($product_id, $args = array())
    {
        self::$last_matched_rule = null;

        $product_id = absint($product_id);

        if (!$product_id) {
            return array();
        }

        // Check if the system is enabled.
        if ('1' !== woobooster_get_option('enabled', '1')) {
            return array();
        }

        $args_hash = md5(wp_json_encode($args));
        $cache_key = 'woobooster_rec_v' . self::cache_version() . '_' . $product_id . '_' . $args_hash;
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false !== $cached) {
            $this->debug_log("Cache hit for product {$product_id}");
            return $cached;
        }

        $start_time = microtime(true);

        // Step 1: Get all taxonomy terms for this product.
        $terms = $this->get_product_terms($product_id);

        if (empty($terms)) {
            $this->debug_log("No taxonomy terms for product {$product_id}; store-wide rules can still match");
        }

        // Step 2: Build composite keys.
        $condition_keys = array();
        foreach ($terms as $term) {
            $condition_keys[] = $term['taxonomy'] . ':' . $term['slug'];
        }
        // Include product ID key so specific_product conditions are found in the index.
        $condition_keys[] = 'specific_product:' . $product_id;
        // Include sentinel so rules with not_equals conditions are always candidates.
        $condition_keys[] = '__not_equals__:1';
        // Entire-store conditions match every product (even with no categories/tags).
        $condition_keys[] = '__store_all:1';

        // Step 3: Find the winning rule via the lookup index.
        $rule = $this->find_matching_rule($condition_keys, $terms, $product_id);

        if (!$rule) {
            $this->debug_log("No matching rule for product {$product_id}");
            return array();
        }

        self::$last_matched_rule = $rule;
        $this->debug_log("Matched rule #{$rule->id} ({$rule->name}) for product {$product_id}");

        // Step 4: Execute actions.
        $all_product_ids = array();
        $action_groups = WooBooster_Rule::get_actions($rule->id);

        if (!empty($action_groups)) {
            // Groups are merged (OR), rows within a group are intersected (AND).
            foreach ($action_groups as $group_id => $actions) {
                if (empty($actions)) {
                    continue;
                }

                $group_product_ids = array();
                $first_in_group = true;

                foreach ($actions as $action) {
                    $ids = $this->execute_query($product_id, $action, $args, $terms);
                    if ($first_in_group) {
                        $group_product_ids = $ids;
                        $first_in_group = false;
                    } else {
                        // AND logic within group
                        $group_product_ids = array_intersect($group_product_ids, $ids);
                    }
                }

                if (!empty($group_product_ids)) {
                    // OR logic between groups
                    $all_product_ids = array_merge($all_product_ids, $group_product_ids);
                }
            }
        }

        // Deduplicate.
        $all_product_ids = array_values(array_unique($all_product_ids));

        // If a global hard limit was requested, apply it here too.
        if (isset($args['limit']) && $args['limit'] > 0) {
            $all_product_ids = array_slice($all_product_ids, 0, absint($args['limit']));
        }

        wp_cache_set($cache_key, $all_product_ids, self::CACHE_GROUP, HOUR_IN_SECONDS);

        $total_actions = 0;
        if (!empty($action_groups)) {
            foreach ($action_groups as $group) {
                $total_actions += count($group);
            }
        }
        $elapsed = round((microtime(true) - $start_time) * 1000, 2);
        $this->debug_log("Recommendation query for product {$product_id}: {$elapsed}ms, returned " . count($all_product_ids) . ' products from ' . $total_actions . ' actions');

        return $all_product_ids;
    }

    /**
     * Get recommendations by executing a specific rule (bypass condition matching).
     *
     * Used when a Bricks query loop is pinned to a specific rule,
     * e.g. Bronze / Silver / Gold bundles for the same product.
     *
     * @param int   $rule_id    The rule to execute.
     * @param int   $product_id The source product ID.
     * @param array $args       Optional overrides: limit, exclude_outofstock.
     * @return array Array of product IDs.
     */
    public function get_recommendations_by_rule($rule_id, $product_id, $args = array())
    {
        self::$last_matched_rule = null;

        $rule_id    = absint($rule_id);
        $product_id = absint($product_id);

        if (!$rule_id || !$product_id) {
            return array();
        }

        if ('1' !== woobooster_get_option('enabled', '1')) {
            return array();
        }

        $args_hash = md5(wp_json_encode($args));
        $cache_key = 'woobooster_rule_v' . self::cache_version() . '_' . $rule_id . '_' . $product_id . '_' . $args_hash;
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false !== $cached) {
            $this->debug_log("Cache hit for rule #{$rule_id}, product {$product_id}");
            return $cached;
        }

        $start_time = microtime(true);

        // Load and validate the rule.
        $rule = WooBooster_Rule::get($rule_id);

        if (!$rule || empty($rule->status)) {
            $this->debug_log("Rule #{$rule_id} not found or disabled");
            return array();
        }

        // Check scheduling dates.
        $now = current_time('mysql', true);
        if (!empty($rule->start_date) && $now < $rule->start_date) {
            return array();
        }
        if (!empty($rule->end_date) && $now > $rule->end_date) {
            return array();
        }

        self::$last_matched_rule = $rule;
        $this->debug_log("Executing specific rule #{$rule->id} ({$rule->name}) for product {$product_id}");

        // Get product terms (needed by execute_query for attribute resolution).
        $terms = $this->get_product_terms($product_id);

        // Execute actions — same logic as get_recommendations().
        $all_product_ids = array();
        $action_groups   = WooBooster_Rule::get_actions($rule_id);

        if (!empty($action_groups)) {
            foreach ($action_groups as $group_id => $actions) {
                if (empty($actions)) {
                    continue;
                }

                $group_product_ids = array();
                $first_in_group    = true;

                foreach ($actions as $action) {
                    $ids = $this->execute_query($product_id, $action, $args, $terms);
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
        }

        $all_product_ids = array_values(array_unique($all_product_ids));

        if (isset($args['limit']) && $args['limit'] > 0) {
            $all_product_ids = array_slice($all_product_ids, 0, absint($args['limit']));
        }

        wp_cache_set($cache_key, $all_product_ids, self::CACHE_GROUP, HOUR_IN_SECONDS);

        $elapsed = round((microtime(true) - $start_time) * 1000, 2);
        $this->debug_log("Specific rule #{$rule_id} for product {$product_id}: {$elapsed}ms, returned " . count($all_product_ids) . ' products');

        return $all_product_ids;
    }

    /**
     * Run a single Smart Recommendations strategy without a rule.
     *
     * Used by the Bricks "WooBooster Smart Recommendations" query type so
     * designers can drop a loop tied to a strategy (similar / copurchase /
     * trending / recently_viewed) without creating a companion rule.
     *
     * @param int    $product_id Source product ID.
     * @param string $source     One of copurchase|trending|recently_viewed|similar.
     * @param array  $args       Optional: limit (int), exclude_outofstock (bool).
     * @return int[] Array of product IDs.
     */
    public function get_smart_recommendations($product_id, $source, $args = array())
    {
        self::$last_matched_rule = null;

        $product_id = absint($product_id);
        $allowed    = array('copurchase', 'trending', 'recently_viewed', 'similar');

        if (!$product_id || !in_array($source, $allowed, true)) {
            return array();
        }

        if ('1' !== woobooster_get_option('enabled', '1')) {
            return array();
        }

        $limit = isset($args['limit']) ? absint($args['limit']) : 0;
        if ($limit < 1) {
            $limit = 4;
        }

        $global_exclude = '1' === woobooster_get_option('exclude_outofstock', '1');
        $exclude_outofstock = isset($args['exclude_outofstock'])
            ? (bool) $args['exclude_outofstock']
            : $global_exclude;

        $args_hash = md5(wp_json_encode(array($limit, $exclude_outofstock)));
        $cache_key = 'woobooster_smart_v' . self::cache_version() . '_' . $source . '_' . $product_id . '_' . $args_hash;
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false !== $cached) {
            return $cached;
        }

        $terms = $this->get_product_terms($product_id);

        $action = (object) array(
            'action_source'      => $source,
            'action_limit'       => $limit,
            'action_orderby'     => 'rand',
            'action_products'    => '',
            'action_value'       => '',
            'include_children'   => 0,
            'exclude_categories' => '',
            'exclude_products'   => '',
            'exclude_price_min'  => null,
            'exclude_price_max'  => null,
        );

        $product_ids = $this->execute_smart_query(
            $product_id,
            $action,
            $limit,
            $exclude_outofstock,
            $terms
        );

        if (!is_array($product_ids)) {
            $product_ids = array();
        }

        $product_ids = array_values(array_unique(array_map('absint', $product_ids)));

        if ($limit > 0) {
            $product_ids = array_slice($product_ids, 0, $limit);
        }

        wp_cache_set($cache_key, $product_ids, self::CACHE_GROUP, HOUR_IN_SECONDS);

        return $product_ids;
    }

    /**
     * Get all taxonomy terms for a product in a single query.
     *
     * @param int $product_id Product ID.
     * @return array Array of ['taxonomy' => ..., 'slug' => ...].
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

    /**
     * Find the matching rule from the lookup index.
     *
     * Uses the fast index table to find candidate rules, then verifies
     * multi-condition groups (AND within group, OR between groups).
     *
     * @param array $condition_keys Composite keys (e.g. ['pa_brand:glock', 'pa_caliber:9mm']).
     * @param array $terms          Full term data [['taxonomy' => '...', 'slug' => '...', 'term_id' => ...]].
     * @return object|null The winning rule or null.
     */
    private function find_matching_rule($condition_keys, $terms, $product_id = 0)
    {
        global $wpdb;

        $index_table = $wpdb->prefix . 'woobooster_rule_index';
        $rules_table = $wpdb->prefix . 'woobooster_rules';

        if (empty($condition_keys)) {
            return null;
        }

        // Sanitize all keys before use.
        $condition_keys = array_map('sanitize_text_field', $condition_keys);

        // Build the IN clause safely.
        $placeholders = implode(', ', array_fill(0, count($condition_keys), '%s'));

        // Get ALL candidate rule IDs (distinct, ordered by priority).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidate_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT rule_id FROM {$index_table}
                WHERE condition_key IN ({$placeholders})
                ORDER BY priority ASC",
                ...$condition_keys
            )
        );

        if (empty($candidate_ids)) {
            return null;
        }

        // Build a set of product keys for fast lookup.
        $product_keys_set = array_flip($condition_keys);

        // Pre-fetch product data for exclusion checks.
        $product_id = absint($product_id);
        $product_price = null;
        $product_cat_ids = array();
        if ($product_id) {
            $product_price = (float) get_post_meta($product_id, '_price', true);
            $cat_terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));
            if (!is_wp_error($cat_terms)) {
                $product_cat_ids = array_map('absint', $cat_terms);
            }
        }

        // Bulk-prefetch every candidate rule in a single query instead of one
        // SELECT per candidate (previously N+1 when many rules matched).
        $candidate_ids_int = array_map('absint', $candidate_ids);
        $this->prefetch_rules($candidate_ids_int);
        $now = current_time('mysql', true);

        // Verify each candidate rule against the product's condition keys.
        foreach ($candidate_ids_int as $rule_id) {
            $rule = self::$rule_row_cache[$rule_id] ?? null;

            if (!$rule) {
                continue;
            }

            // Check scheduling dates — skip if rule is outside its active window.
            if (!empty($rule->start_date) && $now < $rule->start_date) {
                continue;
            }
            if (!empty($rule->end_date) && $now > $rule->end_date) {
                continue;
            }

            // Get condition groups for this rule (memoized).
            $groups = $this->get_conditions_cached($rule_id);

            if (empty($groups)) {
                continue;
            }

            // Check if ANY group is fully satisfied (OR between groups).
            foreach ($groups as $conditions) {
                $group_satisfied = true;

                // ALL conditions in this group must match (AND within group).
                foreach ($conditions as $cond) {
                    // Check condition-level exclusions first.
                    if ($product_id && $this->is_product_excluded_by_condition($product_id, $product_price, $product_cat_ids, $cond)) {
                        $group_satisfied = false;
                        break;
                    }

                    $operator = isset($cond->condition_operator) ? $cond->condition_operator : 'equals';

                    // Match every product (no category/tag/value needed).
                    if ('__store_all' === sanitize_key($cond->condition_attribute) && '1' === sanitize_text_field($cond->condition_value)) {
                        $condition_satisfied = ('not_equals' === $operator) ? false : true;
                        if (!$condition_satisfied) {
                            $group_satisfied = false;
                            break;
                        }
                        continue;
                    }

                    $key_matched = false;

                    // Handle comma-separated specific_product values.
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

                        // Direct key match.
                        if (isset($product_keys_set[$cond_key])) {
                            $key_matched = true;
                        }

                        // If include_children is enabled, check if any product term
                        // is a descendant of this condition's term.
                        if (!$key_matched && !empty($cond->include_children)) {
                            $attr = sanitize_key($cond->condition_attribute);
                            $parent_term = self::get_term_cached($cond->condition_value, $attr);

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

                    // Apply operator: for not_equals, invert the match.
                    $condition_satisfied = ('not_equals' === $operator) ? !$key_matched : $key_matched;

                    if (!$condition_satisfied) {
                        $group_satisfied = false;
                        break;
                    }
                }

                if ($group_satisfied) {
                    return $rule; // This rule matches!
                }
            }
        }

        return null;
    }

    /**
     * Check if a product is excluded by a condition's exclusion rules.
     *
     * @param int    $product_id      Product ID.
     * @param float  $product_price   Product price.
     * @param array  $product_cat_ids Product category term IDs.
     * @param object $cond            Condition object.
     * @return bool True if the product should be excluded from this condition.
     */
    private function is_product_excluded_by_condition($product_id, $product_price, $product_cat_ids, $cond)
    {
        // Exclude specific products.
        if (!empty($cond->exclude_products)) {
            $ex_ids = array_filter(array_map('absint', explode(',', $cond->exclude_products)));
            if (in_array($product_id, $ex_ids, true)) {
                return true;
            }
        }

        // Exclude categories.
        if (!empty($cond->exclude_categories)) {
            $slugs = array_filter(array_map('trim', explode(',', $cond->exclude_categories)));
            foreach ($slugs as $slug) {
                $term = self::get_term_cached($slug, 'product_cat');
                if ($term && !is_wp_error($term) && in_array((int) $term->term_id, $product_cat_ids, true)) {
                    return true;
                }
            }
        }

        // Exclude price range (product must be within range to pass; outside = excluded).
        $has_min = isset($cond->exclude_price_min) && null !== $cond->exclude_price_min && '' !== $cond->exclude_price_min;
        $has_max = isset($cond->exclude_price_max) && null !== $cond->exclude_price_max && '' !== $cond->exclude_price_max;

        if (($has_min || $has_max) && null !== $product_price) {
            if ($has_min && $product_price < (float) $cond->exclude_price_min) {
                return true;
            }
            if ($has_max && $product_price > (float) $cond->exclude_price_max) {
                return true;
            }
        }

        return false;
    }

    /**
     * Execute the product query based on the action configuration.
     *
     * @param int    $product_id Current product ID (excluded from results).
     * @param object $action     The action object.
     * @param array  $args       Override args (limit, exclude_outofstock).
     * @param array  $terms      Product terms for "same attribute" resolution.
     * @return array Array of product IDs.
     */
    public function execute_query($product_id, $action, $args, $terms)
    {
        // Determine limit.
        $limit = isset($args['limit']) && $args['limit'] ? absint($args['limit']) : absint($action->action_limit);

        // Determine exclude outofstock.
        $global_exclude = '1' === woobooster_get_option('exclude_outofstock', '1');
        $exclude_outofstock = isset($args['exclude_outofstock']) ? (bool) $args['exclude_outofstock'] : $global_exclude;

        // Smart Recommendation sources — bypass taxonomy-based query.
        $smart_sources = array('copurchase', 'trending', 'recently_viewed', 'similar');
        if (in_array($action->action_source, $smart_sources, true)) {
            return $this->execute_smart_query($product_id, $action, $limit, $exclude_outofstock, $terms);
        }

        // Specific Products source — query by explicit IDs.
        if ('specific_products' === $action->action_source) {
            return $this->execute_specific_products_query($product_id, $action, $limit, $exclude_outofstock);
        }

        // Apply Coupon source — no product query needed, handled by WooBooster_Coupon class.
        if ('apply_coupon' === $action->action_source) {
            return array();
        }

        // Resolve taxonomy and term for the query.
        $resolved = $this->resolve_action($action, $terms);

        if (!$resolved) {
            return array();
        }

        $query_args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__not_in' => array($product_id),
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => $resolved['taxonomy'],
                    'field' => 'slug',
                    'terms' => $resolved['term'],
                    'include_children' => !empty($action->include_children),
                ),
            ),
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        // Exclude out of stock.
        if ($exclude_outofstock) {
            $query_args['meta_query'] = array(
                array(
                    'key' => '_stock_status',
                    'value' => 'instock',
                    'compare' => '=',
                ),
            );
        }

        // Apply exclusions (categories, products, price range).
        $query_args = $this->apply_exclusions($query_args, $action);

        // Order by.
        switch ($action->action_orderby) {
            case 'bestselling':
                $query_args['meta_key'] = 'total_sales';
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'DESC';
                break;

            case 'price':
                $query_args['meta_key'] = '_price';
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'ASC';
                break;

            case 'price_desc':
                $query_args['meta_key'] = '_price';
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'DESC';
                break;

            case 'rating':
                $query_args['meta_key'] = '_wc_average_rating';
                $query_args['orderby'] = 'meta_value_num';
                $query_args['order'] = 'DESC';
                break;

            case 'date':
                $query_args['orderby'] = 'date';
                $query_args['order'] = 'DESC';
                break;

            case 'rand':
            default:
                $query_args['orderby'] = 'rand';
                break;
        }

        $this->debug_log('Query args for action: ' . wp_json_encode($query_args));

        $query = new WP_Query($query_args);
        $result_ids = $query->posts;

        return $result_ids;
    }

    /**
     * Execute a query for "specific_products" action source.
     *
     * @param int    $product_id        Current product ID.
     * @param object $action            The action object.
     * @param int    $limit             Max products.
     * @param bool   $exclude_outofstock Exclude out-of-stock.
     * @return array Array of product IDs.
     */
    private function execute_specific_products_query($product_id, $action, $limit, $exclude_outofstock)
    {
        if (empty($action->action_products)) {
            return array();
        }

        $product_ids = array_filter(array_map('absint', explode(',', $action->action_products)));
        // Remove the current product.
        $product_ids = array_diff($product_ids, array($product_id));

        if (empty($product_ids)) {
            return array();
        }

        $query_args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'post__in' => array_slice($product_ids, 0, $limit * 2),
            'orderby' => 'post__in',
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        if ($exclude_outofstock) {
            $query_args['meta_query'] = array(
                array(
                    'key' => '_stock_status',
                    'value' => 'instock',
                    'compare' => '=',
                ),
            );
        }

        // Apply exclusions.
        $query_args = $this->apply_exclusions($query_args, $action);

        $query = new WP_Query($query_args);
        return $query->posts;
    }

    /**
     * Apply exclusion filters to a WP_Query args array.
     *
     * Supports: exclude_categories, exclude_products, exclude_price_min/max.
     *
     * @param array  $query_args Existing WP_Query args.
     * @param object $action     The action object.
     * @return array Modified WP_Query args.
     */
    private function apply_exclusions($query_args, $action)
    {
        // 1. Exclude categories (stored as comma-separated slugs).
        if (!empty($action->exclude_categories)) {
            $slugs = array_filter(array_map('trim', explode(',', $action->exclude_categories)));
            if (!empty($slugs)) {
                if (!isset($query_args['tax_query'])) {
                    $query_args['tax_query'] = array();
                }
                $query_args['tax_query'][] = array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => $slugs,
                    'operator' => 'NOT IN',
                );
            }
        }

        // 2. Exclude products.
        if (!empty($action->exclude_products)) {
            $exclude_ids = array_filter(array_map('absint', explode(',', $action->exclude_products)));
            if (!empty($exclude_ids)) {
                $existing = isset($query_args['post__not_in']) ? $query_args['post__not_in'] : array();
                $query_args['post__not_in'] = array_unique(array_merge($existing, $exclude_ids));
            }
        }

        // 3. Exclude by price range.
        $has_min = isset($action->exclude_price_min) && null !== $action->exclude_price_min && '' !== $action->exclude_price_min;
        $has_max = isset($action->exclude_price_max) && null !== $action->exclude_price_max && '' !== $action->exclude_price_max;

        if ($has_min || $has_max) {
            if (!isset($query_args['meta_query'])) {
                $query_args['meta_query'] = array();
            }

            if ($has_min) {
                $query_args['meta_query'][] = array(
                    'key' => '_price',
                    'value' => floatval($action->exclude_price_min),
                    'compare' => '>=',
                    'type' => 'DECIMAL',
                );
            }

            if ($has_max) {
                $query_args['meta_query'][] = array(
                    'key' => '_price',
                    'value' => floatval($action->exclude_price_max),
                    'compare' => '<=',
                    'type' => 'DECIMAL',
                );
            }
        }

        return $query_args;
    }

    /**
     * Execute a Smart Recommendation query (copurchase, trending, recently_viewed, similar).
     *
     * @param int    $product_id        Current product ID.
     * @param object $action            The action object.
     * @param int    $limit             Max products to return.
     * @param bool   $exclude_outofstock Whether to exclude out-of-stock.
     * @param array  $terms             Product terms.
     * @return array Array of product IDs.
     */
    protected function execute_smart_query($product_id, $action, $limit, $exclude_outofstock, $terms)
    {
        switch ($action->action_source) {
            case 'copurchase':
                $stored = get_post_meta($product_id, '_woobooster_copurchased', true);
                $ranked = (!empty($stored) && is_array($stored)) ? array_map('absint', $stored) : array();
                $valid  = $this->validate_candidates($ranked, $limit, $exclude_outofstock, array($product_id));
                if (count($valid) < $limit) {
                    $valid = $this->fallback_fill($product_id, $valid, $limit, $exclude_outofstock, $terms);
                }
                return $valid;

            case 'trending':
                $ranked = $this->build_trending_candidates($product_id);
                $valid  = $this->validate_candidates($ranked, $limit, $exclude_outofstock, array($product_id));
                if (count($valid) < $limit) {
                    $valid = $this->fallback_fill($product_id, $valid, $limit, $exclude_outofstock, $terms);
                }
                return $valid;

            case 'recently_viewed':
                $ranked = array();
                if (isset($_COOKIE['woobooster_recently_viewed'])) {
                    $raw = sanitize_text_field(wp_unslash($_COOKIE['woobooster_recently_viewed']));
                    $ranked = array_values(array_filter(array_map('absint', explode(',', $raw))));
                }
                $valid = $this->validate_candidates($ranked, $limit, $exclude_outofstock, array($product_id));
                if (count($valid) < $limit) {
                    $valid = $this->fallback_fill($product_id, $valid, $limit, $exclude_outofstock, $terms);
                }
                return $valid;

            case 'similar':
                return $this->execute_similar_query($product_id, $limit, $exclude_outofstock, $terms);
        }

        return array();
    }

    /**
     * Validate a ranked list of product IDs: keep only published, optionally in-stock,
     * preserve the incoming order, and cap to $limit.
     */
    protected function validate_candidates(array $ranked, int $limit, bool $exclude_outofstock, array $exclude_ids = array()): array
    {
        if (empty($ranked)) {
            return array();
        }

        $ranked = array_values(array_diff(array_map('absint', $ranked), array_map('absint', $exclude_ids)));
        if (empty($ranked)) {
            return array();
        }

        $query_args = array(
            'post_type'              => 'product',
            'post_status'            => 'publish',
            'posts_per_page'         => count($ranked),
            'post__in'               => $ranked,
            'orderby'                => 'post__in',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        if ($exclude_outofstock) {
            $query_args['meta_query'] = array(
                array(
                    'key'     => '_stock_status',
                    'value'   => 'instock',
                    'compare' => '=',
                ),
            );
        }

        $valid = (new WP_Query($query_args))->posts;

        return array_slice($valid, 0, $limit);
    }

    /**
     * Progressive fallback: fill the recommendation slot with category bestsellers,
     * then global trending, then the most recent products.
     */
    protected function fallback_fill(int $product_id, array $existing, int $limit, bool $exclude_outofstock, array $terms): array
    {
        $need = $limit - count($existing);
        if ($need <= 0) {
            return $existing;
        }

        $exclude = array_merge(array($product_id), array_map('absint', $existing));
        $cat_slugs = array();
        foreach ($terms as $term) {
            if ('product_cat' === $term['taxonomy']) {
                $cat_slugs[] = $term['slug'];
            }
        }

        if (!empty($cat_slugs)) {
            $bestsellers = new WP_Query(array(
                'post_type'              => 'product',
                'post_status'            => 'publish',
                'posts_per_page'         => $need * 2,
                'post__not_in'           => $exclude,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_key'               => 'total_sales',
                'orderby'                => 'meta_value_num',
                'order'                  => 'DESC',
                'tax_query'              => array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'slug',
                        'terms'    => $cat_slugs,
                    ),
                ),
                'meta_query'             => $exclude_outofstock
                    ? array(array('key' => '_stock_status', 'value' => 'instock', 'compare' => '='))
                    : array(),
            ));
            $existing = array_merge($existing, array_values(array_diff($bestsellers->posts, $exclude)));
            $existing = array_values(array_unique(array_map('absint', $existing)));
            $need = $limit - count($existing);
        }

        if ($need > 0) {
            $global = get_transient('wb_trending_global');
            if (!empty($global) && is_array($global)) {
                $exclude = array_merge(array($product_id), $existing);
                $extra = array_values(array_diff(array_map('absint', $global), $exclude));
                $existing = array_merge($existing, $this->validate_candidates($extra, $need, $exclude_outofstock, $exclude));
                $need = $limit - count($existing);
            }
        }

        if ($need > 0) {
            $exclude = array_merge(array($product_id), $existing);
            $recent = new WP_Query(array(
                'post_type'              => 'product',
                'post_status'            => 'publish',
                'posts_per_page'         => $need,
                'post__not_in'           => $exclude,
                'fields'                 => 'ids',
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'             => $exclude_outofstock
                    ? array(array('key' => '_stock_status', 'value' => 'instock', 'compare' => '='))
                    : array(),
            ));
            $existing = array_merge($existing, $recent->posts);
        }

        return array_slice(array_values(array_unique(array_map('absint', $existing))), 0, $limit);
    }

    /**
     * Merge category-level trending transients with a rank-aware score so products
     * that rank high in more than one category bubble up.
     */
    protected function build_trending_candidates(int $product_id): array
    {
        $cat_ids = wp_get_object_terms($product_id, 'product_cat', array('fields' => 'ids'));
        if (is_wp_error($cat_ids)) {
            $cat_ids = array();
        }

        $score = array();
        foreach ($cat_ids as $cat_id) {
            $list = get_transient('wb_trending_cat_' . (int) $cat_id);
            if (empty($list) || !is_array($list)) {
                continue;
            }
            $size = count($list);
            foreach ($list as $i => $pid) {
                $pid = absint($pid);
                if (!$pid) {
                    continue;
                }
                $rank_score = max(0, ($size - $i) / $size);
                $score[$pid] = ($score[$pid] ?? 0) + $rank_score;
            }
        }

        if (!empty($score)) {
            arsort($score);
            return array_map('intval', array_keys($score));
        }

        $global = get_transient('wb_trending_global');
        return (!empty($global) && is_array($global)) ? array_map('absint', $global) : array();
    }

    /**
     * "Similar products" via a weighted multi-signal score.
     *
     * Pool: products that share a brand, key attribute, category or tag with the
     * source product (capped to 500 candidates). Each candidate gets a score from
     * brand/attribute/category/tag overlap, price proximity, recent popularity
     * (wc_order_product_lookup over the configured Smart window), publish date
     * recency and shipping class. The top $limit are returned; if fewer, we fall
     * back to category bestsellers, then global trending, then recent products.
     *
     * Weights can be overridden via the `woobooster_similar_weights` filter.
     */
    protected function execute_similar_query($product_id, $limit, $exclude_outofstock, $terms)
    {
        global $wpdb;

        $cache_args_hash = md5(wp_json_encode(array((int) $limit, (bool) $exclude_outofstock)));
        $cache_key = 'wb_similar_v' . self::cache_version() . '_' . (int) $product_id . '_' . $cache_args_hash;
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (false !== $cached) {
            return $cached;
        }

        $weights = apply_filters('woobooster_similar_weights', array(
            'brand'        => 5.0,
            'key_attr'     => 3.0,
            'category'     => 2.0,
            'tag'          => 1.0,
            'price_max'    => 2.0,
            'popularity'   => 1.0,
            'recency'      => 0.5,
            'shipping'     => 1.0,
            'oos_penalty'  => -1.0,
        ));

        $brand_taxonomies = apply_filters('woobooster_similar_brand_taxonomies', array('product_brand', 'pa_brand', 'pa_manufacturer'));
        $key_attr_taxonomies = apply_filters('woobooster_similar_key_attributes', array('pa_caliber-gauge', 'pa_manufacturer', 'pa_platform'));

        $source_terms = array(
            'brand'    => array(),
            'key_attr' => array(),
            'category' => array(),
            'tag'      => array(),
        );
        foreach ($terms as $t) {
            $tax = $t['taxonomy'];
            $slug = $t['slug'];
            if (in_array($tax, $brand_taxonomies, true)) {
                $source_terms['brand'][$tax . ':' . $slug] = true;
            }
            if (in_array($tax, $key_attr_taxonomies, true)) {
                $source_terms['key_attr'][$tax . ':' . $slug] = true;
            }
            if ('product_cat' === $tax) {
                $source_terms['category'][$slug] = true;
            }
            if ('product_tag' === $tax) {
                $source_terms['tag'][$slug] = true;
            }
        }

        $tt_ids = array();
        foreach ($terms as $t) {
            if (isset($t['term_id'])) {
                $term = get_term((int) $t['term_id'], $t['taxonomy']);
                if ($term && !is_wp_error($term) && isset($term->term_taxonomy_id)) {
                    $tt_ids[] = (int) $term->term_taxonomy_id;
                }
            }
        }
        $tt_ids = array_values(array_unique(array_filter($tt_ids)));

        if (empty($tt_ids)) {
            $fallback = $this->fallback_fill($product_id, array(), $limit, $exclude_outofstock, $terms);
            wp_cache_set($cache_key, $fallback, self::CACHE_GROUP, DAY_IN_SECONDS);
            return $fallback;
        }

        $tt_placeholders = implode(', ', array_fill(0, count($tt_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidate_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             WHERE p.post_type = 'product' AND p.post_status = 'publish' AND p.ID != %d
             AND tr.term_taxonomy_id IN ({$tt_placeholders})
             GROUP BY p.ID
             ORDER BY COUNT(*) DESC
             LIMIT 500",
            array_merge(array($product_id), $tt_ids)
        ));

        $candidate_ids = array_values(array_unique(array_map('absint', (array) $candidate_ids)));
        if (empty($candidate_ids)) {
            $fallback = $this->fallback_fill($product_id, array(), $limit, $exclude_outofstock, $terms);
            wp_cache_set($cache_key, $fallback, self::CACHE_GROUP, DAY_IN_SECONDS);
            return $fallback;
        }

        $source_price = (float) get_post_meta($product_id, '_price', true);
        $sigma = $source_price > 0 ? $source_price * 0.25 : 1.0;
        $source_shipping = (string) get_post_meta($product_id, '_shipping_class', true);

        $pop_map = array();
        $smart_days = absint(woobooster_get_option('smart_days', 90));
        if ($smart_days < 1) {
            $smart_days = 90;
        }
        $lookup_table = $wpdb->prefix . 'wc_order_product_lookup';
        $has_lookup   = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookup_table)) === $lookup_table;
        if ($has_lookup) {
            $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $smart_days . ' days'));
            $cand_placeholders = implode(', ', array_fill(0, count($candidate_ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT product_id, SUM(product_qty) AS qty
                 FROM {$lookup_table}
                 WHERE date_created >= %s AND product_id IN ({$cand_placeholders})
                 GROUP BY product_id",
                array_merge(array($cutoff), $candidate_ids)
            ));
            foreach ($rows as $row) {
                $pop_map[(int) $row->product_id] = (int) $row->qty;
            }
        }
        $max_pop = $pop_map ? max($pop_map) : 0;

        $meta_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_key, meta_value
             FROM {$wpdb->postmeta}
             WHERE post_id IN (" . implode(', ', array_fill(0, count($candidate_ids), '%d')) . ")
             AND meta_key IN ('_price', '_stock_status', '_shipping_class')",
            $candidate_ids
        ));
        $meta_map = array();
        foreach ($meta_rows as $row) {
            $meta_map[(int) $row->post_id][$row->meta_key] = $row->meta_value;
        }

        $posts_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_date_gmt
             FROM {$wpdb->posts}
             WHERE ID IN (" . implode(', ', array_fill(0, count($candidate_ids), '%d')) . ")",
            $candidate_ids
        ));
        $date_map = array();
        foreach ($posts_rows as $row) {
            $date_map[(int) $row->ID] = strtotime($row->post_date_gmt . ' UTC');
        }

        $cand_terms = array();
        $ttp = implode(', ', array_fill(0, count($candidate_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $term_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id, tt.taxonomy, t.slug
             FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE tr.object_id IN ({$ttp})",
            $candidate_ids
        ));
        foreach ($term_rows as $row) {
            $cand_terms[(int) $row->object_id][$row->taxonomy][] = $row->slug;
        }

        $now = time();
        $scored = array();
        foreach ($candidate_ids as $cid) {
            $score = 0.0;
            $cterms = $cand_terms[$cid] ?? array();

            foreach ($brand_taxonomies as $tax) {
                foreach ($cterms[$tax] ?? array() as $slug) {
                    if (isset($source_terms['brand'][$tax . ':' . $slug])) {
                        $score += $weights['brand'];
                        break 2;
                    }
                }
            }

            $key_hits = 0;
            foreach ($key_attr_taxonomies as $tax) {
                foreach ($cterms[$tax] ?? array() as $slug) {
                    if (isset($source_terms['key_attr'][$tax . ':' . $slug])) {
                        $key_hits++;
                        break;
                    }
                }
            }
            $score += min(2, $key_hits) * $weights['key_attr'];

            $cat_hits = 0;
            foreach ($cterms['product_cat'] ?? array() as $slug) {
                if (isset($source_terms['category'][$slug])) {
                    $cat_hits++;
                }
            }
            $score += min(3, $cat_hits) * $weights['category'];

            $tag_hits = 0;
            foreach ($cterms['product_tag'] ?? array() as $slug) {
                if (isset($source_terms['tag'][$slug])) {
                    $tag_hits++;
                }
            }
            $score += min(3, $tag_hits) * $weights['tag'];

            $cprice = (float) ($meta_map[$cid]['_price'] ?? 0);
            if ($source_price > 0 && $cprice > 0) {
                $diff = $cprice - $source_price;
                $score += $weights['price_max'] * exp(-($diff * $diff) / (2 * $sigma * $sigma));
            }

            if ($max_pop > 0 && !empty($pop_map[$cid])) {
                $score += $weights['popularity'] * (log1p($pop_map[$cid]) / log1p($max_pop));
            }

            if (!empty($date_map[$cid])) {
                $days = max(0, ($now - $date_map[$cid]) / DAY_IN_SECONDS);
                $score += $weights['recency'] * exp(-$days / 365);
            }

            $cship = (string) ($meta_map[$cid]['_shipping_class'] ?? '');
            if ($source_shipping !== '' && $source_shipping === $cship) {
                $score += $weights['shipping'];
            }

            if (!$exclude_outofstock && ($meta_map[$cid]['_stock_status'] ?? 'instock') !== 'instock') {
                $score += $weights['oos_penalty'];
            }

            $scored[$cid] = $score;
        }

        arsort($scored);
        $ranked = array_map('intval', array_keys($scored));

        $valid = $this->validate_candidates($ranked, $limit, $exclude_outofstock, array($product_id));

        if (count($valid) < $limit) {
            $valid = $this->fallback_fill($product_id, $valid, $limit, $exclude_outofstock, $terms);
        }

        wp_cache_set($cache_key, $valid, self::CACHE_GROUP, DAY_IN_SECONDS);
        return $valid;
    }

    /**
     * Resolve the action taxonomy and term.
     *
     * @param object $action The action object.
     * @param array  $terms  Product terms.
     * @return array|null ['taxonomy' => ..., 'term' => ...] or null.
     */
    private function resolve_action($action, $terms)
    {
        switch ($action->action_source) {
            case 'category':
                return array(
                    'taxonomy' => 'product_cat',
                    'term' => $action->action_value,
                );

            case 'tag':
                return array(
                    'taxonomy' => 'product_tag',
                    'term' => $action->action_value,
                );

            case 'attribute':
                return array(
                    // If source is 'attribute', action_value contains property name (e.g., 'pa_brand').
                    'taxonomy' => $action->action_value,
                    // We need to find the term slug from the current product's terms.
                    'term' => $this->find_term_slug_from_product($action->action_value, $terms),
                );

            case 'attribute_value':
                // action_value is stored as 'taxonomy:term_slug' (e.g., 'pa_brand:glock').
                $parts = explode(':', $action->action_value, 2);
                if (count($parts) !== 2 || empty($parts[0]) || empty($parts[1])) {
                    return null;
                }
                return array(
                    'taxonomy' => $parts[0],
                    'term' => $parts[1],
                );

            default:
                return null;
        }
    }

    /**
     * Find a term slug for a specific taxonomy from the product's terms.
     *
     * @param string $taxonomy Taxonomy name.
     * @param array  $terms    Product terms.
     * @return string|array Term slug(s).
     */
    private function find_term_slug_from_product($taxonomy, $terms)
    {
        $slugs = array();
        foreach ($terms as $term) {
            if ($term['taxonomy'] === $taxonomy) {
                $slugs[] = $term['slug'];
            }
        }
        return empty($slugs) ? '' : $slugs;
    }

    /**
     * Log debug information.
     *
     * @param string $message Log message.
     */
    private function debug_log($message)
    {
        if ('1' !== woobooster_get_option('debug_mode', '0')) {
            return;
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug($message, array('source' => 'woobooster'));
        }
    }

    /**
     * Get matching details for diagnostics (Rule Tester).
     *
     * @param int $product_id Product ID.
     * @return array Diagnostic data.
     */
    public function get_diagnostics($product_id)
    {
        $product_id = absint($product_id);
        $start_time = microtime(true);

        $result = array(
            'product_id' => $product_id,
            'product_name' => '',
            'terms' => array(),
            'keys' => array(),
            'matched_rule' => null,
            'actions' => array(),
            'product_ids' => array(),
            'products' => array(),
            'time_ms' => 0,
        );

        $product = wc_get_product($product_id);
        if (!$product) {
            $result['error'] = __('Product not found.', 'ffl-funnels-addons');
            return $result;
        }

        $result['product_name'] = $product->get_name();

        // Step 1: Get terms.
        $terms = $this->get_product_terms($product_id);
        $result['terms'] = $terms;

        // Step 2: Build keys.
        $condition_keys = array();
        foreach ($terms as $term) {
            $condition_keys[] = $term['taxonomy'] . ':' . $term['slug'];
        }
        $condition_keys[] = 'specific_product:' . $product_id;
        $condition_keys[] = '__not_equals__:1';
        $condition_keys[] = '__store_all:1';
        $result['keys'] = $condition_keys;

        // Step 3: Find rule.
        $rule = $this->find_matching_rule($condition_keys, $terms, $product_id);

        if ($rule) {
            $result['matched_rule'] = array(
                'id' => $rule->id,
                'name' => $rule->name,
                'priority' => $rule->priority,
            );

            // Step 4: Execute actions.
            $action_groups = WooBooster_Rule::get_actions($rule->id);
            foreach ($action_groups as $group_actions) {
                foreach ($group_actions as $action) {
                    $resolved = $this->resolve_action($action, $terms);

                    $action_debug = array(
                        'source' => $action->action_source,
                        'value' => $action->action_value,
                        'limit' => $action->action_limit,
                        'orderby' => $action->action_orderby,
                        'resolved_query' => $resolved,
                        'results' => array()
                    );

                    if ($resolved) {
                        $ids = $this->execute_query($product_id, $action, array(), $terms);
                        $action_debug['results'] = $ids;
                        $result['product_ids'] = array_merge($result['product_ids'], $ids); // Accumulate all
                    }

                    $result['actions'][] = $action_debug;
                }
            }

            $result['product_ids'] = array_unique($result['product_ids']);

            foreach ($result['product_ids'] as $pid) {
                $p = wc_get_product($pid);
                if ($p) {
                    $result['products'][] = array(
                        'id' => $pid,
                        'name' => $p->get_name(),
                        'price' => $p->get_price_html(),
                        'stock' => $p->get_stock_status(),
                    );
                }
            }
        }

        $result['time_ms'] = round((microtime(true) - $start_time) * 1000, 2);

        return $result;
    }
}
