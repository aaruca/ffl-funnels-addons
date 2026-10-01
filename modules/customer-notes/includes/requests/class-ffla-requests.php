<?php
/**
 * Customer requests (issues and returns) — data model and workflow.
 *
 * A request belongs to one WooCommerce order and has its own number
 * (`{order number}-{n}`), type (issue / return), status, assignee, timeline of
 * public and private events, optional files and — once closed — a resolution
 * outcome with a note for the customer.
 *
 * Storage: three custom tables (requests, events, files) so the store gets a
 * real inbox across orders without scanning order meta. Customers reach a
 * request through a per-request access key (derived from a random secret and
 * the site's auth salt, so a database copy alone cannot open requests) or, when
 * signed in, as the order owner.
 *
 * Nothing here changes the WooCommerce order status, refunds money or creates
 * shipments; staff do those in WooCommerce and record the outcome here.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests
{
    const DB_VERSION = '2';
    const DB_OPTION = 'ffla_requests_db_version';

    /** Per order: at most this many requests open at once, and in total. */
    const MAX_OPEN_PER_ORDER = 5;
    const MAX_PER_ORDER = 25;

    /** Customers can reply to (and reopen) a closed request for this many days. */
    const REOPEN_DAYS = 14;

    const MAX_TEXT = 5000;

    /* ── Setup ─────────────────────────────────────────────────────────── */

    public static function boot(): void
    {
        add_action('admin_init', [__CLASS__, 'maybe_install']);
        add_action('woocommerce_order_refunded', [__CLASS__, 'order_refunded'], 20, 2);
    }

    public static function enabled(): bool
    {
        return class_exists('FFLA_Customer_Operations_Settings') && FFLA_Customer_Operations_Settings::enabled('requests');
    }

    public static function setting(string $key)
    {
        $settings = FFLA_Customer_Operations_Settings::get();
        return $settings[$key] ?? null;
    }

    /**
     * @return array{requests: string, events: string, files: string}
     */
    public static function tables(): array
    {
        global $wpdb;
        return [
            'requests' => $wpdb->prefix . 'ffla_requests',
            'events'   => $wpdb->prefix . 'ffla_request_events',
            'files'    => $wpdb->prefix . 'ffla_request_files',
        ];
    }

    public static function maybe_install(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION && self::enabled()) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::tables();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$t['requests']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            number varchar(60) NOT NULL,
            order_id bigint(20) unsigned NOT NULL,
            order_number varchar(60) NOT NULL DEFAULT '',
            type varchar(20) NOT NULL,
            reason varchar(40) NOT NULL DEFAULT '',
            preferred varchar(30) NOT NULL DEFAULT '',
            status varchar(30) NOT NULL,
            priority varchar(10) NOT NULL DEFAULT 'normal',
            assignee bigint(20) unsigned NOT NULL DEFAULT 0,
            due_at datetime NULL DEFAULT NULL,
            customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
            customer_name varchar(200) NOT NULL DEFAULT '',
            customer_email varchar(200) NOT NULL DEFAULT '',
            source varchar(20) NOT NULL DEFAULT '',
            items longtext NULL,
            has_firearm tinyint(1) NOT NULL DEFAULT 0,
            resolution varchar(30) NOT NULL DEFAULT '',
            resolution_note text NULL,
            access_secret char(32) NOT NULL DEFAULT '',
            awaiting varchar(10) NOT NULL DEFAULT 'staff',
            return_carrier varchar(20) NOT NULL DEFAULT '',
            return_tracking varchar(100) NOT NULL DEFAULT '',
            ffl longtext NULL,
            refund_total decimal(19,4) NOT NULL DEFAULT 0,
            waiting_since datetime NULL DEFAULT NULL,
            reminded_at datetime NULL DEFAULT NULL,
            rating tinyint(1) unsigned NOT NULL DEFAULT 0,
            rating_comment text NULL,
            rated_at datetime NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            closed_at datetime NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY number (number),
            KEY order_id (order_id),
            KEY status (status),
            KEY assignee (assignee),
            KEY customer_email (customer_email(60)),
            KEY updated_at (updated_at)
        ) $charset;");

        dbDelta("CREATE TABLE {$t['events']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            actor_type varchar(10) NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            kind varchar(20) NOT NULL,
            is_public tinyint(1) NOT NULL DEFAULT 0,
            body text NULL,
            meta longtext NULL,
            PRIMARY KEY  (id),
            KEY request_id (request_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$t['files']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id bigint(20) unsigned NOT NULL,
            event_id bigint(20) unsigned NOT NULL DEFAULT 0,
            token char(32) NOT NULL,
            name varchar(200) NOT NULL,
            mime varchar(50) NOT NULL,
            size int(10) unsigned NOT NULL DEFAULT 0,
            data longblob NOT NULL,
            is_public tinyint(1) NOT NULL DEFAULT 0,
            kind varchar(20) NOT NULL DEFAULT '',
            actor_type varchar(10) NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY request_id (request_id)
        ) $charset;");

        update_option(self::DB_OPTION, self::DB_VERSION, true);
    }

    /* ── Vocabulary ────────────────────────────────────────────────────── */

    /**
     * Status => [staff label, customer label, is open, types it applies to].
     */
    public static function statuses(): array
    {
        return [
            'new'              => [__('New', 'ffl-funnels-addons'), __('Received', 'ffl-funnels-addons'), true, ['issue', 'return']],
            'in_review'        => [__('In review', 'ffl-funnels-addons'), __('Under review', 'ffl-funnels-addons'), true, ['issue', 'return']],
            'waiting_customer' => [__('Waiting for customer', 'ffl-funnels-addons'), __('Waiting for your reply', 'ffl-funnels-addons'), true, ['issue', 'return']],
            'waiting_carrier'  => [__('Waiting for carrier', 'ffl-funnels-addons'), __('Waiting on the carrier', 'ffl-funnels-addons'), true, ['issue', 'return']],
            'approved'         => [__('Return approved', 'ffl-funnels-addons'), __('Return approved — send the item back', 'ffl-funnels-addons'), true, ['return']],
            'item_received'    => [__('Item received', 'ffl-funnels-addons'), __('Item received — being inspected', 'ffl-funnels-addons'), true, ['return']],
            'closed'           => [__('Closed', 'ffl-funnels-addons'), __('Closed', 'ffl-funnels-addons'), false, ['issue', 'return']],
        ];
    }

    public static function status_label(string $status, string $audience = 'staff'): string
    {
        $all = self::statuses();
        return isset($all[$status]) ? $all[$status]['customer' === $audience ? 1 : 0] : $status;
    }

    public static function is_open(string $status): bool
    {
        $all = self::statuses();
        return isset($all[$status]) && $all[$status][2];
    }

    /** Statuses staff can move a request of this type to (closing goes through close()). */
    public static function statuses_for(string $type): array
    {
        $out = [];
        foreach (self::statuses() as $key => $def) {
            if ('closed' !== $key && in_array($type, $def[3], true)) {
                $out[$key] = $def[0];
            }
        }
        return $out;
    }

    public static function resolutions(): array
    {
        return apply_filters('ffla_requests_resolutions', [
            'refunded'       => __('Refunded', 'ffl-funnels-addons'),
            'partial_refund' => __('Partially refunded', 'ffl-funnels-addons'),
            'replaced'       => __('Replacement sent', 'ffl-funnels-addons'),
            'reshipped'      => __('Order re-shipped', 'ffl-funnels-addons'),
            'exchanged'      => __('Exchanged', 'ffl-funnels-addons'),
            'repaired'       => __('Repaired', 'ffl-funnels-addons'),
            'store_credit'   => __('Store credit issued', 'ffl-funnels-addons'),
            'resolved'       => __('Resolved', 'ffl-funnels-addons'),
            'denied'         => __('Request declined', 'ffl-funnels-addons'),
            'withdrawn'      => __('Cancelled by customer', 'ffl-funnels-addons'),
            'no_response'    => __('Closed — no reply from customer', 'ffl-funnels-addons'),
            'duplicate'      => __('Duplicate request', 'ffl-funnels-addons'),
        ]);
    }

    public static function reasons(string $type): array
    {
        $reasons = 'return' === $type
            ? [
                'changed_mind'       => __('No longer needed', 'ffl-funnels-addons'),
                'ordered_by_mistake' => __('Ordered by mistake', 'ffl-funnels-addons'),
                'not_as_described'   => __('Not as described', 'ffl-funnels-addons'),
                'fit'                => __("Doesn't fit / not compatible", 'ffl-funnels-addons'),
                'defective'          => __('Defective or not working', 'ffl-funnels-addons'),
                'damaged'            => __('Arrived damaged', 'ffl-funnels-addons'),
                'wrong_item'         => __('Wrong item received', 'ffl-funnels-addons'),
                'other'              => __('Other', 'ffl-funnels-addons'),
            ]
            : [
                'not_received'  => __('Package not received', 'ffl-funnels-addons'),
                'late'          => __('Shipping delay', 'ffl-funnels-addons'),
                'damaged'       => __('Arrived damaged', 'ffl-funnels-addons'),
                'missing_item'  => __('Item missing from the package', 'ffl-funnels-addons'),
                'wrong_item'    => __('Wrong item received', 'ffl-funnels-addons'),
                'defective'     => __('Defective or not working', 'ffl-funnels-addons'),
                'billing'       => __('Billing or payment question', 'ffl-funnels-addons'),
                'transfer'      => __('FFL transfer / pickup question', 'ffl-funnels-addons'),
                'other'         => __('Other', 'ffl-funnels-addons'),
            ];

        return apply_filters('ffla_requests_reasons', $reasons, $type);
    }

    public static function preferences(): array
    {
        return apply_filters('ffla_requests_preferences', [
            'refund'        => __('Refund', 'ffl-funnels-addons'),
            'replacement'   => __('Replacement', 'ffl-funnels-addons'),
            'exchange'      => __('Exchange', 'ffl-funnels-addons'),
            'store_credit'  => __('Store credit', 'ffl-funnels-addons'),
            'repair'        => __('Repair', 'ffl-funnels-addons'),
            'no_preference' => __('No preference', 'ffl-funnels-addons'),
        ]);
    }

    public static function priorities(): array
    {
        return [
            'low'    => __('Low', 'ffl-funnels-addons'),
            'normal' => __('Normal', 'ffl-funnels-addons'),
            'high'   => __('High', 'ffl-funnels-addons'),
            'urgent' => __('Urgent', 'ffl-funnels-addons'),
        ];
    }

    public static function type_label(string $type): string
    {
        return 'return' === $type ? __('Return', 'ffl-funnels-addons') : __('Issue', 'ffl-funnels-addons');
    }

    /** Which request types are switched on. */
    public static function types_enabled(): array
    {
        $types = [];
        if (FFLA_Customer_Operations_Settings::enabled('requests_issues')) {
            $types[] = 'issue';
        }
        if (FFLA_Customer_Operations_Settings::enabled('requests_returns')) {
            $types[] = 'return';
        }
        return $types;
    }

    /* ── Orders ────────────────────────────────────────────────────────── */

    /**
     * Find an order by the number the customer sees (supports sequential
     * order-number plugins through `_order_number` and a filter).
     */
    public static function find_order(string $number)
    {
        $number = ltrim(trim($number), '#');
        if ('' === $number || strlen($number) > 60) {
            return null;
        }

        $order = apply_filters('ffla_requests_find_order', null, $number);
        if ($order instanceof WC_Order) {
            return $order;
        }

        $candidates = [];
        if (ctype_digit($number)) {
            $candidates[] = wc_get_order((int) $number);
        }
        if (function_exists('wc_get_orders')) {
            try {
                $found = wc_get_orders(['limit' => 1, 'type' => 'shop_order', 'meta_query' => [['key' => '_order_number', 'value' => $number]]]);
                if (!empty($found)) {
                    $candidates[] = $found[0];
                }
            } catch (Throwable $e) {
                // Older stores without meta_query support: numeric IDs still work.
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate instanceof WC_Order && 'shop_order' === $candidate->get_type() && (string) $candidate->get_order_number() === $number) {
                return $candidate;
            }
        }

        return null;
    }

    /** The order when number and billing email match, else null (no hint why). */
    public static function verify(string $number, string $email)
    {
        $order = self::find_order($number);
        if (!$order) {
            return null;
        }
        $billing = strtolower(trim((string) $order->get_billing_email()));
        $email = strtolower(trim($email));

        return ('' !== $billing && hash_equals($billing, $email)) ? $order : null;
    }

    public static function is_owner($order): bool
    {
        return $order instanceof WC_Order && get_current_user_id() > 0 && (int) $order->get_customer_id() === get_current_user_id();
    }

    public static function is_staff_for($order): bool
    {
        return $order instanceof WC_Order && current_user_can('manage_woocommerce') && current_user_can('edit_shop_order', $order->get_id());
    }

    /**
     * Whether new issue / return requests can be opened for an order.
     *
     * @return array{issue: string, return: string} '' when allowed, otherwise the reason.
     */
    public static function eligibility($order): array
    {
        $out = ['issue' => '', 'return' => ''];
        $types = self::types_enabled();

        if (!$order instanceof WC_Order || in_array($order->get_status(), ['pending', 'failed', 'cancelled', 'checkout-draft', 'trash'], true)) {
            $msg = __('This order is not eligible for requests. Please contact the store.', 'ffl-funnels-addons');
            return ['issue' => $msg, 'return' => $msg];
        }

        $counts = self::order_counts($order->get_id());
        if ($counts['open'] >= self::MAX_OPEN_PER_ORDER || $counts['total'] >= self::MAX_PER_ORDER) {
            $msg = __('This order already has the maximum number of requests. Reply on an existing request instead.', 'ffl-funnels-addons');
            return ['issue' => $msg, 'return' => $msg];
        }

        $created = $order->get_date_created();
        $issue_days = max(1, (int) self::setting('requests_issue_days'));
        if (!in_array('issue', $types, true)) {
            $out['issue'] = __('Issue reports are not available.', 'ffl-funnels-addons');
        } elseif ($created && $created->getTimestamp() < time() - $issue_days * DAY_IN_SECONDS) {
            /* translators: %d: days */
            $out['issue'] = sprintf(__('Issue reports can be opened up to %d days after ordering. Please contact the store.', 'ffl-funnels-addons'), $issue_days);
        }

        if (!in_array('return', $types, true)) {
            $out['return'] = __('Return requests are not available.', 'ffl-funnels-addons');
        } elseif ('refunded' === $order->get_status()) {
            $out['return'] = __('This order was already refunded.', 'ffl-funnels-addons');
        } else {
            // Per line: units left, return rules and each line's own window.
            $lines = array_filter(self::returnable_items($order), static function ($i) { return $i['available'] > 0; });
            $open = array_filter($lines, static function ($i) { return '' === $i['blocked']; });
            if (!$lines) {
                $out['return'] = __('There are no items left to return on this order.', 'ffl-funnels-addons');
            } elseif (!$open) {
                $windows = array_filter($lines, static function ($i) { return $i['allowed']; });
                $out['return'] = $windows
                    /* translators: %d: days */
                    ? sprintf(__('Returns can be requested up to %d days after the order was completed.', 'ffl-funnels-addons'), max(array_column($windows, 'days')))
                    : __('The items on this order cannot be returned online. Please contact the store.', 'ffl-funnels-addons');
            }
        }

        return $out;
    }

    /**
     * Order lines with how many units can still be returned: ordered minus
     * refunded minus units already in OPEN return requests.
     *
     * @return array<int, array{item_id:int, product_id:int, name:string, ordered:int, refunded:int, requested:int, available:int, firearm:bool}>
     */
    public static function returnable_items($order, int $except_request = 0): array
    {
        $in_open = [];
        foreach (self::for_order($order->get_id()) as $request) {
            if ('return' !== $request->type || !self::is_open($request->status) || (int) $request->id === $except_request) {
                continue;
            }
            foreach (self::items($request) as $line) {
                $in_open[$line['item_id']] = ($in_open[$line['item_id']] ?? 0) + (int) $line['qty'];
            }
        }

        $since = $order->get_date_completed() ?: ($order->get_date_paid() ?: $order->get_date_created());
        $since = $since ? $since->getTimestamp() : time();

        $out = [];
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $ordered = (int) $item->get_quantity();
            $refunded = abs((int) $order->get_qty_refunded_for_item($item_id));
            $requested = (int) ($in_open[$item_id] ?? 0);
            $policy = FFLA_Requests_Rules::for_product((int) $item->get_product_id());
            $in_window = $since >= time() - $policy['days'] * DAY_IN_SECONDS;
            $blocked = '';
            if (!$policy['returnable']) {
                $blocked = $policy['note'];
            } elseif (!$in_window) {
                /* translators: %d: days */
                $blocked = sprintf(__('Return window ended (%d days).', 'ffl-funnels-addons'), $policy['days']);
            }
            $out[(int) $item_id] = [
                'item_id'    => (int) $item_id,
                'product_id' => (int) $item->get_product_id(),
                'name'       => $item->get_name(),
                'ordered'    => $ordered,
                'refunded'   => $refunded,
                'requested'  => $requested,
                'available'  => max(0, $ordered - $refunded - $requested),
                'firearm'    => class_exists('FFLA_Customer_Operations') ? FFLA_Customer_Operations::firearm($item) : false,
                'price'      => $ordered ? round(((float) $item->get_total() + (float) $item->get_total_tax()) / $ordered, wc_get_price_decimals()) : 0.0,
                'allowed'    => $policy['returnable'],
                'days'       => $policy['days'],
                'fee'        => $policy['fee'],
                'note'       => $policy['note'],
                'blocked'    => $blocked,
            ];
        }

        return $out;
    }

    /* ── Reading ───────────────────────────────────────────────────────── */

    public static function get(int $id)
    {
        global $wpdb;
        $t = self::tables();
        return $id > 0 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE id = %d", $id)) : null; // phpcs:ignore
    }

    public static function get_by_number(string $number)
    {
        global $wpdb;
        $t = self::tables();
        $number = ltrim(trim($number), '#');
        return '' !== $number ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE number = %s", $number)) : null; // phpcs:ignore
    }

    public static function for_order(int $order_id): array
    {
        global $wpdb;
        $t = self::tables();
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE order_id = %d ORDER BY id ASC", $order_id)); // phpcs:ignore
    }

    /** @return array{open:int, total:int} */
    public static function order_counts(int $order_id): array
    {
        $open = 0;
        $rows = self::for_order($order_id);
        foreach ($rows as $row) {
            if (self::is_open($row->status)) {
                $open++;
            }
        }
        return ['open' => $open, 'total' => count($rows)];
    }

    public static function items($request): array
    {
        $items = json_decode((string) ($request->items ?? ''), true);
        return is_array($items) ? $items : [];
    }

    /**
     * Inbox query.
     *
     * @param array $args view (open|new|awaiting|mine|overdue|closed|all), type, status, search, orderby, order, page, per_page, order_id
     * @return array{rows: array, total: int}
     */
    public static function query(array $args): array
    {
        global $wpdb;
        $t = self::tables();
        $where = ['1=1'];
        $params = [];
        $now = current_time('mysql', true);

        switch ($args['view'] ?? 'open') {
            case 'new':
                $where[] = "status = 'new'";
                break;
            case 'awaiting':
                $where[] = "status <> 'closed' AND awaiting = 'staff'";
                break;
            case 'mine':
                $where[] = "status <> 'closed' AND assignee = %d";
                $params[] = get_current_user_id();
                break;
            case 'overdue':
                $where[] = "status <> 'closed' AND due_at IS NOT NULL AND due_at < %s";
                $params[] = $now;
                break;
            case 'closed':
                $where[] = "status = 'closed'";
                break;
            case 'all':
                break;
            default:
                $where[] = "status <> 'closed'";
        }

        if (!empty($args['type']) && in_array($args['type'], ['issue', 'return'], true)) {
            $where[] = 'type = %s';
            $params[] = $args['type'];
        }
        if (!empty($args['status']) && isset(self::statuses()[$args['status']])) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }
        if (!empty($args['customer_id'])) {
            $where[] = 'customer_id = %d';
            $params[] = (int) $args['customer_id'];
        }
        if (!empty($args['order_id'])) {
            $where[] = 'order_id = %d';
            $params[] = (int) $args['order_id'];
        }
        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[] = '(number LIKE %s OR order_number = %s OR customer_email LIKE %s OR customer_name LIKE %s)';
            array_push($params, $like, ltrim((string) $args['search'], '#'), $like, $like);
        }

        $orderby = in_array($args['orderby'] ?? '', ['created_at', 'updated_at', 'due_at', 'number', 'status'], true) ? $args['orderby'] : 'updated_at';
        $order = 'ASC' === strtoupper((string) ($args['order'] ?? '')) ? 'ASC' : 'DESC';
        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page = max(1, (int) ($args['page'] ?? 1));

        $sql_where = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$t['requests']} WHERE {$sql_where}";
        $list_sql = "SELECT * FROM {$t['requests']} WHERE {$sql_where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, array_merge($params, [$per_page, ($page - 1) * $per_page])));
        // phpcs:enable

        return ['rows' => (array) $rows, 'total' => $total];
    }

    /** Counts per inbox view. */
    public static function counts(): array
    {
        global $wpdb;
        $t = self::tables();
        $now = current_time('mysql', true);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(status <> 'closed') AS open_count,
                SUM(status = 'new') AS new_count,
                SUM(status <> 'closed' AND awaiting = 'staff') AS awaiting_count,
                SUM(status <> 'closed' AND assignee = %d) AS mine_count,
                SUM(status <> 'closed' AND due_at IS NOT NULL AND due_at < %s) AS overdue_count,
                SUM(status = 'closed') AS closed_count,
                COUNT(*) AS all_count
            FROM {$t['requests']}",
            get_current_user_id(),
            $now
        ), ARRAY_A);

        $out = [];
        foreach (['open', 'new', 'awaiting', 'mine', 'overdue', 'closed', 'all'] as $view) {
            $out[$view] = (int) ($row[$view . '_count'] ?? 0);
        }
        return $out;
    }

    public static function events(int $request_id, bool $public_only = false): array
    {
        global $wpdb;
        $t = self::tables();
        $sql = "SELECT * FROM {$t['events']} WHERE request_id = %d" . ($public_only ? ' AND is_public = 1' : '') . ' ORDER BY id ASC';
        return (array) $wpdb->get_results($wpdb->prepare($sql, $request_id)); // phpcs:ignore
    }

    /* ── Writing ───────────────────────────────────────────────────────── */

    /**
     * Open a request.
     *
     * @param WC_Order $order
     * @param array    $data  type, reason, preferred, message, items (item_id => qty)
     * @param string   $source public_form|my_account|staff
     * @param array    $actor ['type' => customer|staff, 'id' => int]
     * @return object The new request row.
     * @throws InvalidArgumentException When the input is not acceptable.
     */
    public static function create($order, array $data, string $source, array $actor)
    {
        global $wpdb;
        self::maybe_install_now();

        $type = in_array($data['type'] ?? '', ['issue', 'return'], true) ? $data['type'] : '';
        if ('' === $type) {
            throw new InvalidArgumentException(__('Choose whether this is an issue or a return.', 'ffl-funnels-addons'));
        }

        // Customers must be inside the store's windows and limits; staff
        // opening a request on someone's behalf (phone, email) may make
        // exceptions.
        $staff = 'staff' === ($actor['type'] ?? '');
        if (!$staff) {
            $eligibility = self::eligibility($order);
            if ('' !== $eligibility[$type]) {
                throw new InvalidArgumentException($eligibility[$type]);
            }
        }

        $reasons = self::reasons($type);
        $reason = sanitize_key($data['reason'] ?? '');
        if (!isset($reasons[$reason])) {
            throw new InvalidArgumentException(__('Choose a reason.', 'ffl-funnels-addons'));
        }

        $preferred = sanitize_key($data['preferred'] ?? '');
        if ('' !== $preferred && !isset(self::preferences()[$preferred])) {
            $preferred = '';
        }

        $message = self::clean_text($data['message'] ?? '');
        if ('' === $message) {
            throw new InvalidArgumentException(__('Describe the problem in a few words.', 'ffl-funnels-addons'));
        }

        // Items: required for returns, optional for issues (which product is affected).
        $lines = [];
        $returnable = self::returnable_items($order);
        foreach ((array) ($data['items'] ?? []) as $item_id => $qty) {
            $item_id = absint($item_id);
            $qty = absint($qty);
            if (!$qty || !isset($returnable[$item_id])) {
                continue;
            }
            $line = $returnable[$item_id];
            $limit = 'return' === $type ? $line['available'] : $line['ordered'];
            if ($qty > $limit) {
                /* translators: %s: product name */
                throw new InvalidArgumentException(sprintf(__('Too many units selected for %s.', 'ffl-funnels-addons'), $line['name']));
            }
            // Return rules bind customers; staff may make exceptions.
            if ('return' === $type && !$staff && '' !== $line['blocked']) {
                /* translators: 1: product name, 2: reason */
                throw new InvalidArgumentException(sprintf(__('%1$s cannot be returned: %2$s', 'ffl-funnels-addons'), $line['name'], $line['blocked']));
            }
            $lines[] = [
                'item_id'    => $item_id,
                'product_id' => $line['product_id'],
                'name'       => $line['name'],
                'qty'        => $qty,
                'firearm'    => (bool) $line['firearm'],
                'price'      => (float) $line['price'],
                'fee'        => 'return' === $type ? FFLA_Requests_Rules::fee_for((float) $line['fee'], $reason) : 0.0,
                'exception'  => 'return' === $type && '' !== $line['blocked'],
            ];
        }
        if ('return' === $type && !$lines) {
            throw new InvalidArgumentException(__('Select the items and quantities to return.', 'ffl-funnels-addons'));
        }

        if (!$staff && FFLA_Requests_Rules::photo_required($reason) && empty($data['files_count'])) {
            throw new InvalidArgumentException(__('Please add at least one photo for this reason.', 'ffl-funnels-addons'));
        }

        // Firearms come back through a licensed dealer.
        $ffl = null;
        $returns_firearm = 'return' === $type && (bool) array_filter($lines, static function ($l) { return $l['firearm']; });
        if ($returns_firearm && isset($data['ffl']) && is_array($data['ffl'])) {
            $ffl = self::clean_ffl($data['ffl'], $order);
        }
        if ($returns_firearm && !$staff && !$ffl && FFLA_Customer_Operations_Settings::enabled('requests_ffl_required')) {
            throw new InvalidArgumentException(__('Enter the FFL dealer that will ship the firearm back (name, license number, city and state).', 'ffl-funnels-addons'));
        }

        $now = current_time('mysql', true);
        $base = (string) $order->get_order_number();
        $has_firearm = (bool) array_filter($lines, static function ($l) { return $l['firearm']; });
        $row = [
            'order_id'       => $order->get_id(),
            'order_number'   => $base,
            'type'           => $type,
            'reason'         => $reason,
            'preferred'      => $preferred,
            'status'         => 'new',
            'priority'       => 'normal',
            'assignee'       => 0,
            'customer_id'    => (int) $order->get_customer_id(),
            'customer_name'  => substr(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()), 0, 200),
            'customer_email' => substr(strtolower(trim((string) $order->get_billing_email())), 0, 200),
            'source'         => in_array($source, ['public_form', 'my_account', 'staff'], true) ? $source : 'public_form',
            'items'          => wp_json_encode($lines),
            'has_firearm'    => $has_firearm ? 1 : 0,
            'ffl'            => $ffl ? wp_json_encode($ffl) : null,
            'access_secret'  => self::random_hex(16),
            'awaiting'       => $staff ? 'customer' : 'staff',
            'created_at'     => $now,
            'updated_at'     => $now,
        ];

        // Number = order number + sequence; retry on the (rare) concurrent collision.
        $t = self::tables();
        $inserted = false;
        for ($attempt = 0; $attempt < 5 && !$inserted; $attempt++) {
            $row['number'] = substr($base, 0, 50) . '-' . (self::order_counts($order->get_id())['total'] + 1 + $attempt);
            $inserted = (bool) $wpdb->insert($t['requests'], $row);
        }
        if (!$inserted) {
            throw new RuntimeException(__('The request could not be saved. Please try again.', 'ffl-funnels-addons'));
        }

        $request = self::get((int) $wpdb->insert_id);
        self::add_event($request, 'created', $actor, true, $message, ['reason' => $reason, 'preferred' => $preferred, 'items' => $lines]);

        if (class_exists('FFLA_Customer_Operations')) {
            FFLA_Customer_Operations::audit($order, sprintf('Customer request %s opened (%s: %s).', $request->number, $type, $reasons[$reason]));
        }

        do_action('ffla_request_created', $request, $order);

        return $request;
    }

    /**
     * Append a timeline event. Updates the request's activity markers.
     *
     * @return int Event ID.
     */
    public static function add_event($request, string $kind, array $actor, bool $public, string $body = '', array $meta = []): int
    {
        global $wpdb;
        $t = self::tables();
        $wpdb->insert($t['events'], [
            'request_id' => (int) $request->id,
            'created_at' => current_time('mysql', true),
            'actor_type' => in_array($actor['type'] ?? '', ['customer', 'staff', 'system'], true) ? $actor['type'] : 'system',
            'actor_id'   => (int) ($actor['id'] ?? 0),
            'kind'       => sanitize_key($kind),
            'is_public'  => $public ? 1 : 0,
            'body'       => $body,
            'meta'       => $meta ? wp_json_encode($meta) : null,
        ]);
        $event_id = (int) $wpdb->insert_id;

        $update = ['updated_at' => current_time('mysql', true)];
        if ('message' === $kind || 'created' === $kind) {
            $update['awaiting'] = 'customer' === ($actor['type'] ?? '') ? 'staff' : 'customer';
        }
        $wpdb->update($t['requests'], $update, ['id' => (int) $request->id]);

        return $event_id;
    }

    /**
     * Staff or customer message on the timeline.
     */
    public static function add_message($request, array $actor, string $text, bool $public = true): int
    {
        $text = self::clean_text($text);
        if ('' === $text) {
            throw new InvalidArgumentException(__('Write a message first.', 'ffl-funnels-addons'));
        }

        $customer = 'customer' === ($actor['type'] ?? '');
        if ($customer && !self::is_open($request->status)) {
            if (!self::can_customer_reopen($request)) {
                throw new InvalidArgumentException(__('This request is closed. Please open a new request.', 'ffl-funnels-addons'));
            }
            self::reopen($request, $actor, false);
            $request = self::get((int) $request->id);
        } elseif ($customer && 'waiting_customer' === $request->status) {
            self::set_status($request, 'in_review', $actor, false);
            $request = self::get((int) $request->id);
        }

        return self::add_event($request, $public ? 'message' : 'note', $actor, $public, $text);
    }

    /** @return int The status event ID (0 when the status did not change). */
    public static function set_status($request, string $to, array $actor, bool $public_event = true, string $note = ''): int
    {
        global $wpdb;
        $allowed = self::statuses_for($request->type);
        if (!isset($allowed[$to])) {
            throw new InvalidArgumentException(__('That status is not available for this request.', 'ffl-funnels-addons'));
        }
        $from = (string) $request->status;
        if ($from === $to) {
            return 0;
        }
        if ('closed' === $from) {
            throw new InvalidArgumentException(__('Reopen the request before changing its status.', 'ffl-funnels-addons'));
        }

        $t = self::tables();
        $now = current_time('mysql', true);
        // waiting_since drives reminders and auto-close; it restarts each time we wait on the customer.
        $wpdb->update($t['requests'], [
            'status'        => $to,
            'updated_at'    => $now,
            'waiting_since' => 'waiting_customer' === $to ? $now : null,
            'reminded_at'   => null,
        ], ['id' => (int) $request->id]);
        $event_id = self::add_event($request, 'status', $actor, $public_event, $note, ['from' => $from, 'to' => $to]);
        self::order_note($request, sprintf('Customer request %s: %s → %s.', $request->number, self::status_label($from), self::status_label($to)));

        do_action('ffla_request_status_changed', self::get((int) $request->id), $from, $to);
        return $event_id;
    }

    /**
     * Close with a required outcome and a note the customer sees.
     */
    public static function close($request, string $resolution, string $note, array $actor): void
    {
        global $wpdb;
        if (!self::is_open($request->status)) {
            throw new InvalidArgumentException(__('This request is already closed.', 'ffl-funnels-addons'));
        }
        if (!isset(self::resolutions()[$resolution])) {
            throw new InvalidArgumentException(__('Choose how the request was resolved.', 'ffl-funnels-addons'));
        }
        $note = self::clean_text($note);
        if ('' === $note && 'withdrawn' !== $resolution) {
            throw new InvalidArgumentException(__('Write a resolution note for the customer.', 'ffl-funnels-addons'));
        }

        $t = self::tables();
        $now = current_time('mysql', true);
        $wpdb->update($t['requests'], [
            'status'          => 'closed',
            'resolution'      => $resolution,
            'resolution_note' => $note,
            'closed_at'       => $now,
            'updated_at'      => $now,
            'awaiting'        => '',
            'waiting_since'   => null,
        ], ['id' => (int) $request->id]);

        self::add_event($request, 'resolution', $actor, true, $note, ['from' => $request->status, 'resolution' => $resolution]);
        self::order_note($request, sprintf('Customer request %s closed: %s.', $request->number, self::resolutions()[$resolution]));

        do_action('ffla_request_closed', self::get((int) $request->id), $resolution);
    }

    public static function reopen($request, array $actor, bool $public_event = true): void
    {
        global $wpdb;
        if (self::is_open($request->status)) {
            return;
        }
        $t = self::tables();
        $wpdb->update($t['requests'], [
            'status'     => 'in_review',
            'resolution' => '',
            'closed_at'  => null,
            'updated_at' => current_time('mysql', true),
            'awaiting'   => 'staff',
        ], ['id' => (int) $request->id]);
        self::add_event($request, 'reopen', $actor, $public_event, '', ['previous_resolution' => $request->resolution]);
        self::order_note($request, sprintf('Customer request %s reopened.', $request->number));
    }

    public static function can_customer_reopen($request): bool
    {
        if (self::is_open($request->status) || in_array($request->resolution, ['duplicate'], true)) {
            return false;
        }
        $closed = $request->closed_at ? strtotime($request->closed_at . ' UTC') : 0;
        return $closed && $closed > time() - self::REOPEN_DAYS * DAY_IN_SECONDS;
    }

    /**
     * Staff fields: assignee, priority, due date (GMT datetime or '').
     */
    public static function update_fields($request, array $fields, array $actor): void
    {
        global $wpdb;
        $t = self::tables();
        $changes = [];

        if (array_key_exists('assignee', $fields)) {
            $assignee = absint($fields['assignee']);
            if ($assignee) {
                $user = get_user_by('id', $assignee);
                if (!$user || !user_can($user, 'manage_woocommerce')) {
                    throw new InvalidArgumentException(__('Assign the request to a store manager.', 'ffl-funnels-addons'));
                }
            }
            if ($assignee !== (int) $request->assignee) {
                $changes['assignee'] = $assignee;
            }
        }
        if (array_key_exists('priority', $fields) && isset(self::priorities()[$fields['priority']]) && $fields['priority'] !== $request->priority) {
            $changes['priority'] = $fields['priority'];
        }
        if (array_key_exists('due_at', $fields)) {
            $due = $fields['due_at'] ? (string) $fields['due_at'] : null;
            if ($due !== $request->due_at) {
                $changes['due_at'] = $due;
            }
        }

        if (!$changes) {
            return;
        }

        $changes['updated_at'] = current_time('mysql', true);
        $wpdb->update($t['requests'], $changes, ['id' => (int) $request->id]);
        unset($changes['updated_at']);
        self::add_event($request, 'update', $actor, false, '', ['changes' => $changes]);

        if (!empty($changes['assignee'])) {
            do_action('ffla_request_assigned', self::get((int) $request->id), (int) $changes['assignee']);
        }
    }

    /** Permanently remove a request with its history and files (spam, test data). */
    public static function delete($request): void
    {
        global $wpdb;
        $t = self::tables();
        $id = (int) $request->id;
        $wpdb->delete($t['files'], ['request_id' => $id]);
        $wpdb->delete($t['events'], ['request_id' => $id]);
        $wpdb->delete($t['requests'], ['id' => $id]);
        self::order_note($request, sprintf('Customer request %s deleted by staff.', $request->number));
    }

    /** A refund on the order is recorded on its open requests (staff-only). */
    public static function order_refunded($order_id, $refund_id): void
    {
        if (!self::enabled() || get_option(self::DB_OPTION) !== self::DB_VERSION) {
            return;
        }
        $refund = wc_get_order($refund_id);
        foreach (self::for_order((int) $order_id) as $request) {
            if (self::is_open($request->status)) {
                $amount = $refund ? wp_strip_all_tags(wc_price($refund->get_amount(), ['currency' => $refund->get_currency()])) : '';
                self::add_event($request, 'note', ['type' => 'system', 'id' => 0], false,
                    /* translators: %s: amount */
                    sprintf(__('A refund of %s was issued on the order. Close this request with the matching outcome when done.', 'ffl-funnels-addons'), $amount));
            }
        }
    }

    /* ── Return shipping ───────────────────────────────────────────────── */

    public static function carriers(): array
    {
        return apply_filters('ffla_requests_carriers', [
            'ups'   => 'UPS',
            'usps'  => 'USPS',
            'fedex' => 'FedEx',
            'dhl'   => 'DHL',
            'other' => __('Other', 'ffl-funnels-addons'),
        ]);
    }

    public static function tracking_link(string $carrier, string $number): string
    {
        $number = rawurlencode($number);
        $links = [
            'ups'   => 'https://www.ups.com/track?tracknum=' . $number,
            'usps'  => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $number,
            'fedex' => 'https://www.fedex.com/fedextrack/?trknbr=' . $number,
            'dhl'   => 'https://www.dhl.com/us-en/home/tracking/tracking-express.html?tracking-id=' . $number,
        ];
        return (string) apply_filters('ffla_requests_tracking_link', $links[$carrier] ?? '', $carrier, $number);
    }

    /** Record how the returned item travels back to the store. */
    public static function set_return_tracking($request, string $carrier, string $tracking, array $actor): void
    {
        global $wpdb;
        $carrier = sanitize_key($carrier);
        $tracking = strtoupper(preg_replace('/[^A-Za-z0-9 \-]/', '', substr(trim($tracking), 0, 60)));
        if (!isset(self::carriers()[$carrier]) || strlen(str_replace([' ', '-'], '', $tracking)) < 6) {
            throw new InvalidArgumentException(__('Choose the carrier and enter a valid tracking number.', 'ffl-funnels-addons'));
        }
        $t = self::tables();
        $wpdb->update($t['requests'], ['return_carrier' => $carrier, 'return_tracking' => $tracking], ['id' => (int) $request->id]);
        self::add_event($request, 'tracking', $actor, true, self::carriers()[$carrier] . ' ' . $tracking, ['carrier' => $carrier, 'tracking' => $tracking]);
        if ('customer' === ($actor['type'] ?? '')) {
            $wpdb->update($t['requests'], ['awaiting' => 'staff'], ['id' => (int) $request->id]);
        }
        self::order_note($request, sprintf('Customer request %s: return shipment %s %s.', $request->number, self::carriers()[$carrier], $tracking));
    }

    /* ── FFL dealer for firearm returns ────────────────────────────────── */

    /** Normalize an FFL license number (format only, not verified). */
    public static function license($value): string
    {
        if (class_exists('Pickup_Shipping_Settings')) {
            return Pickup_Shipping_Settings::license($value);
        }
        $value = strtoupper(preg_replace('/[\s-]/', '', (string) $value));
        return preg_match('/^[0-9]{9}[A-Z][0-9]{5}$/', $value) ? $value : '';
    }

    /** 123456789A12345 → 1-23-456-78-9A-12345 */
    public static function format_license(string $license): string
    {
        return 15 === strlen($license)
            ? implode('-', [substr($license, 0, 1), substr($license, 1, 2), substr($license, 3, 3), substr($license, 6, 2), substr($license, 8, 2), substr($license, 10, 5)])
            : $license;
    }

    /**
     * The dealer the order shipped to (g-FFL Checkout stores the license as
     * `_shipping_fflno` and the dealer in the shipping address).
     *
     * @return array|null
     */
    public static function order_dealer($order)
    {
        if (!$order instanceof WC_Order) {
            return null;
        }
        $license = '';
        foreach (['_shipping_fflno', 'shipping_fflno', '_ffl_license', '_ffl_id'] as $key) {
            $license = self::license($order->get_meta($key));
            if ('' !== $license) {
                break;
            }
        }
        $dealer = '' === $license ? null : [
            'source'   => 'order',
            'name'     => $order->get_shipping_company() ?: trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'license'  => $license,
            'address'  => trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2()),
            'city'     => $order->get_shipping_city(),
            'state'    => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'phone'    => method_exists($order, 'get_shipping_phone') ? $order->get_shipping_phone() : '',
            'email'    => '',
        ];
        return apply_filters('ffla_requests_order_dealer', $dealer, $order);
    }

    /**
     * Validate FFL input: ['source' => 'order'] reuses the order's dealer,
     * otherwise name, license, city and state are required.
     *
     * @return array|null
     */
    public static function clean_ffl(array $input, $order = null)
    {
        if ('order' === ($input['source'] ?? '')) {
            return self::order_dealer($order);
        }
        $clean = static function ($key, $max = 120) use ($input) {
            return substr(sanitize_text_field((string) ($input[$key] ?? '')), 0, $max);
        };
        $ffl = [
            'source'   => 'customer',
            'name'     => $clean('name'),
            'license'  => self::license($input['license'] ?? ''),
            'address'  => $clean('address', 200),
            'city'     => $clean('city', 80),
            'state'    => $clean('state', 40),
            'postcode' => $clean('postcode', 20),
            'phone'    => $clean('phone', 40),
            'email'    => sanitize_email((string) ($input['email'] ?? '')),
        ];
        if ('' === $ffl['name'] || '' === $ffl['license'] || '' === $ffl['city'] || '' === $ffl['state']) {
            return null;
        }
        return $ffl;
    }

    public static function ffl($request): array
    {
        $ffl = json_decode((string) ($request->ffl ?? ''), true);
        return is_array($ffl) ? $ffl : [];
    }

    public static function set_ffl($request, array $ffl, array $actor): void
    {
        global $wpdb;
        $t = self::tables();
        $wpdb->update($t['requests'], ['ffl' => wp_json_encode($ffl)], ['id' => (int) $request->id]);
        self::add_event($request, 'note', $actor, false, sprintf('FFL dealer for the return: %s (%s), %s %s', $ffl['name'], self::format_license($ffl['license']), $ffl['city'], $ffl['state']));
    }

    /* ── Rating and refunds ────────────────────────────────────────────── */

    public static function rate($request, int $rating, string $comment): void
    {
        global $wpdb;
        if (self::is_open($request->status)) {
            throw new InvalidArgumentException(__('You can rate a request once it is closed.', 'ffl-funnels-addons'));
        }
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException(__('Choose 1 to 5 stars.', 'ffl-funnels-addons'));
        }
        $comment = function_exists('mb_substr') ? mb_substr(self::clean_text($comment), 0, 1000) : substr(self::clean_text($comment), 0, 1000);
        $t = self::tables();
        $wpdb->update($t['requests'], ['rating' => $rating, 'rating_comment' => $comment, 'rated_at' => current_time('mysql', true)], ['id' => (int) $request->id]);
        self::add_event($request, 'rating', ['type' => 'customer', 'id' => get_current_user_id()], false, $comment, ['rating' => $rating]);
        do_action('ffla_request_rated', self::get((int) $request->id), $rating, $comment);
    }

    public static function add_refund_total($request, float $amount): void
    {
        global $wpdb;
        $t = self::tables();
        $wpdb->query($wpdb->prepare("UPDATE {$t['requests']} SET refund_total = refund_total + %f WHERE id = %d", $amount, (int) $request->id)); // phpcs:ignore
    }

    /* ── Access ────────────────────────────────────────────────────────── */

    /** The key in a customer's tracking link. */
    public static function access_key($request): string
    {
        return substr(hash_hmac('sha256', $request->id . '|' . $request->access_secret, wp_salt('auth')), 0, 32);
    }

    public static function check_key($request, string $key): bool
    {
        return $request && '' !== $key && hash_equals(self::access_key($request), $key);
    }

    /** Invalidate old links by issuing a new secret. */
    public static function rotate_key($request)
    {
        global $wpdb;
        $t = self::tables();
        $wpdb->update($t['requests'], ['access_secret' => self::random_hex(16)], ['id' => (int) $request->id]);
        return self::get((int) $request->id);
    }

    /** Page that holds the [ffla_order_requests] shortcode. */
    public static function page_url(): string
    {
        $page = absint(self::setting('requests_page'));
        $url = $page ? get_permalink($page) : '';
        return $url ? $url : home_url('/');
    }

    public static function tracking_url($request, bool $with_key = true): string
    {
        $args = ['ffla_request' => $request->number];
        if ($with_key) {
            $args['key'] = self::access_key($request);
        }
        return add_query_arg($args, self::page_url()) . '#ffla-requests';
    }

    /* ── Helpers ───────────────────────────────────────────────────────── */

    public static function clean_text($text): string
    {
        $text = is_scalar($text) ? sanitize_textarea_field((string) $text) : '';
        return function_exists('mb_substr') ? mb_substr($text, 0, self::MAX_TEXT) : substr($text, 0, self::MAX_TEXT);
    }

    public static function random_hex(int $bytes): string
    {
        try {
            return bin2hex(random_bytes($bytes));
        } catch (Throwable $e) {
            return substr(md5(wp_generate_password(32, true, true) . microtime()), 0, $bytes * 2);
        }
    }

    public static function maybe_install_now(): void
    {
        if (get_option(self::DB_OPTION) !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function order_note($request, string $text): void
    {
        $order = wc_get_order((int) $request->order_id);
        if ($order && class_exists('FFLA_Customer_Operations')) {
            FFLA_Customer_Operations::audit($order, $text);
        }
    }

    /** Local time display for a GMT datetime column. */
    public static function local_time(?string $gmt, string $format = ''): string
    {
        if (!$gmt) {
            return '';
        }
        $ts = strtotime($gmt . ' UTC');
        return $ts ? wp_date($format ? $format : get_option('date_format') . ' ' . get_option('time_format'), $ts) : '';
    }

    /**
     * Client IP for rate limiting (filterable for proxies that don't set
     * REMOTE_ADDR to the visitor's address).
     */
    public static function client_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return (string) apply_filters('ffla_requests_client_ip', $ip);
    }

    /**
     * Fixed-window rate limit. Returns false when the bucket is full.
     * Buckets are per visitor IP unless $per_ip is false (e.g. per order).
     * With $count false the bucket is only checked, not incremented.
     */
    public static function rate_limit(string $bucket, int $max, int $window, bool $per_ip = true, bool $count = true): bool
    {
        $key = 'ffla_rl_' . md5($bucket . '|' . ($per_ip ? self::client_ip() : '*'));
        $used = (int) get_transient($key);
        if ($used >= $max) {
            return false;
        }
        if ($count) {
            set_transient($key, $used + 1, $window);
        }
        return true;
    }
}
