<?php
/** Keeps Google for WooCommerce's uploads, removals and pull API in step with this addon's decisions. */
if (!defined('ABSPATH')) {
    exit;
}

/**
 * A failure that concerns one product only. The catalog scan records the
 * product and continues instead of stopping on it.
 */
class Google_Merchant_Policy_Item_Exception extends RuntimeException
{
}

class Google_Merchant_Policy_Google_Sync
{
    const UPDATE_HOOK = 'gla/jobs/update_products/process_item';
    const UPDATE_CLASS = 'Automattic\\WooCommerce\\GoogleListingsAndAds\\Jobs\\UpdateProducts';
    const SYNCER_CLASS = 'Automattic\\WooCommerce\\GoogleListingsAndAds\\Product\\SyncerHooks';
    /** Google for WooCommerce jobs that upload products, by process hook. */
    const UPLOAD_JOBS = [
        self::UPDATE_HOOK => self::UPDATE_CLASS,
        'gla/jobs/update_all_products/process_item' => 'Automattic\\WooCommerce\\GoogleListingsAndAds\\Jobs\\UpdateAllProducts',
        'gla/jobs/resubmit_expiring_products/process_item' => 'Automattic\\WooCommerce\\GoogleListingsAndAds\\Jobs\\ResubmitExpiringProducts',
    ];
    /** Google for WooCommerce's bulk channel visibility endpoint (Product Feed page). */
    const VISIBILITY_ROUTE = '/wc/gla/mc/product-visibility';

    public static function init(): void
    {
        add_action('wp_loaded', [__CLASS__, 'protect_upload_jobs'], 100);
        // Google initializes jobs only in admin/cron/AJAX/CLI. This also covers
        // jobs registered after wp_loaded, before their priority-10 callback.
        foreach (array_keys(self::UPLOAD_JOBS) as $hook) {
            add_action($hook, [__CLASS__, 'protect_upload_jobs'], -100, 0);
        }
        add_filter('rest_pre_dispatch', [__CLASS__, 'guard_visibility_endpoint'], 10, 3);

        // Google for WooCommerce reads a variation's channel visibility from its
        // parent, and its WPCOM proxy (Google's pull API) does not filter
        // variations at all. Hide variations that are not allowed from proxy
        // requests; list queries exclude them, single requests get Google's 403.
        add_filter('woocommerce_rest_product_variation_object_query', [__CLASS__, 'filter_proxy_variation_query'], 20, 2);
        add_filter('woocommerce_rest_prepare_product_variation_object', [__CLASS__, 'filter_proxy_variation_response'], PHP_INT_MAX, 3);
    }

    /**
     * Route every Google upload job through process_update(). Queued updates are
     * already pre-filtered, but the expiring-product resubmission and full-sync
     * jobs load products by ID and read variation visibility from the parent.
     */
    public static function protect_upload_jobs(): void
    {
        global $wp_filter;
        foreach (self::UPLOAD_JOBS as $hook_name => $class) {
            $hook = $wp_filter[$hook_name] ?? null;
            if (!is_object($hook) || !isset($hook->callbacks)) {
                continue;
            }
            foreach ($hook->callbacks as $priority => $callbacks) {
                foreach ($callbacks as $entry) {
                    $callback = $entry['function'];
                    if (!is_array($callback) || !is_object($callback[0])
                        || get_class($callback[0]) !== $class
                        || $callback[1] !== 'handle_process_items_action') {
                        continue;
                    }
                    remove_action($hook_name, $callback, $priority);
                    add_action($hook_name, function (array $items = []) use ($callback) {
                        self::process_update($items, $callback);
                    }, $priority, 1);
                }
            }
        }
    }

