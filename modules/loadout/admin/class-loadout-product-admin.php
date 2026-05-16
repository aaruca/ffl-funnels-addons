<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Product_Admin
{
    const META_LOADOUT_LINK = '_ffla_product_loadout_link';
    const META_ENABLE_TAB = '_ffla_product_loadout_enable_tab';
    const META_CUSTOM_TIERS = '_ffla_product_loadout_tiers';

    public function init(): void
    {
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'render_product_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_meta']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function enqueue_scripts($hook): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['product', 'edit-product'], true)) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script(
            'loadout-product-admin',
            plugins_url('js/loadout-admin.js', __FILE__),
            ['jquery', 'wp-util'],
            FFLA_VERSION,
            true
        );
        wp_enqueue_style(
            'loadout-admin',
            plugins_url('css/loadout-admin.css', __FILE__),
            [],
            FFLA_VERSION
        );
        wp_localize_script('loadout-product-admin', 'loadoutAdmin', [
            'nonce' => wp_create_nonce('loadout_admin'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'strings' => [
                'selectImage' => __('Select Image', 'ffl-funnels-addons'),
                'useImage' => __('Use this Image', 'ffl-funnels-addons'),
                'searching' => __('Searching...', 'ffl-funnels-addons'),
                'noResults' => __('No products found.', 'ffl-funnels-addons'),
            ],
        ]);
    }

    public function add_product_tab($tabs): array
    {
        $tabs['loadout'] = [
            'label' => __('Loadout', 'ffl-funnels-addons'),
            'target' => 'loadout_product_data',
            'class' => [],
            'priority' => 80,
        ];
        return $tabs;
    }

    public function render_product_panel(): void
    {
        global $post;
        $product_id = $post->ID;

        $linked_id = get_post_meta($product_id, self::META_LOADOUT_LINK, true);
        $enable_tab = get_post_meta($product_id, self::META_ENABLE_TAB, true);
        $custom_tiers_json = get_post_meta($product_id, self::META_CUSTOM_TIERS, true);
        $custom_tiers = $custom_tiers_json ? json_decode($custom_tiers_json, true) : [];

        $all_loadouts = Loadout::get_all(['status' => 1]);
        ?>
        <div id="loadout_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <p class="form-field">
                    <label for="loadout_enable_tab"><?php esc_html_e('Enable Loadout Tab', 'ffl-funnels-addons'); ?></label>
                    <input type="checkbox" name="loadout_enable_tab" id="loadout_enable_tab" value="1" <?php checked($enable_tab, 1); ?>>
                    <span class="description"><?php esc_html_e('Show a Loadout tab on this product\'s page.', 'ffl-funnels-addons'); ?></span>
                </p>

                <p class="form-field">
                    <label for="loadout_link"><?php esc_html_e('Link to Global Loadout', 'ffl-funnels-addons'); ?></label>
                    <select name="loadout_link" id="loadout_link">
                        <option value=""><?php esc_html_e('— Use per-product config below —', 'ffl-funnels-addons'); ?></option>
                        <?php foreach ($all_loadouts as $loadout): ?>
                            <option value="<?php echo esc_attr($loadout->get_id()); ?>" <?php selected($linked_id, $loadout->get_id()); ?>>
                                <?php echo esc_html($loadout->get_name()); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="description"><?php esc_html_e('When set, the linked loadout config is used and per-product config below is ignored.', 'ffl-funnels-addons'); ?></span>
                </p>
            </div>

            <div class="options_group">
                <h4 style="padding:0 12px;"><?php esc_html_e('Per-Product Configuration', 'ffl-funnels-addons'); ?></h4>
                <p style="padding:0 12px;color:#666;"><?php esc_html_e('Used only when no global loadout is linked above. Define tiers and items for this product specifically.', 'ffl-funnels-addons'); ?></p>

                <div id="loadout-product-tiers" class="loadout-repeater" style="padding:0 12px;">
                    <?php foreach ($custom_tiers as $tier_index => $tier_data): ?>
                        <?php $this->render_product_tier_row($tier_index, $tier_data); ?>
                    <?php endforeach; ?>
                </div>
                <p style="padding:0 12px;"><button type="button" class="button" id="add-product-tier"><?php esc_html_e('+ Add Tier', 'ffl-funnels-addons'); ?></button></p>
            </div>
        </div>

        <script type="text/html" id="tmpl-loadout-product-tier">
            <?php $this->render_product_tier_row(0, []); ?>
        </script>
        <script type="text/html" id="tmpl-loadout-product-item">
            <?php $this->render_product_item_row(0, 0, []); ?>
        </script>

        <script>
        jQuery(document).ready(function ($) {
            $('#add-product-tier').on('click', function () {
                var $container = $('#loadout-product-tiers');
                var index = $container.find('.loadout-tier-row').length;
                var template = $('#tmpl-loadout-product-tier').html();
                if (template) {
                    // Replace BOTH the form-name index AND data-tier-index/data-index
                    // attributes — otherwise new tiers' items submit under tier 0 and
                    // overwrite each other.
                    var html = template
                        .replace(/product_tiers\[0\]/g, 'product_tiers[' + index + ']')
                        .replace(/data-tier-index="0"/g, 'data-tier-index="' + index + '"')
                        .replace(/data-index="0"/g, 'data-index="' + index + '"');
                    $container.append(html);
                }
            });
            $(document).on('click', '.loadout-product-add-item', function () {
                var $btn = $(this);
                var tierIndex = $btn.data('tier-index');
                var $row = $btn.closest('.loadout-tier-row');
                var $items = $row.find('.loadout-tier-items');
                var itemIndex = $items.find('.loadout-item-row').length;
                var template = $('#tmpl-loadout-product-item').html();
                if (template) {
                    var html = template.replace(/product_tiers\[0\]\[items\]\[0\]/g, 'product_tiers[' + tierIndex + '][items][' + itemIndex + ']');
                    $items.append(html);
                }
            });
            $(document).on('click', '.loadout-tier-remove', function () {
                if (confirm('Remove this tier?')) {
                    $(this).closest('.loadout-tier-row').remove();
                }
            });
            $(document).on('click', '.loadout-item-remove', function () {
                $(this).closest('.loadout-item-row').remove();
            });
            $(document).on('input', '.loadout-tier-name-input', function () {
                $(this).closest('.loadout-tier-row').find('.loadout-tier-name').text($(this).val() || 'New Tier');
            });
        });
        </script>
        <?php
    }

    private function render_product_tier_row(int $index, array $tier_data): void
    {
        $name = $tier_data['name'] ?? '';
        $set_discount = $tier_data['set_discount_pct'] ?? 0;
        $items = $tier_data['items'] ?? [];
        ?>
        <div class="loadout-tier-row" data-index="<?php echo esc_attr($index); ?>">
            <div class="loadout-tier-header">
                <h4 class="loadout-tier-name"><?php echo esc_html($name ?: __('New Tier', 'ffl-funnels-addons')); ?></h4>
                <button type="button" class="button-link loadout-tier-remove"><?php esc_html_e('Remove Tier', 'ffl-funnels-addons'); ?></button>
            </div>

            <div class="loadout-tier-fields">
                <div class="loadout-field">
                    <label><?php esc_html_e('Tier Name', 'ffl-funnels-addons'); ?></label>
                    <input type="text" name="product_tiers[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($name); ?>" class="loadout-tier-name-input" placeholder="<?php esc_attr_e('Essential', 'ffl-funnels-addons'); ?>">
                </div>
                <div class="loadout-field">
                    <label><?php esc_html_e('Set Discount %', 'ffl-funnels-addons'); ?></label>
                    <input type="number" name="product_tiers[<?php echo esc_attr($index); ?>][set_discount_pct]" value="<?php echo esc_attr($set_discount); ?>" min="0" max="100" step="0.01">
                </div>
            </div>

            <div class="loadout-tier-items-section">
                <h5><?php esc_html_e('Items', 'ffl-funnels-addons'); ?></h5>
                <div class="loadout-tier-items">
                    <?php foreach ($items as $item_index => $item_data): ?>
                        <?php $this->render_product_item_row($index, $item_index, $item_data); ?>
                    <?php endforeach; ?>
                </div>
                <p class="loadout-add-item-wrap"><button type="button" class="button loadout-product-add-item" data-tier-index="<?php echo esc_attr($index); ?>"><?php esc_html_e('+ Add Item', 'ffl-funnels-addons'); ?></button></p>
            </div>
        </div>
        <?php
    }

    private function render_product_item_row(int $tier_index, int $item_index, array $item_data): void
    {
        $product_id = $item_data['product_id'] ?? 0;
        $quantity = $item_data['quantity'] ?? 1;
        $discount_pct = $item_data['discount_pct'] ?? 0;

        $product_name = '';
        if ($product_id) {
            $p = wc_get_product($product_id);
            if ($p) {
                $product_name = $p->get_name();
            }
        }
        ?>
        <div class="loadout-item-row">
            <input type="hidden" class="loadout-item-product-id" name="product_tiers[<?php echo esc_attr($tier_index); ?>][items][<?php echo esc_attr($item_index); ?>][product_id]" value="<?php echo esc_attr($product_id); ?>">

            <div class="loadout-item-grid">
                <div class="loadout-field loadout-field--product">
                    <label><?php esc_html_e('Product', 'ffl-funnels-addons'); ?></label>
                    <input type="text" class="loadout-product-search" data-target=".loadout-item-product-id" data-display=".loadout-item-product-display" data-scope="row" placeholder="<?php esc_attr_e('Search products...', 'ffl-funnels-addons'); ?>">
                    <div class="loadout-item-product-display loadout-product-display">
                        <?php if ($product_name): ?>
                            <span><?php echo esc_html($product_name); ?> (#<?php echo esc_html($product_id); ?>)</span>
                        <?php endif; ?>
                    </div>
                    <div class="loadout-search-results"></div>
                </div>
                <div class="loadout-field loadout-field--qty">
                    <label><?php esc_html_e('Qty', 'ffl-funnels-addons'); ?></label>
                    <input type="number" name="product_tiers[<?php echo esc_attr($tier_index); ?>][items][<?php echo esc_attr($item_index); ?>][quantity]" value="<?php echo esc_attr($quantity); ?>" min="1">
                </div>
                <div class="loadout-field loadout-field--discount">
                    <label><?php esc_html_e('Discount %', 'ffl-funnels-addons'); ?></label>
                    <input type="number" name="product_tiers[<?php echo esc_attr($tier_index); ?>][items][<?php echo esc_attr($item_index); ?>][discount_pct]" value="<?php echo esc_attr($discount_pct); ?>" min="0" max="100" step="0.01">
                </div>
                <div class="loadout-field loadout-field--remove">
                    <button type="button" class="button-link loadout-item-remove"><?php esc_html_e('Remove', 'ffl-funnels-addons'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_product_meta($product_id): void
    {
        if (!current_user_can('edit_product', $product_id)) {
            return;
        }

        // The action `woocommerce_process_product_meta` also fires on programmatic
        // saves (REST API, wc_update_product, etc.) where our form fields wouldn't
        // be present. Only proceed if at least one of our markers is in $_POST.
        if (
            !isset($_POST['loadout_enable_tab']) &&
            !isset($_POST['loadout_link']) &&
            !isset($_POST['product_tiers'])
        ) {
            return;
        }

        $enable_tab = !empty($_POST['loadout_enable_tab']) ? 1 : 0;
        $linked_id = isset($_POST['loadout_link']) ? absint($_POST['loadout_link']) : 0;

        update_post_meta($product_id, self::META_ENABLE_TAB, $enable_tab);
        update_post_meta($product_id, self::META_LOADOUT_LINK, $linked_id);

        // Save custom tiers — be permissive: keep tiers with name OR items, and
        // re-key to sequential 0..N so the JSON stays clean.
        $tiers_raw = isset($_POST['product_tiers']) && is_array($_POST['product_tiers']) ? $_POST['product_tiers'] : [];
        $custom_tiers = [];
        foreach ($tiers_raw as $tier_data) {
            if (!is_array($tier_data)) {
                continue;
            }
            $name = isset($tier_data['name']) ? sanitize_text_field(wp_unslash($tier_data['name'])) : '';
            $items = [];
            $items_raw = isset($tier_data['items']) && is_array($tier_data['items']) ? $tier_data['items'] : [];
            foreach ($items_raw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $pid = isset($item['product_id']) ? absint($item['product_id']) : 0;
                if (!$pid) {
                    continue;
                }
                $items[] = [
                    'product_id'   => $pid,
                    'quantity'     => isset($item['quantity']) ? max(1, absint($item['quantity'])) : 1,
                    'discount_pct' => isset($item['discount_pct']) ? floatval($item['discount_pct']) : 0,
                ];
            }
            // Skip only if BOTH name and items are empty (truly blank row).
            if ($name === '' && empty($items)) {
                continue;
            }
            $effective_name = $name !== '' ? $name : sprintf(__('Tier %d', 'ffl-funnels-addons'), count($custom_tiers) + 1);
            $custom_tiers[] = [
                'name'             => $effective_name,
                'slug'             => sanitize_title($effective_name) ?: ('tier-' . (count($custom_tiers) + 1)),
                'set_discount_pct' => isset($tier_data['set_discount_pct']) ? floatval($tier_data['set_discount_pct']) : 0,
                'items'            => $items,
            ];
        }

        if (!empty($custom_tiers)) {
            update_post_meta($product_id, self::META_CUSTOM_TIERS, wp_json_encode($custom_tiers));
        } else {
            delete_post_meta($product_id, self::META_CUSTOM_TIERS);
        }

        // Debug breadcrumb so we can verify the save handler actually ran.
        update_post_meta($product_id, '_ffla_loadout_last_save', current_time('mysql'));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[FFLA Loadout] Save fired on product #%d — enable_tab=%d, linked=%d, tiers=%d',
                $product_id,
                $enable_tab,
                $linked_id,
                count($custom_tiers)
            ));
        }
    }

    /**
     * Get the active loadout config for a product.
     * Returns ['type' => 'global'|'custom', 'loadout' => Loadout|null, 'tiers' => array]
     */
    public static function get_product_config(int $product_id): array
    {
        if (!get_post_meta($product_id, self::META_ENABLE_TAB, true)) {
            return ['type' => 'disabled', 'loadout' => null, 'tiers' => []];
        }

        $linked_id = (int) get_post_meta($product_id, self::META_LOADOUT_LINK, true);
        if ($linked_id) {
            $loadout = Loadout::get($linked_id);
            if ($loadout && $loadout->get_status()) {
                return [
                    'type' => 'global',
                    'loadout' => $loadout,
                    'tiers' => Loadout_Tier::get_by_loadout($linked_id),
                ];
            }
        }

        $custom_json = get_post_meta($product_id, self::META_CUSTOM_TIERS, true);
        $custom_tiers = $custom_json ? json_decode($custom_json, true) : [];

        return [
            'type' => $custom_tiers ? 'custom' : 'disabled',
            'loadout' => null,
            'tiers' => $custom_tiers,
        ];
    }
}
