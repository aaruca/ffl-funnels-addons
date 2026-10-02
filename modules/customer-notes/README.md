# Customer & Order Management

This extends the existing **Customer Notes** module; its module ID remains `customer-notes`. Existing account / guest-email notes and their storage keys are unchanged. No migration, checkout change, payment action, Tax Reports change or Nexus change is performed.

## Enable only what the store needs

Open **FFL Funnels → Customer & Order Management**. The section list on the left shows each section's status (On, Off or how many of its tools are on) and opens one section at a time; **Email preview** and **Request form setup** are under Tools. Settings that need another switch are dimmed and say which one. All sections save together from the **Save settings** bar, which shows when there are unsaved changes and returns you to the same section. Every switch includes a description. Customer Notes remains enabled by default; the twenty new feature switches start **off**. Enabling a dependent switch alone does not activate its prerequisite.

1. **General:** internal notes follow the customer across orders. They do not become customer messages, invoice text or packing-slip text.
2. **Pickup:** enable Ready for Pickup, optionally the preparation checklist and partial collection. Enter the actual collection location, address, hours and instructions. These texts do not change shipping zones, the FFL selector, prices or tax addresses. There is one configured communication location per store; verify it matches the order's pickup location.
3. **Serial Numbers:** enable serial capture and, separately, manufacturer/model/caliber snapshots. Optional readiness enforcement requires Serial numbers. Invoice and packing-slip output have separate switches; PDF Invoices & Packing Slips for WooCommerce by WP Overnight must be active and its template must call `wpo_wcpdf_after_item_meta`.
4. **Follow-up:** enable private cases and optionally private evidence files.
5. **Notifications:** the formatted customer email is WooCommerce's **Ready for pickup** email (WooCommerce → Settings → Emails): enabled by default, it is sent when an order is marked Ready for Pickup and can be edited like any WooCommerce email. For this module's own plain-text messages, enable the email master switch first. Automatic ready notices also require Pickup. Pickup reminders additionally require automatic ready notices. Assigned follow-up reminders require Follow-up. Save templates before using Preview / Send test to me. Test messages go only to the current employee's email and are limited to one per minute.
6. **Customer Visibility:** enable Customer progress to display this module's section on the signed-in owner's My Account → View order screen. Then choose serial visibility, tracking summary, authorized document links and Request help. Public updates are written explicitly by staff; they are shown when both Public order updates and Customer progress are enabled. Their optional email delivery also requires the email master switch.

