<?php
/**
 * Review moderation: forbidden-word screening and pinned reviews.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Moderation
{
    const PIN_ACTION = 'ffla_toggle_review_pin';

    /** @var array<int, string>|null */
    private static $words_memo = null;

    public static function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_filter('comment_row_actions', [__CLASS__, 'add_pin_row_action'], 10, 2);
        add_action('admin_post_' . self::PIN_ACTION, [__CLASS__, 'handle_pin_toggle']);
    }

    /* ---------------------------------------------------------------------
     * Forbidden words
     * ------------------------------------------------------------------- */

    /**
     * @return array<int, string>
     */
    public static function get_forbidden_words(): array
    {
        if (self::$words_memo !== null) {
            return self::$words_memo;
        }

        $raw = (string) Product_Reviews_Core::get_setting('forbidden_words', '');

        // Accept either one term per line or a comma-separated list; shops
        // reliably produce both no matter what the field description says.
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];

        $words = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $words[] = $part;
            }
        }

        self::$words_memo = array_values(array_unique($words));

        return self::$words_memo;
    }

    public static function flush_memo(): void
    {
        self::$words_memo = null;
    }

    /**
     * Substring match, case-insensitive — the same semantics as WordPress's own
     * disallowed-comment-keys list, so shop owners can paste a list they have
     * already tuned there.
     *
     * @param string ...$fields Text to screen (content, author name, ...).
     */
    public static function contains_forbidden_term(string ...$fields): bool
    {
        $words = self::get_forbidden_words();
        if (empty($words)) {
            return false;
        }

        $haystack = strtolower(implode("\n", $fields));
        if (trim($haystack) === '') {
            return false;
        }

        foreach ($words as $word) {
            if (stripos($haystack, strtolower($word)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 'moderate' holds the review for approval; 'reject' refuses it outright.
     */
    private static function action(): string
    {
        $action = (string) Product_Reviews_Core::get_setting('forbidden_action', 'moderate');

        return 'reject' === $action ? 'reject' : 'moderate';
    }

    public static function should_reject(string ...$fields): bool
    {
        return 'reject' === self::action() && self::contains_forbidden_term(...$fields);
    }

    public static function should_hold(string ...$fields): bool
    {
        return 'moderate' === self::action() && self::contains_forbidden_term(...$fields);
    }

    /* ---------------------------------------------------------------------
     * Pinned reviews
     * ------------------------------------------------------------------- */

    public static function is_pinned(int $comment_id): bool
    {
        $comment = get_comment($comment_id);

        return $comment && (int) $comment->comment_karma === 1;
    }

    /**
     * Pin state lives in `comment_karma`, an integer column WordPress core
     * writes to but never reads. Storing it there instead of in commentmeta is
     * what lets the reviews list sort pinned-first inside the main query rather
     * than post-sorting a truncated result set in PHP.
     *
     * Written with a direct query rather than wp_update_comment() because that
     * function merges and re-filters comment_content, which would run stored
     * text back through kses on every pin toggle.
     */
    public static function set_pinned(int $comment_id, bool $pinned): bool
    {
        global $wpdb;

        $comment = get_comment($comment_id);
        if (!$comment) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $updated = $wpdb->update(
            $wpdb->comments,
            ['comment_karma' => $pinned ? 1 : 0],
            ['comment_ID' => $comment_id],
            ['%d'],
            ['%d']
        );

        if (false === $updated) {
            return false;
        }

        clean_comment_cache($comment_id);
        Product_Reviews_Core::flush_product_review_caches((int) $comment->comment_post_ID);

        return true;
    }

    /**
     * @param array<string, string> $actions
     * @param \WP_Comment           $comment
     * @return array<string, string>
     */
    public static function add_pin_row_action(array $actions, $comment): array
    {
        if (!$comment instanceof \WP_Comment) {
            return $actions;
        }

        if ('review' !== $comment->comment_type || 'product' !== get_post_type((int) $comment->comment_post_ID)) {
            return $actions;
        }

        if (!current_user_can('moderate_comments')) {
            return $actions;
        }

        $comment_id = (int) $comment->comment_ID;
        $pinned     = (int) $comment->comment_karma === 1;

        $url = wp_nonce_url(
            add_query_arg(
                [
                    'action'     => self::PIN_ACTION,
                    'comment_id' => $comment_id,
                    'pin'        => $pinned ? '0' : '1',
                ],
                admin_url('admin-post.php')
            ),
            self::PIN_ACTION . '_' . $comment_id
        );

        $actions['ffla_pin'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            $pinned
                ? esc_html__('Unpin review', 'ffl-funnels-addons')
                : esc_html__('Pin review', 'ffl-funnels-addons')
        );

        return $actions;
    }

    public static function handle_pin_toggle(): void
    {
        $comment_id = isset($_GET['comment_id']) ? absint($_GET['comment_id']) : 0;
        if ($comment_id <= 0) {
            wp_die(esc_html__('Invalid review.', 'ffl-funnels-addons'));
        }

        check_admin_referer(self::PIN_ACTION . '_' . $comment_id);

        if (!current_user_can('moderate_comments')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'));
        }

        $comment = get_comment($comment_id);
        if (!$comment || 'product' !== get_post_type((int) $comment->comment_post_ID)) {
            wp_die(esc_html__('Invalid review.', 'ffl-funnels-addons'));
        }

        $pin = isset($_GET['pin']) && '1' === $_GET['pin'];
        self::set_pinned($comment_id, $pin);

        $referer = wp_get_referer();
        wp_safe_redirect($referer ?: admin_url('edit-comments.php'));
        exit;
    }
}
