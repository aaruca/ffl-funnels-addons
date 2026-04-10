<?php
/**
 * Product Reviews Module — Entry Point.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Module extends FFLA_Module
{
    /** @var Product_Reviews_Admin|null */
    private $admin;

    public function get_id(): string
    {
        return 'product-reviews';
    }

    public function get_name(): string
    {
        return 'Product Reviews';
    }

    public function get_description(): string
    {
        return __('Advanced WooCommerce product reviews with Bricks elements, helpful votes, and post-purchase review requests.', 'ffl-funnels-addons');
    }

    public function get_icon_svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
    }

    public function boot(): void
    {
        $base = $this->get_path();

        require_once $base . 'includes/class-product-reviews-core.php';
        Product_Reviews_Core::init();

        require_once $base . 'includes/class-product-reviews-assets.php';
        Product_Reviews_Assets::init();

        require_once $base . 'includes/class-product-reviews-ajax.php';
        Product_Reviews_Ajax::init();

        require_once $base . 'includes/class-product-reviews-email.php';
        Product_Reviews_Email::init();

        require_once $base . 'integrations/class-product-reviews-bricks.php';
        Product_Reviews_Bricks::init();

        if (is_admin()) {
            require_once $base . 'admin/class-product-reviews-admin.php';
            $this->admin = new Product_Reviews_Admin();
            $this->admin->init();
        }
    }

    public function activate(): void
    {
        require_once $this->get_path() . 'includes/class-product-reviews-core.php';
        $defaults = Product_Reviews_Core::get_default_settings();
        $current  = get_option('ffla_product_reviews_settings', []);
        if (!is_array($current)) {
            $current = [];
        }

        update_option('ffla_product_reviews_settings', wp_parse_args($current, $defaults));
    }

    public function deactivate(): void
    {
        // Intentionally keep settings and data.
    }

    public function get_admin_pages(): array
    {
        return [
            [
                'slug'  => 'ffla-product-reviews',
                'title' => __('Product Reviews', 'ffl-funnels-addons'),
                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            ],
        ];
    }

    public function render_admin_page(string $page_slug): void
    {
        if ($this->admin) {
            $this->admin->render_settings_content();
        }
    }
}
