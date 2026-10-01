<?php
/**
 * Customer requests — refunds issued from a request.
 *
 * Uses WooCommerce's own wc_create_refund(): the refund appears on the order
 * like any other, optionally goes back through the payment gateway (when the
 * gateway supports refunds) and optionally restocks the items. The restocking
 * fee is kept by refunding (100 − fee)% of each line's price and tax.
 *
 * Staff must confirm every refund; a per-form token stops a double submit
 * from refunding twice.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Refunds
{
    /**
     * Order lines for the refund form, prefilled with the request's items.
     *
     * @return array<int, array>
     */
    public static function lines($request, $order): array
    {
        $requested = [];
        foreach (FFLA_Requests::items($request) as $line) {
            $requested[(int) $line['item_id']] = $line;
        }

        $out = [];
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $ordered = (int) $item->get_quantity();
            if ($ordered < 1) {
                continue;
            }
            $max = max(0, $ordered - abs((int) $order->get_qty_refunded_for_item($item_id)));
            $unit = (float) $item->get_total() / $ordered;
            $taxes = [];
            $tax_data = $item->get_taxes();
            foreach ((array) ($tax_data['total'] ?? []) as $tax_id => $amount) {
                if ('' !== $amount && null !== $amount) {
                    $taxes[$tax_id] = (float) $amount / $ordered;
                }
            }
            $in_request = isset($requested[(int) $item_id]);
            $out[(int) $item_id] = [
                'item_id'    => (int) $item_id,
                'name'       => $item->get_name(),
                'ordered'    => $ordered,
                'max'        => $max,
                'qty'        => $in_request ? min($max, (int) $requested[(int) $item_id]['qty']) : 0,
                'unit'       => $unit,
                'unit_taxes' => $taxes,
                'unit_gross' => $unit + array_sum($taxes),
                'fee'        => $in_request ? (float) ($requested[(int) $item_id]['fee'] ?? 0) : 0.0,
                'in_request' => $in_request,
            ];
        }
        return $out;
    }

    /** Restocking fee to prefill: the highest fee among the requested lines. */
    public static function suggested_fee($request): float
    {
        $fees = array_map(static function ($l) { return (float) ($l['fee'] ?? 0); }, FFLA_Requests::items($request));
        return $fees ? max($fees) : 0.0;
    }

    public static function gateway_refunds($order): bool
    {
        $gateway = function_exists('wc_get_payment_gateway_by_order') ? wc_get_payment_gateway_by_order($order) : null;
        return $gateway && $gateway->supports('refunds') && (!method_exists($gateway, 'can_refund_order') || $gateway->can_refund_order($order));
    }

    public static function money(float $amount, $order): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($amount, ['currency' => $order->get_currency()])), ENT_QUOTES, 'UTF-8');
    }

    /** Whether the refund form with this token was already processed. */
    public static function already_done($request, string $intent): bool
    {
        if ('' === $intent) {
            return false;
        }
        foreach (FFLA_Requests::events((int) $request->id) as $event) {
            if ('refund' === $event->kind) {
                $meta = json_decode((string) $event->meta, true);
                if (is_array($meta) && ($meta['intent'] ?? '') === $intent) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Issue the refund.
     *
     * @param array $input qty (item_id => qty), fee (%), extra (amount), restock (bool), method (gateway|manual), intent
     * @return array{amount: float, fee: float, refund_id: int, resolution: string, text: string}
     * @throws InvalidArgumentException|RuntimeException
     */
    public static function issue($request, $order, array $input, array $actor): array
    {
        $dp = wc_get_price_decimals();
        $fee_pct = max(0.0, min(100.0, (float) ($input['fee'] ?? 0)));
        $keep = 1 - $fee_pct / 100;
        $lines = self::lines($request, $order);

        $line_items = [];
        $amount = 0.0;
        $fee_total = 0.0;
        foreach ((array) ($input['qty'] ?? []) as $item_id => $qty) {
            $item_id = absint($item_id);
            $qty = absint($qty);
            if (!$qty || !isset($lines[$item_id])) {
                continue;
            }
            $line = $lines[$item_id];
            if ($qty > $line['max']) {
                /* translators: %s: product name */
                throw new InvalidArgumentException(sprintf(__('Too many units of %s: some were already refunded.', 'ffl-funnels-addons'), $line['name']));
            }
            $total = round($line['unit'] * $qty * $keep, $dp);
            $taxes = [];
            foreach ($line['unit_taxes'] as $tax_id => $unit_tax) {
                $taxes[$tax_id] = round($unit_tax * $qty * $keep, $dp);
            }
            $line_items[$item_id] = ['qty' => $qty, 'refund_total' => $total, 'refund_tax' => $taxes];
            $amount += $total + array_sum($taxes);
            $fee_total += $line['unit_gross'] * $qty - ($total + array_sum($taxes));
        }

        $extra = round(max(0.0, (float) ($input['extra'] ?? 0)), $dp);
        $amount = round($amount + $extra, $dp);
        $fee_total = round(max(0.0, $fee_total), $dp);
        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Enter the quantities or an amount to refund.', 'ffl-funnels-addons'));
        }
        $remaining = (float) $order->get_remaining_refund_amount();
        if ($amount > $remaining + 0.0001) {
            throw new InvalidArgumentException(sprintf(
                /* translators: 1: refund amount, 2: remaining amount */
                __('The refund (%1$s) is more than what is left to refund on the order (%2$s).', 'ffl-funnels-addons'),
                self::money($amount, $order),
                self::money($remaining, $order)
            ));
        }
        $gateway = 'gateway' === ($input['method'] ?? '');
        if ($gateway && !self::gateway_refunds($order)) {
            throw new InvalidArgumentException(__('This order’s payment method cannot refund automatically. Choose a manual refund and return the money outside WooCommerce.', 'ffl-funnels-addons'));
        }

        $refund = wc_create_refund([
            'amount'         => $amount,
            'reason'         => sprintf('Customer request %s', $request->number) . ($fee_total > 0 ? ' — ' . sprintf('restocking fee %s', self::money($fee_total, $order)) : ''),
            'order_id'       => $order->get_id(),
            'line_items'     => $line_items,
            'refund_payment' => $gateway,
            'restock_items'  => !empty($input['restock']),
        ]);
        if (is_wp_error($refund)) {
            throw new RuntimeException($refund->get_error_message());
        }

        FFLA_Requests::add_refund_total($request, $amount);
        $text = sprintf(
            /* translators: %s: amount */
            __('Refund issued: %s', 'ffl-funnels-addons'),
            self::money($amount, $order)
        );
        if ($fee_total > 0) {
            /* translators: %s: amount */
            $text .= "\n" . sprintf(__('Restocking fee: %s', 'ffl-funnels-addons'), self::money($fee_total, $order));
        }
        $text .= "\n" . ($gateway ? __('It goes back to your original payment method; banks usually take 5–10 business days.', 'ffl-funnels-addons') : __('Refunded outside the online payment system.', 'ffl-funnels-addons'));

        FFLA_Requests::add_event($request, 'refund', $actor, true, $text, [
            'amount'    => $amount,
            'fee'       => $fee_total,
            'refund_id' => $refund->get_id(),
            'gateway'   => $gateway,
            'restock'   => !empty($input['restock']),
            'intent'    => (string) ($input['intent'] ?? ''),
        ]);

        // Full refund when every requested line was refunded in full with no fee.
        $items = FFLA_Requests::items($request);
        $full = $fee_total <= 0;
        foreach ($items as $line) {
            if (($line_items[(int) $line['item_id']]['qty'] ?? 0) < (int) $line['qty']) {
                $full = false;
            }
        }
        if (!$items) {
            $full = $full && $amount >= $remaining - 0.0001;
        }

        return [
            'amount'     => $amount,
            'fee'        => $fee_total,
            'refund_id'  => (int) $refund->get_id(),
            'resolution' => $full ? 'refunded' : 'partial_refund',
            'text'       => $text,
        ];
    }
}
