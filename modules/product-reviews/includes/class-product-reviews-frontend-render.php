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
     * Reviews the lists on this request showed, per product, in display order.
     * Product_Reviews_Structured_Data marks up exactly these.
     *
     * @var array<int, array<int, \WP_Comment>>
     */
    private static $rendered = [];

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
        echo '<p class="ffla-review-form__file-help" id="' . esc_attr($hint_id) . '">' . esc_html(sprintf(
            /* translators: 1: number of files, 2: size in megabytes */
            _n('Up to %1$d file, %2$d MB. JPG, PNG, GIF, WebP, MP4, or WebM.', 'Up to %1$d files, %2$d MB each. JPG, PNG, GIF, WebP, MP4, or WebM.', Product_Reviews_Core::media_max_files(), 'ffl-funnels-addons'),
            Product_Reviews_Core::media_max_files(),
            Product_Reviews_Core::media_max_mb()
        )) . '</p>';
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

        Product_Reviews_Assets::enqueue();

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

        // WooCommerce → Settings → Products → "Reviews can only be left by
        // verified owners": the handler refuses everyone else, so they get the
        // reason instead of a form they cannot send.
        if ('yes' === get_option('woocommerce_review_rating_verification_required', 'no')
            && !current_user_can('moderate_comments')
            && !Product_Reviews_Core::account_bought_product(get_current_user_id(), $product_id)) {
            $text = is_user_logged_in()
                ? __('Only customers who bought this product can review it.', 'ffl-funnels-addons')
                : __('Only customers who bought this product can review it. Log in to your account, or use the review link from your order email.', 'ffl-funnels-addons');
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--info">' . esc_html($text)
                . (is_user_logged_in() ? '' : ' ' . self::login_link($redirect_target)) . '</p>';
            if ($wrap) {
                echo '</div>';
            }

            return;
        }

        if (!is_user_logged_in() && get_option('comment_registration') && $show_login_hint) {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--info">' . esc_html__('You must be logged in to submit a review.', 'ffl-funnels-addons')
                . ' ' . self::login_link($redirect_target) . '</p>';
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
            // Required criteria are only enforced on a form that showed them.
            echo '<input type="hidden" name="ffla_criteria_shown" value="1">';
            foreach (Product_Reviews_Criteria::get_criteria() as $index => $criterion) {
                self::render_star_radios(
                    Product_Reviews_Criteria::field_name($criterion['slug']),
                    $uid . '-c' . $index,
                    !empty($criterion['required']) ? $criterion['label'] : sprintf(
                        /* translators: %s: name of a secondary rating criterion, e.g. "Quality" */
                        __('%s (optional)', 'ffl-funnels-addons'),
                        $criterion['label']
                    ),
                    !empty($criterion['required'])
                );
            }
        }

        echo '<p class="ffla-review-form__field">';
        echo '<label for="' . esc_attr($comment_id) . '">' . esc_html__('Your review', 'ffl-funnels-addons') . '</label>';
        echo '<span class="ffla-review-form__hint">' . esc_html__('Share what stood out — pros, cons, or who it is best for.', 'ffl-funnels-addons') . '</span>';
        echo '<textarea id="' . esc_attr($comment_id) . '" name="comment" rows="5" required></textarea></p>';

        if (!Product_Reviews_Core::uploads_enabled()) {
            // Uploads switched off in the module settings: no field at all.
        } elseif ($collapse_media) {
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

        /**
         * Extra fields before the CAPTCHA and the submit button (customer
         * questions, terms checkbox).
         *
         * @param int    $product_id
         * @param string $context product / hub
         */
        do_action('ffla_product_reviews_form_fields', $product_id, 'product');

        if (class_exists('Product_Reviews_Captcha')) {
            Product_Reviews_Captcha::render_field();
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


    /**
     * "Log in" link back to this page (Review form → Log in page).
     */
    public static function login_link(string $redirect): string
    {
        $url = class_exists('Product_Reviews_Form_Extras') ? Product_Reviews_Form_Extras::login_url($redirect) : wp_login_url($redirect);

        return '<a class="ffla-review-form__login" href="' . esc_url($url) . '">' . esc_html__('Log in', 'ffl-funnels-addons') . '</a>';
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
     *
     * @param bool                      $interactive Bars filter the list below (needs the script).
     * @param array<string, mixed>|null $data        Numbers for a scope other than one product
     *                                               (Product_Reviews_Query::distribution()).
     * @param int                       $min_rating  The list's minimum rating: bars below it do not filter.
     */
    public static function render_rating_summary(int $product_id, bool $interactive = false, ?array $data = null, int $min_rating = 0): void
    {
        if ($data === null) {
            $data = Product_Reviews_Core::get_rating_distribution($product_id);
        }
        if ($data['total'] < 1) {
            return;
        }

        // The same count as WooCommerce's "Reviews (n)" tab and the structured data.
        $review_count = $data['review_count'];

        echo '<div class="ffla-rating-summary">';
        echo '<div class="ffla-rating-summary__score">';
        echo '<span class="ffla-rating-summary__average">' . esc_html(number_format_i18n($data['average'], 1)) . '</span>';
        echo wp_kses_post(self::render_stars((float) $data['average']));
        echo '<span class="ffla-rating-summary__count">' . esc_html(sprintf(
            /* translators: %s: number of reviews */
            _n('%s review', '%s reviews', $review_count, 'ffl-funnels-addons'),
            number_format_i18n($review_count)
        )) . '</span>';
        echo '</div>';

        echo '<ul class="ffla-rating-summary__bars">';
        for ($stars = 5; $stars >= 1; $stars--) {
            $count   = (int) $data['counts'][$stars];
            $percent = $data['total'] > 0 ? ($count / $data['total']) * 100 : 0;
            $label   = sprintf(
                /* translators: %d: star rating 1-5 */
                _n('%d star', '%d stars', $stars, 'ffl-funnels-addons'),
                $stars
            );

            $attrs = '';
            if ($interactive && $count > 0 && $stars >= $min_rating) {
                $attrs = ' data-ffla-filter-rating="' . esc_attr((string) $stars) . '" role="button" tabindex="0" aria-pressed="false" aria-label="'
                    . esc_attr(sprintf(
                        /* translators: %d: star rating 1-5 */
                        _n('Show %d-star reviews', 'Show %d-star reviews', $stars, 'ffl-funnels-addons'),
                        $stars
                    )) . '"';
            }

            echo '<li class="ffla-rating-summary__row"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
            echo '<span class="ffla-rating-summary__label">' . esc_html($label) . '</span>';
            echo '<span class="ffla-rating-summary__track">';
            echo '<span class="ffla-rating-summary__fill" style="width:' . esc_attr(number_format($percent, 2, '.', '')) . '%;"></span>';
            echo '</span>';
            echo '<span class="ffla-rating-summary__n">' . esc_html(number_format_i18n($count)) . '</span>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    /* ---------------------------------------------------------------------
     * Reviewer details
     * ------------------------------------------------------------------- */

    /**
     * The reviewer's name as the store chose to show it (Display → Reviewer
     * name). Used on the cards and in the structured data, so both say the
     * same.
     */
    public static function display_name(\WP_Comment $review): string
    {
        $name = trim(wp_strip_all_tags((string) $review->comment_author));
        if ($name === '') {
            return __('Customer', 'ffl-funnels-addons');
        }

        $format = (string) Product_Reviews_Core::get_setting('review_name_format', 'first_initial');
        $parts  = preg_split('/\s+/u', $name) ?: [$name];
        $first  = (string) $parts[0];
        $last   = count($parts) > 1 ? (string) $parts[count($parts) - 1] : '';

        switch ($format) {
            case 'full':
                return $name;

            case 'first':
                return $first;

            case 'initials':
                return self::initial($first) . '.' . ($last !== '' ? ' ' . self::initial($last) . '.' : '');

            case 'anonymous':
                return __('Customer', 'ffl-funnels-addons');

            case 'first_initial':
            default:
                return $last !== '' ? $first . ' ' . self::initial($last) . '.' : $first;
        }
    }

    private static function initial(string $word): string
    {
        $char = function_exists('mb_substr') ? mb_substr($word, 0, 1) : substr($word, 0, 1);

        return function_exists('mb_strtoupper') ? mb_strtoupper($char) : strtoupper($char);
    }

    public static function verified_label(): string
    {
        $label = trim((string) Product_Reviews_Core::get_setting('verified_label', ''));

        return $label !== '' ? $label : __('Verified buyer', 'ffl-funnels-addons');
    }

    /**
     * Initials on a colour picked from the address (or the name), the
     * WordPress avatar (Gravatar), or nothing.
     */
    private static function avatar_html(\WP_Comment $review, string $display_name): string
    {
        $mode = (string) Product_Reviews_Core::get_setting('review_avatars', 'initials');

        if ('none' === $mode) {
            return '';
        }

        if ('gravatar' === $mode) {
            $id_or_email = (int) $review->user_id > 0 ? (int) $review->user_id : (string) $review->comment_author_email;
            $url         = get_avatar_url($id_or_email, ['size' => 80]);
            if (!$url) {
                return '';
            }

            return '<img class="ffla-review-card__avatar" src="' . esc_url($url) . '" width="40" height="40" alt="" loading="lazy" decoding="async">';
        }

        $words    = preg_split('/[\s.]+/u', trim($display_name)) ?: [];
        $words    = array_values(array_filter($words, 'strlen'));
        $initials = '';
        if (!empty($words)) {
            $initials = self::initial($words[0]);
            if (count($words) > 1) {
                $initials .= self::initial($words[count($words) - 1]);
            }
        }
        if ($initials === '') {
            $initials = '?';
        }

        $palette = ['#2f6f4f', '#3b5b92', '#8a4b2a', '#6b4c9a', '#946200', '#1f6f78', '#9a3b5b', '#4b5563'];
        $seed    = strtolower(trim((string) $review->comment_author_email)) ?: $display_name;
        $color   = $palette[abs(crc32($seed)) % count($palette)];

        return '<span class="ffla-review-card__avatar ffla-review-card__avatar--initials" style="background-color:' . esc_attr($color) . '" aria-hidden="true">'
            . esc_html($initials) . '</span>';
    }

    /* ---------------------------------------------------------------------
     * Cards
     * ------------------------------------------------------------------- */

    /**
     * What every card in a list shows, from the module settings and the
     * list's own options.
     *
     * @param array<string, mixed> $config list_config()
     * @return array<string, mixed>
     */
    private static function card_context(array $config): array
    {
        $votes = '1' === Product_Reviews_Core::get_setting('enable_helpful_votes', '1');

        return [
            'votes'       => $votes,
            'noVotes'     => $votes && '1' === Product_Reviews_Core::get_setting('enable_not_helpful_votes', '0'),
            'replies'     => '1' === Product_Reviews_Core::get_setting('show_replies', '1'),
            'criteria'    => Product_Reviews_Criteria::is_enabled(),
            'lightbox'    => '1' === Product_Reviews_Core::get_setting('review_lightbox', '1'),
            'showProduct' => !empty($config['showProduct']),
        ];
    }

    /**
     * @param array<int, \WP_Comment> $reviews
     * @param array<string, mixed>    $ctx card_context()
     */
    public static function render_cards(array $reviews, array $ctx): string
    {
        if (empty($reviews)) {
            return '';
        }

        $replies_by_parent = !empty($ctx['replies'])
            ? Product_Reviews_Core::get_replies_for_reviews(wp_list_pluck($reviews, 'comment_ID'))
            : [];

        ob_start();
        foreach ($reviews as $review) {
            self::render_review_card($review, $ctx, $replies_by_parent[(int) $review->comment_ID] ?? []);
        }

        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed>    $ctx
     * @param array<int, \WP_Comment> $replies
     */
    private static function render_review_card(\WP_Comment $review, array $ctx, array $replies): void
    {
        $review_id = (int) $review->comment_ID;
        $rating    = (float) get_comment_meta($review_id, 'rating', true);
        $helpful   = (int) get_comment_meta($review_id, 'ffla_helpful_yes', true);
        $unhelpful = (int) get_comment_meta($review_id, 'ffla_helpful_no', true);
        $verified  = Product_Reviews_Core::is_verified_purchase($review_id);
        $pinned    = (int) $review->comment_karma === 1;
        $criteria  = !empty($ctx['criteria']) ? Product_Reviews_Criteria::get_review_scores($review_id) : [];
        $name      = self::display_name($review);

        $media_ids = get_comment_meta($review_id, 'ffla_review_media_ids', true);
        if (!is_array($media_ids)) {
            $media_ids = [];
        }

        echo '<article class="ffla-review-card' . ($pinned ? ' ffla-review-card--pinned' : '') . '" id="review-' . esc_attr((string) $review_id) . '">';

        if (!empty($ctx['showProduct'])) {
            self::render_card_product((int) $review->comment_post_ID);
        }

        echo '<header class="ffla-review-card__header">';
        echo '<span class="ffla-review-card__who">';
        echo self::avatar_html($review, $name); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in avatar_html().
        echo '<strong class="ffla-review-card__author">' . esc_html($name) . '</strong>';
        echo '</span>';
        echo '<span class="ffla-review-card__date">' . esc_html(wp_date(get_option('date_format'), strtotime($review->comment_date_gmt . ' UTC'))) . '</span>';
        echo '</header>';

        if ($pinned) {
            echo '<span class="ffla-review-card__pinned">' . esc_html__('Featured review', 'ffl-funnels-addons') . '</span>';
        }

        if ($rating > 0) {
            echo '<div class="ffla-review-card__rating">' . wp_kses_post(self::render_stars($rating)) . '</div>';
        }

        if ($verified) {
            echo '<span class="ffla-review-card__verified">' . esc_html(self::verified_label()) . '</span>';
        }

        /**
         * Extra badges after Verified buyer (for example the incentivized
         * review badge). Return escaped HTML.
         *
         * @param string      $html
         * @param \WP_Comment $review
         */
        $badges = (string) apply_filters('ffla_product_reviews_card_badges', '', $review);
        if ($badges !== '') {
            echo $badges; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filter contract: escaped HTML.
        }

        if (!empty($criteria)) {
            echo '<div class="ffla-review-card__criteria">';
            foreach ($criteria as $criterion) {
                echo '<span>' . esc_html($criterion['label']) . ': ' . esc_html((string) $criterion['score']) . '/5</span>';
            }
            echo '</div>';
        }

        /**
         * More about the review under the criteria (for example the
         * reviewer's public answers). Return escaped HTML.
         *
         * @param string      $html
         * @param \WP_Comment $review
         */
        $details = (string) apply_filters('ffla_product_reviews_card_details', '', $review);
        if ($details !== '') {
            echo $details; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filter contract: escaped HTML.
        }

        $allowed_html = [
            'p'      => [],
            'br'     => [],
            'strong' => [],
            'em'     => [],
        ];
        echo '<div class="ffla-review-card__content">' . wp_kses(wpautop(wp_strip_all_tags($review->comment_content)), $allowed_html) . '</div>';

        if (!empty($media_ids)) {
            self::render_card_media($review_id, $media_ids, !empty($ctx['lightbox']));
        }

        if (!empty($ctx['votes'])) {
            echo '<div class="ffla-review-helpful-group">';
            echo '<button class="ffla-review-helpful" type="button" data-vote="yes" data-comment-id="' . esc_attr((string) $review_id) . '">';
            echo esc_html__('Helpful', 'ffl-funnels-addons') . ' ';
            echo '<span class="ffla-review-helpful__count">' . esc_html((string) $helpful) . '</span>';
            echo '</button>';

            if (!empty($ctx['noVotes'])) {
                echo '<button class="ffla-review-helpful ffla-review-helpful--no" type="button" data-vote="no" data-comment-id="' . esc_attr((string) $review_id) . '">';
                echo esc_html__('Not helpful', 'ffl-funnels-addons') . ' ';
                echo '<span class="ffla-review-helpful__count-no">' . esc_html((string) $unhelpful) . '</span>';
                echo '</button>';
            }
            echo '</div>';
        }

        if (!empty($replies)) {
            self::render_replies($replies);
        }

        echo '</article>';
    }

    /**
     * Product name and thumbnail on cards of a store-wide or category list.
     */
    private static function render_card_product(int $product_id): void
    {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $name    = $product ? $product->get_name() : get_the_title($product_id);
        $link    = get_permalink($product_id);
        $thumb   = get_the_post_thumbnail_url($product_id, 'thumbnail');

        echo '<a class="ffla-review-card__product" href="' . esc_url((string) $link) . '">';
        if ($thumb) {
            echo '<img class="ffla-review-card__product-image" src="' . esc_url($thumb) . '" width="40" height="40" alt="" loading="lazy" decoding="async">';
        }
        echo '<span class="ffla-review-card__product-name">' . esc_html($name) . '</span>';
        echo '</a>';
    }

    /**
     * @param array<int, mixed> $media_ids
     */
    private static function render_card_media(int $review_id, array $media_ids, bool $lightbox): void
    {
        echo '<div class="ffla-review-card__media">';
        foreach ($media_ids as $media_id) {
            $media_id = absint($media_id);
            if ($media_id <= 0) {
                continue;
            }

            // Both players and images are core output with every
            // attribute already escaped. wp_kses_post() must not run
            // on them: its list has no <source>, which emptied the
            // video player, and no srcset/sizes on <img>.
            $mime = (string) get_post_mime_type($media_id);
            if (strpos($mime, 'video/') === 0) {
                $video = wp_video_shortcode([
                    'src'     => wp_get_attachment_url($media_id),
                    'preload' => 'metadata',
                ]);
                if (is_string($video) && $video !== '') {
                    echo '<div class="ffla-review-card__media-item ffla-review-card__media-item--video">' . $video . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                continue;
            }

            $image = wp_get_attachment_image($media_id, 'medium', false, ['loading' => 'lazy']);
            if (empty($image)) {
                continue;
            }

            echo '<div class="ffla-review-card__media-item ffla-review-card__media-item--image">';
            if ($lightbox) {
                $full = wp_get_attachment_image_url($media_id, 'large');
                if (!$full) {
                    $full = wp_get_attachment_url($media_id);
                }
                echo '<a class="ffla-review-card__media-link" href="' . esc_url((string) $full) . '" data-ffla-lightbox="' . esc_attr((string) $review_id) . '">'
                    . $image // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    . '<span class="screen-reader-text">' . esc_html__('Open photo', 'ffl-funnels-addons') . '</span></a>';
            } else {
                echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            echo '</div>';
        }
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

    /* ---------------------------------------------------------------------
     * Lists
     * ------------------------------------------------------------------- */

    /**
     * Read a list option that may be a bool, a Bricks select ('show' / 'hide'
     * / '' for "use the module setting") or missing.
     *
     * @param mixed $value
     */
    private static function tri_state($value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }

        return !in_array($value, ['hide', '0', 'false', 'off', 'no'], true);
    }

    /**
     * Every list option, typed and with its defaults.
     *
     * @param array<string, mixed> $settings perPage, orderBy, showSummary, source (product / all /
     *                                       categories), categories, layout (list / grid / slider),
     *                                       columns, minRating, withMedia, showProduct, showFilters,
     *                                       showSort, showSearch, showMediaFilter, showMore.
     * @return array<string, mixed>
     */
    public static function list_config(int $product_id, array $settings): array
    {
        $source = sanitize_key((string) ($settings['source'] ?? 'product'));
        if (!in_array($source, ['product', 'all', 'categories'], true)) {
            $source = 'product';
        }

        $categories = Product_Reviews_Query::normalize(['categories' => $settings['categories'] ?? []])['categories'];
        if ('categories' === $source && empty($categories)) {
            $source = 'all';
        }

        $layout = sanitize_key((string) ($settings['layout'] ?? 'list'));
        if (!in_array($layout, ['list', 'grid', 'slider'], true)) {
            $layout = 'list';
        }

        $order = sanitize_key((string) ($settings['orderBy'] ?? 'recent'));
        if (!in_array($order, Product_Reviews_Query::ORDERS, true)) {
            $order = 'recent';
        }

        $per_page = !empty($settings['perPage']) ? absint($settings['perPage']) : 5;

        $toolbar   = '1' === Product_Reviews_Core::get_setting('list_toolbar', '1') && 'slider' !== $layout;
        $show_more = '1' === Product_Reviews_Core::get_setting('list_show_more', '1') && 'slider' !== $layout;

        $min_rating = absint($settings['minRating'] ?? 0);

        return [
            'source'      => $source,
            'productId'   => 'product' === $source ? $product_id : 0,
            'categories'  => 'categories' === $source ? $categories : [],
            'perPage'     => max(1, min(Product_Reviews_Query::MAX_PER_PAGE, $per_page)),
            'orderBy'     => $order,
            'minRating'   => $min_rating >= 1 && $min_rating <= 5 ? $min_rating : 0,
            'withMedia'   => self::tri_state($settings['withMedia'] ?? false, false),
            'layout'      => $layout,
            'columns'     => max(1, min(6, absint($settings['columns'] ?? 3) ?: 3)),
            'showSummary' => self::setting_bool(
                $settings,
                'showSummary',
                '1' === Product_Reviews_Core::get_setting('show_rating_summary', '1')
            ),
            'showProduct' => self::tri_state($settings['showProduct'] ?? '', 'product' !== $source),
            'filters'     => self::tri_state($settings['showFilters'] ?? '', $toolbar),
            'sort'        => self::tri_state($settings['showSort'] ?? '', $toolbar),
            'search'      => self::tri_state($settings['showSearch'] ?? '', $toolbar),
            'mediaFilter' => self::tri_state($settings['showMediaFilter'] ?? '', $toolbar && Product_Reviews_Core::uploads_enabled()),
            'showMore'    => 'slider' !== $layout && self::tri_state($settings['showMore'] ?? '', $show_more),
        ];
    }

    /**
     * Query arguments for a list.
     *
     * @param array<string, mixed> $config list_config()
     * @return array<string, mixed>
     */
    private static function query_args(array $config): array
    {
        return [
            'products'   => $config['productId'] > 0 ? [$config['productId']] : [],
            'categories' => $config['categories'],
            'orderBy'    => $config['orderBy'],
            'minRating'  => $config['minRating'],
            'withMedia'  => $config['withMedia'],
            'perPage'    => $config['perPage'],
            'page'       => 1,
        ];
    }

    /**
     * A product's newest reviews, pinned first, with nothing filtered: read
     * through WordPress's comment query (and its caches) rather than SQL of
     * our own. Asks for one extra review to know whether there are more.
     *
     * @return array{reviews:array<int,\WP_Comment>,hasMore:bool}
     */
    private static function newest_reviews(int $product_id, int $per_page): array
    {
        $reviews = get_comments([
            'post_id' => $product_id,
            'status'  => 'approve',
            'type'    => 'review',
            'parent'  => 0,
            'number'  => $per_page + 1,
            'orderby' => ['comment_karma' => 'DESC', 'comment_date_gmt' => 'DESC'],
        ]);
        $reviews = is_array($reviews) ? array_values($reviews) : [];

        return [
            'reviews' => array_slice($reviews, 0, $per_page),
            'hasMore' => count($reviews) > $per_page,
        ];
    }

    /**
     * First page of a list, and how many reviews match in all (-1 when only
     * "is there more" is known).
     *
     * @param array<string, mixed> $config
     * @return array{reviews:array<int,\WP_Comment>,total:int,hasMore:bool}
     */
    private static function first_page(array $config): array
    {
        $simple = $config['productId'] > 0
            && 'recent' === $config['orderBy']
            && $config['minRating'] === 0
            && !$config['withMedia'];

        if ($simple) {
            $page = self::newest_reviews($config['productId'], $config['perPage']);

            return ['reviews' => $page['reviews'], 'total' => -1, 'hasMore' => $page['hasMore']];
        }

        $found = Product_Reviews_Query::reviews(self::query_args($config));

        return [
            'reviews' => $found['reviews'],
            'total'   => $found['total'],
            'hasMore' => $found['total'] > count($found['reviews']),
        ];
    }

    /**
     * The reviews a list with these settings shows, in display order.
     *
     * Pinned reviews lead regardless of sort. Pin state lives in the native
     * comment_karma column, so the ordering happens in SQL rather than by
     * re-sorting a page the query has already truncated.
     *
     * @param array<string, mixed> $settings perPage (1-50, default 5), orderBy (`recent` / `helpful` /
     *                                       `highest` / `lowest`), minRating, withMedia.
     * @return array<int, \WP_Comment>
     */
    public static function list_reviews(int $product_id, array $settings = []): array
    {
        if ($product_id <= 0) {
            return [];
        }

        $config = self::list_config($product_id, ['source' => 'product'] + $settings);

        return self::first_page($config)['reviews'];
    }

    /**
     * What the lists on this request showed for a product, or null when no
     * list has rendered for it.
     *
     * @return array<int, \WP_Comment>|null
     */
    public static function rendered_reviews(int $product_id): ?array
    {
        return isset(self::$rendered[$product_id]) ? array_values(self::$rendered[$product_id]) : null;
    }

    /**
     * "Showing 5 of 23 reviews".
     */
    public static function status_text(int $shown, int $total): string
    {
        if ($total === 0) {
            return __('No reviews match your filters.', 'ffl-funnels-addons');
        }

        if ($total < 0) {
            return '';
        }

        return sprintf(
            /* translators: 1: reviews shown, 2: reviews in all */
            _n('Showing %1$s of %2$s review', 'Showing %1$s of %2$s reviews', $total, 'ffl-funnels-addons'),
            number_format_i18n($shown),
            number_format_i18n($total)
        );
    }

    /**
     * @param array<string, mixed> $settings See list_config().
     * @param bool                 $wrap     When false, omit outer `.ffla-reviews-list` (Bricks supplies its own root).
     */
    public static function render_reviews_list(int $product_id, array $settings = [], bool $wrap = true): void
    {
        $config = self::list_config($product_id, $settings);
        if ('product' === $config['source'] && $product_id <= 0) {
            return;
        }

        $page = self::first_page($config);
        $ctx  = self::card_context($config);

        $reviews = $page['reviews'];
        $toolbar = $config['filters'] || $config['sort'] || $config['search'] || $config['mediaFilter'];
        $more    = $config['showMore'] && $page['hasMore'];

        $has_photos = false;
        if ($ctx['lightbox']) {
            foreach ($reviews as $review) {
                $ids = get_comment_meta((int) $review->comment_ID, 'ffla_review_media_ids', true);
                if (is_array($ids) && !empty($ids)) {
                    $has_photos = true;
                    break;
                }
            }
        }

        // The script drives the votes, the toolbar, "Show more", the
        // lightbox and the slider; a list with none of them loads CSS only.
        $needs_script = $ctx['votes'] || ($toolbar && !empty($reviews)) || $more || $has_photos || 'slider' === $config['layout'];
        Product_Reviews_Assets::enqueue($needs_script);

        if ($config['productId'] > 0) {
            if (!isset(self::$rendered[$config['productId']])) {
                self::$rendered[$config['productId']] = [];
            }
            foreach ($reviews as $review) {
                self::$rendered[$config['productId']][(int) $review->comment_ID] = $review;
            }
        }

        $total = $page['total'];
        if ($total < 0 && $config['productId'] > 0) {
            // Unfiltered newest list: WooCommerce's own review count.
            $total = Product_Reviews_Core::get_rating_distribution($config['productId'])['review_count'];
        }

        $classes = 'ffla-reviews-list--' . $config['layout'];
        if ($wrap) {
            echo '<div class="ffla-reviews-list ' . esc_attr($classes) . '">';
        }

        $data = $config['productId'] > 0
            ? Product_Reviews_Core::get_rating_distribution($config['productId'])
            : Product_Reviews_Query::distribution(self::query_args($config));

        if ($config['showSummary']) {
            self::render_rating_summary($config['productId'], $config['filters'], $data, (int) $config['minRating']);
        }

        $client = [
            'products'    => $config['productId'] > 0 ? [$config['productId']] : [],
            'categories'  => $config['categories'],
            'perPage'     => $config['perPage'],
            'orderBy'     => $config['orderBy'],
            'minRating'   => $config['minRating'],
            'withMedia'   => $config['withMedia'],
            'showProduct' => $config['showProduct'],
            'total'       => $total,
        ];

        echo '<div class="ffla-reviews-list__body ' . esc_attr($classes) . '" data-ffla-reviews="' . esc_attr((string) wp_json_encode($client)) . '"'
            . ' style="--ffla-columns:' . esc_attr((string) $config['columns']) . '">';

        if ($toolbar && !empty($reviews)) {
            self::render_toolbar($config, $data);
        }

        if (empty($reviews)) {
            echo '<p class="ffla-reviews-list__empty">' . esc_html__('No reviews yet.', 'ffl-funnels-addons') . '</p>';
        }

        if ('slider' === $config['layout'] && count($reviews) > 1) {
            echo '<div class="ffla-reviews-slider" data-ffla-slider>';
            echo '<button type="button" class="ffla-reviews-slider__nav ffla-reviews-slider__nav--prev" data-ffla-slider-prev hidden aria-label="' . esc_attr__('Previous reviews', 'ffl-funnels-addons') . '">&#8249;</button>';
        }

        echo '<div class="ffla-reviews-list__items" data-ffla-items>';
        echo self::render_cards($reviews, $ctx); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
        echo '</div>';

        if ('slider' === $config['layout'] && count($reviews) > 1) {
            echo '<button type="button" class="ffla-reviews-slider__nav ffla-reviews-slider__nav--next" data-ffla-slider-next hidden aria-label="' . esc_attr__('Next reviews', 'ffl-funnels-addons') . '">&#8250;</button>';
            echo '</div>';
        }

        if (!empty($reviews) && ($toolbar || $more)) {
            echo '<p class="ffla-reviews-list__status" data-ffla-status aria-live="polite">' . esc_html(self::status_text(count($reviews), $total)) . '</p>';
        }

        if ($config['showMore']) {
            echo '<button type="button" class="ffla-reviews-list__more" data-ffla-more' . ($more ? '' : ' hidden') . '>'
                . esc_html__('Show more reviews', 'ffl-funnels-addons') . '</button>';
        }

        echo '</div>';

        if ($wrap) {
            echo '</div>';
        }
    }

    /**
     * Star chips, "With photos", sort and search. Hidden until the script
     * has wired it up, so a page without JavaScript shows the plain list.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $data Rating distribution of the list's scope.
     */
    private static function render_toolbar(array $config, array $data): void
    {
        echo '<div class="ffla-reviews-toolbar" data-ffla-toolbar hidden>';

        if ($config['filters'] || $config['mediaFilter']) {
            echo '<div class="ffla-reviews-toolbar__chips" role="group" aria-label="' . esc_attr__('Filter reviews', 'ffl-funnels-addons') . '">';
            if ($config['filters']) {
                echo '<button type="button" class="ffla-reviews-chip is-active" data-ffla-rating="0" aria-pressed="true">' . esc_html__('All', 'ffl-funnels-addons') . '</button>';
                for ($stars = 5; $stars >= 1; $stars--) {
                    $count = (int) ($data['counts'][$stars] ?? 0);
                    if ($count < 1 || $stars < (int) $config['minRating']) {
                        continue;
                    }
                    echo '<button type="button" class="ffla-reviews-chip" data-ffla-rating="' . esc_attr((string) $stars) . '" aria-pressed="false">'
                        . esc_html((string) $stars) . ' <span aria-hidden="true">&#9733;</span>'
                        . '<span class="screen-reader-text"> ' . esc_html(_n('star', 'stars', $stars, 'ffl-funnels-addons')) . '</span>'
                        . ' <span class="ffla-reviews-chip__n">(' . esc_html(number_format_i18n($count)) . ')</span></button>';
                }
            }
            if ($config['mediaFilter']) {
                echo '<button type="button" class="ffla-reviews-chip ffla-reviews-chip--media" data-ffla-media-filter aria-pressed="' . ($config['withMedia'] ? 'true' : 'false') . '">'
                    . esc_html__('With photos', 'ffl-funnels-addons') . '</button>';
            }
            echo '</div>';
        }

        if ($config['sort'] || $config['search']) {
            echo '<div class="ffla-reviews-toolbar__controls">';
            if ($config['sort']) {
                $sort_id = wp_unique_id('ffla-sort-');
                $orders  = [
                    'recent'  => __('Most recent', 'ffl-funnels-addons'),
                    'helpful' => __('Most helpful', 'ffl-funnels-addons'),
                    'highest' => __('Highest rating', 'ffl-funnels-addons'),
                    'lowest'  => __('Lowest rating', 'ffl-funnels-addons'),
                ];
                echo '<label class="ffla-reviews-toolbar__sort" for="' . esc_attr($sort_id) . '">' . esc_html__('Sort by', 'ffl-funnels-addons') . '</label>';
                echo '<select id="' . esc_attr($sort_id) . '" class="ffla-reviews-toolbar__select" data-ffla-sort>';
                foreach ($orders as $value => $label) {
                    echo '<option value="' . esc_attr($value) . '"' . selected($config['orderBy'], $value, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
            }
            if ($config['search']) {
                $search_id = wp_unique_id('ffla-search-');
                echo '<form class="ffla-reviews-toolbar__search" role="search" data-ffla-search>';
                echo '<label class="screen-reader-text" for="' . esc_attr($search_id) . '">' . esc_html__('Search reviews', 'ffl-funnels-addons') . '</label>';
                echo '<input type="search" id="' . esc_attr($search_id) . '" class="ffla-reviews-toolbar__input" maxlength="' . esc_attr((string) Product_Reviews_Query::MAX_SEARCH_LENGTH) . '" placeholder="' . esc_attr__('Search reviews', 'ffl-funnels-addons') . '">';
                echo '<button type="submit" class="ffla-reviews-toolbar__button">' . esc_html__('Search', 'ffl-funnels-addons') . '</button>';
                echo '</form>';
            }
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Store-wide rating: average, stars, review count and share of verified
     * buyers across every published product.
     *
     * @param array<string, mixed> $settings style (card / compact), theme (light / dark), label,
     *                                       showCount, showVerified, hideIfEmpty.
     * @param string               $tag_open Opening tag to use (Bricks passes its root with link
     *                                       attributes); empty for a plain <div>.
     */
    public static function render_store_badge(array $settings = [], string $tag_open = '', string $tag_close = ''): string
    {
        $stats = Product_Reviews_Query::store_stats();
        if ($stats['review_count'] < 1 && !empty($settings['hideIfEmpty'])) {
            return '';
        }

        Product_Reviews_Assets::enqueue(false);

        $style = 'compact' === ($settings['style'] ?? '') ? 'compact' : 'card';
        $theme = 'dark' === ($settings['theme'] ?? '') ? 'dark' : 'light';
        $label = trim((string) ($settings['label'] ?? ''));
        if ($label === '') {
            $label = __('Customer reviews', 'ffl-funnels-addons');
        }

        $classes = 'ffla-store-badge ffla-store-badge--' . $style . ' ffla-store-badge--' . $theme;
        if ($tag_open === '') {
            $tag_open  = '<div class="' . esc_attr($classes) . '">';
            $tag_close = '</div>';
        }

        $meta = [];
        if (self::setting_bool($settings, 'showCount', true)) {
            $meta[] = sprintf(
                /* translators: %s: number of reviews */
                _n('%s review', '%s reviews', $stats['review_count'], 'ffl-funnels-addons'),
                number_format_i18n($stats['review_count'])
            );
        }
        if (self::setting_bool($settings, 'showVerified', true) && $stats['review_count'] > 0) {
            $meta[] = sprintf(
                /* translators: %s: percentage of reviews from verified buyers */
                __('%s%% verified buyers', 'ffl-funnels-addons'),
                number_format_i18n($stats['verified_percent'])
            );
        }

        $html  = $tag_open;
        $html .= '<span class="ffla-store-badge__average">' . esc_html(number_format_i18n($stats['average'], 1)) . '</span>';
        $html .= '<span class="ffla-store-badge__details">';
        if ('card' === $style) {
            $html .= '<span class="ffla-store-badge__label">' . esc_html($label) . '</span>';
        }
        $html .= self::render_stars((float) $stats['average']);
        if (!empty($meta)) {
            $html .= '<span class="ffla-store-badge__meta">' . esc_html(implode(' · ', $meta)) . '</span>';
        }
        $html .= '</span>';
        $html .= $tag_close;

        return $html;
    }

    /**
     * Cards for the AJAX endpoint: the same markup as the first page.
     *
     * @param array<string, mixed> $args Product_Reviews_Query arguments plus showProduct.
     * @return array{html:string,total:int,count:int,hasMore:bool}
     */
    public static function render_page(array $args): array
    {
        $found = Product_Reviews_Query::reviews($args);
        $q     = Product_Reviews_Query::normalize($args);
        $ctx   = self::card_context(['showProduct' => !empty($args['showProduct'])]);

        $shown = (($q['page'] - 1) * $q['perPage']) + count($found['reviews']);

        return [
            'html'    => self::render_cards($found['reviews'], $ctx),
            'total'   => $found['total'],
            'count'   => $shown,
            'hasMore' => $found['total'] > $shown,
        ];
    }
}
