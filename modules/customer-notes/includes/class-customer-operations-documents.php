<?php
defined('ABSPATH') || exit;

class FFLA_Customer_Operations_Documents
{
    public static function boot(): void
    {
        add_action('wpo_wcpdf_after_item_meta', [__CLASS__, 'pdf_item'], 10, 3);
        add_action('admin_post_ffla_ops_file', [__CLASS__, 'download']);
    }

    public static function pdf_item($type, $row, $order): void
    {
        $setting = ['invoice'=>'invoice_serials', 'packing-slip'=>'packing_serials'][$type] ?? '';
        if (!$setting || !FFLA_Customer_Operations_Settings::enabled($setting) || !$order instanceof WC_Order || !is_array($row)) { return; }
        $id = absint($row['item_id'] ?? 0);
        $item = $id ? $order->get_item($id) : null;
        if (!$item instanceof WC_Order_Item_Product || (int) $item->get_order_id() !== (int) $order->get_id()) { return; }
        $data = FFLA_Customer_Operations::item($item);
        if ($data['serials']) { echo '<p class="ffla-item-serials">Serial numbers: ' . esc_html(implode(', ', $data['serials'])) . '</p>'; }
        if (FFLA_Customer_Operations_Settings::enabled('item_details')) {
            foreach (['manufacturer','model','caliber'] as $key) { if ($data[$key] !== '') { echo '<p>' . esc_html(ucfirst($key) . ': ' . $data[$key]) . '</p>'; } }
        }
    }

    public static function upload($order, array $file): void
    {
        if (!FFLA_Customer_Operations_Settings::enabled('attachments') || !FFLA_Customer_Operations::staff($order)) { throw new RuntimeException('Private attachments are disabled or not permitted.'); }
        $data = FFLA_Customer_Operations::data($order);
        if (count($data['files']) >= 8) { throw new InvalidArgumentException('Maximum 8 private files per order.'); }
        $path = $file['tmp_name'] ?? '';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($path) || filesize($path) > 2 * MB_IN_BYTES) { throw new InvalidArgumentException('Upload one JPEG, PNG or PDF file of no more than 2 MB.'); }
        $name = sanitize_file_name($file['name']);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $valid = wp_check_filetype_and_ext($path, $name, ['jpg|jpeg'=>'image/jpeg','png'=>'image/png','pdf'=>'application/pdf']);
        if (!in_array($mime, ['image/jpeg','image/png','application/pdf'], true) || $valid['type'] !== $mime) { throw new InvalidArgumentException('File content and extension must match JPEG, PNG or PDF.'); }
        $token = wp_generate_uuid4(); $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) > 2 * MB_IN_BYTES) { throw new RuntimeException('The uploaded file could not be read safely.'); }
        // Autoload=false prevents binary evidence being loaded on every page or every order read.
        if (!add_option('_ffla_ops_file_' . $token, ['order'=>$order->get_id(), 'bytes'=>base64_encode($bytes), 'mime'=>$mime, 'name'=>$name], '', false)) { throw new RuntimeException('The attachment could not be stored.'); }
        $data['files'][$token] = ['name'=>$name, 'at'=>time(), 'by'=>get_current_user_id()]; $data['revision'] = wp_generate_uuid4();
        try { $order->update_meta_data(FFLA_Customer_Operations::DATA, $data); $order->save_meta_data(); }
        catch (Throwable $e) { delete_option('_ffla_ops_file_' . $token); throw $e; }
        FFLA_Customer_Operations::audit($order, 'Private attachment added: ' . $name);
    }

    public static function download(): void
    {
        $id = absint($_GET['order'] ?? 0); $token = sanitize_key($_GET['file'] ?? '');
        check_admin_referer('ffla_ops_file_' . $id . '_' . $token);
        $order = wc_get_order($id);
        if (!FFLA_Customer_Operations::staff($order) || !FFLA_Customer_Operations_Settings::enabled('attachments')) { wp_die('Access denied.', '', ['response'=>403]); }
        $index = FFLA_Customer_Operations::data($order)['files'];
        $file = isset($index[$token]) ? get_option('_ffla_ops_file_' . $token) : null;
        if (!is_array($file) || (int) $file['order'] !== $id) { wp_die('File not found.', '', ['response'=>404]); }
        $bytes = base64_decode($file['bytes'], true);
        if ($bytes === false) { wp_die('Invalid file.', '', ['response'=>500]); }
        nocache_headers(); header('X-Content-Type-Options: nosniff');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', sanitize_file_name($file['name'])) . '"');
        header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;
    }
}
