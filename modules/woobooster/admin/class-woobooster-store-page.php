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
        wp_enqueue_style('woobooster-store', $url . 'admin/css/woobooster-store.css', array('woobooster-module'), self::asset_version('admin/css/woobooster-store.css'));
        wp_enqueue_script('woobooster-module', $url . 'admin/js/woobooster-module.js', array('ffla-admin'), FFLA_VERSION, true);
    }

    /** Version + file time, so a same-version ZIP update still refreshes the CSS. */
    private static function asset_version(string $relative): string
    {
        $file = dirname(__DIR__) . '/' . $relative;

        return FFLA_VERSION . (is_file($file) ? '.' . filemtime($file) : '');
    }

    public function render(): void
    {
        $current = self::current_tab();
        $options = get_option('woobooster_settings', array());
        $options = is_array($options) ? $options : array();
        $on      = !isset($options['enabled']) || '1' === (string) $options['enabled'];
        $support = self::support_url();

        echo '<div class="wrap ffla-admin ffla-wb-store">';

        // Header: what this page is for, live status, where to get help.
        echo '<header class="ffla-wbs-hero">';
        echo '<span class="ffla-wbs-hero__icon" aria-hidden="true">' . self::icon('sparkles', 22) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
        echo '<div class="ffla-wbs-hero__text">';
        echo '<h1 class="ffla-wbs-hero__title">' . esc_html__('Bundles & Recommendations', 'ffl-funnels-addons') . '</h1>';
        echo '<p class="ffla-wbs-hero__lead">' . esc_html__('Sell more in every order: offer products together at a better price, and show shoppers the right add-ons on each product page.', 'ffl-funnels-addons') . '</p>';
        echo '</div>';
        echo '<div class="ffla-wbs-hero__aside">';
        echo '<a class="ffla-wbs-status ' . ($on ? 'is-on' : 'is-off') . '" href="' . esc_url(WooBooster_Admin::url('settings')) . '">';
        echo '<span class="ffla-wbs-status__dot" aria-hidden="true"></span>';
        echo esc_html($on ? __('Recommendations on', 'ffl-funnels-addons') : __('Recommendations off', 'ffl-funnels-addons'));
        echo '</a>';
        if ('' !== $support) {
            echo '<a class="wb-btn wb-btn--subtle wb-btn--sm ffla-wbs-help" href="' . esc_url($support) . '" target="_blank" rel="noopener">';
            echo self::icon('help', 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
            echo esc_html__('Get help', 'ffl-funnels-addons');
            echo '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'ffl-funnels-addons') . '</span>';
            echo '</a>';
        }
        echo '</div>';
        echo '</header>';

        // Tabs.
        $counts = self::counts();
        $icons  = array('bundles' => 'package', 'rules' => 'wand', 'settings' => 'sliders', 'analytics' => 'chart');
        echo '<nav class="ffla-wbs-tabs" aria-label="' . esc_attr__('Bundles & Recommendations sections', 'ffl-funnels-addons') . '">';
        foreach (self::tabs() as $key => $label) {
            $active = $key === $current;
            echo '<a href="' . esc_url(WooBooster_Admin::url($key)) . '" class="ffla-wbs-tab' . ($active ? ' is-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>';
            echo self::icon($icons[$key], 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
            echo '<span>' . esc_html($label) . '</span>';
            if (isset($counts[$key])) {
                echo '<span class="ffla-wbs-tab__count">' . esc_html(number_format_i18n($counts[$key])) . '<span class="screen-reader-text"> ' . esc_html__('total', 'ffl-funnels-addons') . '</span></span>';
            }
            echo '</a>';
        }
        echo '</nav>';

        // On a list, explain what the tab does. Open until the first item exists.
        if (!isset($_GET['action'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $this->render_guide($current, isset($counts[$current]) && 0 === $counts[$current]);
        }

        echo '<div class="ffla-wb-store__panel">';
        echo $this->content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered and escaped by the tab renderers.
        echo '</div>';
        echo '</div>';
    }

    /**
     * Plain-language "how it works" for each tab.
     */
    private function render_guide(string $tab, bool $open): void
    {
        $guides = array(
            'bundles' => array(
                'title'   => __('How bundles work', 'ffl-funnels-addons'),
                'steps'   => array(
                    array(__('Pick the products', 'ffl-funnels-addons'), __('Choose items that go well together, like a rifle, a scope and two boxes of ammo. Mark an item Optional if shoppers may leave it out.', 'ffl-funnels-addons')),
                    array(__('Set the price', 'ffl-funnels-addons'), __('Take a percentage off each item, an amount off the whole set, or sell the set for one fixed price.', 'ffl-funnels-addons')),
                    array(__('Choose the product pages', 'ffl-funnels-addons'), __('Under Conditions, pick where the bundle appears: a category, a brand or caliber, or specific products.', 'ffl-funnels-addons')),
                ),
                'where'   => __('Shoppers see the bundle on the product page, tick the items they want and add them to the cart in one click, at the bundle price. If a product page does not show the bundle box yet, ask your FFL Funnels team to add it to your product template.', 'ffl-funnels-addons'),
            ),
            'rules' => array(
                'title'   => __('How recommendation rules work', 'ffl-funnels-addons'),
                'steps'   => array(
                    array(__('When a shopper views…', 'ffl-funnels-addons'), __('Pick the product pages the rule is for: a category such as Handguns, a tag, an attribute such as Caliber: 9mm, specific products, or every product.', 'ffl-funnels-addons')),
                    array(__('…recommend these products', 'ffl-funnels-addons'), __('Pick what to show: products from a category, products with the same caliber, best sellers, items often bought together, and more.', 'ffl-funnels-addons')),
                    array(__('Save it as active', 'ffl-funnels-addons'), __('The rule starts working right away. If two rules fit the same product, the one with the lower priority number is used.', 'ffl-funnels-addons')),
                ),
                'example' => __('Example: when a shopper views a 9mm handgun, show 4 best-selling boxes of 9mm ammo.', 'ffl-funnels-addons'),
                'where'   => __('Recommendations appear in the “You may also like” section of each product page (you can rename it under Settings). A rule can also apply a coupon automatically while the cart matches, for example 10% off with 3 boxes of ammo.', 'ffl-funnels-addons'),
            ),
            'settings' => array(
                'title'   => __('What these settings change', 'ffl-funnels-addons'),
                'steps'   => array(),
                'where'   => __('These switches affect every product page at once. Changes show on your store within a few minutes.', 'ffl-funnels-addons'),
            ),
            'analytics' => array(
                'title'   => __('How sales are counted', 'ffl-funnels-addons'),
                'steps'   => array(),
                'where'   => __('A sale counts here when a shopper adds a recommended product to the cart during the same visit and then buys it. Revenue is the item price before coupons, shipping and refunds, from completed, processing and ready-for-pickup orders. Figures update every 10 minutes.', 'ffl-funnels-addons'),
            ),
        );

        if (!isset($guides[$tab])) {
            return;
        }
        $guide = $guides[$tab];

        echo '<details class="ffla-wbs-guide"' . ($open ? ' open' : '') . '>';
        echo '<summary class="ffla-wbs-guide__summary">' . self::icon('info', 18) . '<span>' . esc_html($guide['title']) . '</span>' . self::icon('chevron', 16) . '</summary>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG, escaped title.
        echo '<div class="ffla-wbs-guide__body">';
        if (!empty($guide['steps'])) {
            echo '<ol class="ffla-wbs-steps">';
            foreach ($guide['steps'] as $i => $step) {
                echo '<li class="ffla-wbs-step"><span class="ffla-wbs-step__num" aria-hidden="true">' . (int) ($i + 1) . '</span>';
                echo '<div><strong class="ffla-wbs-step__title">' . esc_html($step[0]) . '</strong>';
                echo '<p class="ffla-wbs-step__text">' . esc_html($step[1]) . '</p></div></li>';
            }
            echo '</ol>';
        }
        if (!empty($guide['example'])) {
            echo '<p class="ffla-wbs-example">' . esc_html($guide['example']) . '</p>';
        }
        echo '<p class="ffla-wbs-where">' . self::icon('eye', 16) . '<span>' . esc_html($guide['where']) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG, escaped text.
        echo '</div>';
        echo '</details>';
    }

    /**
     * Totals shown on the Bundles and Recommendation rules tabs.
     *
     * @return array<string, int>
     */
    private static function counts(): array
    {
        $counts = array();
        try {
            if (class_exists('WooBooster_Bundle')) {
                $counts['bundles'] = (int) WooBooster_Bundle::count();
            }
            if (class_exists('WooBooster_Rule')) {
                $counts['rules'] = (int) WooBooster_Rule::count();
            }
        } catch (\Throwable $e) {
            return array();
        }

        return $counts;
    }

    /**
     * The support link set in White Label → Dashboard, if any.
     */
    private static function support_url(): string
    {
        if (!class_exists('White_Label_Settings')) {
            return '';
        }
        $url = White_Label_Settings::get('dashboard.links.support', '');

        return is_string($url) ? trim($url) : '';
    }

    /**
     * Small line icons (24px grid, stroke = currentColor).
     */
    public static function icon(string $name, int $size = 18): string
    {
        $paths = array(
            'sparkles' => '<path d="M12 3l1.9 4.6L18.5 9.5l-4.6 1.9L12 16l-1.9-4.6L5.5 9.5l4.6-1.9L12 3z"/><path d="M19 15l.8 1.7 1.7.8-1.7.8L19 20l-.8-1.7-1.7-.8 1.7-.8L19 15z"/>',
            'package'  => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
            'wand'     => '<path d="M15 4V2M15 10V8M11 6h2M17 6h2M4 20l10-10"/><path d="M13.5 6.5l4 4"/>',
            'sliders'  => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
            'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
            'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 115.4 1.8c-.8.6-1.5 1-1.5 2.2M12 17h.01"/>',
            'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
            'eye'      => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
            'chevron'  => '<path d="M6 9l6 6 6-6"/>',
            'cart'     => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.2a1 1 0 001 .8h9.6a1 1 0 001-.8L21 7H6"/>',
            'check'    => '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5L16 9.5"/>',
            'trend'    => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        );
        if (!isset($paths[$name])) {
            return '';
        }

        return '<svg class="ffla-wbs-icon" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
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
            . self::smart_status('copurchase', !empty($options['smart_copurchase']))
        );

        FFLA_Admin::render_toggle_field(
            __('Trending', 'ffl-funnels-addons'),
            'woobooster_smart_trending',
            !empty($options['smart_trending']) ? '1' : '0',
            __('Best sellers of the last weeks, per category. Updated every 6 hours.', 'ffl-funnels-addons')
            . self::smart_status('trending', !empty($options['smart_trending']))
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
     * "Status: updated …" for a Smart strategy that needs its build, so
     * the owner can see it is working.
     */
    private static function smart_status(string $type, bool $enabled): string
    {
        if (!$enabled) {
            return '';
        }

        $builds = get_option('woobooster_last_build', array());
        $build  = is_array($builds) && isset($builds[$type]) && is_array($builds[$type]) ? $builds[$type] : array();
        if (empty($build['date'])) {
            return ' ' . __('Status: waiting for the first update.', 'ffl-funnels-addons');
        }

        $when = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), (string) $build['date']);
        $count = 'trending' === $type ? (int) ($build['categories'] ?? 0) : (int) ($build['products'] ?? 0);
        if (0 === $count) {
            return ' ' . sprintf(
                /* translators: %s: date and time */
                __('Status: updated %s, but there are not enough orders yet to suggest anything.', 'ffl-funnels-addons'),
                $when
            );
        }

        return ' ' . sprintf(
            'trending' === $type
                /* translators: 1: date and time, 2: number of categories */
                ? _n('Status: updated %1$s, best sellers for %2$s category.', 'Status: updated %1$s, best sellers for %2$s categories.', $count, 'ffl-funnels-addons')
                /* translators: 1: date and time, 2: number of products */
                : _n('Status: updated %1$s, suggestions for %2$s product.', 'Status: updated %1$s, suggestions for %2$s products.', $count, 'ffl-funnels-addons'),
            $when,
            number_format_i18n($count)
        );
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
}
