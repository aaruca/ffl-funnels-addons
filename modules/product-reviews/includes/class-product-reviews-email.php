<?php
/**
 * Product Reviews email requests.
 *
 * An order reaching the trigger status (Completed by default) schedules one
 * email for the order (or one per product) after the configured delay, at
 * the configured time of day and on the allowed weekdays; a second reminder
 * can follow. Everything is checked again when an email is due. The store
 * can also send a request by hand from the order screen or the orders list.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Email
{
    const ACTION_SEND_REVIEW_REQUEST = 'ffla_send_product_review_request';

    const ACTION_SEND_ORDER_REVIEW_BUNDLE = 'ffla_send_order_review_bundle';

    const ACTION_SEND_REMINDER = 'ffla_send_order_review_reminder';

    const ACTION_SEND_MANUAL = 'ffla_send_manual_review_request';

    const GROUP = 'ffla-product-reviews';

    /** Set once per order so a re-entered trigger status cannot double-send. */
    const SCHEDULED_META = '_ffla_review_request_scheduled';

    /** When the first email is due. */
    const SCHEDULED_FOR_META = '_ffla_review_scheduled_for';

    const BUNDLE_SENT_META = '_ffla_review_bundle_sent';

    /** Suffixed with the product ID in per-product mode. */
    const PRODUCT_SENT_META_PREFIX = '_ffla_review_sent_';

    /** Failed wp_mail() calls so far, for the bundle email or one product. */
    const BUNDLE_ATTEMPTS_META = '_ffla_review_bundle_attempts';

    const PRODUCT_ATTEMPTS_META_PREFIX = '_ffla_review_attempts_';

    /** Last time any request or reminder for the order went out. */
    const LAST_SENT_META = '_ffla_review_last_sent';

    const REMINDER_SENT_META = '_ffla_review_reminder_sent';

    const REMINDER_ATTEMPTS_META = '_ffla_review_reminder_attempts';

    const MANUAL_SENT_META = '_ffla_review_manual_sent';

    /** When the order first reached a trigger status other than Completed. */
    const TRIGGER_REACHED_META = '_ffla_review_trigger_reached';

    /** Why the order got no request (Product_Reviews_Request_Rules::REASONS and more). */
    const SKIPPED_META = '_ffla_review_skipped';

    const MAX_SEND_ATTEMPTS = 3;

    /** Added to the request delay to get the oldest order still mailed. */
    const ORDER_AGE_GRACE_DAYS = 30;

    /** Core statuses that come before fulfilment or end the order. */
    const PRE_TRIGGER_STATUSES = ['pending', 'processing', 'on-hold', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'trash'];

    public static function init(): void
    {
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status_changed'], 20, 3);

        // A customer who sent the goods back should not be asked how they liked
        // them. Both statuses drop any request still sitting in the queue.
        add_action('woocommerce_order_status_refunded', [__CLASS__, 'cancel_requests_for_order'], 10, 1);
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'cancel_requests_for_order'], 10, 1);

        add_action(self::ACTION_SEND_REVIEW_REQUEST, [__CLASS__, 'send_review_request'], 10, 3);
        add_action(self::ACTION_SEND_ORDER_REVIEW_BUNDLE, [__CLASS__, 'send_order_review_bundle'], 10, 1);
        add_action(self::ACTION_SEND_REMINDER, [__CLASS__, 'send_reminder'], 10, 1);
        add_action(self::ACTION_SEND_MANUAL, [__CLASS__, 'send_manual'], 10, 1);
    }

    /* ---------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------- */

    public static function trigger_status(): string
    {
        $status = sanitize_key((string) Product_Reviews_Core::get_setting('request_trigger_status', 'completed'));
        $status = preg_replace('/^wc-/', '', $status);

        return $status !== '' ? $status : 'completed';
    }

    /**
     * The delay before the first email, in seconds, for this order: the
     * country rule for its shipping (or billing) country, else the setting.
     */
    public static function delay_seconds(?\WC_Order $order = null): int
    {
        $value = max(0, (int) Product_Reviews_Core::get_setting('request_delay_days', '7'));

        $rules = self::country_delays();
        if ($order instanceof \WC_Order && !empty($rules)) {
            $country = strtoupper((string) ($order->get_shipping_country() ?: $order->get_billing_country()));
            if ($country !== '' && isset($rules[$country])) {
                $value = $rules[$country];
            }
        }

        $unit = 'hours' === Product_Reviews_Core::get_setting('request_delay_unit', 'days') ? HOUR_IN_SECONDS : DAY_IN_SECONDS;

        return $value * $unit;
    }

    /**
     * `US|7` per line (or `US:7`, `US 7`), in the delay's unit.
     *
     * @return array<string, int> country code => delay
     */
    public static function country_delays(): array
    {
        $out = [];
        $raw = (string) Product_Reviews_Core::get_setting('request_country_delays', '');

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z]{2})\s*[|:=,\s]\s*(\d+)\s*$/', $line, $m)) {
                $out[strtoupper($m[1])] = (int) $m[2];
            }
        }

        return $out;
    }

    /**
     * Move a send time to the store's sending window: the chosen hour (store
     * time zone) and an allowed weekday, never earlier than $timestamp.
     */
    public static function align_to_window(int $timestamp): int
    {
        $hour = (string) Product_Reviews_Core::get_setting('request_send_hour', '');
        $days = array_values(array_filter(array_map('intval', (array) Product_Reviews_Core::get_setting('request_send_days', []))));

        if ($hour === '' && (empty($days) || count($days) >= 7)) {
            return $timestamp;
        }

        $tz   = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
        $when = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($tz);

        if ($hour !== '') {
            $at = $when->setTime(max(0, min(23, (int) $hour)), 0, 0);
            if ($at->getTimestamp() < $timestamp) {
                $at = $at->modify('+1 day');
            }
            $when = $at;
        }

        if (!empty($days) && count($days) < 7) {
            for ($i = 0; $i < 7 && !in_array((int) $when->format('N'), $days, true); $i++) {
                $when = $when->modify('+1 day');
                if ($hour !== '') {
                    $when = $when->setTime(max(0, min(23, (int) $hour)), 0, 0);
                }
            }
        }

        return $when->getTimestamp();
    }

    /**
     * When the first email for this order goes out.
     */
    public static function first_send_time(\WC_Order $order, int $from = 0): int
    {
        $from = $from > 0 ? $from : time();

        return self::align_to_window($from + self::delay_seconds($order));
    }

    /**
     * Who the email goes to: the billing address, or the customer's account
     * address when the store prefers it (Review requests → Send to the
     * account email).
     */
    public static function recipient(\WC_Order $order): string
    {
        if ('1' === Product_Reviews_Core::get_setting('request_use_account_email', '0') && (int) $order->get_user_id() > 0) {
            $user = get_userdata((int) $order->get_user_id());
            if ($user instanceof \WP_User && is_email($user->user_email)) {
                return (string) $user->user_email;
            }
        }

        return (string) $order->get_billing_email();
    }

    /* ---------------------------------------------------------------------
     * Scheduling
     * ------------------------------------------------------------------- */

    /**
     * @param int|mixed    $order_id
     * @param string|mixed $from
     * @param string|mixed $to
     */
    public static function on_status_changed($order_id, $from, $to): void
    {
        $to = preg_replace('/^wc-/', '', (string) $to);
        if ($to !== self::trigger_status()) {
            return;
        }

        $order = wc_get_order((int) $order_id);
        if (!$order instanceof \WC_Order) {
            return;
        }

        if ('completed' !== $to && !$order->get_meta(self::TRIGGER_REACHED_META)) {
            $order->update_meta_data(self::TRIGGER_REACHED_META, time());
            $order->save();
        }

        self::schedule_requests_for_order((int) $order_id);
    }

    public static function schedule_requests_for_order(int $order_id): void
    {
        // Off, or no published hub page for the link to open.
        if (!Product_Reviews_Core::requests_ready()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $email     = $order->get_billing_email();
        $recipient = self::recipient($order);
        if (!is_email($email) || !is_email($recipient)) {
            return;
        }

        if ($order->get_meta(self::SCHEDULED_META)) {
            return;
        }

        if (Product_Reviews_Notifications::is_opted_out($email) || Product_Reviews_Notifications::is_opted_out($recipient)) {
            self::mark_skipped($order, 'unsubscribed');
            return;
        }

        $refusal = Product_Reviews_Request_Rules::order_refusal($order, $recipient);
        if ($refusal !== '') {
            self::mark_skipped($order, $refusal);
            return;
        }

        $timestamp = self::first_send_time($order);
        $mode      = Product_Reviews_Core::get_setting('request_email_mode', 'bundle');

        if (self::order_too_old($order, $timestamp)) {
            return;
        }

        // Nothing to ask about if every product in the order is already
        // reviewed by this customer, refunded, closed to reviews, or left
        // out by the category and free-product rules.
        $pending = self::pending_product_ids($order, $email);
        if (empty($pending)) {
            self::mark_skipped($order, 'no_products');
            return;
        }

        if ('bundle' === $mode) {
            if ($order->get_meta(self::BUNDLE_SENT_META)) {
                return;
            }
            self::schedule_action($timestamp, self::ACTION_SEND_ORDER_REVIEW_BUNDLE, [$order_id]);
        } else {
            $user_id = (int) $order->get_user_id();
            foreach ($pending as $product_id) {
                if ($order->get_meta(self::PRODUCT_SENT_META_PREFIX . $product_id)) {
                    continue;
                }
                self::schedule_action($timestamp, self::ACTION_SEND_REVIEW_REQUEST, [$order_id, $product_id, $user_id]);
            }
        }

        $order->update_meta_data(self::SCHEDULED_META, 1);
        $order->update_meta_data(self::SCHEDULED_FOR_META, $timestamp);
        $order->delete_meta_data(self::SKIPPED_META);
        $order->save();
    }

    private static function mark_skipped(\WC_Order $order, string $reason): void
    {
        if ($order->get_meta(self::SKIPPED_META) === $reason) {
            return;
        }

        $order->update_meta_data(self::SKIPPED_META, $reason);
        $order->save();
    }

    public static function cancel_requests_for_order(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        self::unschedule_for_order($order);

        // Cleared, not preserved: if the order is completed again later the
        // request should be scheduled again. The per-email "sent" flags stay,
        // so nothing already delivered is repeated.
        $order->delete_meta_data(self::SCHEDULED_META);
        $order->delete_meta_data(self::SCHEDULED_FOR_META);
        $order->save();
    }

    /**
     * Every queued email for the order: bundle, per-product and reminder.
     */
    public static function unschedule_for_order(\WC_Order $order): void
    {
        $order_id = (int) $order->get_id();
        $user_id  = (int) $order->get_user_id();

        self::unschedule_action(self::ACTION_SEND_ORDER_REVIEW_BUNDLE, [$order_id]);
        self::unschedule_action(self::ACTION_SEND_REMINDER, [$order_id]);

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $product_id = Product_Reviews_Core::line_item_parent_product_id($item);
            if ($product_id > 0) {
                self::unschedule_action(self::ACTION_SEND_REVIEW_REQUEST, [$order_id, $product_id, $user_id]);
            }
        }
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function schedule_action(int $timestamp, string $hook, array $args): void
    {
        if (function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action')) {
            if (!as_next_scheduled_action($hook, $args, self::GROUP)) {
                as_schedule_single_action($timestamp, $hook, $args, self::GROUP);
            }

            return;
        }

        if (!wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event($timestamp, $hook, $args);
        }
    }

    /**
     * Queue the action that is running right now once more. Action
     * Scheduler's duplicate check counts the running action, so
     * schedule_action() would skip this.
     *
     * @param array<int, mixed> $args
     */
    private static function schedule_retry(int $timestamp, string $hook, array $args): void
    {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, $hook, $args, self::GROUP);

            return;
        }

        wp_schedule_single_event($timestamp, $hook, $args);
    }

    /**
     * @param array<int, mixed> $args
     */
    private static function unschedule_action(string $hook, array $args): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions($hook, $args, self::GROUP);
        }

        wp_clear_scheduled_hook($hook, $args);
    }

    /**
     * Queue requests the store sends by hand (orders list bulk action). They
     * go out at the next queue run, one per order.
     *
     * @param array<int, int> $order_ids
     */
    public static function queue_manual(array $order_ids): int
    {
        $queued = 0;
        foreach (array_unique(array_map('absint', $order_ids)) as $order_id) {
            if ($order_id > 0) {
                self::schedule_action(time(), self::ACTION_SEND_MANUAL, [$order_id]);
                $queued++;
            }
        }

        return $queued;
    }

    /* ---------------------------------------------------------------------
     * Eligibility
     * ------------------------------------------------------------------- */

    /**
     * Parent product IDs in the order the customer can still review, has not
     * reviewed yet and the request rules ask about, at most the store's
     * product limit (most expensive lines first).
     *
     * @return array<int, int>
     */
    public static function pending_product_ids(\WC_Order $order, string $email): array
    {
        $user_id = (int) $order->get_user_id();
        $lines   = [];

        foreach ($order->get_items() as $item) {
            if ($item instanceof \WC_Order_Item_Product) {
                $lines[Product_Reviews_Core::line_item_parent_product_id($item)][] = $item;
            }
        }

        $ids = [];
        foreach (Product_Reviews_Core::reviewable_product_ids($order) as $product_id) {
            if (!Product_Reviews_Request_Rules::product_allowed($product_id, $lines[$product_id] ?? [])) {
                continue;
            }
            if (!Product_Reviews_Core::customer_has_review_for_product($email, $product_id, $user_id)) {
                $ids[] = $product_id;
            }
        }

        if (absint(Product_Reviews_Core::get_setting('request_max_products', '0')) <= 0) {
            return $ids;
        }

        $totals = [];
        foreach ($ids as $product_id) {
            $totals[$product_id] = 0.0;
            foreach ($lines[$product_id] ?? [] as $line) {
                $totals[$product_id] += (float) $line->get_total();
            }
        }

        return Product_Reviews_Request_Rules::limit_products($ids, $totals);
    }

    /**
     * The trigger status, or a status the store moves orders to after it (a
     * custom "Delivered" after Completed, for example). A core status that
     * comes before fulfilment or ends the order never qualifies, unless it
     * is the trigger itself.
     */
    private static function order_status_allows_request(\WC_Order $order): bool
    {
        $trigger = self::trigger_status();

        if ($order->has_status($trigger)) {
            $allowed = true;
        } elseif ($order->has_status(array_values(array_diff(self::PRE_TRIGGER_STATUSES, [$trigger])))) {
            $allowed = false;
        } elseif ('completed' === $trigger) {
            $allowed = null !== $order->get_date_completed();
        } else {
            $allowed = (bool) $order->get_meta(self::TRIGGER_REACHED_META);
        }

        return (bool) apply_filters('ffla_product_reviews_request_status_allowed', $allowed, $order);
    }

    /**
     * An order created more than delay + 30 days before its email would go
     * out is history rather than a recent purchase: imported orders, old
     * orders completed in bulk, and emails left in a stalled queue are not
     * sent.
     */
    private static function order_too_old(\WC_Order $order, int $send_at): bool
    {
        $created = $order->get_date_created();
        if (!$created) {
            return false;
        }

        $delay_days = (int) ceil(self::delay_seconds($order) / DAY_IN_SECONDS);
        $max_days   = (int) apply_filters(
            'ffla_product_reviews_request_max_order_age_days',
            $delay_days + self::ORDER_AGE_GRACE_DAYS,
            $order
        );

        return $max_days > 0 && ($send_at - $created->getTimestamp()) > $max_days * DAY_IN_SECONDS;
    }

    /**
     * Re-checked at send time, not just at schedule time: the delay is days
     * long, and in the meantime requests may have been switched off, or the
     * customer may have reviewed, refunded, or unsubscribed. Returns the
     * recipient, or '' when the send must abort.
     *
     * Deliberately untyped: wc_get_order() returns WC_Order|false, and `false`
     * does not satisfy a nullable class type hint.
     *
     * @param \WC_Order|false|null $order
     * @param bool                 $manual Sent by the store: automatic requests may be off and
     *                                     the status and age rules do not apply.
     */
    private static function order_still_eligible($order, bool $manual = false): string
    {
        if (!$order instanceof \WC_Order) {
            return '';
        }

        if ($manual ? !Product_Reviews_Core::hub_page_ready() : !Product_Reviews_Core::requests_ready()) {
            return '';
        }

        if ($manual) {
            // The link dies with these, so there is nothing to send.
            if ($order->has_status(Product_Reviews_Core::TOKEN_DEAD_STATUSES)) {
                return '';
            }
        } else {
            if (!self::order_status_allows_request($order)) {
                // Completed → Processing → Completed again: the second completion
                // must be able to schedule the request again, or it is never sent.
                self::release_order($order);

                return '';
            }

            if (self::order_too_old($order, time())) {
                return '';
            }
        }

        $email     = (string) $order->get_billing_email();
        $recipient = self::recipient($order);
        if (!is_email($email) || !is_email($recipient)) {
            return '';
        }

        if (Product_Reviews_Notifications::is_opted_out($email) || Product_Reviews_Notifications::is_opted_out($recipient)) {
            return '';
        }

        if (Product_Reviews_Request_Rules::order_refusal($order, $recipient, true) !== '') {
            return '';
        }

        return $recipient;
    }

    private static function release_order(\WC_Order $order): void
    {
        if (!$order->get_meta(self::SCHEDULED_META)) {
            return;
        }

        $order->delete_meta_data(self::SCHEDULED_META);
        $order->save();
    }

    /* ---------------------------------------------------------------------
     * Sending
     * ------------------------------------------------------------------- */

    public static function send_order_review_bundle(int $order_id): void
    {
        $order     = wc_get_order($order_id);
        $recipient = self::order_still_eligible($order);
        if ($recipient === '') {
            return;
        }

        if ($order->get_meta(self::BUNDLE_SENT_META)) {
            return;
        }

        $pending = self::pending_product_ids($order, (string) $order->get_billing_email());
        if (empty($pending)) {
            return;
        }

        if (!self::deliver($order, $recipient, $pending, 'request', 'bundle')) {
            self::handle_send_failure($order, self::ACTION_SEND_ORDER_REVIEW_BUNDLE, [$order_id], self::BUNDLE_ATTEMPTS_META);

            return;
        }

        $order->update_meta_data(self::BUNDLE_SENT_META, time());
        $order->save();

        self::maybe_schedule_reminder($order);
    }

    public static function send_review_request(int $order_id, int $product_id, int $user_id): void
    {
        $order     = wc_get_order($order_id);
        $recipient = self::order_still_eligible($order);
        if ($recipient === '') {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $sent_meta = self::PRODUCT_SENT_META_PREFIX . $product_id;
        if ($order->get_meta($sent_meta)) {
            return;
        }

        // Refunded in full, closed to reviews, reviewed during the delay,
        // or left out by the rules since the email was queued.
        if (!in_array($product_id, self::pending_product_ids($order, (string) $order->get_billing_email()), true)) {
            return;
        }

        if (!self::deliver($order, $recipient, [$product_id], 'request', 'single')) {
            self::handle_send_failure(
                $order,
                self::ACTION_SEND_REVIEW_REQUEST,
                [$order_id, $product_id, $user_id],
                self::PRODUCT_ATTEMPTS_META_PREFIX . $product_id
            );

            return;
        }

        $order->update_meta_data($sent_meta, time());
        $order->save();

        self::maybe_schedule_reminder($order);
    }

    /**
     * Second email, one for the whole order, listing what is still not
     * reviewed (Review requests → Send a reminder).
     */
    public static function send_reminder(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order || '1' !== Product_Reviews_Core::get_setting('request_reminder', '0')) {
            return;
        }

        if ($order->get_meta(self::REMINDER_SENT_META) || !$order->get_meta(self::LAST_SENT_META)) {
            return;
        }

        $recipient = self::order_still_eligible($order);
        if ($recipient === '') {
            return;
        }

        $pending = self::pending_product_ids($order, (string) $order->get_billing_email());
        if (empty($pending)) {
            return;
        }

        if (!self::deliver($order, $recipient, $pending, 'reminder', 'bundle')) {
            self::handle_send_failure($order, self::ACTION_SEND_REMINDER, [$order_id], self::REMINDER_ATTEMPTS_META);

            return;
        }

        $order->update_meta_data(self::REMINDER_SENT_META, time());
        $order->save();
    }

    private static function maybe_schedule_reminder(\WC_Order $order): void
    {
        if ('1' !== Product_Reviews_Core::get_setting('request_reminder', '0') || $order->get_meta(self::REMINDER_SENT_META)) {
            return;
        }

        $days = max(1, absint(Product_Reviews_Core::get_setting('request_reminder_days', '7')));
        self::schedule_action(self::align_to_window(time() + $days * DAY_IN_SECONDS), self::ACTION_SEND_REMINDER, [(int) $order->get_id()]);
    }

    /**
     * Queued by the orders-list bulk action.
     */
    public static function send_manual(int $order_id): void
    {
        self::send_now($order_id);
    }

    /**
     * Send a request for the order right away, as one email for every
     * product still to review. Used by the order screen and the bulk action.
     * Any automatic email still queued for the order is dropped, so the
     * customer does not get the same request twice.
     *
     * @return array{sent:bool,message:string}
     */
    public static function send_now(int $order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return ['sent' => false, 'message' => __('Order not found.', 'ffl-funnels-addons')];
        }

        if (!Product_Reviews_Core::hub_page_ready()) {
            return ['sent' => false, 'message' => __('Select a published Hub page under Review requests first.', 'ffl-funnels-addons')];
        }

        $recipient = self::order_still_eligible($order, true);
        if ($recipient === '') {
            return ['sent' => false, 'message' => self::manual_refusal_message($order)];
        }

        $pending = self::pending_product_ids($order, (string) $order->get_billing_email());
        if (empty($pending)) {
            return ['sent' => false, 'message' => __('Nothing to review: every product is reviewed, refunded, closed to reviews or left out by the request rules.', 'ffl-funnels-addons')];
        }

        if (!self::deliver($order, $recipient, $pending, 'request', 'bundle')) {
            self::log(sprintf('Order #%d: manual review request could not be sent.', $order_id));

            return ['sent' => false, 'message' => __('The email could not be sent. Check that the site can send email.', 'ffl-funnels-addons')];
        }

        self::unschedule_for_order($order);
        $order->update_meta_data(self::MANUAL_SENT_META, time());
        $order->update_meta_data(self::BUNDLE_SENT_META, time());
        $order->update_meta_data(self::SCHEDULED_META, 1);
        $order->delete_meta_data(self::SKIPPED_META);
        $order->save();
        $order->add_order_note(sprintf(
            /* translators: %s: email address */
            __('Review request sent to %s.', 'ffl-funnels-addons'),
            $recipient
        ));

        self::maybe_schedule_reminder($order);

        return [
            'sent'    => true,
            'message' => sprintf(
                /* translators: %s: email address */
                __('Review request sent to %s.', 'ffl-funnels-addons'),
                $recipient
            ),
        ];
    }

    private static function manual_refusal_message(\WC_Order $order): string
    {
        if ($order->has_status(Product_Reviews_Core::TOKEN_DEAD_STATUSES)) {
            return __('Refunded, cancelled and failed orders get no review request.', 'ffl-funnels-addons');
        }

        $email     = (string) $order->get_billing_email();
        $recipient = self::recipient($order);
        if (!is_email($email) || !is_email($recipient)) {
            return __('The order has no valid email address.', 'ffl-funnels-addons');
        }

        if (Product_Reviews_Notifications::is_opted_out($email) || Product_Reviews_Notifications::is_opted_out($recipient)) {
            return __('The customer unsubscribed from review requests.', 'ffl-funnels-addons');
        }

        $refusal = Product_Reviews_Request_Rules::order_refusal($order, $recipient, true);

        return $refusal !== ''
            ? Product_Reviews_Request_Rules::reason_label($refusal)
            : __('This order cannot get a review request.', 'ffl-funnels-addons');
    }

    /**
     * Build and send one email. Only counts as sent when wp_mail() says so.
     *
     * @param array<int, int> $product_ids
     */
    private static function deliver(\WC_Order $order, string $recipient, array $product_ids, string $kind, string $mode): bool
    {
        $data  = self::email_data($order, $recipient, $product_ids, $mode);
        $email = Product_Reviews_Email_Template::render($kind, $data);

        $sent = (bool) wp_mail($recipient, $email['subject'], $email['body'], self::request_headers($email['html']));

        if ($sent) {
            $order->update_meta_data(self::LAST_SENT_META, time());
            $order->save();
        }

        return $sent;
    }

    /**
     * Everything the template needs for this order.
     *
     * @param array<int, int> $product_ids
     * @return array<string, mixed>
     */
    public static function email_data(\WC_Order $order, string $recipient, array $product_ids, string $mode = 'bundle'): array
    {
        $token = Product_Reviews_Core::build_order_review_token((int) $order->get_id(), (string) $order->get_billing_email());
        $url   = Product_Reviews_Core::order_review_landing_url($token);

        $html = Product_Reviews_Email_Template::is_html();

        $products = [];
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product) {
                continue;
            }
            // Only the HTML layout shows product photos.
            $image      = $html ? get_the_post_thumbnail_url($product_id, 'woocommerce_thumbnail') : '';
            $products[] = [
                'id'    => (int) $product_id,
                'name'  => $product->get_name(),
                'url'   => (string) get_permalink($product_id),
                'image' => $image ? (string) $image : '',
            ];
        }

        return [
            'customer_name'   => self::customer_name($order),
            'products'        => $products,
            'review_url'      => esc_url_raw($url),
            'unsubscribe_url' => esc_url_raw(Product_Reviews_Notifications::unsubscribe_url($recipient)),
            'order_id'        => (int) $order->get_id(),
            'order_number'    => (string) $order->get_order_number(),
            'user_id'         => (int) $order->get_user_id(),
            'pixel_url'       => $html && Product_Reviews_Tracking::enabled() ? Product_Reviews_Tracking::pixel_url((int) $order->get_id()) : '',
            'mode'            => $mode,
        ];
    }

    /**
     * wp_mail() reported a failure. Nothing is marked as sent; the action is
     * queued again an hour later, three attempts in all, and every failure is
     * logged (WooCommerce → Status → Logs, source ffla-product-reviews).
     *
     * @param array<int, mixed> $args
     */
    private static function handle_send_failure(\WC_Order $order, string $hook, array $args, string $attempts_meta): void
    {
        $attempts = (int) $order->get_meta($attempts_meta) + 1;
        $order->update_meta_data($attempts_meta, $attempts);
        $order->save();

        $retry = $attempts < self::MAX_SEND_ATTEMPTS;
        if ($retry) {
            self::schedule_retry(time() + HOUR_IN_SECONDS, $hook, $args);
        }

        self::log(sprintf(
            'Order #%d: review request email could not be sent (attempt %d of %d). %s',
            $order->get_id(),
            $attempts,
            self::MAX_SEND_ATTEMPTS,
            $retry ? 'Trying again in one hour.' : 'Giving up; check that the site can send email.'
        ));
    }

    private static function log(string $message): void
    {
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->warning($message, ['source' => 'ffla-product-reviews']);
        }
    }

    /* ---------------------------------------------------------------------
     * Status for the order screen and the request log
     * ------------------------------------------------------------------- */

    /**
     * @return array{key:string,label:string,scheduled_for:int,sent:int,opened:int,clicked:int,reviewed:int,reminder:int,skipped:string,failed:int}
     */
    public static function request_status(\WC_Order $order): array
    {
        $sent = (int) $order->get_meta(self::LAST_SENT_META);
        if ($sent <= 0) {
            $sent = (int) $order->get_meta(self::BUNDLE_SENT_META);
        }

        $failed = (int) $order->get_meta(self::BUNDLE_ATTEMPTS_META);

        $info = [
            'scheduled_for' => (int) $order->get_meta(self::SCHEDULED_FOR_META),
            'sent'          => $sent,
            'opened'        => (int) $order->get_meta(Product_Reviews_Tracking::OPENED_META),
            'clicked'       => (int) $order->get_meta(Product_Reviews_Tracking::CLICKED_META),
            'reviewed'      => (int) $order->get_meta(Product_Reviews_Tracking::REVIEWED_META),
            'reminder'      => (int) $order->get_meta(self::REMINDER_SENT_META),
            'skipped'       => (string) $order->get_meta(self::SKIPPED_META),
            'failed'        => $failed,
        ];

        if ($info['reviewed'] > 0) {
            $key   = 'reviewed';
            $label = __('Reviewed', 'ffl-funnels-addons');
        } elseif ($info['clicked'] > 0) {
            $key   = 'clicked';
            $label = __('Clicked', 'ffl-funnels-addons');
        } elseif ($info['opened'] > 0) {
            $key   = 'opened';
            $label = __('Opened', 'ffl-funnels-addons');
        } elseif ($sent > 0) {
            $key   = 'sent';
            $label = __('Sent', 'ffl-funnels-addons');
        } elseif ($failed >= self::MAX_SEND_ATTEMPTS) {
            $key   = 'failed';
            $label = __('Failed', 'ffl-funnels-addons');
        } elseif ($info['scheduled_for'] > 0 && $order->get_meta(self::SCHEDULED_META)) {
            $key   = 'scheduled';
            $label = __('Scheduled', 'ffl-funnels-addons');
        } elseif ($info['skipped'] !== '') {
            $key   = 'skipped';
            $label = Product_Reviews_Request_Rules::reason_label($info['skipped']);
        } else {
            $key   = 'none';
            $label = __('No request', 'ffl-funnels-addons');
        }

        return ['key' => $key, 'label' => $label] + $info;
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    private static function customer_name(\WC_Order $order): string
    {
        $name = trim((string) $order->get_billing_first_name());

        return $name !== '' ? $name : __('Customer', 'ffl-funnels-addons');
    }

    /**
     * From and Reply-To as set in WooCommerce → Settings → Emails, so review
     * emails come from the store like its order emails, not from WordPress's
     * default wordpress@ address. Reply-To is WooCommerce's reply-to address
     * when that setting is on, otherwise the From address.
     *
     * @return array<int, string>
     */
    public static function mail_headers(string $content_type = 'text/plain'): array
    {
        $headers = ['Content-Type: ' . $content_type . '; charset=UTF-8'];

        $from_address = sanitize_email((string) get_option('woocommerce_email_from_address', ''));
        $from_name    = self::header_name((string) get_option('woocommerce_email_from_name', ''));
        if (is_email($from_address)) {
            $headers[] = 'From: ' . self::format_address($from_name, $from_address);
        }

        $reply_address = $from_address;
        $reply_name    = $from_name;
        if ('yes' === get_option('woocommerce_email_reply_to_enabled', 'no')) {
            $candidate = sanitize_email((string) get_option('woocommerce_email_reply_to_address', ''));
            if (is_email($candidate)) {
                $reply_address = $candidate;
                $reply_name    = self::header_name((string) get_option('woocommerce_email_reply_to_name', ''));
            }
        }

        if (is_email($reply_address)) {
            $headers[] = 'Reply-To: ' . self::format_address($reply_name, $reply_address);
        }

        return $headers;
    }

    /**
     * Request and reminder emails: the store's headers, plus its own
     * Reply-To and a BCC copy when set (Emails → Sender).
     *
     * @return array<int, string>
     */
    public static function request_headers(bool $html): array
    {
        $headers = self::mail_headers($html ? 'text/html' : 'text/plain');

        $reply = sanitize_email((string) Product_Reviews_Core::get_setting('email_reply_to', ''));
        if (is_email($reply)) {
            $headers = array_values(array_filter($headers, static function (string $h): bool {
                return stripos($h, 'Reply-To:') !== 0;
            }));
            $headers[] = 'Reply-To: ' . $reply;
        }

        $bcc = sanitize_email((string) Product_Reviews_Core::get_setting('email_bcc', ''));
        if (is_email($bcc)) {
            $headers[] = 'Bcc: ' . $bcc;
        }

        return $headers;
    }

    /**
     * A display name safe inside a header: no line breaks (header injection),
     * and no quotes, brackets or commas, which wp_mail() would read as address
     * syntax.
     */
    private static function header_name(string $name): string
    {
        return trim(str_replace(["\r", "\n", '"', '<', '>', ','], '', wp_specialchars_decode($name, ENT_QUOTES)));
    }

    private static function format_address(string $name, string $address): string
    {
        return $name !== '' ? $name . ' <' . $address . '>' : $address;
    }

    /* ---------------------------------------------------------------------
     * Test and preview
     * ------------------------------------------------------------------- */

    /**
     * What a test or preview shows: the newest order with products, or the
     * newest products when there is no order yet (the link then opens the
     * review page without a valid key).
     *
     * @return array<string, mixed>
     */
    public static function sample_data(string $recipient): array
    {
        $orders = function_exists('wc_get_orders') ? wc_get_orders(['limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order']) : [];
        $order  = !empty($orders) && $orders[0] instanceof \WC_Order ? $orders[0] : null;

        $ids = $order ? Product_Reviews_Core::reviewable_product_ids($order) : [];
        if ($order && !empty($ids)) {
            $data                  = self::email_data($order, $recipient, array_slice($ids, 0, 3), 'bundle');
            $data['pixel_url']     = '';
            $data['customer_name'] = self::customer_name($order);

            return $data;
        }

        $products = [];
        foreach ((array) wc_get_products(['limit' => 2, 'status' => 'publish', 'return' => 'ids']) as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $image      = get_the_post_thumbnail_url($product_id, 'woocommerce_thumbnail');
                $products[] = ['id' => (int) $product_id, 'name' => $product->get_name(), 'url' => (string) get_permalink($product_id), 'image' => $image ? (string) $image : ''];
            }
        }

        return [
            'customer_name'   => __('Customer', 'ffl-funnels-addons'),
            'products'        => $products,
            'review_url'      => esc_url_raw(Product_Reviews_Core::order_review_landing_url('preview')),
            'unsubscribe_url' => esc_url_raw(Product_Reviews_Notifications::unsubscribe_url($recipient)),
            'order_id'        => 0,
            'order_number'    => '1234',
            'user_id'         => 0,
            'pixel_url'       => '',
            'mode'            => 'bundle',
        ];
    }

    /**
     * Send the request (or reminder) email as it is set up now to an address
     * of the store's choosing. Nothing is recorded on any order.
     */
    public static function send_test(string $to, string $kind = 'request'): bool
    {
        if (!is_email($to)) {
            return false;
        }

        $email = Product_Reviews_Email_Template::render('reminder' === $kind ? 'reminder' : 'request', self::sample_data($to));

        return (bool) wp_mail(
            $to,
            '[' . __('Test', 'ffl-funnels-addons') . '] ' . $email['subject'],
            $email['body'],
            self::request_headers($email['html'])
        );
    }
}
