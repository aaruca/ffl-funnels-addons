# Product Reviews

Gives WooCommerce products a richer review form and list (star rating plus optional extra criteria, photos and short videos, helpful votes, pinned reviews, store replies) and emails customers after an order is completed, asking them to review what they bought. It is for the store manager who moderates reviews and for whoever builds the product pages in Bricks. Module ID: `product-reviews`, off until it is switched on in **FFL Funnels → Dashboard**. Requires WooCommerce. Bricks Builder is only needed for the elements and dynamic tags; Cloudflare Turnstile is optional and comes from the separate Simple Cloudflare Turnstile plugin.

> **Set a hub page first.** Every review request email links to the **Hub page** chosen under **Order review hub**, in both email modes. Until a published page is selected there, no request is scheduled or sent, and wp-admin shows a warning while *Enable review requests* is on.

## What it does

- **Review form.** Overall rating (required), optional extra star ratings (*Quality* and *Value for money* out of the box, up to six of your own), review text, and up to three photos or videos. Guests enter name and email; signed-in customers post under their account.
- **Reviews list.** Rating summary (average plus a bar per star level), *Verified buyer* label, criteria scores, photos and videos, *Helpful* and optional *Not helpful* buttons, store replies nested under the review, pinned reviews first as *Featured review*.
- **Three places to show them.** Inside WooCommerce's own product **Reviews** tab, with four Bricks elements, or on the order review hub page.
- **Review requests.** An email a set number of days after an order is marked Completed (one per order, or one per product) with a signed link to a hub page where the customer reviews every product in the order without logging in. Every request carries an unsubscribe link.
- **Moderation.** Hold every new review, hold reviews with photos or videos automatically, hold or refuse reviews containing forbidden words, pin reviews. One review per customer per product.
- **Reviewer emails.** "Your review is now published" and, for store replies, "Someone replied to your review", which WordPress does not send on its own.
- **Spam protection.** Hidden honeypot field, Cloudflare Turnstile when the Simple Cloudflare Turnstile plugin is active, an hourly limit for guests, and a security token for signed-in users.
- **Privacy.** Location and camera data are removed from review photos before they are stored.

## Setup

1. Switch the module on in **FFL Funnels → Dashboard**, then open **FFL Funnels → Product Reviews**.
2. Choose where reviews appear on product pages (**Reviews** tab):
   - Standard WooCommerce product pages: turn on **Replace WooCommerce reviews tab with FFL form**.
   - A Bricks single-product template: add the **Reviews List** and **Review Form** elements (and **Reviews Rating Badge** if wanted), then turn on **Hide default Woo reviews tab** so shoppers do not get a second form.
3. Create the hub page: a normal page containing the `[ffla_order_reviews]` shortcode or the Bricks **Order reviews hub** element. Publish it and select it under **Review requests → Hub page**. Request emails do not run without it.
4. Check the **Review requests** and **Emails** tabs: delay, one email per order or per product, subject and text. Check that **WooCommerce → Settings → Emails** has the sender name and address the emails should come from.
5. Optional: turn on **Pretty URLs for review links** (Review requests tab). If a link from a test email gives a 404, visit **Settings → Permalinks** once.
6. Optional: install and configure Simple Cloudflare Turnstile. There is nothing to set here; the **Cloudflare Turnstile** card shows when it is detected.
7. Test: set the delay to 0, mark a test order with your own billing email as Completed, and look for the scheduled action (group `ffla-product-reviews`) under **WooCommerce → Status → Scheduled Actions**. Set the delay back afterwards.

## Settings

**FFL Funnels → Product Reviews**, split into tabs. Each tab is its own form: **Save Settings** stores that tab only and leaves the others as they are (needs the `manage_woocommerce` capability).

