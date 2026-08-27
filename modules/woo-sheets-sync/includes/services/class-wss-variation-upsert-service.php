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

    /** @var array<int,array<string,int[]>> Per-request parent signature index. */
    private $attribute_signature_cache = [];

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

        $sku             = trim((string) ($payload['sku'] ?? ''));
        $existing_by_sku = 0;
        if ($sku !== '') {
            $existing_id = wc_get_product_id_by_sku($sku);
            if ($existing_id) {
                $existing = wc_get_product($existing_id);
                if ($existing && (int) $existing->get_parent_id() === $parent_id) {
                    $existing_by_sku = (int) $existing_id;
                } else {
                    return new WP_Error(
                        'wss_variation',
                        sprintf(
                            __('SKU "%1$s" already belongs to product #%2$d.', 'ffl-funnels-addons'),
                            $sku,
                            (int) $existing_id
                        )
                    );
                }
            }
        }

        $attr_string          = trim((string) ($payload['attributes'] ?? ''));
        if ($attr_string === '' && $sku === '') {
            return new WP_Error(
                'wss_variation',
                __('A variation without a SKU must include its complete attribute set.', 'ffl-funnels-addons')
            );
        }

        if ($attr_string !== '') {
            $preflight = $this->preflight_parent_attribute_keys($parent, $attr_string);
            if (is_wp_error($preflight)) {
                return $preflight;
            }
        }

        // Validate pricing/stock before attribute synchronization can mutate
        // terms or options on the parent product.
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parent_id);
        if ($sku !== '') {
            $variation->set_sku($sku);
        }
        $apply = $this->product_service->apply_pricing_and_stock($variation, $payload);
        if (is_wp_error($apply)) {
            return $apply;
        }

        $lock_token = $this->acquire_parent_lock($parent_id);
        if (is_wp_error($lock_token)) {
            return $lock_token;
        }

        try {
            if (function_exists('clean_post_cache')) {
                clean_post_cache($parent_id);
            }
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($parent_id);
            }
            $locked_parent = wc_get_product($parent_id);
            if (!$locked_parent || !$locked_parent->is_type('variable')) {
                return new WP_Error('wss_variation', __('Variable parent became unavailable during the update.', 'ffl-funnels-addons'));
            }
            $parent = $locked_parent;

            if ($attr_string !== '') {
                $locked_preflight = $this->preflight_parent_attribute_keys($parent, $attr_string);
                if (is_wp_error($locked_preflight)) {
                    return $locked_preflight;
                }
            }

            if ($sku !== '') {
                $locked_sku_id = (int) wc_get_product_id_by_sku($sku);
                if ($locked_sku_id > 0) {
                    $locked_sku_product = wc_get_product($locked_sku_id);
                    if (!$locked_sku_product || (int) $locked_sku_product->get_parent_id() !== $parent_id) {
                        return new WP_Error(
                            'wss_variation',
                            sprintf(__('SKU "%1$s" already belongs to product #%2$d.', 'ffl-funnels-addons'), $sku, $locked_sku_id)
                        );
                    }
                    $existing_by_sku = $locked_sku_id;
                }
            }

            $preview_attributes = [];
            if ($attr_string !== '') {
                $preview_attributes = $this->preview_variation_attributes($parent, $attr_string);
                if (is_wp_error($preview_attributes)) {
                    return $preview_attributes;
                }
                $preview_matches = $this->find_existing_by_attributes($parent, $preview_attributes);
                if (count($preview_matches) > 1) {
                    return new WP_Error(
                        'wss_variation',
                        sprintf(__('Multiple variations under product #%1$d already use this attribute combination: %2$s.', 'ffl-funnels-addons'), $parent_id, implode(', ', $preview_matches))
                    );
                }
                $preview_existing_id = isset($preview_matches[0]) ? (int) $preview_matches[0] : 0;
                if ($existing_by_sku > 0 && $preview_existing_id > 0 && $existing_by_sku !== $preview_existing_id) {
                    return new WP_Error(
                        'wss_variation',
                        sprintf(
                            __('SKU "%1$s" and the supplied attributes identify different variations (#%2$d and #%3$d).', 'ffl-funnels-addons'),
                            $sku,
                            $existing_by_sku,
                            $preview_existing_id
                        )
                    );
                }
            }

            $variation_attributes   = [];
            $existing_by_attributes = 0;
            if ($attr_string !== '') {
            $meta_attrs = $this->attribute_service->build_variation_attributes_and_sync_parent($parent, $attr_string);
            foreach ($meta_attrs as $meta_key => $meta_value) {
                if (strpos((string) $meta_key, 'attribute_') !== 0) {
                    continue;
                }
                $attribute_key = substr((string) $meta_key, strlen('attribute_'));
                if ($attribute_key !== '') {
                    $variation_attributes[$attribute_key] = (string) $meta_value;
                }
            }

            $expected_keys = $this->parent_variation_attribute_keys($parent);
            $provided_keys = array_keys($variation_attributes);
            sort($provided_keys);
            if ($expected_keys !== $provided_keys) {
                return new WP_Error(
                    'wss_variation',
                    sprintf(
                        __('Variation attributes do not match product #%1$d (%2$d resolved; %3$d required).', 'ffl-funnels-addons'),
                        $parent_id,
                        count($provided_keys),
                        count($expected_keys)
                    )
                );
            }

            $attribute_matches = $this->find_existing_by_attributes($parent, $variation_attributes);
            if (count($attribute_matches) > 1) {
                return new WP_Error(
                    'wss_variation',
                    sprintf(__('Multiple variations under product #%1$d already use this attribute combination: %2$s.', 'ffl-funnels-addons'), $parent_id, implode(', ', $attribute_matches))
                );
            }
            $existing_by_attributes = isset($attribute_matches[0]) ? (int) $attribute_matches[0] : 0;
            if ($existing_by_sku > 0 && $existing_by_attributes > 0 && $existing_by_sku !== $existing_by_attributes) {
                return new WP_Error(
                    'wss_variation',
                    sprintf(
                        __('SKU "%1$s" and the supplied attributes identify different variations (#%2$d and #%3$d).', 'ffl-funnels-addons'),
                        $sku,
                        $existing_by_sku,
                        $existing_by_attributes
                    )
                );
            }
            }

            $existing_target_id = $existing_by_sku > 0 ? $existing_by_sku : $existing_by_attributes;
            if ($existing_target_id > 0) {
                $existing = wc_get_product($existing_target_id);
                if (!$existing || !$existing->is_type('variation')) {
                    return new WP_Error('wss_variation', __('Matched variation could not be loaded.', 'ffl-funnels-addons'));
                }
                $apply_existing = $this->product_service->apply_pricing_and_stock($existing, $payload);
                if (is_wp_error($apply_existing)) {
                    return $apply_existing;
                }
                if ($sku !== '' && $sku !== (string) $existing->get_sku()) {
                    try {
                        $existing->set_sku($sku);
                    } catch (Exception $exception) {
                        return new WP_Error('wss_variation', $exception->getMessage());
                    }
                }
                if ($variation_attributes !== []) {
                    $existing->set_attributes($variation_attributes);
                }
                $existing->save();
                update_post_meta($existing_target_id, '_wss_sync_enabled', '1');
                unset($this->attribute_signature_cache[$parent_id]);

                return [
                    'product_id'   => $parent_id,
                    'variation_id' => $existing_target_id,
                    'action'       => 'existing',
                ];
            }

            if ($variation_attributes !== []) {
                $variation->set_attributes($variation_attributes);
            }

            $new_id = (int) $variation->save();
            if ($new_id <= 0) {
                return new WP_Error('wss_variation', __('Failed to save variation.', 'ffl-funnels-addons'));
            }

            update_post_meta($new_id, '_wss_sync_enabled', '1');
            if ($variation_attributes !== []) {
                $this->remember_attribute_signature($parent_id, $variation_attributes, $new_id);
            }

            return [
                'product_id'   => $parent_id,
                'variation_id' => $new_id,
                'action'       => 'created',
            ];
        } finally {
            $this->release_parent_lock($parent_id, (string) $lock_token);
        }
    }

    /**
     * Acquire a short database-backed lock. add_option() is atomic because
     * option_name is unique, unlike a transient check-then-set sequence.
     *
     * @return string|WP_Error Lock token.
     */
    private function acquire_parent_lock(int $parent_id)
    {
        $key   = '_wss_variation_upsert_lock_' . $parent_id;
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('wss_', true);
        $value = ['token' => $token, 'created' => time()];

        if (add_option($key, $value, '', false)) {
            return $token;
        }

        $existing = get_option($key, []);
        if (is_array($existing) && (int) ($existing['created'] ?? 0) < (time() - 300)) {
            delete_option($key);
            if (add_option($key, $value, '', false)) {
                return $token;
            }
        }

        return new WP_Error('wss_variation_busy', __('Another variation update is already running for this product. Please retry.', 'ffl-funnels-addons'));
    }

    private function release_parent_lock(int $parent_id, string $token): void
    {
        $key      = '_wss_variation_upsert_lock_' . $parent_id;
        $existing = get_option($key, []);
        if (is_array($existing) && hash_equals((string) ($existing['token'] ?? ''), $token)) {
            delete_option($key);
        }
    }

    /**
     * Return the normalized keys required by the parent's variation-enabled
     * attributes.
     *
     * @return string[]
     */
    private function parent_variation_attribute_keys($parent): array
    {
        $keys = [];
        foreach ($parent->get_attributes() as $key => $attribute) {
            if (!($attribute instanceof WC_Product_Attribute) || !$attribute->get_variation()) {
                continue;
            }

            $name = $attribute->is_taxonomy() ? (string) $attribute->get_name() : (string) $key;
            $name = sanitize_title($name);
            if ($name !== '') {
                $keys[] = $name;
            }
        }

        sort($keys);
        return array_values(array_unique($keys));
    }

    /**
     * Confirm that a non-empty Sheet attribute payload identifies every
     * variation-enabled parent attribute exactly once before mutating terms or
     * custom options.
     *
     * @return string[]|WP_Error
     */
    private function preflight_parent_attribute_keys($parent, string $attr_string)
    {
        $aliases  = [];
        $required = [];
        foreach ($parent->get_attributes() as $key => $attribute) {
            if (!($attribute instanceof WC_Product_Attribute)) {
                continue;
            }

            $attribute_name = (string) $attribute->get_name();
            $actual_key     = sanitize_title($attribute->is_taxonomy() ? $attribute_name : (string) $key);
            if ($attribute->get_variation() && $actual_key !== '') {
                $required[] = $actual_key;
            }
            $candidates     = [(string) $key, $attribute_name, preg_replace('/^pa_/', '', $attribute_name)];
            if (function_exists('wc_attribute_label')) {
                $candidates[] = (string) wc_attribute_label($attribute_name, $parent);
            }
            foreach ($candidates as $candidate) {
                $alias = $this->normalize_attribute_label((string) $candidate);
                if ($alias !== '' && $actual_key !== '') {
                    if (isset($aliases[$alias]) && $aliases[$alias] !== $actual_key) {
                        $aliases[$alias] = null;
                    } else {
                        $aliases[$alias] = $actual_key;
                    }
                }
            }
        }

        $pairs = $this->attribute_service->parse_pairs($attr_string);
        if ($pairs === []) {
            return new WP_Error('wss_variation', __('The variation attribute text is malformed or empty.', 'ffl-funnels-addons'));
        }

        $provided = [];
        foreach ($pairs as $pair) {
            $alias = $this->normalize_attribute_label((string) ($pair['label'] ?? ''));
            $actual_key = $alias !== '' && array_key_exists($alias, $aliases) ? $aliases[$alias] : null;
            if ($actual_key === null && $alias !== '' && !array_key_exists($alias, $aliases)) {
                $taxonomy = $this->attribute_service->resolve_global_taxonomy_by_label((string) ($pair['label'] ?? ''));
                if ($taxonomy !== '' && trim((string) ($pair['value'] ?? '')) !== '') {
                    $actual_key = sanitize_title($taxonomy);
                }
            }
            if ($alias === '' || $actual_key === null || $actual_key === '') {
                return new WP_Error(
                    'wss_variation',
                    sprintf(__('Attribute "%s" is unknown, ambiguous, or cannot use an empty value on product #%d.', 'ffl-funnels-addons'), (string) ($pair['label'] ?? ''), (int) $parent->get_id())
                );
            }
            if (in_array($actual_key, $provided, true)) {
                return new WP_Error(
                    'wss_variation',
                    sprintf(__('Attribute "%s" is supplied more than once.', 'ffl-funnels-addons'), (string) ($pair['label'] ?? ''))
                );
            }
            $provided[] = $actual_key;
        }

        sort($provided);
        sort($required);
        $missing_required = array_values(array_diff(array_unique($required), $provided));
        if ($missing_required !== []) {
            return new WP_Error(
                'wss_variation',
                sprintf(
                    __('Variation attributes do not match product #%1$d (%2$d provided; %3$d existing variation attributes required).', 'ffl-funnels-addons'),
                    (int) $parent->get_id(),
                    count($provided),
                    count(array_unique($required))
                )
            );
        }

        return $provided;
    }

    private function normalize_attribute_label(string $value): string
    {
        $value = strtolower(wp_strip_all_tags($value));
        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }

    /**
     * Resolve the intended child attributes without creating terms/options or
     * saving the parent. Used for ambiguity and SKU-conflict checks.
     *
     * @return array<string,string>|WP_Error
     */
    private function preview_variation_attributes($parent, string $attr_string)
    {
        $aliases = [];
        foreach ($parent->get_attributes() as $key => $attribute) {
            if (!($attribute instanceof WC_Product_Attribute)) {
                continue;
            }
            $name       = (string) $attribute->get_name();
            $actual_key = sanitize_title($attribute->is_taxonomy() ? $name : (string) $key);
            $definition = [
                'key'       => $actual_key,
                'taxonomy'  => $attribute->is_taxonomy(),
                'name'      => $name,
                'options'   => $attribute->get_options(),
            ];
            $candidates = [(string) $key, $name, preg_replace('/^pa_/', '', $name)];
            if (function_exists('wc_attribute_label')) {
                $candidates[] = (string) wc_attribute_label($name, $parent);
            }
            foreach ($candidates as $candidate) {
                $alias = $this->normalize_attribute_label((string) $candidate);
                if ($alias === '') {
                    continue;
                }
                if (isset($aliases[$alias]) && $aliases[$alias] !== $definition) {
                    $aliases[$alias] = null;
                } else {
                    $aliases[$alias] = $definition;
                }
            }
        }

        $resolved = [];
        foreach ($this->attribute_service->parse_pairs($attr_string) as $pair) {
            $label = (string) ($pair['label'] ?? '');
            $value = trim((string) ($pair['value'] ?? ''));
            $alias = $this->normalize_attribute_label($label);
            $definition = $alias !== '' && array_key_exists($alias, $aliases) ? $aliases[$alias] : null;

            if ($definition === null && $alias !== '' && !array_key_exists($alias, $aliases)) {
                $taxonomy = $this->attribute_service->resolve_global_taxonomy_by_label($label);
                if ($taxonomy !== '' && $value !== '') {
                    $definition = ['key' => sanitize_title($taxonomy), 'taxonomy' => true, 'name' => $taxonomy, 'options' => []];
                }
            }
            if (!is_array($definition) || empty($definition['key'])) {
                return new WP_Error('wss_variation', sprintf(__('Attribute "%s" could not be resolved safely.', 'ffl-funnels-addons'), $label));
            }

            if (!empty($definition['taxonomy'])) {
                if ($value === '') {
                    $resolved[(string) $definition['key']] = '';
                    continue;
                }
                $term = get_term_by('name', $value, (string) $definition['name']);
                if (!$term || is_wp_error($term)) {
                    $term = get_term_by('slug', sanitize_title($value), (string) $definition['name']);
                }
                $resolved[(string) $definition['key']] = ($term && !is_wp_error($term))
                    ? (string) $term->slug
                    : sanitize_title($value);
                continue;
            }

            $canonical = $value;
            foreach (array_map('strval', (array) ($definition['options'] ?? [])) as $option) {
                if (strcasecmp(trim($option), $value) === 0) {
                    $canonical = $option;
                    break;
                }
            }
            $resolved[(string) $definition['key']] = $canonical;
        }

        ksort($resolved);
        return $resolved;
    }

    /**
     * Avoid duplicate variations when the Sheet has no SKU but does provide a
     * complete attribute combination.
     *
     * @return int[]
     */
    private function find_existing_by_attributes($parent, array $attributes): array
    {
        $needle = $this->normalize_attributes($attributes);
        if ($needle === []) {
            return [];
        }

        $parent_id = (int) $parent->get_id();
        if (!isset($this->attribute_signature_cache[$parent_id])) {
            $this->attribute_signature_cache[$parent_id] = [];
            foreach ($parent->get_children() as $child_id) {
                $candidate = wc_get_product($child_id);
                if (!$candidate || !$candidate->is_type('variation')) {
                    continue;
                }

                $candidate_signature = $this->normalize_attributes($candidate->get_attributes());
                if ($candidate_signature === []) {
                    continue;
                }
                $signature_key = md5(serialize($candidate_signature));
                $this->attribute_signature_cache[$parent_id][$signature_key][] = (int) $candidate->get_id();
            }
        }

        $needle_key = md5(serialize($needle));
        return $this->attribute_signature_cache[$parent_id][$needle_key] ?? [];
    }

    private function remember_attribute_signature(int $parent_id, array $attributes, int $variation_id): void
    {
        if (!isset($this->attribute_signature_cache[$parent_id])) {
            return;
        }

        $signature = $this->normalize_attributes($attributes);
        if ($signature === []) {
            return;
        }

        $key = md5(serialize($signature));
        $this->attribute_signature_cache[$parent_id][$key][] = $variation_id;
        $this->attribute_signature_cache[$parent_id][$key] = array_values(array_unique($this->attribute_signature_cache[$parent_id][$key]));
    }

    /**
     * Normalize taxonomy slugs and custom option values for comparison.
     *
     * @param array<string,mixed> $attributes
     * @return array<string,string>
     */
    private function normalize_attributes(array $attributes): array
    {
        $normalized = [];
        foreach ($attributes as $key => $value) {
            $normalized_key   = sanitize_title((string) $key);
            // Preserve punctuation in custom options (for example "#13" and
            // "13" can be distinct WooCommerce choices). Taxonomy values are
            // already canonical term slugs; custom values remain exact.
            $normalized_value = trim(wp_strip_all_tags((string) $value));
            if ($normalized_key !== '') {
                $normalized[$normalized_key] = $normalized_value;
            }
        }
        ksort($normalized);
        return $normalized;
    }
}
