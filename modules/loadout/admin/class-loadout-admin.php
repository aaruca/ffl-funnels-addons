<?php
if (!defined('ABSPATH')) {
    exit;
}

class Loadout_Admin
{
    public function init(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_init', [$this, 'handle_form_save']);
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
                    <p><?php esc_html_e('Loadout deleted.', 'ffl-funnels-addons'); ?></p>
                </div>
            <?php endif; ?>

            <form method="get">
                <input type="hidden" name="page" value="ffla-loadouts">
                <?php $list->search_box(esc_html__('Search Loadouts', 'ffl-funnels-addons'), 'loadout-search'); ?>
                <?php $list->display(); ?>
            </form>
        </div>
        <?php
    }
}
