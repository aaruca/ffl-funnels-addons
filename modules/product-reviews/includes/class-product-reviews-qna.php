<?php
/**
 * Product questions and answers (Q&A tab).
 *
 * Questions are comments of type `ffla_question` on the product; answers are
 * `ffla_answer` comments under them. The review rules (ratings, verified
 * buyer, coupons, structured data) never see them, and WooCommerce's own
 * review list leaves them out.
 *
 * Shoppers ask on the product page (a WooCommerce tab, or the Bricks
 * "Product Q&A" element). Who may answer is a setting: the store only (the
 * default), customers who bought the product, signed-in users, or anyone.
 * Answers from the store are labelled and never held. The store hears about
 * new questions, and the asker hears about answers.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Qna
{
    const QUESTION = 'ffla_question';

    const ANSWER = 'ffla_answer';

    const ASK_ACTION = 'ffla_submit_question';

    const ANSWER_ACTION = 'ffla_submit_answer';

    const LOAD_ACTION = 'ffla_load_questions';

    /** On an answer: written by someone who could moderate comments. */
    const STORE_ANSWER_META = 'ffla_is_store_answer';

    /** On an answer: the asker was told about it. */
    const ASKER_NOTIFIED_META = '_ffla_asker_notified';

    /** On a question: the store was told about it. */
    const STORE_NOTIFIED_META = '_ffla_store_notified';

    /** Hourly count of guest questions and answers per IP address. */
    const GUEST_RATE_PREFIX = 'ffla_qna_guest_';

    /** @var bool Hold the question or answer insert() is saving. */
    private static $hold_next = false;

    /** @var string */
    private static $last_error = '';

    /** Whether the #qna anchor is on the page already. */
    private static $anchored = false;

    public static function init(): void
    {
        add_action('admin_post_' . self::ASK_ACTION, [__CLASS__, 'handle_ask']);
        add_action('admin_post_nopriv_' . self::ASK_ACTION, [__CLASS__, 'handle_ask']);
        add_action('admin_post_' . self::ANSWER_ACTION, [__CLASS__, 'handle_answer']);
        add_action('admin_post_nopriv_' . self::ANSWER_ACTION, [__CLASS__, 'handle_answer']);
        add_action('wp_ajax_' . self::LOAD_ACTION, [__CLASS__, 'ajax_load']);
        add_action('wp_ajax_nopriv_' . self::LOAD_ACTION, [__CLASS__, 'ajax_load']);

        add_filter('woocommerce_product_tabs', [__CLASS__, 'add_product_tab'], 25);
        add_filter('comments_template_query_args', [__CLASS__, 'exclude_from_review_list']);
        add_filter('woocommerce_product_reviews_list_table_prepare_items_args', [__CLASS__, 'exclude_from_admin_reviews']);

        add_filter('pre_comment_approved', [__CLASS__, 'filter_approved'], 20, 2);
        add_action('comment_post', [__CLASS__, 'on_comment_post'], 25, 2);
        add_action('transition_comment_status', [__CLASS__, 'on_status_transition'], 25, 3);
    }

    public static function enabled(): bool
    {
        return '1' === Product_Reviews_Core::get_setting('qna_enabled', '0');
    }

    /* ---------------------------------------------------------------------
     * Keep Q&A out of the review lists
     * ------------------------------------------------------------------- */

    /**
     * WooCommerce's Reviews tab lists the product's comments through
     * comments_template(); questions and answers are not reviews.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function exclude_from_review_list($args)
    {
        if (!is_array($args) || empty($args['post_id']) || 'product' !== get_post_type((int) $args['post_id'])) {
            return $args;
        }

        $args['type__not_in'] = array_values(array_unique(array_merge((array) ($args['type__not_in'] ?? []), Product_Reviews_Core::QNA_TYPES)));

        return $args;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function exclude_from_admin_reviews($args)
    {
        if (!is_array($args)) {
            return $args;
        }

        $args['type__not_in'] = array_values(array_unique(array_merge((array) ($args['type__not_in'] ?? []), Product_Reviews_Core::QNA_TYPES)));

        return $args;
    }

    /* ---------------------------------------------------------------------
     * Queries
     * ------------------------------------------------------------------- */

    /**
     * Approved questions of a product, newest first (or the most answered
     * first), one page.
     *
     * @return array{questions:array<int,\WP_Comment>,total:int}
     */
    public static function questions(int $product_id, int $page = 1, int $per_page = 5, string $search = ''): array
    {
        $args = [
            'post_id' => $product_id,
            'type'    => self::QUESTION,
            'parent'  => 0,
            'status'  => 'approve',
        ];
        if ($search !== '') {
            // Not WP_Comment_Query's `search`: it also matches the asker's
            // email, URL and IP address, which a public search must not reveal.
            $ids = self::search_ids($product_id, $search);
            if (empty($ids)) {
                return ['questions' => [], 'total' => 0];
            }
            $args['comment__in'] = $ids;
        }

        $total     = (int) get_comments($args + ['count' => true]);
        $questions = get_comments($args + [
            'number'  => $per_page,
            'offset'  => max(0, ($page - 1) * $per_page),
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
        ]);

        return ['questions' => is_array($questions) ? array_values($questions) : [], 'total' => $total];
    }

    public static function question_count(int $product_id): int
    {
        $key   = 'qna_count_' . $product_id;
        $count = wp_cache_get($key, 'ffla_product_reviews');
        if (false === $count) {
            $count = (int) get_comments([
                'post_id' => $product_id,
                'type'    => self::QUESTION,
                'parent'  => 0,
                'status'  => 'approve',
                'count'   => true,
            ]);
            wp_cache_set($key, $count, 'ffla_product_reviews', HOUR_IN_SECONDS);
        }

        return (int) $count;
    }

    /**
     * Approved answers for a set of questions, store answers first, then the
     * most helpful, then oldest first.
     *
     * @param array<int, int> $question_ids
     * @return array<int, array<int, \WP_Comment>>
     */
    public static function answers_for(array $question_ids): array
    {
        $question_ids = array_values(array_filter(array_map('absint', $question_ids)));
        if (empty($question_ids)) {
            return [];
        }

        $answers = get_comments([
            'parent__in' => $question_ids,
            'type'       => self::ANSWER,
            'status'     => 'approve',
            'orderby'    => 'comment_date_gmt',
            'order'      => 'ASC',
        ]);

        $grouped = [];
        foreach ((array) $answers as $answer) {
            $grouped[(int) $answer->comment_parent][] = $answer;
        }

        foreach ($grouped as &$list) {
            usort($list, static function ($a, $b): int {
                $store = (int) get_comment_meta((int) $b->comment_ID, self::STORE_ANSWER_META, true)
                    <=> (int) get_comment_meta((int) $a->comment_ID, self::STORE_ANSWER_META, true);
                if ($store !== 0) {
                    return $store;
                }

                $votes = (int) get_comment_meta((int) $b->comment_ID, 'ffla_helpful_yes', true)
                    <=> (int) get_comment_meta((int) $a->comment_ID, 'ffla_helpful_yes', true);

                return $votes !== 0 ? $votes : strcmp((string) $a->comment_date_gmt, (string) $b->comment_date_gmt);
            });
        }
        unset($list);

        return $grouped;
    }

    /* ---------------------------------------------------------------------
     * Who may ask and answer
     * ------------------------------------------------------------------- */

    public static function can_ask(int $user_id): bool
    {
        return 'logged_in' !== Product_Reviews_Core::get_setting('qna_who_asks', 'anyone') || $user_id > 0;
    }

    public static function is_store(int $user_id): bool
    {
        return $user_id > 0 && user_can($user_id, 'moderate_comments');
    }

    public static function can_answer(int $user_id, int $product_id): bool
    {
        if (self::is_store($user_id)) {
            return true;
        }

        switch ((string) Product_Reviews_Core::get_setting('qna_who_answers', 'store')) {
            case 'anyone':
                return true;
            case 'logged_in':
                return $user_id > 0;
            case 'buyers':
                return Product_Reviews_Core::account_bought_product($user_id, $product_id);
        }

        return false;
    }

    /* ---------------------------------------------------------------------
     * Product tab
     * ------------------------------------------------------------------- */

    /**
     * @param array<string, array<string, mixed>> $tabs
     * @return array<string, array<string, mixed>>
     */
    public static function add_product_tab($tabs)
    {
        if (!is_array($tabs) || !self::enabled() || '1' !== Product_Reviews_Core::get_setting('qna_tab', '1')) {
            return $tabs;
        }

        $product_id = Product_Reviews_Core::resolve_context_product_id(0);
        $title      = trim((string) Product_Reviews_Core::get_setting('qna_tab_title', ''));
        if ($title === '') {
            $title = __('Questions & Answers', 'ffl-funnels-addons');
        }
        $count = $product_id > 0 ? self::question_count($product_id) : 0;

        $tabs['ffla_qna'] = [
            'title'    => $count > 0 ? sprintf('%s (%s)', $title, number_format_i18n($count)) : $title,
            'priority' => 35,
            'callback' => [__CLASS__, 'render_tab'],
        ];

        return $tabs;
    }

    public static function render_tab(): void
    {
        $product_id = Product_Reviews_Core::resolve_context_product_id(0);
        if ($product_id > 0) {
            self::render($product_id);
        }
    }

    /* ---------------------------------------------------------------------
     * Output
     * ------------------------------------------------------------------- */

    private static function per_page(): int
    {
        return max(1, min(50, absint(Product_Reviews_Core::get_setting('qna_per_page', '5')) ?: 5));
    }

    /**
     * @param array<string, mixed> $settings perPage, title, showSearch (bool), showForm (bool).
     */
    public static function render(int $product_id, array $settings = [], bool $wrap = true): void
    {
        if ($product_id <= 0 || !self::enabled()) {
            return;
        }

        Product_Reviews_Assets::enqueue();

        $per_page = !empty($settings['perPage']) ? max(1, min(50, absint($settings['perPage']))) : self::per_page();
        $found    = self::questions($product_id, 1, $per_page);
        $search   = Product_Reviews_Frontend_Render::setting_bool($settings, 'showSearch', '1' === Product_Reviews_Core::get_setting('qna_search', '1'));
        $form     = Product_Reviews_Frontend_Render::setting_bool($settings, 'showForm', true);
        $title    = isset($settings['title']) ? trim((string) $settings['title']) : '';

        if ($wrap) {
            echo '<div class="ffla-qna">';
        }
        // The anchor the form redirects to; once, even with the product tab
        // and a Bricks Product Q&A element on the same page.
        if (!self::$anchored) {
            self::$anchored = true;
            echo '<span id="qna" class="ffla-qna__anchor"></span>';
        }

        if ($title !== '') {
            echo '<h3 class="ffla-qna__title">' . esc_html($title) . '</h3>';
        }

        self::render_notice();

        $client = ['product' => $product_id, 'perPage' => $per_page];
        echo '<div class="ffla-qna__body" data-ffla-qna="' . esc_attr((string) wp_json_encode($client)) . '">';

        if ($search && $found['total'] > 0) {
            $id = wp_unique_id('ffla-qna-search-');
            echo '<form class="ffla-qna__search" role="search" data-ffla-qna-search hidden>';
            echo '<label class="screen-reader-text" for="' . esc_attr($id) . '">' . esc_html__('Search questions', 'ffl-funnels-addons') . '</label>';
            echo '<input type="search" id="' . esc_attr($id) . '" class="ffla-reviews-toolbar__input" maxlength="100" placeholder="' . esc_attr__('Search questions and answers', 'ffl-funnels-addons') . '">';
            echo '<button type="submit" class="ffla-reviews-toolbar__button">' . esc_html__('Search', 'ffl-funnels-addons') . '</button>';
            echo '</form>';
        }

        echo '<div class="ffla-qna__items" data-ffla-qna-items>';
        if (empty($found['questions'])) {
            echo '<p class="ffla-qna__empty">' . esc_html__('No questions yet. Be the first to ask.', 'ffl-funnels-addons') . '</p>';
        } else {
            echo self::render_questions($found['questions'], $product_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
        }
        echo '</div>';

        echo '<button type="button" class="ffla-reviews-list__more" data-ffla-qna-more' . ($found['total'] > count($found['questions']) ? '' : ' hidden') . '>'
            . esc_html__('Show more questions', 'ffl-funnels-addons') . '</button>';
        echo '</div>';

        if ($form) {
            self::render_ask_form($product_id);
        }

        if ($wrap) {
            echo '</div>';
        }
    }

    private static function render_notice(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
        $status  = isset($_GET['ffla_qna_status']) ? sanitize_key(wp_unslash($_GET['ffla_qna_status'])) : '';
        $message = isset($_GET['ffla_qna_message']) ? sanitize_text_field(wp_unslash($_GET['ffla_qna_message'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ('asked' === $status) {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--success" role="status">' . esc_html__('Thanks! Your question was sent. It appears here once it is answered or approved.', 'ffl-funnels-addons') . '</p>';
        } elseif ('answered' === $status) {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--success" role="status">' . esc_html__('Thanks! Your answer was sent.', 'ffl-funnels-addons') . '</p>';
        } elseif ('error' === $status && $message !== '') {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--error" role="alert">' . esc_html($message) . '</p>';
        }
    }

    /**
     * @param array<int, \WP_Comment> $questions
     */
    public static function render_questions(array $questions, int $product_id): string
    {
        $answers  = self::answers_for(wp_list_pluck($questions, 'comment_ID'));
        $votes    = '1' === Product_Reviews_Core::get_setting('qna_votes', '1');
        $user_id  = get_current_user_id();
        $answerer = self::can_answer($user_id, $product_id);

        ob_start();
        foreach ($questions as $question) {
            $qid  = (int) $question->comment_ID;
            $list = $answers[$qid] ?? [];

            echo '<article class="ffla-qna-item" id="question-' . esc_attr((string) $qid) . '">';
            echo '<div class="ffla-qna-item__question">';
            echo '<span class="ffla-qna-item__q" aria-hidden="true">Q</span>';
            echo '<div class="ffla-qna-item__text">' . esc_html(wp_strip_all_tags((string) $question->comment_content)) . '</div>';
            echo '</div>';
            echo '<p class="ffla-qna-item__meta">' . esc_html(sprintf(
                /* translators: 1: asker name, 2: date */
                __('Asked by %1$s on %2$s', 'ffl-funnels-addons'),
                Product_Reviews_Frontend_Render::display_name($question),
                wp_date((string) get_option('date_format'), strtotime($question->comment_date_gmt . ' UTC'))
            )) . '</p>';

            if (empty($list)) {
                echo '<p class="ffla-qna-item__none">' . esc_html__('Not answered yet.', 'ffl-funnels-addons') . '</p>';
            }

            foreach ($list as $answer) {
                $aid   = (int) $answer->comment_ID;
                $store = 1 === (int) get_comment_meta($aid, self::STORE_ANSWER_META, true);

                echo '<div class="ffla-qna-answer' . ($store ? ' ffla-qna-answer--store' : '') . '" id="answer-' . esc_attr((string) $aid) . '">';
                echo '<span class="ffla-qna-item__a" aria-hidden="true">A</span>';
                echo '<div class="ffla-qna-answer__body">';
                echo '<div class="ffla-qna-answer__text">' . wp_kses(wpautop(wp_strip_all_tags((string) $answer->comment_content)), ['p' => [], 'br' => []]) . '</div>';
                echo '<p class="ffla-qna-item__meta">';
                echo esc_html($store ? Product_Reviews_Email_Template::store_name() : Product_Reviews_Frontend_Render::display_name($answer));
                if ($store) {
                    echo ' <span class="ffla-review-reply__badge">' . esc_html__('Store answer', 'ffl-funnels-addons') . '</span>';
                } elseif (Product_Reviews_Core::account_bought_product((int) $answer->user_id, $product_id)) {
                    echo ' <span class="ffla-review-card__verified">' . esc_html(Product_Reviews_Frontend_Render::verified_label()) . '</span>';
                }
                echo ' · ' . esc_html(wp_date((string) get_option('date_format'), strtotime($answer->comment_date_gmt . ' UTC')));
                echo '</p>';

                if ($votes) {
                    echo '<div class="ffla-review-helpful-group">';
                    echo '<button class="ffla-review-helpful" type="button" data-vote="yes" data-comment-id="' . esc_attr((string) $aid) . '">'
                        . esc_html__('Helpful', 'ffl-funnels-addons') . ' <span class="ffla-review-helpful__count">'
                        . esc_html((string) (int) get_comment_meta($aid, 'ffla_helpful_yes', true)) . '</span></button>';
                    echo '</div>';
                }
                echo '</div></div>';
            }

            if ($answerer) {
                self::render_answer_form($product_id, $qid);
            }

            echo '</article>';
        }

        return (string) ob_get_clean();
    }

    private static function redirect_target(int $product_id): string
    {
        $target = '';
        if (function_exists('wp_get_canonical_url') && function_exists('is_singular') && is_singular()) {
            $target = (string) wp_get_canonical_url(get_queried_object_id());
        }

        return $target !== '' ? $target : (string) get_permalink($product_id);
    }

    private static function render_guest_fields(string $uid): void
    {
        if (is_user_logged_in()) {
            return;
        }

        echo '<p class="ffla-review-form__field"><label for="' . esc_attr($uid) . '-name">' . esc_html__('Name', 'ffl-funnels-addons') . '</label>';
        echo '<input id="' . esc_attr($uid) . '-name" type="text" name="author" required autocomplete="name" maxlength="80"></p>';
        echo '<p class="ffla-review-form__field"><label for="' . esc_attr($uid) . '-email">' . esc_html__('Email', 'ffl-funnels-addons') . '</label>';
        echo '<input id="' . esc_attr($uid) . '-email" type="email" name="email" required autocomplete="email">';
        echo '<small>' . esc_html__('Not shown. We email you when someone answers.', 'ffl-funnels-addons') . '</small></p>';
    }

    private static function render_ask_form(int $product_id): void
    {
        echo '<details class="ffla-qna__ask">';
        echo '<summary class="ffla-qna__ask-summary">' . esc_html__('Ask a question', 'ffl-funnels-addons') . '</summary>';

        if (!self::can_ask(get_current_user_id())) {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--info">' . esc_html__('Please log in to ask a question.', 'ffl-funnels-addons')
                . ' ' . Product_Reviews_Frontend_Render::login_link(self::redirect_target($product_id)) . '</p>';
            echo '</details>';

            return;
        }

        $uid = wp_unique_id('ffla-qa-');
        echo '<form class="ffla-review-form ffla-qna__form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ASK_ACTION, 'ffla_qna_nonce');
        echo '<input type="text" class="ffla-review-hp-field" name="ffla_hp" value="" autocomplete="off" tabindex="-1" aria-hidden="true">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ASK_ACTION) . '">';
        echo '<input type="hidden" name="product_id" value="' . esc_attr((string) $product_id) . '">';
        echo '<input type="hidden" name="redirect_to" value="' . esc_url(self::redirect_target($product_id)) . '">';
        self::render_guest_fields($uid);
        echo '<p class="ffla-review-form__field"><label for="' . esc_attr($uid) . '-q">' . esc_html__('Your question', 'ffl-funnels-addons') . '</label>';
        echo '<textarea id="' . esc_attr($uid) . '-q" name="question" rows="3" required maxlength="1000"></textarea></p>';
        if (class_exists('Product_Reviews_Captcha')) {
            Product_Reviews_Captcha::render_field('ffla_product_question');
        }
        do_action('ffla_product_reviews_qna_form_fields', 'question', $product_id);
        echo '<button class="ffla-review-form__submit" type="submit">' . esc_html__('Send question', 'ffl-funnels-addons') . '</button>';
        echo '</form></details>';
    }

    private static function render_answer_form(int $product_id, int $question_id): void
    {
        $uid = wp_unique_id('ffla-qan-');
        echo '<details class="ffla-qna-item__reply">';
        echo '<summary class="ffla-qna-item__reply-summary">' . esc_html__('Answer this question', 'ffl-funnels-addons') . '</summary>';
        echo '<form class="ffla-review-form ffla-qna__form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::ANSWER_ACTION, 'ffla_qna_nonce');
        echo '<input type="text" class="ffla-review-hp-field" name="ffla_hp" value="" autocomplete="off" tabindex="-1" aria-hidden="true">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ANSWER_ACTION) . '">';
        echo '<input type="hidden" name="question_id" value="' . esc_attr((string) $question_id) . '">';
        echo '<input type="hidden" name="redirect_to" value="' . esc_url(self::redirect_target($product_id)) . '">';
        self::render_guest_fields($uid);
        echo '<p class="ffla-review-form__field"><label for="' . esc_attr($uid) . '-a">' . esc_html__('Your answer', 'ffl-funnels-addons') . '</label>';
        echo '<textarea id="' . esc_attr($uid) . '-a" name="answer" rows="3" required maxlength="2000"></textarea></p>';
        if (class_exists('Product_Reviews_Captcha') && !is_user_logged_in()) {
            Product_Reviews_Captcha::render_field('ffla_product_answer');
        }
        do_action('ffla_product_reviews_qna_form_fields', 'answer', $product_id);
        echo '<button class="ffla-review-form__submit" type="submit">' . esc_html__('Send answer', 'ffl-funnels-addons') . '</button>';
        echo '</form></details>';
    }

    /**
     * Approved questions of a product whose text, or the text of one of its
     * approved answers, contains $search.
     *
     * @return array<int, int>
     */
    private static function search_ids(int $product_id, string $search): array
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($search) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a live search.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT q.comment_ID FROM {$wpdb->comments} q
            WHERE q.comment_post_ID = %d AND q.comment_type = %s AND q.comment_parent = 0 AND q.comment_approved = '1'
            AND (q.comment_content LIKE %s OR EXISTS (
                SELECT 1 FROM {$wpdb->comments} a
                WHERE a.comment_parent = q.comment_ID AND a.comment_type = %s AND a.comment_approved = '1' AND a.comment_content LIKE %s
            ))
            LIMIT 500",
            $product_id,
            self::QUESTION,
            $like,
            self::ANSWER,
            $like
        ));

        return array_values(array_map('intval', (array) $ids));
    }

    /* ---------------------------------------------------------------------
     * AJAX paging and search
     * ------------------------------------------------------------------- */

    public static function ajax_load(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- public, read-only.
        $product_id = isset($_POST['product']) ? absint($_POST['product']) : 0;
        $page       = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $per_page   = isset($_POST['perPage']) ? max(1, min(50, (int) $_POST['perPage'])) : self::per_page();
        $search     = isset($_POST['search']) ? trim(sanitize_text_field(wp_unslash($_POST['search']))) : '';
        // Characters, not bytes: a cut through a multibyte character makes
        // the query invalid.
        $search = function_exists('mb_substr') ? mb_substr($search, 0, 100) : substr($search, 0, 100);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (!self::enabled() || $product_id <= 0 || 'product' !== get_post_type($product_id) || 'publish' !== get_post_status($product_id)) {
            wp_send_json_error(['message' => __('Questions are not available.', 'ffl-funnels-addons')]);
        }

        $found = self::questions($product_id, $page, $per_page, $search);
        $shown = ($page - 1) * $per_page + count($found['questions']);

        wp_send_json_success([
            'html'    => self::render_questions($found['questions'], $product_id),
            'hasMore' => $found['total'] > $shown,
            'total'   => $found['total'],
            'status'  => 0 === $found['total'] ? __('No questions match your search.', 'ffl-funnels-addons') : '',
        ]);
    }

    /* ---------------------------------------------------------------------
     * Submissions
     * ------------------------------------------------------------------- */

    /**
     * @return never
     */
    private static function back(string $redirect, string $fallback, string $status, string $message = ''): void
    {
        $url  = wp_validate_redirect($redirect, $fallback);
        $args = ['ffla_qna_status' => $status];
        if ($message !== '') {
            $args['ffla_qna_message'] = $message;
        }

        wp_safe_redirect(add_query_arg($args, $url) . '#qna');
        exit;
    }

    private static function guest_rate_key(): string
    {
        return self::GUEST_RATE_PREFIX . md5(Product_Reviews_Ajax::client_ip());
    }

    /**
     * The checks both forms share. Returns the author fields, or redirects
     * back with the reason.
     *
     * @return array{name:string,email:string,user_id:int}
     */
    private static function check_submission(string $action, string $redirect, string $fallback): array
    {
        if (!self::enabled()) {
            self::back($redirect, $fallback, 'error', __('Questions are switched off.', 'ffl-funnels-addons'));
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked below for signed-in users.
        $honeypot = isset($_POST['ffla_hp']) ? trim((string) wp_unslash($_POST['ffla_hp'])) : '';
        $nonce    = isset($_POST['ffla_qna_nonce']) ? sanitize_text_field(wp_unslash($_POST['ffla_qna_nonce'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($honeypot !== '') {
            self::back($redirect, $fallback, 'error', __('Your message could not be sent.', 'ffl-funnels-addons'));
        }

        // As for reviews: a guest nonce is the same for everyone and goes
        // stale on cached pages; guests get Turnstile and an hourly limit.
        if (is_user_logged_in() && !wp_verify_nonce($nonce, $action)) {
            self::back($redirect, $fallback, 'error', __('Security check failed. Please refresh and try again.', 'ffl-funnels-addons'));
        }

        if (!is_user_logged_in() && class_exists('Product_Reviews_Captcha') && !Product_Reviews_Captcha::passes()) {
            self::back($redirect, $fallback, 'error', __('Cloudflare validation failed. Please try again.', 'ffl-funnels-addons'));
        }

        /**
         * Extra checks for a question or answer (terms checkbox, CAPTCHA).
         * Return a message to refuse it.
         *
         * @param string $error  ''
         * @param string $action ffla_submit_question / ffla_submit_answer
         */
        $error = (string) apply_filters('ffla_product_reviews_qna_check', '', $action);
        if ($error !== '') {
            self::back($redirect, $fallback, 'error', $error);
        }

        if (is_user_logged_in()) {
            $user = wp_get_current_user();

            return [
                'name'    => $user instanceof \WP_User ? (string) $user->display_name : '',
                'email'   => $user instanceof \WP_User ? (string) $user->user_email : '',
                'user_id' => get_current_user_id(),
            ];
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $name  = isset($_POST['author']) ? sanitize_text_field(wp_unslash($_POST['author'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ($name === '' || !is_email($email)) {
            self::back($redirect, $fallback, 'error', __('Please enter your name and a valid email.', 'ffl-funnels-addons'));
        }

        $limit = (int) apply_filters('ffla_product_reviews_qna_guest_hourly_limit', 5);
        if ($limit > 0 && (int) get_transient(self::guest_rate_key()) >= $limit) {
            self::back($redirect, $fallback, 'error', __('Too many messages were sent from your connection. Please try again later.', 'ffl-funnels-addons'));
        }

        return ['name' => substr($name, 0, 80), 'email' => $email, 'user_id' => 0];
    }

    private static function count_guest(): void
    {
        if (!is_user_logged_in()) {
            $key = self::guest_rate_key();
            set_transient($key, (int) get_transient($key) + 1, HOUR_IN_SECONDS);
        }
    }

    public static function handle_ask(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check_submission().
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $redirect   = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
        $text       = isset($_POST['question']) ? trim(sanitize_textarea_field(wp_unslash($_POST['question']))) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $fallback = $product_id > 0 ? (string) get_permalink($product_id) : home_url('/');

        if ($product_id <= 0 || 'product' !== get_post_type($product_id) || 'publish' !== get_post_status($product_id)) {
            self::back($redirect, $fallback, 'error', __('Invalid product.', 'ffl-funnels-addons'));
        }

        $author = self::check_submission(self::ASK_ACTION, $redirect, $fallback);

        if (!self::can_ask($author['user_id'])) {
            self::back($redirect, $fallback, 'error', __('Please log in to ask a question.', 'ffl-funnels-addons'));
        }

        if (strlen($text) < 5) {
            self::back($redirect, $fallback, 'error', __('Please write your question.', 'ffl-funnels-addons'));
        }

        $id = self::insert(self::QUESTION, $product_id, 0, $text, $author);
        if (!$id) {
            self::back($redirect, $fallback, 'error', self::$last_error !== '' ? self::$last_error : __('Your question could not be sent. Please try again.', 'ffl-funnels-addons'));
        }

        self::count_guest();
        self::back($redirect, $fallback, 'asked');
    }

    public static function handle_answer(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in check_submission().
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        $redirect    = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
        $text        = isset($_POST['answer']) ? trim(sanitize_textarea_field(wp_unslash($_POST['answer']))) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $question   = $question_id > 0 ? get_comment($question_id) : null;
        $product_id = $question instanceof \WP_Comment ? (int) $question->comment_post_ID : 0;
        $fallback   = $product_id > 0 ? (string) get_permalink($product_id) : home_url('/');

        if (!$question instanceof \WP_Comment || self::QUESTION !== $question->comment_type || '1' !== (string) $question->comment_approved
            || 'publish' !== get_post_status($product_id)) {
            self::back($redirect, $fallback, 'error', __('That question is not available.', 'ffl-funnels-addons'));
        }

        $author = self::check_submission(self::ANSWER_ACTION, $redirect, $fallback);

        if (!self::can_answer($author['user_id'], $product_id)) {
            self::back($redirect, $fallback, 'error', __('Only the store can answer questions here.', 'ffl-funnels-addons'));
        }

        if (strlen($text) < 2) {
            self::back($redirect, $fallback, 'error', __('Please write your answer.', 'ffl-funnels-addons'));
        }

        $id = self::insert(self::ANSWER, $product_id, $question_id, $text, $author);
        if (!$id) {
            self::back($redirect, $fallback, 'error', self::$last_error !== '' ? self::$last_error : __('Your answer could not be sent. Please try again.', 'ffl-funnels-addons'));
        }

        self::count_guest();
        self::back($redirect, $fallback, 'answered');
    }

    /**
     * Saved through wp_new_comment(), so WordPress's own checks (duplicates,
     * flooding, disallowed words, Akismet) apply. Held unless the store
     * writes it or moderation is switched off for that kind.
     *
     * @param array{name:string,email:string,user_id:int} $author
     * @return int Comment ID, 0 when refused.
     */
    /** Why the last insert() was refused (WordPress's message: duplicate, too fast…), or ''. */
    public static function last_error(): string
    {
        return self::$last_error;
    }

    public static function insert(string $type, int $product_id, int $parent, string $text, array $author): int
    {
        $store = self::is_store($author['user_id']);
        $hold  = self::QUESTION === $type
            ? '1' === Product_Reviews_Core::get_setting('qna_moderate_questions', '1')
            : '1' === Product_Reviews_Core::get_setting('qna_moderate_answers', '1');

        $data = [
            'comment_post_ID'      => $product_id,
            'comment_parent'       => $parent,
            'comment_type'         => $type,
            'comment_content'      => $text,
            'comment_author'       => $author['name'],
            'comment_author_email' => $author['email'],
            'comment_author_url'   => '',
            'user_id'              => $author['user_id'],
            'comment_author_IP'    => Product_Reviews_Ajax::client_ip(),
            'comment_agent'        => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 254) : '',
        ];

        if (!$store && class_exists('Product_Reviews_Moderation') && Product_Reviews_Moderation::contains_forbidden_term($text, $author['name'])) {
            $hold = true;
        }

        // wp_allow_comment() overwrites any comment_approved passed in, so
        // the decision is made on pre_comment_approved, before comment_post
        // (and the asker's email) runs.
        self::$hold_next  = $hold && !$store;
        self::$last_error = '';

        // With holding off, publish as the Q&A settings say: Settings →
        // Discussion's "manually approved" and "previously approved author"
        // are about blog comments. Its moderation words and link limit
        // still hold a message.
        $open = !self::$hold_next;
        if ($open) {
            add_filter('pre_option_comment_moderation', '__return_zero');
            add_filter('pre_option_comment_previously_approved', '__return_zero');
        }
        $id = wp_new_comment(wp_slash($data), true);
        if ($open) {
            remove_filter('pre_option_comment_moderation', '__return_zero');
            remove_filter('pre_option_comment_previously_approved', '__return_zero');
        }
        self::$hold_next = false;

        if (is_wp_error($id)) {
            self::$last_error = wp_strip_all_tags($id->get_error_message());

            return 0;
        }
        if (!$id) {
            return 0;
        }

        if (self::ANSWER === $type && $store) {
            update_comment_meta((int) $id, self::STORE_ANSWER_META, 1);
        }

        wp_cache_delete('qna_count_' . $product_id, 'ffla_product_reviews');

        return (int) $id;
    }

    /**
     * @param int|string|\WP_Error $approved
     * @param array<string, mixed> $commentdata
     * @return int|string|\WP_Error
     */
    public static function filter_approved($approved, $commentdata)
    {
        if (!self::$hold_next || is_wp_error($approved) || 'spam' === $approved || 'trash' === $approved) {
            return $approved;
        }

        if (!is_array($commentdata) || !in_array((string) ($commentdata['comment_type'] ?? ''), Product_Reviews_Core::QNA_TYPES, true)) {
            return $approved;
        }

        return 0;
    }

    /* ---------------------------------------------------------------------
     * Notifications
     * ------------------------------------------------------------------- */

    /**
     * @param int|mixed        $comment_id
     * @param int|string|mixed $approved
     */
    public static function on_comment_post($comment_id, $approved): void
    {
        $comment = get_comment((int) $comment_id);
        if (!$comment instanceof \WP_Comment) {
            return;
        }

        if (in_array((string) $approved, ['spam', 'trash'], true)) {
            return;
        }

        if (self::QUESTION === $comment->comment_type) {
            self::notify_store($comment);
        } elseif (self::ANSWER === $comment->comment_type && '1' === (string) $approved
            && self::is_store((int) $comment->user_id)) {
            // Store answers go out at once. A customer's answer published
            // without moderation is not emailed: anyone could otherwise send
            // any text from the store's address. Once a moderator approves
            // one, it is (on_status_transition()).
            self::notify_asker($comment);
        }
    }

    /**
     * @param string|mixed      $new_status
     * @param string|mixed      $old_status
     * @param \WP_Comment|mixed $comment
     */
    public static function on_status_transition($new_status, $old_status, $comment): void
    {
        if (!$comment instanceof \WP_Comment || !in_array($comment->comment_type, Product_Reviews_Core::QNA_TYPES, true)) {
            return;
        }

        wp_cache_delete('qna_count_' . (int) $comment->comment_post_ID, 'ffla_product_reviews');

        if ('approved' === $new_status && 'approved' !== $old_status && self::ANSWER === $comment->comment_type) {
            self::notify_asker($comment);
        }
    }

    private static function notify_store(\WP_Comment $question): void
    {
        if ('1' !== Product_Reviews_Core::get_setting('qna_notify_store', '1') || get_comment_meta((int) $question->comment_ID, self::STORE_NOTIFIED_META, true)) {
            return;
        }

        $to = sanitize_email((string) Product_Reviews_Core::get_setting('qna_notify_email', ''));
        if (!is_email($to)) {
            $to = (string) get_option('admin_email');
        }
        if (!is_email($to)) {
            return;
        }

        $product = wc_get_product((int) $question->comment_post_ID);
        $name    = $product ? $product->get_name() : get_the_title((int) $question->comment_post_ID);
        $admin   = add_query_arg(array_filter([
            'page' => 'ffla-product-reviews-questions',
            'view' => '1' === (string) $question->comment_approved ? '' : 'pending',
        ]), admin_url('admin.php')) . '#ffla-question-' . (int) $question->comment_ID;

        $subject = sprintf(
            /* translators: %s: product name */
            __('New question about %s', 'ffl-funnels-addons'),
            $name
        );
        $body = sprintf(
            /* translators: 1: product name, 2: asker name, 3: question, 4: status, 5: admin URL */
            __("A customer asked about %1\$s.\n\n%2\$s wrote:\n%3\$s\n\n%4\$s\n\nAnswer it here:\n%5\$s", 'ffl-funnels-addons'),
            $name,
            (string) $question->comment_author,
            wp_strip_all_tags((string) $question->comment_content),
            '1' === (string) $question->comment_approved
                ? __('It is already shown on the product page.', 'ffl-funnels-addons')
                : __('It waits for approval and is not shown yet.', 'ffl-funnels-addons'),
            $admin
        );

        if (wp_mail($to, $subject, $body, Product_Reviews_Email::mail_headers())) {
            update_comment_meta((int) $question->comment_ID, self::STORE_NOTIFIED_META, 1);
        }
    }

    private static function notify_asker(\WP_Comment $answer): void
    {
        if ('1' !== Product_Reviews_Core::get_setting('qna_notify_asker', '1') || get_comment_meta((int) $answer->comment_ID, self::ASKER_NOTIFIED_META, true)) {
            return;
        }

        $question = get_comment((int) $answer->comment_parent);
        if (!$question instanceof \WP_Comment || self::QUESTION !== $question->comment_type || '1' !== (string) $question->comment_approved) {
            return;
        }

        $to = (string) $question->comment_author_email;
        if (!is_email($to) || strtolower($to) === strtolower((string) $answer->comment_author_email)) {
            return;
        }

        $product = wc_get_product((int) $question->comment_post_ID);
        $name    = $product ? $product->get_name() : get_the_title((int) $question->comment_post_ID);
        $link    = get_permalink((int) $question->comment_post_ID) . '#question-' . (int) $question->comment_ID;
        $first   = (string) (preg_split('/\s+/', trim((string) $question->comment_author)) ?: [''])[0];

        $subject = sprintf(
            /* translators: %s: product name */
            __('Your question about %s was answered', 'ffl-funnels-addons'),
            $name
        );
        $body = sprintf(
            /* translators: 1: asker first name, 2: product name, 3: question, 4: answer, 5: link */
            __("Hi %1\$s,\n\nYou asked about %2\$s:\n%3\$s\n\nThe answer:\n%4\$s\n\nSee it on the product page:\n%5\$s", 'ffl-funnels-addons'),
            $first !== '' ? $first : __('there', 'ffl-funnels-addons'),
            $name,
            wp_strip_all_tags((string) $question->comment_content),
            wp_strip_all_tags((string) $answer->comment_content),
            $link
        );

        if (wp_mail($to, $subject, $body, Product_Reviews_Email::mail_headers())) {
            update_comment_meta((int) $answer->comment_ID, self::ASKER_NOTIFIED_META, 1);
        }
    }
}
