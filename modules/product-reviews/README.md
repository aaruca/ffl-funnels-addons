# Product Reviews

Gives WooCommerce products a richer review form and list (star rating plus optional extra criteria, photos and short videos, helpful votes, pinned reviews, store replies) and emails customers after an order is completed, asking them to review what they bought. It is for the store manager who moderates reviews and for whoever builds the product pages in Bricks. Module ID: `product-reviews`, off until it is switched on in **FFL Funnels → Dashboard**. Requires WooCommerce. Bricks Builder is only needed for the elements and dynamic tags; Cloudflare Turnstile is optional and comes from the separate Simple Cloudflare Turnstile plugin.

> **Set a hub page first.** Every review request email links to the **Hub page** chosen under **Order review hub**, in both email modes. Without one, the link does not lead to a review form.

## What it does

- **Review form.** Overall rating (required), optional extra star ratings (*Quality* and *Value for money* out of the box, up to six of your own), review text, and up to three photos or videos. Guests enter name and email; signed-in customers post under their account.
- **Reviews list.** Rating summary (average plus a bar per star level), *Verified buyer* label, criteria scores, photos and videos, *Helpful* and optional *Not helpful* buttons, store replies nested under the review, pinned reviews first as *Featured review*.
- **Three places to show them.** Inside WooCommerce's own product **Reviews** tab, with four Bricks elements, or on the order review hub page.
- **Review requests.** An email a set number of days after an order is marked Completed (one per product or one per order) with a signed link to a hub page where the customer reviews every product in the order without logging in. Every request carries an unsubscribe link.
- **Moderation.** Hold every new review, hold reviews with photos or videos automatically, hold or refuse reviews containing forbidden words, pin reviews.
- **Reviewer emails.** "Your review is now published" and "Someone replied to your review", which WordPress does not send on its own.
- **Spam protection.** Security token, hidden honeypot field, and Cloudflare Turnstile when the Simple Cloudflare Turnstile plugin is active.

## Setup

1. Switch the module on in **FFL Funnels → Dashboard**, then open **FFL Funnels → Product Reviews**.
2. Choose where reviews appear on product pages:
   - Standard WooCommerce product pages: turn on **Replace WooCommerce reviews tab with FFL form**.
   - A Bricks single-product template: add the **Reviews List** and **Review Form** elements (and **Reviews Rating Badge** if wanted), then turn on **Hide default Woo reviews tab** so shoppers do not get a second form.
3. Create the hub page: a normal page containing the `[ffla_order_reviews]` shortcode or the Bricks **Order reviews hub** element. Publish it and select it under **Hub page**.
4. Check **Review Collection** and **Email Template**: delay, one email per product or per order, subject and text.
5. Optional: turn on **Pretty URLs for review links**. If a link from a test email gives a 404, visit **Settings → Permalinks** once.
6. Optional: install and configure Simple Cloudflare Turnstile. There is nothing to set here; the **Cloudflare Turnstile** card shows when it is detected.
7. Test: set the delay to 0, mark a test order with your own billing email as Completed, and look for the scheduled action (group `ffla-product-reviews`) under **WooCommerce → Status → Scheduled Actions**. Set the delay back afterwards.

## Settings

**FFL Funnels → Product Reviews**, saved with **Save Settings** (needs the `manage_woocommerce` capability).

