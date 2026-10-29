<?php
/**
 * Product Reviews AJAX handlers.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Ajax
{
    const META_YES = 'ffla_helpful_yes';

    const META_NO = 'ffla_helpful_no';

    /**
     * Cloudflare's published edge ranges (https://www.cloudflare.com/ips/).
     */
    const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    public static function init(): void
    {
        add_action('wp_ajax_ffla_vote_review_helpful', [__CLASS__, 'vote_review_helpful']);
        add_action('wp_ajax_nopriv_ffla_vote_review_helpful', [__CLASS__, 'vote_review_helpful']);
        add_action('wp_ajax_ffla_load_reviews', [__CLASS__, 'load_reviews']);
        add_action('wp_ajax_nopriv_ffla_load_reviews', [__CLASS__, 'load_reviews']);
    }

    /**
     * A page of review cards for a list's filters, sort, search and "Show
     * more". Public and read-only: the query only ever returns approved
     * reviews of published products, whatever the request asks for, so there
     * is no nonce to go stale on cached pages.
     */
    public static function load_reviews(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- public, read-only.
        $raw = static function (string $key) {
            return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : null;
        };

        $args = [
            'products'    => (array) ($raw('products') ?? []),
            'categories'  => (array) ($raw('categories') ?? []),
            'rating'      => $raw('rating') ?? 0,
            'minRating'   => $raw('minRating') ?? 0,
            'withMedia'   => '1' === (string) ($raw('withMedia') ?? ''),
            'search'      => is_scalar($raw('search')) ? (string) $raw('search') : '',
            'orderBy'     => is_scalar($raw('orderBy')) ? (string) $raw('orderBy') : 'recent',
            'page'        => $raw('page') ?? 1,
            'perPage'     => $raw('perPage') ?? 5,
            'showProduct' => '1' === (string) ($raw('showProduct') ?? ''),
        ];
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $page = Product_Reviews_Frontend_Render::render_page($args);

        wp_send_json_success([
            'html'    => $page['html'],
            'total'   => $page['total'],
            'count'   => $page['count'],
            'hasMore' => $page['hasMore'],
            'status'  => Product_Reviews_Frontend_Render::status_text($page['count'], $page['total']),
        ]);
    }

    public static function vote_review_helpful(): void
    {
        // Only a signed-in user's nonce proves anything. A guest nonce is the
        // same for every visitor and goes stale on cached product pages, which
        // would break voting there; guests are held by the per-IP limits below.
        // A signed-in user on a cached page whose nonce expired gets a fresh one
        // instead of check_ajax_referer()'s bare "-1", and the script retries once.
        if (is_user_logged_in() && false === check_ajax_referer('ffla_product_reviews_nonce', 'nonce', false)) {
            wp_send_json_error([
                'code'    => 'invalid_nonce',
                'nonce'   => wp_create_nonce('ffla_product_reviews_nonce'),
                'message' => __('This page has expired. Reload it and try again.', 'ffl-funnels-addons'),
            ], 403);
        }

        $comment_id = isset($_POST['comment_id']) ? absint($_POST['comment_id']) : 0;
        $target     = $comment_id > 0 ? get_comment($comment_id) : null;
        $is_answer  = $target instanceof \WP_Comment && 'ffla_answer' === $target->comment_type;

        // Answers have their own switch (Q&A tab); everything else is a review.
        $enabled = $is_answer
            ? '1' === Product_Reviews_Core::get_setting('qna_votes', '1')
            : '1' === Product_Reviews_Core::get_setting('enable_helpful_votes', '1');
        if (!$enabled) {
            wp_send_json_error(['message' => __('Helpful votes are disabled.', 'ffl-funnels-addons')]);
        }

        // Absent `vote` means an older cached script; treat it as the only
        // thing that script could ever have meant.
        $vote = isset($_POST['vote']) ? sanitize_key(wp_unslash($_POST['vote'])) : 'yes';
        if (!in_array($vote, ['yes', 'no'], true)) {
            wp_send_json_error(['message' => __('Invalid vote.', 'ffl-funnels-addons')]);
        }

        if ('no' === $vote && ($is_answer || '1' !== Product_Reviews_Core::get_setting('enable_not_helpful_votes', '0'))) {
            wp_send_json_error(['message' => __('That vote type is disabled.', 'ffl-funnels-addons')]);
        }

        if ($comment_id <= 0) {
            wp_send_json_error(['message' => __('Invalid review.', 'ffl-funnels-addons')]);
        }

        $comment = get_comment($comment_id);
        if (!$comment || 'product' !== get_post_type((int) $comment->comment_post_ID) || '1' !== $comment->comment_approved
            || !in_array((string) $comment->comment_type, ['review', 'ffla_answer'], true)) {
            wp_send_json_error(['message' => __('Review not available.', 'ffl-funnels-addons')]);
        }

        $ip = self::client_ip();

        // Keyed on the review and the voter only, never on the direction, so a
        // single visitor cannot vote a review both helpful and not helpful.
        $rate_key = 'ffla_helpful_' . md5($comment_id . '|' . $ip);

        // The date is part of the key so the cap starts over each day. With a
        // fixed key, every vote pushed the expiry back another 24 hours and a
        // busy review could stay capped indefinitely.
        $daily_key = 'ffla_helpful_daily_' . $comment_id . '_' . wp_date('Ymd');

        if (get_transient($rate_key)) {
            self::respond($comment_id, true, __('Vote already registered recently.', 'ffl-funnels-addons'));
        }

        $daily     = (int) get_transient($daily_key);
        $daily_cap = (int) apply_filters('ffla_helpful_daily_cap', 200);
        if ($daily >= $daily_cap) {
            self::respond($comment_id, true, __('Daily vote limit reached for this review.', 'ffl-funnels-addons'));
        }

        self::increment_counter($comment_id, 'yes' === $vote ? self::META_YES : self::META_NO);

        set_transient($rate_key, 1, HOUR_IN_SECONDS * 12);
        set_transient($daily_key, $daily + 1, DAY_IN_SECONDS);

        self::respond($comment_id, false, __('Vote saved.', 'ffl-funnels-addons'));
    }

    /**
     * The voter's address for throttling. Behind Cloudflare, REMOTE_ADDR is a
     * Cloudflare edge shared by many shoppers, so one vote would lock that
     * review for everyone on the same edge for 12 hours. CF-Connecting-IP is
     * only believed when the request really comes from a Cloudflare edge;
     * a client talking to the server directly could set it to anything.
     */
    public static function client_ip(): string
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';
        $ip     = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';

        if ($ip !== '' && !empty($_SERVER['HTTP_CF_CONNECTING_IP']) && self::is_cloudflare_edge($ip)) {
            $visitor = trim(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])));
            if (filter_var($visitor, FILTER_VALIDATE_IP)) {
                $ip = $visitor;
            }
        }

        return (string) apply_filters('ffla_product_reviews_client_ip', $ip !== '' ? $ip : 'unknown');
    }

    public static function is_cloudflare_edge(string $ip): bool
    {
        $ranges = apply_filters('ffla_product_reviews_cloudflare_ranges', self::CLOUDFLARE_RANGES);

        foreach ((array) $ranges as $cidr) {
            if (self::ip_in_range($ip, (string) $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function ip_in_range(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($parts[0], FILTER_VALIDATE_IP)) {
            return false;
        }

        $address = inet_pton($ip);
        $subnet  = inet_pton($parts[0]);
        if ($address === false || $subnet === false || strlen($address) !== strlen($subnet)) {
            return false;
        }

        $bits      = isset($parts[1]) ? max(0, min(strlen($address) * 8, (int) $parts[1])) : strlen($address) * 8;
        $whole     = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($whole > 0 && substr($address, 0, $whole) !== substr($subnet, 0, $whole)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$whole]) & $mask) === (ord($subnet[$whole]) & $mask);
    }

    /**
     * Atomic increment. The read-modify-write this replaced lost votes under
     * concurrent traffic: two requests would read the same value and both write
     * current+1, dropping one increment.
     */
    private static function increment_counter(int $comment_id, string $meta_key): void
    {
        global $wpdb;

        add_comment_meta($comment_id, $meta_key, 0, true);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->commentmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE comment_id = %d AND meta_key = %s",
            $comment_id,
            $meta_key
        ));

        wp_cache_delete($comment_id, 'comment_meta');
    }

    private static function respond(int $comment_id, bool $throttled, string $message): void
    {
        wp_send_json_success([
            'count'     => (int) get_comment_meta($comment_id, self::META_YES, true),
            'countNo'   => (int) get_comment_meta($comment_id, self::META_NO, true),
            'throttled' => $throttled,
            'message'   => $message,
        ]);
    }
}
