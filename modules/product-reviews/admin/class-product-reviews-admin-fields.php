<?php
/**
 * Product Reviews settings: which fields each tab shows, how they render and
 * how a posted value is cleaned before it is stored.
 *
 * Each tab is a list of cards and each card a list of field definitions:
 *
 *     [
 *         'key'      => 'request_delay_days', // settings key (omit for 'html')
 *         'type'     => 'number',             // see render_field()
 *         'label'    => '…',
 *         'desc'     => '…',                  // plain text, escaped on output
 *         'options'  => [value => label],     // select, multiselect
 *         'min'/'max'=> int,                  // number
 *         'fallback' => '…',                  // text: stored when left empty
 *         'sanitize' => callable,             // fn($raw, $field, $current) → value
 *         'store'    => false,                // read on save, never stored
 *     ]
 *
 * A form only posts its own tab, so saving reads and writes that tab's keys
 * and leaves every other setting as it was.
 *
 * @package FFL_Funnels_Addons
 */

if (!defined('ABSPATH')) {
    exit;
}

class Product_Reviews_Admin_Fields
{
    /**
     * @return array<string, string> slug => label, in display order.
     */
    public static function tabs(): array
    {
        return [
            'reviews'  => __('Reviews', 'ffl-funnels-addons'),
            'display'  => __('Display', 'ffl-funnels-addons'),
            'form'     => __('Review form', 'ffl-funnels-addons'),
            'requests' => __('Review requests', 'ffl-funnels-addons'),
            'emails'   => __('Emails', 'ffl-funnels-addons'),
        ];
    }

    /**
     * @return array<int, array{title:string,desc?:string,fields:array<int,array<string,mixed>>}>
     */
    public static function cards(string $tab): array
    {
        switch ($tab) {
            case 'reviews':
                return self::reviews_cards();
            case 'display':
                return self::display_cards();
            case 'form':
                return self::form_cards();
            case 'requests':
                return self::requests_cards();
            case 'emails':
                return self::emails_cards();
        }

        return [];
    }

    /**
     * Every storable field of a tab, flattened.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fields(string $tab): array
    {
        $fields = [];
        foreach (self::cards($tab) as $card) {
            foreach ($card['fields'] as $field) {
                if (!empty($field['key'])) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /* ---------------------------------------------------------------------
     * Tabs
     * ------------------------------------------------------------------- */

