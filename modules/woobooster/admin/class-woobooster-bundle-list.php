<?php
/**
 * WooBooster Bundle List Table.
 *
 * Extends WP_List_Table for displaying bundles in the admin.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class WooBooster_Bundle_List extends WP_List_Table
{

    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'bundle',
            'plural'   => 'bundles',
            'ajax'     => false,
        ));
    }

    public function get_columns()
    {
        return array(
            'cb'       => '<input type="checkbox">',
            'name'     => __('Name', 'ffl-funnels-addons'),
            'items'    => __('Items', 'ffl-funnels-addons'),
            'discount' => __('Discount', 'ffl-funnels-addons'),
            'priority' => __('Priority', 'ffl-funnels-addons'),
            'status'   => __('Status', 'ffl-funnels-addons'),
            'actions'  => __('Actions', 'ffl-funnels-addons'),
        );
    }

    protected function get_sortable_columns()
    {
        return array(
            'name'     => array('name', false),
            'priority' => array('priority', true),
            'status'   => array('status', false),
        );
    }

    public function prepare_items()
    {
        $per_page     = 20;
        $current_page = $this->get_pagenum();

        $this->process_bulk_action();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'priority';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'ASC';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $total_items = WooBooster_Bundle::count(array('search' => $search));

        $this->items = WooBooster_Bundle::get_all(array(
            'orderby' => $orderby,
            'order'   => $order,
            'search'  => $search,
            'limit'   => $per_page,
            'offset'  => ($current_page - 1) * $per_page,
        ));

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ));

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );
    }

    protected function column_cb($item)
    {
        return '<input type="checkbox" name="bundle_ids[]" value="' . esc_attr($item->id) . '">';
    }

    protected function column_name($item)
    {
        $edit_url = admin_url('admin.php?page=ffla-woobooster-bundles&action=edit&bundle_id=' . $item->id);
        return '<a href="' . esc_url($edit_url) . '" class="wb-link--strong">' . esc_html($item->name) . '</a>';
    }

    protected function column_items($item)
    {
        $static_items  = WooBooster_Bundle::get_items($item->id);
        $action_groups = WooBooster_Bundle::get_actions($item->id);

        $parts = array();
        if (!empty($static_items)) {
            $count   = count($static_items);
            $parts[] = $count . ' ' . _n('product', 'products', $count, 'ffl-funnels-addons');
        }

        if (!empty($action_groups)) {
            $action_count = 0;
            foreach ($action_groups as $group) {
                $action_count += count($group);
            }
            $parts[] = $action_count . ' ' . _n('dynamic source', 'dynamic sources', $action_count, 'ffl-funnels-addons');
        }

        return !empty($parts)
            ? implode(' + ', $parts)
            : '<span class="wb-text--muted">' . esc_html__('None', 'ffl-funnels-addons') . '</span>';
    }

    protected function column_discount($item)
    {
        if ('none' === $item->discount_type || empty($item->discount_value)) {
            return '<span class="wb-text--muted">—</span>';
        }

        if ('percentage' === $item->discount_type) {
            return '<span class="wb-badge wb-badge--success">' . esc_html($item->discount_value) . '%</span>';
        }

        return '<span class="wb-badge wb-badge--success">$' . esc_html(number_format((float) $item->discount_value, 2)) . '</span>';
    }

    protected function column_priority($item)
    {
        return '<span class="wb-badge wb-badge--neutral">' . esc_html($item->priority) . '</span>';
    }

    protected function column_status($item)
    {
        $status_html = $item->status
            ? '<span class="wb-status wb-status--active">' . esc_html__('Active', 'ffl-funnels-addons') . '</span>'
            : '<span class="wb-status wb-status--inactive">' . esc_html__('Inactive', 'ffl-funnels-addons') . '</span>';

        $now      = current_time('mysql', true);
        $schedule = '';
        if (!empty($item->start_date) || !empty($item->end_date)) {
            $schedule .= '<div style="font-size: 11px; margin-top: 4px; color: var(--wb-color-neutral-text);">';
            if (!empty($item->start_date) && $now < $item->start_date) {
                $schedule .= sprintf(esc_html__('Starts: %s', 'ffl-funnels-addons'), date_i18n(get_option('date_format'), strtotime($item->start_date)));
            } elseif (!empty($item->end_date) && $now > $item->end_date) {
                $schedule .= esc_html__('Expired', 'ffl-funnels-addons');
            } elseif (!empty($item->end_date)) {
                $schedule .= sprintf(esc_html__('Ends: %s', 'ffl-funnels-addons'), date_i18n(get_option('date_format'), strtotime($item->end_date)));
            }
            $schedule .= '</div>';
        }

        return $status_html . $schedule;
    }

    protected function column_actions($item)
    {
        $edit_url = admin_url('admin.php?page=ffla-woobooster-bundles&action=edit&bundle_id=' . $item->id);
        $duplicate_url = wp_nonce_url(
            admin_url('admin.php?page=ffla-woobooster-bundles&action=duplicate&bundle_id=' . $item->id),
            'woobooster_duplicate_bundle_' . $item->id
        );
        $delete_url = wp_nonce_url(
            admin_url('admin.php?page=ffla-woobooster-bundles&action=delete&bundle_id=' . $item->id),
            'woobooster_delete_bundle_' . $item->id
        );

        $toggle_label = $item->status
            ? __('Deactivate', 'ffl-funnels-addons')
            : __('Activate', 'ffl-funnels-addons');

        $html = '<div class="wb-row-actions">';
        $html .= '<a href="' . esc_url($edit_url) . '" class="wb-btn wb-btn--subtle wb-btn--xs" title="' . esc_attr__('Edit', 'ffl-funnels-addons') . '">';
        $html .= WooBooster_Icons::get('edit');
        $html .= '</a>';
        $html .= '<a href="' . esc_url($duplicate_url) . '" class="wb-btn wb-btn--subtle wb-btn--xs" title="' . esc_attr__('Duplicate', 'ffl-funnels-addons') . '">';
        $html .= WooBooster_Icons::get('duplicate');
        $html .= '</a>';
        $html .= '<button type="button" class="wb-btn wb-btn--subtle wb-btn--xs wb-toggle-bundle" data-bundle-id="' . esc_attr($item->id) . '" title="' . esc_attr($toggle_label) . '">';
        $html .= WooBooster_Icons::get('toggle');
        $html .= '</button>';
        $html .= '<a href="' . esc_url($delete_url) . '" class="wb-btn wb-btn--subtle wb-btn--xs wb-btn--danger wb-delete-bundle" title="' . esc_attr__('Delete', 'ffl-funnels-addons') . '">';
        $html .= WooBooster_Icons::get('delete');
        $html .= '</a>';
        $html .= '</div>';

        return $html;
    }

    protected function get_bulk_actions()
    {
        return array(
            'bulk_delete'     => __('Delete', 'ffl-funnels-addons'),
            'bulk_activate'   => __('Activate', 'ffl-funnels-addons'),
            'bulk_deactivate' => __('Deactivate', 'ffl-funnels-addons'),
        );
    }

    private function process_bulk_action()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if ('delete' === $this->current_action()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $bundle_id = isset($_GET['bundle_id']) ? absint($_GET['bundle_id']) : 0;
            if ($bundle_id && check_admin_referer('woobooster_delete_bundle_' . $bundle_id)) {
                WooBooster_Bundle::delete($bundle_id);
                wp_safe_redirect(admin_url('admin.php?page=ffla-woobooster-bundles'));
                exit;
            }
        }

        if ('duplicate' === $this->current_action()) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $bundle_id = isset($_GET['bundle_id']) ? absint($_GET['bundle_id']) : 0;
            if ($bundle_id && check_admin_referer('woobooster_duplicate_bundle_' . $bundle_id)) {
                $new_id = WooBooster_Bundle::duplicate($bundle_id);
                $redirect = $new_id
                    ? admin_url('admin.php?page=ffla-woobooster-bundles&action=edit&bundle_id=' . $new_id)
                    : admin_url('admin.php?page=ffla-woobooster-bundles');
                wp_safe_redirect($redirect);
                exit;
            }
        }

        $action = $this->current_action();
        if (in_array($action, array('bulk_delete', 'bulk_activate', 'bulk_deactivate'), true)) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'bulk-bundles')) {
                return;
            }

            $bundle_ids = isset($_POST['bundle_ids']) ? array_map('absint', $_POST['bundle_ids']) : array();

            foreach ($bundle_ids as $bid) {
                switch ($action) {
                    case 'bulk_delete':
                        WooBooster_Bundle::delete($bid);
                        break;
                    case 'bulk_activate':
                        WooBooster_Bundle::update($bid, array('status' => 1));
                        break;
                    case 'bulk_deactivate':
                        WooBooster_Bundle::update($bid, array('status' => 0));
                        break;
                }
            }

            wp_safe_redirect(admin_url('admin.php?page=ffla-woobooster-bundles'));
            exit;
        }
    }

    public function no_items()
    {
        echo '<div class="wb-empty-state">';
        echo WooBooster_Icons::get('rules'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<p>' . esc_html__('No bundles created yet.', 'ffl-funnels-addons') . '</p>';
        $add_url = admin_url('admin.php?page=ffla-woobooster-bundles&action=add');
        echo '<a href="' . esc_url($add_url) . '" class="wb-btn wb-btn--primary">' . esc_html__('Create Your First Bundle', 'ffl-funnels-addons') . '</a>';
        echo '</div>';
    }
}
