<?php
/**
 * FFL Funnels Addons GitHub Updater.
 *
 * Handles plugin updates via GitHub Releases. Checks the GitHub API for new
 * tags and injects update information into WordPress's transient.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class FFLA_Updater
{
    /** @var string */
    private $github_user;

    /** @var string */
    private $github_repo;

    /** @var string */
    private $plugin_basename;

    /** @var string */
    private $current_version;

    /** @var string */
    private $plugin_slug;

    /** @var object|null */
    private $github_response = null;

    public function __construct(string $github_user, string $github_repo, string $plugin_basename, string $current_version)
    {
        $this->github_user = $github_user;
        $this->github_repo = $github_repo;
        $this->plugin_basename = $plugin_basename;
        $this->current_version = $current_version;
        $this->plugin_slug = dirname($plugin_basename);
    }

    /**
     * Register update hooks.
     */
    public function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'source_selection'], 10, 4);

        add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'add_check_update_link']);
        add_action('after_plugin_row_' . $this->plugin_basename, [$this, 'show_update_notice'], 10, 2);
        add_action('admin_notices', [$this, 'maybe_show_token_notice']);
        add_action('admin_init', [$this, 'handle_force_check']);

        // AJAX handler for the dashboard "Check for Updates" button.
        add_action('wp_ajax_ffla_check_update', [$this, 'ajax_check_update']);
    }

    /**
     * Add "Check for updates" link on the Plugins page.
     */
    public function add_check_update_link(array $links): array
    {
        $check_url = wp_nonce_url(
            admin_url('plugins.php?ffla_force_check=1'),
            'ffla_force_check'
        );
        $links['check_update'] = '<a href="' . esc_url($check_url) . '">' . esc_html__('Check for updates', 'ffl-funnels-addons') . '</a>';
        return $links;
    }

    /**
     * Handle force-check URL from the Plugins page.
     */
    public function handle_force_check(): void
    {
        if (empty($_GET['ffla_force_check'])) {
            return;
        }

        check_admin_referer('ffla_force_check');

        if (!current_user_can('update_plugins')) {
            return;
        }

        $this->force_check();

        $update_transient = get_site_transient('update_plugins');
        $has_update = isset($update_transient->response[$this->plugin_basename]);

        wp_safe_redirect(add_query_arg(
            'ffla_checked',
            $has_update ? 'update_available' : 'up_to_date',
            admin_url('plugins.php')
        ));
        exit;
    }

    /**
     * Force a fresh update check.
     */
    public function force_check(): void
    {
        $this->github_response = null;
        delete_transient('ffla_github_release');
        delete_transient('ffla_github_api_error');
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }

    /**
     * AJAX handler for the dashboard "Check for Updates" button.
     */
    public function ajax_check_update(): void
    {
        check_ajax_referer('ffla_admin_nonce', 'nonce');

        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => __('Permission denied.', 'ffl-funnels-addons')]);
        }

        $this->force_check();

        $update_transient = get_site_transient('update_plugins');
        $has_update = isset($update_transient->response[$this->plugin_basename]);

        if ($has_update) {
            $new_version = $update_transient->response[$this->plugin_basename]->new_version;
            wp_send_json_success([
                'status' => 'update_available',
                'message' => sprintf(__('Update available: v%s', 'ffl-funnels-addons'), $new_version),
                'version' => $new_version,
            ]);
        } else {
            wp_send_json_success([
                'status' => 'up_to_date',
                'message' => sprintf(__('You are running the latest version (v%s).', 'ffl-funnels-addons'), $this->current_version),
            ]);
        }
    }

    /**
     * Show inline update notice below the plugin row.
     */
    public function show_update_notice(string $file, array $plugin): void
    {
        if (!empty($_GET['ffla_checked'])) {
            $msg = ('update_available' === $_GET['ffla_checked'])
                ? __('Update found! Click "update now" above.', 'ffl-funnels-addons')
                : sprintf(__('You are running the latest version (v%s).', 'ffl-funnels-addons'), $this->current_version);

            echo '<tr class="plugin-update-tr"><td colspan="4" class="plugin-update colspanchange">';
            echo '<div class="notice inline notice-info"><p>' . esc_html($msg) . '</p></div>';
            echo '</td></tr>';
        }
    }

    /**
     * Show admin notice if GitHub API failed. Truly dismissible via AJAX.
     */
    public function maybe_show_token_notice(): void
    {
        $error = get_transient('ffla_github_api_error');
        if (!$error || !current_user_can('manage_options')) {
            return;
        }

        $dismiss_url = wp_nonce_url(
            admin_url('admin-post.php?action=ffla_dismiss_api_notice'),
            'ffla_dismiss_api_notice'
        );

        echo '<div class="notice notice-warning is-dismissible" id="ffla-api-notice">';
        echo '<p><strong>FFL Funnels Addons:</strong> ' . esc_html($error) . '</p>';
        echo '</div>';
        // Delete the transient when the WP dismiss (X) button is clicked.
        ?>
        <script>
            jQuery(function ($) {
                $(document).on('click', '#ffla-api-notice .notice-dismiss', function () {
                    $.post(ajaxurl, { action: 'ffla_dismiss_api_notice', _wpnonce: '<?php echo esc_js(wp_create_nonce('ffla_dismiss_api_notice')); ?>' });
                });
            });
        </script>
        <?php
    }

    /**
     * AJAX handler to permanently dismiss the API notice.
     */
    public function handle_dismiss_api_notice(): void
    {
        check_ajax_referer('ffla_dismiss_api_notice', '_wpnonce');
        delete_transient('ffla_github_api_error');
        wp_die();
    }

    /**
     * Fetch the latest release from GitHub API.
     */
    private function get_github_release(): ?object
    {
        if (null !== $this->github_response) {
            return $this->github_response;
        }

        $cache_key = 'ffla_github_release';
        $cached = get_transient($cache_key);

        if (false !== $cached) {
            $this->github_response = $cached;
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_user,
            $this->github_repo
        );

        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'FFLA-Updater/' . $this->current_version,
        ];

        // Support private repos: define('FFLA_GITHUB_TOKEN', 'ghp_xxxxx') in wp-config.php.
        if (defined('FFLA_GITHUB_TOKEN') && FFLA_GITHUB_TOKEN) {
            $headers['Authorization'] = 'token ' . FFLA_GITHUB_TOKEN;
        }

        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            set_transient('ffla_github_api_error', __('Could not connect to GitHub. Check your server\'s outbound connectivity.', 'ffl-funnels-addons'), HOUR_IN_SECONDS);
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if (200 !== $status_code) {
            if (404 === $status_code && (!defined('FFLA_GITHUB_TOKEN') || !FFLA_GITHUB_TOKEN)) {
                set_transient(
                    'ffla_github_api_error',
                    sprintf(
                        __('Auto-updates disabled — the GitHub repo is private. Add %s to wp-config.php with a valid Personal Access Token.', 'ffl-funnels-addons'),
                        'FFLA_GITHUB_TOKEN'
                    ),
                    DAY_IN_SECONDS
                );
            } elseif (403 === $status_code) {
                set_transient('ffla_github_api_error', __('GitHub API rate limit exceeded. Updates will retry in 1 hour.', 'ffl-funnels-addons'), HOUR_IN_SECONDS);
            } elseif (401 === $status_code) {
                set_transient('ffla_github_api_error', __('GitHub token is invalid or expired. Please update FFLA_GITHUB_TOKEN in wp-config.php.', 'ffl-funnels-addons'), DAY_IN_SECONDS);
            }
            return null;
        }

        delete_transient('ffla_github_api_error');

        $body = json_decode(wp_remote_retrieve_body($response));

        if (empty($body) || empty($body->tag_name)) {
            return null;
        }

        $this->github_response = $body;
        set_transient($cache_key, $body, 6 * HOUR_IN_SECONDS);

        return $body;
    }

    /**
     * Inject update info into WordPress update transient.
     */
    public function check_update(object $transient): object
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();

        if (!$release) {
            return $transient;
        }

        $latest_version = ltrim($release->tag_name, 'v');

        if (version_compare($latest_version, $this->current_version, '>')) {
            $download_url = $this->get_download_url($release);

            if ($download_url) {
                $plugin_data = new stdClass();
                $plugin_data->slug = $this->plugin_slug;
                $plugin_data->plugin = $this->plugin_basename;
                $plugin_data->new_version = $latest_version;
                $plugin_data->url = $release->html_url;
                $plugin_data->package = $download_url;
                $plugin_data->icons = [];
                $plugin_data->banners = [];
                $plugin_data->tested = '';
                $plugin_data->requires = '6.0';
                $plugin_data->requires_php = '7.4';

                $transient->response[$this->plugin_basename] = $plugin_data;
            }
        }

        return $transient;
    }

    /**
     * Provide plugin info for the "View details" popup.
     */
    public function plugin_info($result, string $action, object $args)
    {
        if ('plugin_information' !== $action) {
            return $result;
        }

        if (empty($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $release = $this->get_github_release();

        if (!$release) {
            return $result;
        }

        $latest_version = ltrim($release->tag_name, 'v');

        $info = new stdClass();
        $info->name = 'FFL Funnels Addons';
        $info->slug = $this->plugin_slug;
        $info->version = $latest_version;
        $info->author = '<a href="https://github.com/' . esc_attr($this->github_user) . '">Ale Aruca</a>';
        $info->homepage = 'https://github.com/' . $this->github_user . '/' . $this->github_repo;
        $info->requires = '6.0';
        $info->tested = '';
        $info->requires_php = '7.4';
        $info->downloaded = 0;
        $info->last_updated = $release->published_at;
        $info->download_link = $this->get_download_url($release);

        if (!empty($release->body)) {
            $info->sections = [
                'description' => 'Modular WooCommerce toolkit — WooBooster, Wishlist, and Doofinder Sync in a single unified plugin.',
                'changelog' => nl2br(esc_html($release->body)),
            ];
        }

        return $info;
    }

    /**
     * Fix directory name after update.
     *
     * GitHub's auto-generated zipball extracts to "aaruca-ffl-funnels-addons-{sha}/"
     * instead of "ffl-funnels-addons/". This hook renames it to the correct path.
     *
     * IMPORTANT: Do NOT call activate_plugin() here. The WP Upgrader is still active
     * (maintenance mode is ON), so loading the plugin at this point causes the update
     * to hang indefinitely. WordPress re-enables active plugins automatically once the
     * Upgrader finishes and the plugin basename matches.
     */
    /**
     * Fix directory name in the temp extracting directory BEFORE WP moves it.
     *
     * WordPress extracts the zip into a temporary folder. GitHub zipballs 
     * often have a root folder like "aaruca-ffl-funnels-addons-{sha}/".
     * We need to rename that root folder to "ffl-funnels-addons/" within the 
     * temp directory so that when WP moves it to WP_PLUGIN_DIR, it overwrites
     * the exact original plugin folder without changing the basename.
     */
    public function source_selection(string $source, string $remote_source, object $upgrader, array $hook_extra = []): string
    {
        // Only trigger this for our plugin update
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $source;
        }

        global $wp_filesystem;

        // The path where WP has extracted the files (e.g. /wp-content/upgrade/...)
        $upgrader_temp_dir = trailingslashit($remote_source);

        // The expected folder name inside the temp directory
        $proper_folder_name = trailingslashit($this->plugin_slug);
        $proper_destination = $upgrader_temp_dir . $proper_folder_name;

        // If it's already extracted to the perfect folder name, we do nothing.
        // This is important because our custom CI-built zips are already structured correctly.
        if (trailingslashit($source) === $proper_destination) {
            return $source;
        }

        // Rename the extracted folder to our expected plugin slug
        if ($wp_filesystem->move($source, $proper_destination, true)) {
            return $proper_destination;
        }

        // Fallback to original source if move fails
        return $source;
    }

    /**
     * Get the download URL from a release.
     */
    private function get_download_url(object $release): string
    {
        if (!empty($release->assets) && is_array($release->assets)) {
            foreach ($release->assets as $asset) {
                if (isset($asset->content_type) && 'application/zip' === $asset->content_type) {
                    return $asset->browser_download_url;
                }
                if (isset($asset->name) && substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }

        return $release->zipball_url;
    }
}
