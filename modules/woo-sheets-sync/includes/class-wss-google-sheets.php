<?php
/**
 * WSS Google Sheets — API v4 wrapper using WP HTTP API.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class WSS_Google_Sheets
{
    private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    /** @var WSS_Google_OAuth */
    private $oauth;

    public function __construct(WSS_Google_OAuth $oauth)
    {
        $this->oauth = $oauth;
    }

    /**
     * Make an authenticated request to the Google Sheets API.
     *
     * @param string $method HTTP method (GET, POST, PUT).
     * @param string $url    Full API URL.
     * @param array  $body   Request body (will be JSON-encoded for POST/PUT).
     * @return array|WP_Error Decoded JSON response or WP_Error.
     */
    public function request(string $method, string $url, array $body = [])
    {
        $token = $this->oauth->get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 60,
        ];

        if (!empty($body) && in_array($method, ['POST', 'PUT'], true)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $decoded     = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code < 200 || $status_code >= 300) {
            $error_msg = $decoded['error']['message']
                ?? $decoded['error']['status']
                ?? sprintf(__('Google Sheets API error (HTTP %d)', 'ffl-funnels-addons'), $status_code);
            return new WP_Error('wss_sheets_api', $error_msg);
        }

        return $decoded ?? [];
    }

    /**
     * Read a range of values from a spreadsheet.
     *
     * @param string $spreadsheet_id Google Sheet ID.
     * @param string $range          A1 notation (e.g. "Inventory!A:K").
     * @return array|WP_Error Array of row arrays, or WP_Error.
     */
    public function read_range(string $spreadsheet_id, string $range)
    {
        $url = sprintf(
            '%s/%s/values/%s',
            self::API_BASE,
            urlencode($spreadsheet_id),
            urlencode($range)
        );

        $result = $this->request('GET', $url);

        if (is_wp_error($result)) {
            return $result;
        }

        return $result['values'] ?? [];
    }

    /**
     * Write values to a specific range (overwrites existing data).
     *
     * @param string $spreadsheet_id Google Sheet ID.
     * @param string $range          A1 notation.
     * @param array  $values         Array of row arrays.
     * @return array|WP_Error
     */
    public function write_range(string $spreadsheet_id, string $range, array $values)
    {
        $url = sprintf(
            '%s/%s/values/%s?valueInputOption=USER_ENTERED',
            self::API_BASE,
            urlencode($spreadsheet_id),
            urlencode($range)
        );

        return $this->request('PUT', $url, [
            'range'  => $range,
            'values' => $values,
        ]);
    }

    /**
     * Batch update multiple ranges in a single API call.
     *
     * @param string $spreadsheet_id Google Sheet ID.
     * @param array  $data           Array of ['range' => '...', 'values' => [[...]]].
     * @return array|WP_Error
     */
    public function batch_update(string $spreadsheet_id, array $data)
    {
        if (empty($data)) {
            return [];
        }

        $url = sprintf(
            '%s/%s/values:batchUpdate',
            self::API_BASE,
            urlencode($spreadsheet_id)
        );

        return $this->request('POST', $url, [
            'valueInputOption' => 'USER_ENTERED',
            'data'             => $data,
        ]);
    }

    /**
     * Append rows after the last row with data.
     *
     * @param string $spreadsheet_id Google Sheet ID.
     * @param string $range          A1 notation (e.g. "Inventory!A:K").
     * @param array  $values         Array of row arrays to append.
     * @return array|WP_Error
     */
    public function append_rows(string $spreadsheet_id, string $range, array $values)
    {
        if (empty($values)) {
            return [];
        }

        $url = sprintf(
            '%s/%s/values/%s:append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS',
            self::API_BASE,
            urlencode($spreadsheet_id),
            urlencode($range)
        );

        return $this->request('POST', $url, [
            'values' => $values,
        ]);
    }

    /**
     * Ensure the header row exists in the sheet. Writes headers if row 1 is empty.
     *
     * @param string $spreadsheet_id Google Sheet ID.
     * @param string $tab_name       Sheet tab name.
     * @return true|WP_Error
     */
    public function ensure_headers(string $spreadsheet_id, string $tab_name)
    {
        $safe_tab = str_replace("'", "''", $tab_name);
        $range    = "'" . $safe_tab . "'!A1:L1";
        $result = $this->read_range($spreadsheet_id, $range);

        if (is_wp_error($result)) {
            return $result;
        }

        $headers = [
            'product_id',
            'variation_id',
            'product_name',
            'attributes',
            'sku',
            'regular_price',
            'sale_price',
            'stock_qty',
            'stock_status',
            'manage_stock',
            'woo_updated_at',
            'sheet_updated_at',
        ];

        // Write headers if row 1 is empty or doesn't match.
        if (empty($result) || empty($result[0]) || $result[0] !== $headers) {
            $write_result = $this->write_range($spreadsheet_id, $range, [$headers]);
            if (is_wp_error($write_result)) {
                return $write_result;
            }
        }

        return true;
    }
}
