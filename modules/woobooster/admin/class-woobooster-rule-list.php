<?php
/**
 * WooBooster Rule List Table.
 *
 * Extends WP_List_Table for displaying rules in the admin.
 *
 * @package WooBooster
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WooBooster_Rule_List extends WP_List_Table
{

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'rule',
            'plural' => 'rules',
            'ajax' => false,
        ));
    }

    /**
     * Define table columns.
     *
     * @return array
     */
    public function get_columns()
    {
        return array(
            'cb' => '<input type="checkbox">',
            'name' => __('Name', 'woobooster'),
            'priority' => __('Priority', 'woobooster'),
            'condition' => __('Condition', 'woobooster'),
            'action' => __('Action', 'woobooster'),
            'status' => __('Status', 'woobooster'),
            'actions' => __('Actions', 'woobooster'),
        );
    }

    /**
     * Sortable columns.
     *
     * @return array
     */
    protected function get_sortable_columns()
    {
        return array(
            'name' => array('name', false),
            'priority' => array('priority', true),
            'status' => array('status', false),
        );
    }

    /**
     * Prepare items for display.
     */
    public function prepare_items()
    {
        $per_page = 20;
        $current_page = $this->get_pagenum();

        // Handle bulk actions.
        $this->process_bulk_action();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'priority';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'ASC';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $total_items = WooBooster_Rule::count(array(
            'search' => $search,
        ));

        $this->items = WooBooster_Rule::get_all(array(
            'orderby' => $orderby,
            'order' => $order,
            'search' => $search,
            'limit' => $per_page,
            'offset' => ($current_page - 1) * $per_page,
        ));

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ));

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );
    }

    /**
     * Checkbox column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_cb($item)
    {
        return '<input type="checkbox" name="rule_ids[]" value="' . esc_attr($item->id) . '">';
    }

    /**
     * Name column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_name($item)
    {
        $edit_url = admin_url('admin.php?page=ffla-woobooster-rules&action=edit&rule_id=' . $item->id);
        return '<a href="' . esc_url($edit_url) . '" class="wb-link--strong">' . esc_html($item->name) . '</a>';
    }

    /**
     * Priority column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_priority($item)
    {
        return '<span class="wb-badge wb-badge--neutral">' . esc_html($item->priority) . '</span>';
    }

    /**
     * Condition column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_condition($item)
    {
        $groups = WooBooster_Rule::get_conditions($item->id);
        if (empty($groups)) {
            return '<span class="wb-text--muted">—</span>';
        }

        $attr_labels = array(
            'product_cat' => __('Category', 'woobooster'),
            'product_tag' => __('Tag', 'woobooster'),
            'specific_product' => __('Product', 'woobooster'),
        );

        $first_group = reset($groups);
        $first_cond = reset($first_group);

        $attr = $first_cond->condition_attribute;
        $attr_label = isset($attr_labels[$attr]) ? $attr_labels[$attr] : $attr;
        $operator = isset($first_cond->condition_operator) ? $first_cond->condition_operator : 'equals';
        $op_label = ('not_equals' === $operator) ? __('is not', 'woobooster') : __('is', 'woobooster');

        // Resolve value to human name.
        $value = $first_cond->condition_value;
        if ('specific_product' === $attr) {
            $ids = array_filter(array_map('absint', explode(',', $value)));
            $value = count($ids) . ' ' . _n('product', 'products', count($ids), 'woobooster');
        } else {
            $term = get_term_by('slug', $value, $attr);
            if ($term && !is_wp_error($term)) {
                $value = $term->name;
            }
        }

        $html = esc_html($attr_label) . ' <em>' . esc_html($op_label) . '</em> <code>' . esc_html($value) . '</code>';

        $total = 0;
        foreach ($groups as $g) {
            $total += count($g);
        }
        if ($total > 1) {
            $html .= ' <span class="wb-badge wb-badge--neutral">+' . ($total - 1) . '</span>';
        }

        return $html;
    }

    /**
     * Action column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_action($item)
    {
        $source_labels = array(
            'category' => __('Category', 'woobooster'),
            'tag' => __('Tag', 'woobooster'),
            'attribute' => __('Same Attribute', 'woobooster'),
            'attribute_value' => __('Attribute', 'woobooster'),
            'copurchase' => __('Bought Together', 'woobooster'),
            'trending' => __('Trending', 'woobooster'),
            'recently_viewed' => __('Recently Viewed', 'woobooster'),
            'similar' => __('Similar Products', 'woobooster'),
            'specific_products' => __('Specific Products', 'woobooster'),
            'apply_coupon' => __('Apply Coupon', 'woobooster'),
        );

        $action_groups = WooBooster_Rule::get_actions($item->id);
        if (empty($action_groups)) {
            return '<span class="wb-text--muted">—</span>';
        }

        // Flatten grouped actions to get a flat list of action objects.
        $actions = array();
        foreach ($action_groups as $group) {
            if (is_array($group)) {
                foreach ($group as $action) {
                    $actions[] = $action;
                }
            } else {
                $actions[] = $group;
            }
        }
        if (empty($actions)) {
            return '<span class="wb-text--muted">—</span>';
        }

        $first = reset($actions);
        $source = isset($source_labels[$first->action_source]) ? $source_labels[$first->action_source] : $first->action_source;

        // Build human-readable value based on source type.
        $value = '';
        switch ($first->action_source) {
            case 'attribute':
                // "Same Attribute" uses the product's own terms — no static value.
                break;

            case 'attribute_value':
                if (false !== strpos($first->action_value, ':')) {
                    $parts = explode(':', $first->action_value, 2);
                    $term = get_term_by('slug', $parts[1], $parts[0]);
                    $value = $term && !is_wp_error($term) ? $term->name : $parts[1];
                }
                break;

            case 'category':
            case 'tag':
                $taxonomy = ('category' === $first->action_source) ? 'product_cat' : 'product_tag';
                $term = get_term_by('slug', $first->action_value, $taxonomy);
                $value = $term && !is_wp_error($term) ? $term->name : $first->action_value;
                break;

            case 'specific_products':
                if (!empty($first->action_products)) {
                    $ids = array_filter(explode(',', $first->action_products));
                    $value = count($ids) . ' ' . _n('product', 'products', count($ids), 'woobooster');
                }
                break;

            case 'apply_coupon':
                if (!empty($first->action_coupon_id)) {
                    $coupon = new WC_Coupon(absint($first->action_coupon_id));
                    $value = strtoupper($coupon->get_code());
                }
                break;

            default:
                // Smart sources (copurchase, trending, recently_viewed, similar) have no static value.
                break;
        }

        $html = esc_html($source);
        if ($value) {
            $html .= ': <code>' . esc_html($value) . '</code>';
        }

        // Show limit/orderby only for product-returning actions.
        $no_limit_sources = array('apply_coupon');
        if (!in_array($first->action_source, $no_limit_sources, true)) {
            $html .= ' <span class="wb-text--muted">(' . esc_html($first->action_orderby) . ', '
                . esc_html($first->action_limit) . ')</span>';
        }

        if (count($actions) > 1) {
            $html .= ' <span class="wb-badge wb-badge--neutral">+' . (count($actions) - 1) . '</span>';
        }

        return $html;
    }

    /**
     * Status column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_status($item)
    {
        $status_html = '';
        if ($item->status) {
            $status_html = '<span class="wb-status wb-status--active">' . esc_html__('Active', 'woobooster') . '</span>';
        } else {
            $status_html = '<span class="wb-status wb-status--inactive">' . esc_html__('Inactive', 'woobooster') . '</span>';
        }

        // Add schedule info
        $now = current_time('mysql');
        $schedule = '';
        if (!empty($item->start_date) || !empty($item->end_date)) {
            $schedule .= '<div style="font-size: 11px; margin-top: 4px; color: var(--wb-color-neutral-text);">';
            if (!empty($item->start_date) && $now < $item->start_date) {
                // Future
                $schedule .= '🕒 ' . sprintf(esc_html__('Starts: %s', 'woobooster'), date_i18n(get_option('date_format'), strtotime($item->start_date)));
            } elseif (!empty($item->end_date) && $now > $item->end_date) {
                // Expired
                $schedule .= '⚠️ ' . esc_html__('Expired', 'woobooster');
            } else {
                // Active timeframe
                if (!empty($item->end_date)) {
                    $schedule .= '⏳ ' . sprintf(esc_html__('Ends: %s', 'woobooster'), date_i18n(get_option('date_format'), strtotime($item->end_date)));
                } else {
                    $schedule .= '🕒 ' . sprintf(esc_html__('Started: %s', 'woobooster'), date_i18n(get_option('date_format'), strtotime($item->start_date)));
                }
            }
            $schedule .= '</div>';
        }

        return $status_html . $schedule;
    }

    /**
     * Actions column.
     *
     * @param object $item Rule object.
     * @return string
     */
    protected function column_actions($item)
    {
        $edit_url = admin_url('admin.php?page=ffla-woobooster-rules&action=edit&rule_id=' . $item->id);
        $duplicate_url = wp_nonce_url(
            admin_url('admin.php?page=ffla-woobooster-rules&action=duplicate&rule_id=' . $item->id),
            'woobooster_duplicate_rule_' . $item->id
        );
        $delete_url = wp_nonce_url(
            admin_url('admin.php?page=ffla-woobooster-rules&action=delete&rule_id=' . $item->id),
            'woobooster_delete_rule_' . $item->id
        );

        $toggle_label = $item->status
            ? __('Deactivate', 'woobooster')
            : __('Activate', 'woobooster');

        $html = '<div class="wb-row-actions">';
        $html .= '<a href="' . esc_url($edit_url) . '" class="wb-btn wb-btn--subtle wb-btn--xs" title="' . esc_attr__('Edit', 'woobooster') . '">';
        $html .= WooBooster_Icons::get('edit');
        $html .= '</a>';
        $html .= '<a href="' . esc_url($duplicate_url) . '" class="wb-btn wb-btn--subtle wb-btn--xs" title="' . esc_attr__('Duplicate', 'woobooster') . '">';
        $html .= WooBooster_Icons::get('duplicate');
        $html .= '</a>';
        $html .= '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-rule" data-rule-id="' . esc_attr($item->id) . '" title="' . esc_attr($toggle_label) . '">';
        $html .= WooBooster_Icons::get('toggle');
        $html .= '</button>';
        $html .= '<a href="' . esc_url($delete_url) . '" class="wb-btn wb-btn--subtle wb-btn--xs wb-btn--danger wb-delete-rule" title="' . esc_attr__('Delete', 'woobooster') . '">';
        $html .= WooBooster_Icons::get('delete');
        $html .= '</a>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Bulk action options.
     *
     * @return array
     */
    protected function get_bulk_actions()
    {
        return array(
            'bulk_delete' => __('Delete', 'woobooster'),
            'bulk_activate' => __('Activate', 'woobooster'),
            'bulk_deactivate' => __('Deactivate', 'woobooster'),
        );
    }

    /**
     * Process bulk actions.
     */
    private function process_bulk_action()
    {
        // Single delete.
        if ('delete' === $this->current_action()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $rule_id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
            if ($rule_id && check_admin_referer('woobooster_delete_rule_' . $rule_id)) {
                WooBooster_Rule::delete($rule_id);
                wp_safe_redirect(admin_url('admin.php?page=ffla-woobooster-rules'));
                exit;
            }
        }

        // Single duplicate.
        if ('duplicate' === $this->current_action()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $rule_id = isset($_GET['rule_id']) ? absint($_GET['rule_id']) : 0;
            if ($rule_id && check_admin_referer('woobooster_duplicate_rule_' . $rule_id)) {
                $new_id = WooBooster_Rule::duplicate($rule_id);
                $redirect = $new_id
                    ? admin_url('admin.php?page=ffla-woobooster-rules&action=edit&rule_id=' . $new_id)
                    : admin_url('admin.php?page=ffla-woobooster-rules');
                wp_safe_redirect($redirect);
                exit;
            }
        }

        // Bulk actions.
        $action = $this->current_action();
        if (in_array($action, array('bulk_delete', 'bulk_activate', 'bulk_deactivate'), true)) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'bulk-rules')) {
                return;
            }

            $rule_ids = isset($_POST['rule_ids']) ? array_map('absint', $_POST['rule_ids']) : array();

            foreach ($rule_ids as $rid) {
                switch ($action) {
                    case 'bulk_delete':
                        WooBooster_Rule::delete($rid);
                        break;
                    case 'bulk_activate':
                        WooBooster_Rule::update($rid, array('status' => 1));
                        break;
                    case 'bulk_deactivate':
                        WooBooster_Rule::update($rid, array('status' => 0));
                        break;
                }
            }

            wp_safe_redirect(admin_url('admin.php?page=ffla-woobooster-rules'));
            exit;
        }
    }

    /**
     * Empty table message.
     */
    public function no_items()
    {
        echo '<div class="wb-empty-state">';
        echo WooBooster_Icons::get('rules'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<p>' . esc_html__('No rules created yet.', 'woobooster') . '</p>';
        $add_url = admin_url('admin.php?page=ffla-woobooster-rules&action=add');
        echo '<a href="' . esc_url($add_url) . '" class="wb-btn wb-btn--primary">' . esc_html__('Create Your First Rule', 'woobooster') . '</a>';
        echo '</div>';
    }
}
