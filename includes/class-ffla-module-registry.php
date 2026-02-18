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
     * Activate a module.
     */
    public function activate_module(string $id): bool
    {
        if (!isset($this->modules[$id])) {
            return false;
        }

        if (!$this->is_active($id)) {
            $this->active_ids[] = $id;
            update_option('ffla_active_modules', $this->active_ids);
        }

        // Run the module's activation routine (create tables, etc.).
        $this->modules[$id]->activate();

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
