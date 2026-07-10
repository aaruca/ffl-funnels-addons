<?php
/**
 * Bricks dynamic tags for product review aggregates.
 *
 * Lets a Bricks template drop the review count, average, or recommendation
 * share into any text field without an element wrapper.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Dynamic_Tags
{
    const GROUP = 'FFL Funnels - Product Reviews';

    /**
     * @return array<string, string> tag name => label
     */
    private static function tags(): array
    {
        return [
            'ffla_review_count'             => __('Review count', 'ffl-funnels-addons'),
            'ffla_review_average'           => __('Review average rating', 'ffl-funnels-addons'),
            'ffla_review_recommend_percent' => __('Recommended (4-5 star) percent', 'ffl-funnels-addons'),
        ];
    }

    public static function init(): void
    {
        add_filter('bricks/dynamic_tags_list', [__CLASS__, 'register_tags']);
        add_filter('bricks/dynamic_data/render_tag', [__CLASS__, 'render_tag'], 20, 3);
        add_filter('bricks/dynamic_data/render_content', [__CLASS__, 'render_content'], 20, 3);
        add_filter('bricks/frontend/render_data', [__CLASS__, 'render_content'], 20, 2);
    }

    /**
     * @param array<int, array<string, string>> $tags
     * @return array<int, array<string, string>>
     */
    public static function register_tags(array $tags): array
    {
        foreach (self::tags() as $name => $label) {
            $tags[] = [
                'name'  => '{' . $name . '}',
                'label' => $label,
                'group' => self::GROUP,
            ];
        }

        return $tags;
    }

    private static function resolve_product_id($post): int
    {
        $explicit = ($post instanceof \WP_Post) ? (int) $post->ID : 0;

        $product_id = Product_Reviews_Core::resolve_context_product_id($explicit);
        if ($product_id > 0) {
            return $product_id;
        }

        // The tag may sit on a page or a template, where $post is not the
        // product; fall back to the loop / queried object.
        return Product_Reviews_Core::resolve_context_product_id(0);
    }

    private static function value_for(string $name, int $product_id): string
    {
        if ($product_id <= 0) {
            return '';
        }

        $data = Product_Reviews_Core::get_rating_distribution($product_id);

        switch ($name) {
            case 'ffla_review_count':
                return number_format_i18n($data['total']);

            case 'ffla_review_average':
                return $data['total'] > 0 ? number_format_i18n($data['average'], 1) : '';

            case 'ffla_review_recommend_percent':
                if ($data['total'] < 1) {
                    return '';
                }
                $positive = (int) $data['counts'][5] + (int) $data['counts'][4];

                return number_format_i18n(round(($positive / $data['total']) * 100));
        }

        return '';
    }

    /**
     * @param string $tag
     * @param mixed  $post
     * @param string $context
     * @return string
     */
    public static function render_tag($tag, $post = null, $context = 'text')
    {
        if (!is_string($tag)) {
            return $tag;
        }

        // Bricks may hand over `{tag}` or `{tag:filter}`.
        $name = trim($tag, '{}');
        $name = explode(':', $name, 2)[0];

        if (!array_key_exists($name, self::tags())) {
            return $tag;
        }

        return self::value_for($name, self::resolve_product_id($post));
    }

    /**
     * @param string $content
     * @param mixed  $post
     * @param string $context
     * @return string
     */
    public static function render_content($content, $post = null, $context = 'text')
    {
        if (!is_string($content) || strpos($content, '{ffla_review_') === false) {
            return $content;
        }

        $product_id = self::resolve_product_id($post);

        foreach (array_keys(self::tags()) as $name) {
            $needle = '{' . $name . '}';
            if (strpos($content, $needle) !== false) {
                $content = str_replace($needle, self::value_for($name, $product_id), $content);
            }
        }

        return $content;
    }
}
