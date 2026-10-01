<?php
/**
 * Customer requests — saved replies for staff.
 *
 * Placeholders: {first_name}, {customer_name}, {request_number},
 * {order_number}, {store_name}, {request_link}.
 *
 * @package FFL_Funnels_Addons
 */

defined('ABSPATH') || exit;

class FFLA_Requests_Replies
{
    const OPTION = 'ffla_requests_replies';
    const MAX = 50;

    public static function defaults(): array
    {
        return [
            ['title' => __('Need photos', 'ffl-funnels-addons'), 'body' => __("Hi {first_name},\n\nThanks for letting us know. Could you reply with a few photos of the item and the packaging (including the shipping label)? That helps us resolve request {request_number} faster.", 'ffl-funnels-addons')],
            ['title' => __('Return approved — label attached', 'ffl-funnels-addons'), 'body' => __("Hi {first_name},\n\nYour return {request_number} is approved. The prepaid label is attached on your request page. Please pack the item securely, write {request_number} on the box and reply with the tracking number once it ships.", 'ffl-funnels-addons')],
            ['title' => __('Refund issued', 'ffl-funnels-addons'), 'body' => __("Hi {first_name},\n\nWe issued your refund for order #{order_number}. Depending on your bank it can take 5–10 business days to show on your statement.", 'ffl-funnels-addons')],
            ['title' => __('Replacement shipped', 'ffl-funnels-addons'), 'body' => __("Hi {first_name},\n\nYour replacement is on its way. We will add the tracking number here as soon as the carrier scans it.", 'ffl-funnels-addons')],
            ['title' => __('Firearm return through an FFL', 'ffl-funnels-addons'), 'body' => __("Hi {first_name},\n\nFirearms have to come back through a licensed dealer. Please take the firearm to your FFL and ask them to ship it to us with a copy of their license, marked with {request_number}. Reply here with the dealer's tracking number once it ships.", 'ffl-funnels-addons')],
        ];
    }

    /** @return array<int, array{title:string, body:string}> */
    public static function all(): array
    {
        $saved = get_option(self::OPTION, null);
        return is_array($saved) ? $saved : self::defaults();
    }

    public static function save(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($out) >= self::MAX) {
                continue;
            }
            $title = substr(sanitize_text_field((string) ($row['title'] ?? '')), 0, 100);
            $body = substr(sanitize_textarea_field((string) ($row['body'] ?? '')), 0, FFLA_Requests::MAX_TEXT);
            if ('' !== $title && '' !== trim($body)) {
                $out[] = ['title' => $title, 'body' => $body];
            }
        }
        update_option(self::OPTION, $out, false);
        return $out;
    }

    public static function fill(string $body, $request): string
    {
        $first = explode(' ', trim((string) $request->customer_name))[0];
        return strtr($body, [
            '{first_name}'     => '' !== $first ? $first : __('there', 'ffl-funnels-addons'),
            '{customer_name}'  => (string) $request->customer_name,
            '{request_number}' => (string) $request->number,
            '{order_number}'   => (string) $request->order_number,
            '{store_name}'     => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            '{request_link}'   => FFLA_Requests::tracking_url($request),
        ]);
    }
}
