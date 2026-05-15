<?php
/**
 * Loadout Bricks Builder Integration.
 *
 * Registers custom Query Loop types and Dynamic Data tags for building
 * fully custom Loadout layouts in Bricks Builder.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Bricks
{
    const QUERY_LOADOUTS    = 'loadout_loadouts';
    const QUERY_TIERS       = 'loadout_tiers';
    const QUERY_ITEMS       = 'loadout_tier_items';
    const QUERY_CROSS_SELLS = 'loadout_cross_sells';

    public function init(): void
    {
        // Query types.
        add_filter('bricks/setup/control_options', [$this, 'register_query_types']);
        add_filter('bricks/query/run', [$this, 'run_query'], 10, 2);
        add_filter('bricks/query/loop_object', [$this, 'set_loop_object'], 10, 3);
        add_filter('bricks/query/loop_object_id', [$this, 'set_loop_object_id'], 10, 3);
        add_action('bricks/query/after_loop', [$this, 'after_loop'], 10, 1);

        // Container/Block/Div controls so settings appear when one of our query types is selected.
        foreach (['container', 'block', 'div'] as $element) {
            add_filter("bricks/elements/{$element}/controls", [$this, 'add_element_controls'], 20);
        }

        // Dynamic data tags.
        add_filter('bricks/dynamic_tags_list', [$this, 'register_dynamic_tags']);
        add_filter('bricks/dynamic_data/render_tag', [$this, 'render_dynamic_tag'], 20, 3);
        add_filter('bricks/dynamic_data/render_content', [$this, 'render_dynamic_content'], 20, 3);
        add_filter('bricks/frontend/render_data', [$this, 'render_dynamic_content'], 20, 2);
    }

    public function register_query_types($control_options)
    {
        $control_options['queryTypes'][self::QUERY_LOADOUTS]    = esc_html__('Loadouts', 'ffl-funnels-addons');
        $control_options['queryTypes'][self::QUERY_TIERS]       = esc_html__('Loadout Tiers', 'ffl-funnels-addons');
        $control_options['queryTypes'][self::QUERY_ITEMS]       = esc_html__('Loadout Tier Items', 'ffl-funnels-addons');
        $control_options['queryTypes'][self::QUERY_CROSS_SELLS] = esc_html__('Loadout Cross-Sells', 'ffl-funnels-addons');
        return $control_options;
    }

    public function add_element_controls($controls)
    {
        $loadout_options = ['' => esc_html__('— Select Loadout —', 'ffl-funnels-addons')];
        $loadouts = Loadout::get_all(['status' => 1]);
        foreach ($loadouts as $l) {
            $loadout_options[$l->get_id()] = $l->get_name();
        }

        $controls['loadout_settings_group'] = [
            'tab' => 'content',
            'type' => 'separator',
            'label' => esc_html__('Loadout Settings', 'ffl-funnels-addons'),
            'required' => ['query.objectType', '=', [self::QUERY_LOADOUTS, self::QUERY_TIERS, self::QUERY_ITEMS, self::QUERY_CROSS_SELLS]],
        ];

        // Loadout selector — used by Tiers, Items, Cross-Sells.
        $controls['loadout_target_id'] = [
            'tab' => 'content',
            'label' => esc_html__('Loadout', 'ffl-funnels-addons'),
            'type' => 'select',
            'options' => $loadout_options,
            'description' => esc_html__('Source loadout for the loop. Leave empty to inherit from a parent loop.', 'ffl-funnels-addons'),
            'required' => ['query.objectType', '=', [self::QUERY_TIERS, self::QUERY_ITEMS, self::QUERY_CROSS_SELLS]],
        ];

        // Tier selector — for Items query (alternative to inheriting from parent loop).
        $controls['loadout_target_tier_id'] = [
            'tab' => 'content',
            'label' => esc_html__('Specific Tier (optional)', 'ffl-funnels-addons'),
            'type' => 'number',
            'description' => esc_html__('Tier ID. If empty, items inherit from the current parent Tiers loop.', 'ffl-funnels-addons'),
            'required' => ['query.objectType', '=', self::QUERY_ITEMS],
        ];

        // Active tier only filter for Items.
        $controls['loadout_only_active'] = [
            'tab' => 'content',
            'label' => esc_html__('Active Tier Only', 'ffl-funnels-addons'),
            'type' => 'checkbox',
            'description' => esc_html__('Only render items for the currently active tier (frontend JS toggles visibility).', 'ffl-funnels-addons'),
            'required' => ['query.objectType', '=', self::QUERY_ITEMS],
        ];

        return $controls;
    }

    public function run_query($results, $query_obj)
    {
        if (!in_array($query_obj->object_type, [self::QUERY_LOADOUTS, self::QUERY_TIERS, self::QUERY_ITEMS, self::QUERY_CROSS_SELLS], true)) {
            return $results;
        }

        $settings = $query_obj->settings ?? [];

        switch ($query_obj->object_type) {
            case self::QUERY_LOADOUTS:
                return Loadout::get_all(['status' => 1]);

            case self::QUERY_TIERS:
                $loadout_id = absint($settings['loadout_target_id'] ?? 0);
                if (!$loadout_id) {
                    $loadout_id = self::get_inherited_loadout_id();
                }
                return $loadout_id ? Loadout_Tier::get_by_loadout($loadout_id) : [];

            case self::QUERY_ITEMS:
                $tier_id = absint($settings['loadout_target_tier_id'] ?? 0);
                if (!$tier_id) {
                    $tier_id = self::get_inherited_tier_id();
                }
                return $tier_id ? Loadout_Tier_Item::get_by_tier($tier_id) : [];

            case self::QUERY_CROSS_SELLS:
                $loadout_id = absint($settings['loadout_target_id'] ?? 0);
                if (!$loadout_id) {
                    $loadout_id = self::get_inherited_loadout_id();
                }
                return $loadout_id ? Loadout_Cross_Sell::get_by_loadout($loadout_id) : [];
        }

        return $results;
    }

    public function set_loop_object($loop_object, $loop_key, $query_obj)
    {
        if (!in_array($query_obj->object_type, [self::QUERY_LOADOUTS, self::QUERY_TIERS, self::QUERY_ITEMS, self::QUERY_CROSS_SELLS], true)) {
            return $loop_object;
        }

        // Store on global so nested loops and dynamic tags can access.
        global $ffla_loadout_loop_stack;
        if (!is_array($ffla_loadout_loop_stack)) {
            $ffla_loadout_loop_stack = [];
        }
        $ffla_loadout_loop_stack[$query_obj->object_type] = $loop_object;

        return $loop_object;
    }

    public function set_loop_object_id($loop_id, $loop_key, $query_obj)
    {
        if (!in_array($query_obj->object_type, [self::QUERY_LOADOUTS, self::QUERY_TIERS, self::QUERY_ITEMS, self::QUERY_CROSS_SELLS], true)) {
            return $loop_id;
        }

        $obj = self::current_loop_object($query_obj->object_type);
        if (!$obj) {
            return $loop_id;
        }

        if (method_exists($obj, 'get_id')) {
            return $obj->get_id();
        }

        return $loop_id;
    }

    public function after_loop($query_obj): void
    {
        if (!in_array($query_obj->object_type, [self::QUERY_LOADOUTS, self::QUERY_TIERS, self::QUERY_ITEMS, self::QUERY_CROSS_SELLS], true)) {
            return;
        }

        global $ffla_loadout_loop_stack;
        if (is_array($ffla_loadout_loop_stack)) {
            unset($ffla_loadout_loop_stack[$query_obj->object_type]);
        }
    }

    public static function current_loop_object(string $type)
    {
        global $ffla_loadout_loop_stack;
        if (!is_array($ffla_loadout_loop_stack)) {
            return null;
        }
        return $ffla_loadout_loop_stack[$type] ?? null;
    }

    public static function get_inherited_loadout_id(): int
    {
        // Try parent Loadout loop first.
        $loadout = self::current_loop_object(self::QUERY_LOADOUTS);
        if ($loadout instanceof Loadout) {
            return $loadout->get_id();
        }
        // Then parent Tier loop's loadout_id.
        $tier = self::current_loop_object(self::QUERY_TIERS);
        if ($tier instanceof Loadout_Tier) {
            return $tier->get_loadout_id();
        }
        return 0;
    }

    public static function get_inherited_tier_id(): int
    {
        $tier = self::current_loop_object(self::QUERY_TIERS);
        if ($tier instanceof Loadout_Tier) {
            return $tier->get_id();
        }
        return 0;
    }

    /**
     * Dynamic Data Tags.
     */
    public function register_dynamic_tags($tags)
    {
        $defs = self::get_tag_definitions();
        foreach ($defs as $name => $def) {
            $tags[] = [
                'name'  => '{' . $name . '}',
                'label' => $def['label'],
                'group' => $def['group'],
            ];
        }
        return $tags;
    }

    public function render_dynamic_tag($tag, $post, $context = 'text')
    {
        $name = trim($tag, '{}');
        $defs = self::get_tag_definitions();
        if (!isset($defs[$name])) {
            return $tag;
        }
        $value = self::resolve_tag($name);
        return $value !== null ? $value : '';
    }

    public function render_dynamic_content($content, $post = null, $context = 'text')
    {
        if (!is_string($content) || strpos($content, '{loadout_') === false) {
            return $content;
        }

        $defs = self::get_tag_definitions();
        foreach (array_keys($defs) as $name) {
            $token = '{' . $name . '}';
            if (strpos($content, $token) !== false) {
                $value = self::resolve_tag($name);
                $content = str_replace($token, $value !== null ? $value : '', $content);
            }
        }
        return $content;
    }

    private static function get_tag_definitions(): array
    {
        $group_loadout = esc_html__('Loadout', 'ffl-funnels-addons');
        $group_tier = esc_html__('Loadout: Tier', 'ffl-funnels-addons');
        $group_item = esc_html__('Loadout: Item', 'ffl-funnels-addons');
        $group_cs = esc_html__('Loadout: Cross-Sell', 'ffl-funnels-addons');

        return [
            // Loadout-level.
            'loadout_id'           => ['label' => 'Loadout ID', 'group' => $group_loadout],
            'loadout_name'         => ['label' => 'Loadout Name', 'group' => $group_loadout],
            'loadout_slug'         => ['label' => 'Loadout Slug', 'group' => $group_loadout],
            'loadout_headline'     => ['label' => 'Headline', 'group' => $group_loadout],
            'loadout_subheadline'  => ['label' => 'Subheadline', 'group' => $group_loadout],
            'loadout_hero_image'   => ['label' => 'Hero Image URL', 'group' => $group_loadout],
            'loadout_brand_logo'   => ['label' => 'Brand Logo URL', 'group' => $group_loadout],
            'loadout_anchor_id'    => ['label' => 'Anchor Product ID', 'group' => $group_loadout],
            'loadout_anchor_name'  => ['label' => 'Anchor Product Name', 'group' => $group_loadout],
            'loadout_anchor_price' => ['label' => 'Anchor Product Price', 'group' => $group_loadout],
            // Tier-level.
            'loadout_tier_id'                  => ['label' => 'Tier ID', 'group' => $group_tier],
            'loadout_tier_name'                => ['label' => 'Tier Name', 'group' => $group_tier],
            'loadout_tier_slug'                => ['label' => 'Tier Slug', 'group' => $group_tier],
            'loadout_tier_accessory_discount'  => ['label' => 'Accessory Discount %', 'group' => $group_tier],
            'loadout_tier_set_discount'        => ['label' => 'Set Discount %', 'group' => $group_tier],
            'loadout_tier_threshold'           => ['label' => 'Perk Threshold', 'group' => $group_tier],
            'loadout_tier_bonus_label'         => ['label' => 'Bonus Label', 'group' => $group_tier],
            'loadout_tier_bonus_value'         => ['label' => 'Bonus Value', 'group' => $group_tier],
            'loadout_tier_perks'               => ['label' => 'Perks (HTML list)', 'group' => $group_tier],
            // Item-level.
            'loadout_item_id'              => ['label' => 'Item ID', 'group' => $group_item],
            'loadout_item_product_id'      => ['label' => 'Product ID', 'group' => $group_item],
            'loadout_item_product_name'    => ['label' => 'Product Name', 'group' => $group_item],
            'loadout_item_product_image'   => ['label' => 'Product Image HTML', 'group' => $group_item],
            'loadout_item_product_thumb'   => ['label' => 'Product Thumbnail URL', 'group' => $group_item],
            'loadout_item_quantity'        => ['label' => 'Quantity', 'group' => $group_item],
            'loadout_item_discount'        => ['label' => 'Discount %', 'group' => $group_item],
            'loadout_item_regular_price'   => ['label' => 'Regular Price', 'group' => $group_item],
            'loadout_item_final_price'     => ['label' => 'Final Price (after discount)', 'group' => $group_item],
            'loadout_item_savings'         => ['label' => 'Savings (amount)', 'group' => $group_item],
            'loadout_item_in_stock'        => ['label' => 'In Stock (1/0)', 'group' => $group_item],
            // Cross-sell.
            'loadout_cross_sell_label' => ['label' => 'Tile Label', 'group' => $group_cs],
            'loadout_cross_sell_image' => ['label' => 'Tile Image HTML', 'group' => $group_cs],
            'loadout_cross_sell_link'  => ['label' => 'Tile Link URL', 'group' => $group_cs],
        ];
    }

    private static function resolve_tag(string $name): ?string
    {
        $loadout = self::current_loop_object(self::QUERY_LOADOUTS);
        $tier    = self::current_loop_object(self::QUERY_TIERS);
        $item    = self::current_loop_object(self::QUERY_ITEMS);
        $cs      = self::current_loop_object(self::QUERY_CROSS_SELLS);

        // Loadout-level tags fall back to tier's parent loadout.
        if (strpos($name, 'loadout_anchor') === 0 || in_array($name, ['loadout_id', 'loadout_name', 'loadout_slug', 'loadout_headline', 'loadout_subheadline', 'loadout_hero_image', 'loadout_brand_logo'], true)) {
            if (!$loadout && $tier instanceof Loadout_Tier) {
                $loadout = Loadout::get($tier->get_loadout_id());
            }
        }

        switch ($name) {
            case 'loadout_id':           return $loadout ? (string) $loadout->get_id() : null;
            case 'loadout_name':         return $loadout ? $loadout->get_name() : null;
            case 'loadout_slug':         return $loadout ? $loadout->get_slug() : null;
            case 'loadout_headline':     return $loadout ? $loadout->get_headline() : null;
            case 'loadout_subheadline':  return $loadout ? $loadout->get_subheadline() : null;
            case 'loadout_hero_image':   return $loadout && $loadout->get_hero_image_id() ? wp_get_attachment_image_url($loadout->get_hero_image_id(), 'full') : '';
            case 'loadout_brand_logo':   return $loadout && $loadout->get_brand_logo_id() ? wp_get_attachment_image_url($loadout->get_brand_logo_id(), 'medium') : '';
            case 'loadout_anchor_id':    return $loadout ? (string) ($loadout->get_anchor_product_id() ?? '') : null;
            case 'loadout_anchor_name':
                if ($loadout && $loadout->get_anchor_product_id()) {
                    $p = wc_get_product($loadout->get_anchor_product_id());
                    return $p ? $p->get_name() : '';
                }
                return '';
            case 'loadout_anchor_price':
                if ($loadout && $loadout->get_anchor_product_id()) {
                    $p = wc_get_product($loadout->get_anchor_product_id());
                    return $p ? $p->get_price_html() : '';
                }
                return '';

            case 'loadout_tier_id':                 return $tier ? (string) $tier->get_id() : null;
            case 'loadout_tier_name':               return $tier ? $tier->get_name() : null;
            case 'loadout_tier_slug':               return $tier ? $tier->get_slug() : null;
            case 'loadout_tier_accessory_discount': return $tier ? (string) $tier->get_accessory_discount() : null;
            case 'loadout_tier_set_discount':       return $tier ? (string) $tier->get_set_discount_pct() : null;
            case 'loadout_tier_threshold':          return $tier ? (string) $tier->get_threshold_items() : null;
            case 'loadout_tier_bonus_label':        return $tier ? ($tier->get_bonus_label() ?? '') : null;
            case 'loadout_tier_bonus_value':        return $tier && $tier->get_bonus_display_value() ? wc_price($tier->get_bonus_display_value()) : '';
            case 'loadout_tier_perks':
                if (!$tier) return null;
                $perks = $tier->get_perks();
                if (empty($perks)) return '';
                $html = '<ul class="ffla-loadout__perks-list">';
                foreach ($perks as $p) {
                    $html .= '<li>' . esc_html($p) . '</li>';
                }
                return $html . '</ul>';

            case 'loadout_item_id':           return $item ? (string) $item->get_id() : null;
            case 'loadout_item_product_id':   return $item ? (string) $item->get_product_id() : null;
            case 'loadout_item_quantity':     return $item ? (string) $item->get_quantity() : null;
            case 'loadout_item_discount':     return $item ? (string) ($item->get_discount_pct() + ($tier ? $tier->get_accessory_discount() : 0)) : null;
            case 'loadout_item_product_name':
                if (!$item) return null;
                $p = wc_get_product($item->get_product_id());
                return $p ? $p->get_name() : '';
            case 'loadout_item_product_image':
                if (!$item) return null;
                $p = wc_get_product($item->get_product_id());
                return $p ? $p->get_image('thumbnail') : '';
            case 'loadout_item_product_thumb':
                if (!$item) return null;
                $p = wc_get_product($item->get_product_id());
                if (!$p) return '';
                $img = wp_get_attachment_image_url($p->get_image_id(), 'thumbnail');
                return $img ?: '';
            case 'loadout_item_regular_price':
                if (!$item) return null;
                $p = wc_get_product($item->get_product_id());
                if (!$p) return '';
                $reg = (float) $p->get_regular_price();
                if ($reg <= 0) $reg = (float) $p->get_price();
                return wc_price($reg);
            case 'loadout_item_final_price':
                if (!$item) return null;
                $p = wc_get_product($item->get_product_id());
                if (!$p) return '';
                $reg = (float) $p->get_regular_price();
                if ($reg <= 0) $reg = (float) $p->get_price();
                $disc = $item->get_discount_pct() + ($tier ? $tier->get_accessory_discount() : 0);
                $disc = min(100, $disc);
                $final = $disc > 0 ? $reg * (1 - $disc / 100) : $reg;
                return wc_price($final);
            case 'loadout_item_savings':
                if (!$item) return null;
                $p = wc_get_product($item->get_product_id());
                if (!$p) return '';
                $reg = (float) $p->get_regular_price();
                if ($reg <= 0) $reg = (float) $p->get_price();
                $disc = $item->get_discount_pct() + ($tier ? $tier->get_accessory_discount() : 0);
                $savings = $disc > 0 ? $reg * ($disc / 100) : 0;
                return wc_price($savings);
            case 'loadout_item_in_stock':
                if (!$item) return null;
                $p = wc_get_product($item->get_product_id());
                return $p && $p->is_in_stock() ? '1' : '0';

            case 'loadout_cross_sell_label': return $cs ? $cs->get_label() : null;
            case 'loadout_cross_sell_image':
                if (!$cs || !$cs->get_image_id()) return '';
                return wp_get_attachment_image($cs->get_image_id(), 'medium');
            case 'loadout_cross_sell_link':
                return $cs ? self::resolve_cs_link($cs) : null;
        }

        return null;
    }

    private static function resolve_cs_link($cs): string
    {
        $type = $cs->get_link_type();
        $value = $cs->get_link_value();
        if (!$value) return '#';
        switch ($type) {
            case 'category':
                $term = get_term_by('slug', $value, 'product_cat');
                return $term ? get_term_link($term) : '#';
            case 'url':
                return esc_url_raw($value);
            case 'loadout':
                $l = Loadout::get_by_slug($value);
                return $l ? '#loadout-' . $l->get_id() : '#';
        }
        return '#';
    }
}
