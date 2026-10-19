<?php
/**
 * MonsterInsights Compatibility — GA4 payload normalization.
 *
 * Pure helpers with no WordPress dependency, so they can be exercised offline
 * (tests/smoke/monsterinsights-payload-smoke.php).
 *
 * They correct two defects in what the MonsterInsights eCommerce Addon sends to
 * GA4 through the Measurement Protocol (server side):
 *  - item text is taken from get_the_title(), so names arrive HTML-encoded
 *    ("H&amp;R … 22&#8243;");
 *  - add_to_cart and remove_from_cart compute `value` from (int) price ×
 *    quantity, dropping the cents; begin_checkout has no value or currency.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Ga4_Bridge_Payload
{
    /** Item fields that carry display text. */
    private const ITEM_TEXT_FIELDS = [
        'item_name',
        'item_brand',
        'item_variant',
        'item_list_name',
        'item_category',
        'item_category2',
        'item_category3',
        'item_category4',
        'item_category5',
    ];

    /** Events whose `value` MonsterInsights computes with integer prices. */
    private const RECOMPUTED_VALUE_EVENTS = ['add_to_cart', 'remove_from_cart'];

    /** Events that MonsterInsights sends without value/currency. */
    private const MISSING_VALUE_EVENTS = ['begin_checkout'];

    /**
     * Plain text for GA4: tags removed, HTML entities decoded, whitespace trimmed.
     *
     * @param mixed $value
     * @return mixed Strings are cleaned; anything else is returned unchanged.
     */
    public static function clean_text($value)
    {
        if (!is_string($value) || '' === $value) {
            return $value;
        }

        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Clean the text fields of one GA4 item.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function clean_item(array $item): array
    {
        foreach (self::ITEM_TEXT_FIELDS as $field) {
            if (isset($item[$field])) {
                $item[$field] = self::clean_text($item[$field]);
            }
        }

        return $item;
    }

    /**
     * Sum of price × quantity, or null when an item has no numeric price.
     *
     * @param array<int, mixed> $items
     */
    public static function items_value(array $items, int $decimals): ?float
    {
        if (!$items) {
            return null;
        }

        $total = 0.0;
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['price']) || !is_numeric($item['price'])) {
                return null;
            }
            $quantity = isset($item['quantity']) && is_numeric($item['quantity']) ? (float) $item['quantity'] : 1.0;
            $total   += (float) $item['price'] * max(0.0, $quantity);
        }

        return round($total, max(0, $decimals));
    }

    /**
     * Normalize a Measurement Protocol request body built by MonsterInsights.
     *
     * Only item text and the value/currency of cart and checkout events change;
     * every other key (client_id, session_id, purchase totals, …) is untouched.
     *
     * @param mixed  $body     The body passed to `monsterinsights_mp_v4_api_call`.
     * @param string $currency Store currency, used when an event has none.
     * @param int    $decimals Price decimals, used to round recomputed values.
     * @return mixed
     */
    public static function normalize_body($body, string $currency, int $decimals)
    {
        if (!is_array($body) || empty($body['events']) || !is_array($body['events'])) {
            return $body;
        }

        foreach ($body['events'] as $index => $event) {
            if (!is_array($event) || !isset($event['params']) || !is_array($event['params'])) {
                continue;
            }

            $name   = isset($event['name']) ? (string) $event['name'] : '';
            $params = $event['params'];

            if (isset($params['items']) && is_array($params['items'])) {
                foreach ($params['items'] as $item_index => $item) {
                    if (is_array($item)) {
                        $params['items'][$item_index] = self::clean_item($item);
                    }
                }

                $recompute = in_array($name, self::RECOMPUTED_VALUE_EVENTS, true);
                $fill      = in_array($name, self::MISSING_VALUE_EVENTS, true) && !isset($params['value']);

                if ($recompute || $fill) {
                    $value = self::items_value($params['items'], $decimals);
                    if (null !== $value) {
                        $params['value'] = $value;
                        if (empty($params['currency']) && '' !== $currency) {
                            $params['currency'] = $currency;
                        }
                    }
                }
            }

            $body['events'][$index]['params'] = $params;
        }

        return $body;
    }
}
