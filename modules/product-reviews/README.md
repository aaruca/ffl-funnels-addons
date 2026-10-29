# Product Reviews

Gives WooCommerce products a richer review form and list (star rating plus optional extra criteria, photos and short videos, helpful votes, pinned reviews, store replies) and emails customers after an order is completed, asking them to review what they bought. It is for the store manager who moderates reviews and for whoever builds the product pages in Bricks. Module ID: `product-reviews`, off until it is switched on in **FFL Funnels → Dashboard**. Requires WooCommerce. Bricks Builder is only needed for the elements and dynamic tags; Cloudflare Turnstile is optional and comes from the separate Simple Cloudflare Turnstile plugin.

> **Set a hub page first.** Every review request email links to the **Hub page** chosen under **Order review hub**, in both email modes. Until a published page is selected there, no request is scheduled or sent, and wp-admin shows a warning while *Enable review requests* is on.

## What it does

- **Review form.** Overall rating (required), extra star ratings (*Quality* and *Value for money* out of the box, up to twelve of your own, each optional or required), questions about the reviewer (text, one or several choices; shown on the review or kept private), review text, photos or videos (3 files of 5 MB by default, up to 10 of 50 MB), an optional terms checkbox, and a CAPTCHA (Cloudflare Turnstile, hCaptcha or reCAPTCHA v2/v3). Guests enter name and email; signed-in customers post under their account.
- **Reviews list.** Rating summary (average plus a bar per star level), *Verified buyer* label, criteria scores, photos and videos (photos open in a lightbox), *Helpful* and optional *Not helpful* buttons, store replies nested under the review, pinned reviews first as *Featured review*. Reviewer names are shortened to *Ann B.* by default, with an initials avatar.
- **Filters and paging.** Star chips (and clickable summary bars), a *With photos* chip, a sort menu (most recent, most helpful, highest, lowest), a search box and *Show more reviews*, all without reloading the page.
- **Store-wide lists.** A Bricks Reviews List can show one product, some product categories or the whole store, as a list, a grid or a slider. The **Store Rating Badge** shows the store's average, review count and share of verified buyers.
- **Three places to show them.** Inside WooCommerce's own product **Reviews** tab, with five Bricks elements, or on the order review hub page.
- **Review requests.** An email a set delay (days or hours, per country if needed) after an order reaches the trigger status (Completed, or any status such as a custom *Delivered*), at a chosen hour and on chosen weekdays, with an optional second reminder. It links to a hub page where the customer reviews every product in the order without logging in. HTML by default (logo, product photos, five clickable stars per product, a button), or plain text. Every request carries an unsubscribe link.
- **Who and what is asked.** Guests on or off, user roles, product categories, free products left out, a product limit for large orders, excluded addresses and domains, one request per customer within a window, optional consent checkbox at checkout (classic and block checkout), the account address instead of the billing address.
- **On the order.** A *Review request* box: status (scheduled, sent, opened, clicked, reviewed, skipped and why), *Send review request now*, *Copy review link*, a QR code (download or print), WhatsApp click-to-chat with the link, and *Cancel*. A *Send review request* bulk action on the orders list, and a **Review requests** log with 30-day totals.
- **Coupons for reviews.** A coupon by email when a verified buyer's review is published, whatever the rating: one per order, up to three tiers by number of photos or videos, a new unique coupon (restricted to the reviewer, one use) or an existing code, and a disclosure badge on rewarded reviews.
- **Product Q&A.** Shoppers ask about a product in a product tab or the Bricks **Product Q&A** element; the store (or, if allowed, buyers or any customer) answers. Held for approval by default, store answers labelled and first, helpful votes on answers, search and *Show more*, an admin **Questions** page with inline answers, emails to the store and the asker.
- **Import and export.** Reviews (with replies, criteria, photos and votes) and Q&A as CSV; import from another store or from Customer Reviews for WooCommerce exports in the background, with photos downloaded; a one-click copy of that plugin's photos and votes.
- **Moderation.** Hold every new review, hold reviews with photos or videos automatically, hold or refuse reviews containing forbidden words, pin reviews. One review per customer per product.
- **Reviewer emails.** "Your review is now published" and, for store replies, "Someone replied to your review", which WordPress does not send on its own.
- **Spam protection.** Hidden honeypot field, a CAPTCHA (the Simple Cloudflare Turnstile plugin by default, or Turnstile, hCaptcha, reCAPTCHA v2 or v3 with your own keys), an hourly limit for guests, and a security token for signed-in users.
- **For the store.** An email about each new review (or only low ratings), and a *Photos and videos* box on a review's edit screen to add or take off files.
- **Privacy.** Location and camera data are removed from review photos before they are stored.

## Setup

1. Switch the module on in **FFL Funnels → Dashboard**, then open **FFL Funnels → Product Reviews**.
2. Choose where reviews appear on product pages (**Reviews** tab):
   - Standard WooCommerce product pages: turn on **Replace WooCommerce reviews tab with FFL form**.
   - A Bricks single-product template: add the **Reviews List** and **Review Form** elements (and **Reviews Rating Badge** if wanted), then turn on **Hide default Woo reviews tab** so shoppers do not get a second form.
