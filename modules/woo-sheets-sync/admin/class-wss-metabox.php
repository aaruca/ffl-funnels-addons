<?php
/**
 * WSS Metabox — Product edit screen metabox for sync toggle.
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

        $enabled     = get_post_meta($post->ID, '_wss_sync_enabled', true);
        $last_synced = get_post_meta($post->ID, '_wss_last_synced', true);
        $product     = wc_get_product($post->ID);
        $var_count   = $product && $product->is_type('variable') ? count($product->get_children()) : 1;
        ?>

        <p>
            <label>
                <input type="checkbox" name="wss_sync_enabled" value="1" <?php checked($enabled, '1'); ?>>
                <?php esc_html_e('Sync with Google Sheets', 'ffl-funnels-addons'); ?>
            </label>
        </p>

        <p class="wss-metabox-info">
            <strong><?php esc_html_e('Variations:', 'ffl-funnels-addons'); ?></strong>
            <?php echo esc_html($var_count); ?>
        </p>

        <p class="wss-metabox-info">
            <strong><?php esc_html_e('Last synced:', 'ffl-funnels-addons'); ?></strong>
            <?php echo $last_synced ? esc_html(wp_date('Y-m-d H:i', strtotime($last_synced))) : '&mdash;'; ?>
        </p>
        <?php
    }

    /**
     * Save the metabox data.
     */
    public function save(int $post_id): void
    {
        // Verify nonce.
        if (!isset($_POST['wss_metabox_nonce']) || !wp_verify_nonce($_POST['wss_metabox_nonce'], 'wss_metabox')) {
            return;
        }

        // Skip autosave.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check capability.
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $enabled = isset($_POST['wss_sync_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_wss_sync_enabled', $enabled);
    }
}
