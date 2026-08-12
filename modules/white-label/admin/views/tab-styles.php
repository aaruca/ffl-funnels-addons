<?php
/**
 * White Label — Styles tab (view).
 *
 * Each colour has a Light and a Dark value; the admin-bar toggle switches which
 * set applies. Each control is a hex text input (blank = unset) with a native
 * swatch and a Clear button, kept in sync by the module JS.
 *
 * @var array<string, array<string, string>> $style_fields  group label => (key => label)
 * @var array{light: array<string,string>, dark: array<string,string>} $style_values
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render one swatch control (text + native swatch + clear).
 *
 * @param string $name
 * @param string $value
 */
$render_swatch = static function (string $name, string $value) {
    $swatch = '' !== $value ? $value : '#000000';
    ?>
    <span class="ffla-wl-color" data-ffla-wl-color>
        <input type="color" class="ffla-wl-color__swatch" value="<?php echo esc_attr($swatch); ?>"
            data-ffla-wl-color-swatch tabindex="-1" aria-hidden="true">
        <input type="text" name="<?php echo esc_attr($name); ?>"
            class="wb-input ffla-wl-color__text" value="<?php echo esc_attr($value); ?>"
            placeholder="#rrggbb" spellcheck="false" autocomplete="off" data-ffla-wl-color-text>
        <button type="button" class="wb-btn wb-btn--subtle ffla-wl-color__clear" data-ffla-wl-color-clear>
            <?php esc_html_e('Clear', 'ffl-funnels-addons'); ?>
        </button>
    </span>
    <?php
};

/**
 * Render a field row: label + a Light and a Dark swatch.
 *
 * @param string                                                          $key
 * @param string                                                          $label
 * @param array{light: array<string,string>, dark: array<string,string>} $values
 */
$render_field = static function (string $key, string $label, array $values) use ($render_swatch) {
    $light = isset($values['light'][$key]) ? (string) $values['light'][$key] : '';
    $dark  = isset($values['dark'][$key]) ? (string) $values['dark'][$key] : '';
    ?>
    <div class="wb-field ffla-wl-color-pair">
        <label class="wb-field__label"><?php echo esc_html($label); ?></label>
        <div class="wb-field__control ffla-wl-color-pair__modes">
            <div class="ffla-wl-color-pair__mode">
                <span class="ffla-wl-color-pair__tag"><?php esc_html_e('Light', 'ffl-funnels-addons'); ?></span>
                <?php $render_swatch('ffla_wl[styles][light][' . $key . ']', $light); ?>
            </div>
            <div class="ffla-wl-color-pair__mode">
                <span class="ffla-wl-color-pair__tag"><?php esc_html_e('Dark', 'ffl-funnels-addons'); ?></span>
                <?php $render_swatch('ffla_wl[styles][dark][' . $key . ']', $dark); ?>
            </div>
        </div>
    </div>
    <?php
};
?>

<div class="wb-card">
    <div class="wb-card__body">
        <p class="wb-field__desc">
            <?php esc_html_e('Set a Light and a Dark colour for each item. The sun/moon toggle in the top bar switches between them. Leave a colour blank to keep the WordPress default; hover/current backgrounds inherit the Primary colour when left blank.', 'ffl-funnels-addons'); ?>
        </p>
    </div>
</div>

<?php foreach ($style_fields as $group_label => $fields) : ?>
    <div class="wb-card">
        <div class="wb-card__header"><h3><?php echo esc_html($group_label); ?></h3></div>
        <div class="wb-card__body">
            <?php foreach ($fields as $key => $label) : ?>
                <?php $render_field($key, $label, $style_values); ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
