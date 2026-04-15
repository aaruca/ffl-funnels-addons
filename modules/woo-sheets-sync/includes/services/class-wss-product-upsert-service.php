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

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function upsert_simple(array $payload)
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return new WP_Error('wss_product', __('Product name is required.', 'ffl-funnels-addons'));
        }

        $sku = trim((string) ($payload['sku'] ?? ''));
        if ($sku !== '') {
            $existing_id = wc_get_product_id_by_sku($sku);
            if ($existing_id) {
                $existing = wc_get_product($existing_id);
                if ($existing) {
                    return [
                        'product_id'   => (int) ($existing->get_parent_id() ?: $existing_id),
                        'variation_id' => (int) $existing_id,
                        'action'       => 'existing',
                    ];
                }
            }
        }

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_status('publish');

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

        $manage = strtoupper(trim((string) ($payload['manage_stock'] ?? '')));
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

