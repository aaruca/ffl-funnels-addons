<?php
/**
 * FFL Funnels → Product Reviews → Questions: every product question, with
 * its answers, an inline answer box, and approve / hold / trash. Questions
 * and answers are comments, so they also show under Comments.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Qna_Admin
{
    const PAGE = 'ffla-product-reviews-questions';

    const ANSWER_ACTION = 'ffla_qna_admin_answer';

    const STATUS_ACTION = 'ffla_qna_admin_status';

    const PER_PAGE = 20;

    public function init(): void
    {
        add_action('admin_post_' . self::ANSWER_ACTION, [$this, 'handle_answer']);
        add_action('admin_post_' . self::STATUS_ACTION, [$this, 'handle_status']);
    }

    public static function page_url(array $args = []): string
    {
        return add_query_arg(['page' => self::PAGE] + $args, admin_url('admin.php'));
    }

    /**
     * @return array<string, int> unanswered / pending / answered / all
     */
    public static function counts(): array
    {
        $base = ['type' => Product_Reviews_Qna::QUESTION, 'parent' => 0, 'count' => true];

        $approved = (int) get_comments($base + ['status' => 'approve']);
        $pending  = (int) get_comments($base + ['status' => 'hold']);

        $answered_ids = [];
        foreach ((array) get_comments(['type' => Product_Reviews_Qna::ANSWER, 'status' => 'approve', 'fields' => 'ids']) as $answer_id) {
            $answer = get_comment((int) $answer_id);
            if ($answer instanceof \WP_Comment) {
                $answered_ids[(int) $answer->comment_parent] = true;
            }
        }

        $answered = 0;
        foreach ((array) get_comments(['type' => Product_Reviews_Qna::QUESTION, 'parent' => 0, 'status' => 'approve', 'fields' => 'ids']) as $qid) {
            if (isset($answered_ids[(int) $qid])) {
                $answered++;
            }
        }

        return [
            'unanswered' => max(0, $approved - $answered),
            'pending'    => $pending,
            'answered'   => $answered,
            'all'        => $approved + $pending,
        ];
    }

    public static function render(): void
    {
        if (!current_user_can('moderate_comments')) {
            echo '<div class="wb-card"><div class="wb-card__body"><p>' . esc_html__('You need permission to moderate comments to answer questions.', 'ffl-funnels-addons') . '</p></div></div>';
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen filters and a notice.
        $view   = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'unanswered';
        $paged  = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $notice = isset($_GET['qna_notice']) ? sanitize_key(wp_unslash($_GET['qna_notice'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (!Product_Reviews_Qna::enabled()) {
            FFLA_Admin::render_notice('info', esc_html__('Product Q&A is switched off. Switch it on under Product Reviews → Settings → Q&A to show questions on product pages.', 'ffl-funnels-addons'));
        }

        $messages = [
            'answered' => __('Answer published.', 'ffl-funnels-addons'),
            'approved' => __('Approved.', 'ffl-funnels-addons'),
            'held'     => __('Moved back to pending.', 'ffl-funnels-addons'),
            'trashed'  => __('Moved to the trash.', 'ffl-funnels-addons'),
            'empty'    => __('Write an answer first.', 'ffl-funnels-addons'),
        ];
        if (isset($messages[$notice])) {
            FFLA_Admin::render_notice('empty' === $notice ? 'warning' : 'success', esc_html($messages[$notice]));
        }

        $counts = self::counts();
        $views  = [
            'unanswered' => __('Unanswered', 'ffl-funnels-addons'),
            'pending'    => __('Waiting for approval', 'ffl-funnels-addons'),
            'answered'   => __('Answered', 'ffl-funnels-addons'),
            'all'        => __('All', 'ffl-funnels-addons'),
        ];
        if (!isset($views[$view])) {
            $view = 'unanswered';
        }

        echo '<div class="wb-card"><div class="wb-card__header"><h3>' . esc_html__('Product questions', 'ffl-funnels-addons') . '</h3>';
        echo '<nav class="ffla-pr-tabs ffla-pr-tabs--small">';
        foreach ($views as $key => $label) {
            echo '<a class="ffla-pr-tabs__tab' . ($key === $view ? ' is-current' : '') . '" href="' . esc_url(self::page_url(['view' => $key])) . '">'
                . esc_html($label) . ' (' . esc_html(number_format_i18n($counts[$key])) . ')</a>';
        }
        echo '</nav></div>';
        echo '<div class="wb-card__body">';

        $args = [
            'type'    => Product_Reviews_Qna::QUESTION,
            'parent'  => 0,
            'status'  => 'pending' === $view ? 'hold' : ('all' === $view ? 'all' : 'approve'),
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
        ];
        $questions = (array) get_comments($args);
        $answers   = self::all_answers(wp_list_pluck($questions, 'comment_ID'));

        if ('unanswered' === $view || 'answered' === $view) {
            $questions = array_values(array_filter($questions, static function ($q) use ($answers, $view) {
                $has = !empty(array_filter($answers[(int) $q->comment_ID] ?? [], static function ($a) {
                    return '1' === (string) $a->comment_approved;
                }));

                return 'answered' === $view ? $has : !$has;
            }));
        }

        $total     = count($questions);
        $questions = array_slice($questions, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);

        if (empty($questions)) {
            echo '<div class="wb-empty-state"><p>' . esc_html__('No questions here.', 'ffl-funnels-addons') . '</p></div>';
        }

        foreach ($questions as $question) {
            echo self::question_html($question, $answers[(int) $question->comment_ID] ?? [], $view); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
        }
        echo '</div>';

        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages > 1) {
            echo '<div class="wb-card__footer">';
            for ($p = 1; $p <= $pages; $p++) {
                echo $p === $paged
                    ? '<span class="wb-btn wb-btn--xs wb-btn--primary">' . esc_html((string) $p) . '</span>'
                    : '<a class="wb-btn wb-btn--xs wb-btn--subtle" href="' . esc_url(self::page_url(['view' => $view, 'paged' => $p])) . '">' . esc_html((string) $p) . '</a>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    /**
     * Every answer (any status) of these questions.
     *
     * @param array<int, int|string> $question_ids
     * @return array<int, array<int, \WP_Comment>>
     */
    private static function all_answers(array $question_ids): array
    {
        $question_ids = array_values(array_filter(array_map('absint', $question_ids)));
        if (empty($question_ids)) {
            return [];
        }

        $out = [];
        foreach ((array) get_comments(['parent__in' => $question_ids, 'type' => Product_Reviews_Qna::ANSWER, 'status' => 'all', 'orderby' => 'comment_date_gmt', 'order' => 'ASC']) as $answer) {
            $out[(int) $answer->comment_parent][] = $answer;
        }

        return $out;
    }

    private static function status_link(\WP_Comment $comment, string $to, string $label, string $view): string
    {
        $url = wp_nonce_url(
            add_query_arg(['action' => self::STATUS_ACTION, 'comment_id' => (int) $comment->comment_ID, 'to' => $to, 'view' => $view], admin_url('admin-post.php')),
            self::STATUS_ACTION . '_' . (int) $comment->comment_ID
        );

        return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    /**
     * @param array<int, \WP_Comment> $answers
     */
    public static function question_html(\WP_Comment $question, array $answers, string $view = 'all'): string
    {
        $qid     = (int) $question->comment_ID;
        $product = wc_get_product((int) $question->comment_post_ID);
        $name    = $product ? $product->get_name() : get_the_title((int) $question->comment_post_ID);
        $pending = '1' !== (string) $question->comment_approved;

        $html  = '<div class="ffla-pr-qna" id="ffla-question-' . esc_attr((string) $qid) . '">';
        $html .= '<div class="ffla-pr-qna__head"><a class="wb-link--strong" href="' . esc_url((string) get_permalink((int) $question->comment_post_ID)) . '#question-' . esc_attr((string) $qid) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a>';
        if ($pending) {
            $html .= ' <span class="ffla-pr-status ffla-pr-status--skipped">' . esc_html__('Waiting for approval', 'ffl-funnels-addons') . '</span>';
        }
        $html .= '</div>';
        $html .= '<p class="ffla-pr-qna__question">' . esc_html(wp_strip_all_tags((string) $question->comment_content)) . '</p>';
        $html .= '<p class="wb-text--muted">' . esc_html(sprintf(
            /* translators: 1: name, 2: email, 3: date */
            __('%1$s (%2$s) · %3$s', 'ffl-funnels-addons'),
            (string) $question->comment_author,
            (string) $question->comment_author_email,
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($question->comment_date_gmt . ' UTC'))
        )) . '</p>';

        $actions = [];
        if ($pending) {
            $actions[] = self::status_link($question, 'approve', __('Approve', 'ffl-funnels-addons'), $view);
        } else {
            $actions[] = self::status_link($question, 'hold', __('Unapprove', 'ffl-funnels-addons'), $view);
        }
        $actions[] = self::status_link($question, 'trash', __('Trash', 'ffl-funnels-addons'), $view);
        $html     .= '<p class="wb-row-actions">' . implode(' · ', $actions) . '</p>';

        foreach ($answers as $answer) {
            $store    = 1 === (int) get_comment_meta((int) $answer->comment_ID, Product_Reviews_Qna::STORE_ANSWER_META, true);
            $held     = '1' !== (string) $answer->comment_approved;
            $html    .= '<div class="ffla-pr-qna__answer' . ($held ? ' is-held' : '') . '">';
            $html    .= '<p>' . esc_html(wp_strip_all_tags((string) $answer->comment_content)) . '</p>';
            $html    .= '<p class="wb-text--muted">' . esc_html($store ? __('Store answer', 'ffl-funnels-addons') : (string) $answer->comment_author)
                . ($held ? ' · ' . esc_html__('waiting for approval', 'ffl-funnels-addons') : '') . '</p>';
            $a_actions = [$held ? self::status_link($answer, 'approve', __('Approve', 'ffl-funnels-addons'), $view) : self::status_link($answer, 'hold', __('Unapprove', 'ffl-funnels-addons'), $view)];
            $a_actions[] = self::status_link($answer, 'trash', __('Trash', 'ffl-funnels-addons'), $view);
            $html      .= '<p class="wb-row-actions">' . implode(' · ', $a_actions) . '</p>';
            $html      .= '</div>';
        }

        $html .= '<form class="ffla-pr-qna__form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $html .= '<input type="hidden" name="action" value="' . esc_attr(self::ANSWER_ACTION) . '">';
        $html .= '<input type="hidden" name="question_id" value="' . esc_attr((string) $qid) . '">';
        $html .= '<input type="hidden" name="view" value="' . esc_attr($view) . '">';
        $html .= wp_nonce_field(self::ANSWER_ACTION . '_' . $qid, '_wpnonce', true, false);
        $html .= '<label class="screen-reader-text" for="ffla-answer-' . esc_attr((string) $qid) . '">' . esc_html__('Your answer', 'ffl-funnels-addons') . '</label>';
        $html .= '<textarea id="ffla-answer-' . esc_attr((string) $qid) . '" class="wb-input" name="answer" rows="2" placeholder="' . esc_attr__('Write the store’s answer…', 'ffl-funnels-addons') . '"></textarea>';
        $html .= '<button type="submit" class="wb-btn wb-btn--primary wb-btn--sm">'
            . esc_html($pending ? __('Approve and answer', 'ffl-funnels-addons') : __('Publish answer', 'ffl-funnels-addons')) . '</button>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    public function handle_answer(): void
    {
        $question_id = isset($_POST['question_id']) ? absint($_POST['question_id']) : 0;
        check_admin_referer(self::ANSWER_ACTION . '_' . $question_id);
        if (!current_user_can('moderate_comments')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }

        $view     = isset($_POST['view']) ? sanitize_key(wp_unslash($_POST['view'])) : 'unanswered';
        $text     = isset($_POST['answer']) ? trim(sanitize_textarea_field(wp_unslash($_POST['answer']))) : '';
        $question = get_comment($question_id);

        if (!$question instanceof \WP_Comment || Product_Reviews_Qna::QUESTION !== $question->comment_type) {
            wp_die(esc_html__('Question not found.', 'ffl-funnels-addons'), '', ['response' => 404]);
        }

        if ($text === '') {
            wp_safe_redirect(self::page_url(['view' => $view, 'qna_notice' => 'empty']) . '#ffla-question-' . $question_id);
            exit;
        }

        // Answering a held question publishes it: the answer would
        // otherwise sit under a question nobody can see.
        if ('1' !== (string) $question->comment_approved) {
            wp_set_comment_status($question_id, 'approve');
        }

        $user = wp_get_current_user();
        Product_Reviews_Qna::insert(Product_Reviews_Qna::ANSWER, (int) $question->comment_post_ID, $question_id, $text, [
            'name'    => $user instanceof \WP_User ? (string) $user->display_name : '',
            'email'   => $user instanceof \WP_User ? (string) $user->user_email : '',
            'user_id' => get_current_user_id(),
        ]);

        wp_safe_redirect(self::page_url(['view' => $view, 'qna_notice' => 'answered']));
        exit;
    }

    public function handle_status(): void
    {
        $comment_id = isset($_GET['comment_id']) ? absint($_GET['comment_id']) : 0;
        check_admin_referer(self::STATUS_ACTION . '_' . $comment_id);
        if (!current_user_can('moderate_comments')) {
            wp_die(esc_html__('Permission denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }

        $comment = get_comment($comment_id);
        if (!$comment instanceof \WP_Comment || !in_array($comment->comment_type, Product_Reviews_Core::QNA_TYPES, true)) {
            wp_die(esc_html__('Question not found.', 'ffl-funnels-addons'), '', ['response' => 404]);
        }

        $to   = isset($_GET['to']) ? sanitize_key(wp_unslash($_GET['to'])) : '';
        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'unanswered';
        $map  = ['approve' => 'approved', 'hold' => 'held', 'trash' => 'trashed'];

        if (isset($map[$to])) {
            wp_set_comment_status($comment_id, $to);
        }

        wp_safe_redirect(self::page_url(['view' => $view, 'qna_notice' => $map[$to] ?? '']));
        exit;
    }
}
