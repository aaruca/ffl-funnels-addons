<?php
/**
 * FFL Dealer Finder — Bricks Element.
 *
 * Renders the Garidium FFL dealer-finder widget on the WooCommerce checkout page.
 * Reads the g-ffl-cockpit API key from the `g_ffl_cockpit_key` option and passes
 * it safely via wp_localize_script — no key is ever exposed to or stored in the
 * Bricks element settings.
 *
 * @package FFL_Funnels_Addons
 */

namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Dealer_Finder_Element extends \Bricks\Element
{
    /** Sidebar group label shown in the Bricks panel. */
    public $category = 'FFL Checkout';

    /** Unique element slug (kebab-case). */
    public $name = 'ffl-dealer-finder';

    /** Themify icon shown in the sidebar. */
    public $icon = 'ti-map-alt';

    /** Default HTML wrapper tag. */
    public $tag = 'div';

    /**
     * JS handle(s) used by Bricks to auto-init the element in the builder.
     * Must match the handle registered in enqueue_scripts().
     */
    public $scripts = ['fflDealerFinder'];

    // ── Labels / metadata ────────────────────────────────────────────────────

    public function get_label(): string
    {
        return esc_html__('FFL Dealer Finder', 'ffl-funnels-addons');
    }

    // ── Control groups ──────────────────────────────────────────────────────

    public function set_control_groups(): void
    {
        $this->control_groups['ffl_widget'] = [
            'title' => esc_html__('Widget Settings', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['ffl_text'] = [
            'title' => esc_html__('Labels', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['ffl_style_group'] = [
            'title' => esc_html__('Widget Style', 'ffl-funnels-addons'),
            'tab'   => 'style',
        ];
    }

    // ── Controls ─────────────────────────────────────────────────────────────

    public function set_controls(): void
    {
        // ── Content: Widget Settings ──────────────────────────────────────────

        $this->controls['map_height'] = [
            'group'    => 'ffl_widget',
            'tab'      => 'content',
            'label'    => esc_html__('Widget Height', 'ffl-funnels-addons'),
            'type'     => 'number',
            'units'    => true,
            'default'  => '500px',
            'css'      => [
                [
                    'property' => 'height',
                    'selector' => '.ffl-dealer-finder__results',
                ],
            ],
        ];

        $this->controls['results_per_page'] = [
            'group'       => 'ffl_widget',
            'tab'         => 'content',
            'label'       => esc_html__('Results Per Page', 'ffl-funnels-addons'),
            'type'        => 'number',
            'default'     => 10,
            'min'         => 1,
            'max'         => 50,
            'placeholder' => '10',
        ];

        $this->controls['radius_miles'] = [
            'group'       => 'ffl_widget',
            'tab'         => 'content',
            'label'       => esc_html__('Search Radius (miles)', 'ffl-funnels-addons'),
            'type'        => 'number',
            'default'     => 50,
            'min'         => 5,
            'max'         => 500,
            'placeholder' => '50',
        ];

        // ── Content: Labels ───────────────────────────────────────────────────

        $this->controls['placeholder_text'] = [
            'group'       => 'ffl_text',
            'tab'         => 'content',
            'label'       => esc_html__('ZIP Code Placeholder', 'ffl-funnels-addons'),
            'type'        => 'text',
            'default'     => esc_html__('Enter your ZIP code', 'ffl-funnels-addons'),
            'inline'      => true,
        ];

        $this->controls['button_text'] = [
            'group'   => 'ffl_text',
            'tab'     => 'content',
            'label'   => esc_html__('Search Button Text', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('Find FFL Dealers', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        $this->controls['no_results_text'] = [
            'group'   => 'ffl_text',
            'tab'     => 'content',
            'label'   => esc_html__('No Results Text', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('No FFL dealers found near that ZIP code. Try expanding your search radius.', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        $this->controls['select_button_text'] = [
            'group'   => 'ffl_text',
            'tab'     => 'content',
            'label'   => esc_html__('Select Dealer Button Text', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('Select This Dealer', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        // ── Style: Widget ─────────────────────────────────────────────────────

        $this->controls['widget_bg'] = [
            'group' => 'ffl_style_group',
            'tab'   => 'style',
            'label' => esc_html__('Widget Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [
                [
                    'property' => 'background-color',
                    'selector' => '.ffl-dealer-finder',
                ],
            ],
        ];

        $this->controls['result_hover_bg'] = [
            'group' => 'ffl_style_group',
            'tab'   => 'style',
            'label' => esc_html__('Result Hover Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [
                [
                    'property' => 'background-color',
                    'selector' => '.ffl-dealer-finder__result:hover',
                ],
            ],
        ];

        $this->controls['selected_bg'] = [
            'group' => 'ffl_style_group',
            'tab'   => 'style',
            'label' => esc_html__('Selected Dealer Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [
                [
                    'property' => 'background-color',
                    'selector' => '.ffl-dealer-finder__result--selected',
                ],
            ],
        ];
    }

    // ── Asset loading ─────────────────────────────────────────────────────────

    public function enqueue_scripts(): void
    {
        $module_url = FFLA_URL . 'modules/ffl-checkout/';

        // CSS.
        wp_enqueue_style(
            'ffl-dealer-finder',
            $module_url . 'assets/css/ffl-dealer-finder.css',
            [],
            FFLA_VERSION
        );

        // JS widget.
        wp_enqueue_script(
            'fflDealerFinder',
            $module_url . 'assets/js/ffl-dealer-finder.js',
            [],
            FFLA_VERSION,
            true
        );

        // Pass settings + API key to the JS layer.
        // The API key never appears in element HTML — it's accessed only here via PHP.
        $api_key = get_option('g_ffl_cockpit_key', '');

        wp_localize_script('fflDealerFinder', 'fflDealerFinderConfig', [
            'apiUrl'           => 'https://ffl-api.garidium.com',
            'apiKey'           => $api_key,
            'nonce'            => wp_create_nonce('ffl_dealer_finder_save'),
            'ajaxUrl'          => admin_url('admin-ajax.php'),
            'cartHasFflItems'  => $this->cart_has_ffl_items() ? '1' : '0',
            'isBuilder'        => \Bricks\Database::$is_builder_call ? '1' : '0',
        ]);
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render(): void
    {
        $settings = $this->settings;

        // Extract settings with defaults.
        $placeholder      = $settings['placeholder_text']   ?? esc_html__('Enter your ZIP code', 'ffl-funnels-addons');
        $btn_text         = $settings['button_text']         ?? esc_html__('Find FFL Dealers', 'ffl-funnels-addons');
        $no_results_text  = $settings['no_results_text']     ?? esc_html__('No FFL dealers found near that ZIP code.', 'ffl-funnels-addons');
        $select_btn_text  = $settings['select_button_text']  ?? esc_html__('Select This Dealer', 'ffl-funnels-addons');
        $results_per_page = absint($settings['results_per_page'] ?? 10);
        $radius_miles     = absint($settings['radius_miles']     ?? 50);

        // Require g-ffl-cockpit to be active.
        if (!defined('G_FFL_COCKPIT_VERSION')) {
            echo '<div class="ffl-dealer-finder ffl-dealer-finder--inactive">';
            echo '<p>' . esc_html__('[FFL Dealer Finder: g-FFL Cockpit plugin is not active]', 'ffl-funnels-addons') . '</p>';
            echo '</div>';
            return;
        }

        // Builder preview: show placeholder instead of a live widget.
        if ($this->is_builder_context()) {
            $this->render_builder_preview();
            return;
        }

        // Cart doesn't contain any FFL products: hide widget.
        if (!$this->cart_has_ffl_items()) {
            // Render a hidden container so JS can re-check if cart changes dynamically.
            echo '<div class="ffl-dealer-finder ffl-dealer-finder--hidden" style="display:none;" '
                . 'data-ffl-empty-cart="1"></div>';
            return;
        }

        // Render the full widget.
        $this->set_attribute('_root', 'class', 'ffl-dealer-finder');
        $this->set_attribute('_root', 'data-results-per-page', $results_per_page);
        $this->set_attribute('_root', 'data-radius-miles', $radius_miles);

        echo '<div ' . $this->render_attributes('_root') . '>';

            // Hidden field that WooCommerce checkout reads on order save.
            echo '<input type="hidden" id="ffl_selected_dealer" name="ffl_selected_dealer" value="" />';
            echo '<input type="hidden" id="ffl_selected_dealer_name" name="ffl_selected_dealer_name" value="" />';

            // Search bar.
            echo '<div class="ffl-dealer-finder__search">';
                echo '<input type="text" id="ffl-zip-input" class="ffl-dealer-finder__input"'
                    . ' placeholder="' . esc_attr($placeholder) . '"'
                    . ' maxlength="10" inputmode="numeric" />';
                echo '<button type="button" id="ffl-search-btn" class="ffl-dealer-finder__btn">'
                    . esc_html($btn_text) . '</button>';
            echo '</div>';

            // Loading spinner.
            echo '<div class="ffl-dealer-finder__loading" style="display:none;">';
                echo '<div class="ffl-dealer-finder__spinner"></div>';
                echo '<span>' . esc_html__('Searching for nearby FFL dealers…', 'ffl-funnels-addons') . '</span>';
            echo '</div>';

            // Selected dealer banner.
            echo '<div class="ffl-dealer-finder__selected-banner" style="display:none;">';
                echo '<span class="ffl-dealer-finder__selected-label">'
                    . esc_html__('Selected Dealer:', 'ffl-funnels-addons') . '</span>';
                echo '<span class="ffl-dealer-finder__selected-name"></span>';
                echo '<button type="button" class="ffl-dealer-finder__change-btn">'
                    . esc_html__('Change', 'ffl-funnels-addons') . '</button>';
            echo '</div>';

            // Results list.
            echo '<div class="ffl-dealer-finder__results" role="list">'
                // JS fills this in.
                . '</div>';

            // No-results message (hidden by default, shown by JS).
            echo '<div class="ffl-dealer-finder__no-results" style="display:none;">'
                . esc_html($no_results_text)
                . '</div>';

            // Pass runtime strings to the JS so we avoid hardcoding them there.
            echo '<script type="application/json" id="ffl-dealer-finder-strings">'
                . wp_json_encode([
                    'selectBtn'  => $select_btn_text,
                    'noResults'  => $no_results_text,
                    'miles'      => esc_html__('mi', 'ffl-funnels-addons'),
                    'license'    => esc_html__('License:', 'ffl-funnels-addons'),
                    'phone'      => esc_html__('Phone:', 'ffl-funnels-addons'),
                    'email'      => esc_html__('Email:', 'ffl-funnels-addons'),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . '</script>';

        echo '</div>';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Return true when we are inside the Bricks visual editor.
     */
    private function is_builder_context(): bool
    {
        return bricks_is_builder_call() || bricks_is_builder();
    }

    /**
     * Render a static placeholder in the Bricks builder so the user can see
     * the element without making live API calls.
     */
    private function render_builder_preview(): void
    {
        $this->set_attribute('_root', 'class', 'ffl-dealer-finder ffl-dealer-finder--preview');

        echo '<div ' . $this->render_attributes('_root') . '>';
            echo '<div class="ffl-dealer-finder__search">';
                echo '<input type="text" class="ffl-dealer-finder__input" placeholder="12345" disabled />';
                echo '<button type="button" class="ffl-dealer-finder__btn" disabled>'
                    . esc_html__('Find FFL Dealers', 'ffl-funnels-addons') . '</button>';
            echo '</div>';

            echo '<div class="ffl-dealer-finder__results">';
                // Sample preview rows.
                for ($i = 1; $i <= 3; $i++) {
                    echo '<div class="ffl-dealer-finder__result">';
                        echo '<div class="ffl-dealer-finder__result-header">';
                            echo '<span class="ffl-dealer-finder__result-name">'
                                . sprintf(esc_html__('Sample FFL Dealer %d', 'ffl-funnels-addons'), $i) . '</span>';
                            echo '<span class="ffl-dealer-finder__result-distance">'
                                . ($i * 5) . ' ' . esc_html__('mi', 'ffl-funnels-addons') . '</span>';
                        echo '</div>';
                        echo '<div class="ffl-dealer-finder__result-address">123 Main St, City, ST 12345</div>';
                        echo '<button type="button" class="ffl-dealer-finder__select-btn" disabled>'
                            . esc_html__('Select This Dealer', 'ffl-funnels-addons') . '</button>';
                    echo '</div>';
                }
            echo '</div>';
        echo '</div>';
    }

    /**
     * Check whether the current cart contains at least one FFL product.
     *
     * A product is considered an FFL product when it has the `automated_listing`
     * post meta set (matching g-ffl-cockpit's own detection logic).
     *
     * @return bool
     */
    private function cart_has_ffl_items(): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            // Cart is not available (e.g. during admin/builder requests).
            // Return true so the widget is visible in those contexts.
            return true;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product_id = absint($item['product_id'] ?? 0);
            if ($product_id && get_post_meta($product_id, 'automated_listing', true)) {
                return true;
            }
        }

        return false;
    }
}
