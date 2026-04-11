<?php
/**
 * Order review hub: multi-product forms from email link (shortcode + Bricks).
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Order_Hub
{
    /** @var string Token from pretty URL rewrite (parse_request). */
    private static $context_token = '';

    public static function init(): void
    {
        add_shortcode('ffla_order_reviews', [__CLASS__, 'shortcode_render']);
    }

    public static function set_context_token(string $token): void
    {
        self::$context_token = $token;
    }

    public static function clear_context_token(): void
    {
        self::$context_token = '';
    }

    public static function get_effective_token(): string
    {
        if (self::$context_token !== '') {
            return self::$context_token;
        }

        $qv = get_query_var('ffla_order_review_token', '');
        if (is_string($qv) && $qv !== '') {
            return $qv;
        }

        if (isset($_GET['ffla_ro'])) {
            return sanitize_text_field(wp_unslash($_GET['ffla_ro']));
        }

        return '';
    }

    public static function is_bricks_preview(): bool
    {
        if (function_exists('bricks_is_builder') && bricks_is_builder()) {
            return true;
        }
        if (function_exists('bricks_is_builder_call') && bricks_is_builder_call()) {
            return true;
        }
        if (class_exists('\Bricks\Database') && !empty(\Bricks\Database::$is_builder_call)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $atts Shortcode attributes (unused; reserved).
     */
    public static function shortcode_render($atts = []): string
    {
        return self::render_hub(false);
    }

    /**
     * @param array<string, mixed> $settings Bricks element settings (optional intro title override).
     */
    public static function render_hub(bool $builder_preview, array $settings = []): string
    {
        $token = self::get_effective_token();

        if ($builder_preview || (self::is_bricks_preview() && $token === '')) {
            return self::render_placeholder();
        }

        if ($token === '') {
            return '<div class="ffla-order-reviews ffla-order-reviews--error"><p>'
                . esc_html__('This link is missing a review key. Open the link from your order email.', 'ffl-funnels-addons')
                . '</p></div>';
        }

        $order = \Product_Reviews_Core::get_order_for_review_token($token);
        if (!$order) {
            return '<div class="ffla-order-reviews ffla-order-reviews--error"><p>'
                . esc_html__('This review link is invalid or has expired.', 'ffl-funnels-addons')
                . '</p></div>';
        }

        $products = self::unique_products_from_order($order);
        if (empty($products)) {
            return '<div class="ffla-order-reviews ffla-order-reviews--error"><p>'
                . esc_html__('No products in this order to review.', 'ffl-funnels-addons')
                . '</p></div>';
        }

        $billing_email = strtolower(trim((string) $order->get_billing_email()));
        $show_criteria = '1' === \Product_Reviews_Core::get_setting('order_review_show_criteria', '0');
        $redirect_base = \Product_Reviews_Core::order_review_return_base_url();
        $redirect_to   = \Product_Reviews_Core::append_order_review_token_to_url($redirect_base, $token);

        $status = isset($_GET['ffla_review_status']) ? sanitize_text_field(wp_unslash($_GET['ffla_review_status'])) : '';
        $msg    = isset($_GET['ffla_review_message']) ? sanitize_text_field(wp_unslash($_GET['ffla_review_message'])) : '';

        ob_start();

        echo '<div class="ffla-order-reviews" id="reviews">';

        $intro_title = isset($settings['introTitle']) && $settings['introTitle'] !== ''
            ? $settings['introTitle']
            : '';
        if ($intro_title !== '') {
            echo '<h2 class="ffla-order-reviews__page-title">' . esc_html($intro_title) . '</h2>';
        }

        echo '<p class="ffla-order-reviews__intro">';
        echo esc_html(
            sprintf(
                /* translators: %s: order number */
                __('Thank you for order #%s. Please leave a review for each product below.', 'ffl-funnels-addons'),
                (string) $order->get_order_number()
            )
        );
        echo '</p>';

        if ($status === 'success') {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--success" role="status">'
                . esc_html__('Thanks! Your review was submitted.', 'ffl-funnels-addons') . '</p>';
        } elseif ($status === 'error' && $msg !== '') {
            echo '<p class="ffla-review-form__notice ffla-review-form__notice--error" role="alert">' . esc_html($msg) . '</p>';
        }

        foreach ($products as $product_id => $product_name) {
            echo '<section class="ffla-order-reviews__product" id="ffla-order-review-' . esc_attr((string) $product_id) . '">';
            echo '<h3 class="ffla-order-reviews__product-title">' . esc_html($product_name) . '</h3>';

            if (\Product_Reviews_Core::customer_has_review_for_product($billing_email, $product_id)) {
                echo '<p class="ffla-order-reviews__done">' . esc_html__('You already submitted a review for this product.', 'ffl-funnels-addons') . '</p>';
                echo '</section>';
                continue;
            }

            self::render_single_form($product_id, $token, $show_criteria, $redirect_to, $order);
            echo '</section>';
        }

        echo '</div>';

        return (string) ob_get_clean();
    }

    private static function render_placeholder(): string
    {
        return '<div class="ffla-order-reviews ffla-order-reviews--placeholder"><p><strong>'
            . esc_html__('Order review hub (preview)', 'ffl-funnels-addons')
            . '</strong></p><p>'
            . esc_html__('Customers see one form per product when they open the signed link from the email. Save and test with a real link.', 'ffl-funnels-addons')
            . '</p></div>';
    }

    /**
     * @return array<int, string> product_id => name
     */
    private static function unique_products_from_order(\WC_Order $order): array
    {
        $out = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $pid = \Product_Reviews_Core::line_item_parent_product_id($item);
            if ($pid <= 0 || 'product' !== get_post_type($pid)) {
                continue;
            }
            if (isset($out[$pid])) {
                continue;
            }
            $p = wc_get_product($pid);
            $out[$pid] = $p ? $p->get_name() : (string) $item->get_name();
        }

        return $out;
    }

    private static function render_single_form(int $product_id, string $token, bool $show_criteria, string $redirect_to, \WC_Order $order): void
    {
        $uid = wp_unique_id('ffla-or-');

        $first = trim((string) $order->get_billing_first_name());
        $last  = trim((string) $order->get_billing_last_name());
        $name  = trim($first . ' ' . $last);
        if ($name === '') {
            $name = __('Customer', 'ffl-funnels-addons');
        }

        echo '<form class="ffla-review-form ffla-order-reviews__form" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('ffla_review_form', 'ffla_review_form_nonce');
        echo '<input type="text" class="ffla-review-hp-field" name="ffla_hp" value="" autocomplete="off" tabindex="-1" aria-hidden="true">';
        echo '<input type="hidden" name="action" value="ffla_submit_product_review">';
        echo '<input type="hidden" name="ffla_order_review_token" value="' . esc_attr($token) . '">';
        echo '<input type="hidden" name="comment_post_ID" value="' . esc_attr((string) $product_id) . '">';
        echo '<input type="hidden" name="comment_parent" value="0">';
        echo '<input type="hidden" name="redirect_to" value="' . esc_url($redirect_to) . '">';
        echo '<input type="hidden" name="author" value="' . esc_attr($name) . '">';
        echo '<input type="hidden" name="email" value="' . esc_attr((string) $order->get_billing_email()) . '">';

        self::render_star_fieldset('rating', $uid . '-r', __('Overall rating', 'ffl-funnels-addons'), true);

        if ($show_criteria) {
            self::render_star_fieldset('ffla_review_quality', $uid . '-q', __('Quality (optional)', 'ffl-funnels-addons'), false);
            self::render_star_fieldset('ffla_review_value', $uid . '-v', __('Value for money (optional)', 'ffl-funnels-addons'), false);
        }

        $comment_id = $uid . '-c';
        echo '<p class="ffla-review-form__field">';
        echo '<label for="' . esc_attr($comment_id) . '">' . esc_html__('Your review', 'ffl-funnels-addons') . '</label>';
        echo '<textarea id="' . esc_attr($comment_id) . '" name="comment" rows="5" required></textarea></p>';

        echo '<p class="ffla-review-form__field ffla-review-form__field--media">';
        echo '<label for="' . esc_attr($uid) . '-m">' . esc_html__('Photos / video (optional)', 'ffl-funnels-addons') . '</label>';
        echo '<input id="' . esc_attr($uid) . '-m" name="ffla_review_media[]" type="file" accept="image/*,video/mp4,video/webm" multiple>';
        echo '<small>' . esc_html__('Up to 3 files, 5 MB each.', 'ffl-funnels-addons') . '</small></p>';

        if (\Product_Reviews_Core::is_turnstile_enabled()) {
            echo '<p class="ffla-order-reviews__turnstile-note"><small>'
                . esc_html__('Security check is not shown here; your signed email link is used instead.', 'ffl-funnels-addons')
                . '</small></p>';
        }

        echo '<button class="ffla-review-form__submit" type="submit">' . esc_html__('Submit review', 'ffl-funnels-addons') . '</button>';
        echo '</form>';
    }

    private static function render_star_fieldset(string $name, string $id_prefix, string $legend, bool $required): void
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
            echo '<span class="screen-reader-text">' . esc_html(sprintf(__('%d out of 5 stars', 'ffl-funnels-addons'), $i)) . '</span>';
            echo '<span class="ffla-star-rating-input__glyph" aria-hidden="true">&#9733;</span>';
            echo '</label></span>';
        }
        echo '</div></fieldset>';
    }
}