| Setting | What it does | Default |
|---|---|---|
| **Review Collection** | | |
| Enable review requests | Schedules the review request email when an order is completed. | On |
| Delay after completed order (days) | Days between Completed and the email. 0 sends at the next queue run. | 7 |
| Review request email | *One email per product (scheduled separately)* or *One email per order (all products, single link)*. | One email per product |
| Enable helpful votes | Shows the *Helpful* button on each review. | On |
| Also allow “Not helpful” votes | Adds a *Not helpful* button. Needs helpful votes on. | Off |
| Replace WooCommerce reviews tab with FFL form | The product **Reviews** tab shows the FFL list and form instead of WooCommerce's. | Off |
| Hide default Woo reviews tab | Removes the **Reviews** tab. Ignored while the setting above is on. | Off |
| Hold all new reviews for moderation | Every new product review waits for approval. | Off |
| Allow photo and video uploads | Shows the upload field on every FFL form. Off hides it and ignores uploaded files. | On |
| Review form title | Heading of the form in the WooCommerce Reviews tab (the Bricks Review Form has its own Title). | Write a review |
| **Rating criteria** | | |
| Enable secondary rating criteria | Shows the extra star groups on the form and the scores on reviews. | On |
| Criteria | One per line, `slug\|Label`, up to 6. Malformed lines are dropped on save. | `quality\|Quality` and `value\|Value for money` |
| **Review display** | | |
| Show rating summary | Average and star-by-star bars above every list. A Bricks Reviews List can override it. | On |
| Show replies under reviews | Shows approved replies beneath the review. | On |
| **Content filter** | | |
| Forbidden words | One term per line or comma separated. Case-insensitive, matches inside longer words. Empty turns the filter off. | Empty |
| When a review matches | *Hold it for moderation* or *Refuse it and ask the customer to revise*. | Hold it for moderation |
| **Reviewer notifications** | | |
| Email the reviewer when their review is approved | Sent once when a held review is approved. | On |
| Email the reviewer when someone replies | Sent once per approved reply, not when the reply comes from the reviewer's own address. | On |
| Re-subscribe an address | Not stored: enter an email and save to remove it from the unsubscribed list, when the customer asks to get review requests again. | Empty |
| **Order review hub** | | |
| Hub page | The page with the shortcode or Bricks element. Every request email (both modes) links here. Without one, links open the home page with `?ffla_ro=`, which only works if the hub is on the home page. | None |
| Pretty URLs for review links | `/order-review/{token}/` instead of `?ffla_ro={token}`. Rewrite rules are refreshed when this or the slug changes. | Off |
| Pretty URL slug | The part before the token. Empty falls back to `order-review`. | `order-review` |
| Show extra rating criteria on hub forms | Adds the configured criteria (not only Quality and Value) to each hub form. Needs secondary criteria on. | Off |
| **Email Template** | | |
| Email subject | Empty sends "How was your purchase?". | How was your purchase? |
| Email heading | First line of the email. | Leave a review for your recent order |
| Email body template | Plain text with placeholders (below); HTML tags are stripped on save. Empty, or the untouched default while *One email per order* is chosen, uses a built-in text for the chosen mode. | Hi {customer_name}, … {review_url} … Thank you! |

The **Cloudflare Turnstile** and **Bricks Elements** cards are information only. The **Reviewer notifications** card also shows how many addresses have unsubscribed.

## How it works

### Submitting a review

- The FFL form (product tab, Bricks Review Form, hub) posts to `admin-post.php`. In order it checks: valid product, security token, empty honeypot, Turnstile (if the plugin is active, skipped on signed hub links), that reviews are open (WooCommerce's *Enable product reviews* and the product's own *Enable reviews* box), for hub links that the product is in the order and has not been reviewed from that billing email yet, review text, a rating of 1–5, forbidden words (refuse mode), WordPress's *Users must be registered and logged in to comment* (skipped on signed hub links), a valid email, and WooCommerce's *Reviews can only be left by "verified owners"* (a signed hub link counts as proof of purchase; staff who can moderate comments are exempt). Problems come back as a message above the form; success shows "Thanks! Your review was submitted."
- The security token, honeypot and Turnstile are checked once per submission (a Turnstile token can only be verified once).
- The review is saved as a normal WordPress comment of type `review`, so WordPress's own comment checks (duplicates, flooding, Discussion settings) still apply.
- **Verified buyer** is set when the review came through a signed link for an order containing the product, or when WooCommerce's purchase check finds the product in an order for that email or account.
- Variations are always reviewed on the parent product.

### When a review is held or refused

