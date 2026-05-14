<?php
/**
 * WooBooster Bundle Model.
 *
 * Handles CRUD operations for product bundles, including items, actions,
 * conditions, and lookup index management.
 *
 * @package FFL_Funnels_Addons
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
            'name'              => '',
            'priority'          => 10,
            'status'            => 1,
            'discount_type'     => 'none',
            'discount_value'    => 0,
            'bundle_price_type' => 'discount',
            'bundle_price'      => null,
        );

        $data = wp_parse_args($data, $defaults);
        $data = self::sanitize_bundle_data($data);

        $inserted = $wpdb->insert(self::$table, $data, self::get_format($data));

        if ($inserted) {
            $bundle_id = $wpdb->insert_id;
            // Index is rebuilt by save_conditions(); a fresh bundle has no conditions yet.
            WooBooster_Matcher::invalidate_recommendation_cache();
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
            WooBooster_Matcher::invalidate_recommendation_cache();
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

        WooBooster_Matcher::invalidate_recommendation_cache();

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

        $updated = $wpdb->update(
            self::$table,
            array('status' => $new_status),
            array('id' => absint($id)),
            array('%d'),
            array('%d')
        );

        if (false !== $updated) {
            // Index entries depend on bundle->status; rebuild so deactivated bundles drop out.
            self::rebuild_index_for_bundle($id);
            WooBooster_Matcher::invalidate_recommendation_cache();
            return true;
        }

        return false;
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
            'name'              => $bundle->name . ' (Copy)',
            'priority'          => $bundle->priority,
            'status'            => 0,
            'discount_type'     => $bundle->discount_type,
            'discount_value'    => $bundle->discount_value,
            'bundle_price_type' => isset($bundle->bundle_price_type) ? $bundle->bundle_price_type : 'discount',
            'bundle_price'      => isset($bundle->bundle_price) ? $bundle->bundle_price : null,
            'start_date'        => $bundle->start_date,
            'end_date'          => $bundle->end_date,
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
                    'quantity'    => isset($item->quantity) ? $item->quantity : 1,
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

        self::with_transaction(function () use ($wpdb, $bundle_id, $items) {
            $wpdb->delete(self::$items_table, array('bundle_id' => $bundle_id), array('%d'));

            if (empty($items)) {
                return;
            }

            $values = array();
            $params = array();
            foreach ($items as $index => $item) {
                $values[] = '(%d, %d, %d, %d, %d)';
                array_push(
                    $params,
                    $bundle_id,
                    absint($item['product_id']),
                    isset($item['quantity']) ? max(1, absint($item['quantity'])) : 1,
                    isset($item['sort_order']) ? absint($item['sort_order']) : $index,
                    !empty($item['is_optional']) ? 1 : 0
                );
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}woobooster_bundle_items (bundle_id, product_id, quantity, sort_order, is_optional) VALUES " . implode(', ', $values),
                ...$params
            ));
        });
    }

    /**
     * Wrap a write callback in a DB transaction. InnoDB only; degrades to a
     * straight call when the engine doesn't support transactions.
     */
    private static function with_transaction(callable $fn)
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            $fn();
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
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

        self::with_transaction(function () use ($wpdb, $bundle_id, $groups) {
            self::save_actions_inner($wpdb, $bundle_id, $groups);
        });
    }

    private static function save_actions_inner($wpdb, $bundle_id, $groups)
    {
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
     * Get condition groups for many bundles in a single query.
     *
     * @param int[] $bundle_ids
     * @return array Map: [ bundle_id => [ group_id => [ condition, ... ], ... ], ... ]
     */
    public static function get_conditions_for_bundles(array $bundle_ids)
    {
        global $wpdb;
        self::init_tables();

        $bundle_ids = array_values(array_filter(array_map('absint', $bundle_ids)));
        if (empty($bundle_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($bundle_ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}woobooster_bundle_conditions
             WHERE bundle_id IN ({$placeholders})
             ORDER BY bundle_id ASC, group_id ASC, id ASC",
            ...$bundle_ids
        ));

        $out = array();
        foreach ($bundle_ids as $bid) {
            $out[$bid] = array();
        }
        foreach ($rows as $row) {
            $out[(int) $row->bundle_id][(int) $row->group_id][] = $row;
        }
        return $out;
    }

    /**
     * Save condition groups for a bundle.
     */
    public static function save_conditions($bundle_id, $groups)
    {
        global $wpdb;
        self::init_tables();

        $bundle_id = absint($bundle_id);

        self::with_transaction(function () use ($wpdb, $bundle_id, $groups) {
            $wpdb->delete(self::$conditions_table, array('bundle_id' => $bundle_id), array('%d'));

            if (empty($groups)) {
                return;
            }

            $values = array();
            $params = array();
            foreach ($groups as $group_id => $conditions) {
                if (empty($conditions)) {
                    continue;
                }
                foreach ($conditions as $condition) {
                    $values[] = '(%d, %d, %s, %s, %s, %d)';
                    array_push(
                        $params,
                        $bundle_id,
                        absint($group_id),
                        sanitize_key($condition['condition_attribute']),
                        sanitize_key($condition['condition_operator'] ?? 'equals'),
                        sanitize_text_field($condition['condition_value']),
                        absint($condition['include_children'] ?? 0)
                    );
                }
            }

            if (!empty($values)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}woobooster_bundle_conditions
                     (bundle_id, group_id, condition_attribute, condition_operator, condition_value, include_children)
                     VALUES " . implode(', ', $values),
                    ...$params
                ));
            }
        });

        self::rebuild_index_for_bundle($bundle_id);
        WooBooster_Matcher::invalidate_recommendation_cache();
    }

    /* ── Index ────────────────────────────────────────────────────── */

    /**
     * Rebuild the lookup index for a specific bundle.
     */
    public static function rebuild_index_for_bundle($id)
    {
        global $wpdb;
        self::init_tables();
        $id = absint($id);

        self::with_transaction(function () use ($wpdb, $id) {
            $wpdb->delete(self::$index_table, array('bundle_id' => $id), array('%d'));

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
                            if (!is_wp_error($child_ids) && !empty($child_ids)) {
                                $child_ids = array_slice($child_ids, 0, 500);
                                // Bulk load child slugs in a single query instead of N x get_term().
                                $children = get_terms(array(
                                    'taxonomy'   => $attr,
                                    'include'    => $child_ids,
                                    'hide_empty' => false,
                                    'fields'     => 'id=>slug',
                                ));
                                if (!is_wp_error($children)) {
                                    foreach ($children as $child_slug) {
                                        $condition_keys[$attr . ':' . sanitize_text_field($child_slug)] = true;
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

            if (empty($condition_keys)) {
                return;
            }

            $values   = array();
            $params   = array();
            $priority = absint($bundle->priority);
            foreach (array_keys($condition_keys) as $ck) {
                $values[] = '(%s, %d, %d)';
                array_push($params, $ck, $id, $priority);
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}woobooster_bundle_index (condition_key, bundle_id, priority) VALUES " . implode(', ', $values),
                ...$params
            ));
        });
    }

    /**
     * Rebuild the entire bundle lookup index.
     */
    public static function rebuild_full_bundle_index()
    {
        global $wpdb;
        self::init_tables();

        $wpdb->query($wpdb->prepare("TRUNCATE TABLE %i", self::$index_table));

        $offset = 0;
        $page_size = 500;
        do {
            $bundles = self::get_all(array(
                'status' => 1,
                'limit'  => $page_size,
                'offset' => $offset,
            ));
            foreach ($bundles as $bundle) {
                self::rebuild_index_for_bundle($bundle->id);
            }
            $offset += $page_size;
        } while (count($bundles) === $page_size);
    }

    /**
     * Calculate per-item original and discounted prices for a bundle.
     *
     * Used by both the widget renderer and the AJAX add-to-cart so that
     * the customer is charged exactly what they saw on the page.
     *
     * @param object $bundle      Bundle row.
     * @param array  $product_ids Product IDs to price.
     * @return array Map of product_id => ['original' => float, 'discounted' => float].
     */
    public static function calculate_item_prices($bundle, array $product_ids)
    {
        $product_ids = array_values(array_filter(array_map('absint', $product_ids)));
        if (empty($product_ids) || !$bundle) {
            return array();
        }

        $prices = array();
        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            $prices[$pid] = (float) $product->get_price();
        }

        $dec = function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2;

        // Fixed bundle price mode: distribute a single target total across items.
        $price_type = isset($bundle->bundle_price_type) ? $bundle->bundle_price_type : 'discount';
        if ('fixed' === $price_type && isset($bundle->bundle_price) && null !== $bundle->bundle_price) {
            return self::apply_fixed_bundle_price($prices, (float) $bundle->bundle_price, $dec);
        }

        return self::apply_discount_to_prices(
            $prices,
            $bundle->discount_type ?? 'none',
            (float) ($bundle->discount_value ?? 0),
            $dec
        );
    }

    /**
     * Pure helper: distribute a fixed bundle price across items pro-rata.
     *
     * Each item's discounted per-unit price is its share of the target total,
     * weighted by its original price. When the target exceeds the sum of
     * originals (a markup), each item is capped at its original price so no
     * item is ever priced above its standalone value.
     *
     * @param array<int, float> $prices       Map of product_id => original price.
     * @param float             $bundle_price Target total for the full set.
     * @param int               $dec          Rounding decimals.
     * @return array Map of product_id => ['original' => float, 'discounted' => float].
     */
    public static function apply_fixed_bundle_price(array $prices, $bundle_price, $dec = 2)
    {
        $bundle_price = max(0.0, (float) $bundle_price);
        $out          = array();
        $sub          = 0.0;
        foreach ($prices as $pid => $price) {
            $price     = (float) $price;
            $out[$pid] = array('original' => $price, 'discounted' => $price);
            $sub      += $price;
        }
        if (empty($out) || $sub <= 0) {
            return $out;
        }

        // A target at or above the subtotal is not a discount — leave originals.
        if ($bundle_price >= $sub) {
            return $out;
        }

        $factor = $bundle_price / $sub;
        foreach ($out as $pid => &$row) {
            $row['discounted'] = max(0.0, round($row['original'] * $factor, $dec));
        }
        unset($row);

        return $out;
    }

    /**
     * Pure helper: apply a bundle-level discount to a price map.
     *
     * @param array<int, float> $prices  Map of product_id => original price.
     * @param string            $type    'none' | 'percentage' | 'fixed'.
     * @param float             $value   Discount amount (% or currency units).
     * @param int               $dec     Rounding decimals.
     * @return array Map of product_id => ['original' => float, 'discounted' => float].
     */
    public static function apply_discount_to_prices(array $prices, $type, $value, $dec = 2)
    {
        $value = (float) $value;
        $out   = array();
        $sub   = 0.0;
        foreach ($prices as $pid => $price) {
            $price       = (float) $price;
            $out[$pid]   = array('original' => $price, 'discounted' => $price);
            $sub        += $price;
        }
        if (empty($out)) {
            return array();
        }

        if ('percentage' === $type && $value > 0) {
            $factor = max(0.0, 1 - ($value / 100));
            foreach ($out as $pid => &$row) {
                $row['discounted'] = round($row['original'] * $factor, $dec);
            }
            unset($row);
        } elseif ('fixed' === $type && $value > 0 && $sub > 0) {
            $total_discount = min($value, $sub);
            foreach ($out as $pid => &$row) {
                $share              = ($row['original'] / $sub) * $total_discount;
                $row['discounted']  = max(0.0, round($row['original'] - $share, $dec));
            }
            unset($row);
        }

        return $out;
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

        if (isset($data['bundle_price_type'])) {
            $allowed = array('discount', 'fixed');
            $sanitized['bundle_price_type'] = in_array($data['bundle_price_type'], $allowed, true)
                ? $data['bundle_price_type']
                : 'discount';
        }

        if (array_key_exists('bundle_price', $data)) {
            $sanitized['bundle_price'] = ('' === $data['bundle_price'] || null === $data['bundle_price'])
                ? null
                : max(0, floatval($data['bundle_price']));
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
            'name'              => '%s',
            'priority'          => '%d',
            'status'            => '%d',
            'discount_type'     => '%s',
            'discount_value'    => '%s',
            'bundle_price_type' => '%s',
            'bundle_price'      => '%s',
            'start_date'        => '%s',
            'end_date'          => '%s',
        );

        $format = array();
        foreach (array_keys($data) as $key) {
            $format[] = isset($format_map[$key]) ? $format_map[$key] : '%s';
        }

        return $format;
    }
}
