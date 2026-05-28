<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bricks Dynamic Data tags for Loadout.
 *
 * Registers tags into Bricks' dynamic data picker so they can be used inside
 * the Conditions tab, attribute values, and rendered text.
 *
 * Available tags:
 *   {ffla_product_has_loadout} — Returns "1" when the current product (in the
 *       template/page render context) resolves to a Loadout config that would
 *       actually render content (linked global Loadout or per-product custom
 *       tiers, with the loadout tab enabled). Returns empty string otherwise.
 *
 * Usage in Bricks element Conditions tab:
 *   - Source: Dynamic data
 *   - Dynamic data: {ffla_product_has_loadout}
 *   - Compare: == (or "is not empty")
 *   - Value: 1
 */
class Loadout_Bricks_Tags
{
    const TAG_HAS_LOADOUT = 'ffla_product_has_loadout';

    public static function init(): void
    {
        if (!defined('BRICKS_VERSION')) {
            return;
        }

        add_filter('bricks/dynamic_tags_list', [__CLASS__, 'register_tag_in_picker']);
        add_filter('bricks/dynamic_data/render_tag', [__CLASS__, 'render_tag'], 20, 3);
        add_filter('bricks/dynamic_data/render_content', [__CLASS__, 'render_content'], 20, 3);
    }

    /**
     * Add the tag to Bricks' dynamic data picker so it appears in dropdowns
     * (including the Conditions tab's Dynamic Data source).
     */
    public static function register_tag_in_picker(array $tags): array
    {
        $tags[] = [
            'name'  => '{' . self::TAG_HAS_LOADOUT . '}',
            'label' => __('Product has Loadout', 'ffl-funnels-addons'),
            'group' => __('FFL Funnels', 'ffl-funnels-addons'),
        ];
        return $tags;
    }

    /**
     * Render the tag value when Bricks resolves a single tag (Conditions,
     * attribute values, etc.).
     *
     * @param string  $tag     Tag name without braces, e.g. "ffla_product_has_loadout".
     * @param WP_Post $post    Current post object Bricks is rendering against.
     * @param string  $context "text" or "link".
     */
    public static function render_tag($tag, $post = null, $context = 'text')
    {
        if ($tag !== self::TAG_HAS_LOADOUT) {
            return $tag;
        }
        return self::has_loadout($post) ? '1' : '';
    }

    /**
     * Replace the tag inside text content (used when the tag appears inline
     * in element text/HTML).
     */
    public static function render_content($content, $post = null, $context = 'text')
    {
        $needle = '{' . self::TAG_HAS_LOADOUT . '}';
        if (false === strpos((string) $content, $needle)) {
            return $content;
        }
        $value = self::has_loadout($post) ? '1' : '';
        return str_replace($needle, $value, $content);
    }

    /**
     * Resolve whether the post in render context has a Loadout that would
     * actually display content. Mirrors the resolver used by the Loadout
     * elements so the condition value matches what gets rendered.
     */
    private static function has_loadout($post = null): bool
    {
        $product_id = 0;

        if ($post instanceof WP_Post) {
            $product_id = (int) $post->ID;
        } elseif (is_numeric($post)) {
            $product_id = (int) $post;
        } else {
            // Fall back to the current global post when Bricks doesn't pass one.
            global $post;
            if ($post instanceof WP_Post) {
                $product_id = (int) $post->ID;
            }
        }

        if (!$product_id || get_post_type($product_id) !== 'product') {
            return false;
        }

        if (!class_exists('Loadout_Product_Admin')) {
            return false;
        }

        $config = Loadout_Product_Admin::get_product_config($product_id);
        $type   = isset($config['type']) ? $config['type'] : 'disabled';

        if ($type === 'global' && !empty($config['loadout'])) {
            return true;
        }

        if ($type === 'custom' && !empty($config['tiers'])) {
            return true;
        }

        return false;
    }
}
