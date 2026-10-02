<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * [loadout id="12"] / [loadout slug="ar-15-build"] — a global loadout on any
 * page, without Bricks. Uses the same renderer as the Bricks elements.
 */
class Loadout_Shortcode
{
    public static function init(): void
    {
        add_shortcode('loadout', [__CLASS__, 'render']);
    }

    public static function render($atts): string
    {
        $atts = shortcode_atts([
            'id' => 0,
            'slug' => '',
            'default_tier' => 0,
            'show_cart' => 'yes',
            'show_cross_sells' => 'yes',
        ], $atts, 'loadout');

        $loadout = null;
        if (!empty($atts['slug'])) {
            $loadout = Loadout::get_by_slug($atts['slug']);
        } elseif (!empty($atts['id'])) {
            $loadout = Loadout::get(absint($atts['id']));
        }

        if (!$loadout || !$loadout->get_status()) {
            return '';
        }

        $loadout_id = (int) $loadout->get_id();
        $tiers      = Loadout_Element_Helpers::global_tiers($loadout_id);
        if (empty($tiers)) {
            return '';
        }

        if (class_exists('Loadout_Frontend')) {
            Loadout_Frontend::enqueue();
        }

        $default_index    = absint($atts['default_tier']);
        $show_cart        = $atts['show_cart'] !== 'no';
        $show_cross_sells = $atts['show_cross_sells'] !== 'no';
        $thresholds       = array_map(function ($tier) {
            return ['id' => $tier['id'], 'slug' => $tier['slug'], 'threshold' => $tier['threshold']];
        }, $tiers);

        ob_start();
        ?>
        <div class="ffla-loadout" data-loadout-id="<?php echo esc_attr($loadout_id); ?>" data-tiers="<?php echo esc_attr(wp_json_encode($thresholds)); ?>">
            <?php Loadout_Element_Helpers::render_header($loadout); ?>

            <div class="ffla-loadout__progress">
                <div class="ffla-loadout__progress-track">
                    <div class="ffla-loadout__progress-bar" style="width:0%;"></div>
                </div>
                <span class="ffla-loadout__progress-label"></span>
            </div>

            <?php Loadout_Element_Helpers::render_tabs($tiers, $default_index); ?>

            <div class="ffla-loadout__body">
                <?php Loadout_Element_Helpers::render_loadout_anchor($loadout); ?>
                <?php Loadout_Element_Helpers::render_recommended_section($tiers, $default_index); ?>

                <?php if ($show_cart): ?>
                    <aside class="ffla-loadout__cart">
                        <h3><?php esc_html_e('Your Cart', 'ffl-funnels-addons'); ?></h3>
                        <div class="ffla-loadout__cart-summary"></div>
                    </aside>
                <?php endif; ?>
            </div>

            <?php if ($show_cross_sells) {
                Loadout_Element_Helpers::render_cross_sells($loadout_id);
            } ?>

            <footer class="ffla-loadout__checkout">
                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="ffla-loadout__checkout-btn">
                    <?php esc_html_e('PROCEED TO CHECKOUT', 'ffl-funnels-addons'); ?>
                </a>
            </footer>
        </div>
        <?php
        return ob_get_clean();
    }
}
