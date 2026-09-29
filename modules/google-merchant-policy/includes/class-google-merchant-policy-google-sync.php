<?php
/** Narrow compatibility adapter for Google for WooCommerce's queued updates. */
if (!defined('ABSPATH')) {
    exit;
}

class Google_Merchant_Policy_Google_Sync
{
    const UPDATE_HOOK = 'gla/jobs/update_products/process_item';
    const UPDATE_CLASS = 'Automattic\\WooCommerce\\GoogleListingsAndAds\\Jobs\\UpdateProducts';
    const SYNCER_CLASS = 'Automattic\\WooCommerce\\GoogleListingsAndAds\\Product\\SyncerHooks';

    public static function init(): void
    {
        add_action('wp_loaded', [__CLASS__, 'protect_update_job'], 100);
        // Google initializes jobs only in admin/cron/AJAX/CLI. This also covers
        // jobs registered after wp_loaded, before their priority-10 callback.
        add_action(self::UPDATE_HOOK, [__CLASS__, 'protect_update_job'], -100, 0);

        // Google for WooCommerce reads a variation's channel visibility from its
        // parent, and its WPCOM proxy (Google's pull API) does not filter
        // variations at all. Hide variations that are not allowed from proxy
        // requests; list queries exclude them, single requests get Google's 403.
        add_filter('woocommerce_rest_product_variation_object_query', [__CLASS__, 'filter_proxy_variation_query'], 20, 2);
        add_filter('woocommerce_rest_prepare_product_variation_object', [__CLASS__, 'filter_proxy_variation_response'], PHP_INT_MAX, 3);
    }

    public static function protect_update_job(): void
    {
        global $wp_filter;
        $hook = $wp_filter[self::UPDATE_HOOK] ?? null;
        if (!is_object($hook) || !isset($hook->callbacks)) {
            return;
        }
        foreach ($hook->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $entry) {
                $callback = $entry['function'];
                if (!is_array($callback) || !is_object($callback[0])
                    || get_class($callback[0]) !== self::UPDATE_CLASS
                    || $callback[1] !== 'handle_process_items_action') {
                    continue;
                }
                remove_action(self::UPDATE_HOOK, $callback, $priority);
                add_action(self::UPDATE_HOOK, function (array $items = []) use ($callback) {
                    self::process_update($items, $callback);
                }, $priority, 1);
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
            throw new RuntimeException(__('Google product IDs exist without a sync timestamp. Review this product in Google for WooCommerce before resuming removal.', 'ffl-funnels-addons'));
        }
        global $wp_filter;
        $hook = $wp_filter['woocommerce_update_product'] ?? null;
        if (is_object($hook) && isset($hook->callbacks)) {
            foreach ($hook->callbacks as $callbacks) {
                foreach ($callbacks as $entry) {
                    $callback = $entry['function'];
                    if (is_array($callback) && is_object($callback[0])
                        && is_a($callback[0], self::SYNCER_CLASS)
                        && is_callable([$callback[0], 'pre_delete']) && is_callable([$callback[0], 'delete'])) {
                        $callback[0]->pre_delete((int) $product->get_id());
                        $callback[0]->delete((int) $product->get_id());
                        return true;
                    }
                }
            }
        }
        throw new RuntimeException(__('Feed exclusion saved, but Google removal could not be requested. Check the Google for WooCommerce connection and resume the scan.', 'ffl-funnels-addons'));
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
