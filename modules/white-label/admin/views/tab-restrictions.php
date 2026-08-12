<?php
/**
 * White Label — Restrictions tab (view).
 *
 * Two sections: Access (which staff are exempt, by email pattern) and Menu
 * visibility (a check-tree of admin menu items to hide + block from clients).
 *
 * @var string                            $exempt_emails       one pattern per line
 * @var array<int, array<string, mixed>>  $menu_tree
 * @var array<int, string>                $hidden_menu         slugs to hide
 * @var array<string, string>             $adminbar_nodes      id => label
 * @var array<int, string>                $hidden_adminbar     node ids to remove
 * @var bool                              $superusers_defined  FFLA_WL_SUPERUSERS present
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wb-card">
    <div class="wb-card__body">
        <?php
        FFLA_Admin::render_notice(
            'warning',
            __('Your clients are Administrators. Restrictions apply to everyone who is <strong>not</strong> exempt below. Add your own email (or an <code>*@yourdomain.com</code> pattern) before you rely on this, or you could lock yourself out of these settings.', 'ffl-funnels-addons')
        );
        ?>
    </div>
</div>

<div class="wb-card">
    <div class="wb-card__header"><h3><?php esc_html_e('Exempt staff (by email)', 'ffl-funnels-addons'); ?></h3></div>
    <div class="wb-card__body">
        <?php
        FFLA_Admin::render_textarea_field(
            __('Exempt email patterns', 'ffl-funnels-addons'),
            'ffla_wl[restrictions][exempt_emails]',
            $exempt_emails,
            __('One per line. Use * as a wildcard — e.g. *@fflfunnels.com, adeel*, or a full address. Anyone matching keeps full, unrestricted access; everyone else is treated as a client.', 'ffl-funnels-addons')
        );
        ?>

        <?php if ($superusers_defined) : ?>
            <?php FFLA_Admin::render_notice('info', __('An FFLA_WL_SUPERUSERS safety-net list is also defined in wp-config.php and is always exempt.', 'ffl-funnels-addons')); ?>
        <?php else : ?>
            <p class="wb-field__desc">
                <?php esc_html_e('Tip: for a tamper-proof safety net, also add define( \'FFLA_WL_SUPERUSERS\', [ \'you@agency.com\' ] ); to wp-config.php — clients can never edit that.', 'ffl-funnels-addons'); ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="wb-card">
    <div class="wb-card__header"><h3><?php esc_html_e('Menu visibility', 'ffl-funnels-addons'); ?></h3></div>
    <div class="wb-card__body">
        <p class="wb-field__desc">
            <?php esc_html_e('Check any item to hide it from clients. Hidden items are also blocked by direct URL. Items are grouped by top-level menu (usually one per plugin).', 'ffl-funnels-addons'); ?>
        </p>

        <ul class="ffla-wl-menutree">
            <?php foreach ($menu_tree as $item) : ?>
                <li class="ffla-wl-menutree__item">
                    <label class="ffla-wl-check ffla-wl-menutree__top">
                        <input type="checkbox" name="ffla_wl[restrictions][hidden_menu][]"
                            value="<?php echo esc_attr($item['slug']); ?>"
                            <?php checked(in_array($item['slug'], $hidden_menu, true)); ?>>
                        <strong><?php echo esc_html($item['label']); ?></strong>
                    </label>

                    <?php if (!empty($item['children'])) : ?>
                        <ul class="ffla-wl-menutree__children">
                            <?php foreach ($item['children'] as $child) : ?>
                                <li>
                                    <label class="ffla-wl-check">
                                        <input type="checkbox" name="ffla_wl[restrictions][hidden_menu][]"
                                            value="<?php echo esc_attr($child['slug']); ?>"
                                            <?php checked(in_array($child['slug'], $hidden_menu, true)); ?>>
                                        <span><?php echo esc_html($child['label']); ?></span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="wb-card">
    <div class="wb-card__header"><h3><?php esc_html_e('Admin bar', 'ffl-funnels-addons'); ?></h3></div>
    <div class="wb-card__body">
        <p class="wb-field__desc">
            <?php esc_html_e('Top-bar items that link to a page you hid above are already removed automatically. Use this only for the ones that remain — e.g. plugin dropdowns with no direct link, or items that use a custom URL.', 'ffl-funnels-addons'); ?>
        </p>

        <?php if (empty($adminbar_nodes)) : ?>
            <p class="wb-field__desc"><em><?php esc_html_e('No admin-bar items detected (is the toolbar enabled for your account?).', 'ffl-funnels-addons'); ?></em></p>
        <?php else : ?>
            <div class="ffla-wl-checklist">
                <?php foreach ($adminbar_nodes as $node_id => $node_label) : ?>
                    <label class="ffla-wl-check">
                        <input type="checkbox" name="ffla_wl[restrictions][hidden_adminbar][]"
                            value="<?php echo esc_attr($node_id); ?>"
                            <?php checked(in_array($node_id, $hidden_adminbar, true)); ?>>
                        <span><?php echo esc_html($node_label); ?> <code><?php echo esc_html($node_id); ?></code></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
