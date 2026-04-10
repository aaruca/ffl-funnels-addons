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
        add_filter('ffla_product_reviews_enqueue_assets', [__CLASS__, 'enqueue_assets_in_bricks_context']);
    }

    /**
     * Load review CSS/JS in the Bricks editor/preview where the canvas is not always a product single.
     */
    public static function enqueue_assets_in_bricks_context($load): bool
    {
        if ($load) {
            return true;
        }

        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return true;
        }

        if (function_exists('bricks_is_builder_call') && bricks_is_builder_call()) {
            return true;
        }

        return false;
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
        ];

        foreach ($element_files as $file) {
            if (file_exists($file)) {
                \Bricks\Elements::register_element($file);
            }
        }
    }
}
