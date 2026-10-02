<?php
/**
 * White Label — product editor tools for categories, brands and tags.
 *
 * On the classic Edit Product screen and in the products list's Quick Edit and
 * Bulk Edit, for every user who can edit products (staff and clients alike):
 *
 *  - search:        a search field on every taxonomy box (categories, brands,
 *                   tags…) and on the Quick / Bulk Edit checklists, with a
 *                   "Selected only" view; tags get a searchable checklist;
 *  - keep_order:    ticked brands and other nested terms stay in their place
 *                   in the tree instead of WordPress moving them to the top
 *                   (WooCommerce already does this for product categories);
 *  - collapse_tree: parent categories can be folded; long lists start folded
 *                   except the branches that hold ticked categories;
 *  - auto_parents:  ticking a subcategory also ticks its parents (off by
 *                   default);
 *  - list_filters:  the Products list gets a tag filter, and its category,
 *                   brand and tag filters become searchable.
 *
 * Settings live in ffla_white_label_settings under `term_search`; a missing
 * key means its default. Everything works on WordPress's own markup, so core
 * still holds and submits the values. Stores nothing else.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class White_Label_Term_Search
{
    const HANDLE      = 'ffla-wl-term-search';
    const AJAX_ACTION = 'ffla_wl_term_search';
    const NONCE       = 'ffla_wl_term_search';

    /** Flat taxonomies with more terms than this are searched over AJAX. */
    const INLINE_LIMIT = 2000;

    /** Maximum names returned by one AJAX search. */
    const AJAX_LIMIT = 50;

    /** Each tool and its default (used while the key is missing). */
    const DEFAULTS = [
        'enabled'       => true,
        'keep_order'    => true,
        'collapse_tree' => true,
        'auto_parents'  => false,
        'list_filters'  => true,
    ];

    /** Most tags listed in the Products list's tag filter. */
    const TAG_FILTER_LIMIT = 1000;

    /**
     * One tool's on/off value. A missing key (sites that saved White Label
     * before the setting existed) means its default.
     */
    public static function setting(string $key): bool
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            return false;
        }
        $value = White_Label_Settings::get('term_search.' . $key, null);

        return null === $value ? self::DEFAULTS[$key] : (bool) $value;
    }

    /** Category & tag search. */
    public static function enabled(): bool
    {
        return self::setting('enabled');
    }

    /** Whether any of the tools is on (nothing loads otherwise). */
    public static function any_enabled(): bool
    {
        foreach (array_keys(self::DEFAULTS) as $key) {
            if (self::setting($key)) {
                return true;
            }
        }

        return false;
    }

    public function register_hooks(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        if (self::enabled()) {
            add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax_search']);
        }
        if (self::setting('keep_order')) {
            add_filter('wp_terms_checklist_args', [$this, 'keep_tree_order'], 20, 2);
        }
        if (self::setting('list_filters')) {
            add_action('restrict_manage_posts', [$this, 'render_tag_filter'], 20, 2);
        }
    }

    /**
     * WordPress lifts ticked categories out of the tree and lists them first,
     * which hides where they sit and duplicates the hierarchy's context. Keep
     * every term in its place (the "Selected only" view lists the ticked ones).
     *
     * @param array<string, mixed> $args
     * @param int                  $post_id
     * @return array<string, mixed>
     */
    public function keep_tree_order($args, $post_id)
    {
        if (!is_array($args)) {
            return $args;
        }
        $post_type = $post_id ? get_post_type((int) $post_id) : '';
        if ($post_type && in_array($post_type, self::post_types(), true)) {
            $args['checked_ontop'] = false;
        }

        return $args;
    }

    /**
     * Products list: a "Filter by tag" dropdown next to WooCommerce's category,
     * type and stock filters. WordPress filters by the `product_tag` query
     * variable on its own.
     *
     * @param string $post_type
     * @param string $which
     */
    public function render_tag_filter($post_type, $which = 'top'): void
    {
        if ('product' !== $post_type || 'top' !== $which || !taxonomy_exists('product_tag')) {
            return;
        }
        $terms = get_terms([
            'taxonomy'   => 'product_tag',
            'hide_empty' => true,
            'orderby'    => 'name',
            'number'     => self::TAG_FILTER_LIMIT,
        ]);
        if (!is_array($terms) || empty($terms)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
        $current = isset($_GET['product_tag']) ? sanitize_title(wp_unslash($_GET['product_tag'])) : '';

        echo '<label class="screen-reader-text" for="ffla-ts-tag-filter">' . esc_html__('Filter by tag', 'ffl-funnels-addons') . '</label>';
        echo '<select name="product_tag" id="ffla-ts-tag-filter" class="ffla-ts-tag-filter">';
        echo '<option value="">' . esc_html__('Filter by tag', 'ffl-funnels-addons') . '</option>';
        foreach ($terms as $term) {
            printf(
                '<option value="%1$s"%2$s>%3$s (%4$s)</option>',
                esc_attr($term->slug),
                selected($current, $term->slug, false),
                esc_html($term->name),
                esc_html(number_format_i18n((int) $term->count))
            );
        }
        echo '</select>';
    }

    /**
     * Post types whose edit screens get the search (default: products).
     *
     * @return array<int, string>
     */
    public static function post_types(): array
    {
        /**
         * Filter the post types whose taxonomy boxes get the search.
         *
         * @param string[] $post_types Default ['product'].
         */
        $types = apply_filters('ffla_term_search_post_types', ['product']);

        return array_values(array_unique(array_filter(array_map('sanitize_key', (array) $types))));
    }

    /**
     * Load the assets on the edit screens (post.php / post-new.php) and on the
     * list screen (edit.php: Quick Edit and Bulk Edit) of the allowed types.
     *
     * @param string $hook
     */
    public function enqueue($hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php', 'edit.php'], true)) {
            return;
        }

        $screen    = function_exists('get_current_screen') ? get_current_screen() : null;
        $post_type = $screen instanceof WP_Screen ? (string) $screen->post_type : '';
        if ('' === $post_type || !in_array($post_type, self::post_types(), true)) {
            return;
        }

        $is_list    = 'edit.php' === $hook;
        $features   = [
            'search'      => self::enabled(),
            'collapse'    => self::setting('collapse_tree'),
            'autoParents' => self::setting('auto_parents'),
            'listFilters' => $is_list && 'product' === $post_type && self::setting('list_filters'),
        ];
        if (!$features['search'] && !$features['collapse'] && !$features['autoParents'] && !$features['listFilters']) {
            return;
        }
        $taxonomies = $this->taxonomy_data($post_type, $is_list, $features['search']);
        if (empty($taxonomies) && !$features['listFilters']) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE,
            FFLA_URL . 'modules/white-label/admin/css/white-label-term-search.css',
            [],
            FFLA_VERSION
        );

        // After core's own box scripts: 'post' (category tabs, tagBox) on the
        // edit screen, 'inline-edit-post' (Quick/Bulk Edit) on the list.
        wp_enqueue_script(
            self::HANDLE,
            FFLA_URL . 'modules/white-label/admin/js/white-label-term-search.js',
            $is_list ? ['jquery', 'inline-edit-post'] : ['jquery', 'post'],
            FFLA_VERSION,
            true
        );

        wp_localize_script(self::HANDLE, 'fflaTermSearch', [
            'features'   => $features,
            // Lists with at least this many terms start folded (Collapse tree).
            'collapseMin' => (int) apply_filters('ffla_term_search_collapse_min', 15),
            'taxonomies' => $taxonomies,
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'action'     => self::AJAX_ACTION,
            'nonce'      => wp_create_nonce(self::NONCE),
            'minChars'   => 2,
            'i18n'       => [
                'matchOne'     => __('%d match', 'ffl-funnels-addons'),
                'matchMany'    => __('%d matches', 'ffl-funnels-addons'),
                'selected'     => __('%d selected', 'ffl-funnels-addons'),
                'noMatches'    => __('No matches', 'ffl-funnels-addons'),
                'selectedOnly' => __('Selected only', 'ffl-funnels-addons'),
                'search'       => __('Search…', 'ffl-funnels-addons'),
                'typeMore'     => __('Type at least 2 characters to search all terms.', 'ffl-funnels-addons'),
                'searching'    => __('Searching…', 'ffl-funnels-addons'),
                'searchFailed' => __('The search could not be completed. Try again.', 'ffl-funnels-addons'),
                /* translators: %s: category name */
                'expand'       => __('Show subcategories of %s', 'ffl-funnels-addons'),
                /* translators: %s: category name */
                'collapse'     => __('Hide subcategories of %s', 'ffl-funnels-addons'),
                'expandAll'    => __('Expand all', 'ffl-funnels-addons'),
                'collapseAll'  => __('Collapse all', 'ffl-funnels-addons'),
            ],
        ]);
    }

    /**
     * Per-taxonomy data for the script: labels, and for flat taxonomies on the
     * edit screen the existing term names (or a flag to search over AJAX).
     *
     * @return array<string, array<string, mixed>>
     */
    private function taxonomy_data(string $post_type, bool $is_list, bool $search = true): array
    {
        $data = [];

        foreach (get_object_taxonomies($post_type, 'objects') as $taxonomy) {
            if (!$taxonomy instanceof WP_Taxonomy || empty($taxonomy->show_ui)) {
                continue;
            }
            if ($is_list ? (!$taxonomy->hierarchical || empty($taxonomy->show_in_quick_edit)) : false === $taxonomy->meta_box_cb) {
                continue;
            }
            // Without search, only the category tree tools apply.
            if (!$search && !$taxonomy->hierarchical) {
                continue;
            }

            $labels = $taxonomy->labels;
            $search = isset($labels->search_items) && '' !== (string) $labels->search_items
                ? (string) $labels->search_items
                /* translators: %s: taxonomy name, e.g. "Product categories". */
                : sprintf(__('Search %s', 'ffl-funnels-addons'), (string) $labels->name);

            $item = [
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'name'         => (string) $labels->name,
                'searchLabel'  => $search,
                /* translators: %s: the taxonomy's "Search …" label, e.g. "Search categories". */
                'placeholder'  => sprintf(__('%s…', 'ffl-funnels-addons'), $search),
                'listLabel'    => isset($labels->all_items) ? (string) $labels->all_items : (string) $labels->name,
            ];

            // Flat taxonomies get their own checklist of existing terms. Only
            // for users who may assign them (core disables the box otherwise).
            if (!$is_list && !$taxonomy->hierarchical) {
                if (!current_user_can($taxonomy->cap->assign_terms)) {
                    continue;
                }
                /**
                 * Filter how many terms of a flat taxonomy are listed in the page
                 * before the box searches the server instead.
                 *
                 * @param int    $limit    Default 2000.
                 * @param string $taxonomy Taxonomy name.
                 */
                $limit = max(0, (int) apply_filters('ffla_term_search_inline_limit', self::INLINE_LIMIT, $taxonomy->name));
                $names = $this->term_names($taxonomy->name, $limit + 1);
                if (count($names) > $limit) {
                    $item['remote'] = true;
                    $item['terms']  = [];
                } else {
                    $item['remote'] = false;
                    $item['terms']  = $names;
                }
            }

            $data[$taxonomy->name] = $item;
        }

        return $data;
    }

    /**
     * Term names, name-ordered, decoded to the plain text core's tag field uses.
     *
     * @return array<int, string>
     */
    private function term_names(string $taxonomy, int $number, string $search = ''): array
    {
        $args = [
            'taxonomy'               => $taxonomy,
            'hide_empty'             => false,
            'orderby'                => 'name',
            'order'                  => 'ASC',
            'number'                 => $number,
            'fields'                 => 'names',
            'update_term_meta_cache' => false,
        ];
        if ('' !== $search) {
            $args['name__like'] = $search;
        }

        $names = get_terms($args);
        if (!is_array($names)) {
            return [];
        }

        $out = [];
        foreach ($names as $name) {
            $name = trim(html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ('' !== $name) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Search a flat taxonomy that is too large to list in the page.
     */
    public function ajax_search(): void
    {
        if (!check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => __('Your session expired. Reload the page.', 'ffl-funnels-addons')], 403);
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_key(wp_unslash($_POST['taxonomy'])) : '';
        $query    = isset($_POST['q']) ? trim(sanitize_text_field(wp_unslash($_POST['q']))) : '';
        // phpcs:enable

        $tax = '' !== $taxonomy ? get_taxonomy($taxonomy) : false;
        if (
            !$tax instanceof WP_Taxonomy
            || !array_intersect(self::post_types(), (array) $tax->object_type)
            || !current_user_can($tax->cap->assign_terms)
        ) {
            wp_send_json_error(['message' => __('You are not allowed to search these terms.', 'ffl-funnels-addons')], 403);
        }

        $length = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
        if ($length < 2) {
            wp_send_json_success(['terms' => []]);
        }

        wp_send_json_success(['terms' => $this->term_names($taxonomy, self::AJAX_LIMIT, $query)]);
    }
}
