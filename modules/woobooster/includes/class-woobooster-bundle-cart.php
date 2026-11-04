<?php
/**
 * WooBooster Bundle Cart.
 *
 * Adds a bundle to the cart as one real cart line per item (the product, or
 * the variation its default attributes choose), each at its own discounted
 * unit price. The lines of one "Add to cart" share a group hash:
 *
 * - WooCommerce tracks stock, tax class, shipping and coupon rules per item,
 *   and checks such as the FFL dealer step see every product (a firearm is
 *   always its own line).
 * - The price of each line comes from a snapshot the server took when the
 *   bundle was added (cart item data), applied on every totals calculation.
 * - Quantities are locked; removing one line removes the whole group, and
 *   "Undo" restores the whole group. A group that loses a line (or whose
 *   bundle is switched off or out of schedule) loses the bundle price and is
 *   removed when the cart next loads.
 *
 * Carts saved before this change hold one "representative" line priced at
 * the whole bundle total with the other products as meta
 * (META_BUNDLE_ITEMS + META_BUNDLE_TOTAL). Those legacy lines keep their
 * price and display until checkout.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Bundle_Cart
{
    /**
     * Cart-item meta keys shared by every bundle line.
     */
    const META_BUNDLE_ID      = '_woobooster_bundle_id';
    const META_BUNDLE_HASH    = '_woobooster_bundle_hash';
    const META_SOURCE_PRODUCT = '_woobooster_bundle_source_product_id';

    /**
     * Legacy single-line format (carts saved before per-item lines).
     */
    const META_BUNDLE_ITEMS = '_woobooster_bundle_items';
    const META_BUNDLE_TOTAL = '_woobooster_bundle_total';

    /**
     * Per-item lines.
     */
    const META_UNIT_PRICE    = '_woobooster_bundle_unit_price';
    const META_REGULAR_PRICE = '_woobooster_bundle_regular_price';
    const META_LINE_QTY      = '_woobooster_bundle_line_qty';
    const META_GROUP_SIZE    = '_woobooster_bundle_group_size';
    const META_BUNDLE_NAME   = '_woobooster_bundle_name';

    const NONCE_ACTION = 'woobooster_bundle_cart';

    /**
     * How long a signed item list from the widget is honoured (12 hours).
     */
    const TOKEN_TTL = 604800; // 7 days: page caches keep HTML that long. Any edit of the bundle invalidates it (bundle_fingerprint()).

    /**
     * Most bundles carried by one "Add to cart".
     */
    const MAX_BUNDLE_QTY = 99;

    /**
     * Set while this class removes or restores lines itself, so the group
     * hooks do not react to their own changes.
     *
     * @var bool
     */
    private static $syncing = false;

    public static function init()
    {
        add_action('wp_ajax_woobooster_add_bundle_to_cart', array(__CLASS__, 'ajax_add_bundle_to_cart'));
        add_action('wp_ajax_nopriv_woobooster_add_bundle_to_cart', array(__CLASS__, 'ajax_add_bundle_to_cart'));

        // Fresh nonce for pages served from a page cache (admin-ajax is never cached).
        add_action('wp_ajax_woobooster_bundle_nonce', array(__CLASS__, 'ajax_refresh_nonce'));
        add_action('wp_ajax_nopriv_woobooster_bundle_nonce', array(__CLASS__, 'ajax_refresh_nonce'));

        // Price each bundle line from its server-side snapshot.
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'set_bundle_price'), 20);

        // Drop bundle lines whose bundle was switched off, deleted or whose
        // schedule ended while they sat in the cart (or a saved session), and
        // groups that lost one of their lines.
        add_action('woocommerce_cart_loaded_from_session', array(__CLASS__, 'remove_unavailable_bundles'), 20);

        // Keep each bundle add as its own lines so they never merge with a
        // plain purchase of the same product (or another bundle).
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'preserve_bundle_meta'), 10, 2);

        // A bundle is bought as a set: one removal removes the group, undo
        // restores it, quantities are locked.
        add_action('woocommerce_cart_item_removed', array(__CLASS__, 'remove_group_with_item'), 10, 2);
        add_action('woocommerce_cart_item_restored', array(__CLASS__, 'restore_group_with_item'), 10, 2);
        add_filter('woocommerce_cart_item_quantity', array(__CLASS__, 'cart_item_quantity'), 10, 3);
        add_filter('woocommerce_update_cart_validation', array(__CLASS__, 'validate_cart_quantity_update'), 10, 4);
        add_filter('woocommerce_store_api_product_quantity_editable', array(__CLASS__, 'store_api_quantity_editable'), 10, 3);
        add_filter('woocommerce_store_api_product_quantity_minimum', array(__CLASS__, 'store_api_quantity_limit'), 10, 3);
        add_filter('woocommerce_store_api_product_quantity_maximum', array(__CLASS__, 'store_api_quantity_limit'), 10, 3);

        // Coupons that exclude sale items treat discounted bundle lines as sale items.
        add_filter('woocommerce_coupon_is_valid_for_product', array(__CLASS__, 'coupon_valid_for_product'), 10, 4);
        add_filter('woocommerce_coupon_is_valid', array(__CLASS__, 'coupon_is_valid'), 10, 3);

        // Cart / checkout display.
        add_filter('woocommerce_cart_item_name', array(__CLASS__, 'cart_item_name'), 10, 2);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'cart_item_data_display'), 10, 2);
        add_filter('woocommerce_cart_item_thumbnail', array(__CLASS__, 'cart_item_thumbnail'), 10, 3);
        add_filter('woocommerce_cart_item_price', array(__CLASS__, 'cart_item_price'), 10, 3);

        // Persist bundle details onto the order line items.
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'add_order_line_item_meta'), 10, 3);
    }

    /* ── Line types ───────────────────────────────────────────────── */

    /**
     * A per-item bundle line (current format).
     *
     * @param mixed $cart_item Cart item.
     */
    public static function is_item_line($cart_item)
    {
        return is_array($cart_item)
            && !empty($cart_item[self::META_BUNDLE_HASH])
            && isset($cart_item[self::META_UNIT_PRICE])
            && !isset($cart_item[self::META_BUNDLE_TOTAL]);
    }

    /**
     * A single representative line from before per-item lines.
     *
     * @param mixed $cart_item Cart item.
     */
    public static function is_legacy_line($cart_item)
    {
        return is_array($cart_item) && isset($cart_item[self::META_BUNDLE_TOTAL]);
    }

    /**
     * A bundle line priced below the item's own price at the time it was added.
     *
     * @param mixed $cart_item Cart item.
     */
    public static function is_discounted_line($cart_item)
    {
        return self::is_item_line($cart_item)
            && isset($cart_item[self::META_REGULAR_PRICE])
            && (float) $cart_item[self::META_UNIT_PRICE] < (float) $cart_item[self::META_REGULAR_PRICE] - 0.00001;
    }

    /**
     * Give every bundle line a unique key so WooCommerce never merges it with
     * another cart item.
     */
    public static function preserve_bundle_meta($cart_item_data, $product_id)
    {
        if (!empty($cart_item_data[self::META_BUNDLE_HASH])) {
            $cart_item_data['unique_key'] = $cart_item_data[self::META_BUNDLE_HASH];
        }
        return $cart_item_data;
    }

    /* ── Signed item list (snapshot token) ───────────────────────── */

    /**
     * Sign the items a widget shows, so the add-to-cart request adds exactly
     * those (automatic picks can change between page view and click).
     *
     * Format: "{expires}.{id-id-…}.{hmac}". The IDs keep the widget's order;
     * the signature covers them sorted, with the bundle, the source product
     * and the expiry.
     *
     * @param int      $bundle_id         Bundle ID.
     * @param int      $source_product_id Product the widget was shown for (0 = none).
     * @param int[]    $item_ids          Product IDs shown.
     * @param int|null $expires           Unix time; default now + TOKEN_TTL.
     * @return string Empty when there are no items.
     */
    public static function create_snapshot_token($bundle_id, $source_product_id, array $item_ids, $expires = null)
    {
        $ids = array_values(array_unique(array_filter(array_map('absint', $item_ids))));
        if (empty($ids)) {
            return '';
        }

        $expires = null === $expires ? time() + self::TOKEN_TTL : (int) $expires;

        return $expires . '.' . implode('-', $ids) . '.' . self::sign_snapshot($bundle_id, $source_product_id, $ids, $expires);
    }

    /**
     * Check a snapshot token.
     *
     * @param string $token             Token from the request.
     * @param int    $bundle_id         Bundle ID from the request.
     * @param int    $source_product_id Source product ID from the request.
     * @return int[]|null The signed item IDs (widget order), or null when the
     *                    token is missing, malformed, tampered with or expired.
     */
    public static function verify_snapshot_token($token, $bundle_id, $source_product_id)
    {
        $token = trim((string) $token);
        if ('' === $token || strlen($token) > 2048) {
            return null;
        }
        if (!preg_match('/^(\d{1,12})\.(\d{1,20}(?:-\d{1,20}){0,199})\.([0-9a-f]{64})$/', $token, $m)) {
            return null;
        }

        $expires = (int) $m[1];
        $now     = time();
        if ($expires < $now || $expires > $now + self::TOKEN_TTL + 300) {
            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', explode('-', $m[2])))));
        if (empty($ids)) {
            return null;
        }

        if (!hash_equals(self::sign_snapshot($bundle_id, $source_product_id, $ids, $expires), $m[3])) {
            return null;
        }

        return $ids;
    }

    /**
     * HMAC over the bundle, the source product, the sorted item IDs and the expiry.
     */
    private static function sign_snapshot($bundle_id, $source_product_id, array $ids, $expires)
    {
        $sorted = array_map('absint', $ids);
        sort($sorted, SORT_NUMERIC);

        $message = 'woobooster_bundle_snapshot|' . absint($bundle_id) . '|' . absint($source_product_id)
            . '|' . implode(',', $sorted) . '|' . (int) $expires . '|' . self::bundle_fingerprint($bundle_id);

        return hash_hmac('sha256', $message, wp_salt('nonce'));
    }

    /** @var array<int, string> Per-request fingerprints. */
    private static $fingerprints = array();

    /**
     * Hash of everything that decides what a bundle sells and for how much:
     * status, schedule, pricing, items (quantities, Optional), automatic
     * picks and conditions. A signed list made before an edit no longer
     * verifies, so a long-lived token cannot sell yesterday's items or price.
     */
    public static function bundle_fingerprint($bundle_id)
    {
        $bundle_id = absint($bundle_id);
        if (isset(self::$fingerprints[$bundle_id])) {
            return self::$fingerprints[$bundle_id];
        }

        $bundle = WooBooster_Bundle::get($bundle_id);
        $fields = array();
        if ($bundle) {
            foreach (array('status', 'start_date', 'end_date', 'discount_type', 'discount_value', 'bundle_price_type', 'bundle_price') as $field) {
                $fields[$field] = isset($bundle->$field) ? (string) $bundle->$field : '';
            }
        }

        $data = array(
            'bundle'     => $fields,
            'items'      => self::rows_for_fingerprint(WooBooster_Bundle::get_items($bundle_id)),
            'actions'    => self::rows_for_fingerprint(WooBooster_Bundle::get_actions($bundle_id)),
            'conditions' => self::rows_for_fingerprint(WooBooster_Bundle::get_conditions($bundle_id)),
        );

        return self::$fingerprints[$bundle_id] = md5((string) wp_json_encode($data));
    }

    /**
     * Rows (objects or nested groups of objects) as plain arrays without
     * row IDs or timestamps, which change without changing the offer.
     *
     * @param mixed $rows
     * @return mixed
     */
    private static function rows_for_fingerprint($rows)
    {
        if (is_object($rows)) {
            $rows = get_object_vars($rows);
            unset($rows['id'], $rows['created_at'], $rows['updated_at']);
        }
        if (is_array($rows)) {
            foreach ($rows as $key => $value) {
                $rows[$key] = self::rows_for_fingerprint($value);
            }
        }

        return $rows;
    }

    /** Forget cached fingerprints (after a bundle is saved in this request). */
    public static function flush_fingerprints()
    {
        self::$fingerprints = array();
    }

    /* ── Add to cart ──────────────────────────────────────────────── */

    /**
     * AJAX: fresh add-to-cart nonce for pages served from a page cache.
     */
    public static function ajax_refresh_nonce()
    {
        nocache_headers();
        wp_send_json_success(array('nonce' => wp_create_nonce(self::NONCE_ACTION)));
    }

    /**
     * AJAX: add a bundle to the cart, one line per item.
     */
    public static function ajax_add_bundle_to_cart()
    {
        // The nonce (or, for guests on cached pages, the signed item list) is
        // checked in add_bundle().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $result = self::add_bundle(wp_unslash($_POST));

        if (empty($result['success'])) {
            wp_send_json_error($result['data'], $result['status']);
        }

        ob_start();
        woocommerce_mini_cart();
        $mini_cart = ob_get_clean();

        $data              = $result['data'];
        $data['fragments'] = apply_filters('woocommerce_add_to_cart_fragments', array(
            'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
        ));
        $data['cart_hash'] = WC()->cart->get_cart_hash();

        wp_send_json_success($data);
    }

    /**
     * Add a bundle to the cart from request data.
     *
     * Request keys: bundle_id, source_product_id, product_ids[] (the ticked
     * items), token (signed item list from the widget), nonce, quantity
     * (number of bundles, default 1).
     *
     * @param array $request Unslashed request data.
     * @return array ['success' => bool, 'status' => int, 'data' => array].
     */
    public static function add_bundle(array $request)
    {
        $bundle_id         = isset($request['bundle_id']) ? absint($request['bundle_id']) : 0;
        $source_product_id = isset($request['source_product_id']) ? absint($request['source_product_id']) : 0;
        $product_ids       = isset($request['product_ids']) ? array_map('absint', (array) $request['product_ids']) : array();
        $product_ids       = array_values(array_unique(array_filter($product_ids)));
        $bundle_qty        = isset($request['quantity']) ? absint($request['quantity']) : 1;
        $bundle_qty        = min(self::MAX_BUNDLE_QTY, max(1, $bundle_qty));
        $token             = isset($request['token']) && is_scalar($request['token']) ? (string) $request['token'] : '';
        $nonce             = isset($request['nonce']) && is_scalar($request['nonce']) ? (string) $request['nonce'] : '';

        if (!$bundle_id || empty($product_ids)) {
            return self::fail(__('Invalid bundle or no products selected.', 'ffl-funnels-addons'));
        }

        $snapshot_ids = self::verify_snapshot_token($token, $bundle_id, $source_product_id);

        // Logged-in shoppers need a valid nonce. A guest's page often comes
        // from a page cache, where the nonce has expired or was made for
        // another visitor; the signed item list binds a guest's request
        // instead. Without either, the widget fetches a fresh nonce and retries.
        $nonce_ok = '' !== $nonce && false !== wp_verify_nonce($nonce, self::NONCE_ACTION);
        if (!$nonce_ok && (is_user_logged_in() || null === $snapshot_ids)) {
            return self::fail(
                __('Your session has expired. Please try again.', 'ffl-funnels-addons'),
                array('code' => 'invalid_nonce'),
                403
            );
        }

        $bundle = WooBooster_Bundle::get($bundle_id);
        if (!$bundle || !$bundle->status) {
            return self::fail(__('Bundle not found or inactive.', 'ffl-funnels-addons'));
        }

        // The schedule is enforced for widget visibility, but a captured or
        // replayed request must not be able to redeem an expired (or not yet
        // started) bundle.
        if (!self::bundle_schedule_is_active($bundle)) {
            return self::fail(__('This bundle offer is not currently available.', 'ffl-funnels-addons'));
        }

        // The bundle's Conditions (User Role included) must allow it for the
        // product it was shown on and for this shopper. A bundle without
        // conditions (pinned in Bricks) may be added without a product.
        $not_here = __('This bundle is not available for this product.', 'ffl-funnels-addons');
        if ($source_product_id) {
            $source = wc_get_product($source_product_id);
            if (!$source || 'trash' === $source->get_status()) {
                return self::fail($not_here);
            }
        }
        // With a valid signed item list our own widget showed exactly these
        // items here (pinned bundles included), so only the viewer conditions
        // are checked again. Without one, the product conditions must match.
        $matcher = new WooBooster_Bundle_Matcher();
        $allowed = null !== $snapshot_ids
            ? $matcher->bundle_allows_viewer($bundle)
            : $matcher->bundle_matches_product($bundle, $source_product_id);
        if (!$allowed) {
            return self::fail($not_here);
        }

        $required_ids = WooBooster_Bundle::get_required_product_ids($bundle->id);
        $qty_map      = WooBooster_Bundle::get_item_quantities($bundle->id);

        // The bundle's items: exactly what the widget showed when its signed
        // list is valid and still holds every required item; otherwise worked
        // out again (older cached pages), leaving out what the widget leaves out.
        if (null !== $snapshot_ids && !array_diff($required_ids, $snapshot_ids)) {
            $item_set = $snapshot_ids;
        } else {
            $item_set = self::available_items(
                array_map('absint', (array) $matcher->resolve_bundle_items($bundle, $source_product_id)),
                $qty_map,
                $required_ids
            );
        }

        // Every static item not marked Optional is part of every purchase,
        // whichever product the bundle is shown on.
        if (array_diff($required_ids, $item_set)) {
            return self::fail(__('This bundle is not available right now.', 'ffl-funnels-addons'));
        }

        $selected = array_values(array_intersect($item_set, $product_ids));
        if (empty($selected)) {
            return self::fail(__('Selected products do not belong to this bundle.', 'ffl-funnels-addons'));
        }
        if (array_diff($required_ids, $selected)) {
            return self::fail(__('Bundle requires all items to be selected.', 'ffl-funnels-addons'));
        }

        // Prices over the full bundle (what the widget showed), so unticking
        // an optional item does not change the others.
        $price_map    = WooBooster_Bundle::calculate_item_prices($bundle, $item_set, $qty_map);
        $required_map = array_flip($required_ids);

        // 1. Check every item before adding any.
        $lines  = array();
        $errors = array();
        foreach ($selected as $pid) {
            $qty     = (isset($qty_map[$pid]) ? max(1, (int) $qty_map[$pid]) : 1) * $bundle_qty;
            $line    = WooBooster_Bundle::prepare_line($pid, $qty);
            $message = '' !== $line['error'] ? $line['error'] : self::run_add_to_cart_validation($line, $qty);

            if ('' !== $message) {
                // Without a required item the bundle (and its price) does not apply.
                if (isset($required_map[$pid])) {
                    return self::fail($message, $errors ? array('errors' => $errors) : array());
                }
                $errors[] = $message;
                continue;
            }

            $regular = isset($price_map[$pid]) ? (float) $price_map[$pid]['original'] : (float) $line['buy']->get_price();
            $unit    = isset($price_map[$pid]) ? (float) $price_map[$pid]['discounted'] : $regular;

            $lines[] = array(
                'item_id'      => $pid,
                'product_id'   => $line['product_id'],
                'variation_id' => $line['variation_id'],
                'variation'    => $line['variation'],
                'name'         => $line['name'],
                'qty'          => $qty,
                'regular'      => $regular,
                'unit'         => $unit,
                'required'     => isset($required_map[$pid]),
            );
        }

        if (empty($lines)) {
            return self::fail(__('No products could be added to cart.', 'ffl-funnels-addons'), array('errors' => $errors));
        }

        // 2. One cart line per item, all sharing the group hash.
        $cart  = WC()->cart;
        $hash  = wp_generate_password(16, false, false);
        $added = array();

        foreach ($lines as $line) {
            $cart_item_data = array(
                self::META_BUNDLE_ID      => $bundle_id,
                self::META_BUNDLE_HASH    => $hash,
                self::META_BUNDLE_NAME    => (string) $bundle->name,
                self::META_UNIT_PRICE     => $line['unit'],
                self::META_REGULAR_PRICE  => $line['regular'],
                self::META_SOURCE_PRODUCT => $source_product_id,
            );

            $before = self::notices_snapshot();
            $key    = $cart->add_to_cart($line['product_id'], $line['qty'], $line['variation_id'], $line['variation'], $cart_item_data);

            if (!$key) {
                $message = self::take_new_error_notices($before, sprintf(
                    /* translators: %s: product name */
                    __('"%s" could not be added to the cart.', 'ffl-funnels-addons'),
                    $line['name']
                ));
                if ($line['required']) {
                    self::remove_lines_quietly($cart, $added);
                    return self::fail($message, $errors ? array('errors' => $errors) : array());
                }
                $errors[] = $message;
                continue;
            }

            // Two items that resolve to the same variation share one line.
            if (!in_array($key, $added, true)) {
                $added[] = $key;
            }
        }

        if (empty($added)) {
            return self::fail(__('Could not add the bundle to cart.', 'ffl-funnels-addons'), array('errors' => $errors));
        }

        // Seal the group: each line records the group's size and its own
        // quantity. A missing line or a changed quantity loses the bundle price.
        $contents = $cart->get_cart_contents();
        foreach ($added as $key) {
            if (isset($contents[$key])) {
                $contents[$key][self::META_GROUP_SIZE] = count($added);
                $contents[$key][self::META_LINE_QTY]   = $contents[$key]['quantity'];
            }
        }
        $cart->set_cart_contents($contents);
        $cart->calculate_totals();

        $data = array(
            'message'   => __('Bundle added to cart.', 'ffl-funnels-addons'),
            'added'     => count($added),
            'cart_keys' => $added,
        );
        if (!empty($errors)) {
            $data['errors'] = $errors;
        }

        return array('success' => true, 'status' => 200, 'data' => $data);
    }

    /**
     * Failed add-to-cart result. Sent as HTTP 200 with success = false, as
     * before; only an invalid nonce is a 403.
     */
    private static function fail($message, array $extra = array(), $status = 200)
    {
        return array(
            'success' => false,
            'status'  => (int) $status,
            'data'    => array_merge(array('message' => $message), $extra),
        );
    }

    /**
     * Items that can be bought now, in order; required items always stay so
     * the request fails with the reason.
     *
     * @param int[] $ids          Resolved item IDs.
     * @param array $qty_map      product_id => units.
     * @param int[] $required_ids Required item IDs.
     * @return int[]
     */
    private static function available_items(array $ids, array $qty_map, array $required_ids)
    {
        $required = array_flip($required_ids);
        $out      = array();
        foreach ($ids as $pid) {
            $qty = isset($qty_map[$pid]) ? max(1, (int) $qty_map[$pid]) : 1;
            if (isset($required[$pid]) || '' === WooBooster_Bundle::prepare_line($pid, $qty)['error']) {
                $out[] = $pid;
            }
        }

        return $out;
    }

    /**
     * Run WooCommerce's add-to-cart validation for one item, as the product
     * page's own form would (other plugins use it to refuse products).
     *
     * @return string Empty when the item passed, otherwise why it did not.
     */
    private static function run_add_to_cart_validation(array $line, $qty)
    {
        $before = self::notices_snapshot();
        $passed = apply_filters(
            'woocommerce_add_to_cart_validation',
            true,
            $line['product_id'],
            $qty,
            $line['variation_id'],
            $line['variation']
        );

        if ($passed) {
            return '';
        }

        return self::take_new_error_notices($before, sprintf(
            /* translators: %s: product name */
            __('"%s" cannot be added to the cart.', 'ffl-funnels-addons'),
            $line['name']
        ));
    }

    /**
     * Current WooCommerce notices (null when there is no session).
     */
    private static function notices_snapshot()
    {
        if (!function_exists('wc_get_notices') || !function_exists('WC') || !WC()->session) {
            return null;
        }
        return wc_get_notices();
    }

    /**
     * Error notices added since $before, as plain text, and remove them from
     * the session: the widget shows the message, so it must not appear again
     * on the next page.
     *
     * @param array|null $before   notices_snapshot() result.
     * @param string     $fallback Message when no notice was added.
     * @return string
     */
    private static function take_new_error_notices($before, $fallback)
    {
        if (null === $before) {
            return $fallback;
        }

        $after  = wc_get_notices();
        $known  = isset($before['error']) ? count((array) $before['error']) : 0;
        $errors = isset($after['error']) ? array_slice((array) $after['error'], $known) : array();

        if (function_exists('wc_set_notices')) {
            wc_set_notices($before);
        }

        $messages = array();
        foreach ($errors as $notice) {
            $text = is_array($notice) ? (isset($notice['notice']) ? $notice['notice'] : '') : (string) $notice;
            $text = trim(html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8'));
            if ('' !== $text) {
                $messages[] = $text;
            }
        }

        return $messages ? implode(' ', $messages) : $fallback;
    }

    /**
     * Remove lines this class added or is cleaning up, without the group
     * hooks reacting and without offering "Undo" for them.
     *
     * @param WC_Cart  $cart Cart.
     * @param string[] $keys Cart item keys.
     */
    private static function remove_lines_quietly($cart, array $keys)
    {
        if (empty($keys)) {
            return;
        }

        $was           = self::$syncing;
        self::$syncing = true;
        foreach ($keys as $key) {
            $cart->remove_cart_item($key);
        }
        self::$syncing = $was;

        $removed = $cart->get_removed_cart_contents();
        foreach ($keys as $key) {
            unset($removed[$key]);
        }
        $cart->set_removed_cart_contents($removed);
    }

    /* ── Group behaviour ──────────────────────────────────────────── */

    /**
     * Number of lines present per group hash.
     *
     * @param array $contents Cart contents.
     * @return array<string, int>
     */
    private static function count_group_lines(array $contents)
    {
        $counts = array();
        foreach ($contents as $cart_item) {
            if (self::is_item_line($cart_item)) {
                $hash          = (string) $cart_item[self::META_BUNDLE_HASH];
                $counts[$hash] = (isset($counts[$hash]) ? $counts[$hash] : 0) + 1;
            }
        }
        return $counts;
    }

    /**
     * Every line of this line's group is in the cart.
     */
    private static function group_is_complete(array $cart_item, array $group_counts)
    {
        $hash = (string) $cart_item[self::META_BUNDLE_HASH];
        $size = isset($cart_item[self::META_GROUP_SIZE]) ? (int) $cart_item[self::META_GROUP_SIZE] : 0;

        return $size > 0 && isset($group_counts[$hash]) && $group_counts[$hash] === $size;
    }

    /**
     * The group is complete and this line still has the quantity it was added with.
     */
    private static function line_keeps_bundle_price(array $cart_item, array $group_counts)
    {
        if (!self::group_is_complete($cart_item, $group_counts)) {
            return false;
        }
        if (!isset($cart_item[self::META_LINE_QTY], $cart_item['quantity'])) {
            return false;
        }

        return abs((float) $cart_item['quantity'] - (float) $cart_item[self::META_LINE_QTY]) < 0.0001;
    }

    /**
     * Removing one line of a bundle removes the rest of its group.
     *
     * @param string  $cart_item_key Removed line.
     * @param WC_Cart $cart          Cart.
     */
    public static function remove_group_with_item($cart_item_key, $cart)
    {
        if (self::$syncing || !$cart || !is_object($cart) || !method_exists($cart, 'get_removed_cart_contents')) {
            return;
        }

        $removed = $cart->get_removed_cart_contents();
        if (!isset($removed[$cart_item_key]) || !self::is_item_line($removed[$cart_item_key])) {
            return;
        }

        $hash = (string) $removed[$cart_item_key][self::META_BUNDLE_HASH];

        self::$syncing = true;
        foreach ($cart->get_cart() as $key => $cart_item) {
            if ($key !== $cart_item_key && self::is_item_line($cart_item) && $hash === (string) $cart_item[self::META_BUNDLE_HASH]) {
                $cart->remove_cart_item($key);
            }
        }
        self::$syncing = false;
    }

    /**
     * "Undo" on one removed bundle line restores its whole group.
     *
     * @param string  $cart_item_key Restored line.
     * @param WC_Cart $cart          Cart.
     */
    public static function restore_group_with_item($cart_item_key, $cart)
    {
        if (self::$syncing || !$cart || !is_object($cart) || !method_exists($cart, 'get_removed_cart_contents')) {
            return;
        }

        $contents = $cart->get_cart_contents();
        if (!isset($contents[$cart_item_key]) || !self::is_item_line($contents[$cart_item_key])) {
            return;
        }

        $hash = (string) $contents[$cart_item_key][self::META_BUNDLE_HASH];

        self::$syncing = true;
        foreach ($cart->get_removed_cart_contents() as $key => $cart_item) {
            if (self::is_item_line($cart_item) && $hash === (string) $cart_item[self::META_BUNDLE_HASH]) {
                $cart->restore_cart_item($key);
            }
        }
        self::$syncing = false;
    }

    /**
     * Classic cart: show a bundle line's quantity as text (it is locked).
     */
    public static function cart_item_quantity($product_quantity, $cart_item_key, $cart_item = null)
    {
        if (!self::is_item_line($cart_item)) {
            return $product_quantity;
        }

        $qty = isset($cart_item['quantity']) ? $cart_item['quantity'] : 1;

        return sprintf(
            '<span class="woobooster-bundle-qty">%1$s</span> <input type="hidden" name="cart[%2$s][qty]" value="%1$s" />',
            esc_html($qty),
            esc_attr($cart_item_key)
        );
    }

    /**
     * Classic cart "Update cart": refuse a new quantity on a bundle line
     * (0 is allowed and removes the bundle).
     */
    public static function validate_cart_quantity_update($passed, $cart_item_key, $values, $quantity)
    {
        if (!$passed || !self::is_item_line($values)) {
            return $passed;
        }

        if ((float) $quantity <= 0 || abs((float) $quantity - (float) $values['quantity']) < 0.0001) {
            return $passed;
        }

        if (function_exists('wc_add_notice')) {
            wc_add_notice(
                __('The quantity of an item in a bundle cannot be changed. Remove the bundle and add it again.', 'ffl-funnels-addons'),
                'error'
            );
        }

        return false;
    }

    /**
     * Block cart (Store API): bundle lines have a fixed quantity.
     */
    public static function store_api_quantity_editable($value, $product = null, $cart_item = null)
    {
        return self::is_item_line($cart_item) ? false : $value;
    }

    /**
     * Block cart (Store API): minimum and maximum of a bundle line are its quantity.
     */
    public static function store_api_quantity_limit($value, $product = null, $cart_item = null)
    {
        return self::is_item_line($cart_item) && isset($cart_item['quantity']) ? $cart_item['quantity'] : $value;
    }

    /* ── Pricing ──────────────────────────────────────────────────── */

    /**
     * Price bundle lines from their snapshot.
     *
     * Per-item lines: the line's discounted unit price, while its bundle is on
     * offer, its group is complete and its quantity unchanged (otherwise the
     * product's own price). Legacy lines: the stored bundle total (per unit of
     * the line, so their quantity still buys whole bundles).
     *
     * @param WC_Cart $cart The WooCommerce cart.
     */
    public static function set_bundle_price($cart)
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        if (!$cart || !is_object($cart) || !method_exists($cart, 'get_cart')) {
            return;
        }

        $contents     = $cart->get_cart();
        $group_counts = self::count_group_lines($contents);
        $on_offer     = array();

        foreach ($contents as $cart_item) {
            if (empty($cart_item['data']) || !is_object($cart_item['data'])) {
                continue;
            }

            $legacy = self::is_legacy_line($cart_item);
            if (!$legacy && !self::is_item_line($cart_item)) {
                continue;
            }

            // Don't honour the stored bundle price once the bundle is disabled or
            // its scheduled window has passed — the line may have been sitting in
            // the cart (or a persistent session) since before it expired.
            $bundle_id = isset($cart_item[self::META_BUNDLE_ID]) ? absint($cart_item[self::META_BUNDLE_ID]) : 0;
            $apply     = true;
            if ($bundle_id) {
                if (!array_key_exists($bundle_id, $on_offer)) {
                    $on_offer[$bundle_id] = self::bundle_is_on_offer($bundle_id);
                }
                $apply = $on_offer[$bundle_id];
            } elseif (!$legacy) {
                $apply = false;
            }

            $target = $legacy ? (float) $cart_item[self::META_BUNDLE_TOTAL] : (float) $cart_item[self::META_UNIT_PRICE];
            if ($apply && !$legacy) {
                $apply = self::line_keeps_bundle_price($cart_item, $group_counts);
            }

            if ($apply) {
                $cart_item['data']->set_price($target);
            } else {
                self::reset_price_if_ours($cart_item, $target);
            }
        }
    }

    /**
     * A line that lost its bundle price earlier in this request still holds
     * it on its product object: put the product's own price back (only when
     * the price is the one this class set, so other pricing plugins win).
     *
     * @param array $cart_item Cart item.
     * @param float $ours      Price this class would set.
     */
    private static function reset_price_if_ours(array $cart_item, $ours)
    {
        if (abs((float) $cart_item['data']->get_price() - (float) $ours) > 0.0001) {
            return;
        }

        $id      = !empty($cart_item['variation_id']) ? $cart_item['variation_id'] : (isset($cart_item['product_id']) ? $cart_item['product_id'] : 0);
        $product = $id ? wc_get_product($id) : null;
        if ($product) {
            $cart_item['data']->set_price($product->get_price());
        }
    }

    /**
     * Remove bundle lines whose bundle is no longer on offer, and groups that
     * lost a line (for example a product WooCommerce dropped because it can
     * no longer be bought). One notice per bundle.
     *
     * Without this, a line whose bundle was switched off or whose schedule
     * ended kept its bundle details but fell back to the product's own
     * price, and an incomplete group left other items at the bundle price.
     *
     * @param WC_Cart $cart The WooCommerce cart.
     */
    public static function remove_unavailable_bundles($cart)
    {
        if (!$cart || !is_object($cart) || !method_exists($cart, 'get_cart')) {
            return;
        }

        $contents     = $cart->get_cart();
        $group_counts = self::count_group_lines($contents);
        $on_offer     = array();
        $remove       = array();
        $notices      = array();

        foreach ($contents as $key => $cart_item) {
            if (empty($cart_item[self::META_BUNDLE_ID])) {
                continue;
            }
            $legacy = self::is_legacy_line($cart_item);
            if (!$legacy && !self::is_item_line($cart_item)) {
                continue;
            }

            $group     = $legacy ? 'line:' . $key : 'group:' . $cart_item[self::META_BUNDLE_HASH];
            $bundle_id = absint($cart_item[self::META_BUNDLE_ID]);
            if (!array_key_exists($bundle_id, $on_offer)) {
                $on_offer[$bundle_id] = self::bundle_is_on_offer($bundle_id);
            }

            if (!$on_offer[$bundle_id]) {
                $remove[]        = $key;
                $notices[$group] = __('A bundle in your cart is no longer available and was removed.', 'ffl-funnels-addons');
                continue;
            }

            if (!$legacy && !self::group_is_complete($cart_item, $group_counts)) {
                $remove[] = $key;
                if (!isset($notices[$group])) {
                    $notices[$group] = __('A bundle in your cart was missing some of its items and was removed.', 'ffl-funnels-addons');
                }
            }
        }

        if (empty($remove)) {
            return;
        }

        self::remove_lines_quietly($cart, $remove);

        if (function_exists('wc_add_notice') && (!is_admin() || wp_doing_ajax())) {
            foreach ($notices as $message) {
                wc_add_notice($message, 'notice');
            }
        }

        // Recalculate so the session (and its totals) drop the lines now.
        $cart->calculate_totals();
    }

    /**
     * Bundle exists, is switched on and inside its schedule.
     *
     * @param int $bundle_id Bundle ID.
     */
    private static function bundle_is_on_offer($bundle_id)
    {
        $bundle = WooBooster_Bundle::get($bundle_id);

        return $bundle && $bundle->status && self::bundle_schedule_is_active($bundle);
    }

    /**
     * Whether a bundle is inside its scheduled window.
     *
     * start_date/end_date are stored as GMT (the admin form converts via
     * get_gmt_from_date), so compare against GMT "now" — the same clock the
     * matcher uses. An empty bound means "unbounded".
     *
     * @param object $bundle Bundle row.
     * @return bool
     */
    private static function bundle_schedule_is_active($bundle)
    {
        $now = current_time('mysql', true);

        if (!empty($bundle->start_date) && $now < $bundle->start_date) {
            return false;
        }
        if (!empty($bundle->end_date) && $now > $bundle->end_date) {
            return false;
        }

        return true;
    }

    /* ── Coupons ──────────────────────────────────────────────────── */

    /**
     * Item coupons (percent, fixed product) that exclude sale items do not
     * discount bundle lines sold below the item's own price.
     */
    public static function coupon_valid_for_product($valid, $product, $coupon = null, $values = array())
    {
        if (!$valid || !is_object($coupon) || !method_exists($coupon, 'get_exclude_sale_items') || !$coupon->get_exclude_sale_items()) {
            return $valid;
        }

        return self::is_discounted_line($values) ? false : $valid;
    }

    /**
     * Cart coupons (fixed cart) that exclude sale items: WooCommerce refuses
     * them when a sale item is in the cart; a discounted bundle line counts
     * as one.
     *
     * @throws Exception Coupon not valid (WooCommerce shows the message).
     */
    public static function coupon_is_valid($valid, $coupon = null, $discounts = null)
    {
        if (!$valid || !is_object($coupon) || !method_exists($coupon, 'get_exclude_sale_items') || !$coupon->get_exclude_sale_items()) {
            return $valid;
        }
        if (function_exists('wc_get_product_coupon_types') && $coupon->is_type(wc_get_product_coupon_types())) {
            return $valid; // Item coupons: handled per line by coupon_valid_for_product().
        }
        if (!is_object($discounts) || !method_exists($discounts, 'get_items')) {
            return $valid;
        }

        foreach ((array) $discounts->get_items() as $item) {
            if (isset($item->object) && self::is_discounted_line($item->object)) {
                $message = method_exists($coupon, 'get_coupon_error')
                    ? $coupon->get_coupon_error(110) // WC_Coupon::E_WC_COUPON_NOT_VALID_SALE_ITEMS
                    : __('Sorry, this coupon is not valid for sale items.', 'ffl-funnels-addons');
                throw new Exception($message, 110); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }

        return $valid;
    }

    /* ── Display ──────────────────────────────────────────────────── */

    /**
     * Bundle name for a line: the bundle's current name, or the name saved
     * when it was added.
     */
    private static function bundle_name($cart_item)
    {
        $bundle = !empty($cart_item[self::META_BUNDLE_ID]) ? WooBooster_Bundle::get((int) $cart_item[self::META_BUNDLE_ID]) : null;
        if ($bundle && !empty($bundle->name)) {
            return (string) $bundle->name;
        }

        return isset($cart_item[self::META_BUNDLE_NAME]) ? (string) $cart_item[self::META_BUNDLE_NAME] : '';
    }

    /**
     * Legacy lines: show the bundle name instead of the representative
     * product's name. Per-item lines keep the product's own name.
     */
    public static function cart_item_name($name, $cart_item)
    {
        if (empty($cart_item[self::META_BUNDLE_ID]) || !self::is_legacy_line($cart_item)) {
            return $name;
        }

        $bundle_name = self::bundle_name($cart_item);

        return '' !== $bundle_name ? esc_html($bundle_name) : $name;
    }

    /**
     * Per-item lines: "Bundle: {name}" (classic cart, mini-cart, checkout and
     * the block cart through the Store API). Legacy lines: the "Includes" list.
     *
     * Each legacy item gets its own block with padding and a faint bottom
     * border so the list reads cleanly in both the mini-cart and the checkout
     * review column. Plain-text `value` is preserved for emails / order
     * details that strip HTML.
     */
    public static function cart_item_data_display($item_data, $cart_item)
    {
        if (self::is_item_line($cart_item)) {
            $bundle_name = self::bundle_name($cart_item);
            if ('' !== $bundle_name) {
                $item_data[] = array(
                    'key'   => __('Bundle', 'ffl-funnels-addons'),
                    'value' => $bundle_name,
                );
            }
            return $item_data;
        }

        if (empty($cart_item[self::META_BUNDLE_ITEMS]) || !is_array($cart_item[self::META_BUNDLE_ITEMS])) {
            return $item_data;
        }

        $lines      = array();
        $html_lines = array();
        foreach ($cart_item[self::META_BUNDLE_ITEMS] as $bi) {
            if (empty($bi['name'])) {
                continue;
            }
            $qty   = isset($bi['quantity']) ? max(1, (int) $bi['quantity']) : 1;
            $label = ($qty > 1 ? $qty . '× ' : '') . $bi['name'];

            $lines[]      = $label;
            $html_lines[] = '<li style="padding:4px 0;border-bottom:1px solid rgba(0,0,0,0.06);">' . esc_html($label) . '</li>';
        }

        if (!empty($html_lines)) {
            // Drop the bottom border on the last item so the list ends cleanly.
            $last_index = count($html_lines) - 1;
            $html_lines[$last_index] = str_replace('border-bottom:1px solid rgba(0,0,0,0.06);', '', $html_lines[$last_index]);

            $display = '<ul class="woobooster-bundle-includes" style="margin:4px 0 0;padding:0;list-style:none;font-size:0.9em;">' . implode('', $html_lines) . '</ul>';
            $item_data[] = array(
                'key'     => __('Includes', 'ffl-funnels-addons'),
                'value'   => implode(', ', $lines),
                'display' => $display,
            );
        }

        return $item_data;
    }

    /**
     * Per-item lines at the bundle price: show the item's own price struck
     * through next to it (classic cart and mini-cart).
     */
    public static function cart_item_price($price_html, $cart_item, $cart_item_key = '')
    {
        if (!self::is_discounted_line($cart_item) || empty($cart_item['data']) || !is_object($cart_item['data'])) {
            return $price_html;
        }
        // Only while the bundle price is applied to the line.
        if (abs((float) $cart_item['data']->get_price() - (float) $cart_item[self::META_UNIT_PRICE]) > 0.0001) {
            return $price_html;
        }
        if (!function_exists('wc_format_sale_price') || !function_exists('WC') || !WC()->cart) {
            return $price_html;
        }

        $args    = array('qty' => 1, 'price' => (float) $cart_item[self::META_REGULAR_PRICE]);
        $regular = WC()->cart->display_prices_including_tax()
            ? wc_get_price_including_tax($cart_item['data'], $args)
            : wc_get_price_excluding_tax($cart_item['data'], $args);

        return wc_format_sale_price($regular, $price_html);
    }

    /**
     * Legacy lines: swap the thumbnail with the bundle's own image (when set).
     * Per-item lines show their product's image.
     *
     * @param string $thumbnail Default thumbnail HTML.
     * @param array  $cart_item Cart item data.
     * @param string $cart_item_key Cart item key (unused but part of the filter signature).
     * @return string
     */
    public static function cart_item_thumbnail($thumbnail, $cart_item, $cart_item_key)
    {
        if (empty($cart_item[self::META_BUNDLE_ID]) || !self::is_legacy_line($cart_item)) {
            return $thumbnail;
        }

        $bundle = WooBooster_Bundle::get((int) $cart_item[self::META_BUNDLE_ID]);
        if (!$bundle || empty($bundle->image_id)) {
            return $thumbnail;
        }

        $custom = wp_get_attachment_image(
            (int) $bundle->image_id,
            'woocommerce_thumbnail',
            false,
            array('class' => 'attachment-woocommerce_thumbnail size-woocommerce_thumbnail')
        );

        return $custom ?: $thumbnail;
    }

    /**
     * Persist bundle details onto the order line item.
     *
     * Every bundle line: `_woobooster_bundle_id`. Per-item lines also get the
     * group hash (`_woobooster_bundle_hash`, links the lines of one bundle)
     * and a visible "Bundle" name; legacy lines keep "Bundle contents".
     *
     * @param WC_Order_Item_Product $item          Order line item.
     * @param string                $cart_item_key Cart item key.
     * @param array                 $values        Cart item values.
     */
    public static function add_order_line_item_meta($item, $cart_item_key, $values)
    {
        if (empty($values[self::META_BUNDLE_ID])) {
            return;
        }

        $item->add_meta_data(self::META_BUNDLE_ID, (int) $values[self::META_BUNDLE_ID], true);

        if (self::is_item_line($values)) {
            $item->add_meta_data(self::META_BUNDLE_HASH, (string) $values[self::META_BUNDLE_HASH], true);
            $bundle_name = self::bundle_name($values);
            if ('' !== $bundle_name) {
                $item->add_meta_data(__('Bundle', 'ffl-funnels-addons'), $bundle_name, true);
            }
            return;
        }

        if (!empty($values[self::META_BUNDLE_ITEMS]) && is_array($values[self::META_BUNDLE_ITEMS])) {
            $lines = array();
            foreach ($values[self::META_BUNDLE_ITEMS] as $bi) {
                if (empty($bi['name'])) {
                    continue;
                }
                $qty     = isset($bi['quantity']) ? max(1, (int) $bi['quantity']) : 1;
                $lines[] = ($qty > 1 ? $qty . '× ' : '') . $bi['name'];
            }
            if (!empty($lines)) {
                $item->add_meta_data(__('Bundle contents', 'ffl-funnels-addons'), implode(', ', $lines), true);
            }
        }
    }
}
