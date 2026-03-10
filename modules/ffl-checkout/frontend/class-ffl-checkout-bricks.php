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
    }

    /**
     * Register the FFL Dealer Finder element with Bricks.
     */
    public function register_elements(): void
    {
        \Bricks\Elements::register_element(
            $this->module_path . 'elements/ffl-dealer-finder-element.php'
        );
    }
}
