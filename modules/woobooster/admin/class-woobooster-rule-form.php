<?php
/**
 * WooBooster Rule Form.
 *
 * Handles rendering and processing of the Add/Edit rule form.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Rule_Form
{

    /**
     * Render the form.
     */
    public function render()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $rule_id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
        $rule = $rule_id ? WooBooster_Rule::get($rule_id) : null;
        $is_edit = !empty($rule);

        // Handle save.
        $this->handle_save();

        $title = $is_edit
            ? __('Edit Rule', 'woobooster')
            : __('Add New Rule', 'woobooster');

        // Default values.
        $name = $rule ? $rule->name : '';
        $priority = $rule ? $rule->priority : 10;
        $status = $rule ? $rule->status : 1;
        $action_source = $rule ? $rule->action_source : 'category';
        $action_value = $rule ? $rule->action_value : '';
        $action_orderby = $rule ? $rule->action_orderby : 'rand';
        $action_limit = $rule ? $rule->action_limit : 4;
        $exclude_outofstock = $rule ? $rule->exclude_outofstock : 1;

        $taxonomies = WooBooster_Rule::get_product_taxonomies();

        // Load condition groups from new conditions table.
        $condition_groups = $rule_id ? WooBooster_Rule::get_conditions($rule_id) : array();
        if (empty($condition_groups)) {
            // Default: one group with one empty condition.
            $condition_groups = array(
                0 => array(
                    (object) array(
                        'condition_attribute' => '',
                        'condition_operator' => 'equals',
                        'condition_value' => '',
                        'include_children' => 0,
                    ),
                ),
            );
        }

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header">';
        echo '<h2>' . esc_html($title) . '</h2>';
        $back_url = admin_url('admin.php?page=ffla-woobooster-rules');
        echo '<a href="' . esc_url($back_url) . '" class="wb-btn wb-btn--subtle wb-btn--sm">';
        echo WooBooster_Icons::get('chevron-left'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo esc_html__('Back to Rules', 'woobooster');
        echo '</a>';
        echo '</div>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['saved']) && '1' === $_GET['saved']) {
            echo '<div class="wb-message wb-message--success">';
            echo WooBooster_Icons::get('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<span>' . esc_html__('Rule saved successfully.', 'woobooster') . '</span>';
            echo '</div>';
        }


        echo '<form method="post" action="" class="wb-form">';
        wp_nonce_field('woobooster_save_rule', 'woobooster_rule_nonce');

        if ($rule_id) {
            echo '<input type="hidden" name="rule_id" value="' . esc_attr($rule_id) . '">';
        }

        // ── Basic Settings ──────────────────────────────────────────────────

        echo '<div class="wb-card__section">';
        echo '<h3>' . esc_html__('Basic Settings', 'woobooster') . '</h3>';

        // Name.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-rule-name">' . esc_html__('Rule Name', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<input type="text" id="wb-rule-name" name="rule_name" value="' . esc_attr($name) . '" class="wb-input" required>';
        echo '</div></div>';

        // Priority.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-rule-priority">' . esc_html__('Priority', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<input type="number" id="wb-rule-priority" name="rule_priority" value="' . esc_attr($priority) . '" min="1" max="999" class="wb-input wb-input--sm">';
        echo '<p class="wb-field__desc">' . esc_html__('Lower number = higher priority. If multiple rules match, the lowest priority wins.', 'woobooster') . '</p>';
        echo '</div></div>';

        // Status.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Status', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" name="rule_status" value="1"' . checked($status, 1, false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '</div></div>';

        // Schedule.
        $start_date = $rule && isset($rule->start_date) ? $rule->start_date : '';
        $end_date = $rule && isset($rule->end_date) ? $rule->end_date : '';

        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Schedule', 'woobooster') . '</label>';
        echo '<div class="wb-field__control wb-schedule-row">';
        echo '<label class="wb-schedule-label">' . esc_html__('From', 'woobooster');
        echo '<input type="datetime-local" name="rule_start_date" value="' . esc_attr($start_date ? date('Y-m-d\TH:i', strtotime($start_date)) : '') . '" class="wb-input wb-input--sm wb-input--auto">';
        echo '</label>';
        echo '<label class="wb-schedule-label">' . esc_html__('Until', 'woobooster');
        echo '<input type="datetime-local" name="rule_end_date" value="' . esc_attr($end_date ? date('Y-m-d\TH:i', strtotime($end_date)) : '') . '" class="wb-input wb-input--sm wb-input--auto">';
        echo '</label>';
        echo '</div>';
        echo '<p class="wb-field__desc">' . esc_html__('Optional. Leave empty to keep the rule always active (when enabled).', 'woobooster') . '</p>';
        echo '</div>';

        echo '</div>'; // .wb-card__section

        // ── Conditions ──────────────────────────────────────────────────────

        echo '<div class="wb-card__section" id="wb-conditions-section">';
        echo '<h3>' . esc_html__('Conditions', 'woobooster') . '</h3>';
        echo '<p class="wb-section-desc">' . esc_html__('Groups are combined with OR. Conditions within a group are combined with AND.', 'woobooster') . '</p>';

        echo '<div id="wb-condition-groups">';

        $group_index = 0;
        foreach ($condition_groups as $group_id => $conditions) {
            if ($group_index > 0) {
                echo '<div class="wb-or-divider">' . esc_html__('— OR —', 'woobooster') . '</div>';
            }

            echo '<div class="wb-condition-group" data-group="' . esc_attr($group_index) . '">';
            echo '<div class="wb-condition-group__header">';
            echo '<span class="wb-condition-group__label">' . esc_html__('Condition Group', 'woobooster') . ' ' . ($group_index + 1) . '</span>';
            if ($group_index > 0) {
                echo '<button type="button" class="wb-btn wb-btn--danger wb-btn--xs wb-remove-group" title="' . esc_attr__('Remove Group', 'woobooster') . '">&times;</button>';
            }
            echo '</div>';

            $cond_index = 0;
            foreach ($conditions as $cond) {
                $c_attr = is_object($cond) ? $cond->condition_attribute : '';
                $c_val = is_object($cond) ? $cond->condition_value : '';
                $c_op = is_object($cond) && isset($cond->condition_operator) ? $cond->condition_operator : 'equals';
                $c_inc = is_object($cond) ? (int) $cond->include_children : 0;
                $c_min_qty = is_object($cond) && isset($cond->min_quantity) ? max(1, (int) $cond->min_quantity) : 1;

                // Resolve label for existing values.
                $c_label = '';
                if ('specific_product' === $c_attr && $c_val) {
                    // Multi-product: chips rendered by JS from hidden value.
                    $c_label = '';
                } elseif ($c_val && $c_attr) {
                    $term = get_term_by('slug', $c_val, $c_attr);
                    if ($term && !is_wp_error($term)) {
                        $c_label = $term->name;
                    }
                }

                $field_prefix = 'conditions[' . $group_index . '][' . $cond_index . ']';

                echo '<div class="wb-condition-row" data-condition="' . esc_attr($cond_index) . '">';

                // Determine condition type from existing attribute.
                $c_type = '';
                $c_attr_taxonomy = '';
                if ('specific_product' === $c_attr) {
                    $c_type = 'specific_product';
                } elseif ('product_cat' === $c_attr) {
                    $c_type = 'category';
                } elseif ('product_tag' === $c_attr) {
                    $c_type = 'tag';
                } elseif (!empty($c_attr)) {
                    $c_type = 'attribute';
                    $c_attr_taxonomy = $c_attr;
                }

                // Condition Type select.
                echo '<select class="wb-select wb-select--inline wb-condition-type" required>';
                echo '<option value="">' . esc_html__('Type…', 'woobooster') . '</option>';
                echo '<option value="category"' . selected($c_type, 'category', false) . '>' . esc_html__('Category', 'woobooster') . '</option>';
                echo '<option value="tag"' . selected($c_type, 'tag', false) . '>' . esc_html__('Tag', 'woobooster') . '</option>';
                echo '<option value="attribute"' . selected($c_type, 'attribute', false) . '>' . esc_html__('Attribute', 'woobooster') . '</option>';
                echo '<option value="specific_product"' . selected($c_type, 'specific_product', false) . '>' . esc_html__('Specific Product', 'woobooster') . '</option>';
                echo '</select>';

                // Attribute Taxonomy select (shown only when type = attribute).
                $cond_attr_taxonomies = wc_get_attribute_taxonomies();
                $display_cond_attr = 'attribute' === $c_type ? '' : 'display:none;';
                echo '<select class="wb-select wb-select--inline wb-condition-attr-taxonomy" style="' . esc_attr($display_cond_attr) . '">';
                echo '<option value="">' . esc_html__('Attribute…', 'woobooster') . '</option>';
                if ($cond_attr_taxonomies) {
                    foreach ($cond_attr_taxonomies as $attribute) {
                        $tax_name = wc_attribute_taxonomy_name($attribute->attribute_name);
                        echo '<option value="' . esc_attr($tax_name) . '"' . selected($c_attr_taxonomy, $tax_name, false) . '>';
                        echo esc_html($attribute->attribute_label);
                        echo '</option>';
                    }
                }
                echo '</select>';

                // Hidden attribute value (actual taxonomy name for save).
                echo '<input type="hidden" name="' . esc_attr($field_prefix . '[attribute]') . '" class="wb-condition-attr" value="' . esc_attr($c_attr) . '">';

                // Operator select.
                echo '<select name="' . esc_attr($field_prefix . '[operator]') . '" class="wb-select wb-select--operator wb-condition-operator">';
                echo '<option value="equals"' . selected($c_op, 'equals', false) . '>' . esc_html__('is', 'woobooster') . '</option>';
                echo '<option value="not_equals"' . selected($c_op, 'not_equals', false) . '>' . esc_html__('is not', 'woobooster') . '</option>';
                echo '</select>';

                // Value autocomplete.
                echo '<div class="wb-autocomplete wb-condition-value-wrap">';
                echo '<input type="text" class="wb-input wb-autocomplete__input wb-condition-value-display" placeholder="' . esc_attr__('Value…', 'woobooster') . '" value="' . esc_attr($c_label) . '" autocomplete="off">';
                echo '<input type="hidden" name="' . esc_attr($field_prefix . '[value]') . '" class="wb-condition-value-hidden" value="' . esc_attr($c_val) . '">';
                echo '<div class="wb-autocomplete__dropdown"></div>';
                $chips_display = 'specific_product' === $c_type ? '' : 'display:none;';
                echo '<div class="wb-condition-product-chips wb-chips" style="' . esc_attr($chips_display) . '"></div>';
                echo '</div>';

                // Include children.
                echo '<label class="wb-checkbox wb-condition-children-label" style="display:none;">';
                echo '<input type="checkbox" name="' . esc_attr($field_prefix . '[include_children]') . '" value="1"' . checked($c_inc, 1, false) . '> ';
                echo esc_html__('+ Children', 'woobooster');
                echo '</label>';

                // Min quantity.
                echo '<input type="number" name="' . esc_attr($field_prefix . '[min_quantity]') . '" value="' . esc_attr($c_min_qty) . '" min="1" class="wb-input wb-input--sm wb-input--w60" title="' . esc_attr__('Min cart qty (coupon rules only)', 'woobooster') . '" placeholder="Qty">';

                // Remove button.
                if ($cond_index > 0 || count($conditions) > 1) {
                    echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-condition" title="' . esc_attr__('Remove', 'woobooster') . '">&times;</button>';
                }

                echo '</div>'; // .wb-condition-row

                // ── Condition Exclusion Panel (collapsible) ──
                $cex_cats = is_object($cond) && isset($cond->exclude_categories) ? $cond->exclude_categories : '';
                $cex_prods = is_object($cond) && isset($cond->exclude_products) ? $cond->exclude_products : '';
                $cex_price_min = is_object($cond) && isset($cond->exclude_price_min) ? $cond->exclude_price_min : '';
                $cex_price_max = is_object($cond) && isset($cond->exclude_price_max) ? $cond->exclude_price_max : '';
                $cex_has = $cex_cats || $cex_prods || '' !== $cex_price_min || '' !== $cex_price_max;

                echo '<div class="wb-cond-exclusion-panel wb-sub-panel">';
                echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-cond-exclusions">';
                echo ($cex_has ? '&#9660;' : '&#9654;') . ' ' . esc_html__('Condition Exclusions', 'woobooster');
                echo '</button>';

                $cex_body_display = $cex_has ? '' : 'display:none;';
                echo '<div class="wb-cond-exclusion-body" style="' . esc_attr($cex_body_display) . '">';

                // Exclude Categories.
                echo '<div class="wb-field">';
                echo '<label class="wb-field__label">' . esc_html__('Exclude Categories', 'woobooster') . '</label>';
                echo '<div class="wb-autocomplete wb-autocomplete--sm wb-cond-exclude-cats-search">';
                echo '<input type="text" class="wb-input wb-cond-exclude-cats__input" placeholder="' . esc_attr__('Search categories…', 'woobooster') . '" autocomplete="off">';
                echo '<input type="hidden" name="' . esc_attr($field_prefix . '[exclude_categories]') . '" class="wb-cond-exclude-cats__ids" value="' . esc_attr($cex_cats) . '">';
                echo '<div class="wb-autocomplete__dropdown"></div>';
                echo '<div class="wb-cond-exclude-cats-chips wb-chips"></div>';
                echo '</div></div>';

                // Exclude Products.
                echo '<div class="wb-field">';
                echo '<label class="wb-field__label">' . esc_html__('Exclude Products', 'woobooster') . '</label>';
                echo '<div class="wb-autocomplete wb-autocomplete--sm wb-cond-exclude-prods-search">';
                echo '<input type="text" class="wb-input wb-cond-exclude-prods__input" placeholder="' . esc_attr__('Search products…', 'woobooster') . '" autocomplete="off">';
                echo '<input type="hidden" name="' . esc_attr($field_prefix . '[exclude_products]') . '" class="wb-cond-exclude-prods__ids" value="' . esc_attr($cex_prods) . '">';
                echo '<div class="wb-autocomplete__dropdown"></div>';
                echo '<div class="wb-cond-exclude-prods-chips wb-chips"></div>';
                echo '</div></div>';

                // Exclude Price Range.
                echo '<div class="wb-field">';
                echo '<label class="wb-field__label">' . esc_html__('Price Range Filter', 'woobooster') . '</label>';
                echo '<div class="wb-price-range">';
                echo '<input type="number" name="' . esc_attr($field_prefix . '[exclude_price_min]') . '" value="' . esc_attr($cex_price_min) . '" class="wb-input wb-input--sm wb-input--w90" placeholder="' . esc_attr__('Min $', 'woobooster') . '" step="0.01" min="0">';
                echo '<span>&mdash;</span>';
                echo '<input type="number" name="' . esc_attr($field_prefix . '[exclude_price_max]') . '" value="' . esc_attr($cex_price_max) . '" class="wb-input wb-input--sm wb-input--w90" placeholder="' . esc_attr__('Max $', 'woobooster') . '" step="0.01" min="0">';
                echo '</div></div>';

                echo '</div>'; // .wb-cond-exclusion-body
                echo '</div>'; // .wb-cond-exclusion-panel

                $cond_index++;
            }

            echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--sm wb-add-condition">';
            echo '+ ' . esc_html__('AND Condition', 'woobooster');
            echo '</button>';

            echo '</div>'; // .wb-condition-group
            $group_index++;
        }

        echo '</div>'; // #wb-condition-groups

        echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--sm" id="wb-add-group">';
        echo '+ ' . esc_html__('OR Group', 'woobooster');
        echo '</button>';

        echo '</div>'; // .wb-card__section

        // ── Action ──────────────────────────────────────────────────────────

        echo '<div class="wb-card__section" id="wb-actions-section">';
        echo '<h3>' . esc_html__('Actions', 'woobooster') . '</h3>';
        echo '<p class="wb-section-desc">' . esc_html__('Define one or more actions to execute when the condition matches.', 'woobooster') . '</p>';

        $actions = $rule_id ? WooBooster_Rule::get_actions($rule_id) : array();
        if (empty($actions)) {
            $actions = array(
                (object) array(
                    'action_source' => 'category',
                    'action_value' => '',
                    'action_orderby' => 'rand',
                    'action_limit' => 4,
                    'action_products' => '',
                    'action_coupon_id' => '',
                    'exclude_categories' => '',
                    'exclude_products' => '',
                    'exclude_price_min' => '',
                    'exclude_price_max' => '',
                )
            );
        }

        $action_logic = $rule && isset($rule->action_logic) ? $rule->action_logic : 'or';
        echo '<input type="hidden" name="action_logic" id="wb-action-logic" value="' . esc_attr($action_logic) . '">';

        echo '<div id="wb-action-repeater">';

        foreach ($actions as $index => $action) {
            // AND/OR divider between action rows.
            if ($index > 0) {
                echo '<div class="wb-action-logic-divider">';
                echo '<select class="wb-action-logic-select">';
                echo '<option value="or"' . selected($action_logic, 'or', false) . '>' . esc_html__('OR', 'woobooster') . '</option>';
                echo '<option value="and"' . selected($action_logic, 'and', false) . '>' . esc_html__('AND', 'woobooster') . '</option>';
                echo '</select>';
                echo '</div>';
            }
            $a_source = $action->action_source;
            $a_value = $action->action_value;
            $a_orderby = $action->action_orderby;
            $a_limit = $action->action_limit;
            $a_inc = isset($action->include_children) ? (int) $action->include_children : 0;

            // Resolve label for existing value.
            $a_label = '';
            $selected_attr_tax = '';
            $attr_term_slug = '';

            if ($a_value) {
                if ('attribute_value' === $a_source && false !== strpos($a_value, ':')) {
                    $parts = explode(':', $a_value, 2);
                    $selected_attr_tax = $parts[0];
                    $attr_term_slug = $parts[1];
                    $term = get_term_by('slug', $attr_term_slug, $selected_attr_tax);
                    if ($term && !is_wp_error($term)) {
                        $a_label = $term->name;
                    }
                } else {
                    $action_tax = 'category' === $a_source ? 'product_cat' : ('tag' === $a_source ? 'product_tag' : '');
                    if ($action_tax) {
                        $term = get_term_by('slug', $a_value, $action_tax);
                        if ($term && !is_wp_error($term)) {
                            $a_label = $term->name;
                        }
                    }
                }
            }

            $prefix = 'actions[' . $index . ']';

            echo '<div class="wb-action-row" data-index="' . esc_attr($index) . '">';

            // Source Type.
            echo '<select name="' . esc_attr($prefix . '[action_source]') . '" class="wb-select wb-select--inline wb-action-source">';
            echo '<option value="category"' . selected($a_source, 'category', false) . '>' . esc_html__('Category', 'woobooster') . '</option>';
            echo '<option value="tag"' . selected($a_source, 'tag', false) . '>' . esc_html__('Tag', 'woobooster') . '</option>';
            echo '<option value="attribute"' . selected($a_source, 'attribute', false) . '>' . esc_html__('Same Attribute', 'woobooster') . '</option>';
            echo '<option value="attribute_value"' . selected($a_source, 'attribute_value', false) . '>' . esc_html__('Attribute', 'woobooster') . '</option>';
            echo '<option value="copurchase"' . selected($a_source, 'copurchase', false) . '>' . esc_html__('Bought Together', 'woobooster') . '</option>';
            echo '<option value="trending"' . selected($a_source, 'trending', false) . '>' . esc_html__('Trending', 'woobooster') . '</option>';
            echo '<option value="recently_viewed"' . selected($a_source, 'recently_viewed', false) . '>' . esc_html__('Recently Viewed', 'woobooster') . '</option>';
            echo '<option value="similar"' . selected($a_source, 'similar', false) . '>' . esc_html__('Similar Products', 'woobooster') . '</option>';
            echo '<option value="specific_products"' . selected($a_source, 'specific_products', false) . '>' . esc_html__('Specific Products', 'woobooster') . '</option>';
            echo '<option value="apply_coupon"' . selected($a_source, 'apply_coupon', false) . '>' . esc_html__('Apply Coupon', 'woobooster') . '</option>';
            echo '</select>';

            // Attribute Taxonomy Selector (for attribute_value source).
            $attr_taxonomies = wc_get_attribute_taxonomies();
            $display_attr = 'attribute_value' === $a_source ? '' : 'display:none;';
            echo '<select class="wb-select wb-select--inline wb-action-attr-taxonomy" style="' . esc_attr($display_attr) . '">';
            echo '<option value="">' . esc_html__('Attribute…', 'woobooster') . '</option>';
            if ($attr_taxonomies) {
                foreach ($attr_taxonomies as $attribute) {
                    $tax_name = wc_attribute_taxonomy_name($attribute->attribute_name);
                    echo '<option value="' . esc_attr($tax_name) . '"' . selected($selected_attr_tax, $tax_name, false) . '>';
                    echo esc_html($attribute->attribute_label);
                    echo '</option>';
                }
            }
            echo '</select>';

            // Value Autocomplete.
            echo '<div class="wb-autocomplete wb-action-value-wrap">';
            echo '<input type="text" class="wb-input wb-autocomplete__input wb-action-value-display" placeholder="' . esc_attr__('Value…', 'woobooster') . '" value="' . esc_attr($a_label) . '" autocomplete="off">';
            echo '<input type="hidden" name="' . esc_attr($prefix . '[action_value]') . '" class="wb-action-value-hidden" value="' . esc_attr($a_value) . '">';
            echo '<div class="wb-autocomplete__dropdown"></div>';
            echo '</div>';

            // Include Children Checkbox (hidden unless source=category).
            $display_inc = 'category' === $a_source ? '' : 'display:none;';
            echo '<label class="wb-checkbox wb-action-children-label" style="' . esc_attr($display_inc) . '">';
            echo '<input type="checkbox" name="' . esc_attr($prefix . '[include_children]') . '" value="1"' . checked($a_inc, 1, false) . '> ';
            echo esc_html__('+ Children', 'woobooster');
            echo '</label>';

            // Order By.
            echo '<select name="' . esc_attr($prefix . '[action_orderby]') . '" class="wb-select wb-select--inline" title="' . esc_attr__('Order By', 'woobooster') . '">';
            $orderbys = array(
                'rand' => __('Random', 'woobooster'),
                'date' => __('Newest', 'woobooster'),
                'price' => __('Price (Low to High)', 'woobooster'),
                'price_desc' => __('Price (High to Low)', 'woobooster'),
                'bestselling' => __('Bestselling', 'woobooster'),
                'rating' => __('Rating', 'woobooster'),
            );
            foreach ($orderbys as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($a_orderby, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';

            // Limit.
            echo '<input type="number" name="' . esc_attr($prefix . '[action_limit]') . '" value="' . esc_attr($a_limit) . '" min="1" class="wb-input wb-input--sm wb-input--w70" title="' . esc_attr__('Limit', 'woobooster') . '">';

            // Remove Button.
            if ($index > 0 || count($actions) > 1) {
                echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-action" title="' . esc_attr__('Remove', 'woobooster') . '">&times;</button>';
            }

            echo '</div>'; // .wb-action-row

            // ── Specific Products Selector ──
            $sp_display = 'specific_products' === $a_source ? '' : 'display:none;';
            $sp_products = isset($action->action_products) ? $action->action_products : '';
            echo '<div class="wb-action-products-panel wb-sub-panel" style="' . esc_attr($sp_display) . '">';
            echo '<label class="wb-field__label">' . esc_html__('Select Products', 'woobooster') . '</label>';
            echo '<div class="wb-autocomplete wb-autocomplete--md wb-product-search">';
            echo '<input type="text" class="wb-input wb-product-search__input" placeholder="' . esc_attr__('Search products by name…', 'woobooster') . '" autocomplete="off">';
            echo '<input type="hidden" name="' . esc_attr($prefix . '[action_products]') . '" class="wb-product-search__ids" value="' . esc_attr($sp_products) . '">';
            echo '<div class="wb-autocomplete__dropdown"></div>';
            echo '<div class="wb-product-chips wb-chips"></div>';
            echo '</div></div>';

            // ── Coupon Selector ──
            $cp_display = 'apply_coupon' === $a_source ? '' : 'display:none;';
            $cp_id = isset($action->action_coupon_id) ? absint($action->action_coupon_id) : 0;
            $cp_label = '';
            if ($cp_id) {
                $cp_coupon = new WC_Coupon($cp_id);
                $cp_label = $cp_coupon->get_code();
            }
            $cp_message = isset($action->action_coupon_message) ? $action->action_coupon_message : '';
            echo '<div class="wb-action-coupon-panel wb-sub-panel" style="' . esc_attr($cp_display) . '">';
            echo '<p class="wb-field__desc wb-coupon-desc">' . esc_html__('Works with your existing WooCommerce coupons. Create coupons in Marketing > Coupons first.', 'woobooster') . '</p>';
            echo '<label class="wb-field__label">' . esc_html__('Select Coupon', 'woobooster') . '</label>';
            echo '<div class="wb-autocomplete wb-autocomplete--sm wb-coupon-search">';
            echo '<input type="text" class="wb-input wb-coupon-search__input" placeholder="' . esc_attr__('Search coupons…', 'woobooster') . '" value="' . esc_attr($cp_label) . '" autocomplete="off">';
            echo '<input type="hidden" name="' . esc_attr($prefix . '[action_coupon_id]') . '" class="wb-coupon-search__id" value="' . esc_attr($cp_id) . '">';
            echo '<div class="wb-autocomplete__dropdown"></div>';
            echo '</div>';
            echo '<div class="wb-field">';
            echo '<label class="wb-field__label">' . esc_html__('Custom Cart Message', 'woobooster') . '</label>';
            echo '<input type="text" name="' . esc_attr($prefix . '[action_coupon_message]') . '" class="wb-input wb-input--max-md" placeholder="' . esc_attr__('e.g. You got 15% off on Ammo products!', 'woobooster') . '" value="' . esc_attr($cp_message) . '">';
            echo '<p class="wb-field__desc">' . esc_html__('Leave empty for the default auto-apply message.', 'woobooster') . '</p>';
            echo '</div>';
            echo '</div>';

            // ── Exclusion Panel (collapsible) ──
            $ex_cats = isset($action->exclude_categories) ? $action->exclude_categories : '';
            $ex_prods = isset($action->exclude_products) ? $action->exclude_products : '';
            $ex_price_min = isset($action->exclude_price_min) ? $action->exclude_price_min : '';
            $ex_price_max = isset($action->exclude_price_max) ? $action->exclude_price_max : '';
            $has_exclusions = $ex_cats || $ex_prods || '' !== $ex_price_min || '' !== $ex_price_max;

            echo '<div class="wb-exclusion-panel wb-sub-panel">';
            echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-exclusions">';
            echo ($has_exclusions ? '&#9660;' : '&#9654;') . ' ' . esc_html__('Action Exclusions', 'woobooster');
            echo '</button>';

            $ex_body_display = $has_exclusions ? '' : 'display:none;';
            echo '<div class="wb-exclusion-body" style="' . esc_attr($ex_body_display) . '">';

            // Exclude Categories.
            echo '<div class="wb-field">';
            echo '<label class="wb-field__label">' . esc_html__('Exclude Categories', 'woobooster') . '</label>';
            echo '<div class="wb-autocomplete wb-autocomplete--md wb-exclude-cats-search">';
            echo '<input type="text" class="wb-input wb-exclude-cats__input" placeholder="' . esc_attr__('Search categories…', 'woobooster') . '" autocomplete="off">';
            echo '<input type="hidden" name="' . esc_attr($prefix . '[exclude_categories]') . '" class="wb-exclude-cats__ids" value="' . esc_attr($ex_cats) . '">';
            echo '<div class="wb-autocomplete__dropdown"></div>';
            echo '<div class="wb-exclude-cats-chips wb-chips"></div>';
            echo '</div></div>';

            // Exclude Products.
            echo '<div class="wb-field">';
            echo '<label class="wb-field__label">' . esc_html__('Exclude Products', 'woobooster') . '</label>';
            echo '<div class="wb-autocomplete wb-autocomplete--md wb-exclude-prods-search">';
            echo '<input type="text" class="wb-input wb-exclude-prods__input" placeholder="' . esc_attr__('Search products…', 'woobooster') . '" autocomplete="off">';
            echo '<input type="hidden" name="' . esc_attr($prefix . '[exclude_products]') . '" class="wb-exclude-prods__ids" value="' . esc_attr($ex_prods) . '">';
            echo '<div class="wb-autocomplete__dropdown"></div>';
            echo '<div class="wb-exclude-prods-chips wb-chips"></div>';
            echo '</div></div>';

            // Exclude Price Range.
            echo '<div class="wb-field">';
            echo '<label class="wb-field__label">' . esc_html__('Price Range Filter', 'woobooster') . '</label>';
            echo '<div class="wb-price-range">';
            echo '<input type="number" name="' . esc_attr($prefix . '[exclude_price_min]') . '" value="' . esc_attr($ex_price_min) . '" class="wb-input wb-input--sm wb-input--w100" placeholder="' . esc_attr__('Min $', 'woobooster') . '" step="0.01" min="0">';
            echo '<span>—</span>';
            echo '<input type="number" name="' . esc_attr($prefix . '[exclude_price_max]') . '" value="' . esc_attr($ex_price_max) . '" class="wb-input wb-input--sm wb-input--w100" placeholder="' . esc_attr__('Max $', 'woobooster') . '" step="0.01" min="0">';
            echo '<span class="wb-field__desc">' . esc_html__('Only include products in this price range', 'woobooster') . '</span>';
            echo '</div></div>';

            echo '</div>'; // .wb-exclusion-body
            echo '</div>'; // .wb-exclusion-panel
        }

        echo '</div>'; // #wb-action-repeater

        echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--sm" id="wb-add-action">';
        echo '+ ' . esc_html__('Add Action', 'woobooster');
        echo '</button>';

        // Global Exclude (applies to all).
        echo '<div class="wb-field wb-global-setting">';
        echo '<label class="wb-field__label">' . esc_html__('Exclude Out of Stock', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" name="exclude_outofstock" value="1"' . checked($exclude_outofstock, 1, false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '<p class="wb-field__desc">' . esc_html__('Override global setting for this rule.', 'woobooster') . '</p>';
        echo '</div></div>';

        echo '</div>'; // .wb-card__section

        // ── Save Bar ────────────────────────────────────────────────────────

        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">';
        echo $is_edit ? esc_html__('Update Rule', 'woobooster') : esc_html__('Create Rule', 'woobooster');
        echo '</button>';
        echo '<a href="' . esc_url($back_url) . '" class="wb-btn wb-btn--subtle">' . esc_html__('Cancel', 'woobooster') . '</a>';
        echo '</div>';

        echo '</form>';
        echo '</div>'; // .wb-card
    }

    /**
     * Handle form save.
     */
    private function handle_save()
    {
        if (!isset($_POST['woobooster_rule_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_key($_POST['woobooster_rule_nonce']), 'woobooster_save_rule')) {
            return;
        }

        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $rule_id = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;

        // Process Actions.
        $raw_actions = isset($_POST['actions']) && is_array($_POST['actions']) ? $_POST['actions'] : array();
        $clean_actions = array();

        foreach ($raw_actions as $action) {
            $clean_actions[] = array(
                'action_source' => isset($action['action_source']) ? sanitize_key($action['action_source']) : 'category',
                'action_value' => isset($action['action_value']) ? sanitize_text_field(wp_unslash($action['action_value'])) : '',
                'action_limit' => isset($action['action_limit']) ? absint($action['action_limit']) : 4,
                'action_orderby' => isset($action['action_orderby']) ? sanitize_key($action['action_orderby']) : 'rand',
                'include_children' => isset($action['include_children']) ? absint($action['include_children']) : 0,
                'action_products' => isset($action['action_products']) ? sanitize_text_field(wp_unslash($action['action_products'])) : '',
                'action_coupon_id' => isset($action['action_coupon_id']) && $action['action_coupon_id'] ? absint($action['action_coupon_id']) : '',
                'exclude_categories' => isset($action['exclude_categories']) ? sanitize_text_field(wp_unslash($action['exclude_categories'])) : '',
                'exclude_products' => isset($action['exclude_products']) ? sanitize_text_field(wp_unslash($action['exclude_products'])) : '',
                'exclude_price_min' => isset($action['exclude_price_min']) && '' !== $action['exclude_price_min'] ? floatval($action['exclude_price_min']) : '',
                'exclude_price_max' => isset($action['exclude_price_max']) && '' !== $action['exclude_price_max'] ? floatval($action['exclude_price_max']) : '',
                'action_coupon_message' => isset($action['action_coupon_message']) ? sanitize_text_field(wp_unslash($action['action_coupon_message'])) : '',
            );
        }

        // Fallback for legacy columns (use first action).
        $first_action = !empty($clean_actions) ? reset($clean_actions) : array(
            'action_source' => 'category',
            'action_value' => '',
            'action_limit' => 4,
            'action_orderby' => 'rand'
        );

        // Build rule data.
        $data = array(
            'name' => isset($_POST['rule_name']) ? sanitize_text_field(wp_unslash($_POST['rule_name'])) : '',
            'priority' => isset($_POST['rule_priority']) ? absint($_POST['rule_priority']) : 10,
            'status' => isset($_POST['rule_status']) ? 1 : 0,

            // Legacy columns population.
            'action_source' => $first_action['action_source'],
            'action_value' => $first_action['action_value'],
            'action_orderby' => $first_action['action_orderby'],
            'action_limit' => $first_action['action_limit'],

            'exclude_outofstock' => isset($_POST['exclude_outofstock']) ? 1 : 0,
            'action_logic' => isset($_POST['action_logic']) && 'and' === $_POST['action_logic'] ? 'and' : 'or',
        );

        // Keep legacy inline fields populated from the first condition for backward compatibility.
        $raw_conditions = isset($_POST['conditions']) && is_array($_POST['conditions']) ? $_POST['conditions'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $first_group = !empty($raw_conditions) ? reset($raw_conditions) : array();
        $first_cond = !empty($first_group) ? reset($first_group) : array();
        $data['condition_attribute'] = isset($first_cond['attribute']) ? sanitize_key($first_cond['attribute']) : '';
        $data['condition_operator'] = isset($first_cond['operator']) ? sanitize_key($first_cond['operator']) : 'equals';
        $data['condition_value'] = isset($first_cond['value']) ? sanitize_text_field(wp_unslash($first_cond['value'])) : '';
        $data['include_children'] = isset($first_cond['include_children']) ? 1 : 0;

        // Scheduling dates.
        $data['start_date'] = !empty($_POST['rule_start_date'])
            ? date('Y-m-d H:i:s', strtotime(sanitize_text_field(wp_unslash($_POST['rule_start_date']))))
            : null;
        $data['end_date'] = !empty($_POST['rule_end_date'])
            ? date('Y-m-d H:i:s', strtotime(sanitize_text_field(wp_unslash($_POST['rule_end_date']))))
            : null;

        if ($rule_id) {
            WooBooster_Rule::update($rule_id, $data);
        } else {
            $rule_id = WooBooster_Rule::create($data);
        }

        // Save multi-condition groups.
        $condition_groups = array();
        foreach ($raw_conditions as $g_idx => $group) {
            if (!is_array($group)) {
                continue;
            }
            $group_conditions = array();
            foreach ($group as $c_idx => $cond) {
                if (!is_array($cond) || empty($cond['attribute'])) {
                    continue;
                }
                $group_conditions[] = array(
                    'condition_attribute' => sanitize_key($cond['attribute']),
                    'condition_operator' => isset($cond['operator']) ? sanitize_key($cond['operator']) : 'equals',
                    'condition_value' => sanitize_text_field(wp_unslash($cond['value'] ?? '')),
                    'include_children' => isset($cond['include_children']) ? 1 : 0,
                    'min_quantity' => isset($cond['min_quantity']) ? max(1, absint($cond['min_quantity'])) : 1,
                    'exclude_categories' => isset($cond['exclude_categories']) ? sanitize_text_field(wp_unslash($cond['exclude_categories'])) : '',
                    'exclude_products' => isset($cond['exclude_products']) ? sanitize_text_field(wp_unslash($cond['exclude_products'])) : '',
                    'exclude_price_min' => isset($cond['exclude_price_min']) && '' !== $cond['exclude_price_min'] ? floatval($cond['exclude_price_min']) : '',
                    'exclude_price_max' => isset($cond['exclude_price_max']) && '' !== $cond['exclude_price_max'] ? floatval($cond['exclude_price_max']) : '',
                );
            }
            if (!empty($group_conditions)) {
                $condition_groups[absint($g_idx)] = $group_conditions;
            }
        }

        if (!empty($condition_groups)) {
            WooBooster_Rule::save_conditions($rule_id, $condition_groups);
        }

        // Save multi-actions.
        if (!empty($clean_actions)) {
            WooBooster_Rule::save_actions($rule_id, $clean_actions);
        }

        wp_safe_redirect(admin_url('admin.php?page=ffla-woobooster-rules&action=edit&rule_id=' . $rule_id . '&saved=1'));
        exit;
    }
}