7. **Customer Requests:** customer-facing issue reports and return requests with tracking. Turn on the master switch, review the sub-options (they start on, but do nothing until the master switch is on), then place the form — see [Customer requests](#customer-requests-issues--returns).

Disabling a feature stops its writes / display / messages, not historical record retention. A previously used Ready for Pickup status remains registered so old orders are readable. Standard order notes and previously issued PDF documents remain in their original systems.

## Staff workflow inside a WooCommerce order

The **Order Management** panel supports the legacy order editor and HPOS. It follows the preparation order: serial numbers (and item details), the preparation checklist, **Save Order Management**, then the Pickup section with **Mark Ready for Pickup**. The follow-up case sits below Pickup with its own copy of the same save button.

### Serial numbers and preparation

- Add one serial per line, up to the purchased quantity (maximum 200 serials per item). Letters, punctuation and case are preserved. Case-insensitive duplicates **within the order** are rejected; this is not a cross-order inventory uniqueness registry.
- Readiness requires serials only for unrefunded units. Previously captured serials may remain for historical reference after a refund; refunds never require an invented replacement serial.
- Firearm detection uses the native `product_is_firearm()` function when available, or `_firearm_product=yes` on the product / variation parent. Item detection is frozen after staff save its record, retaining access if the catalog product is later removed.
- Manufacturer, model and caliber initially come from matching product attributes; staff can review and correct them. Saving creates an order-item snapshot rather than editing the catalog product. No inference from product names is performed.
- The checklist is internal preparation guidance, not automatic compliance approval.
- Select **Save Order Management** before any separate action. This saves this panel only, not unrelated billing/order edits elsewhere in WooCommerce. It refreshes the panel without discarding those other edits. Concurrent changes cause a reload warning instead of overwriting another employee's record.
- Invoice and packing-slip switches control newly rendered PDF item rows independently of My Account serial visibility. Existing PDFs are not silently rewritten. Regenerate documents through WP Overnight where appropriate; invoice numbering and document access remain controlled by that plugin.

### Ready for Pickup and collection

- Only existing **Processing** orders with recorded payment, all packages already configured as pickup, and any required serials can enter Ready for Pickup. Missing payment, Authorize.Net authorization without a confirmed capture, mixed shipping/pickup packages and incomplete Split Payment settlement / procurement eligibility are rejected. This panel never captures a payment or overrides the native FFL checkout validation.
- Staff may use the panel action or **Mark Ready for Pickup (validated)** in the order-list bulk menu, up to 100 orders per action. Ineligible orders are skipped with a count; open their panels to see the reason. Core order-editor status changes are also validated before persistence.
- A ready cycle records its time and a snapshot of the customer-facing pickup instructions. Automated notices are queued only for new cycles after enabling the switches; no old-order mail blast occurs.
- **Ready for pickup email.** WooCommerce sends its Ready for pickup email (pickup location, hours and instructions from the ready cycle, then the order details) when an order enters Ready for Pickup, unless it is disabled in WooCommerce → Settings → Emails. Staff can send it again from a ready order: **Order actions** → “Send ready for pickup email to customer” (works even while automatic sending is disabled), or the **Send order email** box added by PDF Invoices & Packing Slips (WP Overnight) → “Ready for pickup” (listed while the email is enabled). WooCommerce's REST order-email API (`woocommerce_rest_order_actions_email_*`) also offers and preselects it for ready orders. Copy `templates/emails/customer-ready-for-pickup.php` (and `plain/`) to `yourtheme/woocommerce/emails/` to customize it. While it is enabled, this module's plain-text ready notice is skipped so the customer never gets both; pickup reminders still come from this module, and **Send / resend ready email** in the Communication log sends the WooCommerce email.
- With partial collection enabled, enter the **cumulative** physically collected quantity on each line. It may increase only on an eligible ready order, never decrease or exceed unrefunded ordered units. Later refunds do not rewrite historical collection quantities.
- After physical collection of all remaining units, use **Confirm all remaining units collected**. This completes the WooCommerce order and records time, employee and item quantities. If another fulfillment rule prevents completion, collection is not marked. Recording all quantities manually does not auto-complete the order; use the confirmation action.
- After a status-changing action, reload the order before further native WooCommerce editing. The panel does not auto-refresh away unrelated unsaved edits.
- This is operational order tracking, not a substitute for legally required transfer records or staff release checks.

### Follow-up cases

Use a case for a lost shipment, shortage, return request, refund follow-up, replacement follow-up or another issue. A case does **not** change the WooCommerce order status, refund money, create a shipment or create a replacement order.

- Status: Open, In progress, Waiting for customer, Waiting for carrier, Resolved. Select Open to reopen.
- Priority: Low, Normal, High, Urgent.
- Assignment: choose an employee permitted to manage this order (up to 100 available staff names in the selector).
- Due date/time uses the store's WordPress timezone. Optional due reminders go to the current assigned employee, never to the buyer. Changing the deadline or assignee makes the old scheduled reminder ineligible.
- Related references are internal text for replacement order / refund numbers. They do not create relationships in external plugins.
- Every edit / added private note is recorded in standard internal WooCommerce order notes with actor information.
- The order list adds a Follow-up column and filters for Unresolved, Assigned to me, Overdue and Resolved, preserving other WooCommerce filters.

### Private evidence

Staff can upload JPEG, PNG and PDF files, no more than 2 MB each and eight per order. Content MIME and extension must agree. Files are stored as non-autoloaded private database records; the order stores their index. They are not Media Library attachments and have no public upload URL. Download requires a current staff session, order editing permission, the attachment feature and an order/file-specific nonce. Downloads use attachment disposition and `nosniff`.

This is access-controlled storage, **not encryption**. Database backups include the files. Disabling the feature retains evidence. Include this data in the store's retention / deletion procedures; no automatic time-based purge is enabled.

### Buyer-visible updates and email

Write a separate **Buyer-visible update**, then explicitly publish it. Check **Also email the billing recipient** only when appropriate. Public messages are not taken from internal notes or cases. Up to 100 updates of 5,000 characters are allowed per order.

The signed-in owner can see the enabled progress section, saved public messages, explicitly enabled serials and existing shipment tracking. Tracking reads `_wc_shipment_tracking_items` used by AST / WooCommerce Shipment Tracking; it does not contact carriers or claim live delivery status. It includes an existing custom tracking link when available. Native tracking-plugin output may also remain elsewhere on the page.

Document links reuse the existing WP Overnight My Account actions and authorization. Enabling this module's switch does not grant access to otherwise private documents, generate an invoice on page load or grant guest access. Guest customers continue using the store's existing email/support channels.

**Request help** is available only to signed-in order owners. A request opens/reopens the private case, records the customer's text internally, and leaves order status unchanged. Limit: one request per order per ten minutes, up to 5,000 characters. It does not automatically issue a refund, resend goods or promise a reply deadline.

Email statuses distinguish `sending`, `accepted`, `failed` and `uncertain`. Acceptance by `wp_mail` is not delivery confirmation; inspect SMTP/mail-provider logs. Each automatic ready/reminder/due event has a deduplication key saved before sending. Failed or uncertain events are not blindly retried. Manual ready-email resend requires explicit confirmation and may duplicate an already-delivered email; review logs first. Maximum 250 logged delivery attempts per order.

Pickup reminders are limited to 1–5 per ready cycle, spaced 1–30 days apart. WP-Cron must run reliably; event timing is not a guaranteed delivery SLA. Every event rechecks switches, order status, cycle, payment eligibility, case resolution and/or assignment as appropriate. No full-order-table polling is used. Job failures are reported in WooCommerce logs under `ffla-order-management`.

## Customer requests (issues & returns)

A customer-facing way to report a problem with an order or request a return, follow its status and get a closing resolution. Separate from the private follow-up case above (which stays staff-only); while requests are on, they replace the simpler **Request help** form in My Account.

### Setup

1. **Customer Requests → Customer requests (issues & returns)** on, then save. Choose issue reports and/or returns, guest access (order number + checkout email), customer uploads and customer emails. Set the issue window (days after the order date, default 90) and return window (days after completion or payment, default 30).
2. Put `[ffla_order_requests]` on a page — the home page works. Options: `type="issue"` or `type="return"` to show one type, `title="Need help with an order?"` for a heading. Page builders: use their Shortcode element. The **Customer requests setup** panel at the bottom of the settings page tells you whether the form was found and can create an **Order Help** page.
3. Set **Requests page** to the page with the form (empty = home page). Links in emails open requests there.
4. Edit the **Return instructions** (sent when you approve a return) and **Firearm return notice**. Variables: `{request_number}`, `{order_number}`, `{customer_name}`, `{store_name}`.
5. Optionally list **Staff notification emails**; otherwise new requests and replies go to the assignee or the site admin email.

### Customer flow

- **Find the order:** order number (a `#` is fine) and the billing email. Failures always say the same thing, so the form cannot be used to discover orders or emails. A request number plus email opens that request directly. Signed-in customers see their recent orders and requests and never need the email.
- **My Account tab:** with **My Account tab** on (default, once requests are on), My Account gets a **Returns & Issues** tab (rename it with **My Account tab name**) right after Orders, at `/my-account/returns-issues/` (filter `ffla_requests_account_endpoint` to change the slug). It lists all of the customer's requests with their status and their recent orders to start a new one; the menu shows a count when a request is waiting for the customer's reply. **Get help** in the Orders list opens the tab with that order already selected. **My Account → View order** also shows the form for that order. Permalinks refresh automatically when the tab is switched on or off.
- **Report a problem** (not received, delay, damaged, missing or wrong item, defective, billing, FFL transfer/pickup, other) or **Request a return** (no longer needed, ordered by mistake, not as described, doesn't fit, defective, damaged, wrong item, other). Returns need items and quantities: each line offers the units not yet refunded and not already in an open return. Firearm lines are flagged and show the firearm notice.
- Optional preferred outcome, a required description and JPEG/PNG/PDF files: no limit per message for anyone, none per request for staff (customers: 200 per request, against abuse). Photos are shrunk in the browser to 2000 px before sending, so 15+ phone photos go in one message; the only cap is the server's own `max_file_uploads` (usually 20 at a time) and `post_max_size`, which the form checks before sending.
- After sending, the customer sees the request with its number, a step tracker and history, gets a confirmation email with a private link, and the address bar holds the same link so the page can be bookmarked.
- The customer can reply with files at any time while open; a reply within 14 days of closing reopens the request. They can cancel a request until the returned item is received.

The step tracker follows the type: issues go **Received → Under review → Resolved**; returns **Received → Under review → Approved → Item received → Resolved**. "Waiting for your reply" and "Waiting on the carrier" show as Under review with that status label. Return steps that were never reached show as skipped when a return is declined or cancelled.

### Staff workflow — WooCommerce → Requests

- **Inbox:** Open, **Needs reply** (the customer wrote last), New, Assigned to me, Overdue, Closed and All, with type and status filters, search (request or order number, customer name or email) and bulk **Assign to me / Unassign / Mark in review**. The menu bubble counts requests that need a reply.
- **Request screen:** summary (customer, order with refunded total, reason, preference, items with firearm flags, source), the full history — customer messages, replies, internal notes, status changes, assignments and every email attempt — and files.
  - **Reply to customer** (optionally emailed; optionally sets *Waiting for customer*) or add an **Internal note**; both accept files. Internal notes and their files are never shown or emailed to the customer.
  - **Approve return** sends the return instructions (and the firearm notice for firearms); **Mark item received** when it arrives. Any status can be set with an optional message; status changes always appear on the customer's page, the email is optional.
  - **Assignment:** assignee (store managers), priority and due date; the assignee is emailed.
  - **Close with resolution** requires an outcome and a note for the customer: refunded, partially refunded, replacement sent, order re-shipped, exchanged, repaired, store credit issued, resolved, request declined or duplicate. **Closing does not refund or ship anything** — do that in the WooCommerce order first. A refund on the order adds a reminder to its open requests.
  - **Customer link:** copy it to share by phone or email, **Email a new link**, or **Disable current link** (both replace the link; old ones stop working).
  - **Delete** permanently (spam or test data only).
- **Orders:** the **Customer requests** box on Edit Order lists the order's requests and opens **New request** — for customers who call or email. Staff requests skip the time windows; the confirmation email is optional. The orders list gets a **Requests** column.

### Return rules and restocking fees

**WooCommerce → Requests → Rules & replies.** A rule targets a product category (including its subcategories) or a product tag and can make products **not returnable** (for example Ammunition, a `used` or `special-order` tag), give them their own **return window** or a **restocking fee**. When several rules match one product, it is not returnable if any rule says so, the shortest window and the highest fee apply; products without a rule use **Return window** and **Default restocking fee** from settings. Each line uses its own window, so a 60-day accessory can still be returned on an order whose firearm is past 30 days.

Customers see non-returnable lines greyed out with the rule's note, and the fee per line. While they fill in the form they get a fee estimate ("Restocking fee: $17.70. Estimated refund: $100.30, shipping not included"). The fee is never charged for damaged, defective, wrong or not-as-described items (filter `ffla_requests_fee_waived_reasons`). The fee percent is saved on each request line at submission. Staff-created requests may include non-returnable items as an exception (flagged on the request).

### Required photos

With **Require a photo** on (default), customers must attach at least one photo when the reason is Arrived damaged, Wrong item received or Defective (filter `ffla_requests_photo_reasons`). Requires customer uploads.

### FFL dealer on firearm returns

With **Ask for the FFL dealer on firearm returns** on (default), a return that includes a firearm needs the dealer who will ship it back: either **the dealer from the order** (read from g-FFL Checkout's `_shipping_fflno` and the shipping address; filter `ffla_requests_order_dealer`) or another dealer (name, 15-character FFL license number, city and state required; address, ZIP, phone and email optional). The license format is checked, not verified with the ATF. Staff see and can edit the dealer on the request, and it is included in staff emails and the CSV export.

### Return shipping

**Approve return** accepts an optional prepaid **return label** (PDF or image) and its tracking number. The customer sees **Your return label** with a download link, and while the return is approved they can enter the carrier (UPS, USPS, FedEx, DHL, other; filter `ffla_requests_carriers`) and tracking number themselves. Staff are emailed when the customer adds it, the inbox marks the request **Shipped back**, and both sides get a carrier tracking link. Staff can add or correct tracking in **Return shipment**.

### Refund from the request

The **Refund** box on an open request issues a normal WooCommerce refund (`wc_create_refund`): quantities prefilled from the request, the restocking fee prefilled from the request lines (kept by refunding the rest of each line's price and tax), an optional extra amount such as shipping, automatic refund through the payment gateway when it supports refunds (or a manual refund record), optional restock, and optionally **close the request** — as Refunded, or Partially refunded when a fee was kept or not every requested unit was refunded — with the refund details in the customer's resolution note and email. Staff must tick **I confirm this refund**; a form token stops a double submit from refunding twice, and the amount can never exceed what is left to refund on the order. The total updates live as staff change quantities or the fee.

### Saved replies

Staff insert saved replies into a reply or a resolution note from a dropdown, then edit before sending. Manage them in **Rules & replies**; placeholders `{first_name}`, `{customer_name}`, `{request_number}`, `{order_number}`, `{store_name}`, `{request_link}`. Five starter replies are included.

### Automation (Request Automation settings, off by default)

- **Remind customers who have not replied:** one email after the request has been *Waiting for customer* for N days (1–30, default 3), quoting the last staff message. Requires customer emails.
- **Close requests with no reply:** after N days of *Waiting for customer* (1–90, default 14) the request closes as **Closed — no reply from customer**; the customer can still reply for 14 days to reopen.
- **Daily staff digest:** from 8:00 store time, one email to the staff notification addresses listing overdue requests and requests waiting for a staff reply; skipped when there is nothing to report.

Runs on an hourly WP-Cron event (`ffla_requests_hourly`) that exists only while one of these is on; each run handles at most 50 requests per job.

### Ratings

With **Rating after closing** on (default), customers rate a closed request 1–5 stars with an optional comment from their request page (not for cancelled or duplicate requests); they can change it later. Ratings of 1–2 email staff. The closing email invites them to rate. Staff see the rating on the request and in the inbox.

### Report

**WooCommerce → Requests → Report** (last 30 / 90 days, 12 months, all time): requests opened (issues vs returns), still open, median time to first staff reply and to close, amount refunded from requests, average rating; top reasons, outcomes, products with the most returned units and issues with their **return rate** (units returned ÷ units sold in the period, from WooCommerce Analytics), and the rating distribution. **Export CSV** downloads the period's requests (spreadsheet formulas in customer text are neutralised).

### Security, storage and privacy

- The shortcode HTML holds no customer data, so full-page caching is safe; everything loads through `admin-ajax.php`.
- Access: a signed order ticket (2 hours) after the number + email check, the request's key from the emailed link (HMAC of a per-request secret with the site's auth salt), or being the signed-in order customer (nonce fetched over AJAX).
- Abuse limits: honeypot and minimum fill time; lookups 10 per 15 minutes per visitor and 15 per hour per order; 6 new requests and 30 attempts per hour per visitor; 30 replies per hour; at most 5 open and 25 total requests per order. Behind a proxy that hides visitor IPs, return the real IP with the `ffla_requests_client_ip` filter.
- Uploads must be real JPEG, PNG or PDF matching the extension. Photos are re-encoded (max 2000 px), which removes location and device data. Files are stored in private database tables (not the Media Library) and served with `nosniff`; PDFs download as attachments.
- Data lives in `wp_ffla_requests`, `wp_ffla_request_events` and `wp_ffla_request_files` (schema version 2 adds return shipping, FFL dealer, refund total, reminder and rating columns; it upgrades automatically). Rules and saved replies are the `ffla_requests_rules` and `ffla_requests_replies` options. **Tools → Export Personal Data** includes requests; **Erase Personal Data** anonymizes closed requests (name, email, customer messages and files) and keeps open ones. Tables are dropped on plugin deletion only if **Delete requests on uninstall** is on.
- Hooks: `ffla_request_created`, `ffla_request_status_changed`, `ffla_request_closed`, `ffla_request_assigned`, `ffla_request_rated`; filters `ffla_requests_carriers`, `ffla_requests_tracking_link`, `ffla_requests_order_dealer`, `ffla_requests_fee_waived_reasons`, `ffla_requests_photo_reasons`, filters `ffla_requests_reasons`, `ffla_requests_resolutions`, `ffla_requests_preferences`, `ffla_requests_find_order` (custom order numbers; `_order_number` meta is supported) and `ffla_requests_client_ip`.
- MCP (WordPress 6.9+ with the MCP Adapter): `ffla-requests/list`, `ffla-requests/get` and `ffla-requests/add-note` for staff with `manage_woocommerce`. Replies, status changes and closing stay in the admin screen.

## Verification

Local fixture tests do not use real orders, customer email, payment gateways or a production database:

```sh
php tests/smoke/customer-operations-smoke.php
node tests/smoke/customer-operations-endpoints-smoke.js
# Requires Playwright and Chrome; network is blocked by the test.
node tests/smoke/customer-operations-ui-smoke.js
```

Before enabling in production, verify the full flow on staging with the site's actual WooCommerce/HPOS mode, native FFL plugin, Split Payment version, WP Overnight template, SMTP transport and cron. Check the resulting PDF, owner/non-owner account access, physical collection workflow, private file upload/download, and existing tax/report filters. Tax Reports and Nexus code and filters were deliberately not changed.
