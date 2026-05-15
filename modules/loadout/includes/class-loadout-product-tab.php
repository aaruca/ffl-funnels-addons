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
                    $tier_id = $is_global ? $tier->get_id() : 0;
                    $tier_slug = $is_global ? $tier->get_slug() : sanitize_title($tier['name'] ?? '');
                    $set_discount = $is_global ? $tier->get_set_discount_pct() : ($tier['set_discount_pct'] ?? 0);
                    $items = $is_global ? $tier->get_items() : ($tier['items'] ?? []);
                    ?>
                    <div class="ffla-loadout-tab__panel<?php echo $tier_index === 0 ? ' is-active' : ''; ?>"
                         data-tier-slug="<?php echo esc_attr($tier_slug); ?>"
                         data-tier-id="<?php echo esc_attr($tier_id); ?>">

                        <?php if ($set_discount > 0): ?>
                            <p class="ffla-loadout-tab__set-discount">
                                <?php printf(
                                    esc_html__('Add the entire %s tier and save an additional %s%%!', 'ffl-funnels-addons'),
                                    '<strong>' . esc_html($is_global ? $tier->get_name() : $tier['name']) . '</strong>',
                                    esc_html($set_discount)
                                ); ?>
                            </p>
                        <?php endif; ?>

                        <ul class="ffla-loadout-tab__items">
                            <?php foreach ($items as $item):
                                $item_product_id = $is_global ? $item->get_product_id() : ($item['product_id'] ?? 0);
                                $item_qty = $is_global ? $item->get_quantity() : ($item['quantity'] ?? 1);
                                $item_discount = $is_global ? $item->get_discount_pct() : ($item['discount_pct'] ?? 0);
                                $item_id_attr = $is_global ? $item->get_id() : 0;
                                $product_obj = wc_get_product($item_product_id);
                                if (!$product_obj || !$product_obj->is_in_stock()) {
                                    continue;
                                }
                                $regular_price = (float) $product_obj->get_regular_price();
                                if ($regular_price <= 0) {
                                    $regular_price = (float) $product_obj->get_price();
                                }
                                $final_price = $item_discount > 0 ? $regular_price * (1 - $item_discount / 100) : $regular_price;
                                $thumb = $product_obj->get_image('thumbnail');
                            ?>
                                <li class="ffla-loadout-tab__item">
                                    <div class="ffla-loadout-tab__item-thumb"><?php echo $thumb; ?></div>
                                    <div class="ffla-loadout-tab__item-info">
                                        <h4><?php echo esc_html($product_obj->get_name()); ?></h4>
                                        <?php if ($item_discount > 0): ?>
                                            <span class="ffla-loadout-tab__item-badge"><?php echo esc_html($item_discount); ?>% OFF</span>
                                        <?php endif; ?>
                                        <div class="ffla-loadout-tab__item-price">
                                            <?php if ($item_discount > 0): ?>
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
