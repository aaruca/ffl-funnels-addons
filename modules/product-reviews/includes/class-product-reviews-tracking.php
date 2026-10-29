<?php
/**
 * What happened to a review request: opened (a 1×1 image in HTML emails),
 * clicked (the review page opened with a valid link) and reviewed (a review
 * sent through the link). Stored on the order, shown on the order screen
 * and in the request log.
 *
 * Opens are approximate: some mail apps block images, and mail scanners
 * that fetch every image and link count as an open or a click.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Tracking
{
    const OPEN_ACTION = 'ffla_review_open';

    const OPENED_META = '_ffla_review_opened';

    const OPEN_COUNT_META = '_ffla_review_open_count';

    const CLICKED_META = '_ffla_review_clicked';

    const CLICK_COUNT_META = '_ffla_review_click_count';

    const REVIEWED_META = '_ffla_review_reviewed';

    const FIRST_REVIEW_META = '_ffla_review_first_review';

    /** @var array<int, bool> Orders whose click was recorded on this request. */
    private static $clicked = [];

    public static function init(): void
    {
        add_action('admin_post_' . self::OPEN_ACTION, [__CLASS__, 'handle_open']);
        add_action('admin_post_nopriv_' . self::OPEN_ACTION, [__CLASS__, 'handle_open']);
    }

    public static function enabled(): bool
    {
        return '1' === Product_Reviews_Core::get_setting('email_tracking', '1');
    }

    private static function signature(int $order_id): string
    {
        return substr(hash_hmac('sha256', 'open|' . $order_id, wp_salt('ffla_product_reviews_open')), 0, 20);
    }

    public static function pixel_url(int $order_id): string
    {
        return add_query_arg(
            [
                'action' => self::OPEN_ACTION,
                'o'      => $order_id,
                's'      => self::signature($order_id),
            ],
            admin_url('admin-post.php')
        );
    }

    /**
     * Records the open (when the signature matches) and answers with a
     * transparent GIF either way, so the email never shows a broken image.
     */
    public static function handle_open(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- signed URL, no state beyond a counter.
        $order_id = isset($_GET['o']) ? absint($_GET['o']) : 0;
        $sig      = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        self::record_open($order_id, $sig);

        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: image/gif');
        }
        echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        exit;
    }

    public static function record_open(int $order_id, string $signature): bool
    {
        if ($order_id <= 0 || !hash_equals(self::signature($order_id), $signature) || !self::enabled()) {
            return false;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            return false;
        }

        if (!$order->get_meta(self::OPENED_META)) {
            $order->update_meta_data(self::OPENED_META, time());
        }
        $order->update_meta_data(self::OPEN_COUNT_META, (int) $order->get_meta(self::OPEN_COUNT_META) + 1);
        self::save($order);

        return true;
    }

    /**
     * The review page opened with this order's valid link. Counted once per
     * page load even when the page holds more than one hub.
     */
    public static function record_click(\WC_Order $order): void
    {
        $order_id = (int) $order->get_id();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the redirect after a review, read only.
        $after_review = isset($_GET['ffla_review_status']);
        if (isset(self::$clicked[$order_id]) || !self::enabled() || $after_review) {
            return;
        }
        self::$clicked[$order_id] = true;

        if (!$order->get_meta(self::CLICKED_META)) {
            $order->update_meta_data(self::CLICKED_META, time());
        }
        $order->update_meta_data(self::CLICK_COUNT_META, (int) $order->get_meta(self::CLICK_COUNT_META) + 1);
        self::save($order);
    }

    /**
     * A review arrived through the order's signed link.
     */
    public static function record_review(\WC_Order $order): void
    {
        if (!$order->get_meta(self::FIRST_REVIEW_META)) {
            $order->update_meta_data(self::FIRST_REVIEW_META, time());
        }
        $order->update_meta_data(self::REVIEWED_META, (int) $order->get_meta(self::REVIEWED_META) + 1);
        self::save($order);
    }

    /**
     * Only the order's meta: these come from anonymous requests (an image
     * proxy, the review page), and a full save() would fire
     * woocommerce_update_order (webhooks, syncs) and change its modified
     * date each time.
     */
    private static function save(\WC_Order $order): void
    {
        if (class_exists('Product_Reviews_Email')) {
            Product_Reviews_Email::sync_state($order);
        }
        $order->save_meta_data();
    }
}