| Setting | What it does | Default |
|---|---|---|
| **Reviews tab** | | |
| Replace WooCommerce reviews tab with FFL form | The product **Reviews** tab shows the FFL list and form instead of WooCommerce's. | Off |
| Hide default Woo reviews tab | Removes the **Reviews** tab. Ignored while the setting above is on. | Off |
| Hold all new reviews for moderation | Every new product review waits for approval. | Off |
| Forbidden words | One term per line or comma separated. Case-insensitive, matches inside longer words. Empty turns the filter off. | Empty |
| When a review matches | *Hold it for moderation* or *Refuse it and ask the customer to revise*. | Hold it for moderation |
| **Display tab** | | |
| Show rating summary | Average and star-by-star bars above every list. A Bricks Reviews List can override it. | On |
| Show replies under reviews | Shows approved replies beneath the review. | On |
| Enable helpful votes | Shows the *Helpful* button on each review. | On |
| Also allow “Not helpful” votes | Adds a *Not helpful* button. Needs helpful votes on. | Off |
| **Review form tab** | | |
| Review form title | Heading of the form in the WooCommerce Reviews tab (the Bricks Review Form has its own Title). | Write a review |
| Allow photo and video uploads | Shows the upload field on every FFL form. Off hides it and ignores uploaded files. | On |
| Enable secondary rating criteria | Shows the extra star groups on the form and the scores on reviews. | On |
| Criteria | One per line, `slug\|Label`, up to 6. Malformed lines are dropped on save. | `quality\|Quality` and `value\|Value for money` |
| **Review requests tab** | | |
| Enable review requests | Schedules the review request email when an order is completed. Does nothing until a published **Hub page** is selected. Switching it off also stops emails already queued. | On |
| Delay after completed order (days) | Days between Completed and the email. 0 sends at the next queue run. Orders placed more than this many days plus 30 before the email would go out are not emailed. | 7 |
| Review request email | *One email per order (all products, single link)* or *One email per product (all sent the same day)*. | One email per order |
| Hub page | The page with the shortcode or Bricks element. Every request email (both modes) links here. Required: without a published page here, no request is scheduled or sent. | None |
| Pretty URLs for review links | `/order-review/{token}/` instead of `?ffla_ro={token}`. Rewrite rules are refreshed when this or the slug changes. | Off |
| Pretty URL slug | The part before the token. Empty falls back to `order-review`. | `order-review` |
| Show extra rating criteria on hub forms | Adds the configured criteria (not only Quality and Value) to each hub form. Needs secondary criteria on. | Off |
| **Emails tab** | | |
| Email subject | Empty sends "How was your purchase?". | How was your purchase? |
| Email heading | First line of the email. | Leave a review for your recent order |
| Email body template | Plain text with placeholders (below); HTML tags are stripped on save. Empty, or the untouched default while *One email per order* is chosen, uses a built-in text for the chosen mode. | Hi {customer_name}, … {review_url} … Thank you! |
| Email the reviewer when their review is approved | Sent once when a held review is approved. If `wp_mail()` fails, nothing is recorded and it is sent the next time the review is approved. | On |
| Email the reviewer when someone replies | Sent once per approved store reply (written by someone who could moderate comments). Other replies are never emailed. A failed send is retried the next time the reply is approved. | On |
| Re-subscribe an address | Not stored: enter an email and save to remove it from the unsubscribed list, when the customer asks to get review requests again. | Empty |

The **Cloudflare Turnstile** card (Review form tab) and **Bricks Elements** card (Display tab) are information only. The Emails tab also shows how many addresses have unsubscribed. While requests are on and no published hub page is selected, the settings page and the rest of wp-admin (for users with `manage_woocommerce`) show a warning that links to the Review requests tab.

## How it works

### Submitting a review

