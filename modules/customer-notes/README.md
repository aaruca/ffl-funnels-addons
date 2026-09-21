# Customer & Order Management

This extends the existing **Customer Notes** module; its module ID remains `customer-notes`. Existing account / guest-email notes and their storage keys are unchanged. No migration, checkout change, payment action, Tax Reports change or Nexus change is performed.

## Enable only what the store needs

Open **FFL Funnels → Customer & Order Management**. The six collapsible settings sections submit together with **Save settings**. Every switch includes a description. Customer Notes remains enabled by default; the twenty new feature switches start **off**. Enabling a dependent switch alone does not activate its prerequisite.

1. **General:** internal notes follow the customer across orders. They do not become customer messages, invoice text or packing-slip text.
2. **Pickup:** enable Ready for Pickup, optionally the preparation checklist and partial collection. Enter the actual collection location, address, hours and instructions. These texts do not change shipping zones, the FFL selector, prices or tax addresses. There is one configured communication location per store; verify it matches the order's pickup location.
3. **Serial Numbers:** enable serial capture and, separately, manufacturer/model/caliber snapshots. Optional readiness enforcement requires Serial numbers. Invoice and packing-slip output have separate switches; PDF Invoices & Packing Slips for WooCommerce by WP Overnight must be active and its template must call `wpo_wcpdf_after_item_meta`.
4. **Follow-up:** enable private cases and optionally private evidence files.
5. **Notifications:** enable the email master switch first. Automatic ready notices also require Pickup. Pickup reminders additionally require automatic ready notices. Assigned follow-up reminders require Follow-up. Save templates before using Preview / Send test to me. Test messages go only to the current employee's email and are limited to one per minute.
6. **Customer Visibility:** enable Customer progress to display this module's section on the signed-in owner's My Account → View order screen. Then choose serial visibility, tracking summary, authorized document links and Request help. Public updates are written explicitly by staff; they are shown when both Public order updates and Customer progress are enabled. Their optional email delivery also requires the email master switch.

Disabling a feature stops its writes / display / messages, not historical record retention. A previously used Ready for Pickup status remains registered so old orders are readable. Standard order notes and previously issued PDF documents remain in their original systems.

## Staff workflow inside a WooCommerce order

The **Order Management** panel supports the legacy order editor and HPOS.

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

## Verification

Local fixture tests do not use real orders, customer email, payment gateways or a production database:

```sh
php tests/smoke/customer-operations-smoke.php
node tests/smoke/customer-operations-endpoints-smoke.js
# Requires Playwright and Chrome; network is blocked by the test.
node tests/smoke/customer-operations-ui-smoke.js
```

Before enabling in production, verify the full flow on staging with the site's actual WooCommerce/HPOS mode, native FFL plugin, Split Payment version, WP Overnight template, SMTP transport and cron. Check the resulting PDF, owner/non-owner account access, physical collection workflow, private file upload/download, and existing tax/report filters. Tax Reports and Nexus code and filters were deliberately not changed.
