<?php
/** Read-only Split Payment receipt/underlying-sale adapter. No gateway or order writes. */
defined('ABSPATH') || exit;

class Tax_Report_Sale_Identity
{
    const PLAN_STATUSES = ['fppc-plan-hold', 'fppc-paid-manual', 'fppc-defaulted', 'fppc-denied', 'pending', 'failed', 'cancelled'];
    public $pages_processed = 0;

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
        if (!$managed && method_exists($order, 'get_items')) {
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

    /** Two bounded, HPOS-compatible queries; ordinary orders keep their existing date/status basis. */
    public function orders(array $statuses, int $from, int $to, int $page_size, int $cap): Generator
    {
        $seen = [];
        $scanned = 0;
        $capture_refs = [];
        $this->pages_processed = 0;
        foreach (['date_created', 'date_paid'] as $date_field) {
            $page = 1;
            do {
                $query = ['type' => 'shop_order', 'status' => array_values(array_unique(array_merge($statuses, self::PLAN_STATUSES))),
                    $date_field => $from . '...' . $to, 'orderby' => 'date', 'order' => 'ASC',
                    'limit' => $page_size, 'page' => $page, 'paginate' => true, 'return' => 'objects'];
                $result = wc_get_orders($query);
                $this->pages_processed++;
                $orders = is_object($result) && isset($result->orders) ? $result->orders : (is_array($result) ? $result : []);
                $pages = is_object($result) && isset($result->max_num_pages) ? max(1, (int) $result->max_num_pages) : 1;
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
                $page++;
            } while ($page <= $pages);
        }
    }
}
