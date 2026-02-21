<?php
/**
 * WooBooster Rule Model.
 *
 * Handles CRUD operations for recommendation rules and lookup index management.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Rule
{

    /**
     * Rules table name.
     *
     * @var string
     */
    private static $table;

    /**
     * Index table name.
     *
     * @var string
     */
    private static $index_table;

    /**
     * Conditions table name.
     *
     * @var string
     */
    private static $conditions_table;

    /**
     * Actions table name.
     *
     * @var string
     */
    private static $actions_table;

    /**
     * Initialize table names.
     */
    private static function init_tables()
    {
        global $wpdb;
        self::$table = $wpdb->prefix . 'woobooster_rules';
        self::$index_table = $wpdb->prefix . 'woobooster_rule_index';
        self::$conditions_table = $wpdb->prefix . 'woobooster_rule_conditions';
        self::$actions_table = $wpdb->prefix . 'woobooster_rule_actions';
    }

    /**
     * Get a single rule by ID.
     *
     * @param int $id Rule ID.
     * @return object|null
     */
    public static function get($id)
    {
        global $wpdb;
        self::init_tables();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM %i WHERE id = %d", self::$table, $id)
        );
    }

    /**
     * Get all rules with optional sorting and filtering.
     *
     * @param array $args Query arguments.
     * @return array
     */
    public static function get_all($args = array())
    {
        global $wpdb;
        self::init_tables();

        $defaults = array(
            'orderby' => 'priority',
            'order' => 'ASC',
            'status' => null,
            'search' => '',
            'limit' => 100,
            'offset' => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $sql = "SELECT * FROM %i";
        $params = array(self::$table);
        $where_clauses = array();

        if (null !== $args['status']) {
            $where_clauses[] = 'status = %d';
            $params[] = absint($args['status']);
        }

        if (!empty($args['search'])) {
            $where_clauses[] = 'name LIKE %s';
            $params[] = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
        }

        if (!empty($where_clauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_clauses);
        }

        // Whitelist orderby columns.
        $allowed_orderby = array('id', 'name', 'priority', 'status', 'created_at', 'updated_at');
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'priority';

        $order = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';

        $sql .= " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = absint($args['limit']);
        $params[] = absint($args['offset']);

        return $wpdb->get_results(
            $wpdb->prepare($sql, ...$params)
        );
    }

    /**
     * Count total rules.
     *
     * @param array $args Filter arguments.
     * @return int
     */
    public static function count($args = array())
    {
        global $wpdb;
        self::init_tables();

        $sql = "SELECT COUNT(*) FROM %i";
        $params = array(self::$table);
        $where_clauses = array();

        if (isset($args['status'])) {
            $where_clauses[] = 'status = %d';
            $params[] = absint($args['status']);
        }

        if (!empty($args['search'])) {
            $where_clauses[] = 'name LIKE %s';
            $params[] = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
        }

        if (!empty($where_clauses)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_clauses);
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare($sql, ...$params)
        );
    }

    /**
     * Create a new rule.
     *
     * @param array $data Rule data.
     * @return int|false Rule ID on success, false on failure.
     */
    public static function create($data)
    {
        global $wpdb;
        self::init_tables();

        $defaults = array(
            'name' => '',
            'priority' => 10,
            'status' => 1,
            'condition_attribute' => '',
            'condition_operator' => 'equals',
            'condition_value' => '',
            'include_children' => 0,
            // Legacy action fields (kept for backward compat during saving)
            'action_source' => 'category',
            'action_value' => '',
            'action_orderby' => 'rand',
            'action_limit' => 4,
        );

        $data = wp_parse_args($data, $defaults);
        $data = self::sanitize_rule_data($data);

        $inserted = $wpdb->insert(
            self::$table,
            $data,
            self::get_format($data)
        );

        if ($inserted) {
            $rule_id = $wpdb->insert_id;
            self::rebuild_index_for_rule($rule_id);
            return $rule_id;
        }

        return false;
    }

    /**
     * Update an existing rule.
     *
     * @param int   $id   Rule ID.
     * @param array $data Rule data.
     * @return bool
     */
    public static function update($id, $data)
    {
        global $wpdb;
        self::init_tables();

        $data = self::sanitize_rule_data($data);

        $updated = $wpdb->update(
            self::$table,
            $data,
            array('id' => absint($id)),
            self::get_format($data),
            array('%d')
        );

        if (false !== $updated) {
            self::rebuild_index_for_rule($id);
            return true;
        }

        return false;
    }

    /**
     * Delete a rule.
     *
     * @param int $id Rule ID.
     * @return bool
     */
    public static function delete($id)
    {
        global $wpdb;
        self::init_tables();

        // Delete from conditions and actions tables.
        $wpdb->delete(self::$conditions_table, array('rule_id' => absint($id)), array('%d'));
        $wpdb->delete(self::$actions_table, array('rule_id' => absint($id)), array('%d'));

        // Delete from index.
        $wpdb->delete(self::$index_table, array('rule_id' => absint($id)), array('%d'));

        return (bool) $wpdb->delete(
            self::$table,
            array('id' => absint($id)),
            array('%d')
        );
    }

    /**
     * Toggle rule status.
     *
     * @param int $id Rule ID.
     * @return bool
     */
    public static function toggle_status($id)
    {
        global $wpdb;
        self::init_tables();

        $rule = self::get($id);
        if (!$rule) {
            return false;
        }

        $new_status = $rule->status ? 0 : 1;

        return (bool) $wpdb->update(
            self::$table,
            array('status' => $new_status),
            array('id' => absint($id)),
            array('%d'),
            array('%d')
        );
    }

    /**
     * Get all condition groups for a rule.
     *
     * @param int $rule_id Rule ID.
     * @return array Grouped conditions: [ group_id => [ condition, ... ], ... ]
     */
    public static function get_conditions($rule_id)
    {
        global $wpdb;
        self::init_tables();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE rule_id = %d ORDER BY group_id ASC, id ASC",
                self::$conditions_table,
                absint($rule_id)
            )
        );

        // If no rows in the new table, fall back to the rule's inline fields.
        if (empty($rows)) {
            $rule = self::get($rule_id);
            if ($rule && !empty($rule->condition_attribute)) {
                return array(
                    0 => array(
                        (object) array(
                            'id' => 0,
                            'rule_id' => $rule_id,
                            'group_id' => 0,
                            'condition_attribute' => $rule->condition_attribute,
                            'condition_operator' => $rule->condition_operator,
                            'condition_value' => $rule->condition_value,
                            'include_children' => $rule->include_children,
                        ),
                    ),
                );
            }
            return array();
        }

        $groups = array();
        foreach ($rows as $row) {
            $groups[(int) $row->group_id][] = $row;
        }

        return $groups;
    }

    /**
     * Get all actions for a rule.
     *
     * @param int $rule_id Rule ID.
     * @return array List of action objects.
     */
    public static function get_actions($rule_id)
    {
        global $wpdb;
        self::init_tables();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE rule_id = %d ORDER BY id ASC",
                self::$actions_table,
                absint($rule_id)
            )
        );

        // Fallback to legacy rule columns if no action rows exist.
        if (empty($rows)) {
            $rule = self::get($rule_id);
            if ($rule) {
                return array(
                    (object) array(
                        'id' => 0,
                        'rule_id' => $rule_id,
                        'action_source' => $rule->action_source,
                        'action_value' => $rule->action_value,
                        'action_limit' => $rule->action_limit,
                        'action_orderby' => $rule->action_orderby,
                    ),
                );
            }
            return array();
        }

        return $rows;
    }

    /**
     * Save condition groups for a rule.
     *
     * @param int   $rule_id Rule ID.
     * @param array $groups  Array of groups, each group is an array of conditions.
     */
    public static function save_conditions($rule_id, $groups)
    {
        global $wpdb;
        self::init_tables();

        $rule_id = absint($rule_id);

        // Delete existing conditions.
        $wpdb->delete(self::$conditions_table, array('rule_id' => $rule_id), array('%d'));

        // Insert new conditions.
        if (!empty($groups)) {
            foreach ($groups as $group_id => $conditions) {
                if (!empty($conditions)) {
                    foreach ($conditions as $condition) {
                        $row = array(
                            'rule_id' => $rule_id,
                            'group_id' => absint($group_id),
                            'condition_attribute' => sanitize_key($condition['condition_attribute']),
                            'condition_operator' => sanitize_key($condition['condition_operator'] ?? 'equals'),
                            'condition_value' => sanitize_text_field($condition['condition_value']),
                            'include_children' => absint($condition['include_children'] ?? 0),
                            'min_quantity' => max(1, absint($condition['min_quantity'] ?? 1)),
                        );

                        // Exclusion columns.
                        $row['exclude_categories'] = isset($condition['exclude_categories']) && '' !== $condition['exclude_categories']
                            ? sanitize_text_field($condition['exclude_categories']) : null;
                        $row['exclude_products'] = isset($condition['exclude_products']) && '' !== $condition['exclude_products']
                            ? sanitize_text_field($condition['exclude_products']) : null;
                        $row['exclude_price_min'] = isset($condition['exclude_price_min']) && '' !== $condition['exclude_price_min']
                            ? floatval($condition['exclude_price_min']) : null;
                        $row['exclude_price_max'] = isset($condition['exclude_price_max']) && '' !== $condition['exclude_price_max']
                            ? floatval($condition['exclude_price_max']) : null;

                        // Filter NULLs and build format array.
                        $filtered_row = array_filter($row, function ($v) {
                            return null !== $v;
                        });
                        $format_map = array(
                            'rule_id' => '%d',
                            'group_id' => '%d',
                            'condition_attribute' => '%s',
                            'condition_operator' => '%s',
                            'condition_value' => '%s',
                            'include_children' => '%d',
                            'min_quantity' => '%d',
                            'exclude_categories' => '%s',
                            'exclude_products' => '%s',
                            'exclude_price_min' => '%s',
                            'exclude_price_max' => '%s',
                        );
                        $filtered_format = array();
                        foreach (array_keys($filtered_row) as $key) {
                            $filtered_format[] = isset($format_map[$key]) ? $format_map[$key] : '%s';
                        }

                        $wpdb->insert(self::$conditions_table, $filtered_row, $filtered_format);
                    }
                }
            }
        }

        // Rebuild index.
        self::rebuild_index_for_rule($rule_id);
    }

    /**
     * Save actions for a rule.
     *
     * @param int   $rule_id Rule ID.
     * @param array $actions Array of actions.
     */
    public static function save_actions($rule_id, $actions)
    {
        global $wpdb;
        self::init_tables();

        $rule_id = absint($rule_id);

        // Delete existing actions.
        $wpdb->delete(self::$actions_table, array('rule_id' => $rule_id), array('%d'));

        // Insert new actions.
        if (!empty($actions)) {
            foreach ($actions as $action) {
                $row = array(
                    'rule_id' => $rule_id,
                    'action_source' => sanitize_key($action['action_source']),
                    'action_value' => sanitize_text_field($action['action_value']),
                    'action_limit' => absint($action['action_limit'] ?? 4),
                    'action_orderby' => sanitize_key($action['action_orderby'] ?? 'rand'),
                    'include_children' => absint($action['include_children'] ?? 0),
                );

                // New columns for v1.5.0.
                $row['action_products'] = isset($action['action_products']) ? sanitize_text_field($action['action_products']) : null;
                $row['action_coupon_id'] = isset($action['action_coupon_id']) && $action['action_coupon_id'] ? absint($action['action_coupon_id']) : null;
                $row['action_coupon_message'] = isset($action['action_coupon_message']) && '' !== $action['action_coupon_message'] ? sanitize_text_field($action['action_coupon_message']) : null;
                $row['exclude_categories'] = isset($action['exclude_categories']) ? sanitize_text_field($action['exclude_categories']) : null;
                $row['exclude_products'] = isset($action['exclude_products']) ? sanitize_text_field($action['exclude_products']) : null;
                $row['exclude_price_min'] = isset($action['exclude_price_min']) && '' !== $action['exclude_price_min'] ? floatval($action['exclude_price_min']) : null;
                $row['exclude_price_max'] = isset($action['exclude_price_max']) && '' !== $action['exclude_price_max'] ? floatval($action['exclude_price_max']) : null;

                $format = array('%d', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s');

                // Handle NULLs — wpdb->insert won't insert NULL correctly, so filter them.
                $filtered_row = array_filter($row, function ($v) {
                    return null !== $v;
                });
                $filtered_format = array();
                $format_map = array(
                    'rule_id' => '%d',
                    'action_source' => '%s',
                    'action_value' => '%s',
                    'action_limit' => '%d',
                    'action_orderby' => '%s',
                    'include_children' => '%d',
                    'action_products' => '%s',
                    'action_coupon_id' => '%d',
                    'action_coupon_message' => '%s',
                    'exclude_categories' => '%s',
                    'exclude_products' => '%s',
                    'exclude_price_min' => '%s',
                    'exclude_price_max' => '%s',
                );
                foreach (array_keys($filtered_row) as $key) {
                    $filtered_format[] = isset($format_map[$key]) ? $format_map[$key] : '%s';
                }

                $wpdb->insert(self::$actions_table, $filtered_row, $filtered_format);
            }
        }
    }

    /**
     * Rebuild the lookup index for a specific rule.
     *
     * Indexes ALL condition keys from ALL groups so the matcher
     * can quickly find candidate rules. Multi-condition verification
     * happens in the matcher after candidates are found.
     *
     * @param int $id Rule ID.
     */
    public static function rebuild_index_for_rule($id)
    {
        global $wpdb;
        self::init_tables();

        // Delete existing index entries for this rule.
        $wpdb->delete(
            self::$index_table,
            array('rule_id' => absint($id)),
            array('%d')
        );

        // Get the rule.
        $rule = self::get($id);
        if (!$rule || !$rule->status) {
            return;
        }

        // Get conditions from the new table.
        $groups = self::get_conditions($id);

        if (empty($groups)) {
            return;
        }

        // Collect all unique condition keys to index.
        $condition_keys = array();
        $has_not_equals = false;

        foreach ($groups as $conditions) {
            foreach ($conditions as $cond) {
                $attr = sanitize_key($cond->condition_attribute);
                $val = sanitize_text_field($cond->condition_value);
                $operator = isset($cond->condition_operator) ? $cond->condition_operator : 'equals';

                // For not_equals conditions, we can't index the specific key (the rule
                // should match products that DON'T have it). We use a sentinel instead.
                if ('not_equals' === $operator) {
                    $has_not_equals = true;
                    continue;
                }

                // Expand comma-separated specific_product values into individual index entries.
                if ('specific_product' === $attr && false !== strpos($val, ',')) {
                    $ids = array_filter(array_map('absint', explode(',', $val)));
                    foreach ($ids as $pid) {
                        $condition_keys['specific_product:' . $pid] = true;
                    }
                } else {
                    $condition_keys[$attr . ':' . $val] = true;
                }

                // Expand child categories if enabled.
                if (!empty($cond->include_children) && taxonomy_exists($attr) && is_taxonomy_hierarchical($attr)) {
                    $parent_term = get_term_by('slug', $val, $attr);
                    if ($parent_term && !is_wp_error($parent_term)) {
                        $child_ids = get_term_children($parent_term->term_id, $attr);
                        if (!is_wp_error($child_ids)) {
                            $max_children = (int) apply_filters('woobooster_max_index_children', 500);
                            $child_ids = array_slice($child_ids, 0, $max_children);
                            foreach ($child_ids as $child_id) {
                                $child_term = get_term($child_id, $attr);
                                if ($child_term && !is_wp_error($child_term)) {
                                    $child_key = $attr . ':' . sanitize_text_field($child_term->slug);
                                    $condition_keys[$child_key] = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Add sentinel so rules with not_equals conditions are always candidates.
        if ($has_not_equals) {
            $condition_keys['__not_equals__:1'] = true;
        }

        // Insert one index entry per unique condition key.
        foreach (array_keys($condition_keys) as $condition_key) {
            $wpdb->insert(
                self::$index_table,
                array(
                    'condition_key' => $condition_key,
                    'rule_id' => absint($id),
                    'priority' => absint($rule->priority),
                ),
                array('%s', '%d', '%d')
            );
        }
    }

    /**
     * Rebuild the entire lookup index.
     */
    public static function rebuild_full_index()
    {
        global $wpdb;
        self::init_tables();

        // Truncate the index table.
        $wpdb->query($wpdb->prepare("TRUNCATE TABLE %i", self::$index_table));

        // Get all active rules and rebuild using per-rule logic (handles include_children).
        $rules = self::get_all(array('status' => 1, 'limit' => 10000));

        foreach ($rules as $rule) {
            self::rebuild_index_for_rule($rule->id);
        }
    }

    /**
     * Sanitize rule data before insert/update.
     *
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    private static function sanitize_rule_data($data)
    {
        $sanitized = array();

        if (isset($data['name'])) {
            $sanitized['name'] = sanitize_text_field($data['name']);
        }

        if (isset($data['priority'])) {
            $sanitized['priority'] = absint($data['priority']);
        }

        if (isset($data['status'])) {
            $sanitized['status'] = absint($data['status']) ? 1 : 0;
        }

        if (isset($data['condition_attribute'])) {
            $sanitized['condition_attribute'] = sanitize_key($data['condition_attribute']);
        }

        if (isset($data['condition_value'])) {
            $sanitized['condition_value'] = sanitize_text_field($data['condition_value']);
        }

        if (isset($data['condition_operator'])) {
            $allowed_ops = array('equals', 'not_equals', 'contains');
            $sanitized['condition_operator'] = in_array($data['condition_operator'], $allowed_ops, true)
                ? $data['condition_operator']
                : 'equals';
        }

        if (isset($data['action_source'])) {
            $allowed_sources = array('category', 'tag', 'attribute', 'attribute_value', 'copurchase', 'trending', 'recently_viewed', 'similar', 'specific_products', 'apply_coupon');
            $sanitized['action_source'] = in_array($data['action_source'], $allowed_sources, true)
                ? $data['action_source']
                : 'category';
        }

        if (isset($data['action_value'])) {
            $sanitized['action_value'] = sanitize_text_field($data['action_value']);
        }

        if (isset($data['action_orderby'])) {
            $allowed_orderby = array('rand', 'date', 'price', 'price_desc', 'bestselling', 'rating');
            $sanitized['action_orderby'] = in_array($data['action_orderby'], $allowed_orderby, true)
                ? $data['action_orderby']
                : 'rand';
        }

        if (isset($data['action_limit'])) {
            $limit = absint($data['action_limit']);
            $sanitized['action_limit'] = max($limit, 1);
        }

        if (isset($data['include_children'])) {
            $sanitized['include_children'] = absint($data['include_children']) ? 1 : 0;
        }

        if (isset($data['exclude_outofstock'])) {
            $sanitized['exclude_outofstock'] = absint($data['exclude_outofstock']) ? 1 : 0;
        }

        if (array_key_exists('start_date', $data)) {
            $sanitized['start_date'] = !empty($data['start_date'])
                ? sanitize_text_field($data['start_date'])
                : null;
        }

        if (array_key_exists('end_date', $data)) {
            $sanitized['end_date'] = !empty($data['end_date'])
                ? sanitize_text_field($data['end_date'])
                : null;
        }

        return $sanitized;
    }

    /**
     * Get format array for wpdb operations.
     *
     * @param array $data Data array.
     * @return array
     */
    private static function get_format($data)
    {
        $format_map = array(
            'name' => '%s',
            'priority' => '%d',
            'status' => '%d',
            'condition_attribute' => '%s',
            'condition_value' => '%s',
            'condition_operator' => '%s',
            'action_source' => '%s',
            'action_value' => '%s',
            'action_orderby' => '%s',
            'action_limit' => '%d',
            'include_children' => '%d',
            'exclude_outofstock' => '%d',
            'start_date' => '%s',
            'end_date' => '%s',
        );

        $format = array();
        foreach (array_keys($data) as $key) {
            $format[] = isset($format_map[$key]) ? $format_map[$key] : '%s';
        }

        return $format;
    }

    /**
     * Duplicate a rule including its conditions and actions.
     *
     * @param int $id Rule ID to duplicate.
     * @return int|false New rule ID on success, false on failure.
     */
    public static function duplicate($id)
    {
        global $wpdb;
        self::init_tables();

        $rule = self::get($id);
        if (!$rule) {
            return false;
        }

        // Create the new rule.
        $new_id = self::create(array(
            'name' => $rule->name . ' (Copy)',
            'priority' => $rule->priority,
            'status' => 0, // Start inactive.
            'condition_attribute' => $rule->condition_attribute,
            'condition_operator' => $rule->condition_operator,
            'condition_value' => $rule->condition_value,
            'include_children' => $rule->include_children,
            'action_source' => $rule->action_source,
            'action_value' => $rule->action_value,
            'action_orderby' => $rule->action_orderby,
            'action_limit' => $rule->action_limit,
            'start_date' => isset($rule->start_date) ? $rule->start_date : null,
            'end_date' => isset($rule->end_date) ? $rule->end_date : null,
        ));

        if (!$new_id) {
            return false;
        }

        // Copy conditions.
        $groups = self::get_conditions($id);
        if (!empty($groups)) {
            $condition_data = array();
            foreach ($groups as $group_id => $conditions) {
                $condition_data[$group_id] = array();
                foreach ($conditions as $cond) {
                    $condition_data[$group_id][] = (array) $cond;
                }
            }
            self::save_conditions($new_id, $condition_data);
        }

        // Copy actions.
        $actions = self::get_actions($id);
        if (!empty($actions)) {
            $action_data = array();
            foreach ($actions as $action) {
                $action_data[] = (array) $action;
            }
            self::save_actions($new_id, $action_data);
        }

        return $new_id;
    }

    /**
     * Get all registered product taxonomies for dropdowns.
     *
     * @return array Associative array of taxonomy slug => label.
     */
    public static function get_product_taxonomies()
    {
        $taxonomies = array();

        // Product categories & tags.
        $taxonomies['product_cat'] = __('Product Category', 'woobooster');
        $taxonomies['product_tag'] = __('Product Tag', 'woobooster');

        // Product attributes.
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        if ($attribute_taxonomies) {
            foreach ($attribute_taxonomies as $attribute) {
                $tax_name = wc_attribute_taxonomy_name($attribute->attribute_name);
                $taxonomies[$tax_name] = $attribute->attribute_label;
            }
        }

        return $taxonomies;
    }
}
