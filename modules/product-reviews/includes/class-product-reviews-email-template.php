<?php
/**
 * Review request emails: subject and body, as HTML or plain text.
 *
 * The HTML layout is a single 600px table (it renders in Gmail, Outlook and
 * Apple Mail without a CSS file): logo or store name, heading, the store's
 * text, one row per product with five clickable stars, a button to the
 * review page, and a footer with the store address and the unsubscribe
 * link. A star link opens the review page with that rating already picked
 * for that product. Colours, logo and footer fall back to WooCommerce's own
 * email settings, so the emails look like the store's order emails until
 * they are changed here.
 *
 * Plain text keeps the module's original format: heading, blank line, body,
 * and an unsubscribe line when the body has no {unsubscribe_url}.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Email_Template
{
    /**
     * @param array<string, mixed> $data customer_name, products (list of id, name, url, image),
     *                                   review_url, unsubscribe_url, order_id, order_number,
     *                                   user_id, pixel_url, mode (bundle / single).
     * @return array<string, string> {placeholder} => value (unescaped)
     */
    public static function placeholders(array $data): array
    {
        $names = [];
        foreach ((array) ($data['products'] ?? []) as $product) {
            $names[] = (string) ($product['name'] ?? '');
        }
        $names = implode(', ', array_filter($names));

        return [
            '{customer_name}'      => (string) ($data['customer_name'] ?? ''),
            '{product_name}'       => $names,
            '{product_names_list}' => $names,
            '{review_url}'         => (string) ($data['review_url'] ?? ''),
            '{review_order_url}'   => (string) ($data['review_url'] ?? ''),
            '{order_id}'           => (string) ($data['order_id'] ?? ''),
            '{order_number}'       => (string) ($data['order_number'] ?? ($data['order_id'] ?? '')),
            '{user_id}'            => (string) ($data['user_id'] ?? '0'),
            '{unsubscribe_url}'    => (string) ($data['unsubscribe_url'] ?? ''),
            '{store_name}'         => self::store_name(),
            '{review_link_days}'   => (string) Product_Reviews_Core::review_link_days(),
        ];
    }

    public static function store_name(): string
    {
        return trim(wp_specialchars_decode((string) get_option('blogname', ''), ENT_QUOTES));
    }

    public static function is_html(): bool
    {
        return 'plain' !== Product_Reviews_Core::get_setting('email_format', 'html');
    }

    /**
     * @param string               $kind request / reminder
     * @param array<string, mixed> $data
     */
    public static function subject(string $kind, array $data): string
    {
        if ('reminder' === $kind) {
            $subject = trim((string) Product_Reviews_Core::get_setting('email_reminder_subject', ''));
            if ($subject === '') {
                $subject = __('A quick reminder: how was your order?', 'ffl-funnels-addons');
            }
        } else {
            $subject = trim(sanitize_text_field((string) Product_Reviews_Core::get_setting('email_subject', '')));
            if ($subject === '') {
                $subject = __('How was your purchase?', 'ffl-funnels-addons');
            }
        }

        // Subjects are plain text; strip anything a placeholder might add.
        return trim(wp_strip_all_tags(strtr($subject, self::placeholders($data))));
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function heading(string $kind, array $data): string
    {
        $key     = 'reminder' === $kind ? 'email_reminder_heading' : 'email_heading';
        $heading = trim(sanitize_text_field((string) Product_Reviews_Core::get_setting($key, '')));
        if ($heading === '' && 'reminder' === $kind) {
            $heading = trim(sanitize_text_field((string) Product_Reviews_Core::get_setting('email_heading', '')));
        }

        return trim(wp_strip_all_tags(strtr($heading, self::placeholders($data))));
    }

    /**
     * Built-in text for a kind of email. HTML emails carry the link in a
     * button and the products in their own rows, so their text has neither.
     */
    public static function default_text(string $kind, string $mode, bool $html): string
    {
        if ($html) {
            if ('reminder' === $kind) {
                return __("Hi {customer_name},\n\nJust a quick reminder: you can still review your recent order from {store_name}. It only takes a minute, and it helps other customers choose.", 'ffl-funnels-addons');
            }

            return 'single' === $mode
                ? __("Hi {customer_name},\n\nThanks for shopping with {store_name}. Would you take a minute to tell other customers what you think of {product_name}?", 'ffl-funnels-addons')
                : __("Hi {customer_name},\n\nThanks for shopping with {store_name}. Would you take a minute to tell other customers what you think of your purchase? Tap a star to start.", 'ffl-funnels-addons');
        }

        if ('reminder' === $kind) {
            return __("Hi {customer_name},\n\nJust a quick reminder: you can still review your recent order from {store_name}:\n{product_names_list}\n\nLeave your reviews here:\n{review_order_url}\n\nThank you!", 'ffl-funnels-addons');
        }

        return 'single' === $mode
            ? __("Hi {customer_name},\n\nWe would love your feedback on {product_name}.\n\nLeave your review here:\n{review_url}\n\nThank you!", 'ffl-funnels-addons')
            : __("Hi {customer_name},\n\nWe would love your feedback on:\n{product_names_list}\n\nLeave your reviews here:\n{review_order_url}\n\nThank you!", 'ffl-funnels-addons');
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags(str_replace("\r", '', $text))));
    }

    /**
     * The store's own text, unless it is empty or still the stock text, in
     * which case the built-in text for this kind of email is used (the
     * stock text is written for one product and has the bare link in it).
     */
    private static function body_template(string $kind, string $mode, bool $html): string
    {
        $template = (string) Product_Reviews_Core::get_setting('email_template', '');
        $stock    = (string) (Product_Reviews_Core::get_default_settings()['email_template'] ?? '');

        if (self::normalize($template) === '' || self::normalize($template) === self::normalize($stock)) {
            return self::default_text($kind, $mode, $html);
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{subject:string,body:string,html:bool}
     */
    public static function render(string $kind, array $data): array
    {
        $html = self::is_html();
        $mode = 'single' === ($data['mode'] ?? '') ? 'single' : 'bundle';

        return [
            'subject' => self::subject($kind, $data),
            'body'    => $html ? self::render_html($kind, $mode, $data) : self::render_plain($kind, $mode, $data),
            'html'    => $html,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function render_plain(string $kind, string $mode, array $data): string
    {
        $template = self::body_template($kind, $mode, false);
        $text     = wp_strip_all_tags(str_replace(['<br>', '<br />', '<br/>', '</p>'], ["\n", "\n", "\n", "\n\n"], $template));

        $body = strtr($text, self::placeholders($data));
        $body = trim(self::heading($kind, $data) . "\n\n" . $body);

        // Every review request must carry a way out. If the shop placed the
        // placeholder themselves, respect where they put it.
        if (strpos($text, '{unsubscribe_url}') === false) {
            $body .= "\n\n---\n" . sprintf(
                /* translators: %s: unsubscribe URL */
                __('Do not want these emails? Unsubscribe here: %s', 'ffl-funnels-addons'),
                (string) ($data['unsubscribe_url'] ?? '')
            );
        }

        return $body;
    }

    /**
     * Colours, logo, button and footer, with WooCommerce's email settings
     * as the fallback.
     *
     * @return array<string, string>
     */
    public static function design(): array
    {
        $pick = static function (string $setting, string $woo, string $default): string {
            $value = sanitize_hex_color((string) Product_Reviews_Core::get_setting($setting, ''));
            if (!$value) {
                $value = sanitize_hex_color((string) get_option($woo, ''));
            }

            return $value ? $value : $default;
        };

        $logo    = '';
        $logo_id = absint(Product_Reviews_Core::get_setting('email_logo_id', '0'));
        if ($logo_id > 0) {
            $logo = (string) wp_get_attachment_image_url($logo_id, 'medium');
        }
        if ($logo === '') {
            $logo = (string) get_option('woocommerce_email_header_image', '');
        }

        $button = trim((string) Product_Reviews_Core::get_setting('email_button_text', ''));

        return [
            'brand'  => $pick('email_brand_color', 'woocommerce_email_base_color', '#1f2937'),
            'bg'     => $pick('email_background_color', 'woocommerce_email_background_color', '#f4f5f7'),
            'text'   => $pick('email_text_color', 'woocommerce_email_text_color', '#1f2937'),
            'star'   => $pick('email_star_color', '', '#d4a017'),
            'logo'   => $logo,
            'button' => $button !== '' ? $button : __('Leave a review', 'ffl-funnels-addons'),
            'footer' => self::footer_text(),
        ];
    }

    /**
     * The module's footer text, else WooCommerce's email footer, else the
     * store name and address (a sender address is expected in emails like
     * these).
     */
    private static function footer_text(): string
    {
        $footer = trim((string) Product_Reviews_Core::get_setting('email_footer_text', ''));
        if ($footer === '') {
            $footer = trim((string) get_option('woocommerce_email_footer_text', ''));
        }

        $address = implode(', ', array_filter([
            (string) get_option('woocommerce_store_address', ''),
            (string) get_option('woocommerce_store_city', ''),
            (string) get_option('woocommerce_store_postcode', ''),
        ]));

        if ($footer === '') {
            $footer = self::store_name() . ($address !== '' ? ' · ' . $address : '');
        }

        return strtr($footer, [
            '{site_title}'    => self::store_name(),
            '{site_url}'      => home_url('/'),
            '{store_address}' => $address,
            '{site_address}'  => $address,
            '{store_name}'    => self::store_name(),
        ]);
    }

    /**
     * Placeholder values made safe for HTML. URLs go through esc_url(), so a
     * {review_url} inside the store's own href stays a working link.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function html_placeholders(array $data): array
    {
        $out = [];
        foreach (self::placeholders($data) as $key => $value) {
            $out[$key] = in_array($key, ['{review_url}', '{review_order_url}', '{unsubscribe_url}'], true)
                ? esc_url($value)
                : esc_html($value);
        }

        return $out;
    }

    /**
     * The review page with this rating picked for this product, scrolled to
     * its form.
     */
    public static function rate_url(string $review_url, int $product_id, int $stars): string
    {
        return add_query_arg('ffla_rate[' . $product_id . ']', (string) $stars, $review_url) . '#ffla-order-review-' . $product_id;
    }

    /**
     * Text from the editor (filtered by wp_kses_post on save) with the
     * placeholders filled in; plain text gets paragraphs.
     *
     * @param array<string, string> $placeholders Already escaped for HTML.
     */
    public static function text_html(string $template, array $placeholders): string
    {
        $text = wp_kses_post($template);
        if ($text === wp_strip_all_tags($text)) {
            $text = wpautop($text);
        }
        $text = strtr($text, $placeholders);

        return str_replace('<p>', '<p style="margin:0 0 14px;">', $text);
    }

    /**
     * The HTML frame every email of the module shares: logo or store name,
     * heading, text, a middle block (product rows, a coupon), an optional
     * button and the footer.
     *
     * @param array<string, string> $parts subject, heading, text (HTML), middle (HTML), button_label,
     *                                     button_url, unsubscribe_url (adds the link), pixel_url.
     */
    public static function wrap(array $parts): string
    {
        $design = self::design();
        $font   = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

        $html  = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        $html .= '<meta name="x-apple-disable-message-reformatting"><title>' . esc_html($parts['subject'] ?? '') . '</title></head>';
        $html .= '<body style="margin:0;padding:0;background-color:' . esc_attr($design['bg']) . ';">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:' . esc_attr($design['bg']) . ';"><tr><td align="center" style="padding:24px 12px;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background-color:#ffffff;border-radius:8px;">';

        // Header: logo or store name.
        $html .= '<tr><td align="center" style="padding:24px 32px;border-bottom:1px solid #eceef1;font-family:' . $font . ';">';
        if ($design['logo'] !== '') {
            $html .= '<img src="' . esc_url($design['logo']) . '" alt="' . esc_attr(self::store_name()) . '" style="display:block;max-width:200px;max-height:80px;width:auto;height:auto;border:0;">';
        } else {
            $html .= '<span style="font-size:20px;font-weight:700;color:' . esc_attr($design['text']) . ';">' . esc_html(self::store_name()) . '</span>';
        }
        $html .= '</td></tr>';

        // Heading, text, middle, button.
        $html .= '<tr><td style="padding:28px 32px 8px;font-family:' . $font . ';font-size:16px;line-height:1.55;color:' . esc_attr($design['text']) . ';">';
        if (!empty($parts['heading'])) {
            $html .= '<h1 style="margin:0 0 16px;font-size:22px;line-height:1.3;color:' . esc_attr($design['text']) . ';">' . esc_html($parts['heading']) . '</h1>';
        }
        $html .= $parts['text'] ?? '';
        $html .= $parts['middle'] ?? '';

        if (!empty($parts['button_url'])) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:24px auto 20px;"><tr><td align="center" style="border-radius:6px;background-color:' . esc_attr($design['brand']) . ';">';
            $html .= '<a href="' . esc_url($parts['button_url']) . '" style="display:inline-block;padding:14px 28px;font-family:' . $font . ';font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:6px;">' . esc_html($parts['button_label'] ?? '') . '</a>';
            $html .= '</td></tr></table>';
        }
        $html .= '</td></tr>';

        // Footer: address and, for requests, the way out.
        $html .= '<tr><td align="center" style="padding:16px 32px 24px;border-top:1px solid #eceef1;font-family:' . $font . ';font-size:12px;line-height:1.5;color:#6b7280;">';
        if ($design['footer'] !== '') {
            $html .= wp_kses_post(nl2br($design['footer'])) . '<br>';
        }
        if (!empty($parts['unsubscribe_url'])) {
            $html .= '<a href="' . esc_url($parts['unsubscribe_url']) . '" style="color:#6b7280;text-decoration:underline;">'
                . esc_html__('Unsubscribe from review requests', 'ffl-funnels-addons') . '</a>';
        }
        $html .= '</td></tr>';

        $html .= '</table></td></tr></table>';

        if (!empty($parts['pixel_url'])) {
            $html .= '<img src="' . esc_url($parts['pixel_url']) . '" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">';
        }

        $html .= '</body></html>';

        return $html;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function render_html(string $kind, string $mode, array $data): string
    {
        $design   = self::design();
        $font     = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $template = self::body_template($kind, $mode, true);
        $review   = (string) ($data['review_url'] ?? '');

        $middle   = '';
        $products = (array) ($data['products'] ?? []);
        if (!empty($products) && '1' === Product_Reviews_Core::get_setting('email_show_products', '1')) {
            $middle .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 4px;">';
            foreach ($products as $product) {
                $pid     = (int) ($product['id'] ?? 0);
                $middle .= '<tr><td width="72" valign="middle" style="padding:10px 12px 10px 0;border-top:1px solid #eceef1;">';
                if (!empty($product['image'])) {
                    $middle .= '<img src="' . esc_url((string) $product['image']) . '" width="64" height="64" alt="" style="display:block;width:64px;height:64px;border-radius:6px;object-fit:cover;border:0;">';
                }
                $middle .= '</td><td valign="middle" style="padding:10px 0;border-top:1px solid #eceef1;font-family:' . $font . ';">';
                $middle .= '<div style="font-size:15px;font-weight:600;color:' . esc_attr($design['text']) . ';">' . esc_html((string) ($product['name'] ?? '')) . '</div>';
                if ($review !== '' && $pid > 0) {
                    $middle .= '<div style="margin-top:4px;font-size:0;line-height:1;">';
                    for ($stars = 1; $stars <= 5; $stars++) {
                        $middle .= '<a href="' . esc_url(self::rate_url($review, $pid, $stars)) . '" title="' . esc_attr(sprintf(
                            /* translators: %d: star rating 1-5 */
                            _n('%d star', '%d stars', $stars, 'ffl-funnels-addons'),
                            $stars
                        )) . '" style="display:inline-block;padding:0 3px 0 0;font-size:26px;line-height:1;color:' . esc_attr($design['star']) . ';text-decoration:none;">&#9733;</a>';
                    }
                    $middle .= '</div>';
                }
                $middle .= '</td></tr>';
            }
            $middle .= '</table>';
        }

        return self::wrap([
            'subject'         => self::subject($kind, $data),
            'heading'         => self::heading($kind, $data),
            'text'            => self::text_html($template, self::html_placeholders($data)),
            'middle'          => $middle,
            'button_label'    => $design['button'],
            'button_url'      => $review,
            'unsubscribe_url' => strpos($template, '{unsubscribe_url}') === false ? (string) ($data['unsubscribe_url'] ?? '') : '',
            'pixel_url'       => (string) ($data['pixel_url'] ?? ''),
        ]);
    }
}
