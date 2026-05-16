<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Form
{
    public static function handle_save(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (!isset($_POST['loadout_nonce']) || !wp_verify_nonce(sanitize_key($_POST['loadout_nonce']), 'loadout_save')) {
            return;
        }

        $loadout_id = isset($_POST['loadout_id']) ? absint($_POST['loadout_id']) : 0;

        $data = [
            'name'              => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
            'status'            => isset($_POST['status']) ? absint($_POST['status']) : 1,
            'anchor_product_id' => isset($_POST['anchor_product_id']) ? absint($_POST['anchor_product_id']) : null,
            'hero_image_id'     => isset($_POST['hero_image_id']) ? absint($_POST['hero_image_id']) : null,
            'brand_logo_id'     => isset($_POST['brand_logo_id']) ? absint($_POST['brand_logo_id']) : null,
            'headline'          => isset($_POST['headline']) ? sanitize_text_field(wp_unslash($_POST['headline'])) : null,
            'subheadline'       => isset($_POST['subheadline']) ? sanitize_text_field(wp_unslash($_POST['subheadline'])) : null,
        ];

        if ($loadout_id) {
            $loadout = Loadout::get($loadout_id);
            if (!$loadout) {
                return;
            }
            $loadout->set_name($data['name']);
            $loadout->set_status($data['status']);
            $loadout->set_anchor_product_id($data['anchor_product_id']);
            $loadout->set_hero_image_id($data['hero_image_id']);
            $loadout->set_brand_logo_id($data['brand_logo_id']);
            $loadout->set_headline($data['headline']);
            $loadout->set_subheadline($data['subheadline']);
            $loadout->save();
        } else {
            $loadout = Loadout::create($data);
            if (!$loadout) {
                return;
            }
            $loadout_id = $loadout->get_id();
        }

        // Save tiers.
        self::save_tiers($loadout_id);

        // Save cross-sells.
        self::save_cross_sells($loadout_id);

        wp_safe_redirect(admin_url('admin.php?page=ffla-loadouts&action=edit&loadout_id=' . $loadout_id . '&saved=1'));
        exit;
    }

    private static function save_tiers(int $loadout_id): void
    {
        global $wpdb;

        $tiers_raw = isset($_POST['tiers']) ? (array) $_POST['tiers'] : [];
        $existing_tiers = Loadout_Tier::get_by_loadout($loadout_id);
        $existing_ids = array_map(fn($t) => $t->get_id(), $existing_tiers);
        $kept_ids = [];

        foreach ($tiers_raw as $index => $tier_data) {
            $tier_id = isset($tier_data['id']) ? absint($tier_data['id']) : 0;

            $perks_input = isset($tier_data['perks']) ? sanitize_text_field(wp_unslash($tier_data['perks'])) : '';
            $perks = array_filter(array_map('trim', explode("\n", $perks_input)));

            $data = [
                'name'                => isset($tier_data['name']) ? sanitize_text_field(wp_unslash($tier_data['name'])) : '',
                'slug'                => isset($tier_data['slug']) ? sanitize_title(wp_unslash($tier_data['slug'])) : '',
                'sort_order'          => $index,
                'accessory_discount'  => isset($tier_data['accessory_discount']) ? floatval($tier_data['accessory_discount']) : 0,
                'set_discount_pct'    => isset($tier_data['set_discount_pct']) ? floatval($tier_data['set_discount_pct']) : 0,
                'perks'               => $perks,
                'bonus_product_id'    => isset($tier_data['bonus_product_id']) ? absint($tier_data['bonus_product_id']) : null,
                'bonus_label'         => isset($tier_data['bonus_label']) ? sanitize_text_field(wp_unslash($tier_data['bonus_label'])) : null,
                'bonus_display_value' => isset($tier_data['bonus_display_value']) ? floatval($tier_data['bonus_display_value']) : null,
                'threshold_items'     => isset($tier_data['threshold_items']) ? absint($tier_data['threshold_items']) : 0,
            ];

            if (empty($data['name'])) {
                continue;
            }

            if ($tier_id && in_array($tier_id, $existing_ids, true)) {
                $tier = Loadout_Tier::get($tier_id);
                if ($tier) {
                    $tier->set_name($data['name']);
                    $tier->set_sort_order($data['sort_order']);
                    $tier->set_accessory_discount($data['accessory_discount']);
                    $tier->set_set_discount_pct($data['set_discount_pct']);
                    $tier->set_perks($data['perks']);
                    $tier->set_bonus_product_id($data['bonus_product_id']);
                    $tier->set_bonus_label($data['bonus_label']);
                    $tier->set_bonus_display_value($data['bonus_display_value']);
                    $tier->set_threshold_items($data['threshold_items']);
                    $tier->save();
                    $kept_ids[] = $tier_id;
                }
            } else {
                $new_tier = Loadout_Tier::create($loadout_id, $data);
                if ($new_tier) {
                    $tier_id = $new_tier->get_id();
                    $kept_ids[] = $tier_id;
                }
            }

            // Save items in this tier.
            if ($tier_id) {
                self::save_tier_items($tier_id, $tier_data['items'] ?? []);
            }
        }

        // Delete tiers no longer in the form.
        foreach ($existing_ids as $eid) {
            if (!in_array($eid, $kept_ids, true)) {
                Loadout_Tier::delete($eid);
            }
        }
    }

    private static function save_tier_items(int $tier_id, array $items_raw): void
    {
        $existing_items = Loadout_Tier_Item::get_by_tier($tier_id);
        $existing_ids = array_map(fn($i) => $i->get_id(), $existing_items);
        $kept_ids = [];

        foreach ($items_raw as $index => $item_data) {
            $item_id = isset($item_data['id']) ? absint($item_data['id']) : 0;
            $product_id = isset($item_data['product_id']) ? absint($item_data['product_id']) : 0;

            if (!$product_id) {
                continue;
            }

            $data = [
                'product_id'  => $product_id,
                'quantity'    => isset($item_data['quantity']) ? max(1, absint($item_data['quantity'])) : 1,
                'discount_pct' => isset($item_data['discount_pct']) ? floatval($item_data['discount_pct']) : 0,
                'is_required' => !empty($item_data['is_required']) ? 1 : 0,
                'sort_order'  => $index,
            ];

            if ($item_id && in_array($item_id, $existing_ids, true)) {
                $item = Loadout_Tier_Item::get($item_id);
                if ($item) {
                    $item->set_product_id($data['product_id']);
                    $item->set_quantity($data['quantity']);
                    $item->set_discount_pct($data['discount_pct']);
                    $item->set_is_required($data['is_required']);
                    $item->set_sort_order($data['sort_order']);
                    $item->save();
                    $kept_ids[] = $item_id;
                }
            } else {
                $new_item = Loadout_Tier_Item::create($tier_id, $data);
                if ($new_item) {
                    $kept_ids[] = $new_item->get_id();
                }
            }
        }

        // Delete items no longer in the form.
        foreach ($existing_ids as $eid) {
            if (!in_array($eid, $kept_ids, true)) {
                Loadout_Tier_Item::delete($eid);
            }
        }
    }

    private static function save_cross_sells(int $loadout_id): void
    {
        $cs_raw = isset($_POST['cross_sells']) ? (array) $_POST['cross_sells'] : [];
        $existing_cs = Loadout_Cross_Sell::get_by_loadout($loadout_id);
        $existing_ids = array_map(fn($c) => $c->get_id(), $existing_cs);
        $kept_ids = [];

        foreach ($cs_raw as $index => $cs_data) {
            $cs_id = isset($cs_data['id']) ? absint($cs_data['id']) : 0;

            $data = [
                'label'      => isset($cs_data['label']) ? sanitize_text_field(wp_unslash($cs_data['label'])) : '',
                'image_id'   => isset($cs_data['image_id']) ? absint($cs_data['image_id']) : null,
                'link_type'  => isset($cs_data['link_type']) ? sanitize_key($cs_data['link_type']) : 'category',
                'link_value' => isset($cs_data['link_value']) ? sanitize_text_field(wp_unslash($cs_data['link_value'])) : null,
                'sort_order' => $index,
            ];

            if (empty($data['label'])) {
                continue;
            }

            if ($cs_id && in_array($cs_id, $existing_ids, true)) {
                $cs = Loadout_Cross_Sell::get($cs_id);
                if ($cs) {
                    $cs->set_label($data['label']);
                    $cs->set_image_id($data['image_id']);
                    $cs->set_link_type($data['link_type']);
                    $cs->set_link_value($data['link_value']);
                    $cs->set_sort_order($data['sort_order']);
                    $cs->save();
                    $kept_ids[] = $cs_id;
                }
            } else {
                $new_cs = Loadout_Cross_Sell::create($loadout_id, $data);
                if ($new_cs) {
                    $kept_ids[] = $new_cs->get_id();
                }
            }
        }

        foreach ($existing_ids as $eid) {
            if (!in_array($eid, $kept_ids, true)) {
                Loadout_Cross_Sell::delete($eid);
            }
        }
    }

    public static function render_form($loadout = null): void
    {
        $is_edit = $loadout instanceof Loadout;
        $loadout_id = $is_edit ? $loadout->get_id() : 0;
        $name = $is_edit ? $loadout->get_name() : '';
        $status = $is_edit ? $loadout->get_status() : 1;
        $anchor_product_id = $is_edit ? $loadout->get_anchor_product_id() : 0;
        $hero_image_id = $is_edit ? $loadout->get_hero_image_id() : 0;
        $brand_logo_id = $is_edit ? $loadout->get_brand_logo_id() : 0;
        $headline = $is_edit ? $loadout->get_headline() : '';
        $subheadline = $is_edit ? $loadout->get_subheadline() : '';

        $tiers = $is_edit ? Loadout_Tier::get_by_loadout($loadout_id) : [];
        $cross_sells = $is_edit ? Loadout_Cross_Sell::get_by_loadout($loadout_id) : [];

        $anchor_name = '';
        if ($anchor_product_id) {
            $anchor_product = wc_get_product($anchor_product_id);
            if ($anchor_product) {
                $anchor_name = $anchor_product->get_name();
            }
        }

        ?>
        <div class="wrap loadout-form-wrap">
            <h1><?php echo $is_edit ? esc_html__('Edit Loadout', 'ffl-funnels-addons') : esc_html__('Add Loadout', 'ffl-funnels-addons'); ?></h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Loadout saved successfully.', 'ffl-funnels-addons'); ?></p>
                </div>
            <?php endif; ?>

            <details class="loadout-help-box" open>
                <summary><strong><?php esc_html_e('How Loadouts work', 'ffl-funnels-addons'); ?></strong></summary>
                <div class="loadout-help-content">
                    <p><?php esc_html_e('A Loadout is a tier-based product configurator. The customer picks one tier (e.g. Essential, Performance, Elite), sees a curated list of products, and can add them individually or all at once to their cart.', 'ffl-funnels-addons'); ?></p>
                    <ul>
                        <li><strong><?php esc_html_e('Tiers', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('Each tier is a separate package level with its own recommended products and discount rules.', 'ffl-funnels-addons'); ?></li>
                        <li><strong><?php esc_html_e('Accessory Discount %', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('Discount applied to every item the customer adds individually from this tier (via the standalone widget).', 'ffl-funnels-addons'); ?></li>
                        <li><strong><?php esc_html_e('Set Discount %', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('Extra discount on top, applied only when the customer adds the entire tier at once (via a product page tab).', 'ffl-funnels-addons'); ?></li>
                        <li><strong><?php esc_html_e('Perk Threshold + Perks + Bonus', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('Gamification — when the cart hits the threshold number of items from this tier, the perks list shows as unlocked and the bonus product is auto-added free. Remove items below threshold and the bonus is auto-removed.', 'ffl-funnels-addons'); ?></li>
                        <li><strong><?php esc_html_e('Anchor Product', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('The hero product (e.g. the rifle) shown prominently at the top of the widget. Customers add it manually like any other item.', 'ffl-funnels-addons'); ?></li>
                        <li><strong><?php esc_html_e('Cross-Sells', 'ffl-funnels-addons'); ?>:</strong> <?php esc_html_e('Category tiles shown below the tier panel (e.g. "Tactical Optics") that link out to related collections.', 'ffl-funnels-addons'); ?></li>
                    </ul>
                    <p><em><?php esc_html_e('Set everything to 0 / leave fields blank if you don\'t want that feature — only filled-in fields take effect.', 'ffl-funnels-addons'); ?></em></p>
                </div>
            </details>

            <form method="post" action="" id="loadout-form">
                <?php wp_nonce_field('loadout_save', 'loadout_nonce'); ?>
                <input type="hidden" name="loadout_id" value="<?php echo esc_attr($loadout_id); ?>">
                <input type="hidden" name="action" value="save_loadout">

                <h2 class="title"><?php esc_html_e('Basic Information', 'ffl-funnels-addons'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="loadout-name"><?php esc_html_e('Name', 'ffl-funnels-addons'); ?></label></th>
                        <td>
                            <input type="text" id="loadout-name" name="name" value="<?php echo esc_attr($name); ?>" class="regular-text" required>
                            <p class="description"><?php esc_html_e('Internal label only — never shown to customers. Use a recognizable name like "AR-15 Build" or "Daniel Defense V7 Kit".', 'ffl-funnels-addons'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="loadout-status"><?php esc_html_e('Status', 'ffl-funnels-addons'); ?></label></th>
                        <td>
                            <select id="loadout-status" name="status">
                                <option value="1" <?php selected($status, 1); ?>><?php esc_html_e('Active', 'ffl-funnels-addons'); ?></option>
                                <option value="0" <?php selected($status, 0); ?>><?php esc_html_e('Inactive', 'ffl-funnels-addons'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Inactive loadouts are hidden from the Bricks element selector and any product-page link.', 'ffl-funnels-addons'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="loadout-headline"><?php esc_html_e('Headline', 'ffl-funnels-addons'); ?></label></th>
                        <td>
                            <input type="text" id="loadout-headline" name="headline" value="<?php echo esc_attr($headline); ?>" class="regular-text" placeholder="<?php esc_attr_e('Build Your Kinetic Loadout', 'ffl-funnels-addons'); ?>">
                            <p class="description"><?php esc_html_e('Big title shown at the top of the widget. Leave blank to use the Name above.', 'ffl-funnels-addons'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="loadout-subheadline"><?php esc_html_e('Subheadline', 'ffl-funnels-addons'); ?></label></th>
                        <td>
                            <input type="text" id="loadout-subheadline" name="subheadline" value="<?php echo esc_attr($subheadline); ?>" class="regular-text" placeholder="<?php esc_attr_e('Bundle & Save', 'ffl-funnels-addons'); ?>">
                            <p class="description"><?php esc_html_e('Optional small text below the headline.', 'ffl-funnels-addons'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Branding', 'ffl-funnels-addons'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e('Hero Image', 'ffl-funnels-addons'); ?></th>
                        <td>
                            <?php self::render_image_picker('hero_image_id', $hero_image_id); ?>
                            <p class="description"><?php esc_html_e('Large image shown next to the anchor product (e.g. lifestyle photo of the rifle). If empty, the anchor product\'s own featured image is used.', 'ffl-funnels-addons'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Brand Logo', 'ffl-funnels-addons'); ?></th>
                        <td>
                            <?php self::render_image_picker('brand_logo_id', $brand_logo_id); ?>
                            <p class="description"><?php esc_html_e('Small logo shown at the very top of the widget (e.g. manufacturer logo). Optional.', 'ffl-funnels-addons'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Anchor Product', 'ffl-funnels-addons'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e('Hero Product', 'ffl-funnels-addons'); ?></label></th>
                        <td>
                            <input type="hidden" id="anchor-product-id" name="anchor_product_id" value="<?php echo esc_attr($anchor_product_id); ?>">
                            <input type="text" id="anchor-product-search" class="regular-text loadout-product-search" data-target="#anchor-product-id" data-display="#anchor-product-display" placeholder="<?php esc_attr_e('Search products...', 'ffl-funnels-addons'); ?>">
                            <div id="anchor-product-display" class="loadout-product-display">
                                <?php if ($anchor_name): ?>
                                    <span><?php echo esc_html($anchor_name); ?> (#<?php echo esc_html($anchor_product_id); ?>)</span>
                                    <button type="button" class="button-link loadout-product-remove" data-target="#anchor-product-id" data-display="#anchor-product-display"><?php esc_html_e('Remove', 'ffl-funnels-addons'); ?></button>
                                <?php endif; ?>
                            </div>
                            <div class="loadout-search-results"></div>
                            <p class="description"><?php esc_html_e('The hero product (e.g. the rifle) shown prominently at the top of the widget. Customers add it manually like any tier item.', 'ffl-funnels-addons'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2 class="title"><?php esc_html_e('Tiers', 'ffl-funnels-addons'); ?></h2>
                <p class="description"><?php esc_html_e('Add tiers like Essential, Performance, Elite. Each tier contains recommended products with optional discounts and perks.', 'ffl-funnels-addons'); ?></p>
                <div id="loadout-tiers" class="loadout-repeater">
                    <?php foreach ($tiers as $tier_index => $tier): ?>
                        <?php self::render_tier_row($tier_index, $tier); ?>
                    <?php endforeach; ?>
                </div>
                <p><button type="button" class="button" id="add-tier"><?php esc_html_e('+ Add Tier', 'ffl-funnels-addons'); ?></button></p>

                <h2 class="title"><?php esc_html_e('Cross-Sells', 'ffl-funnels-addons'); ?></h2>
                <p class="description"><?php esc_html_e('Category tiles shown at the bottom of the widget (e.g., "Tactical Optics", "Slings & Grips").', 'ffl-funnels-addons'); ?></p>
                <div id="loadout-cross-sells" class="loadout-repeater">
                    <?php foreach ($cross_sells as $cs_index => $cs): ?>
                        <?php self::render_cross_sell_row($cs_index, $cs); ?>
                    <?php endforeach; ?>
                </div>
                <p><button type="button" class="button" id="add-cross-sell"><?php esc_html_e('+ Add Cross-Sell Tile', 'ffl-funnels-addons'); ?></button></p>

                <p class="submit">
                    <button type="submit" class="button button-primary"><?php echo $is_edit ? esc_html__('Update Loadout', 'ffl-funnels-addons') : esc_html__('Create Loadout', 'ffl-funnels-addons'); ?></button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ffla-loadouts')); ?>" class="button"><?php esc_html_e('Cancel', 'ffl-funnels-addons'); ?></a>
                </p>
            </form>

            <?php self::render_tier_template(); ?>
            <?php self::render_item_template(); ?>
            <?php self::render_cross_sell_template(); ?>
        </div>
        <?php
    }

    private static function render_image_picker(string $field_name, $image_id): void
    {
        $thumb_url = '';
        if ($image_id) {
            $thumb = wp_get_attachment_image_src($image_id, 'thumbnail');
            $thumb_url = $thumb ? $thumb[0] : '';
        }
        ?>
        <div class="loadout-image-picker" data-field="<?php echo esc_attr($field_name); ?>">
            <input type="hidden" name="<?php echo esc_attr($field_name); ?>" value="<?php echo esc_attr($image_id); ?>">
            <img src="<?php echo esc_url($thumb_url); ?>" class="loadout-image-preview" style="<?php echo $thumb_url ? 'display:inline-block;' : 'display:none;'; ?>max-width:80px;border:1px solid #ddd;margin-right:8px;vertical-align:middle;">
            <button type="button" class="button loadout-image-select"><?php esc_html_e('Select Image', 'ffl-funnels-addons'); ?></button>
            <button type="button" class="button loadout-image-remove" style="<?php echo $thumb_url ? '' : 'display:none;'; ?>"><?php esc_html_e('Remove', 'ffl-funnels-addons'); ?></button>
        </div>
        <?php
    }

    private static function render_tier_row(int $index, ?Loadout_Tier $tier = null): void
    {
        $id = $tier ? $tier->get_id() : '';
        $name = $tier ? $tier->get_name() : '';
        $slug = $tier ? $tier->get_slug() : '';
        $accessory_discount = $tier ? $tier->get_accessory_discount() : 0;
        $set_discount = $tier ? $tier->get_set_discount_pct() : 0;
        $threshold = $tier ? $tier->get_threshold_items() : 0;
        $perks = $tier ? implode("\n", $tier->get_perks()) : '';
        $bonus_product_id = $tier ? $tier->get_bonus_product_id() : 0;
        $bonus_label = $tier ? $tier->get_bonus_label() : '';
        $bonus_display_value = $tier ? $tier->get_bonus_display_value() : '';
        $items = $tier ? $tier->get_items() : [];

        $bonus_name = '';
        if ($bonus_product_id) {
            $bp = wc_get_product($bonus_product_id);
            if ($bp) {
                $bonus_name = $bp->get_name();
            }
        }
        ?>
        <div class="loadout-tier-row" data-index="<?php echo esc_attr($index); ?>">
            <div class="loadout-tier-header">
                <h3 class="loadout-tier-name"><?php echo esc_html($name ?: __('New Tier', 'ffl-funnels-addons')); ?></h3>
                <button type="button" class="button-link loadout-tier-remove" style="color:#c00;"><?php esc_html_e('Remove Tier', 'ffl-funnels-addons'); ?></button>
            </div>
            <input type="hidden" name="tiers[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($id); ?>">

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Tier Name', 'ffl-funnels-addons'); ?></th>
                    <td>
                        <input type="text" name="tiers[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($name); ?>" class="regular-text loadout-tier-name-input" placeholder="<?php esc_attr_e('Essential / Performance / Elite', 'ffl-funnels-addons'); ?>" required>
                        <p class="description"><?php esc_html_e('Tab label shown to the customer (e.g. "Essential", "Performance", "Elite").', 'ffl-funnels-addons'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Accessory Discount %', 'ffl-funnels-addons'); ?></th>
                    <td>
                        <input type="number" name="tiers[<?php echo esc_attr($index); ?>][accessory_discount]" value="<?php echo esc_attr($accessory_discount); ?>" min="0" max="100" step="0.01" class="small-text">
                        <p class="description"><?php esc_html_e('Discount applied to each item the customer adds individually from this tier (via the standalone widget). Stacks on top of any per-item discount below. Use 0 for no tier-wide discount.', 'ffl-funnels-addons'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Set Discount %', 'ffl-funnels-addons'); ?></th>
                    <td>
                        <input type="number" name="tiers[<?php echo esc_attr($index); ?>][set_discount_pct]" value="<?php echo esc_attr($set_discount); ?>" min="0" max="100" step="0.01" class="small-text">
                        <p class="description"><?php esc_html_e('Bonus discount applied only when the customer adds the entire tier together (via a product page Loadout tab). Reverts automatically if any item is removed.', 'ffl-funnels-addons'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Perk Threshold', 'ffl-funnels-addons'); ?></th>
                    <td>
                        <input type="number" name="tiers[<?php echo esc_attr($index); ?>][threshold_items]" value="<?php echo esc_attr($threshold); ?>" min="0" class="small-text">
                        <p class="description"><?php esc_html_e('Minimum number of items from this tier the customer must add before the perks list shows as "unlocked" and the bonus product (below) auto-adds free. Set to 0 to disable the gamification — perks/bonus then always apply.', 'ffl-funnels-addons'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Perks', 'ffl-funnels-addons'); ?></th>
                    <td>
                        <textarea name="tiers[<?php echo esc_attr($index); ?>][perks]" rows="4" class="large-text" placeholder="<?php esc_attr_e('One perk per line, e.g.:&#10;10% OFF accessories&#10;Priority Order Processing&#10;Free Upgraded Shipping', 'ffl-funnels-addons'); ?>"><?php echo esc_textarea($perks); ?></textarea>
                        <p class="description"><?php esc_html_e('Cosmetic benefits displayed when the threshold is met. One per line. These are display-only — they don\'t change cart pricing on their own.', 'ffl-funnels-addons'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Bonus Product (Free Gift)', 'ffl-funnels-addons'); ?></th>
                    <td>
                        <input type="hidden" class="loadout-bonus-id" name="tiers[<?php echo esc_attr($index); ?>][bonus_product_id]" value="<?php echo esc_attr($bonus_product_id); ?>">
                        <input type="text" class="regular-text loadout-product-search" data-target=".loadout-bonus-id" data-display=".loadout-bonus-display" data-scope="row" placeholder="<?php esc_attr_e('Search products...', 'ffl-funnels-addons'); ?>">
                        <div class="loadout-bonus-display loadout-product-display">
                            <?php if ($bonus_name): ?>
                                <span><?php echo esc_html($bonus_name); ?> (#<?php echo esc_html($bonus_product_id); ?>)</span>
                                <button type="button" class="button-link loadout-product-remove" data-target=".loadout-bonus-id" data-display=".loadout-bonus-display" data-scope="row"><?php esc_html_e('Remove', 'ffl-funnels-addons'); ?></button>
                            <?php endif; ?>
                        </div>
                        <div class="loadout-search-results"></div>
                        <p class="description"><?php esc_html_e('Free gift auto-added to the cart at $0 when the Perk Threshold is hit. Auto-removed if items drop below threshold. Leave empty if you don\'t want a free gift.', 'ffl-funnels-addons'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Bonus Label', 'ffl-funnels-addons'); ?></th>
                    <td>
                        <input type="text" name="tiers[<?php echo esc_attr($index); ?>][bonus_label]" value="<?php echo esc_attr($bonus_label); ?>" class="regular-text" placeholder="<?php esc_attr_e('FREE Kinetic Armory', 'ffl-funnels-addons'); ?>">
                        <p class="description"><?php esc_html_e('Custom display text for the bonus item (defaults to the product\'s own name if empty).', 'ffl-funnels-addons'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Bonus Display Value', 'ffl-funnels-addons'); ?></th>
                    <td>
                        <input type="number" name="tiers[<?php echo esc_attr($index); ?>][bonus_display_value]" value="<?php echo esc_attr($bonus_display_value); ?>" min="0" step="0.01" class="small-text">
                        <p class="description"><?php esc_html_e('Cosmetic "valued at $X" text shown next to the bonus (e.g. "FREE Kinetic Armory — valued at $30"). Customer is still charged $0 — this is just display.', 'ffl-funnels-addons'); ?></p>
                    </td>
                </tr>
            </table>

            <h4><?php esc_html_e('Tier Items', 'ffl-funnels-addons'); ?></h4>
            <div class="loadout-tier-items">
                <?php foreach ($items as $item_index => $item): ?>
                    <?php self::render_item_row($index, $item_index, $item); ?>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="button loadout-add-item" data-tier-index="<?php echo esc_attr($index); ?>"><?php esc_html_e('+ Add Item', 'ffl-funnels-addons'); ?></button></p>
        </div>
        <?php
    }

    private static function render_item_row(int $tier_index, int $item_index, ?Loadout_Tier_Item $item = null): void
    {
        $id = $item ? $item->get_id() : '';
        $product_id = $item ? $item->get_product_id() : 0;
        $quantity = $item ? $item->get_quantity() : 1;
        $discount_pct = $item ? $item->get_discount_pct() : 0;
        $is_required = $item ? $item->get_is_required() : 0;

        $product_name = '';
        $price_html = '';
        $stock_html = '';
        if ($product_id) {
            $p = wc_get_product($product_id);
            if ($p) {
                $product_name = $p->get_name();
                $price_html = $p->get_price_html();
                $stock_html = class_exists('Loadout_Ajax') ? Loadout_Ajax::format_stock_html($p) : '';
            }
        }
        ?>
        <div class="loadout-item-row">
            <input type="hidden" name="tiers[<?php echo esc_attr($tier_index); ?>][items][<?php echo esc_attr($item_index); ?>][id]" value="<?php echo esc_attr($id); ?>">
            <input type="hidden" class="loadout-item-product-id" name="tiers[<?php echo esc_attr($tier_index); ?>][items][<?php echo esc_attr($item_index); ?>][product_id]" value="<?php echo esc_attr($product_id); ?>">

            <div class="loadout-item-fields">
                <input type="text" class="regular-text loadout-product-search" data-target=".loadout-item-product-id" data-display=".loadout-item-product-display" data-scope="row" placeholder="<?php esc_attr_e('Search products...', 'ffl-funnels-addons'); ?>">
                <div class="loadout-item-product-display loadout-product-display">
                    <?php if ($product_name): ?>
                        <span class="loadout-product-name"><?php echo esc_html($product_name); ?> (#<?php echo esc_html($product_id); ?>)</span>
                        <span class="loadout-product-price"><?php echo wp_kses_post($price_html); ?></span>
                        <?php echo wp_kses_post($stock_html); ?>
                    <?php endif; ?>
                </div>
                <div class="loadout-search-results"></div>

                <label><?php esc_html_e('Qty:', 'ffl-funnels-addons'); ?>
                    <input type="number" name="tiers[<?php echo esc_attr($tier_index); ?>][items][<?php echo esc_attr($item_index); ?>][quantity]" value="<?php echo esc_attr($quantity); ?>" min="1" class="small-text">
                </label>
                <label><?php esc_html_e('Discount %:', 'ffl-funnels-addons'); ?>
                    <input type="number" name="tiers[<?php echo esc_attr($tier_index); ?>][items][<?php echo esc_attr($item_index); ?>][discount_pct]" value="<?php echo esc_attr($discount_pct); ?>" min="0" max="100" step="0.01" class="small-text">
                </label>
                <label>
                    <input type="checkbox" name="tiers[<?php echo esc_attr($tier_index); ?>][items][<?php echo esc_attr($item_index); ?>][is_required]" value="1" <?php checked($is_required, 1); ?>>
                    <?php esc_html_e('Pre-checked', 'ffl-funnels-addons'); ?>
                </label>
                <button type="button" class="button-link loadout-item-remove" style="color:#c00;"><?php esc_html_e('Remove', 'ffl-funnels-addons'); ?></button>
            </div>
        </div>
        <?php
    }

    private static function render_cross_sell_row(int $index, ?Loadout_Cross_Sell $cs = null): void
    {
        $id = $cs ? $cs->get_id() : '';
        $label = $cs ? $cs->get_label() : '';
        $image_id = $cs ? $cs->get_image_id() : 0;
        $link_type = $cs ? $cs->get_link_type() : 'category';
        $link_value = $cs ? $cs->get_link_value() : '';

        $thumb_url = '';
        if ($image_id) {
            $thumb = wp_get_attachment_image_src($image_id, 'thumbnail');
            $thumb_url = $thumb ? $thumb[0] : '';
        }
        ?>
        <div class="loadout-cross-sell-row">
            <input type="hidden" name="cross_sells[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($id); ?>">
            <div class="loadout-cs-fields">
                <input type="text" name="cross_sells[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('Tactical Optics', 'ffl-funnels-addons'); ?>" class="regular-text">

                <div class="loadout-image-picker" data-field="cross_sells[<?php echo esc_attr($index); ?>][image_id]">
                    <input type="hidden" name="cross_sells[<?php echo esc_attr($index); ?>][image_id]" value="<?php echo esc_attr($image_id); ?>">
                    <img src="<?php echo esc_url($thumb_url); ?>" class="loadout-image-preview" style="<?php echo $thumb_url ? 'display:inline-block;' : 'display:none;'; ?>max-width:60px;border:1px solid #ddd;margin-right:8px;vertical-align:middle;">
                    <button type="button" class="button loadout-image-select"><?php esc_html_e('Image', 'ffl-funnels-addons'); ?></button>
                    <button type="button" class="button loadout-image-remove" style="<?php echo $thumb_url ? '' : 'display:none;'; ?>"><?php esc_html_e('×', 'ffl-funnels-addons'); ?></button>
                </div>

                <select name="cross_sells[<?php echo esc_attr($index); ?>][link_type]">
                    <option value="category" <?php selected($link_type, 'category'); ?>><?php esc_html_e('Category', 'ffl-funnels-addons'); ?></option>
                    <option value="url" <?php selected($link_type, 'url'); ?>><?php esc_html_e('URL', 'ffl-funnels-addons'); ?></option>
                    <option value="loadout" <?php selected($link_type, 'loadout'); ?>><?php esc_html_e('Loadout', 'ffl-funnels-addons'); ?></option>
                </select>
                <input type="text" name="cross_sells[<?php echo esc_attr($index); ?>][link_value]" value="<?php echo esc_attr($link_value); ?>" placeholder="<?php esc_attr_e('Slug, URL, or ID', 'ffl-funnels-addons'); ?>" class="regular-text">
                <button type="button" class="button-link loadout-cs-remove" style="color:#c00;"><?php esc_html_e('Remove', 'ffl-funnels-addons'); ?></button>
            </div>
        </div>
        <?php
    }

    private static function render_tier_template(): void
    {
        ?>
        <script type="text/html" id="tmpl-loadout-tier">
            <?php self::render_tier_row(0); ?>
        </script>
        <?php
    }

    private static function render_item_template(): void
    {
        ?>
        <script type="text/html" id="tmpl-loadout-item">
            <?php self::render_item_row(0, 0); ?>
        </script>
        <?php
    }

    private static function render_cross_sell_template(): void
    {
        ?>
        <script type="text/html" id="tmpl-loadout-cross-sell">
            <?php self::render_cross_sell_row(0); ?>
        </script>
        <?php
    }
}
