<?php
/**
 * "Photos and videos" box on a review's edit screen (Products → Reviews →
 * Edit): add files from the Media Library, take files off the review, and
 * see the reviewer's answers to the review form questions.
 *
 * Taking a file off the review leaves it in the Media Library, and a file
 * picked here is never deleted with the review (only files uploaded or
 * imported for it are: Product_Reviews_Core::cleanup_review_media()).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Review_Media_Admin
{
    const NONCE = 'ffla_review_media_admin';

    public function init(): void
    {
        add_action('add_meta_boxes_comment', [$this, 'register']);
        add_action('edit_comment', [$this, 'save'], 10, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    private static function is_review(\WP_Comment $comment): bool
    {
        return 'review' === $comment->comment_type && 0 === (int) $comment->comment_parent
            && 'product' === get_post_type((int) $comment->comment_post_ID);
    }

    /**
     * @param \WP_Comment|mixed $comment
     */
    public function register($comment): void
    {
        if (!$comment instanceof \WP_Comment || !self::is_review($comment)) {
            return;
        }

        add_meta_box('ffla-review-media', __('Photos and videos', 'ffl-funnels-addons'), [$this, 'render'], 'comment', 'normal', 'default');

        if (class_exists('Product_Reviews_Form_Extras') && !empty(Product_Reviews_Form_Extras::answers((int) $comment->comment_ID))) {
            add_meta_box('ffla-review-answers', __('Answers to the review form questions', 'ffl-funnels-addons'), [$this, 'render_answers'], 'comment', 'normal', 'default');
        }
    }

    public function enqueue(string $hook = ''): void
    {
        if ('comment.php' !== $hook) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('ffla-product-reviews-comment', FFLA_URL . 'modules/product-reviews/admin/js/product-reviews-comment.js', ['jquery'], FFLA_VERSION, true);
        wp_localize_script('ffla-product-reviews-comment', 'fflaReviewMedia', [
            'title'  => __('Add photos or videos to this review', 'ffl-funnels-addons'),
            'button' => __('Add to review', 'ffl-funnels-addons'),
            'remove' => __('Remove from review', 'ffl-funnels-addons'),
            'video'  => __('Video', 'ffl-funnels-addons'),
        ]);
    }

    /**
     * @param \WP_Comment|mixed $comment
     */
    public function render($comment): void
    {
        if (!$comment instanceof \WP_Comment) {
            return;
        }

        echo self::box_html($comment); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
    }

    public static function box_html(\WP_Comment $comment): string
    {
        $ids = get_comment_meta((int) $comment->comment_ID, 'ffla_review_media_ids', true);
        $ids = is_array($ids) ? array_values(array_filter(array_map('absint', $ids))) : [];

        $html  = wp_nonce_field(self::NONCE . '_' . (int) $comment->comment_ID, 'ffla_review_media_nonce', true, false);
        $html .= '<input type="hidden" name="ffla_review_media_ids" value="' . esc_attr(implode(',', $ids)) . '" data-ffla-media-ids>';
        $html .= '<ul class="ffla-review-media-admin" data-ffla-media-list style="display:flex;flex-wrap:wrap;gap:10px;margin:0;padding:0;list-style:none;">';
        foreach ($ids as $id) {
            $html .= self::item_html($id);
        }
        $html .= '</ul>';
        $html .= '<p><button type="button" class="button" data-ffla-media-add>' . esc_html__('Add photos or videos', 'ffl-funnels-addons') . '</button></p>';
        $html .= '<p class="description">' . esc_html__('Files taken off the review stay in the Media Library. Deleting the review for good deletes only the files uploaded with it. Update the review to save.', 'ffl-funnels-addons') . '</p>';

        return $html;
    }

    private static function item_html(int $id): string
    {
        $url  = wp_get_attachment_url($id);
        $mime = (string) get_post_mime_type($id);
        if (!$url) {
            return '';
        }

        $preview = strpos($mime, 'image/') === 0
            ? '<img src="' . esc_url((string) (wp_get_attachment_image_url($id, 'thumbnail') ?: $url)) . '" alt="" style="width:80px;height:80px;object-fit:cover;border-radius:4px;display:block;">'
            : '<span style="display:flex;align-items:center;justify-content:center;width:80px;height:80px;background:#f0f0f1;border-radius:4px;">' . esc_html__('Video', 'ffl-funnels-addons') . '</span>';

        return '<li data-id="' . esc_attr((string) $id) . '" style="text-align:center;">' . $preview
            . '<button type="button" class="button-link" data-ffla-media-remove="' . esc_attr((string) $id) . '">' . esc_html__('Remove from review', 'ffl-funnels-addons') . '</button></li>';
    }

    /**
     * @param \WP_Comment|mixed $comment
     */
    public function render_answers($comment): void
    {
        if (!$comment instanceof \WP_Comment || !class_exists('Product_Reviews_Form_Extras')) {
            return;
        }

        echo '<table class="form-table"><tbody>';
        foreach (Product_Reviews_Form_Extras::answers((int) $comment->comment_ID) as $label => $answer) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($answer) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * @param int|mixed $comment_id
     */
    public function save($comment_id): void
    {
        $comment_id = (int) $comment_id;
        if (!isset($_POST['ffla_review_media_nonce'], $_POST['ffla_review_media_ids'])) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ffla_review_media_nonce'])), self::NONCE . '_' . $comment_id)) {
            return;
        }

        if (!current_user_can('edit_comment', $comment_id) && !current_user_can('moderate_comments')) {
            return;
        }

        $comment = get_comment($comment_id);
        if (!$comment instanceof \WP_Comment || !self::is_review($comment)) {
            return;
        }

        $raw = sanitize_text_field(wp_unslash($_POST['ffla_review_media_ids']));
        $ids = [];
        foreach (array_filter(array_map('absint', explode(',', $raw))) as $id) {
            $mime = (string) get_post_mime_type($id);
            // Only photos and videos the Media Library knows.
            if ((strpos($mime, 'image/') === 0 || strpos($mime, 'video/') === 0) && wp_get_attachment_url($id)) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));

        if (empty($ids)) {
            delete_comment_meta($comment_id, 'ffla_review_media_ids');
        } else {
            update_comment_meta($comment_id, 'ffla_review_media_ids', $ids);
        }
    }
}
