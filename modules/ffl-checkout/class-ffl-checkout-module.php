<?php
/**
 * FFL Checkout Module — Entry Point.
 *
 * Extends FFLA_Module. Integrates Mapbox address autocomplete
 * and FFL Dealer Finder into the WooCommerce checkout.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFL_Checkout_Module extends FFLA_Module
{
    /** @var FFL_Checkout_Admin|null */
    private $admin;

    public function get_id(): string
    {
        return 'ffl-checkout';
    }

    public function get_name(): string
    {
        return 'FFL Checkout';
    }

    public function get_description(): string
    {
        return __('FFL Dealer Finder and Mapbox address autocomplete for WooCommerce checkout.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
    }

    /* ── Boot ──────────────────────────────────────────────────────── */

    public function boot(): void
    {
        $base = $this->get_path();

        // Frontend asset loading.
        require_once $base . 'includes/class-ffl-checkout-assets.php';
        FFL_Checkout_Assets::init();

        // AJAX handlers (search dealers, Mapbox token, C&R upload).
        require_once $base . 'includes/class-ffl-checkout-ajax.php';
        FFL_Checkout_Ajax::init();

        // Admin settings page.
        if (is_admin()) {
            require_once $base . 'admin/class-ffl-checkout-admin.php';
            $this->admin = new FFL_Checkout_Admin();
            $this->admin->init();
        }

        // ── Bricks Builder Integration ─────────────────────────────────────
        // Register the element at `init` priority 11 (Bricks convention).
        // The element's render() gracefully handles missing g-ffl-cockpit.
        if (defined('BRICKS_VERSION')) {
            require_once $base . 'frontend/class-ffl-checkout-bricks.php';
            $bricks = new FFL_Checkout_Bricks($base);
            $bricks->init();
        }

        // ── Save selected FFL dealer to order meta ─────────────────────────
        // Mirrors g-ffl-cockpit's approach: dealer data is posted via the
        // hidden `ffl_selected_dealer` field and stored as order meta.
        add_action('woocommerce_checkout_create_order', [$this, 'save_ffl_dealer_to_order'], 10, 2);
    }

    /**
     * Persist the customer-selected FFL dealer onto the WooCommerce order.
     *
     * The hidden checkout field `ffl_selected_dealer` is a JSON string
     * containing: ffl_id, business_name, license_number, premise_street,
     * premise_city, premise_state, premise_zip_code, phone.
     *
     * @param \WC_Order $order The new order.
     * @param array     $data  Posted checkout data.
     */
    public function save_ffl_dealer_to_order(\WC_Order $order, array $data): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $raw = isset($_POST['ffl_selected_dealer'])
            ? sanitize_text_field(wp_unslash($_POST['ffl_selected_dealer']))
            : '';

        if (empty($raw)) {
            return;
        }

        // Validate: must be JSON with an ffl_id.
        $dealer = json_decode($raw, true);
        if (!is_array($dealer) || empty($dealer['ffl_id'])) {
            return;
        }

        // Save the full dealer JSON blob as order meta.
        $order->update_meta_data('_ffl_selected_dealer', wp_json_encode($dealer));

        // Save individual readable fields for the cockpit admin order meta box.
        $order->update_meta_data('_ffl_dealer_id',      sanitize_text_field($dealer['ffl_id']));
        $order->update_meta_data('_ffl_dealer_name',    sanitize_text_field($dealer['business_name']    ?? ''));
        $order->update_meta_data('_ffl_dealer_license', sanitize_text_field($dealer['license_number']   ?? ''));
        $order->update_meta_data('_ffl_dealer_address', sanitize_text_field(
            implode(', ', array_filter([
                $dealer['premise_street']   ?? '',
                $dealer['premise_city']    ?? '',
                $dealer['premise_state']   ?? '',
                $dealer['premise_zip_code'] ?? '',
            ]))
        ));
        $order->update_meta_data('_ffl_dealer_phone', sanitize_text_field($dealer['phone'] ?? ''));
    }

    /* ── Activation / Deactivation ─────────────────────────────────── */

    public function activate(): void
    {
        // Set default options if they don't exist yet.
        if (false === get_option('ffl_checkout_settings')) {
            update_option('ffl_checkout_settings', [
                'radar_publishable_key' => '',
                'autocomplete_enabled'  => '0',
            ]);
        }
    }

    public function deactivate(): void
    {
        // No cleanup needed — keep settings for re-activation.
    }

    /* ── Admin Pages ───────────────────────────────────────────────── */

    public function get_admin_pages(): array
    {
        return [
            [
                'slug'  => 'ffla-ffl-checkout',
                'title' => __('FFL Checkout Settings', 'ffl-funnels-addons'),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
            ],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        if ($this->admin) {
            $this->admin->render_settings_page();
        }
    }
}
