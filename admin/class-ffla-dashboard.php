<?php
/**
 * FFLA Dashboard — the main page showing all module cards with toggle switches.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Dashboard
{
    /** @var FFLA_Module_Registry */
    private $registry;

    public function __construct(FFLA_Module_Registry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Render the dashboard content (inside the shared shell).
     */
    public function render(): void
    {
        $active = [];
        $available = [];
        foreach ($this->registry->get_all() as $module) {
            if ($this->registry->is_active($module->get_id())) {
                $active[] = $module;
            } else {
                $available[] = $module;
            }
        }

        echo '<div class="ffla-dashboard">';
        echo '<div class="ffla-page-head"><h2>' . esc_html__('Modules', 'ffl-funnels-addons') . '</h2>';
        echo '<p>' . esc_html__('Turn on the modules this store uses. Each active module adds its settings to the sidebar; turning one off keeps its saved settings.', 'ffl-funnels-addons') . '</p></div>';

        foreach ([[__('Active', 'ffl-funnels-addons'), $active], [__('Available', 'ffl-funnels-addons'), $available]] as [$label, $modules]) {
            if (!$modules) {
                continue;
            }
            echo '<section class="ffla-modules-section">';
            echo '<h3 class="ffla-modules-section__title">' . esc_html($label) . ' <span class="ffla-modules-section__count">' . esc_html((string) count($modules)) . '</span></h3>';
            echo '<div class="ffla-modules-grid">';
            foreach ($modules as $module) {
                $this->render_module_card($module);
            }
            echo '</div></section>';
        }

        $this->render_updates_card();

        echo '</div>'; // .ffla-dashboard
    }

    /**
     * Render the Plugin Updates card.
     */
    private function render_updates_card(): void
    {
        echo '<div class="wb-card ffla-updates-card">';
        echo '<div class="wb-card__header"><h2>' . esc_html__('Plugin updates', 'ffl-funnels-addons') . '</h2></div>';
        echo '<div class="wb-card__body">';
        echo '<p>' . esc_html__('Current version:', 'ffl-funnels-addons') . ' <strong>v' . esc_html(FFLA_VERSION) . '</strong></p>';
        echo '<p class="wb-field__desc">' . esc_html__('Click below to check GitHub for new releases. WordPress checks automatically every 12 hours.', 'ffl-funnels-addons') . '</p>';
        echo '<div style="margin-top:12px;display:flex;align-items:center;gap:12px;">';
        echo '<button type="button" id="ffla-check-update" class="wb-btn wb-btn--subtle">' . esc_html__('Check for updates', 'ffl-funnels-addons') . '</button>';
        echo '<span id="ffla-update-result"></span>';
        echo '</div>';

        // GitHub token status.
        $token_defined = defined('FFLA_GITHUB_TOKEN') && FFLA_GITHUB_TOKEN;
        if ($token_defined) {
            echo '<p style="margin-top:12px;"><span class="wb-status wb-status--active">' . esc_html__('GitHub token configured', 'ffl-funnels-addons') . '</span></p>';
        }

        echo '</div></div>';
    }

    /**
     * Render a single module card.
     */
    private function render_module_card(FFLA_Module $module): void
    {
        $is_active = $this->registry->is_active($module->get_id());
        $status_label = $is_active ? __('Active', 'ffl-funnels-addons') : __('Inactive', 'ffl-funnels-addons');

        echo '<div class="wb-card ffla-module-card' . ($is_active ? ' ffla-module-card--active' : '') . '">';
        echo '<div class="wb-card__body">';

        // Icon + title row.
        echo '<div class="ffla-module-card__header">';
        echo '<div class="ffla-module-card__icon">' . $module->get_icon_svg() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="ffla-module-card__info">';
        echo '<h3 class="ffla-module-card__title">' . esc_html($module->get_name()) . '</h3>';
        echo '<span class="screen-reader-text">' . esc_html($status_label) . '</span>';
        echo '</div>';
        echo '<div class="ffla-module-card__toggle">';
        $toggle_label = sprintf(
            /* translators: %s: module name. */
            __('Enable %s module', 'ffl-funnels-addons'),
            $module->get_name()
        );
        echo '<label class="wb-toggle">';
        echo '<span class="screen-reader-text">' . esc_html($toggle_label) . '</span>';
        echo '<input type="checkbox" class="ffla-module-toggle" data-module="' . esc_attr($module->get_id()) . '"'
            . ' aria-label="' . esc_attr($toggle_label) . '"'
            . checked($is_active, true, false) . '>';
        echo '<span class="wb-toggle__slider" aria-hidden="true"></span>';
        echo '</label>';
        echo '</div>';
        echo '</div>'; // header

        // Description.
        echo '<p class="ffla-module-card__desc">' . esc_html($module->get_description()) . '</p>';

        // Footer: settings link.
        $pages = $is_active ? $module->get_admin_pages() : [];
        if (!empty($pages)) {
            $first_page = reset($pages);
            echo '<div class="ffla-module-card__footer">';
            echo '<a href="' . esc_url(admin_url('admin.php?page=' . $first_page['slug'])) . '" class="ffla-module-card__link">' . esc_html__('Open settings', 'ffl-funnels-addons') . '</a>';
            echo '</div>';
        }
        echo '</div>'; // .wb-card__body
        echo '</div>'; // .wb-card
    }
}
