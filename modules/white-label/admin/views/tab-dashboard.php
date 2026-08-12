<?php
/**
 * White Label — Dashboard tab (view).
 *
 * @var array{enabled: bool, links: array<string, string>} $dashboard
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

$links = $dashboard['links'];
?>

<div class="wb-card">
    <div class="wb-card__header"><h3><?php esc_html_e('Custom dashboard', 'ffl-funnels-addons'); ?></h3></div>
    <div class="wb-card__body">
        <?php
        FFLA_Admin::render_toggle_field(
            __('Replace the WordPress dashboard', 'ffl-funnels-addons'),
            'ffla_wl[dashboard][enabled]',
            !empty($dashboard['enabled']) ? '1' : '0',
            __('Turns /wp-admin/ into the branded client dashboard: quick links, live sales & search stats, and none of the default or plugin widgets.', 'ffl-funnels-addons')
        );
        ?>
    </div>
</div>

<div class="wb-card">
    <div class="wb-card__header"><h3><?php esc_html_e('Quick-link cards', 'ffl-funnels-addons'); ?></h3></div>
    <div class="wb-card__body">
        <p class="wb-field__desc"><?php esc_html_e('Set the URL for each card. Leave one blank to hide that card.', 'ffl-funnels-addons'); ?></p>

        <?php
        FFLA_Admin::render_text_field(
            __('Support', 'ffl-funnels-addons'),
            'ffla_wl[dashboard][links][support]',
            $links['support'],
            __('e.g. https://support.fflfunnels.com', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Knowledge Base', 'ffl-funnels-addons'),
            'ffla_wl[dashboard][links][knowledge_base]',
            $links['knowledge_base'],
            __('Your ClickUp (or other) knowledge-base URL.', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Cockpit', 'ffl-funnels-addons'),
            'ffla_wl[dashboard][links][cockpit]',
            $links['cockpit'],
            __('e.g. admin.php?page=g-ffl-cockpit-settings', 'ffl-funnels-addons')
        );

        FFLA_Admin::render_text_field(
            __('Command Center', 'ffl-funnels-addons'),
            'ffla_wl[dashboard][links][command_center]',
            $links['command_center'],
            __('Link to your Command Center (email marketing).', 'ffl-funnels-addons')
        );
        ?>
    </div>
</div>
