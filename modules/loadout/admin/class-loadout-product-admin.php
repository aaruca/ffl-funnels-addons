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
        add_action('restrict_manage_posts', [$this, 'render_products_filter']);
        add_filter('parse_query', [$this, 'filter_products_query']);
    }

    /**
     * Render a "Loadout" dropdown on the admin Products list table.
     */
    public function render_products_filter($post_type): void
    {
        if ($post_type !== 'product') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $current = isset($_GET['loadout_filter']) ? sanitize_key(wp_unslash($_GET['loadout_filter'])) : '';
        ?>
        <select name="loadout_filter">
            <option value=""><?php esc_html_e('All loadouts', 'ffl-funnels-addons'); ?></option>
            <option value="has" <?php selected($current, 'has'); ?>><?php esc_html_e('With loadout', 'ffl-funnels-addons'); ?></option>
            <option value="none" <?php selected($current, 'none'); ?>><?php esc_html_e('Without loadout', 'ffl-funnels-addons'); ?></option>
        </select>
        <?php
    }

    /**
     * Filter the Products list by loadout status (enabled tab meta).
     */
    public function filter_products_query($query): void
    {
        global $pagenow, $typenow;

        if (!is_admin() || $pagenow !== 'edit.php' || $typenow !== 'product' || !$query->is_main_query()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filter = isset($_GET['loadout_filter']) ? sanitize_key(wp_unslash($_GET['loadout_filter'])) : '';
        if ($filter !== 'has' && $filter !== 'none') {
            return;
        }

        $meta_query = (array) $query->get('meta_query');

        if ($filter === 'has') {
            $meta_query[] = [
                'key'     => self::META_ENABLE_TAB,
                'value'   => '1',
                'compare' => '=',
            ];
        } else {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key'     => self::META_ENABLE_TAB,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => self::META_ENABLE_TAB,
                    'value'   => '1',
                    'compare' => '!=',
                ],
            ];
        }

        $query->set('meta_query', $meta_query);
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
            <details class="loadout-help-box" style="margin:12px;">
                <summary><strong><?php esc_html_e('How the Loadout tab works', 'ffl-funnels-addons'); ?></strong></summary>
                <div class="loadout-help-content" style="padding:8px 12px;">
                    <p><?php esc_html_e('You can attach a tiered cross-sell tab to this product. Customers see it as an extra tab on the product page and can add curated items (with optional discounts and a free bonus) alongside the main product.', 'ffl-funnels-addons'); ?></p>
                    <ul>
                        <li><?php esc_html_e('Enable the tab, then either pick a saved Loadout config or build a per-product one below.', 'ffl-funnels-addons'); ?></li>
                        <li><strong><?php esc_html_e('Accessory Discount %', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('Discount applied to each item the customer adds individually.', 'ffl-funnels-addons'); ?></li>
                        <li><strong><?php esc_html_e('Set Discount %', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('Extra discount when the customer adds the whole tier at once.', 'ffl-funnels-addons'); ?></li>
                        <li><strong><?php esc_html_e('Perk Threshold + Perks + Bonus', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('Add N items to unlock perks and get a free bonus product. Set threshold to 0 to disable.', 'ffl-funnels-addons'); ?></li>
                        <li><strong><?php esc_html_e('Pre-checked', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('The item is selected by default for the customer.', 'ffl-funnels-addons'); ?></li>
                    </ul>
                </div>
            </details>

            <div class="options_group">
                <p class="form-field">
                    <label for="loadout_enable_tab"><?php esc_html_e('Enable Loadout Tab', 'ffl-funnels-addons'); ?></label>
                    <input type="checkbox" name="loadout_enable_tab" id="loadout_enable_tab" value="1" <?php checked($enable_tab, 1); ?>>
                    <span class="description"><?php esc_html_e('Adds a "Loadout" tab to this product\'s page on the storefront. Without this checked, the tab is hidden even if you configure tiers below.', 'ffl-funnels-addons'); ?></span>
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
                    <span class="description"><?php esc_html_e('Optional. If you pick a saved Loadout here, that config drives the tab and any per-product config below is ignored. Use this when many products should share the same tier setup.', 'ffl-funnels-addons'); ?></span>
                </p>
            </div>

            <div class="options_group">
                <h4 style="padding:0 12px;"><?php esc_html_e('Per-Product Configuration', 'ffl-funnels-addons'); ?></h4>
                <p style="padding:0 12px;color:#666;"><?php esc_html_e('Used only when no global loadout is linked above. Each tier is a separate "package level" shown as a tab on the product page.', 'ffl-funnels-addons'); ?></p>

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
        $name                = $tier_data['name'] ?? '';
        $set_discount        = $tier_data['set_discount_pct'] ?? 0;
        $accessory_discount  = $tier_data['accessory_discount'] ?? 0;
        $threshold_items     = $tier_data['threshold_items'] ?? 0;
        $perks               = $tier_data['perks'] ?? [];
        $perks_text          = is_array($perks) ? implode("\n", $perks) : '';
        $bonus_product_id    = $tier_data['bonus_product_id'] ?? 0;
        $bonus_label         = $tier_data['bonus_label'] ?? '';
        $bonus_display_value = $tier_data['bonus_display_value'] ?? '';
        $items               = $tier_data['items'] ?? [];

        $bonus_name = '';
        $bonus_price_html = '';
        $bonus_stock_html = '';
        if ($bonus_product_id) {
            $bp = wc_get_product($bonus_product_id);
            if ($bp) {
                $bonus_name       = $bp->get_name();
                $bonus_price_html = $bp->get_price_html();
                $bonus_stock_html = class_exists('Loadout_Ajax') ? Loadout_Ajax::format_stock_html($bp) : '';
            }
        }
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
                    <span class="loadout-help"><?php esc_html_e('Tab label shown to the customer.', 'ffl-funnels-addons'); ?></span>
                </div>
                <div class="loadout-field">
                    <label><?php esc_html_e('Accessory Discount %', 'ffl-funnels-addons'); ?></label>
                    <input type="number" name="product_tiers[<?php echo esc_attr($index); ?>][accessory_discount]" value="<?php echo esc_attr($accessory_discount); ?>" min="0" max="100" step="0.01">
                    <span class="loadout-help"><?php esc_html_e('Per-item discount applied when customer adds items individually.', 'ffl-funnels-addons'); ?></span>
                </div>
                <div class="loadout-field">
                    <label><?php esc_html_e('Set Discount %', 'ffl-funnels-addons'); ?></label>
                    <input type="number" name="product_tiers[<?php echo esc_attr($index); ?>][set_discount_pct]" value="<?php echo esc_attr($set_discount); ?>" min="0" max="100" step="0.01">
                    <span class="loadout-help"><?php esc_html_e('Extra discount when the customer adds the whole tier together.', 'ffl-funnels-addons'); ?></span>
                </div>
                <div class="loadout-field">
                    <label><?php esc_html_e('Perk Threshold', 'ffl-funnels-addons'); ?></label>
                    <input type="number" name="product_tiers[<?php echo esc_attr($index); ?>][threshold_items]" value="<?php echo esc_attr($threshold_items); ?>" min="0">
                    <span class="loadout-help"><?php esc_html_e('Items the customer must add to unlock perks/bonus. 0 = always unlocked.', 'ffl-funnels-addons'); ?></span>
                </div>
            </div>

            <div class="loadout-field loadout-field--full">
                <label><?php esc_html_e('Perks (one per line)', 'ffl-funnels-addons'); ?></label>
                <textarea name="product_tiers[<?php echo esc_attr($index); ?>][perks]" rows="3" placeholder="<?php esc_attr_e("10% OFF accessories&#10;Priority Order Processing&#10;Free Upgraded Shipping", 'ffl-funnels-addons'); ?>"><?php echo esc_textarea($perks_text); ?></textarea>
                <span class="loadout-help"><?php esc_html_e('Cosmetic benefits shown when the threshold is met (display-only — these don\'t change cart pricing on their own).', 'ffl-funnels-addons'); ?></span>
            </div>

            <div class="loadout-tier-bonus">
                <h5><?php esc_html_e('Bonus Item (Free Gift)', 'ffl-funnels-addons'); ?></h5>
                <p class="loadout-help" style="margin:0 0 8px;"><?php esc_html_e('Free product auto-added to the cart at $0 when the customer hits the Perk Threshold. Auto-removed if they drop below threshold. Leave Bonus Product empty if you don\'t want a free gift.', 'ffl-funnels-addons'); ?></p>
                <input type="hidden" class="loadout-bonus-id" name="product_tiers[<?php echo esc_attr($index); ?>][bonus_product_id]" value="<?php echo esc_attr($bonus_product_id); ?>">
                <div class="loadout-bonus-row">
                    <div class="loadout-field loadout-field--product">
                        <label><?php esc_html_e('Bonus Product', 'ffl-funnels-addons'); ?></label>
                        <input type="text" class="loadout-product-search" data-target=".loadout-bonus-id" data-display=".loadout-bonus-display" data-scope="row" placeholder="<?php esc_attr_e('Search products...', 'ffl-funnels-addons'); ?>">
                        <div class="loadout-bonus-display loadout-product-display">
                            <?php if ($bonus_name): ?>
                                <span class="loadout-product-name"><?php echo esc_html($bonus_name); ?> (#<?php echo esc_html($bonus_product_id); ?>)</span>
                                <span class="loadout-product-price"><?php echo wp_kses_post($bonus_price_html); ?></span>
                                <?php echo wp_kses_post($bonus_stock_html); ?>
                            <?php endif; ?>
                        </div>
                        <div class="loadout-search-results"></div>
                    </div>
                    <div class="loadout-field">
                        <label><?php esc_html_e('Bonus Label', 'ffl-funnels-addons'); ?></label>
                        <input type="text" name="product_tiers[<?php echo esc_attr($index); ?>][bonus_label]" value="<?php echo esc_attr($bonus_label); ?>" placeholder="<?php esc_attr_e('FREE Kinetic Armory', 'ffl-funnels-addons'); ?>">
                        <span class="loadout-help"><?php esc_html_e('Custom display text for the bonus.', 'ffl-funnels-addons'); ?></span>
                    </div>
                    <div class="loadout-field">
                        <label><?php esc_html_e('Display Value', 'ffl-funnels-addons'); ?></label>
                        <input type="number" name="product_tiers[<?php echo esc_attr($index); ?>][bonus_display_value]" value="<?php echo esc_attr($bonus_display_value); ?>" min="0" step="0.01" placeholder="30.00">
                        <span class="loadout-help"><?php esc_html_e('Cosmetic "valued at $X" amount.', 'ffl-funnels-addons'); ?></span>
                    </div>
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
        $product_id   = $item_data['product_id'] ?? 0;
        $quantity     = $item_data['quantity'] ?? 1;
        $discount_pct = $item_data['discount_pct'] ?? 0;
        $is_required  = $item_data['is_required'] ?? 0;

        $product_name = '';
        $price_html   = '';
        $stock_html   = '';
        if ($product_id) {
            $p = wc_get_product($product_id);
            if ($p) {
                $product_name = $p->get_name();
                $price_html   = $p->get_price_html();
                $stock_html   = (class_exists('Loadout_Ajax') ? Loadout_Ajax::format_stock_html($p) : '');
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
                            <span class="loadout-product-name"><?php echo esc_html($product_name); ?> (#<?php echo esc_html($product_id); ?>)</span>
                            <span class="loadout-product-price"><?php echo wp_kses_post($price_html); ?></span>
                            <?php echo wp_kses_post($stock_html); ?>
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
                <div class="loadout-field loadout-field--required">
                    <label><?php esc_html_e('Pre-checked', 'ffl-funnels-addons'); ?></label>
                    <input type="checkbox" name="product_tiers[<?php echo esc_attr($tier_index); ?>][items][<?php echo esc_attr($item_index); ?>][is_required]" value="1" <?php checked($is_required, 1); ?>>
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
                    'is_required'  => !empty($item['is_required']) ? 1 : 0,
                ];
            }
            // Skip only if BOTH name and items are empty (truly blank row).
            if ($name === '' && empty($items)) {
                continue;
            }
            $effective_name = $name !== '' ? $name : sprintf(__('Tier %d', 'ffl-funnels-addons'), count($custom_tiers) + 1);

            // Perks: textarea, one perk per line.
            $perks = [];
            if (isset($tier_data['perks'])) {
                $perks_raw = sanitize_textarea_field(wp_unslash($tier_data['perks']));
                $perks = array_values(array_filter(array_map('trim', preg_split('/\R/', $perks_raw))));
            }

            $custom_tiers[] = [
                'name'                => $effective_name,
                'slug'                => sanitize_title($effective_name) ?: ('tier-' . (count($custom_tiers) + 1)),
                'accessory_discount'  => isset($tier_data['accessory_discount']) ? floatval($tier_data['accessory_discount']) : 0,
                'set_discount_pct'    => isset($tier_data['set_discount_pct']) ? floatval($tier_data['set_discount_pct']) : 0,
                'threshold_items'     => isset($tier_data['threshold_items']) ? absint($tier_data['threshold_items']) : 0,
                'perks'               => $perks,
                'bonus_product_id'    => isset($tier_data['bonus_product_id']) ? absint($tier_data['bonus_product_id']) : 0,
                'bonus_label'         => isset($tier_data['bonus_label']) ? sanitize_text_field(wp_unslash($tier_data['bonus_label'])) : '',
                'bonus_display_value' => isset($tier_data['bonus_display_value']) && $tier_data['bonus_display_value'] !== '' ? floatval($tier_data['bonus_display_value']) : null,
                'items'               => $items,
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
