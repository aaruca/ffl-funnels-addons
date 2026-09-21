<?php
/** Order-scoped operations. No checkout, payment, stock, tax or carrier writes. */
defined('ABSPATH') || exit;

class FFLA_Customer_Operations
{
    const STATUS = 'ffla-ready';
    const DATA = '_ffla_ops';
    const ITEM = '_ffla_ops_item';

    public static function lifecycle(): void
    {
        // Keep historical status readable even when this module / switch is disabled.
        add_action('init', [__CLASS__, 'register_status']);
        add_filter('wc_order_statuses', [__CLASS__, 'statuses']);
        add_action('woocommerce_before_order_object_save', [__CLASS__, 'guard_status'], 90);
        add_filter('woocommerce_order_is_paid', [__CLASS__, 'preserve_paid'], 20, 2);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'status_changed'], 30, 4);
    }

    public static function register_status(): void
    {
        register_post_status('wc-' . self::STATUS, ['label'=>__('Ready for Pickup', 'ffl-funnels-addons'), 'public'=>true,
            'exclude_from_search'=>false, 'show_in_admin_all_list'=>true, 'show_in_admin_status_list'=>true,
            'label_count'=>_n_noop('Ready for Pickup <span class="count">(%s)</span>', 'Ready for Pickup <span class="count">(%s)</span>', 'ffl-funnels-addons')]);
    }

    public static function statuses(array $statuses): array
    {
        if (FFLA_Customer_Operations_Settings::enabled('pickup') || get_option('_ffla_ops_ready_used', false)) {
            $statuses['wc-' . self::STATUS] = __('Ready for Pickup', 'ffl-funnels-addons');
        }
        return $statuses;
    }

    public static function staff($order): bool
    {
        return $order instanceof WC_Order && $order->get_type() === 'shop_order' && current_user_can('manage_woocommerce') && current_user_can('edit_shop_order', $order->get_id());
    }

    public static function owner($order): bool
    {
        return $order instanceof WC_Order && $order->get_type() === 'shop_order' && get_current_user_id() > 0
            && (int) $order->get_customer_id() === get_current_user_id();
    }

    public static function data($order): array
    {
        $data = $order->get_meta(self::DATA, true);
        return array_replace(['revision'=>'', 'checklist'=>[], 'ready_since'=>0, 'ready_cycle'=>'', 'public'=>[], 'files'=>[], 'collected_at'=>0], is_array($data) ? $data : []);
    }

    public static function item($item): array
    {
        $data = $item->get_meta(self::ITEM, true);
        return array_replace(['serials'=>[], 'manufacturer'=>'', 'model'=>'', 'caliber'=>'', 'collected'=>0], is_array($data) ? $data : []);
    }

    public static function firearm($item): bool
    {
        if ($item->get_meta('_ffla_ops_firearm') === 'yes') { return true; }
        $product = $item->get_product();
        if (!$product) { return false; }
        if (function_exists('product_is_firearm') && product_is_firearm($product)) { return true; }
        if (strtolower((string) $product->get_meta('_firearm_product')) === 'yes') { return true; }
        $parent = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : null;
        return $parent && strtolower((string) $parent->get_meta('_firearm_product')) === 'yes';
    }

    public static function pickup($order): bool
    {
        $methods = $order->get_shipping_methods();
        if (!$methods) { return false; }
        foreach ($methods as $method) {
            $saved = $method->get_meta('_ffla_delivery');
            if ($method->get_method_id() !== 'local_pickup' && (!is_array($saved) || ($saved['mode'] ?? '') !== 'pickup')) { return false; }
        }
        return true;
    }

    public static function paid($order): bool
    {
        if (!$order->get_date_paid() || in_array($order->get_status(), ['pending','failed','cancelled','refunded','on-hold'], true)) { return false; }
        if ($order->get_payment_method() === 'anet') {
            if (is_callable(['FPPC_Anet_Official','capture_confirmed'])) { return FPPC_Anet_Official::capture_confirmed($order); }
            return $order->get_meta('_anet_credit_card_charge_captured') === 'yes' && (string) $order->get_meta('_anet_credit_card_charge_id') !== '' && (string) $order->get_meta('_anet_credit_card_charge_id') !== '0';
        }
        return true;
    }

    public static function preserve_paid($paid, $order): bool
    {
        return $order->get_status() === self::STATUS ? self::paid($order) : (bool) $paid;
    }

    public static function ready_error($order): string
    {
        if (!FFLA_Customer_Operations_Settings::enabled('pickup')) { return 'Ready for Pickup is disabled. Existing records remain available.'; }
        if (!in_array($order->get_status(), ['processing', self::STATUS], true)) { return 'Only processing orders can be marked ready. Resolve the existing workflow first.'; }
        if (!self::pickup($order)) { return 'Every shipping package must already be local pickup. Mixed shipping / pickup orders require separate fulfillment.'; }
        if (!self::paid($order)) { return 'A recorded, confirmed payment is required. This action cannot collect payment.'; }
        $managed = $order->get_meta('_fppc_managed_plan') === 'yes' || $order->get_meta('_fppc_plan_principals') || $order->get_meta('_fppc_plan_subscription_id');
        $remaining = 0;
        foreach ($order->get_items() as $id=>$item) {
            $managed = $managed || $item->get_meta('_fppc_plan_snapshot_v2');
            $remaining += max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($id)));
        }
        if (!$remaining) { return 'No unrefunded units remain on this order.'; }
        if ($managed && (!is_callable(['FPPC_Hold','plan_is_settled']) || !FPPC_Hold::plan_is_settled($order)
            || !is_callable(['FPPC_Procurement','customer_fulfillment_gate_satisfied']) || !FPPC_Procurement::customer_fulfillment_gate_satisfied($order))) {
            return 'Split Payment has not confirmed settlement and fulfillment eligibility. Resolve it in Split Payment first.';
        }
        if (FFLA_Customer_Operations_Settings::enabled('require_serials')) {
            foreach ($order->get_items() as $id=>$item) {
                $required = max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($id)));
                if (self::firearm($item) && count(self::item($item)['serials']) < $required) { return 'Every unrefunded firearm unit needs a serial number before readiness or collection.'; }
            }
        }
        return '';
    }

    public static function guard_status($order): void
    {
        if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') { return; }
        $changes = $order->get_changes();
        if (($changes['status'] ?? '') === self::STATUS) {
            $stored = $order->get_id() ? wc_get_order($order->get_id()) : null;
            if (!$stored || !in_array($stored->get_status(), ['processing', self::STATUS], true)) { throw new RuntimeException('Only an existing processing order can become Ready for Pickup.'); }
            $error = self::ready_error($order);
            if ($error !== '') { throw new Exception($error); }
        }
    }

    public static function status_changed($id, $from, $to, $order): void
    {
        if ($from === $to || ($from !== self::STATUS && $to !== self::STATUS)) { return; }
        $data = self::data($order);
        if ($to === self::STATUS) {
            update_option('_ffla_ops_ready_used', true, false);
            $data['ready_since'] = time(); $data['ready_cycle'] = wp_generate_uuid4(); $data['collected_at'] = 0;
            $data['pickup_location'] = array_intersect_key(FFLA_Customer_Operations_Settings::get(), array_flip(['store_name','store_address','store_hours','store_instructions']));
        } else { $data['ready_cycle'] = ''; }
        $data['revision'] = wp_generate_uuid4();
        $order->update_meta_data(self::DATA, $data); $order->save_meta_data();
        self::audit($order, 'Pickup status changed from ' . $from . ' to ' . $to . '.');
        if ($to === self::STATUS && class_exists('FFLA_Customer_Operations_Messages')) { FFLA_Customer_Operations_Messages::queue_ready($order, $data['ready_cycle']); }
    }

    public static function audit($order, string $text): void
    {
        $actor = get_current_user_id() ? 'staff #' . get_current_user_id() : 'system';
        $order->add_order_note('[Order management / ' . $actor . '] ' . sanitize_textarea_field($text), false, (bool) get_current_user_id());
    }

    /** Atomic per-order lease. Stale lease recovery compares its exact value. */
    public static function locked(int $id, callable $callback)
    {
        global $wpdb;
        $key = '_ffla_ops_lock_' . $id; $lease = (time() + 120) . ':' . wp_generate_uuid4();
        $old = get_option($key, '');
        if (is_string($old) && $old !== '' && (int) $old < time()) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $old));
            wp_cache_delete($key, 'options');
        }
        if (!add_option($key, $lease, '', false)) { throw new RuntimeException('This order is being updated. Wait and reload before trying again.'); }
        try { return $callback(wc_get_order($id)); }
        finally {
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $lease));
            wp_cache_delete($key, 'options');
        }
    }

    public static function case_data($order): array
    {
        return ['state'=>(string) $order->get_meta('_ffla_ops_case'), 'reason'=>(string) $order->get_meta('_ffla_ops_reason'),
            'priority'=>(string) $order->get_meta('_ffla_ops_priority'), 'assignee'=>(int) $order->get_meta('_ffla_ops_assignee'),
            'due'=>(int) $order->get_meta('_ffla_ops_due'), 'related'=>(string) $order->get_meta('_ffla_ops_related')];
    }

    public static function save_case($order, array $input): void
    {
        $old = self::case_data($order);
        $new = [];
        foreach (['state'=>['open','in_progress','waiting_customer','waiting_carrier','resolved'],
            'reason'=>['lost_shipment','shortage','return','refund','replacement','other'], 'priority'=>['low','normal','high','urgent']] as $key=>$values) {
            $value = sanitize_key($input[$key] ?? '');
            if ($value !== '' && !in_array($value, $values, true)) { throw new InvalidArgumentException('Invalid case ' . $key); }
            $new[$key] = $value;
        }
        $new['assignee'] = absint($input['assignee'] ?? 0);
        $user = $new['assignee'] ? get_user_by('id', $new['assignee']) : null;
        if ($new['assignee'] && (!$user || !user_can($user, 'manage_woocommerce') || !user_can($user, 'edit_shop_order', $order->get_id()))) { throw new InvalidArgumentException('The case owner must have permission to manage this WooCommerce order.'); }
        $due = (string) ($input['due'] ?? '');
        $date = $due !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $due, wp_timezone()) : false;
        if ($due !== '' && (!$date || $date->format('Y-m-d\TH:i') !== $due)) { throw new InvalidArgumentException('Use a valid follow-up date and time.'); }
        $new['due'] = $date ? $date->getTimestamp() : 0;
        $new['related'] = substr(sanitize_text_field($input['related'] ?? ''), 0, 500);
        foreach ($new as $key=>$value) { $order->update_meta_data('_ffla_ops_' . ($key === 'state' ? 'case' : $key), $value); }
        if ($new !== $old) { self::audit($order, 'Case updated: ' . wp_json_encode(['before'=>$old, 'after'=>$new])); }
        if ($new['state'] === 'resolved' && $old['state'] !== 'resolved') { $order->update_meta_data('_ffla_ops_resolved_at', time()); }
        if (!empty($input['note'])) { self::audit($order, 'Case note: ' . substr(sanitize_textarea_field($input['note']), 0, 5000)); }
        if ($new['due'] && $new['state'] !== 'resolved' && class_exists('FFLA_Customer_Operations_Messages')) { FFLA_Customer_Operations_Messages::queue_case($order->get_id(), $new); }
    }

    /** Validate every line before persisting any item; retain serial punctuation/case. */
    public static function validate_items($order, array $input): array
    {
        $changes = []; $seen = [];
        foreach ($order->get_items() as $id=>$item) {
            $old = self::item($item); $next = $old; $fields = isset($input[$id]) && is_array($input[$id]) ? $input[$id] : [];
            if (self::firearm($item) && FFLA_Customer_Operations_Settings::enabled('serials') && array_key_exists('serials', $fields)) {
                $next['serials'] = array_values(array_filter(array_map('trim', preg_split('/\R/', sanitize_textarea_field($fields['serials']))), 'strlen'));
                if (count($next['serials']) > (int) $item->get_quantity() || count($next['serials']) > 200) { throw new InvalidArgumentException('Too many serials on item #' . $id . '. Enter one per unit.'); }
                foreach ($next['serials'] as $serial) { if (strlen($serial) > 100) { throw new InvalidArgumentException('Serial numbers must be 100 characters or less.'); } }
            }
            foreach ($next['serials'] as $serial) {
                $canonical = strtoupper(trim($serial));
                if (isset($seen[$canonical])) { throw new InvalidArgumentException('Duplicate serial number within this order: ' . $serial); }
                $seen[$canonical] = true;
            }
            if (self::firearm($item) && FFLA_Customer_Operations_Settings::enabled('item_details')) {
                foreach (['manufacturer','model','caliber'] as $key) { if (isset($fields[$key])) { $next[$key] = substr(sanitize_text_field($fields[$key]), 0, 150); } }
            }
            if (FFLA_Customer_Operations_Settings::enabled('partial_pickup') && isset($fields['collected'])) {
                $amount = filter_var($fields['collected'], FILTER_VALIDATE_INT);
                $available = max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($id)));
                if ($amount === false || $amount < (int) $old['collected'] || ($amount > (int) $old['collected'] && $amount > $available)) { throw new InvalidArgumentException('Collected units must not decrease or exceed unrefunded ordered units.'); }
                if ($amount > (int) $old['collected'] && ($order->get_status() !== self::STATUS || self::ready_error($order) !== '')) { throw new InvalidArgumentException('Mark a fully validated local-pickup order ready before recording collection.'); }
                $next['collected'] = $amount;
            }
            if ($next !== $old) { $changes[$id] = $next; }
        }
        if ($order->get_status() === self::STATUS && FFLA_Customer_Operations_Settings::enabled('require_serials')) {
            foreach ($order->get_items() as $id=>$item) {
                $next = $changes[$id] ?? self::item($item);
                $required = max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($id)));
                if (self::firearm($item) && count($next['serials']) < $required) { throw new InvalidArgumentException('A ready order must retain a serial for every unrefunded firearm unit. Move it back to processing before removing required serials.'); }
            }
        }
        return $changes;
    }

    public static function save($order, array $input): void
    {
        if (!self::staff($order)) { throw new RuntimeException('You cannot edit this order.'); }
        $data = self::data($order);
        if (!hash_equals((string) $data['revision'], (string) ($input['revision'] ?? ''))) { throw new RuntimeException('Order management changed in another session. Reload before saving.'); }
        $changes = self::validate_items($order, (array) ($input['items'] ?? []));
        if (FFLA_Customer_Operations_Settings::enabled('followup') && isset($input['case'])) { self::save_case($order, (array) $input['case']); }
        if (FFLA_Customer_Operations_Settings::enabled('checklist')) {
            $checklist = array_values(array_intersect(['items_checked','serials_checked','documents_ready'], (array) ($input['checklist'] ?? [])));
            if ($data['checklist'] !== $checklist) { self::audit($order, 'Preparation checklist: ' . implode(', ', $checklist)); }
            $data['checklist'] = $checklist;
        }
        foreach ($changes as $id=>$next) {
            $item = $order->get_item($id); $before = self::item($item);
            $item->update_meta_data(self::ITEM, $next);
            if (self::firearm($item)) { $item->update_meta_data('_ffla_ops_firearm', 'yes'); }
            $item->save(); self::audit($order, 'Item #' . $id . ' updated: ' . wp_json_encode(['before'=>$before,'after'=>$next]));
        }
        $data['revision'] = wp_generate_uuid4(); $order->update_meta_data(self::DATA, $data); $order->save();
    }

    public static function collect($order): void
    {
        if (!self::staff($order) || $order->get_status() !== self::STATUS) { throw new RuntimeException('Only staff may record collection of a ready order.'); }
        $error = self::ready_error($order); if ($error !== '') { throw new RuntimeException($error); }
        if (!$order->update_status('completed', 'Collection confirmed by staff #' . get_current_user_id() . '.', true)
            || wc_get_order($order->get_id())->get_status() !== 'completed') {
            throw new RuntimeException('Another fulfillment rule prevented completion. Collection was not recorded.');
        }
        foreach ($order->get_items() as $id=>$item) {
            $data = self::item($item);
            $data['collected'] = max((int) $data['collected'], max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($id))));
            $item->update_meta_data(self::ITEM, $data); $item->save();
        }
        $data = self::data($order); $data['collected_at'] = time(); $data['collected_by'] = get_current_user_id(); $data['revision'] = wp_generate_uuid4();
        $order->update_meta_data(self::DATA, $data); $order->save_meta_data();
        self::audit($order, 'All remaining units recorded as collected.');
    }
}
