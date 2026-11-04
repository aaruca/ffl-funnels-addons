<?php
/**
 * WooBooster Bundle Element — Bricks Builder.
 *
 * "Frequently Bought Together" style widget with checkboxes per item
 * and a single Add to Cart button.
 *
 * @package FFL_Funnels_Addons
 */

namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Bundle_Element extends \Bricks\Element
{
    public $category = 'FFL Funnels';
    public $name     = 'woobooster-bundle';
    public $icon     = 'ti-package';
    public $tag      = 'div';
    public $scripts  = ['wooboosterBundle'];

    public function get_label(): string
    {
        return esc_html__('WooBooster Bundle', 'ffl-funnels-addons');
    }

    // ── Control Groups ─────────────────────────────────────────────────

    public function set_control_groups(): void
    {
        $this->control_groups['wb_bundle_settings'] = [
            'title' => esc_html__('Bundle Settings', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_display'] = [
            'title' => esc_html__('Display Options', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_heading'] = [
            'title' => esc_html__('Heading Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_card'] = [
            'title' => esc_html__('Item Card Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_separator'] = [
            'title' => esc_html__('Separator Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_image'] = [
            'title' => esc_html__('Image Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_title'] = [
            'title' => esc_html__('Title Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_price'] = [
            'title' => esc_html__('Price Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_badge'] = [
            'title' => esc_html__('Discount Badge Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_checkbox'] = [
            'title' => esc_html__('Checkbox Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_total'] = [
            'title' => esc_html__('Total Section Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_savings'] = [
            'title' => esc_html__('Savings Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['wb_bundle_s_button'] = [
            'title' => esc_html__('Add to Cart Button Style', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];
    }

    // ── Controls ───────────────────────────────────────────────────────

    public function set_controls(): void
    {
        // ── Bundle Settings ──

        $bundle_options = ['' => esc_html__('Auto (first matching bundle)', 'ffl-funnels-addons')];
        $bundles = \WooBooster_Bundle::get_all(['status' => 1, 'limit' => 200, 'orderby' => 'name', 'order' => 'ASC']);
        if (!empty($bundles)) {
            foreach ($bundles as $b) {
                $bundle_options[$b->id] = sprintf('%s (ID: %d)', $b->name, $b->id);
            }
        }

        $this->controls['wb_bundle_id'] = [
            'group'       => 'wb_bundle_settings',
            'tab'         => 'content',
            'label'       => esc_html__('Bundle', 'ffl-funnels-addons'),
            'type'        => 'select',
            'options'     => $bundle_options,
            'default'     => '',
            'description' => esc_html__('Select a specific bundle or let the system auto-detect based on conditions.', 'ffl-funnels-addons'),
        ];

        $this->controls['wb_bundle_source'] = [
            'group'    => 'wb_bundle_settings',
            'tab'      => 'content',
            'label'    => esc_html__('Product Source', 'ffl-funnels-addons'),
            'type'     => 'select',
            'options'  => [
                'current' => esc_html__('Current Product (auto-detect)', 'ffl-funnels-addons'),
                'manual'  => esc_html__('Manual Product ID', 'ffl-funnels-addons'),
                'none'    => esc_html__('None (display bundle as-is)', 'ffl-funnels-addons'),
            ],
            'default' => 'current',
            'description' => esc_html__('Choose "None" if using a specific bundle on homepage or custom pages.', 'ffl-funnels-addons'),
        ];

        $this->controls['wb_bundle_product_id'] = [
            'group'    => 'wb_bundle_settings',
            'tab'      => 'content',
            'label'    => esc_html__('Product ID', 'ffl-funnels-addons'),
            'type'     => 'number',
            'required' => ['wb_bundle_source', '=', 'manual'],
        ];

        // ── Display Options ──

        $this->controls['wb_bundle_heading'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Heading Text', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('Frequently Bought Together', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        $this->controls['wb_bundle_button_text'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Button Text', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('Add Selected to Cart', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        $this->controls['wb_bundle_layout'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Layout', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                'horizontal' => esc_html__('Horizontal', 'ffl-funnels-addons'),
                'vertical'   => esc_html__('Vertical', 'ffl-funnels-addons'),
                'grid'       => esc_html__('Grid', 'ffl-funnels-addons'),
            ],
            'default' => 'horizontal',
        ];

        $this->controls['wb_bundle_show_image'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Show Image', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['wb_bundle_show_bundle_image'] = [
            'group'       => 'wb_bundle_display',
            'tab'         => 'content',
            'label'       => esc_html__('Show Bundle Image', 'ffl-funnels-addons'),
            'type'        => 'checkbox',
            'default'     => false,
            'description' => esc_html__('Shows the image set on the bundle above the heading.', 'ffl-funnels-addons'),
        ];

        $this->controls['wb_bundle_show_title'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Show Title', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['wb_bundle_show_price'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Show Price', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['wb_bundle_image_size'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Image Size', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                'woocommerce_thumbnail'       => esc_html__('WooCommerce Thumbnail', 'ffl-funnels-addons'),
                'woocommerce_gallery_thumbnail' => esc_html__('Gallery Thumbnail', 'ffl-funnels-addons'),
                'medium'                      => esc_html__('Medium', 'ffl-funnels-addons'),
                'thumbnail'                   => esc_html__('Thumbnail', 'ffl-funnels-addons'),
            ],
            'default' => 'woocommerce_thumbnail',
        ];

        $this->controls['wb_bundle_grid_columns'] = [
            'group'    => 'wb_bundle_display',
            'tab'      => 'content',
            'label'    => esc_html__('Grid Columns', 'ffl-funnels-addons'),
            'type'     => 'number',
            'min'      => 1,
            'max'      => 6,
            'default'  => 3,
            'required' => ['wb_bundle_layout', '=', 'grid'],
        ];

        $this->controls['wb_bundle_separator_text'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Separator Text', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => '+',
            'inline'  => true,
            'description' => esc_html__('Shown between items. Leave empty to hide.', 'ffl-funnels-addons'),
        ];

        $this->controls['wb_bundle_total_label'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Total Label', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('Total:', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        $this->controls['wb_bundle_show_original_price'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Show Original Price (strikethrough)', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['wb_bundle_show_badge'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Show Per-Item Discount Badge', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['wb_bundle_show_total'] = [
            'group'   => 'wb_bundle_display',
            'tab'     => 'content',
            'label'   => esc_html__('Show Total Section', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
        ];

        $this->controls['wb_bundle_show_savings'] = [
            'group'    => 'wb_bundle_display',
            'tab'      => 'content',
            'label'    => esc_html__('Show Savings', 'ffl-funnels-addons'),
            'type'     => 'checkbox',
            'default'  => true,
            'required' => ['wb_bundle_show_total', '!=', ''],
        ];

        $this->controls['wb_bundle_savings_format'] = [
            'group'    => 'wb_bundle_display',
            'tab'      => 'content',
            'label'    => esc_html__('Savings Format', 'ffl-funnels-addons'),
            'type'     => 'select',
            'options'  => [
                'amount'     => esc_html__('Amount (e.g. Save $20)', 'ffl-funnels-addons'),
                'percentage' => esc_html__('Percentage (e.g. Save 15%)', 'ffl-funnels-addons'),
                'both'       => esc_html__('Both (e.g. Save $20 / 15%)', 'ffl-funnels-addons'),
            ],
            'default'  => 'amount',
            'required' => ['wb_bundle_show_savings', '!=', ''],
        ];

        // ── Heading Style ──

        $this->controls['wb_heading_typography'] = [
            'group' => 'wb_bundle_s_heading',
            'tab'   => 'content',
            'label' => esc_html__('Typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.wb-bundle-heading']],
        ];

        $this->controls['wb_heading_margin'] = [
            'group' => 'wb_bundle_s_heading',
            'tab'   => 'content',
            'label' => esc_html__('Margin', 'ffl-funnels-addons'),
            'type'  => 'spacing',
            'css'   => [['property' => 'margin', 'selector' => '.wb-bundle-heading']],
        ];

        $this->controls['wb_heading_align'] = [
            'group'   => 'wb_bundle_s_heading',
            'tab'     => 'content',
            'label'   => esc_html__('Alignment', 'ffl-funnels-addons'),
            'type'    => 'text-align',
            'css'     => [['property' => 'text-align', 'selector' => '.wb-bundle-heading']],
        ];

        // ── Item Card Style ──

        $this->controls['wb_card_bg'] = [
            'group' => 'wb_bundle_s_card',
            'tab'   => 'content',
            'label' => esc_html__('Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.wb-bundle-item']],
        ];

        $this->controls['wb_card_border'] = [
            'group' => 'wb_bundle_s_card',
            'tab'   => 'content',
            'label' => esc_html__('Border', 'ffl-funnels-addons'),
            'type'  => 'border',
            'css'   => [['property' => 'border', 'selector' => '.wb-bundle-item']],
        ];

        $this->controls['wb_card_padding'] = [
            'group' => 'wb_bundle_s_card',
            'tab'   => 'content',
            'label' => esc_html__('Padding', 'ffl-funnels-addons'),
            'type'  => 'spacing',
            'css'   => [['property' => 'padding', 'selector' => '.wb-bundle-item']],
        ];

        $this->controls['wb_card_gap'] = [
            'group'   => 'wb_bundle_s_card',
            'tab'     => 'content',
            'label'   => esc_html__('Gap Between Items', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'default' => '16px',
            'css'     => [['property' => 'gap', 'selector' => '.wb-bundle-items']],
        ];

        $this->controls['wb_card_radius'] = [
            'group' => 'wb_bundle_s_card',
            'tab'   => 'content',
            'label' => esc_html__('Border Radius', 'ffl-funnels-addons'),
            'type'  => 'number',
            'units' => true,
            'css'   => [['property' => 'border-radius', 'selector' => '.wb-bundle-item']],
        ];

        // ── Separator Style ──

        $this->controls['wb_sep_color'] = [
            'group' => 'wb_bundle_s_separator',
            'tab'   => 'content',
            'label' => esc_html__('Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.wb-bundle-separator']],
        ];

        $this->controls['wb_sep_typography'] = [
            'group' => 'wb_bundle_s_separator',
            'tab'   => 'content',
            'label' => esc_html__('Typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.wb-bundle-separator']],
        ];

        // ── Image Style ──

        $this->controls['wb_img_width'] = [
            'group'   => 'wb_bundle_s_image',
            'tab'     => 'content',
            'label'   => esc_html__('Width', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'default' => '100px',
            'css'     => [['property' => 'width', 'selector' => '.wb-bundle-item__image img']],
        ];

        $this->controls['wb_img_border'] = [
            'group' => 'wb_bundle_s_image',
            'tab'   => 'content',
            'label' => esc_html__('Border', 'ffl-funnels-addons'),
            'type'  => 'border',
            'css'   => [['property' => 'border', 'selector' => '.wb-bundle-item__image img']],
        ];

        // ── Title Style ──

        $this->controls['wb_title_typography'] = [
            'group' => 'wb_bundle_s_title',
            'tab'   => 'content',
            'label' => esc_html__('Typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.wb-bundle-item__name']],
        ];

        // ── Price Style ──

        $this->controls['wb_price_typography'] = [
            'group' => 'wb_bundle_s_price',
            'tab'   => 'content',
            'label' => esc_html__('Typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.wb-bundle-item__price']],
        ];

        $this->controls['wb_price_original_color'] = [
            'group' => 'wb_bundle_s_price',
            'tab'   => 'content',
            'label' => esc_html__('Original Price Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.wb-bundle-item__price del']],
        ];

        $this->controls['wb_price_sale_color'] = [
            'group' => 'wb_bundle_s_price',
            'tab'   => 'content',
            'label' => esc_html__('Sale Price Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.wb-bundle-item__price ins']],
        ];

        // ── Discount Badge Style ──

        $this->controls['wb_badge_bg'] = [
            'group' => 'wb_bundle_s_badge',
            'tab'   => 'content',
            'label' => esc_html__('Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.wb-bundle-badge']],
        ];

        $this->controls['wb_badge_color'] = [
            'group' => 'wb_bundle_s_badge',
            'tab'   => 'content',
            'label' => esc_html__('Text Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.wb-bundle-badge']],
        ];

        $this->controls['wb_badge_typography'] = [
            'group' => 'wb_bundle_s_badge',
            'tab'   => 'content',
            'label' => esc_html__('Typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.wb-bundle-badge']],
        ];

        $this->controls['wb_badge_border'] = [
            'group' => 'wb_bundle_s_badge',
            'tab'   => 'content',
            'label' => esc_html__('Border Radius', 'ffl-funnels-addons'),
            'type'  => 'border',
            'css'   => [['property' => 'border', 'selector' => '.wb-bundle-badge']],
        ];

        $this->controls['wb_badge_padding'] = [
            'group' => 'wb_bundle_s_badge',
            'tab'   => 'content',
            'label' => esc_html__('Padding', 'ffl-funnels-addons'),
            'type'  => 'spacing',
            'css'   => [['property' => 'padding', 'selector' => '.wb-bundle-badge']],
        ];

        // ── Checkbox Style ──

        $this->controls['wb_checkbox_size'] = [
            'group'   => 'wb_bundle_s_checkbox',
            'tab'     => 'content',
            'label'   => esc_html__('Size', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'default' => '18px',
            'css'     => [
                ['property' => 'width', 'selector' => '.wb-bundle-item__checkbox input[type="checkbox"]'],
                ['property' => 'height', 'selector' => '.wb-bundle-item__checkbox input[type="checkbox"]'],
            ],
        ];

        $this->controls['wb_checkbox_accent'] = [
            'group' => 'wb_bundle_s_checkbox',
            'tab'   => 'content',
            'label' => esc_html__('Accent Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'accent-color', 'selector' => '.wb-bundle-item__checkbox input[type="checkbox"]']],
        ];

        // ── Total Section Style ──

        $this->controls['wb_total_bg'] = [
            'group' => 'wb_bundle_s_total',
            'tab'   => 'content',
            'label' => esc_html__('Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.wb-bundle-total']],
        ];

        $this->controls['wb_total_typography'] = [
            'group' => 'wb_bundle_s_total',
            'tab'   => 'content',
            'label' => esc_html__('Typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.wb-bundle-total']],
        ];

        $this->controls['wb_total_padding'] = [
            'group' => 'wb_bundle_s_total',
            'tab'   => 'content',
            'label' => esc_html__('Padding', 'ffl-funnels-addons'),
            'type'  => 'spacing',
            'css'   => [['property' => 'padding', 'selector' => '.wb-bundle-total']],
        ];

        $this->controls['wb_total_border'] = [
            'group' => 'wb_bundle_s_total',
            'tab'   => 'content',
            'label' => esc_html__('Border', 'ffl-funnels-addons'),
            'type'  => 'border',
            'css'   => [['property' => 'border', 'selector' => '.wb-bundle-total']],
        ];

        $this->controls['wb_total_original_color'] = [
            'group' => 'wb_bundle_s_total',
            'tab'   => 'content',
            'label' => esc_html__('Original Total Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.wb-bundle-total__original']],
        ];

        $this->controls['wb_total_final_color'] = [
            'group' => 'wb_bundle_s_total',
            'tab'   => 'content',
            'label' => esc_html__('Final Total Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.wb-bundle-total__discounted']],
        ];

        // ── Savings Style ──

        $this->controls['wb_savings_color'] = [
            'group' => 'wb_bundle_s_savings',
            'tab'   => 'content',
            'label' => esc_html__('Text Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.wb-bundle-total__savings']],
        ];

        $this->controls['wb_savings_bg'] = [
            'group' => 'wb_bundle_s_savings',
            'tab'   => 'content',
            'label' => esc_html__('Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.wb-bundle-total__savings']],
        ];

        $this->controls['wb_savings_typography'] = [
            'group' => 'wb_bundle_s_savings',
            'tab'   => 'content',
            'label' => esc_html__('Typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.wb-bundle-total__savings']],
        ];

        $this->controls['wb_savings_padding'] = [
            'group' => 'wb_bundle_s_savings',
            'tab'   => 'content',
            'label' => esc_html__('Padding', 'ffl-funnels-addons'),
            'type'  => 'spacing',
            'css'   => [['property' => 'padding', 'selector' => '.wb-bundle-total__savings']],
        ];

        $this->controls['wb_savings_radius'] = [
            'group' => 'wb_bundle_s_savings',
            'tab'   => 'content',
            'label' => esc_html__('Border Radius', 'ffl-funnels-addons'),
            'type'  => 'number',
            'units' => true,
            'css'   => [['property' => 'border-radius', 'selector' => '.wb-bundle-total__savings']],
        ];

        // ── Add to Cart Button Style ──

        $this->controls['wb_btn_bg'] = [
            'group' => 'wb_bundle_s_button',
            'tab'   => 'content',
            'label' => esc_html__('Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.wb-bundle-add-to-cart']],
        ];

        $this->controls['wb_btn_color'] = [
            'group' => 'wb_bundle_s_button',
            'tab'   => 'content',
            'label' => esc_html__('Text Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.wb-bundle-add-to-cart']],
        ];

        $this->controls['wb_btn_typography'] = [
            'group' => 'wb_bundle_s_button',
            'tab'   => 'content',
            'label' => esc_html__('Typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.wb-bundle-add-to-cart']],
        ];

        $this->controls['wb_btn_border'] = [
            'group' => 'wb_bundle_s_button',
            'tab'   => 'content',
            'label' => esc_html__('Border', 'ffl-funnels-addons'),
            'type'  => 'border',
            'css'   => [['property' => 'border', 'selector' => '.wb-bundle-add-to-cart']],
        ];

        $this->controls['wb_btn_padding'] = [
            'group' => 'wb_bundle_s_button',
            'tab'   => 'content',
            'label' => esc_html__('Padding', 'ffl-funnels-addons'),
            'type'  => 'spacing',
            'css'   => [['property' => 'padding', 'selector' => '.wb-bundle-add-to-cart']],
        ];

        $this->controls['wb_btn_hover_bg'] = [
            'group' => 'wb_bundle_s_button',
            'tab'   => 'content',
            'label' => esc_html__('Hover Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.wb-bundle-add-to-cart:hover']],
        ];

        $this->controls['wb_btn_hover_color'] = [
            'group' => 'wb_bundle_s_button',
            'tab'   => 'content',
            'label' => esc_html__('Hover Text Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.wb-bundle-add-to-cart:hover']],
        ];
    }

    // ── Asset Loading ──────────────────────────────────────────────────

    public function enqueue_scripts(): void
    {
        $module_url = FFLA_URL . 'modules/woobooster/';

        wp_enqueue_style(
            'woobooster-bundle',
            $module_url . 'assets/css/woobooster-bundle.css',
            [],
            FFLA_VERSION
        );

        wp_enqueue_script(
            'wooboosterBundle',
            $module_url . 'assets/js/woobooster-bundle.js',
            ['jquery'],
            FFLA_VERSION,
            true
        );

        wp_localize_script('wooboosterBundle', 'wooboosterBundleConfig', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('woobooster_bundle_cart'),
            'isBuilder' => $this->is_builder_context() ? '1' : '0',
            'i18n'      => [
                'adding'      => esc_html__('Adding...', 'ffl-funnels-addons'),
                'added'       => esc_html__('Added to Cart!', 'ffl-funnels-addons'),
                'error'       => esc_html__('Error adding to cart. Please try again.', 'ffl-funnels-addons'),
                'partial'     => esc_html__('Some items could not be added:', 'ffl-funnels-addons'),
                'noItems'     => esc_html__('Please select at least one product.', 'ffl-funnels-addons'),
                /* translators: %s: amount saved */
                'saveFormat'  => esc_html__('(Save %s)', 'ffl-funnels-addons'),
            ],
        ]);
    }

    // ── Render ─────────────────────────────────────────────────────────

    public function render(): void
    {
        $s = $this->settings;

        // Resolve product ID.
        $product_id = $this->resolve_product_id($s);

        // Resolve bundle.
        $bundle = null;
        $matcher = new \WooBooster_Bundle_Matcher();

        $specific_bundle_id = !empty($s['wb_bundle_id']) ? absint($s['wb_bundle_id']) : 0;

        if ($specific_bundle_id) {
            // Specific bundle selected — works with or without product context
            // ($product_id may be 0 on homepage / standalone pages).
            $bundle = $matcher->get_bundle_by_id($specific_bundle_id, $product_id);

            // A pinned bundle shows wherever it is placed, as before, but its
            // User Role conditions still apply (an LE-only bundle never shows
            // to other shoppers). Its product conditions only decide where it
            // shows by itself. In the builder it always previews.
            if ($bundle && !$this->is_builder_context() && !$matcher->bundle_allows_viewer($bundle)) {
                $bundle = null;
            }
        } elseif ($product_id) {
            $bundles = $matcher->get_bundles_for_product($product_id);
            $bundle = !empty($bundles) ? $bundles[0] : null;
        }

        $items = $bundle && !empty($bundle->resolved_items) ? $this->build_items($bundle, $product_id) : [];

        if (empty($items)) {
            // No bundle, no items, or a required item cannot be bought —
            // render nothing (or builder placeholder).
            if ($this->is_builder_context()) {
                $this->render_builder_placeholder();
            }
            return;
        }

        $heading       = $s['wb_bundle_heading'] ?? __('Frequently Bought Together', 'ffl-funnels-addons');
        $button_text   = $s['wb_bundle_button_text'] ?? __('Add Selected to Cart', 'ffl-funnels-addons');
        $layout        = $s['wb_bundle_layout'] ?? 'horizontal';
        $show_image    = isset($s['wb_bundle_show_image']) ? $s['wb_bundle_show_image'] : true;
        $show_title    = isset($s['wb_bundle_show_title']) ? $s['wb_bundle_show_title'] : true;
        $show_price    = isset($s['wb_bundle_show_price']) ? $s['wb_bundle_show_price'] : true;
        $image_size    = $s['wb_bundle_image_size'] ?? 'woocommerce_thumbnail';
        $grid_cols     = isset($s['wb_bundle_grid_columns']) ? max(1, absint($s['wb_bundle_grid_columns'])) : 3;
        $separator     = array_key_exists('wb_bundle_separator_text', $s) ? $s['wb_bundle_separator_text'] : '+';
        $total_label   = $s['wb_bundle_total_label'] ?? __('Total:', 'ffl-funnels-addons');
        $show_original = isset($s['wb_bundle_show_original_price']) ? $s['wb_bundle_show_original_price'] : true;
        $show_badge    = isset($s['wb_bundle_show_badge']) ? $s['wb_bundle_show_badge'] : true;
        $show_total    = isset($s['wb_bundle_show_total']) ? $s['wb_bundle_show_total'] : true;
        $show_savings  = isset($s['wb_bundle_show_savings']) ? $s['wb_bundle_show_savings'] : true;
        $savings_fmt   = $s['wb_bundle_savings_format'] ?? 'amount';

        $show_cover    = !empty($s['wb_bundle_show_bundle_image']);

        // Signed list of the items shown, so the cart adds exactly these
        // (automatic picks can change between page view and click).
        $token = \WooBooster_Bundle_Cart::create_snapshot_token(
            $bundle->id,
            $product_id,
            array_map(function ($item) { return $item['id']; }, $items)
        );

        $this->set_attribute('_root', 'class', 'wb-bundle');
        $this->set_attribute('_root', 'data-bundle-id', $bundle->id);
        $this->set_attribute('_root', 'data-source-product-id', $product_id);
        $this->set_attribute('_root', 'data-bundle-token', $token);

        echo '<div ' . $this->render_attributes('_root') . '>';

        // Bundle image (optional, set on the bundle).
        if ($show_cover && !empty($bundle->image_id)) {
            $cover = wp_get_attachment_image((int) $bundle->image_id, $image_size, false, ['class' => 'wb-bundle-cover__img']);
            if ($cover) {
                echo '<div class="wb-bundle-cover">' . $cover . '</div>';
            }
        }

        // Heading.
        if ($heading) {
            echo '<h3 class="wb-bundle-heading">' . esc_html($heading) . '</h3>';
        }

        // Items grid.
        $items_style = ('grid' === $layout)
            ? ' style="grid-template-columns:repeat(' . esc_attr($grid_cols) . ',1fr);"'
            : '';
        echo '<div class="wb-bundle-items wb-bundle-items--' . esc_attr($layout) . '"' . $items_style . '>';

        foreach ($items as $idx => $item) {
            $item_classes = 'wb-bundle-item'
                . ($item['required'] ? ' wb-bundle-item--required' : '')
                . ($item['anchor'] ? ' wb-bundle-item--anchor' : '');
            echo '<div class="' . esc_attr($item_classes) . '" data-product-id="' . esc_attr($item['id']) . '" data-price="' . esc_attr($item['discounted_price']) . '" data-original-price="' . esc_attr($item['original_price']) . '" data-qty="' . esc_attr($item['qty']) . '">';

            // Checkbox. Items not marked Optional are part of every purchase,
            // so their box is ticked and locked (the server enforces it too).
            $checkbox_id = 'wb-bundle-cb-' . $bundle->id . '-' . $item['id'];
            echo '<label class="wb-bundle-item__checkbox" for="' . esc_attr($checkbox_id) . '">';
            echo '<input type="checkbox" id="' . esc_attr($checkbox_id) . '" name="wb_bundle_products[]" value="' . esc_attr($item['id']) . '" aria-label="' . esc_attr(sprintf(
                /* translators: %s: product name */
                $item['required'] ? __('%s is included in this bundle', 'ffl-funnels-addons') : __('Include %s in bundle', 'ffl-funnels-addons'),
                $item['name']
            )) . '" checked' . ($item['required'] ? ' disabled data-required="1"' : '') . '>';
            echo '</label>';

            // Image.
            if ($show_image) {
                echo '<div class="wb-bundle-item__image">';
                echo $item['image'];
                echo '</div>';
            }

            // Info.
            echo '<div class="wb-bundle-item__info">';

            if ($item['anchor']) {
                echo '<span class="wb-bundle-item__anchor-label">' . esc_html__('This item', 'ffl-funnels-addons') . '</span>';
            }

            if ($show_title) {
                $title = $item['qty'] > 1
                    /* translators: 1: quantity, 2: product name */
                    ? sprintf(__('%1$d × %2$s', 'ffl-funnels-addons'), $item['qty'], $item['name'])
                    : $item['name'];
                echo '<div class="wb-bundle-item__name">' . esc_html($title) . '</div>';
                if ('' !== $item['variation_text']) {
                    echo '<div class="wb-bundle-item__variation">' . esc_html($item['variation_text']) . '</div>';
                }
            }

            if ($show_price) {
                echo '<div class="wb-bundle-item__price">';
                if ($item['has_discount']) {
                    if ($show_original) {
                        echo '<del>' . wp_kses_post(wc_price($item['original_price'])) . '</del> ';
                    }
                    echo '<ins>' . wp_kses_post(wc_price($item['discounted_price'])) . '</ins>';
                } else {
                    echo wp_kses_post(wc_price($item['original_price']));
                }
                if ($item['qty'] > 1) {
                    /* translators: %d: quantity */
                    echo ' <span class="wb-bundle-item__qty">' . esc_html(sprintf(__('each (×%d)', 'ffl-funnels-addons'), $item['qty'])) . '</span>';
                }
                echo '</div>';
            }

            // Discount badge.
            if ($show_badge && $item['has_discount'] && $item['badge_text']) {
                echo '<span class="wb-bundle-badge">' . esc_html($item['badge_text']) . '</span>';
            }

            echo '</div>'; // end info

            echo '</div>'; // end item

            // Separator (except after last item).
            if ('' !== $separator && $idx < count($items) - 1) {
                echo '<div class="wb-bundle-separator">' . esc_html($separator) . '</div>';
            }
        }

        echo '</div>'; // end items

        // Total section: unit prices × quantities, exactly what the cart charges.
        $total_original   = 0.0;
        $total_discounted = 0.0;
        foreach ($items as $item) {
            $total_original   += $item['original_price'] * $item['qty'];
            $total_discounted += $item['discounted_price'] * $item['qty'];
        }

        if ($show_total) {
            echo '<div class="wb-bundle-total">';
            echo '<div class="wb-bundle-total__row">';
            echo '<span class="wb-bundle-total__label">' . esc_html($total_label) . '</span>';
            echo '<span class="wb-bundle-total__prices">';
            if ($total_discounted < $total_original) {
                if ($show_original) {
                    echo '<del class="wb-bundle-total__original">' . wp_kses_post(wc_price($total_original)) . '</del> ';
                }
                echo '<ins class="wb-bundle-total__discounted">' . wp_kses_post(wc_price($total_discounted)) . '</ins>';
                if ($show_savings) {
                    echo ' <span class="wb-bundle-total__savings">' . wp_kses_post(
                        $this->format_savings($total_original, $total_discounted, $savings_fmt)
                    ) . '</span>';
                }
            } else {
                echo '<span class="wb-bundle-total__discounted">' . wp_kses_post(wc_price($total_original)) . '</span>';
            }
            echo '</span>';
            echo '</div>';
            echo '</div>';
        }

        // Inline error slot (a11y-friendly status region).
        echo '<div class="wb-bundle-error" role="alert" aria-live="polite" hidden></div>';

        // Add to Cart button.
        echo '<button type="button" class="wb-bundle-add-to-cart" data-bundle-id="' . esc_attr($bundle->id) . '">';
        echo esc_html($button_text);
        echo '</button>';

        // Hidden data for JS.
        echo '<input type="hidden" class="wb-bundle-data" value="' . esc_attr(wp_json_encode([
            'bundle_id'       => $bundle->id,
            'discount_type'   => $bundle->discount_type,
            'discount_value'  => $bundle->discount_value,
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'currency_pos'    => get_option('woocommerce_currency_pos', 'left'),
            'decimals'        => wc_get_price_decimals(),
            'decimal_sep'     => wc_get_price_decimal_separator(),
            'thousand_sep'    => wc_get_price_thousand_separator(),
            'show_original'   => (bool) $show_original,
            'show_savings'    => (bool) $show_savings,
            'savings_format'  => $savings_fmt,
        ])) . '">';

        echo '</div>'; // end wb-bundle
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Build the "(Save …)" string for the total row.
     *
     * @param float  $original   Sum of original prices.
     * @param float  $discounted Sum of discounted prices.
     * @param string $format     'amount' | 'percentage' | 'both'.
     * @return string Escaped, currency-formatted savings markup.
     */
    private function format_savings($original, $discounted, $format): string
    {
        $savings = max(0.0, (float) $original - (float) $discounted);
        if ($savings <= 0) {
            return '';
        }

        $amount  = wc_price($savings);
        $percent = $original > 0 ? round(($savings / $original) * 100) . '%' : '0%';

        switch ($format) {
            case 'percentage':
                $value = esc_html($percent);
                break;
            case 'both':
                $value = $amount . ' / ' . esc_html($percent);
                break;
            case 'amount':
            default:
                $value = $amount;
                break;
        }

        /* translators: %s: amount or percentage saved */
        return sprintf(esc_html__('(Save %s)', 'ffl-funnels-addons'), $value);
    }

    /**
     * Items the widget shows, priced like the cart will charge them.
     *
     * Items that cannot be bought right now (out of stock, not purchasable,
     * a variable product without default attributes) are left out. When a
     * required item is one of them the bundle cannot be added, so nothing is
     * shown (empty array).
     *
     * @param object $bundle     Bundle with ->resolved_items.
     * @param int    $product_id Product being viewed (0 = none).
     * @return array
     */
    private function build_items($bundle, int $product_id): array
    {
        $qty_map  = \WooBooster_Bundle::get_item_quantities($bundle->id);
        $required = array_flip(\WooBooster_Bundle::get_required_product_ids($bundle->id));

        $lines = [];
        foreach ($bundle->resolved_items as $item_product_id) {
            $item_product_id = absint($item_product_id);
            $qty             = isset($qty_map[$item_product_id]) ? max(1, (int) $qty_map[$item_product_id]) : 1;
            $line            = \WooBooster_Bundle::prepare_line($item_product_id, $qty);
            if ('' !== $line['error']) {
                if (isset($required[$item_product_id])) {
                    return [];
                }
                continue;
            }
            $lines[$item_product_id] = $line;
        }

        // A required item that is no longer published is missing from the
        // resolved items: the cart refuses the bundle, so hide it here too.
        if (array_diff(array_keys($required), array_keys($lines))) {
            return [];
        }

        if (empty($lines)) {
            return [];
        }

        // Shared price math so widget + cart show/charge the same numbers,
        // item quantities included.
        $price_map = \WooBooster_Bundle::calculate_item_prices($bundle, array_keys($lines), $qty_map);

        $items = [];
        foreach ($lines as $pid => $line) {
            $item             = $this->prepare_item_data($line, $bundle, $price_map);
            $item['qty']      = isset($qty_map[$pid]) ? max(1, (int) $qty_map[$pid]) : 1;
            $item['required'] = isset($required[$pid]);
            $item['anchor']   = $product_id && $pid === $product_id;
            $items[]          = $item;
        }

        return $items;
    }

    /**
     * Display data for one item.
     *
     * @param array  $line      WooBooster_Bundle::prepare_line() result.
     * @param object $bundle    Bundle row.
     * @param array  $price_map product_id => ['original', 'discounted'].
     */
    private function prepare_item_data(array $line, $bundle, array $price_map = []): array
    {
        $product          = $line['product'];
        $buy              = $line['buy'] ? $line['buy'] : $product;
        $pid              = $product->get_id();
        $row              = $price_map[$pid] ?? null;
        $original         = $row ? (float) $row['original']   : (float) $buy->get_price();
        $discounted_price = $row ? (float) $row['discounted'] : $original;
        $has_discount     = $discounted_price < $original;

        $price_type = isset($bundle->bundle_price_type) ? $bundle->bundle_price_type : 'discount';
        $badge_text = '';
        if ($has_discount) {
            if ('fixed' === $price_type) {
                // Fixed bundle price: items share a uniform discount factor — show the % off.
                $pct        = $original > 0 ? round((($original - $discounted_price) / $original) * 100) : 0;
                $badge_text = $pct > 0 ? '-' . $pct . '%' : '';
            } elseif ('percentage' === $bundle->discount_type && $bundle->discount_value > 0) {
                $badge_text = '-' . rtrim(rtrim(number_format((float) $bundle->discount_value, 2, '.', ''), '0'), '.') . '%';
            } elseif ('fixed' === $bundle->discount_type) {
                // Show the per-item saving, not the whole bundle total — one badge per card.
                $saving     = $original - $discounted_price;
                $badge_text = '-' . wp_strip_all_tags(wc_price($saving));
            }
        }

        $image_size = $this->settings['wb_bundle_image_size'] ?? 'woocommerce_thumbnail';

        // A variable product is added as its default variation: name it and
        // list any of its attributes the name does not already show.
        $variation_text = '';
        if ($line['variation_id'] && function_exists('wc_get_formatted_variation')) {
            $variation_text = trim(wp_strip_all_tags((string) wc_get_formatted_variation($buy, true, true, true)));
        }

        return [
            'id'               => $pid,
            'name'             => $buy->get_name(),
            'variation_text'   => $variation_text,
            'original_price'   => $original,
            'discounted_price' => $discounted_price,
            'has_discount'     => $has_discount,
            'badge_text'       => $badge_text,
            'image'            => $buy->get_image($image_size),
        ];
    }

    private function resolve_product_id(array $settings): int
    {
        $source = $settings['wb_bundle_source'] ?? 'current';

        if ($source === 'none') {
            return 0;
        }

        if ($source === 'manual') {
            return absint($settings['wb_bundle_product_id'] ?? 0);
        }

        // Current product.
        global $product;
        if ($product && is_a($product, 'WC_Product')) {
            return $product->get_id();
        }

        if (is_singular('product')) {
            return get_the_ID();
        }

        // Builder preview — get any product.
        if ($this->is_builder_context()) {
            $products = wc_get_products(['limit' => 1, 'status' => 'publish', 'return' => 'ids']);
            return !empty($products) ? absint($products[0]) : 0;
        }

        return 0;
    }

    private function is_builder_context(): bool
    {
        if (function_exists('bricks_is_builder_call') && bricks_is_builder_call()) {
            return true;
        }
        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return true;
        }
        if (class_exists('\Bricks\Database') && !empty(\Bricks\Database::$is_builder_call)) {
            return true;
        }
        return false;
    }

    private function render_builder_placeholder(): void
    {
        $this->set_attribute('_root', 'class', 'wb-bundle wb-bundle--placeholder');

        echo '<div ' . $this->render_attributes('_root') . '>';
        echo '<h3 class="wb-bundle-heading">' . esc_html__('Frequently Bought Together', 'ffl-funnels-addons') . '</h3>';
        echo '<div class="wb-bundle-items wb-bundle-items--horizontal">';
        for ($i = 1; $i <= 3; $i++) {
            echo '<div class="wb-bundle-item">';
            echo '<label class="wb-bundle-item__checkbox"><input type="checkbox" checked disabled></label>';
            echo '<div class="wb-bundle-item__image" style="width:80px;height:80px;background:#e5e7eb;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:11px;border-radius:4px;">Product ' . $i . '</div>';
            echo '<div class="wb-bundle-item__info">';
            echo '<div class="wb-bundle-item__name">Sample Product ' . $i . '</div>';
            echo '<div class="wb-bundle-item__price">' . wp_kses_post(wc_price(29.99)) . '</div>';
            echo '</div>';
            echo '</div>';
            if ($i < 3) {
                echo '<div class="wb-bundle-separator">+</div>';
            }
        }
        echo '</div>';
        echo '<div class="wb-bundle-total"><div class="wb-bundle-total__row"><span class="wb-bundle-total__label">' . esc_html__('Total:', 'ffl-funnels-addons') . '</span><span class="wb-bundle-total__discounted">' . wp_kses_post(wc_price(89.97)) . '</span></div></div>';
        echo '<button type="button" class="wb-bundle-add-to-cart" disabled>' . esc_html__('Add Selected to Cart', 'ffl-funnels-addons') . '</button>';
        echo '</div>';
    }
}
