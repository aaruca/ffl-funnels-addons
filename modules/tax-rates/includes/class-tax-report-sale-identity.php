<?php
/** Read-only Split Payment receipt/underlying-sale adapter. No gateway or order writes. */
defined('ABSPATH') || exit;

require_once __DIR__ . '/class-tax-report-memory.php';

class Tax_Report_Sale_Identity
{
    const PLAN_STATUSES = ['fppc-plan-hold', 'fppc-paid-manual', 'fppc-defaulted', 'fppc-denied', 'pending', 'failed', 'cancelled'];
    public $pages_processed = 0;

    /** @var array<int,bool>|null Page hint: does an order have any Split Payment line-item meta? */
    private $item_flag_hint = null;

    public static function policy(): string
    {
        return 'Split Payment: captured receipts by payment date; one underlying sale and original product quantities at the original captured deposit date. Later installments are collections, not new sales. Refunds retain their own dates. This is a reporting basis, not a determination of state nexus or filing rules.';
    }

    private function meta($order, string $key)
    {
        return is_object($order) && method_exists($order, 'get_meta') ? $order->get_meta($key, true) : '';
    }

    private function timestamp($order, string $method): int
    {
        $date = is_object($order) && method_exists($order, $method) ? $order->$method() : null;
        return is_object($date) && method_exists($date, 'getTimestamp') ? (int) $date->getTimestamp() : 0;
    }

    /** null means this gateway has no separate capture-proof adapter. */
    private function capture_confirmed($order): ?bool
    {
        if (!is_object($order) || !method_exists($order, 'get_payment_method') || $order->get_payment_method() !== 'anet') {
            return null;
        }
        if (is_callable(['FPPC_Anet_Official', 'capture_confirmed'])) {
            return (bool) FPPC_Anet_Official::capture_confirmed($order);
        }
        // Historical reporting must remain safe if Split Payment is deactivated.
        $charge = (string) $this->meta($order, '_anet_credit_card_charge_id');
        return $this->meta($order, '_anet_credit_card_charge_captured') === 'yes' && $charge !== '' && $charge !== '0';
    }

    public function describe($order): array
    {
        $id = (int) $order->get_id();
        $plan_id = (int) $this->meta($order, '_fppc_plan_subscription_id');
        $subscription = $plan_id && function_exists('wc_get_order') ? wc_get_order($plan_id) : null;
        // Legacy WCS renewals are managed only when the owning subscription is FPPC.
        if (!$plan_id && (int) $this->meta($order, '_subscription_renewal') > 0 && function_exists('wc_get_order')) {
            $candidate = wc_get_order((int) $this->meta($order, '_subscription_renewal'));
            if ($candidate && ($this->meta($candidate, '_fppc_managed_plan') === 'yes' || $this->meta($candidate, '_fppc_plan_components'))) {
                $subscription = $candidate;
                $plan_id = (int) $candidate->get_id();
            }
        }
        $managed = $plan_id > 0 || $this->meta($order, '_fppc_managed_plan') === 'yes'
            || (bool) $this->meta($order, '_fppc_plan_principals');
        if (!$managed && method_exists($order, 'get_items') && $this->may_have_managed_items($id)) {
            foreach ($order->get_items('line_item') as $item) {
                if ($this->meta($item, '_fppc_managed_plan') === 'yes' || $this->meta($item, '_fppc_plan_snapshot_v2')) {
                    $managed = true;
                    break;
                }
            }
        }
        $result = ['managed' => $managed, 'sale_id' => $id, 'parent_id' => $id, 'plan_id' => $plan_id,
            'paid_at' => $this->timestamp($order, 'get_date_paid'), 'recognized_at' => 0, 'uncaptured' => false, 'issues' => []];
        if (!$managed) { return $result; }
        $parent = $order;
        if ($plan_id) {
            $parent_id = $subscription && method_exists($subscription, 'get_parent_id') ? (int) $subscription->get_parent_id() : 0;
            $parent = $parent_id && function_exists('wc_get_order') ? wc_get_order($parent_id) : null;
            if (!$parent || $parent_id === $id || $parent_id === $plan_id) {
                $result['issues'][] = 'split_payment_identity_unresolved';
                $parent = null;
            } else {
                $result['sale_id'] = $result['parent_id'] = $parent_id;
                if (method_exists($parent, 'get_currency') && method_exists($order, 'get_currency') && $parent->get_currency() !== $order->get_currency()) {
                    $result['issues'][] = 'split_payment_currency_mismatch';
                }
                foreach (['get_shipping_country', 'get_shipping_state'] as $field) {
                    if (method_exists($parent, $field) && method_exists($order, $field)
                        && $parent->$field() !== $order->$field()) {
                        $result['issues'][] = 'split_payment_destination_changed';
                        break;
                    }
                }
            }
        }
        $result['recognized_at'] = $this->timestamp($parent, 'get_date_paid');
        if (function_exists('apply_filters')) {
            $result['recognized_at'] = (int) apply_filters('ffla_tax_sale_recognition_timestamp', $result['recognized_at'], $parent, $order);
        }
        if (!$result['recognized_at']) { $result['issues'][] = 'split_payment_sale_date_missing'; }
        if ($parent && $this->capture_confirmed($parent) === false) { $result['issues'][] = 'split_payment_parent_capture_unconfirmed'; }
        if (!$result['paid_at']) { $result['issues'][] = 'split_payment_capture_date_missing'; }
        if ($result['paid_at'] && $result['recognized_at'] > $result['paid_at']) {
            $result['issues'][] = 'split_payment_sale_date_after_receipt';
        }
        // Authorizations must not be represented as captured receipts.
        if ($this->capture_confirmed($order) === false) {
            $result['uncaptured'] = true;
            $result['paid_at'] = 0;
        } elseif ($result['paid_at'] && method_exists($order, 'get_status') && in_array($order->get_status(), ['pending', 'failed', 'cancelled'], true)) {
            // Do not silently erase a historical capture when an order is later cancelled.
            $result['issues'][] = 'split_payment_capture_status_conflict';
        }
        return $result;
    }

