<?php
namespace Bricks;

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Review_Form extends \Bricks\Element
{
    public $category = 'FFL Funnels';
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

        $this->controls['showLoginHint'] = [
            'tab'     => 'content',
            'label'   => esc_html__('Show login hint', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'inline'  => true,
            'default' => true,
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

        $title = $settings['title'] ?? Product_Reviews_Core::get_setting('form_title', __('Write a review', 'ffl-funnels-addons'));
        $show_login_hint = !isset($settings['showLoginHint']) || !empty($settings['showLoginHint']);
        $status = isset($_GET['ffla_review_status']) ? sanitize_text_field(wp_unslash($_GET['ffla_review_status'])) : '';
        $message = isset($_GET['ffla_review_message']) ? sanitize_text_field(wp_unslash($_GET['ffla_review_message'])) : '';

        $this->set_attribute('_root', 'class', 'ffla-review-form-wrap');
        echo '<div ' . $this->render_attributes('_root') . '>';
        echo '<h4 class="ffla-review-form__title">' . esc_html($title) . '</h4>';

        if ($status === 'success') {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--success">' . esc_html__('Thanks! Your review was submitted.', 'ffl-funnels-addons') . '</p>';
        } elseif ($status === 'error' && $message !== '') {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--error">' . esc_html($message) . '</p>';
        }

        if (!is_user_logged_in() && get_option('comment_registration') && $show_login_hint) {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--info">' . esc_html__('You must be logged in to submit a review.', 'ffl-funnels-addons') . '</p>';
        }

        echo '<form class="ffla-review-form" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ffla_review_form', 'ffla_review_form_nonce');
        echo '<input type="text" class="ffla-review-hp-field" name="ffla_hp" value="" autocomplete="off" tabindex="-1" aria-hidden="true">';
        echo '<input type="hidden" name="action" value="ffla_submit_product_review">';

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
        echo '<input type="hidden" name="redirect_to" value="' . esc_url(get_permalink($product_id)) . '">';
        echo '<button class="ffla-review-form__submit" type="submit">' . esc_html__('Submit review', 'ffl-funnels-addons') . '</button>';

        echo '</form>';
        echo '</div>';
    }
}
