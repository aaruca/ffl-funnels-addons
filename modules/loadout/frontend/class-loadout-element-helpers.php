<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shared resolver and renderer for the Loadout Bricks elements and the
 * [loadout] shortcode, so every surface shows the same tiers, prices and
 * markup (and the shared frontend JS works on all of them).
 *
 * Resolution priority:
 *   1. Explicit loadout_id (picked in the element) — only while it is active.
 *   2. Current single product page → linked active global Loadout.
 *   3. Current single product page → per-product custom tiers.
 */
class Loadout_Element_Helpers
{
    /**
     * Resolve tier data (id, slug, name, threshold) for the current context.
     *
     * @return array{loadout_id: int, product_loadout_id: int, tiers: array}
     */
    public static function resolve_tiers_for_current_context(int $explicit_loadout_id = 0): array
    {
        $data = self::resolve_full_tiers_for_current_context($explicit_loadout_id);

        $tiers = [];
        foreach ($data['tiers'] as $tier) {
            $tiers[] = [
                'id'        => (int) $tier['id'],
                'slug'      => (string) $tier['slug'],
                'name'      => (string) $tier['name'],
                'threshold' => (int) $tier['threshold'],
            ];
        }
        $data['tiers'] = $tiers;

        return $data;
    }

    /**
     * Resolve the full tier data (items, discounts, perks, bonus) for the
     * current context.
     *
     * @return array{loadout_id: int, product_loadout_id: int, tiers: array}
     */
    public static function resolve_full_tiers_for_current_context(int $explicit_loadout_id = 0): array
    {
        $empty = [
            'loadout_id'         => 0,
            'product_loadout_id' => 0,
            'tiers'              => [],
        ];

        // 1. Explicit selection (inactive loadouts are not shown anywhere).
        if ($explicit_loadout_id > 0) {
            $loadout = Loadout::get($explicit_loadout_id);
            if (!$loadout || !$loadout->get_status()) {
                return $empty;
            }
            return [
                'loadout_id'         => $explicit_loadout_id,
                'product_loadout_id' => 0,
                'tiers'              => self::global_tiers($explicit_loadout_id),
            ];
        }

        // 2 + 3. Current product page.
        $product_id = self::current_product_id();
        if ($product_id && class_exists('Loadout_Product_Admin')) {
            $config = Loadout_Product_Admin::get_product_config($product_id);

            if ($config['type'] === 'global' && $config['loadout'] instanceof Loadout) {
                $loadout_id = (int) $config['loadout']->get_id();
                return [
                    'loadout_id'         => $loadout_id,
                    'product_loadout_id' => (int) $product_id,
                    'tiers'              => self::global_tiers($loadout_id),
                ];
            }
            if ($config['type'] === 'custom') {
                return [
                    'loadout_id'         => 0,
                    'product_loadout_id' => (int) $product_id,
                    'tiers'              => self::normalize_custom_tiers((array) $config['tiers']),
                ];
            }
        }

        return $empty;
    }

