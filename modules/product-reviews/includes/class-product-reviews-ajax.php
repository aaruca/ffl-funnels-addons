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

        $comment_id = isset($_POST['comment_id']) ? absint($_POST['comment_id']) : 0;
        if ($comment_id <= 0) {
            wp_send_json_error(['message' => __('Invalid review.', 'ffl-funnels-addons')]);
        }

        $comment = get_comment($comment_id);
        if (!$comment || 'product' !== get_post_type((int) $comment->comment_post_ID) || '1' !== $comment->comment_approved) {
            wp_send_json_error(['message' => __('Review not available.', 'ffl-funnels-addons')]);
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $rate_key = 'ffla_helpful_' . md5($comment_id . '|' . $ip);
        $daily_key = 'ffla_helpful_daily_' . $comment_id;

        if (get_transient($rate_key)) {
            $current = (int) get_comment_meta($comment_id, 'ffla_helpful_yes', true);
            wp_send_json_success([
                'count'      => $current,
                'throttled'  => true,
                'message'    => __('Vote already registered recently.', 'ffl-funnels-addons'),
            ]);
        }

        $daily = (int) get_transient($daily_key);
        $daily_cap = (int) apply_filters('ffla_helpful_daily_cap', 200);
        if ($daily >= $daily_cap) {
            $current = (int) get_comment_meta($comment_id, 'ffla_helpful_yes', true);
            wp_send_json_success([
                'count'      => $current,
                'throttled'  => true,
                'message'    => __('Daily vote limit reached for this review.', 'ffl-funnels-addons'),
            ]);
        }

        $current = (int) get_comment_meta($comment_id, 'ffla_helpful_yes', true);
        $new     = $current + 1;
        update_comment_meta($comment_id, 'ffla_helpful_yes', $new);
        set_transient($rate_key, 1, HOUR_IN_SECONDS * 12);
        set_transient($daily_key, $daily + 1, DAY_IN_SECONDS);

        wp_send_json_success([
            'count'     => $new,
            'throttled' => false,
            'message'   => __('Vote saved.', 'ffl-funnels-addons'),
        ]);
    }
}
