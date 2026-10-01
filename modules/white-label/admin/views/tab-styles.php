<?php
/**
 * White Label — Styles tab (view).
 *
 * Each colour has a Light and a Dark value; the admin-bar toggle switches which
 * set applies. Each control is a hex text input (blank = unset) with a native
 * swatch, kept in sync by the module JS. Each input carries the mode's default
 * (data-ffla-wl-default) so the live preview can show what a blank field falls
 * back to.
 *
 * @var array<string, array<string, string>> $style_fields  group label => (key => label)
 * @var array{light: array<string,string>, dark: array<string,string>} $style_values
 * @var array{light: array<string,string>, dark: array<string,string>} $style_defaults
 * @var int|null                                                        $dash_radius
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render one swatch control (text + native swatch).
 *
 * @param string $name
 * @param string $value   Saved value ('' = unset).
 * @param string $default The mode's default for this key ('' = none; the CSS
 *                        fallback applies, e.g. hover colours inherit Primary).
 */
$render_swatch = static function (string $name, string $value, string $default = '') {
    // The native swatch only accepts #rrggbb: expand #rgb, and show the
    // mode's default when the value is unset.
    $to_swatch = static function (string $hex): string {
        $hex = trim($hex);
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return $hex;
        }
        if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $hex, $m)) {
            return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        return '';
    };
    $swatch = $to_swatch($value);
    if ('' === $swatch) {
        $swatch = '' !== $to_swatch($default) ? $to_swatch($default) : '#000000';
    }
    ?>
    <span class="ffla-wl-color" data-ffla-wl-color>
        <input type="color" class="ffla-wl-color__swatch" value="<?php echo esc_attr($swatch); ?>"
            data-ffla-wl-color-swatch tabindex="-1" aria-hidden="true">
        <input type="text" name="<?php echo esc_attr($name); ?>"
            class="wb-input ffla-wl-color__text" value="<?php echo esc_attr($value); ?>"
            placeholder="<?php echo esc_attr('' !== $default ? $default : '#rrggbb'); ?>"
            data-ffla-wl-default="<?php echo esc_attr($default); ?>"
            spellcheck="false" autocomplete="off" data-ffla-wl-color-text>
    </span>
    <?php
};

/**
 * Render a field row: label + a Light and a Dark swatch.
 *
 * @param string                                                          $key
 * @param string                                                          $label
 * @param array{light: array<string,string>, dark: array<string,string>} $values
 * @param array{light: array<string,string>, dark: array<string,string>} $defaults
 */
$render_field = static function (string $key, string $label, array $values, array $defaults) use ($render_swatch) {
    $light = isset($values['light'][$key]) ? (string) $values['light'][$key] : '';
    $dark  = isset($values['dark'][$key]) ? (string) $values['dark'][$key] : '';
    $light_default = isset($defaults['light'][$key]) ? (string) $defaults['light'][$key] : '';
    $dark_default  = isset($defaults['dark'][$key]) ? (string) $defaults['dark'][$key] : '';
    ?>
    <div class="wb-field ffla-wl-color-pair">
        <label class="wb-field__label"><?php echo esc_html($label); ?></label>
        <div class="wb-field__control ffla-wl-color-pair__modes">
            <div class="ffla-wl-color-pair__mode">
                <span class="ffla-wl-color-pair__tag"><?php esc_html_e('Light', 'ffl-funnels-addons'); ?></span>
                <?php $render_swatch('ffla_wl[styles][light][' . $key . ']', $light, $light_default); ?>
            </div>
            <div class="ffla-wl-color-pair__mode">
                <span class="ffla-wl-color-pair__tag"><?php esc_html_e('Dark', 'ffl-funnels-addons'); ?></span>
                <?php $render_swatch('ffla_wl[styles][dark][' . $key . ']', $dark, $dark_default); ?>
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
            <?php if ($group_label === __('Dashboard', 'ffl-funnels-addons')) : ?>
                <div class="wb-field ffla-wl-radius-field">
                    <label class="wb-field__label" for="ffla-wl-dash-radius"><?php esc_html_e('Base corner radius', 'ffl-funnels-addons'); ?></label>
                    <div class="wb-field__control">
                        <div class="ffla-wl-radius-input">
                            <input type="number" min="0" max="40" step="1" id="ffla-wl-dash-radius"
                                name="ffla_wl[styles][dashRadius]" class="wb-input"
                                value="<?php echo esc_attr(null === $dash_radius ? '' : (string) $dash_radius); ?>"
                                placeholder="14">
                            <span class="ffla-wl-radius-unit">px</span>
                        </div>
                        <p class="wb-field__desc"><?php esc_html_e('Corner radius for the dashboard cards; smaller items scale from it. Set 0 for square corners. Leave blank for the default.', 'ffl-funnels-addons'); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            <?php foreach ($fields as $key => $label) : ?>
                <?php $render_field($key, $label, $style_values, isset($style_defaults) && is_array($style_defaults) ? $style_defaults : []); ?>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
