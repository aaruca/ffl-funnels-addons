<?php
defined('ABSPATH') || exit;

class FFLA_Customer_Operations_Admin
{
    public static function boot(): void
    {
        add_action('add_meta_boxes', [__CLASS__, 'metabox']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_ffla_ops_settings', [__CLASS__, 'save_settings']);
        add_action('wp_ajax_ffla_ops', [__CLASS__, 'ajax']);
        add_action('wp_ajax_ffla_ops_template', [__CLASS__, 'template_ajax']);
        foreach (['edit-shop_order', 'woocommerce_page_wc-orders'] as $screen) {
            add_filter('manage_' . $screen . '_columns', [__CLASS__, 'columns']);
            add_filter('bulk_actions-' . $screen, [__CLASS__, 'bulk_actions']);
            add_filter('handle_bulk_actions-' . $screen, [__CLASS__, 'bulk'], 10, 3);
        }
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'column'], 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'column'], 10, 2);
        add_action('restrict_manage_posts', [__CLASS__, 'filter_control']);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [__CLASS__, 'filter_control']);
        add_action('pre_get_posts', [__CLASS__, 'legacy_query']);
        add_filter('woocommerce_order_list_table_prepare_items_query_args', [__CLASS__, 'hpos_query']);
        add_action('admin_notices', [__CLASS__, 'bulk_notice']);
    }

    public static function assets(): void
    {
        $screen = get_current_screen();
        if (!$screen || (!in_array($screen->id, ['shop_order','edit-shop_order','woocommerce_page_wc-orders'], true) && strpos($screen->id, 'ffla-customer-operations') === false)) { return; }
        $dir = dirname(__DIR__) . '/assets/'; $url = FFLA_URL . 'modules/customer-notes/assets/';
        wp_enqueue_style('ffla-ops', $url . 'operations.css', [], (string) filemtime($dir . 'operations.css'));
        wp_enqueue_script('ffla-ops', $url . 'operations.js', [], (string) filemtime($dir . 'operations.js'), true);
        wp_localize_script('ffla-ops', 'fflaOps', ['ajax'=>admin_url('admin-ajax.php')]);
    }

    public static function settings(): void
    {
        if (!current_user_can('manage_woocommerce')) { return; }
        $s = FFLA_Customer_Operations_Settings::get(); $groups = [];
        foreach (FFLA_Customer_Operations_Settings::fields() as $key=>$f) { $groups[$f[0]][$key] = $f; }
        echo '<div class="ffla-ops"><h1>Customer &amp; Order Management</h1><p>Enable only the tools your store needs. Customer Notes stays in this same module. All new tools start disabled; saved information is retained when a switch is turned off.</p>';
        echo '<p class="ffla-ops-notice">Internal customer notes and case evidence are staff-only. Public updates are written separately. No checkout, payment or tax settings are changed here.</p>';
        if (isset($_GET['saved'])) { echo '<p role="status">Settings saved.</p>'; }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ffla_ops_settings">';
        wp_nonce_field('ffla_ops_settings');
        foreach ($groups as $group=>$fields) {
            echo '<details class="ffla-ops-section"' . ($group === 'General' ? ' open' : '') . '><summary>' . esc_html($group) . '</summary><div class="ffla-ops-grid">';
            foreach ($fields as $key=>$f) {
                $id = 'ffla-ops-' . $key;
                echo '<div class="ffla-ops-field"><label for="' . esc_attr($id) . '">';
                if ($f[3] === 'switch') { echo '<input type="checkbox" role="switch" id="' . esc_attr($id) . '" name="settings[' . esc_attr($key) . ']" value="1" ' . checked((bool) $s[$key], true, false) . ' aria-describedby="' . esc_attr($id) . '-help"> '; }
                echo esc_html($f[1]) . '</label>';
                if ($f[3] === 'textarea') { echo '<textarea rows="4" id="' . esc_attr($id) . '" name="settings[' . esc_attr($key) . ']" aria-describedby="' . esc_attr($id) . '-help">' . esc_textarea(str_replace('\\n', "\n", $s[$key])) . '</textarea>'; }
                elseif ($f[3] !== 'switch') { echo '<input type="' . esc_attr($f[3]) . '" id="' . esc_attr($id) . '" name="settings[' . esc_attr($key) . ']" value="' . esc_attr($s[$key]) . '" ' . ($f[3] === 'number' ? 'min="1" max="' . ($key === 'reminder_max' ? '5' : '30') . '"' : '') . ' aria-describedby="' . esc_attr($id) . '-help">'; }
                echo '<p id="' . esc_attr($id) . '-help" class="description">' . esc_html($f[2]) . '</p></div>';
            }
            echo '</div></details>';
        }
        echo '<p><button type="submit" class="button button-primary">Save settings</button></p></form>';
        echo '<section class="ffla-ops-section"><h2>Preview and test saved email templates</h2><p>Save settings first. Preview uses sample order information. Tests go only to your own staff email; the email master switch must be enabled.</p><div data-ffla-template data-nonce="' . esc_attr(wp_create_nonce('ffla_ops_template')) . '"><label>Template <select name="kind"><option value="ready">Ready for Pickup</option><option value="reminder">Pickup reminder</option></select></label> <button type="button" class="button" data-template-action="preview">Preview</button> <button type="button" class="button" data-template-action="test">Send test to me</button><pre role="status" class="ffla-ops-result"></pre></div></section>';
        echo '<p>Workflow: configure switches → open a WooCommerce order → save Order Management → mark eligible pickup orders ready. Save serials before marking ready. Confirm physical collection before completing the order. Use the separate public-update action only for information the buyer may see.</p></div>';
    }

    public static function save_settings(): void
    {
        if (!current_user_can('manage_woocommerce')) { wp_die('Access denied.', '', ['response'=>403]); }
        check_admin_referer('ffla_ops_settings');
        update_option(FFLA_Customer_Operations_Settings::OPTION, FFLA_Customer_Operations_Settings::sanitize((array) wp_unslash($_POST['settings'] ?? [])), false);
        wp_safe_redirect(admin_url('admin.php?page=ffla-customer-operations&saved=1')); exit;
    }

    public static function metabox(): void
    {
        if (!current_user_can('manage_woocommerce')) { return; }
        $s = FFLA_Customer_Operations_Settings::get(); unset($s['notes']);
        $visible = false;
        foreach (['pickup','serials','item_details','checklist','followup','public_messages','notifications'] as $key) { $visible = $visible || FFLA_Customer_Operations_Settings::enabled($key); }
        if (!$visible) { return; }
        foreach (array_unique(['shop_order', function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order']) as $screen) {
            add_meta_box('ffla_order_management', 'Order Management', [__CLASS__, 'render'], $screen, 'normal', 'default');
        }
    }

    private static function input(string $name, string $label, $value = '', string $type = 'text'): void
    {
        echo '<label class="ffla-ops-field">' . esc_html($label);
        if ($type === 'textarea') { echo '<textarea rows="3" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea>'; }
        else { echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . ($type === 'number' ? ' min="0" step="1"' : '') . '>'; }
        echo '</label>';
    }

    private static function select(string $name, string $label, $current, array $values): void
    {
        echo '<label class="ffla-ops-field">' . esc_html($label) . '<select name="' . esc_attr($name) . '">';
        foreach ($values as $key=>$text) { echo '<option value="' . esc_attr($key) . '" ' . selected((string) $current, (string) $key, false) . '>' . esc_html($text) . '</option>'; }
        echo '</select></label>';
    }

    public static function render($object): void
    {
        $order = $object instanceof WC_Order ? $object : wc_get_order($object->ID);
        if (!FFLA_Customer_Operations::staff($order)) { return; }
        $d = FFLA_Customer_Operations::data($order); $id = $order->get_id();
        echo '<div class="ffla-ops" data-ffla-order="' . absint($id) . '" data-nonce="' . esc_attr(wp_create_nonce('ffla_ops_' . $id)) . '" data-intent="' . esc_attr(wp_generate_uuid4()) . '"><input type="hidden" name="ops[revision]" value="' . esc_attr($d['revision']) . '">';
        echo '<p>These records belong only to this order. Save changes here separately from the main WooCommerce Update button. Internal audit entries appear in Order notes.</p>';
        if (FFLA_Customer_Operations_Settings::enabled('pickup')) {
            echo '<section class="ffla-ops-section"><h3>Pickup</h3>';
            $error = FFLA_Customer_Operations::ready_error($order);
            echo '<p>' . esc_html($error ?: 'Payment and pickup checks passed. Confirm staff preparation before marking ready.') . '</p>';
            if ($order->get_status() === FFLA_Customer_Operations::STATUS) { self::button('collect', 'Confirm all remaining units collected', 'Confirm the customer has physically collected every remaining unit? This completes the order.'); }
            elseif ($error === '') { self::button('ready', 'Mark Ready for Pickup', 'Save item changes first. Mark this order Ready for Pickup?'); }
            if ($d['collected_at']) { echo '<p>Collected ' . esc_html(wp_date('Y-m-d H:i', $d['collected_at'])) . ' by staff #' . absint($d['collected_by'] ?? 0) . '</p>'; }
            echo '</section>';
        }
        if (FFLA_Customer_Operations_Settings::enabled('checklist')) {
            echo '<fieldset class="ffla-ops-section"><legend>Preparation checklist (internal)</legend>';
            foreach (['items_checked'=>'Items inspected','serials_checked'=>'Serial numbers reviewed','documents_ready'=>'Documents prepared'] as $value=>$label) { echo '<label class="ffla-ops-check"><input type="checkbox" name="ops[checklist][]" value="' . esc_attr($value) . '" ' . checked(in_array($value, $d['checklist'], true), true, false) . '> ' . esc_html($label) . '</label>'; }
            echo '</fieldset>';
        }
        foreach ($order->get_items() as $item_id=>$item) {
            $firearm = FFLA_Customer_Operations::firearm($item); $v = FFLA_Customer_Operations::item($item);
            if (!($firearm && (FFLA_Customer_Operations_Settings::enabled('serials') || FFLA_Customer_Operations_Settings::enabled('item_details'))) && !FFLA_Customer_Operations_Settings::enabled('partial_pickup')) { continue; }
            echo '<details class="ffla-ops-section" open><summary>' . esc_html($item->get_name() . ' × ' . $item->get_quantity() . ' — line #' . $item_id) . '</summary><div class="ffla-ops-grid">';
            if ($firearm && FFLA_Customer_Operations_Settings::enabled('serials')) {
                self::input('ops[items][' . $item_id . '][serials]', 'Serial numbers — one per unit / line', implode("\n", $v['serials']), 'textarea');
                $required = max(0, (int) $item->get_quantity() - abs((int) $order->get_qty_refunded_for_item($item_id)));
                if (count($v['serials']) < $required) { echo '<p class="ffla-ops-notice">Missing serial numbers for unrefunded units: ' . absint($required - count($v['serials'])) . '. Duplicates within this order are rejected when saving.</p>'; }
            }
            if ($firearm && FFLA_Customer_Operations_Settings::enabled('item_details')) {
                $product = $item->get_product();
                foreach (['manufacturer','model','caliber'] as $key) {
                    $value = $v[$key];
                    if ($value === '' && $product && !$item->get_meta(FFLA_Customer_Operations::ITEM)) { $value = $product->get_attribute('pa_' . $key) ?: $product->get_attribute($key); }
                    self::input('ops[items][' . $item_id . '][' . $key . ']', ucfirst($key), $value);
                }
            }
            if (FFLA_Customer_Operations_Settings::enabled('partial_pickup')) { self::input('ops[items][' . $item_id . '][collected]', 'Cumulative units physically collected (cannot decrease)', $v['collected'], 'number'); }
            echo '</div></details>';
        }
        if (FFLA_Customer_Operations_Settings::enabled('followup')) {
            $c = FFLA_Customer_Operations::case_data($order);
            echo '<section class="ffla-ops-section"><h3>Internal follow-up case</h3><p>This does not change the order status or issue a refund / replacement. Select Resolved when done; choose Open to reopen.</p><div class="ffla-ops-grid">';
            self::select('ops[case][state]', 'Case status', $c['state'], [''=>'No case','open'=>'Open','in_progress'=>'In progress','waiting_customer'=>'Waiting for customer','waiting_carrier'=>'Waiting for carrier','resolved'=>'Resolved']);
            self::select('ops[case][reason]', 'Reason', $c['reason'], [''=>'Not specified','lost_shipment'=>'Missing / delayed shipment','shortage'=>'Product shortage','return'=>'Return request','refund'=>'Refund follow-up','replacement'=>'Replacement follow-up','other'=>'Other']);
            self::select('ops[case][priority]', 'Priority', $c['priority'], [''=>'Not specified','low'=>'Low','normal'=>'Normal','high'=>'High','urgent'=>'Urgent']);
            $staff_options = [0=>'Unassigned'];
            foreach (get_users(['capability'=>'manage_woocommerce','number'=>100,'orderby'=>'display_name','order'=>'ASC']) as $employee) {
                if (user_can($employee, 'edit_shop_order', $id)) { $staff_options[$employee->ID] = $employee->display_name . ' (#' . $employee->ID . ')'; }
            }
            if ($c['assignee'] && !isset($staff_options[$c['assignee']])) { $staff_options[$c['assignee']] = 'Previously assigned staff #' . $c['assignee']; }
            self::select('ops[case][assignee]', 'Assigned employee', $c['assignee'], $staff_options);
            self::input('ops[case][due]', 'Follow-up due — store time zone', $c['due'] ? wp_date('Y-m-d\TH:i', $c['due']) : '', 'datetime-local');
            self::input('ops[case][related]', 'Related replacement order / refund references', $c['related']);
            self::input('ops[case][note]', 'Add private case note — never sent to customer', '', 'textarea');
            echo '</div></section>';
        }
        self::button('save', 'Save Order Management');
        if (FFLA_Customer_Operations_Settings::enabled('attachments')) {
            echo '<section class="ffla-ops-section"><h3>Private evidence</h3><p>Staff only. JPEG, PNG or PDF, 2 MB maximum each, up to 8 per order. Upload separately after saving changes.</p><label>Evidence file <input type="file" name="evidence" accept="image/jpeg,image/png,application/pdf"></label> ';
            self::button('upload', 'Upload private file');
            foreach ($d['files'] as $token=>$file) {
                $url = wp_nonce_url(add_query_arg(['action'=>'ffla_ops_file','order'=>$id,'file'=>$token], admin_url('admin-post.php')), 'ffla_ops_file_' . $id . '_' . $token);
                echo '<p><a href="' . esc_url($url) . '">' . esc_html($file['name']) . '</a></p>';
            }
            echo '</section>';
        }
        if (FFLA_Customer_Operations_Settings::enabled('public_messages')) {
            echo '<section class="ffla-ops-section"><h3>Buyer-visible update</h3><p>This text is public to the signed-in order owner when Customer progress is enabled. Email is optional and requires Email communications. Nothing is copied from internal notes.</p>';
            self::input('public_text', 'Write customer-facing message', '', 'textarea');
            echo '<label class="ffla-ops-check"><input type="checkbox" name="email_public" value="1"> Also email the billing recipient</label>';
            self::button('public', 'Publish customer update', 'Publish this text to the order owner?');
            foreach ($d['public'] as $m) { echo '<p>' . esc_html(wp_date('Y-m-d H:i', $m['at']) . ': ' . $m['text']) . '</p>'; }
            echo '</section>';
        }
        if (FFLA_Customer_Operations_Settings::enabled('notifications')) {
            echo '<section class="ffla-ops-section"><h3>Communication log</h3><p>Accepted means handed to WordPress mail transport, not delivered. Failed / uncertain sends are not retried automatically. Manual resend may duplicate an already-delivered message.</p>';
            if ($order->get_status() === FFLA_Customer_Operations::STATUS) { self::button('mail', 'Send / resend ready email', 'Send another ready email to the billing recipient? Check the log to avoid duplicates.'); }
            $log = $order->get_meta('_ffla_ops_mail');
            foreach (array_reverse(is_array($log) ? $log : []) as $entry) { echo '<p>' . esc_html(wp_date('Y-m-d H:i', $entry['at']) . ' — ' . $entry['subject'] . ' — ' . $entry['status'] . (!empty($entry['error']) ? ' (' . $entry['error'] . ')' : '')) . '</p>'; }
            echo '</section>';
        }
        echo '<p class="ffla-ops-result" role="status" aria-live="polite"></p></div>';
    }

    private static function button(string $action, string $label, string $confirm = ''): void
    {
        echo '<button type="button" class="button ' . ($action === 'save' ? 'button-primary' : '') . '" data-ops-action="' . esc_attr($action) . '" data-confirm="' . esc_attr($confirm) . '">' . esc_html($label) . '</button> ';
    }

    public static function ajax(): void
    {
        $id = absint($_POST['order'] ?? 0);
        if (!check_ajax_referer('ffla_ops_' . $id, 'nonce', false)) { wp_send_json_error(['message'=>'Session expired. Reload before trying again.'], 403); }
        try {
            if (!in_array('customer-notes', (array) get_option('ffla_active_modules', []), true)) { throw new RuntimeException('This module is disabled.'); }
            $input = wp_unslash($_POST); $op = sanitize_key($input['operation'] ?? '');
            $message = FFLA_Customer_Operations::locked($id, static function ($order) use ($input, $op) {
                if (!FFLA_Customer_Operations::staff($order)) { throw new RuntimeException('You cannot edit this order.'); }
                $d = FFLA_Customer_Operations::data($order);
                if (!hash_equals((string) $d['revision'], (string) ($input['ops']['revision'] ?? ''))) { throw new RuntimeException('This order changed. Reload before trying again.'); }
                if ($op === 'save') { FFLA_Customer_Operations::save($order, (array) ($input['ops'] ?? [])); }
                elseif ($op === 'ready') {
                    $error = FFLA_Customer_Operations::ready_error($order); if ($error !== '') { throw new RuntimeException($error); }
                    if (!$order->update_status(FFLA_Customer_Operations::STATUS, 'Staff marked order ready.', true)) { throw new RuntimeException('The order status could not be changed.'); }
                }
                elseif ($op === 'collect') { FFLA_Customer_Operations::collect($order); }
                elseif ($op === 'upload') { FFLA_Customer_Operations_Documents::upload($order, (array) ($_FILES['evidence'] ?? [])); }
                elseif ($op === 'public') {
                    if (!FFLA_Customer_Operations_Settings::enabled('public_messages')) { throw new RuntimeException('Public updates are disabled.'); }
                    $text = sanitize_textarea_field($input['public_text'] ?? '');
                    if ($text === '' || strlen($text) > 5000 || count($d['public']) >= 100) { throw new InvalidArgumentException('Use 1–5000 characters; maximum 100 public updates per order.'); }
                    if (!empty($input['email_public']) && !FFLA_Customer_Operations_Settings::enabled('notifications')) { throw new RuntimeException('Enable Email communications or uncheck email.'); }
                    $token = self::intent($input);
                    $d['public'][] = ['id'=>$token, 'at'=>time(), 'text'=>$text]; $d['revision'] = wp_generate_uuid4();
                    $order->update_meta_data(FFLA_Customer_Operations::DATA, $d); $order->save_meta_data();
                    FFLA_Customer_Operations::audit($order, 'Explicit buyer-visible update published: ' . $text);
                    if (!empty($input['email_public'])) {
                        [$subject] = FFLA_Customer_Operations_Messages::template($order, 'public');
                        try { return 'Update published. ' . FFLA_Customer_Operations_Messages::send($order, 'public:' . $token, $order->get_billing_email(), $subject, $text); }
                        catch (Throwable $e) { return 'Update published, but email could not be sent. Review recipient and mail log.'; }
                    }
                }
                elseif ($op === 'mail') {
                    if ($order->get_status() !== FFLA_Customer_Operations::STATUS || FFLA_Customer_Operations::ready_error($order) !== '') { throw new RuntimeException('Only an eligible ready order can send a ready email.'); }
                    [$subject,$body] = FFLA_Customer_Operations_Messages::template($order, 'ready');
                    return FFLA_Customer_Operations_Messages::send($order, 'manual:' . self::intent($input), $order->get_billing_email(), $subject, $body);
                }
                else { throw new InvalidArgumentException('Unknown operation.'); }
                return 'Saved. Reload the order to view the updated record.';
            });
            $reload = in_array($op, ['ready','collect'], true);
            $html = '';
            if (!$reload) {
                ob_start();
                try { self::render(wc_get_order($id)); }
                catch (Throwable $e) { $reload = true; $message .= ' Reload the order to display its saved record.'; }
                finally { $html = ob_get_clean(); }
                if ($reload) { $html = ''; }
            }
            wp_send_json_success(['message'=>$message, 'reload'=>$reload, 'html'=>$html]);
        } catch (Throwable $e) { wp_send_json_error(['message'=>$e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'The update failed. Reload the order and review WooCommerce logs before retrying.'], 400); }
    }

    private static function intent(array $input): string
    {
        $key = (string) ($input['intent'] ?? '');
        if (!preg_match('/^[a-f0-9-]{36}$/', $key)) { throw new InvalidArgumentException('Reload the page to create a valid action.'); }
        return $key;
    }

    public static function template_ajax(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_ajax_referer('ffla_ops_template', 'nonce', false)) { wp_send_json_error(['message'=>'Access denied.'], 403); }
        $kind = sanitize_key($_POST['kind'] ?? 'ready');
        if (!in_array($kind, ['ready','reminder'], true)) { wp_send_json_error(['message'=>'Unknown template.'], 400); }
        [$subject,$body] = FFLA_Customer_Operations_Messages::template(null, $kind);
        $message = $subject . "\n\n" . $body;
        if (($_POST['operation'] ?? '') === 'test') {
            $key = 'ffla_ops_test_' . get_current_user_id();
            if (!FFLA_Customer_Operations_Settings::enabled('notifications') || get_transient($key)) { wp_send_json_error(['message'=>'Enable Email communications and wait 60 seconds between tests.'], 400); }
            set_transient($key, 1, 60);
            try {
                $ok = wp_mail(wp_get_current_user()->user_email, '[TEST] ' . $subject, $body, ['Content-Type: text/plain; charset=UTF-8']);
                $message = $ok ? 'Test accepted by mail transport. Check your inbox / SMTP log.' : 'Test failed. Review SMTP logs.';
            } catch (Throwable $e) { $message = 'Test delivery is uncertain after a transport error. Review SMTP logs before sending another test.'; }
        }
        wp_send_json_success(['message'=>$message]);
    }

    public static function columns($columns): array
    {
        if (FFLA_Customer_Operations_Settings::enabled('followup')) { $columns['ffla_followup'] = 'Follow-up'; } return $columns;
    }

    public static function column($column, $object): void
    {
        if ($column !== 'ffla_followup' || !FFLA_Customer_Operations_Settings::enabled('followup')) { return; }
        $order = $object instanceof WC_Order ? $object : wc_get_order($object);
        if (!FFLA_Customer_Operations::staff($order)) { return; }
        $c = FFLA_Customer_Operations::case_data($order);
        if (!$c['state']) { echo '—'; return; }
        echo esc_html(str_replace('_', ' ', $c['state']) . ' / ' . $c['priority']);
        if ($c['due']) { echo '<br>' . esc_html('Due ' . wp_date('M j, H:i', $c['due'])); }
        if ($c['assignee']) { echo '<br>Staff #' . absint($c['assignee']); }
    }

    public static function filter_control($type = ''): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['edit-shop_order','woocommerce_page_wc-orders'], true) || !FFLA_Customer_Operations_Settings::enabled('followup') || !current_user_can('manage_woocommerce')) { return; }
        self::select('ffla_followup', 'Follow-up', sanitize_key($_GET['ffla_followup'] ?? ''), [''=>'All cases','active'=>'Unresolved','mine'=>'Assigned to me','overdue'=>'Overdue','resolved'=>'Resolved']);
    }

    public static function filter_meta(): array
    {
        if (!FFLA_Customer_Operations_Settings::enabled('followup') || !current_user_can('manage_woocommerce')) { return []; }
        $filter = sanitize_key($_GET['ffla_followup'] ?? '');
        if (!in_array($filter, ['active','mine','overdue','resolved'], true)) { return []; }
        $clauses = [['key'=>'_ffla_ops_case', 'value'=>$filter === 'resolved' ? ['resolved'] : ['open','in_progress','waiting_customer','waiting_carrier'], 'compare'=>'IN']];
        if ($filter === 'mine') { $clauses[] = ['key'=>'_ffla_ops_assignee','value'=>get_current_user_id(),'compare'=>'=','type'=>'NUMERIC']; }
        if ($filter === 'overdue') { $clauses[] = ['key'=>'_ffla_ops_due','value'=>[1,time()],'compare'=>'BETWEEN','type'=>'NUMERIC']; }
        return $clauses;
    }

    public static function hpos_query($args): array
    {
        $meta = self::filter_meta();
        if ($meta) { $args['meta_query'] = ['relation'=>'AND', $args['meta_query'] ?? [], $meta]; } return $args;
    }

    public static function legacy_query($query): void
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'shop_order') { return; }
        $meta = self::filter_meta(); if ($meta) { $query->set('meta_query', ['relation'=>'AND', (array) $query->get('meta_query'), $meta]); }
    }

    public static function bulk_actions($actions): array
    {
        if (FFLA_Customer_Operations_Settings::enabled('pickup') && current_user_can('manage_woocommerce')) { $actions['ffla_ready'] = 'Mark Ready for Pickup (validated)'; } return $actions;
    }

    public static function bulk($redirect, $action, $ids)
    {
        if ($action !== 'ffla_ready') { return $redirect; }
        // WooCommerce / WP dispatch this hook only after validating the native bulk nonce.
        if (!current_user_can('manage_woocommerce')) { return $redirect; }
        $done = 0; $skipped = 0;
        foreach (array_slice(array_unique(array_map('absint', (array) $ids)), 0, 100) as $id) {
            try {
                FFLA_Customer_Operations::locked($id, static function ($order) {
                    if (!FFLA_Customer_Operations::staff($order)) { throw new RuntimeException('Access denied.'); }
                    $error = FFLA_Customer_Operations::ready_error($order);
                    if ($error !== '' || $order->get_status() === FFLA_Customer_Operations::STATUS) { throw new RuntimeException('Not eligible or already ready.'); }
                    if (!$order->update_status(FFLA_Customer_Operations::STATUS, 'Staff bulk readiness action.', true)) { throw new RuntimeException('Update failed.'); }
                }); $done++;
            } catch (Throwable $e) { $skipped++; }
        }
        $skipped += max(0, count((array) $ids) - 100);
        set_transient('ffla_ops_bulk_' . get_current_user_id(), [$done,$skipped], 120);
        return $redirect;
    }

    public static function bulk_notice(): void
    {
        if (!current_user_can('manage_woocommerce')) { return; }
        $key = 'ffla_ops_bulk_' . get_current_user_id(); $result = get_transient($key);
        if (is_array($result)) { delete_transient($key); echo '<div class="notice notice-info"><p>' . esc_html($result[0] . ' orders marked ready; ' . $result[1] . ' skipped. Open skipped orders to review payment, pickup and serial requirements. Maximum 100 orders per action.') . '</p></div>'; }
    }
}
