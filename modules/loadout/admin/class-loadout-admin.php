<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Admin
{
    const OPTION_SETTINGS = 'ffla_loadout_settings';

    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_init', [$this, 'handle_form_save']);
        add_action('admin_init', [$this, 'handle_list_actions']);
        add_action('admin_post_ffla_loadout_save_settings', [$this, 'handle_settings_save']);
    }

    /**
     * Row Delete and the bulk actions of the Loadouts list. Runs on admin_init,
     * before any output, so the redirect afterwards works.
     */
    public function handle_list_actions(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below per action.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'ffla-loadouts' || !current_user_can('manage_woocommerce')) {
            return;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if (($action === '' || $action === '-1') && isset($_REQUEST['action2'])) {
            $action = sanitize_key(wp_unslash($_REQUEST['action2']));
        }
        // phpcs:enable

        $list_url = admin_url('admin.php?page=ffla-loadouts');

        if ($action === 'delete') {
            $loadout_id = isset($_GET['loadout_id']) ? absint($_GET['loadout_id']) : 0;
            if (!$loadout_id) {
                return;
            }
            check_admin_referer('loadout_delete_' . $loadout_id);
            $deleted = Loadout::delete($loadout_id) ? 1 : 0;
            wp_safe_redirect(add_query_arg('deleted', $deleted, $list_url));
            exit;
        }

        if (!in_array($action, ['bulk_delete', 'bulk_activate', 'bulk_deactivate'], true)) {
            return;
        }

        check_admin_referer('bulk-loadouts');

        $ids   = isset($_REQUEST['loadout_ids']) ? array_map('absint', (array) wp_unslash($_REQUEST['loadout_ids'])) : [];
        $count = 0;
        foreach (array_filter($ids) as $lid) {
            $loadout = Loadout::get($lid);
            if (!$loadout) {
                continue;
            }
            if ($action === 'bulk_delete') {
                $count += Loadout::delete($lid) ? 1 : 0;
                continue;
            }
            $loadout->set_status($action === 'bulk_activate' ? 1 : 0);
            $count += $loadout->save() ? 1 : 0;
        }

        $arg = $action === 'bulk_delete' ? 'deleted' : 'updated';
        wp_safe_redirect(add_query_arg($arg, $count, $list_url));
        exit;
    }

    /**
     * Save the module's own settings (the uninstall data flag).
     */
    public function handle_settings_save(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }
        check_admin_referer('ffla_loadout_save_settings', '_loadout_settings_nonce');

        $settings = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['delete_data_uninstall'] = isset($_POST['delete_data_uninstall']) ? '1' : '0';
        update_option(self::OPTION_SETTINGS, $settings);

        wp_safe_redirect(admin_url('admin.php?page=ffla-loadouts&settings-updated=1'));
        exit;
    }

    public function enqueue_scripts(): void
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'ffla-loadouts') === false) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_script(
            'loadout-admin',
            plugins_url('js/loadout-admin.js', __FILE__),
            ['jquery', 'wp-util'],
            FFLA_VERSION,
            true
        );

        wp_enqueue_style(
            'loadout-admin',
            plugins_url('css/loadout-admin.css', __FILE__),
            [],
            FFLA_VERSION
        );

        wp_localize_script('loadout-admin', 'loadoutAdmin', [
            'nonce' => wp_create_nonce('loadout_admin'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'strings' => [
                'selectImage' => __('Select Image', 'ffl-funnels-addons'),
                'useImage' => __('Use this Image', 'ffl-funnels-addons'),
                'searching' => __('Searching...', 'ffl-funnels-addons'),
                'noResults' => __('No products found.', 'ffl-funnels-addons'),
            ],
        ]);
    }

    public function handle_form_save(): void
    {
        if (!isset($_POST['action']) || $_POST['action'] !== 'save_loadout') {
            return;
        }

        $screen_page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if ($screen_page !== 'ffla-loadouts') {
            return;
        }

        Loadout_Form::handle_save();
    }

    public function render_content(): void
    {
        $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
        $loadout_id = isset($_GET['loadout_id']) ? absint($_GET['loadout_id']) : 0;

        switch ($action) {
            case 'add':
                Loadout_Form::render_form(null);
                break;
            case 'edit':
                $loadout = $loadout_id ? Loadout::get($loadout_id) : null;
                if ($loadout) {
                    Loadout_Form::render_form($loadout);
                } else {
                    $this->render_list();
                }
                break;
            default:
                $this->render_list();
                break;
        }
    }

    private function render_list(): void
    {
        $list = new Loadout_List();
        $list->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e('Loadouts', 'ffl-funnels-addons'); ?></h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=ffla-loadouts&action=add')); ?>" class="page-title-action"><?php esc_html_e('Add New', 'ffl-funnels-addons'); ?></a>
            <hr class="wp-header-end">

            <?php if (isset($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php
                    $deleted = absint($_GET['deleted']);
                    echo esc_html($deleted === 1
                        ? __('Loadout deleted.', 'ffl-funnels-addons')
                        /* translators: %d: number of loadouts */
                        : sprintf(_n('%d loadout deleted.', '%d loadouts deleted.', $deleted, 'ffl-funnels-addons'), $deleted));
                    ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php
                    $updated = absint($_GET['updated']);
                    /* translators: %d: number of loadouts */
                    echo esc_html(sprintf(_n('%d loadout updated.', '%d loadouts updated.', $updated, 'ffl-funnels-addons'), $updated));
                    ?></p>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Settings saved.', 'ffl-funnels-addons'); ?></p>
                </div>
            <?php endif; ?>

            <form method="get">
                <input type="hidden" name="page" value="ffla-loadouts">
                <?php $list->search_box(esc_html__('Search Loadouts', 'ffl-funnels-addons'), 'loadout-search'); ?>
                <?php $list->display(); ?>
            </form>

            <?php $this->render_settings_form(); ?>
        </div>
        <?php
    }

    private function render_settings_form(): void
    {
        $settings = get_option(self::OPTION_SETTINGS, []);
        $delete   = is_array($settings) && !empty($settings['delete_data_uninstall']);
        ?>
        <h2 class="title"><?php esc_html_e('Data', 'ffl-funnels-addons'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ffla_loadout_save_settings">
            <?php wp_nonce_field('ffla_loadout_save_settings', '_loadout_settings_nonce'); ?>
            <p>
                <label>
                    <input type="checkbox" name="delete_data_uninstall" value="1" <?php checked($delete); ?>>
                    <?php esc_html_e('Delete loadout data on uninstall', 'ffl-funnels-addons'); ?>
                </label>
            </p>
            <p class="description"><?php esc_html_e('Off by default. When off, deleting the plugin keeps every loadout, tier, item and cross-sell, so a reinstall restores them. When on (and the Loadout module is switched on at that moment), the Loadout tables, these settings and the products\' Loadout tab settings are permanently deleted. Orders always keep their loadout details.', 'ffl-funnels-addons'); ?></p>
            <?php submit_button(__('Save Settings', 'ffl-funnels-addons'), 'secondary', 'submit', false); ?>
        </form>
        <?php
    }
}
