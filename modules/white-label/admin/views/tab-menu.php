<?php
/**
 * White Label — Menu tab (view).
 *
 * Drag-to-reorder the top-level sidebar menu. The order is captured by the DOM
 * order of the hidden inputs, so a single Save persists whatever order you drag
 * them into.
 *
 * @var array<string, string> $menu_items  slug => label, in current order
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
            <?php esc_html_e('Drag the items to reorder the top-level sidebar menu. This applies to everyone. Menus added later (e.g. a new plugin) appear at the bottom until you move them.', 'ffl-funnels-addons'); ?>
        </p>

        <ul class="ffla-wl-sortable" data-ffla-wl-sortable>
            <?php foreach ($menu_items as $slug => $label) : ?>
                <li class="ffla-wl-sortable__item" draggable="true">
                    <input type="hidden" name="ffla_wl[menu][top][]" value="<?php echo esc_attr($slug); ?>">
                    <span class="ffla-wl-sortable__handle" aria-hidden="true">⠿</span>
                    <span class="ffla-wl-sortable__label"><?php echo esc_html($label); ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
