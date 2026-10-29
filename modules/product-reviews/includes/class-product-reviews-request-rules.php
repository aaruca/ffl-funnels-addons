<?php
/**
 * Who gets review requests and for which products (Review requests tab):
 * consent, guests, user roles, excluded addresses, one request per customer
 * within a window, product categories, free products and how many products
 * one email lists.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Request_Rules
{
    /** Why an order gets no request, as stored in the request log. */
    const REASONS = ['consent', 'guest', 'role', 'excluded', 'recent_request'];

    public static function reason_label(string $reason): string
    {
        $labels = [
            'consent'        => __('No consent at checkout', 'ffl-funnels-addons'),
            'guest'          => __('Guest order (guests are off)', 'ffl-funnels-addons'),
            'role'           => __('Customer role not included', 'ffl-funnels-addons'),
            'excluded'       => __('Excluded address', 'ffl-funnels-addons'),
            'recent_request' => __('Customer had a request recently', 'ffl-funnels-addons'),
            'unsubscribed'   => __('Unsubscribed', 'ffl-funnels-addons'),
            'no_products'    => __('Nothing to review', 'ffl-funnels-addons'),
            'too_old'        => __('Order too old', 'ffl-funnels-addons'),
            'cancelled'      => __('Cancelled by the store', 'ffl-funnels-addons'),
            'requests_off'   => __('Requests were off when it was due', 'ffl-funnels-addons'),
            'status_ended'   => __('Order refunded, cancelled or failed', 'ffl-funnels-addons'),
            'no_email'       => __('No valid email address', 'ffl-funnels-addons'),
        ];

        return $labels[$reason] ?? $reason;
    }

    /**
     * '' when the order may get a request, otherwise the reason it may not.
     *
     * @param bool $manual A request the store sends by hand: the customer's
     *                     own choices (consent, excluded address) still
     *                     count, the store's targeting rules do not.
     */
    public static function order_refusal(\WC_Order $order, string $recipient, bool $manual = false): string
    {
        if ('optin' === Product_Reviews_Core::get_setting('request_consent', 'none')
            && !Product_Reviews_Consent::order_consented($order)) {
            return 'consent';
        }

        if (self::address_excluded((string) $order->get_billing_email()) || self::address_excluded($recipient)) {
            return 'excluded';
        }

        if ($manual) {
            return '';
        }

        $user_id = (int) $order->get_user_id();
        if ($user_id <= 0 && '1' !== Product_Reviews_Core::get_setting('request_guests', '1')) {
            return 'guest';
        }

        if ($user_id > 0 && !self::roles_allowed($user_id)) {
            return 'role';
        }

        if (self::requested_recently($order)) {
            return 'recent_request';
        }

        return '';
    }

    private static function roles_allowed(int $user_id): bool
    {
        $mode = (string) Product_Reviews_Core::get_setting('request_roles_mode', 'all');
        if ('include' !== $mode && 'exclude' !== $mode) {
            return true;
        }

        $chosen = (array) Product_Reviews_Core::get_setting('request_roles', []);
        $user   = get_userdata($user_id);
        $roles  = $user instanceof \WP_User ? (array) $user->roles : [];
        $match  = !empty(array_intersect($roles, $chosen));

        return 'include' === $mode ? $match : !$match;
    }

    /**
     * One address per line, or a whole domain as `@example.com`.
     */
    public static function address_excluded(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        $raw = (string) Product_Reviews_Core::get_setting('request_excluded_emails', '');
        foreach (preg_split('/[\s,;]+/', strtolower($raw)) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry[0] === '@' ? substr($email, -strlen($entry)) === $entry : $entry === $email) {
                return true;
            }
        }

        return false;
    }

    /**
     * Another order of the same customer got a request within the window
     * (Review requests → One request per customer every … days).
     */
    private static function requested_recently(\WC_Order $order): bool
    {
        $days = absint(Product_Reviews_Core::get_setting('request_customer_window_days', '0'));
        if ($days <= 0 || !function_exists('wc_get_orders')) {
            return false;
        }

        $email = (string) $order->get_billing_email();
        if (!is_email($email)) {
            return false;
        }

        $others = wc_get_orders([
            'billing_email' => $email,
            'exclude'       => [(int) $order->get_id()],
            'limit'         => 25,
            'orderby'       => 'date',
            'order'         => 'DESC',
        ]);

        $since = time() - $days * DAY_IN_SECONDS;
        foreach ((array) $others as $other) {
            if (!$other instanceof \WC_Order) {
                continue;
            }
            // Sent within the window, or still to be sent. A due date in the
            // past without a send is a request that never went out.
            if ((int) $other->get_meta(Product_Reviews_Email::LAST_SENT_META) >= $since
                || ((int) $other->get_meta(Product_Reviews_Email::SCHEDULED_FOR_META) >= time() && $other->get_meta(Product_Reviews_Email::SCHEDULED_META))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a product in the order is one requests ask about: its
     * category is included (subcategories count) and, with free products
     * left out, at least one of its lines cost something.
     *
     * @param array<int, \WC_Order_Item_Product> $lines The order's lines of this product.
     */
    public static function product_allowed(int $product_id, array $lines): bool
    {
        $mode = (string) Product_Reviews_Core::get_setting('request_categories_mode', 'all');
        if ('include' === $mode || 'exclude' === $mode) {
            $chosen = array_map('intval', (array) Product_Reviews_Core::get_setting('request_categories', []));
            $in     = false;
            if (!empty($chosen)) {
                foreach (wc_get_product_cat_ids($product_id) as $term_id) {
                    $family = array_merge([(int) $term_id], array_map('intval', (array) get_ancestors((int) $term_id, 'product_cat', 'taxonomy')));
                    if (array_intersect($family, $chosen)) {
                        $in = true;
                        break;
                    }
                }
            }
            if (('include' === $mode && !$in) || ('exclude' === $mode && $in)) {
                return false;
            }
        }

        if ('1' === Product_Reviews_Core::get_setting('request_skip_free', '0')) {
            $paid = false;
            foreach ($lines as $line) {
                if ((float) $line->get_total() > 0) {
                    $paid = true;
                    break;
                }
            }
            if (!$paid) {
                return false;
            }
        }

        return true;
    }

    /**
     * At most this many products in one request (0 = all), most expensive
     * lines first: those are the reviews worth asking for.
     *
     * @param array<int, int>   $product_ids
     * @param array<int, float> $totals product ID => order line total
     * @return array<int, int>
     */
    public static function limit_products(array $product_ids, array $totals): array
    {
        $max = absint(Product_Reviews_Core::get_setting('request_max_products', '0'));
        if ($max <= 0 || count($product_ids) <= $max) {
            return $product_ids;
        }

        $position = array_flip($product_ids);
        usort($product_ids, static function (int $a, int $b) use ($totals, $position): int {
            $cmp = ($totals[$b] ?? 0) <=> ($totals[$a] ?? 0);

            return $cmp !== 0 ? $cmp : $position[$a] <=> $position[$b];
        });

        return array_slice($product_ids, 0, $max);
    }
}
