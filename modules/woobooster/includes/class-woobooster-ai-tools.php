<?php
/**
 * WooBooster AI tools — one implementation shared by the built-in AI chat and
 * the WordPress abilities exposed over MCP (see class-woobooster-abilities.php).
 *
 * Every write goes through validate_rule() first: condition and action values
 * must point at real categories, tags, attribute terms and published products,
 * so an AI can never save a rule built on a guessed slug or ID. New rules are
 * always created inactive for review.
 *
 * All methods return plain arrays (JSON-friendly) and never echo.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_AI_Tools
{
    /** Action sources an AI may use. */
    const ACTION_SOURCES = array('category', 'tag', 'attribute_value', 'specific_products', 'copurchase', 'trending', 'similar', 'recently_viewed');

    /** Smart sources need no action value. */
    const SMART_SOURCES = array('copurchase', 'trending', 'similar', 'recently_viewed');

    const OPERATORS = array('equals', 'not_equals', 'contains');

    const ORDERBY = array('rand', 'bestselling', 'price', 'price_desc', 'date', 'rating');

    const MAX_LIMIT = 24;

    /**
     * Search the catalog for products (title, content or SKU), categories,
     * tags or attribute terms.
     *
     * @param array $args {type: product|category|tag|attribute, query: string, limit?: int}
     * @return array{type: string, query: string, results: array}
     */
    public static function search_catalog(array $args): array
    {
        $type = isset($args['type']) ? sanitize_key((string) $args['type']) : 'product';
        $query = isset($args['query']) ? trim(sanitize_text_field((string) $args['query'])) : '';
        $limit = isset($args['limit']) ? absint($args['limit']) : 10;
        $limit = max(1, min(20, $limit ? $limit : 10));

        $results = array();

        if ('' === $query) {
            return array('type' => $type, 'query' => $query, 'results' => array(), 'note' => 'Empty query.');
        }

        if ('product' === $type) {
            $ids = array();
            // WooCommerce's own product search: title, content, excerpt and SKU.
            if (class_exists('WC_Data_Store')) {
                $store = WC_Data_Store::load('product');
                if (is_callable(array($store, 'search_products'))) {
                    $ids = (array) $store->search_products($query, '', false, false, $limit);
                }
            }
            if (empty($ids) && function_exists('wc_get_products')) {
                $ids = wc_get_products(array('status' => 'publish', 'limit' => $limit, 's' => $query, 'return' => 'ids'));
            }

            foreach (array_slice(array_values(array_filter(array_map('absint', $ids))), 0, $limit) as $id) {
                $product = function_exists('wc_get_product') ? wc_get_product($id) : null;
                if (!$product || 'publish' !== $product->get_status()) {
                    continue;
                }
                $cats = wp_get_post_terms($id, 'product_cat', array('fields' => 'names'));
                $results[] = array(
                    'id'         => $id,
                    'name'       => $product->get_name(),
                    'sku'        => (string) $product->get_sku(),
                    'price'      => (string) $product->get_price(),
                    'stock'      => (string) $product->get_stock_status(),
                    'categories' => is_wp_error($cats) ? array() : array_slice($cats, 0, 3),
                );
            }
        } elseif ('attribute' === $type) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $terms = $wpdb->get_results($wpdb->prepare(
                "SELECT t.term_id, t.name, t.slug, tt.taxonomy, tt.count
                FROM {$wpdb->terms} AS t
                INNER JOIN {$wpdb->term_taxonomy} AS tt ON t.term_id = tt.term_id
                WHERE (t.name LIKE %s OR t.slug LIKE %s) AND tt.taxonomy LIKE %s
                ORDER BY tt.count DESC
                LIMIT %d",
                '%' . $wpdb->esc_like($query) . '%',
                '%' . $wpdb->esc_like(sanitize_title($query)) . '%',
                $wpdb->esc_like('pa_') . '%',
                $limit
            ));
            foreach ((array) $terms as $t) {
                $results[] = array(
                    'id'           => (int) $t->term_id,
                    'name'         => $t->name,
                    'slug'         => $t->slug,
                    'taxonomy'     => $t->taxonomy,
                    'count'        => (int) $t->count,
                    'action_value' => $t->taxonomy . ':' . $t->slug,
                );
            }
        } else {
            $taxonomy = ('tag' === $type) ? 'product_tag' : 'product_cat';
            $terms = get_terms(array(
                'taxonomy'   => $taxonomy,
                'name__like' => $query,
                'number'     => $limit,
                'hide_empty' => false,
                'orderby'    => 'count',
                'order'      => 'DESC',
            ));
            if (!is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $item = array('id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'count' => (int) $t->count);
                    if ('product_cat' === $taxonomy && $t->parent) {
                        $parent = get_term((int) $t->parent, 'product_cat');
                        if ($parent && !is_wp_error($parent)) {
                            $item['parent'] = $parent->slug;
                        }
                    }
                    $results[] = $item;
                }
            }
        }

        return array('type' => $type, 'query' => $query, 'results' => $results);
    }

    /**
     * All rules, compactly: every condition group and action group.
     *
     * @return array{rules: array}
     */
    public static function list_rules(): array
    {
        $rules = WooBooster_Rule::get_all(array('limit' => 500));
        $out = array();

        foreach ((array) $rules as $rule) {
            $conditions = array();
            foreach ((array) WooBooster_Rule::get_conditions($rule->id) as $group) {
                $conditions[] = array_map(static function ($c) {
                    return trim(sprintf('%s %s %s', $c->condition_attribute ?? '', $c->condition_operator ?? '', $c->condition_value ?? ''));
                }, (array) $group);
            }

            $actions = array();
            foreach ((array) WooBooster_Rule::get_actions($rule->id) as $group) {
                $actions[] = array_map(static function ($a) {
                    $value = 'specific_products' === ($a->action_source ?? '') ? ($a->action_products ?? '') : ($a->action_value ?? '');
                    return trim(sprintf('%s %s (%s, limit %d)', $a->action_source ?? '', $value, $a->action_orderby ?? 'rand', (int) ($a->action_limit ?? 0)));
                }, (array) $group);
            }

            $out[] = array(
                'id'         => (int) $rule->id,
                'name'       => $rule->name,
                'priority'   => (int) $rule->priority,
                'active'     => !empty($rule->status),
                'conditions' => $conditions,
                'actions'    => $actions,
            );
        }

        return array('rules' => $out);
    }

    /**
     * Check a rule before it is saved and normalize it.
     *
     * Accepts the flat format used by the AI and the MCP abilities:
     * name, priority, condition_attribute, condition_operator, condition_value,
     * action_source, action_value, action_products, action_orderby, action_limit.
     * When $rule_id is given, missing fields are taken from that rule.
     *
     * @param array $rule    Rule fields.
     * @param int   $rule_id Existing rule being updated (0 = new rule).
     * @return array{valid: bool, errors: string[], warnings: string[], rule: array}
     */
    public static function validate_rule(array $rule, int $rule_id = 0): array
    {
        $errors = array();
        $warnings = array();

        if ($rule_id > 0) {
            $existing = self::flatten_rule($rule_id);
            if (null === $existing) {
                return array('valid' => false, 'errors' => array(sprintf('Rule #%d not found.', $rule_id)), 'warnings' => array(), 'rule' => array());
            }
            $rule = array_merge($existing, array_filter($rule, static function ($v) {
                return null !== $v;
            }));
        }

        $n = array(
            'name'                => trim(sanitize_text_field((string) ($rule['name'] ?? ''))),
            'priority'            => isset($rule['priority']) ? absint($rule['priority']) : 10,
            'condition_attribute' => sanitize_key((string) ($rule['condition_attribute'] ?? '')),
            'condition_operator'  => sanitize_key((string) ($rule['condition_operator'] ?? 'equals')),
            'condition_value'     => trim(sanitize_text_field((string) ($rule['condition_value'] ?? ''))),
            'action_source'       => sanitize_key((string) ($rule['action_source'] ?? '')),
            'action_value'        => trim(sanitize_text_field((string) ($rule['action_value'] ?? ''))),
            'action_products'     => trim(sanitize_text_field((string) ($rule['action_products'] ?? ''))),
            'action_orderby'      => sanitize_key((string) ($rule['action_orderby'] ?? 'rand')),
            'action_limit'        => isset($rule['action_limit']) ? absint($rule['action_limit']) : 4,
        );

        if ('' === $n['name']) {
            $errors[] = 'name is required.';
        }

        // Condition.
        $attr = $n['condition_attribute'];
        if ('' === $attr) {
            $errors[] = 'condition_attribute is required (product_cat, product_tag, specific_product or a pa_* attribute).';
        } elseif ('specific_product' === $attr) {
            $ids = self::id_list($n['condition_value']);
            if (empty($ids)) {
                $errors[] = 'condition_value must be one or more product IDs for specific_product.';
            } else {
                $missing = self::missing_products($ids);
                if ($missing) {
                    $errors[] = sprintf('Condition products not found or not published: %s.', implode(', ', $missing));
                }
                $n['condition_value'] = implode(',', $ids);
            }
        } elseif (in_array($attr, array('product_cat', 'product_tag'), true) || (0 === strpos($attr, 'pa_') && taxonomy_exists($attr))) {
            $slug = self::resolve_term_slug($n['condition_value'], $attr, $warnings);
            if (null === $slug) {
                $errors[] = sprintf('No %s term "%s" exists. Search with search_catalog and use the slug it returns.', $attr, $n['condition_value']);
            } else {
                $n['condition_value'] = $slug;
            }
        } else {
            $errors[] = sprintf('condition_attribute "%s" is not supported. Use product_cat, product_tag, specific_product or an existing pa_* attribute.', $attr);
        }

        if (!in_array($n['condition_operator'], self::OPERATORS, true)) {
            $errors[] = 'condition_operator must be equals, not_equals or contains.';
        }

        // Action.
        $source = $n['action_source'];
        if (!in_array($source, self::ACTION_SOURCES, true)) {
            $errors[] = sprintf('action_source must be one of: %s.', implode(', ', self::ACTION_SOURCES));
        } elseif ('category' === $source || 'tag' === $source) {
            $taxonomy = 'category' === $source ? 'product_cat' : 'product_tag';
            $slug = self::resolve_term_slug($n['action_value'], $taxonomy, $warnings);
            if (null === $slug) {
                $errors[] = sprintf('No %s "%s" exists for action_value.', $taxonomy, $n['action_value']);
            } else {
                $n['action_value'] = $slug;
            }
        } elseif ('attribute_value' === $source) {
            $parts = explode(':', $n['action_value'], 2);
            if (2 !== count($parts) && 0 === strpos($attr, 'pa_')) {
                // Common AI mistake: only the term slug. Assume the condition's attribute.
                $parts = array($attr, $n['action_value']);
                $warnings[] = sprintf('action_value had no taxonomy; assumed %s.', $attr);
            }
            if (2 !== count($parts) || 0 !== strpos($parts[0], 'pa_') || !taxonomy_exists($parts[0])) {
                $errors[] = 'action_value for attribute_value must be "pa_attribute:term-slug", e.g. "pa_caliber:9mm".';
            } else {
                $slug = self::resolve_term_slug($parts[1], $parts[0], $warnings);
                if (null === $slug) {
                    $errors[] = sprintf('No %s term "%s" exists.', $parts[0], $parts[1]);
                } else {
                    $n['action_value'] = $parts[0] . ':' . $slug;
                }
            }
        } elseif ('specific_products' === $source) {
            $ids = self::id_list($n['action_products']);
            if (empty($ids)) {
                $errors[] = 'action_products must list product IDs for specific_products.';
            } else {
                $missing = self::missing_products($ids);
                if ($missing) {
                    $errors[] = sprintf('Products not found or not published: %s.', implode(', ', $missing));
                }
                $out_of_stock = self::out_of_stock($ids);
                if ($out_of_stock) {
                    $warnings[] = sprintf('Out of stock right now (hidden while the stock filter is on): %s.', implode(', ', $out_of_stock));
                }
                $n['action_products'] = implode(',', $ids);
            }
        } elseif (in_array($source, self::SMART_SOURCES, true)) {
            $n['action_value'] = '';
            // Co-purchase and trending need their nightly index; recently
            // viewed needs view tracking. Similar works without a toggle.
            if ('similar' !== $source && '1' !== (string) woobooster_get_option('smart_' . $source, '0')) {
                $warnings[] = sprintf('Smart source "%s" is turned off in WooBooster settings, so this rule will show fallback best sellers until it is enabled.', $source);
            }
        }

        if (!in_array($n['action_orderby'], self::ORDERBY, true)) {
            $warnings[] = sprintf('Unknown action_orderby "%s"; using rand.', $n['action_orderby']);
            $n['action_orderby'] = 'rand';
        }

        if ('specific_products' === $source && !empty($n['action_products'])) {
            $n['action_limit'] = min(self::MAX_LIMIT, max(1, count(self::id_list($n['action_products']))));
        } else {
            $n['action_limit'] = min(self::MAX_LIMIT, max(1, $n['action_limit'] ? $n['action_limit'] : 4));
        }

        return array(
            'valid'    => empty($errors),
            'errors'   => $errors,
            'warnings' => $warnings,
            'rule'     => $n,
        );
    }

    /**
     * Create a rule (always inactive, for review) after validation.
     *
     * @param array $rule Flat rule fields (see validate_rule()).
     * @return array{success: bool, errors?: string[], warnings?: string[], rule_id?: int, edit_url?: string, message: string}
     */
    public static function create_rule(array $rule): array
    {
        $check = self::validate_rule($rule);
        if (!$check['valid']) {
            return array('success' => false, 'errors' => $check['errors'], 'warnings' => $check['warnings'], 'message' => 'Rule not created: fix the errors and try again.');
        }
        $n = $check['rule'];

        $rule_id = WooBooster_Rule::create(array(
            'name'                => $n['name'],
            'priority'            => $n['priority'],
            'status'              => 0,
            'condition_attribute' => $n['condition_attribute'],
            'condition_operator'  => $n['condition_operator'],
            'condition_value'     => $n['condition_value'],
            'action_source'       => $n['action_source'],
            'action_value'        => $n['action_value'],
            'action_orderby'      => $n['action_orderby'],
            'action_limit'        => $n['action_limit'],
        ));

        if (!$rule_id) {
            return array('success' => false, 'errors' => array('Failed to save the rule to the database.'), 'message' => 'Rule not created.');
        }

        self::save_rule_rows((int) $rule_id, $n);

        $edit_url = admin_url('admin.php?page=ffla-woobooster-rules&action=edit&rule_id=' . (int) $rule_id);

        return array(
            'success'  => true,
            'rule_id'  => (int) $rule_id,
            'active'   => false,
            'edit_url' => $edit_url,
            'warnings' => $check['warnings'],
            'message'  => sprintf('Rule #%d "%s" created (inactive — activate it after review).', $rule_id, $n['name']),
        );
    }

    /**
     * Update an existing rule after validating the merged result. Only the
     * fields given change; its single condition and action are rewritten.
     *
     * @param int   $rule_id Rule ID.
     * @param array $changes Flat rule fields to change.
     * @return array
     */
    public static function update_rule(int $rule_id, array $changes): array
    {
        unset($changes['rule_id']);
        $check = self::validate_rule($changes, $rule_id);
        if (!$check['valid']) {
            return array('success' => false, 'errors' => $check['errors'], 'warnings' => $check['warnings'], 'message' => sprintf('Rule #%d not updated.', $rule_id));
        }
        $n = $check['rule'];

        WooBooster_Rule::update($rule_id, array(
            'name'                => $n['name'],
            'priority'            => $n['priority'],
            'condition_attribute' => $n['condition_attribute'],
            'condition_operator'  => $n['condition_operator'],
            'condition_value'     => $n['condition_value'],
            'action_source'       => $n['action_source'],
            'action_value'        => $n['action_value'],
            'action_orderby'      => $n['action_orderby'],
            'action_limit'        => $n['action_limit'],
        ));

        self::save_rule_rows($rule_id, $n);

        return array(
            'success'  => true,
            'rule_id'  => $rule_id,
            'edit_url' => admin_url('admin.php?page=ffla-woobooster-rules&action=edit&rule_id=' . $rule_id),
            'warnings' => $check['warnings'],
            'message'  => sprintf('Rule #%d updated.', $rule_id),
        );
    }

    /**
     * Turn a rule on or off.
     *
     * @return array{success: bool, rule_id: int, active: bool, message: string}
     */
    public static function set_rule_status(int $rule_id, bool $active): array
    {
        $rule = WooBooster_Rule::get($rule_id);
        if (!$rule) {
            return array('success' => false, 'rule_id' => $rule_id, 'active' => false, 'message' => sprintf('Rule #%d not found.', $rule_id));
        }

        if ((bool) $rule->status !== $active) {
            WooBooster_Rule::toggle_status($rule_id);
        }

        return array('success' => true, 'rule_id' => $rule_id, 'active' => $active, 'message' => sprintf('Rule #%d is now %s.', $rule_id, $active ? 'active' : 'inactive'));
    }

    /**
     * Which rule a product matches and what it recommends.
     *
     * @return array
     */
    public static function diagnose_product(int $product_id): array
    {
        $matcher = new WooBooster_Matcher();
        $diag = $matcher->get_diagnostics($product_id);

        if (!empty($diag['error'])) {
            return array('product_id' => $product_id, 'error' => $diag['error']);
        }

        return array(
            'product_id'      => $product_id,
            'product_name'    => $diag['product_name'] ?? '',
            'matched_rule'    => $diag['matched_rule'] ?? null,
            'actions'         => array_map(static function ($a) {
                return array(
                    'source'  => $a['source'] ?? '',
                    'value'   => $a['value'] ?? '',
                    'results' => count((array) ($a['results'] ?? array())),
                );
            }, (array) ($diag['actions'] ?? array())),
            'recommendations' => array_map(static function ($p) {
                return array('id' => (int) $p['id'], 'name' => $p['name'], 'stock' => $p['stock']);
            }, (array) ($diag['products'] ?? array())),
        );
    }

    /* ── Internals ─────────────────────────────────────────────────────── */

    /**
     * Flat fields for an existing rule (first condition and action).
     */
    private static function flatten_rule(int $rule_id): ?array
    {
        $rule = WooBooster_Rule::get($rule_id);
        if (!$rule) {
            return null;
        }

        $flat = array(
            'name'     => $rule->name,
            'priority' => (int) $rule->priority,
        );

        $conditions = WooBooster_Rule::get_conditions($rule_id);
        $first_group = is_array($conditions) ? reset($conditions) : false;
        $cond = is_array($first_group) ? reset($first_group) : null;
        if ($cond) {
            $flat['condition_attribute'] = $cond->condition_attribute;
            $flat['condition_operator'] = $cond->condition_operator;
            $flat['condition_value'] = $cond->condition_value;
        }

        $actions = WooBooster_Rule::get_actions($rule_id);
        $first_group = is_array($actions) ? reset($actions) : false;
        $act = is_array($first_group) ? reset($first_group) : null;
        if ($act) {
            $flat['action_source'] = $act->action_source;
            $flat['action_value'] = $act->action_value;
            $flat['action_products'] = $act->action_products ?? '';
            $flat['action_orderby'] = $act->action_orderby;
            $flat['action_limit'] = (int) $act->action_limit;
        }

        return $flat;
    }

    /**
     * Write the condition and action rows for a validated rule.
     */
    private static function save_rule_rows(int $rule_id, array $n): void
    {
        WooBooster_Rule::save_conditions($rule_id, array(
            array(
                array(
                    'condition_attribute' => $n['condition_attribute'],
                    'condition_operator'  => $n['condition_operator'],
                    'condition_value'     => $n['condition_value'],
                    'include_children'    => 1,
                    'min_quantity'        => 1,
                ),
            ),
        ));

        $action_row = array(
            'action_source'    => $n['action_source'],
            'action_value'     => $n['action_value'],
            'action_orderby'   => $n['action_orderby'],
            'action_limit'     => $n['action_limit'],
            'include_children' => 1,
        );
        if ('specific_products' === $n['action_source']) {
            $action_row['action_products'] = $n['action_products'];
        }

        WooBooster_Rule::save_actions($rule_id, array(array($action_row)));
    }

    /**
     * Resolve a term by slug, or by exact name (case-insensitive) as a
     * fallback, returning its slug — or null when it does not exist.
     *
     * @param string   $value    Slug or name.
     * @param string   $taxonomy Taxonomy.
     * @param string[] $warnings Collects a note when a name was converted.
     */
    private static function resolve_term_slug(string $value, string $taxonomy, array &$warnings): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $term = get_term_by('slug', sanitize_title($value), $taxonomy);
        if ($term && !is_wp_error($term)) {
            return $term->slug;
        }

        $term = get_term_by('name', $value, $taxonomy);
        if ($term && !is_wp_error($term)) {
            $warnings[] = sprintf('"%s" is a name; used its slug "%s".', $value, $term->slug);
            return $term->slug;
        }

        return null;
    }

    /**
     * @return int[]
     */
    private static function id_list(string $value): array
    {
        return array_values(array_unique(array_filter(array_map('absint', preg_split('/[\s,]+/', $value)))));
    }

    /**
     * IDs that are not published products.
     *
     * @param int[] $ids
     * @return int[]
     */
    private static function missing_products(array $ids): array
    {
        $missing = array();
        foreach ($ids as $id) {
            if ('product' !== get_post_type($id) || 'publish' !== get_post_status($id)) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * IDs whose stock status is not "instock".
     *
     * @param int[] $ids
     * @return int[]
     */
    private static function out_of_stock(array $ids): array
    {
        $out = array();
        foreach ($ids as $id) {
            $status = get_post_meta($id, '_stock_status', true);
            if ('' !== $status && 'instock' !== $status) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
