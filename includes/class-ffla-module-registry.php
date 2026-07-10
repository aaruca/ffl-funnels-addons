<?php
/**
 * Module Registry — central hub for registering, activating, and booting modules.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Module_Registry
{
    /** @var FFLA_Module_Registry */
    private static $_instance = null;

    /** @var FFLA_Module[] All registered modules keyed by id. */
    private $modules = [];

    /** @var string[] IDs of active modules. */
    private $active_ids = [];

    public static function instance(): self
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct()
    {
        $this->active_ids = get_option('ffla_active_modules', []);
        if (!is_array($this->active_ids)) {
            $this->active_ids = [];
        }
    }

    /**
     * Register a module.
     */
    public function register(FFLA_Module $module): void
    {
        $this->modules[$module->get_id()] = $module;
    }

    /**
     * Get all registered modules.
     *
     * @return FFLA_Module[]
     */
    public function get_all(): array
    {
        return $this->modules;
    }

    /**
     * Get only active modules.
     *
     * @return FFLA_Module[]
     */
    public function get_active(): array
    {
        $active = [];
        foreach ($this->active_ids as $id) {
            if (isset($this->modules[$id])) {
                $active[$id] = $this->modules[$id];
            }
        }
        return $active;
    }

    /**
     * Check if a module is active.
     */
    public function is_active(string $id): bool
    {
        return in_array($id, $this->active_ids, true);
    }

    /**
     * Define the backward-compat constants a module's legacy code expects.
     *
     * Must run before a module's activate()/boot(), because those reference the
     * constants. The dashboard toggle reaches activation without the bootstrap
     * that normally defines them. Idempotent — every define is guarded.
     */
    public function ensure_module_constants(string $id): void
    {
        if (!isset($this->modules[$id])) {
            return;
        }

        $module = $this->modules[$id];

        switch ($id) {
            case 'woobooster':
                if (!defined('WOOBOOSTER_VERSION')) {
                    define('WOOBOOSTER_VERSION', FFLA_VERSION);
                }
                if (!defined('WOOBOOSTER_DB_VERSION')) {
                    define('WOOBOOSTER_DB_VERSION', '1.10.0');
                }
                if (!defined('WOOBOOSTER_FILE')) {
                    define('WOOBOOSTER_FILE', FFLA_FILE);
                }
                if (!defined('WOOBOOSTER_PATH')) {
                    define('WOOBOOSTER_PATH', $module->get_path());
                }
                if (!defined('WOOBOOSTER_URL')) {
                    define('WOOBOOSTER_URL', $module->get_url());
                }
                if (!defined('WOOBOOSTER_BASENAME')) {
                    define('WOOBOOSTER_BASENAME', FFLA_BASENAME);
                }
                break;

            case 'wishlist':
                if (!defined('ALG_WISHLIST_VERSION')) {
                    define('ALG_WISHLIST_VERSION', FFLA_VERSION);
                }
                if (!defined('ALG_WISHLIST_FILE')) {
                    define('ALG_WISHLIST_FILE', FFLA_FILE);
                }
                if (!defined('ALG_WISHLIST_PATH')) {
                    define('ALG_WISHLIST_PATH', $module->get_path());
                }
                if (!defined('ALG_WISHLIST_URL')) {
                    define('ALG_WISHLIST_URL', $module->get_url());
                }
                if (!defined('ALG_WISHLIST_BASENAME')) {
                    define('ALG_WISHLIST_BASENAME', FFLA_BASENAME);
                }
                break;

            case 'loadout':
                if (!defined('FFLA_LOADOUT_DB_VERSION')) {
                    define('FFLA_LOADOUT_DB_VERSION', '1.0.0');
                }
                break;
        }
    }

    /**
     * Activate a module.
     */
    public function activate_module(string $id): bool
    {
        if (!isset($this->modules[$id])) {
            return false;
        }

        // The module's activation routine (table creation, version options)
        // references its legacy constants, and the toggle path skips the normal
        // bootstrap that defines them.
        $this->ensure_module_constants($id);

        // Activate FIRST, and only persist the module as active if it succeeded.
        // Otherwise a fatal mid-activation leaves a half-created schema marked
        // active, and the next boot fails on missing tables.
        try {
            $this->modules[$id]->activate();
        } catch (\Throwable $e) {
            return false;
        }

        if (!$this->is_active($id)) {
            // Re-read from DB to avoid clobbering a concurrent change.
            $active_ids = get_option('ffla_active_modules', []);
            if (!is_array($active_ids)) {
                $active_ids = [];
            }
            if (!in_array($id, $active_ids, true)) {
                $active_ids[] = $id;
                update_option('ffla_active_modules', $active_ids);
            }
            $this->active_ids = $active_ids;
        }

        return true;
    }

    /**
     * Deactivate a module.
     */
    public function deactivate_module(string $id): bool
    {
        if (!isset($this->modules[$id])) {
            return false;
        }

        // Run the module's deactivation routine.
        $this->modules[$id]->deactivate();

        $this->active_ids = array_values(array_diff($this->active_ids, [$id]));
        update_option('ffla_active_modules', $this->active_ids);

        return true;
    }

    /**
     * Boot all active modules (called on `init`).
     */
    public function boot_active_modules(): void
    {
        foreach ($this->get_active() as $module) {
            $module->boot();
        }
    }

    /**
     * Get a module instance by ID.
     */
    public function get(string $id): ?FFLA_Module
    {
        return $this->modules[$id] ?? null;
    }
}
