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
            'coupons'  => __('Coupons', 'ffl-funnels-addons'),
            'qna'      => __('Q&A', 'ffl-funnels-addons'),
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
            case 'coupons':
                return self::coupons_cards();
            case 'qna':
                return self::qna_cards();
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
                        'key'      => 'login_url',
                        'type'     => 'text',
                        'label'    => __('Log in page', 'ffl-funnels-addons'),
                        'desc'     => __('Where the “Log in” links on review and Q&A forms go (they come back to the product). Empty uses the WooCommerce My Account page, else the WordPress login.', 'ffl-funnels-addons'),
                        'sanitize' => static function ($raw): string {
                            return esc_url_raw(trim((string) $raw));
                        },
                    ],
                ],
            ],
            [
                'title'  => __('Photos and videos', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'allow_media_uploads',
                        'type'  => 'toggle',
                        'label' => __('Allow photo and video uploads', 'ffl-funnels-addons'),
                        'desc'  => __('Shows the upload field on review forms. Reviews with uploads are always held for moderation. Turn off to hide the field everywhere.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'media_max_files',
                        'type'  => 'number',
                        'label' => __('Most files per review', 'ffl-funnels-addons'),
                        'desc'  => __('1 to 10.', 'ffl-funnels-addons'),
                        'min'   => 1,
                        'max'   => 10,
                    ],
                    [
                        'key'   => 'media_max_mb',
                        'type'  => 'number',
                        'label' => __('Largest file (MB)', 'ffl-funnels-addons'),
                        'desc'  => __('1 to 50. Your server’s upload limit still applies.', 'ffl-funnels-addons'),
                        'min'   => 1,
                        'max'   => 50,
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
                        'desc'  => __('Adds star groups below the overall rating, such as Quality or Value for money.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'      => 'review_criteria',
                        'type'     => 'textarea',
                        'label'    => __('Criteria', 'ffl-funnels-addons'),
                        'desc'     => sprintf(
                            /* translators: %d: maximum number of criteria */
                            __('One per line, written as slug|Label — for example: fit|Fit and finish. Add |required to make reviewers rate it (fit|Fit and finish|required). The slug is what the score is stored under, so renaming a label keeps existing scores. Removing a criterion hides it without deleting anything; add it back and the old scores reappear. Maximum %d.', 'ffl-funnels-addons'),
                            Product_Reviews_Criteria::MAX_CRITERIA
                        ),
                        // Re-serialised from the parsed form so a malformed line
                        // is dropped at save time rather than silently ignored on
                        // every page render.
                        'sanitize' => static function ($raw): string {
                            return Product_Reviews_Criteria::serialize_definition(
                                Product_Reviews_Criteria::parse_definition(sanitize_textarea_field((string) $raw))
                            );
                        },
                    ],
                ],
            ],
            [
                'title'  => __('Questions about the reviewer', 'ffl-funnels-addons'),
                'desc'   => __('Extra questions on the review form, for example experience level or how the product is used. Public answers show on the review; the others only in wp-admin and the export.', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'      => 'review_questions',
                        'type'     => 'textarea',
                        'label'    => __('Questions', 'ffl-funnels-addons'),
                        'desc'     => sprintf(
                            /* translators: %d: maximum number of questions */
                            __('One per line: slug|Label|type|choices|flags. Type is text, select, radio (one choice) or checkbox (several). Choices are comma separated (not for text). Flags, comma separated: required, public (shown on the review), product or hub (only on product forms, or only on the review page from the email). Example: experience|Shooting experience|select|Beginner,Intermediate,Expert|required,public. Maximum %d; malformed lines are dropped on save.', 'ffl-funnels-addons'),
                            Product_Reviews_Form_Extras::MAX_QUESTIONS
                        ),
                        'sanitize' => static function ($raw): string {
                            Product_Reviews_Form_Extras::flush_memo();

                            return Product_Reviews_Form_Extras::serialize(Product_Reviews_Form_Extras::parse(sanitize_textarea_field((string) $raw)));
                        },
                    ],
                ],
            ],
            [
                'title'  => __('Terms and privacy checkbox', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'terms_on_reviews',
                        'type'  => 'toggle',
                        'label' => __('On review forms', 'ffl-funnels-addons'),
                        'desc'  => __('Reviewers must tick it to send a review (product pages and the review page from the email).', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'terms_on_qna',
                        'type'  => 'toggle',
                        'label' => __('On question and answer forms', 'ffl-funnels-addons'),
                        'desc'  => '',
                    ],
                    [
                        'key'   => 'terms_text',
                        'type'  => 'text',
                        'label' => __('Checkbox text', 'ffl-funnels-addons'),
                        'desc'  => __('{terms} becomes a link to the page below. Empty uses “I agree to the {terms}.”', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'terms_page_id',
                        'type'  => 'page',
                        'label' => __('Page to link', 'ffl-funnels-addons'),
                        'desc'  => __('Your review guidelines or terms. None uses the privacy policy page from Settings → Privacy.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('CAPTCHA', 'ffl-funnels-addons'),
                'desc'   => __('Spam protection on review and Q&A forms (guests only for answers). The review page from the email never shows one: its signed link is the proof. A hidden honeypot field and an hourly limit for guests always apply.', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'     => 'captcha_provider',
                        'type'    => 'select',
                        'label'   => __('CAPTCHA', 'ffl-funnels-addons'),
                        'options' => [
                            'plugin'       => __('Cloudflare Turnstile through the Simple Cloudflare Turnstile plugin (when active)', 'ffl-funnels-addons'),
                            'turnstile'    => __('Cloudflare Turnstile with the keys below', 'ffl-funnels-addons'),
                            'hcaptcha'     => __('hCaptcha with the keys below', 'ffl-funnels-addons'),
                            'recaptcha_v2' => __('Google reCAPTCHA v2 (checkbox) with the keys below', 'ffl-funnels-addons'),
                            'recaptcha_v3' => __('Google reCAPTCHA v3 (invisible) with the keys below', 'ffl-funnels-addons'),
                            'none'         => __('None', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('With keys set here the provider’s script loads only on pages with a review or Q&A form. Get the keys from the provider for this site’s domain.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'captcha_site_key',
                        'type'  => 'text',
                        'label' => __('Site key', 'ffl-funnels-addons'),
                        'desc'  => '',
                    ],
                    [
                        'key'      => 'captcha_secret_key',
                        'type'     => 'password',
                        'label'    => __('Secret key', 'ffl-funnels-addons'),
                        'desc'     => __('Stored in the settings; never shown on the site.', 'ffl-funnels-addons'),
                        'sanitize' => static function ($raw, $field, $current): string {
                            $value = trim(sanitize_text_field((string) $raw));

                            // An untouched masked field keeps the saved secret.
                            return $value === '' ? (string) ($current['captcha_secret_key'] ?? '') : $value;
                        },
                    ],
                    [
                        'key'      => 'captcha_v3_threshold',
                        'type'     => 'text',
                        'label'    => __('reCAPTCHA v3 minimum score', 'ffl-funnels-addons'),
                        'desc'     => __('0.0 (allow all) to 1.0 (strict). Google suggests 0.5.', 'ffl-funnels-addons'),
                        'sanitize' => static function ($raw): string {
                            $score = (float) str_replace(',', '.', (string) $raw);

                            return (string) max(0.0, min(1.0, $score));
                        },
                    ],
                    ['type' => 'html', 'html' => self::turnstile_status_html()],
                ],
            ],
        ];
    }

    /**
     * WooCommerce's order statuses (custom ones included), without `wc-`.
     *
     * @return array<string, string>
     */
    private static function order_status_options(): array
    {
        $out = [];
        if (function_exists('wc_get_order_statuses')) {
            foreach (wc_get_order_statuses() as $key => $label) {
                $out[preg_replace('/^wc-/', '', (string) $key)] = (string) $label;
            }
        }
        foreach (['pending', 'cancelled', 'refunded', 'failed', 'checkout-draft'] as $never) {
            unset($out[$never]);
        }

        return $out ?: ['completed' => __('Completed', 'ffl-funnels-addons')];
    }

    /**
     * @return array<string, string>
     */
    private static function role_options(): array
    {
        return function_exists('wp_roles') ? (array) wp_roles()->get_names() : [];
    }

    /**
     * @return array<int, string>
     */
    private static function category_options(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500]);
        if (!is_array($terms)) {
            return [];
        }

        $out = [];
        foreach ($terms as $term) {
            if ($term instanceof \WP_Term) {
                $out[(int) $term->term_id] = $term->name;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function hour_options(): array
    {
        $out = ['' => __('Any time (right after the delay)', 'ffl-funnels-addons')];
        for ($h = 0; $h < 24; $h++) {
            $out[(string) $h] = sprintf('%02d:00', $h);
        }

        return $out;
    }

    /**
     * A colour that may be left empty (to fall back to WooCommerce's email
     * colours), which an <input type="color"> cannot express.
     */
    private static function optional_color(string $key, string $label, string $desc): array
    {
        return [
            'key'      => $key,
            'type'     => 'text',
            'label'    => $label,
            'desc'     => $desc,
            'sanitize' => static function ($raw): string {
                $color = sanitize_hex_color(trim((string) $raw));

                return $color ? $color : '';
            },
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
                        'desc'  => __('Send post-purchase emails asking customers to review purchased products. Nothing is scheduled or sent until a published Hub page is selected under Order review hub. Requests sent by hand from an order work while this is off.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'request_email_mode',
                        'type'    => 'select',
                        'label'   => __('Review request email', 'ffl-funnels-addons'),
                        'options' => [
                            'bundle'      => __('One email per order (all products, single link)', 'ffl-funnels-addons'),
                            'per_product' => __('One email per product (all sent the same day)', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Both modes send a signed link that opens the order review hub (Hub page below). Reminders and requests sent by hand are always one email per order.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('When to send', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'     => 'request_trigger_status',
                        'type'    => 'select',
                        'label'   => __('Start when the order is', 'ffl-funnels-addons'),
                        'options' => self::order_status_options(),
                        'desc'    => __('The delay starts when an order reaches this status. Custom statuses (for example Delivered or Shipped) are listed too. Statuses the order moves to afterwards still get the email; going back to Processing or On hold does not.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'request_delay_days',
                        'type'  => 'number',
                        'label' => __('Delay', 'ffl-funnels-addons'),
                        'desc'  => __('How long after that status the first email goes out. 0 sends at the next queue run. Orders placed more than the delay plus 30 days before the email would go out (imports, old orders changed in bulk) are not emailed.', 'ffl-funnels-addons'),
                        'min'   => 0,
                        'max'   => 365,
                    ],
                    [
                        'key'     => 'request_delay_unit',
                        'type'    => 'select',
                        'label'   => __('Delay in', 'ffl-funnels-addons'),
                        'options' => [
                            'days'  => __('Days', 'ffl-funnels-addons'),
                            'hours' => __('Hours', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Also the unit of the per-country delays below.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'request_country_delays',
                        'type'  => 'textarea',
                        'label' => __('Delay per country', 'ffl-funnels-addons'),
                        'desc'  => __('One per line as country code|delay, for example CA|14 for slower shipping to Canada. Uses the shipping country, else the billing country. Countries not listed use the delay above.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'request_send_hour',
                        'type'    => 'select',
                        'label'   => __('Send at', 'ffl-funnels-addons'),
                        'options' => self::hour_options(),
                        'desc'    => __('Hold each email until this time of day (store time zone), for example 10:00 so it lands while customers read their mail. Applies to reminders too.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'request_send_days',
                        'type'    => 'multiselect',
                        'label'   => __('Send on', 'ffl-funnels-addons'),
                        'options' => [
                            '1' => __('Monday', 'ffl-funnels-addons'),
                            '2' => __('Tuesday', 'ffl-funnels-addons'),
                            '3' => __('Wednesday', 'ffl-funnels-addons'),
                            '4' => __('Thursday', 'ffl-funnels-addons'),
                            '5' => __('Friday', 'ffl-funnels-addons'),
                            '6' => __('Saturday', 'ffl-funnels-addons'),
                            '7' => __('Sunday', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Leave all unticked (or all ticked) to send any day. An email due on another day waits for the next allowed one.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'review_link_days',
                        'type'  => 'number',
                        'label' => __('Review link valid for (days)', 'ffl-funnels-addons'),
                        'desc'  => __('How long the signed link in each email works. Applies to links sent from now on.', 'ffl-funnels-addons'),
                        'min'   => 1,
                        'max'   => 365,
                    ],
                ],
            ],
            [
                'title'  => __('Second reminder', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'request_reminder',
                        'type'  => 'toggle',
                        'label' => __('Send a reminder', 'ffl-funnels-addons'),
                        'desc'  => __('One more email, for the whole order, when some products are still not reviewed. Its subject and heading are set on the Emails tab.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'request_reminder_days',
                        'type'  => 'number',
                        'label' => __('Days after the first email', 'ffl-funnels-addons'),
                        'desc'  => __('Then held for the sending hour and days above.', 'ffl-funnels-addons'),
                        'min'   => 1,
                        'max'   => 90,
                    ],
                ],
            ],
            [
                'title'  => __('Who gets requests', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'request_guests',
                        'type'  => 'toggle',
                        'label' => __('Guest orders', 'ffl-funnels-addons'),
                        'desc'  => __('Send requests for orders placed without an account.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'request_roles_mode',
                        'type'    => 'select',
                        'label'   => __('Customer roles', 'ffl-funnels-addons'),
                        'options' => [
                            'all'     => __('Every role', 'ffl-funnels-addons'),
                            'include' => __('Only the roles ticked below', 'ffl-funnels-addons'),
                            'exclude' => __('Every role except those ticked below', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('For orders placed from an account. For example, leave out wholesale or staff accounts.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'request_roles',
                        'type'    => 'multiselect',
                        'label'   => __('Roles', 'ffl-funnels-addons'),
                        'options' => self::role_options(),
                        'desc'    => '',
                    ],
                    [
                        'key'     => 'request_categories_mode',
                        'type'    => 'select',
                        'label'   => __('Product categories', 'ffl-funnels-addons'),
                        'options' => [
                            'all'     => __('Every category', 'ffl-funnels-addons'),
                            'include' => __('Only products in the categories ticked below', 'ffl-funnels-addons'),
                            'exclude' => __('Every product except those in the categories ticked below', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Products left out are not listed in the email; an order with nothing left gets no email. Subcategories count. The hub page still lets the customer review everything in the order.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'request_categories',
                        'type'    => 'multiselect',
                        'label'   => __('Categories', 'ffl-funnels-addons'),
                        'options' => self::category_options(),
                        'desc'    => '',
                    ],
                    [
                        'key'   => 'request_skip_free',
                        'type'  => 'toggle',
                        'label' => __('Leave out free products', 'ffl-funnels-addons'),
                        'desc'  => __('Products whose order lines cost nothing (samples, gifts, free add-ons) are not listed.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'request_max_products',
                        'type'  => 'number',
                        'label' => __('Most products per email', 'ffl-funnels-addons'),
                        'desc'  => __('For large orders: list at most this many, the most expensive lines first. 0 lists them all.', 'ffl-funnels-addons'),
                        'min'   => 0,
                        'max'   => 50,
                    ],
                    [
                        'key'   => 'request_excluded_emails',
                        'type'  => 'textarea',
                        'label' => __('Never email', 'ffl-funnels-addons'),
                        'desc'  => __('Addresses, one per line, or whole domains as @example.com (for example your own staff or marketplace relay addresses). Also blocks requests sent by hand.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'request_customer_window_days',
                        'type'  => 'number',
                        'label' => __('One request per customer every (days)', 'ffl-funnels-addons'),
                        'desc'  => __('Skip an order when the same billing email got a request for another order within this many days. 0 asks about every order.', 'ffl-funnels-addons'),
                        'min'   => 0,
                        'max'   => 365,
                    ],
                    [
                        'key'   => 'request_use_account_email',
                        'type'  => 'toggle',
                        'label' => __('Send to the account email', 'ffl-funnels-addons'),
                        'desc'  => __('For orders placed from an account, email the account’s address instead of the billing address.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Consent at checkout', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'     => 'request_consent',
                        'type'    => 'select',
                        'label'   => __('Ask for consent', 'ffl-funnels-addons'),
                        'options' => [
                            'none'  => __('No checkbox: every order can get a request', 'ffl-funnels-addons'),
                            'optin' => __('Unticked checkbox: only customers who tick it get a request', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Shown above the Place order button on the classic checkout and as an order field on the Checkout block (WooCommerce 8.9 or later). Orders placed before you switch this on have no answer and get no request. Requests sent by hand respect it too.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'      => 'request_consent_text',
                        'type'     => 'text',
                        'label'    => __('Checkbox text', 'ffl-funnels-addons'),
                        'desc'     => __('Empty uses “Email me a link to review my purchase”.', 'ffl-funnels-addons'),
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

        $placeholders = __('Placeholders: {customer_name}, {product_name} (the list in one-email-per-order mode), {product_names_list}, {review_url}, {review_order_url}, {order_id}, {order_number}, {user_id}, {store_name}, {review_link_days}, {unsubscribe_url}.', 'ffl-funnels-addons');

        return [
            [
                'title'  => __('Review request email', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'     => 'email_format',
                        'type'    => 'select',
                        'label'   => __('Format', 'ffl-funnels-addons'),
                        'options' => [
                            'html'  => __('HTML: logo, product photos, clickable stars and a button', 'ffl-funnels-addons'),
                            'plain' => __('Plain text', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('In HTML emails each product has five stars: a click opens the review page with that rating already picked. Plain text emails list the products and the link.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_subject',
                        'type'  => 'text',
                        'label' => __('Email subject', 'ffl-funnels-addons'),
                        'desc'  => __('Example: How was your purchase? Placeholders work here too.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_heading',
                        'type'  => 'text',
                        'label' => __('Email heading', 'ffl-funnels-addons'),
                        'desc'  => __('Appears as the first line in the email body.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_template',
                        'type'  => 'editor',
                        'label' => __('Email text', 'ffl-funnels-addons'),
                        'desc'  => $placeholders . ' ' . __('Leave it as it is (or empty) to use the built-in text for each kind of email. HTML emails add the products and the button after this text; plain text emails need {review_url} in it. If you do not place {unsubscribe_url} yourself, an unsubscribe link is added at the end.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Reminder email', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'email_reminder_subject',
                        'type'  => 'text',
                        'label' => __('Reminder subject', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses “A quick reminder: how was your order?”. Switch reminders on under Review requests.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_reminder_heading',
                        'type'  => 'text',
                        'label' => __('Reminder heading', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses the request email’s heading. The text is the request email’s text (or a built-in reminder text while that is not changed).', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Design (HTML emails)', 'ffl-funnels-addons'),
                'desc'   => __('Empty fields use WooCommerce → Settings → Emails (header image, base, background and text colours, footer text), so review emails match your order emails.', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'email_logo_id',
                        'type'  => 'media',
                        'label' => __('Logo', 'ffl-funnels-addons'),
                        'desc'  => __('Shown at the top, at most 200 × 80 pixels. Without one, WooCommerce’s email header image, else the store name.', 'ffl-funnels-addons'),
                    ],
                    self::optional_color('email_brand_color', __('Button colour', 'ffl-funnels-addons'), __('Hex colour such as #1f2937. Empty uses WooCommerce’s base colour.', 'ffl-funnels-addons')),
                    self::optional_color('email_background_color', __('Background colour', 'ffl-funnels-addons'), __('Around the email. Empty uses WooCommerce’s background colour.', 'ffl-funnels-addons')),
                    self::optional_color('email_text_color', __('Text colour', 'ffl-funnels-addons'), __('Empty uses WooCommerce’s body text colour.', 'ffl-funnels-addons')),
                    self::optional_color('email_star_color', __('Star colour', 'ffl-funnels-addons'), __('The clickable stars. Empty uses gold (#d4a017).', 'ffl-funnels-addons')),
                    [
                        'key'   => 'email_button_text',
                        'type'  => 'text',
                        'label' => __('Button text', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses “Leave a review”.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_show_products',
                        'type'  => 'toggle',
                        'label' => __('Product rows with clickable stars', 'ffl-funnels-addons'),
                        'desc'  => __('Photo, name and five stars for each product. Off leaves only your text and the button.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_footer_text',
                        'type'  => 'textarea',
                        'label' => __('Footer', 'ffl-funnels-addons'),
                        'desc'  => __('Small print above the unsubscribe link: your business name and postal address. {site_title}, {store_address} and {site_url} work. Empty uses WooCommerce’s email footer, else the store name and the address from WooCommerce → Settings → General.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Sender and tracking', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'type' => 'html',
                        'html' => '<p class="wb-field__desc">' . esc_html__('Request emails come from the sender name and address under WooCommerce → Settings → Emails.', 'ffl-funnels-addons') . '</p>',
                    ],
                    [
                        'key'   => 'email_reply_to',
                        'type'  => 'email',
                        'label' => __('Reply-To address', 'ffl-funnels-addons'),
                        'desc'  => __('Where customers’ replies to request emails go. Empty uses WooCommerce’s reply-to address, else the sender.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_bcc',
                        'type'  => 'email',
                        'label' => __('BCC copy to', 'ffl-funnels-addons'),
                        'desc'  => __('Receive a copy of every request and reminder, for example while you check how they look.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'email_tracking',
                        'type'  => 'toggle',
                        'label' => __('Track opens and clicks', 'ffl-funnels-addons'),
                        'desc'  => __('Records on the order when the HTML email is opened (a 1×1 image) and when the review page is opened from it, shown on the order screen and in the request log. Approximate: some mail apps block images, and company mail scanners open links on their own. Reviews sent through the link are always counted.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Preview and test', 'ffl-funnels-addons'),
                'fields' => [
                    ['type' => 'html', 'html' => self::test_email_html()],
                ],
            ],
            [
                'title'  => __('WhatsApp message', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'whatsapp_template',
                        'type'  => 'textarea',
                        'label' => __('Message', 'ffl-funnels-addons'),
                        'desc'  => __('Used by the WhatsApp button in the Review request box on the order screen: it opens a chat with the customer’s billing phone with this text filled in, and you press send. Placeholders: {customer_name}, {store_name}, {order_number}, {review_url}.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('New review emails', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'notify_store_new_review',
                        'type'  => 'toggle',
                        'label' => __('Email the store about new reviews', 'ffl-funnels-addons'),
                        'desc'  => __('One email per new review with its rating, text, Verified buyer, photos and a link to approve or see it. WordPress’s own comment emails (Settings → Discussion) are separate.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'notify_store_email',
                        'type'  => 'email',
                        'label' => __('Send them to', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses the site’s admin email.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'notify_store_max_rating',
                        'type'    => 'select',
                        'label'   => __('For reviews of', 'ffl-funnels-addons'),
                        'options' => [
                            '5' => __('Any rating', 'ffl-funnels-addons'),
                            '3' => __('3 stars or less', 'ffl-funnels-addons'),
                            '2' => __('2 stars or less', 'ffl-funnels-addons'),
                            '1' => __('1 star only', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('For example only low ratings, to answer unhappy customers quickly.', 'ffl-funnels-addons'),
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

    /**
     * Preview link and a test send. The test uses the saved settings, so
     * the description asks to save first.
     */
    private static function test_email_html(): string
    {
        $preview = wp_nonce_url(
            add_query_arg(['action' => 'ffla_review_email_preview'], admin_url('admin-post.php')),
            'ffla_review_email_preview'
        );
        $reminder = wp_nonce_url(
            add_query_arg(['action' => 'ffla_review_email_preview', 'kind' => 'reminder'], admin_url('admin-post.php')),
            'ffla_review_email_preview'
        );

        return '<div class="wb-field"><span class="wb-field__label">' . esc_html__('Preview', 'ffl-funnels-addons') . '</span><div class="wb-field__control">'
            . '<div class="ffla-pr-inline-actions">'
            . '<a class="wb-btn wb-btn--subtle" href="' . esc_url($preview) . '" target="_blank" rel="noopener">' . esc_html__('Preview request email', 'ffl-funnels-addons') . '</a>'
            . '<a class="wb-btn wb-btn--subtle" href="' . esc_url($reminder) . '" target="_blank" rel="noopener">' . esc_html__('Preview reminder', 'ffl-funnels-addons') . '</a>'
            . '</div>'
            . '<p class="wb-field__desc">' . esc_html__('Shows the email with your newest order (or your newest products), as the saved settings make it.', 'ffl-funnels-addons') . '</p>'
            . '</div></div>'
            . self::test_widget_html(['request' => __('Request', 'ffl-funnels-addons'), 'reminder' => __('Reminder', 'ffl-funnels-addons')], 'ffla-pr-test-to');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function coupon_tier_fields(int $tier): array
    {
        $fields = [];

        if ($tier > 1) {
            $fields[] = [
                'key'   => 'coupon_tier' . $tier . '_min_media',
                'type'  => 'number',
                'label' => sprintf(
                    /* translators: %d: tier number */
                    __('Tier %d: photos or videos at least', 'ffl-funnels-addons'),
                    $tier
                ),
                'desc'  => __('Reviews with at least this many photos or videos get this tier instead of a lower one.', 'ffl-funnels-addons'),
                'min'   => 1,
                'max'   => 20,
            ];
        }

        $fields[] = [
            'key'     => 'coupon_tier' . $tier . '_type',
            'type'    => 'select',
            'label'   => sprintf(
                /* translators: %d: tier number */
                __('Tier %d coupon', 'ffl-funnels-addons'),
                $tier
            ),
            'options' => [
                'new'      => __('A new unique coupon for each review', 'ffl-funnels-addons'),
                'existing' => __('An existing coupon code', 'ffl-funnels-addons'),
                'off'      => 1 === $tier ? __('No coupon', 'ffl-funnels-addons') : __('Not used', 'ffl-funnels-addons'),
            ],
            'desc'    => 1 === $tier
                ? __('For every rewarded review, with or without photos.', 'ffl-funnels-addons')
                : __('Not used: reviews with this many photos get the tier below.', 'ffl-funnels-addons'),
        ];

        $fields[] = [
            'key'     => 'coupon_tier' . $tier . '_discount_type',
            'type'    => 'select',
            'label'   => __('Discount type', 'ffl-funnels-addons'),
            'options' => [
                'percent'       => __('Percentage discount', 'ffl-funnels-addons'),
                'fixed_cart'    => __('Fixed cart discount', 'ffl-funnels-addons'),
                'fixed_product' => __('Fixed product discount', 'ffl-funnels-addons'),
            ],
            'desc'    => __('For new coupons.', 'ffl-funnels-addons'),
        ];

        $fields[] = [
            'key'      => 'coupon_tier' . $tier . '_amount',
            'type'     => 'text',
            'label'    => __('Amount', 'ffl-funnels-addons'),
            'desc'     => __('Percent or amount in the store currency, for new coupons.', 'ffl-funnels-addons'),
            'sanitize' => static function ($raw): string {
                $value = (float) str_replace(',', '.', (string) $raw);

                return $value > 0 ? (string) $value : '0';
            },
        ];

        $fields[] = [
            'key'      => 'coupon_tier' . $tier . '_existing',
            'type'     => 'text',
            'label'    => __('Existing coupon code', 'ffl-funnels-addons'),
            'desc'     => __('When the tier uses an existing coupon: its code from Marketing → Coupons. The same code goes to everyone, with that coupon’s own limits.', 'ffl-funnels-addons'),
        ];

        return $fields;
    }

    private static function coupons_cards(): array
    {
        $cards = [
            [
                'title'  => __('Coupons for reviews', 'ffl-funnels-addons'),
                'desc'   => __('A coupon goes out by email when a review is published (held reviews: when you approve them), whatever its rating. One coupon per order, however many of its products are reviewed.', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'coupon_enabled',
                        'type'  => 'toggle',
                        'label' => __('Send coupons for reviews', 'ffl-funnels-addons'),
                        'desc'  => __('Reviews written before you switch this on earn nothing. With the Smart Coupons module on, its guardrails (firearms left out, minimum prices) apply to these coupons too.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'coupon_who',
                        'type'    => 'select',
                        'label'   => __('Which reviews', 'ffl-funnels-addons'),
                        'options' => [
                            'verified' => __('Verified buyers: the email link, or a signed-in account that bought the product', 'ffl-funnels-addons'),
                            'link'     => __('Only reviews sent through the review request email link', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Guest reviews typed into a product page never earn a coupon: anyone could type a buyer’s email.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'coupon_roles_mode',
                        'type'    => 'select',
                        'label'   => __('Customer roles', 'ffl-funnels-addons'),
                        'options' => [
                            'all'     => __('Every role (and guests through the email link)', 'ffl-funnels-addons'),
                            'include' => __('Only the roles ticked below', 'ffl-funnels-addons'),
                            'exclude' => __('Every role except those ticked below', 'ffl-funnels-addons'),
                        ],
                        'desc'    => '',
                    ],
                    [
                        'key'     => 'coupon_roles',
                        'type'    => 'multiselect',
                        'label'   => __('Roles', 'ffl-funnels-addons'),
                        'options' => self::role_options() + ['guest' => __('Guest (no account)', 'ffl-funnels-addons')],
                        'desc'    => '',
                    ],
                ],
            ],
            [
                'title'  => __('Tiers', 'ffl-funnels-addons'),
                'desc'   => __('Reward reviews with photos or videos more: each review gets the highest tier whose minimum it reaches.', 'ffl-funnels-addons'),
                'fields' => array_merge(self::coupon_tier_fields(1), self::coupon_tier_fields(2), self::coupon_tier_fields(3)),
            ],
            [
                'title'  => __('New coupons', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'      => 'coupon_prefix',
                        'type'     => 'text',
                        'label'    => __('Code prefix', 'ffl-funnels-addons'),
                        'desc'     => __('Codes look like REVIEW-7KQ2M9XA. Letters, numbers and dashes.', 'ffl-funnels-addons'),
                        'sanitize' => static function ($raw): string {
                            return strtoupper(substr((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) $raw), 0, 20));
                        },
                    ],
                    [
                        'key'   => 'coupon_expiry_days',
                        'type'  => 'number',
                        'label' => __('Valid for (days)', 'ffl-funnels-addons'),
                        'desc'  => __('From the moment the coupon is made. 0 never expires.', 'ffl-funnels-addons'),
                        'min'   => 0,
                        'max'   => 3650,
                    ],
                    [
                        'key'   => 'coupon_shareable',
                        'type'  => 'toggle',
                        'label' => __('Anyone may use the code', 'ffl-funnels-addons'),
                        'desc'  => __('Off: only the reviewer’s email address can use it (WooCommerce checks the billing email). On: they can pass it to a friend.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'coupon_usage_limit',
                        'type'  => 'number',
                        'label' => __('Uses per coupon', 'ffl-funnels-addons'),
                        'desc'  => __('How many orders can use one code.', 'ffl-funnels-addons'),
                        'min'   => 1,
                        'max'   => 100,
                    ],
                    [
                        'key'      => 'coupon_minimum_spend',
                        'type'     => 'text',
                        'label'    => __('Minimum spend', 'ffl-funnels-addons'),
                        'desc'     => __('Empty for none.', 'ffl-funnels-addons'),
                        'sanitize' => static function ($raw): string {
                            $value = (float) str_replace(',', '.', (string) $raw);

                            return $value > 0 ? (string) $value : '';
                        },
                    ],
                    [
                        'key'      => 'coupon_maximum_spend',
                        'type'     => 'text',
                        'label'    => __('Maximum spend', 'ffl-funnels-addons'),
                        'desc'     => __('Empty for none.', 'ffl-funnels-addons'),
                        'sanitize' => static function ($raw): string {
                            $value = (float) str_replace(',', '.', (string) $raw);

                            return $value > 0 ? (string) $value : '';
                        },
                    ],
                    [
                        'key'   => 'coupon_individual_use',
                        'type'  => 'toggle',
                        'label' => __('Individual use only', 'ffl-funnels-addons'),
                        'desc'  => __('Cannot be combined with other coupons.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'coupon_exclude_sale',
                        'type'  => 'toggle',
                        'label' => __('Exclude sale items', 'ffl-funnels-addons'),
                        'desc'  => '',
                    ],
                    [
                        'key'   => 'coupon_free_shipping',
                        'type'  => 'toggle',
                        'label' => __('Allow free shipping', 'ffl-funnels-addons'),
                        'desc'  => __('Needs a free shipping method that accepts coupons in the shipping zone.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'coupon_categories',
                        'type'    => 'multiselect',
                        'label'   => __('Only for categories', 'ffl-funnels-addons'),
                        'options' => self::category_options(),
                        'desc'    => __('None ticked: every category.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'coupon_excluded_categories',
                        'type'    => 'multiselect',
                        'label'   => __('Never for categories', 'ffl-funnels-addons'),
                        'options' => self::category_options(),
                        'desc'    => '',
                    ],
                ],
            ],
            [
                'title'  => __('Coupon email', 'ffl-funnels-addons'),
                'desc'   => __('Uses the format and design from the Emails tab, with a box showing the code and a button to the shop.', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'coupon_email_subject',
                        'type'  => 'text',
                        'label' => __('Subject', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses “Thanks for your review: a coupon for you”.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'coupon_email_heading',
                        'type'  => 'text',
                        'label' => __('Heading', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses “Thank you for your review”.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'coupon_email_text',
                        'type'  => 'editor',
                        'label' => __('Text', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses a built-in text. Placeholders: {customer_name}, {product_name}, {coupon_code}, {coupon_amount}, {coupon_expiry}, {shop_url}, {store_name}. Plain text emails add the code when the text has no {coupon_code}.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'coupon_button_text',
                        'type'  => 'text',
                        'label' => __('Button text', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses “Shop now”.', 'ffl-funnels-addons'),
                    ],
                    ['type' => 'html', 'html' => self::test_widget_html(['coupon' => __('Coupon email', 'ffl-funnels-addons')], 'ffla-pr-coupon-test-to')],
                ],
            ],
            [
                'title'  => __('Disclosure', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'coupon_badge',
                        'type'  => 'toggle',
                        'label' => __('Mark rewarded reviews', 'ffl-funnels-addons'),
                        'desc'  => __('Shows a badge on reviews that earned a coupon. Rules such as the FTC’s in the US expect incentives for reviews to be disclosed; keep it on unless you have checked otherwise.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'coupon_badge_text',
                        'type'  => 'text',
                        'label' => __('Badge text', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses “Received a coupon for this review”.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
        ];

        return $cards;
    }

    private static function qna_cards(): array
    {
        return [
            [
                'title'  => __('Product questions and answers', 'ffl-funnels-addons'),
                'desc'   => __('Shoppers ask about a product and the store (or other customers) answers. Answer and moderate them under Product Reviews → Questions.', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'qna_enabled',
                        'type'  => 'toggle',
                        'label' => __('Enable product Q&A', 'ffl-funnels-addons'),
                        'desc'  => __('Shows questions and the “Ask a question” form in a product tab and in the Bricks Product Q&A element.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'qna_tab',
                        'type'  => 'toggle',
                        'label' => __('Add a product tab', 'ffl-funnels-addons'),
                        'desc'  => __('A WooCommerce tab next to Reviews. Turn off when the Bricks element shows Q&A instead.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'qna_tab_title',
                        'type'  => 'text',
                        'label' => __('Tab title', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses “Questions & Answers”. The number of published questions is added.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'qna_per_page',
                        'type'  => 'number',
                        'label' => __('Questions per page', 'ffl-funnels-addons'),
                        'desc'  => __('“Show more questions” loads the next ones.', 'ffl-funnels-addons'),
                        'min'   => 1,
                        'max'   => 50,
                    ],
                    [
                        'key'   => 'qna_search',
                        'type'  => 'toggle',
                        'label' => __('Search box', 'ffl-funnels-addons'),
                        'desc'  => __('Searches the questions of the product.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'qna_votes',
                        'type'  => 'toggle',
                        'label' => __('Helpful votes on answers', 'ffl-funnels-addons'),
                        'desc'  => __('Same limits as review votes. Store answers always come first.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Who can ask and answer', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'     => 'qna_who_asks',
                        'type'    => 'select',
                        'label'   => __('Who can ask', 'ffl-funnels-addons'),
                        'options' => [
                            'anyone'    => __('Anyone (guests give a name and email)', 'ffl-funnels-addons'),
                            'logged_in' => __('Signed-in customers only', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Guests are limited to five questions or answers an hour per connection, and must pass Cloudflare Turnstile when it is set up.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'     => 'qna_who_answers',
                        'type'    => 'select',
                        'label'   => __('Who can answer', 'ffl-funnels-addons'),
                        'options' => [
                            'store'     => __('Only the store', 'ffl-funnels-addons'),
                            'buyers'    => __('The store and customers who bought the product', 'ffl-funnels-addons'),
                            'logged_in' => __('The store and any signed-in customer', 'ffl-funnels-addons'),
                            'anyone'    => __('Anyone', 'ffl-funnels-addons'),
                        ],
                        'desc'    => __('Answers from staff (anyone who can moderate comments) are labelled “Store answer”. Customer answers from buyers show the Verified buyer label.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'qna_moderate_questions',
                        'type'  => 'toggle',
                        'label' => __('Hold new questions for approval', 'ffl-funnels-addons'),
                        'desc'  => __('Recommended: a question is shown once you approve or answer it. Answering a held question from the Questions page approves it.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'qna_moderate_answers',
                        'type'  => 'toggle',
                        'label' => __('Hold customer answers for approval', 'ffl-funnels-addons'),
                        'desc'  => __('Store answers are never held. Questions and answers with a forbidden word (Reviews tab) are always held.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
            [
                'title'  => __('Q&A emails', 'ffl-funnels-addons'),
                'fields' => [
                    [
                        'key'   => 'qna_notify_store',
                        'type'  => 'toggle',
                        'label' => __('Email the store about new questions', 'ffl-funnels-addons'),
                        'desc'  => __('With a link to the Questions page.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'qna_notify_email',
                        'type'  => 'email',
                        'label' => __('Send them to', 'ffl-funnels-addons'),
                        'desc'  => __('Empty uses the site’s admin email.', 'ffl-funnels-addons'),
                    ],
                    [
                        'key'   => 'qna_notify_asker',
                        'type'  => 'toggle',
                        'label' => __('Email the asker when the question is answered', 'ffl-funnels-addons'),
                        'desc'  => __('Once per answer, when it is published.', 'ffl-funnels-addons'),
                    ],
                ],
            ],
        ];
    }

    /**
     * An address field and a Send test button for the given email kinds.
     *
     * @param array<string, string> $kinds kind => label
     */
    private static function test_widget_html(array $kinds, string $input_id): string
    {
        $user    = wp_get_current_user();
        $options = '';
        foreach ($kinds as $value => $label) {
            $options .= '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
        }

        return '<div class="wb-field"><label class="wb-field__label" for="' . esc_attr($input_id) . '">' . esc_html__('Send a test', 'ffl-funnels-addons') . '</label><div class="wb-field__control">'
            . '<div class="ffla-pr-inline-actions" data-ffla-pr-test data-nonce="' . esc_attr(wp_create_nonce('ffla_review_send_test')) . '">'
            . '<input type="email" id="' . esc_attr($input_id) . '" class="wb-input" value="' . esc_attr($user instanceof \WP_User ? (string) $user->user_email : '') . '">'
            . (count($kinds) > 1
                ? '<select class="wb-select" data-ffla-pr-test-kind aria-label="' . esc_attr__('Which email', 'ffl-funnels-addons') . '">' . $options . '</select>'
                : '<input type="hidden" data-ffla-pr-test-kind value="' . esc_attr((string) array_key_first($kinds)) . '">')
            . '<button type="button" class="wb-btn wb-btn--subtle" data-ffla-pr-test-send>' . esc_html__('Send test', 'ffl-funnels-addons') . '</button>'
            . '<span class="ffla-pr-result" data-ffla-pr-test-result aria-live="polite"></span>'
            . '</div>'
            . '<p class="wb-field__desc">' . esc_html__('Save your changes first: the test uses the saved settings. The subject starts with [Test]; nothing is recorded or created.', 'ffl-funnels-addons') . '</p>'
            . '</div></div>';
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

            case 'password':
                // The saved secret is never printed back into the page.
                FFLA_Admin::render_password_field($label, $key, '', $desc . ((string) $value !== '' ? ' ' . __('A secret is saved; leave empty to keep it.', 'ffl-funnels-addons') : ''));
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