    public function counts_in_period(array $identity, int $from, int $to): bool
    {
        return !$identity['managed'] || (!$identity['issues'] && $identity['recognized_at'] >= $from && $identity['recognized_at'] <= $to);
    }

    /**
     * Two bounded, HPOS-compatible queries; ordinary orders keep their existing date/status basis.
     *
     * The first pass reads every order created in the period. The second can
     * only add Split Payment receipts created before it, so it asks for IDs and
     * loads just the orders the first pass did not see. The object cache is
     * released after every page, which keeps memory flat however long the period.
     */
    public function orders(array $statuses, int $from, int $to, int $page_size, int $cap): Generator
    {
        $seen = [];
        $scanned = 0;
        $capture_refs = [];
        $this->pages_processed = 0;
        $query_statuses = array_values(array_unique(array_merge($statuses, self::PLAN_STATUSES)));
        foreach (['date_created', 'date_paid'] as $date_field) {
            $page = 1;
            do {
                $query = ['type' => 'shop_order', 'status' => $query_statuses,
                    $date_field => $from . '...' . $to, 'orderby' => 'date', 'order' => 'ASC',
                    'limit' => $page_size, 'page' => $page, 'paginate' => true,
                    'return' => $date_field === 'date_created' ? 'objects' : 'ids'];
                $result = wc_get_orders($query);
                $this->pages_processed++;
                $rows = is_object($result) && isset($result->orders) ? $result->orders : (is_array($result) ? $result : []);
                $pages = is_object($result) && isset($result->max_num_pages) ? max(1, (int) $result->max_num_pages) : 1;
                unset($result);
                $orders = [];
                foreach ($rows as $row) {
                    $row_id = is_object($row) && method_exists($row, 'get_id') ? (int) $row->get_id() : (is_numeric($row) ? (int) $row : 0);
                    if ($row_id <= 0 || isset($seen[$row_id])) { continue; }
                    $orders[] = is_object($row) ? $row : (function_exists('wc_get_order') ? wc_get_order($row_id) : null);
                }
                unset($rows, $row);
                $this->load_item_flag_hint(array_map(static function ($order) {
                    return is_object($order) && method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
                }, $orders));
                foreach ($orders as $order) {
                    if (!is_object($order) || !method_exists($order, 'get_id')) { continue; }
                    $id = (int) $order->get_id();
                    if (isset($seen[$id])) { continue; }
                    if (++$scanned > $cap) { throw new RuntimeException('The report reached its order scan safety cap. Narrow the date range.'); }
                    $seen[$id] = true;
                    $identity = $this->describe($order);
                    if ($identity['managed']) {
                        if ($identity['uncaptured']) { continue; }
                        if (!$identity['paid_at']) {
                            // Ordinary unpaid/failed installments are not receipts; an alleged paid receipt without a date cannot be assigned safely.
                            if ((method_exists($order, 'is_paid') && $order->is_paid())
                                || $this->capture_confirmed($order) === true
                                || (method_exists($order, 'get_status') && $order->get_status() === 'fppc-paid-manual')) {
                                throw new RuntimeException('Split Payment receipt ' . $id . ' needs review: a confirmed payment date is unavailable.');
                            }
                            continue;
                        }
                        if ($identity['paid_at'] < $from || $identity['paid_at'] > $to) { continue; }
                        $transaction = method_exists($order, 'get_transaction_id') ? (string) $order->get_transaction_id() : '';
                        if ($transaction !== '' && method_exists($order, 'get_payment_method')) {
                            $ref = $order->get_payment_method() . '|' . $transaction;
                            if (isset($capture_refs[$ref])) { $identity['issues'][] = 'split_payment_duplicate_transaction_reference'; }
                            $capture_refs[$ref] = true;
                        }
                    } else {
                        if ($date_field !== 'date_created') { continue; }
                        if (method_exists($order, 'get_status') && !in_array($order->get_status(), $statuses, true)) { continue; }
                    }
                    yield ['order' => $order, 'identity' => $identity];
                }
                unset($orders, $order);
                $this->item_flag_hint = null;
                Tax_Report_Memory::release_page();
                $page++;
            } while ($page <= $pages);
        }
    }

