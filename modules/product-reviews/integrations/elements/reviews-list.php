<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Reviews_List extends \Bricks\Element
{
    public $category = 'FFL Funnels';
    public $name     = 'ffla-reviews-list';
    public $icon     = 'ti-comment-alt';
    public $tag      = 'div';

    public function get_label()
    {
        return esc_html__('Reviews List', 'ffl-funnels-addons');
    }

    public function set_controls()
    {
        $this->controls['productId'] = [
            'tab'            => 'content',
            'label'          => esc_html__('Product ID', 'ffl-funnels-addons'),
            'type'           => 'number',
            'hasDynamicData' => true,
            'description'    => esc_html__('Leave empty to use current product context.', 'ffl-funnels-addons'),
        ];

        $this->controls['perPage'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Max reviews', 'ffl-funnels-addons'),
            'type'    => 'number',
            'default' => 5,
            'min'     => 1,
            'max'     => 50,
        ];

        $this->controls['orderBy'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Order by', 'ffl-funnels-addons'),
            'type'    => 'select',
            'options' => [
                'recent'  => esc_html__('Most recent', 'ffl-funnels-addons'),
                'helpful' => esc_html__('Most helpful', 'ffl-funnels-addons'),
            ],
            'default' => 'recent',
        ];

        $this->controls['starFilledColor'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Filled stars color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'default' => '#d4a017',
            'css'     => [['property' => '--ffla-star-filled', 'selector' => '']],
        ];

        $this->controls['starEmptyColor'] = [
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
            'default' => '16px',
            'css'     => [['property' => 'font-size', 'selector' => '.ffla-stars']],
        ];

        $this->controls['cardBg'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card background', 'ffl-funnels-addons'),
            'type'  => 'color',
            'css'   => [['property' => 'background-color', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['cardBorder'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card border', 'ffl-funnels-addons'),
            'type'  => 'border',
            'css'   => [['property' => 'border', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['cardRadius'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card border radius', 'ffl-funnels-addons'),
            'type'  => 'number',
            'units' => true,
            'css'   => [['property' => 'border-radius', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['cardPadding'] = [
            'tab'   => 'content',
            'label' => esc_html__('Card padding', 'ffl-funnels-addons'),
            'type'  => 'spacing',
            'css'   => [['property' => 'padding', 'selector' => '.ffla-review-card']],
        ];

        $this->controls['listGap'] = [
            'tab'   => 'content',
            'label' => esc_html__('List gap', 'ffl-funnels-addons'),
            'type'  => 'number',
            'units' => true,
            'css'   => [['property' => 'gap', 'selector' => '']],
        ];

        $this->controls['authorTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Author typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-review-card__author']],
        ];

        $this->controls['dateTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Date typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-review-card__date']],
        ];

        $this->controls['contentTypography'] = [
            'tab'   => 'content',
            'label' => esc_html__('Review text typography', 'ffl-funnels-addons'),
            'type'  => 'typography',
            'css'   => [['property' => 'typography', 'selector' => '.ffla-review-card__content']],
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
                'title' => esc_html__('No product found for reviews list.', 'ffl-funnels-addons'),
            ]);
        }

        $per_page = !empty($settings['perPage']) ? absint($settings['perPage']) : 5;
        $per_page = max(1, min(50, $per_page));
        $order_by = $settings['orderBy'] ?? 'recent';

        $args = [
            'post_id' => $product_id,
            'status'  => 'approve',
            'type'    => 'review',
            'number'  => ($order_by === 'recent') ? $per_page : 100,
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
        ];
        $reviews = get_comments($args);

        if ($order_by === 'helpful') {
            usort($reviews, static function ($a, $b): int {
                $a_helpful = (int) get_comment_meta($a->comment_ID, 'ffla_helpful_yes', true);
                $b_helpful = (int) get_comment_meta($b->comment_ID, 'ffla_helpful_yes', true);
                if ($a_helpful === $b_helpful) {
                    return strcmp($b->comment_date_gmt, $a->comment_date_gmt);
                }
                return $b_helpful <=> $a_helpful;
            });
            $reviews = array_slice($reviews, 0, $per_page);
        }

        $this->set_attribute('_root', 'class', 'ffla-reviews-list');

        echo '<div ' . $this->render_attributes('_root') . '>';

        if (empty($reviews)) {
            echo '<p class="ffla-reviews-list__empty">' . esc_html__('No reviews yet.', 'ffl-funnels-addons') . '</p>';
            echo '</div>';
            return;
        }

        foreach ($reviews as $review) {
            $rating = (float) get_comment_meta($review->comment_ID, 'rating', true);
            $quality = (int) get_comment_meta($review->comment_ID, 'ffla_review_quality', true);
            $value = (int) get_comment_meta($review->comment_ID, 'ffla_review_value', true);
            $helpful = (int) get_comment_meta($review->comment_ID, 'ffla_helpful_yes', true);
            $verified = (int) get_comment_meta($review->comment_ID, 'ffla_verified_purchase', true) === 1;
            $media_ids = get_comment_meta($review->comment_ID, 'ffla_review_media_ids', true);
            if (!is_array($media_ids)) {
                $media_ids = [];
            }

            echo '<article class="ffla-review-card">';
            echo '<header class="ffla-review-card__header">';
            echo '<strong class="ffla-review-card__author">' . esc_html($review->comment_author) . '</strong>';
            echo '<span class="ffla-review-card__date">' . esc_html(wp_date(get_option('date_format'), strtotime($review->comment_date_gmt . ' UTC'))) . '</span>';
            echo '</header>';

            if ($rating > 0) {
                echo '<div class="ffla-review-card__rating">' . wp_kses_post($this->render_stars($rating)) . '</div>';
            }

            if ($verified) {
                echo '<span class="ffla-review-card__verified">' . esc_html__('Verified buyer', 'ffl-funnels-addons') . '</span>';
            }

            if ($quality > 0 || $value > 0) {
                echo '<div class="ffla-review-card__criteria">';
                if ($quality > 0) {
                    echo '<span>' . esc_html__('Quality:', 'ffl-funnels-addons') . ' ' . esc_html((string) $quality) . '/5</span>';
                }
                if ($value > 0) {
                    echo '<span>' . esc_html__('Value:', 'ffl-funnels-addons') . ' ' . esc_html((string) $value) . '/5</span>';
                }
                echo '</div>';
            }

            echo '<div class="ffla-review-card__content">' . wp_kses_post(wpautop($review->comment_content)) . '</div>';

            if (!empty($media_ids)) {
                echo '<div class="ffla-review-card__media">';
                foreach ($media_ids as $media_id) {
                    $media_id = absint($media_id);
                    if ($media_id <= 0) {
                        continue;
                    }

                    $mime = (string) get_post_mime_type($media_id);
                    if (strpos($mime, 'video/') === 0) {
                        $video = wp_video_shortcode([
                            'src'      => wp_get_attachment_url($media_id),
                            'preload'  => 'metadata',
                            'controls' => true,
                        ]);
                        echo '<div class="ffla-review-card__media-item ffla-review-card__media-item--video">' . wp_kses_post($video) . '</div>';
                    } else {
                        $image = wp_get_attachment_image($media_id, 'medium');
                        if (!empty($image)) {
                            echo '<div class="ffla-review-card__media-item ffla-review-card__media-item--image">' . wp_kses_post($image) . '</div>';
                        }
                    }
                }
                echo '</div>';
            }

            if ('1' === \Product_Reviews_Core::get_setting('enable_helpful_votes', '1')) {
                echo '<button class="ffla-review-helpful" type="button" data-comment-id="' . esc_attr((string) $review->comment_ID) . '">';
                echo esc_html__('Helpful', 'ffl-funnels-addons') . ' ';
                echo '<span class="ffla-review-helpful__count">' . esc_html((string) $helpful) . '</span>';
                echo '</button>';
            }

            echo '</article>';
        }

        echo '</div>';
    }
}
