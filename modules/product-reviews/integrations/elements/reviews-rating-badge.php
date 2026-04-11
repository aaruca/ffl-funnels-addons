<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Reviews_Rating_Badge extends \Bricks\Element
{
    public $category = 'FFL Funnels';
    public $name     = 'ffla-reviews-rating-badge';
    public $icon     = 'ti-star';
    public $tag      = 'div';

    public function get_label()
    {
        return esc_html__('Reviews Rating Badge', 'ffl-funnels-addons');
    }

    public function set_controls()
    {
        $this->controls['showNumber'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Show average number', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
            'inline'  => true,
        ];

        $this->controls['productId'] = [
            'tab'            => 'content',
            'label'          => esc_html__('Product ID', 'ffl-funnels-addons'),
            'type'           => 'number',
            'hasDynamicData' => true,
            'description'    => esc_html__('Leave empty to use current product context.', 'ffl-funnels-addons'),
        ];

        $this->controls['showCount'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Show review count', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'default' => true,
            'inline'  => true,
        ];

        $this->controls['hideIfNoReviews'] = [
            'tab'         => 'content',
            'label'       => esc_html__('Hide when no reviews', 'ffl-funnels-addons'),
            'type'        => 'checkbox',
            'default'     => false,
            'inline'      => true,
            'description' => esc_html__('Do not output the badge on the front end when the product has zero approved reviews. In Bricks, a small placeholder is still shown while editing.', 'ffl-funnels-addons'),
        ];

        $this->controls['filledColor'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Filled stars color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'default' => '#d4a017',
            'css'     => [['property' => '--ffla-star-filled', 'selector' => '']],
        ];

        $this->controls['emptyColor'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Empty stars color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'default' => '#d8dce5',
            'css'     => [['property' => '--ffla-star-empty', 'selector' => '']],
        ];

        $this->controls['starSize'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Star size', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'default' => '18px',
            'css'     => [['property' => 'font-size', 'selector' => '.ffla-stars']],
        ];

        $this->controls['numberColor'] = [
            'tab'   => 'content',
            'label' => esc_html__('Average number color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.ffla-reviews-badge__avg']],
        ];

        $this->controls['numberTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Average number typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-reviews-badge__avg']],
        ];

        $this->controls['countColor'] = [
            'tab'   => 'content',
            'label' => esc_html__('Count color', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'color', 'selector' => '.ffla-reviews-badge__count']],
        ];
    }

    private function render_stars(float $rating, int $max_stars = 5): string
    {
        $rating = max(0, min($max_stars, $rating));
        $percentage = ($rating / $max_stars) * 100;

        $stars = str_repeat('&#9733;', $max_stars);
        return '<span class="ffla-stars" aria-label="' . esc_attr(sprintf(__('Rated %1$s out of %2$s', 'ffl-funnels-addons'), number_format_i18n($rating, 1), $max_stars)) . '">'
            . '<span class="ffla-stars__base">' . $stars . '</span>'
            . '<span class="ffla-stars__fill" style="width:' . esc_attr(number_format($percentage, 4, '.', '')) . '%;">' . $stars . '</span>'
            . '</span>';
    }

    public function render()
    {
        $settings = $this->settings;
        $explicit = !empty($settings['productId']) ? absint($settings['productId']) : 0;
        $product_id = \Product_Reviews_Core::resolve_context_product_id($explicit);
        if ($product_id <= 0) {
            return $this->render_element_placeholder([
                'title' => esc_html__('No product found for rating badge.', 'ffl-funnels-addons'),
            ]);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $average = (float) $product->get_average_rating();
        $count   = (int) $product->get_review_count();
        $show_number = !isset($settings['showNumber']) || !empty($settings['showNumber']);
        $show_count = !isset($settings['showCount']) || !empty($settings['showCount']);

        if (!empty($settings['hideIfNoReviews']) && $count < 1) {
            $in_builder = (function_exists('bricks_is_builder') && bricks_is_builder())
                || (function_exists('bricks_is_builder_call') && bricks_is_builder_call());
            if ($in_builder) {
                return $this->render_element_placeholder([
                    'title' => esc_html__('No reviews (badge hidden on frontend)', 'ffl-funnels-addons'),
                ]);
            }

            return;
        }

        $this->set_attribute('_root', 'class', 'ffla-reviews-badge');

        echo '<div ' . $this->render_attributes('_root') . '>';
        if ($show_number) {
            echo '<span class="ffla-reviews-badge__avg">' . esc_html(number_format_i18n($average, 1)) . '</span>';
        }
        echo wp_kses_post($this->render_stars($average));
        if ($show_count) {
            echo '<span class="ffla-reviews-badge__count">(' . esc_html((string) $count) . ')</span>';
        }
        echo '</div>';
    }
}