    /**
     * Whether describe() still has to read an order's line items.
     *
     * Without a page hint, or for an order the hint does not cover, the answer
     * is always yes, so describe() keeps its full check.
     */
    private function may_have_managed_items(int $order_id): bool
    {
        return !is_array($this->item_flag_hint)
            || !array_key_exists($order_id, $this->item_flag_hint)
            || $this->item_flag_hint[$order_id];
    }

    /**
     * One query per page: which orders have any Split Payment line-item meta.
     *
     * An order with no such row cannot be flagged by its line items, so its
     * items need not be loaded just to learn that. Only stored values that read
     * back as empty or false are excluded; any order that might be flagged is
     * still checked item by item. On a database error no hint is used.
     *
     * @param int[] $order_ids
     */
    private function load_item_flag_hint(array $order_ids): void
    {
        global $wpdb;
        $this->item_flag_hint = null;
        $order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids))));
        if (empty($order_ids) || !isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'prepare')) {
            return;
        }
        // A plugin filtering these values can make get_meta() differ from the database.
        if (function_exists('has_filter')
            && (has_filter('woocommerce_order_item_get__fppc_managed_plan') || has_filter('woocommerce_order_item_get__fppc_plan_snapshot_v2'))) {
            return;
        }

        $falsy_values = ['', '0', 'a:0:{}', 'b:0;', 'i:0;', 'd:0;', 'N;', 's:0:"";'];
        $sql = 'SELECT DISTINCT items.order_id FROM ' . $wpdb->prefix . 'woocommerce_order_items AS items'
            . ' INNER JOIN ' . $wpdb->prefix . 'woocommerce_order_itemmeta AS meta ON meta.order_item_id = items.order_item_id'
            . ' WHERE items.order_id IN (' . implode(',', array_fill(0, count($order_ids), '%d')) . ')'
            . " AND items.order_item_type = 'line_item'"
            . ' AND meta.meta_key IN (%s, %s)'
            // Byte comparison: the column's usual collation ignores case and
            // trailing spaces, which would exclude values PHP reads as true.
            . ' AND CAST(meta.meta_value AS BINARY) NOT IN (' . implode(',', array_fill(0, count($falsy_values), '%s')) . ')';
        $prepared = $wpdb->prepare($sql, array_merge($order_ids, ['_fppc_managed_plan', '_fppc_plan_snapshot_v2'], $falsy_values));
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
        $flagged = is_string($prepared) && $prepared !== '' ? $wpdb->get_col($prepared) : null;
        if (!is_array($flagged) || (string) $wpdb->last_error !== '') {
            return;
        }

        $this->item_flag_hint = array_fill_keys($order_ids, false);
        foreach ($flagged as $flagged_id) {
            $this->item_flag_hint[(int) $flagged_id] = true;
        }
    }
}
