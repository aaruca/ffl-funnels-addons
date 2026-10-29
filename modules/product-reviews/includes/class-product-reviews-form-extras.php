<?php
/**
 * More on the review and Q&A forms (Review form tab): questions about the
 * reviewer (text, one choice, several choices; required or optional; shown
 * on the review or kept for the store), required rating criteria, and a
 * terms and privacy checkbox.
 *
 * A requirement is only enforced on a form that showed the field (the form
 * says so in a hidden marker), so a Bricks form that hides criteria or a hub
 * form without questions still submits.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Form_Extras
{
    const MAX_QUESTIONS = 10;

    const TYPES = ['text', 'select', 'radio', 'checkbox'];

    const META_PREFIX = 'ffla_review_q_';

    /** @var array<int, array<string, mixed>>|null */
    private static $memo = null;

    public static function init(): void
    {
        add_action('ffla_product_reviews_form_fields', [__CLASS__, 'render_review_fields'], 10, 2);
        add_filter('ffla_product_reviews_form_check', [__CLASS__, 'check_review'], 10, 3);
        add_action('comment_post', [__CLASS__, 'save_answers'], 15, 3);
        add_filter('ffla_product_reviews_card_details', [__CLASS__, 'card_answers'], 10, 2);

        add_action('ffla_product_reviews_qna_form_fields', [__CLASS__, 'render_qna_fields'], 10, 2);
        add_filter('ffla_product_reviews_qna_check', [__CLASS__, 'check_qna'], 10, 2);
    }

    /* ---------------------------------------------------------------------
     * Question definitions
     * ------------------------------------------------------------------- */

    /**
     * One per line: `slug|Label|type|options|flags`.
     * type: text (default), select, radio, checkbox.
     * options: comma separated (choices for select / radio / checkbox).
     * flags: comma separated, any of required, public (shown on the
     * review), product (product forms only), hub (review page only).
     *
     * @return array<int, array{slug:string,label:string,type:string,options:array<int,string>,required:bool,public:bool,where:string}>
     */
    public static function parse(string $raw): array
    {
        $out  = [];
        $seen = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $slug  = sanitize_key($parts[0] ?? '');
            $label = sanitize_text_field($parts[1] ?? '');
            $type  = strtolower($parts[2] ?? '') ?: 'text';
            if ($slug === '' || $label === '' || isset($seen[$slug]) || !in_array($type, self::TYPES, true)) {
                continue;
            }

            $options = array_values(array_unique(array_filter(array_map(static function ($o) {
                return sanitize_text_field(trim((string) $o));
            }, explode(',', $parts[3] ?? '')), 'strlen')));
            if ('text' !== $type && count($options) < 1) {
                continue;
            }

            $flags = array_map('trim', explode(',', strtolower($parts[4] ?? '')));
            $where = in_array('product', $flags, true) && !in_array('hub', $flags, true) ? 'product'
                : (in_array('hub', $flags, true) && !in_array('product', $flags, true) ? 'hub' : 'both');

            $seen[$slug] = true;
            $out[]       = [
                'slug'     => $slug,
                'label'    => $label,
                'type'     => $type,
                'options'  => 'text' === $type ? [] : array_slice($options, 0, 20),
                'required' => in_array('required', $flags, true),
                'public'   => in_array('public', $flags, true),
                'where'    => $where,
            ];

            if (count($out) >= self::MAX_QUESTIONS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     */
    public static function serialize(array $questions): string
    {
        return implode("\n", array_map(static function (array $q): string {
            $flags = array_filter([
                $q['required'] ? 'required' : '',
                $q['public'] ? 'public' : '',
                'both' !== $q['where'] ? $q['where'] : '',
            ]);

            return $q['slug'] . '|' . $q['label'] . '|' . $q['type'] . '|' . implode(',', $q['options']) . '|' . implode(',', $flags);
        }, $questions));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function questions(): array
    {
        if (self::$memo === null) {
            self::$memo = self::parse((string) Product_Reviews_Core::get_setting('review_questions', ''));
        }

        return self::$memo;
    }

    public static function flush_memo(): void
    {
        self::$memo = null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function questions_for(string $context): array
    {
        return array_values(array_filter(self::questions(), static function (array $q) use ($context): bool {
            return 'both' === $q['where'] || $context === $q['where'];
        }));
    }

    /* ---------------------------------------------------------------------
     * Review form
     * ------------------------------------------------------------------- */

    /**
     * @param int|mixed    $product_id
     * @param string|mixed $context product / hub
     */
    public static function render_review_fields($product_id, $context = 'product'): void
    {
        $context   = 'hub' === $context ? 'hub' : 'product';
        $questions = self::questions_for($context);

        if (!empty($questions)) {
            echo '<input type="hidden" name="ffla_questions_shown" value="' . esc_attr($context) . '">';
            foreach ($questions as $question) {
                self::render_question($question);
            }
        }

        if (self::terms_on('reviews')) {
            self::render_terms();
        }
    }

    /**
     * @param array<string, mixed> $q
     */
    private static function render_question(array $q): void
    {
        $id    = wp_unique_id('ffla-rq-' . $q['slug'] . '-');
        $name  = 'ffla_review_q[' . $q['slug'] . ']';
        $req   = $q['required'] ? ' required' : '';
        $label = $q['required'] ? $q['label'] : sprintf(
            /* translators: %s: question label */
            __('%s (optional)', 'ffl-funnels-addons'),
            $q['label']
        );

        switch ($q['type']) {
            case 'select':
                echo '<p class="ffla-review-form__field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
                echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"' . $req . '><option value="">' . esc_html__('Choose…', 'ffl-funnels-addons') . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute.
                foreach ($q['options'] as $option) {
                    echo '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
                }
                echo '</select></p>';
                break;

            case 'radio':
            case 'checkbox':
                echo '<fieldset class="ffla-review-form__fieldset ffla-review-form__choices"><legend class="ffla-review-form__legend">' . esc_html($label) . '</legend>';
                foreach ($q['options'] as $i => $option) {
                    $oid = $id . '-' . $i;
                    echo '<label class="ffla-review-form__choice" for="' . esc_attr($oid) . '"><input type="' . esc_attr($q['type']) . '" id="' . esc_attr($oid) . '" name="'
                        . esc_attr($name . ('checkbox' === $q['type'] ? '[]' : '')) . '" value="' . esc_attr($option) . '"'
                        . ('radio' === $q['type'] && 0 === $i ? $req : '') . '> ' . esc_html($option) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute.
                }
                echo '</fieldset>';
                break;

            default:
                echo '<p class="ffla-review-form__field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
                echo '<input type="text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" maxlength="200"' . $req . '></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute.
        }
    }

    /**
     * The answer posted for a question, checked against its choices.
     *
     * @param array<string, mixed> $q
     */
    private static function posted_answer(array $q): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the form handler checked the request.
        $all = isset($_POST['ffla_review_q']) && is_array($_POST['ffla_review_q']) ? wp_unslash($_POST['ffla_review_q']) : [];
        $raw = $all[$q['slug']] ?? '';

        if ('checkbox' === $q['type']) {
            $picked = array_values(array_intersect($q['options'], array_map('strval', (array) $raw)));

            return implode(', ', $picked);
        }

        $value = is_scalar($raw) ? trim(sanitize_text_field((string) $raw)) : '';
        if ('text' === $q['type']) {
            return function_exists('mb_substr') ? mb_substr($value, 0, 200) : substr($value, 0, 200);
        }

        return in_array($value, $q['options'], true) ? $value : '';
    }

    /**
     * @param string|mixed $error
     * @param int|mixed    $product_id
     * @param mixed        $order
     * @return string
     */
    public static function check_review($error, $product_id = 0, $order = null)
    {
        if ((string) $error !== '') {
            return $error;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the form handler checked the request.
        if (!empty($_POST['ffla_criteria_shown'])) {
            $missing = Product_Reviews_Criteria::missing_required();
            if ($missing !== null) {
                return sprintf(
                    /* translators: %s: rating criterion, e.g. Quality */
                    __('Please rate %s.', 'ffl-funnels-addons'),
                    $missing['label']
                );
            }
        }

        $shown = isset($_POST['ffla_questions_shown']) ? sanitize_key(wp_unslash($_POST['ffla_questions_shown'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ($shown !== '') {
            foreach (self::questions_for('hub' === $shown ? 'hub' : 'product') as $q) {
                if ($q['required'] && self::posted_answer($q) === '') {
                    return sprintf(
                        /* translators: %s: question label */
                        __('Please answer: %s', 'ffl-funnels-addons'),
                        $q['label']
                    );
                }
            }
        }

        if (self::terms_on('reviews') && !self::terms_ticked()) {
            return self::terms_error();
        }

        return '';
    }

    /**
     * @param int|mixed        $comment_id
     * @param int|string|mixed $approved
     * @param array|mixed      $commentdata
     */
    public static function save_answers($comment_id, $approved = 0, $commentdata = []): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the FFL form handler checked the request.
        if (!isset($_POST['action'], $_POST['ffla_review_q']) || 'ffla_submit_product_review' !== $_POST['action']) {
            return;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $comment = get_comment((int) $comment_id);
        if (!$comment instanceof \WP_Comment || 'review' !== $comment->comment_type || 'product' !== get_post_type((int) $comment->comment_post_ID)) {
            return;
        }

        foreach (self::questions() as $q) {
            $answer = self::posted_answer($q);
            if ($answer !== '') {
                update_comment_meta((int) $comment_id, self::META_PREFIX . $q['slug'], $answer);
            }
        }
    }

    /**
     * Public answers, on the review card.
     *
     * @param string|mixed      $html
     * @param \WP_Comment|mixed $review
     */
    public static function card_answers($html, $review): string
    {
        $html = (string) $html;
        if (!$review instanceof \WP_Comment) {
            return $html;
        }

        $rows = '';
        foreach (self::questions() as $q) {
            if (!$q['public']) {
                continue;
            }
            $answer = (string) get_comment_meta((int) $review->comment_ID, self::META_PREFIX . $q['slug'], true);
            if ($answer !== '') {
                $rows .= '<div class="ffla-review-card__answer"><dt>' . esc_html($q['label']) . '</dt><dd>' . esc_html($answer) . '</dd></div>';
            }
        }

        return $rows !== '' ? $html . '<dl class="ffla-review-card__answers">' . $rows . '</dl>' : $html;
    }

    /**
     * Every answer of a review (public or not), label => answer, for the
     * admin and the export.
     *
     * @return array<string, string>
     */
    public static function answers(int $comment_id): array
    {
        $out = [];
        foreach (self::questions() as $q) {
            $answer = (string) get_comment_meta($comment_id, self::META_PREFIX . $q['slug'], true);
            if ($answer !== '') {
                $out[$q['label']] = $answer;
            }
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     * Terms checkbox
     * ------------------------------------------------------------------- */

    /**
     * @param string $where reviews / qna
     */
    public static function terms_on(string $where): bool
    {
        $key = 'qna' === $where ? 'terms_on_qna' : 'terms_on_reviews';

        return '1' === Product_Reviews_Core::get_setting($key, '0');
    }

    private static function terms_ticked(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller.
        return isset($_POST['ffla_terms']) && '1' === (string) wp_unslash($_POST['ffla_terms']);
    }

    private static function terms_error(): string
    {
        return __('Please accept the terms to continue.', 'ffl-funnels-addons');
    }

    /**
     * The checkbox label, with {terms} replaced by a link to the chosen page
     * (or the link added after the text).
     */
    public static function terms_label_html(): string
    {
        $text = trim((string) Product_Reviews_Core::get_setting('terms_text', ''));
        if ($text === '') {
            $text = __('I agree to the {terms}.', 'ffl-funnels-addons');
        }

        $page_id = absint(Product_Reviews_Core::get_setting('terms_page_id', '0'));
        if ($page_id <= 0 && function_exists('get_option')) {
            $page_id = absint(get_option('wp_page_for_privacy_policy', 0));
        }

        $link = '';
        if ($page_id > 0 && 'publish' === get_post_status($page_id)) {
            $link = '<a href="' . esc_url((string) get_permalink($page_id)) . '" target="_blank" rel="noopener">' . esc_html(get_the_title($page_id)) . '</a>';
        }

        $parts = explode('{terms}', $text, 2);
        if (count($parts) === 2) {
            return esc_html($parts[0]) . ($link !== '' ? $link : esc_html__('terms', 'ffl-funnels-addons')) . esc_html($parts[1]);
        }

        return esc_html($text) . ($link !== '' ? ' ' . $link : '');
    }

    private static function render_terms(): void
    {
        $id = wp_unique_id('ffla-terms-');
        echo '<p class="ffla-review-form__field ffla-review-form__terms"><label class="ffla-review-form__choice" for="' . esc_attr($id) . '">';
        echo '<input type="checkbox" id="' . esc_attr($id) . '" name="ffla_terms" value="1" required> ';
        echo self::terms_label_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in terms_label_html().
        echo '</label></p>';
    }

    /**
     * @param string|mixed $kind
     * @param int|mixed    $product_id
     */
    public static function render_qna_fields($kind = 'question', $product_id = 0): void
    {
        if (self::terms_on('qna')) {
            self::render_terms();
        }
    }

    /**
     * @param string|mixed $error
     * @param string|mixed $action
     * @return string
     */
    public static function check_qna($error, $action = '')
    {
        if ((string) $error !== '') {
            return $error;
        }

        return self::terms_on('qna') && !self::terms_ticked() ? self::terms_error() : '';
    }

    /* ---------------------------------------------------------------------
     * Log in link
     * ------------------------------------------------------------------- */

    /**
     * Where "log in" links go: the store's setting, else WooCommerce's My
     * Account page, else the WordPress login, coming back to $redirect.
     */
    public static function login_url(string $redirect = ''): string
    {
        $url = trim((string) Product_Reviews_Core::get_setting('login_url', ''));
        if ($url === '' && function_exists('wc_get_page_permalink')) {
            $account = (string) wc_get_page_permalink('myaccount');
            $url     = $account !== '' && false === strpos($account, '?page_id=-1') ? $account : '';
        }
        if ($url === '') {
            return wp_login_url($redirect);
        }

        return $redirect !== '' ? add_query_arg('redirect_to', rawurlencode($redirect), $url) : $url;
    }
}
