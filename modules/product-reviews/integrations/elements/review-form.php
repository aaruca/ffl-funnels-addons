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

    public function set_control_groups()
    {
        $this->control_groups['general'] = [
            'title' => esc_html__('General', 'ffl-funnels-addons'),
            'tab'   => 'content',
        ];

        $this->control_groups['formStyle'] = [
            'title' => esc_html__('Container', 'ffl-funnels-addons'),
            'tab'   => 'style',
        ];

        $this->control_groups['starsStyle'] = [
            'title' => esc_html__('Stars', 'ffl-funnels-addons'),
            'tab'   => 'style',
        ];

        $this->control_groups['fieldsStyle'] = [
            'title' => esc_html__('Fields', 'ffl-funnels-addons'),
            'tab'   => 'style',
        ];

        $this->control_groups['buttonStyle'] = [
            'title' => esc_html__('Submit button', 'ffl-funnels-addons'),
            'tab'   => 'style',
        ];

        $this->control_groups['noticesStyle'] = [
            'title' => esc_html__('Notices', 'ffl-funnels-addons'),
            'tab'   => 'style',
        ];
    }

    /**
     * Bricks checkboxes: unchecked may omit the key; "0" must read as false (see PHP empty() quirk avoided).
     */
    private static function setting_bool(array $settings, string $key, bool $default = true): bool
    {
        if (!array_key_exists($key, $settings)) {
            return $default;
        }

        return filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
    }

    public function set_controls()
    {
        $this->controls['productId'] = [
            'group'          => 'general',
            'tab'            => 'content',
            'label'          => esc_html__('Product ID', 'ffl-funnels-addons'),
            'type'           => 'number',
            'hasDynamicData' => true,
            'description'    => esc_html__('Optional. Leave empty to detect the product from this template (singular product, global $product, or Query Loop).', 'ffl-funnels-addons'),
        ];

        $this->controls['title'] = [
            'group'   => 'general',
            'tab'     => 'content',
            'label'   => esc_html__('Title', 'ffl-funnels-addons'),
            'type'    => 'text',
            'default' => esc_html__('Write a review', 'ffl-funnels-addons'),
        ];

        $this->controls['introText'] = [
            'group'         => 'general',
            'tab'           => 'content',
            'label'         => esc_html__('Intro text', 'ffl-funnels-addons'),
            'type'          => 'textarea',
            'description'   => esc_html__('Short reassurance (e.g. honest feedback helps others). Shown above the fields.', 'ffl-funnels-addons'),
        ];

        $this->controls['showOptionalCriteria'] = [
            'group'         => 'general',
            'tab'           => 'content',
            'label'         => esc_html__('Show quality & value', 'ffl-funnels-addons'),
            'type'          => 'checkbox',
            'inline'        => true,
            'default'       => true,
            'description'   => esc_html__('Optional extra star ratings; overall rating stays required.', 'ffl-funnels-addons'),
        ];

        $this->controls['collapseMedia'] = [
            'group'         => 'general',
            'tab'           => 'content',
            'label'         => esc_html__('Collapse media upload', 'ffl-funnels-addons'),
            'type'          => 'checkbox',
            'inline'        => true,
            'default'       => true,
            'description'   => esc_html__('Keeps the form short; photos/video sit inside an expandable section.', 'ffl-funnels-addons'),
        ];

        $this->controls['showLoginHint'] = [
            'group'   => 'general',
            'tab'     => 'content',
            'label'   => esc_html__('Show login hint', 'ffl-funnels-addons'),
            'type'    => 'checkbox',
            'inline'  => true,
            'default' => true,
        ];

        // ── Style: container ─────────────────────────────────────
        $this->controls['wrapBackground'] = [
            'group'   => 'formStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Background', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'background-color', 'selector' => '']],
        ];

        $this->controls['wrapBorder'] = [
            'group'   => 'formStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Border', 'ffl-funnels-addons'),
            'type'    => 'border',
            'css'     => [['property' => 'border', 'selector' => '']],
        ];

        $this->controls['wrapRadius'] = [
            'group'   => 'formStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Border radius', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'css'     => [['property' => 'border-radius', 'selector' => '']],
        ];

        $this->controls['wrapPadding'] = [
            'group'   => 'formStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Padding', 'ffl-funnels-addons'),
            'type'    => 'spacing',
            'css'     => [['property' => 'padding', 'selector' => '']],
        ];

        $this->controls['formGap'] = [
            'group'   => 'formStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Space between fields', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'css'     => [['property' => 'gap', 'selector' => '.ffla-review-form']],
        ];

        $this->controls['titleTypography'] = [
            'group'   => 'formStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Title typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__title']],
        ];

        $this->controls['introTypography'] = [
            'group'   => 'formStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Intro typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__intro']],
        ];

        // ── Style: stars ───────────────────────────────────────────
        $this->controls['starFilledColor'] = [
            'group'   => 'starsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Filled stars color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'default' => '#d4a017',
            'css'     => [['property' => '--ffla-star-filled', 'selector' => '']],
        ];

        $this->controls['starEmptyColor'] = [
            'group'   => 'starsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Empty stars color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'default' => '#d8dce5',
            'css'     => [['property' => '--ffla-star-empty', 'selector' => '']],
        ];

        $this->controls['starGlyphSize'] = [
            'group'   => 'starsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Star size', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'css'     => [['property' => 'font-size', 'selector' => '.ffla-star-rating-input__glyph']],
        ];

        $this->controls['legendTypography'] = [
            'group'   => 'starsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Rating labels typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__legend']],
        ];

        // ── Style: fields ──────────────────────────────────────────
        $this->controls['fieldLabelTypography'] = [
            'group'   => 'fieldsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Field labels typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__field label']],
        ];

        $this->controls['hintTypography'] = [
            'group'   => 'fieldsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Hint text typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__hint']],
        ];

        $this->controls['inputTypography'] = [
            'group'   => 'fieldsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Inputs & textarea typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__field input, .ffla-review-form__field textarea, .ffla-review-form__field select']],
        ];

        $this->controls['inputBackground'] = [
            'group'   => 'fieldsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Input background', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'background-color', 'selector' => '.ffla-review-form__field input, .ffla-review-form__field textarea, .ffla-review-form__field select']],
        ];

        $this->controls['inputBorder'] = [
            'group'   => 'fieldsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Input border', 'ffl-funnels-addons'),
            'type'    => 'border',
            'css'     => [['property' => 'border', 'selector' => '.ffla-review-form__field input, .ffla-review-form__field textarea, .ffla-review-form__field select']],
        ];

        $this->controls['inputRadius'] = [
            'group'   => 'fieldsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Input border radius', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'css'     => [['property' => 'border-radius', 'selector' => '.ffla-review-form__field input, .ffla-review-form__field textarea, .ffla-review-form__field select']],
        ];

        $this->controls['inputPadding'] = [
            'group'   => 'fieldsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Input padding', 'ffl-funnels-addons'),
            'type'    => 'spacing',
            'css'     => [['property' => 'padding', 'selector' => '.ffla-review-form__field input, .ffla-review-form__field textarea, .ffla-review-form__field select']],
        ];

        $this->controls['mediaBoxBackground'] = [
            'group'   => 'fieldsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Media box background', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'background-color', 'selector' => '.ffla-review-form__media-details']],
        ];

        $this->controls['mediaBoxBorder'] = [
            'group'   => 'fieldsStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Media box border', 'ffl-funnels-addons'),
            'type'    => 'border',
            'css'     => [['property' => 'border', 'selector' => '.ffla-review-form__media-details']],
        ];

        // ── Style: button ──────────────────────────────────────────
        $this->controls['submitTypography'] = [
            'group'   => 'buttonStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__submit']],
        ];

        $this->controls['submitBackground'] = [
            'group'   => 'buttonStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Background', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'background-color', 'selector' => '.ffla-review-form__submit']],
        ];

        $this->controls['submitColor'] = [
            'group'   => 'buttonStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Text color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'color', 'selector' => '.ffla-review-form__submit']],
        ];

        $this->controls['submitBorder'] = [
            'group'   => 'buttonStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Border', 'ffl-funnels-addons'),
            'type'    => 'border',
            'css'     => [['property' => 'border', 'selector' => '.ffla-review-form__submit']],
        ];

        $this->controls['submitRadius'] = [
            'group'   => 'buttonStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Border radius', 'ffl-funnels-addons'),
            'type'    => 'number',
            'units'   => true,
            'css'     => [['property' => 'border-radius', 'selector' => '.ffla-review-form__submit']],
        ];

        $this->controls['submitPadding'] = [
            'group'   => 'buttonStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Padding', 'ffl-funnels-addons'),
            'type'    => 'spacing',
            'css'     => [['property' => 'padding', 'selector' => '.ffla-review-form__submit']],
        ];

        // ── Style: notices ─────────────────────────────────────────
        $this->controls['noticeSuccessTypography'] = [
            'group'   => 'noticesStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Success notice typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__notice--success']],
        ];

        $this->controls['noticeSuccessBackground'] = [
            'group'   => 'noticesStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Success background', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'background-color', 'selector' => '.ffla-review-form__notice--success']],
        ];

        $this->controls['noticeSuccessColor'] = [
            'group'   => 'noticesStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Success text color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'color', 'selector' => '.ffla-review-form__notice--success']],
        ];

        $this->controls['noticeErrorTypography'] = [
            'group'   => 'noticesStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Error notice typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__notice--error']],
        ];

        $this->controls['noticeErrorBackground'] = [
            'group'   => 'noticesStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Error background', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'background-color', 'selector' => '.ffla-review-form__notice--error']],
        ];

        $this->controls['noticeErrorColor'] = [
            'group'   => 'noticesStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Error text color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'color', 'selector' => '.ffla-review-form__notice--error']],
        ];

        $this->controls['noticeInfoTypography'] = [
            'group'   => 'noticesStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Info notice typography', 'ffl-funnels-addons'),
            'type'    => 'typography',
            'css'     => [['property' => 'typography', 'selector' => '.ffla-review-form__notice--info']],
        ];

        $this->controls['noticeInfoBackground'] = [
            'group'   => 'noticesStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Info background', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'background-color', 'selector' => '.ffla-review-form__notice--info']],
        ];

        $this->controls['noticeInfoColor'] = [
            'group'   => 'noticesStyle',
            'tab'     => 'style',
            'label'   => esc_html__('Info text color', 'ffl-funnels-addons'),
            'type'    => 'color',
            'css'     => [['property' => 'color', 'selector' => '.ffla-review-form__notice--info']],
        ];
    }

    /**
     * @param string $name      Input name attribute.
     * @param string $id_prefix Unique prefix for input ids.
     * @param string $legend    Visible legend / aria label.
     * @param bool   $required  Whether one star must be chosen.
     */
    private function render_star_radios(string $name, string $id_prefix, string $legend, bool $required = false): void
    {
        echo '<fieldset class="ffla-review-form__fieldset ffla-star-rating-fieldset">';
        echo '<legend class="ffla-review-form__legend">' . esc_html($legend) . '</legend>';
        echo '<div class="ffla-star-rating-input" data-ffla-stars>';
        for ($i = 1; $i <= 5; $i++) {
            $id = $id_prefix . '-s' . $i;
            $req = ($required && 1 === $i) ? ' required' : '';
            echo '<span class="ffla-star-rating-input__item">';
            echo '<input class="ffla-star-rating-input__radio" type="radio" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $i) . '"' . $req . '>';
            echo '<label class="ffla-star-rating-input__label" for="' . esc_attr($id) . '">';
            echo '<span class="screen-reader-text">' . esc_html(sprintf(/* translators: %d: star rating 1–5 */ __('%d out of 5 stars', 'ffl-funnels-addons'), $i)) . '</span>';
            echo '<span class="ffla-star-rating-input__glyph" aria-hidden="true">&#9733;</span>';
            echo '</label></span>';
        }
        echo '</div></fieldset>';
    }

    public function render()
    {
        $settings = $this->settings;
        $explicit = !empty($settings['productId']) ? absint($settings['productId']) : 0;
        $product_id = \Product_Reviews_Core::resolve_context_product_id($explicit);

        if ($product_id <= 0) {
            return $this->render_element_placeholder([
                'title' => esc_html__('No product in context for this form.', 'ffl-funnels-addons'),
            ]);
        }

        $default_title = \Product_Reviews_Core::get_setting('form_title', __('Write a review', 'ffl-funnels-addons'));
        $title = isset($settings['title']) && $settings['title'] !== '' ? $settings['title'] : $default_title;
        $show_login_hint = self::setting_bool($settings, 'showLoginHint', true);
        $show_criteria   = self::setting_bool($settings, 'showOptionalCriteria', true);
        $collapse_media  = self::setting_bool($settings, 'collapseMedia', true);
        $intro = isset($settings['introText']) ? trim((string) $settings['introText']) : '';

        $status = isset($_GET['ffla_review_status']) ? sanitize_text_field(wp_unslash($_GET['ffla_review_status'])) : '';
        $message = isset($_GET['ffla_review_message']) ? sanitize_text_field(wp_unslash($_GET['ffla_review_message'])) : '';

        $uid = wp_unique_id('ffla-rf-');
        $comment_id = $uid . '-comment';

        $redirect_target = '';
        if (function_exists('wp_get_canonical_url') && is_singular()) {
            $redirect_target = (string) wp_get_canonical_url(get_queried_object_id());
        }
        if ($redirect_target === '') {
            $redirect_target = (string) get_permalink($product_id);
        }

        $this->set_attribute('_root', 'class', 'ffla-review-form-wrap');
        echo '<div ' . $this->render_attributes('_root') . '>';
        echo '<h4 class="ffla-review-form__title">' . esc_html($title) . '</h4>';

        if ($intro !== '') {
            echo '<div class="ffla-review-form__intro">' . wp_kses_post(wpautop($intro)) . '</div>';
        }

        if ($status === 'success') {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--success" role="status">' . esc_html__('Thanks! Your review was submitted.', 'ffl-funnels-addons') . '</p>';
        } elseif ($status === 'error' && $message !== '') {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--error" role="alert">' . esc_html($message) . '</p>';
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
            echo '<p class="ffla-review-form__field"><label for="' . esc_attr($uid) . '-author">' . esc_html__('Name', 'ffl-funnels-addons') . '</label>';
            echo '<input id="' . esc_attr($uid) . '-author" type="text" name="author" value="' . esc_attr($commenter['comment_author'] ?? '') . '" required autocomplete="name"></p>';
            echo '<p class="ffla-review-form__field"><label for="' . esc_attr($uid) . '-email">' . esc_html__('Email', 'ffl-funnels-addons') . '</label>';
            echo '<input id="' . esc_attr($uid) . '-email" type="email" name="email" value="' . esc_attr($commenter['comment_author_email'] ?? '') . '" required autocomplete="email"></p>';
        }

        $this->render_star_radios('rating', $uid . '-r', __('Overall rating', 'ffl-funnels-addons'), true);

        if ($show_criteria) {
            $this->render_star_radios('ffla_review_quality', $uid . '-q', __('Quality (optional)', 'ffl-funnels-addons'), false);
            $this->render_star_radios('ffla_review_value', $uid . '-v', __('Value for money (optional)', 'ffl-funnels-addons'), false);
        }

        echo '<p class="ffla-review-form__field">';
        echo '<label for="' . esc_attr($comment_id) . '">' . esc_html__('Your review', 'ffl-funnels-addons') . '</label>';
        echo '<span class="ffla-review-form__hint">' . esc_html__('Share what stood out — pros, cons, or who it is best for.', 'ffl-funnels-addons') . '</span>';
        echo '<textarea id="' . esc_attr($comment_id) . '" name="comment" rows="5" required></textarea></p>';

        if ($collapse_media) {
            echo '<details class="ffla-review-form__media-details">';
            echo '<summary class="ffla-review-form__media-summary">' . esc_html__('Add photos or a short video (optional)', 'ffl-funnels-addons') . '</summary>';
            echo '<p class="ffla-review-form__field ffla-review-form__field--media">';
            echo '<label for="' . esc_attr($uid) . '-media">' . esc_html__('Files', 'ffl-funnels-addons') . '</label>';
            echo '<input id="' . esc_attr($uid) . '-media" name="ffla_review_media[]" type="file" accept="image/*,video/mp4,video/webm" multiple>';
            echo '<small>' . esc_html__('Up to 3 files, 5 MB each.', 'ffl-funnels-addons') . '</small></p>';
            echo '</details>';
        } else {
            echo '<p class="ffla-review-form__field ffla-review-form__field--media">';
            echo '<label for="' . esc_attr($uid) . '-media">' . esc_html__('Photos / video (optional)', 'ffl-funnels-addons') . '</label>';
            echo '<input id="' . esc_attr($uid) . '-media" name="ffla_review_media[]" type="file" accept="image/*,video/mp4,video/webm" multiple>';
            echo '<small>' . esc_html__('Up to 3 files, 5 MB each.', 'ffl-funnels-addons') . '</small></p>';
        }

        if (\Product_Reviews_Core::is_turnstile_enabled()) {
            echo '<div class="ffla-review-turnstile-wrap">';
            echo '<div class="cf-turnstile" data-sitekey="' . esc_attr(\Product_Reviews_Core::get_turnstile_site_key()) . '" data-theme="auto"></div>';
            echo '</div>';
        }

        echo '<input type="hidden" name="comment_post_ID" value="' . esc_attr((string) $product_id) . '">';
        echo '<input type="hidden" name="comment_parent" value="0">';
        echo '<input type="hidden" name="redirect_to" value="' . esc_url($redirect_target) . '">';
        echo '<button class="ffla-review-form__submit" type="submit">' . esc_html__('Submit review', 'ffl-funnels-addons') . '</button>';

        echo '</form>';
        echo '</div>';
    }
}