| Rule | Result |
|---|---|
| **Hold all new reviews for moderation** on | Pending |
| A photo or video is attached | Pending, always |
| Forbidden word in the text or the name, *Hold it for moderation* | Pending |
| Forbidden word, *Refuse it and ask the customer to revise* | FFL form: refused with "Your review contains wording we cannot publish…". Other paths (WooCommerce's own form, REST): saved as spam. |

These rules apply to every new product review, including ones from WooCommerce's standard form. Users who can moderate comments are never held or filtered. Store replies are not held.

### Photos and videos

- Up to 3 files per review, 5 MB each: JPG, PNG, GIF, WebP, MP4 or WebM. The browser warns about extra or oversized files; the server also skips anything over the limits or of another type.
- Files go into the Media Library, not attached to the product. Media Cleaner always treats them as in use.
- Permanently deleting a review (not just moving it to the comments trash) also deletes its files.
- **Allow photo and video uploads** off removes the field from every form; anything uploaded anyway is ignored and does not hold the review.

### Ratings, criteria and summary

- The overall star rating is stored in WooCommerce's own `rating` comment meta.
- A criterion's slug is what its score is stored under, so renaming a label keeps old scores; removing a line hides the scores without deleting them. *Quality* and *Value for money* keep their original meta keys.
- The rating summary and dynamic tags count approved top-level reviews with a 1–5 rating. They are cached per product for 12 hours and refreshed whenever a review is posted, edited, approved, unapproved, deleted or pinned, or its rating changes. The Reviews Rating Badge uses the same numbers.

### Lists, pinned reviews and replies

- A list shows at most its *Max reviews* (product tab: 5 most recent). There is no pagination.
- *Most helpful* sorts all of a product's approved reviews by net score (helpful minus not helpful) in the database, pinned reviews first and newest first on ties.
- Pinned reviews always come first, labelled *Featured review*. Use the **Pin review** / **Unpin review** row action under **Products → Reviews** (needs `moderate_comments`).
- Only approved direct replies to a review are shown. A reply is badged *Store response* when its author could moderate comments at the time of replying.

### Helpful votes

- One vote per review per IP address for 12 hours, in one direction. After voting, both buttons on that review lock until the page is reloaded.
- A review accepts at most 200 votes per day. Votes are only counted on approved reviews.

### Review request emails

- Scheduled when an order changes to **Completed**, if requests are enabled, the billing email is valid and not unsubscribed, the order has not been scheduled before, and at least one product in it has not been reviewed from that email. Orders completed before the module was switched on are not picked up.
- Queued through Action Scheduler (group `ffla-product-reviews`) when available, otherwise WP-Cron.
- **One email per product**: one email per product not yet reviewed. **One email per order**: one email listing the products not yet reviewed. Both link to the hub page.
- Checked again when the email is due: the order must still be Completed, the address not unsubscribed and the email not already sent. Products reviewed in the meantime are left out; if none remain, nothing is sent.
- An order that is refunded or cancelled drops its queued emails. If it is completed again later it is scheduled again, but an email already sent is never repeated.
- Sent with `wp_mail()` as plain text to the billing email: heading, blank line, body. If the body has no `{unsubscribe_url}`, an unsubscribe line is added at the end.

| Placeholder | Replaced with |
|---|---|
| `{customer_name}` | Billing first name, or "Customer" |
| `{product_name}` | The product name; in per-order mode the comma-separated list |
| `{product_names_list}` | The comma-separated list (per-order) or the product name |
| `{review_url}`, `{review_order_url}` | The signed hub link (both give the same link) |
| `{order_id}` | The internal order ID |
| `{user_id}` | The customer's user ID (0 for guests) |
| `{unsubscribe_url}` | The unsubscribe link |

The default body is written for one product ("We would love your feedback on {product_name}"). In per-order mode `{product_name}` becomes the list, so it still works; edit the text if you want plural wording.

### The order review hub

- The signed link is valid for 90 days from when the email was sent and is tied to the order and its billing email. Changing the order's billing email invalidates it.
- The page shows "Thank you for order #…", then one form per product in the order. Products already reviewed from that billing email show "You already submitted a review for this product."
- No login is needed. Name and email come from the order, the review is marked *Verified buyer*, and Turnstile is not shown.
- Without a valid link the block shows "This link is missing a review key…" or "This review link is invalid or has expired." In the Bricks editor it shows a placeholder.

### Unsubscribe

The link in a request email opens a confirmation page; the address is only added to the list after **Confirm unsubscribe**, so mail scanners that open links cannot unsubscribe anyone. The list stores a hash of the address, not the address. It only stops review requests; approval and reply notices are still sent. To take an address off the list (the customer asked for requests again), use **Re-subscribe an address** in the settings.

## Where it shows up

| Place | What appears |
|---|---|
| **FFL Funnels → Product Reviews** | Settings. |
| **Products → Reviews** (WooCommerce's reviews screen) | *Review Media* and *Helpful* columns (with a *Pinned* label), and the **Pin review** / **Unpin review** row action. |
| **Comments** | The same columns, for stores that still list reviews there. |
| Product page, **Reviews** tab | FFL list (5 most recent) and form, when *Replace WooCommerce reviews tab* is on. |
| Bricks elements, category **FFL Funnels** | See below. |
| Bricks dynamic tags, group **FFL Funnels - Product Reviews** | `{ffla_review_count}`, `{ffla_review_average}` (one decimal, empty with no reviews), `{ffla_review_recommend_percent}` (share of 4 and 5 star reviews, empty with no reviews). |
| Hub page | Forms for every product in the order. |
| Customer inbox | Review request, approval and reply emails. |

| Bricks element | Shows | Main controls |
|---|---|---|
| Reviews Rating Badge | Average, partial stars, count in brackets (the same numbers as the rating summary) | Product ID, Average number (Show / Hide), Review count (Show / Hide), Hide when no reviews, star colours and size, typography |
| Reviews List | Summary and review cards | Product ID, Max reviews (1–50, default 5), Order by (Most recent / Most helpful), Rating summary (Use module setting / Show / Hide), star and card styling, typography |
| Review Form | The FFL form | Product ID, Title (default "Write a review"), Intro text, Extra rating criteria (Show / Hide; all configured criteria), Media upload (Collapsed / Expanded), Login hint (Show / Hide); Style tab: Container, Stars, Fields, Submit button, Notices |
| Order reviews hub | The hub | Intro title (optional), plus Bricks' own ID, classes and styles on the wrapper |

The on/off options above are selects, not checkboxes: Bricks drops an unchecked checkbox, which made default-on checkboxes impossible to switch off. Elements saved with the old checkboxes keep showing everything until you pick *Hide* (or *Expanded*).

Empty *Product ID* means the current product: the global product, the Bricks query loop item or the product page. In the Bricks editor the newest published product is used for the preview.

The CSS and JavaScript load on product pages, the hub page, pages whose content contains `[ffla_order_reviews]`, and in the Bricks editor.

## Data and uninstall

| Data | Where | On uninstall |
|---|---|---|
| Settings | option `ffla_product_reviews_settings` | Deleted |
| Unsubscribed addresses (hashed) | option `ffla_review_email_optouts` | Kept on purpose, so a reinstall does not email them again |
| Reviews and replies | WordPress comments on products | Kept |
| Rating, criteria scores, votes, verified flag, media IDs, notification flags | comment meta | Kept |
| Pinned flag | `comment_karma` = 1 on the comment | Kept |
| Review photos and videos | Media Library | Kept |
| Request tracking | order meta | Kept |
| Queued request emails | Action Scheduler / WP-Cron | Removed |
| Rating summary cache | transients `ffla_rev_dist_<product ID>` | Deleted |
| Vote limits | transients | Expire on their own (12 hours / 1 day) |

Uninstall cleanup runs whenever the settings option exists, even if the module was switched off first. Switching the module off keeps everything; request emails that come due while it is off are not sent (switching a module off stops all of its work, including queued emails).

## Troubleshooting

- **No request emails.** Check *Enable review requests*; that the order reached Completed while the module was on; the billing email; whether the address unsubscribed; whether every product in the order was already reviewed from that email; the action in **WooCommerce → Status → Scheduled Actions** (group `ffla-product-reviews`); and that the site can send email at all.
- **The link opens the home page or shows "missing a review key".** No **Hub page** is set, or the page does not contain the shortcode or element.
- **Pretty link gives a 404.** Visit **Settings → Permalinks** once, and make sure the hub page is published.
- **"This review link is invalid or has expired."** The link is older than 90 days, the order's billing email changed, or the link was cut off or altered on the way.
- **Reviews stay pending.** A photo or video is attached, *Hold all new reviews* is on, a forbidden word matched, or a WordPress Discussion setting held it.
- **Stars cannot be clicked, votes do nothing, the file picker looks plain.** The CSS/JS did not load on that page (for example a Bricks element outside a product page). Return `true` from `ffla_product_reviews_enqueue_assets` there.
- **"Security check failed."** The form's WordPress security token is no longer valid, for example on a product page served from a full-page cache. Refresh, or keep product pages out of long-lived caching.
- **Two review forms on a product.** Turn on *Hide default Woo reviews tab* when using the Bricks elements.
- **"Reviews are closed for this product."** WooCommerce's *Enable product reviews* is off, or the product's *Enable reviews* box (Advanced tab) is unticked.
- **"Only customers who bought this product can review it."** WooCommerce → Settings → Products → *Reviews can only be left by "verified owners"* is on. Customers can still review through the signed link in a request email.
- **"Vote already registered recently."** Someone on the same IP address voted on that review in the last 12 hours.

## For developers

**Filters**

| Hook | Arguments | Use |
|---|---|---|
| `ffla_product_reviews_wc_tab_list_settings` | `array $settings`, `int $product_id` | List in the replaced tab. Keys `perPage` (1–50), `orderBy` (`recent`/`helpful`), `showSummary` (bool). Default `['perPage' => 5, 'orderBy' => 'recent']`. |
| `ffla_product_reviews_wc_tab_form_settings` | `array $settings`, `int $product_id` | Form in the replaced tab. Keys `title`, `introText`, `showOptionalCriteria`, `collapseMedia`, `showLoginHint`. Default `[]`. |
| `ffla_product_reviews_enqueue_assets` | `bool $force` (false) | Load the front-end CSS/JS on other pages. |
| `ffla_helpful_daily_cap` | `int` (200) | Votes per review per day. |
| `ffla_review_approved_email_subject`, `ffla_review_approved_email_body` | `string`, `WP_Comment $review` | Approval email. |
| `ffla_review_reply_email_subject`, `ffla_review_reply_email_body` | `string`, `WP_Comment $reply`, `WP_Comment $review` | Reply email. |

**Scheduled actions** (Action Scheduler group `ffla-product-reviews`, or single WP-Cron events): `ffla_send_product_review_request` (`$order_id`, `$product_id`, `$user_id`) and `ffla_send_order_review_bundle` (`$order_id`).

**Endpoints**

| Endpoint | Notes |
|---|---|
| `admin-post.php`, action `ffla_submit_product_review` (POST, guests too) | Fields `comment_post_ID`, `rating`, `comment`, `author` and `email` (guests), `ffla_review_criteria[<slug>]`, `ffla_review_media[]` (multipart), `ffla_order_review_token`, `redirect_to`, `ffla_hp` (must be empty), `ffla_review_form_nonce` (nonce action `ffla_review_form`). Redirects with `ffla_review_status` (`success`/`error`) and `ffla_review_message`, anchored `#reviews`. |
| `admin-ajax.php`, action `ffla_vote_review_helpful` (POST, guests too) | `nonce` (`ffla_product_reviews_nonce`), `comment_id`, `vote` (`yes`/`no`). Returns `count`, `countNo`, `throttled`, `message`. |
| `admin-post.php`, action `ffla_review_unsubscribe` (GET `t`), then `ffla_review_unsubscribe_confirm` (POST `t`) | Unsubscribe confirmation and opt-out. Tokens do not expire. |
| `admin-post.php`, action `ffla_toggle_review_pin` | `comment_id`, `pin` (`1`/`0`), nonce; needs `moderate_comments`. |
| `admin-post.php`, action `product_reviews_save_settings` | Settings form; needs `manage_woocommerce`. |

**Other**

- Shortcode `[ffla_order_reviews]` (no attributes). The token arrives as `?ffla_ro=` or through the rewrite `/<slug>/<token>/` (query var `ffla_order_review_token`).
- Bricks element names: `ffla-reviews-rating-badge`, `ffla-reviews-list`, `ffla-review-form`, `ffla-order-review-hub`.
- Comment meta: `rating`, `ffla_review_quality`, `ffla_review_value`, `ffla_review_criteria_<slug>`, `ffla_helpful_yes`, `ffla_helpful_no`, `ffla_verified_purchase`, `ffla_review_media_ids` (array of attachment IDs), `ffla_is_store_reply`, `_ffla_approved_notified`, `_ffla_reply_notified`.
- Order meta: `_ffla_review_request_scheduled`, `_ffla_review_bundle_sent`, `_ffla_review_sent_<product ID>`.
- `Product_Reviews_Core::get_rating_distribution( $product_id )` returns `counts` (per star), `total` and `average`, using the 12-hour cache.
