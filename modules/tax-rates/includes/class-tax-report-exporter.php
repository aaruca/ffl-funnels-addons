<?php
/**
 * Tax report package exporter.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Tax_Report_Exporter
{
    /**
     * Build a temporary ZIP package containing CSV, XLSX, PDF, HTML and JSON.
     *
     * The caller owns the returned temporary file and must delete it with
     * cleanup_file() when it no longer needs it.
     */
    public static function create_package(array $report): array
    {
        $datasets = self::datasets($report);
        $files = [];
        foreach ($datasets as $dataset => $rows) {
            $files[$dataset . '.csv'] = self::build_csv(Tax_Report_Service::get_columns($dataset), $rows);
        }

        $files['tax-filing-report.xlsx'] = self::build_xlsx($datasets);
        $files['tax-filing-summary.pdf'] = self::build_pdf_summary($report);
        $files['tax-filing-summary.html'] = self::build_html_summary($report);
        $files['README.txt'] = self::build_readme($report);

        $manifest = isset($report['manifest']) && is_array($report['manifest']) ? $report['manifest'] : [];
        $report_detail = (string) ($manifest['filters']['report_detail'] ?? 'filing');
        $report_detail = $report_detail === 'advanced' ? 'advanced' : 'filing';
        $manifest['report_detail'] = $report_detail;
        $manifest['dataset_rows'] = [];
        foreach ($datasets as $dataset => $rows) {
            $manifest['dataset_rows'][$dataset] = count($rows);
        }
        $manifest['files'] = array_merge(array_keys($files), ['report-manifest.json']);
        $manifest['file_checksums_sha256'] = [];
        foreach ($files as $filename => $contents) {
            $manifest['file_checksums_sha256'][$filename] = hash('sha256', (string) $contents);
        }
        $manifest_json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $files['report-manifest.json'] = is_string($manifest_json) ? $manifest_json : '{}';

        $from = sanitize_file_name((string) ($manifest['filters']['date_from'] ?? 'start'));
        $to = sanitize_file_name((string) ($manifest['filters']['date_to'] ?? 'end'));
        $report_id = sanitize_file_name(substr((string) ($manifest['report_id'] ?? 'report'), 0, 8));
        $archive_name = sprintf('ffla-tax-%s-report-%s-to-%s-%s.zip', $report_detail, $from, $to, $report_id);
        $folder = sprintf('ffla-tax-%s-report-%s-to-%s/', $report_detail, $from, $to);

        $tmp = function_exists('wp_tempnam') ? wp_tempnam($archive_name) : tempnam(sys_get_temp_dir(), 'ffla-tax-');
        if (!$tmp) {
            throw new RuntimeException(__('A temporary export file could not be created.', 'ffl-funnels-addons'));
        }

        $archive_files = [];
        foreach ($files as $filename => $contents) {
            $archive_files[$folder . $filename] = $contents;
        }
        if (!self::create_zip_file($tmp, $archive_files)) {
            self::cleanup_file($tmp);
            throw new RuntimeException(__('The tax report ZIP file could not be created.', 'ffl-funnels-addons'));
        }

        Tax_Report_Service::record_run($manifest);

        return [
            'path'     => $tmp,
            'filename' => $archive_name,
            'bytes'    => (int) filesize($tmp),
            'manifest' => $manifest,
        ];
    }

    /**
     * Build a standalone PDF summary for email fallback when the ZIP is large.
     */
    public static function create_summary_pdf(array $report): array
    {
        $manifest = isset($report['manifest']) && is_array($report['manifest']) ? $report['manifest'] : [];
        $from = sanitize_file_name((string) ($manifest['filters']['date_from'] ?? 'start'));
        $to = sanitize_file_name((string) ($manifest['filters']['date_to'] ?? 'end'));
        $filename = sprintf('ffla-tax-filing-summary-%s-to-%s.pdf', $from, $to);
        $tmp = function_exists('wp_tempnam') ? wp_tempnam($filename) : tempnam(sys_get_temp_dir(), 'ffla-tax-pdf-');
        if (!$tmp) {
            throw new RuntimeException(__('A temporary PDF file could not be created.', 'ffl-funnels-addons'));
        }

        $written = file_put_contents($tmp, self::build_pdf_summary($report));
        if ($written === false) {
            self::cleanup_file($tmp);
            throw new RuntimeException(__('The tax report PDF file could not be created.', 'ffl-funnels-addons'));
        }

        return [
            'path'     => $tmp,
            'filename' => $filename,
            'bytes'    => (int) filesize($tmp),
            'manifest' => $manifest,
        ];
    }

    public static function cleanup_file(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Build and send a ZIP package to the browser.
     */
    public static function download_package(array $report): void
    {
        $package = self::create_package($report);
        $tmp = (string) $package['path'];

        try {
            if (headers_sent()) {
                throw new RuntimeException(__('The ZIP response could not start because output was already sent.', 'ffl-funnels-addons'));
            }
            while (ob_get_level()) {
                ob_end_clean();
            }
            nocache_headers();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . (string) $package['filename'] . '"');
            header('Content-Length: ' . (int) $package['bytes']);
            header('X-Content-Type-Options: nosniff');
            if (readfile($tmp) === false) {
                throw new RuntimeException(__('The ZIP report could not be streamed.', 'ffl-funnels-addons'));
            }
        } finally {
            self::cleanup_file($tmp);
        }
        exit;
    }

    private static function datasets(array $report): array
    {
        $datasets = [
            'filing-totals'        => (array) ($report['summaries']['filing_totals'] ?? []),
            'state-summary'        => (array) ($report['summaries']['states'] ?? []),
            'jurisdiction-summary' => (array) ($report['summaries']['jurisdictions'] ?? []),
        ];
        if (!empty($report['manifest']['filters']['include_pii'])) {
            $datasets['order-audit'] = (array) ($report['orders'] ?? []);
        }
        if (($report['manifest']['filters']['report_detail'] ?? 'filing') === 'advanced') {
            $datasets['orders'] = (array) ($report['orders'] ?? []);
            $datasets['order-lines'] = (array) ($report['order_lines'] ?? []);
            $datasets['tax-lines'] = (array) ($report['tax_lines'] ?? []);
            $datasets['refunds'] = (array) ($report['refunds'] ?? []);
            $datasets['product-summary'] = (array) ($report['summaries']['products'] ?? []);
            $datasets['payment-summary'] = (array) ($report['summaries']['payments'] ?? []);
            $datasets['exceptions'] = (array) ($report['exceptions'] ?? []);
        }
        return $datasets;
    }

    private static function build_csv(array $columns, array $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if (!$stream) {
            return '';
        }

        // UTF-8 BOM keeps customer names and addresses readable in Excel.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $columns, ',', '"', '');
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = self::safe_spreadsheet_value($row[$column] ?? '');
            }
            fputcsv($stream, $values, ',', '"', '');
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        return is_string($contents) ? $contents : '';
    }

    private static function safe_spreadsheet_value($value)
    {
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value);
        }
        $value = (string) $value;
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) && !preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Create a dependency-free OOXML workbook with one sheet per dataset.
     */
    private static function build_xlsx(array $datasets): string
    {
        $parts = [];

        $sheet_names = [
            'filing-totals' => 'Filing Totals',
            'state-summary' => 'State Summary',
            'jurisdiction-summary' => 'Jurisdictions',
            'order-audit' => 'Order Audit',
            'orders' => 'Orders',
            'order-lines' => 'Order Lines',
            'tax-lines' => 'Tax Lines',
            'refunds' => 'Refunds',
            'product-summary' => 'Products',
            'payment-summary' => 'Payments',
            'exceptions' => 'Exceptions',
        ];

        $content_overrides = '';
        $workbook_sheets = '';
        $workbook_rels = '';
        $sheet_index = 1;
        foreach ($datasets as $dataset => $rows) {
            $columns = Tax_Report_Service::get_columns($dataset);
            $xml = self::worksheet_xml($columns, $rows);
            $parts['xl/worksheets/sheet' . $sheet_index . '.xml'] = $xml;
            $content_overrides .= '<Override PartName="/xl/worksheets/sheet' . $sheet_index . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbook_sheets .= '<sheet name="' . self::xml($sheet_names[$dataset] ?? $dataset) . '" sheetId="' . $sheet_index . '" r:id="rId' . $sheet_index . '"/>';
            $workbook_rels .= '<Relationship Id="rId' . $sheet_index . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheet_index . '.xml"/>';
            $sheet_index++;
        }

        $styles_relation_id = $sheet_index;
        $workbook_rels .= '<Relationship Id="rId' . $styles_relation_id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $parts['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $content_overrides . '</Types>';
        $parts['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $parts['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $workbook_sheets . '</sheets></workbook>';
        $parts['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $workbook_rels . '</Relationships>';
        $parts['xl/styles.xml'] = self::styles_xml();

        return self::zip_string($parts, 'ffla-tax-report.xlsx');
    }

    private static function worksheet_xml(array $columns, array $rows): string
    {
        $last_column = self::column_letter(max(1, count($columns)));
        $row_count = count($rows) + 1;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $last_column . $row_count . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/><sheetData>';

        $xml .= '<row r="1">';
        foreach ($columns as $index => $column) {
            $ref = self::column_letter($index + 1) . '1';
            $xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . self::xml($column) . '</t></is></c>';
        }
        $xml .= '</row>';

        $excel_row = 2;
        foreach ($rows as $row) {
            if ($excel_row > 1048576) {
                break;
            }
            $xml .= '<row r="' . $excel_row . '">';
            foreach ($columns as $index => $column) {
                $ref = self::column_letter($index + 1) . $excel_row;
                $value = $row[$column] ?? '';
                if (self::is_numeric_column($column) && is_numeric($value) && $value !== '') {
                    $style = self::is_money_column($column) ? ' s="2"' : '';
                    $xml .= '<c r="' . $ref . '" t="n"' . $style . '><v>' . self::xml((string) $value) . '</v></c>';
                } else {
                    $value = self::safe_spreadsheet_value($value);
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::xml((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
            $excel_row++;
        }

        $xml .= '</sheetData><autoFilter ref="A1:' . $last_column . '1"/></worksheet>';
        return $xml;
    }

    private static function styles_xml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function build_html_summary(array $report): string
    {
        $manifest = (array) ($report['manifest'] ?? []);
        $filters = (array) ($manifest['filters'] ?? []);
        $stats = (array) ($report['stats'] ?? []);
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>WooCommerce Tax Report</title>'
            . '<style>@page{margin:18mm}body{font:14px/1.45 Arial,sans-serif;color:#172033;margin:32px}h1{margin:0 0 4px}h2{margin-top:30px;border-bottom:2px solid #dbe3f0;padding-bottom:6px}.meta{color:#556176}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:20px 0}.card{border:1px solid #dbe3f0;border-radius:8px;padding:12px}.card b{display:block;font-size:20px;margin-top:3px}table{width:100%;border-collapse:collapse;margin:12px 0 24px;font-size:12px}th,td{border:1px solid #dbe3f0;padding:6px 7px;text-align:left}th{background:#eff5ff}.money{text-align:right}.note{background:#fff8dc;border-left:4px solid #d89b00;padding:10px 14px}@media print{body{margin:0}.no-print{display:none}tr{break-inside:avoid}}</style></head><body>';
        $html .= '<button class="no-print" onclick="window.print()">Print / Save as PDF</button>';
        $html .= '<h1>WooCommerce Tax Report</h1><div class="meta">' . esc_html((string) ($manifest['site_name'] ?? '')) . ' · '
            . esc_html((string) ($filters['date_from'] ?? '')) . ' through ' . esc_html((string) ($filters['date_to'] ?? ''))
            . ' · Generated ' . esc_html((string) ($manifest['generated_at_utc'] ?? '')) . '</div>';
        $html .= '<div class="cards"><div class="card">Orders<b>' . esc_html((string) ($stats['orders'] ?? 0)) . '</b></div>'
            . '<div class="card">Refunds<b>' . esc_html((string) ($stats['refunds'] ?? 0)) . '</b></div>'
            . '<div class="card">Exceptions<b>' . esc_html((string) ($stats['exceptions'] ?? 0)) . '</b></div>'
            . '<div class="card">Snapshot coverage<b>' . esc_html((string) ($manifest['data_quality']['snapshot_coverage_percent'] ?? 0)) . '%</b></div></div>';

        $html .= '<h2>Totals by currency</h2>' . self::html_table(
            ['currency', 'orders', 'gross_product_sales', 'discounts', 'net_product_sales', 'shipping', 'fees', 'tax_collected', 'tax_refunded', 'net_tax', 'refunds', 'order_total', 'net_collected'],
            (array) ($report['totals_by_currency'] ?? [])
        );
        $html .= '<h2>State summary</h2>' . self::html_table(Tax_Report_Service::get_columns('state-summary'), (array) ($report['summaries']['states'] ?? []));
        $html .= '<h2>Exception summary</h2>' . self::html_table(['severity', 'code', 'count', 'message'], (array) ($report['summaries']['exceptions'] ?? []));
        $html .= '<h2>Scope and limitations</h2><div class="note"><ul>';
        foreach ((array) ($manifest['limitations'] ?? []) as $limitation) {
            $html .= '<li>' . esc_html((string) $limitation) . '</li>';
        }
        $html .= '</ul></div><p class="meta">Report ID: ' . esc_html((string) ($manifest['report_id'] ?? '')) . '</p></body></html>';
        return $html;
    }

    private static function html_table(array $columns, array $rows): string
    {
        $html = '<table><thead><tr>';
        foreach ($columns as $column) {
            $label = $column === 'taxable_sales'
                ? 'total taxable sales (including shipping)'
                : str_replace('_', ' ', $column);
            $html .= '<th>' . esc_html($label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        if (empty($rows)) {
            return $html . '<tr><td colspan="' . count($columns) . '">No records</td></tr></tbody></table>';
        }
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $class = self::is_money_column($column) ? ' class="money"' : '';
                $html .= '<td' . $class . '>' . esc_html((string) ($row[$column] ?? '')) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table>';
    }

    /**
     * Produce a simple, dependency-free printable PDF summary.
     */
    private static function build_pdf_summary(array $report): string
    {
        $manifest = (array) ($report['manifest'] ?? []);
        $filters = (array) ($manifest['filters'] ?? []);
        $stats = (array) ($report['stats'] ?? []);
        $lines = [
            'SALES TAX FILING REPORT',
            (string) ($manifest['site_name'] ?? ''),
            'Period: ' . ($filters['date_from'] ?? '') . ' through ' . ($filters['date_to'] ?? ''),
            'Generated UTC: ' . ($manifest['generated_at_utc'] ?? ''),
            'Report ID: ' . ($manifest['report_id'] ?? ''),
            '',
            'Orders: ' . ($stats['orders'] ?? 0) . '   Refunds: ' . ($stats['refunds'] ?? 0),
            '',
            'FILING TOTALS BY CURRENCY',
            'Cur Orders  Taxable incl. shipping  Non-taxable  Review sales  Net tax collected  Calculated tax  Over/(under)',
        ];

        foreach ((array) ($report['summaries']['filing_totals'] ?? []) as $row) {
            $lines[] = sprintf(
                '%-3s %6s %22s %12s %12s %18s %15s %14s',
                $row['currency'] ?? '',
                $row['orders'] ?? 0,
                $row['taxable_sales'] ?? '0.00',
                $row['non_taxable_sales'] ?? '0.00',
                $row['needs_review_sales'] ?? '0.00',
                $row['net_tax'] ?? '0.00',
                $row['calculated_tax'] ?? '0.00',
                $row['over_under'] ?? '0.00'
            );
        }

        $lines[] = '';
        $lines[] = 'STATE FILING SUMMARY';
        $lines[] = 'State Cur Orders  Taxable incl. shipping  Non-taxable  Review sales  Net collected  Tax due  Difference  Status';
        foreach ((array) ($report['summaries']['states'] ?? []) as $row) {
            $lines[] = sprintf(
                '%-5s %-3s %5s %22s %12s %12s %14s %9s %11s  %s',
                $row['state'] ?? '',
                $row['currency'] ?? '',
                $row['orders'] ?? 0,
                $row['taxable_sales'] ?? '0.00',
                $row['non_taxable_sales'] ?? '0.00',
                $row['needs_review_sales'] ?? '0.00',
                $row['net_tax'] ?? '0.00',
                $row['calculated_tax'] ?? '0.00',
                $row['over_under'] ?? '0.00',
                $row['filing_status'] ?? ''
            );
        }

        $lines[] = '';
        $lines[] = 'JURISDICTIONS WITH ACTIVITY';
        $lines[] = 'State Type     Jurisdiction                 Rate   Taxable incl. shipping  Net collected  Tax due  Difference  Status';
        foreach ((array) ($report['summaries']['jurisdictions'] ?? []) as $row) {
            $lines[] = sprintf(
                '%-5s %-8.8s %-28.28s %6s%% %22s %14s %9s %11s  %s',
                $row['state'] ?? '',
                $row['jurisdiction_type'] ?? '',
                $row['jurisdiction_name'] ?? '',
                $row['rate_percent'] ?? '0',
                $row['taxable_sales'] ?? '0.00',
                $row['net_tax'] ?? '0.00',
                $row['calculated_tax'] ?? '0.00',
                $row['over_under'] ?? '0.00',
                $row['filing_status'] ?? ''
            );
        }

        $lines[] = '';
        $lines[] = 'Total taxable sales includes all taxed product, fee, and shipping lines and is the single filing-base amount.';
        $lines[] = 'Calculated tax uses the effective rate stored with each WooCommerce order.';
        $lines[] = 'Review any state or jurisdiction marked Needs review before filing.';
        $lines[] = 'This report helps prepare returns; the filing portal and tax professional remain authoritative.';

        return self::pdf_from_lines($lines);
    }

    private static function pdf_from_lines(array $lines): string
    {
        $pages = array_chunk($lines, 44);
        if (empty($pages)) {
            $pages = [[]];
        }

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';
        $kids = [];
        $object_id = 4;
        foreach ($pages as $page_lines) {
            $page_id = $object_id++;
            $content_id = $object_id++;
            $kids[] = $page_id . ' 0 R';
            $stream = "BT\n/F1 8 Tf\n42 760 Td\n";
            foreach ($page_lines as $line) {
                $stream .= '(' . self::pdf_escape(self::ascii((string) $line)) . ") Tj\n0 -16 Td\n";
            }
            $stream .= "ET\n";
            $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . $content_id . ' 0 R >>';
            $objects[$content_id] = '<< /Length ' . strlen($stream) . ">>\nstream\n" . $stream . 'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    private static function build_readme(array $report): string
    {
        $manifest = (array) ($report['manifest'] ?? []);
        $filters = (array) ($manifest['filters'] ?? []);
        $advanced = ($filters['report_detail'] ?? 'filing') === 'advanced';
        $states = !empty($filters['states']) && is_array($filters['states'])
            ? implode(', ', $filters['states'])
            : 'All states';
        $detail_files = $advanced
            ? "4. orders.csv, order-lines.csv, tax-lines.csv and refunds.csv\r\n"
                . "5. product-summary.csv, payment-summary.csv and exceptions.csv\r\n"
                . "6. report-manifest.json for filters, data-quality metrics, row counts and file checksums\r\n"
            : "4. report-manifest.json for filters, data-quality metrics, row counts and file checksums\r\n";

        return "FFL Funnels Addons - WooCommerce Tax Report\r\n"
            . "================================================\r\n\r\n"
            . "This package is designed to give your accountant the most complete tax workpaper available from this WooCommerce site. It does not file a return and does not replace professional accounting or legal review.\r\n\r\n"
            . "Report detail: " . ($advanced ? 'Advanced audit' : 'Filing') . "\r\n"
            . "State scope: " . $states . "\r\n"
            . "Negative orders: " . (!empty($filters['include_negative_orders']) ? 'Included' : 'Excluded') . "\r\n\r\n"
            . "Recommended review order:\r\n"
            . "1. tax-filing-summary.pdf or tax-filing-summary.html\r\n"
            . "2. filing-totals.csv\r\n"
            . "3. state-summary.csv and jurisdiction-summary.csv\r\n"
            . $detail_files . "\r\n"
            . "The XLSX workbook contains the same tabular datasets in one file. The HTML summary can be opened in a browser and printed or saved as PDF.\r\n\r\n"
            . ($advanced
                ? "Advanced mode includes order, line, tax, refund, product, payment and exception audit datasets. Filing mode intentionally omits these detailed datasets except for the optional PII-controlled order audit.\r\n\r\n"
                : "Filing mode intentionally keeps the package concise. Choose advanced detail when order, line, tax, refund, product, payment and exception audit datasets are required.\r\n\r\n")
            . "The taxable_sales column is total taxable sales including every taxed shipping line. It is the single filing-base amount; shipping must not be added again.\r\n\r\n"
            . "Report ID: " . ($manifest['report_id'] ?? '') . "\r\n"
            . "Generated UTC: " . ($manifest['generated_at_utc'] ?? '') . "\r\n";
    }

    private static function zip_string(array $files, string $name): string
    {
        $tmp = function_exists('wp_tempnam') ? wp_tempnam($name) : tempnam(sys_get_temp_dir(), 'ffla-zip-');
        if (!$tmp) {
            return '';
        }
        if (!self::create_zip_file($tmp, $files)) {
            @unlink($tmp);
            return '';
        }
        $contents = file_get_contents($tmp);
        @unlink($tmp);
        return is_string($contents) ? $contents : '';
    }

    /**
     * Write string entries using ZipArchive or WordPress' bundled PclZip.
     */
    private static function create_zip_file(string $path, array $files): bool
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return false;
            }
            foreach ($files as $filename => $contents) {
                $zip->addFromString((string) $filename, (string) $contents);
            }
            return $zip->close();
        }

        if (!class_exists('PclZip')) {
            $pclzip = ABSPATH . 'wp-admin/includes/class-pclzip.php';
            if (is_readable($pclzip)) {
                require_once $pclzip;
            }
        }
        if (!function_exists('gzopen')
            || !class_exists('PclZip')
            || !defined('PCLZIP_ATT_FILE_NAME')
            || !defined('PCLZIP_ATT_FILE_CONTENT')) {
            return false;
        }

        $entries = [];
        foreach ($files as $filename => $contents) {
            $entries[] = [
                PCLZIP_ATT_FILE_NAME    => (string) $filename,
                PCLZIP_ATT_FILE_CONTENT => (string) $contents,
            ];
        }
        @unlink($path);
        $archive = new PclZip($path);
        return $archive->create($entries) !== 0;
    }

    private static function is_numeric_column(string $column): bool
    {
        return in_array($column, array_merge([
            'orders', 'count', 'quantity', 'refunded_quantity', 'rate_percent', 'tax_quote_rate_percent',
        ], self::money_columns()), true);
    }

    private static function is_money_column(string $column): bool
    {
        return in_array($column, self::money_columns(), true);
    }

    private static function money_columns(): array
    {
        return [
            'gross_product_sales', 'gross_sales', 'discounts', 'net_product_sales', 'net_sales', 'shipping',
            'fees', 'sales_with_tax', 'sales_without_tax', 'taxable_sales', 'taxable_shipping', 'non_taxable_sales',
            'needs_review_sales', 'calculated_tax', 'over_under', 'subtotal', 'subtotal_tax', 'total_ex_tax', 'tax',
            'total_inc_tax', 'product_tax', 'shipping_tax', 'tax_collected', 'tax_refunded', 'net_tax',
            'refunds', 'refund_amount', 'refunded_amount', 'amount', 'product_refund', 'shipping_refund',
            'fee_refund', 'order_total', 'net_collected', 'vendor_price', 'cogs_value',
        ];
    }

    private static function column_letter(int $number): string
    {
        $letter = '';
        while ($number > 0) {
            $number--;
            $letter = chr(65 + ($number % 26)) . $letter;
            $number = (int) floor($number / 26);
        }
        return $letter;
    }

    private static function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function ascii(string $value): string
    {
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        return (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
    }

    private static function pdf_escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }

    private static function wrap_ascii(string $value, int $width): array
    {
        return explode("\n", wordwrap(self::ascii($value), $width, "\n", true));
    }
}
