<?php
/**
 * FFL Checkout — Dealer Finder Bridge.
 *
 * Provides the [ffl_dealer_finder] shortcode that replicates the
 * g-FFL Checkout plugin's checkout widget output so it can be placed
 * inside a Bricks Builder custom checkout template.
 *
 * The g-FFL Checkout plugin normally hooks into
 * `woocommerce_before_checkout_form`, which does NOT fire inside
 * Bricks' Customer Details / Order Review elements.  This bridge
 * reads the same WP options and outputs the same HTML + JS
 * initialisation that the original plugin would.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Dealer_Bridge
{
    /**
     * Register the shortcode.
     */
    public static function init(): void
    {
        add_shortcode('ffl_dealer_finder', [__CLASS__, 'render']);
    }

    /* ── Shortcode Callback ──────────────────────────────────────────── */

    /**
     * Render the dealer finder widget.
     *
     * Mirrors the logic of G_ffl_Api_Public::ffl_woo_checkout() and
     * G_ffl_Api_Public::ffl_init_map() without requiring access to
     * the plugin instance.
     *
     * @param array|string $atts Shortcode attributes (unused).
     * @return string HTML output.
     */
    public static function render($atts = []): string
    {
        // Bail if not on checkout or if WooCommerce is unavailable.
        if (!function_exists('WC') || !WC()->cart) {
            return '';
        }

        // Require the g-FFL Checkout plugin to be active.
        if (!defined('G_FFL_API_VERSION')) {
            return '<p style="color:#b91c1c;font-weight:600;">'
                . esc_html__('[FFL Dealer Finder: g-FFL Checkout plugin is not active]', 'ffl-funnels-addons')
                . '</p>';
        }

        $api_key = esc_attr(get_option('ffl_api_key_option', ''));
        if ($api_key === '') {
            return '';
        }

        // ── Determine whether the FFL selector is needed ─────────────
        if (!self::requires_ffl_selector()) {
            return '';
        }

        // ── Build the widget output ──────────────────────────────────
        ob_start();
        self::render_ffl_map($api_key);
        self::render_document_upload();
        return ob_get_clean();
    }

    /* ── FFL Requirement Check ───────────────────────────────────────── */

    /**
     * Check if the current cart/request requires the FFL dealer selector.
     *
     * Mirrors G_ffl_Api_Public::ffl_woo_checkout() logic.
     */
    private static function requires_ffl_selector(): bool
    {
        // C&R override cookie suppresses everything.
        if (isset($_COOKIE['g_ffl_checkout_candr_override'])) {
            return false;
        }

        // Use the canonical check from g-FFL Checkout when available.
        if (function_exists('order_requires_ffl_selector')) {
            return (bool) order_requires_ffl_selector();
        }

        // Fallback: manual detection.
        $has_firearms = false;
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = wc_get_product($cart_item['data']->get_id());
            if (!$product) {
                continue;
            }

            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $parent  = wc_get_product($parent_id);
                $firearm = $parent ? $parent->get_meta('_firearm_product') : '';
            } else {
                $firearm = $product->get_meta('_firearm_product');
            }

            if ($firearm === 'yes') {
                $has_firearms = true;
                break;
            }
        }

        // Also check URL-based compliance triggers.
        $ammo_compliance = false;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['ammo_compliance']) && $_GET['ammo_compliance'] === '1') {
            $ammo_compliance = true;
        }
        if (!$ammo_compliance && function_exists('ffl_get_compliance_mode_from_request')) {
            $mode = ffl_get_compliance_mode_from_request();
            if (!empty($mode)) {
                $ammo_compliance = true;
            }
        }

        return $has_firearms || $ammo_compliance;
    }

    /* ── Render the FFL Map / Widget ─────────────────────────────────── */

    /**
     * Output the same HTML + JS variables that G_ffl_Api_Public::ffl_init_map() produces.
     *
     * The g-FFL Checkout JS files (ffl-widget.js, ffl-api-public.js) are
     * already enqueued by the plugin on checkout pages.  We only need to
     * provide the container div, the JS config variables, and call
     * initFFLJs().
     */
    private static function render_ffl_map(string $api_key): void
    {
        $hok = get_option('ffl_init_map_location', 'woocommerce_checkout_order_review');

        $wMes = get_option('ffl_checkout_message', '');
        if ($wMes === '') {
            $wMes = '<b>Federal law dictates that your online firearms purchase must be delivered to a federally licensed firearms dealer (FFL) before you can take possession.</b> This process is called a Transfer. Enter your zip code, radius, and FFL name (optional), then click the Find button to get a list of FFL dealers in your area. Select the FFL dealer you want the firearm shipped to. <b><u>Before Checking Out, Contact your selected FFL dealer to confirm they are currently accepting transfers</u></b>. You can also confirm transfer costs.';
        }

        $wMesAmmo = get_option('ffl_ammo_checkout_message', 'Ammo Compliance Message');
        $wMesNonFirearms = get_option('ffl_non_firearms_checkout_message', '');
        if ($wMesNonFirearms === '') {
            $wMesNonFirearms = '<b>Non-firearms shipments to your state require delivery to a federally licensed firearms dealer (FFL).</b> Enter your zip code, radius, and FFL name (optional), then click the Find button to get a list of FFL dealers in your area. Select the FFL dealer you want the items shipped to. <b><u>Before Checking Out, Contact your selected FFL dealer to confirm they are currently accepting transfers</u></b>.';
        }

        // Determine compliance mode.
        $compliance_mode = '';
        if (function_exists('ffl_get_compliance_mode_from_request')) {
            $compliance_mode = ffl_get_compliance_mode_from_request();
        }
        if (!$compliance_mode) {
            if (function_exists('requires_non_firearms_compliance') && requires_non_firearms_compliance()) {
                $compliance_mode = 'all_non_firearms';
            } elseif (function_exists('requires_ammunition_compliance') && requires_ammunition_compliance()) {
                $compliance_mode = 'ammunition';
            }
        }

        $is_ammo_compliance = !empty($compliance_mode);

        // Pick the correct ammo message variant.
        if ($compliance_mode === 'all_non_firearms') {
            $wMesAmmo = $wMesNonFirearms;
        } elseif ($compliance_mode === 'ammunition') {
            $ammo_msg = get_option('ffl_ammo_checkout_message', '');
            if ($ammo_msg !== '') {
                $wMesAmmo = $ammo_msg;
            }
        }

        $ffl_local_pickup   = get_option('ffl_local_pickup', '');
        $candr_override     = get_option('ffl_candr_override', '');
        $ffl_include_map    = get_option('ffl_include_map', 'Yes') !== 'No';
        $mixed_cart_support = get_option('ffl_mixed_cart_support', 'No') === 'Yes';
        $is_mixed_cart      = function_exists('order_contains_mixed_cart') ? order_contains_mixed_cart() : false;

        $customer_favorite_ffl = '';
        if (isset($_COOKIE['g_ffl_checkout_favorite_ffl'])) {
            $customer_favorite_ffl = sanitize_text_field($_COOKIE['g_ffl_checkout_favorite_ffl']);
        }

        // Configurable text & colors.
        $mixed_cart_notice_text           = get_option('ffl_mixed_cart_notice_text', 'You have both FFL and non-FFL items in your cart. <strong>FFL items will be shipped to your selected FFL dealer</strong>, while other items will be shipped to the shipping address you provide.');
        $first_last_name_notice_text      = get_option('ffl_first_last_name_notice_text', 'The First and Last name below help the FFL identify your shipment when it arrives at their location. Enter <b><u>your</u></b> First and Last Name.');
        $mixed_cart_notice_bg_color       = get_option('ffl_mixed_cart_notice_bg_color', '#F0F9FF');
        $mixed_cart_notice_text_color     = get_option('ffl_mixed_cart_notice_text_color', '#000000');
        $first_last_name_notice_bg_color  = get_option('ffl_first_last_name_notice_bg_color', '#FFFFF0');
        $first_last_name_notice_text_color = get_option('ffl_first_last_name_notice_text_color', '#000000');
        $ammo_checkout_msg_bg_color       = get_option('ffl_ammo_checkout_message_bg_color', '#FFFFF0');
        $ammo_checkout_msg_text_color     = get_option('ffl_ammo_checkout_message_text_color', '#000000');
        $checkout_msg_bg_color            = get_option('ffl_checkout_message_bg_color', '#FFFFF0');
        $checkout_msg_text_color          = get_option('ffl_checkout_message_text_color', '#000000');
        $restricted_states_message        = get_option('ffl_restricted_states_message', 'We apologize, but we are unable to ship firearms or ammunition to your state. Please contact us for more information.');

        // Determine plugin directory for assets (used by ffl-widget.js).
        $plugin_dir = '';
        if (defined('G_FFL_API_VERSION')) {
            // The g-FFL Checkout plugin's public class is in .../public/
            // ffl-widget.js references g_ffl_plugin_directory for logo/assets.
            $active_plugins = wp_get_active_and_valid_plugins();
            foreach ($active_plugins as $plugin_file) {
                if (strpos($plugin_file, 'g-ffl-checkout') !== false || strpos($plugin_file, 'g-ffl-api') !== false) {
                    $plugin_dir = plugin_dir_url($plugin_file) . 'public/';
                    break;
                }
            }
        }

        // Container div.
        echo '<div id="ffl_container"></div>';

        // JS variables + initialisation (mirrors ffl_init_map exactly).
        ?>
        <script type="text/javascript">
            let g_ffl_plugin_directory = <?php echo wp_json_encode($plugin_dir); ?>;
            let aKey = <?php echo wp_json_encode($api_key); ?>;
            let wMes = <?php echo wp_json_encode($wMes); ?>;
            let wMesAmmo = <?php echo wp_json_encode($wMesAmmo); ?>;
            let hok = <?php echo wp_json_encode($hok); ?>;
            let fflLocalPickup = <?php echo wp_json_encode($ffl_local_pickup); ?>;
            let candrOverride = <?php echo wp_json_encode($candr_override); ?>;
            let fflIncludeMap = <?php echo wp_json_encode($ffl_include_map ? '1' : ''); ?>;
            let mixedCartSupport = <?php echo $mixed_cart_support ? 'true' : 'false'; ?>;
            let isMixedCart = <?php echo $is_mixed_cart ? 'true' : 'false'; ?>;
            let isAmmoCompliance = <?php echo $is_ammo_compliance ? 'true' : 'false'; ?>;
            let complianceMode = <?php echo wp_json_encode($compliance_mode); ?>;
            let licenseSearchValue = "";
            let customerFavoriteFFL = <?php echo wp_json_encode($customer_favorite_ffl); ?>;
            let mixedCartNoticeText = <?php echo wp_json_encode($mixed_cart_notice_text); ?>;
            let firstLastNameNoticeText = <?php echo wp_json_encode($first_last_name_notice_text); ?>;
            let mixedCartNoticeBgColor = <?php echo wp_json_encode($mixed_cart_notice_bg_color); ?>;
            let mixedCartNoticeTextColor = <?php echo wp_json_encode($mixed_cart_notice_text_color); ?>;
            let firstLastNameNoticeBgColor = <?php echo wp_json_encode($first_last_name_notice_bg_color); ?>;
            let firstLastNameNoticeTextColor = <?php echo wp_json_encode($first_last_name_notice_text_color); ?>;
            let ammoCheckoutMessageBgColor = <?php echo wp_json_encode($ammo_checkout_msg_bg_color); ?>;
            let ammoCheckoutMessageTextColor = <?php echo wp_json_encode($ammo_checkout_msg_text_color); ?>;
            let checkoutMessageBgColor = <?php echo wp_json_encode($checkout_msg_bg_color); ?>;
            let checkoutMessageTextColor = <?php echo wp_json_encode($checkout_msg_text_color); ?>;
            let restrictedStatesMessage = <?php echo wp_json_encode($restricted_states_message); ?>;
            localStorage.removeItem("selectedFFL");
            document.addEventListener("DOMContentLoaded", function() {
                if (typeof initFFLJs === "function") {
                    initFFLJs(aKey, hok);
                }
            });
        </script>
        <?php
    }

    /* ── Document Upload Section ─────────────────────────────────────── */

    /**
     * Attempt to render the document upload section from g-FFL Checkout.
     *
     * The plugin's add_document_upload_section() is a public method on
     * G_ffl_Api_Public.  Since we can't access the instance directly we
     * fire the same hook it would have been attached to so the original
     * callback runs (it was registered during ffl_woo_checkout which
     * never fires in Bricks — so we trigger it manually here).
     */
    private static function render_document_upload(): void
    {
        // Document upload is only relevant when enabled.
        if (get_option('ffl_document_upload_enabled', 'No') !== 'Yes') {
            return;
        }

        // Check if cart has firearms or ammo.
        $has_firearms = function_exists('cart_contains_firearms') ? cart_contains_firearms() : false;
        $has_ammo     = function_exists('cart_contains_ammunition') ? cart_contains_ammunition() : false;

        if (!$has_firearms && !$has_ammo) {
            return;
        }

        // Try to find the registered callback for document upload.
        // The plugin registers: add_action('woocommerce_checkout_before_order_review', [$this, 'add_document_upload_section'], 15)
        // inside ffl_woo_checkout() — which didn't fire. So we look for
        // the method on any hooked G_ffl_Api_Public instance, or fire the hook.
        // Since ffl_woo_checkout never ran, the action was never added.
        // We need to find the public instance another way.

        // Strategy: the plugin's enqueue_scripts registered a callback on
        // wp_enqueue_scripts. We can find the G_ffl_Api_Public instance
        // from the registered hooks.
        $public_instance = self::find_plugin_public_instance();

        if ($public_instance && method_exists($public_instance, 'add_document_upload_section')) {
            $public_instance->add_document_upload_section();
        }
    }

    /**
     * Find the G_ffl_Api_Public instance from registered WP hooks.
     *
     * The plugin registers enqueue_scripts and enqueue_styles on
     * wp_enqueue_scripts using the public instance as the callback
     * object.  We can extract it from there.
     *
     * @return object|null The public instance or null.
     */
    private static function find_plugin_public_instance(): ?object
    {
        global $wp_filter;

        if (!isset($wp_filter['wp_enqueue_scripts'])) {
            return null;
        }

        foreach ($wp_filter['wp_enqueue_scripts']->callbacks as $priority => $hooks) {
            foreach ($hooks as $hook) {
                if (!is_array($hook['function']) || !is_object($hook['function'][0])) {
                    continue;
                }
                $obj = $hook['function'][0];
                if ($obj instanceof \G_ffl_Api_Public || get_class($obj) === 'G_ffl_Api_Public') {
                    return $obj;
                }
            }
        }

        return null;
    }
}
