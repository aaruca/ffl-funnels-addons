<?php
/**
 * Smart Coupons — coupon categories.
 *
 * WooCommerce coupons have no categories. This adds a hierarchical
 * "Coupon categories" taxonomy (Marketing → Coupon categories) with a colour
 * and optional rules per category:
 *
 * - Cannot be combined with coupons from chosen other categories.
 * - Only one coupon from this category per order.
 * - Maximum discount per coupon.
 * - May discount firearms / protected items.
 * - Only for these customer roles.
 * - Default expiry (days) for new coupons without an expiry date.
 * - Monthly budget: once the discount given this month reaches it, the
 *   category's coupons stop working until next month.
 *
 * The coupon list gets coloured category badges, a category filter and bulk
 * assignment (WordPress bulk edit). Store credit and bulk-code batches are
 * filed automatically.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Coupon_Categories
{
    const TAX = 'ffla_coupon_cat';
    const SEEDED = 'ffla_coupon_cats_seeded';
    const META = '_ffla_rules';

    public static function boot(): void
    {
        self::register();
        add_action('init', [__CLASS__, 'seed'], 30);
        add_filter('woocommerce_coupon_is_valid', [__CLASS__, 'validate'], 25, 3);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'flush_spent'], 10, 0);

        if (is_admin()) {
            add_action('admin_menu', [__CLASS__, 'menu'], 80);
            add_filter('parent_file', [__CLASS__, 'parent_file']);
            add_action(self::TAX . '_add_form_fields', [__CLASS__, 'add_fields']);
            add_action(self::TAX . '_edit_form_fields', [__CLASS__, 'edit_fields']);
            add_action('created_' . self::TAX, [__CLASS__, 'save_term']);
            add_action('edited_' . self::TAX, [__CLASS__, 'save_term']);
            add_filter('manage_edit-' . self::TAX . '_columns', [__CLASS__, 'term_columns']);
            add_filter('manage_' . self::TAX . '_custom_column', [__CLASS__, 'term_column'], 10, 3);
            add_filter('manage_edit-shop_coupon_columns', [__CLASS__, 'coupon_columns'], 20);
            add_action('manage_shop_coupon_posts_custom_column', [__CLASS__, 'coupon_column'], 10, 2);
            add_action('restrict_manage_posts', [__CLASS__, 'filter_dropdown']);
            add_action('woocommerce_coupon_options_save', [__CLASS__, 'apply_default_expiry'], 30, 2);
        }
    }

    public static function register(): void
    {
        register_taxonomy(self::TAX, 'shop_coupon', [
            'labels'            => [
                'name'          => __('Coupon categories', 'ffl-funnels-addons'),
                'singular_name' => __('Coupon category', 'ffl-funnels-addons'),
                'menu_name'     => __('Coupon categories', 'ffl-funnels-addons'),
                'all_items'     => __('All coupon categories', 'ffl-funnels-addons'),
                'edit_item'     => __('Edit coupon category', 'ffl-funnels-addons'),
                'add_new_item'  => __('Add coupon category', 'ffl-funnels-addons'),
                'search_items'  => __('Search coupon categories', 'ffl-funnels-addons'),
                'not_found'     => __('No coupon categories found.', 'ffl-funnels-addons'),
            ],
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_in_menu'      => false,
            'show_admin_column' => false,
            'show_in_quick_edit' => true,
            'show_in_rest'      => false,
            'query_var'         => self::TAX,
            'rewrite'           => false,
            'capabilities'      => [
                'manage_terms' => 'manage_woocommerce',
                'edit_terms'   => 'manage_woocommerce',
                'delete_terms' => 'manage_woocommerce',
                'assign_terms' => 'edit_shop_coupons',
            ],
        ]);
    }

    /** Starter categories, created once. */
    public static function seed(): void
    {
        if (get_option(self::SEEDED)) {
            return;
        }
        update_option(self::SEEDED, 1, false);
        $defaults = [
            'promotions'        => [__('Promotions', 'ffl-funnels-addons'), '#2271b1', []],
            'email'             => [__('Email campaigns', 'ffl-funnels-addons'), '#7c3aed', []],
            'social'            => [__('Social & influencers', 'ffl-funnels-addons'), '#db2777', []],
            'events'            => [__('Gun shows & events', 'ffl-funnels-addons'), '#b45309', []],
            'partners'          => [__('Partners & ranges', 'ffl-funnels-addons'), '#0f766e', []],
            'employees'         => [__('Employees', 'ffl-funnels-addons'), '#475569', ['one_per_order' => true]],
            'loyalty'           => [__('Loyalty & members', 'ffl-funnels-addons'), '#ca8a04', []],
            'customer-service'  => [__('Customer service', 'ffl-funnels-addons'), '#0891b2', []],
            'store-credit'      => [__('Store credit', 'ffl-funnels-addons'), '#15803d', ['allow_protected' => true]],
        ];
        foreach ($defaults as $slug => [$name, $color, $rules]) {
            if (term_exists($slug, self::TAX)) {
                continue;
            }
            $term = wp_insert_term($name, self::TAX, ['slug' => $slug]);
            if (!is_wp_error($term)) {
                update_term_meta($term['term_id'], self::META, array_merge(self::rule_defaults(), ['color' => $color], $rules));
            }
        }
    }

    /* ── Rules ─────────────────────────────────────────────────────────── */

    public static function rule_defaults(): array
    {
        return [
            'color'           => '#64748b',
            'no_combine'      => [],
            'one_per_order'   => false,
            'max_discount'    => 0.0,
            'allow_protected' => false,
            'roles'           => [],
            'expiry_days'     => 0,
            'budget'          => 0.0,
        ];
    }

    public static function rules(int $term_id): array
    {
        $saved = get_term_meta($term_id, self::META, true);
        return array_merge(self::rule_defaults(), is_array($saved) ? $saved : []);
    }

    /** Category term IDs of a coupon. */
    public static function coupon_terms(WC_Coupon $coupon): array
    {
        $ids = $coupon->get_id() ? wp_get_object_terms($coupon->get_id(), self::TAX, ['fields' => 'ids']) : [];
        return is_wp_error($ids) ? [] : array_map('intval', $ids);
    }

    /**
     * One rule merged across the coupon's categories: any "true" wins, the
     * smallest non-zero cap applies, lists are combined.
     */
    public static function rule(WC_Coupon $coupon, string $key)
    {
        $values = array_map(static function ($id) use ($key) {
            return self::rules($id)[$key];
        }, self::coupon_terms($coupon));
        switch ($key) {
            case 'allow_protected':
            case 'one_per_order':
                return in_array(true, $values, true);
            case 'max_discount':
            case 'expiry_days':
                $values = array_filter(array_map('floatval', $values));
                return $values ? min($values) : 0.0;
            default:
                $merged = [];
                foreach ($values as $list) {
                    foreach ((array) $list as $value) {
                        $merged[] = $value;
                    }
                }
                return array_values(array_unique($merged));
        }
    }

    /**
     * @throws Exception
     */
    public static function validate($valid, $coupon, $discounts = null)
    {
        if (!$valid || !$coupon instanceof WC_Coupon || FFLA_Coupon_Settings::is_credit($coupon)) {
            return $valid;
        }
        $cart = $discounts instanceof WC_Discounts ? $discounts->get_object() : null;
        if (!$cart instanceof WC_Cart) {
            return $valid;
        }
        $mine = self::coupon_terms($coupon);
        if (!$mine) {
            return $valid;
        }

        $roles = self::rule($coupon, 'roles');
        if ($roles) {
            $user = wp_get_current_user();
            $have = $user && $user->exists() ? (array) $user->roles : ['guest'];
            if (!array_intersect($have, $roles)) {
                throw new Exception(esc_html__('This coupon is not available for your account.', 'ffl-funnels-addons'), 100);
            }
        }

        // Earlier coupons only, so two coupons never knock each other out.
        $applied = array_values($cart->get_applied_coupons()); // Keys can have gaps after a removal.
        $position = array_search($coupon->get_code(), $applied, true);
        $earlier = false === $position ? $applied : array_slice($applied, 0, $position);
        foreach ($earlier as $code) {
            if ($code === $coupon->get_code()) {
                continue;
            }
            $other = new WC_Coupon($code);
            if (!$other->get_id() || FFLA_Coupon_Settings::is_credit($other)) {
                continue;
            }
            $theirs = self::coupon_terms($other);
            $shared = array_intersect($mine, $theirs);
            foreach ($shared as $term_id) {
                if (self::rules($term_id)['one_per_order']) {
                    /* translators: %s: category */
                    throw new Exception(esc_html(sprintf(__('Only one %s coupon can be used per order.', 'ffl-funnels-addons'), self::name($term_id))), 100);
                }
            }
            foreach ($mine as $a) {
                foreach ($theirs as $b) {
                    if (in_array($b, self::rules($a)['no_combine'], true) || in_array($a, self::rules($b)['no_combine'], true)) {
                        /* translators: 1: category, 2: category */
                        throw new Exception(esc_html(sprintf(__('%1$s coupons cannot be combined with %2$s coupons.', 'ffl-funnels-addons'), self::name($a), self::name($b))), 100);
                    }
                }
            }
        }

        foreach ($mine as $term_id) {
            $budget = (float) self::rules($term_id)['budget'];
            if ($budget > 0 && self::spent_this_month($term_id) >= $budget) {
                throw new Exception(esc_html__('This promotion has reached its limit for this month.', 'ffl-funnels-addons'), 100);
            }
        }

        return $valid;
    }

    /** Categories this one cannot be combined with, in either direction. */
    public static function blocked_with(int $term_id): array
    {
        $out = array_map('intval', (array) self::rules($term_id)['no_combine']);
        $terms = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'fields' => 'ids']);
        foreach (is_wp_error($terms) ? [] : $terms as $other) {
            if ((int) $other !== $term_id && in_array($term_id, array_map('intval', (array) self::rules((int) $other)['no_combine']), true)) {
                $out[] = (int) $other;
            }
        }
        return array_values(array_unique(array_map('intval', $out)));
    }

    public static function name(int $term_id): string
    {
        $term = get_term($term_id, self::TAX);
        return $term && !is_wp_error($term) ? $term->name : '';
    }

    /** Coupon post IDs in a category (including subcategories). */
    public static function coupon_ids(int $term_id): array
    {
        return array_map('intval', get_posts([
            'post_type'      => 'shop_coupon',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [['taxonomy' => self::TAX, 'terms' => [$term_id], 'include_children' => true]], // phpcs:ignore WordPress.DB.SlowDBQuery
        ]));
    }

    /** Discount given this calendar month (store time) by the category's coupons. */
    public static function spent_this_month(int $term_id): float
    {
        $key = 'ffla_cpn_spent_' . $term_id . '_' . wp_date('Ym');
        $cached = get_transient($key);
        if (false !== $cached) {
            return (float) $cached;
        }
        $start = wp_date('Y-m-01 00:00:00');
        $spent = self::discount_total(self::coupon_ids($term_id), $start, null);
        set_transient($key, $spent, 10 * MINUTE_IN_SECONDS);
        return $spent;
    }

    public static function flush_spent(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_ffla\\_cpn\\_spent\\_%' OR option_name LIKE '\\_transient\\_timeout\\_ffla\\_cpn\\_spent\\_%'"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    }

    /**
     * Discount amount (before tax) that these coupons gave on paid, processing
     * and on-hold orders since a local datetime. Reads the orders' coupon lines
     * directly, so it is up to date the moment an order is placed (the
     * Analytics tables are filled in the background and also hold drafts).
     */
    public static function discount_total(array $coupon_ids, string $since_local, ?string $until_local = null): float
    {
        global $wpdb;
        $codes = array_values(array_unique(array_filter(array_map(static function ($id) {
            return wc_format_coupon_code(get_the_title($id));
        }, $coupon_ids))));
        if (!$codes) {
            return 0.0;
        }
        $statuses = array_map(static function ($s) { return 'wc-' . $s; }, array_merge(wc_get_is_paid_statuses(), ['on-hold']));
        $hpos = class_exists('Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        if ($hpos) {
            $join = "JOIN {$wpdb->prefix}wc_orders o ON o.id = i.order_id AND o.type = 'shop_order'";
            $status = 'o.status';
            $date = 'o.date_created_gmt';
        } else {
            $join = "JOIN {$wpdb->posts} o ON o.ID = i.order_id AND o.post_type = 'shop_order'";
            $status = 'o.post_status';
            $date = 'o.post_date_gmt';
        }
        $sql = "SELECT COALESCE(SUM(m.meta_value), 0)
            FROM {$wpdb->prefix}woocommerce_order_items i
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = i.order_item_id AND m.meta_key = 'discount_amount'
            {$join}
            WHERE i.order_item_type = 'coupon'
              AND LOWER(i.order_item_name) IN (" . implode(',', array_fill(0, count($codes), '%s')) . ")
              AND {$status} IN (" . implode(',', array_fill(0, count($statuses), '%s')) . ")
              AND {$date} >= %s" . ($until_local ? " AND {$date} < %s" : '');
        $args = array_merge($codes, $statuses, [get_gmt_from_date($since_local)], $until_local ? [get_gmt_from_date($until_local)] : []);
        return (float) $wpdb->get_var($wpdb->prepare($sql, $args)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    }

    /** File a coupon in a category by slug (created on the fly if missing). */
    public static function assign(int $coupon_id, string $slug): void
    {
        $term = get_term_by('slug', $slug, self::TAX);
        if (!$term) {
            self::seed();
            $term = get_term_by('slug', $slug, self::TAX);
        }
        if ($term) {
            wp_set_object_terms($coupon_id, [(int) $term->term_id], self::TAX, true);
        }
    }

    /** New coupons without an expiry get the category's default. */
    public static function apply_default_expiry($post_id, $coupon = null): void
    {
        $coupon = $coupon instanceof WC_Coupon ? $coupon : new WC_Coupon((int) $post_id);
        if ($coupon->get_date_expires()) {
            return;
        }
        $days = (int) self::rule($coupon, 'expiry_days');
        if ($days > 0) {
            $coupon->set_date_expires(strtotime('+' . $days . ' days', current_time('timestamp', true)));
            $coupon->save();
        }
    }

    /* ── Admin ─────────────────────────────────────────────────────────── */

    public static function menu(): void
    {
        $parent = class_exists('Automattic\WooCommerce\Admin\Features\Features') && menu_page_url('woocommerce-marketing', false) ? 'woocommerce-marketing' : 'woocommerce';
        add_submenu_page($parent, __('Coupon categories', 'ffl-funnels-addons'), __('Coupon categories', 'ffl-funnels-addons'), 'manage_woocommerce', 'edit-tags.php?taxonomy=' . self::TAX . '&post_type=shop_coupon');
    }

    public static function parent_file($file)
    {
        global $current_screen;
        if ($current_screen && self::TAX === ($current_screen->taxonomy ?? '')) {
            return menu_page_url('woocommerce-marketing', false) ? 'woocommerce-marketing' : 'woocommerce';
        }
        return $file;
    }

    private static function fields(array $rules, int $term_id = 0): void
    {
        $others = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'exclude' => $term_id ? [$term_id] : []]);
        $roles = array_merge(['guest' => __('Guests (not signed in)', 'ffl-funnels-addons')], wp_roles()->get_names());
        $rows = [
            'color'           => [__('Colour', 'ffl-funnels-addons'), '<input type="color" name="ffla_rules[color]" value="' . esc_attr($rules['color']) . '">'],
            'one_per_order'   => [__('One per order', 'ffl-funnels-addons'), '<label><input type="checkbox" name="ffla_rules[one_per_order]" value="1"' . checked($rules['one_per_order'], true, false) . '> ' . esc_html__('Only one coupon from this category per order', 'ffl-funnels-addons') . '</label>'],
            'no_combine'      => [__('Cannot be combined with', 'ffl-funnels-addons'), self::multi('ffla_rules[no_combine][]', is_wp_error($others) ? [] : wp_list_pluck($others, 'name', 'term_id'), $term_id ? self::blocked_with($term_id) : $rules['no_combine']) . '<p class="description">' . esc_html__('Coupons from these categories cannot be used together with this category’s coupons. Works both ways.', 'ffl-funnels-addons') . '</p>'],
            'max_discount'    => [__('Maximum discount per coupon', 'ffl-funnels-addons'), '<input type="number" min="0" step="0.01" name="ffla_rules[max_discount]" value="' . esc_attr($rules['max_discount'] ? (string) $rules['max_discount'] : '') . '" placeholder="' . esc_attr__('No limit', 'ffl-funnels-addons') . '">'],
            'allow_protected' => [__('Firearms and protected items', 'ffl-funnels-addons'), '<label><input type="checkbox" name="ffla_rules[allow_protected]" value="1"' . checked($rules['allow_protected'], true, false) . '> ' . esc_html__('This category’s coupons may discount them (MAP prices still apply)', 'ffl-funnels-addons') . '</label>'],
            'roles'           => [__('Only for', 'ffl-funnels-addons'), self::multi('ffla_rules[roles][]', $roles, $rules['roles']) . '<p class="description">' . esc_html__('Leave empty for everyone.', 'ffl-funnels-addons') . '</p>'],
            'expiry_days'     => [__('Default expiry (days)', 'ffl-funnels-addons'), '<input type="number" min="0" step="1" name="ffla_rules[expiry_days]" value="' . esc_attr($rules['expiry_days'] ? (string) $rules['expiry_days'] : '') . '"><p class="description">' . esc_html__('Applied when a coupon in this category is saved without an expiry date.', 'ffl-funnels-addons') . '</p>'],
            'budget'          => [__('Monthly budget', 'ffl-funnels-addons'), '<input type="number" min="0" step="0.01" name="ffla_rules[budget]" value="' . esc_attr($rules['budget'] ? (string) $rules['budget'] : '') . '" placeholder="' . esc_attr__('No limit', 'ffl-funnels-addons') . '"><p class="description">' . esc_html__('Total discount this category may give per calendar month. Its coupons stop working once it is reached.', 'ffl-funnels-addons') . ($term_id && $rules['budget'] ? ' ' . esc_html(sprintf(
                /* translators: %s: amount */
                __('Used this month: %s', 'ffl-funnels-addons'),
                html_entity_decode(wp_strip_all_tags(wc_price(self::spent_this_month($term_id))), ENT_QUOTES, 'UTF-8')
            )) : '') . '</p>'],
        ];
        wp_nonce_field('ffla_coupon_cat', '_ffla_cat_nonce');
        foreach ($rows as $key => [$label, $html]) {
            if ($term_id) {
                echo '<tr class="form-field"><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
            } else {
                echo '<div class="form-field"><label>' . esc_html($label) . '</label>' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
        }
    }

    private static function multi(string $name, array $options, array $selected): string
    {
        $html = '<select name="' . esc_attr($name) . '" multiple class="wc-enhanced-select" data-placeholder="' . esc_attr__('None', 'ffl-funnels-addons') . '" size="' . esc_attr((string) min(6, max(3, count($options)))) . '" style="min-width:240px;width:95%;max-width:420px">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr((string) $value) . '"' . selected(in_array((string) $value, array_map('strval', $selected), true), true, false) . '>' . esc_html($label) . '</option>';
        }
        return $html . '</select>';
    }

    public static function add_fields(): void
    {
        self::fields(self::rule_defaults());
    }

    public static function edit_fields($term): void
    {
        self::fields(self::rules((int) $term->term_id), (int) $term->term_id);
    }

    public static function save_term($term_id): void
    {
        if (!isset($_POST['_ffla_cat_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_ffla_cat_nonce'])), 'ffla_coupon_cat') || !current_user_can('manage_woocommerce')) {
            return;
        }
        $in = (array) wp_unslash($_POST['ffla_rules'] ?? []);
        $color = sanitize_hex_color((string) ($in['color'] ?? '')) ?: '#64748b';
        $term_id = (int) $term_id;
        $no_combine = array_values(array_diff(array_map('absint', (array) ($in['no_combine'] ?? [])), [0, $term_id]));
        // Keep the pairing symmetric, so removing it on either side removes it.
        $terms = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'fields' => 'ids']);
        foreach (is_wp_error($terms) ? [] : array_map('intval', $terms) as $other) {
            if ($other === $term_id) {
                continue;
            }
            $theirs = get_term_meta($other, self::META, true);
            if (!is_array($theirs)) {
                continue;
            }
            $list = array_map('intval', (array) ($theirs['no_combine'] ?? []));
            $has = in_array($term_id, $list, true);
            $want = in_array($other, $no_combine, true);
            if ($has !== $want) {
                $theirs['no_combine'] = array_values($want ? array_merge($list, [$term_id]) : array_diff($list, [$term_id]));
                update_term_meta($other, self::META, $theirs);
            }
        }
        update_term_meta($term_id, self::META, [
            'color'           => $color,
            'no_combine'      => $no_combine,
            'one_per_order'   => !empty($in['one_per_order']),
            'max_discount'    => max(0.0, (float) ($in['max_discount'] ?? 0)),
            'allow_protected' => !empty($in['allow_protected']),
            'roles'           => array_values(array_intersect((array) ($in['roles'] ?? []), array_merge(['guest'], array_keys(wp_roles()->get_names())))),
            'expiry_days'     => max(0, (int) ($in['expiry_days'] ?? 0)),
            'budget'          => max(0.0, (float) ($in['budget'] ?? 0)),
        ]);
        self::flush_spent();
    }

    public static function term_columns($columns)
    {
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ('name' === $key) {
                $out['ffla_rules'] = __('Rules', 'ffl-funnels-addons');
            }
        }
        return $out;
    }

    public static function term_column($content, $column, $term_id)
    {
        if ('ffla_rules' !== $column) {
            return $content;
        }
        $r = self::rules((int) $term_id);
        $bits = [];
        if ($r['one_per_order']) {
            $bits[] = __('one per order', 'ffl-funnels-addons');
        }
        $blocked = self::blocked_with((int) $term_id);
        if ($blocked) {
            /* translators: %s: categories */
            $bits[] = sprintf(__('not with %s', 'ffl-funnels-addons'), implode(', ', array_filter(array_map([__CLASS__, 'name'], $blocked))));
        }
        if ($r['max_discount']) {
            /* translators: %s: amount */
            $bits[] = sprintf(__('max %s', 'ffl-funnels-addons'), html_entity_decode(wp_strip_all_tags(wc_price($r['max_discount'])), ENT_QUOTES, 'UTF-8'));
        }
        if ($r['allow_protected']) {
            $bits[] = __('firearms allowed', 'ffl-funnels-addons');
        }
        if ($r['roles']) {
            /* translators: %s: roles */
            $bits[] = sprintf(__('only %s', 'ffl-funnels-addons'), implode(', ', $r['roles']));
        }
        if ($r['budget']) {
            /* translators: 1: used, 2: budget */
            $bits[] = sprintf(__('budget %1$s of %2$s this month', 'ffl-funnels-addons'), html_entity_decode(wp_strip_all_tags(wc_price(self::spent_this_month((int) $term_id))), ENT_QUOTES, 'UTF-8'), html_entity_decode(wp_strip_all_tags(wc_price($r['budget'])), ENT_QUOTES, 'UTF-8'));
        }
        return self::badge((int) $term_id) . ($bits ? '<br><span class="description">' . esc_html(implode(' · ', $bits)) . '</span>' : '');
    }

    public static function badge(int $term_id): string
    {
        $r = self::rules($term_id);
        return '<span class="ffla-cpn-cat" style="--c:' . esc_attr($r['color']) . '">' . esc_html(self::name($term_id)) . '</span>';
    }

    public static function coupon_columns($columns)
    {
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ('coupon_code' === $key) {
                $out['ffla_cat'] = __('Category', 'ffl-funnels-addons');
            }
        }
        return $out;
    }

    public static function coupon_column($column, $post_id): void
    {
        if ('ffla_cat' !== $column) {
            return;
        }
        $terms = wp_get_object_terms((int) $post_id, self::TAX, ['fields' => 'ids']);
        if (is_wp_error($terms) || !$terms) {
            echo '<span class="description">—</span>';
            return;
        }
        foreach ($terms as $term_id) {
            $url = add_query_arg([self::TAX => get_term((int) $term_id)->slug, 'post_type' => 'shop_coupon'], admin_url('edit.php'));
            echo '<a href="' . esc_url($url) . '">' . self::badge((int) $term_id) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    public static function filter_dropdown($post_type): void
    {
        if ('shop_coupon' !== $post_type) {
            return;
        }
        wp_dropdown_categories([
            'taxonomy'        => self::TAX,
            'name'            => self::TAX,
            'value_field'     => 'slug',
            'show_option_all' => __('All coupon categories', 'ffl-funnels-addons'),
            'hierarchical'    => true,
            'hide_empty'      => false,
            'selected'        => isset($_GET[self::TAX]) ? sanitize_title(wp_unslash($_GET[self::TAX])) : '', // phpcs:ignore WordPress.Security.NonceVerification
        ]);
    }
}
