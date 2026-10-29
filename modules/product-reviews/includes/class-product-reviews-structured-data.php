<?php
/**
 * Review structured data that matches the reviews the FFL list shows.
 *
 * Google only accepts review markup for reviews visible on the page.
 * WooCommerce marks up the five newest rated reviews of any comment type; the
 * FFL list puts pinned reviews first, can sort by helpfulness, shows only
 * `review` comments and has its own length. The `review` array of the
 * product's JSON-LD (WooCommerce's, and Rank Math's when it builds its own)
 * is therefore rewritten to the reviews the list shows.
 *
 * WooCommerce builds the product markup before the list renders (at
 * `woocommerce_single_product_summary`, or before the body of a Bricks
 * template), so the list is first worked out from its settings: the Reviews
 * List element in the page's Bricks content, or the replaced Reviews tab.
 * WooCommerce prints the markup in the footer, after the list has rendered,
 * so just before that the exact reviews the list showed replace the first
 * guess. Rank Math prints in <head> and only gets the first step.
 *
 * Markup is only changed when an FFL list shows (or will show) the product's
 * reviews. With WooCommerce's own Reviews tab in use it is left alone.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Structured_Data
{
    /** @var array<string, int> WooCommerce Product node `@id` => product ID */
    private static $woocommerce_nodes = [];

    public static function init(): void
    {
        add_filter('woocommerce_structured_data_product', [__CLASS__, 'filter_woocommerce_product'], 20, 2);
        // WooCommerce prints its JSON-LD at wp_footer priority 10.
        add_action('wp_footer', [__CLASS__, 'sync_woocommerce_markup'], 9);
        add_filter('rank_math/json_ld', [__CLASS__, 'filter_rank_math'], 9999);
    }

    /**
     * @param mixed $markup
     * @param mixed $product
     * @return mixed
     */
    public static function filter_woocommerce_product($markup, $product)
    {
        if (!is_array($markup) || !$product instanceof \WC_Product) {
            return $markup;
        }

        $product_id = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();

        if (isset($markup['@id']) && is_string($markup['@id'])) {
            self::$woocommerce_nodes[$markup['@id']] = $product_id;
        }

        $reviews = self::visible_reviews($product_id);

        return $reviews === null ? $markup : self::with_reviews($markup, $reviews);
    }

    /**
     * Replace the first guess with what the lists actually rendered.
     */
    public static function sync_woocommerce_markup(): void
    {
        if (empty(self::$woocommerce_nodes) || !function_exists('WC')) {
            return;
        }

        $structured_data = WC()->structured_data;
        if (!$structured_data instanceof \WC_Structured_Data) {
            return;
        }

        $nodes   = $structured_data->get_data();
        $changed = false;

        foreach ($nodes as $index => $node) {
            $id = (is_array($node) && isset($node['@id']) && is_string($node['@id'])) ? $node['@id'] : '';
            if ($id === '' || !isset(self::$woocommerce_nodes[$id])) {
                continue;
            }

            $reviews = Product_Reviews_Frontend_Render::rendered_reviews(self::$woocommerce_nodes[$id]);
            if ($reviews === null) {
                continue;
            }

            $updated = self::with_reviews($node, $reviews);
            if ($updated !== $node) {
                $nodes[$index] = $updated;
                $changed       = true;
            }
        }

        if (!$changed) {
            return;
        }

        // There is no setter for a single node: reset with the first and add
        // the others back in their original order.
        $reset = true;
        foreach ($nodes as $node) {
            $structured_data->set_data($node, $reset);
            $reset = false;
        }
    }

    /**
     * @param mixed $data Rank Math's entities.
     * @return mixed
     */
    public static function filter_rank_math($data)
    {
        if (!is_array($data) || !function_exists('is_product') || !is_product()) {
            return $data;
        }

        $product_id = Product_Reviews_Core::resolve_context_product_id((int) get_queried_object_id());
        $reviews    = $product_id > 0 ? self::visible_reviews($product_id) : null;
        if ($reviews === null) {
            return $data;
        }

        foreach ($data as $key => $entity) {
            if (is_array($entity) && array_key_exists('review', $entity) && self::is_product_entity($entity)) {
                $data[$key] = self::with_reviews($entity, $reviews);
            }
        }

        return $data;
    }

    /**
     * The reviews an FFL list shows for the product on this page, or null when
     * no FFL list shows them.
     *
     * @return array<int, \WP_Comment>|null
     */
    public static function visible_reviews(int $product_id): ?array
    {
        if ($product_id <= 0) {
            return null;
        }

        $rendered = Product_Reviews_Frontend_Render::rendered_reviews($product_id);
        if ($rendered !== null) {
            return $rendered;
        }

        /**
         * Settings of the FFL list that will show this product's reviews
         * (`perPage`, `orderBy`), or null when the page shows none. Use it when
         * the list sits somewhere this class cannot see in advance, such as a
         * nested Bricks template.
         */
        $settings = apply_filters('ffla_product_reviews_structured_data_list_settings', self::expected_list_settings($product_id), $product_id);
        if (!is_array($settings)) {
            return null;
        }

        return Product_Reviews_Frontend_Render::list_reviews($product_id, $settings);
    }

    /**
     * @param array<string, mixed>    $node    A Product node.
     * @param array<int, \WP_Comment> $reviews In display order.
     * @return array<string, mixed>
     */
    public static function with_reviews(array $node, array $reviews): array
    {
        // WooCommerce marks up no reviews when star ratings are switched off.
        if (function_exists('wc_review_ratings_enabled') && !wc_review_ratings_enabled()) {
            return $node;
        }

        $marked = [];
        foreach ($reviews as $review) {
            $item = self::review_markup($review);
            if ($item !== null) {
                $marked[] = $item;
            }
        }

        if (empty($marked)) {
            unset($node['review']);

            return $node;
        }

        $node['review'] = $marked;

        return $node;
    }

    /**
     * Same shape as WooCommerce's review markup. A review without a rating is
     * left out, as WooCommerce does, since Google requires one.
     *
     * @param mixed $review
     * @return array<string, mixed>|null
     */
    private static function review_markup($review): ?array
    {
        if (!$review instanceof \WP_Comment) {
            return null;
        }

        $rating = (int) get_comment_meta((int) $review->comment_ID, 'rating', true);
        if ($rating < 1 || $rating > 5) {
            return null;
        }

        return [
            '@type'         => 'Review',
            'reviewRating'  => [
                '@type'       => 'Rating',
                'bestRating'  => '5',
                'ratingValue' => (string) $rating,
                'worstRating' => '1',
            ],
            'author'        => [
                '@type' => 'Person',
                // As the card shows it (Display → Reviewer name).
                'name'  => Product_Reviews_Frontend_Render::display_name($review),
            ],
            'reviewBody'    => get_comment_text($review),
            'datePublished' => get_comment_date('c', $review),
        ];
    }

    /**
     * Settings of the FFL list expected to show the product on this page.
     *
     * @return array<string, mixed>|null
     */
    private static function expected_list_settings(int $product_id): ?array
    {
        if (!function_exists('is_product') || !is_product()
            || Product_Reviews_Core::resolve_context_product_id((int) get_queried_object_id()) !== $product_id) {
            return null;
        }

        $bricks = self::bricks_list_settings($product_id);
        if ($bricks !== null) {
            return $bricks;
        }

        // WooCommerce only adds the Reviews tab while reviews are open.
        if ('1' === Product_Reviews_Core::get_setting('replace_default_reviews_tab', '0') && comments_open($product_id)) {
            return Product_Reviews_Core::wc_tab_list_settings($product_id);
        }

        return null;
    }

    /**
     * The first Reviews List element for this product among the elements
     * Bricks renders as the page content (the single product template, or
     * the product's own Bricks content). Lists inside nested templates or
     * components are not seen here.
     *
     * @return array<string, mixed>|null
     */
    private static function bricks_list_settings(int $product_id): ?array
    {
        if (!class_exists('\Bricks\Helpers') || !method_exists('\Bricks\Helpers', 'get_bricks_data')) {
            return null;
        }

        $elements = \Bricks\Helpers::get_bricks_data((int) get_queried_object_id(), 'content');
        if (!is_array($elements)) {
            return null;
        }

        foreach ($elements as $element) {
            if (!is_array($element) || ($element['name'] ?? '') !== 'ffla-reviews-list') {
                continue;
            }

            $settings = (isset($element['settings']) && is_array($element['settings'])) ? $element['settings'] : [];
            if (!empty($settings['_hideElementFrontend'])) {
                continue;
            }

            // A fixed Product ID shows another product's reviews, and a
            // store-wide or category list is not this product's list.
            if (!empty($settings['productId']) && is_numeric($settings['productId'])
                && Product_Reviews_Core::resolve_context_product_id(absint($settings['productId'])) !== $product_id) {
                continue;
            }
            if (!empty($settings['source']) && 'product' !== $settings['source']) {
                continue;
            }

            return [
                'perPage'   => $settings['perPage'] ?? 5,
                'orderBy'   => $settings['orderBy'] ?? 'recent',
                'minRating' => $settings['minRating'] ?? 0,
                'withMedia' => $settings['withMedia'] ?? false,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entity
     */
    private static function is_product_entity(array $entity): bool
    {
        $types = isset($entity['@type']) ? (array) $entity['@type'] : [];

        return (bool) array_intersect(['Product', 'ProductGroup'], $types);
    }
}
