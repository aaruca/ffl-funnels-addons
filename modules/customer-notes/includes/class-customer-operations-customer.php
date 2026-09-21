<?php
defined('ABSPATH') || exit;

class FFLA_Customer_Operations_Customer
{
    public static function boot(): void
    {
        add_action('woocommerce_view_order', [__CLASS__, 'render'], 30);
        add_action('admin_post_ffla_ops_help', [__CLASS__, 'help']);
    }

    public static function render($id): void
    {
        $order = wc_get_order($id);
        if (!FFLA_Customer_Operations_Settings::enabled('customer_progress') || !FFLA_Customer_Operations::owner($order)) { return; }
        $d = FFLA_Customer_Operations::data($order);
        echo '<section class="ffla-customer-progress"><h2>Order progress</h2><p>' . esc_html(wc_get_order_status_name($order->get_status())) . '</p>';
        if ($d['collected_at']) { echo '<p>Collection recorded: ' . esc_html(wp_date('M j, Y H:i', $d['collected_at'])) . '</p>'; }
        if ($order->get_status() === FFLA_Customer_Operations::STATUS) {
            foreach ($d['pickup_location'] ?? [] as $value) { if ($value !== '') { echo '<p>' . nl2br(esc_html($value)) . '</p>'; } }
        }
        foreach ($order->get_items() as $item) {
            $v = FFLA_Customer_Operations::item($item);
            if ((FFLA_Customer_Operations_Settings::enabled('partial_pickup') && $v['collected']) || (FFLA_Customer_Operations_Settings::enabled('customer_serials') && $v['serials'])) {
                echo '<h3>' . esc_html($item->get_name()) . '</h3>';
                if (FFLA_Customer_Operations_Settings::enabled('partial_pickup') && $v['collected']) { echo '<p>Units collected: ' . esc_html($v['collected']) . '</p>'; }
                if (FFLA_Customer_Operations_Settings::enabled('customer_serials') && $v['serials']) { echo '<p>Serial numbers: ' . esc_html(implode(', ', $v['serials'])) . '</p>'; }
            }
        }
        if (FFLA_Customer_Operations_Settings::enabled('public_messages')) {
            foreach ($d['public'] as $message) { echo '<article><p><time>' . esc_html(wp_date('M j, Y H:i', $message['at'])) . '</time></p><p>' . nl2br(esc_html($message['text'])) . '</p></article>'; }
        }
        if (FFLA_Customer_Operations_Settings::enabled('customer_tracking')) {
            $tracking = $order->get_meta('_wc_shipment_tracking_items');
            foreach (is_array($tracking) ? $tracking : [] as $track) {
                if (!is_array($track) || empty($track['tracking_number'])) { continue; }
                echo '<p>Shipment: ' . esc_html(($track['custom_tracking_provider'] ?? $track['tracking_provider'] ?? '') . ' — ' . $track['tracking_number']);
                if (!empty($track['custom_tracking_link']) && esc_url($track['custom_tracking_link'], ['https','http'])) { echo ' <a rel="noopener noreferrer" href="' . esc_url($track['custom_tracking_link'], ['https','http']) . '">Track shipment</a>'; }
                echo '</p>';
            }
        }
        if (FFLA_Customer_Operations_Settings::enabled('customer_documents') && function_exists('WPO_WCPDF')) {
            // Reuse the plugin's My Account authorization/settings. Never construct a privileged PDF URL.
            $actions = apply_filters('woocommerce_my_account_my_orders_actions', [], $order);
            foreach (['invoice','packing-slip'] as $key) {
                if (!empty($actions[$key]['url'])) { echo '<p><a href="' . esc_url($actions[$key]['url']) . '">' . esc_html($actions[$key]['name'] ?? $key) . '</a></p>'; }
            }
        }
        if (FFLA_Customer_Operations_Settings::enabled('customer_help')) {
            if (isset($_GET['ffla_help_sent'])) { echo '<p role="status">Your request was sent to the store.</p>'; }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ffla_ops_help"><input type="hidden" name="order" value="' . absint($id) . '">';
            wp_nonce_field('ffla_ops_help_' . $id);
            echo '<p><label for="ffla-help-text">Need help with this order?</label><textarea id="ffla-help-text" name="message" required maxlength="5000" rows="4" style="width:100%"></textarea></p><p>Do not include card numbers or sensitive identity documents.</p><button type="submit" class="button">Send help request</button></form>';
        }
        echo '</section>';
    }

    public static function help(): void
    {
        $id = absint($_POST['order'] ?? 0); check_admin_referer('ffla_ops_help_' . $id);
        try {
            if (!FFLA_Customer_Operations_Settings::enabled('customer_help')) { throw new RuntimeException('Help requests are disabled. Please contact the store.'); }
            FFLA_Customer_Operations::locked($id, static function ($order) {
                if (!FFLA_Customer_Operations::owner($order)) { throw new RuntimeException('You cannot access this order.'); }
                $last = (int) $order->get_meta('_ffla_ops_help_at');
                if ($last > time() - 600) { throw new RuntimeException('Your request was already recorded. Please wait ten minutes before sending another.'); }
                $text = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
                if ($text === '' || strlen($text) > 5000) { throw new InvalidArgumentException('Enter a message of 1–5000 characters.'); }
                $case = FFLA_Customer_Operations::case_data($order);
                if (in_array($case['state'], ['', 'resolved'], true)) { $order->update_meta_data('_ffla_ops_case', 'open'); }
                if (!$case['reason']) { $order->update_meta_data('_ffla_ops_reason', 'other'); }
                $d = FFLA_Customer_Operations::data($order); $d['revision'] = wp_generate_uuid4();
                $order->update_meta_data(FFLA_Customer_Operations::DATA, $d); $order->update_meta_data('_ffla_ops_help_at', time()); $order->save_meta_data();
                $order->add_order_note('[Customer help request / user #' . get_current_user_id() . '] ' . $text, false);
            });
            wp_safe_redirect(add_query_arg('ffla_help_sent', '1', wc_get_order($id)->get_view_order_url())); exit;
        } catch (Throwable $e) { wp_die(esc_html($e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'The request could not be saved. Contact the store.'), '', ['response'=>400]); }
    }
}
