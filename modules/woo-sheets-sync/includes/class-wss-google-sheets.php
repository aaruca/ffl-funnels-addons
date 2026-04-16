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

    /**
     * Per-request cache of sheet titles keyed by spreadsheet id.
     *
     * @var array<string,array<string,bool>>
     */
    private $tab_title_cache = [];

    public function __construct(WSS_Google_OAuth $oauth)
    {
        $this->oauth = $oauth;
    }

    /**
     * Invalidate the in-memory sheet title cache for a spreadsheet.
     */
    private function invalidate_tab_title_cache(string $spreadsheet_id): void
    {
        unset($this->tab_title_cache[$spreadsheet_id]);
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

        // Retry transient failures (429 / 5xx / network errors) with
        // exponential backoff. Non-mutating (GET) requests get more retries
        // since they are always idempotent.
        $max_attempts = ($method === 'GET') ? 3 : 2;
        $attempt      = 0;
        $response     = null;
        $status_code  = 0;
        $decoded      = null;

        while ($attempt < $max_attempts) {
            $attempt++;
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                if ($attempt >= $max_attempts) {
                    return $response;
                }
                usleep(250000 * (1 << ($attempt - 1))); // 250ms, 500ms, 1s...
                continue;
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            $decoded     = json_decode(wp_remote_retrieve_body($response), true);

            $retryable = ($status_code === 429 || ($status_code >= 500 && $status_code < 600));
            if (!$retryable || $attempt >= $max_attempts) {
                break;
            }

            // Honor Retry-After header when present.
            $retry_after = (int) wp_remote_retrieve_header($response, 'retry-after');
            if ($retry_after > 0 && $retry_after <= 10) {
                sleep($retry_after);
            } else {
                usleep(500000 * (1 << ($attempt - 1))); // 500ms, 1s, 2s...
            }
        }

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
        $tab_result = $this->ensure_tab_exists($spreadsheet_id, $tab_name);
        if (is_wp_error($tab_result)) {
            return $tab_result;
        }

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

    /**
     * Create the sheet tab when it does not exist yet.
     *
     * @return true|WP_Error
     */
    private function ensure_tab_exists(string $spreadsheet_id, string $tab_name)
    {
        // Fast-path: hit the per-request cache when we already know this tab.
        if (isset($this->tab_title_cache[$spreadsheet_id][$tab_name])) {
            return true;
        }

        if (!isset($this->tab_title_cache[$spreadsheet_id])) {
            $url = sprintf(
                '%s/%s?fields=sheets.properties.title',
                self::API_BASE,
                urlencode($spreadsheet_id)
            );

            $meta = $this->request('GET', $url);
            if (is_wp_error($meta)) {
                return $meta;
            }

            $titles = [];
            foreach (($meta['sheets'] ?? []) as $sheet) {
                $title = (string) ($sheet['properties']['title'] ?? '');
                if ($title !== '') {
                    $titles[$title] = true;
                }
            }
            $this->tab_title_cache[$spreadsheet_id] = $titles;
        }

        if (isset($this->tab_title_cache[$spreadsheet_id][$tab_name])) {
            return true;
        }

        $batch_url = sprintf(
            '%s/%s:batchUpdate',
            self::API_BASE,
            urlencode($spreadsheet_id)
        );

        $create = $this->request('POST', $batch_url, [
            'requests' => [
                [
                    'addSheet' => [
                        'properties' => [
                            'title' => $tab_name,
                        ],
                    ],
                ],
            ],
        ]);

        if (is_wp_error($create)) {
            return $create;
        }

        $this->tab_title_cache[$spreadsheet_id][$tab_name] = true;
        return true;
    }

    /**
     * Delete a sheet tab by title if it exists.
     *
     * @return true|WP_Error
     */
    public function delete_tab_if_exists(string $spreadsheet_id, string $tab_name)
    {
        $url = sprintf(
            '%s/%s?fields=sheets.properties(sheetId,title)',
            self::API_BASE,
            urlencode($spreadsheet_id)
        );

        $meta = $this->request('GET', $url);
        if (is_wp_error($meta)) {
            return $meta;
        }

        $sheets = is_array($meta['sheets'] ?? null) ? $meta['sheets'] : [];
        if (count($sheets) <= 1) {
            return true; // Never attempt deleting the last remaining tab.
        }

        $sheet_id = 0;
        foreach ($sheets as $sheet) {
            $title = (string) ($sheet['properties']['title'] ?? '');
            if ($title === $tab_name) {
                $sheet_id = (int) ($sheet['properties']['sheetId'] ?? 0);
                break;
            }
        }

        if ($sheet_id <= 0) {
            return true; // Nothing to delete.
        }

        $batch_url = sprintf(
            '%s/%s:batchUpdate',
            self::API_BASE,
            urlencode($spreadsheet_id)
        );

        $result = $this->request('POST', $batch_url, [
            'requests' => [
                [
                    'deleteSheet' => [
                        'sheetId' => $sheet_id,
                    ],
                ],
            ],
        ]);

        // Invalidate cached titles — the tab list just changed.
        $this->invalidate_tab_title_cache($spreadsheet_id);

        return is_wp_error($result) ? $result : true;
    }
}