    private static function reviews_cards(): array
    {
        return [
            [
                'title'  => __('Product page', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'replace_default_reviews_tab',
                        'type'  => 'toggle',
                        'label' => __('Replace WooCommerce reviews tab with FFL form', 'ffl-funnels-addons'),
                        'desc'  => __('Show the FFL reviews list and review form inside the standard “Reviews” product tab (no Bricks block needed). “Hide default tab” is ignored while this is on. Use filters ffla_product_reviews_wc_tab_list_settings and ffla_product_reviews_wc_tab_form_settings to customize.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'hide_default_reviews_tab',
                        'type'  => 'toggle',
                        'label' => __('Hide default Woo reviews tab', 'ffl-funnels-addons'),
                        'desc'  => __('Turn on when you use only the Bricks Review Form / list / badge so shoppers do not see WooCommerce’s second, built-in reviews tab. Ignored if “Replace WooCommerce reviews tab” is enabled.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Moderation', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'moderate_all_reviews',
                        'type'  => 'toggle',
                        'label' => __('Hold all new reviews for moderation', 'ffl-funnels-addons'),
                        'desc'  => __('When enabled, every product review stays pending until an administrator approves it. Useful with or without media uploads.', 'ffl-funnels-addons'),
                    ],
                    [
                        'type' => 'html',
                        'html' => '<p class="wb-field__desc">' . wp_kses(
                            sprintf(
                                /* translators: %s: URL to the pending product reviews screen */
                                __('Pending reviews appear under Products → Reviews → Pending. <a href="%s">Open the pending queue</a>.', 'ffl-funnels-addons'),
                                esc_url(Product_Reviews_Core::reviews_admin_url('moderated'))
                            ),
                            ['a' => ['href' => []]]
                        ) . '</p>',
                    ],
                ],
            ],
            [
                'title'  => __('Content filter', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'forbidden_words',
                        'type'  => 'textarea',
                        'label' => __('Forbidden words', 'ffl-funnels-addons'),
                        'desc'  => __('One term per line, or comma separated. Matching is case-insensitive and matches inside longer words, exactly like WordPress\'s own disallowed comment keys. Leave empty to disable.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'forbidden_action',
                        'type'    => 'select',
                        'label'   => __('When a review matches', 'ffl-funnels-addons'),
                        'options' => [
                            'moderate' => __('Hold it for moderation', 'ffl-funnels-addons'),
                            'reject'   => __('Refuse it and ask the customer to revise', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Reviews submitted by users who can moderate comments are never filtered.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
        ];
    }

    private static function display_cards(): array
    {
        return [
            [
                'title'  => __('Review list', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'show_rating_summary',
                        'type'  => 'toggle',
                        'label' => __('Show rating summary', 'ffl-funnels-addons'),
                        'desc'  => __('Displays the average score and a star-by-star breakdown above the review list.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'show_replies',
                        'type'  => 'toggle',
                        'label' => __('Show replies under reviews', 'ffl-funnels-addons'),
                        'desc'  => __('Replies you leave from Products → Reviews (Reply) are shown beneath the review, badged as a store response.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'list_per_page',
                        'type'  => 'number',
                        'label' => __('Reviews per page', 'ffl-funnels-addons'),
                        'desc'  => __('How many reviews the WooCommerce Reviews tab shows at first, and loads with each “Show more”. A Bricks Reviews List uses its own Max reviews.', 'ffl-funnels-addons'),
                        'min'   => 1,
                        'max'   => 50,
                    ],
                    [
                        'key'   => 'list_toolbar',
                        'type'  => 'toggle',
                        'label' => __('Filters, sort and search above lists', 'ffl-funnels-addons'),
                        'desc'  => __('Star chips (and clickable summary bars), a “With photos” chip, a sort menu (most recent, most helpful, highest, lowest) and a search box. A Bricks Reviews List can switch each one on or off. Never shown in the slider layout unless the element asks for it.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'list_show_more',
                        'type'  => 'toggle',
                        'label' => __('“Show more reviews” button', 'ffl-funnels-addons'),
                        'desc'  => __('Loads the next page of reviews under the list without reloading the page.', 'ffl-funnels-addons'),
                    ],
                    [
                        'type' => 'html',
                        'html' => '<p class="wb-field__desc">' . esc_html__('To feature a review at the top of the list, open Products → Reviews and use the “Pin review” row action.', 'ffl-funnels-addons') . '</p>',
                    ],
                ],
            ],
            [
                'title'  => __('Reviewer details', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'     => 'review_name_format',
                        'type'    => 'select',
                        'label'   => __('Reviewer name', 'ffl-funnels-addons'),
                        'options' => [
                            'first_initial' => __('First name and last initial (Ann B.)', 'ffl-funnels-addons'),
                            'full'          => __('Name as entered (Ann Buyer)', 'ffl-funnels-addons'),
                            'first'         => __('First name only (Ann)', 'ffl-funnels-addons'),
                            'initials'      => __('Initials only (A. B.)', 'ffl-funnels-addons'),
                            'anonymous'     => __('Anonymous (Customer)', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('How reviewers are named on review cards and in the product’s structured data. The stored name is not changed, so you can switch at any time.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'review_avatars',
                        'type'    => 'select',
                        'label'   => __('Avatars', 'ffl-funnels-addons'),
                        'options' => [
                            'initials' => __('Initials on a colour', 'ffl-funnels-addons'),
                            'gravatar' => __('WordPress avatar (Gravatar)', 'ffl-funnels-addons'),
                            'none'     => __('No avatar', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Initials need no outside service. The WordPress avatar loads each picture from Gravatar, which receives a hash of the reviewer’s email.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'      => 'verified_label',
                        'type'     => 'text',
                        'label'    => __('Verified buyer label', 'ffl-funnels-addons'),
                        'desc'     => __('Text of the badge on reviews from customers who bought the product. Empty uses “Verified buyer”.', 'ffl-funnels-addons'),
                        'fallback' => __('Verified buyer', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'review_lightbox',
                        'type'  => 'toggle',
                        'label' => __('Open review photos in a lightbox', 'ffl-funnels-addons'),
                        'desc'  => __('Clicking a photo opens it full size over the page, with previous / next for reviews with several photos. Turn off if your theme already opens every image in its own lightbox.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Helpful votes', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'enable_helpful_votes',
                        'type'  => 'toggle',
                        'label' => __('Enable helpful votes', 'ffl-funnels-addons'),
                        'desc'  => __('Allow customers to mark a review as helpful. Each visitor (by IP address) can vote on a review once every 12 hours.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'enable_not_helpful_votes',
                        'type'  => 'toggle',
                        'label' => __('Also allow “Not helpful” votes', 'ffl-funnels-addons'),
                        'desc'  => __('Adds a downvote button next to “Helpful”. A visitor’s vote on a review counts in one direction only, under the same 12-hour limit. Off by default: a negative button invites brigading, and the sort order already accounts for net score.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Bricks Elements', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'type' => 'html',
                        'html' => '<p class="wb-field__desc">' . esc_html__('The module registers Bricks elements: Reviews Rating Badge, Reviews List (one product, a category or the whole store; list, grid or slider), Review Form, Order reviews hub and Store Rating Badge.', 'ffl-funnels-addons') . '</p>'
                            . '<p class="wb-field__desc">' . esc_html__('In the Bricks element panel they are under "FFL Funnels". Their dynamic data tags are grouped under "FFL Funnels - Product Reviews".', 'ffl-funnels-addons') . '</p>',
                    ],
                ],
            ],
        ];
    }

    private static function form_cards(): array
    {
        return [
            [
                'title'  => __('Review form', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'      => 'form_title',
                        'type'     => 'text',
                        'label'    => __('Review form title', 'ffl-funnels-addons'),
                        'desc'     => __('Heading of the review form in the WooCommerce Reviews tab. The Bricks Review Form element has its own Title.', 'ffl-funnels-addons'),
                        'fallback' => __('Write a review', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'allow_media_uploads',
                        'type'  => 'toggle',
                        'label' => __('Allow photo and video uploads', 'ffl-funnels-addons'),
                        'desc'  => __('Shows the upload field on review forms (up to 3 files, 5 MB each). Reviews with uploads are always held for moderation. Turn off to hide the field everywhere.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Rating criteria', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'enable_criteria',
                        'type'  => 'toggle',
                        'label' => __('Enable secondary rating criteria', 'ffl-funnels-addons'),
                        'desc'  => __('Adds optional star groups below the overall rating, such as Quality or Value for money.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'      => 'review_criteria',
                        'type'     => 'textarea',
                        'label'    => __('Criteria', 'ffl-funnels-addons'),
                        'desc'     => sprintf(
                            /* translators: %d: maximum number of criteria */
                            __('One per line, written as slug|Label — for example: fit|Fit and finish. The slug is what the score is stored under, so renaming a label keeps existing scores. Removing a criterion hides it without deleting anything; add it back and the old scores reappear. Maximum %d.', 'ffl-funnels-addons'),
                            Product_Reviews_Criteria::MAX_CRITERIA
                        ),
                        // Re-serialised from the parsed form so a malformed line
                        // is dropped at save time rather than silently ignored on
                        // every page render.
                        'sanitize' => static function ($raw): string {
                            $parsed = Product_Reviews_Criteria::parse_definition(sanitize_textarea_field((string) $raw));

                            return implode("\n", array_map(
                                static function (array $c): string {
                                    return $c['slug'] . '|' . $c['label'];
                                },
                                $parsed
                            ));
                        },
                    ],
                ],
            ],
            [
                'title'  => __('Cloudflare Turnstile', 'ffl-funnels-addons'),
                'fields' => [
                    ['type' => 'html', 'html' => self::turnstile_status_html()],
                ],
            ],
        ];
    }

    private static function requests_cards(): array
    {
        return [
            [
                'title'  => __('Review Collection', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'enable_requests',
                        'type'  => 'toggle',
                        'label' => __('Enable review requests', 'ffl-funnels-addons'),
                        'desc'  => __('Send post-purchase emails asking customers to review purchased products. Nothing is scheduled or sent until a published Hub page is selected under Order review hub.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'request_delay_days',
                        'type'  => 'number',
                        'label' => __('Delay after completed order (days)', 'ffl-funnels-addons'),
                        'desc'  => __('How many days to wait before sending a review request email. Orders placed more than this many days plus 30 before the email would go out (imports, old orders completed in bulk) are not emailed.', 'ffl-funnels-addons'),
                        'min'   => 0,
                    ],
                    [
                        'key'     => 'request_email_mode',
                        'type'    => 'select',
                        'label'   => __('Review request email', 'ffl-funnels-addons'),
                        'options' => [
                            'bundle'      => __('One email per order (all products, single link)', 'ffl-funnels-addons'),
                            'per_product' => __('One email per product (all sent the same day)', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Both modes send a signed link that opens the order review hub (Hub page below). One email per order lists every product; with the default template it uses {review_order_url} and {product_names_list} automatically. One email per product sends a separate email for each product in the order, all on the same day.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Order review hub', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'order_review_page_id',
                        'type'  => 'page',
                        'label' => __('Hub page', 'ffl-funnels-addons'),
                        'desc'  => __('Page that contains the [ffla_order_reviews] shortcode or the Bricks “Order reviews hub” element. Required: review request emails are only scheduled and sent while a published page is selected here.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'order_review_pretty_urls',
                        'type'  => 'toggle',
                        'label' => __('Pretty URLs for review links', 'ffl-funnels-addons'),
                        'desc'  => __('Use /your-slug/{token}/ instead of ?ffla_ro= on the hub URL. Save settings once, then visit Settings → Permalinks if links 404.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'      => 'order_review_rewrite_slug',
                        'type'     => 'text',
                        'label'    => __('Pretty URL slug', 'ffl-funnels-addons'),
                        'desc'     => __('Segment before the signed token, e.g. order-review → /order-review/{token}/.', 'ffl-funnels-addons'),
                        'sanitize' => static function ($raw): string {
                            $slug = sanitize_title((string) $raw);

                            return $slug !== '' ? $slug : 'order-review';
                        },
                    ],
                    [
                        'key'   => 'order_review_show_criteria',
                        'type'  => 'toggle',
                        'label' => __('Show extra rating criteria on hub forms', 'ffl-funnels-addons'),
                        'desc'  => __('Adds the optional star groups from Rating criteria (Review form tab) to each product form on the hub page.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
        ];
    }

    private static function emails_cards(): array
    {
        $optout_count = Product_Reviews_Notifications::optout_count();

        return [
            [
                'title'  => __('Review request email', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'email_subject',
                        'type'  => 'text',
                        'label' => __('Email subject', 'ffl-funnels-addons'),
                        'desc'  => __('Example: How was your purchase?', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_heading',
                        'type'  => 'text',
                        'label' => __('Email heading', 'ffl-funnels-addons'),
                        'desc'  => __('Appears as the first line in the email body.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_template',
                        'type'  => 'textarea',
                        'label' => __('Email body template', 'ffl-funnels-addons'),
                        'desc'  => __('Placeholders: {customer_name}, {product_name}, {product_names_list}, {review_url}, {review_order_url}, {order_id}, {user_id}, {unsubscribe_url}. Per-product mode uses {review_url}; bundle mode uses {review_order_url} and {product_names_list}. If you do not place {unsubscribe_url} yourself, an unsubscribe line is appended to the end of every request email.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Reviewer notifications', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'notify_on_approved',
                        'type'  => 'toggle',
                        'label' => __('Email the reviewer when their review is approved', 'ffl-funnels-addons'),
                        'desc'  => __('Only fires when a held review is approved. Reviews published immediately do not trigger it.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'notify_on_reply',
                        'type'  => 'toggle',
                        'label' => __('Email the reviewer when someone replies', 'ffl-funnels-addons'),
                        'desc'  => __('Only for store replies: a reply written by someone who can moderate comments, such as the Reply link under Products → Reviews. WordPress never sends this on its own — it only notifies the post author.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Unsubscribed addresses', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'type' => 'html',
                        'html' => '<p class="wb-field__desc">' . esc_html(sprintf(
                            /* translators: %s: number of unsubscribed addresses */
                            _n(
                                '%s address has unsubscribed from review request emails.',
                                '%s addresses have unsubscribed from review request emails.',
                                $optout_count,
                                'ffl-funnels-addons'
                            ),
                            number_format_i18n($optout_count)
                        )) . ' ' . esc_html__('Approval and reply notices are answers to something the customer did, so they ignore this list.', 'ffl-funnels-addons') . '</p>',
                    ],
                    [
                        'key'      => 'resubscribe_email',
                        'type'     => 'text',
                        'label'    => __('Re-subscribe an address', 'ffl-funnels-addons'),
                        'desc'     => __('Only when the customer asks for review requests again: enter their email and save to remove it from the unsubscribed list.', 'ffl-funnels-addons'),
                        'store'    => false,
                        'sanitize' => static function ($raw): string {
                            $email = sanitize_email((string) $raw);
                            if ($email !== '' && is_email($email)) {
                                Product_Reviews_Notifications::opt_in($email);
                            }

                            return '';
                        },
                    ],
                ],
            ],
        ];
    }

    private static function turnstile_status_html(): string
    {
        $active = class_exists('Product_Reviews_Turnstile') && Product_Reviews_Turnstile::is_available();

        if ($active) {
            return '<p class="wb-field__desc">' . esc_html__(
                'Detected: Simple Cloudflare Turnstile plugin is active. The widget is rendered automatically on the FFL review form and validated on submit. While “Replace WooCommerce reviews tab” is on, new reviews posted to WordPress\'s own comment endpoint must pass it too. Manage site key, secret key, and theme inside that plugin\'s settings page.',
                'ffl-funnels-addons'
            ) . '</p>';
        }

        return '<p class="wb-field__desc">' . wp_kses_post(sprintf(
            /* translators: %s: link to the Simple Cloudflare Turnstile plugin page on wordpress.org */
            __('Cloudflare Turnstile on the FFL review form is provided by the %s plugin. Install and configure it; protection is added automatically once it is active. No keys live in this module anymore.', 'ffl-funnels-addons'),
            '<a href="https://wordpress.org/plugins/simple-cloudflare-turnstile/" target="_blank" rel="noopener noreferrer"><strong>Simple Cloudflare Turnstile</strong></a>'
        )) . '</p>';
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------- */

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $settings
     */
    public static function render_field(array $field, array $settings): void
    {
        $type  = (string) ($field['type'] ?? 'text');
        $key   = (string) ($field['key'] ?? '');
        $label = (string) ($field['label'] ?? '');
        $desc  = (string) ($field['desc'] ?? '');
        $store = $field['store'] ?? true;
        $value = ($key !== '' && $store) ? ($settings[$key] ?? '') : '';

        switch ($type) {
            case 'html':
                echo $field['html'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
                return;

            case 'toggle':
                FFLA_Admin::render_toggle_field($label, $key, (string) $value, $desc);
                return;

            case 'select':
                FFLA_Admin::render_select_field($label, $key, (string) $value, (array) ($field['options'] ?? []), $desc);
                return;

            case 'textarea':
                FFLA_Admin::render_textarea_field($label, $key, (string) $value, $desc);
                return;

            case 'color':
                FFLA_Admin::render_color_field($label, $key, (string) $value, $desc);
                return;

            case 'media':
                FFLA_Admin::render_media_field($label, $key, absint($value), $desc);
                return;

            case 'number':
                self::open_field($label, $key);
                echo '<input type="number" class="wb-input wb-input--sm" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr((string) $value) . '"'
                    . (isset($field['min']) ? ' min="' . esc_attr((string) $field['min']) . '"' : '')
                    . (isset($field['max']) ? ' max="' . esc_attr((string) $field['max']) . '"' : '')
                    . (isset($field['step']) ? ' step="' . esc_attr((string) $field['step']) . '"' : '')
                    . '>';
                self::close_field($desc);
                return;

            case 'page':
                self::open_field($label, $key);
                wp_dropdown_pages([
                    'name'              => $key,
                    'id'                => $key,
                    'selected'          => absint($value),
                    'show_option_none'  => __('-- Select Page --', 'ffl-funnels-addons'),
                    'option_none_value' => '0',
                    'class'             => 'wb-select',
                ]);
                self::close_field($desc);
                return;

            case 'multiselect':
                $selected = is_array($value) ? array_map('strval', $value) : [];
                self::open_field($label, $key);
                echo '<div class="ffla-pr-checklist" id="' . esc_attr($key) . '">';
                // An empty marker makes "nothing ticked" reach the save handler.
                echo '<input type="hidden" name="' . esc_attr($key) . '[]" value="">';
                foreach ((array) ($field['options'] ?? []) as $opt_value => $opt_label) {
                    echo '<label class="wb-checkbox"><input type="checkbox" name="' . esc_attr($key) . '[]" value="' . esc_attr((string) $opt_value) . '"'
                        . checked(in_array((string) $opt_value, $selected, true), true, false) . '> '
                        . esc_html((string) $opt_label) . '</label>';
                }
                if (empty($field['options'])) {
                    echo '<p class="wb-text--muted">' . esc_html__('Nothing to choose from yet.', 'ffl-funnels-addons') . '</p>';
                }
                echo '</div>';
                self::close_field($desc);
                return;

            case 'editor':
                self::open_field($label, $key);
                wp_editor((string) $value, $key, [
                    'textarea_name' => $key,
                    'textarea_rows' => (int) ($field['rows'] ?? 10),
                    'media_buttons' => false,
                    'teeny'         => true,
                ]);
                self::close_field($desc);
                return;

            case 'text':
            default:
                FFLA_Admin::render_text_field($label, $key, (string) $value, $desc);
                return;
        }
    }

    private static function open_field(string $label, string $key): void
    {
        echo '<div class="wb-field">';
        echo '<label class="wb-field__label" for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
        echo '<div class="wb-field__control">';
    }

    private static function close_field(string $desc): void
    {
        if ($desc !== '') {
            echo '<p class="wb-field__desc">' . esc_html($desc) . '</p>';
        }
        echo '</div></div>';
    }

    /* ---------------------------------------------------------------------
     * Saving
     * ------------------------------------------------------------------- */

    /**
     * The value to store for a field from the posted form. Reads $_POST; the
     * caller has checked the capability and nonce.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $current Settings before this save.
     * @return mixed
     */
    public static function sanitize_field(array $field, array $current)
    {
        $key  = (string) $field['key'];
        $type = (string) ($field['type'] ?? 'text');

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the caller.
        $posted = array_key_exists($key, $_POST);
        $raw    = $posted ? wp_unslash($_POST[$key]) : null;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (isset($field['sanitize']) && is_callable($field['sanitize'])) {
            return call_user_func($field['sanitize'], $raw, $field, $current);
        }

        $default = Product_Reviews_Core::get_default_settings()[$key] ?? '';

        switch ($type) {
            case 'toggle':
                return $posted ? '1' : '0';

            case 'number':
                $number = is_scalar($raw) && is_numeric(trim((string) $raw)) ? (int) $raw : (int) $default;
                if (isset($field['min'])) {
                    $number = max((int) $field['min'], $number);
                }
                if (isset($field['max'])) {
                    $number = min((int) $field['max'], $number);
                }

                return (string) $number;

            case 'select':
                $options = (array) ($field['options'] ?? []);
                $choice  = is_scalar($raw) ? (string) $raw : '';

                return array_key_exists($choice, $options) ? $choice : (string) $default;

            case 'multiselect':
                $options = array_map('strval', array_keys((array) ($field['options'] ?? [])));
                $picked  = is_array($raw) ? array_map('strval', $raw) : [];

                return array_values(array_intersect($options, $picked));

            case 'textarea':
                return is_scalar($raw) ? sanitize_textarea_field((string) $raw) : '';

            case 'editor':
                return is_scalar($raw) ? wp_kses_post((string) $raw) : '';

            case 'color':
                $color = is_scalar($raw) ? sanitize_hex_color((string) $raw) : '';

                return $color ? $color : (string) $default;

            case 'page':
            case 'media':
                return (string) absint(is_scalar($raw) ? $raw : 0);

            case 'email':
                $email = is_scalar($raw) ? sanitize_email((string) $raw) : '';

                return is_email($email) ? $email : '';

            case 'text':
            default:
                $text = is_scalar($raw) ? sanitize_text_field((string) $raw) : '';
                if ($text === '' && isset($field['fallback'])) {
                    return (string) $field['fallback'];
                }

                return $text;
        }
    }
}
