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

    public static function init(): void
    {
        add_action('wp_ajax_ffla_vote_review_helpful', [__CLASS__, 'vote_review_helpful']);
        add_action('wp_ajax_nopriv_ffla_vote_review_helpful', [__CLASS__, 'vote_review_helpful']);
    }

    public static function vote_review_helpful(): void
    {
        check_ajax_referer('ffla_product_reviews_nonce', 'nonce');

        if ('1' !== Product_Reviews_Core::get_setting('enable_helpful_votes', '1')) {
            wp_send_json_error(['message' => __('Helpful votes are disabled.', 'ffl-funnels-addons')]);
        }

        // Absent `vote` means an older cached script; treat it as the only
        // thing that script could ever have meant.
        $vote = isset($_POST['vote']) ? sanitize_key(wp_unslash($_POST['vote'])) : 'yes';
        if (!in_array($vote, ['yes', 'no'], true)) {
            wp_send_json_error(['message' => __('Invalid vote.', 'ffl-funnels-addons')]);
        }

        if ('no' === $vote && '1' !== Product_Reviews_Core::get_setting('enable_not_helpful_votes', '0')) {
            wp_send_json_error(['message' => __('That vote type is disabled.', 'ffl-funnels-addons')]);
        }

        $comment_id = isset($_POST['comment_id']) ? absint($_POST['comment_id']) : 0;
        if ($comment_id <= 0) {
            wp_send_json_error(['message' => __('Invalid review.', 'ffl-funnels-addons')]);
        }

        $comment = get_comment($comment_id);
        if (!$comment || 'product' !== get_post_type((int) $comment->comment_post_ID) || '1' !== $comment->comment_approved) {
            wp_send_json_error(['message' => __('Review not available.', 'ffl-funnels-addons')]);
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';

        // Keyed on the review and the voter only, never on the direction, so a
        // single visitor cannot vote a review both helpful and not helpful.
        $rate_key  = 'ffla_helpful_' . md5($comment_id . '|' . $ip);
        $daily_key = 'ffla_helpful_daily_' . $comment_id;

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
