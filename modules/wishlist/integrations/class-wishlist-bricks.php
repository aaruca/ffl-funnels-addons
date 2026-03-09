<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Bricks Builder Integration
 *
 * Registers Wishlist query loop and native elements.
 *
 * @package FFL_Funnels_Addons
 */
class Alg_Wishlist_Bricks
{

    public static function init()
    {
        // Load Query Integration immediately to catch setup/control_options hooks early.
        require_once dirname(__DIR__) . '/integrations/class-wishlist-bricks-query.php';
        Alg_Wishlist_Bricks_Query::init();

        // Register native Bricks elements (priority 11, after Bricks' own init).
        add_action('init', [__CLASS__, 'register_elements'], 11);
    }

    /**
     * Register Wishlist elements with Bricks.
     */
    public static function register_elements()
    {
        if (!class_exists('\Bricks\Elements')) {
            return;
        }

        $elements_dir = dirname(__DIR__) . '/integrations/elements/';

        $element_files = [
            $elements_dir . 'wishlist-button.php',
            $elements_dir . 'wishlist-count.php',
        ];

        foreach ($element_files as $file) {
            if (file_exists($file)) {
                \Bricks\Elements::register_element($file);
            }
        }
    }
}
