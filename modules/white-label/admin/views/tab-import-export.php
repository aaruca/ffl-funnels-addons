<?php
/**
 * White Label — Import / Export tab (view).
 *
 * Two independent actions, each with its own posting mechanism (they can't share
 * the main settings form): Export streams a .json download via a nonced link,
 * Import uploads a file or pasted JSON to its own admin-post handler.
 *
 * @var string                                       $export_json
 * @var string                                       $export_url
 * @var string                                       $form_action
 * @var string                                       $import_action
 * @var string                                       $import_nonce_action
 * @var string                                       $import_nonce_field
 * @var array{type:string, message:string}|null      $import_notice
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!empty($import_notice) && is_array($import_notice)) {
    FFLA_Admin::render_notice($import_notice['type'], $import_notice['message']);
}
?>

<div class="wb-card">
    <div class="wb-card__header"><h3><?php esc_html_e('Export settings', 'ffl-funnels-addons'); ?></h3></div>
    <div class="wb-card__body">
        <p class="wb-field__desc">
            <?php esc_html_e('Download this site’s White Label configuration as a .json file, or copy it below to paste into another site.', 'ffl-funnels-addons'); ?>
        </p>

        <textarea class="ffla-wl-io__json" rows="10" readonly onclick="this.select()"><?php echo esc_textarea($export_json); ?></textarea>

        <div class="ffla-wl-io__actions">
            <a class="wb-btn wb-btn--primary" href="<?php echo esc_url($export_url); ?>">
                <?php esc_html_e('Download .json', 'ffl-funnels-addons'); ?>
            </a>
            <button type="button" class="wb-btn" data-ffla-wl-copy>
                <?php esc_html_e('Copy to clipboard', 'ffl-funnels-addons'); ?>
            </button>
        </div>
    </div>
</div>

<div class="wb-card">
    <div class="wb-card__header"><h3><?php esc_html_e('Import settings', 'ffl-funnels-addons'); ?></h3></div>
    <div class="wb-card__body">
        <p class="wb-field__desc">
            <?php esc_html_e('Upload an exported .json file, or paste its contents below. This replaces the current Styles, Menu, Dashboard, and Restrictions settings — it can’t be undone, so export a backup first.', 'ffl-funnels-addons'); ?>
        </p>

        <form method="post"
            action="<?php echo esc_url($form_action); ?>"
            enctype="multipart/form-data"
            onsubmit="return window.confirm('<?php echo esc_js(__('Replace the current White Label settings with the imported ones?', 'ffl-funnels-addons')); ?>');">
            <input type="hidden" name="action" value="<?php echo esc_attr($import_action); ?>">
            <?php wp_nonce_field($import_nonce_action, $import_nonce_field); ?>

            <p>
                <label class="ffla-wl-io__label"><?php esc_html_e('Choose a .json file', 'ffl-funnels-addons'); ?></label>
                <input type="file" name="ffla_wl_import_file" accept="application/json,.json">
            </p>

            <p>
                <label class="ffla-wl-io__label"><?php esc_html_e('…or paste JSON', 'ffl-funnels-addons'); ?></label>
                <textarea class="ffla-wl-io__json" name="ffla_wl_import_json" rows="8" placeholder="<?php esc_attr_e('Paste exported JSON here', 'ffl-funnels-addons'); ?>"></textarea>
            </p>

            <div class="ffla-wl-io__actions">
                <button type="submit" class="wb-btn wb-btn--primary"><?php esc_html_e('Import settings', 'ffl-funnels-addons'); ?></button>
            </div>
        </form>
    </div>
</div>
