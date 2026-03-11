<?php
/**
 * FFL Dealer Finder — Bricks Element.
 *
 * Renders the full FFL dealer finder widget on the WooCommerce checkout page,
 * including Mapbox map, radius search, FFL list, local pickup, favorites,
 * and C&R upload — matching the g-ffl-checkout plugin's output.
 *
 * API key is never exposed to the browser; all calls go through WP-AJAX.
 *
 * @package FFL_Funnels_Addons
 */

namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Dealer_Finder_Element extends \Bricks\Element
{
    public $category = 'FFL Checkout';
    public $name     = 'ffl-dealer-finder';
    public $icon     = 'ti-map-alt';
    public $tag      = 'div';
    public $scripts  = ['fflDealerFinder'];

    public function get_label(): string
    {
        return esc_html__('FFL Dealer Finder', 'ffl-funnels-addons');
    }

    // ── Control Groups ─────────────────────────────────────────────────────

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

    // ── Controls ───────────────────────────────────────────────────────────

    public function set_controls(): void
    {
        // ── Content: Widget Settings ────────────────────────────────────

        $this->controls['map_height'] = [
            'group'   => 'ffl_widget',
            'tab'     => 'content',
            'label'   => esc_html__('Map Height', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'default' => '35vh',
            'css'     => [['property' => 'height', 'selector' => '.ffl-map']],
        ];

        $this->controls['list_height'] = [
            'group'   => 'ffl_widget',
            'tab'     => 'content',
            'label'   => esc_html__('Results List Height', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'default' => '300px',
            'css'     => [['property' => 'max-height', 'selector' => '.ffl-list']],
        ];

        // ── Content: Labels ─────────────────────────────────────────────

        $this->controls['heading_text'] = [
            'group'   => 'ffl_text',
            'tab'     => 'content',
            'label'   => esc_html__('Heading', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('Select your preferred FFL Dealer', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        $this->controls['search_button_text'] = [
            'group'   => 'ffl_text',
            'tab'     => 'content',
            'label'   => esc_html__('Search Button Text', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('FIND FFL', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        $this->controls['local_pickup_text'] = [
            'group'   => 'ffl_text',
            'tab'     => 'content',
            'label'   => esc_html__('Local Pickup Button Text', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('CLICK HERE FOR IN STORE PICKUP', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        $this->controls['favorite_button_text'] = [
            'group'   => 'ffl_text',
            'tab'     => 'content',
            'label'   => esc_html__('Favorite Button Text', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('FIND THE LAST FFL YOU USED', 'ffl-funnels-addons'),
            'inline'  => true,
        ];

        // ── Style: Widget ───────────────────────────────────────────────

        $this->controls['widget_bg'] = [
            'group' => 'ffl_style_group',
            'tab'   => 'style',
            'label' => esc_html__('Widget Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.ffl-container']],
        ];

        $this->controls['search_btn_bg'] = [
            'group' => 'ffl_style_group',
            'tab'   => 'style',
            'label' => esc_html__('Search Button Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.ffl-search-btn']],
        ];

        $this->controls['search_btn_color'] = [
            'group' => 'ffl_style_group',
            'tab'   => 'style',
            'label' => esc_html__('Search Button Text Color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.ffl-search-btn']],
        ];

        $this->controls['selected_bg'] = [
            'group' => 'ffl_style_group',
            'tab'   => 'style',
            'label' => esc_html__('Selected Dealer Background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.selectedFFLDivButton']],
        ];
    }

    // ── Asset Loading ──────────────────────────────────────────────────────

    public function enqueue_scripts(): void
    {
        $module_url = FFLA_URL . 'modules/ffl-checkout/';
        $settings   = wp_parse_args(get_option('ffl_checkout_settings', []), [
            'include_map' => '1',
        ]);

        // Mapbox GL JS + CSS (only when map is enabled).
        if ($settings['include_map'] === '1') {
            wp_enqueue_style(
                'mapbox-gl',
                'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css',
                [],
                '3.3.0'
            );
            wp_enqueue_script(
                'mapbox-gl',
                'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js',
                [],
                '3.3.0',
                false // Load in head so it's available before our widget.
            );
        }

        // Widget CSS.
        wp_enqueue_style(
            'ffl-dealer-finder',
            $module_url . 'assets/css/ffl-dealer-finder.css',
            [],
            FFLA_VERSION
        );

        // Widget JS.
        wp_enqueue_script(
            'fflDealerFinder',
            $module_url . 'assets/js/ffl-dealer-finder.js',
            $settings['include_map'] === '1' ? ['mapbox-gl'] : [],
            FFLA_VERSION,
            true
        );

        // Pass AJAX config (NO API key exposed).
        wp_localize_script('fflDealerFinder', 'fflDealerFinderConfig', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('ffl_checkout_nonce'),
            'includeMap'     => $settings['include_map'],
            'isBuilder'      => \Bricks\Database::$is_builder_call ? '1' : '0',
            'cartHasFflItems' => $this->cart_has_ffl_items() ? '1' : '0',
            'localPickupLicense' => $settings['local_pickup_license'] ?? '',
            'candrEnabled'   => $settings['candr_enabled'] ?? '0',
            'blacklist'      => array_keys(get_option('ffl_blacklist', [])),
        ]);
    }

    // ── Render ─────────────────────────────────────────────────────────────

    public function render(): void
    {
        $el_settings = $this->settings;
        $settings    = wp_parse_args(get_option('ffl_checkout_settings', []), [
            'include_map'                 => '1',
            'checkout_message'            => '<b>Federal law dictates that your online firearms purchase must be delivered to a federally licensed firearms dealer (FFL) before you can take possession.</b> This process is called a Transfer. Enter your zip code, radius, and FFL name (optional), then click the Find button to get a list of FFL dealers in your area. Select the FFL dealer you want the firearm shipped to. <b><u>Before Checking Out, Contact your selected FFL dealer to confirm they are currently accepting transfers</u></b>. You can also confirm transfer costs.',
            'ammo_checkout_message'       => '',
            'required_notice_text'        => 'REQUIRED: You must search for and select an FFL dealer to complete your order',
            'local_pickup_license'        => '',
            'candr_enabled'               => '0',
            'checkout_message_bg_color'   => '#FFFFF0',
            'checkout_message_text_color' => '#000000',
        ]);

        // Require g-ffl-cockpit to be active.
        if (!defined('G_FFL_COCKPIT_VERSION')) {
            echo '<div class="ffl-container ffl-container--inactive">';
            echo '<p>' . esc_html__('[FFL Dealer Finder: g-FFL Cockpit plugin is not active]', 'ffl-funnels-addons') . '</p>';
            echo '</div>';
            return;
        }

        // Builder preview.
        if ($this->is_builder_context()) {
            $this->render_builder_preview($el_settings, $settings);
            return;
        }

        // Cart doesn't contain FFL products — hide widget.
        if (!$this->cart_has_ffl_items()) {
            echo '<div class="ffl-container ffl-container--hidden" style="display:none;" data-ffl-empty-cart="1"></div>';
            return;
        }

        $heading       = $el_settings['heading_text'] ?? $settings['required_notice_text'];
        $search_btn    = $el_settings['search_button_text'] ?? 'FIND FFL';
        $pickup_text   = $el_settings['local_pickup_text'] ?? 'CLICK HERE FOR IN STORE PICKUP';
        $favorite_text = $el_settings['favorite_button_text'] ?? 'FIND THE LAST FFL YOU USED';
        $msg_bg        = $settings['checkout_message_bg_color'];
        $msg_color     = $settings['checkout_message_text_color'];

        $this->set_attribute('_root', 'class', 'ffl-container');
        $this->set_attribute('_root', 'id', 'ffl_container');

        echo '<div ' . $this->render_attributes('_root') . '>';

        // ── Hidden checkout fields ──
        echo '<input type="hidden" id="ffl_selected_dealer" name="ffl_selected_dealer" value="" />';
        echo '<input type="hidden" id="ffl_selected_dealer_name" name="ffl_selected_dealer_name" value="" />';
        echo '<input type="hidden" name="shipping_fflno" id="shipping_fflno" value="" />';
        echo '<input type="hidden" name="shipping_fflexp" id="shipping_fflexp" value="" />';
        echo '<input type="hidden" name="shipping_ffl_onfile" id="shipping_ffl_onfile" value="" />';
        echo '<input type="hidden" name="shipping_ffl_name" id="shipping_ffl_name" value="" />';
        echo '<input type="hidden" name="shipping_ffl_phone" id="shipping_ffl_phone" value="" />';

        echo '<div class="content">';

        // ── Heading ──
        echo '<h3 class="ffl-dealer-heading">' . esc_html($heading) . '</h3>';

        // ── Required notice ──
        echo '<div id="ffl-required-notice" style="background:#f8d7da; border-left:4px solid #dc3545; padding:10px; margin-bottom:10px; color:#721c24; font-size:inherit; font-weight:normal; font-family:inherit; text-align:left;">'
            . esc_html($settings['required_notice_text'])
            . '</div>';

        // ── Checkout message ──
        echo '<p id="ffl_checkout_notice" class="ffl_checkout_notice" style="margin-bottom:10px !important; background-color:'
            . esc_attr($msg_bg) . '; color:' . esc_attr($msg_color)
            . '; display:block; border-left:4px solid ' . esc_attr($msg_bg) . '; padding-left:10px;">'
            . wp_kses_post($settings['checkout_message'])
            . '</p>';

        // ── Ammo notice (hidden by default, JS shows if needed) ──
        echo '<p id="ffl_checkout_notice_ammo" style="margin-bottom:10px !important; display:none;" class="ffl_checkout_notice"></p>';

        // ── C&R Section ──
        if ($settings['candr_enabled'] === '1') {
            echo '<div id="ffl-candr-section" style="margin-bottom:20px; padding:5px; border:solid 2px; background:#EEEEEE; display:none;">';
            echo '<div class="ffl_checkout_columns">';
            echo '<div class="ffl_checkout_column" style="text-align:left;color:black;">' . esc_html__('Have a C&R?', 'ffl-funnels-addons') . '</div>';
            echo '<div class="ffl_checkout_column" style="width:100%;text-align:right;font-style:italic;">' . esc_html__('Upload your C&R', 'ffl-funnels-addons') . '</div>';
            echo '</div>';
            echo '<div class="ffl_checkout_columns" id="ffl-candr-inner-section" style="padding:5px;border:solid 2px;background-color:white;">';
            echo '<div class="ffl_checkout_column" id="candr_upload_section">';
            echo '<input type="file" id="candr_upload_filename" style="display:none;" accept=".pdf,.jpg,.jpeg,.png,.gif">';
            echo '<label style="width:100%;" id="candrUploadLable" for="candr_upload_filename" class="button alt">' . esc_html__('SELECT C&R', 'ffl-funnels-addons') . '</label>';
            echo '</div>';
            echo '<div class="ffl_checkout_column">';
            echo '<input style="width:100%;" autocomplete="off" type="text" id="candr_license_number" placeholder="' . esc_attr__('Enter Full C&R License#', 'ffl-funnels-addons') . '" value="">';
            echo '</div>';
            echo '<div class="ffl_checkout_column">';
            echo '<input style="width:100%;" readonly id="ffl-candr-override" value="UPLOAD">';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }

        // ── Local Pickup ──
        echo '<div id="ffl-local-pickup-section" style="display:none; margin-bottom:10px;">';
        echo '<div><input readonly id="ffl-local-pickup-search" value="' . esc_attr($pickup_text) . '"></div>';
        echo '</div>';

        // ── Favorites ──
        echo '<div id="ffl-favorite-section" style="display:none;">';
        echo '<div><input readonly id="ffl-favorite-search" value="' . esc_attr($favorite_text) . '"></div>';
        echo '</div>';

        // ── Search Fields ──
        echo '<div id="ffl_search_fields">';
        echo '<div class="ffl_checkout_columns">';
        echo '<div class="ffl_checkout_column"><input autocomplete="off" type="text" id="ffl-zip-code" placeholder="' . esc_attr__('Zip Code', 'ffl-funnels-addons') . '" value=""></div>';
        echo '<div class="ffl_checkout_column">';
        echo '<select id="ffl-radius">';
        echo '<option value="5" selected>' . esc_html__('within 5 Miles', 'ffl-funnels-addons') . '</option>';
        echo '<option value="10">' . esc_html__('Within 10 Miles', 'ffl-funnels-addons') . '</option>';
        echo '<option value="25">' . esc_html__('Within 25 Miles', 'ffl-funnels-addons') . '</option>';
        echo '<option value="50">' . esc_html__('Within 50 Miles', 'ffl-funnels-addons') . '</option>';
        echo '</select>';
        echo '</div>';
        echo '<div class="ffl_checkout_column"><input readonly id="ffl-search" class="ffl-search-btn" value="' . esc_attr($search_btn) . '"></div>';
        echo '</div>';

        echo '<div class="ffl_checkout_columns">';
        echo '<div class="ffl_checkout_column"><input autocomplete="off" type="text" id="ffl-name-search" placeholder="' . esc_attr__('FFL Name (optional)', 'ffl-funnels-addons') . '"></div>';
        echo '</div>';
        echo '</div>'; // end search fields

        echo '</div>'; // end .content

        // ── Click instructions ──
        echo '<div id="ffl-click-instructions" class="ffl-hide">' . esc_html__('Click on FFL to Confirm the Pickup Location', 'ffl-funnels-addons') . '</div>';

        // ── Results list + loading spinner ──
        echo '<div class="ffl-list-container">';
        echo '<ul id="ffl-list" class="ffl-hide"></ul>';
        echo '<div id="floatingBarsG" style="display:none;">';
        for ($i = 1; $i <= 8; $i++) {
            echo '<div class="blockG" id="rotateG_0' . $i . '"></div>';
        }
        echo '</div>';
        echo '</div>';

        // ── Mapbox Map ──
        if ($settings['include_map'] === '1') {
            echo '<div id="ffl-map" class="ffl-map ffl-map-resize"></div>';
            echo '<span id="mapbox-attribution-line" class="mapbox-attribution">'
                . '&copy; <a style="color:gray !important;" target="_blank" href="https://www.mapbox.com/about/maps/">Mapbox</a> '
                . '&copy; <a style="color:gray !important;" target="_blank" href="http://www.openstreetmap.org/copyright">OpenStreetMap</a> '
                . '&copy; <a style="color:gray !important;" target="_blank" href="http://www.maxar.com">Maxar</a>'
                . '<strong> | <a style="color:gray !important;" href="https://www.mapbox.com/map-feedback/" target="_blank">Improve this map</a></strong>'
                . '</span><br>';
        }

        echo '</div>'; // end ffl_container
    }

    // ── Builder Preview ────────────────────────────────────────────────────

    private function render_builder_preview(array $el_settings, array $settings): void
    {
        $this->set_attribute('_root', 'class', 'ffl-container ffl-container--preview');

        echo '<div ' . $this->render_attributes('_root') . '>';
        echo '<div class="content">';
        echo '<h3 class="ffl-dealer-heading">' . esc_html($el_settings['heading_text'] ?? 'Select your preferred FFL Dealer') . '</h3>';

        echo '<div style="background:#f8d7da; border-left:4px solid #dc3545; padding:10px; margin-bottom:10px; color:#721c24; text-align:left;">'
            . esc_html($settings['required_notice_text'] ?? 'REQUIRED: You must search for and select an FFL dealer to complete your order')
            . '</div>';

        // Search form preview.
        echo '<div class="ffl_checkout_columns">';
        echo '<div class="ffl_checkout_column"><input type="text" placeholder="Zip Code" disabled></div>';
        echo '<div class="ffl_checkout_column"><select disabled><option>within 5 Miles</option></select></div>';
        echo '<div class="ffl_checkout_column"><input readonly class="ffl-search-btn" value="FIND FFL" disabled></div>';
        echo '</div>';
        echo '<div class="ffl_checkout_columns">';
        echo '<div class="ffl_checkout_column"><input type="text" placeholder="FFL Name (optional)" disabled></div>';
        echo '</div>';

        // Sample results.
        echo '<ul style="list-style:none; padding:0; margin:10px 0; max-height:200px; overflow-y:auto;">';
        for ($i = 1; $i <= 3; $i++) {
            echo '<li style="padding:5px; border:1px solid #ccc; border-radius:4px; margin-bottom:3px; text-align:left;">';
            echo '<b>Sample FFL Dealer ' . $i . '</b><br>123 Main St, City, ST 12345<br>(555) 555-000' . $i;
            echo '</li>';
        }
        echo '</ul>';

        // Map placeholder.
        if ($settings['include_map'] === '1') {
            echo '<div style="height:35vh; background:#e5e7eb; display:flex; align-items:center; justify-content:center; color:#6b7280; border-radius:4px;">';
            echo '<span>' . esc_html__('Mapbox Map Preview', 'ffl-funnels-addons') . '</span>';
            echo '</div>';
        }

        echo '</div></div>';
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function is_builder_context(): bool
    {
        return bricks_is_builder_call() || bricks_is_builder();
    }

    private function cart_has_ffl_items(): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return true; // Assume yes during admin/builder.
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product_id = absint($item['product_id'] ?? 0);
            if ($product_id && get_post_meta($product_id, 'automated_listing', true)) {
                return true;
            }
            // Also check the cockpit's _firearm_product meta.
            if ($product_id && get_post_meta($product_id, '_firearm_product', true) === 'yes') {
                return true;
            }
        }

        return false;
    }
}
