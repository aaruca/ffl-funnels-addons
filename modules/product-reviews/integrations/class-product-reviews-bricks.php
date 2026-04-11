<?php
/**
 * Bricks integration for Product Reviews.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Bricks
{
    public static function init(): void
    {
        add_action('init', [__CLASS__, 'register_elements'], 11);
    }

    public static function register_elements(): void
    {
        if (!class_exists('\Bricks\Elements')) {
            return;
        }

        $elements_dir = dirname(__DIR__) . '/integrations/elements/';
        $element_files = [
            $elements_dir . 'reviews-rating-badge.php',
            $elements_dir . 'reviews-list.php',
            $elements_dir . 'review-form.php',
            $elements_dir . 'order-review-hub.php',
        ];

        foreach ($element_files as $file) {
            if (file_exists($file)) {
                \Bricks\Elements::register_element($file);
            }
        }
    }
}
