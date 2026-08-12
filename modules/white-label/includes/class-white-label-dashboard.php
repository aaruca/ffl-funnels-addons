<?php
/**
 * White Label — custom dashboard.
 *
 * Takes over /wp-admin/index.php: strips all dashboard widgets (default and
 * third-party), removes the welcome panel, Screen Options and Help buttons,
 * suppresses admin notices, and renders the branded client dashboard in their
 * place. Only active when enabled in the Dashboard settings tab.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Dashboard
{
    const AJAX_ACTION = 'ffla_wl_dashboard_analytics';
    const AJAX_NONCE  = 'ffla_wl_dashboard_analytics';
    const SOURCE_META = 'ffla_wl_dashboard_analytics_source';
    const RANGE_META  = 'ffla_wl_dashboard_analytics_range';

    /** @var array<string, mixed> The 'dashboard' settings sub-array. */
    private $settings;

    /**
     * @param array<string, mixed> $dashboard
     */
    public function __construct(array $dashboard)
    {
        $this->settings = $dashboard;
    }

    public function register_hooks(): void
    {
        if (empty($this->settings['enabled'])) {
            return;
        }

        // Render our dashboard in place of the welcome panel.
        add_action('welcome_panel', [$this, 'render']);

        // Strip widgets AND remove the default welcome panel here — this fires
        // during the dashboard load, after admin-filters.php has registered
        // wp_welcome_panel (an early remove_action on init would miss it).
        add_action('wp_dashboard_setup', [$this, 'strip_widgets'], 99999);

        // Screen-level cleanup + notice/footer suppression, dashboard only.
        add_action('current_screen', [$this, 'clean_screen']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_analytics']);
    }

    /**
     * Empty the dashboard meta boxes so wp_dashboard() renders nothing, and
     * remove WordPress's own welcome panel.
     */
    public function strip_widgets(): void
    {
        $GLOBALS['wp_meta_boxes']['dashboard'] = [];
        remove_action('welcome_panel', 'wp_welcome_panel');
    }

    /**
     * On the dashboard screen: drop help tabs, hide Screen Options, and suppress
     * admin notices.
     *
     * @param WP_Screen $screen
     */
    public function clean_screen($screen): void
    {
        if (!$screen instanceof WP_Screen || 'dashboard' !== $screen->id) {
            return;
        }

        $screen->remove_help_tabs();
        add_filter('screen_options_show_screen', '__return_false');

        // Blank the admin footer credit + version on the dashboard.
        add_filter('admin_footer_text', '__return_empty_string', 99);
        add_filter('update_footer', '__return_empty_string', 99);

        // Notices are printed later, inside .wrap; remove them just before.
        add_action('admin_head', static function () {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            remove_all_actions('user_admin_notices');
        }, 1);
    }

    public function enqueue($hook): void
    {
        if ('index.php' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'ffla-wl-dashboard',
            FFLA_URL . 'modules/white-label/admin/css/white-label-dashboard.css',
            [],
            FFLA_VERSION
        );

        // Chart.js is already vendored in the plugin (woobooster module).
        wp_enqueue_script(
            'ffla-wl-chartjs',
            FFLA_URL . 'modules/woobooster/assets/lib/chart.umd.js',
            [],
            FFLA_VERSION,
            true
        );
        wp_enqueue_script(
            'ffla-wl-dashboard',
            FFLA_URL . 'modules/white-label/admin/js/white-label-dashboard.js',
            ['ffla-wl-chartjs'],
            FFLA_VERSION,
            true
        );

        wp_localize_script('ffla-wl-dashboard', 'fflaWhiteLabelDashboard', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'action'        => self::AJAX_ACTION,
            'nonce'         => wp_create_nonce(self::AJAX_NONCE),
            'initialSource' => $this->analytics_source(),
            'initialRange'  => $this->analytics_range(),
            'forceRefresh'  => $this->should_force_refresh(),
            'strings'       => [
                'loading'          => __('Loading analytics…', 'ffl-funnels-addons'),
                'loadError'        => __('Analytics could not be loaded. Please try again.', 'ffl-funnels-addons'),
                'previousPeriod'   => __('vs. previous period', 'ffl-funnels-addons'),
                'openReport'       => __('Open full report', 'ffl-funnels-addons'),
                'openSettings'     => __('Open Rank Math settings', 'ffl-funnels-addons'),
                'trend'            => __('Traffic trend', 'ffl-funnels-addons'),
                'searchTraffic'    => __('Organic search traffic', 'ffl-funnels-addons'),
                'organicClicks'    => __('Organic clicks', 'ffl-funnels-addons'),
                'topLandingPages'  => __('Top organic landing pages', 'ffl-funnels-addons'),
                'landingPage'      => __('Landing page', 'ffl-funnels-addons'),
                'pageviews'        => __('Traffic', 'ffl-funnels-addons'),
                'clicks'           => __('Clicks', 'ffl-funnels-addons'),
                'impressions'      => __('Impressions', 'ffl-funnels-addons'),
                'ctr'              => __('CTR', 'ffl-funnels-addons'),
                'movement'         => __('Organic movement', 'ffl-funnels-addons'),
                'winners'          => __('Winning pages', 'ffl-funnels-addons'),
                'losers'           => __('Losing pages', 'ffl-funnels-addons'),
                'clickChange'      => __('clicks', 'ffl-funnels-addons'),
                'noMovement'       => __('No meaningful page movement in this period.', 'ffl-funnels-addons'),
                'searchFunnel'     => __('On-site search funnel', 'ffl-funnels-addons'),
                'topSearchTerms'   => __('Top search terms', 'ffl-funnels-addons'),
                'searchTerm'       => __('Search term', 'ffl-funnels-addons'),
                'searches'         => __('Searches', 'ffl-funnels-addons'),
                'productClicks'    => __('Product clicks', 'ffl-funnels-addons'),
                'noSearchTerms'    => __('No search terms to show for this period.', 'ffl-funnels-addons'),
            ],
        ]);
    }

    /**
     * Lazy-load one analytics provider and remember the user's selection.
     */
    public function ajax_analytics(): void
    {
        if (!check_ajax_referer(self::AJAX_NONCE, 'nonce', false) || !current_user_can('read')) {
            wp_send_json_error(['message' => __('You do not have permission to view analytics.', 'ffl-funnels-addons')], 403);
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : 'google';
        if (!in_array($source, ['google', 'snapfind'], true)) {
            $source = 'google';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $range = isset($_POST['range']) ? absint(wp_unslash($_POST['range'])) : 30;
        if (!in_array($range, [7, 30, 90], true)) {
            $range = 30;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $force = isset($_POST['force']) && '1' === sanitize_text_field(wp_unslash($_POST['force']));

        update_user_meta(get_current_user_id(), self::SOURCE_META, $source);
        update_user_meta(get_current_user_id(), self::RANGE_META, $range);

        require_once __DIR__ . '/class-white-label-dashboard-data.php';
        wp_send_json_success(White_Label_Dashboard_Data::analytics($source, $range, $force));
    }

    /**
     * Render the dashboard (called in place of the welcome panel).
     */
    public function render(): void
    {
        require_once __DIR__ . '/class-white-label-dashboard-data.php';

        $from = gmdate('Y-m-d', strtotime('-29 days'));
        $to   = gmdate('Y-m-d');

        // A nonce-guarded ?ffla_refresh=1 request bypasses the cache, pulls fresh
        // numbers from the database, and updates the stored cache.
        $force = $this->should_force_refresh();

        $view_data = [
            'data'             => White_Label_Dashboard_Data::get($from, $to, $force),
            'links'            => $this->links(),
            'user'             => wp_get_current_user(),
            'from'             => $from,
            'to'               => $to,
            'analytics_source' => $this->analytics_source(),
            'analytics_range'  => $this->analytics_range(),
            'refresh_url'      => wp_nonce_url(
                add_query_arg('ffla_refresh', '1', admin_url('index.php')),
                'ffla_wl_refresh'
            ),
        ];

        $view = dirname(__DIR__) . '/admin/views/dashboard.php';
        if (!is_readable($view)) {
            return;
        }

        (static function () use ($view, $view_data) {
            // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- controlled view data.
            extract($view_data);
            include $view;
        })();
    }

    /**
     * Whether the current dashboard request carries a valid refresh nonce.
     */
    private function should_force_refresh(): bool
    {
        return isset($_GET['ffla_refresh'], $_GET['_wpnonce'])
            && '1' === sanitize_text_field(wp_unslash($_GET['ffla_refresh']))
            && (bool) wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'ffla_wl_refresh');
    }

    private function analytics_source(): string
    {
        $source = (string) get_user_meta(get_current_user_id(), self::SOURCE_META, true);

        return in_array($source, ['google', 'snapfind'], true) ? $source : 'google';
    }

    private function analytics_range(): int
    {
        $range = (int) get_user_meta(get_current_user_id(), self::RANGE_META, true);

        return in_array($range, [7, 30, 90], true) ? $range : 30;
    }

    /**
     * The four quick-link URLs, filtered to those that are set.
     *
     * @return array<string, string>
     */
    private function links(): array
    {
        $links = isset($this->settings['links']) && is_array($this->settings['links']) ? $this->settings['links'] : [];

        return [
            'support'        => (string) ($links['support'] ?? ''),
            'knowledge_base' => (string) ($links['knowledge_base'] ?? ''),
            'cockpit'        => (string) ($links['cockpit'] ?? ''),
            'command_center' => (string) ($links['command_center'] ?? ''),
        ];
    }
}
