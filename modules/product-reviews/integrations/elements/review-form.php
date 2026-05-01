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

        $form_settings = [
            'title'                 => isset($settings['title']) ? (string) $settings['title'] : '',
            'showLoginHint'         => self::setting_bool($settings, 'showLoginHint', true),
            'showOptionalCriteria'  => self::setting_bool($settings, 'showOptionalCriteria', true),
            'collapseMedia'         => self::setting_bool($settings, 'collapseMedia', true),
            'introText'             => isset($settings['introText']) ? trim((string) $settings['introText']) : '',
        ];

        $this->set_attribute('_root', 'class', 'ffla-review-form-wrap');
        echo '<div ' . $this->render_attributes('_root') . '>';
        \Product_Reviews_Frontend_Render::render_review_form($product_id, $form_settings, false);
        echo '</div>';
    }
}
