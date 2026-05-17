<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared resolver used by the small composable Bricks elements (Tier Tabs,
 * Progress Bar, Cart Mirror) so they can auto-detect the right Loadout
 * config when placed on a product page — without making the builder pick
 * the loadout from a dropdown every time.
 *
 * Priority:
 *   1. Explicit loadout_id passed in (builder selected one from the dropdown)
 *   2. Current loop's Loadout / Loadout_Tier object (if inside a Bricks loop)
 *   3. Current single product page → linked global Loadout
 *   4. Current single product page → per-product custom tiers
 */
class Loadout_Element_Helpers
{
    /**
     * Resolve tier data for the current rendering context.
     *
     * Returns an array shaped like:
     *   [
     *     'loadout_id'         => int|0   // global Loadout id, if any
     *     'product_loadout_id' => int|0   // product page id (for per-product custom tiers)
     *     'tiers'              => array<int, ['id' => int, 'slug' => string, 'name' => string]>
     *   ]
     */
    public static function resolve_tiers_for_current_context(int $explicit_loadout_id = 0): array
    {
        $loadout_id         = 0;
        $product_loadout_id = 0;
        $tiers              = [];

        // 1. Explicit selection.
        if ($explicit_loadout_id > 0) {
            $loadout_id = $explicit_loadout_id;
            foreach (Loadout_Tier::get_by_loadout($loadout_id) as $t) {
                $tiers[] = [
                    'id'   => (int) $t->get_id(),
                    'slug' => (string) $t->get_slug(),
                    'name' => (string) $t->get_name(),
                ];
            }
            return [
                'loadout_id'         => $loadout_id,
                'product_loadout_id' => 0,
                'tiers'              => $tiers,
            ];
        }

        // 2. Bricks query loop context.
        if (class_exists('Loadout_Bricks')) {
            $loadout = Loadout_Bricks::current_loop_object(Loadout_Bricks::QUERY_LOADOUTS);
            $tier    = Loadout_Bricks::current_loop_object(Loadout_Bricks::QUERY_TIERS);
            if (!$loadout && $tier instanceof Loadout_Tier) {
                $loadout = Loadout::get($tier->get_loadout_id());
            }
            if ($loadout instanceof Loadout) {
                $loadout_id = $loadout->get_id();
                foreach (Loadout_Tier::get_by_loadout($loadout_id) as $t) {
                    $tiers[] = [
                        'id'   => (int) $t->get_id(),
                        'slug' => (string) $t->get_slug(),
                        'name' => (string) $t->get_name(),
                    ];
                }
                return [
                    'loadout_id'         => $loadout_id,
                    'product_loadout_id' => 0,
                    'tiers'              => $tiers,
                ];
            }
        }

        // 3 + 4. Current product page.
        $product_id = self::current_product_id();
        if ($product_id && class_exists('Loadout_Product_Admin')) {
            $config = Loadout_Product_Admin::get_product_config($product_id);

            if ($config['type'] === 'global' && $config['loadout'] instanceof Loadout) {
                $loadout_id         = (int) $config['loadout']->get_id();
                $product_loadout_id = (int) $product_id;
                foreach (Loadout_Tier::get_by_loadout($loadout_id) as $t) {
                    $tiers[] = [
                        'id'   => (int) $t->get_id(),
                        'slug' => (string) $t->get_slug(),
                        'name' => (string) $t->get_name(),
                    ];
                }
            } elseif ($config['type'] === 'custom') {
                $product_loadout_id = (int) $product_id;
                foreach ((array) $config['tiers'] as $ct) {
                    $name = isset($ct['name']) ? (string) $ct['name'] : '';
                    $slug = isset($ct['slug']) && $ct['slug'] !== ''
                        ? (string) $ct['slug']
                        : sanitize_title($name);
                    if ($name === '') {
                        continue;
                    }
                    $tiers[] = [
                        'id'   => 0,
                        'slug' => $slug,
                        'name' => $name,
                    ];
                }
            }
        }

        return [
            'loadout_id'         => $loadout_id,
            'product_loadout_id' => $product_loadout_id,
            'tiers'              => $tiers,
        ];
    }

    /**
     * Best-effort current product ID lookup. Works on single product pages,
     * within Bricks templates assigned to product post types, and on AJAX
     * calls that pass through `bricks_render_dynamic_data({post_id})`.
     */
    public static function current_product_id(): int
    {
        if (is_singular('product')) {
            return (int) get_queried_object_id();
        }
        global $post, $product;
        if ($product instanceof WC_Product) {
            return (int) $product->get_id();
        }
        if ($post && isset($post->post_type) && $post->post_type === 'product') {
            return (int) $post->ID;
        }
        $maybe = (int) get_the_ID();
        if ($maybe && get_post_type($maybe) === 'product') {
            return $maybe;
        }
        return 0;
    }
}
