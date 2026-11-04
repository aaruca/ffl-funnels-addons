# FFL Funnels Addons

**Custom addons and integrations for FFL Funnels WooCommerce stores.**

![Version](https://img.shields.io/badge/version-1.57.0-brightgreen.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.2+-blue.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0+-violet.svg)
![PHP](https://img.shields.io/badge/PHP-7.4+-green.svg)

FFL Funnels Addons is a modular WooCommerce plugin for FFL Funnels stores. Switch on only the modules a store needs in **FFL Funnels → Dashboard**. Each module has its own guide with setup steps, settings, limits and troubleshooting.

## Module documentation

| Module | What it is for | Guide |
| --- | --- | --- |
| WooBooster | Product recommendations, bundles and cart coupon rules, with an optional AI assistant. | [modules/woobooster/README.md](modules/woobooster/README.md) |
| Wishlist | Heart-button wishlist for guests and customers, with a counter and a wishlist page. | [modules/wishlist/README.md](modules/wishlist/README.md) |
| FFL Checkout | Mapbox address suggestions, the g-FFL Checkout dealer finder and a vendor selector for classic checkout. | [modules/ffl-checkout/README.md](modules/ffl-checkout/README.md) |
| Pickup & Shipping | Pickup or shipping choice at classic checkout, with optional FFL pickup rules. | [modules/pickup-shipping/README.md](modules/pickup-shipping/README.md) |
| Woo Sheets Sync | Two-way inventory sync between WooCommerce and Google Sheets. | [modules/woo-sheets-sync/README.md](modules/woo-sheets-sync/README.md) |
| Sales Tax Resolver | US sales tax by customer address in the cart, at checkout and on order recalculation. | [modules/tax-rates/README.md](modules/tax-rates/README.md) |
| Sales Tax Reports | Sales tax filing reports, reconciliation, a nexus monitor and a monthly email to your accountant. | [modules/tax-reports/README.md](modules/tax-reports/README.md) |
| Product Reviews | Review form and list with photos and votes, plus post-purchase review request emails. | [modules/product-reviews/README.md](modules/product-reviews/README.md) |
| Loadout | Tiered accessory packages on product pages. | [modules/loadout/README.md](modules/loadout/README.md) |
| Media Cleaner | Finds unused, broken, orphan and duplicate media and moves it to a restorable trash. | [modules/media-cleaner/README.md](modules/media-cleaner/README.md) |
| Customer & Order Management | Customer notes, pickup operations, serial numbers, follow-up cases and customer issue and return requests. | [modules/customer-notes/README.md](modules/customer-notes/README.md) |
| MonsterInsights Compatibility | Fills in a missing GA4 `view_item` on product pages and corrects product names and cart values in MonsterInsights eCommerce events. | [modules/ga4-bridge/README.md](modules/ga4-bridge/README.md) |
| Google Merchant Policy | Decides which products Google for WooCommerce may send to Merchant Center. | [modules/google-merchant-policy/README.md](modules/google-merchant-policy/README.md) |
| White Label | Branded wp-admin, client restrictions and a client dashboard. | [modules/white-label/README.md](modules/white-label/README.md) |
| Order Badges | Colour-coded product-tag badges on WooCommerce orders. | [modules/order-badges/README.md](modules/order-badges/README.md) |
| Smart Coupons | Coupon guardrails, conditions, extra discount types, store credit and bulk codes. | [modules/smart-coupons/README.md](modules/smart-coupons/README.md) |

## Features

Short summaries of each module. The guide linked at the end of each one is the reference for setup, settings and limits.

### WooBooster

Product recommendations, product bundles and cart coupon rules.

*   **Rules:** conditions on the viewed product (category, tag, attribute, specific products or the entire store) decide when a rule applies, and actions decide what to show. Rules are managed under **FFL Funnels → WB Rules** and are checked by priority number, lowest first (the oldest rule on a tie); the first rule whose conditions match is used.
*   **Smart Recommendations:** Bought Together (ranked by how much more often items sell together than chance), Trending (an order's weight halves every 14 days), Recently Viewed and Similar Products (brand, key attributes such as caliber, category, price). Use them as rule actions or on their own in a Bricks loop. Bought Together and Trending are built from order history; **WB Settings** has the switches, **Rebuild Now** and Index Diagnostics. Results are cached (1 hour; 6 hours for Similar Products).
*   **Where they show:** instead of WooCommerce's related products on classic product templates; in the Bricks query loops **WooBooster Recommendations**, **WooBooster Smart Recommendations** and **WooBooster Bundles**; and anywhere else with the `[woobooster]` shortcode.
*   **Bundles and coupon rules:** "frequently bought together" bundles (a discount or a fixed total, added to the cart as one line) are shown by a Bricks element, and Bricks is the only way to show them on the storefront. Apply Coupon rules apply an existing WooCommerce coupon while the cart matches and remove it when it no longer does.
*   **AI assistant and MCP:** **Generate with AI** drafts rules and bundles from plain language using your real catalog. It uses OpenAI by default (DeepSeek and NVIDIA NIM are also available, with a model override), can use Tavily for web search, and saves everything it creates as inactive. Keys can be set in `wp-config.php` (`FFLA_WOOBOOSTER_AI_KEY`, `FFLA_WOOBOOSTER_TAVILY_KEY`). On WordPress 6.9+, the `woobooster/*` abilities let MCP clients such as Claude, ChatGPT and Cursor search the catalog and manage rules through the MCP Adapter plugin or a plugin that includes it, such as Novamira. They require `manage_woocommerce` and expose only WooBooster operations.
*   **Analytics and testing:** **WB Analytics** attributes revenue, items, orders and add-to-carts to the rule that recommended them (Smart loops appear as one **Smart (all)** row), and **WB Diagnostics** shows which rule matches a product.

Guide: [modules/woobooster/README.md](modules/woobooster/README.md)

### Wishlist

A lightweight wishlist for shoppers, guests included.

*   **Lists:** heart buttons add or remove a product without a page reload. Signed-in customers keep one list on their account; guests keep it in a browser cookie for 30 days, and it merges into their account when they sign in.
*   **Where it shows:** a header counter and a wishlist page (`[alg_wishlist_page]`, or a Bricks query loop of the **Wishlist** query type). Colours, a custom icon and custom CSS are set in **FFL Funnels → Wishlist**.
*   **Bricks and shortcodes:** **Wishlist Button** and **Wishlist Counter** elements, plus `[alg_wishlist_button]` and `[alg_wishlist_count]`.
*   **SnapFind (Typesense):** when the SnapFind plugin is active, heart buttons appear on search results. An optional ranking boost for wishlisted products is off by default and is enabled in the Wishlist settings. A `wishlist_count` field can be added to the search index; the setup is on the **Documentation** card of the Wishlist settings page.
*   **Caching:** on a cached page the browser corrects the hearts and counter for the current visitor, and the wishlist page tells caches not to store it. Server caches that ignore `DONOTCACHEPAGE` and no-cache headers must exclude the wishlist page by hand.

Guide: [modules/wishlist/README.md](modules/wishlist/README.md)

### FFL Checkout

Checkout helpers for stores that run the g-FFL Checkout plugin, aimed at the classic checkout and custom Bricks checkout templates.

*   **Address autocomplete:** Mapbox suggestions (up to six US addresses) appear under the existing billing and shipping street fields. Picking one fills street, city, state, ZIP and country and recalculates the checkout. It is off until **Enable Address Autocomplete** is on.
*   **Mapbox token:** enter your own public token, or leave it blank to borrow one through g-FFL Checkout (cached on the server for 50 minutes). The settings page shows which source is active.
*   **Dealer finder:** the `[ffl_dealer_finder]` shortcode and the **FFL Dealer Finder** Bricks element place g-FFL Checkout's own dealer widget in checkout templates where its normal checkout hook does not run. Dealer search, live dealer data, messages and colours come from g-FFL Checkout; the finder has no settings of its own. It is shown only when g-FFL Checkout says the cart needs an FFL, and only one widget fits on a page.
*   **Vendor selector:** `[ffl_vendor_selector]` lists the vendors that can supply each eligible cart item, with stock and price. The choice changes that item's price, SKU and shipping class and is saved on the order line. It needs the g-FFL Cockpit API key and products with `automated_listing` and a `pa_upc` attribute, and it is off until **Enable Vendor Selector** is on.
*   **Classic checkout only:** there is no Checkout block integration.

Guide: [modules/ffl-checkout/README.md](modules/ffl-checkout/README.md)

### Pickup & Shipping

Lets customers choose pickup or shipping at checkout. It is opt-in and configured per site.

*   **Setup:** choose pickup, shipping or both, the default selection, and which existing WooCommerce shipping method instances count as pickup and as shipping. Empty or incomplete settings leave checkout unchanged. The Appearance & Text settings control colours, radii, gap and copy.
*   **Classic checkout selector:** accessible delivery cards, placed before the billing fields automatically or with `[ffla_delivery_choice]` inside a custom classic checkout form. Prices, tax and carrier requests stay with WooCommerce and your existing providers, and the module never makes a method free. Store pickup orders get the store as their ship-to address instead of a copy of billing (FFL dealer orders keep g-FFL Checkout's dealer address). Checkout Blocks and the Store API are not modified; an admin notice says so.
*   **Optional FFL rules (needs g-FFL Checkout):** the module reads g-FFL Checkout's native Local Pickup FFL. The configured local FFL permits only the selected pickup methods; any other FFL permits shipping only. Mixed FFL and customer packages must be separated by the fulfillment provider, otherwise checkout is blocked with an explanation.
*   **Validation and order data:** the final choice is validated on the server, and a delivery snapshot is saved in the order's shipping-line metadata. Since 1.47.7, a plugin such as Split Payment (FPPC 1.2.3) can keep an authorized, already offered zero-cost internal plan rate through the `ffla_pickup_shipping_keep_internal_rate` filter. Missing FFL choices, disallowed pickup or shipping modes and non-zero rates stay blocked.
*   **Before enabling:** disable the old Camarillo pickup/shipping snippet (the module pauses itself with an admin warning when it finds it) and keep any separate tax code. Verify on staging first.

Guide: [modules/pickup-shipping/README.md](modules/pickup-shipping/README.md)

### Woo Sheets Sync

Two-way sync between WooCommerce products and a Google Sheet, for stores that manage inventory in a spreadsheet.

*   **What syncs:** SKU, regular price, sale price, stock quantity, stock status and manage stock, one row per simple product or variation. Edit them in the sheet; WooCommerce writes its current values back, including stock sold through orders (real-time stock push, on by default). A daily sync runs at a time you choose, and **Sync Now** runs one on demand.
*   **Connecting:** a Google service account is recommended. Paste its JSON key on the **WSS Settings** page (or set it in `wp-config.php`) and share the sheet with the service account email as an Editor. The OAuth **Connect with Google** flow is offered only when `WSS_PROXY_SECRET` is defined.
*   **Tabs:** one spreadsheet with several tab groups; each tab gets products picked one by one, by category, by tag or all at once. A product can sit in several tabs: stock changes are tracked per tab, and when tabs disagree on a price the later tab wins. Removing a tab group on the WSS Dashboard asks before also deleting that tab from the Google Sheet, and never deletes a tab another group still uses.
*   **Conflicts:** SKU, prices and manage stock follow the sheet. Stock quantity follows whichever side changed, and WooCommerce wins if both did.
*   **Creating products:** a new row creates a simple product, or a variation under an existing variable product. A product created this way joins that tab's group.
*   **REST API:** internal `wss/v1` endpoints create or update products, variations and attribute terms (see [REST API](#rest-api)).

Guide: [modules/woo-sheets-sync/README.md](modules/woo-sheets-sync/README.md)

### Sales Tax Resolver

Looks up the US sales tax rate for the customer's address and applies it in the cart, at checkout and when WooCommerce recalculates an order. It works with the classic checkout and Checkout Blocks.

*   **One Sales Tax line:** state, county, city and district rates are added together into one **Sales Tax** line, and the breakdown is saved on the order. When the module cannot produce a rate, WooCommerce's own tax table is used, which means no tax if that table is empty. The module is not tax or legal advice and does not check nexus or registrations.
*   **Rate sources:** the Google Sheet ZIP dataset is the default. It needs no account and is imported monthly into local tables; lookups match ZIP, then city, then a statewide rate. With your own USGeocoder key, the default **Automatic** routing uses live address-level lookups (billed per uncached lookup) and falls back to the sheet if USGeocoder fails or finds no rate. The **Tax rate source** setting can force either source.
*   **Scope and exemptions:** limit the resolver to the states you use (other states use WooCommerce's tax table, and, while **Limit resolver to selected states** is on, unchecking a state deletes its imported sheet data). Whole-order exemptions for selected customers or roles, category and tag exemption rules, and tax holidays with date windows are available. Local pickup is taxed at the store address.
*   **Renewals:** since 1.47.8, renewals, admin **Recalculate** and Split Payment (FPPC) plan orders are taxed from the order itself and reuse the quote saved at checkout for the same address, so every payment of a plan uses the deposit's rate. Existing orders are not recalculated.
*   **Admin tools:** Quote Lookup, Coverage Matrix, Datasets, Audit Log and Settings under **FFL Funnels → Sales Tax Resolver**, plus a REST API (see [REST API](#rest-api)). WooCommerce taxes must be enabled.

Guide: [modules/tax-rates/README.md](modules/tax-rates/README.md)

### Sales Tax Reports

Sales tax filing reports built from the tax values stored on WooCommerce orders and refunds, for store managers and their accountants. It is a separate module and works without the Sales Tax Resolver; when the resolver is on, its saved quotes make the reports more precise.

*   **Reports:** **WooCommerce → Sales Tax Reports** has Overview, States, Jurisdictions, Orders, Reconciliation, Nexus Monitor, Delivery & History and Tools tabs. It gives one row per state and per local filing jurisdiction and currency: gross sales, taxable sales (taxed shipping included), tax collected and refunded, tax calculated from the stored rate, and over/under collection. Georgia rows use the official filing codes (`000` for the statewide row).
*   **Downloads:** a ZIP package with CSV files, an XLSX workbook, a PDF and HTML summary, a README and a manifest with checksums. An *Advanced audit package* adds order-level files. The optional order audit includes customer names and shipping addresses, so leave it off unless the recipient needs it.
*   **Checks:** an *Items to review* list, a reconciliation with WooCommerce's own tax totals and an advisory economic-nexus monitor. The nexus thresholds are unverified sample data. The module does not file returns, does not know where you are registered and is not tax advice; resolve every row marked *Needs review*.
*   **Delivery and combining:** a monthly email of the previous month's package to the recipients you set, and a tool that combines jurisdiction summaries from several stores and maps them into a state or accountant CSV template.
*   **Split Payment (FPPC):** linked installment receipts are grouped under their original sale, which counts once with its original product quantities. Each payment and refund keeps its own date and amount. Since 1.47.9, FPPC's "Final shipping" fee counts as shipping, and so does the initial fee when the order's plan includes shipping in it. See [Split Payment: tax reports and nexus](docs/split-payment-tax-reports.md).

Guide: [modules/tax-reports/README.md](modules/tax-reports/README.md)

### Product Reviews

A richer review form and list for WooCommerce products, plus review request emails after an order is completed.

*   **Form and list:** an overall star rating, extra criteria (*Quality* and *Value for money* by default, up to six of your own), review text, and up to three photos or short videos. Reviews show a *Verified buyer* label, *Helpful* votes, store replies and pinned reviews. Reviews with a photo or video are always held for moderation.
*   **Rules:** the form follows WooCommerce's review settings: reviews must be enabled for the store and the product, and *Reviews can only be left by "verified owners"* is enforced (a signed request-email link counts as proof of purchase). Photo and video uploads can be switched off.
*   **Where to show them:** in the WooCommerce product **Reviews** tab (turn on **Replace WooCommerce reviews tab with FFL form**), with the Bricks elements **Reviews Rating Badge**, **Reviews List**, **Review Form** and **Order reviews hub** (category **FFL Funnels**), or on the order review hub page (`[ffla_order_reviews]`).
*   **Review requests:** an email a set number of days after an order is marked Completed, either per product or one per order, with a signed link to a hub page where the customer reviews every product in the order without logging in. Choose that **Hub page** first: every request email links to it.
*   **Moderation:** hold every new review, hold or refuse reviews that contain forbidden words, pin reviews with the **Pin review** row action and see review media and helpful votes in their own columns under **Products → Reviews**, and send reviewers "approved" and "reply" emails.
*   **Spam protection:** a security token, a honeypot field, and Cloudflare Turnstile through the [Simple Cloudflare Turnstile](https://wordpress.org/plugins/simple-cloudflare-turnstile/) plugin when it is active. Signed order-review links skip the challenge.

Guide: [modules/product-reviews/README.md](modules/product-reviews/README.md)

### Loadout

Tiered "loadout" offers on product pages: a main product plus tiers of recommended accessories, each tier with its own discounts, perks and optional bonus product.

*   **Admin:** manage loadouts, tiers, tier items and cross-sell tiles under **FFL Funnels → Loadout**. A product can link to a global loadout or have its own tiers in the **Loadout** tab of its product data.
*   **Storefront:** rendered by four Bricks elements (the `[loadout]` shortcode works without Bricks); there is no tab on the storefront product page. Shoppers add items one at a time or a whole tier as one cart line.
*   **Pricing:** loadout discounts are taken off the regular price but never charge more than the current price, so a deeper sale is kept. Prices, tiers and bonus products are checked on the server against the saved loadout; the free bonus product is one free unit while the threshold is met. Place a test order before launch.
*   **Bricks dynamic tag:** `{ffla_product_has_loadout}` for conditional rendering.
*   **Data:** four custom tables. Uninstalling **keeps** that data unless **Delete loadout data on uninstall** (FFL Funnels → Loadout → Data) is on.

Guide: [modules/loadout/README.md](modules/loadout/README.md)

### Media Cleaner

Finds media the site no longer needs and moves it to a trash you can restore from. It is for administrators (`manage_options`).

*   **Scans:** unused attachments, attachments whose file is missing, orphan files in the uploads folder, and byte-identical duplicates. Scans run in small batches from **FFL Funnels → Media Cleaner** (keep the tab open until it finishes) or with WP-CLI.
*   **What it reads:** Bricks (page content, headers, footers, templates, global settings and theme styles), WooCommerce, ACF, Elementor, Beaver Builder, Oxygen, WS Form, the common WordPress surface, and this plugin's own modules (review photos and videos, Loadout hero, brand-logo and cross-sell images, WooBooster bundle images), plus WooCommerce downloadable files, ACF fields on posts, terms, users and options (including fields defined in PHP or local JSON) and user meta. Media used only in theme files or other plugins' tables can still be listed; review before trashing. Duplicates are only listed when nothing uses them, and the uploads-folder scan only looks at WordPress's own media folders.
*   **Trash:** removals move files to a trash folder in uploads (with an unguessable name on new sites) and hide the attachment, with restore and an optional auto-empty after 7, 30 or 90 days. **Skip the trash** deletes immediately. New scans keep the Trash and Ignored lists.
*   **WP-CLI:** `wp ffla-media scan [--force]`, `status`, `list`, `trash --all` and `empty-trash --yes`.
*   **Data:** two custom tables, dropped on uninstall after everything in the trash has been put back into the Media Library.
*   Back up the database and uploads before deleting media in bulk.

Guide: [modules/media-cleaner/README.md](modules/media-cleaner/README.md)

### Customer & Order Management

Extends the existing Customer Notes module (original notes contributed by @adeelwebify). The module ID and existing notes are unchanged, and the twenty new feature switches start off. Settings are under **FFL Funnels → Customer & Order Management**.

*   **Notes:** an internal "Customer Note" for each customer on the Edit Order screen and the WordPress user profile (guest notes follow the billing email). It needs `manage_woocommerce`, and customers never see these notes.
*   **Pickup operations:** a validated Ready for Pickup status, an optional preparation checklist, partial collection and staff-confirmed collection. It does not capture payments or bypass the native FFL checkout.
*   **Serial numbers:** per-firearm serials on order items, optional manufacturer, model and caliber snapshots, and separate invoice and packing-slip switches for PDF Invoices & Packing Slips for WooCommerce (WP Overnight).
*   **Follow-up cases:** private cases with status, priority, assignee, deadline and optional private evidence files, plus order-list filters. A case does not change payment or fulfillment status.
*   **Customer requests (issues and returns):** customers report a problem or request a return with the `[ffla_order_requests]` shortcode or a **Returns & Issues** tab in My Account. Staff work them in **WooCommerce → Requests**, with return rules and restocking fees, a refund box that issues a WooCommerce refund after explicit confirmation, saved replies, automation, ratings and a report. Closing a request does not refund or ship anything.
*   **Customer visibility:** optional owner-only My Account progress, explicitly published updates, help requests, existing tracking and document links, the WooCommerce **Ready for pickup** email and bounded reminders. Internal notes stay private.

Read the guide before enabling features, and validate PDF templates, SMTP, cron and fulfillment integrations on staging.

Guide: [modules/customer-notes/README.md](modules/customer-notes/README.md)

### MonsterInsights Compatibility

Optional GA4 fixes for stores that track with MonsterInsights and its eCommerce Addon on custom Bricks product templates. It has no settings page.

*   **One analytics owner:** MonsterInsights loads the Google tag, applies its excluded roles, and sends `add_to_cart`, `remove_from_cart`, `begin_checkout`, purchases and refunds itself, from the server. The module never loads a Google tag and never sends a cart or order event.
*   **Product views:** it fills in a missing `view_item` on single product pages, where a custom Bricks template skips the hook MonsterInsights uses. It first checks the page's `dataLayer`, so a `view_item` MonsterInsights already sent is left untouched.
*   **Cleaner MonsterInsights data:** it corrects MonsterInsights' server-side events before they are sent: product names without HTML entities (`H&R`, not `H&amp;R`), `add_to_cart` / `remove_from_cart` values with cents, and a value and currency on `begin_checkout`. Purchase totals are not changed.
*   **Opt-in:** switch it on for MonsterInsights stores with custom Bricks product templates. Stores still on Google Analytics for WooCommerce keep the earlier compatibility path.

Guide: [modules/ga4-bridge/README.md](modules/ga4-bridge/README.md)

### Google Merchant Policy

Decides which WooCommerce products Google for WooCommerce may send to Google Merchant Center, for stores whose feed must not carry firearms, ammunition and other restricted items.

*   **Decisions:** each product category gets a rule (Allow, Block, Pending or Inherit parent), and each product can be set to Follow policy rules, Always include or Always exclude. Products flagged `_firearm_product` or `_ammunition_product` are always Blocked, even with Always include. A keyword safety scan (on by default) also blocks products whose name, description or category names match restricted-content words; it is an aid, not a compliance check.
*   **Modes:** **Audit only** (the default) records a decision for every product and changes nothing in Google. **Enforce** sets Google for WooCommerce's Channel visibility to match each decision (Allowed products sync, Blocked and Pending products do not) and asks Google for WooCommerce to upload or remove products through its own jobs. Product saves, category changes, per-product decisions and the catalog scan all go through this two-way sync, and the module's **Google Merchant Policy** box replaces Google for WooCommerce's Channel visibility controls while Enforce is on.
*   **Existing exclusions:** in Enforce, a product already hidden in Google for WooCommerce is kept out as Always exclude until someone chooses Follow policy rules for it.
*   **Catalog scan:** a resumable background scan re-evaluates every published product and variation in batches, with pause and resume. It starts when you click **Save policies & start catalog scan** and when a category's rule or parent changes. A product whose Google data blocks its removal is listed and skipped instead of stopping the scan. In Enforce, a notice asks you to run it once when no scan has run yet or the last one predates 1.48.0, so two-way sync covers the whole catalog.
*   **Start in Audit only:** top-level categories start as Pending and Pending products are removed like Blocked ones, so switching to Enforce before setting Allow on your categories takes the whole catalog out of Google.
*   **Limits:** a completed scan is a local result, not confirmed removal or Google approval. Check Google for WooCommerce's scheduled jobs and Merchant Center. Other feed sources are not controlled, and the module does not request an account review.

Guide: [modules/google-merchant-policy/README.md](modules/google-merchant-policy/README.md)

### White Label

Brands wp-admin for client stores and limits what client logins can reach. Clients usually log in as Administrators, so the module tells staff and clients apart by email address, not by role.

*   **Light and dark colours:** 32 colour fields and a dashboard corner radius, each with a Light and a Dark value. Each user switches modes with a sun/moon button in the admin bar, and new users start in dark mode. Nothing changes until a colour or the radius is saved.
*   **Agency branding:** while the module is on, everyone sees the FFL Funnels logo and wordmark at the top of the admin sidebar and a text-only footer credit (filter `ffla_wl_admin_footer_text`), and the WordPress logo menu is removed from the admin bar. There is no login-screen styling.
*   **Client restrictions:** reorder the top-level menu, add dividers, hide and block menu items by URL (hiding a content list such as Pages also blocks adding and editing it, and every FFL Funnels page is always blocked), and remove admin-bar items for clients. Staff are exempt by email pattern (`*@example.com`) or the `FFLA_WL_SUPERUSERS` constant and keep the native menu. Restrictions do nothing until at least one exempt pattern or constant entry exists.
*   **Client dashboard:** an optional replacement for `/wp-admin/` with quick-link cards, 30-day WooCommerce sales figures and **MonsterInsights** and **SnapFind** analytics tabs. It shows only to users who can edit theme options (Administrators and Shop Managers); other roles keep the standard dashboard.
*   **MonsterInsights reports:** read through MonsterInsights' existing Google connection without adding tags. Pro supports 7, 30 and 90-day ranges ending yesterday and Lite only 30. The eCommerce figures need MonsterInsights 11.2 or later, Pro, an eligible license and the eCommerce Addon.
*   **Product editor tools** (Products tab, for everyone who can edit products): search on the category, brand and tag boxes of the Edit Product screen and in Quick Edit / Bulk Edit, a folding category tree, ticked brands kept in place, optional automatic ticking of parent categories, and a tag filter plus searchable filters on the Products list.
*   **Portable settings:** export the configuration as JSON, or import a file to replace the Styles, Menu, Dashboard, Restrictions and Products settings.

Guide: [modules/white-label/README.md](modules/white-label/README.md)

### Order Badges

Colour-coded product-tag badges on WooCommerce orders, so staff see at a glance what an order contains.

*   **Where:** a **Product Type** column on the Orders list (HPOS and legacy), and on the Edit Order screen for the whole order and for each product line. Badges are admin-only.
*   **Badge tags:** choose any product tags to show as badges, each with its own colour. Variations use the parent product's tags.
*   **In-store tags:** a product that carries none of the in-store tags you choose gets an **Online Only** badge (colour configurable). Leave the list empty to turn Online Only off. In-store tags show a badge only if they are also badge tags.
*   **Live:** badges follow each product's current tags, so retagging a product re-labels its past orders. Lines whose product was deleted show no badge.

Guide: [modules/order-badges/README.md](modules/order-badges/README.md)

### Smart Coupons

Coupon rules built for FFL stores, on the regular WooCommerce coupon screens, the classic checkout and the Cart / Checkout blocks. Off until switched on.

*   **Guardrails:** with the module on, firearms (and any categories or tags you add) are left out of every coupon by default unless the coupon or its category allows them. No coupon takes a product below its **Minimum price (MAP)**, and a coupon can have a maximum discount.
*   **Which products and when:** require all of several categories (for example Rifles and Used Guns), match or exclude product tags, and set a start date, first-order only, roles, uses per person (by email, phone and address), minimum quantity, pickup or shipping, states and payment method.
*   **Discount types and stacking:** spend tiers, buy X get Y and a free gift, with per-coupon stacking rules and an optional best-discount-wins setting.
*   **Coupon categories:** colour badges, a list filter, bulk assignment and per-category rules (one per order, no combining, a cap, firearms allowed, roles, default expiry and a monthly budget).
*   **Store credit, codes and links:** running-balance store credit (also issued when closing a customer request), up to 500 single-use bulk codes with CSV, `?coupon=CODE` links, guessing protection, and a coupon report by coupon and category.

Guide: [modules/smart-coupons/README.md](modules/smart-coupons/README.md)

## Installation

1.  Download the versioned `ffl-funnels-addons-vX.Y.Z.zip` file from the [Releases](https://github.com/aaruca/ffl-funnels-addons/releases) page.
2.  Go to **WordPress Admin > Plugins > Add New**.
3.  Click **Upload Plugin** and select the zip file.
4.  Activate the plugin.
5.  Go to **FFL Funnels** in the admin menu to configure modules.

## Auto-Updates

The plugin supports automatic updates via GitHub Releases. When a new version is published, WordPress will detect it and offer the update in the Plugins page.

For private repositories, add this to `wp-config.php`:
```php
define('FFLA_GITHUB_TOKEN', 'ghp_your_token_here');
```

## Configuration

### Activating Modules
The plugin is modular. You can enable or disable features to keep your site lightweight.
1.  Navigate to **FFL Funnels > Dashboard**.
2.  Toggle the switches for the modules you want to use (e.g., WooBooster, Wishlist). The dashboard groups modules into **Active** and **Available**.
3.  Click **Open settings** on an active module to configure it.

Each module guide lists what the module needs and what happens to its data when it is switched off or the plugin is uninstalled.

### Sales Tax Resolver
1.  Turn on WooCommerce taxes (**Enable tax rates and calculations**) and switch on **Sales Tax Resolver** in **FFL Funnels > Dashboard**.
2.  Go to **FFL Funnels > Sales Tax Resolver > Settings**.
3.  The Google Sheet dataset is the default rate source. Optionally paste a **USGeocoder Auth Key**, click **Test key** and choose the **Tax rate source**.
4.  Optionally enable **Limit resolver to selected states** and choose only the states your store uses.
5.  Click **Save Settings**, then open **Datasets** and click **Sync Sheet Data** if you do not want to wait for the scheduled import.
6.  Use **Quote Lookup** to verify results before testing in WooCommerce checkout, then read the **Audit Log** after a test order.
7.  Optionally set up exemptions and tax holidays on the Settings tab.
8.  For filing reports, switch on **Sales Tax Reports** (a separate module) and open **WooCommerce > Sales Tax Reports**. Use **Delivery & History** to configure the previous-month email schedule, and enable the order audit with shipping addresses only for recipients authorized to receive customer data.

Notes:
*   Without a USGeocoder key the Google Sheet dataset is used. With a key, **Automatic** routing tries USGeocoder first and falls back to the sheet, so keep the sheet imported.
*   Unchecking a state and saving deletes that state's imported sheet data.
*   **Delete Old Tax Database** deletes all imported sheet datasets (including the ones in use), the address cache and the audit log. Sheet lookups fail until the next sync, so do not use it as routine cleanup.
*   Every REST route requires `manage_woocommerce`.

### WooBooster Rules
1.  Go to **FFL Funnels > WB Rules**.
2.  Click **Add Rule** and name it.
3.  **Conditions:** Define *when* this rule applies (e.g., "Product Category is Firearms").
4.  **Actions:** Define *what* to show (e.g., "Products from Category: Ammo" or "Products with Attribute: Caliber = 9mm").
5.  Click **Create Rule**, and use **WB Diagnostics** to check which rule matches a product.
6.  **Priority:** Active rules are checked lowest priority number first (the oldest rule on a tie); the first rule whose conditions match wins. Apply Coupon rules are the exception; every matching rule applies its coupon.

## Requirements

*   WordPress 6.2 or higher
*   WooCommerce, installed and active (the plugin header requires it). 8.0 or higher is the documented baseline; the plugin does not check the WooCommerce version.
*   PHP 7.4 or higher

Optional, depending on the modules you use:

*   **Bricks Builder:** the Bricks elements, query loops and dynamic tags of WooBooster, Wishlist, FFL Checkout, Product Reviews and Loadout. It is the only way to show WooBooster bundles on the storefront.
*   **g-FFL Checkout:** the FFL Checkout dealer finder and borrowed Mapbox token, and the FFL rules in Pickup & Shipping.
*   **Google for WooCommerce**, connected to Merchant Center: needed for Google Merchant Policy to change anything in Google.
*   **MonsterInsights** with its eCommerce Addon and a GA4 connection: MonsterInsights Compatibility. MonsterInsights and SnapFind are also optional data sources for the White Label dashboard.
*   **Google Sheets access** (a service account, or the OAuth proxy flow) for Woo Sheets Sync; PHP's OpenSSL extension is used for it.
*   **WordPress 6.9+ and the MCP Adapter plugin** (or a plugin that includes it, such as Novamira): the WooBooster MCP abilities and the Customer & Order Management MCP tools.
*   **USGeocoder key:** optional live lookups in Sales Tax Resolver.
*   **Simple Cloudflare Turnstile:** optional spam protection for Product Reviews. **SnapFind:** search integration for Wishlist.

## REST API

The plugin registers two REST namespaces. Every route requires a user with the `manage_woocommerce` capability, and cookie-authenticated requests also need a standard WordPress REST nonce in the `X-WP-Nonce` header. The endpoints are intended for site-local tooling and integrations you write yourself; they are not public APIs.

### Woo Sheets Sync: `wss/v1`

Internal endpoints for creating or updating products, variations and attribute terms. The WSS Settings page lists them and has a **Test API now** button. All routes are `POST` and read a JSON body only.

Base URL: `https://<your-site>/wp-json/wss/v1/`

| Method | Endpoint             | Purpose                                                                                                                                  |
| ------ | -------------------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| POST   | `/products/upsert`   | Create or update a simple product, matched by `product_id` or `sku` (`name` is required to create). Creates a published product when none matches; optional `tab_name` adds it to that tab group. |
| POST   | `/variations/upsert` | Create or update a variation under `parent_id` (required), matched by `sku` or the complete `attributes`.                                |
| POST   | `/attributes/upsert` | Find or create a term (`value`, required) on an existing global attribute (`taxonomy` as `pa_*`, or `label`).                            |
| POST   | `/batch/upsert`      | `{ "items": [{ "kind": "product\|variation\|attribute", "payload": { ... } }] }`: at most 200 items and 2 MB per call.                    |

*   Field formats follow the sheet, and JSON types are accepted too: `manage_stock` is `true`/`false` or `"TRUE"`/`"FALSE"` (also yes/no, 1/0), and `attributes` is an array or one string, `"Label: Value | Label: Value"`.
*   Single endpoints return HTTP 400 with `{ "error": ... }` on failure; an oversized batch returns 413.
*   `status` and `type` on `/products/upsert` and `variation_id` on `/variations/upsert` are honoured.

Example: ensure a `pa_manufacturer` term:

```json
POST /wss/v1/attributes/upsert
{ "label": "Manufacturer", "value": "Demo Manufacturer" }
```

### Sales Tax Resolver: `ffl-tax/v1`

Routes for quoting sales tax and inspecting the resolver's datasets and audit log. Checkout does not call them; it uses the quote engine directly. `/quote` is limited to 60 requests per minute per IP and `/quote/batch` to 30 per minute. The IP ignores proxy headers unless the `ffla_tax_trust_proxy_headers` filter returns `true`.

Base URL: `https://<your-site>/wp-json/ffl-tax/v1/`

| Method | Endpoint          | Purpose                                                                                                                                                                          |
| ------ | ----------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST   | `/quote`          | Resolve the total sales-tax rate and breakdown for one address (`street`, `city`, `state`, `zip`, or a one-line `address`). Returns 200 on success, 422 when the outcome is not a success and 429 over the limit. `totalRate` is a decimal (`0.0825` = 8.25%). |
| POST   | `/quote/batch`    | Resolve several addresses. Body: `{ "addresses": [ ... ] }`, at most 25. Returns `{ count, results }`.                                                                           |
| GET    | `/coverage`       | Coverage Matrix per state: status, resolver, notes, whether it is enabled for the store, and the source strategy, plus `dataset` (version, load date, age, freshness).            |
| GET    | `/health`         | Active datasets with age and freshness, coverage summary, sheet source, resolvers and 24-hour lookup stats. `cacheHitRatio24h` is always `null` (`cacheHitsAudited: false`) because cache hits are not audited. |
| GET    | `/datasets`       | The 50 most recently loaded sheet dataset versions, newest first.                                                                                                                |
| POST   | `/admin/sync`     | Run the sheet sync now (same as the **Sync Sheet Data** button).                                                                                                                 |
| GET    | `/admin/audit`    | Recent audit rows. Supports `?limit=1..100` (default 25) and `?state=XX`.                                                                                                        |

Example: quote a single address:

```json
POST /ffl-tax/v1/quote
{
  "street": "1600 Pennsylvania Ave NW",
  "city":   "Washington",
  "state":  "DC",
  "zip":    "20500"
}
```

### Debug logging

OAuth debug output for Woo Sheets Sync is off by default. It turns on when `WSS_OAUTH_DEBUG` is true, or when `WP_DEBUG` and `WP_DEBUG_LOG` are both on. Writing a log file is a separate opt-in, so nothing is written to `wp-content/uploads` unless you ask for it. To enable it temporarily, add these constants to `wp-config.php`:

```php
define('WSS_OAUTH_DEBUG', true);       // OAuth debug to the PHP error log
define('WSS_OAUTH_DEBUG_FILE', true);  // also write wp-content/uploads/wss-logs/wss-oauth-debug.log
```
---
*For internal use by FFL Funnels clients.*
