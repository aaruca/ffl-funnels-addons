<?php
/**
 * WSS Attribute Upsert Service.
 *
 * Handles global WooCommerce attributes (pa_*) and terms creation/reuse.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Attribute_Upsert_Service
{
    /**
     * Parse "Label: Value | Label2: Value2" into pairs.
     *
     * @return array<int,array{label:string,value:string}>
     */
    public function parse_pairs(string $attr_string): array
    {
        $pairs = [];
        foreach (array_map('trim', explode('|', $attr_string)) as $pair) {
            if ($pair === '' || strpos($pair, ':') === false) {
                continue;
            }
            [$label, $value] = array_map('trim', explode(':', $pair, 2));
            if ($label === '' || $value === '') {
                continue;
            }
            $pairs[] = ['label' => $label, 'value' => $value];
        }

        return $pairs;
    }

    /**
     * Resolve taxonomy name (pa_*) from a human label.
     */
    public function resolve_global_taxonomy_by_label(string $label): string
    {
        $label = trim($label);
        if ($label === '') {
            return '';
        }

        foreach (wc_get_attribute_taxonomies() as $tax) {
            $name     = isset($tax->attribute_name) ? (string) $tax->attribute_name : '';
            $taxonomy = $name !== '' ? wc_attribute_taxonomy_name($name) : '';
            if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
                continue;
            }

            $tax_label = isset($tax->attribute_label) ? (string) $tax->attribute_label : '';
            if (
                strcasecmp($tax_label, $label) === 0
                || strcasecmp($name, $label) === 0
                || strcasecmp($taxonomy, $label) === 0
                || strcasecmp(str_replace('pa_', '', $taxonomy), $label) === 0
            ) {
                return $taxonomy;
            }
        }

        return '';
    }

    /**
     * Ensure a term exists in a global taxonomy and return it.
     *
     * @return WP_Term|WP_Error
     */
    public function ensure_term(string $taxonomy, string $value)
    {
        $taxonomy = trim($taxonomy);
        $value    = trim($value);

        if ($taxonomy === '' || $value === '' || !taxonomy_exists($taxonomy)) {
            return new WP_Error('wss_attr', __('Invalid taxonomy/value for attribute term.', 'ffl-funnels-addons'));
        }

        $term = get_term_by('name', $value, $taxonomy);
        if (!$term || is_wp_error($term)) {
            $insert = wp_insert_term($value, $taxonomy);
            if (is_wp_error($insert)) {
                return $insert;
            }
            $term = get_term((int) $insert['term_id'], $taxonomy);
        }

        if (!$term || is_wp_error($term)) {
            return new WP_Error('wss_attr', __('Could not resolve attribute term.', 'ffl-funnels-addons'));
        }

        return $term;
    }

    /**
     * Apply global attribute terms to a simple product.
     *
     * @return array<string,string> taxonomy => term_slug
     */
    public function apply_terms_to_simple_product(int $product_id, string $attr_string): array
    {
        $out = [];
        if ($product_id <= 0 || trim($attr_string) === '') {
            return $out;
        }

        foreach ($this->parse_pairs($attr_string) as $pair) {
            $taxonomy = $this->resolve_global_taxonomy_by_label($pair['label']);
            if ($taxonomy === '') {
                continue;
            }

            $term = $this->ensure_term($taxonomy, $pair['value']);
            if (is_wp_error($term)) {
                continue;
            }

            wp_set_object_terms($product_id, $term->slug, $taxonomy, true);
            $out[$taxonomy] = $term->slug;
        }

        return $out;
    }

    /**
     * Build variation meta attributes and ensure parent has compatible taxonomy attributes/options.
     *
     * @return array<string,string> e.g. ['attribute_pa_color' => 'red']
     */
    public function build_variation_attributes_and_sync_parent(WC_Product $parent, string $attr_string): array
    {
        $result = [];
        if (!$parent->is_type('variable') || trim($attr_string) === '') {
            return $result;
        }

        $parent_attrs   = $parent->get_attributes();
        $parent_changed = false;

        foreach ($this->parse_pairs($attr_string) as $pair) {
            $taxonomy = $this->resolve_global_taxonomy_by_label($pair['label']);
            if ($taxonomy === '') {
                continue;
            }

            $term = $this->ensure_term($taxonomy, $pair['value']);
            if (is_wp_error($term)) {
                continue;
            }

            wp_set_object_terms($parent->get_id(), $term->slug, $taxonomy, true);

            if (!isset($parent_attrs[$taxonomy]) || !($parent_attrs[$taxonomy] instanceof WC_Product_Attribute)) {
                $attr = new WC_Product_Attribute();
                $attr->set_id((int) wc_attribute_taxonomy_id_by_name($taxonomy));
                $attr->set_name($taxonomy);
                $attr->set_options([(int) $term->term_id]);
                $attr->set_position(count($parent_attrs));
                $attr->set_visible(true);
                $attr->set_variation(true);
                $parent_attrs[$taxonomy] = $attr;
                $parent_changed = true;
            } else {
                /** @var WC_Product_Attribute $attr */
                $attr    = $parent_attrs[$taxonomy];
                $options = array_map('intval', $attr->get_options());
                if (!in_array((int) $term->term_id, $options, true)) {
                    $options[] = (int) $term->term_id;
                    $attr->set_options($options);
                    $parent_changed = true;
                }
                if (!$attr->get_variation()) {
                    $attr->set_variation(true);
                    $parent_changed = true;
                }
                $parent_attrs[$taxonomy] = $attr;
            }

            $result['attribute_' . $taxonomy] = (string) $term->slug;
        }

        if ($parent_changed) {
            $parent->set_attributes($parent_attrs);
            $parent->save();
        }

        return $result;
    }
}

