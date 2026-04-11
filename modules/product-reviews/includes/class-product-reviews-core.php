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

    /** @var bool */
    private static $order_review_rewrite_tag_registered = false;

    public static function init(): void
    {
        add_filter('preprocess_comment', [__CLASS__, 'validate_review_submission']);
        add_action('comment_post', [__CLASS__, 'save_review_meta'], 10, 3);
        add_action('delete_comment', [__CLASS__, 'cleanup_review_media'], 10, 1);
        add_action('admin_post_ffla_submit_product_review', [__CLASS__, 'handle_form_submission']);
        add_action('admin_post_nopriv_ffla_submit_product_review', [__CLASS__, 'handle_form_submission']);
        add_filter('woocommerce_product_tabs', [__CLASS__, 'maybe_hide_default_reviews_tab'], 99);
        add_filter('pre_comment_approved', [__CLASS__, 'filter_pre_comment_approved'], 10, 2);
        add_action('init', [__CLASS__, 'register_order_review_rewrites'], 20);
        add_action('parse_request', [__CLASS__, 'parse_request_order_review_page'], 4);

        if (is_admin()) {
            add_filter('manage_edit-comments_columns', [__CLASS__, 'register_admin_comments_columns']);
            add_action('manage_comments_custom_column', [__CLASS__, 'render_admin_comments_columns'], 10, 2);
            add_action('admin_head-edit-comments.php', [__CLASS__, 'render_admin_comments_column_css']);
        }
    }

    public static function get_default_settings(): array
    {
        return [
            'enable_requests'           => '1',
            'request_delay_days'        => '7',
            'enable_helpful_votes'      => '1',
            'hide_default_reviews_tab'  => '0',
            'enable_turnstile'          => '0',
            'turnstile_site_key'        => '',
            'turnstile_secret_key'      => '',
            'form_title'                => __('Write a review', 'ffl-funnels-addons'),
            'moderate_all_reviews'      => '0',
            'request_email_mode'        => 'per_product',
            'order_review_page_id'      => '0',
            'order_review_show_criteria' => '0',
            'order_review_pretty_urls'  => '0',
            'order_review_rewrite_slug' => 'order-review',
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

    public static function maybe_hide_default_reviews_tab(array $tabs): array
    {
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

        if (self::review_honeypot_triggered()) {
            wp_die(esc_html__('Your review could not be submitted.', 'ffl-funnels-addons'));
        }

        if (isset($_POST['ffla_review_form_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['ffla_review_form_nonce']));
            if (!wp_verify_nonce($nonce, 'ffla_review_form')) {
                wp_die(esc_html__('Security check failed. Please refresh and try again.', 'ffl-funnels-addons'));
            }
        }

        if (!self::turnstile_token_valid_for_request()) {
            wp_die(esc_html__('Cloudflare validation failed. Please try again.', 'ffl-funnels-addons'));
        }

        return self::apply_review_comment_approval($commentdata);
    }

    /**
     * Hold reviews when admin enables moderation or when media is attached.
     */
    private static function apply_review_comment_approval(array $commentdata): array
    {
        if ('1' === self::get_setting('moderate_all_reviews', '0')) {
            $commentdata['comment_approved'] = 0;
        }

        return self::apply_media_moderation_flag($commentdata);
    }

    /**
     * @param int|string $approved
     * @return int|string
     */
    public static function filter_pre_comment_approved($approved, array $commentdata)
    {
        if (($commentdata['comment_type'] ?? '') !== 'review') {
            return $approved;
        }

        $post_id = isset($commentdata['comment_post_ID']) ? (int) $commentdata['comment_post_ID'] : 0;
        if ($post_id <= 0 || 'product' !== get_post_type($post_id)) {
            return $approved;
        }

        if (is_user_logged_in()) {
            $uid = get_current_user_id();
            if ($uid && user_can($uid, 'moderate_comments')) {
                return $approved;
            }
        }

        if ('1' !== self::get_setting('moderate_all_reviews', '0')) {
            return $approved;
        }

        return 0;
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

    public static function build_order_review_token(int $order_id, string $billing_email): string
    {
        $exp = time() + (90 * DAY_IN_SECONDS);
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

    public static function order_contains_reviewable_product(\WC_Order $order, int $parent_product_id): bool
    {
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            if (self::line_item_parent_product_id($item) === $parent_product_id) {
                return true;
            }
        }

        return false;
    }

    public static function customer_has_review_for_product(string $email, int $product_id): bool
    {
        if ($email === '' || $product_id <= 0) {
            return false;
        }

        $found = get_comments([
            'post_id'      => $product_id,
            'type'         => 'review',
            'author_email' => $email,
            'status'       => 'all',
            'number'       => 1,
            'fields'       => 'ids',
        ]);

        return !empty($found);
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

    public static function maybe_flush_rewrites_on_settings(): void
    {
        if ('1' === self::get_setting('order_review_pretty_urls', '0')) {
            flush_rewrite_rules(false);
        }
    }

    private static function review_honeypot_triggered(): bool
    {
        $honeypot = isset($_POST['ffla_hp']) ? trim((string) wp_unslash($_POST['ffla_hp'])) : '';

        return $honeypot !== '';
    }

    /**
     * When Turnstile is enabled, require a valid token (native + Bricks forms).
     */
    private static function turnstile_token_valid_for_request(): bool
    {
        if (!self::is_turnstile_enabled()) {
            return true;
        }

        if (self::order_review_token_bypasses_turnstile()) {
            return true;
        }

        $token = isset($_POST['cf-turnstile-response'])
            ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response']))
            : '';

        return $token !== '' && self::verify_turnstile_token($token);
    }

    private static function order_review_token_bypasses_turnstile(): bool
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

    public static function save_review_meta(int $comment_id, int $comment_approved, array $commentdata): void
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

        $quality = isset($_POST['ffla_review_quality']) ? absint($_POST['ffla_review_quality']) : 0;
        if ($quality > 0) {
            update_comment_meta($comment_id, 'ffla_review_quality', min(5, $quality));
        }

        $value = isset($_POST['ffla_review_value']) ? absint($_POST['ffla_review_value']) : 0;
        if ($value > 0) {
            update_comment_meta($comment_id, 'ffla_review_value', min(5, $value));
        }

        if (!metadata_exists('comment', $comment_id, 'ffla_helpful_yes')) {
            update_comment_meta($comment_id, 'ffla_helpful_yes', 0);
        }

        $comment = get_comment($comment_id);
        if (!$comment) {
            return;
        }

        $email   = $comment->comment_author_email;
        $user_id = (int) $comment->user_id;

        $order_token = isset($_POST['ffla_order_review_token'])
            ? sanitize_text_field(wp_unslash($_POST['ffla_order_review_token']))
            : '';
        $is_verified = false;
        if ($order_token !== '') {
            $ord = self::get_order_for_review_token($order_token);
            if ($ord && self::order_contains_reviewable_product($ord, $product_id)) {
                $is_verified = true;
            }
        }
        if (!$is_verified && function_exists('wc_customer_bought_product')) {
            $is_verified = (bool) wc_customer_bought_product($email, $user_id, $product_id);
        }

        update_comment_meta($comment_id, 'ffla_verified_purchase', $is_verified ? 1 : 0);

        self::save_review_media($comment_id);
    }

    private static function save_review_media(int $comment_id): void
    {
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
            echo esc_html((string) ((int) get_comment_meta($comment_id, 'ffla_helpful_yes', true)));
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
        </style>';
    }

    private static function has_review_media_upload(): bool
    {
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

    public static function is_turnstile_enabled(): bool
    {
        return '1' === self::get_setting('enable_turnstile', '0')
            && self::get_turnstile_site_key() !== ''
            && self::get_turnstile_secret_key() !== '';
    }

    public static function get_turnstile_site_key(): string
    {
        return trim((string) self::get_setting('turnstile_site_key', ''));
    }

    public static function get_turnstile_secret_key(): string
    {
        return trim((string) self::get_setting('turnstile_secret_key', ''));
    }

    private static function verify_turnstile_token(string $token): bool
    {
        $secret = self::get_turnstile_secret_key();
        if ($secret === '') {
            return false;
        }

        $body = [
            'secret'   => $secret,
            'response' => $token,
        ];

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $body['remoteip'] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 10,
            'body'    => $body,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return is_array($data) && !empty($data['success']);
    }

    private static function normalize_uploads_array(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return [$files];
        }

        $normalized = [];
        foreach ($files['name'] as $idx => $name) {
            $normalized[] = [
                'name'     => $name,
                'type'     => $files['type'][$idx] ?? '',
                'tmp_name' => $files['tmp_name'][$idx] ?? '',
                'error'    => $files['error'][$idx] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $files['size'][$idx] ?? 0,
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

        if (!isset($_POST['ffla_review_form_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffla_review_form_nonce'])), 'ffla_review_form')) {
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

        if ($order_ctx) {
            if (!self::order_contains_reviewable_product($order_ctx, $product_id)) {
                self::redirect_with_status(
                    $redirect,
                    'error',
                    __('That product is not part of this order.', 'ffl-funnels-addons'),
                    $fallback
                );
            }

            $billing_email = strtolower(trim((string) $order_ctx->get_billing_email()));
            if (self::customer_has_review_for_product($billing_email, $product_id)) {
                self::redirect_with_status(
                    $redirect,
                    'error',
                    __('You already submitted a review for this product.', 'ffl-funnels-addons'),
                    $fallback
                );
            }
        }

        $comment_content = isset($_POST['comment']) ? trim((string) wp_unslash($_POST['comment'])) : '';
        $rating          = isset($_POST['rating']) ? absint($_POST['rating']) : 0;

        if ($comment_content === '') {
            self::redirect_with_status($redirect, 'error', __('Review text is required.', 'ffl-funnels-addons'), $fallback);
        }

        if ($rating < 1 || $rating > 5) {
            self::redirect_with_status($redirect, 'error', __('Please select a rating between 1 and 5.', 'ffl-funnels-addons'), $fallback);
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

        $commentdata = self::apply_review_comment_approval($commentdata);

        $comment_id = wp_new_comment($commentdata, true);
        if (is_wp_error($comment_id) || !$comment_id) {
            self::redirect_with_status($redirect, 'error', __('Could not submit your review. Please try again.', 'ffl-funnels-addons'), $fallback);
        }

        self::redirect_with_status($redirect, 'success', '', $fallback);
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
