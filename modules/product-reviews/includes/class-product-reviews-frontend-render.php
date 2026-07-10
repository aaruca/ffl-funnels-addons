<?php
/**
 * Shared HTML output for review form and list (Bricks elements + WooCommerce tab).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Frontend_Render
{
    /**
     * Bricks checkboxes: unchecked may omit the key.
     */
    public static function setting_bool(array $settings, string $key, bool $default = true): bool
    {
        if (!array_key_exists($key, $settings)) {
            return $default;
        }

        return filter_var($settings[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param string $name      Input name attribute.
     * @param string $id_prefix Unique prefix for input ids.
     * @param string $legend    Visible legend / aria label.
     * @param bool   $required  Whether one star must be chosen.
     */
    public static function render_star_radios(string $name, string $id_prefix, string $legend, bool $required = false): void
    {
        echo '<fieldset class="ffla-review-form__fieldset ffla-star-rating-fieldset">';
        echo '<legend class="ffla-review-form__legend">' . esc_html($legend) . '</legend>';
        echo '<div class="ffla-star-rating-input" data-ffla-stars>';
        for ($i = 1; $i <= 5; $i++) {
            $id = $id_prefix . '-s' . $i;
            $req = ($required && 1 === $i) ? ' required' : '';
            echo '<span class="ffla-star-rating-input__item">';
            echo '<input class="ffla-star-rating-input__radio" type="radio" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $i) . '"' . $req . '>';
            echo '<label class="ffla-star-rating-input__label" for="' . esc_attr($id) . '">';
            echo '<span class="screen-reader-text">' . esc_html(sprintf(/* translators: %d: star rating 1–5 */ __('%d out of 5 stars', 'ffl-funnels-addons'), $i)) . '</span>';
            echo '<span class="ffla-star-rating-input__glyph" aria-hidden="true">&#9733;</span>';
            echo '</label></span>';
        }
        echo '</div></fieldset>';
    }

    /**
     * Styled upload UI + list/remove via JS (product-reviews.js).
     *
     * Multiple uploads require: form `enctype="multipart/form-data"`, input `multiple`,
     * and `name="ffla_review_media[]"` so PHP receives an array (see normalize_uploads_array).
     *
     * @param string $input_id Unique DOM id for the file input.
     */
    public static function render_media_upload_widget(string $input_id): void
    {
        $hint_id = $input_id . '-hint';
        echo '<div class="ffla-review-form__media-upload" data-ffla-media-upload>';
        echo '<input id="' . esc_attr($input_id) . '" class="ffla-review-form__file-input" name="ffla_review_media[]" type="file" accept="image/*,video/mp4,video/webm" multiple aria-describedby="' . esc_attr($hint_id) . '">';
        echo '<div class="ffla-review-form__file-ui">';
        echo '<button type="button" class="ffla-review-form__file-add">' . esc_html__('Choose files', 'ffl-funnels-addons') . '</button>';
        echo '<ul class="ffla-review-form__file-list" hidden aria-live="polite"></ul>';
        echo '</div>';
        echo '<p class="ffla-review-form__file-help" id="' . esc_attr($hint_id) . '">' . esc_html__('Up to 3 files, 5 MB each. JPG, PNG, GIF, WebP, MP4, or WebM.', 'ffl-funnels-addons') . '</p>';
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $settings Optional: title, showLoginHint, showOptionalCriteria, collapseMedia, introText.
     * @param bool                 $wrap     When false, omit outer `.ffla-review-form-wrap` (e.g. Bricks supplies its own root).
     */
    public static function render_review_form(int $product_id, array $settings = [], bool $wrap = true): void
    {
        if ($product_id <= 0) {
            return;
        }

        $default_title = Product_Reviews_Core::get_setting('form_title', __('Write a review', 'ffl-funnels-addons'));
        $title = isset($settings['title']) && $settings['title'] !== '' ? (string) $settings['title'] : $default_title;
        $show_login_hint = self::setting_bool($settings, 'showLoginHint', true);
        $show_criteria   = self::setting_bool($settings, 'showOptionalCriteria', true);
        $collapse_media  = self::setting_bool($settings, 'collapseMedia', true);
        $intro = isset($settings['introText']) ? trim((string) $settings['introText']) : '';

        $status = isset($_GET['ffla_review_status']) ? sanitize_text_field(wp_unslash($_GET['ffla_review_status'])) : '';
        $message = isset($_GET['ffla_review_message']) ? sanitize_text_field(wp_unslash($_GET['ffla_review_message'])) : '';

        $uid = wp_unique_id('ffla-rf-');
        $comment_id = $uid . '-comment';

        $redirect_target = '';
        if (function_exists('wp_get_canonical_url') && is_singular()) {
            $redirect_target = (string) wp_get_canonical_url(get_queried_object_id());
        }
        if ($redirect_target === '') {
            $redirect_target = (string) get_permalink($product_id);
        }

        if ($wrap) {
            echo '<div class="ffla-review-form-wrap">';
        }
        echo '<h4 class="ffla-review-form__title">' . esc_html($title) . '</h4>';

        if ($intro !== '') {
            echo '<div class="ffla-review-form__intro">' . wp_kses_post(wpautop($intro)) . '</div>';
        }

        if ($status === 'success') {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--success" role="status">' . esc_html__('Thanks! Your review was submitted.', 'ffl-funnels-addons') . '</p>';
        } elseif ($status === 'error' && $message !== '') {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--error" role="alert">' . esc_html($message) . '</p>';
        }

        if (!is_user_logged_in() && get_option('comment_registration') && $show_login_hint) {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--info">' . esc_html__('You must be logged in to submit a review.', 'ffl-funnels-addons') . '</p>';
        }

        echo '<form class="ffla-review-form" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ffla_review_form', 'ffla_review_form_nonce');
        echo '<input type="text" class="ffla-review-hp-field" name="ffla_hp" value="" autocomplete="off" tabindex="-1" aria-hidden="true">';
        echo '<input type="hidden" name="action" value="ffla_submit_product_review">';

        if (!is_user_logged_in()) {
            $commenter = wp_get_current_commenter();
            echo '<p class="ffla-review-form__field"><label for="' . esc_attr($uid) . '-author">' . esc_html__('Name', 'ffl-funnels-addons') . '</label>';
            echo '<input id="' . esc_attr($uid) . '-author" type="text" name="author" value="' . esc_attr($commenter['comment_author'] ?? '') . '" required autocomplete="name"></p>';
            echo '<p class="ffla-review-form__field"><label for="' . esc_attr($uid) . '-email">' . esc_html__('Email', 'ffl-funnels-addons') . '</label>';
            echo '<input id="' . esc_attr($uid) . '-email" type="email" name="email" value="' . esc_attr($commenter['comment_author_email'] ?? '') . '" required autocomplete="email"></p>';
        }

        self::render_star_radios('rating', $uid . '-r', __('Overall rating', 'ffl-funnels-addons'), true);

        if ($show_criteria && Product_Reviews_Criteria::is_enabled()) {
            foreach (Product_Reviews_Criteria::get_criteria() as $index => $criterion) {
                self::render_star_radios(
                    Product_Reviews_Criteria::field_name($criterion['slug']),
                    $uid . '-c' . $index,
                    sprintf(
                        /* translators: %s: name of a secondary rating criterion, e.g. "Quality" */
                        __('%s (optional)', 'ffl-funnels-addons'),
                        $criterion['label']
                    ),
                    false
                );
            }
        }

        echo '<p class="ffla-review-form__field">';
        echo '<label for="' . esc_attr($comment_id) . '">' . esc_html__('Your review', 'ffl-funnels-addons') . '</label>';
        echo '<span class="ffla-review-form__hint">' . esc_html__('Share what stood out — pros, cons, or who it is best for.', 'ffl-funnels-addons') . '</span>';
        echo '<textarea id="' . esc_attr($comment_id) . '" name="comment" rows="5" required></textarea></p>';

        if ($collapse_media) {
            echo '<details class="ffla-review-form__media-details">';
            echo '<summary class="ffla-review-form__media-summary">' . esc_html__('Add photos or a short video (optional)', 'ffl-funnels-addons') . '</summary>';
            echo '<div class="ffla-review-form__media-details-inner">';
            self::render_media_upload_widget($uid . '-media');
            echo '</div></details>';
        } else {
            echo '<div class="ffla-review-form__media-block">';
            echo '<p class="ffla-review-form__media-heading">' . esc_html__('Photos / video (optional)', 'ffl-funnels-addons') . '</p>';
            self::render_media_upload_widget($uid . '-media');
            echo '</div>';
        }

        if (class_exists('Product_Reviews_Turnstile')) {
            Product_Reviews_Turnstile::render_field();
        }

        echo '<input type="hidden" name="comment_post_ID" value="' . esc_attr((string) $product_id) . '">';
        echo '<input type="hidden" name="comment_parent" value="0">';
        echo '<input type="hidden" name="redirect_to" value="' . esc_url($redirect_target) . '">';
        echo '<button class="ffla-review-form__submit" type="submit">' . esc_html__('Submit review', 'ffl-funnels-addons') . '</button>';

        echo '</form>';
        if ($wrap) {
            echo '</div>';
        }
    }

    public static function render_stars(float $rating, int $max_stars = 5): string
    {
        $rating = max(0, min($max_stars, $rating));
        $percentage = ($rating / $max_stars) * 100;
        $stars = str_repeat('&#9733;', $max_stars);

        return '<span class="ffla-stars" aria-label="' . esc_attr(sprintf(__('Rated %1$s out of %2$s', 'ffl-funnels-addons'), number_format_i18n($rating, 1), $max_stars)) . '">'
            . '<span class="ffla-stars__base">' . $stars . '</span>'
            . '<span class="ffla-stars__fill" style="width:' . esc_attr(number_format($percentage, 4, '.', '')) . '%;">' . $stars . '</span>'
            . '</span>';
    }

    /**
     * Star histogram plus the headline average.
     */
    public static function render_rating_summary(int $product_id): void
    {
        $data = Product_Reviews_Core::get_rating_distribution($product_id);
        if ($data['total'] < 1) {
            return;
        }

        echo '<div class="ffla-rating-summary">';
        echo '<div class="ffla-rating-summary__score">';
        echo '<span class="ffla-rating-summary__average">' . esc_html(number_format_i18n($data['average'], 1)) . '</span>';
        echo wp_kses_post(self::render_stars((float) $data['average']));
        echo '<span class="ffla-rating-summary__count">' . esc_html(sprintf(
            /* translators: %s: number of reviews */
            _n('%s review', '%s reviews', $data['total'], 'ffl-funnels-addons'),
            number_format_i18n($data['total'])
        )) . '</span>';
        echo '</div>';

        echo '<ul class="ffla-rating-summary__bars">';
        for ($stars = 5; $stars >= 1; $stars--) {
            $count   = (int) $data['counts'][$stars];
            $percent = $data['total'] > 0 ? ($count / $data['total']) * 100 : 0;

            echo '<li class="ffla-rating-summary__row">';
            echo '<span class="ffla-rating-summary__label">' . esc_html(sprintf(
                /* translators: %d: star rating 1-5 */
                _n('%d star', '%d stars', $stars, 'ffl-funnels-addons'),
                $stars
            )) . '</span>';
            echo '<span class="ffla-rating-summary__track">';
            echo '<span class="ffla-rating-summary__fill" style="width:' . esc_attr(number_format($percent, 2, '.', '')) . '%;"></span>';
            echo '</span>';
            echo '<span class="ffla-rating-summary__n">' . esc_html(number_format_i18n($count)) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    /**
     * @param array<int, \WP_Comment> $replies
     */
    private static function render_replies(array $replies): void
    {
        if (empty($replies)) {
            return;
        }

        $allowed_html = ['p' => [], 'br' => [], 'strong' => [], 'em' => []];

        echo '<div class="ffla-review-card__replies">';
        foreach ($replies as $reply) {
            $reply_id  = (int) $reply->comment_ID;
            $is_store  = (int) get_comment_meta($reply_id, Product_Reviews_Notifications::STORE_REPLY_META, true) === 1;
            $classes   = 'ffla-review-reply' . ($is_store ? ' ffla-review-reply--store' : '');

            echo '<div class="' . esc_attr($classes) . '" id="comment-' . esc_attr((string) $reply_id) . '">';
            echo '<div class="ffla-review-reply__header">';
            echo '<strong class="ffla-review-reply__author">' . esc_html($reply->comment_author) . '</strong>';
            if ($is_store) {
                echo '<span class="ffla-review-reply__badge">' . esc_html__('Store response', 'ffl-funnels-addons') . '</span>';
            }
            echo '<span class="ffla-review-reply__date">' . esc_html(wp_date(get_option('date_format'), strtotime($reply->comment_date_gmt . ' UTC'))) . '</span>';
            echo '</div>';
            echo '<div class="ffla-review-reply__content">' . wp_kses(wpautop(wp_strip_all_tags($reply->comment_content)), $allowed_html) . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Net helpfulness. Sorting on `yes` alone lets a review with 40 up and 39
     * down outrank one with 12 up and none.
     */
    private static function helpful_score(int $comment_id): int
    {
        return (int) get_comment_meta($comment_id, 'ffla_helpful_yes', true)
            - (int) get_comment_meta($comment_id, 'ffla_helpful_no', true);
    }

    /**
     * @param array<string, mixed> $settings Optional: perPage, orderBy, showSummary.
     * @param bool                 $wrap     When false, omit outer `.ffla-reviews-list` (Bricks supplies its own root).
     */
    public static function render_reviews_list(int $product_id, array $settings = [], bool $wrap = true): void
    {
        if ($product_id <= 0) {
            return;
        }

        $per_page = !empty($settings['perPage']) ? absint($settings['perPage']) : 5;
        $per_page = max(1, min(50, $per_page));
        $order_by = $settings['orderBy'] ?? 'recent';

        $show_summary = self::setting_bool(
            $settings,
            'showSummary',
            '1' === Product_Reviews_Core::get_setting('show_rating_summary', '1')
        );
        $show_replies    = '1' === Product_Reviews_Core::get_setting('show_replies', '1');
        $votes_enabled   = '1' === Product_Reviews_Core::get_setting('enable_helpful_votes', '1');
        $no_vote_enabled = $votes_enabled && '1' === Product_Reviews_Core::get_setting('enable_not_helpful_votes', '0');

        // Pinned reviews lead regardless of sort. Pin state lives in the native
        // comment_karma column, so the ordering happens in SQL rather than by
        // re-sorting a page the query has already truncated.
        $args = [
            'post_id'        => $product_id,
            'status'         => 'approve',
            'type'           => 'review',
            'parent'         => 0,
            'number'         => ($order_by === 'recent') ? $per_page : 100,
            'orderby'        => ['comment_karma' => 'DESC', 'comment_date_gmt' => 'DESC'],
        ];
        $reviews = get_comments($args);

        if ($order_by === 'helpful') {
            usort($reviews, static function ($a, $b): int {
                $a_pinned = (int) $a->comment_karma === 1 ? 1 : 0;
                $b_pinned = (int) $b->comment_karma === 1 ? 1 : 0;
                if ($a_pinned !== $b_pinned) {
                    return $b_pinned <=> $a_pinned;
                }

                $a_score = self::helpful_score((int) $a->comment_ID);
                $b_score = self::helpful_score((int) $b->comment_ID);
                if ($a_score === $b_score) {
                    return strcmp($b->comment_date_gmt, $a->comment_date_gmt);
                }

                return $b_score <=> $a_score;
            });
            $reviews = array_slice($reviews, 0, $per_page);
        }

        if ($wrap) {
            echo '<div class="ffla-reviews-list">';
        }

        if ($show_summary) {
            self::render_rating_summary($product_id);
        }

        if (empty($reviews)) {
            echo '<p class="ffla-reviews-list__empty">' . esc_html__('No reviews yet.', 'ffl-funnels-addons') . '</p>';
            if ($wrap) {
                echo '</div>';
            }

            return;
        }

        $replies_by_parent = $show_replies
            ? Product_Reviews_Core::get_replies_for_reviews(wp_list_pluck($reviews, 'comment_ID'))
            : [];

        foreach ($reviews as $review) {
            $review_id = (int) $review->comment_ID;
            $rating    = (float) get_comment_meta($review_id, 'rating', true);
            $helpful   = (int) get_comment_meta($review_id, 'ffla_helpful_yes', true);
            $unhelpful = (int) get_comment_meta($review_id, 'ffla_helpful_no', true);
            $verified  = (int) get_comment_meta($review_id, 'ffla_verified_purchase', true) === 1;
            $pinned    = (int) $review->comment_karma === 1;
            $criteria  = Product_Reviews_Criteria::is_enabled()
                ? Product_Reviews_Criteria::get_review_scores($review_id)
                : [];

            $media_ids = get_comment_meta($review_id, 'ffla_review_media_ids', true);
            if (!is_array($media_ids)) {
                $media_ids = [];
            }

            echo '<article class="ffla-review-card' . ($pinned ? ' ffla-review-card--pinned' : '') . '">';
            echo '<header class="ffla-review-card__header">';
            echo '<strong class="ffla-review-card__author">' . esc_html($review->comment_author) . '</strong>';
            echo '<span class="ffla-review-card__date">' . esc_html(wp_date(get_option('date_format'), strtotime($review->comment_date_gmt . ' UTC'))) . '</span>';
            echo '</header>';

            if ($pinned) {
                echo '<span class="ffla-review-card__pinned">' . esc_html__('Featured review', 'ffl-funnels-addons') . '</span>';
            }

            if ($rating > 0) {
                echo '<div class="ffla-review-card__rating">' . wp_kses_post(self::render_stars($rating)) . '</div>';
            }

            if ($verified) {
                echo '<span class="ffla-review-card__verified">' . esc_html__('Verified buyer', 'ffl-funnels-addons') . '</span>';
            }

            if (!empty($criteria)) {
                echo '<div class="ffla-review-card__criteria">';
                foreach ($criteria as $criterion) {
                    echo '<span>' . esc_html($criterion['label']) . ': ' . esc_html((string) $criterion['score']) . '/5</span>';
                }
                echo '</div>';
            }

            $allowed_html = [
                'p'      => [],
                'br'     => [],
                'strong' => [],
                'em'     => [],
            ];
            echo '<div class="ffla-review-card__content">' . wp_kses(wpautop(wp_strip_all_tags($review->comment_content)), $allowed_html) . '</div>';

            if (!empty($media_ids)) {
                echo '<div class="ffla-review-card__media">';
                foreach ($media_ids as $media_id) {
                    $media_id = absint($media_id);
                    if ($media_id <= 0) {
                        continue;
                    }

                    $mime = (string) get_post_mime_type($media_id);
                    if (strpos($mime, 'video/') === 0) {
                        $video = wp_video_shortcode([
                            'src'      => wp_get_attachment_url($media_id),
                            'preload'  => 'metadata',
                            'controls' => true,
                        ]);
                        echo '<div class="ffla-review-card__media-item ffla-review-card__media-item--video">' . wp_kses_post($video) . '</div>';
                    } else {
                        $image = wp_get_attachment_image($media_id, 'medium');
                        if (!empty($image)) {
                            echo '<div class="ffla-review-card__media-item ffla-review-card__media-item--image">' . wp_kses_post($image) . '</div>';
                        }
                    }
                }
                echo '</div>';
            }

            if ($votes_enabled) {
                echo '<div class="ffla-review-helpful-group">';
                echo '<button class="ffla-review-helpful" type="button" data-vote="yes" data-comment-id="' . esc_attr((string) $review_id) . '">';
                echo esc_html__('Helpful', 'ffl-funnels-addons') . ' ';
                echo '<span class="ffla-review-helpful__count">' . esc_html((string) $helpful) . '</span>';
                echo '</button>';

                if ($no_vote_enabled) {
                    echo '<button class="ffla-review-helpful ffla-review-helpful--no" type="button" data-vote="no" data-comment-id="' . esc_attr((string) $review_id) . '">';
                    echo esc_html__('Not helpful', 'ffl-funnels-addons') . ' ';
                    echo '<span class="ffla-review-helpful__count-no">' . esc_html((string) $unhelpful) . '</span>';
                    echo '</button>';
                }
                echo '</div>';
            }

            if (!empty($replies_by_parent[$review_id])) {
                self::render_replies($replies_by_parent[$review_id]);
            }

            echo '</article>';
        }

        if ($wrap) {
            echo '</div>';
        }
    }
}
