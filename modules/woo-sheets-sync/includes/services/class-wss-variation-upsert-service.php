<?php
/**
 * WSS Variation Upsert Service.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Variation_Upsert_Service
{
    /** @var WSS_Attribute_Upsert_Service */
    private $attribute_service;

    /** @var WSS_Product_Upsert_Service */
    private $product_service;

    public function __construct(
        WSS_Attribute_Upsert_Service $attribute_service,
        WSS_Product_Upsert_Service $product_service
    ) {
        $this->attribute_service = $attribute_service;
        $this->product_service   = $product_service;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|WP_Error
     */
    public function upsert_variation(int $parent_id, array $payload)
    {
        $parent = wc_get_product($parent_id);
        if (!$parent || !$parent->is_type('variable')) {
            return new WP_Error(
                'wss_variation',
                sprintf(
                    __('Cannot create variation: product #%d does not exist or is not variable.', 'ffl-funnels-addons'),
                    $parent_id
                )
            );
        }

        $sku = trim((string) ($payload['sku'] ?? ''));
        if ($sku !== '') {
            $existing_id = wc_get_product_id_by_sku($sku);
            if ($existing_id) {
                $existing = wc_get_product($existing_id);
                if ($existing && (int) $existing->get_parent_id() === $parent_id) {
                    return [
                        'product_id'   => $parent_id,
                        'variation_id' => (int) $existing_id,
                        'action'       => 'existing',
                    ];
                }
            }
        }

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent_id);

        if ($sku !== '') {
            $variation->set_sku($sku);
        }

        $apply = $this->product_service->apply_pricing_and_stock($variation, $payload);
        if (is_wp_error($apply)) {
            return $apply;
        }

        $new_id = (int) $variation->save();
        if ($new_id <= 0) {
            return new WP_Error('wss_variation', __('Failed to save variation.', 'ffl-funnels-addons'));
        }

        $attr_string = trim((string) ($payload['attributes'] ?? ''));
        if ($attr_string !== '') {
            $meta_attrs = $this->attribute_service->build_variation_attributes_and_sync_parent($parent, $attr_string);
            foreach ($meta_attrs as $meta_key => $meta_value) {
                update_post_meta($new_id, $meta_key, $meta_value);
            }
        }

        update_post_meta($new_id, '_wss_sync_enabled', '1');

        return [
            'product_id'   => $parent_id,
            'variation_id' => $new_id,
            'action'       => 'created',
        ];
    }
}

