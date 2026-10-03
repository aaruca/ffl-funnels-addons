<?php
/**
 * Customer requests — staff side.
 *
 * WooCommerce → Requests: inbox with views, filters, search and bulk actions;
 * a request screen with the full timeline (public and internal), replies,
 * internal notes, status, assignment, return approval, closure with a
 * resolution, reopening, customer links and files. Orders get a metabox and a
 * list column; staff can open a request on a customer's behalf. Also: the
 * setup panel on the module settings page and privacy export / erasure.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Admin
{
    const SLUG = 'ffla-requests';
    const FLASH = 'ffla_req_flash_';

    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu'], 60);
        add_action('admin_post_ffla_req_action', [__CLASS__, 'handle']);
        add_action('admin_post_ffla_req_create', [__CLASS__, 'handle_create']);
        add_action('admin_post_ffla_req_setup_page', [__CLASS__, 'create_page']);
        add_action('admin_post_ffla_req_setup_save', [__CLASS__, 'save_setup']);
        add_action('admin_post_ffla_req_export', [__CLASS__, 'export_csv']);
        add_action('wp_ajax_ffla_req_admin_file', [__CLASS__, 'file']);
        add_action('add_meta_boxes', [__CLASS__, 'metabox'], 30);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        foreach (['edit-shop_order', 'woocommerce_page_wc-orders'] as $screen) {
            add_filter('manage_' . $screen . '_columns', [__CLASS__, 'columns'], 30);
        }
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'column'], 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'column'], 10, 2);
        add_filter('wp_privacy_personal_data_exporters', [__CLASS__, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [__CLASS__, 'erasers']);
    }

    /* ── Menu, assets, routing ─────────────────────────────────────────── */

    public static function menu(): void
    {
        if (!FFLA_Requests::enabled()) {
            return;
        }
        FFLA_Requests::maybe_install_now();
        $count = current_user_can('manage_woocommerce') ? FFLA_Requests::counts()['awaiting'] : 0;
        $label = __('Requests', 'ffl-funnels-addons')
            . ($count ? ' <span class="awaiting-mod count-' . (int) $count . '"><span class="pending-count">' . esc_html(number_format_i18n($count)) . '</span></span>' : '');
        $hook = add_submenu_page('woocommerce', __('Customer requests', 'ffl-funnels-addons'), $label, 'manage_woocommerce', self::SLUG, [__CLASS__, 'page']);
        if ($hook) {
            add_action('load-' . $hook, [__CLASS__, 'load']);
        }
    }

    public static function assets(string $hook = ''): void
    {
        $screen = get_current_screen();
        $id = $screen ? $screen->id : '';
        $ours = false !== strpos($id, self::SLUG) || false !== strpos($id, 'ffla-customer-operations');
        $orders = in_array($id, ['shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders'], true);
        if (!$ours && !($orders && FFLA_Requests::enabled())) {
            return;
        }
        $dir = dirname(__DIR__, 2) . '/assets/';
        $url = FFLA_URL . 'modules/customer-notes/assets/';
        wp_enqueue_style('ffla-requests-admin', $url . 'requests-admin.css', [], (string) @filemtime($dir . 'requests-admin.css')); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        FFLA_Requests_Files::register_assets($url, $dir);
        wp_enqueue_style('ffla-request-files');
        wp_enqueue_script('ffla-requests-admin', $url . 'requests-admin.js', ['ffla-request-files'], (string) @filemtime($dir . 'requests-admin.js'), true); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        wp_localize_script('ffla-requests-admin', 'fflaReqAdmin', FFLA_Requests_Files::client_config() + FFLA_Requests_Files::picker_strings() + [
            'copied'       => __('Copied', 'ffl-funnels-addons'),
            'preparing'    => __('Preparing photos…', 'ffl-funnels-addons'),
            /* translators: %d: number of files the server accepts at once */
            'tooMany'      => __('This server accepts %d files at a time. Send the rest with another reply or note.', 'ffl-funnels-addons'),
            'tooLarge'     => __('These files are too large to send together. Send some of them with another reply or note.', 'ffl-funnels-addons'),
            'fileTooBig'   => __('Each file must be up to 10 MB.', 'ffl-funnels-addons'),
        ]);
    }

    /** Before output: bulk actions and the "add request" order lookup. */
    public static function load(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        if (isset($_GET['new_order'])) {
            check_admin_referer('ffla_req_find_order');
            $order = FFLA_Requests::find_order(sanitize_text_field(wp_unslash($_GET['new_order'])));
            if ($order) {
                wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&new=' . $order->get_id()));
                exit;
            }
            self::flash('error', __('No order found with that number.', 'ffl-funnels-addons'));
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG));
            exit;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ('' === $action || '-1' === $action) {
            $action = isset($_REQUEST['action2']) ? sanitize_key(wp_unslash($_REQUEST['action2'])) : '';
        }
        $ids = isset($_REQUEST['request_ids']) ? array_map('absint', (array) wp_unslash($_REQUEST['request_ids'])) : [];
        // phpcs:enable
        if (!$ids || !in_array($action, ['assign_me', 'unassign', 'in_review'], true)) {
            return;
        }
        check_admin_referer('bulk-requests');

        $actor = self::actor();
        $done = 0;
        foreach (array_slice($ids, 0, 100) as $id) {
            $request = FFLA_Requests::get($id);
            if (!$request) {
                continue;
            }
            try {
                if ('assign_me' === $action) {
                    FFLA_Requests::update_fields($request, ['assignee' => get_current_user_id()], $actor);
                } elseif ('unassign' === $action) {
                    FFLA_Requests::update_fields($request, ['assignee' => 0], $actor);
                } elseif (FFLA_Requests::is_open($request->status)) {
                    FFLA_Requests::set_status($request, 'in_review', $actor, true);
                }
                $done++;
            } catch (Throwable $e) {
                continue;
            }
        }
        /* translators: %d: number of requests */
        self::flash('success', sprintf(_n('%d request updated.', '%d requests updated.', $done, 'ffl-funnels-addons'), $done));
        wp_safe_redirect(remove_query_arg(['action', 'action2', 'request_ids', '_wpnonce', '_wp_http_referer']));
        exit;
    }

    public static function page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification
        if (isset($_GET['request'])) {
            $request = FFLA_Requests::get(absint($_GET['request']));
            if ($request) {
                self::render_request($request);
                return;
            }
            self::flash('error', __('That request no longer exists.', 'ffl-funnels-addons'));
        }
        $screen = isset($_GET['screen']) ? sanitize_key(wp_unslash($_GET['screen'])) : '';
        if ('setup' === $screen) {
            self::render_setup();
            return;
        }
        if ('report' === $screen) {
            require_once __DIR__ . '/class-ffla-requests-report.php';
            FFLA_Requests_Report::render();
            return;
        }
        if (isset($_GET['new'])) {
            $order = wc_get_order(absint($_GET['new']));
            if ($order && 'shop_order' === $order->get_type()) {
                self::render_new($order);
                return;
            }
        }
        // phpcs:enable
        self::render_inbox();
    }

    /* ── Inbox ─────────────────────────────────────────────────────────── */

    private static function render_inbox(): void
    {
        require_once __DIR__ . '/class-ffla-requests-list-table.php';
        $table = new FFLA_Requests_List_Table();
        $table->prepare_items();

        echo '<div class="wrap ffla-req-admin"><h1 class="wp-heading-inline">' . esc_html__('Customer requests', 'ffl-funnels-addons') . '</h1> '
            . '<a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '&screen=report')) . '">' . esc_html__('Report', 'ffl-funnels-addons') . '</a> '
            . '<a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '&screen=setup')) . '">' . esc_html__('Rules & replies', 'ffl-funnels-addons') . '</a> ';
        echo '<details class="ffla-req-add"><summary class="page-title-action">' . esc_html__('Add request', 'ffl-funnels-addons') . '</summary>'
            . '<form method="get" action="' . esc_url(admin_url('admin.php')) . '"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">';
        wp_nonce_field('ffla_req_find_order', '_wpnonce', false);
        echo '<label for="ffla-req-new-order">' . esc_html__('Order number', 'ffl-funnels-addons') . '</label> <input id="ffla-req-new-order" name="new_order" type="text" required> ';
        submit_button(__('Continue', 'ffl-funnels-addons'), 'secondary', '', false);
        echo '<p class="description">' . esc_html__('Open a request for a customer who contacted you by phone or email. You can also use “New request” in the order’s Customer requests box.', 'ffl-funnels-addons') . '</p></form></details>';
        echo '<hr class="wp-header-end">';
        self::notices();
        self::setup_warning();

        // phpcs:ignore WordPress.Security.NonceVerification
        if (!empty($_GET['order_id'])) {
            echo '<p>' . esc_html__('Showing requests for one order.', 'ffl-funnels-addons') . ' <a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '&view=all')) . '">' . esc_html__('Show all', 'ffl-funnels-addons') . '</a></p>';
        }

        $table->views();
        echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '"><input type="hidden" name="view" value="' . esc_attr($table->view) . '">';
        // phpcs:ignore WordPress.Security.NonceVerification
        if (!empty($_GET['order_id'])) {
            echo '<input type="hidden" name="order_id" value="' . absint($_GET['order_id']) . '">'; // phpcs:ignore WordPress.Security.NonceVerification
        }
        $table->search_box(__('Search requests', 'ffl-funnels-addons'), 'ffla-req-search');
        $table->display();
        echo '</form><p class="description">' . esc_html__('Search by request number, order number, customer name or email.', 'ffl-funnels-addons') . '</p></div>';
    }

    /* ── One request ───────────────────────────────────────────────────── */

    private static function render_request($r): void
    {
        $order = wc_get_order((int) $r->order_id);
        $events = FFLA_Requests::events((int) $r->id);
        $files = FFLA_Requests_Files::for_request((int) $r->id);
        $files_by_event = [];
        foreach ($files as $file) {
            $files_by_event[(int) $file->event_id][] = $file;
        }
        $open = FFLA_Requests::is_open($r->status);
        $emails_on = FFLA_Requests_Mail::customer_enabled();
        $reasons = FFLA_Requests::reasons($r->type);

        echo '<div class="wrap ffla-req-admin">';
        echo '<h1 class="wp-heading-inline">' . esc_html(FFLA_Requests::type_label($r->type) . ' ' . $r->number) . '</h1> ' . self::badge($r); // phpcs:ignore WordPress.Security.EscapeOutput
        echo ' <a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('← All requests', 'ffl-funnels-addons') . '</a>';
        echo '<hr class="wp-header-end">';
        self::notices();

        echo '<div class="ffla-req-cols"><div class="ffla-req-main">';

        // Summary.
        echo '<div class="ffla-req-box"><h2>' . esc_html__('Summary', 'ffl-funnels-addons') . '</h2><table class="widefat striped ffla-req-summary"><tbody>';
        $rows = [
            __('Customer', 'ffl-funnels-addons') => esc_html($r->customer_name ?: '—') . ' · <a href="mailto:' . esc_attr($r->customer_email) . '">' . esc_html($r->customer_email) . '</a>'
                . ' · <a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '&view=all&s=' . rawurlencode($r->customer_email))) . '">' . esc_html__('their requests', 'ffl-funnels-addons') . '</a>',
            __('Order', 'ffl-funnels-addons') => '<a href="' . esc_url(self::order_url((int) $r->order_id)) . '">#' . esc_html($r->order_number) . '</a>'
                . ($order ? ' · ' . esc_html(wc_get_order_status_name($order->get_status())) . ' · ' . wp_kses_post($order->get_formatted_order_total())
                    . ($order->get_total_refunded() > 0 ? ' · <strong>' . esc_html__('Refunded:', 'ffl-funnels-addons') . '</strong> ' . wp_kses_post(wc_price($order->get_total_refunded(), ['currency' => $order->get_currency()])) : '')
                    : ' · <em>' . esc_html__('order deleted', 'ffl-funnels-addons') . '</em>'),
            __('Reason', 'ffl-funnels-addons') => esc_html($reasons[$r->reason] ?? $r->reason),
            __('Customer prefers', 'ffl-funnels-addons') => esc_html($r->preferred ? (FFLA_Requests::preferences()[$r->preferred] ?? $r->preferred) : '—'),
            __('Opened', 'ffl-funnels-addons') => esc_html(FFLA_Requests::local_time($r->created_at)) . ' · ' . esc_html(self::source_label($r->source)),
        ];
        if ('' !== (string) $r->return_tracking) {
            $link = FFLA_Requests::tracking_link((string) $r->return_carrier, (string) $r->return_tracking);
            $rows[__('Return shipment', 'ffl-funnels-addons')] = esc_html((FFLA_Requests::carriers()[$r->return_carrier] ?? $r->return_carrier) . ' ' . $r->return_tracking)
                . ($link ? ' · <a href="' . esc_url($link) . '" target="_blank" rel="noopener">' . esc_html__('Track', 'ffl-funnels-addons') . '</a>' : '');
        }
        $ffl = FFLA_Requests::ffl($r);
        if ($ffl) {
            $rows[__('FFL dealer', 'ffl-funnels-addons')] = '<strong>' . esc_html($ffl['name']) . '</strong> · ' . esc_html(FFLA_Requests::format_license((string) $ffl['license']))
                . '<br>' . esc_html(trim(implode(', ', array_filter([$ffl['address'] ?? '', $ffl['city'] ?? '', trim(($ffl['state'] ?? '') . ' ' . ($ffl['postcode'] ?? ''))]))))
                . (!empty($ffl['phone']) ? ' · ' . esc_html($ffl['phone']) : '') . (!empty($ffl['email']) ? ' · ' . esc_html($ffl['email']) : '')
                . ' <span class="description">(' . esc_html('order' === ($ffl['source'] ?? '') ? __('dealer from the order', 'ffl-funnels-addons') : __('entered by the customer', 'ffl-funnels-addons')) . ')</span>';
        }
        if ((float) $r->refund_total > 0) {
            $rows[__('Refunded from this request', 'ffl-funnels-addons')] = wp_kses_post(wc_price((float) $r->refund_total, ['currency' => $order ? $order->get_currency() : get_woocommerce_currency()]));
        }
        if ((int) $r->rating) {
            $rows[__('Customer rating', 'ffl-funnels-addons')] = '<span class="ffla-req-stars" aria-hidden="true">' . esc_html(str_repeat('★', (int) $r->rating) . str_repeat('☆', 5 - (int) $r->rating)) . '</span> '
                . esc_html((int) $r->rating . '/5') . ('' !== (string) $r->rating_comment ? ' — ' . esc_html((string) $r->rating_comment) : '');
        }
        if (!$open) {
            $rows[__('Resolution', 'ffl-funnels-addons')] = '<strong>' . esc_html(FFLA_Requests::resolutions()[$r->resolution] ?? $r->resolution) . '</strong> · ' . esc_html(FFLA_Requests::local_time($r->closed_at));
        }
        foreach ($rows as $label => $html) {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
        }
        $items = FFLA_Requests::items($r);
        if ($items) {
            echo '<tr><th scope="row">' . esc_html('return' === $r->type ? __('Items to return', 'ffl-funnels-addons') : __('Items affected', 'ffl-funnels-addons')) . '</th><td><ul class="ffla-req-items">';
            foreach ($items as $line) {
                echo '<li>' . esc_html($line['name'] . ' × ' . (int) $line['qty']) . (!empty($line['firearm']) ? ' <span class="ffla-req-firearm">' . esc_html__('Firearm — FFL-to-FFL return', 'ffl-funnels-addons') . '</span>' : '')
                    . (!empty($line['fee']) ? ' <span class="ffla-req-type">' . esc_html(sprintf(
                        /* translators: %s: percent */
                        __('Restocking fee %s%%', 'ffl-funnels-addons'),
                        wc_format_decimal($line['fee'], 2, true)
                    )) . '</span>' : '')
                    . (!empty($line['exception']) ? ' <span class="ffla-req-priority ffla-req-priority--high">' . esc_html__('Outside return rules (staff exception)', 'ffl-funnels-addons') . '</span>' : '') . '</li>';
            }
            echo '</ul></td></tr>';
        }
        echo '</tbody></table></div>';

        // Timeline.
        echo '<div class="ffla-req-box"><h2>' . esc_html__('History', 'ffl-funnels-addons') . '</h2><ol class="ffla-req-timeline" data-ffla-gallery>';
        foreach ($events as $event) {
            self::render_event($r, $event, $files_by_event[(int) $event->id] ?? []);
        }
        echo '</ol></div>';

        // Reply / internal note.
        echo '<div class="ffla-req-box ffla-req-compose"><h2>' . esc_html__('Respond', 'ffl-funnels-addons') . '</h2>';
        if (!$open) {
            echo '<p class="description">' . esc_html__('This request is closed. A public reply does not reopen it — use Reopen if work continues.', 'ffl-funnels-addons') . '</p>';
        }
        echo '<div class="ffla-req-tabs" role="tablist">'
            . '<button type="button" role="tab" aria-selected="true" aria-controls="ffla-req-reply" id="ffla-req-tab-reply" class="is-active">' . esc_html__('Reply to customer', 'ffl-funnels-addons') . '</button>'
            . '<button type="button" role="tab" aria-selected="false" aria-controls="ffla-req-note" id="ffla-req-tab-note">' . esc_html__('Internal note', 'ffl-funnels-addons') . '</button></div>';

        echo '<div id="ffla-req-reply" role="tabpanel" aria-labelledby="ffla-req-tab-reply">';
        self::form_open($r, 'reply', true);
        echo '<label class="screen-reader-text" for="ffla-req-reply-text">' . esc_html__('Reply', 'ffl-funnels-addons') . '</label>';
        self::replies_picker('ffla-req-reply-text', $r);
        echo '<textarea id="ffla-req-reply-text" name="message" rows="6" required maxlength="' . (int) FFLA_Requests::MAX_TEXT . '" placeholder="' . esc_attr__('Visible to the customer on their request page.', 'ffl-funnels-addons') . '"></textarea>';
        self::file_field('reply');
        echo '<p><label><input type="checkbox" name="notify" value="1"' . checked($emails_on, true, false) . disabled(!$emails_on, true, false) . '> ' . esc_html__('Email the reply to the customer', 'ffl-funnels-addons') . '</label>'
            . ($emails_on ? '' : ' <span class="description">' . esc_html__('(customer emails are off in settings)', 'ffl-funnels-addons') . '</span>') . '</p>';
        if ($open && 'waiting_customer' !== $r->status) {
            echo '<p><label><input type="checkbox" name="waiting" value="1"> ' . esc_html__('Set status to “Waiting for customer”', 'ffl-funnels-addons') . '</label></p>';
        }
        self::button(__('Send reply', 'ffl-funnels-addons'), 'primary');
        echo '</form></div>';

        echo '<div id="ffla-req-note" role="tabpanel" aria-labelledby="ffla-req-tab-note" hidden>';
        self::form_open($r, 'note', true);
        echo '<label class="screen-reader-text" for="ffla-req-note-text">' . esc_html__('Internal note', 'ffl-funnels-addons') . '</label>';
        echo '<textarea id="ffla-req-note-text" name="message" rows="4" required maxlength="' . (int) FFLA_Requests::MAX_TEXT . '" placeholder="' . esc_attr__('Staff only. Never shown or emailed to the customer.', 'ffl-funnels-addons') . '"></textarea>';
        self::file_field('note');
        self::button(__('Add internal note', 'ffl-funnels-addons'), 'secondary');
        echo '</form></div></div>';

        echo '</div><div class="ffla-req-side">';
        FFLA_Requests_Claim::box($r, $order ?: null, $files);
        self::render_sidebar($r, $order, $emails_on);
        echo '</div></div></div>';
    }

    private static function render_event($r, $event, array $files): void
    {
        static $names = [];
        $meta = json_decode((string) $event->meta, true);
        $meta = is_array($meta) ? $meta : [];

        if ('customer' === $event->actor_type) {
            $who = $r->customer_name ?: __('Customer', 'ffl-funnels-addons');
        } elseif ('staff' === $event->actor_type) {
            if (!isset($names[$event->actor_id])) {
                $user = get_user_by('id', (int) $event->actor_id);
                $names[$event->actor_id] = $user ? $user->display_name : __('Staff', 'ffl-funnels-addons');
            }
            $who = $names[$event->actor_id];
        } else {
            $who = __('System', 'ffl-funnels-addons');
        }

        $statuses = FFLA_Requests::statuses();
        switch ($event->kind) {
            case 'created':
                /* translators: %s: person */
                $title = sprintf(__('%s opened the request', 'ffl-funnels-addons'), $who);
                break;
            case 'message':
                /* translators: %s: person */
                $title = sprintf('customer' === $event->actor_type ? __('%s wrote', 'ffl-funnels-addons') : __('%s replied to the customer', 'ffl-funnels-addons'), $who);
                break;
            case 'note':
                /* translators: %s: person */
                $title = sprintf(__('Internal note by %s', 'ffl-funnels-addons'), $who);
                break;
            case 'status':
                /* translators: 1: person, 2: old status, 3: new status */
                $title = sprintf(__('%1$s changed status: %2$s → %3$s', 'ffl-funnels-addons'), $who, $statuses[$meta['from'] ?? ''][0] ?? '', $statuses[$meta['to'] ?? ''][0] ?? '');
                break;
            case 'resolution':
                /* translators: 1: person, 2: outcome */
                $title = sprintf(__('%1$s closed the request: %2$s', 'ffl-funnels-addons'), $who, FFLA_Requests::resolutions()[$meta['resolution'] ?? ''] ?? '');
                break;
            case 'reopen':
                /* translators: %s: person */
                $title = sprintf(__('%s reopened the request', 'ffl-funnels-addons'), $who);
                break;
            case 'update':
                /* translators: %s: person */
                $title = sprintf(__('%s updated', 'ffl-funnels-addons'), $who) . ': ' . self::describe_changes((array) ($meta['changes'] ?? []));
                break;
            case 'email':
                $title = (string) $event->body;
                break;
            case 'tracking':
                /* translators: %s: person */
                $title = sprintf(__('%s added the return tracking', 'ffl-funnels-addons'), $who);
                break;
            case 'refund':
                /* translators: %s: person */
                $title = sprintf(__('%s issued a refund', 'ffl-funnels-addons'), $who);
                break;
            case 'rating':
                /* translators: %d: stars */
                $title = sprintf(__('Customer rated this request %d/5', 'ffl-funnels-addons'), (int) ($meta['rating'] ?? 0));
                break;
            default:
                $title = $event->kind;
        }

        $public = (int) $event->is_public && 'email' !== $event->kind;
        echo '<li class="ffla-req-event ffla-req-event--' . esc_attr($event->kind) . ' ffla-req-event--' . esc_attr($event->actor_type) . ($public ? '' : ' is-private') . '">';
        echo '<div class="ffla-req-event-head"><strong>' . esc_html($title) . '</strong>'
            . (!$public && 'email' !== $event->kind ? ' <span class="ffla-req-private">' . esc_html__('Internal', 'ffl-funnels-addons') . '</span>' : '')
            . ' <time>' . esc_html(FFLA_Requests::local_time($event->created_at)) . '</time></div>';
        if ('email' !== $event->kind && '' !== trim((string) $event->body)) {
            echo '<div class="ffla-req-event-body">' . nl2br(esc_html((string) $event->body)) . '</div>';
        }
        if ($files) {
            echo '<ul class="ffla-req-files">';
            foreach ($files as $file) {
                $url = add_query_arg([
                    'action'   => 'ffla_req_admin_file',
                    'request'  => (int) $r->id,
                    'file'     => $file->token,
                    '_wpnonce' => wp_create_nonce('ffla_req_file_' . (int) $r->id),
                ], admin_url('admin-ajax.php'));
                $kind = FFLA_Requests_Files::kind_of($file);
                echo '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener"' . ('file' !== $kind ? ' data-ffla-view="' . esc_attr($kind) . '" data-name="' . esc_attr($file->name) . '"' : '') . '>'
                    . ('image' === $kind ? '<img src="' . esc_url($url) . '" alt="" loading="lazy">' : '')
                    . ('video' === $kind ? '<video src="' . esc_url($url) . '#t=0.1" muted preload="metadata" playsinline></video><span class="ffla-req-play" aria-hidden="true">▶</span>' : '')
                    . '<span>' . esc_html($file->name . ' (' . size_format((int) $file->size) . ')') . '</span></a>'
                    . ((int) $file->is_public ? '' : ' <span class="ffla-req-private">' . esc_html__('Internal', 'ffl-funnels-addons') . '</span>') . '</li>';
            }
            echo '</ul>';
        }
        echo '</li>';
    }

    private static function describe_changes(array $changes): string
    {
        $parts = [];
        foreach ($changes as $field => $value) {
            if ('assignee' === $field) {
                $user = $value ? get_user_by('id', (int) $value) : null;
                $parts[] = __('assignee', 'ffl-funnels-addons') . ' → ' . ($user ? $user->display_name : __('nobody', 'ffl-funnels-addons'));
            } elseif ('priority' === $field) {
                $parts[] = __('priority', 'ffl-funnels-addons') . ' → ' . (FFLA_Requests::priorities()[$value] ?? $value);
            } elseif ('due_at' === $field) {
                $parts[] = __('due', 'ffl-funnels-addons') . ' → ' . ($value ? FFLA_Requests::local_time((string) $value, get_option('date_format')) : __('none', 'ffl-funnels-addons'));
            }
        }
        return implode(', ', $parts);
    }

    private static function render_sidebar($r, $order, bool $emails_on): void
    {
        $open = FFLA_Requests::is_open($r->status);
        $notify = static function (bool $default) use ($emails_on): void {
            echo '<p><label><input type="checkbox" name="notify" value="1"' . checked($emails_on && $default, true, false) . disabled(!$emails_on, true, false) . '> '
                . esc_html__('Email the customer', 'ffl-funnels-addons') . '</label></p>';
        };

        if ($open) {
            // Return shortcuts.
            if ('return' === $r->type && in_array($r->status, ['new', 'in_review', 'waiting_customer', 'waiting_carrier'], true)) {
                echo '<div class="ffla-req-box ffla-req-box--accent"><h2>' . esc_html__('Approve return', 'ffl-funnels-addons') . '</h2>';
                self::form_open($r, 'status', true);
                echo '<input type="hidden" name="status" value="approved">';
                echo '<p class="description">' . esc_html__('The customer gets the return instructions from settings:', 'ffl-funnels-addons') . '</p>';
                echo '<blockquote class="ffla-req-quote">' . nl2br(esc_html(FFLA_Requests_Mail::fill((string) FFLA_Requests::setting('requests_return_instructions'), $r))) . '</blockquote>';
                if ((int) $r->has_firearm) {
                    echo '<p class="ffla-req-firearm-note">' . esc_html__('Includes a firearm: arrange an FFL-to-FFL transfer. The firearm notice from settings is added for the customer.', 'ffl-funnels-addons') . '</p>';
                }
                echo '<label for="ffla-req-label">' . esc_html__('Prepaid return label (optional)', 'ffl-funnels-addons') . '</label>'
                    . '<input type="file" id="ffla-req-label" name="label" accept=".pdf,.jpg,.jpeg,.png,.heic,.heif">'
                    . '<span class="description">' . esc_html__('PDF or image. The customer sees it as “Your return label”.', 'ffl-funnels-addons') . '</span>';
                echo '<div class="ffla-req-two"><span><label for="ffla-req-label-carrier">' . esc_html__('Carrier', 'ffl-funnels-addons') . '</label>' . self::carrier_select('ffla-req-label-carrier', '') . '</span>'
                    . '<span><label for="ffla-req-label-tracking">' . esc_html__('Label tracking no. (optional)', 'ffl-funnels-addons') . '</label><input type="text" id="ffla-req-label-tracking" name="tracking" maxlength="60"></span></div>';
                echo '<label for="ffla-req-approve-note">' . esc_html__('Extra note for the customer (optional)', 'ffl-funnels-addons') . '</label><textarea id="ffla-req-approve-note" name="note" rows="3"></textarea>';
                $notify(true);
                self::button(__('Approve return', 'ffl-funnels-addons'), 'primary');
                echo '</form></div>';
            } elseif ('return' === $r->type && 'approved' === $r->status) {
                echo '<div class="ffla-req-box ffla-req-box--accent"><h2>' . esc_html__('Returned item', 'ffl-funnels-addons') . '</h2>';
                self::form_open($r, 'status');
                echo '<input type="hidden" name="status" value="item_received">';
                echo '<label for="ffla-req-received-note">' . esc_html__('Note for the customer (optional)', 'ffl-funnels-addons') . '</label><textarea id="ffla-req-received-note" name="note" rows="2"></textarea>';
                $notify(true);
                self::button(__('Mark item received', 'ffl-funnels-addons'), 'primary');
                echo '</form></div>';
            }

            // Status.
            echo '<div class="ffla-req-box"><h2>' . esc_html__('Status', 'ffl-funnels-addons') . '</h2>';
            self::form_open($r, 'status');
            echo '<label for="ffla-req-status-to" class="screen-reader-text">' . esc_html__('Status', 'ffl-funnels-addons') . '</label><select id="ffla-req-status-to" name="status">';
            foreach (FFLA_Requests::statuses_for($r->type) as $key => $label) {
                echo '<option value="' . esc_attr($key) . '"' . selected($r->status, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select><label for="ffla-req-status-note">' . esc_html__('Message to the customer (optional)', 'ffl-funnels-addons') . '</label><textarea id="ffla-req-status-note" name="note" rows="2"></textarea>';
            $notify(false);
            echo '<p class="description">' . esc_html__('Status changes always appear on the customer’s request page.', 'ffl-funnels-addons') . '</p>';
            self::button(__('Update status', 'ffl-funnels-addons'), 'secondary');
            echo '</form></div>';

            if ($order) {
                self::render_refund($r, $order, $emails_on);
            }
        }

        // Return shipment.
        if ('return' === $r->type) {
            echo '<details class="ffla-req-box"' . ('' === (string) $r->return_tracking && 'approved' === $r->status ? ' open' : '') . '><summary><strong>' . esc_html__('Return shipment', 'ffl-funnels-addons') . '</strong>'
                . ('' !== (string) $r->return_tracking ? ' — ' . esc_html((FFLA_Requests::carriers()[$r->return_carrier] ?? '') . ' ' . $r->return_tracking) : '') . '</summary>';
            self::form_open($r, 'tracking');
            echo '<div class="ffla-req-two"><span><label for="ffla-req-ship-carrier">' . esc_html__('Carrier', 'ffl-funnels-addons') . '</label>' . self::carrier_select('ffla-req-ship-carrier', (string) $r->return_carrier) . '</span>'
                . '<span><label for="ffla-req-ship-tracking">' . esc_html__('Tracking number', 'ffl-funnels-addons') . '</label><input type="text" id="ffla-req-ship-tracking" name="tracking" maxlength="60" value="' . esc_attr((string) $r->return_tracking) . '"></span></div>'
                . '<p class="description">' . esc_html__('Customers add this themselves once the return is approved; staff can add or correct it here. It shows on the customer’s page.', 'ffl-funnels-addons') . '</p>';
            self::button(__('Save tracking', 'ffl-funnels-addons'), 'secondary');
            echo '</form></details>';
        }

        // FFL dealer.
        $ffl = FFLA_Requests::ffl($r);
        if ((int) $r->has_firearm || $ffl) {
            echo '<details class="ffla-req-box"' . (!$ffl ? ' open' : '') . '><summary><strong>' . esc_html__('FFL dealer for the return', 'ffl-funnels-addons') . '</strong>'
                . ($ffl ? ' — ' . esc_html($ffl['name']) : ' — <span class="ffla-req-needs">' . esc_html__('missing', 'ffl-funnels-addons') . '</span>') . '</summary>';
            self::form_open($r, 'ffl');
            $dealer = $order ? FFLA_Requests::order_dealer($order) : null;
            if ($dealer) {
                echo '<p><label><input type="checkbox" name="ffl[source]" value="order"> ' . esc_html(sprintf(
                    /* translators: %s: dealer */
                    __('Use the dealer from the order: %s', 'ffl-funnels-addons'),
                    $dealer['name'] . ' · ' . FFLA_Requests::format_license($dealer['license'])
                )) . '</label></p>';
            }
            foreach (['name' => __('Dealer name', 'ffl-funnels-addons'), 'license' => __('FFL license number', 'ffl-funnels-addons'), 'address' => __('Street address', 'ffl-funnels-addons'), 'city' => __('City', 'ffl-funnels-addons'), 'state' => __('State', 'ffl-funnels-addons'), 'postcode' => __('ZIP code', 'ffl-funnels-addons'), 'phone' => __('Phone', 'ffl-funnels-addons'), 'email' => __('Email', 'ffl-funnels-addons')] as $key => $label) {
                $value = 'license' === $key && !empty($ffl['license']) ? FFLA_Requests::format_license($ffl['license']) : (string) ($ffl[$key] ?? '');
                echo '<label for="ffla-req-ffl-' . esc_attr($key) . '">' . esc_html($label) . '</label><input type="text" id="ffla-req-ffl-' . esc_attr($key) . '" name="ffl[' . esc_attr($key) . ']" value="' . esc_attr($value) . '">';
            }
            self::button(__('Save dealer', 'ffl-funnels-addons'), 'secondary');
            echo '</form></details>';
        }

        // Assignment.
        echo '<div class="ffla-req-box"><h2>' . esc_html__('Assignment', 'ffl-funnels-addons') . '</h2>';
        self::form_open($r, 'fields');
        echo '<label for="ffla-req-assignee">' . esc_html__('Assignee', 'ffl-funnels-addons') . '</label><select id="ffla-req-assignee" name="assignee"><option value="0">' . esc_html__('— Nobody —', 'ffl-funnels-addons') . '</option>';
        foreach (get_users(['capability' => 'manage_woocommerce', 'fields' => ['ID', 'display_name'], 'number' => 100, 'orderby' => 'display_name']) as $user) {
            echo '<option value="' . (int) $user->ID . '"' . selected((int) $r->assignee, (int) $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
        }
        echo '</select><label for="ffla-req-priority">' . esc_html__('Priority', 'ffl-funnels-addons') . '</label><select id="ffla-req-priority" name="priority">';
        foreach (FFLA_Requests::priorities() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($r->priority, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select><label for="ffla-req-due">' . esc_html__('Due date', 'ffl-funnels-addons') . '</label>'
            . '<input type="date" id="ffla-req-due" name="due" value="' . esc_attr($r->due_at ? get_date_from_gmt($r->due_at, 'Y-m-d') : '') . '">';
        self::button(__('Save', 'ffl-funnels-addons'), 'secondary');
        if ((int) $r->assignee !== get_current_user_id()) {
            echo ' <button type="submit" name="do" value="assign_me" class="button-link">' . esc_html__('Assign to me', 'ffl-funnels-addons') . '</button>';
        }
        echo '</form></div>';

        // Close / reopen.
        if ($open) {
            echo '<div class="ffla-req-box"><h2>' . esc_html__('Close with resolution', 'ffl-funnels-addons') . '</h2>';
            self::form_open($r, 'close');
            echo '<label for="ffla-req-resolution">' . esc_html__('Outcome', 'ffl-funnels-addons') . '</label><select id="ffla-req-resolution" name="resolution" required><option value="">' . esc_html__('— Choose —', 'ffl-funnels-addons') . '</option>';
            foreach (FFLA_Requests::resolutions() as $key => $label) {
                if ('withdrawn' !== $key) {
                    echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
                }
            }
            echo '</select>';
            if (class_exists('FFLA_Store_Credit')) {
                echo '<div class="ffla-req-credit" data-ffla-credit hidden><label for="ffla-req-credit-amount">' . esc_html__('Store credit amount', 'ffl-funnels-addons') . '</label>'
                    . '<input type="number" id="ffla-req-credit-amount" name="credit_amount" min="0" step="0.01">'
                    . '<span class="description">' . esc_html__('Creates a store credit code for this customer (Smart Coupons) and adds it to the resolution note.', 'ffl-funnels-addons') . '</span></div>';
            }
            echo '<label for="ffla-req-resolution-note">' . esc_html__('Resolution note for the customer', 'ffl-funnels-addons') . '</label>';
            self::replies_picker('ffla-req-resolution-note', $r);
            echo ''
                . '<textarea id="ffla-req-resolution-note" name="note" rows="3" required placeholder="' . esc_attr__('What was done, e.g. refund amount and when it appears, or replacement tracking number.', 'ffl-funnels-addons') . '"></textarea>';
            if ($order && $order->get_total_refunded() > 0) {
                echo '<p class="description">' . esc_html(sprintf(
                    /* translators: %s: amount */
                    __('Refunded on this order so far: %s', 'ffl-funnels-addons'),
                    html_entity_decode(wp_strip_all_tags(wc_price($order->get_total_refunded(), ['currency' => $order->get_currency()])), ENT_QUOTES, 'UTF-8')
                )) . '</p>';
            }
            echo '<p class="description">' . esc_html__('Closing does not refund or ship anything — do that in the order first.', 'ffl-funnels-addons') . '</p>';
            $notify(true);
            self::button(__('Close request', 'ffl-funnels-addons'), 'primary');
            echo '</form></div>';
        } else {
            echo '<div class="ffla-req-box"><h2>' . esc_html__('Reopen', 'ffl-funnels-addons') . '</h2>';
            if ('' !== trim((string) $r->resolution_note)) {
                echo '<blockquote class="ffla-req-quote">' . nl2br(esc_html((string) $r->resolution_note)) . '</blockquote>';
            }
            self::form_open($r, 'reopen');
            echo '<label for="ffla-req-reopen-note">' . esc_html__('Message to the customer (optional)', 'ffl-funnels-addons') . '</label><textarea id="ffla-req-reopen-note" name="note" rows="2"></textarea>';
            $notify(false);
            self::button(__('Reopen request', 'ffl-funnels-addons'), 'secondary');
            echo '</form></div>';
        }

        // Customer link.
        $link = FFLA_Requests::tracking_url($r);
        echo '<div class="ffla-req-box"><h2>' . esc_html__('Customer link', 'ffl-funnels-addons') . '</h2>'
            . '<p class="description">' . esc_html__('Lets the customer follow and reply without signing in. Share it only with the customer.', 'ffl-funnels-addons') . '</p>'
            . '<p class="ffla-req-copy"><input type="text" readonly value="' . esc_attr($link) . '" aria-label="' . esc_attr__('Customer link', 'ffl-funnels-addons') . '"> <button type="button" class="button" data-ffla-copy>' . esc_html__('Copy', 'ffl-funnels-addons') . '</button></p>';
        self::form_open($r, 'resend');
        self::button(__('Email a new link', 'ffl-funnels-addons'), 'secondary', !$emails_on);
        echo ' <button type="submit" name="do" value="rotate" class="button-link">' . esc_html__('Disable current link', 'ffl-funnels-addons') . '</button>';
        echo '<p class="description">' . esc_html__('Both replace the link; earlier links stop working.', 'ffl-funnels-addons') . '</p></form></div>';

        // Delete.
        echo '<details class="ffla-req-box ffla-req-danger"><summary>' . esc_html__('Delete request', 'ffl-funnels-addons') . '</summary>';
        self::form_open($r, 'delete');
        echo '<p class="description">' . esc_html__('Permanently deletes the request, its history and files. Use for spam or test data; close real requests instead.', 'ffl-funnels-addons') . '</p>'
            . '<p><label><input type="checkbox" name="confirm_delete" value="1" required> ' . esc_html__('I understand this cannot be undone', 'ffl-funnels-addons') . '</label></p>';
        self::button(__('Delete permanently', 'ffl-funnels-addons'), 'delete');
        echo '</form></details>';
    }

    /* ── Staff-created request ─────────────────────────────────────────── */

    private static function render_new($order): void
    {
        $types = FFLA_Requests::types_enabled() ?: ['issue', 'return'];
        $items = FFLA_Requests::returnable_items($order);
        $emails_on = FFLA_Requests_Mail::customer_enabled();

        echo '<div class="wrap ffla-req-admin"><h1>' . esc_html(sprintf(
            /* translators: %s: order number */
            __('New request for order #%s', 'ffl-funnels-addons'),
            $order->get_order_number()
        )) . '</h1>';
        self::notices();
        echo '<p>' . esc_html(trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . ' · ' . $order->get_billing_email() . ' · ' . wc_get_order_status_name($order->get_status()))
            . ' · <a href="' . esc_url(self::order_url((int) $order->get_id())) . '">' . esc_html__('Edit order', 'ffl-funnels-addons') . '</a></p>';

        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="ffla-req-box ffla-req-new">'
            . '<input type="hidden" name="action" value="ffla_req_create"><input type="hidden" name="order_id" value="' . (int) $order->get_id() . '">';
        wp_nonce_field('ffla_req_create_' . $order->get_id());

        echo '<fieldset><legend>' . esc_html__('Type', 'ffl-funnels-addons') . '</legend>';
        foreach ($types as $i => $type) {
            echo '<label class="ffla-req-inline"><input type="radio" name="type" value="' . esc_attr($type) . '"' . checked(0 === $i, true, false) . ' data-ffla-type> ' . esc_html(FFLA_Requests::type_label($type)) . '</label>';
        }
        echo '</fieldset>';

        foreach ($types as $i => $type) {
            echo '<p class="ffla-req-field" data-ffla-for="' . esc_attr($type) . '"' . (0 === $i ? '' : ' hidden') . '><label for="ffla-req-reason-' . esc_attr($type) . '">'
                . esc_html(sprintf(
                    /* translators: %s: request type */
                    __('Reason (%s)', 'ffl-funnels-addons'),
                    strtolower(FFLA_Requests::type_label($type))
                )) . '</label><select id="ffla-req-reason-' . esc_attr($type) . '" name="reason_' . esc_attr($type) . '">';
            foreach (FFLA_Requests::reasons($type) as $key => $label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></p>';
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Item', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Ordered', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Returnable', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Quantity', 'ffl-funnels-addons') . '</th></tr></thead><tbody>';
        foreach ($items as $line) {
            echo '<tr><td>' . esc_html($line['name']) . ($line['firearm'] ? ' <span class="ffla-req-firearm">' . esc_html__('Firearm', 'ffl-funnels-addons') . '</span>' : '') . '</td><td>' . (int) $line['ordered'] . '</td><td>' . (int) $line['available'] . '</td>'
                . '<td><input type="number" min="0" max="' . (int) $line['ordered'] . '" step="1" value="0" name="items[' . (int) $line['item_id'] . ']" aria-label="' . esc_attr(sprintf(
                    /* translators: %s: product name */
                    __('Quantity of %s', 'ffl-funnels-addons'),
                    $line['name']
                )) . '"></td></tr>';
        }
        echo '</tbody></table><p class="description">' . esc_html__('Returns need at least one item and cannot exceed the returnable quantity.', 'ffl-funnels-addons') . '</p>';

        echo '<p class="ffla-req-field"><label for="ffla-req-preferred">' . esc_html__('Customer prefers', 'ffl-funnels-addons') . '</label><select id="ffla-req-preferred" name="preferred"><option value="">—</option>';
        foreach (FFLA_Requests::preferences() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></p>';
        echo '<p class="ffla-req-field"><label for="ffla-req-new-message">' . esc_html__('Description (visible to the customer)', 'ffl-funnels-addons') . '</label><textarea id="ffla-req-new-message" name="message" rows="5" required maxlength="' . (int) FFLA_Requests::MAX_TEXT . '"></textarea></p>';
        self::file_field('new');
        echo '<p><label><input type="checkbox" name="files_public" value="1"> ' . esc_html__('Let the customer see these files', 'ffl-funnels-addons') . '</label></p>';
        echo '<p><label><input type="checkbox" name="notify" value="1"' . checked($emails_on, true, false) . disabled(!$emails_on, true, false) . '> ' . esc_html__('Email the customer a confirmation with their tracking link', 'ffl-funnels-addons') . '</label></p>';
        submit_button(__('Create request', 'ffl-funnels-addons'));
        echo '</form></div>';
    }

    public static function handle_create(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        $order_id = absint($_POST['order_id'] ?? 0);
        check_admin_referer('ffla_req_create_' . $order_id);
        $order = wc_get_order($order_id);
        if (!$order || !current_user_can('edit_shop_order', $order_id)) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }

        $type = sanitize_key(wp_unslash($_POST['type'] ?? ''));
        $data = [
            'type'      => $type,
            'reason'    => sanitize_key(wp_unslash($_POST['reason_' . $type] ?? '')),
            'preferred' => sanitize_key(wp_unslash($_POST['preferred'] ?? '')),
            'message'   => wp_unslash($_POST['message'] ?? ''), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned in create().
            'items'     => array_map('absint', (array) wp_unslash($_POST['items'] ?? [])),
        ];
        $actor = self::actor();
        try {
            $prepared = FFLA_Requests_Files::prepare(FFLA_Requests_Files::from_request('files'), null);
            $request = FFLA_Requests::create($order, $data, 'staff', $actor);
            if ($prepared) {
                $events = FFLA_Requests::events((int) $request->id);
                FFLA_Requests_Files::store($request, $prepared, $actor, !empty($_POST['files_public']), $events ? (int) $events[0]->id : 0);
            }
            if (!empty($_POST['notify'])) {
                FFLA_Requests_Mail::customer_received($request);
            }
            /* translators: %s: request number */
            self::flash('success', sprintf(__('Request %s created.', 'ffl-funnels-addons'), $request->number));
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&request=' . (int) $request->id));
        } catch (InvalidArgumentException $e) {
            self::flash('error', $e->getMessage());
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&new=' . $order_id));
        } catch (Throwable $e) {
            self::flash('error', __('The request could not be saved.', 'ffl-funnels-addons'));
            wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&new=' . $order_id));
        }
        exit;
    }

    /* ── Actions on one request ────────────────────────────────────────── */

    public static function handle(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        $id = absint($_POST['request'] ?? 0);
        check_admin_referer('ffla_req_' . $id);
        $r = FFLA_Requests::get($id);
        if (!$r) {
            wp_die(esc_html__('That request no longer exists.', 'ffl-funnels-addons'), '', ['response' => 404]);
        }
        $order = wc_get_order((int) $r->order_id);
        if ($order && !current_user_can('edit_shop_order', $order->get_id())) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }

        $actor = self::actor();
        $do = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        $back = admin_url('admin.php?page=' . self::SLUG . '&request=' . $id);
        $notify = !empty($_POST['notify']);
        $text = FFLA_Requests::clean_text(wp_unslash($_POST['message'] ?? $_POST['note'] ?? '')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        try {
            switch ($do) {
                case 'reply':
                case 'note':
                    $public = 'reply' === $do;
                    $prepared = FFLA_Requests_Files::prepare(FFLA_Requests_Files::from_request('files'), null);
                    $event_id = FFLA_Requests::add_message($r, $actor, $text, $public);
                    if ($prepared) {
                        FFLA_Requests_Files::store($r, $prepared, $actor, $public, $event_id);
                    }
                    if ($public && !empty($_POST['waiting'])) {
                        $r = FFLA_Requests::get($id);
                        if (FFLA_Requests::is_open($r->status) && 'waiting_customer' !== $r->status) {
                            FFLA_Requests::set_status($r, 'waiting_customer', $actor, true);
                        }
                    }
                    if ($public && $notify) {
                        FFLA_Requests_Mail::customer_message(FFLA_Requests::get($id), $text);
                    }
                    self::flash('success', $public ? __('Reply added.', 'ffl-funnels-addons') : __('Internal note added.', 'ffl-funnels-addons'));
                    break;

                case 'status':
                    $to = sanitize_key(wp_unslash($_POST['status'] ?? ''));
                    $label = 'approved' === $to ? array_slice(FFLA_Requests_Files::from_request('label'), 0, 1) : [];
                    $prepared = $label ? FFLA_Requests_Files::prepare($label, null) : [];
                    $event_id = FFLA_Requests::set_status($r, $to, $actor, true, $text);
                    if ($prepared && $event_id) {
                        FFLA_Requests_Files::store($r, $prepared, $actor, true, $event_id, 'label');
                    }
                    $tracking = sanitize_text_field(wp_unslash($_POST['tracking'] ?? ''));
                    if ('approved' === $to && '' !== $tracking) {
                        FFLA_Requests::set_return_tracking(FFLA_Requests::get($id), sanitize_key(wp_unslash($_POST['carrier'] ?? '')), $tracking, $actor);
                    }
                    if ($notify) {
                        FFLA_Requests_Mail::customer_status(FFLA_Requests::get($id), $text);
                    }
                    self::flash('success', __('Status updated.', 'ffl-funnels-addons'));
                    break;

                case 'fields':
                case 'assign_me':
                    if ('assign_me' === $do) {
                        $fields = ['assignee' => get_current_user_id()];
                    } else {
                        $due = sanitize_text_field(wp_unslash($_POST['due'] ?? ''));
                        $fields = [
                            'assignee' => absint($_POST['assignee'] ?? 0),
                            'priority' => sanitize_key(wp_unslash($_POST['priority'] ?? 'normal')),
                            'due_at'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? get_gmt_from_date($due . ' 23:59:59') : '',
                        ];
                    }
                    FFLA_Requests::update_fields($r, $fields, $actor);
                    self::flash('success', __('Saved.', 'ffl-funnels-addons'));
                    break;

                case 'close':
                    $resolution = sanitize_key(wp_unslash($_POST['resolution'] ?? ''));
                    $credit_amount = (float) wp_unslash($_POST['credit_amount'] ?? 0);
                    if ('store_credit' === $resolution && $credit_amount > 0 && class_exists('FFLA_Store_Credit')) {
                        if (!FFLA_Requests::is_open($r->status)) {
                            throw new InvalidArgumentException(__('This request is already closed.', 'ffl-funnels-addons'));
                        }
                        $credit = FFLA_Store_Credit::from_request($r, $credit_amount);
                        $text = trim($text . "\n\n" . FFLA_Store_Credit::describe($credit));
                        FFLA_Requests::add_event($r, 'note', $actor, false, sprintf('Store credit %s issued (%s).', strtoupper($credit->get_code()), FFLA_Store_Credit::money($credit_amount)));
                    }
                    FFLA_Requests::close($r, $resolution, $text, $actor);
                    if ($notify) {
                        FFLA_Requests_Mail::customer_closed(FFLA_Requests::get($id));
                    }
                    self::flash('success', __('Request closed.', 'ffl-funnels-addons'));
                    break;

                case 'reopen':
                    FFLA_Requests::reopen($r, $actor, true);
                    if ('' !== $text) {
                        FFLA_Requests::add_message(FFLA_Requests::get($id), $actor, $text, true);
                    }
                    if ($notify) {
                        FFLA_Requests_Mail::customer_status(FFLA_Requests::get($id), $text);
                    }
                    self::flash('success', __('Request reopened.', 'ffl-funnels-addons'));
                    break;

                case 'resend':
                    if (!FFLA_Requests_Mail::customer_enabled()) {
                        throw new InvalidArgumentException(__('Customer emails are off in settings. Copy the link instead.', 'ffl-funnels-addons'));
                    }
                    $sent = FFLA_Requests_Mail::customer_link(FFLA_Requests::rotate_key($r));
                    FFLA_Requests::add_event($r, 'note', $actor, false, __('Customer link replaced and emailed.', 'ffl-funnels-addons'));
                    self::flash($sent ? 'success' : 'error', $sent ? __('A new link was emailed. Earlier links no longer work.', 'ffl-funnels-addons') : __('The link was replaced but the email could not be sent. Copy the new link instead.', 'ffl-funnels-addons'));
                    break;

                case 'rotate':
                    FFLA_Requests::rotate_key($r);
                    FFLA_Requests::add_event($r, 'note', $actor, false, __('Customer link disabled and replaced.', 'ffl-funnels-addons'));
                    self::flash('success', __('The old link no longer works. Copy the new one to share it.', 'ffl-funnels-addons'));
                    break;

                case 'tracking':
                    FFLA_Requests::set_return_tracking($r, sanitize_key(wp_unslash($_POST['carrier'] ?? '')), sanitize_text_field(wp_unslash($_POST['tracking'] ?? '')), $actor);
                    self::flash('success', __('Return tracking saved.', 'ffl-funnels-addons'));
                    break;

                case 'ffl':
                    $input = isset($_POST['ffl']) && is_array($_POST['ffl']) ? array_map(static function ($v) { return is_scalar($v) ? (string) $v : ''; }, (array) wp_unslash($_POST['ffl'])) : [];
                    $ffl = FFLA_Requests::clean_ffl($input, $order);
                    if (!$ffl) {
                        throw new InvalidArgumentException(__('Enter the dealer name, a valid 15-character FFL license number, city and state.', 'ffl-funnels-addons'));
                    }
                    FFLA_Requests::set_ffl($r, $ffl, $actor);
                    self::flash('success', __('FFL dealer saved.', 'ffl-funnels-addons'));
                    break;

                case 'refund':
                    if (!$order) {
                        throw new InvalidArgumentException(__('The order no longer exists.', 'ffl-funnels-addons'));
                    }
                    if (empty($_POST['confirm_refund'])) {
                        throw new InvalidArgumentException(__('Tick the confirmation box to issue the refund.', 'ffl-funnels-addons'));
                    }
                    $intent = sanitize_text_field(wp_unslash($_POST['intent'] ?? ''));
                    if (FFLA_Requests_Refunds::already_done($r, $intent)) {
                        self::flash('success', __('This refund was already issued.', 'ffl-funnels-addons'));
                        break;
                    }
                    $result = FFLA_Requests_Refunds::issue($r, $order, [
                        'qty'     => array_map('absint', (array) wp_unslash($_POST['qty'] ?? [])),
                        'fee'     => (float) wp_unslash($_POST['fee'] ?? 0),
                        'extra'   => (float) wp_unslash($_POST['extra'] ?? 0),
                        'restock' => !empty($_POST['restock']),
                        'method'  => sanitize_key(wp_unslash($_POST['method'] ?? 'manual')),
                        'intent'  => $intent,
                    ], $actor);
                    $fresh = FFLA_Requests::get($id);
                    $message = trim($result['text'] . ('' !== $text ? "\n\n" . $text : ''));
                    if (!empty($_POST['close']) && FFLA_Requests::is_open($fresh->status)) {
                        FFLA_Requests::close($fresh, $result['resolution'], $message, $actor);
                        if ($notify) {
                            FFLA_Requests_Mail::customer_closed(FFLA_Requests::get($id));
                        }
                    } elseif ($notify) {
                        FFLA_Requests_Mail::customer_message(FFLA_Requests::get($id), $message);
                    }
                    self::flash('success', sprintf(
                        /* translators: %s: amount */
                        __('Refund of %s issued.', 'ffl-funnels-addons'),
                        FFLA_Requests_Refunds::money($result['amount'], $order)
                    ));
                    break;

                case 'delete':
                    if (empty($_POST['confirm_delete'])) {
                        throw new InvalidArgumentException(__('Tick the confirmation box to delete.', 'ffl-funnels-addons'));
                    }
                    FFLA_Requests::delete($r);
                    /* translators: %s: request number */
                    self::flash('success', sprintf(__('Request %s deleted.', 'ffl-funnels-addons'), $r->number));
                    $back = admin_url('admin.php?page=' . self::SLUG);
                    break;

                default:
                    throw new InvalidArgumentException(__('Unknown action.', 'ffl-funnels-addons'));
            }
        } catch (InvalidArgumentException $e) {
            self::flash('error', $e->getMessage());
        } catch (RuntimeException $e) {
            self::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            self::flash('error', __('The change could not be saved.', 'ffl-funnels-addons'));
        }

        wp_safe_redirect($back);
        exit;
    }

    public static function file(): void
    {
        $id = absint($_GET['request'] ?? 0); // phpcs:ignore WordPress.Security.NonceVerification
        $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
        if (!current_user_can('manage_woocommerce') || !wp_verify_nonce($nonce, 'ffla_req_file_' . $id)) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        $request = FFLA_Requests::get($id);
        if (!$request) {
            wp_die(esc_html__('Not found.', 'ffl-funnels-addons'), '', ['response' => 404]);
        }
        FFLA_Requests_Files::stream($request, (string) wp_unslash($_GET['file'] ?? ''), false); // phpcs:ignore WordPress.Security.NonceVerification
    }

    /* ── Orders: metabox and list column ───────────────────────────────── */

    public static function metabox(): void
    {
        if (!FFLA_Requests::enabled() || !current_user_can('manage_woocommerce')) {
            return;
        }
        foreach (array_unique(['shop_order', function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order']) as $screen) {
            add_meta_box('ffla_order_requests', __('Customer requests', 'ffl-funnels-addons'), [__CLASS__, 'render_metabox'], $screen, 'side', 'default');
        }
    }

    public static function render_metabox($object): void
    {
        $order = $object instanceof WC_Order ? $object : wc_get_order($object->ID ?? 0);
        if (!$order instanceof WC_Order) {
            return;
        }
        FFLA_Requests::maybe_install_now();
        $rows = array_reverse(FFLA_Requests::for_order($order->get_id()));
        if (!$rows) {
            echo '<p class="description">' . esc_html__('No customer requests on this order.', 'ffl-funnels-addons') . '</p>';
        } else {
            echo '<ul class="ffla-req-metabox">';
            foreach ($rows as $r) {
                echo '<li><a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '&request=' . (int) $r->id)) . '"><strong>' . esc_html($r->number) . '</strong></a> '
                    . esc_html(FFLA_Requests::type_label($r->type)) . '<br>' . self::badge($r) // phpcs:ignore WordPress.Security.EscapeOutput
                    . ' <span class="description">' . esc_html(FFLA_Requests::local_time($r->updated_at, get_option('date_format'))) . '</span></li>';
            }
            echo '</ul>';
        }
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '&new=' . (int) $order->get_id())) . '">' . esc_html__('New request', 'ffl-funnels-addons') . '</a></p>';
    }

    public static function columns($columns)
    {
        if (!FFLA_Requests::enabled() || !is_array($columns)) {
            return $columns;
        }
        $out = [];
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ('order_status' === $key) {
                $out['ffla_requests'] = __('Requests', 'ffl-funnels-addons');
            }
        }
        if (!isset($out['ffla_requests'])) {
            $out['ffla_requests'] = __('Requests', 'ffl-funnels-addons');
        }
        return $out;
    }

    public static function column($column, $order_or_id): void
    {
        if ('ffla_requests' !== $column || !FFLA_Requests::enabled()) {
            return;
        }
        $order_id = $order_or_id instanceof WC_Order ? $order_or_id->get_id() : (int) $order_or_id;
        $counts = FFLA_Requests::order_counts($order_id);
        if (!$counts['total']) {
            echo '<span class="description">—</span>';
            return;
        }
        $url = admin_url('admin.php?page=' . self::SLUG . '&view=all&order_id=' . $order_id);
        echo '<a href="' . esc_url($url) . '" class="ffla-req-count' . ($counts['open'] ? ' is-open' : '') . '">'
            . esc_html($counts['open']
                /* translators: %d: number of open requests */
                ? sprintf(_n('%d open', '%d open', $counts['open'], 'ffl-funnels-addons'), $counts['open'])
                /* translators: %d: number of closed requests */
                : sprintf(_n('%d closed', '%d closed', $counts['total'], 'ffl-funnels-addons'), $counts['total']))
            . '</a>';
    }

    /* ── Setup panel on the settings page ──────────────────────────────── */

    /** @param bool $embedded Inside the settings screen's own panel (no outer box or heading). */
    public static function setup_panel(bool $embedded = false): void
    {
        $close = $embedded ? '</div>' : '</section>';
        echo $embedded ? '<div class="ffla-req-setup">' : '<section class="ffla-ops-section ffla-req-setup"><h2>' . esc_html__('Customer requests setup', 'ffl-funnels-addons') . '</h2>';
        if (!FFLA_Requests::enabled()) {
            echo '<p>' . esc_html__('Turn on “Customer requests (issues & returns)” in Customer requests and save. Then place the form on your site.', 'ffl-funnels-addons') . '</p>' . $close; // phpcs:ignore WordPress.Security.EscapeOutput
            return;
        }

        echo '<p>' . wp_kses(sprintf(
            /* translators: %s: shortcode */
            __('Place %s on any page — for example your home page. Optional: <code>type="issue"</code> or <code>type="return"</code> to show one type, <code>title="Need help?"</code> for a heading. Signed-in customers also get it in My Account → View order.', 'ffl-funnels-addons'),
            '<code>[ffla_order_requests]</code>'
        ), ['code' => []]) . '</p>';

        $page_id = absint(FFLA_Requests::setting('requests_page'));
        $front = 'page' === get_option('show_on_front') ? absint(get_option('page_on_front')) : 0;
        $target = $page_id ?: $front;
        $found = $target && self::page_has_shortcode($target);

        if ($page_id) {
            $status = get_post_status($page_id);
            echo '<p>' . esc_html__('Tracking links point to:', 'ffl-funnels-addons') . ' <a href="' . esc_url(get_permalink($page_id)) . '" target="_blank" rel="noopener">' . esc_html(get_the_title($page_id)) . '</a>'
                . ('publish' !== $status ? ' <strong>' . esc_html__('(not published)', 'ffl-funnels-addons') . '</strong>' : '') . '</p>';
        } else {
            echo '<p>' . esc_html__('Tracking links point to your home page.', 'ffl-funnels-addons') . '</p>';
        }
        if ($found) {
            echo '<p class="ffla-req-ok">' . esc_html__('The form was found on that page.', 'ffl-funnels-addons') . '</p>';
        } else {
            echo '<p class="ffla-ops-notice">' . esc_html__('The shortcode was not detected on that page. If you use a page builder, add it with the builder’s Shortcode element and check the page; otherwise create a dedicated page below.', 'ffl-funnels-addons') . '</p>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ffla_req_setup_page">';
        wp_nonce_field('ffla_req_setup_page');
        self::button(__('Create an “Order Help” page with the form', 'ffl-funnels-addons'), 'secondary');
        echo ' <a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Open the requests inbox', 'ffl-funnels-addons') . '</a></form>' . $close; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    private static function page_has_shortcode(int $page_id): bool
    {
        $post = get_post($page_id);
        if (!$post) {
            return false;
        }
        if (has_shortcode((string) $post->post_content, 'ffla_order_requests')) {
            return true;
        }
        foreach (['_bricks_page_content_2', '_elementor_data'] as $meta) {
            $value = get_post_meta($page_id, $meta, true);
            if ($value && false !== strpos(is_string($value) ? $value : (string) wp_json_encode($value), 'ffla_order_requests')) {
                return true;
            }
        }
        return false;
    }

    public static function create_page(): void
    {
        if (!current_user_can('manage_woocommerce') || !current_user_can('publish_pages')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        check_admin_referer('ffla_req_setup_page');
        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __('Order Help', 'ffl-funnels-addons'),
            'post_content' => "<!-- wp:shortcode -->\n[ffla_order_requests]\n<!-- /wp:shortcode -->",
        ], true);
        if (!is_wp_error($id)) {
            $settings = get_option(FFLA_Customer_Operations_Settings::OPTION, []);
            $settings = is_array($settings) ? $settings : [];
            $settings['requests_page'] = (int) $id;
            update_option(FFLA_Customer_Operations_Settings::OPTION, $settings, false);
        }
        wp_safe_redirect(admin_url('admin.php?page=ffla-customer-operations&saved=1'));
        exit;
    }

    private static function setup_warning(): void
    {
        $page_id = absint(FFLA_Requests::setting('requests_page'));
        $front = 'page' === get_option('show_on_front') ? absint(get_option('page_on_front')) : 0;
        $target = $page_id ?: $front;
        if (!$target || !self::page_has_shortcode($target)) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Customers need the request form on your site.', 'ffl-funnels-addons')
                . ' <a href="' . esc_url(admin_url('admin.php?page=ffla-customer-operations')) . '">' . esc_html__('Finish setup', 'ffl-funnels-addons') . '</a></p></div>';
        }
    }

    /* ── Privacy ───────────────────────────────────────────────────────── */

    public static function exporters($exporters)
    {
        $exporters['ffla-requests'] = ['exporter_friendly_name' => __('Customer requests', 'ffl-funnels-addons'), 'callback' => [__CLASS__, 'export']];
        return $exporters;
    }

    public static function erasers($erasers)
    {
        $erasers['ffla-requests'] = ['eraser_friendly_name' => __('Customer requests', 'ffl-funnels-addons'), 'callback' => [__CLASS__, 'erase']];
        return $erasers;
    }

    public static function export($email, $page = 1): array
    {
        global $wpdb;
        if (get_option(FFLA_Requests::DB_OPTION) !== FFLA_Requests::DB_VERSION) {
            return ['data' => [], 'done' => true];
        }
        $t = FFLA_Requests::tables();
        $page = max(1, (int) $page);
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE customer_email = %s ORDER BY id ASC LIMIT 20 OFFSET %d", strtolower(trim((string) $email)), ($page - 1) * 20)); // phpcs:ignore

        $data = [];
        foreach ($rows as $r) {
            $messages = [];
            foreach (FFLA_Requests::events((int) $r->id, true) as $event) {
                if ('' !== trim((string) $event->body)) {
                    $messages[] = FFLA_Requests::local_time($event->created_at) . ' — ' . ('customer' === $event->actor_type ? __('Customer', 'ffl-funnels-addons') : __('Store', 'ffl-funnels-addons')) . ': ' . $event->body;
                }
            }
            $data[] = [
                'group_id'    => 'ffla-requests',
                'group_label' => __('Customer requests', 'ffl-funnels-addons'),
                'item_id'     => 'ffla-request-' . (int) $r->id,
                'data'        => [
                    ['name' => __('Request', 'ffl-funnels-addons'), 'value' => $r->number],
                    ['name' => __('Order', 'ffl-funnels-addons'), 'value' => $r->order_number],
                    ['name' => __('Type', 'ffl-funnels-addons'), 'value' => FFLA_Requests::type_label($r->type)],
                    ['name' => __('Status', 'ffl-funnels-addons'), 'value' => FFLA_Requests::status_label($r->status, 'customer')],
                    ['name' => __('Name', 'ffl-funnels-addons'), 'value' => $r->customer_name],
                    ['name' => __('Opened', 'ffl-funnels-addons'), 'value' => FFLA_Requests::local_time($r->created_at)],
                    ['name' => __('Resolution', 'ffl-funnels-addons'), 'value' => (FFLA_Requests::resolutions()[$r->resolution] ?? '') . ($r->resolution_note ? ' — ' . $r->resolution_note : '')],
                    ['name' => __('Messages', 'ffl-funnels-addons'), 'value' => implode("\n\n", $messages)],
                ],
            ];
        }
        return ['data' => $data, 'done' => count($rows) < 20];
    }

    /**
     * Closed requests are anonymized (name, email, customer messages and
     * customer files removed); open requests are kept so they can be finished.
     */
    public static function erase($email, $page = 1): array
    {
        global $wpdb;
        $out = ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        if (get_option(FFLA_Requests::DB_OPTION) !== FFLA_Requests::DB_VERSION) {
            return $out;
        }
        $t = FFLA_Requests::tables();
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE customer_email = %s LIMIT 500", strtolower(trim((string) $email)))); // phpcs:ignore
        foreach ($rows as $r) {
            if (FFLA_Requests::is_open($r->status)) {
                $out['items_retained'] = true;
                /* translators: %s: request number */
                $out['messages'][] = sprintf(__('Customer request %s is still open and was kept. Close it, then erase again.', 'ffl-funnels-addons'), $r->number);
                continue;
            }
            $wpdb->update($t['requests'], ['customer_name' => '', 'customer_email' => '', 'customer_id' => 0, 'access_secret' => FFLA_Requests::random_hex(16)], ['id' => (int) $r->id]);
            $wpdb->update($t['events'], ['body' => '[' . __('removed', 'ffl-funnels-addons') . ']'], ['request_id' => (int) $r->id, 'actor_type' => 'customer']);
            FFLA_Requests_Files::delete_for_request((int) $r->id, ['actor_type' => 'customer']);
            $out['items_removed'] = true;
        }
        return $out;
    }

    /* ── Helpers ───────────────────────────────────────────────────────── */

    public static function badge($r): string
    {
        $status = FFLA_Requests::is_open($r->status) ? $r->status : 'closed';
        return '<span class="ffla-req-badge ffla-req-badge--' . esc_attr($status) . '">' . esc_html(FFLA_Requests::status_label($r->status)) . '</span>';
    }

    public static function order_url(int $order_id): string
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
        }
        return admin_url('post.php?post=' . $order_id . '&action=edit');
    }

    private static function source_label(string $source): string
    {
        $labels = [
            'public_form' => __('from the website form', 'ffl-funnels-addons'),
            'my_account'  => __('from My Account', 'ffl-funnels-addons'),
            'staff'       => __('by staff', 'ffl-funnels-addons'),
        ];
        return $labels[$source] ?? $source;
    }

    private static function render_refund($r, $order, bool $emails_on): void
    {
        $remaining = (float) $order->get_remaining_refund_amount();
        $money = static function ($amount) use ($order) {
            return FFLA_Requests_Refunds::money((float) $amount, $order);
        };
        if ($remaining <= 0) {
            echo '<div class="ffla-req-box"><h2>' . esc_html__('Refund', 'ffl-funnels-addons') . '</h2><p class="description">' . esc_html__('Nothing left to refund on this order.', 'ffl-funnels-addons') . '</p></div>';
            return;
        }
        $lines = FFLA_Requests_Refunds::lines($r, $order);
        $gateway = FFLA_Requests_Refunds::gateway_refunds($order);
        $currency = [
            'symbol'   => html_entity_decode(get_woocommerce_currency_symbol($order->get_currency()), ENT_QUOTES, 'UTF-8'),
            'decimals' => wc_get_price_decimals(),
            'format'   => html_entity_decode(get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8'),
        ];

        echo '<details class="ffla-req-box ffla-req-refund"' . ('item_received' === $r->status ? ' open' : '') . '><summary><strong>' . esc_html__('Refund', 'ffl-funnels-addons') . '</strong></summary>';
        self::form_open($r, 'refund');
        echo '<input type="hidden" name="intent" value="' . esc_attr(wp_generate_uuid4()) . '">';
        echo '<div data-ffla-refund data-currency="' . esc_attr((string) wp_json_encode($currency)) . '" data-remaining="' . esc_attr((string) $remaining) . '">';
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Item', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Qty', 'ffl-funnels-addons') . '</th></tr></thead><tbody>';
        foreach ($lines as $line) {
            if (!$line['max'] && !$line['in_request']) {
                continue;
            }
            echo '<tr' . ($line['in_request'] ? ' class="is-requested"' : '') . '><td>' . esc_html($line['name']) . '<br><span class="description">' . esc_html($money($line['unit_gross']) . ' × ' . $line['ordered'] . ($line['max'] < $line['ordered'] ? ' · ' . sprintf(
                /* translators: %d: units */
                __('%d already refunded', 'ffl-funnels-addons'),
                $line['ordered'] - $line['max']
            ) : '')) . '</span></td>'
                . '<td><input type="number" min="0" max="' . (int) $line['max'] . '" step="1" name="qty[' . (int) $line['item_id'] . ']" value="' . (int) $line['qty'] . '" data-unit="' . esc_attr((string) $line['unit_gross']) . '" aria-label="' . esc_attr(sprintf(
                    /* translators: %s: product */
                    __('Quantity of %s to refund', 'ffl-funnels-addons'),
                    $line['name']
                )) . '"' . ($line['max'] ? '' : ' disabled') . '></td></tr>';
        }
        echo '</tbody></table>';
        echo '<div class="ffla-req-two"><span><label for="ffla-req-fee">' . esc_html__('Restocking fee (%)', 'ffl-funnels-addons') . '</label><input type="number" id="ffla-req-fee" name="fee" min="0" max="100" step="0.01" value="' . esc_attr(wc_format_decimal(FFLA_Requests_Refunds::suggested_fee($r), 2, true)) . '"></span>'
            . '<span><label for="ffla-req-extra">' . esc_html__('Extra amount (e.g. shipping)', 'ffl-funnels-addons') . '</label><input type="number" id="ffla-req-extra" name="extra" min="0" step="0.01" value="0"></span></div>';
        echo '<p class="ffla-req-refund-total">' . esc_html__('Refund total:', 'ffl-funnels-addons') . ' <strong data-ffla-refund-total>—</strong> <span class="description" data-ffla-refund-fee></span></p>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: amount */
            __('Left to refund on the order: %s', 'ffl-funnels-addons'),
            $money($remaining)
        )) . '</p></div>';

        echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Refund method', 'ffl-funnels-addons') . '</legend>';
        if ($gateway) {
            echo '<p><label><input type="radio" name="method" value="gateway" checked> ' . esc_html(sprintf(
                /* translators: %s: payment method */
                __('Refund automatically via %s', 'ffl-funnels-addons'),
                $order->get_payment_method_title() ?: __('the payment gateway', 'ffl-funnels-addons')
            )) . '</label></p>';
        }
        echo '<p><label><input type="radio" name="method" value="manual"' . checked(!$gateway, true, false) . '> ' . esc_html__('Manual refund (record it; return the money another way)', 'ffl-funnels-addons') . '</label></p></fieldset>';
        echo '<p><label><input type="checkbox" name="restock" value="1"' . checked('return' === $r->type, true, false) . '> ' . esc_html__('Put the items back in stock', 'ffl-funnels-addons') . '</label></p>';
        echo '<p><label><input type="checkbox" name="close" value="1" checked> ' . esc_html__('Close the request as refunded', 'ffl-funnels-addons') . '</label></p>';
        echo '<label for="ffla-req-refund-note">' . esc_html__('Extra note for the customer (optional)', 'ffl-funnels-addons') . '</label><textarea id="ffla-req-refund-note" name="note" rows="2"></textarea>';
        echo '<p><label><input type="checkbox" name="notify" value="1"' . checked($emails_on, true, false) . disabled(!$emails_on, true, false) . '> ' . esc_html__('Email the customer', 'ffl-funnels-addons') . '</label></p>';
        echo '<p class="ffla-req-confirm-box"><label><input type="checkbox" name="confirm_refund" value="1" required> <strong>' . esc_html__('I confirm this refund', 'ffl-funnels-addons') . '</strong></label></p>';
        self::button(__('Issue refund', 'ffl-funnels-addons'), 'primary');
        echo '<p class="description">' . esc_html__('Creates a normal WooCommerce refund on the order. It cannot be undone from here.', 'ffl-funnels-addons') . '</p>';
        echo '</form></details>';
    }

    private static function carrier_select(string $id, string $current): string
    {
        $html = '<select id="' . esc_attr($id) . '" name="carrier">';
        foreach (FFLA_Requests::carriers() as $key => $label) {
            $html .= '<option value="' . esc_attr($key) . '"' . selected($current, $key, false) . '>' . esc_html($label) . '</option>';
        }
        return $html . '</select>';
    }

    private static function replies_picker(string $target, $r): void
    {
        $replies = FFLA_Requests_Replies::all();
        if (!$replies) {
            return;
        }
        echo '<p class="ffla-req-replies"><label class="screen-reader-text" for="' . esc_attr($target) . '-pick">' . esc_html__('Insert a saved reply', 'ffl-funnels-addons') . '</label>'
            . '<select id="' . esc_attr($target) . '-pick" data-ffla-insert="' . esc_attr($target) . '"><option value="">' . esc_html__('Insert a saved reply…', 'ffl-funnels-addons') . '</option>';
        foreach ($replies as $reply) {
            echo '<option value="' . esc_attr(FFLA_Requests_Replies::fill($reply['body'], $r)) . '">' . esc_html($reply['title']) . '</option>';
        }
        echo '</select> <a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG . '&screen=setup#ffla-replies')) . '">' . esc_html__('Manage', 'ffl-funnels-addons') . '</a></p>';
    }

    /* ── Rules & saved replies screen ──────────────────────────────────── */

    private static function render_setup(): void
    {
        $terms = [
            'product_cat' => get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1000, 'orderby' => 'name']),
            'product_tag' => get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false, 'number' => 1000, 'orderby' => 'name']),
        ];
        $target_select = static function (string $name, string $current) use ($terms): string {
            $html = '<select name="' . esc_attr($name) . '" required><option value="">' . esc_html__('— Choose —', 'ffl-funnels-addons') . '</option>';
            foreach (['product_cat' => __('Categories (includes subcategories)', 'ffl-funnels-addons'), 'product_tag' => __('Tags', 'ffl-funnels-addons')] as $taxonomy => $label) {
                if (is_wp_error($terms[$taxonomy]) || !$terms[$taxonomy]) {
                    continue;
                }
                $html .= '<optgroup label="' . esc_attr($label) . '">';
                foreach ($terms[$taxonomy] as $term) {
                    $value = $taxonomy . ':' . $term->term_id;
                    $html .= '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($term->name) . '</option>';
                }
                $html .= '</optgroup>';
            }
            return $html . '</select>';
        };
        $rule_row = static function ($i, array $rule) use ($target_select): string {
            $n = 'rules[' . $i . ']';
            $target = !empty($rule) ? $rule['taxonomy'] . ':' . $rule['term'] : '';
            return '<tr><td>' . $target_select($n . '[target]', $target) . '</td>'
                . '<td><select name="' . esc_attr($n . '[returnable]') . '"><option value="yes"' . selected(!isset($rule['returnable']) || $rule['returnable'], true, false) . '>' . esc_html__('Returnable', 'ffl-funnels-addons') . '</option><option value="no"' . selected(isset($rule['returnable']) && !$rule['returnable'], true, false) . '>' . esc_html__('Not returnable', 'ffl-funnels-addons') . '</option></select></td>'
                . '<td><input type="number" min="1" max="365" name="' . esc_attr($n . '[days]') . '" value="' . esc_attr(isset($rule['days']) && null !== $rule['days'] ? (string) $rule['days'] : '') . '" placeholder="' . esc_attr__('default', 'ffl-funnels-addons') . '"></td>'
                . '<td><input type="number" min="0" max="100" step="0.01" name="' . esc_attr($n . '[fee]') . '" value="' . esc_attr(isset($rule['fee']) && null !== $rule['fee'] ? (string) $rule['fee'] : '') . '" placeholder="' . esc_attr__('default', 'ffl-funnels-addons') . '"></td>'
                . '<td><input type="text" maxlength="300" name="' . esc_attr($n . '[note]') . '" value="' . esc_attr((string) ($rule['note'] ?? '')) . '" placeholder="' . esc_attr__('e.g. Ammunition cannot be returned.', 'ffl-funnels-addons') . '"></td>'
                . '<td><button type="button" class="button-link button-link-delete" data-ffla-remove>' . esc_html__('Remove', 'ffl-funnels-addons') . '</button></td></tr>';
        };
        $reply_row = static function ($i, array $reply): string {
            $n = 'replies[' . $i . ']';
            return '<tr><td><input type="text" maxlength="100" name="' . esc_attr($n . '[title]') . '" value="' . esc_attr((string) ($reply['title'] ?? '')) . '" aria-label="' . esc_attr__('Title', 'ffl-funnels-addons') . '"></td>'
                . '<td><textarea rows="4" name="' . esc_attr($n . '[body]') . '" aria-label="' . esc_attr__('Reply text', 'ffl-funnels-addons') . '">' . esc_textarea((string) ($reply['body'] ?? '')) . '</textarea></td>'
                . '<td><button type="button" class="button-link button-link-delete" data-ffla-remove>' . esc_html__('Remove', 'ffl-funnels-addons') . '</button></td></tr>';
        };

        echo '<div class="wrap ffla-req-admin ffla-req-setup-screen"><h1 class="wp-heading-inline">' . esc_html__('Return rules & saved replies', 'ffl-funnels-addons') . '</h1> '
            . '<a class="page-title-action" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('← All requests', 'ffl-funnels-addons') . '</a><hr class="wp-header-end">';
        self::notices();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ffla_req_setup_save">';
        wp_nonce_field('ffla_req_setup_save');

        echo '<div class="ffla-req-box"><h2>' . esc_html__('Return rules', 'ffl-funnels-addons') . '</h2><p>' . esc_html(sprintf(
            /* translators: 1: days, 2: percent */
            __('Without a rule, products use the defaults from settings: %1$d-day return window and %2$s%% restocking fee. When several rules match a product, it is not returnable if any rule says so, the shortest window and the highest fee apply. The restocking fee is never charged for damaged, defective, wrong or not-as-described items.', 'ffl-funnels-addons'),
            FFLA_Requests_Rules::default_days(),
            wc_format_decimal(FFLA_Requests_Rules::default_fee(), 2, true)
        )) . ' <a href="' . esc_url(admin_url('admin.php?page=ffla-customer-operations')) . '">' . esc_html__('Change defaults', 'ffl-funnels-addons') . '</a></p>';
        echo '<table class="widefat ffla-req-repeater" data-ffla-repeater="rules"><thead><tr><th>' . esc_html__('Applies to', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Returns', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Window (days)', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Restocking fee (%)', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Note shown to customers', 'ffl-funnels-addons') . '</th><th></th></tr></thead><tbody>';
        foreach (FFLA_Requests_Rules::all() as $i => $rule) {
            echo $rule_row($i, $rule); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</tbody></table><template data-ffla-template="rules">' . $rule_row('__i__', []) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<p><button type="button" class="button" data-ffla-add="rules">' . esc_html__('Add rule', 'ffl-funnels-addons') . '</button></p></div>';

        echo '<div class="ffla-req-box" id="ffla-replies"><h2>' . esc_html__('Saved replies', 'ffl-funnels-addons') . '</h2><p>' . esc_html__('Staff insert these from the request screen and edit them before sending. Placeholders: {first_name}, {customer_name}, {request_number}, {order_number}, {store_name}, {request_link}.', 'ffl-funnels-addons') . '</p>';
        echo '<table class="widefat ffla-req-repeater" data-ffla-repeater="replies"><thead><tr><th style="width:25%">' . esc_html__('Title', 'ffl-funnels-addons') . '</th><th>' . esc_html__('Reply', 'ffl-funnels-addons') . '</th><th></th></tr></thead><tbody>';
        foreach (FFLA_Requests_Replies::all() as $i => $reply) {
            echo $reply_row($i, $reply); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</tbody></table><template data-ffla-template="replies">' . $reply_row('__i__', []) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<p><button type="button" class="button" data-ffla-add="replies">' . esc_html__('Add reply', 'ffl-funnels-addons') . '</button></p></div>';

        self::button(__('Save rules & replies', 'ffl-funnels-addons'), 'primary');
        echo '</form></div>';
    }

    public static function save_setup(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Access denied.', 'ffl-funnels-addons'), '', ['response' => 403]);
        }
        check_admin_referer('ffla_req_setup_save');
        $rules = FFLA_Requests_Rules::save(array_values((array) wp_unslash($_POST['rules'] ?? [])));
        $replies = FFLA_Requests_Replies::save(array_values((array) wp_unslash($_POST['replies'] ?? [])));
        self::flash('success', sprintf(
            /* translators: 1: rules, 2: replies */
            __('Saved %1$d return rules and %2$d saved replies.', 'ffl-funnels-addons'),
            count($rules),
            count($replies)
        ));
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&screen=setup'));
        exit;
    }

    public static function export_csv(): void
    {
        require_once __DIR__ . '/class-ffla-requests-report.php';
        FFLA_Requests_Report::export();
    }

    private static function actor(): array
    {
        return ['type' => 'staff', 'id' => get_current_user_id()];
    }

    private static function form_open($r, string $do, bool $files = false): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($files ? ' enctype="multipart/form-data"' : '') . '>'
            . '<input type="hidden" name="action" value="ffla_req_action"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="request" value="' . (int) $r->id . '">';
        wp_nonce_field('ffla_req_' . (int) $r->id);
    }

    private static function button(string $label, string $kind = 'secondary', bool $disabled = false): void
    {
        $class = 'primary' === $kind ? 'button button-primary' : ('delete' === $kind ? 'button button-link-delete' : 'button');
        echo '<button type="submit" class="' . esc_attr($class) . '"' . ($disabled ? ' disabled' : '') . '>' . esc_html($label) . '</button>';
    }

    private static function file_field(string $id): void
    {
        echo '<p class="ffla-req-field"><label for="ffla-req-files-' . esc_attr($id) . '">' . esc_html__('Attach files', 'ffl-funnels-addons') . '</label>'
            . '<input type="file" id="ffla-req-files-' . esc_attr($id) . '" name="files[]" multiple accept="' . esc_attr(FFLA_Requests_Files::accept()) . '" data-ffla-files>'
            . '<span class="description">' . esc_html(FFLA_Requests_Files::videos_enabled()
                ? __('Photos (JPEG, PNG, iPhone HEIC), PDFs or short videos, as many as you need. Drag them in or paste a screenshot. Photos are resized before sending.', 'ffl-funnels-addons')
                : __('Photos (JPEG, PNG, iPhone HEIC) or PDFs, as many as you need. Drag them in or paste a screenshot. Photos are resized before sending.', 'ffl-funnels-addons')) . '</span>'
            . '<span class="ffla-req-files-status" role="status" aria-live="polite"></span></p>';
    }

    private static function flash(string $type, string $message): void
    {
        $key = self::FLASH . get_current_user_id();
        $list = get_transient($key);
        $list = is_array($list) ? $list : [];
        $list[] = [$type, $message];
        set_transient($key, $list, 120);
    }

    private static function notices(): void
    {
        $key = self::FLASH . get_current_user_id();
        $list = get_transient($key);
        if (!is_array($list)) {
            return;
        }
        delete_transient($key);
        foreach ($list as $item) {
            echo '<div class="notice notice-' . ('error' === $item[0] ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($item[1]) . '</p></div>';
        }
    }
}
