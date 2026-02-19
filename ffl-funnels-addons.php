<?php
/**
 * Plugin Name:       FFL Funnels Addons
 * Plugin URI:        https://github.com/aaruca/ffl-funnels-addons
 * Description:       Modular WooCommerce toolkit — WooBooster, Wishlist, and Doofinder Sync in a single unified plugin.
 * Version:           1.0.8
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ale Aruca
 * Author URI:        https://github.com/aaruca
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ffl-funnels-addons
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants.
define('FFLA_VERSION', '1.0.8');


define('FFLA_DB_VERSION', '1.0.0');
define('FFLA_FILE', __FILE__);
define('FFLA_PATH', plugin_dir_path(__FILE__));
define('FFLA_URL', plugin_dir_url(__FILE__));
define('FFLA_BASENAME', plugin_basename(__FILE__));

/**
 * Main FFL Funnels Addons class.
 */
final class FFL_Funnels_Addons
{
    /** @var FFL_Funnels_Addons */
    protected static $_instance = null;

    /** @var bool */
    private $dependencies_met = false;

    /** @var FFLA_Module_Registry */
    private $registry;

    /**
     * Main instance.
     */
    public static function instance(): self
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct()
    {
        $this->includes();
        $this->register_modules();
        $this->define_compat_constants();
        $this->init_hooks();
    }

    /**
     * Include core files.
     */
    private function includes(): void
    {
        require_once FFLA_PATH . 'includes/class-ffla-module.php';
        require_once FFLA_PATH . 'includes/class-ffla-module-registry.php';
        require_once FFLA_PATH . 'includes/class-ffla-conflict-checker.php';
        require_once FFLA_PATH . 'includes/class-ffla-updater.php';

        if (is_admin()) {
            require_once FFLA_PATH . 'admin/class-ffla-admin.php';
            require_once FFLA_PATH . 'admin/class-ffla-dashboard.php';
        }

        // Module entry points (always loaded so they can be registered).
        require_once FFLA_PATH . 'modules/woobooster/class-woobooster-module.php';
        require_once FFLA_PATH . 'modules/wishlist/class-wishlist-module.php';
        require_once FFLA_PATH . 'modules/doofinder-sync/class-doofinder-module.php';
    }

    /**
     * Register all available modules.
     */
    private function register_modules(): void
    {
        $this->registry = FFLA_Module_Registry::instance();
        $this->registry->register(new WooBooster_Module());
        $this->registry->register(new Wishlist_Module());
        $this->registry->register(new Doofinder_Module());
    }

    /**
     * Define backward-compatibility constants for active modules.
     */
    private function define_compat_constants(): void
    {
        // WooBooster compat constants.
        if ($this->registry->is_active('woobooster')) {
            $wb_module = $this->registry->get('woobooster');
            if (!defined('WOOBOOSTER_VERSION')) {
                define('WOOBOOSTER_VERSION', FFLA_VERSION);
            }
            if (!defined('WOOBOOSTER_DB_VERSION')) {
                define('WOOBOOSTER_DB_VERSION', '1.4.0');
            }
            if (!defined('WOOBOOSTER_FILE')) {
                define('WOOBOOSTER_FILE', FFLA_FILE);
            }
            if (!defined('WOOBOOSTER_PATH')) {
                define('WOOBOOSTER_PATH', $wb_module->get_path());
            }
            if (!defined('WOOBOOSTER_URL')) {
                define('WOOBOOSTER_URL', $wb_module->get_url());
            }
            if (!defined('WOOBOOSTER_BASENAME')) {
                define('WOOBOOSTER_BASENAME', FFLA_BASENAME);
            }
        }

        // Wishlist compat constants.
        if ($this->registry->is_active('wishlist')) {
            $wl_module = $this->registry->get('wishlist');
            if (!defined('ALG_WISHLIST_VERSION')) {
                define('ALG_WISHLIST_VERSION', FFLA_VERSION);
            }
            if (!defined('ALG_WISHLIST_FILE')) {
                define('ALG_WISHLIST_FILE', FFLA_FILE);
            }
            if (!defined('ALG_WISHLIST_PATH')) {
                define('ALG_WISHLIST_PATH', $wl_module->get_path());
            }
            if (!defined('ALG_WISHLIST_URL')) {
                define('ALG_WISHLIST_URL', $wl_module->get_url());
            }
            if (!defined('ALG_WISHLIST_BASENAME')) {
                define('ALG_WISHLIST_BASENAME', FFLA_BASENAME);
            }
        }

        // Doofinder Sync compat constants.
        if ($this->registry->is_active('doofinder-sync')) {
            if (!defined('DSYNC_PREFIX')) {
                define('DSYNC_PREFIX', 'dsync_');
            }
            if (!defined('DSYNC_PLUGIN_BASENAME')) {
                define('DSYNC_PLUGIN_BASENAME', FFLA_BASENAME);
            }
            if (!defined('DSYNC_PLUGIN_SLUG')) {
                define('DSYNC_PLUGIN_SLUG', 'ffl-funnels-addons');
            }
        }
    }

