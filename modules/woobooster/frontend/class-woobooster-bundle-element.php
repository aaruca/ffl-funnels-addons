<?php
/**
 * WooBooster Bundle Element — Bricks Builder.
 *
 * "Frequently Bought Together" style widget with checkboxes per item
 * and a single Add to Cart button.
 *
 * @package WooBooster
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

        $this->control_groups['wb_bundle_s_card'] = [
            'title' => esc_html__('Item Card Style', 'ffl-funnels-addons'),
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
            'group'   => 'wb_bundle_settings',
            'tab'     => 'content',
            'label'   => esc_html__('Product Source', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                'current' => esc_html__('Current Product (auto-detect)', 'ffl-funnels-addons'),
                'manual'  => esc_html__('Manual Product ID', 'ffl-funnels-addons'),
            ],
            'default' => 'current',
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
                'adding'  => esc_html__('Adding...', 'ffl-funnels-addons'),
                'added'   => esc_html__('Added to Cart!', 'ffl-funnels-addons'),
                'error'   => esc_html__('Error adding to cart. Please try again.', 'ffl-funnels-addons'),
                'noItems' => esc_html__('Please select at least one product.', 'ffl-funnels-addons'),
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
            $bundle = $matcher->get_bundle_by_id($specific_bundle_id, $product_id);
        } elseif ($product_id) {
            $bundles = $matcher->get_bundles_for_product($product_id);
            $bundle = !empty($bundles) ? $bundles[0] : null;
        }

        if (!$bundle || empty($bundle->resolved_items)) {
            // No bundle or no items — render nothing (or builder placeholder).
            if ($this->is_builder_context()) {
                $this->render_builder_placeholder();
            }
            return;
        }

        // Gather product data.
        $items = [];
        foreach ($bundle->resolved_items as $item_product_id) {
            $product = wc_get_product($item_product_id);
            if (!$product) {
                continue;
            }
            $items[] = $this->prepare_item_data($product, $bundle);
        }

        if (empty($items)) {
            return;
        }

        $heading     = $s['wb_bundle_heading'] ?? __('Frequently Bought Together', 'ffl-funnels-addons');
        $button_text = $s['wb_bundle_button_text'] ?? __('Add Selected to Cart', 'ffl-funnels-addons');
        $layout      = $s['wb_bundle_layout'] ?? 'horizontal';
        $show_image  = isset($s['wb_bundle_show_image']) ? $s['wb_bundle_show_image'] : true;
        $show_title  = isset($s['wb_bundle_show_title']) ? $s['wb_bundle_show_title'] : true;
        $show_price  = isset($s['wb_bundle_show_price']) ? $s['wb_bundle_show_price'] : true;
        $image_size  = $s['wb_bundle_image_size'] ?? 'woocommerce_thumbnail';

        $this->set_attribute('_root', 'class', 'wb-bundle');
        $this->set_attribute('_root', 'data-bundle-id', $bundle->id);

        echo '<div ' . $this->render_attributes('_root') . '>';

        // Heading.
        if ($heading) {
            echo '<h3 class="wb-bundle-heading">' . esc_html($heading) . '</h3>';
        }

        // Items grid.
        echo '<div class="wb-bundle-items wb-bundle-items--' . esc_attr($layout) . '">';

        foreach ($items as $idx => $item) {
            echo '<div class="wb-bundle-item" data-product-id="' . esc_attr($item['id']) . '" data-price="' . esc_attr($item['discounted_price']) . '" data-original-price="' . esc_attr($item['original_price']) . '">';

            // Checkbox.
            echo '<label class="wb-bundle-item__checkbox">';
            echo '<input type="checkbox" name="wb_bundle_products[]" value="' . esc_attr($item['id']) . '" checked>';
            echo '</label>';

            // Image.
            if ($show_image) {
                echo '<div class="wb-bundle-item__image">';
                echo $item['image'];
                echo '</div>';
            }

            // Info.
            echo '<div class="wb-bundle-item__info">';

            if ($show_title) {
                echo '<div class="wb-bundle-item__name">' . esc_html($item['name']) . '</div>';
            }

            if ($show_price) {
                echo '<div class="wb-bundle-item__price">';
                if ($item['has_discount']) {
                    echo '<del>' . wp_kses_post(wc_price($item['original_price'])) . '</del> ';
                    echo '<ins>' . wp_kses_post(wc_price($item['discounted_price'])) . '</ins>';
                } else {
                    echo wp_kses_post(wc_price($item['original_price']));
                }
                echo '</div>';
            }

            // Discount badge.
            if ($item['has_discount'] && $item['badge_text']) {
                echo '<span class="wb-bundle-badge">' . esc_html($item['badge_text']) . '</span>';
            }

            echo '</div>'; // end info

            echo '</div>'; // end item

            // Plus sign separator (except after last item).
            if ($idx < count($items) - 1) {
                echo '<div class="wb-bundle-separator">+</div>';
            }
        }

        echo '</div>'; // end items

        // Total section.
        $total_original   = array_sum(array_column($items, 'original_price'));
        $total_discounted = array_sum(array_column($items, 'discounted_price'));

        echo '<div class="wb-bundle-total">';
        echo '<div class="wb-bundle-total__row">';
        echo '<span class="wb-bundle-total__label">' . esc_html__('Total:', 'ffl-funnels-addons') . '</span>';
        echo '<span class="wb-bundle-total__prices">';
        if ($total_discounted < $total_original) {
            echo '<del class="wb-bundle-total__original">' . wp_kses_post(wc_price($total_original)) . '</del> ';
            echo '<ins class="wb-bundle-total__discounted">' . wp_kses_post(wc_price($total_discounted)) . '</ins>';
            $savings = $total_original - $total_discounted;
            echo ' <span class="wb-bundle-total__savings">' . sprintf(esc_html__('(Save %s)', 'ffl-funnels-addons'), wp_kses_post(wc_price($savings))) . '</span>';
        } else {
            echo '<span class="wb-bundle-total__discounted">' . wp_kses_post(wc_price($total_original)) . '</span>';
        }
        echo '</span>';
        echo '</div>';
        echo '</div>';

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
        ])) . '">';

        echo '</div>'; // end wb-bundle
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function prepare_item_data($product, $bundle): array
    {
        $price = (float) $product->get_price();
        $discounted_price = $price;
        $has_discount = false;
        $badge_text = '';

        if ($bundle->discount_type === 'percentage' && $bundle->discount_value > 0) {
            $discounted_price = $price * (1 - $bundle->discount_value / 100);
            $has_discount = true;
            $badge_text = '-' . $bundle->discount_value . '%';
        } elseif ($bundle->discount_type === 'fixed' && $bundle->discount_value > 0) {
            $discounted_price = max(0, $price - $bundle->discount_value);
            $has_discount = $discounted_price < $price;
            if ($has_discount) {
                $badge_text = '-' . strip_tags(wc_price($bundle->discount_value));
            }
        }

        $image_size = $this->settings['wb_bundle_image_size'] ?? 'woocommerce_thumbnail';

        return [
            'id'               => $product->get_id(),
            'name'             => $product->get_name(),
            'original_price'   => $price,
            'discounted_price' => round($discounted_price, wc_get_price_decimals()),
            'has_discount'     => $has_discount,
            'badge_text'       => $badge_text,
            'image'            => $product->get_image($image_size),
        ];
    }

    private function resolve_product_id(array $settings): int
    {
        $source = $settings['wb_bundle_source'] ?? 'current';

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
