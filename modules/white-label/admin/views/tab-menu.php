<?php
/**
 * White Label — Menu tab (view).
 *
 * Drag-to-reorder the top-level sidebar menu, and add any number of dividers
 * anywhere. The order is captured by the DOM order of the hidden inputs, so a
 * single Save persists whatever order (and dividers) you arrange.
 *
 * @var array<int, array{type:string, slug:string, label?:string}> $menu_rows
 * @var string                                                      $divider_prefix
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wb-card">
    <div class="wb-card__header"><h3><?php esc_html_e('Sidebar order', 'ffl-funnels-addons'); ?></h3></div>
    <div class="wb-card__body">
        <p class="wb-field__desc">
            <?php esc_html_e('Drag the items to reorder the top-level sidebar menu, and add dividers wherever you like. Menus added later (e.g. a new plugin) appear at the bottom until you move them.', 'ffl-funnels-addons'); ?>
        </p>
        <p class="wb-field__desc">
            <?php esc_html_e('This order (and hiding the default WordPress separators) applies to clients only. Staff matched by the exempt emails keep the native menu, so you will not see your changes in your own sidebar — check them while logged in as a client account.', 'ffl-funnels-addons'); ?>
        </p>

        <div class="ffla-wl-menu-toolbar">
            <button type="button" class="wb-btn" data-ffla-wl-add-divider>
                <span class="dashicons dashicons-minus" aria-hidden="true"></span>
                <?php esc_html_e('Add divider', 'ffl-funnels-addons'); ?>
            </button>
        </div>

        <ul class="ffla-wl-sortable" data-ffla-wl-sortable data-ffla-wl-divider-prefix="<?php echo esc_attr($divider_prefix); ?>">
            <?php foreach ($menu_rows as $row) : ?>
                <?php if ('divider' === $row['type']) : ?>
                    <li class="ffla-wl-sortable__item ffla-wl-sortable__divider" draggable="true">
                        <input type="hidden" name="ffla_wl[menu][top][]" value="<?php echo esc_attr($row['slug']); ?>">
                        <span class="ffla-wl-sortable__handle" aria-hidden="true">⠿</span>
                        <span class="ffla-wl-sortable__label ffla-wl-sortable__label--divider"><?php esc_html_e('Divider', 'ffl-funnels-addons'); ?></span>
                        <button type="button" class="ffla-wl-sortable__remove" data-ffla-wl-remove aria-label="<?php esc_attr_e('Remove divider', 'ffl-funnels-addons'); ?>">
                            <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                        </button>
                    </li>
                <?php else : ?>
                    <li class="ffla-wl-sortable__item" draggable="true">
                        <input type="hidden" name="ffla_wl[menu][top][]" value="<?php echo esc_attr($row['slug']); ?>">
                        <span class="ffla-wl-sortable__handle" aria-hidden="true">⠿</span>
                        <span class="ffla-wl-sortable__label"><?php echo esc_html($row['label']); ?></span>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>

        <?php // Template cloned by the JS when adding a new divider. ?>
        <template data-ffla-wl-divider-template>
            <li class="ffla-wl-sortable__item ffla-wl-sortable__divider" draggable="true">
                <input type="hidden" name="ffla_wl[menu][top][]" value="">
                <span class="ffla-wl-sortable__handle" aria-hidden="true">⠿</span>
                <span class="ffla-wl-sortable__label ffla-wl-sortable__label--divider"><?php esc_html_e('Divider', 'ffl-funnels-addons'); ?></span>
                <button type="button" class="ffla-wl-sortable__remove" data-ffla-wl-remove aria-label="<?php esc_attr_e('Remove divider', 'ffl-funnels-addons'); ?>">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </li>
        </template>
    </div>
</div>
