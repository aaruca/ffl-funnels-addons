<?php
/**
 * Doofinder Core — Meta injection logic.
 *
 * Refactored from procedural doofinder-sync.php to OOP.
 * All methods are static since they're pure utility functions.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Doofinder_Core
{
    /**
     * Register all hooks.
     */
    public static function init(): void
    {
        // Prevent escaping of forward slashes in JSON responses.
        add_filter('wp_json_encode_options', function () {
            return JSON_UNESCAPED_SLASHES;
        });

        // Inject dynamic meta into WooCommerce REST API product response.
        add_filter('woocommerce_rest_prepare_product_object', [__CLASS__, 'add_dynamic_meta_to_rest'], 10, 3);

        // Inject dynamic meta into standard get_post_meta() calls.
        add_filter('get_post_metadata', [__CLASS__, 'inject_dynamic_meta'], 10, 4);

        // Correctly set the 'on sale' status based on dynamically calculated discount.
        add_filter('woocommerce_product_is_on_sale', [__CLASS__, 'check_discount_for_on_sale'], 10, 2);

        // Price structure fix for certain themes/plugins.
        add_action('wp_footer', [__CLASS__, 'add_price_structure_fix']);
    }

    /**
     * Dynamic meta field configuration.
     *
     * 'tax' = taxonomy lookup, 'cb' = custom callback.
     */
    public static function dynamic_meta_config(): array
    {
        return [
            '_category_slugs' => ['tax' => 'product_cat', 'hier' => true],
            '_brand_slugs' => ['tax' => 'product_brand', 'hier' => true],
            '_tag_slugs' => ['tax' => 'product_tag', 'hier' => true],
            '_caliber_gauge_slugs' => ['tax' => 'pa_caliber-gauge', 'hier' => false],
            '_manufacturer_slugs' => ['cb' => [__CLASS__, 'get_manufacturer_slugs']],
            '_discount_codes' => ['cb' => [__CLASS__, 'get_discount_price']],
            '_pewc_has_extra_fields' => ['cb' => 'pewc_has_extra_fields'],
            '_product_class' => ['cb' => [__CLASS__, 'get_product_class']],
        ];
    }

    /**
     * Add dynamic meta fields to the REST API product response.
     */
    public static function add_dynamic_meta_to_rest($response, $product, $request)
    {
        if (!$product instanceof WC_Product) {
            return $response;
        }

        $product_id = $product->get_id();
        foreach (self::dynamic_meta_config() as $meta_key => $opts) {
            $response->data[$meta_key] = self::get_dynamic_meta_value($product_id, $opts);
        }

        if (!empty($response->data['_discount_codes'])) {
            $response->data['on_sale'] = true;
        }

        return $response;
    }

    /**
     * Intercept get_post_metadata for products and inject dynamic values.
     */
    public static function inject_dynamic_meta($value, $post_id, $meta_key, $single)
    {
        if (get_post_type($post_id) !== 'product') {
            return $value;
        }

        $cfg = self::dynamic_meta_config();
        if (isset($cfg[$meta_key])) {
            $val = self::get_dynamic_meta_value($post_id, $cfg[$meta_key]);
            return $single ? $val : [$val];
        }

        return $value;
    }

    /**
     * Retrieve a dynamic meta value based on its configuration.
     */
    public static function get_dynamic_meta_value($product_id, $opt)
    {
        if (!empty($opt['tax'])) {
            return self::get_taxonomy_slugs($product_id, $opt['tax'], !empty($opt['hier']));
        }
        if (!empty($opt['cb']) && is_callable($opt['cb'])) {
            return call_user_func($opt['cb'], $product_id);
        }
        return '';
    }

    /**
     * Get concatenated taxonomy slugs, optionally with hierarchy.
     */
    public static function get_taxonomy_slugs($product_id, $tax, $hier = false): string
    {
        $terms = get_the_terms($product_id, $tax);
        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        $collected_paths = [];
        foreach ($terms as $term) {
            if ($hier) {
                $paths_for_term = self::get_taxonomy_paths($term->term_id, $tax);
                $collected_paths = array_merge($collected_paths, $paths_for_term);
            } else {
                $collected_paths[] = $term->slug;
            }
        }

        $unique_paths = array_unique($collected_paths);
        if (empty($unique_paths)) {
            return '';
        }

        $processed_paths = [];
        foreach ($unique_paths as $current_path) {
            if ($hier) {
                if (substr($current_path, -1) !== '/') {
                    $processed_paths[] = $current_path . '/';
                } else {
                    $processed_paths[] = $current_path;
                }
            } else {
                $processed_paths[] = $current_path;
            }
        }

        return implode(' ', $processed_paths);
    }

    /**
     * Build hierarchical paths (slugs) for a given term.
     */
    public static function get_taxonomy_paths($term_id, $tax): array
    {
        $paths = [];
        $slugs = [];

        $ancestor_ids = array_reverse(get_ancestors($term_id, $tax));
        $ancestor_ids[] = $term_id;

        foreach ($ancestor_ids as $ancestor_id) {
            $ancestor = get_term($ancestor_id, $tax);
            if (!is_wp_error($ancestor) && $ancestor) {
                $slugs[] = $ancestor->slug;
                $paths[] = implode('/', $slugs);
            }
        }

        return $paths;
    }

    /**
     * Get manufacturer slugs for a product.
     * Priority: Taxonomies (pa_manufacturer, manufacturer) > Text Attributes > Custom Fields.
     */
    public static function get_manufacturer_slugs($product_id): string
    {
        // 1. Global taxonomy pa_manufacturer.
        $terms_pa = get_the_terms($product_id, 'pa_manufacturer');
        if ($terms_pa && !is_wp_error($terms_pa)) {
            $slugs = [];
            foreach ($terms_pa as $t) {
                $slugs[] = $t->slug;
            }
            return implode(' ', array_unique($slugs));
        }

        // 2. Dedicated 'manufacturer' taxonomy.
        $terms = get_the_terms($product_id, 'manufacturer');
        if ($terms && !is_wp_error($terms)) {
            $slugs = [];
            foreach ($terms as $t) {
                $slugs[] = $t->slug;
            }
            return implode(' ', array_unique($slugs));
        }

        $product = wc_get_product($product_id);
        if ($product) {
            // 3. Free-text attribute pa_manufacturer.
            $val = $product->get_attribute('pa_manufacturer');
            if (!empty($val)) {
                return self::sanitize_manufacturer_slug($val);
            }

            // 4. Other common attribute names.
            foreach (['manufacturer', 'Manufacturer', 'MANUFACTURER'] as $attr) {
                $val = $product->get_attribute($attr);
                if (!empty($val)) {
                    return self::sanitize_manufacturer_slug($val);
                }
            }

            // 5. Custom field 'Manufacturer'.
            $cf = get_post_meta($product_id, 'Manufacturer', true);
            if (!empty($cf)) {
                return self::sanitize_manufacturer_slug($cf);
            }
        }

        return '';
    }

    /**
     * Sanitize a manufacturer string into a slug.
     */
    private static function sanitize_manufacturer_slug(string $val): string
    {
        $val = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $val = str_replace(['/', ',', '&', '  '], ['-', '-', '-', ' '], $val);
        $slug = sanitize_title($val);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Compute the final discounted price.
     * Prioritizes "Discount Rules for WooCommerce" by Flycart, falls back to standard sale price.
     */
    public static function get_discount_price($product_id): string
    {
        $p = wc_get_product($product_id);
        if (!$p) {
            return '';
        }

        $final = '';

        // Check for Flycart's Discount Rules plugin.
        if (class_exists('\Wdr\App\Controllers\ManageDiscount') && method_exists('\Wdr\App\Controllers\ManageDiscount', 'getDiscountDetailsOfAProduct')) {
            $details = \Wdr\App\Controllers\ManageDiscount::getDiscountDetailsOfAProduct(false, $p, 1, 0);

            if ($details && isset($details['discounted_price'], $details['initial_price'])) {
                $discounted = floatval($details['discounted_price']);
                $initial = floatval($details['initial_price']);

                if ($discounted < $initial) {
                    $regular = floatval($p->get_regular_price());
                    if ($discounted < $regular) {
                        $rounded = round($discounted, wc_get_price_decimals());
                        $final = number_format($rounded, 2, '.', '');
                    }
                }
            }
        }

        // Fallback to standard WooCommerce sale price.
        if ($final === '') {
            $sale = floatval($p->get_sale_price());
            $regular = floatval($p->get_regular_price());
            if ($sale > 0 && $sale < $regular) {
                $rounded = round($sale, wc_get_price_decimals());
                $final = number_format($rounded, 2, '.', '');
            }
        }

        return $final;
    }

    /**
     * Get product_class custom field.
     */
    public static function get_product_class($product_id): string
    {
        $val = get_post_meta($product_id, 'product_class', true);
        return !empty($val) ? sanitize_text_field($val) : '';
    }

    /**
     * Ensure is_on_sale is true when a dynamic discount exists.
     */
    public static function check_discount_for_on_sale($on_sale, $product)
    {
        if ($on_sale) {
            return $on_sale;
        }

        $discount_price = get_post_meta($product->get_id(), '_discount_codes', true);
        return !empty($discount_price) ? true : $on_sale;
    }

    /**
     * Price structure fix for certain themes/plugins.
     */
    public static function add_price_structure_fix(): void
    {
        ?>
        <script>
            jQuery(document).ready(function ($) {
                $('.price').each(function () {
                    var $price = $(this);
                    var $del = $price.find('del');
                    if ($del.find('ins').length > 0) {
                        var originalPriceHTML = $del.find('ins .woocommerce-Price-amount').parent().html();
                        var currentPriceHTML = $price.find('del + ins .woocommerce-Price-amount').parent().html();
                        var originalPriceText = $del.find('ins .woocommerce-Price-amount').text();
                        var currentPriceText = $price.find('del + ins .woocommerce-Price-amount').text();
                        var newHtml = '<del aria-hidden="true">' + originalPriceHTML + '</del> ';
                        newHtml += '<span class="screen-reader-text">Original price was: ' + originalPriceText + '.</span>';
                        newHtml += '<ins>' + currentPriceHTML + '</ins>';
                        newHtml += '<span class="screen-reader-text">Current price is: ' + currentPriceText + '.</span>';
                        $price.html(newHtml);
                    }
                });
            });
        </script>
        <?php
    }
}
