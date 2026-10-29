<?php
/**
 * Product identifiers in the structured data (Display → SEO): GTIN, MPN and
 * brand, which search engines use to match a product's reviews.
 *
 * WooCommerce's own values come first: its "GTIN, UPC, EAN, or ISBN" field
 * (WooCommerce 9.2+) and its Brands taxonomy (9.6+). The module adds an MPN
 * and a Brand field (and a GTIN field on older WooCommerce) under the SKU on
 * the product's Inventory tab, and a default brand for products without
 * one. Values are only added to markup that lacks them, so SEO plugins'
 * own identifiers are kept.
 *
 * Variations use their parent's identifiers: WooCommerce's product markup
 * describes the parent.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Identifiers
{
    const GTIN_META = '_ffla_gtin';

    const MPN_META = '_ffla_mpn';

    const BRAND_META = '_ffla_brand';

    const NONCE = 'ffla_product_identifiers';

    public static function init(): void
    {
        if (!self::enabled()) {
            return;
        }

        add_filter('woocommerce_structured_data_product', [__CLASS__, 'filter_markup'], 30, 2);
        add_filter('rank_math/snippet/rich_snippet_product_entity', [__CLASS__, 'filter_rank_math'], 30);

        if (is_admin()) {
            add_action('woocommerce_product_options_sku', [__CLASS__, 'render_fields']);
            add_action('woocommerce_admin_process_product_object', [__CLASS__, 'save_fields']);
        }
    }

    public static function enabled(): bool
    {
        return '1' === Product_Reviews_Core::get_setting('seo_identifiers', '1');
    }

    /**
     * WooCommerce has its own GTIN field.
     */
    public static function wc_has_gtin(): bool
    {
        return method_exists('WC_Product', 'get_global_unique_id');
    }

    /**
     * 8, 12, 13 or 14 digits (EAN-8, UPC-A, EAN-13, GTIN-14), else ''.
     */
    public static function clean_gtin(string $value): string
    {
        $digits = preg_replace('/[\s-]+/', '', trim($value));

        return is_string($digits) && preg_match('/^(\d{8}|\d{12}|\d{13}|\d{14})$/', $digits) ? $digits : '';
    }

    /**
     * @param \WC_Product $product
     * @return array{gtin:string,mpn:string,brand:string}
     */
    public static function for_product($product): array
    {
        if ($product instanceof \WC_Product && $product->is_type('variation') && $product->get_parent_id() > 0) {
            $parent  = wc_get_product($product->get_parent_id());
            $product = $parent instanceof \WC_Product ? $parent : $product;
        }

        if (!$product instanceof \WC_Product) {
            return ['gtin' => '', 'mpn' => '', 'brand' => ''];
        }

        $id   = (int) $product->get_id();
        $gtin = self::wc_has_gtin() ? (string) $product->get_global_unique_id() : '';
        if ($gtin === '') {
            $gtin = (string) $product->get_meta(self::GTIN_META, true);
        }

        $brand = '';
        if (taxonomy_exists('product_brand')) {
            $terms = wp_get_post_terms($id, 'product_brand', ['fields' => 'names']);
            if (is_array($terms) && !empty($terms)) {
                $brand = (string) reset($terms);
            }
        }
        if ($brand === '') {
            $brand = trim((string) $product->get_meta(self::BRAND_META, true));
        }
        if ($brand === '') {
            $brand = trim((string) Product_Reviews_Core::get_setting('seo_default_brand', ''));
        }

        return [
            'gtin'  => self::clean_gtin($gtin),
            'mpn'   => trim(sanitize_text_field((string) $product->get_meta(self::MPN_META, true))),
            'brand' => sanitize_text_field($brand),
        ];
    }

    /**
     * Fill what the markup lacks. The GTIN goes into the property its
     * length names (gtin8, gtin12, gtin13, gtin14).
     *
     * @param array<string, mixed>                       $node
     * @param array{gtin:string,mpn:string,brand:string} $ids
     * @return array<string, mixed>
     */
    public static function apply(array $node, array $ids): array
    {
        $has_gtin = false;
        foreach (['gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14'] as $key) {
            $has_gtin = $has_gtin || !empty($node[$key]);
        }
        if (!$has_gtin && $ids['gtin'] !== '') {
            $node['gtin' . strlen($ids['gtin'])] = $ids['gtin'];
        }

        if (empty($node['mpn']) && $ids['mpn'] !== '') {
            $node['mpn'] = $ids['mpn'];
        }

        if (empty($node['brand']) && $ids['brand'] !== '') {
            $node['brand'] = ['@type' => 'Brand', 'name' => $ids['brand']];
        }

        return $node;
    }

    /**
     * @param mixed $markup
     * @param mixed $product
     * @return mixed
     */
    public static function filter_markup($markup, $product)
    {
        if (!is_array($markup) || !$product instanceof \WC_Product) {
            return $markup;
        }

        return self::apply($markup, self::for_product($product));
    }

    /**
     * @param mixed $entity
     * @return mixed
     */
    public static function filter_rank_math($entity)
    {
        if (!is_array($entity) || !function_exists('is_product') || !is_product()) {
            return $entity;
        }

        $product = wc_get_product((int) get_queried_object_id());

        return $product instanceof \WC_Product ? self::apply($entity, self::for_product($product)) : $entity;
    }

    /* ---------------------------------------------------------------------
     * Product screen
     * ------------------------------------------------------------------- */

    public static function render_fields(): void
    {
        global $post;

        $product = $post instanceof \WP_Post ? wc_get_product($post->ID) : null;
        if (!$product instanceof \WC_Product) {
            return;
        }

        wp_nonce_field(self::NONCE, 'ffla_identifiers_nonce');

        echo '<div class="options_group ffla-product-identifiers">';
        if (!self::wc_has_gtin()) {
            woocommerce_wp_text_input([
                'id'          => self::GTIN_META,
                'label'       => __('GTIN / UPC / EAN', 'ffl-funnels-addons'),
                'value'       => (string) $product->get_meta(self::GTIN_META, true),
                'desc_tip'    => true,
                'description' => __('8, 12, 13 or 14 digits from the barcode. Added to the product\'s structured data.', 'ffl-funnels-addons'),
            ]);
        }
        woocommerce_wp_text_input([
            'id'          => self::MPN_META,
            'label'       => __('MPN', 'ffl-funnels-addons'),
            'value'       => (string) $product->get_meta(self::MPN_META, true),
            'desc_tip'    => true,
            'description' => __('Manufacturer part number. Added to the product\'s structured data.', 'ffl-funnels-addons'),
        ]);
        if (!taxonomy_exists('product_brand')) {
            woocommerce_wp_text_input([
                'id'          => self::BRAND_META,
                'label'       => __('Brand', 'ffl-funnels-addons'),
                'value'       => (string) $product->get_meta(self::BRAND_META, true),
                'desc_tip'    => true,
                'description' => __('The manufacturer\'s brand. Empty uses the default brand from Product Reviews → Display.', 'ffl-funnels-addons'),
            ]);
        }
        echo '</div>';
    }

    /**
     * @param \WC_Product|mixed $product
     */
    public static function save_fields($product): void
    {
        if (!$product instanceof \WC_Product || !isset($_POST['ffla_identifiers_nonce'])) {
            return;
        }
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffla_identifiers_nonce'])), self::NONCE)) {
            return;
        }

        foreach ([self::GTIN_META, self::MPN_META, self::BRAND_META] as $key) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $value = trim(sanitize_text_field(wp_unslash($_POST[$key])));
            if (self::GTIN_META === $key && $value !== '') {
                $clean = self::clean_gtin($value);
                if ($clean === '' && class_exists('WC_Admin_Meta_Boxes')) {
                    \WC_Admin_Meta_Boxes::add_error(__('The GTIN was not saved: it must be 8, 12, 13 or 14 digits.', 'ffl-funnels-addons'));
                }
                $value = $clean;
            }

            if ($value === '') {
                $product->delete_meta_data($key);
            } else {
                $product->update_meta_data($key, $value);
            }
        }
    }
}
