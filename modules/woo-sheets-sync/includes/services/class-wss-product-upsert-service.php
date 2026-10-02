<?php
/**
 * WSS Product Upsert Service.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Product_Upsert_Service
{
    /** @var WSS_Attribute_Upsert_Service */
    private $attribute_service;

    public function __construct(WSS_Attribute_Upsert_Service $attribute_service)
    {
        $this->attribute_service = $attribute_service;
    }

    /** Post statuses a REST caller may set. */
    private const ALLOWED_STATUSES = ['publish', 'draft', 'pending', 'private'];

    /**
     * Normalize an API or sheet payload: "manage_stock" may be a boolean or
     * TRUE/FALSE/yes/no/1/0, and "attributes" may be the sheet string or an
     * array ([{label, value}], [{name, option}] or [label => value]).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public static function normalize_payload(array $payload): array
    {
        if (array_key_exists('manage_stock', $payload) && class_exists('WSS_Sync_Engine')) {
            $payload['manage_stock'] = WSS_Sync_Engine::normalize_manage_stock($payload['manage_stock']);
        }
        if (array_key_exists('attributes', $payload)) {
            $payload['attributes'] = self::attributes_to_string($payload['attributes']);
        }
        foreach (['sku', 'name', 'regular_price', 'sale_price', 'stock_qty', 'stock_status', 'status', 'type'] as $key) {
            if (isset($payload[$key]) && !is_scalar($payload[$key])) {
                $payload[$key] = '';
            }
        }

        return $payload;
    }

    /**
     * "Label: Value | Label: Value" from an attribute string or array.
     *
     * @param mixed $attributes
     */
    public static function attributes_to_string($attributes): string
    {
        if (is_string($attributes)) {
            return $attributes;
        }
        if (!is_array($attributes)) {
            return '';
        }

        $parts = [];
        foreach ($attributes as $key => $item) {
            if (is_array($item)) {
                $label = $item['label'] ?? ($item['name'] ?? '');
                $value = $item['value'] ?? ($item['option'] ?? '');
            } else {
                $label = is_string($key) ? $key : '';
                $value = $item;
            }
            if (!is_scalar($label) || !is_scalar($value)) {
                continue;
            }
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            $parts[] = $label . ': ' . trim((string) $value);
        }

        return implode(' | ', $parts);
    }

    /**
     * Create or update a simple product.
     *
     * Target: "product_id" when given (must be a simple product), otherwise the
     * simple product that owns "sku", otherwise a new product. "status" may be
     * publish, draft, pending or private (new products default to publish);
     * "type" may only be "simple".
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function upsert_simple(array $payload)
    {
        $payload = self::normalize_payload($payload);

        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        if ($type !== '' && $type !== 'simple') {
            return new WP_Error('wss_product', __('Only simple products can be created or updated here; use the variations endpoint for variations.', 'ffl-funnels-addons'));
        }

        $status = sanitize_key((string) ($payload['status'] ?? ''));
        if ($status !== '' && !in_array($status, self::ALLOWED_STATUSES, true)) {
            return new WP_Error('wss_product', __('Status must be publish, draft, pending or private.', 'ffl-funnels-addons'));
        }

        $name       = trim((string) ($payload['name'] ?? ''));
        $sku        = trim((string) ($payload['sku'] ?? ''));
        $product_id = (int) ($payload['product_id'] ?? 0);

        if ($product_id > 0) {
            $existing = wc_get_product($product_id);
            if (!$existing || !$existing->is_type('simple')) {
                return new WP_Error(
                    'wss_product',
                    sprintf(__('Product #%d does not exist or is not a simple product.', 'ffl-funnels-addons'), $product_id)
                );
            }
            if ($sku !== '') {
                $owner_id = (int) wc_get_product_id_by_sku($sku);
                if ($owner_id > 0 && $owner_id !== $product_id) {
                    return new WP_Error(
                        'wss_product',
                        sprintf(__('SKU "%1$s" already belongs to WooCommerce object #%2$d.', 'ffl-funnels-addons'), $sku, $owner_id)
                    );
                }
            }

            return $this->update_existing_simple($existing, $name, $sku, $status, $payload);
        }

        if ($name === '') {
            return new WP_Error('wss_product', __('Product name is required.', 'ffl-funnels-addons'));
        }

        if ($sku !== '') {
            $existing_id = wc_get_product_id_by_sku($sku);
            if ($existing_id) {
                $existing = wc_get_product($existing_id);
                if ($existing) {
                    if (!$existing->is_type('simple')) {
                        return new WP_Error(
                            'wss_product',
                            sprintf(__('SKU "%1$s" belongs to WooCommerce object #%2$d, which is not a simple product.', 'ffl-funnels-addons'), $sku, (int) $existing_id)
                        );
                    }

                    return $this->update_existing_simple($existing, $name, '', $status, $payload);
                }
            }
        }

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_status($status !== '' ? $status : 'publish');

        if ($sku !== '') {
            $product->set_sku($sku);
        }

        $apply = $this->apply_pricing_and_stock($product, $payload);
        if (is_wp_error($apply)) {
            return $apply;
        }

        $new_id = (int) $product->save();
        if ($new_id <= 0) {
            return new WP_Error('wss_product', __('Failed to save simple product.', 'ffl-funnels-addons'));
        }

        update_post_meta($new_id, '_wss_sync_enabled', '1');

        $attr_string = trim((string) ($payload['attributes'] ?? ''));
        if ($attr_string !== '') {
            $this->attribute_service->apply_terms_to_simple_product($new_id, $attr_string);
        }

        return [
            'product_id'   => $new_id,
            'variation_id' => $new_id,
            'action'       => 'created',
        ];
    }

    /**
     * Apply a payload to an existing simple product.
     *
     * @param WC_Product          $existing
     * @param string              $name    New name, or '' to keep it.
     * @param string              $sku     New SKU, or '' to keep it.
     * @param string              $status  New status, or '' to keep it.
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    private function update_existing_simple($existing, string $name, string $sku, string $status, array $payload)
    {
        $existing_id = (int) $existing->get_id();

        if ($name !== '') {
            $existing->set_name($name);
        }
        if ($sku !== '' && $sku !== (string) $existing->get_sku()) {
            try {
                $existing->set_sku($sku);
            } catch (Exception $exception) {
                return new WP_Error('wss_product', $exception->getMessage());
            }
        }
        if ($status !== '') {
            $existing->set_status($status);
        }

        $apply = $this->apply_pricing_and_stock($existing, $payload);
        if (is_wp_error($apply)) {
            return $apply;
        }
        $existing->save();
        update_post_meta($existing_id, '_wss_sync_enabled', '1');

        $attr_string = trim((string) ($payload['attributes'] ?? ''));
        if ($attr_string !== '') {
            $this->attribute_service->apply_terms_to_simple_product($existing_id, $attr_string);
        }

        return [
            'product_id'   => (int) ($existing->get_parent_id() ?: $existing_id),
            'variation_id' => $existing_id,
            'action'       => 'existing',
        ];
    }

    /**
     * @param WC_Product $product
     * @param array<string,mixed> $payload
     * @return true|WP_Error
     */
    public function apply_pricing_and_stock($product, array $payload)
    {
        $regular = trim((string) ($payload['regular_price'] ?? ''));
        if ($regular !== '') {
            $regular_f = (float) $regular;
            if ($regular_f < 0) {
                return new WP_Error('wss_product', __('Regular price cannot be negative.', 'ffl-funnels-addons'));
            }
            $product->set_regular_price((string) $regular_f);
        }

        $sale = trim((string) ($payload['sale_price'] ?? ''));
        if ($sale !== '') {
            $sale_f = (float) $sale;
            if ($sale_f < 0) {
                return new WP_Error('wss_product', __('Sale price cannot be negative.', 'ffl-funnels-addons'));
            }
            $product->set_sale_price($sale_f == 0.0 ? '' : (string) $sale_f);
        }

        $manage = class_exists('WSS_Sync_Engine')
            ? WSS_Sync_Engine::normalize_manage_stock($payload['manage_stock'] ?? '')
            : strtoupper(trim((string) ($payload['manage_stock'] ?? '')));
        if ($manage === 'TRUE' || $manage === 'FALSE') {
            $product->set_manage_stock($manage === 'TRUE');
        }

        if ($product->get_manage_stock()) {
            $qty = trim((string) ($payload['stock_qty'] ?? ''));
            if ($qty !== '') {
                $product->set_stock_quantity((int) $qty);
            }
        }

        $status = strtolower(trim((string) ($payload['stock_status'] ?? '')));
        if (in_array($status, ['instock', 'outofstock', 'onbackorder'], true)) {
            $product->set_stock_status($status);
        }

        return true;
    }
}