    public static function process_update(array $items, callable $original): void
    {
        if ((string) Google_Merchant_Policy_Engine::get_settings()['mode'] !== 'enforce') {
            $original($items);
            return;
        }
        Google_Merchant_Policy_Engine::reset_runtime_cache();
        $remaining = [];
        $excluded = 0;
        foreach ($items as $id) {
            // Leave malformed, deleted and unrelated items to Google's normal
            // validation and failure monitoring. Never suppress arbitrary errors.
            $product = is_int($id) && $id > 0 ? wc_get_product($id) : false;
            if (!$product || Google_Merchant_Policy_Engine::evaluate_product($product)['status'] === 'allowed') {
                $remaining[] = $id;
                continue;
            }
            Google_Merchant_Policy_Engine::apply_to_product($product);
            try {
                self::request_withdrawal($product);
            } catch (\Throwable $e) {
                // The product still stays out of this upload. A failed removal
                // request must not drop the allowed products in Google's batch;
                // the catalog scan reports the product for review.
                self::log_warning('Google removal could not be requested during a queued update', $product, $e);
            }
            $excluded++;
        }
        if ($remaining || $excluded === 0) {
            $original($remaining);
        }
        // An entirely policy-excluded job has nothing to upload. Completing it
        // avoids Google's empty-list exception / immediate-retry loop.
    }

    /**
     * Request removal through Google's own ID mapping and DeleteProducts job.
     * true means a request was delegated, NOT that Google confirmed deletion.
     * Does not trash/delete the WooCommerce product or invoke other save hooks.
     */
    public static function request_withdrawal($product): bool
    {
        if ((string) Google_Merchant_Policy_Engine::get_settings()['mode'] !== 'enforce'
            || (string) $product->get_meta(Google_Merchant_Policy_Engine::VISIBILITY_META, true) !== 'dont-sync-and-show'
            || $product->is_type('variable')
            || empty($product->get_meta('_wc_gla_google_ids', true))) {
            return false;
        }
        if (empty($product->get_meta('_wc_gla_synced_at', true))) {
            // A problem with this one product: the scan records it and moves on.
            throw new Google_Merchant_Policy_Item_Exception(__('Google product IDs exist without a sync timestamp. Review this product in Google for WooCommerce.', 'ffl-funnels-addons'));
        }
        $syncer = self::find_syncer();
        if ($syncer && is_callable([$syncer, 'pre_delete']) && is_callable([$syncer, 'delete'])) {
            $syncer->pre_delete((int) $product->get_id());
            $syncer->delete((int) $product->get_id());
            return true;
        }
        throw new RuntimeException(__('Feed exclusion saved, but Google removal could not be requested. Check the Google for WooCommerce connection and resume the scan.', 'ffl-funnels-addons'));
    }

    /**
     * Ask Google for WooCommerce to upload a product this addon now allows, through
     * the same flow it runs when a product is saved. Its update job re-checks the
     * decision. false means nothing was requested: with Merchant Center not
     * connected, Google for WooCommerce syncs every eligible product once it is.
     */
    public static function request_upload($product): bool
    {
        if ((string) Google_Merchant_Policy_Engine::get_settings()['mode'] !== 'enforce'
            || !is_object($product) || !method_exists($product, 'get_id')) {
            return false;
        }
        $syncer = self::find_syncer();
        if (!$syncer || !is_callable([$syncer, 'update_by_object'])) {
            return false;
        }
        $syncer->update_by_object((int) $product->get_id(), $product);
        return true;
    }

    /**
     * In Enforce, this addon decides what reaches Google, so Google for
     * WooCommerce's bulk visibility edit is refused with an explanation (its
     * Product Feed page shows the message) instead of being silently reverted.
     *
     * @param mixed $result  Response to short-circuit with, or null.
     * @param mixed $server  WP_REST_Server.
     * @param mixed $request WP_REST_Request.
     * @return mixed
     */
    public static function guard_visibility_endpoint($result, $server, $request)
    {
        if ($result !== null || !is_object($request) || !method_exists($request, 'get_route')
            || rtrim((string) $request->get_route(), '/') !== self::VISIBILITY_ROUTE
            || !in_array(strtoupper((string) $request->get_method()), ['POST', 'PUT', 'PATCH'], true)
            || (string) Google_Merchant_Policy_Engine::get_settings()['mode'] !== 'enforce') {
            return $result;
        }
        return new WP_Error(
            'ffla_gmp_visibility_managed',
            __('Google Merchant Policy (FFL Funnels) is in Enforce mode and decides which products reach Google. Use the Google bulk actions in Products or the Google Merchant Policy box on the product page.', 'ffl-funnels-addons'),
            ['status' => 409]
        );
    }

