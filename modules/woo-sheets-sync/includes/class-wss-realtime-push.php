<?php
/**
 * WSS Real-time Push — Immediately mirror Woo stock changes to the Sheet.
 *
 * When an order reduces stock (or a refund/cancel restores it), the nightly
 * sync alone would leave the Sheet showing the OLD quantity until 02:00 — and
 * because the Sheet used to win on any difference, the nightly run could then
 * revert Woo's reduced stock back up, silently undoing the sale. This pushes
 * the new Woo quantity to the Sheet right away so the two stay in lock-step,
 * and updates the agreed snapshots so the change-aware nightly sync treats the
 * push as "already reconciled".
 *
 * CHECKOUT SAFETY: the reduce hook fires during checkout. This class never
 * throws — every entry point is wrapped in try/catch and the single batched
 * Sheets write is deferred to 'shutdown' so a slow API can't delay the
 * customer. Any failure is logged and left for the nightly sync to recover.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Realtime_Push
{
    /**
     * Collected changes for this request: variation_id => [qty, status, manage].
     *
     * @var array<int,array{qty:?int,status:string,manage:bool}>
     */
    private static $pending = [];

    /** @var bool */
    private static $hooked = false;

    /** @var bool */
    private static $flush_registered = false;

    /**
     * Register the stock-change hooks.
     */
    public static function init(): void
    {
        if (self::$hooked) {
            return;
        }
        self::$hooked = true;

        // Order placed → stock reduced. Order cancelled → stock restored.
        add_action('woocommerce_reduce_order_stock', [__CLASS__, 'on_order_stock_change'], 20, 1);
        add_action('woocommerce_restore_order_stock', [__CLASS__, 'on_order_stock_change'], 20, 1);

        // Refund with "restock refunded items" → per-item stock restored.
        add_action('woocommerce_restock_refunded_item', [__CLASS__, 'on_refunded_item'], 20, 5);
    }

    /**
     * Whether real-time push is enabled and a sheet is configured.
     */
    private static function enabled(): bool
    {
        $settings = get_option('wss_settings', []);
        if (!is_array($settings) || empty($settings['sheet_id'])) {
            return false;
        }

        // Default ON when the key has never been saved.
        return array_key_exists('realtime_push', $settings)
            ? (bool) $settings['realtime_push']
            : true;
    }

    /**
     * Collect affected variations from an order (reduce or restore).
     *
     * @param WC_Order|int $order
     */
    public static function on_order_stock_change($order): void
    {
        try {
            if (!self::ready()) {
                return;
            }

            if (!$order instanceof WC_Order) {
                $order = wc_get_order($order);
            }
            if (!$order) {
                return;
            }

            foreach ($order->get_items() as $item) {
                if (!$item instanceof WC_Order_Item_Product) {
                    continue;
                }
                $pid = $item->get_variation_id() ?: $item->get_product_id();
                self::collect((int) $pid);
            }

            self::schedule_flush();
        } catch (\Throwable $e) {
            self::log_error('order stock collect failed: ' . $e->getMessage());
        }
    }

    /**
     * Collect a single product whose stock was restored by a refund.
     *
     * @param int $product_id
     */
    public static function on_refunded_item($product_id, $old_stock = null, $new_stock = null, $order = null, $refund = null): void
    {
        try {
            if (!self::ready()) {
                return;
            }
            self::collect((int) $product_id);
            self::schedule_flush();
        } catch (\Throwable $e) {
            self::log_error('refund stock collect failed: ' . $e->getMessage());
        }
    }

    /**
     * Enabled, not in the middle of our own Sheet→Woo apply, and WC present.
     */
    private static function ready(): bool
    {
        if (!self::enabled()) {
            return false;
        }
        // Feedback-loop guard: ignore stock writes we are making ourselves.
        if (class_exists('WSS_Sync_Engine') && WSS_Sync_Engine::is_applying()) {
            return false;
        }
        return function_exists('wc_get_product');
    }

    /**
     * Record a product/variation's new stock if it's in sync scope.
     */
    private static function collect(int $product_id): void
    {
        if ($product_id <= 0) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        // Scope: the parent (or the product itself, for simple) must be enabled
        // for sync. _wss_sync_enabled is kept in lock-step with the sheet tab
        // groups by WSS_Sync_Groups, so it is the canonical scope signal.
        $parent_id = (int) $product->get_parent_id();
        $scope_id  = $parent_id > 0 ? $parent_id : $product_id;
        if (get_post_meta($scope_id, '_wss_sync_enabled', true) !== '1') {
            return;
        }

        $manage = (bool) $product->get_manage_stock();

        self::$pending[$product_id] = [
            'qty'    => $manage ? (int) $product->get_stock_quantity() : null,
            'status' => (string) $product->get_stock_status(),
            'manage' => $manage,
        ];
    }

    /**
     * Register the deferred flush exactly once per request.
     */
    private static function schedule_flush(): void
    {
        if (self::$flush_registered) {
            return;
        }
        self::$flush_registered = true;
        add_action('shutdown', [__CLASS__, 'flush'], 20);
    }

    /**
     * Flush all collected changes to the Sheet in a single batched write.
     *
     * Runs on 'shutdown' so the customer-facing request isn't blocked by the
     * Sheets API. Verifies each row's variation_id (column B) before writing
     * because row numbers can drift; on any mismatch it skips that row and
     * lets the nightly change-aware sync reconcile.
     */
    public static function flush(): void
    {
        if (empty(self::$pending)) {
            return;
        }

        $pending       = self::$pending;
        self::$pending = [];

        try {
            if (!self::enabled()) {
                return;
            }
            if (class_exists('WSS_Sync_Engine') && WSS_Sync_Engine::is_applying()) {
                return;
            }
            if (!class_exists('WSS_Auth') || !class_exists('WSS_Google_Sheets')) {
                return;
            }

            $settings = get_option('wss_settings', []);
            $sheet_id = (string) ($settings['sheet_id'] ?? '');
            if ($sheet_id === '') {
                return;
            }

            $row_map = get_option('wss_row_map', []);
            if (!is_array($row_map) || empty($row_map)) {
                // No map yet — the nightly/manual sync builds it. Nothing to do.
                return;
            }

            $provider = WSS_Auth::get_provider();
            $sheets   = new WSS_Google_Sheets($provider);

            $writes  = [];
            $written = [];

            foreach ($pending as $vid => $data) {
                $vid   = (int) $vid;
                $entry = $row_map[$vid] ?? null;
                if (!is_array($entry)) {
                    continue; // not mapped — let nightly reconcile
                }

                $tab = (string) ($entry['tab'] ?? '');
                $row = (int) ($entry['row'] ?? 0);
                if ($tab === '' || $row < 2) {
                    continue;
                }

                $safe_tab = str_replace("'", "''", $tab);

                // Verify the variation_id (column B) still sits at this row.
                $bcell = $sheets->read_range($sheet_id, "'" . $safe_tab . "'!B" . $row);
                if (is_wp_error($bcell)) {
                    continue;
                }
                $bval = isset($bcell[0][0]) ? (int) $bcell[0][0] : 0;
                if ($bval !== $vid) {
                    continue; // row drifted — skip, nightly reconciles
                }

                $qty_str = ($data['manage'] && $data['qty'] !== null) ? (string) $data['qty'] : '';
                $writes[] = [
                    'range'  => "'" . $safe_tab . "'!H" . $row . ':I' . $row, // stock_qty, stock_status
                    'values' => [[$qty_str, (string) $data['status']]],
                ];
                $written[$vid] = $data;
            }

            if (empty($writes)) {
                return;
            }

            $result = $sheets->batch_update($sheet_id, $writes);
            if (is_wp_error($result)) {
                // Leave snapshots untouched so the nightly sync detects the Woo
                // move and recovers the push.
                self::log_error('batch write failed: ' . $result->get_error_message());
                return;
            }

            // Persist the agreed snapshots only after a successful write so both
            // sides are recorded at the new quantity.
            foreach ($written as $vid => $data) {
                if ($data['manage'] && $data['qty'] !== null) {
                    update_post_meta((int) $vid, WSS_Sync_Engine::META_SNAP_WOO, (int) $data['qty']);
                    update_post_meta((int) $vid, WSS_Sync_Engine::META_SNAP_SHEET, (int) $data['qty']);
                }
            }
        } catch (\Throwable $e) {
            self::log_error('flush failed: ' . $e->getMessage());
        }
    }

    /**
     * Log a non-fatal failure without ever throwing.
     */
    private static function log_error(string $message): void
    {
        try {
            if (class_exists('WSS_Logger')) {
                (new WSS_Logger())->log('woo_to_sheet', 0, 0, 'error', 'Real-time push: ' . $message);
            }
        } catch (\Throwable $e) {
            // Swallow — logging must never break checkout.
        }
    }
}