- The FFL form (product tab, Bricks Review Form, hub) posts to `admin-post.php`. In order it checks: valid product, a signed hub link that is still valid (when one was sent), the security token (signed-in users only; a signed hub link replaces it), empty honeypot, Turnstile (if the plugin is active, skipped on signed hub links), that reviews are open (WooCommerce's *Enable product reviews* and the product's own *Enable reviews* box), for hub links that the product is in the order and not refunded in full, review text, a rating of 1–5, forbidden words (refuse mode), WordPress's *Users must be registered and logged in to comment* (skipped on signed hub links), a valid email, WooCommerce's *Reviews can only be left by "verified owners"*, one review per customer, and the guest hourly limit. Problems come back as a message above the form, including WordPress's own refusals (for example "You are posting comments too quickly"); success shows "Thanks! Your review was submitted."
- Guests have no security token check: a guest token is the same for every visitor and goes stale on cached product pages. They are covered by the honeypot, Turnstile and a limit of five new reviews per IP address per hour.
- The security token, honeypot and Turnstile are checked once per submission (a Turnstile token can only be verified once).
- The review is saved as a normal WordPress comment of type `review`, so WordPress's own comment checks (duplicates, flooding, Discussion settings) still apply. Signed hub links skip WordPress's 15-second flood check, so a customer can review the products of an order one after another.
- **Verified buyer** is set only when the review came through a signed link for an order that still contains the product (not refunded in full), or from a signed-in account that bought the product (WooCommerce's purchase check by account). An email address typed into a guest form never counts. WooCommerce's own *verified owner* flag on the review is set to the same answer. Reviews written before the module was switched on have no answer from the module and show WooCommerce's own flag.
- *Reviews can only be left by "verified owners"*: a signed hub link counts as proof of purchase; otherwise the customer must be signed in to an account that bought the product. Guests see "Only customers who bought this product can review it. Log in to your account, or use the review link from your order email." instead of the form. Staff who can moderate comments are exempt.
- **One review per customer per product** (staff who can moderate comments are exempt): per account, per signed link (reviews from the order's account or verified reviews under its billing email), and, for guests, per typed address. A guest review someone typed an address into does not count for the address's owner: it does not block their own review or stop their request emails. The guest check never looks at verified reviews, so the form does not reveal whether an address bought the product.
- WooCommerce's own form and anything else posting to `wp-comments-post.php` follow the same *verified owners* and one-review rules, and the honeypot. While *Replace WooCommerce reviews tab* is on, a new top-level review posted there must also pass Turnstile when the plugin is active (unless the plugin's own comment-form protection is on; it then checks the form itself). Reviews added by other code (imports, other plugins) are not affected.
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
- Files are only taken from the FFL form, after its security checks, and never for a review filed as spam or trash. Files sent with a comment to WordPress's own `wp-comments-post.php` are ignored and do not hold the review.
- Before a photo is stored, its location and camera data are removed: EXIF (GPS, camera, date), XMP, IPTC and comments in JPEG; text and EXIF chunks in PNG; EXIF and XMP in WebP. The colour profile is kept, and a rotated JPEG keeps its orientation so it still displays upright. The file is not re-encoded. A photo that cannot be read this way is skipped. GIF carries no such data; videos are stored as uploaded (their metadata is not changed).
- Files go into the Media Library, not attached to the product. Media Cleaner always treats them as in use.
- On a review, videos play in WordPress's own video player and photos use the *Medium* size with WordPress's responsive image sizes.
- Permanently deleting a review (not just moving it to the comments trash) also deletes its files.
- **Allow photo and video uploads** off removes the field from every form; anything uploaded anyway is ignored and does not hold the review.

### Ratings, criteria and summary

- The overall star rating is stored in WooCommerce's own `rating` comment meta.
- A criterion's slug is what its score is stored under, so renaming a label keeps old scores; removing a line hides the scores without deleting them. *Quality* and *Value for money* keep their original meta keys.
- The rating summary, the Reviews Rating Badge and the dynamic tags use the numbers WooCommerce keeps on each product: the star counts and average of approved ratings, and its review count. They match the **Reviews (n)** tab title, the stars in shop loops and the structured data. WooCommerce updates them whenever a review is posted, edited, approved, unapproved or deleted; a rating written directly (custom code, imports, WP-CLI) also triggers a recount. Reading them costs no extra query, even for a badge on every product in a loop.
- The review count can be higher than the number of ratings: WooCommerce also counts reviews left without stars. The summary bars and the 4–5 star share are worked out from the ratings.

### Lists, pinned reviews and replies

- A list shows at most its *Max reviews* (product tab: 5 most recent). There is no pagination.
- *Most helpful* sorts all of a product's approved reviews by net score (helpful minus not helpful) in the database, pinned reviews first and newest first on ties.
- Pinned reviews always come first, labelled *Featured review*. Use the **Pin review** / **Unpin review** row action under **Products → Reviews** (needs `moderate_comments`). Pinning refreshes the product's comment count, which tells page caches (caching plugins and hosts that purge on comment or post changes) to drop the cached product page.
- Only approved direct replies to a review are shown. A reply is badged *Store response* when its author could moderate comments at the time of replying. Only those store replies are emailed to the reviewer.

### Helpful votes

- One vote per review per IP address for 12 hours, in one direction. After voting, both buttons on that review lock until the page is reloaded and a short note confirms the vote.
- Behind Cloudflare the shopper's own address is used (the `CF-Connecting-IP` header), but only when the request really comes from one of Cloudflare's published addresses. Otherwise all shoppers reaching the store through the same Cloudflare edge would share one address, and one vote would lock the review for all of them.
- A review accepts at most 200 votes per calendar day (store time zone); the count starts over the next day. Votes are only counted on approved reviews.
- If the page came from a page cache and its security token has expired, the vote is retried once with a fresh token. When a vote fails, the reason shows under the buttons.

### Structured data (SEO)

Google only accepts review markup for reviews shoppers can see on the page. WooCommerce marks up the 5 newest rated reviews of any kind, while the FFL list shows pinned reviews first, can sort by *Most helpful*, shows only product reviews and has its own *Max reviews*. When an FFL list shows a product's reviews, the `review` list in the product's structured data is therefore replaced by the rated reviews that list shows, in the same order (a review without stars is left out, as WooCommerce does). The average and review count stay WooCommerce's, which the summary and badge also show.

- **WooCommerce's structured data** (printed in the footer): first matched to the list the page is expected to show, then to the reviews the lists on the page actually rendered.
- **Rank Math's** Product schema, when Rank Math builds it: printed in the page head, before any list renders, so only the expected list is used.
- The expected list is the **Reviews List** element in the product's Bricks template (its *Max reviews* and *Order by*), or, with *Replace WooCommerce reviews tab* on, the tab's list. A list inside a nested template or a component is not seen in advance; WooCommerce's markup still ends up matching it, Rank Math's does not (use the `ffla_product_reviews_structured_data_list_settings` filter).
- With WooCommerce's own Reviews tab in use and no FFL list on the page, the markup is left as WooCommerce builds it.

### Review request emails

- Scheduled when an order changes to **Completed**, if requests are enabled, a published hub page is selected, the billing email is valid and not unsubscribed, the order has not been scheduled before, the order was placed no more than the request delay plus 30 days before the email would go out (imports and old orders completed in bulk are skipped), and at least one product in it can still be reviewed: not refunded in full, reviews open, and not yet reviewed by the customer (from their account, or a verified review under the billing email). Orders completed before the module was switched on are not picked up.
- Queued through Action Scheduler (group `ffla-product-reviews`) when available, otherwise WP-Cron.
- **One email per order** (the default): one email listing the products still to review. **One email per product**: one email per such product, all on the same day. Both link to the hub page.
- Checked again when the email is due: requests still enabled and the hub page still published, the order still Completed (or moved to a store-specific status after completion, such as a custom *Delivered*), still within the age limit, the address not unsubscribed and the email not already sent. Products reviewed, refunded in full or closed to reviews in the meantime are left out; if none remain, nothing is sent.
- An order that is back in Processing, On hold or another status from before completion when its email is due is not emailed and is released: when it is completed again, the request is scheduled again. An order that is refunded or cancelled drops its queued emails; if it is completed again later it is scheduled again. An email already sent is never repeated.
- Sent with `wp_mail()` as plain text to the billing email: heading, blank line, body. If the body has no `{unsubscribe_url}`, an unsubscribe line is added at the end. From is WooCommerce's sender name and address (**WooCommerce → Settings → Emails**); Reply-To is WooCommerce's reply-to address when that setting exists and is on, otherwise the same sender. The approval and reply notices use the same sender.
- An email only counts as sent when `wp_mail()` succeeds. A failure is logged under **WooCommerce → Status → Logs** (source `ffla-product-reviews`) and tried again an hour later, three attempts in all. Approval and reply notices are logged the same way, and are sent the next time the review or reply is approved.
- Switching the module off removes its queued emails. Orders that were already scheduled are not scheduled again when it is switched back on.

| Placeholder | Replaced with |
|---|---|
| `{customer_name}` | Billing first name, or "Customer" |
| `{product_name}` | The product name; in per-order mode the comma-separated list |
| `{product_names_list}` | The comma-separated list (per-order) or the product name |
| `{review_url}`, `{review_order_url}` | The signed hub link (both give the same link) |
| `{order_id}` | The internal order ID |
| `{user_id}` | The customer's user ID (0 for guests) |
| `{unsubscribe_url}` | The unsubscribe link |

The default body is written for one product ("We would love your feedback on {product_name}"). While it is left untouched, *One email per order* uses a built-in text that lists the products instead. Once edited, the text is used as written in both modes; in per-order mode `{product_name}` becomes the list.

### The order review hub

- The signed link is valid for 90 days from when the email was sent and is tied to the order and its billing email. Changing the order's billing email invalidates it, and so does refunding, cancelling or failing the order.
- The page shows "Thank you for order #…", then one form per product in the order that can still be reviewed (products refunded in full and products closed to reviews are left out). Products the customer already reviewed show "You already submitted a review for this product."
- No login and no security token are needed: the signed link is the proof. Name and email come from the order on the server (the forms carry neither), the review is marked *Verified buyer*, and Turnstile is not shown.
- A page opened with a valid signed link is sent with no-cache headers and `DONOTCACHEPAGE`, so page caches never store it, and with `Referrer-Policy: same-origin`, so the link is not passed to other sites.
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
| Bricks dynamic tags, group **FFL Funnels - Product Reviews** | `{ffla_review_count}` (WooCommerce's review count), `{ffla_review_average}` (one decimal, empty with no ratings), `{ffla_review_recommend_percent}` (share of 4 and 5 star ratings, empty with no ratings). |
| Product page structured data | The `review` list matches the FFL list (see *Structured data*). |
| Hub page | Forms for every product in the order. |
| Customer inbox | Review request, approval and reply emails. |

| Bricks element | Shows | Main controls |
|---|---|---|
| Reviews Rating Badge | Average, partial stars, review count in brackets (WooCommerce's numbers, as in the rating summary) | Product ID, Average number (Show / Hide), Review count (Show / Hide), Hide when no reviews, star colours and size, typography |
| Reviews List | Summary and review cards | Product ID, Max reviews (1–50, default 5), Order by (Most recent / Most helpful), Rating summary (Use module setting / Show / Hide), star and card styling, typography |
| Review Form | The FFL form | Product ID, Title (default "Write a review"), Intro text, Extra rating criteria (Show / Hide; all configured criteria), Media upload (Collapsed / Expanded), Login hint (Show / Hide); Style tab: Container, Stars, Fields, Submit button, Notices |
| Order reviews hub | The hub | Intro title (optional), plus Bricks' own ID, classes and styles on the wrapper |

The on/off options above are selects, not checkboxes: Bricks drops an unchecked checkbox, which made default-on checkboxes impossible to switch off. Elements saved with the old checkboxes keep showing everything until you pick *Hide* (or *Expanded*).

Empty *Product ID* means the current product: the global product, the Bricks query loop item or the product page. In the Bricks editor the newest published product is used for the preview.

The CSS and JavaScript load only where review output is printed: each Bricks element loads them when it renders (the badge loads only the CSS, so shop and archive loops get styled stars), and so do the list, form and hub themselves. They go in the page head on the hub page, on pages whose content contains `[ffla_order_reviews]`, on product pages while *Replace WooCommerce reviews tab* is on, and in the Bricks editor; anywhere else they are added at the end of the page. A product page without any FFL review output loads neither file. The script is left out when nothing on the page needs it (a list with helpful votes off, a badge).

## Data and uninstall

| Data | Where | On uninstall |
|---|---|---|
| Settings | option `ffla_product_reviews_settings` | Deleted |
| Unsubscribed addresses (hashed) | option `ffla_review_email_optouts` | Kept on purpose, so a reinstall does not email them again |
| Reviews and replies | WordPress comments on products | Kept |
| Rating, criteria scores, votes, verified flag (the module's and WooCommerce's `verified`), media IDs, notification flags | comment meta | Kept |
| Pinned flag | `comment_karma` = 1 on the comment | Kept |
| Review photos and videos | Media Library | Kept |
| Request tracking (scheduled, sent, failed attempts) | order meta | Kept |
| Queued request emails | Action Scheduler / WP-Cron | Removed (also when the module is switched off) |
| Rating summary cache from versions before 1.55.10 (no longer written) | transients `ffla_rev_dist_<product ID>` | Deleted |
| Vote limits, guest review limit | transients (`ffla_helpful_<hash>`, `ffla_helpful_daily_<review ID>_<date>`, guest limit per IP) | Expire on their own (12 hours / 1 day / 1 hour) |

Uninstall cleanup runs whenever the settings option exists, even if the module was switched off first. Switching the module off keeps settings and data but removes its queued request emails; they are not sent later.

## Troubleshooting

- **No request emails.** Check *Enable review requests* and that a published **Hub page** is selected (wp-admin warns when it is not); that the order reached Completed while the module was on; that it was placed no more than the delay plus 30 days before the email was due; the billing email; whether the address unsubscribed; whether every product in the order was already reviewed, refunded in full or closed to reviews; the action in **WooCommerce → Status → Scheduled Actions** (group `ffla-product-reviews`); and the `ffla-product-reviews` log under **WooCommerce → Status → Logs**, where failed sends are recorded.
- **The link shows "missing a review key".** The hub page does not contain the shortcode or element.
- **Pretty link gives a 404.** Visit **Settings → Permalinks** once, and make sure the hub page is published.
- **"This review link is invalid or has expired."** The link is older than 90 days, the order's billing email changed, the order was refunded, cancelled or failed, or the link was cut off or altered on the way.
- **Reviews stay pending.** A photo or video is attached, *Hold all new reviews* is on, a forbidden word matched, or a WordPress Discussion setting held it.
- **Stars cannot be clicked, votes do nothing, the file picker looks plain.** The CSS/JS did not load on that page, for example review markup printed by custom code that does not go through the module's renderers. Call `Product_Reviews_Assets::enqueue()` there, or return `true` from `ffla_product_reviews_enqueue_assets` to load them in the page head.
- **"Security check failed."** Only signed-in users see this: the form's WordPress security token is no longer valid (the page was open for more than a day). Refresh the page.
- **"You already submitted a review for this product."** The customer's account, signed link or (for guests) the typed address already has a review on that product, approved or pending.
- **"Too many reviews were sent from your connection."** Five guest reviews came from that IP address within the hour (`ffla_product_reviews_guest_hourly_limit`).
- **"Cloudflare validation failed" on WooCommerce's own form.** *Replace WooCommerce reviews tab* is on and something on the page still uses WordPress's comment form without a Turnstile widget. Turn on the Turnstile plugin's own comment-form protection, or use the FFL form there.
- **"This page has expired. Reload it and try again." under the vote buttons.** A signed-in user's security token had expired (a long-cached page) and the automatic retry also failed. Reload the page.
- **Every vote says "Vote already registered recently" behind a proxy.** The visitor address is wrong. Cloudflare is handled; for another proxy or load balancer, return the visitor's address from `ffla_product_reviews_client_ip`.
- **Rich results test shows other reviews than the page.** Check which list the product page shows (see *Structured data*). A Reviews List in a nested template is only matched in WooCommerce's markup; for Rank Math's, return its settings from `ffla_product_reviews_structured_data_list_settings`.
- **Two review forms on a product.** Turn on *Hide default Woo reviews tab* when using the Bricks elements.
- **"Reviews are closed for this product."** WooCommerce's *Enable product reviews* is off, or the product's *Enable reviews* box (Advanced tab) is unticked.
- **"Only customers who bought this product can review it." / "Please log in to review this product…"** WooCommerce → Settings → Products → *Reviews can only be left by "verified owners"* is on. Customers review by signing in to the account they bought with, or through the signed link in a request email.
- **"Vote already registered recently."** Someone on the same IP address voted on that review in the last 12 hours.

## For developers

**Filters**

| Hook | Arguments | Use |
|---|---|---|
| `ffla_product_reviews_wc_tab_list_settings` | `array $settings`, `int $product_id` | List in the replaced tab. Keys `perPage` (1–50), `orderBy` (`recent`/`helpful`), `showSummary` (bool). Default `['perPage' => 5, 'orderBy' => 'recent']`. |
| `ffla_product_reviews_wc_tab_form_settings` | `array $settings`, `int $product_id` | Form in the replaced tab. Keys `title`, `introText`, `showOptionalCriteria`, `collapseMedia`, `showLoginHint`. Default `[]`. |
| `ffla_product_reviews_enqueue_assets` | `bool $force` (false) | Load the front-end CSS/JS in the page head on other pages. |
| `ffla_product_reviews_structured_data_list_settings` | `array\|null $settings`, `int $product_id` | The list the product page will show (`perPage`, `orderBy`), worked out before it renders; `null` leaves the structured data alone. Default: the Bricks Reviews List element in the page content, or the replaced tab's list. |
| `ffla_helpful_daily_cap` | `int` (200) | Votes per review per calendar day. |
| `ffla_product_reviews_guest_hourly_limit` | `int` (5) | New guest reviews per IP address per hour through the FFL form. 0 turns the limit off. |
| `ffla_product_reviews_request_max_order_age_days` | `int` (delay + 30), `WC_Order $order` | Oldest order, in days between order date and send time, that still gets a request email. 0 turns the limit off. |
| `ffla_product_reviews_request_status_allowed` | `bool`, `WC_Order $order` | Whether the order's status allows its request email when it is due. Default: Completed, or a status outside WooCommerce's own list reached after completion. |
| `ffla_product_reviews_client_ip` | `string $ip` | Visitor address used to throttle votes. |
| `ffla_product_reviews_cloudflare_ranges` | `string[]` (Cloudflare's published IPv4/IPv6 ranges) | Proxy addresses whose `CF-Connecting-IP` header is trusted. |
| `ffla_review_approved_email_subject`, `ffla_review_approved_email_body` | `string`, `WP_Comment $review` | Approval email. |
| `ffla_review_reply_email_subject`, `ffla_review_reply_email_body` | `string`, `WP_Comment $reply`, `WP_Comment $review` | Reply email. |

**Scheduled actions** (Action Scheduler group `ffla-product-reviews`, or single WP-Cron events): `ffla_send_product_review_request` (`$order_id`, `$product_id`, `$user_id`) and `ffla_send_order_review_bundle` (`$order_id`).

**Endpoints**

| Endpoint | Notes |
|---|---|
| `admin-post.php`, action `ffla_submit_product_review` (POST, guests too) | Fields `comment_post_ID`, `rating`, `comment`, `author` and `email` (guests without a signed link), `ffla_review_criteria[<slug>]`, `ffla_review_media[]` (multipart), `ffla_order_review_token`, `redirect_to`, `ffla_hp` (must be empty), `ffla_review_form_nonce` (nonce action `ffla_review_form`; checked for signed-in users without a signed link only). Redirects with `ffla_review_status` (`success`/`error`) and `ffla_review_message`, anchored `#reviews`. |
| `admin-ajax.php`, action `ffla_vote_review_helpful` (POST, guests too) | `nonce` (`ffla_product_reviews_nonce`; checked for signed-in users only), `comment_id`, `vote` (`yes`/`no`). Returns `count`, `countNo`, `throttled`, `message`. A signed-in user's invalid or expired nonce gets HTTP 403 with `code` `invalid_nonce`, a fresh `nonce` and a `message`. |
| `admin-post.php`, action `ffla_review_unsubscribe` (GET `t`), then `ffla_review_unsubscribe_confirm` (POST `t`) | Unsubscribe confirmation and opt-out. Tokens do not expire. |
| `admin-post.php`, action `ffla_toggle_review_pin` | `comment_id`, `pin` (`1`/`0`), nonce; needs `moderate_comments`. |
| `admin-post.php`, action `product_reviews_save_settings` | Settings form, one tab per post (`tab` field); only that tab's keys are written. Needs `manage_woocommerce`. Fires `ffla_product_reviews_settings_saved` (`$tab`, `$new`, `$old`). |

**Other**

- Shortcode `[ffla_order_reviews]` (no attributes). The token arrives as `?ffla_ro=` or through the rewrite `/<slug>/<token>/` (query var `ffla_order_review_token`).
- Bricks element names: `ffla-reviews-rating-badge`, `ffla-reviews-list`, `ffla-review-form`, `ffla-order-review-hub`.
- Comment meta: `rating`, `ffla_review_quality`, `ffla_review_value`, `ffla_review_criteria_<slug>`, `ffla_helpful_yes`, `ffla_helpful_no`, `ffla_verified_purchase`, `ffla_review_media_ids` (array of attachment IDs), `ffla_is_store_reply`, `_ffla_approved_notified`, `_ffla_reply_notified`. WooCommerce's `verified` is overwritten with the same value as `ffla_verified_purchase` for every product comment posted while the module is on.
- Order meta: `_ffla_review_request_scheduled`, `_ffla_review_bundle_sent`, `_ffla_review_sent_<product ID>`, `_ffla_review_bundle_attempts`, `_ffla_review_attempts_<product ID>` (failed sends).
- `Product_Reviews_Core::hub_page_ready()` and `requests_ready()` say whether request emails can run; `reviewable_product_ids( $order )` lists the order's products that can still be reviewed.
- `Product_Reviews_Core::get_rating_distribution( $product_id, $product = null )` returns `counts` (per star), `total` (number of ratings), `average` and `review_count`, read from WooCommerce's stored product aggregates. Pass the `WC_Product` when already loaded.
- `Product_Reviews_Core::is_verified_purchase( $comment_id )`: the module's flag when the review has one, otherwise WooCommerce's `verified`.
- `Product_Reviews_Frontend_Render::list_reviews( $product_id, $settings )` returns the reviews a list with those settings shows, in order; `rendered_reviews( $product_id )` what the lists on the current page showed.
- `Product_Reviews_Assets::enqueue( $with_script = true )` loads the front-end CSS (and JS).
