<?php
/**
 * FFL Checkout — Bricks Builder Integration.
 *
 * Registers the "FFL Dealer Finder" custom element in Bricks Builder.
 * Only loaded when both BRICKS_VERSION and G_FFL_COCKPIT_VERSION are defined.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Bricks
{
    /**
     * Absolute path to the module root (trailing slash).
     *
     * @var string
     */
    private $module_path;

    /**
     * @param string $module_path Absolute path to the ffl-checkout module directory.
     */
    public function __construct(string $module_path)
    {
        $this->module_path = trailingslashit($module_path);
    }

    /**
     * Wire up Bricks hooks.
     *
     * Called from the module's boot() inside a plugins_loaded callback (priority 20).
     */
    public function init(): void
    {
        // Register the element file at Bricks' expected priority.
        add_action('init', [$this, 'register_elements'], 11);

        // Fallback: ensure dealer-finder assets are enqueued on the frontend
        // even when Bricks' element-level enqueue_scripts() doesn't fire
        // (e.g. Bricks native checkout without WooCommerce shortcode).
        add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue_assets'], 30);
    }

    /**
     * Register the FFL Dealer Finder element with Bricks.
     */
    public function register_elements(): void
    {
        if (!class_exists('\Bricks\Elements')) {
            return;
        }

        $file = $this->module_path . 'elements/ffl-dealer-finder-element.php';
        if (file_exists($file)) {
            \Bricks\Elements::register_element($file);
        }
    }

    /**
     * Enqueue dealer-finder assets if the element is present on the current page.
     *
     * Bricks stores element data in post meta. The element can live in:
     *  - The page's own content (BRICKS_DB_PAGE_CONTENT)
     *  - A Bricks template applied to the page (header, content, footer)
     *  - A nested template referenced inside another template
     *
     * We check all of these to ensure assets load even when Bricks'
     * element-level enqueue_scripts() doesn't fire (e.g. native checkout).
     */
    public function maybe_enqueue_assets(): void
    {
        // Skip if already enqueued (Bricks element-level enqueue worked).
        if (wp_script_is('fflDealerFinder', 'enqueued')) {
            return;
        }

        // Only run on singular pages (where Bricks content lives).
        if (!is_singular()) {
            return;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return;
        }

        if (!$this->page_has_dealer_finder($post_id)) {
            return;
        }

        $module_url = FFLA_URL . 'modules/ffl-checkout/';
        $settings   = wp_parse_args(get_option('ffl_checkout_settings', []), [
            'include_map' => '1',
        ]);

        // Mapbox GL JS + CSS.
        if ($settings['include_map'] === '1') {
            wp_enqueue_style('mapbox-gl', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css', [], '3.3.0');
            wp_enqueue_script('mapbox-gl', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js', [], '3.3.0', false);
        }

        // Widget CSS.
        wp_enqueue_style('ffl-dealer-finder', $module_url . 'assets/css/ffl-dealer-finder.css', [], FFLA_VERSION);

        // Widget JS — no hard dependency on mapbox-gl.
        wp_enqueue_script('fflDealerFinder', $module_url . 'assets/js/ffl-dealer-finder.js', [], FFLA_VERSION, true);

        // AJAX config.
        $is_builder = false;
        if (class_exists('\Bricks\Database')) {
            $is_builder = !empty(\Bricks\Database::$is_builder_call);
        }

        wp_localize_script('fflDealerFinder', 'fflDealerFinderConfig', [
            'ajaxUrl'            => admin_url('admin-ajax.php'),
            'nonce'              => wp_create_nonce('ffl_checkout_nonce'),
            'includeMap'         => $settings['include_map'],
            'isBuilder'          => $is_builder ? '1' : '0',
            'cartHasFflItems'    => $this->cart_has_ffl_items() ? '1' : '0',
            'localPickupLicense' => $settings['local_pickup_license'] ?? '',
            'candrEnabled'       => $settings['candr_enabled'] ?? '0',
            'blacklist'          => array_keys(get_option('ffl_blacklist', [])),
        ]);
    }

    /**
     * Check if the current page (or its Bricks templates) contains our element.
     */
    private function page_has_dealer_finder(int $post_id): bool
    {
        // 1. Check the page's own Bricks content.
        $page_data = get_post_meta($post_id, BRICKS_DB_PAGE_CONTENT, true);
        if ($this->bricks_data_has_element($page_data)) {
            return true;
        }

        // 2. Check Bricks templates assigned to this page (header, content, footer).
        //    Bricks\Helpers::get_bricks_data() resolves the correct template for each area.
        if (class_exists('\Bricks\Helpers')) {
            foreach (['header', 'content', 'footer'] as $area) {
                $area_data = \Bricks\Helpers::get_bricks_data($post_id, $area);
                if ($this->bricks_data_has_element($area_data)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Search a Bricks elements array for ffl-dealer-finder (including nested templates).
     */
    private function bricks_data_has_element($bricks_data): bool
    {
        if (empty($bricks_data) || !is_array($bricks_data)) {
            return false;
        }

        foreach ($bricks_data as $element) {
            if (!isset($element['name'])) {
                continue;
            }

            // Direct match.
            if ($element['name'] === 'ffl-dealer-finder') {
                return true;
            }

            // Nested template reference — check its content too.
            if ($element['name'] === 'template' && !empty($element['settings']['template'])) {
                $tpl_id   = absint($element['settings']['template']);
                $tpl_data = get_post_meta($tpl_id, BRICKS_DB_PAGE_CONTENT, true);
                if ($this->bricks_data_has_element($tpl_data)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the WooCommerce cart contains FFL-eligible items.
     */
    private function cart_has_ffl_items(): bool
    {
        if (!function_exists('WC') || !WC()->cart) {
            return true; // Assume yes if cart isn't available yet.
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product_id = absint($item['product_id'] ?? 0);
            if ($product_id && get_post_meta($product_id, 'automated_listing', true)) {
                return true;
            }
            if ($product_id && get_post_meta($product_id, '_firearm_product', true) === 'yes') {
                return true;
            }
        }

        return false;
    }
}
