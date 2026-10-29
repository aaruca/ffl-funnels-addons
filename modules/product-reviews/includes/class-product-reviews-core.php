<?php
/**
 * Product Reviews Core.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Core
{
    const MAX_MEDIA_FILES = 3;

    /**
     * Seed for the `review_criteria` setting. Lives here rather than in
     * Product_Reviews_Criteria so get_default_settings() never depends on that
     * class having been loaded first.
     */
    const DEFAULT_CRITERIA = "quality|Quality\nvalue|Value for money";

    const RATING_DISTRIBUTION_TRANSIENT_PREFIX = 'ffla_rev_dist_';

    /** Hourly count of new guest reviews per IP address. */
    const GUEST_RATE_TRANSIENT_PREFIX = 'ffla_rev_guest_';

    /** Order statuses whose signed review links stop working. */
    const TOKEN_DEAD_STATUSES = ['refunded', 'cancelled', 'failed', 'trash'];

    /** @var bool */
    private static $order_review_rewrite_tag_registered = false;

    /**
     * Set once the FFL form handler has checked the nonce, honeypot and
     * Turnstile for this request, so preprocess_comment does not check them a
     * second time (a Turnstile token can only be verified once). Review media
     * is only accepted when this is set.
     *
     * @var bool
     */
    private static $form_checks_passed = false;

    /**
     * The order behind a signed review link the FFL form handler validated
     * for this request, if any.
     *
     * @var \WC_Order|null
     */
    private static $token_order = null;

    public static function init(): void
    {
        add_filter('preprocess_comment', [__CLASS__, 'validate_review_submission']);
        add_action('comment_post', [__CLASS__, 'save_review_meta'], 10, 3);
        // A customer on the hub usually reviews several products back to back,
        // which WordPress's 15-second flood check would refuse.
        add_filter('wp_is_comment_flood', [__CLASS__, 'filter_comment_flood'], 20);
        add_action('delete_comment', [__CLASS__, 'cleanup_review_media'], 10, 1);

        // WooCommerce recounts a product's ratings itself whenever a review is
        // posted, edited, approved or deleted (`wp_update_comment_count`). A
        // rating written any other way is handled here.
        add_action('updated_comment_meta', [__CLASS__, 'flush_caches_for_rating_meta'], 10, 3);
        add_action('added_comment_meta', [__CLASS__, 'flush_caches_for_rating_meta'], 10, 3);
        add_action('admin_post_ffla_submit_product_review', [__CLASS__, 'handle_form_submission']);
        add_action('admin_post_nopriv_ffla_submit_product_review', [__CLASS__, 'handle_form_submission']);
        add_filter('woocommerce_product_tabs', [__CLASS__, 'maybe_replace_default_reviews_tab'], 98);
        add_filter('woocommerce_product_tabs', [__CLASS__, 'maybe_hide_default_reviews_tab'], 99);
        add_filter('pre_comment_approved', [__CLASS__, 'filter_pre_comment_approved'], 10, 2);
        add_action('init', [__CLASS__, 'register_order_review_rewrites'], 20);
        add_action('parse_request', [__CLASS__, 'parse_request_order_review_page'], 4);

        if (is_admin()) {
            // Classic Comments screen (WooCommerce before 6.7, or stores that
            // still list reviews there).
            add_filter('manage_edit-comments_columns', [__CLASS__, 'register_admin_comments_columns']);
            add_action('manage_comments_custom_column', [__CLASS__, 'render_admin_comments_columns'], 10, 2);
            add_action('admin_head-edit-comments.php', [__CLASS__, 'render_admin_comments_column_css']);

            // Products → Reviews (WooCommerce 6.7+), where reviews actually live.
            add_filter('woocommerce_product_reviews_table_columns', [__CLASS__, 'register_admin_comments_columns']);
            add_action('woocommerce_product_reviews_table_column_ffla_review_media', [__CLASS__, 'render_wc_reviews_media_column']);
            add_action('woocommerce_product_reviews_table_column_ffla_review_helpful', [__CLASS__, 'render_wc_reviews_helpful_column']);
            add_action('admin_head-product_page_product-reviews', [__CLASS__, 'render_admin_comments_column_css']);
        }
    }

    /**
     * Where staff manage product reviews: Products → Reviews on WooCommerce
     * 6.7+, otherwise the Comments screen.
     */
    public static function reviews_admin_url(string $status = ''): string
    {
        if (class_exists('\Automattic\WooCommerce\Internal\Admin\ProductReviews\Reviews')) {
            $args = ['post_type' => 'product', 'page' => 'product-reviews'];
            if ($status !== '') {
                $args['comment_status'] = $status;
            }

            return add_query_arg($args, admin_url('edit.php'));
        }

        return admin_url('edit-comments.php' . ($status !== '' ? '?comment_status=' . rawurlencode($status) : ''));
    }

    /**
     * @param \WP_Comment|mixed $item
     */
    public static function render_wc_reviews_media_column($item): void
    {
        if ($item instanceof \WP_Comment) {
            self::render_admin_comments_columns('ffla_review_media', (int) $item->comment_ID);
        }
    }

    /**
     * @param \WP_Comment|mixed $item
     */
    public static function render_wc_reviews_helpful_column($item): void
    {
        if ($item instanceof \WP_Comment) {
            self::render_admin_comments_columns('ffla_review_helpful', (int) $item->comment_ID);
        }
    }

    /**
     * Uploads on review forms (photos and videos).
     */
    public static function uploads_enabled(): bool
    {
        return '1' === self::get_setting('allow_media_uploads', '1');
    }

    public static function get_default_settings(): array
    {
        return [
            'enable_requests'           => '1',
            'request_delay_days'        => '7',
            'enable_helpful_votes'      => '1',
            'enable_not_helpful_votes'  => '0',
            'enable_criteria'           => '1',
            'review_criteria'           => self::DEFAULT_CRITERIA,
            'show_rating_summary'       => '1',
            'show_replies'              => '1',
            'list_per_page'             => '5',
            'list_toolbar'              => '1',
            'list_show_more'            => '1',
            'review_name_format'        => 'first_initial',
            'review_avatars'            => 'initials',
            'verified_label'            => __('Verified buyer', 'ffl-funnels-addons'),
            'review_lightbox'           => '1',
            'forbidden_words'           => '',
            'forbidden_action'          => 'moderate',
            'notify_on_approved'        => '1',
            'notify_on_reply'           => '1',
            'hide_default_reviews_tab'   => '0',
            'replace_default_reviews_tab' => '0',
            'form_title'                => __('Write a review', 'ffl-funnels-addons'),
            'allow_media_uploads'       => '1',
            'moderate_all_reviews'      => '0',
            'request_email_mode'        => 'bundle',
            'order_review_page_id'      => '0',
            'order_review_show_criteria' => '0',
            'order_review_pretty_urls'  => '0',
            'order_review_rewrite_slug' => 'order-review',
            'request_trigger_status'    => 'completed',
            'request_delay_unit'        => 'days',
            'request_country_delays'    => '',
            'request_send_hour'         => '',
            'request_send_days'         => [],
            'review_link_days'          => '90',
            'request_reminder'          => '0',
            'request_reminder_days'     => '7',
            'request_guests'            => '1',
            'request_roles_mode'        => 'all',
            'request_roles'             => [],
            'request_categories_mode'   => 'all',
            'request_categories'        => [],
            'request_skip_free'         => '0',
            'request_max_products'      => '0',
            'request_excluded_emails'   => '',
            'request_customer_window_days' => '0',
            'request_use_account_email' => '0',
            'request_consent'           => 'none',
            'request_consent_text'      => '',
            'email_format'              => 'html',
            'email_reminder_subject'    => '',
            'email_reminder_heading'    => '',
            'email_logo_id'             => '0',
            'email_brand_color'         => '',
            'email_background_color'    => '',
            'email_text_color'          => '',
            'email_star_color'          => '',
            'email_button_text'         => '',
            'email_show_products'       => '1',
            'email_footer_text'         => '',
            'email_reply_to'            => '',
            'email_bcc'                 => '',
            'email_tracking'            => '1',
            'coupon_enabled'            => '0',
            'coupon_who'                => 'verified',
            'coupon_roles_mode'         => 'all',
            'coupon_roles'              => [],
            'coupon_tier1_type'         => 'new',
            'coupon_tier1_discount_type' => 'percent',
            'coupon_tier1_amount'       => '10',
            'coupon_tier1_existing'     => '',
            'coupon_tier2_type'         => 'off',
            'coupon_tier2_min_media'    => '1',
            'coupon_tier2_discount_type' => 'percent',
            'coupon_tier2_amount'       => '15',
            'coupon_tier2_existing'     => '',
            'coupon_tier3_type'         => 'off',
            'coupon_tier3_min_media'    => '3',
            'coupon_tier3_discount_type' => 'percent',
            'coupon_tier3_amount'       => '20',
            'coupon_tier3_existing'     => '',
            'coupon_prefix'             => 'REVIEW-',
            'coupon_expiry_days'        => '30',
            'coupon_minimum_spend'      => '',
            'coupon_maximum_spend'      => '',
            'coupon_individual_use'     => '1',
            'coupon_exclude_sale'       => '0',
            'coupon_free_shipping'      => '0',
            'coupon_usage_limit'        => '1',
            'coupon_shareable'          => '0',
            'coupon_categories'         => [],
            'coupon_excluded_categories' => [],
            'coupon_email_subject'      => '',
            'coupon_email_heading'      => '',
            'coupon_email_text'         => '',
            'coupon_button_text'        => '',
            'coupon_badge'              => '1',
            'coupon_badge_text'         => '',
            'whatsapp_template'         => __('Hi {customer_name}, thank you for your order from {store_name}! Would you take a minute to review it? {review_url}', 'ffl-funnels-addons'),
            'email_subject'             => __('How was your purchase?', 'ffl-funnels-addons'),
            'email_heading'             => __('Leave a review for your recent order', 'ffl-funnels-addons'),
            'email_template'            => __("Hi {customer_name},\n\nWe would love your feedback on {product_name}.\n\nLeave your review here:\n{review_url}\n\nThank you!", 'ffl-funnels-addons'),
        ];
    }

    public static function get_settings(): array
    {
        $stored = get_option('ffla_product_reviews_settings', []);
        if (!is_array($stored)) {
            $stored = [];
        }

        return wp_parse_args($stored, self::get_default_settings());
    }

    public static function get_setting(string $key, $default = '')
    {
        $settings = self::get_settings();
        return $settings[$key] ?? $default;
    }

    /**
     * The hub page every request email links to: selected, a page, and
     * published. Without it the signed link has nowhere to land.
     */
    public static function hub_page_ready(): bool
    {
        $page_id = absint(self::get_setting('order_review_page_id', '0'));
        if ($page_id <= 0) {
            return false;
        }

        $page = get_post($page_id);

        return $page && 'page' === $page->post_type && 'publish' === $page->post_status;
    }

    /**
     * Review request emails are only scheduled and sent while requests are
     * switched on and the hub page is ready. Checked again at send time, so
     * switching requests off also stops emails already queued.
     */
    public static function requests_ready(): bool
    {
        return '1' === self::get_setting('enable_requests', '1') && self::hub_page_ready();
    }

    /**
     * WooCommerce's "Enable product reviews" and the product's own "Enable
     * reviews" box.
     */
    public static function product_reviews_open(int $product_id): bool
    {
        return 'yes' === get_option('woocommerce_enable_reviews', 'yes') && comments_open($product_id);
    }

    /**
     * Purchase check behind the "Verified buyer" label and WooCommerce's
     * "verified owners only" rule. Only a signed-in account counts: an
     * address typed into a guest form proves nothing. WooCommerce makes the
     * same call before it shows its own form.
     */
    public static function account_bought_product(int $user_id, int $product_id): bool
    {
        return $user_id > 0
            && $product_id > 0
            && function_exists('wc_customer_bought_product')
            && (bool) wc_customer_bought_product('', $user_id, $product_id);
    }

    /**
     * Use FFL review list + form inside WooCommerce’s Reviews tab instead of the core comments template.
     */
    public static function maybe_replace_default_reviews_tab(array $tabs): array
    {
        if ('1' !== self::get_setting('replace_default_reviews_tab', '0')) {
            return $tabs;
        }

        if (!isset($tabs['reviews'])) {
            return $tabs;
        }

        $tabs['reviews']['callback'] = [__CLASS__, 'render_wc_reviews_tab'];

        return $tabs;
    }

    /**
     * Callback for the product Reviews tab when "replace default" is enabled.
     */
    public static function render_wc_reviews_tab(): void
    {
        $product_id = self::resolve_context_product_id(0);
        if ($product_id <= 0) {
            return;
        }

        $form_settings = apply_filters('ffla_product_reviews_wc_tab_form_settings', [], $product_id);

        echo '<div class="ffla-wc-reviews-tab">';

        Product_Reviews_Frontend_Render::render_reviews_list($product_id, self::wc_tab_list_settings($product_id));
        Product_Reviews_Frontend_Render::render_review_form(
            $product_id,
            is_array($form_settings) ? $form_settings : []
        );

        echo '</div>';
    }

    /**
     * List settings for the replaced Reviews tab. Shared with the structured
     * data, which has to mark up the same reviews the tab shows.
     *
     * @return array<string, mixed>
     */
    public static function wc_tab_list_settings(int $product_id): array
    {
        $defaults = [
            'perPage' => max(1, min(50, absint(self::get_setting('list_per_page', '5')) ?: 5)),
            'orderBy' => 'recent',
        ];

        $settings = apply_filters('ffla_product_reviews_wc_tab_list_settings', $defaults, $product_id);

        return is_array($settings) ? $settings : $defaults;
    }

    public static function maybe_hide_default_reviews_tab(array $tabs): array
    {
        if ('1' === self::get_setting('replace_default_reviews_tab', '0')) {
            return $tabs;
        }

        if ('1' === self::get_setting('hide_default_reviews_tab', '0') && isset($tabs['reviews'])) {
            unset($tabs['reviews']);
        }

        return $tabs;
    }

    /**
     * Resolve product ID for Bricks elements: explicit ID, global product, main query, Query Loop, or builder preview.
     */
    public static function resolve_context_product_id(int $explicit_id = 0): int
    {
        if ($explicit_id > 0) {
            return self::normalize_to_parent_product_id($explicit_id);
        }

        global $product;
        if ($product instanceof \WC_Product) {
            if ($product->is_type('variation')) {
                return (int) $product->get_parent_id();
            }

            return (int) $product->get_id();
        }

        if (class_exists('\Bricks\Query')) {
            $loop_id = (int) \Bricks\Query::get_loop_object_id();
            $norm    = self::normalize_to_parent_product_id($loop_id);
            if ($norm > 0) {
                return $norm;
            }
            $loop_obj = \Bricks\Query::get_loop_object();
            if ($loop_obj instanceof \WP_Post) {
                $norm = self::normalize_to_parent_product_id((int) $loop_obj->ID);
                if ($norm > 0) {
                    return $norm;
                }
            }
        }

        if (function_exists('is_product') && is_product()) {
            $qid = (int) get_queried_object_id();
            $norm = self::normalize_to_parent_product_id($qid);
            if ($norm > 0) {
                return $norm;
            }
        }

        $tid = (int) get_the_ID();
        $norm = self::normalize_to_parent_product_id($tid);
        if ($norm > 0) {
            return $norm;
        }

        if (self::is_bricks_preview_context() && function_exists('wc_get_products')) {
            $ids = wc_get_products([
                'limit'   => 1,
                'status'  => 'publish',
                'return'  => 'ids',
                'orderby' => 'date',
                'order'   => 'DESC',
            ]);
            if (!empty($ids)) {
                return (int) $ids[0];
            }
        }

        return 0;
    }

    private static function is_bricks_preview_context(): bool
    {
        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return true;
        }
        if (function_exists('bricks_is_builder_call') && bricks_is_builder_call()) {
            return true;
        }
        if (class_exists('\Bricks\Database') && !empty(\Bricks\Database::$is_builder_call)) {
            return true;
        }

        return false;
    }

    /**
     * WooCommerce stores reviews on the parent product; map variation IDs to parent.
     */
    private static function normalize_to_parent_product_id(int $post_id): int
    {
        if ($post_id <= 0) {
            return 0;
        }

        $type = get_post_type($post_id);
        if ('product' === $type) {
            return $post_id;
        }

        if ('product_variation' === $type && function_exists('wc_get_product')) {
            $v = wc_get_product($post_id);
            $parent = $v ? (int) $v->get_parent_id() : 0;

            return $parent > 0 ? $parent : 0;
        }

        return 0;
    }

    public static function validate_review_submission(array $commentdata): array
    {
        if (empty($commentdata['comment_post_ID'])) {
            return $commentdata;
        }

        $product_id = absint($commentdata['comment_post_ID']);
        if ('product' !== get_post_type($product_id)) {
            return $commentdata;
        }

        // Allow admins with moderate_comments capability to bypass checks
        // (e.g., replies from the comments table, REST API submissions).
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            if ($user_id && user_can($user_id, 'moderate_comments')) {
                return self::apply_review_comment_approval($commentdata);
            }
        }

        // The FFL form handler already ran its checks for its own submissions.
        if (!self::$form_checks_passed) {
            self::check_native_submission($commentdata);
        }

        return self::apply_review_comment_approval($commentdata);
    }

    /**
     * A product comment that did not come through the FFL form handler:
     * WooCommerce's own form, or something posting to wp-comments-post.php.
     */
    private static function check_native_submission(array $commentdata): void
    {
        if (self::review_honeypot_triggered()) {
            wp_die(esc_html__('Your review could not be submitted.', 'ffl-funnels-addons'));
        }

        // FFL form fields posted to the native endpoint (an old cached copy of
        // the form). Same rules as the handler: a nonce only means something
        // for a signed-in user, and a signed order link replaces it.
        if (isset($_POST['ffla_review_form_nonce'])) {
            if (is_user_logged_in() && !self::request_order_token_valid()) {
                $nonce = sanitize_text_field(wp_unslash($_POST['ffla_review_form_nonce']));
                if (!wp_verify_nonce($nonce, 'ffla_review_form')) {
                    wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-funnels-addons'));
                }
            }

            if (!self::turnstile_token_valid_for_request()) {
                wp_die(esc_html__('Cloudflare validation failed. Please try again.', 'ffl-funnels-addons'));
            }

            return;
        }

        // With the Reviews tab replaced, WooCommerce's form is not on the page,
        // so a new top-level review arriving here was not typed into a form a
        // shopper could see. Require the challenge when one is available,
        // unless the Turnstile plugin already guards the comment form itself.
        if (self::is_native_top_level_review($commentdata)
            && '1' === self::get_setting('replace_default_reviews_tab', '0')
            && class_exists('Product_Reviews_Turnstile')
            && Product_Reviews_Turnstile::is_available()
            && !Product_Reviews_Turnstile::protects_native_comments()
            && !Product_Reviews_Turnstile::passes()) {
            wp_die(
                esc_html__('Cloudflare validation failed. Please try again.', 'ffl-funnels-addons'),
                '',
                ['response' => 403, 'back_link' => true]
            );
        }
    }

    /**
     * A new top-level review sent through wp-comments-post.php. Programmatic
     * wp_new_comment() calls (imports, other plugins) are left alone.
     */
    private static function is_native_top_level_review(array $commentdata): bool
    {
        return did_action('pre_comment_on_post') > 0 && empty($commentdata['comment_parent']);
    }

    /**
     * WooCommerce's own form, or a direct post to wp-comments-post.php, gets
     * the same "verified owners only" and one-review-per-customer rules as the
     * FFL form.
     */
    private static function native_review_refusal(int $product_id, array $commentdata): ?\WP_Error
    {
        $user_id = (int) ($commentdata['user_id'] ?? 0);
        $email   = (string) ($commentdata['comment_author_email'] ?? '');

        if ('yes' === get_option('woocommerce_review_rating_verification_required', 'no')
            && !self::account_bought_product($user_id, $product_id)) {
            return new \WP_Error(
                'ffla_review_unverified',
                __('Only customers who bought this product can review it.', 'ffl-funnels-addons'),
                403
            );
        }

        $duplicate = $user_id > 0
            ? self::customer_has_review_for_product($email, $product_id, $user_id)
            : self::guest_has_review_for_product($email, $product_id);
        if ($duplicate) {
            return new \WP_Error(
                'ffla_review_duplicate',
                __('You already submitted a review for this product.', 'ffl-funnels-addons'),
                409
            );
        }

        return null;
    }

    /**
     * @param bool $is_flood
     * @return bool
     */
    public static function filter_comment_flood($is_flood)
    {
        return self::$token_order instanceof \WC_Order ? false : $is_flood;
    }

    /**
     * Hold reviews when admin enables moderation, when media is attached, or
     * when the text trips the forbidden-word list.
     */
    private static function apply_review_comment_approval(array $commentdata): array
    {
        if ('1' === self::get_setting('moderate_all_reviews', '0')) {
            $commentdata['comment_approved'] = 0;
        }

        $content = (string) ($commentdata['comment_content'] ?? '');
        $author  = (string) ($commentdata['comment_author'] ?? '');

        if (class_exists('Product_Reviews_Moderation')) {
            if (Product_Reviews_Moderation::should_reject($content, $author)) {
                // The submit handler catches this earlier and shows a message.
                // Anything arriving through another path (REST, imports) is
                // filed as spam rather than killed with wp_die().
                $commentdata['comment_approved'] = 'spam';

                return $commentdata;
            }

            if (Product_Reviews_Moderation::should_hold($content, $author)) {
                $commentdata['comment_approved'] = 0;
            }
        }

        return self::apply_media_moderation_flag($commentdata);
    }

    /**
     * Final say on whether a review is published, held, or refused.
     *
     * `pre_comment_approved` is the last filter wp_allow_comment() applies, and
     * wp_allow_comment() runs *after* `preprocess_comment` and overwrites
     * comment_approved wholesale. Any hold decided earlier in the pipeline is
     * therefore silently discarded, so every rule has to be re-applied here.
     *
     * Returning a WP_Error here refuses the comment: wp_new_comment() hands it
     * back to the caller, and wp-comments-post.php shows its message.
     *
     * @param int|string|\WP_Error $approved
     * @return int|string|\WP_Error
     */
    public static function filter_pre_comment_approved($approved, array $commentdata)
    {
        if (is_wp_error($approved)) {
            return $approved;
        }

        if (($commentdata['comment_type'] ?? '') !== 'review') {
            return $approved;
        }

        $post_id = isset($commentdata['comment_post_ID']) ? (int) $commentdata['comment_post_ID'] : 0;
        if ($post_id <= 0 || 'product' !== get_post_type($post_id)) {
            return $approved;
        }

        // Never soften a verdict WordPress already reached on its own.
        if ('spam' === $approved || 'trash' === $approved) {
            return $approved;
        }

        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            if ($uid && user_can($uid, 'moderate_comments')) {
                return $approved;
            }
        }

        // The FFL form handler has already applied these rules to its own
        // submissions (with the signed order link in mind).
        if (!self::$form_checks_passed && self::is_native_top_level_review($commentdata)) {
            $refusal = self::native_review_refusal($post_id, $commentdata);
            if ($refusal) {
                return $refusal;
            }
        }

        $content = (string) ($commentdata['comment_content'] ?? '');
        $author  = (string) ($commentdata['comment_author'] ?? '');

        if (class_exists('Product_Reviews_Moderation')) {
            if (Product_Reviews_Moderation::should_reject($content, $author)) {
                return 'spam';
            }

            if (Product_Reviews_Moderation::should_hold($content, $author)) {
                return 0;
            }
        }

        if ('1' === self::get_setting('moderate_all_reviews', '0')) {
            return 0;
        }

        if (self::has_review_media_upload()) {
            return 0;
        }

        return $approved;
    }

    public static function register_order_review_rewrites(): void
    {
        if (!self::$order_review_rewrite_tag_registered) {
            add_rewrite_tag('%ffla_order_review_token%', '([A-Za-z0-9._~-]+)');
            self::$order_review_rewrite_tag_registered = true;
        }

        if ('1' !== self::get_setting('order_review_pretty_urls', '0')) {
            return;
        }

        $slug = sanitize_title(self::get_setting('order_review_rewrite_slug', 'order-review'));
        if ($slug === '') {
            return;
        }

        add_rewrite_rule(
            '^' . preg_quote($slug, '/') . '/([A-Za-z0-9._~-]+)/?$',
            'index.php?ffla_order_review_token=$matches[1]',
            'top'
        );
    }

    /**
     * Map /{slug}/{token}/ to the configured hub page and preserve token for the hub renderer.
     *
     * @param \WP $wp
     */
    public static function parse_request_order_review_page($wp): void
    {
        if (!isset($wp->query_vars['ffla_order_review_token']) || $wp->query_vars['ffla_order_review_token'] === '') {
            return;
        }

        $token = (string) $wp->query_vars['ffla_order_review_token'];
        Product_Reviews_Order_Hub::set_context_token($token);

        $page_id = absint(self::get_setting('order_review_page_id', '0'));
        if ($page_id <= 0) {
            return;
        }

        $page = get_post($page_id);
        if (!$page || 'page' !== $page->post_type || 'publish' !== $page->post_status) {
            return;
        }

        $wp->query_vars = [
            'page_id'   => $page_id,
            'post_type' => 'page',
        ];
    }

    public static function order_review_token_secret(): string
    {
        return wp_salt('ffla_product_reviews_order');
    }

    /**
     * How long a signed review link works (Review requests → Link valid for).
     */
    public static function review_link_days(): int
    {
        return max(1, min(365, absint(self::get_setting('review_link_days', '90')) ?: 90));
    }

    public static function build_order_review_token(int $order_id, string $billing_email): string
    {
        $exp = time() + (self::review_link_days() * DAY_IN_SECONDS);
        $eh  = md5(strtolower(trim($billing_email)));
        $payload = $order_id . '|' . $exp . '|' . $eh;
        $sig     = hash_hmac('sha256', $payload, self::order_review_token_secret());

        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    /**
     * @return array{order_id:int,expires:int,email_hash:string}|null
     */
    public static function parse_order_review_token_payload(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $s = strtr($token, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }

        $bin = base64_decode($s, true);
        if ($bin === false || substr_count($bin, '|') < 3) {
            return null;
        }

        $parts = explode('|', $bin, 4);
        if (count($parts) !== 4) {
            return null;
        }

        [$oid, $exp, $eh, $sig] = $parts;
        $payload = $oid . '|' . $exp . '|' . $eh;
        $expected = hash_hmac('sha256', $payload, self::order_review_token_secret());
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        if ((int) $exp < time()) {
            return null;
        }

        return [
            'order_id'   => (int) $oid,
            'expires'    => (int) $exp,
            'email_hash' => $eh,
        ];
    }

    public static function get_order_for_review_token(string $token): ?\WC_Order
    {
        if ($token === '') {
            return null;
        }

        $data = self::parse_order_review_token_payload($token);
        if (!$data) {
            return null;
        }

        $order = wc_get_order($data['order_id']);
        if (!$order) {
            return null;
        }

        $eh = md5(strtolower(trim((string) $order->get_billing_email())));
        if (!hash_equals($data['email_hash'], $eh)) {
            return null;
        }

        // A refunded or cancelled order is no longer a purchase to review.
        if ($order->has_status(self::TOKEN_DEAD_STATUSES)) {
            return null;
        }

        return $order;
    }

    public static function line_item_parent_product_id(\WC_Order_Item_Product $item): int
    {
        $product = $item->get_product();
        if ($product && $product->is_type('variation')) {
            return (int) $product->get_parent_id();
        }

        return (int) $item->get_product_id();
    }

    /**
     * A line refunded in full no longer counts as a purchase.
     */
    public static function order_line_is_reviewable(\WC_Order $order, \WC_Order_Item_Product $item): bool
    {
        $refunded = abs((int) $order->get_qty_refunded_for_item($item->get_id()));

        return (int) $item->get_quantity() > $refunded;
    }

    public static function order_contains_reviewable_product(\WC_Order $order, int $parent_product_id): bool
    {
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            if (self::line_item_parent_product_id($item) === $parent_product_id
                && self::order_line_is_reviewable($order, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parent product IDs in the order that can still be reviewed: at least one
     * line not refunded in full, and reviews open on the product. Used for both
     * the request emails and the hub forms.
     *
     * @return array<int, int>
     */
    public static function reviewable_product_ids(\WC_Order $order): array
    {
        $ids = [];

        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }

            $product_id = self::line_item_parent_product_id($item);
            if ($product_id <= 0 || isset($ids[$product_id]) || 'product' !== get_post_type($product_id)) {
                continue;
            }

            // Another line of the same product (a second variation) may still
            // qualify, so this one is skipped rather than the product.
            if (!self::order_line_is_reviewable($order, $item) || !self::product_reviews_open($product_id)) {
                continue;
            }

            $ids[$product_id] = $product_id;
        }

        return array_values($ids);
    }

    /**
     * Whether the customer already has a top-level review (approved or
     * pending) on the product.
     *
     * Counts reviews left from the customer's account, and reviews under the
     * address that were verified (a signed order link or a signed-in buyer).
     * A review someone else typed the address into does not count, so it can
     * neither block the real customer nor stop their request emails.
     */
    public static function customer_has_review_for_product(string $email, int $product_id, int $user_id = 0): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        $args = self::top_level_review_query($product_id) + ['number' => 1];

        if ($user_id > 0 && !empty(get_comments($args + ['user_id' => $user_id]))) {
            return true;
        }

        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        return !empty(get_comments($args + [
            'author_email' => $email,
            'meta_key'     => 'ffla_verified_purchase',
            'meta_value'   => '1',
        ]));
    }

    /**
     * One review per address for guests without a signed link: whether a
     * guest review that proves nothing (no account, no verified purchase)
     * already sits under this address. Verified and account reviews are
     * deliberately not consulted, or the guest form would tell anyone who
     * types an address whether its owner bought the product.
     */
    public static function guest_has_review_for_product(string $email, int $product_id): bool
    {
        $email = strtolower(trim($email));
        if ($product_id <= 0 || $email === '') {
            return false;
        }

        $ids = get_comments(self::top_level_review_query($product_id) + [
            'author_email' => $email,
            'user_id'      => 0,
        ]);

        foreach ($ids as $id) {
            if (1 !== (int) get_comment_meta((int) $id, 'ffla_verified_purchase', true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function top_level_review_query(int $product_id): array
    {
        return [
            'post_id' => $product_id,
            'type'    => 'review',
            'parent'  => 0,
            'status'  => 'all',
            'fields'  => 'ids',
        ];
    }

    /* ---------------------------------------------------------------------
     * Aggregates
     * ------------------------------------------------------------------- */

    /**
     * Called whenever a product's reviews change. WooCommerce keeps the rating
     * aggregates itself, so all that is left is the histogram cache that
     * versions before 1.55.10 stored.
     */
    public static function flush_product_review_caches(int $product_id): void
    {
        if ($product_id <= 0 || 'product' !== get_post_type($product_id)) {
            return;
        }

        delete_transient(self::RATING_DISTRIBUTION_TRANSIENT_PREFIX . $product_id);
    }

    /**
     * A rating written outside WooCommerce's own review flows (custom code,
     * imports, WP-CLI) leaves the product's stored counts stale. WooCommerce
     * recounts by itself after a review is posted (`comment_post`) or edited
     * (`wp_update_comment_data`), so those two paths are left to it.
     *
     * @param int|array $meta_id
     */
    public static function flush_caches_for_rating_meta($meta_id, $comment_id, $meta_key): void
    {
        if ('rating' !== $meta_key || doing_action('comment_post') || doing_filter('wp_update_comment_data')) {
            return;
        }

        $comment = get_comment((int) $comment_id);
        if (!$comment || '1' !== (string) $comment->comment_approved) {
            return;
        }

        if (class_exists('WC_Comments') && method_exists('WC_Comments', 'clear_transients')) {
            \WC_Comments::clear_transients((int) $comment->comment_post_ID);
        }
    }

    /**
     * Star counts, average and review count for a product, e.g. counts
     * [5 => 12, 4 => 3, 3 => 0, 2 => 0, 1 => 1].
     *
     * Read from the aggregates WooCommerce stores on the product
     * (`_wc_rating_count`, `_wc_average_rating`, `_wc_review_count`), so the
     * summary, badge and dynamic tags show the same numbers as the Reviews tab
     * title, the shop loop stars and the structured data. It runs no query of
     * its own: in a product loop the product and its meta are already loaded.
     *
     * `total` is the number of ratings, which the bars and the 4-5 star share
     * divide by. `review_count` is WooCommerce's review count, the number shown
     * to shoppers; it also counts reviews left without a rating.
     *
     * @param \WC_Product|null $product Pass it when already loaded.
     * @return array{counts:array<int,int>,total:int,average:float,review_count:int}
     */
    public static function get_rating_distribution(int $product_id, $product = null): array
    {
        $result = [
            'counts'       => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
            'total'        => 0,
            'average'      => 0.0,
            'review_count' => 0,
        ];

        if (!$product instanceof \WC_Product) {
            $product = ($product_id > 0 && function_exists('wc_get_product')) ? wc_get_product($product_id) : null;
        }

        if (!$product instanceof \WC_Product) {
            return $result;
        }

        foreach ((array) $product->get_rating_counts() as $stars => $count) {
            $stars = (int) $stars;
            if ($stars < 1 || $stars > 5) {
                continue;
            }

            $result['counts'][$stars] += (int) $count;
            $result['total'] += (int) $count;
        }

        $result['average']      = $result['total'] > 0 ? (float) $product->get_average_rating() : 0.0;
        $result['review_count'] = (int) $product->get_review_count();

        return $result;
    }

    /**
     * Every review saved while the module is on carries its own verdict,
     * `ffla_verified_purchase`, and that verdict is final even when it is 0.
     * WooCommerce's `verified` flag is only read for reviews without one,
     * i.e. written before the module was switched on. It must never be OR-ed
     * in: for guests WooCommerce sets it from the email they typed, so anyone
     * entering a past buyer's address would get the label.
     */
    public static function is_verified_purchase(int $comment_id): bool
    {
        if (metadata_exists('comment', $comment_id, 'ffla_verified_purchase')) {
            return (int) get_comment_meta($comment_id, 'ffla_verified_purchase', true) === 1;
        }

        return (int) get_comment_meta($comment_id, 'verified', true) === 1;
    }

    /**
     * Approved replies for a set of reviews, grouped by parent, in one query.
     *
     * No `type` filter: a reply left from the WordPress comments screen is
     * stored as a plain comment, not as a review.
     *
     * @param array<int, int> $review_ids
     * @return array<int, array<int, \WP_Comment>>
     */
    public static function get_replies_for_reviews(array $review_ids): array
    {
        $review_ids = array_values(array_filter(array_map('absint', $review_ids)));
        if (empty($review_ids)) {
            return [];
        }

        $replies = get_comments([
            'parent__in' => $review_ids,
            'status'     => 'approve',
            'orderby'    => 'comment_date_gmt',
            'order'      => 'ASC',
        ]);

        $grouped = [];
        foreach ($replies as $reply) {
            $grouped[(int) $reply->comment_parent][] = $reply;
        }

        return $grouped;
    }

    public static function order_review_return_base_url(): string
    {
        $page_id = absint(self::get_setting('order_review_page_id', '0'));
        if ($page_id > 0) {
            $u = get_permalink($page_id);
            if ($u) {
                return $u;
            }
        }

        return home_url('/');
    }

    public static function append_order_review_token_to_url(string $url, string $token): string
    {
        if ('1' === self::get_setting('order_review_pretty_urls', '0')) {
            $slug = sanitize_title(self::get_setting('order_review_rewrite_slug', 'order-review'));
            if ($slug !== '') {
                return trailingslashit(home_url($slug)) . rawurlencode($token) . '/';
            }
        }

        return add_query_arg('ffla_ro', rawurlencode($token), $url);
    }

    public static function order_review_landing_url(string $token): string
    {
        if ('1' === self::get_setting('order_review_pretty_urls', '0')) {
            $slug = sanitize_title(self::get_setting('order_review_rewrite_slug', 'order-review'));
            if ($slug !== '') {
                return trailingslashit(home_url($slug)) . rawurlencode($token) . '/';
            }
        }

        $base = self::order_review_return_base_url();

        return add_query_arg('ffla_ro', rawurlencode($token), $base);
    }

    private static function review_honeypot_triggered(): bool
    {
        $honeypot = isset($_POST['ffla_hp']) ? trim((string) wp_unslash($_POST['ffla_hp'])) : '';

        return $honeypot !== '';
    }

    /**
     * Validate the Turnstile response (when the Simple Cloudflare Turnstile
     * plugin is active). Signed order-review links bypass the challenge
     * because the token already proves the request comes from the customer.
     */
    private static function turnstile_token_valid_for_request(): bool
    {
        if (!class_exists('Product_Reviews_Turnstile') || !Product_Reviews_Turnstile::is_available()) {
            return true;
        }

        if (self::request_order_token_valid()) {
            return true;
        }

        return Product_Reviews_Turnstile::passes();
    }

    /**
     * The posted signed order link is valid and the posted product is in that
     * order.
     */
    private static function request_order_token_valid(): bool
    {
        if (empty($_POST['ffla_order_review_token']) || empty($_POST['comment_post_ID'])) {
            return false;
        }

        $raw = sanitize_text_field(wp_unslash($_POST['ffla_order_review_token']));
        $order = self::get_order_for_review_token($raw);
        if (!$order) {
            return false;
        }

        $pid = self::normalize_to_parent_product_id(absint($_POST['comment_post_ID']));

        return self::order_contains_reviewable_product($order, $pid);
    }

    private static function apply_media_moderation_flag(array $commentdata): array
    {
        if (self::has_review_media_upload()) {
            $commentdata['comment_approved'] = 0;
        }

        return $commentdata;
    }

    /**
     * @param int|string $comment_approved 1, 0, 'spam' or 'trash'. Untyped: a
     *                                     string verdict against an int type
     *                                     is a TypeError that kills the request.
     */
    public static function save_review_meta(int $comment_id, $comment_approved, array $commentdata): void
    {
        if (empty($commentdata['comment_post_ID'])) {
            return;
        }

        $product_id = absint($commentdata['comment_post_ID']);
        if ('product' !== get_post_type($product_id)) {
            return;
        }

        $rating = isset($_POST['rating']) ? absint($_POST['rating']) : 0;
        if ($rating > 0) {
            update_comment_meta($comment_id, 'rating', min(5, $rating));
        }

        if (class_exists('Product_Reviews_Criteria')) {
            Product_Reviews_Criteria::save_review_scores($comment_id);
        }

        if (!metadata_exists('comment', $comment_id, 'ffla_helpful_yes')) {
            update_comment_meta($comment_id, 'ffla_helpful_yes', 0);
        }

        if (!metadata_exists('comment', $comment_id, 'ffla_helpful_no')) {
            update_comment_meta($comment_id, 'ffla_helpful_no', 0);
        }

        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }

        $verified = self::review_proves_purchase($comment, $product_id) ? 1 : 0;
        update_comment_meta($comment_id, 'ffla_verified_purchase', $verified);

        // A review through the order's signed link: the request worked.
        if (self::$token_order instanceof \WC_Order && class_exists('Product_Reviews_Tracking')
            && !in_array((string) $comment_approved, ['spam', 'trash'], true)) {
            update_comment_meta($comment_id, 'ffla_review_order_id', (int) self::$token_order->get_id());
            Product_Reviews_Tracking::record_review(self::$token_order);
        }

        // WooCommerce writes its own "verified owner" flag on this same hook
        // from the typed address (the hole closed above), and its templates,
        // REST API and blocks show it. Keep both flags on the stricter answer;
        // either hook order ends here, since WooCommerce only adds the flag
        // when none exists.
        update_comment_meta($comment_id, 'verified', $verified);

        // Files are only taken from the FFL form, after its nonce, honeypot and
        // Turnstile checks, and never for a review filed as spam or trash.
        // WooCommerce's form has no upload field, so files posted straight to
        // wp-comments-post.php are not a shopper's photos.
        if (self::$form_checks_passed && !in_array((string) $comment_approved, ['spam', 'trash'], true)) {
            self::save_review_media($comment_id);
        }
    }

    /**
     * "Verified buyer" comes from a signed order link (validated by the FFL
     * form handler, for an order that still contains the product, under the
     * order's own address) or from a signed-in account that bought the
     * product. The address on the review is never enough: anyone can type a
     * past buyer's email into a guest form.
     */
    private static function review_proves_purchase(\WP_Comment $comment, int $product_id): bool
    {
        if (self::$token_order instanceof \WC_Order
            && self::order_contains_reviewable_product(self::$token_order, $product_id)
            && strtolower(trim((string) self::$token_order->get_billing_email())) === strtolower(trim((string) $comment->comment_author_email))) {
            return true;
        }

        return self::account_bought_product((int) $comment->user_id, $product_id);
    }

    private static function save_review_media(int $comment_id): void
    {
        if (!self::uploads_enabled()) {
            return;
        }

        if (empty($_FILES['ffla_review_media']) || !is_array($_FILES['ffla_review_media'])) {
            return;
        }

        $files = self::normalize_uploads_array($_FILES['ffla_review_media']);
        if (empty($files)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $allowed_mimes = [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'gif'      => 'image/gif',
            'webp'     => 'image/webp',
            'mp4'      => 'video/mp4',
            'webm'     => 'video/webm',
        ];

        $attachment_ids = [];
        $processed = 0;

        foreach ($files as $file) {
            if ($processed >= self::MAX_MEDIA_FILES) {
                break;
            }

            if (!empty($file['error']) || empty($file['tmp_name'])) {
                continue;
            }

            if (!empty($file['size']) && (int) $file['size'] > (5 * 1024 * 1024)) {
                continue;
            }

            $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mimes);
            if (empty($check['ext']) || empty($check['type'])) {
                continue;
            }

            // Review photos are public files, and phone photos carry the GPS
            // position they were taken at. A photo that cannot be cleaned is
            // skipped rather than published as it is.
            if (strpos((string) $check['type'], 'image/') === 0) {
                if (!Product_Reviews_Image_Privacy::strip_file((string) $file['tmp_name'], (string) $check['type'])) {
                    continue;
                }
                clearstatcache(true, (string) $file['tmp_name']);
                $size = @filesize((string) $file['tmp_name']);
                if (false !== $size) {
                    $file['size'] = (int) $size;
                }
            }

            $_FILES['ffla_review_media_single'] = $file;
            $attachment_id = media_handle_upload(
                'ffla_review_media_single',
                0,
                [],
                [
                    'test_form' => false,
                    'mimes'     => $allowed_mimes,
                ]
            );
            unset($_FILES['ffla_review_media_single']);

            if (!is_wp_error($attachment_id) && $attachment_id > 0) {
                $attachment_ids[] = (int) $attachment_id;
                $processed++;
            }
        }

        if (!empty($attachment_ids)) {
            update_comment_meta($comment_id, 'ffla_review_media_ids', array_values(array_unique($attachment_ids)));
        }
    }

    public static function cleanup_review_media(int $comment_id): void
    {
        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }

        $product_id = (int) $comment->comment_post_ID;
        if ('product' !== get_post_type($product_id)) {
            return;
        }

        $media_ids = get_comment_meta($comment_id, 'ffla_review_media_ids', true);
        if (!is_array($media_ids) || empty($media_ids)) {
            return;
        }

        foreach ($media_ids as $media_id) {
            $media_id = absint($media_id);
            if ($media_id > 0) {
                wp_delete_attachment($media_id, true);
            }
        }
    }

    public static function register_admin_comments_columns(array $columns): array
    {
        $columns['ffla_review_media'] = __('Review Media', 'ffl-funnels-addons');
        $columns['ffla_review_helpful'] = __('Helpful', 'ffl-funnels-addons');
        return $columns;
    }

    public static function render_admin_comments_columns(string $column, int $comment_id): void
    {
        $comment = get_comment($comment_id);
        if (!$comment || 'product' !== get_post_type((int) $comment->comment_post_ID)) {
            return;
        }

        if ('ffla_review_helpful' === $column) {
            $yes = (int) get_comment_meta($comment_id, 'ffla_helpful_yes', true);
            $no  = (int) get_comment_meta($comment_id, 'ffla_helpful_no', true);

            echo esc_html($no > 0 ? $yes . ' / -' . $no : (string) $yes);

            if ((int) $comment->comment_karma === 1) {
                echo '<div class="ffla-comment-pinned">' . esc_html__('Pinned', 'ffl-funnels-addons') . '</div>';
            }

            return;
        }

        if ('ffla_review_media' !== $column) {
            return;
        }

        $media_ids = get_comment_meta($comment_id, 'ffla_review_media_ids', true);
        if (!is_array($media_ids) || empty($media_ids)) {
            echo '<span aria-hidden="true">-</span>';
            return;
        }

        $max_preview = 3;
        $shown = 0;

        echo '<div class="ffla-comment-media-preview">';
        foreach ($media_ids as $media_id) {
            if ($shown >= $max_preview) {
                break;
            }

            $media_id = absint($media_id);
            if ($media_id <= 0) {
                continue;
            }

            $mime = (string) get_post_mime_type($media_id);
            $url  = wp_get_attachment_url($media_id);
            if (!$url) {
                continue;
            }

            if (strpos($mime, 'image/') === 0) {
                $thumb = wp_get_attachment_image_url($media_id, 'thumbnail');
                if (!$thumb) {
                    $thumb = $url;
                }
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__('Open media', 'ffl-funnels-addons') . '">';
                echo '<img src="' . esc_url($thumb) . '" alt="" />';
                echo '</a>';
            } else {
                echo '<a class="ffla-comment-media-file" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">';
                echo esc_html__('Video', 'ffl-funnels-addons');
                echo '</a>';
            }

            $shown++;
        }
        echo '</div>';

        $remaining = count($media_ids) - $shown;
        if ($remaining > 0) {
            echo '<div class="ffla-comment-media-more">+' . esc_html((string) $remaining) . '</div>';
        }
    }

    public static function render_admin_comments_column_css(): void
    {
        echo '<style>
            .column-ffla_review_media { width: 160px; }
            .column-ffla_review_helpful { width: 80px; }
            .ffla-comment-media-preview { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
            .ffla-comment-media-preview img { width:28px; height:28px; object-fit:cover; border-radius:4px; border:1px solid #dcdcde; }
            .ffla-comment-media-file { display:inline-block; padding:2px 6px; border:1px solid #dcdcde; border-radius:4px; text-decoration:none; font-size:11px; }
            .ffla-comment-media-more { font-size:11px; color:#646970; margin-top:2px; }
            .ffla-comment-pinned { display:inline-block; margin-top:4px; padding:1px 6px; border-radius:3px; background:#fdf6e3; color:#8a6100; font-size:11px; font-weight:600; }
        </style>';
    }

    /**
     * Files the FFL form will actually store (the ones that hold a review for
     * moderation). Anything else posted with a review is ignored.
     */
    private static function has_review_media_upload(): bool
    {
        if (!self::$form_checks_passed || !self::uploads_enabled()) {
            return false;
        }

        if (empty($_FILES['ffla_review_media']) || !is_array($_FILES['ffla_review_media'])) {
            return false;
        }

        $files = $_FILES['ffla_review_media'];
        if (!isset($files['name'])) {
            return false;
        }

        if (is_array($files['name'])) {
            foreach ($files['name'] as $name) {
                if (!empty($name)) {
                    return true;
                }
            }
            return false;
        }

        return !empty($files['name']);
    }

    /**
     * Flatten $_FILES['ffla_review_media'] for name="ffla_review_media[]" + multiple.
     * Supports multi-file shape (name/type/tmp_name as arrays) and single-file legacy shape (scalar keys).
     */
    private static function normalize_uploads_array(array $files): array
    {
        if (!isset($files['name'])) {
            return [];
        }

        if (!is_array($files['name'])) {
            return [
                [
                    'name'     => $files['name'],
                    'type'     => $files['type'] ?? '',
                    'tmp_name' => $files['tmp_name'] ?? '',
                    'error'    => isset($files['error']) ? (int) $files['error'] : UPLOAD_ERR_NO_FILE,
                    'size'     => isset($files['size']) ? (int) $files['size'] : 0,
                ],
            ];
        }

        $normalized = [];
        foreach ($files['name'] as $idx => $name) {
            $normalized[] = [
                'name'     => $name,
                'type'     => $files['type'][$idx] ?? '',
                'tmp_name' => $files['tmp_name'][$idx] ?? '',
                'error'    => isset($files['error'][$idx]) ? (int) $files['error'][$idx] : UPLOAD_ERR_NO_FILE,
                'size'     => isset($files['size'][$idx]) ? (int) $files['size'][$idx] : 0,
            ];
        }

        return $normalized;
    }

    public static function handle_form_submission(): void
    {
        $product_id = isset($_POST['comment_post_ID']) ? absint($_POST['comment_post_ID']) : 0;
        $product_id = self::normalize_to_parent_product_id($product_id);

        $order_token_raw = isset($_POST['ffla_order_review_token'])
            ? sanitize_text_field(wp_unslash($_POST['ffla_order_review_token']))
            : '';
        $order_ctx = $order_token_raw !== '' ? self::get_order_for_review_token($order_token_raw) : null;

        $redirect_raw = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';

        $fallback = home_url('/');
        if ($order_ctx) {
            $fallback = self::append_order_review_token_to_url(self::order_review_return_base_url(), $order_token_raw);
        } elseif ($product_id > 0 && 'product' === get_post_type($product_id)) {
            $fallback = get_permalink($product_id);
        }

        $redirect = $redirect_raw !== '' ? wp_validate_redirect($redirect_raw, $fallback) : $fallback;

        if ($product_id <= 0 || 'product' !== get_post_type($product_id)) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('Invalid product for review.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        // Expired, tampered with, or the order was refunded or cancelled.
        if ($order_token_raw !== '' && !$order_ctx) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('This review link is invalid or has expired.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        // A signed order link is its own proof. Otherwise only a signed-in
        // user's nonce means anything: a guest nonce is the same for every
        // visitor and goes stale on cached product pages, so guests rely on
        // the honeypot, Turnstile and the hourly limit below instead.
        if (!$order_ctx && is_user_logged_in()
            && (!isset($_POST['ffla_review_form_nonce'])
                || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffla_review_form_nonce'])), 'ffla_review_form'))) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('Security check failed. Please refresh and try again.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        if (self::review_honeypot_triggered()) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('Your review could not be submitted.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        if (!self::turnstile_token_valid_for_request()) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('Cloudflare validation failed. Please try again.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        // These three checks are done; preprocess_comment must not repeat them.
        self::$form_checks_passed = true;

        // WooCommerce's own review rules apply to this form too.
        if (!self::product_reviews_open($product_id)) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('Reviews are closed for this product.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        // Also refuses a line that was refunded in full.
        if ($order_ctx && !self::order_contains_reviewable_product($order_ctx, $product_id)) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('That product is not part of this order.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        if ($order_ctx) {
            self::$token_order = $order_ctx;
        }

        $comment_content = isset($_POST['comment']) ? trim((string) wp_unslash($_POST['comment'])) : '';
        $rating          = isset($_POST['rating']) ? absint($_POST['rating']) : 0;

        if ($comment_content === '') {
            self::redirect_with_status($redirect, 'error', __('Review text is required.', 'ffl-funnels-addons'), $fallback);
        }

        if ($rating < 1 || $rating > 5) {
            self::redirect_with_status($redirect, 'error', __('Please select a rating between 1 and 5.', 'ffl-funnels-addons'), $fallback);
        }

        // Matches the exemption in filter_pre_comment_approved(): a user who can
        // moderate comments is never filtered by their own word list.
        $can_moderate = is_user_logged_in() && current_user_can('moderate_comments');

        $submitted_author = isset($_POST['author']) ? sanitize_text_field(wp_unslash($_POST['author'])) : '';
        if (!$can_moderate
            && class_exists('Product_Reviews_Moderation')
            && Product_Reviews_Moderation::should_reject($comment_content, $submitted_author)) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('Your review contains wording we cannot publish. Please revise it and try again.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        if (!$order_ctx && !is_user_logged_in() && get_option('comment_registration')) {
            self::redirect_with_status($redirect, 'error', __('You must be logged in to submit a review.', 'ffl-funnels-addons'), $fallback);
        }

        $commentdata = [
            'comment_post_ID'      => $product_id,
            'comment_content'      => $comment_content,
            'comment_parent'       => 0,
            'comment_type'         => 'review',
            'comment_author_IP'    => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
            'comment_agent'        => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'user_id'              => get_current_user_id(),
            'comment_author'       => '',
            'comment_author_email' => '',
            'comment_author_url'   => '',
        ];

        if ($order_ctx) {
            $first = trim((string) $order_ctx->get_billing_first_name());
            $last  = trim((string) $order_ctx->get_billing_last_name());
            $name  = trim($first . ' ' . $last);
            if ($name === '') {
                $name = __('Customer', 'ffl-funnels-addons');
            }
            $commentdata['comment_author']       = $name;
            $commentdata['comment_author_email'] = (string) $order_ctx->get_billing_email();
            $commentdata['user_id']              = (int) $order_ctx->get_user_id();
        } elseif (is_user_logged_in()) {
            $user = wp_get_current_user();
            $commentdata['comment_author']       = $user instanceof \WP_User ? $user->display_name : '';
            $commentdata['comment_author_email'] = $user instanceof \WP_User ? $user->user_email : '';
        } else {
            $commentdata['comment_author']       = isset($_POST['author']) ? sanitize_text_field(wp_unslash($_POST['author'])) : '';
            $commentdata['comment_author_email'] = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        }

        if (!is_email($commentdata['comment_author_email'])) {
            self::redirect_with_status($redirect, 'error', __('Please enter a valid email.', 'ffl-funnels-addons'), $fallback);
        }

        // "Reviews can only be left by verified owners" (WooCommerce →
        // Settings → Products). A signed order link proves the purchase;
        // otherwise it takes a signed-in account that bought the product.
        if (!$order_ctx
            && 'yes' === get_option('woocommerce_review_rating_verification_required', 'no')
            && !$can_moderate) {
            if (!is_user_logged_in()) {
                self::redirect_with_status(
                    $redirect,
                    'error',
                    __('Please log in to review this product, or use the review link from your order email.', 'ffl-funnels-addons'),
                    $fallback
                );
            }

            if (!self::account_bought_product(get_current_user_id(), $product_id)) {
                self::redirect_with_status(
                    $redirect,
                    'error',
                    __('Only customers who bought this product can review it.', 'ffl-funnels-addons'),
                    $fallback
                );
            }
        }

        // One review per customer per product: by account, plus verified
        // reviews under the address; for a guest, per typed address.
        $is_guest  = !$order_ctx && !is_user_logged_in();
        $duplicate = $is_guest
            ? self::guest_has_review_for_product((string) $commentdata['comment_author_email'], $product_id)
            : self::customer_has_review_for_product((string) $commentdata['comment_author_email'], $product_id, (int) $commentdata['user_id']);
        if (!$can_moderate && $duplicate) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('You already submitted a review for this product.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        if ($is_guest && self::guest_rate_limited()) {
            self::redirect_with_status(
                $redirect,
                'error',
                __('Too many reviews were sent from your connection. Please try again later.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        $commentdata = self::apply_review_comment_approval($commentdata);

        $comment_id = wp_new_comment($commentdata, true);
        if (is_wp_error($comment_id)) {
            // WordPress's own refusals (duplicate, flooding, Discussion rules)
            // say what went wrong; show that rather than a generic line.
            $message = trim(wp_strip_all_tags(html_entity_decode($comment_id->get_error_message(), ENT_QUOTES, 'UTF-8')));
            self::redirect_with_status(
                $redirect,
                'error',
                $message !== '' ? $message : __('Could not submit your review. Please try again.', 'ffl-funnels-addons'),
                $fallback
            );
        }

        if (!$comment_id) {
            self::redirect_with_status($redirect, 'error', __('Could not submit your review. Please try again.', 'ffl-funnels-addons'), $fallback);
        }

        if ($is_guest) {
            self::count_guest_review();
        }

        self::redirect_with_status($redirect, 'success', '', $fallback);
    }

    private static function guest_rate_key(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        return self::GUEST_RATE_TRANSIENT_PREFIX . md5($ip);
    }

    /**
     * New guest reviews from one IP address within an hour of the last one.
     * Signed-in users fall under WordPress's own flood control and signed
     * links under one review per product in the order.
     */
    private static function guest_rate_limited(): bool
    {
        $limit = (int) apply_filters('ffla_product_reviews_guest_hourly_limit', 5);

        return $limit > 0 && (int) get_transient(self::guest_rate_key()) >= $limit;
    }

    private static function count_guest_review(): void
    {
        $key = self::guest_rate_key();
        set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
    }

    private static function redirect_with_status(string $redirect, string $status, string $message, string $fallback = ''): void
    {
        if ($fallback === '') {
            $fallback = home_url('/');
        }

        $redirect = wp_validate_redirect($redirect, $fallback);

        $args = ['ffla_review_status' => $status];
        if ($message !== '') {
            $args['ffla_review_message'] = $message;
        }

        $url = add_query_arg($args, $redirect) . '#reviews';
        wp_safe_redirect($url);
        exit;
    }
}