    /**
     * Hook into WordPress.
     */
    private function init_hooks(): void
    {
        register_activation_hook(FFLA_FILE, [$this, 'activate']);
        register_deactivation_hook(FFLA_FILE, [$this, 'deactivate']);

        add_action('plugins_loaded', [$this, 'on_plugins_loaded']);
        add_action('init', [$this, 'init'], 0);
        add_action('admin_notices', [$this, 'dependency_notices']);

        // HPOS Compatibility.
        add_action('before_woocommerce_init', function () {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', FFLA_FILE, true);
            }
        });
    }

    /**
     * Plugin activation.
     *
     * Wrapped in output buffering to prevent any accidental output from
     * triggering WordPress's "unexpected output during activation" warning.
     */
    public function activate(): void
    {
        ob_start();

        // Create the active modules option if it doesn't exist.
        if (false === get_option('ffla_active_modules')) {
            // Auto-detect existing plugin data to pre-activate modules.
            $auto_active = [];

            global $wpdb;

            // Check for existing WooBooster data.
            $wb_table = $wpdb->prefix . 'woobooster_rules';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wb_table)) === $wb_table) {
                $auto_active[] = 'woobooster';
            }

            // Check for existing Wishlist data.
            $wl_table = $wpdb->prefix . 'alg_wishlists';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $wl_table)) === $wl_table) {
                $auto_active[] = 'wishlist';
            }

            update_option('ffla_active_modules', $auto_active);
        }

        // Ensure backward compatibility constants are defined for newly activated modules.
        // This handles cases where activation happens after __construct (where define_compat_constants runs).
        $this->define_compat_constants();

        // Run activation for any pre-activated modules.
        $registry = FFLA_Module_Registry::instance();
        foreach ($registry->get_active() as $module) {
            $module->activate();
        }

        ob_end_clean();
    }


    /**
     * Plugin deactivation.
     */
    public function deactivate(): void
    {
        foreach ($this->registry->get_active() as $module) {
            $module->deactivate();
        }
    }

    /**
     * Actions to run on plugins_loaded.
     */
    public function on_plugins_loaded(): void
    {
        $this->check_dependencies();
        load_plugin_textdomain('ffl-funnels-addons', false, dirname(FFLA_BASENAME) . '/languages/');

        // Conflict checker.
        FFLA_Conflict_Checker::init();
    }

    /**
     * Init — run on the 'init' hook (priority 0).
     */
    public function init(): void
    {
        if (!$this->dependencies_met) {
            return;
        }

        // Admin shell.
        if (is_admin()) {
            $admin = new FFLA_Admin($this->registry);
            $admin->init();

            // Unified updater.
            $updater = new FFLA_Updater(
                'aaruca',
                'ffl-funnels-addons',
                FFLA_BASENAME,
                FFLA_VERSION
            );
            $updater->init();
        }

        // Boot active modules.
        $this->registry->boot_active_modules();
    }

    /**
     * Check WooCommerce dependency.
     */
    private function check_dependencies(): void
    {
        $this->dependencies_met = class_exists('WooCommerce');
    }

    /**
     * Admin notice if WooCommerce is missing.
     */
    public function dependency_notices(): void
    {
        if (!class_exists('WooCommerce')) {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                wp_kses_post(
                    sprintf(
                        esc_html__('%s requires WooCommerce to be installed and active.', 'ffl-funnels-addons'),
                        '<strong>FFL Funnels Addons</strong>'
                    )
                )
            );
        }
    }

    /**
     * Request type helper.
     */
    public function is_request(string $type): bool
    {
        switch ($type) {
            case 'admin':
                return is_admin();
            case 'ajax':
                return defined('DOING_AJAX');
            case 'cron':
                return defined('DOING_CRON');
            case 'frontend':
                return (!is_admin() || defined('DOING_AJAX')) && !defined('DOING_CRON');
        }
        return false;
    }

    /**
     * Get the module registry.
     */
    public function registry(): FFLA_Module_Registry
    {
        return $this->registry;
    }
}

/**
 * Main instance of FFL Funnels Addons.
 */
function ffl_funnels_addons(): FFL_Funnels_Addons
{
    return FFL_Funnels_Addons::instance();
}

$GLOBALS['ffl_funnels_addons'] = ffl_funnels_addons();

// Backward-compatible helper for WooBooster options.
if (!function_exists('woobooster_get_option')) {
    function woobooster_get_option($key, $default = '')
    {
        $options = get_option('woobooster_settings', []);
        return isset($options[$key]) ? $options[$key] : $default;
    }
}
