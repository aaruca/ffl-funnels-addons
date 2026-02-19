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
                $c_inc = is_object($cond) ? (int) $cond->include_children : 0;

                // Resolve label for existing values.
                $c_label = '';
                if ($c_val && $c_attr) {
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
                if ('product_cat' === $c_attr) {
                    $c_type = 'category';
                } elseif ('product_tag' === $c_attr) {
                    $c_type = 'tag';
                } elseif (!empty($c_attr)) {
                    $c_type = 'attribute';
                    $c_attr_taxonomy = $c_attr;
                }

                // Condition Type select.
                echo '<select class="wb-select wb-condition-type" style="width: auto; flex-shrink: 0;" required>';
                echo '<option value="">' . esc_html__('Type…', 'woobooster') . '</option>';
                echo '<option value="category"' . selected($c_type, 'category', false) . '>' . esc_html__('Category', 'woobooster') . '</option>';
                echo '<option value="tag"' . selected($c_type, 'tag', false) . '>' . esc_html__('Tag', 'woobooster') . '</option>';
                echo '<option value="attribute"' . selected($c_type, 'attribute', false) . '>' . esc_html__('Attribute', 'woobooster') . '</option>';
                echo '</select>';

                // Attribute Taxonomy select (shown only when type = attribute).
                $cond_attr_taxonomies = wc_get_attribute_taxonomies();
                $display_cond_attr = 'attribute' === $c_type ? '' : 'display:none;';
                echo '<select class="wb-select wb-condition-attr-taxonomy" style="width: auto; flex-shrink: 0; ' . esc_attr($display_cond_attr) . '">';
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

                // Hidden operator.
                echo '<input type="hidden" name="' . esc_attr($field_prefix . '[operator]') . '" value="equals">';

                // Value autocomplete.
                echo '<div class="wb-autocomplete wb-condition-value-wrap">';
                echo '<input type="text" class="wb-input wb-autocomplete__input wb-condition-value-display" placeholder="' . esc_attr__('Value…', 'woobooster') . '" value="' . esc_attr($c_label) . '" autocomplete="off">';
                echo '<input type="hidden" name="' . esc_attr($field_prefix . '[value]') . '" class="wb-condition-value-hidden" value="' . esc_attr($c_val) . '">';
                echo '<div class="wb-autocomplete__dropdown"></div>';
                echo '</div>';

                // Include children.
                echo '<label class="wb-checkbox wb-condition-children-label" style="display:none;">';
                echo '<input type="checkbox" name="' . esc_attr($field_prefix . '[include_children]') . '" value="1"' . checked($c_inc, 1, false) . '> ';
                echo esc_html__('+ Children', 'woobooster');
                echo '</label>';

                // Remove button.
                if ($cond_index > 0 || count($conditions) > 1) {
                    echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-condition" title="' . esc_attr__('Remove', 'woobooster') . '">&times;</button>';
                }

                echo '</div>'; // .wb-condition-row
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

        echo '<div class="wb-card__section">';
        echo '<h3>' . esc_html__('Actions', 'woobooster') . '</h3>';
        echo '<p class="wb-section-desc">' . esc_html__('Define one or more actions to execute when the condition matches. Results from all actions will be merged.', 'woobooster') . '</p>';

        $actions = $rule_id ? WooBooster_Rule::get_actions($rule_id) : array();
        if (empty($actions)) {
            $actions = array(
                (object) array(
                    'action_source' => 'category',
                    'action_value' => '',
                    'action_orderby' => 'rand',
                    'action_limit' => 4,
                )
            );
        }

        echo '<div id="wb-action-repeater">';

        foreach ($actions as $index => $action) {
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
            echo '<select name="' . esc_attr($prefix . '[action_source]') . '" class="wb-select wb-action-source" style="width: auto; flex-shrink: 0;">';
            echo '<option value="category"' . selected($a_source, 'category', false) . '>' . esc_html__('Category', 'woobooster') . '</option>';
            echo '<option value="tag"' . selected($a_source, 'tag', false) . '>' . esc_html__('Tag', 'woobooster') . '</option>';
            echo '<option value="attribute"' . selected($a_source, 'attribute', false) . '>' . esc_html__('Same Attribute', 'woobooster') . '</option>';
            echo '<option value="attribute_value"' . selected($a_source, 'attribute_value', false) . '>' . esc_html__('Attribute', 'woobooster') . '</option>';
            echo '<option value="copurchase"' . selected($a_source, 'copurchase', false) . '>' . esc_html__('Bought Together', 'woobooster') . '</option>';
            echo '<option value="trending"' . selected($a_source, 'trending', false) . '>' . esc_html__('Trending', 'woobooster') . '</option>';
            echo '<option value="recently_viewed"' . selected($a_source, 'recently_viewed', false) . '>' . esc_html__('Recently Viewed', 'woobooster') . '</option>';
            echo '<option value="similar"' . selected($a_source, 'similar', false) . '>' . esc_html__('Similar Products', 'woobooster') . '</option>';
            echo '</select>';

            // Attribute Taxonomy Selector (for attribute_value source).
            $attr_taxonomies = wc_get_attribute_taxonomies();
            $display_attr = 'attribute_value' === $a_source ? '' : 'display:none;';
            echo '<select class="wb-select wb-action-attr-taxonomy" style="width: auto; flex-shrink: 0; ' . esc_attr($display_attr) . '">';
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
            echo '<div class="wb-autocomplete wb-action-value-wrap" style="flex: 1; min-width: 200px;">';
            echo '<input type="text" class="wb-input wb-autocomplete__input wb-action-value-display" placeholder="' . esc_attr__('Value…', 'woobooster') . '" value="' . esc_attr($a_label) . '" autocomplete="off">';
            echo '<input type="hidden" name="' . esc_attr($prefix . '[action_value]') . '" class="wb-action-value-hidden" value="' . esc_attr($a_value) . '">';
            echo '<div class="wb-autocomplete__dropdown"></div>';
            echo '</div>';

            // Include Children Checkbox (hidden unless source=category).
            $display_inc = 'category' === $a_source ? '' : 'display:none;';
            echo '<label class="wb-checkbox wb-action-children-label" style="' . esc_attr($display_inc) . ' margin-left: 10px; align-self: center;">';
            echo '<input type="checkbox" name="' . esc_attr($prefix . '[include_children]') . '" value="1"' . checked($a_inc, 1, false) . '> ';
            echo esc_html__('+ Children', 'woobooster');
            echo '</label>';

            // Order By.
            echo '<select name="' . esc_attr($prefix . '[action_orderby]') . '" class="wb-select" style="width: auto; flex-shrink: 0;" title="' . esc_attr__('Order By', 'woobooster') . '">';
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
            echo '<input type="number" name="' . esc_attr($prefix . '[action_limit]') . '" value="' . esc_attr($a_limit) . '" min="1" class="wb-input wb-input--sm" style="width: 70px;" title="' . esc_attr__('Limit', 'woobooster') . '">';

            // Remove Button.
            if ($index > 0 || count($actions) > 1) {
                echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-action" title="' . esc_attr__('Remove', 'woobooster') . '">&times;</button>';
            }

            echo '</div>'; // .wb-action-row
        }

        echo '</div>'; // #wb-action-repeater

        echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--sm" id="wb-add-action">';
        echo '+ ' . esc_html__('Add Action', 'woobooster');
        echo '</button>';

        // Global Exclude (applies to all).
        echo '<div class="wb-field" style="margin-top: 24px; border-top: 1px solid #eee; padding-top: 24px;">';
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
        );

        // Keep legacy inline fields populated from the first condition for backward compatibility.
        $raw_conditions = isset($_POST['conditions']) && is_array($_POST['conditions']) ? $_POST['conditions'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $first_group = !empty($raw_conditions) ? reset($raw_conditions) : array();
        $first_cond = !empty($first_group) ? reset($first_group) : array();
        $data['condition_attribute'] = isset($first_cond['attribute']) ? sanitize_key($first_cond['attribute']) : '';
        $data['condition_operator'] = 'equals';
        $data['condition_value'] = isset($first_cond['value']) ? sanitize_text_field(wp_unslash($first_cond['value'])) : '';
        $data['include_children'] = isset($first_cond['include_children']) ? 1 : 0;

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
                    'condition_operator' => 'equals',
                    'condition_value' => sanitize_text_field(wp_unslash($cond['value'] ?? '')),
                    'include_children' => isset($cond['include_children']) ? 1 : 0,
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
