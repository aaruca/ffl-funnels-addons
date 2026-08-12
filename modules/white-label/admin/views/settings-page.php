<?php
/**
 * White Label — settings page layout (view).
 *
 * Markup only. Renders a module header, the tab navigation, and a single form
 * containing one panel per tab plus one Save button. Tabs are switched by JS
 * (admin/js/white-label-module.js); everything posts together.
 *
 * @var array<string, string> $tabs        slug => label
 * @var string                $active_tab
 * @var array<string, mixed>  $settings
 * @var bool                  $was_saved
 * @var string                $form_action
 * @var string                $save_action
 * @var string                $nonce_action
 * @var string                $nonce_field
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!empty($was_saved)) {
    FFLA_Admin::render_notice('success', __('Settings saved.', 'ffl-funnels-addons'));
}
?>

<div class="ffla-wl-header">
    <h2 class="ffla-wl-header__title"><?php esc_html_e('White Label', 'ffl-funnels-addons'); ?></h2>
    <p class="ffla-wl-header__desc">
        <?php esc_html_e('Brand and lock down wp-admin per user — login/admin styling, per-role menus, admin-bar control, and access restrictions.', 'ffl-funnels-addons'); ?>
    </p>
</div>

<div class="ffla-wl" data-ffla-wl>
    <nav class="ffla-wl-tabs" role="tablist">
        <?php foreach ($tabs as $tab_slug => $tab_label) : ?>
            <button type="button"
                class="ffla-wl-tab<?php echo ($tab_slug === $active_tab) ? ' is-active' : ''; ?>"
                data-ffla-wl-tab="<?php echo esc_attr($tab_slug); ?>">
                <?php echo esc_html($tab_label); ?>
            </button>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="<?php echo esc_url($form_action); ?>" class="ffla-wl-form">
        <input type="hidden" name="action" value="<?php echo esc_attr($save_action); ?>">
        <input type="hidden" name="active_tab" value="<?php echo esc_attr($active_tab); ?>" data-ffla-wl-active-tab>
        <?php wp_nonce_field($nonce_action, $nonce_field); ?>

        <?php foreach ($tabs as $tab_slug => $tab_label) : ?>
            <?php if ('import-export' === $tab_slug) { continue; } // Rendered outside the form (it has its own forms). ?>
            <div class="ffla-wl-panel" data-ffla-wl-panel="<?php echo esc_attr($tab_slug); ?>" <?php echo ($tab_slug === $active_tab) ? '' : 'hidden'; ?>>
                <?php if ('styles' === $tab_slug) : ?>
                    <?php include __DIR__ . '/tab-styles.php'; ?>
                <?php elseif ('menu' === $tab_slug) : ?>
                    <?php include __DIR__ . '/tab-menu.php'; ?>
                <?php elseif ('dashboard' === $tab_slug) : ?>
                    <?php include __DIR__ . '/tab-dashboard.php'; ?>
                <?php elseif ('restrictions' === $tab_slug) : ?>
                    <?php include __DIR__ . '/tab-restrictions.php'; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="wb-actions-bar">
            <button type="submit" class="wb-btn wb-btn--primary"><?php esc_html_e('Save Settings', 'ffl-funnels-addons'); ?></button>
        </div>
    </form>

    <?php // Import / Export lives outside the main form: export is a download link and import is its own upload form. ?>
    <div class="ffla-wl-panel" data-ffla-wl-panel="import-export" <?php echo ('import-export' === $active_tab) ? '' : 'hidden'; ?>>
        <?php include __DIR__ . '/tab-import-export.php'; ?>
    </div>
</div>
