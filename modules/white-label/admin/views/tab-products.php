<?php
/**
 * White Label — Products tab (view): product editor tools.
 *
 * @var bool                $term_search_enabled
 * @var array<string, bool> $term_search_tools
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

$ffla_wl_tools = isset($term_search_tools) && is_array($term_search_tools) ? $term_search_tools : [];
$ffla_wl_tool  = static function (string $key, bool $default) use ($ffla_wl_tools): string {
    return array_key_exists($key, $ffla_wl_tools) ? ($ffla_wl_tools[$key] ? '1' : '0') : ($default ? '1' : '0');
};

$ffla_wl_rows = [
    'enabled' => [
        __('Category, brand & tag search', 'ffl-funnels-addons'),
        __('A search box on the category, brand and tag boxes of the Edit Product screen and on the Quick Edit / Bulk Edit checklists. It ignores case and accents, keeps the parent category visible next to each match, and adds a "Selected only" view with a live count. Tags get a searchable checklist of every existing tag.', 'ffl-funnels-addons'),
        true,
    ],
    'keep_order' => [
        __('Keep ticked brands and terms in place', 'ffl-funnels-addons'),
        __('WordPress moves ticked terms to the top of a list, out of their place in the tree. WooCommerce already prevents this for product categories; this does the same for Brands and every other nested product taxonomy. Use "Selected only" to review what is ticked.', 'ffl-funnels-addons'),
        true,
    ],
    'collapse_tree' => [
        __('Collapsible category tree', 'ffl-funnels-addons'),
        __('Adds an arrow to every parent category to show or hide its subcategories, plus Expand all / Collapse all. Lists with 15 or more categories start folded, with the branches that hold ticked categories open. Searching always shows every match.', 'ffl-funnels-addons'),
        true,
    ],
    'auto_parents' => [
        __('Tick parent categories automatically', 'ffl-funnels-addons'),
        __('Ticking a subcategory (for example Pump Action Shotguns) also ticks its parents (Shotguns). Unticking never unticks anything else. Leave off if your store assigns only the deepest category on purpose.', 'ffl-funnels-addons'),
        false,
    ],
    'list_filters' => [
        __('Better Products list filters', 'ffl-funnels-addons'),
        __('Adds a "Filter by tag" dropdown to Products, and makes the category, brand and tag filters searchable.', 'ffl-funnels-addons'),
        true,
    ],
];
?>

<div class="wb-card">
    <div class="wb-card__header"><h3><?php esc_html_e('Product editor tools', 'ffl-funnels-addons'); ?></h3></div>
    <div class="wb-card__body">
        <p class="wb-field__desc">
            <?php esc_html_e('Make categories, brands and tags easier to find and assign on the Edit Product screen, in Quick Edit and Bulk Edit, and on the Products list. These apply to everyone who can edit products, staff and clients alike, and only change how WordPress’s own boxes work: the product is saved exactly as before.', 'ffl-funnels-addons'); ?>
        </p>
        <?php foreach ($ffla_wl_rows as $ffla_wl_key => $ffla_wl_row) : ?>
            <?php // Sent when the toggle is off, so "off" is saved explicitly (a missing key means the default). ?>
            <input type="hidden" name="ffla_wl[term_search][<?php echo esc_attr($ffla_wl_key); ?>]" value="0">
            <?php
            FFLA_Admin::render_toggle_field(
                $ffla_wl_row[0],
                'ffla_wl[term_search][' . $ffla_wl_key . ']',
                'enabled' === $ffla_wl_key && isset($term_search_enabled) && !array_key_exists('enabled', $ffla_wl_tools)
                    ? (!empty($term_search_enabled) ? '1' : '0')
                    : $ffla_wl_tool($ffla_wl_key, $ffla_wl_row[2]),
                $ffla_wl_row[1]
            );
            ?>
        <?php endforeach; ?>
    </div>
</div>
