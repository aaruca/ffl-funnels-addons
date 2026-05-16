<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Product_Tab
{
    public static function init(): void
    {
        add_filter('woocommerce_product_tabs', [__CLASS__, 'add_loadout_tab']);
    }

    public static function add_loadout_tab($tabs): array
    {
        global $product;
        if (!$product) {
            return $tabs;
        }

        $product_id = $product->get_id();
        $config = Loadout_Product_Admin::get_product_config($product_id);
        if ($config['type'] === 'disabled' || empty($config['tiers'])) {
            return $tabs;
        }

        $tabs['loadout'] = [
            'title' => __('Loadout', 'ffl-funnels-addons'),
            'priority' => 30,
            'callback' => [__CLASS__, 'render_tab_content'],
        ];

        return $tabs;
    }

    public static function render_tab_content(): void
    {
        global $product;
        if (!$product) {
            return;
        }

        $product_id = $product->get_id();
        $config = Loadout_Product_Admin::get_product_config($product_id);
        if ($config['type'] === 'disabled' || empty($config['tiers'])) {
            return;
        }

        $is_global = $config['type'] === 'global';
        $loadout_id = $is_global ? $config['loadout']->get_id() : 0;
        $product_loadout_id = $product_id;

        ?>
        <div class="ffla-loadout-tab" data-product-loadout-id="<?php echo esc_attr($product_loadout_id); ?>" data-loadout-id="<?php echo esc_attr($loadout_id); ?>">
            <?php if ($is_global && $config['loadout']): ?>
                <?php $loadout = $config['loadout']; ?>
                <?php if ($loadout->get_headline()): ?>
                    <h3><?php echo esc_html($loadout->get_headline()); ?></h3>
                <?php endif; ?>
            <?php endif; ?>

            <nav class="ffla-loadout-tab__tiers">
                <?php foreach ($config['tiers'] as $tier_index => $tier): ?>
                    <?php
                    $tier_id = $is_global ? $tier->get_id() : 0;
                    $tier_name = $is_global ? $tier->get_name() : ($tier['name'] ?? '');
                    $tier_slug = $is_global ? $tier->get_slug() : sanitize_title($tier['name'] ?? '');
                    ?>
                    <button type="button" class="ffla-loadout-tab__tier-btn<?php echo $tier_index === 0 ? ' is-active' : ''; ?>"
                            data-tier-slug="<?php echo esc_attr($tier_slug); ?>"
                            data-tier-id="<?php echo esc_attr($tier_id); ?>"
                            data-tier-index="<?php echo esc_attr($tier_index); ?>">
                        <?php echo esc_html($tier_name); ?>
                    </button>
                <?php endforeach; ?>
            </nav>

            <div class="ffla-loadout-tab__panels">
                <?php foreach ($config['tiers'] as $tier_index => $tier): ?>
                    <?php
                    $tier_id            = $is_global ? $tier->get_id() : 0;
                    $tier_name_str      = $is_global ? $tier->get_name() : ($tier['name'] ?? '');
                    $tier_slug          = $is_global ? $tier->get_slug() : sanitize_title($tier_name_str);
                    $set_discount       = $is_global ? $tier->get_set_discount_pct() : ($tier['set_discount_pct'] ?? 0);
                    $accessory_discount = $is_global ? $tier->get_accessory_discount() : ($tier['accessory_discount'] ?? 0);
                    $threshold          = $is_global ? $tier->get_threshold_items() : ($tier['threshold_items'] ?? 0);
                    $perks_list         = $is_global ? $tier->get_perks() : ($tier['perks'] ?? []);
                    $bonus_pid          = $is_global ? $tier->get_bonus_product_id() : ($tier['bonus_product_id'] ?? 0);
                    $bonus_label        = $is_global ? $tier->get_bonus_label() : ($tier['bonus_label'] ?? '');
                    $bonus_value        = $is_global ? $tier->get_bonus_display_value() : ($tier['bonus_display_value'] ?? null);
                    $items              = $is_global ? $tier->get_items() : ($tier['items'] ?? []);
                    ?>
                    <div class="ffla-loadout-tab__panel<?php echo $tier_index === 0 ? ' is-active' : ''; ?>"
                         data-tier-slug="<?php echo esc_attr($tier_slug); ?>"
                         data-tier-id="<?php echo esc_attr($tier_id); ?>"
                         data-threshold="<?php echo esc_attr($threshold); ?>">

                        <?php if ($set_discount > 0): ?>
                            <p class="ffla-loadout-tab__set-discount">
                                <?php printf(
                                    esc_html__('Add the entire %s tier and save an additional %s%%!', 'ffl-funnels-addons'),
                                    '<strong>' . esc_html($tier_name_str) . '</strong>',
                                    esc_html($set_discount)
                                ); ?>
                            </p>
                        <?php endif; ?>

                        <ul class="ffla-loadout-tab__items">
                            <?php foreach ($items as $item):
                                $item_product_id = $is_global ? $item->get_product_id() : ($item['product_id'] ?? 0);
                                $item_qty        = $is_global ? $item->get_quantity() : ($item['quantity'] ?? 1);
                                $item_discount   = $is_global ? $item->get_discount_pct() : ($item['discount_pct'] ?? 0);
                                $item_id_attr    = $is_global ? $item->get_id() : 0;
                                $is_required     = $is_global ? $item->get_is_required() : ($item['is_required'] ?? 0);
                                $product_obj     = wc_get_product($item_product_id);
                                if (!$product_obj || !$product_obj->is_in_stock()) {
                                    continue;
                                }
                                $regular_price = (float) $product_obj->get_regular_price();
                                if ($regular_price <= 0) {
                                    $regular_price = (float) $product_obj->get_price();
                                }
                                // Combine per-item discount + tier accessory discount, capped at 100.
                                $combined_discount = min(100, (float) $item_discount + (float) $accessory_discount);
                                $final_price       = $combined_discount > 0 ? $regular_price * (1 - $combined_discount / 100) : $regular_price;
                                $thumb             = $product_obj->get_image('thumbnail');
                            ?>
                                <li class="ffla-loadout-tab__item<?php echo $is_required ? ' is-required' : ''; ?>">
                                    <div class="ffla-loadout-tab__item-thumb"><?php echo $thumb; ?></div>
                                    <div class="ffla-loadout-tab__item-info">
                                        <h4><?php echo esc_html($product_obj->get_name()); ?> <span class="ffla-loadout-tab__item-qty">×<?php echo esc_html($item_qty); ?></span></h4>
                                        <?php if ($combined_discount > 0): ?>
                                            <span class="ffla-loadout-tab__item-badge"><?php echo esc_html(round($combined_discount)); ?>% OFF</span>
                                        <?php endif; ?>
                                        <div class="ffla-loadout-tab__item-price">
                                            <?php if ($combined_discount > 0): ?>
                                                <s><?php echo wc_price($regular_price); ?></s>
                                            <?php endif; ?>
                                            <strong><?php echo wc_price($final_price); ?></strong>
                                        </div>
                                    </div>
                                    <button type="button" class="button ffla-loadout-tab__add-btn"
                                            data-product-id="<?php echo esc_attr($item_product_id); ?>"
                                            data-quantity="<?php echo esc_attr($item_qty); ?>"
                                            data-discount-pct="<?php echo esc_attr($item_discount); ?>"
                                            data-item-id="<?php echo esc_attr($item_id_attr); ?>">
                                        <?php esc_html_e('ADD', 'ffl-funnels-addons'); ?>
                                    </button>
                                </li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if (!empty($perks_list)): ?>
                            <div class="ffla-loadout-tab__perks">
                                <strong><?php esc_html_e('Perks Unlocked:', 'ffl-funnels-addons'); ?></strong>
                                <ul>
                                    <?php foreach ($perks_list as $perk): ?>
                                        <li><?php echo esc_html($perk); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <?php if ($bonus_pid):
                            $bonus_obj = wc_get_product($bonus_pid);
                        ?>
                            <div class="ffla-loadout-tab__bonus">
                                <strong><?php echo esc_html($bonus_label ?: __('FREE Bonus Item', 'ffl-funnels-addons')); ?></strong>
                                <?php if ($bonus_obj): ?>
                                    <span class="ffla-loadout-tab__bonus-name"><?php echo esc_html($bonus_obj->get_name()); ?></span>
                                <?php endif; ?>
                                <?php if ($bonus_value): ?>
                                    <span class="ffla-loadout-tab__bonus-value"><?php printf(esc_html__('Valued at %s', 'ffl-funnels-addons'), wc_price($bonus_value)); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <p>
                            <button type="button" class="button button-primary ffla-loadout-tab__add-tier-btn"
                                    data-tier-id="<?php echo esc_attr($tier_id); ?>"
                                    data-tier-slug="<?php echo esc_attr($tier_slug); ?>">
                                <?php esc_html_e('ADD ENTIRE TIER', 'ffl-funnels-addons'); ?>
                            </button>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}
