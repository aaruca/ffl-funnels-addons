<?php
/**
 * WooBooster Bundle Model.
 *
 * Handles CRUD operations for product bundles, including items, actions,
 * conditions, and lookup index management.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Bundle
{

    private static $table;
    private static $items_table;
    private static $actions_table;
    private static $conditions_table;
    private static $index_table;

    private static function init_tables()
    {
        global $wpdb;
        self::$table            = $wpdb->prefix . 'woobooster_bundles';
        self::$items_table      = $wpdb->prefix . 'woobooster_bundle_items';
        self::$actions_table    = $wpdb->prefix . 'woobooster_bundle_actions';
        self::$conditions_table = $wpdb->prefix . 'woobooster_bundle_conditions';
        self::$index_table      = $wpdb->prefix . 'woobooster_bundle_index';
    }

    /**
     * Get a single bundle by ID.
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
     * Get all bundles with optional sorting and filtering.
     */
    public static function get_all($args = array())
    {
        global $wpdb;
        self::init_tables();

        $defaults = array(
            'orderby' => 'priority',
            'order'   => 'ASC',
            'status'  => null,
            'search'  => '',
            'limit'   => 100,
            'offset'  => 0,
        );

        $args = wp_parse_args($args, $defaults);

        $sql    = "SELECT * FROM %i";
        $params = array(self::$table);
        $where  = array();

        if (null !== $args['status']) {
            $where[]  = 'status = %d';
            $params[] = absint($args['status']);
        }

        if (!empty($args['search'])) {
            $where[]  = 'name LIKE %s';
            $params[] = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $allowed_orderby = array('id', 'name', 'priority', 'status', 'created_at', 'updated_at');
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'priority';
        $order   = strtoupper($args['order']) === 'DESC' ? 'DESC' : 'ASC';

        $sql     .= " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $params[] = absint($args['limit']);
        $params[] = absint($args['offset']);

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count total bundles.
     */
    public static function count($args = array())
    {
        global $wpdb;
        self::init_tables();

        $sql    = "SELECT COUNT(*) FROM %i";
        $params = array(self::$table);
        $where  = array();

        if (isset($args['status'])) {
            $where[]  = 'status = %d';
            $params[] = absint($args['status']);
        }

        if (!empty($args['search'])) {
            $where[]  = 'name LIKE %s';
            $params[] = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * Create a new bundle.
     *
     * @return int|false Bundle ID on success.
     */
    public static function create($data)
    {
        global $wpdb;
        self::init_tables();

        $defaults = array(
            'name'           => '',
            'priority'       => 10,
            'status'         => 1,
            'discount_type'  => 'none',
            'discount_value' => 0,
        );

        $data = wp_parse_args($data, $defaults);
        $data = self::sanitize_bundle_data($data);

        $inserted = $wpdb->insert(self::$table, $data, self::get_format($data));

        if ($inserted) {
            $bundle_id = $wpdb->insert_id;
            self::rebuild_index_for_bundle($bundle_id);
            return $bundle_id;
        }

        return false;
    }

    /**
     * Update an existing bundle.
     */
    public static function update($id, $data)
    {
        global $wpdb;
        self::init_tables();

        $data = self::sanitize_bundle_data($data);

        $updated = $wpdb->update(
            self::$table,
            $data,
            array('id' => absint($id)),
            self::get_format($data),
            array('%d')
        );

        if (false !== $updated) {
            self::rebuild_index_for_bundle($id);
            return true;
        }

        return false;
    }

    /**
     * Delete a bundle and all related data.
     */
    public static function delete($id)
    {
        global $wpdb;
        self::init_tables();

        $id = absint($id);

        $wpdb->delete(self::$items_table, array('bundle_id' => $id), array('%d'));
        $wpdb->delete(self::$actions_table, array('bundle_id' => $id), array('%d'));
        $wpdb->delete(self::$conditions_table, array('bundle_id' => $id), array('%d'));
        $wpdb->delete(self::$index_table, array('bundle_id' => $id), array('%d'));

        return (bool) $wpdb->delete(self::$table, array('id' => $id), array('%d'));
    }

    /**
     * Toggle bundle status.
     */
    public static function toggle_status($id)
    {
        global $wpdb;
        self::init_tables();

        $bundle = self::get($id);
        if (!$bundle) {
            return false;
        }

        $new_status = $bundle->status ? 0 : 1;

        return (bool) $wpdb->update(
            self::$table,
            array('status' => $new_status),
            array('id' => absint($id)),
            array('%d'),
            array('%d')
        );
    }

    /**
     * Duplicate a bundle including items, actions, and conditions.
     *
     * @return int|false New bundle ID.
     */
    public static function duplicate($id)
    {
        global $wpdb;
        self::init_tables();

        $bundle = self::get($id);
        if (!$bundle) {
            return false;
        }

        $new_id = self::create(array(
            'name'           => $bundle->name . ' (Copy)',
            'priority'       => $bundle->priority,
            'status'         => 0,
            'discount_type'  => $bundle->discount_type,
            'discount_value' => $bundle->discount_value,
            'start_date'     => $bundle->start_date,
            'end_date'       => $bundle->end_date,
        ));

        if (!$new_id) {
            return false;
        }

        // Copy items.
        $items = self::get_items($id);
        if (!empty($items)) {
            $item_data = array();
            foreach ($items as $item) {
                $item_data[] = array(
                    'product_id'  => $item->product_id,
                    'sort_order'  => $item->sort_order,
                    'is_optional' => $item->is_optional,
                );
            }
            self::save_items($new_id, $item_data);
        }

        // Copy actions.
        $action_groups = self::get_actions($id);
        if (!empty($action_groups)) {
            $action_data = array();
            foreach ($action_groups as $group_id => $actions) {
                $action_data[$group_id] = array();
                foreach ($actions as $action) {
                    $action_data[$group_id][] = (array) $action;
                }
            }
            self::save_actions($new_id, $action_data);
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

        return $new_id;
    }

    /* ── Items (Static) ───────────────────────────────────────────── */

    /**
     * Get static items for a bundle.
     *
     * @return array List of item objects.
     */
    public static function get_items($bundle_id)
    {
        global $wpdb;
        self::init_tables();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE bundle_id = %d ORDER BY sort_order ASC, id ASC",
                self::$items_table,
                absint($bundle_id)
            )
        );
    }

    /**
     * Save static items for a bundle (delete + reinsert).
     *
     * @param int   $bundle_id Bundle ID.
     * @param array $items     Array of ['product_id' => int, 'sort_order' => int, 'is_optional' => 0|1].
     */
    public static function save_items($bundle_id, $items)
    {
        global $wpdb;
        self::init_tables();

        $bundle_id = absint($bundle_id);

        $wpdb->delete(self::$items_table, array('bundle_id' => $bundle_id), array('%d'));

        if (!empty($items)) {
            foreach ($items as $index => $item) {
                $wpdb->insert(
                    self::$items_table,
                    array(
                        'bundle_id'   => $bundle_id,
                        'product_id'  => absint($item['product_id']),
                        'sort_order'  => isset($item['sort_order']) ? absint($item['sort_order']) : $index,
                        'is_optional' => !empty($item['is_optional']) ? 1 : 0,
                    ),
                    array('%d', '%d', '%d', '%d')
                );
            }
        }
    }

    /* ── Actions (Dynamic/AI) ─────────────────────────────────────── */

    /**
     * Get dynamic action groups for a bundle.
     *
     * @return array Grouped actions: [ group_id => [ action, ... ], ... ]
     */
    public static function get_actions($bundle_id)
    {
        global $wpdb;
        self::init_tables();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE bundle_id = %d ORDER BY group_id ASC, id ASC",
                self::$actions_table,
                absint($bundle_id)
            )
        );

        if (empty($rows)) {
            return array();
        }

        $groups = array();
        foreach ($rows as $row) {
            $groups[(int) $row->group_id][] = $row;
        }

        return $groups;
    }

    /**
     * Save dynamic action groups for a bundle.
     */
    public static function save_actions($bundle_id, $groups)
    {
        global $wpdb;
        self::init_tables();

        $bundle_id = absint($bundle_id);

        $wpdb->delete(self::$actions_table, array('bundle_id' => $bundle_id), array('%d'));

        if (empty($groups)) {
            return;
        }

        foreach ($groups as $group_id => $actions) {
            if (empty($actions)) {
                continue;
            }

            foreach ($actions as $action) {
                $row = array(
                    'bundle_id'      => $bundle_id,
                    'group_id'       => absint($group_id),
                    'action_source'  => sanitize_key($action['action_source']),
                    'action_value'   => sanitize_text_field($action['action_value']),
                    'action_limit'   => absint($action['action_limit'] ?? 4),
                    'action_orderby' => sanitize_key($action['action_orderby'] ?? 'rand'),
                    'include_children' => absint($action['include_children'] ?? 0),
                );

                $row['action_products']    = isset($action['action_products']) ? sanitize_text_field($action['action_products']) : null;
                $row['exclude_categories'] = isset($action['exclude_categories']) ? sanitize_text_field($action['exclude_categories']) : null;
                $row['exclude_products']   = isset($action['exclude_products']) ? sanitize_text_field($action['exclude_products']) : null;
                $row['exclude_price_min']  = isset($action['exclude_price_min']) && '' !== $action['exclude_price_min'] ? floatval($action['exclude_price_min']) : null;
                $row['exclude_price_max']  = isset($action['exclude_price_max']) && '' !== $action['exclude_price_max'] ? floatval($action['exclude_price_max']) : null;

                $filtered_row = array_filter($row, function ($v) {
                    return null !== $v;
                });

                $format_map = array(
                    'bundle_id'        => '%d',
                    'group_id'         => '%d',
                    'action_source'    => '%s',
                    'action_value'     => '%s',
                    'action_limit'     => '%d',
                    'action_orderby'   => '%s',
                    'include_children' => '%d',
                    'action_products'    => '%s',
                    'exclude_categories' => '%s',
                    'exclude_products'   => '%s',
                    'exclude_price_min'  => '%s',
                    'exclude_price_max'  => '%s',
                );

                $filtered_format = array();
                foreach (array_keys($filtered_row) as $key) {
                    $filtered_format[] = isset($format_map[$key]) ? $format_map[$key] : '%s';
                }

                $wpdb->insert(self::$actions_table, $filtered_row, $filtered_format);
            }
        }
    }

    /* ── Conditions ───────────────────────────────────────────────── */

    /**
     * Get condition groups for a bundle.
     *
     * @return array Grouped conditions: [ group_id => [ condition, ... ], ... ]
     */
    public static function get_conditions($bundle_id)
    {
        global $wpdb;
        self::init_tables();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE bundle_id = %d ORDER BY group_id ASC, id ASC",
                self::$conditions_table,
                absint($bundle_id)
            )
        );

        if (empty($rows)) {
            return array();
        }

        $groups = array();
        foreach ($rows as $row) {
            $groups[(int) $row->group_id][] = $row;
        }

        return $groups;
    }

    /**
     * Save condition groups for a bundle.
     */
    public static function save_conditions($bundle_id, $groups)
    {
        global $wpdb;
        self::init_tables();

        $bundle_id = absint($bundle_id);

        $wpdb->delete(self::$conditions_table, array('bundle_id' => $bundle_id), array('%d'));

        if (empty($groups)) {
            return;
        }

        foreach ($groups as $group_id => $conditions) {
            if (empty($conditions)) {
                continue;
            }

            foreach ($conditions as $condition) {
                $row = array(
                    'bundle_id'            => $bundle_id,
                    'group_id'             => absint($group_id),
                    'condition_attribute'  => sanitize_key($condition['condition_attribute']),
                    'condition_operator'   => sanitize_key($condition['condition_operator'] ?? 'equals'),
                    'condition_value'      => sanitize_text_field($condition['condition_value']),
                    'include_children'     => absint($condition['include_children'] ?? 0),
                );

                $wpdb->insert(
                    self::$conditions_table,
                    $row,
                    array('%d', '%d', '%s', '%s', '%s', '%d')
                );
            }
        }

        self::rebuild_index_for_bundle($bundle_id);
    }

    /* ── Index ────────────────────────────────────────────────────── */

    /**
     * Rebuild the lookup index for a specific bundle.
     */
    public static function rebuild_index_for_bundle($id)
    {
        global $wpdb;
        self::init_tables();

        $wpdb->delete(self::$index_table, array('bundle_id' => absint($id)), array('%d'));

        $bundle = self::get($id);
        if (!$bundle || !$bundle->status) {
            return;
        }

        $groups = self::get_conditions($id);
        if (empty($groups)) {
            return;
        }

        $condition_keys = array();
        $has_not_equals = false;

        foreach ($groups as $conditions) {
            foreach ($conditions as $cond) {
                $attr     = sanitize_key($cond->condition_attribute);
                $val      = sanitize_text_field($cond->condition_value);
                $operator = isset($cond->condition_operator) ? $cond->condition_operator : 'equals';

                if ('not_equals' === $operator) {
                    $has_not_equals = true;
                    continue;
                }

                if ('specific_product' === $attr && false !== strpos($val, ',')) {
                    $ids = array_filter(array_map('absint', explode(',', $val)));
                    foreach ($ids as $pid) {
                        $condition_keys['specific_product:' . $pid] = true;
                    }
                } else {
                    $condition_keys[$attr . ':' . $val] = true;
                }

                if (!empty($cond->include_children) && taxonomy_exists($attr) && is_taxonomy_hierarchical($attr)) {
                    $parent_term = get_term_by('slug', $val, $attr);
                    if ($parent_term && !is_wp_error($parent_term)) {
                        $child_ids = get_term_children($parent_term->term_id, $attr);
                        if (!is_wp_error($child_ids)) {
                            $child_ids = array_slice($child_ids, 0, 500);
                            foreach ($child_ids as $child_id) {
                                $child_term = get_term($child_id, $attr);
                                if ($child_term && !is_wp_error($child_term)) {
                                    $condition_keys[$attr . ':' . sanitize_text_field($child_term->slug)] = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($has_not_equals) {
            $condition_keys['__not_equals__:1'] = true;
        }

        foreach (array_keys($condition_keys) as $condition_key) {
            $wpdb->insert(
                self::$index_table,
                array(
                    'condition_key' => $condition_key,
                    'bundle_id'     => absint($id),
                    'priority'      => absint($bundle->priority),
                ),
                array('%s', '%d', '%d')
            );
        }
    }

    /**
     * Rebuild the entire bundle lookup index.
     */
    public static function rebuild_full_bundle_index()
    {
        global $wpdb;
        self::init_tables();

        $wpdb->query($wpdb->prepare("TRUNCATE TABLE %i", self::$index_table));

        $bundles = self::get_all(array('status' => 1, 'limit' => 10000));

        foreach ($bundles as $bundle) {
            self::rebuild_index_for_bundle($bundle->id);
        }
    }

    /* ── Internal helpers ─────────────────────────────────────────── */

    private static function sanitize_bundle_data($data)
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

        if (isset($data['discount_type'])) {
            $allowed = array('none', 'percentage', 'fixed');
            $sanitized['discount_type'] = in_array($data['discount_type'], $allowed, true)
                ? $data['discount_type']
                : 'none';
        }

        if (isset($data['discount_value'])) {
            $sanitized['discount_value'] = max(0, floatval($data['discount_value']));
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

    private static function get_format($data)
    {
        $format_map = array(
            'name'           => '%s',
            'priority'       => '%d',
            'status'         => '%d',
            'discount_type'  => '%s',
            'discount_value' => '%s',
            'start_date'     => '%s',
            'end_date'       => '%s',
        );

        $format = array();
        foreach (array_keys($data) as $key) {
            $format[] = isset($format_map[$key]) ? $format_map[$key] : '%s';
        }

        return $format;
    }
}
