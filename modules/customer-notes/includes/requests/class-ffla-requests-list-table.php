<?php
/**
 * WooCommerce → Requests inbox table.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class FFLA_Requests_List_Table extends WP_List_Table
{
    /** @var string */
    public $view = 'open';

    /** @var array<int, string> */
    private $names = [];

    public function __construct()
    {
        parent::__construct(['singular' => 'request', 'plural' => 'requests', 'ajax' => false]);
        // phpcs:ignore WordPress.Security.NonceVerification
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'open';
        $this->view = in_array($view, ['open', 'awaiting', 'new', 'mine', 'overdue', 'closed', 'all'], true) ? $view : 'open';
    }

    public function get_columns(): array
    {
        return [
            'cb'       => '<input type="checkbox" />',
            'number'   => __('Request', 'ffl-funnels-addons'),
            'customer' => __('Customer', 'ffl-funnels-addons'),
            'order'    => __('Order', 'ffl-funnels-addons'),
            'status'   => __('Status', 'ffl-funnels-addons'),
            'reason'   => __('Reason', 'ffl-funnels-addons'),
            'assignee' => __('Assignee', 'ffl-funnels-addons'),
            'due'      => __('Due', 'ffl-funnels-addons'),
            'updated'  => __('Last activity', 'ffl-funnels-addons'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [
            'number'  => ['number', false],
            'status'  => ['status', false],
            'due'     => ['due_at', false],
            'updated' => ['updated_at', true],
        ];
    }

    protected function get_bulk_actions(): array
    {
        return [
            'assign_me' => __('Assign to me', 'ffl-funnels-addons'),
            'unassign'  => __('Unassign', 'ffl-funnels-addons'),
            'in_review' => __('Mark in review', 'ffl-funnels-addons'),
        ];
    }

    protected function get_views(): array
    {
        $counts = FFLA_Requests::counts();
        $labels = [
            'open'     => __('Open', 'ffl-funnels-addons'),
            'awaiting' => __('Needs reply', 'ffl-funnels-addons'),
            'new'      => __('New', 'ffl-funnels-addons'),
            'mine'     => __('Assigned to me', 'ffl-funnels-addons'),
            'overdue'  => __('Overdue', 'ffl-funnels-addons'),
            'closed'   => __('Closed', 'ffl-funnels-addons'),
            'all'      => __('All', 'ffl-funnels-addons'),
        ];
        $views = [];
        foreach ($labels as $view => $label) {
            $url = admin_url('admin.php?page=' . FFLA_Requests_Admin::SLUG . '&view=' . $view);
            $views[$view] = '<a href="' . esc_url($url) . '"' . ($this->view === $view ? ' class="current" aria-current="page"' : '') . '>'
                . esc_html($label) . ' <span class="count">(' . esc_html(number_format_i18n($counts[$view] ?? 0)) . ')</span></a>';
        }
        return $views;
    }

    protected function extra_tablenav($which): void
    {
        if ('top' !== $which) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        // phpcs:enable
        echo '<div class="alignleft actions">';
        echo '<label class="screen-reader-text" for="ffla-req-type">' . esc_html__('Filter by type', 'ffl-funnels-addons') . '</label>';
        echo '<select name="type" id="ffla-req-type"><option value="">' . esc_html__('All types', 'ffl-funnels-addons') . '</option>';
        foreach (['issue', 'return'] as $value) {
            echo '<option value="' . esc_attr($value) . '"' . selected($type, $value, false) . '>' . esc_html(FFLA_Requests::type_label($value)) . '</option>';
        }
        echo '</select>';
        echo '<label class="screen-reader-text" for="ffla-req-status">' . esc_html__('Filter by status', 'ffl-funnels-addons') . '</label>';
        echo '<select name="status" id="ffla-req-status"><option value="">' . esc_html__('All statuses', 'ffl-funnels-addons') . '</option>';
        foreach (FFLA_Requests::statuses() as $value => $def) {
            echo '<option value="' . esc_attr($value) . '"' . selected($status, $value, false) . '>' . esc_html($def[0]) . '</option>';
        }
        echo '</select>';
        submit_button(__('Filter', 'ffl-funnels-addons'), '', 'filter_action', false);
        echo '</div>';
    }

    public function prepare_items(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification
        $args = [
            'view'     => $this->view,
            'type'     => isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '',
            'status'   => isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '',
            'search'   => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'order_id' => isset($_GET['order_id']) ? absint($_GET['order_id']) : 0,
            'orderby'  => isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'updated_at',
            'order'    => isset($_GET['order']) ? sanitize_key(wp_unslash($_GET['order'])) : 'desc',
            'page'     => $this->get_pagenum(),
            'per_page' => 20,
        ];
        // phpcs:enable
        $result = FFLA_Requests::query($args);
        $this->items = $result['rows'];
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'number'];
        $this->set_pagination_args(['total_items' => $result['total'], 'per_page' => 20, 'total_pages' => (int) ceil($result['total'] / 20)]);
    }

    public function no_items(): void
    {
        esc_html_e('No requests here.', 'ffl-funnels-addons');
    }

    protected function column_cb($item): string
    {
        return '<label class="screen-reader-text" for="ffla-req-' . (int) $item->id . '">' . esc_html(sprintf(
            /* translators: %s: request number */
            __('Select %s', 'ffl-funnels-addons'),
            $item->number
        )) . '</label><input type="checkbox" id="ffla-req-' . (int) $item->id . '" name="request_ids[]" value="' . (int) $item->id . '" />';
    }

    protected function column_number($item): string
    {
        $url = admin_url('admin.php?page=' . FFLA_Requests_Admin::SLUG . '&request=' . (int) $item->id);
        $out = '<strong><a class="row-title" href="' . esc_url($url) . '">' . esc_html($item->number) . '</a></strong>'
            . ' <span class="ffla-req-type ffla-req-type--' . esc_attr($item->type) . '">' . esc_html(FFLA_Requests::type_label($item->type)) . '</span>';
        if ('normal' !== $item->priority && isset(FFLA_Requests::priorities()[$item->priority])) {
            $out .= ' <span class="ffla-req-priority ffla-req-priority--' . esc_attr($item->priority) . '">' . esc_html(FFLA_Requests::priorities()[$item->priority]) . '</span>';
        }
        if ((int) $item->has_firearm) {
            $out .= ' <span class="ffla-req-firearm">' . esc_html__('Firearm', 'ffl-funnels-addons') . '</span>';
        }
        return $out . $this->row_actions([
            'view'  => '<a href="' . esc_url($url) . '">' . esc_html__('Open', 'ffl-funnels-addons') . '</a>',
            'order' => '<a href="' . esc_url(FFLA_Requests_Admin::order_url((int) $item->order_id)) . '">' . esc_html__('Edit order', 'ffl-funnels-addons') . '</a>',
        ]);
    }

    protected function column_customer($item): string
    {
        return esc_html($item->customer_name ?: '—') . '<br><span class="description">' . esc_html($item->customer_email) . '</span>';
    }

    protected function column_order($item): string
    {
        return '<a href="' . esc_url(FFLA_Requests_Admin::order_url((int) $item->order_id)) . '">#' . esc_html($item->order_number) . '</a>';
    }

    protected function column_status($item): string
    {
        $out = FFLA_Requests_Admin::badge($item);
        if (FFLA_Requests::is_open($item->status) && 'staff' === $item->awaiting) {
            $out .= '<br><span class="ffla-req-needs">' . esc_html__('Needs reply', 'ffl-funnels-addons') . '</span>';
        } elseif (!FFLA_Requests::is_open($item->status) && $item->resolution) {
            $out .= '<br><span class="description">' . esc_html(FFLA_Requests::resolutions()[$item->resolution] ?? $item->resolution) . '</span>';
        }
        if ('approved' === $item->status && '' !== (string) $item->return_tracking) {
            $out .= '<br><span class="ffla-req-shipped">' . esc_html__('Shipped back', 'ffl-funnels-addons') . '</span>';
        }
        if ((int) $item->rating) {
            $out .= '<br><span class="ffla-req-stars" title="' . esc_attr((int) $item->rating . '/5') . '">' . esc_html(str_repeat('★', (int) $item->rating) . str_repeat('☆', 5 - (int) $item->rating)) . '</span>';
        }
        return $out;
    }

    protected function column_reason($item): string
    {
        $reasons = FFLA_Requests::reasons($item->type);
        $items = FFLA_Requests::items($item);
        $units = array_sum(array_map(static function ($l) { return (int) $l['qty']; }, $items));
        return esc_html($reasons[$item->reason] ?? $item->reason)
            . ($units ? '<br><span class="description">' . esc_html(sprintf(
                /* translators: %d: number of units */
                _n('%d unit', '%d units', $units, 'ffl-funnels-addons'),
                $units
            )) . '</span>' : '');
    }

    protected function column_assignee($item): string
    {
        $id = (int) $item->assignee;
        if (!$id) {
            return '<span class="description">—</span>';
        }
        if (!isset($this->names[$id])) {
            $user = get_user_by('id', $id);
            $this->names[$id] = $user ? $user->display_name : '#' . $id;
        }
        return esc_html($this->names[$id]);
    }

    protected function column_due($item): string
    {
        if (!$item->due_at) {
            return '<span class="description">—</span>';
        }
        $overdue = FFLA_Requests::is_open($item->status) && strtotime($item->due_at . ' UTC') < time();
        return '<span class="' . ($overdue ? 'ffla-req-overdue' : '') . '">' . esc_html(FFLA_Requests::local_time($item->due_at, get_option('date_format'))) . '</span>';
    }

    protected function column_updated($item): string
    {
        $ts = strtotime($item->updated_at . ' UTC');
        return '<span title="' . esc_attr(FFLA_Requests::local_time($item->updated_at)) . '">' . esc_html(sprintf(
            /* translators: %s: human time difference */
            __('%s ago', 'ffl-funnels-addons'),
            human_time_diff($ts, time())
        )) . '</span>';
    }
}