    /** Google for WooCommerce's SyncerHooks service, registered only when Merchant Center is ready. */
    private static function find_syncer()
    {
        global $wp_filter;
        $hook = $wp_filter['woocommerce_update_product'] ?? null;
        if (!is_object($hook) || !isset($hook->callbacks)) {
            return null;
        }
        foreach ($hook->callbacks as $callbacks) {
            foreach ($callbacks as $entry) {
                $callback = $entry['function'];
                if (is_array($callback) && is_object($callback[0]) && is_a($callback[0], self::SYNCER_CLASS)) {
                    return $callback[0];
                }
            }
        }
        return null;
    }

    /**
     * Exclude variations that are not allowed from a WPCOM proxy variation list.
     *
     * WooCommerce applies this filter before it sets post_parent, so the parent
     * comes from the request route.
     *
     * @param mixed $args    WP_Query arguments.
     * @param mixed $request WP_REST_Request.
     * @return mixed
     */
    public static function filter_proxy_variation_query($args, $request)
    {
        if (!is_array($args) || !self::is_enforced_proxy_request($request)) {
            return $args;
        }
        $parent = wc_get_product((int) $request->get_param('product_id'));
        if (!$parent || !method_exists($parent, 'get_children')) {
            return $args;
        }

        $hidden = [];
        foreach ((array) $parent->get_children() as $child_id) {
            if (Google_Merchant_Policy_Engine::evaluate_product((int) $child_id)['status'] !== 'allowed') {
                $hidden[] = (int) $child_id;
            }
        }
        if ($hidden) {
            $args['post__not_in'] = array_values(array_unique(array_merge(
                array_map('intval', (array) ($args['post__not_in'] ?? [])),
                $hidden
            )));
        }
        return $args;
    }

    /**
     * Answer a single WPCOM proxy variation request like Google's own proxy does
     * for a product that is not syncable.
     *
     * @param mixed $response  WP_REST_Response.
     * @param mixed $variation WC_Product_Variation.
     * @param mixed $request   WP_REST_Request.
     * @return mixed
     */
    public static function filter_proxy_variation_response($response, $variation, $request)
    {
        if (!$response instanceof WP_REST_Response || !self::is_enforced_proxy_request($request)
            || !preg_match('#/variations/\d+$#', (string) $request->get_route())
            || Google_Merchant_Policy_Engine::evaluate_product($variation)['status'] === 'allowed') {
            return $response;
        }

        return new WP_REST_Response([
            'code'    => 'gla_rest_item_no_syncable',
            'message' => 'Item not syncable',
            'data'    => ['status' => 403],
        ], 403);
    }

    /** Google's pull API marks its requests with gla_syncable=1. */
    private static function is_enforced_proxy_request($request): bool
    {
        return is_object($request) && method_exists($request, 'get_param')
            && (string) $request->get_param('gla_syncable') === '1'
            && (string) Google_Merchant_Policy_Engine::get_settings()['mode'] === 'enforce';
    }

    private static function log_warning(string $message, $product, \Throwable $e): void
    {
        if (!function_exists('wc_get_logger')) {
            return;
        }
        wc_get_logger()->warning(sprintf(
            '%s (product %d): %s',
            $message,
            method_exists($product, 'get_id') ? (int) $product->get_id() : 0,
            $e->getMessage()
        ), ['source' => 'ffla-google-merchant-policy']);
    }
}