3. Create the hub page: a normal page containing the `[ffla_order_reviews]` shortcode or the Bricks **Order reviews hub** element. Publish it and select it under **Review requests → Hub page**. Request emails do not run without it.
4. Check the **Review requests** tab (trigger status, delay, sending hour and days, who gets requests) and the **Emails** tab (format, subject, text, design). Use **Preview** and **Send a test** at the bottom of the Emails tab. Check that **WooCommerce → Settings → Emails** has the sender name and address the emails should come from; its header image, colours and footer are used until you set your own.
5. Optional: turn on **Pretty URLs for review links** (Review requests tab). If a link from a test email gives a 404, visit **Settings → Permalinks** once.
6. Optional: spam protection under **Review form → CAPTCHA**: keep *Simple Cloudflare Turnstile plugin* (install and configure that plugin), or pick Turnstile, hCaptcha or reCAPTCHA and paste the site and secret keys.
7. Test: open a test order with your own billing email and use **Send review request now** in its *Review request* box; or set the delay to 0, move the order to the trigger status and look for the scheduled action (group `ffla-product-reviews`) under **WooCommerce → Status → Scheduled Actions** and in **Product Reviews → Review requests**. Set the delay back afterwards.

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
| Reviews per page | First page of the WooCommerce Reviews tab, and each *Show more* (1–50). A Bricks Reviews List uses its own *Max reviews*. | 5 |
| Filters, sort and search above lists | Star chips and clickable summary bars, *With photos*, the sort menu and the search box. A Bricks Reviews List can switch each part on or off. Not in the slider layout unless the element asks for it. | On |
| “Show more reviews” button | Loads the next page under the list. | On |
| Reviewer name | *First name and last initial (Ann B.)*, *Name as entered*, *First name only*, *Initials only (A. B.)* or *Anonymous (Customer)*. Cards and structured data; the stored name is not changed. | First name and last initial |
| Avatars | *Initials on a colour*, *WordPress avatar (Gravatar)* or *No avatar*. | Initials |
| Verified buyer label | Text of the badge. Empty uses “Verified buyer”. | Verified buyer |
| Open review photos in a lightbox | Photos open full size over the page, with previous / next. Off for themes that put every image in their own lightbox. | On |
| Enable helpful votes | Shows the *Helpful* button on each review. | On |
| Also allow “Not helpful” votes | Adds a *Not helpful* button. Needs helpful votes on. | Off |
| **Review form tab** | | |
| Review form title | Heading of the form in the WooCommerce Reviews tab (the Bricks Review Form has its own Title). | Write a review |
| Log in page | Where the *Log in* links on the review and Q&A forms go (back to the product). Empty: WooCommerce's My Account page, else the WordPress login. | Empty |
| Allow photo and video uploads | Shows the upload field on every FFL form. Off hides it and ignores uploaded files. | On |
| Most files per review / Largest file (MB) | 1–10 files, 1–50 MB each (the server's upload limit still applies). | 3 / 5 |
| Enable secondary rating criteria | Shows the extra star groups on the form and the scores on reviews. | On |
| Criteria | One per line, `slug\|Label`, or `slug\|Label\|required`, up to 12. Malformed lines are dropped on save. | `quality\|Quality` and `value\|Value for money` |
| Questions about the reviewer | One per line, `slug\|Label\|type\|choices\|flags`: type `text`, `select`, `radio` or `checkbox`; choices comma separated; flags `required`, `public` (shown on the review), `product` or `hub` (only there). Up to 10. | Empty |
| Terms checkbox: on review forms / on Q&A forms / text / page | A required checkbox; `{terms}` in the text links to the page (none: the privacy policy page). | Off |
| CAPTCHA / Site key / Secret key / reCAPTCHA v3 minimum score | *Simple Cloudflare Turnstile plugin*, *Cloudflare Turnstile*, *hCaptcha*, *reCAPTCHA v2*, *reCAPTCHA v3* (with the keys) or *None*. The secret is never printed back into the page. | Plugin / 0.5 |
| **Review requests tab** | | |
| Enable review requests | Schedules request emails automatically. Does nothing until a published **Hub page** is selected. Switching it off also stops emails already queued. Requests sent by hand still work. | On |
| Review request email | *One email per order (all products, single link)* or *One email per product (all sent the same day)*. Reminders and requests sent by hand are always one per order. | One email per order |
| Start when the order is | The order status that starts the delay: any WooCommerce status, custom ones included (Pending, Cancelled, Refunded and Failed are not offered). | Completed |
| Delay / Delay in | How long after that status the first email goes out, in *Days* or *Hours* (0–365). 0 sends at the next queue run. | 7 days |
| Delay per country | `CA\|14` per line, in the same unit; shipping country, else billing country. | Empty |
| Send at | Hold each email (and the reminder) until this hour, store time zone. | Any time |
| Send on | Weekdays emails may go out on; none or all ticked means any day. | Any day |
| Review link valid for (days) | Lifetime of the signed link in each email (1–365). | 90 |
| Send a reminder / Days after the first email | One more email for the whole order when something is still not reviewed (1–90 days later, then held for the sending hour and days). | Off / 7 |
| Guest orders | Requests for orders without an account. | On |
| Customer roles / Roles | *Every role*, *Only the roles ticked*, *Every role except those ticked* (account orders). | Every role |
| Product categories / Categories | *Every category*, *Only products in…*, *Every product except those in…* (subcategories count). Leaves products out of the email; the hub still shows the whole order. | Every category |
| Leave out free products | Products whose lines cost nothing are not listed. | Off |
| Most products per email | 0 lists all; otherwise the most expensive lines first. | 0 |
| Never email | Addresses or `@domain` per line. Also blocks requests sent by hand. | Empty |
| One request per customer every (days) | Skip an order when the billing email got a request for another order in this window. 0 is off. | 0 |
| Send to the account email | Account orders go to the account's address. | Off |
| Ask for consent / Checkbox text | *No checkbox*, or an unticked checkbox at checkout (classic checkout, and the Checkout block on WooCommerce 8.9+): only orders where it was ticked get a request, sent by hand too. | No checkbox |
| Hub page | The page with the shortcode or Bricks element. Every request email (both modes) links here. Required: without a published page here, no request is scheduled or sent. | None |
| Pretty URLs for review links | `/order-review/{token}/` instead of `?ffla_ro={token}`. Rewrite rules are refreshed when this or the slug changes. | Off |
| Pretty URL slug | The part before the token. Empty falls back to `order-review`. | `order-review` |
| Show extra rating criteria on hub forms | Adds the configured criteria (not only Quality and Value) to each hub form. Needs secondary criteria on. | Off |
| **Emails tab** | | |
| Format | *HTML* (logo, product rows with photos and five clickable stars, button) or *Plain text*. | HTML |
| Email subject / Email heading | Placeholders work in both. Empty subject sends "How was your purchase?". | How was your purchase? / Leave a review for your recent order |
| Email text | Editor; placeholders below. Left as installed (or empty) it uses a built-in text for each kind of email: HTML emails add the products and button after it, plain text emails list products and the link. If `{unsubscribe_url}` is not in it, an unsubscribe link is added. | Built-in |
| Reminder subject / heading | Empty uses “A quick reminder: how was your order?” and the request heading. | Empty |
| Logo, Button / Background / Text / Star colour, Button text | HTML design. Empty uses WooCommerce → Settings → Emails (header image, base, background and text colour); star colour gold; button “Leave a review”. Colours as hex. | Empty |
| Product rows with clickable stars | Off leaves only the text and the button. | On |
| Footer | Business name and postal address above the unsubscribe link; `{site_title}`, `{store_address}`, `{site_url}`. Empty uses WooCommerce's email footer, else the store name and WooCommerce's store address. | Empty |
| Reply-To address / BCC copy to | For request and reminder emails only. | Empty |
| Track opens and clicks | Open pixel in HTML emails and the hub visit, recorded on the order. Reviews through the link are always counted. | On |
| Preview / Send a test | Preview the request or reminder in a new tab, or send it to an address, with the newest order (or newest products). Uses the saved settings; nothing is recorded on any order. | |
| WhatsApp message | Text for the WhatsApp button on the order screen; `{customer_name}`, `{store_name}`, `{order_number}`, `{review_url}`. | Hi {customer_name}, thank you for your order… |
| Email the store about new reviews / Send them to / For reviews of | One plain-text email per new review (rating, text, Verified buyer, photos, published or waiting with a link); *Any rating* or only 3, 2 or 1 stars. | Off / admin email / Any rating |
| Email the reviewer when their review is approved | Sent once when a held review is approved. If `wp_mail()` fails, nothing is recorded and it is sent the next time the review is approved. | On |
| Email the reviewer when someone replies | Sent once per approved store reply (written by someone who could moderate comments). Other replies are never emailed. A failed send is retried the next time the reply is approved. | On |
| Re-subscribe an address | Not stored: enter an email and save to remove it from the unsubscribed list, when the customer asks to get review requests again. | Empty |

| **Coupons tab** | | |
| Send coupons for reviews | Coupon email when a review is published (held reviews: on approval), whatever its rating. Reviews from before it was switched on earn nothing. | Off |
| Which reviews | *Verified buyers* (the email link, or a signed-in account that bought the product) or *Only reviews sent through the review request email link*. Guest reviews typed on a product page never qualify. | Verified buyers |
| Customer roles / Roles | *Every role*, *Only the roles ticked*, *Every role except…*; *Guest (no account)* is one of the choices. | Every role |
| Tier 1 coupon | *A new unique coupon for each review*, *An existing coupon code* or *No coupon*, with its discount type (percentage, fixed cart, fixed product), amount and existing code. | New, 10% |
| Tier 2 / Tier 3: photos or videos at least, coupon … | A review with at least that many photos or videos gets that tier instead (*Not used* by default; 1 and 3 photos, 15% and 20%). | Not used |
| Code prefix | Codes look like `REVIEW-7KQ2M9XA`. | `REVIEW-` |
| Valid for (days) | 0 never expires. | 30 |
| Anyone may use the code | Off: only the reviewer's email address. | Off |
| Uses per coupon | | 1 |
| Minimum spend / Maximum spend | Empty for none. | Empty |
| Individual use only / Exclude sale items / Allow free shipping | As on a WooCommerce coupon. | On / Off / Off |
| Only for categories / Never for categories | Product categories the coupon applies to or never applies to. | None |
| Subject, Heading, Text, Button text | The coupon email; `{customer_name}`, `{product_name}`, `{coupon_code}`, `{coupon_amount}`, `{coupon_expiry}`, `{shop_url}`, `{store_name}`. Format and design come from the Emails tab. *Send a test* sends it with a made-up code. | Built-in |
| Mark rewarded reviews / Badge text | Badge on reviews that earned a coupon (disclosure rules such as the FTC's expect it). | On / Received a coupon for this review |

| **Q&A tab** | | |
| Enable product Q&A | Questions and the *Ask a question* form in a product tab and the Bricks Product Q&A element. | Off |
| Add a product tab / Tab title | A WooCommerce tab (*Questions & Answers (n)*, n = published questions). | On / Questions & Answers |
| Questions per page | First page, and each *Show more questions*. | 5 |
| Search box | Searches the product's questions. | On |
| Helpful votes on answers | Same limits as review votes; its own switch. | On |
| Who can ask | *Anyone* (guests give a name and email) or *Signed-in customers only*. | Anyone |
| Who can answer | *Only the store*, *The store and customers who bought the product*, *… any signed-in customer*, *Anyone*. | Only the store |
| Hold new questions for approval | Shown once approved or answered. | On |
| Hold customer answers for approval | Store answers are never held. | On |
| Email the store about new questions / Send them to | With a link to the Questions page; empty address uses the admin email. | On / admin email |
| Email the asker when the question is answered | Once per answer, when it is published. | On |

The CAPTCHA card (Review form tab) says whether the Simple Cloudflare Turnstile plugin is active; the **Bricks Elements** card (Display tab) is information only. The Emails tab also shows how many addresses have unsubscribed. While requests are on and no published hub page is selected, the settings page and the rest of wp-admin (for users with `manage_woocommerce`) show a warning that links to the Review requests tab.

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

### Form extras

- **Required criteria and questions** are only enforced on a form that showed them (the form posts `ffla_criteria_shown` / `ffla_questions_shown`), so a Bricks Review Form that hides the criteria, or a hub form without the product-only questions, still sends. A missing one comes back as "Please rate Fit." or "Please answer: …". An answer that is not one of a question's choices does not count; checkbox answers are saved in the order of the choices (`ffla_review_q_<slug>`, comma separated). Public answers show on the card under the criteria; all of them show in a box on the review's edit screen.
- **Terms checkbox**: required when on; on the product form and the hub (reviews) and on the ask and answer forms (Q&A).
- **CAPTCHA**: with your own keys, the provider's script is loaded with the form and the token is checked server-side once per request (`wp_safe_remote_post()` to the provider's verify URL); an unreachable provider refuses and logs. reCAPTCHA v3 has no widget: the script fetches a token when the form is sent, and a score below the minimum refuses. Signed review links never get a challenge. While *Replace WooCommerce reviews tab* is on, a review posted straight to `wp-comments-post.php` must pass it too (unless the Turnstile plugin protects the comment form itself).
- **New review emails** go out once per review when it is saved (not for spam), to *Send them to* or the admin email.
- **Photos and videos box**: on a review's edit screen (Products → Reviews → Edit), add photos and videos from the Media Library or take them off the review (the files stay in the Media Library); saved with the review's Update button. Only photo and video attachments are kept; needs permission to edit the comment.

### When a review is held or refused

| Rule | Result |
|---|---|
| **Hold all new reviews for moderation** on | Pending |
| A photo or video is attached | Pending, always |
| Forbidden word in the text or the name, *Hold it for moderation* | Pending |
| Forbidden word, *Refuse it and ask the customer to revise* | FFL form: refused with "Your review contains wording we cannot publish…". Other paths (WooCommerce's own form, REST): saved as spam. |

These rules apply to every new product review, including ones from WooCommerce's standard form. Users who can moderate comments are never held or filtered. Store replies are not held.

### Photos and videos

- Up to **Most files per review** files (3 by default), **Largest file** each (5 MB): JPG, PNG, GIF, WebP, MP4 or WebM. The browser warns about extra or oversized files; the server also skips anything over the limits or of another type.
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

- A list first shows its *Max reviews* (product tab: **Reviews per page**, 5 by default). *Show more reviews* loads the next page of the same size under it; the status line says how many are shown (“Showing 10 of 23 reviews”).
- Pinned reviews always come first, labelled *Featured review*, whatever the sort or filter. Use the **Pin review** / **Unpin review** row action under **Products → Reviews** (needs `moderate_comments`). Pinning refreshes the product's comment count, which tells page caches (caching plugins and hosts that purge on comment or post changes) to drop the cached product page.
- Sorts: *Most recent*; *Most helpful* (net score, helpful minus not helpful, newest first on ties); *Highest* and *Lowest rating* (reviews without stars last). Every sort ends on the review ID, so pages never repeat or skip a review.
- Filters: one star level (chips, or click a summary bar; click it again to clear), *With photos*, and search in the review text and reviewer name. They combine. With nothing matching, the list says so and offers *Clear filters*. The chip counts are the scope's star counts.
- The toolbar and *Show more* need JavaScript and are hidden until the script has set them up; without it the first page shows as a plain list. Filtering and paging go to `admin-ajax.php` (action `ffla_load_reviews`), which only ever returns approved top-level reviews of published products, so there is no nonce to expire on cached pages.
- Store-wide and category lists (Bricks **Reviews List** → *Reviews of*) show the product's name and thumbnail on each card and a summary of the whole scope. Their numbers are counted once and cached until a review changes. Reviews of draft or private products never appear. A category includes its subcategories.
- Layouts: *List*; *Grid* (1–6 columns, at most 2 on tablets and 1 on phones); *Slider* (cards per view, 2 on tablets, one and a bit on phones, with arrows). The slider has no toolbar or *Show more* unless the element asks for them (*Show more* never applies to it).
- Only approved direct replies to a review are shown. A reply is badged *Store response* when its author could moderate comments at the time of replying. Only those store replies are emailed to the reviewer.
- Reviewer names: **Display → Reviewer name** decides how the stored name is shown on cards and in the structured data. Store replies keep their author's name. Avatars are initials on a colour picked from the address by default; *WordPress avatar* loads Gravatar pictures.
- Review photos open in a lightbox (a native `<dialog>`): arrow keys and buttons move between a review's photos, Escape or a click outside closes it, focus returns to the photo. Ctrl/Cmd-click still opens the photo in a new tab.

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

- Scheduled when an order reaches the trigger status (**Start when the order is**, Completed by default), if requests are enabled, a published hub page is selected, the email address is valid and not unsubscribed, the order has not been scheduled before, the order was placed no more than the request delay plus 30 days before the email would go out (imports and old orders changed in bulk are skipped), the **Who gets requests** rules and the consent setting allow it, and at least one product in it can still be reviewed: not refunded in full, reviews open, not yet reviewed by the customer (from their account, or a verified review under the billing email), and not left out by the category or free-product rules. Orders that reached the status before the module was switched on are not picked up.
- Why an order got no request is stored on it (*No consent at checkout*, *Guest order*, *Customer role not included*, *Excluded address*, *Customer had a request recently*, *Unsubscribed*, *Nothing to review*, *Cancelled by the store*) and shown in the order box and the log.
- The send time is the trigger time plus the delay (the country's own delay when listed), then moved forward to the **Send at** hour and an allowed **Send on** weekday, in the store's time zone.
- Queued through Action Scheduler (group `ffla-product-reviews`) when available, otherwise WP-Cron.
- **One email per order** (the default): one email listing the products still to review (at most **Most products per email**, most expensive first). **One email per product**: one email per such product, all on the same day. Both link to the hub page.
- Checked again when the email is due: requests still enabled and the hub page still published, the order still at the trigger status (or moved to a store-specific status after it, such as a custom *Delivered*), still within the age limit, the address not unsubscribed or excluded, consent still given and the email not already sent. Products reviewed, refunded in full, closed to reviews or left out by the rules in the meantime are left out; if none remain, nothing is sent.
- An order that is back at a core status from before the trigger (Pending, Processing, On hold) when its email is due is not emailed and is released: when it reaches the trigger again, the request is scheduled again. With Processing as the trigger, Processing itself qualifies. An order that is refunded or cancelled drops its queued emails (reminder included); if it reaches the trigger again later it is scheduled again. An email already sent is never repeated.
- **Second reminder** (off by default): queued when the first email (or the first per-product email) is sent, for **Days after the first email** later, held for the sending hour and days. One email for the whole order, listing what is still not reviewed; nothing when everything is reviewed. Only one per order.
- Sent with `wp_mail()` to the billing email (or the account email, with **Send to the account email**). HTML emails are one 600px table that works in Gmail, Outlook and Apple Mail: logo or store name, heading, the store's text, a row per product with its photo and five star links, a button, and a footer with the store address and the unsubscribe link. A star link opens the hub with that rating already picked on that product's form, scrolled to it. Plain text emails are the heading, a blank line and the text, with an unsubscribe line added at the end when the text has no `{unsubscribe_url}`. From is WooCommerce's sender name and address (**WooCommerce → Settings → Emails**); Reply-To is the module's **Reply-To address**, else WooCommerce's reply-to address when that setting is on, else the sender. **BCC copy to** gets a copy. The approval and reply notices are plain text from the same sender.
- An email only counts as sent when `wp_mail()` succeeds. A failure is logged under **WooCommerce → Status → Logs** (source `ffla-product-reviews`) and tried again an hour later, three attempts in all (the reminder too). Approval and reply notices are logged the same way, and are sent the next time the review or reply is approved.
- Switching the module off removes its queued emails (requests, reminders and requests queued by hand). Orders that were already scheduled are not scheduled again when it is switched back on.
- **Tracking** (on by default): an HTML email carries a 1×1 image whose signed URL records the first open and an open count on the order; opening the hub with the order's valid link records the first click and a count (once per page load). Both are approximate: mail apps that block images miss opens, and company mail scanners that fetch every image and link add them. Reviews sent through the link are always counted on the order (`_ffla_review_reviewed`) and the review remembers its order (`ffla_review_order_id`).

**Requests sent by hand.** **Send review request now** in the order's *Review request* box, or the **Send review request** bulk action on the orders list (queued, out within minutes). One email for every product still to review, sent even while automatic requests are off and whatever the order's status, except refunded, cancelled and failed orders. The customer's own choices still count: unsubscribed, excluded addresses and, with consent on, an unticked box. Any automatic email still queued for the order is dropped and the order is not scheduled again, so the customer does not get the same request twice; with reminders on, the reminder follows. An order note records it. **Cancel the scheduled email** drops a queued request (the order is not scheduled again; Send now still works).

**Copy review link, QR code and WhatsApp.** The box makes a fresh signed link (valid for **Review link valid for** days) for this order: *Copy review link*, a QR code drawn in the browser (no outside service; *Download* as SVG or *Print*), and *WhatsApp*, which opens `wa.me` with the billing phone (the billing country's calling code is added when the number has none) and the **WhatsApp message** filled in, for you to press send. Share these only with that customer.

| Placeholder | Replaced with |
|---|---|
| `{customer_name}` | Billing first name, or "Customer" |
| `{product_name}` | The product name; in per-order mode the comma-separated list |
| `{product_names_list}` | The comma-separated list (per-order) or the product name |
| `{review_url}`, `{review_order_url}` | The signed hub link (both give the same link) |
| `{order_id}`, `{order_number}` | The internal order ID, and the order number customers see |
| `{user_id}` | The customer's user ID (0 for guests) |
| `{store_name}` | The site title |
| `{review_link_days}` | How many days the link works |
| `{unsubscribe_url}` | The unsubscribe link |

The installed text is written for one product ("We would love your feedback on {product_name}"). While it is left untouched (or emptied), each kind of email uses a built-in text: per order or per product, request or reminder, HTML (without the bare link, which the button carries) or plain text. Once edited, the text is used as written for requests and reminders.

### Coupons for reviews

- Decided once per review, when it is published: posted approved, or approved later from **Products → Reviews**. Re-approving never sends a second coupon.
- The review must be tied to an order: the order of the signed email link it was sent through, or, for a signed-in account that bought the product (Verified buyer), that account's newest Completed or Processing order containing the product. In *Only reviews sent through the review request email link* mode only the first counts. Guest reviews typed on a product page have no verified purchase and earn nothing.
- **One coupon per order**: the order records its code (`_ffla_review_coupon`) before the email goes out, so reviewing the order's other products earns nothing more.
- The tier is the highest one whose photo/video minimum the review reaches; tier 1 has none. *A new unique coupon* is made with the prefix and 8 random characters, the tier's discount type and amount (a percentage is capped at 100), the expiry, the email restriction (unless shareable), the usage limits, minimum and maximum spend, individual use, sale items, free shipping and categories from *New coupons*, and a description naming the review and order. *An existing coupon code* sends that code (with its own limits) when it exists; when it does not, nothing is sent, it is logged, and the order can still earn a coupon later.
- With the Smart Coupons module on, its guardrails (firearms left out, minimum prices, maximum discount) apply to these coupons like to any other.
- The email uses the Emails tab's format and design: a box with the code, the amount and expiry, and a *Shop now* button. It has no unsubscribe link: it answers something the customer did. It counts as sent only when `wp_mail()` succeeds; a failure keeps the coupon, is logged and is retried an hour later, three attempts in all.
- The review gets `ffla_incentivized` and shows the disclosure badge (**Mark rewarded reviews**, on by default). The coupon also shows in the order's *Review request* box.

### Product Q&A

- Questions are comments of type `ffla_question` on the product, answers `ffla_answer` comments under them. The review rules never touch them: no rating, Verified buyer flag, review media, coupon or *Hold all new reviews*; they are left out of WooCommerce's own review list (`comments_template_query_args`) and of every FFL review list and count. They show under **Comments** in wp-admin, and WordPress's own comment checks (duplicates, flooding, disallowed keys, Akismet) apply.
- Asking: the product must be published. Guests give a name and email (not shown), pass the honeypot and Turnstile (when the Simple Cloudflare Turnstile plugin is active), and are limited to five questions or answers an hour per connection (`ffla_product_reviews_qna_guest_hourly_limit`). Signed-in users need a valid nonce and post under their account. *Signed-in customers only* refuses guests.
- Held for approval by default (**Hold new questions for approval**); a question with a forbidden word (Reviews tab) is always held. The store is emailed about each new question.
- Answering: *Only the store* by default (anyone who can moderate comments). Store answers are published straight away, labelled *Store answer* with the store name, and listed first; customer answers are held by default and show *Verified buyer* when the account bought the product. Answers are only accepted under published questions. The asker gets an email once per published answer (not for their own).
- Answering a held question from **Product Reviews → Questions** publishes it. The page has *Unanswered*, *Waiting for approval*, *Answered* and *All* views, an answer box under each question, and Approve / Unapprove / Trash for questions and answers (needs `moderate_comments`).
- The list shows published questions, newest first, with their published answers (store answers first, then the most helpful). *Show more questions* and the search use `admin-ajax.php` (action `ffla_load_questions`), which only returns published questions of published products. Votes on answers use the review vote endpoint with its limits; questions take no votes. The *Ask a question* and *Answer this question* forms are folded `<details>` and work without JavaScript.

### Import and export

**Product Reviews → Import / Export** (needs `manage_woocommerce`).

- **Export reviews** downloads every review and reply on products (approved and pending; spam and trash left out), oldest first: `id`, `parent_id` (replies), `product_id`, `product_sku`, `product_name`, `status`, `date_gmt`, `author`, `email`, `user_id`, `rating`, `verified`, `pinned`, `store_reply`, `content`, `criteria` (`quality=5;value=4`), `media` (URLs separated by `|`), `helpful`, `not_helpful`, `order_id`. **Export questions and answers** has `id`, `parent_id`, `type`, `product_id`, `product_sku`, `product_name`, `status`, `date_gmt`, `author`, `email`, `user_id`, `store_answer`, `content`, `helpful`. UTF-8 with a BOM, so Excel opens it correctly. A cell starting with `=`, `+`, `-` or `@` (and not a number) gets a leading `'` so a spreadsheet never runs it as a formula; importing removes it again. The files hold customers' names and emails.
- **Import** takes a CSV (up to 20 MB) whose first row names the columns: `content` and `product_id` or `product_sku` are needed, the export's other columns are understood, and so are the column names of Customer Reviews for WooCommerce exports (`review_content`, `review_score`, `review_title`, `display_name`, `parent`, `date`, `media` separated by commas). Variations are imported on the parent product. `date_gmt` is UTC; `date` is store time. *Status: As in the file* (approved unless `pending`) or *Everything waits for approval*.
- It runs in the background, 100 rows per queue run (Action Scheduler, else WP-Cron); the page shows progress, reloads while it runs, and can cancel it. The file waits in `wp-content/uploads/ffla-review-imports/` (closed to the web on Apache, random name everywhere) and is deleted when the job ends or is cancelled. The page keeps the result and up to 100 notes with line numbers (rows skipped and why, photos that could not be downloaded).
- Rows are saved with `wp_insert_comment()`: no submission rules, no emails, no coupons. Replies and answers go under the review or question named in `parent_id` (an `id` from the same file, or an existing comment on the same product); if it cannot be found the row is skipped. Store reviews (`product_id` -1) are skipped. `verified` 1 sets Verified buyer; `pinned` 1 pins.
- Photos and videos in `media` (at most five per review) are downloaded with `download_url()` (which refuses local and private addresses), checked to be JPG, PNG, GIF, WebP, MP4 or WebM up to 25 MB, cleaned of location data like uploads, and added to the Media Library.
- A row already imported (same product, parent, email, text and date) or one whose `id` is the same review on this site is skipped, so a file, or this store's own export, can be imported again without duplicates.
- When it ends, WooCommerce recounts the rating of every product the file touched.

**From Customer Reviews for WooCommerce.** Its reviews are WooCommerce reviews, so they already show in FFL lists with their stars. **Copy photos and votes** (shown while that plugin's data is in the database) adds its photos and videos (the same attachments, `ivole_review_image2` / `ivole_review_video2`) to each review's FFL photos, and its upvoters and downvoters as *Helpful* / *Not helpful* counts, 200 reviews per queue run. Photos and higher vote counts already in place are kept; running it again changes nothing.

### Consent at checkout

With **Ask for consent** on *Unticked checkbox*, the classic checkout shows the box above *Place order* (saved as `_ffla_review_consent` `yes` / `no`) and the Checkout block gets an order field through WooCommerce's additional checkout fields (WooCommerce 8.9+; stored as `_wc_other/ffla/review-consent`). Only orders where it was ticked get requests, including requests sent by hand. Orders placed before the setting was switched on have no answer and get none.

### The order review hub

- The signed link is valid for **Review link valid for** days (90 by default) from when it was made and is tied to the order and its billing email. Changing the order's billing email invalidates it, and so does refunding, cancelling or failing the order.
- A star clicked in an HTML email arrives as `ffla_rate[<product ID>]=<1–5>`: that rating is picked on that product's form.
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
| Product page, **Reviews** tab | FFL list (first **Reviews per page**, with filters and *Show more*) and form, when *Replace WooCommerce reviews tab* is on. |
| Bricks elements, category **FFL Funnels** | See below. |
| Bricks dynamic tags, group **FFL Funnels - Product Reviews** | `{ffla_review_count}` (WooCommerce's review count), `{ffla_review_average}` (one decimal, empty with no ratings), `{ffla_review_recommend_percent}` (share of 4 and 5 star ratings, empty with no ratings); store-wide `{ffla_store_review_average}`, `{ffla_store_review_count}`, `{ffla_store_review_verified}` (percent of verified buyers); `{ffla_question_count}` (the product's published questions). |
| Product page structured data | The `review` list matches the FFL list (see *Structured data*). |
| Hub page | Forms for every product in the order. |
| **WooCommerce → Orders** → an order | *Review request* box (side): status, Send now, Cancel, Copy review link, QR code, WhatsApp. |
| **WooCommerce → Orders** list | Bulk action *Send review request* (while a hub page is ready). |
| **FFL Funnels → Product Reviews → Review requests** | 30-day totals (sent, opened, review page opened, reviewed, reviews written, scheduled, skipped) and every order with request activity, filterable by status. |
| Checkout | The consent checkbox, when switched on. |
| Product page, **Questions & Answers** tab | Questions, answers, search and the ask form, when Q&A and the tab are on. |
| **FFL Funnels → Product Reviews → Import / Export** | Review and Q&A CSV export, background import with progress, copy of photos and votes from Customer Reviews for WooCommerce. |
| **FFL Funnels → Product Reviews → Questions** | Every question with its answers, an answer box, approve / unapprove / trash. |
| Customer inbox | Review request, approval and reply emails. |

| Bricks element | Shows | Main controls |
|---|---|---|
| Reviews Rating Badge | Average, partial stars, review count in brackets (WooCommerce's numbers, as in the rating summary) | Product ID, Average number (Show / Hide), Review count (Show / Hide), Hide when no reviews, star colours and size, typography |
| Reviews List | Summary, toolbar and review cards | *Which reviews*: Reviews of (This product / All products / Product categories), Categories, Product ID, Minimum rating, Only reviews with photos. Max reviews (1–50, default 5), Order by (Most recent / Most helpful / Highest / Lowest rating), Rating summary. *Layout*: List / Grid / Slider, Columns / cards per view, Product on each card. *Filters and paging*: Star filter, “With photos” filter, Sort menu, Search box, “Show more” button (each Use module setting / Show / Hide). Star and card styling, typography |
| Product Q&A | Questions, answers, search, ask form | Product ID, Title, Questions per page, Search box, “Ask a question” form; question border and background, question and answer typography |
| Store Rating Badge | Store-wide average, stars, review count and share of verified buyers | Style (Card / Compact), Theme (Light / Dark), Label, Review count, Verified buyers share, With no reviews yet (Show 0.0 / Hide), Link; star, background and text colours, average typography |
| Review Form | The FFL form | Product ID, Title (default "Write a review"), Intro text, Extra rating criteria (Show / Hide; all configured criteria), Media upload (Collapsed / Expanded), Login hint (Show / Hide); Style tab: Container, Stars, Fields, Submit button, Notices |
| Order reviews hub | The hub | Intro title (optional), plus Bricks' own ID, classes and styles on the wrapper |

The on/off options above are selects, not checkboxes: Bricks drops an unchecked checkbox, which made default-on checkboxes impossible to switch off. Elements saved with the old checkboxes keep showing everything until you pick *Hide* (or *Expanded*).

Empty *Product ID* means the current product: the global product, the Bricks query loop item or the product page. In the Bricks editor the newest published product is used for the preview.

The CSS and JavaScript load only where review output is printed: each Bricks element loads them when it renders (the badges load only the CSS, so shop and archive loops get styled stars), and so do the list, form and hub themselves. They go in the page head on the hub page, on pages whose content contains `[ffla_order_reviews]`, on product pages while *Replace WooCommerce reviews tab* is on, and in the Bricks editor; anywhere else they are added at the end of the page. A product page without any FFL review output loads neither file. The script is left out when nothing on the page needs it: a badge, or a list with votes, filters, *Show more*, the lightbox and the slider all off or unused.

## Data and uninstall

| Data | Where | On uninstall |
|---|---|---|
| Settings | option `ffla_product_reviews_settings` | Deleted |
| Unsubscribed addresses (hashed) | option `ffla_review_email_optouts` | Kept on purpose, so a reinstall does not email them again |
| Reviews and replies | WordPress comments on products | Kept |
| Questions and answers | WordPress comments on products (types `ffla_question`, `ffla_answer`; meta `ffla_is_store_answer`, `ffla_helpful_yes`, `_ffla_store_notified`, `_ffla_asker_notified`) | Kept |
| Rating, criteria scores, votes, verified flag (the module's and WooCommerce's `verified`), media IDs, notification flags | comment meta | Kept |
| Pinned flag | `comment_karma` = 1 on the comment | Kept |
| Review photos and videos | Media Library | Kept |
| Request tracking (scheduled and when, sent, reminder, sent by hand, skip reason, failed attempts, opens, clicks, reviews through the link) | order meta | Kept |
| Import job, migration progress | options `ffla_review_import_job`, `ffla_review_migrate_cusrev`; files waiting in `uploads/ffla-review-imports/`; imported comments carry `_ffla_import_hash` | Deleted (the comments are kept) |
| Checkout consent | order meta `_ffla_review_consent` (classic) or `_wc_other/ffla/review-consent` (block) | Kept |
| Coupons made for reviews | WooCommerce coupons (meta `_ffla_review_coupon_review`); the review's `ffla_review_coupon_code`, `ffla_incentivized`, `_ffla_review_coupon_checked`, `_ffla_review_coupon_emailed`, `_ffla_review_coupon_attempts`; the order's `_ffla_review_coupon` | Kept |
| Queued request, reminder and hand-sent emails | Action Scheduler / WP-Cron | Removed (also when the module is switched off) |
| Rating summary cache from versions before 1.55.10 (no longer written) | transients `ffla_rev_dist_<product ID>` | Deleted |
| Store-wide and category numbers (star counts, verified share) | transients `ffla_rev_scope_*`, one hour; option `ffla_rev_cache_version`, bumped when a review changes | Deleted |
| Vote limits, guest review limit | transients (`ffla_helpful_<hash>`, `ffla_helpful_daily_<review ID>_<date>`, guest limit per IP) | Expire on their own (12 hours / 1 day / 1 hour) |

Uninstall cleanup runs whenever the settings option exists, even if the module was switched off first. Switching the module off keeps settings and data but removes its queued request emails; they are not sent later.

## Troubleshooting

- **No request emails.** Look at the order's *Review request* box or **Review requests** log first: a skipped order says why (no consent, guest, role, excluded address, recent request, unsubscribed, nothing to review). Then check *Enable review requests* and that a published **Hub page** is selected (wp-admin warns when it is not); that the order reached the trigger status (**Start when the order is**) while the module was on; the **Send at** hour and **Send on** days; that it was placed no more than the delay plus 30 days before the email was due; the billing email; whether the address unsubscribed; whether every product in the order was already reviewed, refunded in full or closed to reviews; the action in **WooCommerce → Status → Scheduled Actions** (group `ffla-product-reviews`); and the `ffla-product-reviews` log under **WooCommerce → Status → Logs**, where failed sends are recorded.
- **The email went out but shows no photos or stars.** The mail app blocks images or shows plain text; the button and link still work. Check **Format** and *Product rows with clickable stars*.
- **Opens or clicks look too high.** Company mail scanners open links and images on delivery. Reviews through the link are the reliable number.
- **The link shows "missing a review key".** The hub page does not contain the shortcode or element.
- **Pretty link gives a 404.** Visit **Settings → Permalinks** once, and make sure the hub page is published.
- **"This review link is invalid or has expired."** The link is older than 90 days, the order's billing email changed, the order was refunded, cancelled or failed, or the link was cut off or altered on the way.
- **Filters or Show more do nothing.** The page must load the module's script; it loads with the list itself. If review markup is printed some other way, see the next item. A page cache that rewrites `admin-ajax.php` POSTs breaks it too.
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
| `ffla_product_reviews_card_details` | `string $html` (''), `WP_Comment $review` | More under the criteria on a card (public answers use it). Return escaped HTML. |
| `ffla_product_reviews_form_check` | `string $error` (''), `int $product_id`, `WC_Order\|null $order` | Refuse an FFL review submission with a message. |
| `ffla_product_reviews_form_fields` (action) | `int $product_id`, `string $context` (`product` / `hub`) | Print extra fields in the review forms. |
| `ffla_product_reviews_card_badges` | `string $html` (''), `WP_Comment $review` | Extra badges printed after *Verified buyer* on a card. Return escaped HTML. |
| `ffla_helpful_daily_cap` | `int` (200) | Votes per review per calendar day. |
| `ffla_product_reviews_guest_hourly_limit` | `int` (5) | New guest reviews per IP address per hour through the FFL form. 0 turns the limit off. |
| `ffla_product_reviews_request_max_order_age_days` | `int` (delay + 30), `WC_Order $order` | Oldest order, in days between order date and send time, that still gets a request email. 0 turns the limit off. |
| `ffla_product_reviews_request_status_allowed` | `bool`, `WC_Order $order` | Whether the order's status allows its request email when it is due. Default: the trigger status, or a status that is not a core pre-fulfilment status reached after it. |
| `ffla_product_reviews_client_ip` | `string $ip` | Visitor address used to throttle votes. |
| `ffla_product_reviews_before_coupon_save` (action) | `WC_Coupon $coupon`, `WP_Comment $review`, `WC_Order $order` | Change a coupon made for a review before it is saved. |
| `ffla_product_reviews_qna_check` | `string $error` (''), `string $action` | Refuse a question or answer with a message (extra checks). |
| `ffla_product_reviews_qna_guest_hourly_limit` | `int` (5) | Guest questions and answers per IP address per hour. 0 turns it off. |
| `ffla_product_reviews_qna_form_fields` (action) | `string $kind` (`question` / `answer`), `int $product_id` | Print extra fields in the Q&A forms. |
| `ffla_product_reviews_whatsapp_phone` | `string $digits`, `WC_Order $order` | The number the WhatsApp button opens (digits with country code). |
| `ffla_product_reviews_cloudflare_ranges` | `string[]` (Cloudflare's published IPv4/IPv6 ranges) | Proxy addresses whose `CF-Connecting-IP` header is trusted. |
| `ffla_review_approved_email_subject`, `ffla_review_approved_email_body` | `string`, `WP_Comment $review` | Approval email. |
| `ffla_review_reply_email_subject`, `ffla_review_reply_email_body` | `string`, `WP_Comment $reply`, `WP_Comment $review` | Reply email. |

**Scheduled actions** (Action Scheduler group `ffla-product-reviews`, or single WP-Cron events): `ffla_send_product_review_request` (`$order_id`, `$product_id`, `$user_id`), `ffla_send_order_review_bundle` (`$order_id`), `ffla_send_order_review_reminder` (`$order_id`) `ffla_send_manual_review_request` (`$order_id`, queued by the bulk action) and `ffla_send_review_coupon_email` (`$review_id`, a coupon email retry), `ffla_review_import_batch` (the next 100 rows of an import) and `ffla_review_migrate_cusrev` (the next 200 reviews of the copy).

**Endpoints**

| Endpoint | Notes |
|---|---|
| `admin-post.php`, action `ffla_submit_product_review` (POST, guests too) | Fields `comment_post_ID`, `rating`, `comment`, `author` and `email` (guests without a signed link), `ffla_review_criteria[<slug>]`, `ffla_review_media[]` (multipart), `ffla_order_review_token`, `redirect_to`, `ffla_hp` (must be empty), `ffla_review_form_nonce` (nonce action `ffla_review_form`; checked for signed-in users without a signed link only). Redirects with `ffla_review_status` (`success`/`error`) and `ffla_review_message`, anchored `#reviews`. |
| `admin-ajax.php`, action `ffla_load_reviews` (POST, guests too, no nonce) | `products[]`, `categories[]`, `rating` (1–5), `minRating`, `withMedia` (`1`), `search` (100 characters at most), `orderBy` (`recent` / `helpful` / `highest` / `lowest`), `page`, `perPage` (≤ 50), `showProduct` (`1`). Returns `html` (the cards), `total`, `count` (shown so far), `hasMore`, `status`. Only approved top-level reviews of published products. |
| `admin-ajax.php`, action `ffla_vote_review_helpful` (POST, guests too) | `nonce` (`ffla_product_reviews_nonce`; checked for signed-in users only), `comment_id`, `vote` (`yes`/`no`). Returns `count`, `countNo`, `throttled`, `message`. A signed-in user's invalid or expired nonce gets HTTP 403 with `code` `invalid_nonce`, a fresh `nonce` and a `message`. |
| `admin-post.php`, action `ffla_review_unsubscribe` (GET `t`), then `ffla_review_unsubscribe_confirm` (POST `t`) | Unsubscribe confirmation and opt-out. Tokens do not expire. |
| `admin-post.php`, action `ffla_review_open` (GET `o`, `s`, guests too) | Open pixel: records the open when the signature matches; always answers with a transparent GIF. |
| `admin-post.php`, actions `ffla_review_send_now` and `ffla_review_cancel` (GET `order_id`, nonce) | Order box; need `manage_woocommerce` or `edit_shop_orders`. |
| `admin-ajax.php`, action `ffla_review_send_test` (POST `to`, `kind`, nonce) and `admin-post.php`, action `ffla_review_email_preview` (GET `kind`, nonce) | Test email and preview; need `manage_woocommerce`. |
| `admin-post.php`, actions `ffla_submit_question` (`product_id`, `question`) and `ffla_submit_answer` (`question_id`, `answer`), POST, guests too | Guests also `author`, `email`; `ffla_hp` must be empty; `ffla_qna_nonce` checked for signed-in users. Redirect with `ffla_qna_status` (`asked`, `answered`, `error`) and `ffla_qna_message`, anchored `#qna`. |
| `admin-ajax.php`, action `ffla_load_questions` (POST, guests too, no nonce) | `product`, `page`, `perPage` (≤ 50), `search`. Returns `html`, `hasMore`, `total`, `status`. |
| `admin-post.php`, actions `ffla_qna_admin_answer` and `ffla_qna_admin_status` | Questions page; need `moderate_comments`. |
| `admin-post.php`, actions `ffla_review_export` (GET `kind`), `ffla_review_import` (POST `file`, `kind`, `mode`), `ffla_review_import_cancel`, `ffla_review_migrate` | Import / Export page; nonce and `manage_woocommerce`. |
| `admin-post.php`, action `ffla_toggle_review_pin` | `comment_id`, `pin` (`1`/`0`), nonce; needs `moderate_comments`. |
| `admin-post.php`, action `product_reviews_save_settings` | Settings form, one tab per post (`tab` field); only that tab's keys are written. Needs `manage_woocommerce`. Fires `ffla_product_reviews_settings_saved` (`$tab`, `$new`, `$old`). |

**Other**

- Shortcode `[ffla_order_reviews]` (no attributes). The token arrives as `?ffla_ro=` or through the rewrite `/<slug>/<token>/` (query var `ffla_order_review_token`).
- Bricks element names: `ffla-reviews-rating-badge`, `ffla-reviews-list`, `ffla-review-form`, `ffla-order-review-hub`.
- Comment meta: `rating`, `ffla_review_quality`, `ffla_review_value`, `ffla_review_criteria_<slug>`, `ffla_review_q_<slug>` (answers), `_ffla_store_review_notified`, `ffla_helpful_yes`, `ffla_helpful_no`, `ffla_verified_purchase`, `ffla_review_media_ids` (array of attachment IDs), `ffla_is_store_reply`, `_ffla_approved_notified`, `_ffla_reply_notified`. WooCommerce's `verified` is overwritten with the same value as `ffla_verified_purchase` for every product comment posted while the module is on.
- Order meta: `_ffla_review_request_scheduled`, `_ffla_review_scheduled_for`, `_ffla_review_bundle_sent`, `_ffla_review_sent_<product ID>`, `_ffla_review_last_sent`, `_ffla_review_reminder_sent`, `_ffla_review_manual_sent`, `_ffla_review_trigger_reached`, `_ffla_review_skipped`, `_ffla_review_bundle_attempts`, `_ffla_review_attempts_<product ID>`, `_ffla_review_reminder_attempts` (failed sends), `_ffla_review_opened`, `_ffla_review_open_count`, `_ffla_review_clicked`, `_ffla_review_click_count`, `_ffla_review_reviewed`, `_ffla_review_first_review`.
- `Product_Reviews_Email::send_now( $order_id )` sends a request by hand and returns `sent` and `message`; `request_status( $order )` the status shown in the box and the log; `first_send_time( $order )` when the first email would go out.
- `Product_Reviews_Core::hub_page_ready()` and `requests_ready()` say whether request emails can run; `reviewable_product_ids( $order )` lists the order's products that can still be reviewed.
- `Product_Reviews_Core::get_rating_distribution( $product_id, $product = null )` returns `counts` (per star), `total` (number of ratings), `average` and `review_count`, read from WooCommerce's stored product aggregates. Pass the `WC_Product` when already loaded.
- `Product_Reviews_Core::is_verified_purchase( $comment_id )`: the module's flag when the review has one, otherwise WooCommerce's `verified`.
- `Product_Reviews_Frontend_Render::list_reviews( $product_id, $settings )` returns the reviews a list with those settings shows, in order; `rendered_reviews( $product_id )` what the lists on the current page showed; `display_name( $review )` the reviewer's name as shown.
- `Product_Reviews_Query::find( $args )` returns `ids` (one page, in display order) and `total` for `products`, `categories`, `rating`, `minRating`, `withMedia`, `search`, `orderBy`, `page`, `perPage`; `distribution( $args )` a scope's star counts, average and review count; `store_stats()` the store-wide numbers with `verified` and `verified_percent`.
- Bricks element names also include `ffla-store-rating-badge` and `ffla-product-qna`.
- `Product_Reviews_Qna::questions( $product_id, $page, $per_page, $search )`, `question_count( $product_id )`, `can_answer( $user_id, $product_id )`.
- `Product_Reviews_Assets::enqueue( $with_script = true )` loads the front-end CSS (and JS).
