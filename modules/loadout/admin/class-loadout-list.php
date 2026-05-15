<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Loadout_List extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'loadout',
            'plural'   => 'loadouts',
            'ajax'     => false,
        ]);
    }

    public function get_columns()
    {
        return [
            'cb'      => '<input type="checkbox">',
            'name'    => __('Name', 'ffl-funnels-addons'),
            'anchor'  => __('Anchor Product', 'ffl-funnels-addons'),
            'tiers'   => __('Tiers', 'ffl-funnels-addons'),
            'status'  => __('Status', 'ffl-funnels-addons'),
            'actions' => __('Actions', 'ffl-funnels-addons'),
        ];
    }

    protected function get_sortable_columns()
    {
        return [
            'name'   => ['name', false],
            'status' => ['status', false],
        ];
    }

    public function prepare_items()
    {
        $per_page = 20;
        $current_page = $this->get_pagenum();

        $this->process_bulk_action();

        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'name';
        $order = isset($_GET['order']) ? sanitize_key($_GET['order']) : 'ASC';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $total_items = Loadout::count(['search' => $search]);

        $this->items = Loadout::get_all([
            'orderby' => $orderby,
            'order'   => $order,
            'search'  => $search,
            'limit'   => $per_page,
            'offset'  => ($current_page - 1) * $per_page,
        ]);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    protected function column_cb($item)
    {
        return '<input type="checkbox" name="loadout_ids[]" value="' . esc_attr($item->get_id()) . '">';
    }

    protected function column_name($item)
    {
        $edit_url = admin_url('admin.php?page=ffla-loadouts&action=edit&loadout_id=' . $item->get_id());
        return '<a href="' . esc_url($edit_url) . '"><strong>' . esc_html($item->get_name()) . '</strong></a>';
    }

    protected function column_anchor($item)
    {
        $anchor_id = $item->get_anchor_product_id();
        if (!$anchor_id) {
            return '<span style="color:#999;">—</span>';
        }
        $product = wc_get_product($anchor_id);
        if (!$product) {
            return '<span style="color:#c00;">' . esc_html__('Missing product', 'ffl-funnels-addons') . '</span>';
        }
        return esc_html($product->get_name()) . ' <span style="color:#999;">(#' . esc_html($anchor_id) . ')</span>';
    }

    protected function column_tiers($item)
    {
        $tiers = Loadout_Tier::get_by_loadout($item->get_id());
        $count = count($tiers);
        if (!$count) {
            return '<span style="color:#999;">' . esc_html__('No tiers', 'ffl-funnels-addons') . '</span>';
        }
        return sprintf(
            _n('%d tier', '%d tiers', $count, 'ffl-funnels-addons'),
            $count
        );
    }

    protected function column_status($item)
    {
        $is_active = $item->get_status();
        $label = $is_active ? __('Active', 'ffl-funnels-addons') : __('Inactive', 'ffl-funnels-addons');
        $color = $is_active ? '#46b450' : '#999';
        return '<span style="color:' . esc_attr($color) . ';font-weight:600;">' . esc_html($label) . '</span>';
    }

    protected function column_actions($item)
    {
        $edit_url = admin_url('admin.php?page=ffla-loadouts&action=edit&loadout_id=' . $item->get_id());
        $delete_url = wp_nonce_url(
            admin_url('admin.php?page=ffla-loadouts&action=delete&loadout_id=' . $item->get_id()),
            'loadout_delete_' . $item->get_id()
        );

        $html = '<a href="' . esc_url($edit_url) . '" class="button button-small">' . esc_html__('Edit', 'ffl-funnels-addons') . '</a> ';
        $html .= '<a href="' . esc_url($delete_url) . '" class="button button-small loadout-delete" style="color:#c00;" onclick="return confirm(\'' . esc_js(__('Delete this loadout? This cannot be undone.', 'ffl-funnels-addons')) . '\');">' . esc_html__('Delete', 'ffl-funnels-addons') . '</a>';

        return $html;
    }

    protected function get_bulk_actions()
    {
        return [
            'bulk_delete'     => __('Delete', 'ffl-funnels-addons'),
            'bulk_activate'   => __('Activate', 'ffl-funnels-addons'),
            'bulk_deactivate' => __('Deactivate', 'ffl-funnels-addons'),
        ];
    }

    private function process_bulk_action()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if ('delete' === $this->current_action()) {
            $loadout_id = isset($_GET['loadout_id']) ? absint($_GET['loadout_id']) : 0;
            if ($loadout_id && check_admin_referer('loadout_delete_' . $loadout_id)) {
                Loadout::delete($loadout_id);
                wp_safe_redirect(admin_url('admin.php?page=ffla-loadouts&deleted=1'));
                exit;
            }
        }

        $action = $this->current_action();
        if (in_array($action, ['bulk_delete', 'bulk_activate', 'bulk_deactivate'], true)) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce']), 'bulk-loadouts')) {
                return;
            }

            $loadout_ids = isset($_POST['loadout_ids']) ? array_map('absint', $_POST['loadout_ids']) : [];

            foreach ($loadout_ids as $lid) {
                $loadout = Loadout::get($lid);
                if (!$loadout) {
                    continue;
                }
                switch ($action) {
                    case 'bulk_delete':
                        Loadout::delete($lid);
                        break;
                    case 'bulk_activate':
                        $loadout->set_status(1);
                        $loadout->save();
                        break;
                    case 'bulk_deactivate':
                        $loadout->set_status(0);
                        $loadout->save();
                        break;
                }
            }

            wp_safe_redirect(admin_url('admin.php?page=ffla-loadouts'));
            exit;
        }
    }

    public function no_items()
    {
        echo '<div style="text-align:center;padding:40px;">';
        echo '<p style="font-size:16px;color:#666;">' . esc_html__('No loadouts created yet.', 'ffl-funnels-addons') . '</p>';
        $add_url = admin_url('admin.php?page=ffla-loadouts&action=add');
        echo '<a href="' . esc_url($add_url) . '" class="button button-primary">' . esc_html__('Create Your First Loadout', 'ffl-funnels-addons') . '</a>';
        echo '</div>';
    }
}
