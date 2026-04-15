<?php
/**
 * WSS REST Routes.
 *
 * Internal/admin-oriented endpoints for product/variation/attribute upsert.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_REST_Routes
{
    /** @var WSS_Attribute_Upsert_Service */
    private $attribute_service;

    /** @var WSS_Product_Upsert_Service */
    private $product_service;

    /** @var WSS_Variation_Upsert_Service */
    private $variation_service;

    public function __construct(
        WSS_Attribute_Upsert_Service $attribute_service,
        WSS_Product_Upsert_Service $product_service,
        WSS_Variation_Upsert_Service $variation_service
    ) {
        $this->attribute_service = $attribute_service;
        $this->product_service   = $product_service;
        $this->variation_service = $variation_service;
    }

    public function init(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('wss/v1', '/products/upsert', [
            'methods'             => 'POST',
            'permission_callback' => [$this, 'can_manage'],
            'callback'            => [$this, 'upsert_product'],
        ]);

        register_rest_route('wss/v1', '/variations/upsert', [
            'methods'             => 'POST',
            'permission_callback' => [$this, 'can_manage'],
            'callback'            => [$this, 'upsert_variation'],
        ]);

        register_rest_route('wss/v1', '/attributes/upsert', [
            'methods'             => 'POST',
            'permission_callback' => [$this, 'can_manage'],
            'callback'            => [$this, 'upsert_attribute'],
        ]);

        register_rest_route('wss/v1', '/batch/upsert', [
            'methods'             => 'POST',
            'permission_callback' => [$this, 'can_manage'],
            'callback'            => [$this, 'batch_upsert'],
        ]);
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public function upsert_product(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $result  = $this->product_service->upsert_simple(is_array($payload) ? $payload : []);

        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 400);
        }

        return new WP_REST_Response(['status' => $result['action'] ?? 'updated', 'data' => $result], 200);
    }

    public function upsert_variation(WP_REST_Request $request): WP_REST_Response
    {
        $payload   = $request->get_json_params();
        $payload   = is_array($payload) ? $payload : [];
        $parent_id = (int) ($payload['parent_id'] ?? 0);

        $result = $this->variation_service->upsert_variation($parent_id, $payload);
        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 400);
        }

        return new WP_REST_Response(['status' => $result['action'] ?? 'updated', 'data' => $result], 200);
    }

    public function upsert_attribute(WP_REST_Request $request): WP_REST_Response
    {
        $payload  = $request->get_json_params();
        $payload  = is_array($payload) ? $payload : [];
        $label    = (string) ($payload['label'] ?? '');
        $value    = (string) ($payload['value'] ?? '');
        $taxonomy = (string) ($payload['taxonomy'] ?? '');
        if ($taxonomy === '' && $label !== '') {
            $taxonomy = $this->attribute_service->resolve_global_taxonomy_by_label($label);
        }

        $term = $this->attribute_service->ensure_term($taxonomy, $value);
        if (is_wp_error($term)) {
            return new WP_REST_Response(['error' => $term->get_error_message()], 400);
        }

        return new WP_REST_Response([
            'status' => 'upserted',
            'data'   => [
                'taxonomy' => $taxonomy,
                'term_id'  => (int) $term->term_id,
                'slug'     => (string) $term->slug,
                'name'     => (string) $term->name,
            ],
        ], 200);
    }

    public function batch_upsert(WP_REST_Request $request): WP_REST_Response
    {
        $payload = $request->get_json_params();
        $items   = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $results = [];

        foreach ($items as $idx => $item) {
            $kind    = (string) ($item['kind'] ?? '');
            $data    = is_array($item['payload'] ?? null) ? $item['payload'] : [];
            $entry   = ['index' => (int) $idx, 'kind' => $kind, 'status' => 'skipped'];

            if ($kind === 'product') {
                $res = $this->product_service->upsert_simple($data);
                $entry = is_wp_error($res)
                    ? ['index' => (int) $idx, 'kind' => $kind, 'status' => 'error', 'message' => $res->get_error_message()]
                    : ['index' => (int) $idx, 'kind' => $kind, 'status' => (string) ($res['action'] ?? 'updated'), 'data' => $res];
            } elseif ($kind === 'variation') {
                $parent_id = (int) ($data['parent_id'] ?? 0);
                $res = $this->variation_service->upsert_variation($parent_id, $data);
                $entry = is_wp_error($res)
                    ? ['index' => (int) $idx, 'kind' => $kind, 'status' => 'error', 'message' => $res->get_error_message()]
                    : ['index' => (int) $idx, 'kind' => $kind, 'status' => (string) ($res['action'] ?? 'updated'), 'data' => $res];
            } elseif ($kind === 'attribute') {
                $taxonomy = (string) ($data['taxonomy'] ?? '');
                $label    = (string) ($data['label'] ?? '');
                $value    = (string) ($data['value'] ?? '');
                if ($taxonomy === '' && $label !== '') {
                    $taxonomy = $this->attribute_service->resolve_global_taxonomy_by_label($label);
                }
                $res = $this->attribute_service->ensure_term($taxonomy, $value);
                $entry = is_wp_error($res)
                    ? ['index' => (int) $idx, 'kind' => $kind, 'status' => 'error', 'message' => $res->get_error_message()]
                    : ['index' => (int) $idx, 'kind' => $kind, 'status' => 'upserted', 'data' => ['taxonomy' => $taxonomy, 'term_id' => (int) $res->term_id]];
            }

            $results[] = $entry;
        }

        return new WP_REST_Response(['results' => $results], 200);
    }
}

