<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Review_Form extends \Bricks\Element
{
    public $category = 'FFL Funnels - Product Reviews';
    public $name     = 'ffla-review-form';
    public $icon     = 'ti-pencil-alt';
    public $tag      = 'div';

    public function get_label()
    {
        return esc_html__('Review Form', 'ffl-funnels-addons');
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

        $this->controls['title'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Title', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('Write a review', 'ffl-funnels-addons'),
        ];
    }

    public function render()
    {
        $settings   = $this->settings;
        $product_id = !empty($settings['productId']) ? absint($settings['productId']) : get_the_ID();
        if (!$product_id || 'product' !== get_post_type($product_id)) {
            return $this->render_element_placeholder([
                'title' => esc_html__('No product found for review form.', 'ffl-funnels-addons'),
            ]);
        }

        if (!comments_open($product_id)) {
            return;
        }

        $title = $settings['title'] ?? Product_Reviews_Core::get_setting('form_title', __('Write a review', 'ffl-funnels-addons'));

        $this->set_attribute('_root', 'class', 'ffla-review-form-wrap');
        echo '<div ' . $this->render_attributes('_root') . '>';
        echo '<h4 class="ffla-review-form__title">' . esc_html($title) . '</h4>';

        echo '<form class="ffla-review-form" method="post" enctype="multipart/form-data" action="' . esc_url(site_url('/wp-comments-post.php')) . '">';
        wp_nonce_field('ffla_review_form', 'ffla_review_form_nonce');
        echo '<input type="text" class="ffla-review-hp-field" name="ffla_hp" value="" autocomplete="off" tabindex="-1" aria-hidden="true">';

        if (!is_user_logged_in()) {
            $commenter = wp_get_current_commenter();
            echo '<p class="ffla-review-form__field"><label>' . esc_html__('Name', 'ffl-funnels-addons') . '</label>';
            echo '<input type="text" name="author" value="' . esc_attr($commenter['comment_author'] ?? '') . '" required></p>';
            echo '<p class="ffla-review-form__field"><label>' . esc_html__('Email', 'ffl-funnels-addons') . '</label>';
            echo '<input type="email" name="email" value="' . esc_attr($commenter['comment_author_email'] ?? '') . '" required></p>';
        }

        echo '<p class="ffla-review-form__field"><label for="ffla_review_rating">' . esc_html__('Rating', 'ffl-funnels-addons') . '</label>';
        echo '<select id="ffla_review_rating" name="rating" required>';
        echo '<option value="">' . esc_html__('Select a rating', 'ffl-funnels-addons') . '</option>';
        for ($i = 5; $i >= 1; $i--) {
            echo '<option value="' . esc_attr((string) $i) . '">' . esc_html((string) $i) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="ffla-review-form__field"><label for="ffla_review_quality">' . esc_html__('Quality', 'ffl-funnels-addons') . '</label>';
        echo '<select id="ffla_review_quality" name="ffla_review_quality"><option value="">' . esc_html__('Select', 'ffl-funnels-addons') . '</option>';
        for ($i = 1; $i <= 5; $i++) {
            echo '<option value="' . esc_attr((string) $i) . '">' . esc_html((string) $i) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="ffla-review-form__field"><label for="ffla_review_value">' . esc_html__('Value', 'ffl-funnels-addons') . '</label>';
        echo '<select id="ffla_review_value" name="ffla_review_value"><option value="">' . esc_html__('Select', 'ffl-funnels-addons') . '</option>';
        for ($i = 1; $i <= 5; $i++) {
            echo '<option value="' . esc_attr((string) $i) . '">' . esc_html((string) $i) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="ffla-review-form__field"><label for="comment">' . esc_html__('Review', 'ffl-funnels-addons') . '</label>';
        echo '<textarea id="comment" name="comment" rows="5" required></textarea></p>';

        echo '<p class="ffla-review-form__field"><label for="ffla_review_media">' . esc_html__('Photos / Video (optional)', 'ffl-funnels-addons') . '</label>';
        echo '<input id="ffla_review_media" name="ffla_review_media[]" type="file" accept="image/*,video/mp4,video/webm" multiple>';
        echo '<small>' . esc_html__('Up to 3 files. Max 5MB each.', 'ffl-funnels-addons') . '</small></p>';

        if (Product_Reviews_Core::is_turnstile_enabled()) {
            echo '<div class="ffla-review-turnstile-wrap">';
            echo '<div class="cf-turnstile" data-sitekey="' . esc_attr(Product_Reviews_Core::get_turnstile_site_key()) . '" data-theme="auto"></div>';
            echo '</div>';
        }

        echo '<input type="hidden" name="comment_post_ID" value="' . esc_attr((string) $product_id) . '">';
        echo '<input type="hidden" name="comment_parent" value="0">';
        echo '<input type="hidden" name="redirect_to" value="' . esc_url(get_permalink($product_id) . '#reviews') . '">';
        echo '<button class="ffla-review-form__submit" type="submit">' . esc_html__('Submit review', 'ffl-funnels-addons') . '</button>';

        echo '</form>';
        echo '</div>';
    }
}
