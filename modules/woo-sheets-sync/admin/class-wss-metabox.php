<?php
/**
 * WSS Metabox — Product edit screen box: which sheet tabs the product syncs to.
 *
 * The tab groups on the WSS Dashboard decide what syncs. This box edits the
 * product's place in those groups directly: one checkbox per tab group.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Metabox
{
    /**
     * Register hooks.
     */
    public function init(): void
    {
        add_action('add_meta_boxes', [$this, 'register']);
        add_action('save_post_product', [$this, 'save'], 10, 1);
    }

    /**
     * Register the metabox on the product edit screen.
     */
    public function register(): void
    {
        add_meta_box(
            'wss_sync_meta',
            __('Google Sheets Sync', 'ffl-funnels-addons'),
            [$this, 'render'],
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render the metabox content.
     */
    public function render(\WP_Post $post): void
    {
        wp_nonce_field('wss_metabox', 'wss_metabox_nonce');

        $product   = wc_get_product($post->ID);
        $var_count = $product && $product->is_type('variable') ? count($product->get_children()) : 1;
        $groups    = class_exists('WSS_Sync_Groups') ? WSS_Sync_Groups::get_groups() : [];
        $product_id = (int) $post->ID;
        ?>

        <?php if ($groups === []): ?>
            <p class="description"><?php esc_html_e('No sheet tab groups yet. Add one under WSS Dashboard → Sheet tab groups.', 'ffl-funnels-addons'); ?></p>
        <?php else: ?>
            <p><strong><?php esc_html_e('Sync to these sheet tabs:', 'ffl-funnels-addons'); ?></strong></p>
            <?php foreach ($groups as $group): ?>
                <?php
                $gid      = (string) ($group['id'] ?? '');
                $listed   = in_array($product_id, (array) ($group['product_ids'] ?? []), true);
                $resolved = WSS_Sync_Groups::resolve_parent_product_ids($group);
                $by_rule  = !$listed && in_array($product_id, $resolved, true);
                ?>
                <p style="margin:4px 0;">
                    <label>
                        <input type="checkbox" name="wss_sync_groups[]" value="<?php echo esc_attr($gid); ?>" <?php checked($listed || $by_rule); ?> <?php disabled($by_rule); ?>>
                        <?php echo esc_html((string) ($group['tab_name'] ?? '')); ?>
                    </label>
                    <?php if ($by_rule): ?>
                        <br><span class="description"><?php esc_html_e('Included by a category or tag rule; change it on the WSS Dashboard.', 'ffl-funnels-addons'); ?></span>
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>
            <input type="hidden" name="wss_sync_groups_present" value="1">
        <?php endif; ?>

        <p class="wss-metabox-info">
            <strong><?php esc_html_e('Variations:', 'ffl-funnels-addons'); ?></strong>
            <?php echo esc_html((string) $var_count); ?>
        </p>

        <?php $last_synced = self::last_synced($product); ?>
        <p class="wss-metabox-info">
            <strong><?php esc_html_e('Last synced:', 'ffl-funnels-addons'); ?></strong>
            <?php echo $last_synced ? esc_html(wp_date('Y-m-d H:i', $last_synced)) : '&mdash;'; ?>
        </p>
        <?php
    }

    /**
     * Most recent time a row of this product (or any of its variations) was
     * written to or read from the sheet, as a Unix timestamp; 0 when never.
     *
     * @param WC_Product|false|null $product
     */
    private static function last_synced($product): int
    {
        if (!$product) {
            return 0;
        }

        $ids = [(int) $product->get_id()];
        if ($product->is_type('variable')) {
            $ids = array_merge($ids, array_map('intval', $product->get_children()));
        }

        $latest = 0;
        foreach ($ids as $id) {
            $value = (string) get_post_meta($id, '_wss_last_synced', true);
            $time  = $value !== '' ? strtotime($value) : false;
            if ($time && $time > $latest) {
                $latest = $time;
            }
        }

        return $latest;
    }

    /**
     * Save the product's tab membership.
     */
    public function save(int $post_id): void
    {
        // Verify nonce.
        if (!isset($_POST['wss_metabox_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wss_metabox_nonce'])), 'wss_metabox')) {
            return;
        }

        // Skip autosave and revisions.
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) {
            return;
        }

        // Tab groups are store-wide settings.
        if (!current_user_can('edit_post', $post_id) || !current_user_can('manage_woocommerce')) {
            return;
        }

        // The box only shows checkboxes when groups exist.
        if (empty($_POST['wss_sync_groups_present']) || !class_exists('WSS_Sync_Groups')) {
            return;
        }

        $checked = isset($_POST['wss_sync_groups']) && is_array($_POST['wss_sync_groups'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['wss_sync_groups']))
            : [];

        WSS_Sync_Groups::set_product_groups($post_id, $checked);
    }
}