    /**
     * Render-ready tiers of a global loadout.
     */
    public static function global_tiers(int $loadout_id): array
    {
        $out = [];
        foreach (Loadout_Tier::get_by_loadout($loadout_id) as $t) {
            $items = [];
            foreach ($t->get_items() as $it) {
                $items[] = [
                    'product_id'   => (int) $it->get_product_id(),
                    'quantity'     => max(1, (int) $it->get_quantity()),
                    'discount_pct' => (float) $it->get_discount_pct(),
                    'item_id'      => (int) $it->get_id(),
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
            if (!is_array($ct)) {
                continue;
            }
            $name = isset($ct['name']) ? (string) $ct['name'] : '';
            if ($name === '') {
                continue;
            }
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
                ];
            }
            $out[] = [
                'id'                  => 0,
                'slug'                => Loadout_Product_Admin::custom_tier_slug($ct),
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
     * Index of the tier shown first: the requested one, or the first tier when
     * the requested index does not exist.
     */
    public static function clamp_index(int $index, int $count): int
    {
        return ($index >= 0 && $index < $count) ? $index : 0;
    }

    /**
     * Echo the tier navigation buttons.
     */
    public static function render_tabs(array $tiers, int $default_index = 0): void
    {
        $tiers  = array_values($tiers);
        $active = self::clamp_index($default_index, count($tiers));

        echo '<nav class="ffla-loadout__tiers">';
        foreach ($tiers as $i => $tier) {
            printf(
                '<button type="button" class="ffla-loadout__tier-btn%s" data-tier-slug="%s" data-tier-id="%d" aria-selected="%s">%s</button>',
                $i === $active ? ' is-active' : '',
                esc_attr($tier['slug']),
                (int) $tier['id'],
                $i === $active ? 'true' : 'false',
                esc_html($tier['name'])
            );
        }
        echo '</nav>';
    }

    /**
     * Echo the recommended-products section (the per-tier panels). Must be
     * inside a `.ffla-loadout` wrapper carrying data-loadout-id and/or
     * data-product-loadout-id so the add-to-cart handler knows the context.
     */
    public static function render_recommended_section(array $tiers, int $default_index = 0): void
    {
        if (empty($tiers)) {
            echo '<section class="ffla-loadout__recommended"><p class="ffla-loadout__tier-empty">'
                . esc_html__('No loadout products configured for this context.', 'ffl-funnels-addons')
                . '</p></section>';
            return;
        }

        $tiers  = array_values($tiers);
        $active = self::clamp_index($default_index, count($tiers));

        echo '<section class="ffla-loadout__recommended">';
        foreach ($tiers as $i => $tier) {
            self::render_tier_panel($tier, $i === $active);
        }
        echo '</section>';
    }

    /**
     * Echo a single tier panel (products list + add buttons + perks + bonus).
     */
    private static function render_tier_panel(array $tier, bool $active): void
    {
        $accessory_discount = (float) ($tier['accessory_discount'] ?? 0);
        $threshold          = (int) ($tier['threshold'] ?? 0);
        ?>
        <div class="ffla-loadout__panel<?php echo $active ? ' is-active' : ''; ?>"
             data-tier-slug="<?php echo esc_attr($tier['slug']); ?>"
             data-tier-id="<?php echo esc_attr($tier['id']); ?>"
             data-threshold="<?php echo esc_attr($threshold); ?>">

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
                    $prices    = Loadout_Pricing::product_prices($p);
                    $pct       = Loadout_Pricing::combine($item['discount_pct'], $accessory_discount);
                    $reference = Loadout_Pricing::reference_price($prices['regular'], $prices['current']);
                    $final     = Loadout_Pricing::unit_price($prices['regular'], $prices['current'], $pct);
                    $saving    = Loadout_Pricing::saving_percent($reference, $final);
                    $qty       = (int) $item['quantity'];
                    $in_stock  = $p->is_in_stock();
                    $addable   = Loadout_Cart::is_addable($p, $qty);
                ?>
                    <li class="ffla-loadout__item<?php echo $in_stock ? '' : ' is-oos'; ?>">
                        <div class="ffla-loadout__item-thumb"><?php echo $p->get_image('thumbnail'); ?></div>
                        <div class="ffla-loadout__item-info">
                            <h4 class="ffla-loadout__item-name"><?php echo esc_html($p->get_name()); ?></h4>
                            <?php if ($p->get_average_rating()): ?>
                                <div class="ffla-loadout__item-rating">
                                    <?php echo wc_get_rating_html($p->get_average_rating()); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($saving > 0): ?>
                                <span class="ffla-loadout__badge"><?php echo esc_html($saving); ?>% OFF</span>
                            <?php endif; ?>
                            <div class="ffla-loadout__item-price">
                                <?php if ($final < $reference): ?>
                                    <s><?php echo wc_price($reference); ?></s>
                                <?php endif; ?>
                                <strong><?php echo wc_price($final); ?></strong>
                            </div>
                        </div>
                        <button type="button" class="ffla-loadout__add-btn"
                                data-product-id="<?php echo esc_attr($item['product_id']); ?>"
                                data-quantity="<?php echo esc_attr($qty); ?>"
                                data-item-id="<?php echo esc_attr($item['item_id']); ?>"
                                <?php disabled(!$addable); ?>>
                            <?php echo $in_stock ? esc_html__('ADD', 'ffl-funnels-addons') : esc_html__('OUT', 'ffl-funnels-addons'); ?>
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
                    <h5><?php echo $threshold > 0
                        ? esc_html__('Perks Unlocked at Threshold:', 'ffl-funnels-addons')
                        : esc_html__('Perks:', 'ffl-funnels-addons'); ?></h5>
                    <ul>
                        <?php foreach ($tier['perks'] as $perk): ?>
                            <li><?php echo esc_html($perk); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($tier['bonus_product_id']) && $threshold > 0): ?>
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
     * Link of a cross-sell tile. Category: slug or term ID. URL: as entered.
     * Loadout: slug or ID, opening that loadout's hero product (or its block
     * on the same page when it has no hero product).
     */
    public static function cross_sell_url($cs): string
    {
        $type  = (string) $cs->get_link_type();
        $value = trim((string) $cs->get_link_value());
        if ($value === '') {
            return '#';
        }

        switch ($type) {
            case 'category':
                $term = ctype_digit($value)
                    ? get_term((int) $value, 'product_cat')
                    : get_term_by('slug', sanitize_title($value), 'product_cat');
                if ($term && !is_wp_error($term)) {
                    $link = get_term_link($term);
                    if (!is_wp_error($link)) {
                        return (string) $link;
                    }
                }
                return '#';

            case 'url':
                $url = esc_url_raw($value);
                return $url !== '' ? $url : '#';

            case 'loadout':
                $loadout = ctype_digit($value) ? Loadout::get((int) $value) : Loadout::get_by_slug($value);
                if (!$loadout || !$loadout->get_status()) {
                    return '#';
                }
                $anchor = (int) $loadout->get_anchor_product_id();
                $url    = $anchor ? get_permalink($anchor) : '';
                return $url ? (string) $url : '#loadout-' . (int) $loadout->get_id();

            default:
                return '#';
        }
    }

    /**
     * Echo the "Complete Your Loadout" tiles of a global loadout.
     */
    public static function render_cross_sells(int $loadout_id): void
    {
        $cross_sells = Loadout_Cross_Sell::get_by_loadout($loadout_id);
        if (empty($cross_sells)) {
            return;
        }

        echo '<section class="ffla-loadout__cross-sells">';
        echo '<h3>' . esc_html__('Complete Your Loadout', 'ffl-funnels-addons') . '</h3>';
        echo '<div class="ffla-loadout__cross-sells-grid">';
        foreach ($cross_sells as $cs) {
            $cs_image = $cs->get_image_id() ? wp_get_attachment_image($cs->get_image_id(), 'medium') : '';
            echo '<a href="' . esc_url(self::cross_sell_url($cs)) . '" class="ffla-loadout__cross-sell-tile">';
            if ($cs_image) {
                echo $cs_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core image markup.
            }
            echo '<span>' . esc_html($cs->get_label()) . '</span>';
            echo '</a>';
        }
        echo '</div>';
        echo '</section>';
    }

    /**
     * Echo the header (brand logo, headline, subheadline) of a global loadout.
     * Carries id="loadout-<id>" so cross-sell links can point at it.
     */
    public static function render_header(Loadout $loadout): void
    {
        $brand_logo_url = $loadout->get_brand_logo_id()
            ? wp_get_attachment_image_url($loadout->get_brand_logo_id(), 'medium')
            : '';
        echo '<header class="ffla-loadout__header" id="loadout-' . (int) $loadout->get_id() . '">';
        if ($brand_logo_url) {
            echo '<img class="ffla-loadout__brand" src="' . esc_url($brand_logo_url) . '" alt="">';
        }
        echo '<h2 class="ffla-loadout__title">' . esc_html($loadout->get_headline() ?: $loadout->get_name()) . '</h2>';
        if ($loadout->get_subheadline()) {
            echo '<p class="ffla-loadout__subtitle">' . esc_html($loadout->get_subheadline()) . '</p>';
        }
        echo '</header>';
    }

    /**
     * Echo the anchor column for a global loadout: hero image (or the hero
     * product's image), name, price and an Add hero button.
     */
    public static function render_loadout_anchor(Loadout $loadout): void
    {
        $anchor_id      = (int) $loadout->get_anchor_product_id();
        $anchor_product = $anchor_id ? wc_get_product($anchor_id) : null;
        $hero_url       = $loadout->get_hero_image_id()
            ? wp_get_attachment_image_url($loadout->get_hero_image_id(), 'full')
            : '';

        echo '<aside class="ffla-loadout__anchor">';
        if ($anchor_product) {
            if ($hero_url) {
                echo '<img class="ffla-loadout__hero" src="' . esc_url($hero_url) . '" alt="">';
            } else {
                echo '<div class="ffla-loadout__hero-fallback">' . $anchor_product->get_image('medium') . '</div>';
            }
            echo '<h3 class="ffla-loadout__anchor-name">' . esc_html($anchor_product->get_name()) . '</h3>';
            echo '<div class="ffla-loadout__anchor-price">' . $anchor_product->get_price_html() . '</div>';
            printf(
                '<button type="button" class="ffla-loadout__add-btn ffla-loadout__add-anchor" data-product-id="%d" data-quantity="1"%s>%s</button>',
                (int) $anchor_product->get_id(),
                Loadout_Cart::is_addable($anchor_product, 1) ? '' : ' disabled="disabled"',
                esc_html__('ADD HERO', 'ffl-funnels-addons')
            );
        }
        echo '</aside>';
    }

    /**
     * Echo the anchor column for a product page: the product being viewed
     * (the main item of an Add cart line on that page).
     */
    public static function render_product_anchor(int $product_id): void
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        echo '<aside class="ffla-loadout__anchor">';
        echo '<div class="ffla-loadout__hero-fallback">' . $product->get_image('medium') . '</div>';
        echo '<h3 class="ffla-loadout__anchor-name">' . esc_html($product->get_name()) . '</h3>';
        echo '<div class="ffla-loadout__anchor-price">' . $product->get_price_html() . '</div>';
        echo '</aside>';
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
