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
        echo '<div class="ffla-dashboard">';

        // Welcome card.
        echo '<div class="wb-card">';
        echo '<div class="wb-card__header"><h2>' . esc_html__('Modules', 'ffl-funnels-addons') . '</h2></div>';
        echo '<div class="wb-card__body">';
        echo '<p class="wb-field__desc">' . esc_html__('Activate or deactivate modules. Each module adds its own settings pages to the sidebar when active.', 'ffl-funnels-addons') . '</p>';
        echo '</div></div>';

        // Module cards grid.
        echo '<div class="ffla-modules-grid">';

        foreach ($this->registry->get_all() as $module) {
            $this->render_module_card($module);
        }

        echo '</div>'; // .ffla-modules-grid
        echo '</div>'; // .ffla-dashboard
    }

    /**
     * Render a single module card.
     */
    private function render_module_card(FFLA_Module $module): void
    {
        $is_active = $this->registry->is_active($module->get_id());
        $status_class = $is_active ? 'wb-status--active' : 'wb-status--inactive';
        $status_label = $is_active ? __('Active', 'ffl-funnels-addons') : __('Inactive', 'ffl-funnels-addons');

        echo '<div class="wb-card ffla-module-card' . ($is_active ? ' ffla-module-card--active' : '') . '">';
        echo '<div class="wb-card__body">';

        // Icon + title row.
        echo '<div class="ffla-module-card__header">';
        echo '<div class="ffla-module-card__icon">' . $module->get_icon_svg() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="ffla-module-card__info">';
        echo '<h3 class="ffla-module-card__title">' . esc_html($module->get_name()) . '</h3>';
        echo '<span class="wb-status ' . esc_attr($status_class) . '">' . esc_html($status_label) . '</span>';
        echo '</div>';
        echo '<div class="ffla-module-card__toggle">';
        echo '<label class="wb-toggle">';
        echo '<input type="checkbox" class="ffla-module-toggle" data-module="' . esc_attr($module->get_id()) . '"' . checked($is_active, true, false) . '>';
        echo '<span class="wb-toggle__slider"></span>';
        echo '</label>';
        echo '</div>';
        echo '</div>'; // header

        // Description.
        echo '<p class="ffla-module-card__desc">' . esc_html($module->get_description()) . '</p>';

        // Footer: version + settings link.
        echo '<div class="ffla-module-card__footer">';
        echo '<span class="ffla-module-card__version">v' . esc_html($module->get_version()) . '</span>';

        if ($is_active) {
            $pages = $module->get_admin_pages();
            if (!empty($pages)) {
                $first_page = reset($pages);
                echo '<a href="' . esc_url(admin_url('admin.php?page=' . $first_page['slug'])) . '" class="wb-btn wb-btn--subtle wb-btn--xs">';
                echo esc_html__('Settings', 'ffl-funnels-addons');
                echo '</a>';
            }
        }

        echo '</div>'; // footer
        echo '</div>'; // .wb-card__body
        echo '</div>'; // .wb-card
    }
}
