<?php
/**
 * WooBooster — client page: WooCommerce → Bundles & Recommendations.
 *
 * White Label hides and blocks every page under the FFL Funnels menu for
 * client logins, so store owners manage bundles, recommendation rules, the
 * basic settings and analytics here instead. Agency tools stay in FFL Funnels:
 * the AI assistant and its keys, MCP, Debug Mode, Delete Data on Uninstall,
 * the Smart index tuning and rebuild, rule import/export/delete all,
 * diagnostics and docs.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WooBooster_Store_Page
{
    /** Nonce action of the client settings form. */
    const SETTINGS_NONCE = 'woobooster_save_store_settings';

    /** @var WooBooster_Admin */
    private $admin;

    /** @var string Page hook from add_submenu_page(). */
    private $hook = '';

    /** @var string The tab, rendered on load-{hook} before any output. */
    private $content = '';

    public function __construct(WooBooster_Admin $admin)
    {
        $this->admin = $admin;
    }

    public function init(): void
    {
        // After WooCommerce adds its menu (priority 9), before White Label
        // reorders and hides items (9999).
        add_action('admin_menu', array($this, 'register_menu'), 60);
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
    }

    /**
     * @return array<string, string> Tab key => label.
     */
    public static function tabs(): array
    {
        return array(
            'bundles'   => __('Bundles', 'ffl-funnels-addons'),
            'rules'     => __('Recommendation rules', 'ffl-funnels-addons'),
            'settings'  => __('Settings', 'ffl-funnels-addons'),
            'analytics' => __('Analytics', 'ffl-funnels-addons'),
        );
    }

    public static function current_tab(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        return array_key_exists($tab, self::tabs()) ? $tab : 'bundles';
    }

    public function register_menu(): void
    {
        $hook = add_submenu_page(
            'woocommerce',
            __('Bundles & Recommendations', 'ffl-funnels-addons'),
            __('Bundles & Recommendations', 'ffl-funnels-addons'),
            'manage_woocommerce',
            WooBooster_Admin::STORE_SLUG,
            array($this, 'render')
        );

        if ($hook) {
            $this->hook = (string) $hook;
            add_action('load-' . $this->hook, array($this, 'load'));
        }
    }

    /**
     * Runs before WordPress prints the admin header. Saves, deletes, bulk
     * actions and their redirects happen while the tab renders, so the tab
     * is rendered here into a buffer: a redirect can still send headers.
     */
    public function load(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'ffl-funnels-addons'), 403);
        }

        $this->handle_settings_save();

        ob_start();
        try {
            $this->render_tab(self::current_tab());
        } finally {
            $this->content = (string) ob_get_clean();
        }
    }

    public function enqueue(string $hook): void
    {
        if ('' === $this->hook || $hook !== $this->hook) {
            return;
        }

        // The shared FFL Funnels styles (ffla-admin) are enqueued by FFLA_Admin
        // for every page whose slug contains "ffla"; add WooBooster's own.
        $url = defined('WOOBOOSTER_URL') ? WOOBOOSTER_URL : FFLA_URL . 'modules/woobooster/';
        wp_enqueue_style('woobooster-module', $url . 'admin/css/woobooster-module.css', array('ffla-admin'), FFLA_VERSION);
        wp_enqueue_script('woobooster-module', $url . 'admin/js/woobooster-module.js', array('ffla-admin'), FFLA_VERSION, true);
        wp_add_inline_style('woobooster-module', self::inline_css());
    }

    public function render(): void
    {
        $current = self::current_tab();

        echo '<div class="wrap ffla-admin ffla-wb-store">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Bundles & Recommendations', 'ffl-funnels-addons') . '</h1>';
        echo '<p class="ffla-wb-store__intro">' . esc_html__('Bundles and the products recommended on your product pages.', 'ffl-funnels-addons') . '</p>';

        echo '<nav class="nav-tab-wrapper ffla-wb-store__tabs" aria-label="' . esc_attr__('Bundles & Recommendations sections', 'ffl-funnels-addons') . '">';
        foreach (self::tabs() as $key => $label) {
            $active = $key === $current;
            echo '<a href="' . esc_url(WooBooster_Admin::url($key)) . '" class="nav-tab' . ($active ? ' nav-tab-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        echo '</nav>';

        echo '<div class="ffla-wb-store__panel">';
        echo $this->content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered and escaped by the tab renderers.
        echo '</div>';
        echo '</div>';
    }

    private function render_tab(string $tab): void
    {
        switch ($tab) {
            case 'rules':
                $this->admin->render_rules_content();
                break;
            case 'settings':
                $this->render_settings();
                break;
            case 'analytics':
                $analytics = new WooBooster_Analytics();
                $analytics->render_dashboard();
                break;
            default:
                $this->admin->render_bundles_content();
        }
    }

    /**
     * The settings a store owner may change. Everything else in
     * woobooster_settings is left exactly as it is.
     */
    private function render_settings(): void
    {
        $options = get_option('woobooster_settings', array());
        $options = is_array($options) ? $options : array();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['settings-updated']) && 'true' === $_GET['settings-updated']) {
            FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
        }

        echo '<form method="post" action="' . esc_url(WooBooster_Admin::url('settings')) . '">';
        wp_nonce_field(self::SETTINGS_NONCE, 'woobooster_store_settings_nonce');

        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h2>' . esc_html__('Product page recommendations', 'ffl-funnels-addons') . '</h2></div>';
        echo '<div class="wb-card__body">';

        FFLA_Admin::render_toggle_field(
            __('Show recommendations', 'ffl-funnels-addons'),
            'woobooster_enabled',
            isset($options['enabled']) ? (string) $options['enabled'] : '1',
            __('Recommended products on product pages, from your rules and the Smart strategies below. When off, product pages show WooCommerce’s related products. Bundles and coupon rules keep working.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Section title', 'ffl-funnels-addons'),
            'woobooster_section_title',
            isset($options['section_title']) ? (string) $options['section_title'] : __('You May Also Like', 'ffl-funnels-addons'),
            __('Heading above the recommendations on standard product pages and in the [woobooster] shortcode. Page-builder templates use their own heading.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Hide out-of-stock products', 'ffl-funnels-addons'),
            'woobooster_exclude_outofstock',
            isset($options['exclude_outofstock']) ? (string) $options['exclude_outofstock'] : '1',
            __('Leave out-of-stock products out of recommendations, bundle suggestions and the shortcode.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-card" style="margin-top:24px;">';
        echo '<div class="wb-card__header"><h2>' . esc_html__('Smart recommendations', 'ffl-funnels-addons') . '</h2></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc" style="margin-bottom:20px;">' . esc_html__('Smart strategies pick products from your sales and your shoppers’ browsing. Use them as the source in a rule or a bundle. Similar Products is always available.', 'ffl-funnels-addons') . '</p>';

        FFLA_Admin::render_toggle_field(
            __('Bought together', 'ffl-funnels-addons'),
            'woobooster_smart_copurchase',
            !empty($options['smart_copurchase']) ? '1' : '0',
            __('Products customers buy together more often than chance, from your completed and processing orders. Updated once a day; the first update can take up to a day.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Trending', 'ffl-funnels-addons'),
            'woobooster_smart_trending',
            !empty($options['smart_trending']) ? '1' : '0',
            __('Best sellers of the last weeks, per category. Updated every 6 hours.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_toggle_field(
            __('Recently viewed', 'ffl-funnels-addons'),
            'woobooster_smart_recently_viewed',
            !empty($options['smart_recently_viewed']) ? '1' : '0',
            __('Products the shopper looked at recently, remembered in a browser cookie for 30 days.', 'ffl-funnels-addons')
        );

        echo '</div></div>';

        echo '<div class="wb-actions-bar">';
        echo '<button type="submit" class="wb-btn wb-btn--primary">' . esc_html__('Save Settings', 'ffl-funnels-addons') . '</button>';
        echo '</div>';
        echo '</form>';
    }

    /**
     * Save the client settings. Only these keys change; AI, MCP, debug,
     * uninstall and Smart tuning values are kept as they are.
     */
    private function handle_settings_save(): void
    {
        if (!isset($_POST['woobooster_store_settings_nonce'])) {
            return;
        }

        $nonce = sanitize_key(wp_unslash($_POST['woobooster_store_settings_nonce']));
        if (!wp_verify_nonce($nonce, self::SETTINGS_NONCE) || !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Your session expired. Reload the page and try again.', 'ffl-funnels-addons'), 403);
        }

        $existing = get_option('woobooster_settings', array());
        $existing = is_array($existing) ? $existing : array();

        $options = array_merge($existing, array(
            'enabled'               => isset($_POST['woobooster_enabled']) ? '1' : '0',
            'section_title'         => isset($_POST['woobooster_section_title']) ? sanitize_text_field(wp_unslash($_POST['woobooster_section_title'])) : '',
            'exclude_outofstock'    => isset($_POST['woobooster_exclude_outofstock']) ? '1' : '0',
            'smart_copurchase'      => isset($_POST['woobooster_smart_copurchase']) ? '1' : '0',
            'smart_trending'        => isset($_POST['woobooster_smart_trending']) ? '1' : '0',
            'smart_recently_viewed' => isset($_POST['woobooster_smart_recently_viewed']) ? '1' : '0',
        ));

        // Not autoloaded: the option can hold API keys.
        update_option('woobooster_settings', $options, false);

        WooBooster_Matcher::invalidate_recommendation_cache();
        WooBooster_Cron::schedule();

        wp_safe_redirect(WooBooster_Admin::url('settings', array('settings-updated' => 'true')));
        exit;
    }

    private static function inline_css(): string
    {
        // .ffla-admin pulls the FFL Funnels shell 20px left; a WooCommerce
        // page keeps WordPress's own .wrap spacing.
        return '.ffla-admin.ffla-wb-store{margin:10px 20px 0 2px;}'
            . '@media (max-width:782px){.ffla-admin.ffla-wb-store{margin:10px 12px 0 0;}}'
            . '.ffla-wb-store__intro{margin:4px 0 12px;color:#50575e;}'
            . '.ffla-wb-store__tabs{margin-bottom:20px;}'
            . '.ffla-wb-store__panel{max-width:1200px;}'
            . '.ffla-wb-store .wb-actions-bar{position:static;}';
    }
}
