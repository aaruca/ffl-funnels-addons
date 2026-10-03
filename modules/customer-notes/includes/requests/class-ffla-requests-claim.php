<?php
/**
 * Customer requests — "Download all (ZIP)" and the claim packet.
 *
 * The claim packet is what a carrier (damage or loss claim) or a manufacturer
 * (warranty) asks for, in one ZIP: a printable summary (claim-summary.html —
 * open it and print or save as PDF) with the store, order, shipment, value,
 * items and the history, plus every photo, video and document on the request.
 * Shipment details are prefilled from the order (WooCommerce Shipment
 * Tracking / Advanced Shipment Tracking, filter `ffla_requests_claim_shipment`)
 * and can be corrected before downloading; each download is logged as an
 * internal note so the next one starts from the same details.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Claim
{
    public static function boot(): void
    {
        add_action('admin_post_ffla_req_zip', [__CLASS__, 'download_all']);
        add_action('admin_post_ffla_req_claim', [__CLASS__, 'download_claim']);
    }

    private static function request_or_die()
    {
        $id = absint($_REQUEST['request'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        $request = FFLA_Requests::get($id);
        if (!$request) {
            wp_die(esc_html__('Not found.', 'ffl-funnels-addons'), '', ['response' => 404]);
        }
        return $request;
    }

    /** All files on the request (customer, staff and internal) as one ZIP. */
    public static function download_all(): void
    {
        $request = self::request_or_die();
        check_admin_referer('ffla_req_zip_' . (int) $request->id);
        $files = FFLA_Requests_Files::for_request((int) $request->id);
        if (!$files) {
            wp_die(esc_html__('This request has no files.', 'ffl-funnels-addons'));
        }
        FFLA_Requests_Files::send_zip($request, $files, sanitize_file_name(strtolower($request->number)) . '-files.zip');
    }

    /** Carrier / manufacturer claim packet. */
    public static function download_claim(): void
    {
        $request = self::request_or_die();
        check_admin_referer('ffla_req_claim_' . (int) $request->id);
        $order = wc_get_order((int) $request->order_id);
        $in = (array) wp_unslash($_POST['claim'] ?? []); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $fields = self::clean($in);

        $files = FFLA_Requests_Files::for_request((int) $request->id);
        if (empty($in['internal'])) {
            $files = array_values(array_filter($files, static function ($f) {
                return (int) $f->is_public; // Leave out internal-note files.
            }));
        }

        $actor = ['type' => 'staff', 'id' => get_current_user_id()];
        $summary = [];
        foreach (['carrier' => __('carrier', 'ffl-funnels-addons'), 'tracking' => __('tracking', 'ffl-funnels-addons'), 'value' => __('value', 'ffl-funnels-addons')] as $key => $label) {
            if ('' !== $fields[$key]) {
                $summary[] = $label . ' ' . ('carrier' === $key ? self::carrier_name($fields[$key]) : $fields[$key]);
            }
        }
        FFLA_Requests::add_event($request, 'note', $actor, false,
            /* translators: %s: details such as "carrier UPS, tracking 1Z…" */
            sprintf(__('Claim packet downloaded%s.', 'ffl-funnels-addons'), $summary ? ' (' . implode(', ', $summary) . ')' : ''),
            ['claim' => $fields]
        );

        $html = self::summary_html($request, $order ?: null, $fields, $files);
        FFLA_Requests_Files::send_zip(
            $request,
            $files,
            sanitize_file_name(strtolower($request->number)) . '-claim-packet.zip',
            ['claim-summary.html' => $html],
            'photos-and-files'
        );
    }

    /** @return array{carrier:string,tracking:string,ship_date:string,value:string,claim_type:string,description:string} */
    private static function clean(array $in): array
    {
        $carrier = sanitize_key((string) ($in['carrier'] ?? ''));
        $date = (string) ($in['ship_date'] ?? '');
        return [
            'claim_type'  => in_array($in['claim_type'] ?? '', ['damage', 'loss', 'warranty'], true) ? $in['claim_type'] : 'damage',
            'carrier'     => array_key_exists($carrier, FFLA_Requests::carriers()) ? $carrier : '',
            'tracking'    => substr(sanitize_text_field((string) ($in['tracking'] ?? '')), 0, 100),
            'ship_date'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '',
            'value'       => '' !== trim((string) ($in['value'] ?? '')) ? wc_format_decimal((string) $in['value'], 2) : '',
            'description' => substr(sanitize_textarea_field((string) ($in['description'] ?? '')), 0, 5000),
        ];
    }

    private static function carrier_name(string $key): string
    {
        $carriers = FFLA_Requests::carriers();
        return (string) ($carriers[$key] ?? $key);
    }

    /**
     * Prefilled claim details: the last claim packet's details, otherwise
     * what the order and the request say.
     */
    public static function defaults($request, $order): array
    {
        foreach (array_reverse(FFLA_Requests::events((int) $request->id)) as $event) {
            $meta = json_decode((string) $event->meta, true);
            if ('note' === $event->kind && is_array($meta) && isset($meta['claim']) && is_array($meta['claim'])) {
                return self::clean($meta['claim']);
            }
        }

        $shipment = ['carrier' => '', 'tracking' => '', 'ship_date' => ''];
        if ($order) {
            $tracking = $order->get_meta('_wc_shipment_tracking_items');
            foreach (array_reverse(is_array($tracking) ? $tracking : []) as $track) {
                if (!is_array($track) || empty($track['tracking_number'])) {
                    continue;
                }
                $provider = strtolower((string) ($track['tracking_provider'] ?? $track['custom_tracking_provider'] ?? ''));
                foreach (array_keys(FFLA_Requests::carriers()) as $key) {
                    if ('other' !== $key && false !== strpos($provider, $key)) {
                        $shipment['carrier'] = $key;
                    }
                }
                if ('' === $shipment['carrier'] && '' !== $provider) {
                    $shipment['carrier'] = 'other';
                }
                $shipment['tracking'] = (string) $track['tracking_number'];
                if (!empty($track['date_shipped']) && is_numeric($track['date_shipped'])) {
                    $shipment['ship_date'] = gmdate('Y-m-d', (int) $track['date_shipped']);
                }
                break;
            }
            if ('' === $shipment['ship_date'] && $order->get_date_completed()) {
                $shipment['ship_date'] = $order->get_date_completed()->date('Y-m-d');
            }
            $shipment = (array) apply_filters('ffla_requests_claim_shipment', $shipment, $order, $request);
        }

        $first = '';
        foreach (FFLA_Requests::events((int) $request->id) as $event) {
            if ('customer' === $event->actor_type && in_array($event->kind, ['created', 'message'], true) && '' !== trim((string) $event->body)) {
                $first = (string) $event->body;
                break;
            }
        }

        $value = '';
        $items = FFLA_Requests::items($request);
        if ($order && $items) {
            $sum = 0.0;
            foreach ($items as $line) {
                $item = $order->get_item((int) ($line['item_id'] ?? 0));
                if ($item && $item->get_quantity()) {
                    $sum += ((float) $item->get_total() + (float) $item->get_total_tax()) / (int) $item->get_quantity() * (int) ($line['qty'] ?? 0);
                }
            }
            $value = $sum > 0 ? wc_format_decimal($sum, 2) : '';
        } elseif ($order) {
            $value = wc_format_decimal((float) $order->get_total(), 2);
        }

        $type = in_array($request->reason, ['not_received', 'missing_item', 'late'], true) ? 'loss' : ('defective' === $request->reason ? 'warranty' : 'damage');

        return self::clean([
            'claim_type'  => $type,
            'carrier'     => $shipment['carrier'] ?? '',
            'tracking'    => $shipment['tracking'] ?? '',
            'ship_date'   => $shipment['ship_date'] ?? '',
            'value'       => $value,
            'description' => $first,
        ]);
    }

    /** The sidebar box on the request screen. */
    public static function box($request, $order, array $files): void
    {
        $counts = ['image' => 0, 'video' => 0, 'file' => 0];
        foreach ($files as $file) {
            $counts[FFLA_Requests_Files::kind_of($file)]++;
        }
        $parts = [];
        if ($counts['image']) {
            /* translators: %d: number of photos */
            $parts[] = sprintf(_n('%d photo', '%d photos', $counts['image'], 'ffl-funnels-addons'), $counts['image']);
        }
        if ($counts['video']) {
            /* translators: %d: number of videos */
            $parts[] = sprintf(_n('%d video', '%d videos', $counts['video'], 'ffl-funnels-addons'), $counts['video']);
        }
        if ($counts['file']) {
            /* translators: %d: number of documents */
            $parts[] = sprintf(_n('%d document', '%d documents', $counts['file'], 'ffl-funnels-addons'), $counts['file']);
        }
        $zip_ok = FFLA_Requests_Files::zip_available();

        echo '<div class="ffla-req-box ffla-req-filesbox" id="ffla-req-files-box"><h2>' . esc_html__('Photos & files', 'ffl-funnels-addons') . '</h2>';
        echo '<p>' . esc_html($parts ? implode(' · ', $parts) : __('No files yet.', 'ffl-funnels-addons')) . '</p>';
        if ($files && $zip_ok) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=ffla_req_zip&request=' . (int) $request->id), 'ffla_req_zip_' . (int) $request->id);
            echo '<p><a class="button" href="' . esc_url($url) . '">' . esc_html__('Download all (ZIP)', 'ffl-funnels-addons') . '</a></p>';
        }

        if ($zip_ok) {
            $d = self::defaults($request, $order);
            echo '<details class="ffla-req-claim"' . ('issue' === $request->type && in_array($request->reason, ['damaged', 'not_received', 'missing_item', 'defective'], true) ? ' open' : '') . '><summary>' . esc_html__('Claim packet', 'ffl-funnels-addons') . '</summary>';
            echo '<p class="description">' . esc_html__('One ZIP for a carrier damage or loss claim, or a manufacturer warranty claim: a printable summary of the order, shipment, value and history, plus every photo, video and document. Check the details first.', 'ffl-funnels-addons') . '</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ffla-req-form">';
            wp_nonce_field('ffla_req_claim_' . (int) $request->id);
            echo '<input type="hidden" name="action" value="ffla_req_claim"><input type="hidden" name="request" value="' . (int) $request->id . '">';
            echo '<label for="ffla-claim-type">' . esc_html__('Claim', 'ffl-funnels-addons') . '</label><select id="ffla-claim-type" name="claim[claim_type]">';
            foreach (['damage' => __('Damaged in transit', 'ffl-funnels-addons'), 'loss' => __('Lost or missing', 'ffl-funnels-addons'), 'warranty' => __('Manufacturer warranty', 'ffl-funnels-addons')] as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($d['claim_type'], $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
            echo '<div class="ffla-req-two"><span><label for="ffla-claim-carrier">' . esc_html__('Carrier', 'ffl-funnels-addons') . '</label><select id="ffla-claim-carrier" name="claim[carrier]"><option value="">—</option>';
            foreach (FFLA_Requests::carriers() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($d['carrier'], $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></span><span><label for="ffla-claim-date">' . esc_html__('Ship date', 'ffl-funnels-addons') . '</label><input type="date" id="ffla-claim-date" name="claim[ship_date]" value="' . esc_attr($d['ship_date']) . '"></span></div>';
            echo '<label for="ffla-claim-tracking">' . esc_html__('Tracking number', 'ffl-funnels-addons') . '</label><input type="text" id="ffla-claim-tracking" name="claim[tracking]" maxlength="100" value="' . esc_attr($d['tracking']) . '">';
            echo '<label for="ffla-claim-value">' . esc_html(sprintf(
                /* translators: %s: currency symbol */
                __('Value claimed (%s)', 'ffl-funnels-addons'),
                html_entity_decode(get_woocommerce_currency_symbol($order ? $order->get_currency() : ''), ENT_QUOTES)
            )) . '</label><input type="text" inputmode="decimal" id="ffla-claim-value" name="claim[value]" value="' . esc_attr($d['value']) . '">';
            echo '<label for="ffla-claim-desc">' . esc_html__('What happened', 'ffl-funnels-addons') . '</label><textarea id="ffla-claim-desc" name="claim[description]" rows="4">' . esc_textarea($d['description']) . '</textarea>';
            echo '<label class="ffla-req-check"><input type="checkbox" name="claim[internal]" value="1" checked> ' . esc_html__('Include internal photos and documents', 'ffl-funnels-addons') . '</label>';
            echo '<p><button type="submit" class="button button-primary">' . esc_html__('Download claim packet', 'ffl-funnels-addons') . '</button></p>';
            echo '</form></details>';
        } elseif ($files) {
            echo '<p class="description">' . esc_html__('This server cannot create ZIP files; download the files one by one from the history.', 'ffl-funnels-addons') . '</p>';
        }
        echo '</div>';
    }

    /** Printable claim summary (self-contained HTML; print or save as PDF). */
    public static function summary_html($request, $order, array $fields, array $files): string
    {
        $store = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $country = explode(':', (string) get_option('woocommerce_default_country', ''));
        $store_address = array_filter([
            get_option('woocommerce_store_address'),
            get_option('woocommerce_store_address_2'),
            trim(get_option('woocommerce_store_city') . ', ' . ($country[1] ?? '') . ' ' . get_option('woocommerce_store_postcode'), ', '),
        ]);
        $currency = $order ? $order->get_currency() : get_woocommerce_currency();
        $money = static function ($amount) use ($currency): string {
            return html_entity_decode(wp_strip_all_tags(wc_price((float) $amount, ['currency' => $currency])), ENT_QUOTES);
        };
        $types = ['damage' => __('Damaged in transit', 'ffl-funnels-addons'), 'loss' => __('Lost or missing', 'ffl-funnels-addons'), 'warranty' => __('Manufacturer warranty', 'ffl-funnels-addons')];
        $reasons = FFLA_Requests::reasons($request->type);
        $row = static function (string $label, string $value): string {
            return '<tr><th>' . esc_html($label) . '</th><td>' . ('' !== $value ? nl2br(esc_html($value)) : '—') . '</td></tr>';
        };

        $h = '<!doctype html><html lang="' . esc_attr(get_bloginfo('language')) . '"><head><meta charset="utf-8"><title>' . esc_html(sprintf(
            /* translators: %s: request number */
            __('Claim summary — %s', 'ffl-funnels-addons'),
            $request->number
        )) . '</title><style>'
            . 'body{font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1d2327;max-width:820px;margin:24px auto;padding:0 16px}'
            . 'h1{font-size:22px;margin:0 0 4px}h2{font-size:16px;margin:24px 0 8px;border-bottom:2px solid #1d2327;padding-bottom:4px}'
            . 'table{width:100%;border-collapse:collapse}th,td{text-align:left;vertical-align:top;padding:6px 8px;border-bottom:1px solid #dcdcde}th{width:34%;color:#50575e;font-weight:600}'
            . '.items th{width:auto}.num{text-align:right;white-space:nowrap}.muted{color:#646970}.ev{margin:0 0 10px;padding:8px 10px;border-left:3px solid #c3c4c7;background:#f6f7f7}.ev b{display:block}'
            . '@media print{body{margin:0}a{color:inherit}}'
            . '</style></head><body>';
        $h .= '<h1>' . esc_html($types[$fields['claim_type']] ?? $types['damage']) . ' — ' . esc_html(__('claim summary', 'ffl-funnels-addons')) . '</h1>';
        $h .= '<p class="muted">' . esc_html(sprintf(
            /* translators: 1: store, 2: date */
            __('Prepared by %1$s on %2$s. Photos, videos and documents are in the "photos-and-files" folder of this ZIP.', 'ffl-funnels-addons'),
            $store,
            wp_date(get_option('date_format'))
        )) . '</p>';

        $h .= '<h2>' . esc_html__('Claimant (shipper)', 'ffl-funnels-addons') . '</h2><table>';
        $h .= $row(__('Business', 'ffl-funnels-addons'), $store);
        $h .= $row(__('Address', 'ffl-funnels-addons'), implode("\n", $store_address));
        $h .= $row(__('Email', 'ffl-funnels-addons'), (string) get_option('woocommerce_email_from_address', get_option('admin_email')));
        $h .= '</table>';

        $h .= '<h2>' . esc_html__('Shipment', 'ffl-funnels-addons') . '</h2><table>';
        $h .= $row(__('Carrier', 'ffl-funnels-addons'), '' !== $fields['carrier'] ? self::carrier_name($fields['carrier']) : '');
        $h .= $row(__('Tracking number', 'ffl-funnels-addons'), $fields['tracking']);
        $h .= $row(__('Ship date', 'ffl-funnels-addons'), '' !== $fields['ship_date'] ? wp_date(get_option('date_format'), strtotime($fields['ship_date'] . ' 12:00:00')) : '');
        if ($order) {
            $to = $order->get_formatted_shipping_address() ?: $order->get_formatted_billing_address();
            $h .= $row(__('Shipped to', 'ffl-funnels-addons'), wp_strip_all_tags(str_replace(['<br/>', '<br>', '<br />'], "\n", (string) $to)));
            $h .= $row(__('Shipping method', 'ffl-funnels-addons'), (string) $order->get_shipping_method());
        }
        $h .= $row(__('Value claimed', 'ffl-funnels-addons'), '' !== $fields['value'] ? $money($fields['value']) : '');
        $h .= '</table>';

        $h .= '<h2>' . esc_html__('Order', 'ffl-funnels-addons') . '</h2><table>';
        $h .= $row(__('Order number', 'ffl-funnels-addons'), $order ? $order->get_order_number() : (string) $request->order_number);
        if ($order) {
            $h .= $row(__('Order date', 'ffl-funnels-addons'), $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : '');
            $h .= $row(__('Customer', 'ffl-funnels-addons'), trim($order->get_formatted_billing_full_name()));
            $h .= $row(__('Order total', 'ffl-funnels-addons'), $money($order->get_total()));
        }
        $h .= $row(__('Store request', 'ffl-funnels-addons'), $request->number . ' · ' . ($reasons[$request->reason] ?? $request->reason));
        $h .= '</table>';

        if ($order) {
            $affected = [];
            foreach (FFLA_Requests::items($request) as $line) {
                $affected[(int) ($line['item_id'] ?? 0)] = (int) ($line['qty'] ?? 0);
            }
            $h .= '<h2>' . esc_html__('Items', 'ffl-funnels-addons') . '</h2><table class="items"><tr><th>' . esc_html__('Item', 'ffl-funnels-addons') . '</th><th>' . esc_html__('SKU', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Qty', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Paid', 'ffl-funnels-addons') . '</th><th>' . esc_html__('In this claim', 'ffl-funnels-addons') . '</th></tr>';
            foreach ($order->get_items() as $item_id => $item) {
                $product = is_callable([$item, 'get_product']) ? $item->get_product() : null;
                $h .= '<tr><td>' . esc_html($item->get_name()) . '</td><td>' . esc_html($product ? (string) $product->get_sku() : '') . '</td><td class="num">' . (int) $item->get_quantity() . '</td><td class="num">'
                    . esc_html($money((float) $item->get_total() + (float) $item->get_total_tax())) . '</td><td>' . (isset($affected[(int) $item_id]) ? esc_html(sprintf(
                        /* translators: %d: quantity */
                        __('Yes (%d)', 'ffl-funnels-addons'),
                        $affected[(int) $item_id]
                    )) : '') . '</td></tr>';
            }
            $h .= '</table>';
        }

        $h .= '<h2>' . esc_html__('What happened', 'ffl-funnels-addons') . '</h2><p>' . ('' !== $fields['description'] ? nl2br(esc_html($fields['description'])) : '—') . '</p>';

        $h .= '<h2>' . esc_html__('History', 'ffl-funnels-addons') . '</h2>';
        foreach (FFLA_Requests::events((int) $request->id, true) as $event) {
            if (!in_array($event->kind, ['created', 'message'], true) || '' === trim((string) $event->body)) {
                continue;
            }
            $who = 'customer' === $event->actor_type ? __('Customer', 'ffl-funnels-addons') : $store;
            $h .= '<div class="ev"><b>' . esc_html($who . ' · ' . FFLA_Requests::local_time($event->created_at)) . '</b>' . nl2br(esc_html((string) $event->body)) . '</div>';
        }

        $h .= '<h2>' . esc_html__('Attached photos, videos and documents', 'ffl-funnels-addons') . '</h2>';
        if ($files) {
            $h .= '<ol>';
            foreach ($files as $file) {
                $h .= '<li>' . esc_html($file->name . ' — ' . size_format((int) $file->size) . ' — ' . FFLA_Requests::local_time($file->created_at)) . '</li>';
            }
            $h .= '</ol>';
        } else {
            $h .= '<p>—</p>';
        }
        return $h . '</body></html>';
    }
}
