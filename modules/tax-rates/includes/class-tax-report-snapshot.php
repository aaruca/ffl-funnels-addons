<?php
/**
 * Permanent fiscal snapshots for WooCommerce orders.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Report_Snapshot
{
    const CURRENT_META = '_ffla_tax_report_snapshot';
    const HASH_META = '_ffla_tax_report_snapshot_hash';
    const REVISION_META = '_ffla_tax_report_snapshot_revision';

    /** @var array<int,bool> */
    private static $capturing = [];

    public static function init(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_checkout_order_created', [__CLASS__, 'capture'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'capture'], 20, 1);
        add_action('woocommerce_payment_complete', [__CLASS__, 'capture'], 20, 1);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'capture_status_change'], 20, 4);
        add_action('woocommerce_update_order', [__CLASS__, 'capture_order_update'], 100, 2);
        add_action('woocommerce_order_refunded', [__CLASS__, 'capture_refund'], 20, 2);
        add_action('woocommerce_refund_created', [__CLASS__, 'capture_refund_created'], 20, 2);
    }

    /**
     * Capture a new revision only when fiscal content actually changed.
     *
     * @param WC_Order|int $order Order object or ID.
     */
    public static function capture($order): void
    {
        if (is_numeric($order)) {
            $order = wc_get_order((int) $order);
        }
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $order_id = (int) $order->get_id();
        if ($order_id <= 0 || isset(self::$capturing[$order_id])) {
            return;
        }

        self::$capturing[$order_id] = true;
        try {
            $service = new Tax_Report_Service();
            $snapshot = $service->build_fiscal_snapshot($order);
            if (empty($snapshot)) {
                return;
            }

            $canonical = wp_json_encode($snapshot, JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', (string) $canonical);
            if (hash_equals((string) $order->get_meta(self::HASH_META, true), $hash)) {
                return;
            }

            $revision = [
                'captured_at_utc' => gmdate('c'),
                'trigger'         => function_exists('current_filter') ? (string) current_filter() : '',
                'hash'            => $hash,
                'snapshot'        => $snapshot,
            ];
            $encoded = wp_json_encode($revision, JSON_UNESCAPED_SLASHES);

            $order->update_meta_data(self::CURRENT_META, $encoded);
            $order->update_meta_data(self::HASH_META, $hash);
            $order->add_meta_data(self::REVISION_META, $encoded, false);
            $order->save_meta_data();
        } catch (Throwable $e) {
            if (function_exists('ffla_tax_log')) {
                ffla_tax_log('error', 'Tax report snapshot failed', [
                    'orderId' => $order_id,
                    'message' => $e->getMessage(),
                ]);
            }
        } finally {
            unset(self::$capturing[$order_id]);
        }
    }

    public static function capture_status_change($order_id, $from, $to, $order): void
    {
        self::capture($order ?: $order_id);
    }

    public static function capture_order_update($order_id, $order = null): void
    {
        self::capture($order ?: $order_id);
    }

    public static function capture_refund($order_id, $refund_id): void
    {
        self::capture($order_id);
    }

    public static function capture_refund_created($refund_id, $args): void
    {
        $refund = wc_get_order((int) $refund_id);
        if (is_a($refund, 'WC_Order_Refund')) {
            self::capture((int) $refund->get_parent_id());
            return;
        }
        if (is_array($args) && !empty($args['order_id'])) {
            self::capture((int) $args['order_id']);
        }
    }
}
