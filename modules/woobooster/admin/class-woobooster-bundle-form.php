<?php
/**
 * WooBooster Bundle Form.
 *
 * Handles rendering and processing of the Add/Edit bundle form.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Bundle_Form
{

    /**
     * Render the bundle form.
     */
    public function render()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $bundle_id = isset($_GET['bundle_id']) ? absint($_GET['bundle_id']) : 0;
        $bundle    = $bundle_id ? WooBooster_Bundle::get($bundle_id) : null;
        $is_edit   = !empty($bundle);

        $this->handle_save();

        $title = $is_edit
            ? __('Edit Bundle', 'woobooster')
            : __('Add New Bundle', 'woobooster');

        $name           = $bundle ? $bundle->name : '';
        $priority       = $bundle ? $bundle->priority : 10;
        $status         = $bundle ? $bundle->status : 1;
        $discount_type  = $bundle ? $bundle->discount_type : 'none';
        $discount_value = $bundle ? $bundle->discount_value : '';

        $back_url = admin_url('admin.php?page=ffla-woobooster-bundles');

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header">';
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<a href="' . esc_url($back_url) . '" class="wb-btn wb-btn--subtle wb-btn--sm">';
        echo WooBooster_Icons::get('chevron-left'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo esc_html__('Back to Bundles', 'woobooster');
        echo '</a>';
        echo '</div>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['saved']) && '1' === $_GET['saved']) {
            echo '<div class="wb-message wb-message--success">';
            echo WooBooster_Icons::get('check'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<span>' . esc_html__('Bundle saved successfully.', 'woobooster') . '</span>';
            echo '</div>';
        }

        echo '<form method="post" action="" class="wb-form">';
        wp_nonce_field('woobooster_save_bundle', 'woobooster_bundle_nonce');

        if ($bundle_id) {
            echo '<input type="hidden" name="bundle_id" value="' . esc_attr($bundle_id) . '">';
        }

        // ── Basic Settings ──────────────────────────────────────────────
        echo '<div class="wb-card__section">';
        echo '<h3>' . esc_html__('Basic Settings', 'woobooster') . '</h3>';

        // Name.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-bundle-name">' . esc_html__('Bundle Name', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<input type="text" id="wb-bundle-name" name="bundle_name" value="' . esc_attr($name) . '" class="wb-input" required>';
        echo '</div></div>';

        // Priority.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="wb-bundle-priority">' . esc_html__('Priority', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<input type="number" id="wb-bundle-priority" name="bundle_priority" value="' . esc_attr($priority) . '" min="1" max="999" class="wb-input wb-input--sm">';
        echo '<p class="wb-field__desc">' . esc_html__('Lower number = higher priority. Used when multiple bundles match the same product.', 'woobooster') . '</p>';
        echo '</div></div>';

        // Status.
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Status', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" name="bundle_status" value="1"' . checked($status, 1, false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '</div></div>';

        // Schedule.
        $start_date = $bundle && isset($bundle->start_date) ? $bundle->start_date : '';
        $end_date   = $bundle && isset($bundle->end_date) ? $bundle->end_date : '';

        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Schedule', 'woobooster') . '</label>';
        echo '<div class="wb-field__control wb-schedule-row">';
        echo '<label class="wb-schedule-label">' . esc_html__('From', 'woobooster');
        echo '<input type="datetime-local" name="bundle_start_date" value="' . esc_attr($start_date ? date('Y-m-d\TH:i', strtotime($start_date)) : '') . '" class="wb-input wb-input--sm wb-input--auto">';
        echo '</label>';
        echo '<label class="wb-schedule-label">' . esc_html__('Until', 'woobooster');
        echo '<input type="datetime-local" name="bundle_end_date" value="' . esc_attr($end_date ? date('Y-m-d\TH:i', strtotime($end_date)) : '') . '" class="wb-input wb-input--sm wb-input--auto">';
        echo '</label>';
        echo '</div>';
        echo '<p class="wb-field__desc">' . esc_html__('Optional. Leave empty to keep the bundle always active.', 'woobooster') . '</p>';
        echo '</div>';

        echo '</div>'; // .wb-card__section

        // ── Discount ────────────────────────────────────────────────────
        echo '<div class="wb-card__section">';
        echo '<h3>' . esc_html__('Discount', 'woobooster') . '</h3>';

        echo '<div class="wb-field">';
        echo '<label class="wb-field__label">' . esc_html__('Discount Type', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<select name="bundle_discount_type" class="wb-select" id="wb-discount-type">';
        echo '<option value="none"' . selected($discount_type, 'none', false) . '>' . esc_html__('No Discount', 'woobooster') . '</option>';
        echo '<option value="percentage"' . selected($discount_type, 'percentage', false) . '>' . esc_html__('Percentage (%)', 'woobooster') . '</option>';
        echo '<option value="fixed"' . selected($discount_type, 'fixed', false) . '>' . esc_html__('Fixed Amount ($)', 'woobooster') . '</option>';
        echo '</select>';
        echo '</div></div>';

        $discount_display = 'none' === $discount_type ? 'display:none;' : '';
        echo '<div class="wb-field wb-discount-value-row" style="' . esc_attr($discount_display) . '">';
        echo '<label class="wb-field__label">' . esc_html__('Discount Value', 'woobooster') . '</label>';
        echo '<div class="wb-field__control">';
        echo '<input type="number" name="bundle_discount_value" value="' . esc_attr($discount_value) . '" min="0" step="0.01" class="wb-input wb-input--sm">';
        echo '<p class="wb-field__desc">' . esc_html__('Applied as a cart discount when the bundle is added.', 'woobooster') . '</p>';
        echo '</div></div>';

        echo '</div>'; // .wb-card__section

        // ── Bundle Items (Static) ───────────────────────────────────────
        echo '<div class="wb-card__section" id="wb-bundle-items-section">';
        echo '<h3>' . esc_html__('Bundle Items (Manual)', 'woobooster') . '</h3>';
        echo '<p class="wb-section-desc">' . esc_html__('Add specific products to this bundle. These are always included.', 'woobooster') . '</p>';

        $items = $bundle_id ? WooBooster_Bundle::get_items($bundle_id) : array();

        echo '<div id="wb-bundle-items">';
        echo '<div class="wb-autocomplete wb-autocomplete--md wb-bundle-product-search">';
        echo '<input type="text" class="wb-input wb-bundle-product-search__input" placeholder="' . esc_attr__('Search products by name…', 'woobooster') . '" autocomplete="off">';
        echo '<div class="wb-autocomplete__dropdown"></div>';
        echo '</div>';

        echo '<div id="wb-bundle-items-list" class="wb-sortable-list">';
        foreach ($items as $idx => $item) {
            $product = wc_get_product($item->product_id);
            $pname   = $product ? $product->get_name() : '#' . $item->product_id;
            $pprice  = $product ? $product->get_price_html() : '';

            echo '<div class="wb-bundle-item" data-product-id="' . esc_attr($item->product_id) . '">';
            echo '<span class="wb-bundle-item__drag">&#9776;</span>';
            echo '<span class="wb-bundle-item__name">' . esc_html($pname) . '</span>';
            echo '<span class="wb-bundle-item__price">' . wp_kses_post($pprice) . '</span>';
            echo '<label class="wb-checkbox wb-bundle-item__optional">';
            echo '<input type="checkbox" name="bundle_items[' . esc_attr($idx) . '][is_optional]" value="1"' . checked($item->is_optional, 1, false) . '>';
            echo esc_html__('Optional', 'woobooster');
            echo '</label>';
            echo '<input type="hidden" name="bundle_items[' . esc_attr($idx) . '][product_id]" value="' . esc_attr($item->product_id) . '">';
            echo '<input type="hidden" name="bundle_items[' . esc_attr($idx) . '][sort_order]" value="' . esc_attr($idx) . '" class="wb-bundle-item__sort">';
            echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-bundle-item" title="' . esc_attr__('Remove', 'woobooster') . '">&times;</button>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .wb-card__section

        // ── Bundle Items (Dynamic/AI) ───────────────────────────────────
        echo '<div class="wb-card__section" id="wb-bundle-actions-section">';
        echo '<h3>' . esc_html__('Bundle Items (Dynamic)', 'woobooster') . '</h3>';
        echo '<p class="wb-section-desc">' . esc_html__('Add dynamic product sources (AI, trending, category, etc). Groups are combined with OR, actions within a group with AND.', 'woobooster') . '</p>';

        $action_groups = $bundle_id ? WooBooster_Bundle::get_actions($bundle_id) : array();

        echo '<div id="wb-bundle-action-groups">';

        if (!empty($action_groups)) {
            $a_group_index = 0;
            foreach ($action_groups as $group_id => $actions) {
                if ($a_group_index > 0) {
                    echo '<div class="wb-or-divider">' . esc_html__('— OR —', 'woobooster') . '</div>';
                }
                $this->render_action_group($a_group_index, $actions);
                $a_group_index++;
            }
        }

        echo '</div>'; // #wb-bundle-action-groups

        echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--sm" id="wb-add-bundle-action-group">';
        echo '+ ' . esc_html__('Add Dynamic Source Group', 'woobooster');
        echo '</button>';

        echo '</div>'; // .wb-card__section

        // ── Conditions ──────────────────────────────────────────────────
        echo '<div class="wb-card__section" id="wb-bundle-conditions-section">';
        echo '<h3>' . esc_html__('Conditions', 'woobooster') . '</h3>';
        echo '<p class="wb-section-desc">' . esc_html__('Define which products this bundle appears on. Leave empty for manual-only bundles (use "Specific Bundle" in Bricks). Groups = OR, within group = AND. Use “Entire store” to match every product.', 'woobooster') . '</p>';

        $condition_groups = $bundle_id ? WooBooster_Bundle::get_conditions($bundle_id) : array();

        echo '<div id="wb-bundle-condition-groups">';

        if (!empty($condition_groups)) {
            $group_index = 0;
            foreach ($condition_groups as $group_id => $conditions) {
                if ($group_index > 0) {
                    echo '<div class="wb-or-divider">' . esc_html__('— OR —', 'woobooster') . '</div>';
                }
                $this->render_condition_group($group_index, $conditions);
                $group_index++;
            }
        }

        echo '</div>'; // #wb-bundle-condition-groups

        echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--sm" id="wb-add-bundle-condition-group">';
        echo '+ ' . esc_html__('Add Condition Group', 'woobooster');
        echo '</button>';

        echo '</div>'; // .wb-card__section

        // ── Save Bar ────────────────────────────────────────────────────
        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">';
        echo $is_edit ? esc_html__('Update Bundle', 'woobooster') : esc_html__('Create Bundle', 'woobooster');
        echo '</button>';
        echo '<a href="' . esc_url($back_url) . '" class="wb-btn wb-btn--subtle">' . esc_html__('Cancel', 'woobooster') . '</a>';
        echo '</div>';

        echo '</form>';
        echo '</div>'; // .wb-card
    }

    /**
     * Render a single condition group.
     */
    private function render_condition_group($group_index, $conditions)
    {
        echo '<div class="wb-condition-group" data-group="' . esc_attr($group_index) . '">';
        echo '<div class="wb-condition-group__header">';
        echo '<span class="wb-condition-group__label">' . esc_html__('Condition Group', 'woobooster') . ' ' . ($group_index + 1) . '</span>';
        if ($group_index > 0) {
            echo '<button type="button" class="wb-btn wb-btn--danger wb-btn--xs wb-remove-bundle-cond-group" title="' . esc_attr__('Remove Group', 'woobooster') . '">&times;</button>';
        }
        echo '</div>';

        $cond_index = 0;
        foreach ($conditions as $cond) {
            $c_attr = is_object($cond) ? $cond->condition_attribute : '';
            $c_val  = is_object($cond) ? $cond->condition_value : '';
            $c_op   = is_object($cond) && isset($cond->condition_operator) ? $cond->condition_operator : 'equals';
            $c_inc  = is_object($cond) ? (int) $cond->include_children : 0;

            $c_label = '';
            if ('specific_product' !== $c_attr && $c_val && $c_attr) {
                $term = get_term_by('slug', $c_val, $c_attr);
                if ($term && !is_wp_error($term)) {
                    $c_label = $term->name;
                }
            }

            $field_prefix = 'bundle_conditions[' . $group_index . '][' . $cond_index . ']';

            $c_type          = '';
            $c_attr_taxonomy = '';
            if ('specific_product' === $c_attr) {
                $c_type = 'specific_product';
            } elseif ('__store_all' === $c_attr && '1' === (string) $c_val) {
                $c_type = 'store_all';
            } elseif ('product_cat' === $c_attr) {
                $c_type = 'category';
            } elseif ('product_tag' === $c_attr) {
                $c_type = 'tag';
            } elseif (!empty($c_attr)) {
                $c_type          = 'attribute';
                $c_attr_taxonomy = $c_attr;
            }

            if ('' === $c_type && '' === $c_attr && '' === (string) $c_val) {
                $c_type = 'store_all';
                $c_attr = '__store_all';
                $c_val  = '1';
            }

            $row_extra_class = ('store_all' === $c_type) ? ' wb-condition-row--entire-store' : '';

            echo '<div class="wb-condition-row' . esc_attr($row_extra_class) . '" data-condition="' . esc_attr($cond_index) . '">';

            // Condition Type.
            echo '<select class="wb-select wb-select--inline wb-condition-type" required>';
            echo '<option value="store_all"' . selected($c_type, 'store_all', false) . '>' . esc_html__('Entire store (all products)', 'woobooster') . '</option>';
            echo '<option value="category"' . selected($c_type, 'category', false) . '>' . esc_html__('Category', 'woobooster') . '</option>';
            echo '<option value="tag"' . selected($c_type, 'tag', false) . '>' . esc_html__('Tag', 'woobooster') . '</option>';
            echo '<option value="attribute"' . selected($c_type, 'attribute', false) . '>' . esc_html__('Attribute', 'woobooster') . '</option>';
            echo '<option value="specific_product"' . selected($c_type, 'specific_product', false) . '>' . esc_html__('Specific Product', 'woobooster') . '</option>';
            echo '</select>';

            // Attribute Taxonomy.
            $cond_attr_taxonomies = wc_get_attribute_taxonomies();
            $display_cond_attr    = 'attribute' === $c_type ? '' : 'display:none;';
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

            echo '<input type="hidden" name="' . esc_attr($field_prefix . '[attribute]') . '" class="wb-condition-attr" value="' . esc_attr($c_attr) . '">';

            // Operator.
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

            echo '<span class="wb-condition-store-all-hint">' . esc_html__('Applies to every product. Use exclusions if this bundle should not appear everywhere.', 'woobooster') . '</span>';

            // Include children.
            echo '<label class="wb-checkbox wb-condition-children-label" style="display:none;">';
            echo '<input type="checkbox" name="' . esc_attr($field_prefix . '[include_children]') . '" value="1"' . checked($c_inc, 1, false) . '> ';
            echo esc_html__('+ Children', 'woobooster');
            echo '</label>';

            // Remove.
            if ($cond_index > 0 || count($conditions) > 1) {
                echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-condition" title="' . esc_attr__('Remove', 'woobooster') . '">&times;</button>';
            }

            echo '</div>'; // .wb-condition-row
            $cond_index++;
        }

        echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--sm wb-add-bundle-condition">';
        echo '+ ' . esc_html__('AND Condition', 'woobooster');
        echo '</button>';

        echo '</div>'; // .wb-condition-group
    }

    /**
     * Render a single action group (dynamic item sources).
     */
    private function render_action_group($a_group_index, $actions)
    {
        echo '<div class="wb-action-group" data-group="' . esc_attr($a_group_index) . '">';
        echo '<div class="wb-action-group__header">';
        echo '<span class="wb-action-group__label">' . esc_html__('Source Group', 'woobooster') . ' ' . ($a_group_index + 1) . '</span>';
        if ($a_group_index > 0) {
            echo '<button type="button" class="wb-btn wb-btn--danger wb-btn--xs wb-remove-bundle-action-group" title="' . esc_attr__('Remove Group', 'woobooster') . '">&times;</button>';
        }
        echo '</div>';

        $a_index = 0;
        foreach ($actions as $action) {
            $a_source  = $action->action_source;
            $a_value   = $action->action_value;
            $a_orderby = $action->action_orderby;
            $a_limit   = $action->action_limit;
            $a_inc     = isset($action->include_children) ? (int) $action->include_children : 0;

            $a_label          = '';
            $selected_attr_tax = '';

            if ($a_value) {
                if ('attribute_value' === $a_source && false !== strpos($a_value, ':')) {
                    $parts             = explode(':', $a_value, 2);
                    $selected_attr_tax = $parts[0];
                    $term              = get_term_by('slug', $parts[1], $parts[0]);
                    $a_label           = $term && !is_wp_error($term) ? $term->name : '';
                } else {
                    $action_tax = 'category' === $a_source ? 'product_cat' : ('tag' === $a_source ? 'product_tag' : '');
                    if ($action_tax) {
                        $term    = get_term_by('slug', $a_value, $action_tax);
                        $a_label = $term && !is_wp_error($term) ? $term->name : '';
                    }
                }
            }

            $prefix = 'bundle_action_groups[' . $a_group_index . '][actions][' . $a_index . ']';

            if ($a_index > 0) {
                echo '<div class="wb-action-logic-divider"><span class="wb-and-divider">' . esc_html__('AND', 'woobooster') . '</span></div>';
            }

            echo '<div class="wb-action-row" data-index="' . esc_attr($a_index) . '">';

            // Source Type (no apply_coupon for bundles).
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
            echo '</select>';

            // Attribute Taxonomy Selector.
            $attr_taxonomies = wc_get_attribute_taxonomies();
            $display_attr    = 'attribute_value' === $a_source ? '' : 'display:none;';
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

            // Include Children.
            $display_inc = 'category' === $a_source ? '' : 'display:none;';
            echo '<label class="wb-checkbox wb-action-children-label" style="' . esc_attr($display_inc) . '">';
            echo '<input type="checkbox" name="' . esc_attr($prefix . '[include_children]') . '" value="1"' . checked($a_inc, 1, false) . '> ';
            echo esc_html__('+ Children', 'woobooster');
            echo '</label>';

            // Order By.
            echo '<select name="' . esc_attr($prefix . '[action_orderby]') . '" class="wb-select wb-select--inline" title="' . esc_attr__('Order By', 'woobooster') . '">';
            $orderbys = array(
                'rand'        => __('Random', 'woobooster'),
                'date'        => __('Newest', 'woobooster'),
                'price'       => __('Price (Low to High)', 'woobooster'),
                'price_desc'  => __('Price (High to Low)', 'woobooster'),
                'bestselling' => __('Bestselling', 'woobooster'),
                'rating'      => __('Rating', 'woobooster'),
            );
            foreach ($orderbys as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($a_orderby, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';

            // Limit.
            echo '<input type="number" name="' . esc_attr($prefix . '[action_limit]') . '" value="' . esc_attr($a_limit) . '" min="1" class="wb-input wb-input--sm wb-input--w70" title="' . esc_attr__('Limit', 'woobooster') . '">';

            // Remove.
            if ($a_index > 0 || count($actions) > 1) {
                echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-remove-action" title="' . esc_attr__('Remove', 'woobooster') . '">&times;</button>';
            }

            echo '</div>'; // .wb-action-row

            // Specific Products panel.
            $sp_display  = 'specific_products' === $a_source ? '' : 'display:none;';
            $sp_products = isset($action->action_products) ? $action->action_products : '';
            echo '<div class="wb-action-products-panel wb-sub-panel" style="' . esc_attr($sp_display) . '">';
            echo '<label class="wb-field__label">' . esc_html__('Select Products', 'woobooster') . '</label>';
            echo '<div class="wb-autocomplete wb-autocomplete--md wb-product-search">';
            echo '<input type="text" class="wb-input wb-product-search__input" placeholder="' . esc_attr__('Search products by name…', 'woobooster') . '" autocomplete="off">';
            echo '<input type="hidden" name="' . esc_attr($prefix . '[action_products]') . '" class="wb-product-search__ids" value="' . esc_attr($sp_products) . '">';
            echo '<div class="wb-autocomplete__dropdown"></div>';
            echo '<div class="wb-product-chips wb-chips"></div>';
            echo '</div></div>';

            // Exclusion Panel.
            $ex_cats      = isset($action->exclude_categories) ? $action->exclude_categories : '';
            $ex_prods     = isset($action->exclude_products) ? $action->exclude_products : '';
            $ex_price_min = isset($action->exclude_price_min) ? $action->exclude_price_min : '';
            $ex_price_max = isset($action->exclude_price_max) ? $action->exclude_price_max : '';
            $has_ex       = $ex_cats || $ex_prods || '' !== $ex_price_min || '' !== $ex_price_max;

            echo '<div class="wb-exclusion-panel wb-sub-panel">';
            echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-exclusions">';
            echo ($has_ex ? '&#9660;' : '&#9654;') . ' ' . esc_html__('Exclusions', 'woobooster');
            echo '</button>';

            $ex_body_display = $has_ex ? '' : 'display:none;';
            echo '<div class="wb-exclusion-body" style="' . esc_attr($ex_body_display) . '">';

            echo '<div class="wb-field">';
            echo '<label class="wb-field__label">' . esc_html__('Exclude Categories', 'woobooster') . '</label>';
            echo '<div class="wb-autocomplete wb-autocomplete--md wb-exclude-cats-search">';
            echo '<input type="text" class="wb-input wb-exclude-cats__input" placeholder="' . esc_attr__('Search categories…', 'woobooster') . '" autocomplete="off">';
            echo '<input type="hidden" name="' . esc_attr($prefix . '[exclude_categories]') . '" class="wb-exclude-cats__ids" value="' . esc_attr($ex_cats) . '">';
            echo '<div class="wb-autocomplete__dropdown"></div>';
            echo '<div class="wb-exclude-cats-chips wb-chips"></div>';
            echo '</div></div>';

            echo '<div class="wb-field">';
            echo '<label class="wb-field__label">' . esc_html__('Exclude Products', 'woobooster') . '</label>';
            echo '<div class="wb-autocomplete wb-autocomplete--md wb-exclude-prods-search">';
            echo '<input type="text" class="wb-input wb-exclude-prods__input" placeholder="' . esc_attr__('Search products…', 'woobooster') . '" autocomplete="off">';
            echo '<input type="hidden" name="' . esc_attr($prefix . '[exclude_products]') . '" class="wb-exclude-prods__ids" value="' . esc_attr($ex_prods) . '">';
            echo '<div class="wb-autocomplete__dropdown"></div>';
            echo '<div class="wb-exclude-prods-chips wb-chips"></div>';
            echo '</div></div>';

            echo '<div class="wb-field">';
            echo '<label class="wb-field__label">' . esc_html__('Price Range Filter', 'woobooster') . '</label>';
            echo '<div class="wb-price-range">';
            echo '<input type="number" name="' . esc_attr($prefix . '[exclude_price_min]') . '" value="' . esc_attr($ex_price_min) . '" class="wb-input wb-input--sm wb-input--w100" placeholder="' . esc_attr__('Min $', 'woobooster') . '" step="0.01" min="0">';
            echo '<span>—</span>';
            echo '<input type="number" name="' . esc_attr($prefix . '[exclude_price_max]') . '" value="' . esc_attr($ex_price_max) . '" class="wb-input wb-input--sm wb-input--w100" placeholder="' . esc_attr__('Max $', 'woobooster') . '" step="0.01" min="0">';
            echo '</div></div>';

            echo '</div>'; // .wb-exclusion-body
            echo '</div>'; // .wb-exclusion-panel

            $a_index++;
        }

        echo '<button type="button" class="wb-btn wb-btn--subtle wb-btn--sm wb-add-bundle-action">';
        echo '+ ' . esc_html__('AND Source', 'woobooster');
        echo '</button>';

        echo '</div>'; // .wb-action-group
    }

    /**
     * Handle form save.
     */
    private function handle_save()
    {
        if (!isset($_POST['woobooster_bundle_nonce'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_key($_POST['woobooster_bundle_nonce']), 'woobooster_save_bundle')) {
            wp_die(esc_html__('Security check failed.', 'ffl-funnels-addons'));
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        $bundle_id = isset($_POST['bundle_id']) ? absint($_POST['bundle_id']) : 0;

        // Bundle data.
        $data = array(
            'name'           => isset($_POST['bundle_name']) ? sanitize_text_field(wp_unslash($_POST['bundle_name'])) : '',
            'priority'       => isset($_POST['bundle_priority']) ? absint($_POST['bundle_priority']) : 10,
            'status'         => isset($_POST['bundle_status']) ? 1 : 0,
            'discount_type'  => isset($_POST['bundle_discount_type']) ? sanitize_key($_POST['bundle_discount_type']) : 'none',
            'discount_value' => isset($_POST['bundle_discount_value']) ? floatval($_POST['bundle_discount_value']) : 0,
            'start_date'     => !empty($_POST['bundle_start_date'])
                ? date('Y-m-d H:i:s', strtotime(sanitize_text_field(wp_unslash($_POST['bundle_start_date']))))
                : null,
            'end_date'       => !empty($_POST['bundle_end_date'])
                ? date('Y-m-d H:i:s', strtotime(sanitize_text_field(wp_unslash($_POST['bundle_end_date']))))
                : null,
        );

        if ($bundle_id) {
            WooBooster_Bundle::update($bundle_id, $data);
        } else {
            $bundle_id = WooBooster_Bundle::create($data);
        }

        // Save static items.
        $raw_items  = isset($_POST['bundle_items']) && is_array($_POST['bundle_items']) ? $_POST['bundle_items'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $clean_items = array();
        foreach ($raw_items as $idx => $item) {
            if (empty($item['product_id'])) {
                continue;
            }
            $clean_items[] = array(
                'product_id'  => absint($item['product_id']),
                'sort_order'  => isset($item['sort_order']) ? absint($item['sort_order']) : $idx,
                'is_optional' => isset($item['is_optional']) ? 1 : 0,
            );
        }
        WooBooster_Bundle::save_items($bundle_id, $clean_items);

        // Save dynamic action groups.
        $raw_action_groups   = isset($_POST['bundle_action_groups']) && is_array($_POST['bundle_action_groups']) ? $_POST['bundle_action_groups'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $clean_action_groups = array();

        foreach ($raw_action_groups as $group_data) {
            $raw_actions   = isset($group_data['actions']) && is_array($group_data['actions']) ? $group_data['actions'] : array();
            $clean_actions = array();

            foreach ($raw_actions as $action) {
                $clean_actions[] = array(
                    'action_source'    => isset($action['action_source']) ? sanitize_key($action['action_source']) : 'category',
                    'action_value'     => isset($action['action_value']) ? sanitize_text_field(wp_unslash($action['action_value'])) : '',
                    'action_limit'     => isset($action['action_limit']) ? absint($action['action_limit']) : 4,
                    'action_orderby'   => isset($action['action_orderby']) ? sanitize_key($action['action_orderby']) : 'rand',
                    'include_children' => isset($action['include_children']) ? absint($action['include_children']) : 0,
                    'action_products'    => isset($action['action_products']) ? sanitize_text_field(wp_unslash($action['action_products'])) : '',
                    'exclude_categories' => isset($action['exclude_categories']) ? sanitize_text_field(wp_unslash($action['exclude_categories'])) : '',
                    'exclude_products'   => isset($action['exclude_products']) ? sanitize_text_field(wp_unslash($action['exclude_products'])) : '',
                    'exclude_price_min'  => isset($action['exclude_price_min']) && '' !== $action['exclude_price_min'] ? floatval($action['exclude_price_min']) : '',
                    'exclude_price_max'  => isset($action['exclude_price_max']) && '' !== $action['exclude_price_max'] ? floatval($action['exclude_price_max']) : '',
                );
            }

            if (!empty($clean_actions)) {
                $clean_action_groups[] = $clean_actions;
            }
        }

        if (!empty($clean_action_groups)) {
            WooBooster_Bundle::save_actions($bundle_id, $clean_action_groups);
        } else {
            WooBooster_Bundle::save_actions($bundle_id, array());
        }

        // Save conditions.
        $raw_conditions    = isset($_POST['bundle_conditions']) && is_array($_POST['bundle_conditions']) ? $_POST['bundle_conditions'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $condition_groups  = array();

        foreach ($raw_conditions as $g_idx => $group) {
            if (!is_array($group)) {
                continue;
            }
            $group_conditions = array();
            foreach ($group as $cond) {
                if (!is_array($cond) || empty($cond['attribute'])) {
                    continue;
                }
                $group_conditions[] = array(
                    'condition_attribute' => sanitize_key($cond['attribute']),
                    'condition_operator'  => isset($cond['operator']) ? sanitize_key($cond['operator']) : 'equals',
                    'condition_value'     => sanitize_text_field(wp_unslash($cond['value'] ?? '')),
                    'include_children'    => isset($cond['include_children']) ? 1 : 0,
                );
            }
            if (!empty($group_conditions)) {
                $condition_groups[absint($g_idx)] = $group_conditions;
            }
        }

        WooBooster_Bundle::save_conditions($bundle_id, $condition_groups);

        wp_safe_redirect(admin_url('admin.php?page=ffla-woobooster-bundles&action=edit&bundle_id=' . $bundle_id . '&saved=1'));
        exit;
    }
}
