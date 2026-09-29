<?php
/**
 * White Label — admin bar branding.
 *
 * WordPress core already renders the site's Site Icon in the admin bar: when a
 * Site Icon is set, wp_admin_bar_site_menu() prints it inside the "site-name"
 * node (see wp-includes/admin-bar.php). The stock "W" WordPress logo sitting to
 * its left is therefore redundant WordPress branding — and its dropdown only
 * links to WordPress.org (About / Documentation / Support / Feedback), which a
 * white-labeled client should not see.
 *
 * So we simply remove the wp-logo node. The site-name node (Site Icon + site
 * name) becomes the left-most branding. Native, no markup or CSS hacks.
 *
 * Applies in wp-admin and on the front-end toolbar alike, for all users.
 *
 * Also adds the FFL Funnels agency brand (logo + wordmark) to the very top of
 * the admin sidebar, linking out to the agency site.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Branding
{
    /** Agency site the sidebar brand links to. */
    private const AGENCY_URL = 'https://fflfunnels.com/';

    public function register_hooks(): void
    {
        // After core's wp_admin_bar_wp_menu() (priority 10) has added the node.
        add_action('admin_bar_menu', [$this, 'remove_wp_logo'], 11);

        // FFL Funnels brand at the top of the admin sidebar.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_sidebar_brand']);
        add_action('adminmenu', [$this, 'render_sidebar_brand']);

        // Replace the wp-admin footer credit ("Thank you for creating with
        // WordPress." — and the variants WooCommerce/other plugins inject) with
        // the agency's message. PHP_INT_MAX so ours is the last filter to run and
        // always wins. Shown to everyone, staff included.
        add_filter('admin_footer_text', [$this, 'footer_text'], PHP_INT_MAX);
    }

    /**
     * The agency footer credit shown on every admin page. Returns limited HTML
     * (core echoes this filter's value into #footer-left unescaped), so the only
     * dynamic parts are escaped here. Filterable for per-site overrides.
     */
    public function footer_text(): string
    {
        $link = '<a href="' . esc_url(self::AGENCY_URL) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html__('FFL Funnels', 'ffl-funnels-addons') . '</a>';

        /* translators: %s: linked "FFL Funnels" agency name. */
        $text = sprintf(esc_html__('Thank you for growing with %s.', 'ffl-funnels-addons'), $link);

        /**
         * Filter the white-label admin footer credit.
         *
         * @param string $text The footer HTML (dynamic parts already escaped).
         */
        return (string) apply_filters('ffla_wl_admin_footer_text', $text);
    }

    /**
     * Load the sidebar-brand stylesheet. The markup itself is rendered
     * server-side (see render_sidebar_brand()).
     */
    public function enqueue_sidebar_brand(): void
    {
        wp_enqueue_style(
            'ffla-wl-brand',
            FFLA_URL . 'modules/white-label/admin/css/white-label-brand.css',
            [],
            FFLA_VERSION
        );
    }

    /**
     * Render the FFL Funnels brand (logo + wordmark) and move it to the top of
     * the sidebar.
     *
     * WordPress prints no hook inside/above `<ul id="adminmenu">`; the only menu
     * hook, `adminmenu`, fires just after the `#adminmenumain` container. So we
     * output the real markup here (server-side, a genuine link) and relocate it
     * to the top of `#adminmenuwrap` with a tiny synchronous inline script. That
     * script runs during parse — before first paint — so there is no layout
     * shift (CLS). If scripting is off, the brand simply stays where it rendered.
     */
    public function render_sidebar_brand(): void
    {
        $logo  = FFLA_URL . 'modules/white-label/admin/images/ffl-funnels-logo.png';
        $label = __('FFL Funnels', 'ffl-funnels-addons');
        ?>
        <a id="ffla-wl-brand" class="ffla-wl-brand" href="<?php echo esc_url(self::AGENCY_URL); ?>"
            target="_blank" rel="noopener noreferrer">
            <img class="ffla-wl-brand__logo" src="<?php echo esc_url($logo); ?>" alt="" aria-hidden="true">
            <span class="ffla-wl-brand__text"><?php echo esc_html($label); ?></span>
        </a>
        <script>
        (function () {
            var brand = document.getElementById('ffla-wl-brand');
            var wrap = document.getElementById('adminmenuwrap');
            var menu = document.getElementById('adminmenu');
            if (brand && wrap && menu && brand.parentNode !== wrap) {
                wrap.insertBefore(brand, menu);
            }
        })();
        </script>
        <?php
    }

    /**
     * Remove the WordPress "W" logo node (and its WordPress.org submenu).
     *
     * The Site Icon itself is rendered by core in the site-name node; its styling
     * (e.g. rounding) lives in admin/css/white-label-theme.css.
     */
    public function remove_wp_logo(WP_Admin_Bar $bar): void
    {
        $bar->remove_node('wp-logo');
    }
}
