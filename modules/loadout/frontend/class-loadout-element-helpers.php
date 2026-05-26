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
     * Resolve the full tier data (including per-tier items, discounts, perks
     * and bonus) for the current context. Mirrors resolve_tiers_for_current_context()
     * but returns everything the recommended-products panels need to render.
     *
     * Returns:
     *   [
     *     'loadout_id'         => int,
     *     'product_loadout_id' => int,
     *     'tiers'              => array<int, array>  // normalized tier rows
     *   ]
     */
    public static function resolve_full_tiers_for_current_context(int $explicit_loadout_id = 0): array
    {
        $loadout_id         = 0;
        $product_loadout_id = 0;
        $tiers              = [];

        // 1. Explicit selection.
        if ($explicit_loadout_id > 0) {
            $loadout_id = $explicit_loadout_id;
            $tiers      = self::normalize_global_tiers(Loadout_Tier::get_by_loadout($loadout_id));
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
                $tiers      = self::normalize_global_tiers(Loadout_Tier::get_by_loadout($loadout_id));
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
                $tiers              = self::normalize_global_tiers(Loadout_Tier::get_by_loadout($loadout_id));
            } elseif ($config['type'] === 'custom') {
                $product_loadout_id = (int) $product_id;
                $tiers              = self::normalize_custom_tiers((array) $config['tiers']);
            }
        }

        return [
            'loadout_id'         => $loadout_id,
            'product_loadout_id' => $product_loadout_id,
            'tiers'              => $tiers,
        ];
    }

    /**
     * Normalize an array of Loadout_Tier objects into the render-ready shape.
     *
     * @param Loadout_Tier[] $objects
     */
    private static function normalize_global_tiers(array $objects): array
    {
        $out = [];
        foreach ($objects as $t) {
            $items = [];
            foreach ($t->get_items() as $it) {
                $items[] = [
                    'product_id'   => (int) $it->get_product_id(),
                    'quantity'     => (int) $it->get_quantity(),
                    'discount_pct' => (float) $it->get_discount_pct(),
                    'item_id'      => (int) $it->get_id(),
                    'is_required'  => (int) $it->get_is_required(),
                ];
            }
            $out[] = [
                'id'                  => (int) $t->get_id(),
                'slug'                => (string) $t->get_slug(),
                'name'                => (string) $t->get_name(),
                'threshold'           => (int) $t->get_threshold_items(),
                'accessory_discount'  => (float) $t->get_accessory_discount(),
                'perks'               => (array) $t->get_perks(),
                'bonus_product_id'    => (int) $t->get_bonus_product_id(),
                'bonus_label'         => (string) $t->get_bonus_label(),
                'bonus_display_value' => $t->get_bonus_display_value(),
                'items'               => $items,
            ];
        }
        return $out;
    }

    /**
     * Normalize per-product custom tier arrays into the render-ready shape.
     */
    private static function normalize_custom_tiers(array $custom): array
    {
        $out = [];
        foreach ($custom as $ct) {
            $name = isset($ct['name']) ? (string) $ct['name'] : '';
            if ($name === '') {
                continue;
            }
            $slug  = isset($ct['slug']) && $ct['slug'] !== '' ? (string) $ct['slug'] : sanitize_title($name);
            $items = [];
            foreach ((array) ($ct['items'] ?? []) as $it) {
                $pid = isset($it['product_id']) ? (int) $it['product_id'] : 0;
                if (!$pid) {
                    continue;
                }
                $items[] = [
                    'product_id'   => $pid,
                    'quantity'     => isset($it['quantity']) ? max(1, (int) $it['quantity']) : 1,
                    'discount_pct' => isset($it['discount_pct']) ? (float) $it['discount_pct'] : 0,
                    'item_id'      => 0,
                    'is_required'  => !empty($it['is_required']) ? 1 : 0,
                ];
            }
            $out[] = [
                'id'                  => 0,
                'slug'                => $slug,
                'name'                => $name,
                'threshold'           => isset($ct['threshold_items']) ? (int) $ct['threshold_items'] : 0,
                'accessory_discount'  => isset($ct['accessory_discount']) ? (float) $ct['accessory_discount'] : 0,
                'perks'               => (array) ($ct['perks'] ?? []),
                'bonus_product_id'    => isset($ct['bonus_product_id']) ? (int) $ct['bonus_product_id'] : 0,
                'bonus_label'         => isset($ct['bonus_label']) ? (string) $ct['bonus_label'] : '',
                'bonus_display_value' => $ct['bonus_display_value'] ?? null,
                'items'               => $items,
            ];
        }
        return $out;
    }

    /**
     * Echo the recommended-products section (the per-tier panels) for the
     * given normalized tiers. Markup matches the monolithic Loadout element so
     * the shared frontend JS/CSS (tier switching, add-to-cart) works unchanged.
     *
     * Must be placed inside a `.ffla-loadout` wrapper carrying data-loadout-id
     * so the add-to-cart handler can resolve the loadout context.
     */
    public static function render_recommended_section(array $tiers, int $default_index = 0): void
    {
        if (empty($tiers)) {
            echo '<section class="ffla-loadout__recommended"><p class="ffla-loadout__tier-empty">'
                . esc_html__('No loadout products configured for this context.', 'ffl-funnels-addons')
                . '</p></section>';
            return;
        }

        echo '<section class="ffla-loadout__recommended">';
        foreach (array_values($tiers) as $i => $tier) {
            self::render_tier_panel($tier, $i === $default_index);
        }
        echo '</section>';
    }

    /**
     * Echo a single tier panel (products list + add buttons + perks + bonus).
     */
    private static function render_tier_panel(array $tier, bool $active): void
    {
        $accessory_discount = (float) ($tier['accessory_discount'] ?? 0);
        ?>
        <div class="ffla-loadout__panel<?php echo $active ? ' is-active' : ''; ?>"
             data-tier-slug="<?php echo esc_attr($tier['slug']); ?>"
             data-tier-id="<?php echo esc_attr($tier['id']); ?>"
             data-threshold="<?php echo esc_attr($tier['threshold']); ?>">

            <h3 class="ffla-loadout__panel-title">
                <?php
                printf(
                    /* translators: %s: tier name */
                    esc_html__('Recommended %s Setup', 'ffl-funnels-addons'),
                    esc_html($tier['name'])
                );
                ?>
            </h3>

            <ul class="ffla-loadout__items">
                <?php foreach ($tier['items'] as $item):
                    $p = wc_get_product($item['product_id']);
                    if (!$p) {
                        continue;
                    }
                    $regular_price = (float) $p->get_regular_price();
                    if ($regular_price <= 0) {
                        $regular_price = (float) $p->get_price();
                    }
                    $combined_discount = min(100, (float) $item['discount_pct'] + $accessory_discount);
                    $final_price       = $combined_discount > 0 ? $regular_price * (1 - $combined_discount / 100) : $regular_price;
                    $is_in_stock       = $p->is_in_stock();
                ?>
                    <li class="ffla-loadout__item<?php echo $is_in_stock ? '' : ' is-oos'; ?>">
                        <div class="ffla-loadout__item-thumb"><?php echo $p->get_image('thumbnail'); ?></div>
                        <div class="ffla-loadout__item-info">
                            <h4 class="ffla-loadout__item-name"><?php echo esc_html($p->get_name()); ?></h4>
                            <?php if ($p->get_average_rating()): ?>
                                <div class="ffla-loadout__item-rating">
                                    <?php echo wc_get_rating_html($p->get_average_rating()); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($combined_discount > 0): ?>
                                <span class="ffla-loadout__badge"><?php echo esc_html(round($combined_discount)); ?>% OFF</span>
                            <?php endif; ?>
                            <div class="ffla-loadout__item-price">
                                <?php if ($combined_discount > 0): ?>
                                    <s><?php echo wc_price($regular_price); ?></s>
                                <?php endif; ?>
                                <strong><?php echo wc_price($final_price); ?></strong>
                            </div>
                        </div>
                        <button type="button" class="ffla-loadout__add-btn"
                                data-product-id="<?php echo esc_attr($item['product_id']); ?>"
                                data-quantity="<?php echo esc_attr($item['quantity']); ?>"
                                data-discount-pct="<?php echo esc_attr($item['discount_pct']); ?>"
                                data-item-id="<?php echo esc_attr($item['item_id']); ?>"
                                <?php disabled(!$is_in_stock); ?>>
                            <?php echo $is_in_stock ? esc_html__('ADD', 'ffl-funnels-addons') : esc_html__('OUT', 'ffl-funnels-addons'); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <button type="button" class="ffla-loadout__add-tier-btn"
                    data-tier-id="<?php echo esc_attr($tier['id']); ?>"
                    data-tier-slug="<?php echo esc_attr($tier['slug']); ?>">
                <?php esc_html_e('ADD CART', 'ffl-funnels-addons'); ?>
            </button>

            <?php if (!empty($tier['perks'])): ?>
                <div class="ffla-loadout__perks">
                    <h5><?php esc_html_e('Perks Unlocked at Threshold:', 'ffl-funnels-addons'); ?></h5>
                    <ul>
                        <?php foreach ($tier['perks'] as $perk): ?>
                            <li><?php echo esc_html($perk); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($tier['bonus_product_id'])): ?>
                <div class="ffla-loadout__bonus">
                    <strong><?php echo esc_html($tier['bonus_label'] ?: __('FREE Bonus Item', 'ffl-funnels-addons')); ?></strong>
                    <?php if (!empty($tier['bonus_display_value'])): ?>
                        <span class="ffla-loadout__bonus-value">
                            <?php printf(
                                /* translators: %s: formatted price */
                                esc_html__('Valued at %s', 'ffl-funnels-addons'),
                                wc_price($tier['bonus_display_value'])
                            ); ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
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
