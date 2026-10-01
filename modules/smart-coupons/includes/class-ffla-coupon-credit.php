<?php
/**
 * Smart Coupons — store credit.
 *
 * A store credit is a fixed-cart coupon locked to the customer's email
 * (filed under "Store credit"). Its amount is a balance: when an order uses
 * part of it the rest stays available, and a cancelled, failed or refunded
 * order gives the used amount back. Credit skips the coupon guardrails —
 * it is money owed, not a price cut — and always combines with promotions.
 *
 * Staff issue credit from Smart Coupons → Store Credit or when they close a
 * customer request with "Store credit issued". Signed-in customers see their
 * balance in My Account and a one-click "Apply" in the cart and checkout.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Store_Credit
{
    public static function boot(): void
    {
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'deduct'], 20, 1);
        add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'deduct'], 20, 1);
        foreach (['cancelled', 'failed', 'refunded'] as $status) {
            add_action('woocommerce_order_status_' . $status, [__CLASS__, 'restore'], 20, 1);
        }
        add_action('woocommerce_account_dashboard', [__CLASS__, 'account']);
        add_action('woocommerce_before_cart', [__CLASS__, 'cart_notice']);
        add_action('woocommerce_before_checkout_form', [__CLASS__, 'cart_notice'], 5);
        add_filter('render_block_woocommerce/cart', [__CLASS__, 'block_notice']);
        add_filter('render_block_woocommerce/checkout', [__CLASS__, 'block_notice']);
        add_action('wp_loaded', [__CLASS__, 'apply_link'], 30);
        add_action('admin_post_ffla_cpn_credit', [__CLASS__, 'handle_issue']);
    }

    /* ── Issuing ───────────────────────────────────────────────────────── */

    /**
     * @param array $args days, note, source, notify
     * @throws InvalidArgumentException
     */
    public static function create(string $email, float $amount, array $args = []): WC_Coupon
    {
        $email = sanitize_email($email);
        $amount = round($amount, wc_get_price_decimals());
        if (!is_email($email) || $amount <= 0) {
            throw new InvalidArgumentException(__('Enter the customer’s email and an amount above zero.', 'ffl-funnels-addons'));
        }
        $days = max(1, (int) ($args['days'] ?? FFLA_Coupon_Settings::value('credit_days')));
        $expires = strtotime('+' . $days . ' days', current_time('timestamp', true));

        $coupon = new WC_Coupon();
        $coupon->set_code(FFLA_Coupon_Codes::unique_code((string) FFLA_Coupon_Settings::value('credit_prefix') . '-', 8));
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount($amount);
        $coupon->set_email_restrictions([$email]);
        $coupon->set_individual_use(false);
        $coupon->set_date_expires($expires);
        $coupon->set_description(substr(sanitize_text_field((string) ($args['note'] ?? __('Store credit', 'ffl-funnels-addons'))), 0, 200));
        $coupon->update_meta_data('_ffla_credit', 'yes');
        $coupon->update_meta_data('_ffla_credit_initial', $amount);
        $coupon->update_meta_data('_ffla_credit_expires', $expires);
        $coupon->update_meta_data('_ffla_credit_source', sanitize_text_field((string) ($args['source'] ?? 'manual')));
        $coupon->update_meta_data('_ffla_credit_by', get_current_user_id());
        $coupon->save();
        FFLA_Coupon_Categories::assign($coupon->get_id(), 'store-credit');

        if (!empty($args['notify'])) {
            self::email($coupon, $email, (string) ($args['message'] ?? ''));
        }
        do_action('ffla_store_credit_created', $coupon, $email, $amount, $args);
        return $coupon;
    }

    /** Credit for a customer request closed as "Store credit issued". */
    public static function from_request($request, float $amount, int $days = 0): WC_Coupon
    {
        return self::create((string) $request->customer_email, $amount, [
            'days'   => $days ?: (int) FFLA_Coupon_Settings::value('credit_days'),
            /* translators: %s: request number */
            'note'   => sprintf(__('Store credit for request %s', 'ffl-funnels-addons'), $request->number),
            'source' => 'request:' . (int) $request->id,
        ]);
    }

    public static function money(float $amount): string
    {
        return html_entity_decode(wp_strip_all_tags(wc_price($amount)), ENT_QUOTES, 'UTF-8');
    }

    /** One-line description for notes and emails. */
    public static function describe(WC_Coupon $coupon): string
    {
        $expires = $coupon->get_date_expires();
        return sprintf(
            /* translators: 1: code, 2: amount, 3: date */
            __('Store credit code %1$s — %2$s, valid until %3$s. Enter it at checkout (it is tied to your email address).', 'ffl-funnels-addons'),
            strtoupper($coupon->get_code()),
            self::money((float) $coupon->get_amount()),
            $expires ? wc_format_datetime($expires) : '—'
        );
    }

    private static function email(WC_Coupon $coupon, string $email, string $message): void
    {
        $body = '<p>' . esc_html__('Good news — you have store credit to use on your next order.', 'ffl-funnels-addons') . '</p>'
            . ('' !== trim($message) ? '<p>' . nl2br(esc_html($message)) . '</p>' : '')
            . '<p style="font-size:20px;font-weight:700;letter-spacing:1px;">' . esc_html(strtoupper($coupon->get_code())) . '</p>'
            . '<p>' . esc_html(self::describe($coupon)) . '</p>'
            . '<p><a href="' . esc_url(wc_get_page_permalink('shop')) . '">' . esc_html__('Shop now', 'ffl-funnels-addons') . '</a></p>';
        $subject = sprintf(
            /* translators: %s: amount */
            __('You have %s in store credit', 'ffl-funnels-addons'),
            self::money((float) $coupon->get_amount())
        );
        if (function_exists('WC') && WC()->mailer()) {
            $mailer = WC()->mailer();
            $mailer->send($email, $subject, $mailer->wrap_message(__('Your store credit', 'ffl-funnels-addons'), $body));
        } else {
            wp_mail($email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        }
    }

    /* ── Balance ───────────────────────────────────────────────────────── */

    /** Take what the order used off each store credit. */
    public static function deduct($order): void
    {
        $order = $order instanceof WC_Order ? $order : wc_get_order($order);
        if (!$order || $order->get_meta('_ffla_credit_used')) {
            return;
        }
        $used = [];
        foreach ($order->get_items('coupon') as $item) {
            $coupon = new WC_Coupon($item->get_code());
            if (!$coupon->get_id() || !FFLA_Coupon_Settings::is_credit($coupon)) {
                continue;
            }
            $amount = round((float) $item->get_discount() + (float) $item->get_discount_tax(), wc_get_price_decimals());
            $left = max(0.0, (float) $coupon->get_amount() - $amount);
            $coupon->set_amount($left);
            if ($left <= 0) {
                $coupon->set_date_expires(current_time('timestamp', true) - 60); // Used up.
            }
            $coupon->save();
            $used[$coupon->get_code()] = $amount;
            $order->add_order_note(sprintf(
                /* translators: 1: code, 2: used, 3: left */
                __('Store credit %1$s: %2$s used, %3$s left.', 'ffl-funnels-addons'),
                strtoupper($coupon->get_code()),
                self::money($amount),
                self::money($left)
            ));
        }
        if ($used) {
            $order->update_meta_data('_ffla_credit_used', $used);
            $order->save_meta_data();
        }
    }

    /** Give the used credit back when the order does not go through. */
    public static function restore($order_id): void
    {
        $order = wc_get_order($order_id);
        $used = $order ? $order->get_meta('_ffla_credit_used') : null;
        if (!$order || !is_array($used) || $order->get_meta('_ffla_credit_restored')) {
            return;
        }
        foreach ($used as $code => $amount) {
            $coupon = new WC_Coupon($code);
            if (!$coupon->get_id()) {
                continue;
            }
            $coupon->set_amount((float) $coupon->get_amount() + (float) $amount);
            $original = (int) $coupon->get_meta('_ffla_credit_expires');
            $expires = $coupon->get_date_expires();
            if ($expires && $expires->getTimestamp() <= time() && $original > time()) {
                $coupon->set_date_expires($original);
            }
            $coupon->save();
            $order->add_order_note(sprintf(
                /* translators: 1: amount, 2: code */
                __('Store credit restored: %1$s back on %2$s.', 'ffl-funnels-addons'),
                self::money((float) $amount),
                strtoupper($code)
            ));
        }
        $order->update_meta_data('_ffla_credit_restored', time());
        $order->save_meta_data();
    }

    /**
     * Usable store credits for an email.
     *
     * @return WC_Coupon[]
     */
    public static function for_email(string $email): array
    {
        $email = strtolower(trim($email));
        if (!is_email($email)) {
            return [];
        }
        $ids = get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
                ['key' => '_ffla_credit', 'value' => 'yes'],
                ['key' => 'customer_email', 'value' => '"' . $email . '"', 'compare' => 'LIKE'],
            ],
        ]);
        $out = [];
        foreach ($ids as $id) {
            $coupon = new WC_Coupon((int) $id);
            $expires = $coupon->get_date_expires();
            if ((float) $coupon->get_amount() > 0 && (!$expires || $expires->getTimestamp() > time())) {
                $out[] = $coupon;
            }
        }
        return $out;
    }

    /* ── Customer side ─────────────────────────────────────────────────── */

    public static function account(): void
    {
        $user = wp_get_current_user();
        $credits = $user && $user->exists() ? self::for_email($user->user_email) : [];
        if (!$credits) {
            return;
        }
        echo '<section class="ffla-store-credit"><h3>' . esc_html__('Your store credit', 'ffl-funnels-addons') . '</h3><table class="shop_table"><thead><tr><th>' . esc_html__('Code', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Balance', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Valid until', 'ffl-funnels-addons') . '</th></tr></thead><tbody>';
        foreach ($credits as $coupon) {
            $expires = $coupon->get_date_expires();
            echo '<tr><td><strong>' . esc_html(strtoupper($coupon->get_code())) . '</strong></td><td>' . wp_kses_post(wc_price((float) $coupon->get_amount())) . '</td><td>' . esc_html($expires ? wc_format_datetime($expires) : '—') . '</td></tr>';
        }
        echo '</tbody></table><p>' . esc_html__('Enter the code at checkout, or use the Apply link in your cart.', 'ffl-funnels-addons') . '</p></section>';
    }

    public static function cart_notice(): void
    {
        if (!FFLA_Coupon_Settings::value('credit_notice') || !is_user_logged_in() || !WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        $applied = WC()->cart->get_applied_coupons();
        foreach (self::for_email(wp_get_current_user()->user_email) as $coupon) {
            if (in_array($coupon->get_code(), $applied, true)) {
                continue;
            }
            $url = add_query_arg(['ffla_apply_credit' => $coupon->get_code(), '_ffla_nonce' => wp_create_nonce('ffla_credit_' . $coupon->get_code())], wc_get_cart_url());
            wc_print_notice(sprintf(
                /* translators: 1: amount, 2: code, 3: link */
                __('You have %1$s in store credit (%2$s). %3$s', 'ffl-funnels-addons'),
                wc_price((float) $coupon->get_amount()),
                esc_html(strtoupper($coupon->get_code())),
                '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Apply it', 'ffl-funnels-addons') . '</a>'
            ), 'notice');
        }
    }

    /** The Cart and Checkout blocks skip the classic hooks, so the reminder goes above the block. */
    public static function block_notice($html)
    {
        ob_start();
        self::cart_notice();
        $notice = trim((string) ob_get_clean());
        return '' === $notice ? $html : '<div class="woocommerce ffla-credit-notice alignwide">' . $notice . '</div>' . $html;
    }

    public static function apply_link(): void
    {
        if (empty($_GET['ffla_apply_credit']) || !is_user_logged_in() || !function_exists('WC') || !WC()->cart) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $code = wc_format_coupon_code(sanitize_text_field(wp_unslash($_GET['ffla_apply_credit']))); // phpcs:ignore WordPress.Security.NonceVerification
        $nonce = sanitize_text_field(wp_unslash($_GET['_ffla_nonce'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        if (wp_verify_nonce($nonce, 'ffla_credit_' . $code)) {
            foreach (self::for_email(wp_get_current_user()->user_email) as $coupon) {
                if ($coupon->get_code() === $code && !WC()->cart->has_discount($code)) {
                    WC()->cart->apply_coupon($code);
                }
            }
        }
        wp_safe_redirect(remove_query_arg(['ffla_apply_credit', '_ffla_nonce']));
        exit;
    }

    /* ── Admin page ────────────────────────────────────────────────────── */

    public static function page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        FFLA_Coupon_Admin::notices();
        echo '<div class="wb-card"><div class="wb-card__header"><h2>' . esc_html__('Issue store credit', 'ffl-funnels-addons') . '</h2></div><div class="wb-card__body">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ffla-cpn-form"><input type="hidden" name="action" value="ffla_cpn_credit">';
        wp_nonce_field('ffla_cpn_credit');
        echo '<p><label for="ffla-credit-email">' . esc_html__('Customer email', 'ffl-funnels-addons') . '</label><input type="email" id="ffla-credit-email" name="email" required class="regular-text"></p>'
            . '<p><label for="ffla-credit-amount">' . esc_html__('Amount', 'ffl-funnels-addons') . '</label><input type="number" id="ffla-credit-amount" name="amount" min="0.01" step="0.01" required></p>'
            . '<p><label for="ffla-credit-days">' . esc_html__('Valid for (days)', 'ffl-funnels-addons') . '</label><input type="number" id="ffla-credit-days" name="days" min="1" max="3650" value="' . esc_attr((string) FFLA_Coupon_Settings::value('credit_days')) . '"></p>'
            . '<p><label for="ffla-credit-note">' . esc_html__('Reason (internal)', 'ffl-funnels-addons') . '</label><input type="text" id="ffla-credit-note" name="note" class="regular-text" maxlength="200"></p>'
            . '<p><label for="ffla-credit-message">' . esc_html__('Message to the customer (optional)', 'ffl-funnels-addons') . '</label><textarea id="ffla-credit-message" name="message" rows="3" class="large-text"></textarea></p>'
            . '<p><label><input type="checkbox" name="notify" value="1" checked> ' . esc_html__('Email the code to the customer', 'ffl-funnels-addons') . '</label></p>'
            . '<p><button type="submit" class="wb-btn wb-btn--primary button button-primary">' . esc_html__('Issue store credit', 'ffl-funnels-addons') . '</button></p></form></div></div>';

        $ids = get_posts(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 200, 'fields' => 'ids', 'meta_key' => '_ffla_credit', 'meta_value' => 'yes']); // phpcs:ignore WordPress.DB.SlowDBQuery
        echo '<div class="wb-card"><div class="wb-card__header"><h2>' . esc_html__('Store credits', 'ffl-funnels-addons') . '</h2></div><div class="wb-card__body">';
        if (!$ids) {
            echo '<p>' . esc_html__('No store credit issued yet.', 'ffl-funnels-addons') . '</p></div></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Code', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Customer', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Issued', 'ffl-funnels-addons') . '</th><th class="num">' . esc_html__('Balance', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Valid until', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Source', 'ffl-funnels-addons') . '</th></tr></thead><tbody>';
        foreach ($ids as $id) {
            $c = new WC_Coupon((int) $id);
            $expires = $c->get_date_expires();
            $source = (string) $c->get_meta('_ffla_credit_source');
            $source_html = 0 === strpos($source, 'request:')
                ? '<a href="' . esc_url(admin_url('admin.php?page=ffla-requests&request=' . (int) substr($source, 8))) . '">' . esc_html__('Customer request', 'ffl-funnels-addons') . '</a>'
                : esc_html('manual' === $source ? __('Issued by staff', 'ffl-funnels-addons') : $source);
            echo '<tr><td><a href="' . esc_url(get_edit_post_link((int) $id)) . '"><strong>' . esc_html(strtoupper($c->get_code())) . '</strong></a></td><td>' . esc_html(implode(', ', $c->get_email_restrictions())) . '</td>'
                . '<td class="num">' . wp_kses_post(wc_price((float) $c->get_meta('_ffla_credit_initial'))) . '</td><td class="num">' . wp_kses_post(wc_price((float) $c->get_amount())) . '</td>'
                . '<td>' . esc_html($expires ? wc_format_datetime($expires) : '—') . ($expires && $expires->getTimestamp() < time() ? ' <span class="ffla-cpn-muted">(' . esc_html((float) $c->get_amount() > 0 ? __('expired', 'ffl-funnels-addons') : __('used up', 'ffl-funnels-addons')) . ')</span>' : '') . '</td><td>' . $source_html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</tbody></table></div></div>';
    }

    public static function handle_issue(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        check_admin_referer('ffla_cpn_credit');
        try {
            $coupon = self::create(
                sanitize_email(wp_unslash($_POST['email'] ?? '')),
                (float) wp_unslash($_POST['amount'] ?? 0),
                [
                    'days'    => absint($_POST['days'] ?? 0),
                    'note'    => sanitize_text_field(wp_unslash($_POST['note'] ?? '')),
                    'message' => sanitize_textarea_field(wp_unslash($_POST['message'] ?? '')),
                    'notify'  => !empty($_POST['notify']),
                    'source'  => 'manual',
                ]
            );
            /* translators: %s: code */
            FFLA_Coupon_Admin::flash('success', sprintf(__('Store credit %s issued.', 'ffl-funnels-addons'), strtoupper($coupon->get_code())));
        } catch (InvalidArgumentException $e) {
            FFLA_Coupon_Admin::flash('error', $e->getMessage());
        }
        wp_safe_redirect(admin_url('admin.php?page=' . Smart_Coupons_Module::PAGE_CREDIT));
        exit;
    }
}
