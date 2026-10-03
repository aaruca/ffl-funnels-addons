# FFL Funnels Addons

**Custom addons and integrations for FFL Funnels WooCommerce stores.**

![Version](https://img.shields.io/badge/version-1.55.3-brightgreen.svg)
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
| MonsterInsights Compatibility | Fills in missing GA4 `view_item` and `add_to_cart` events on product pages. | [modules/ga4-bridge/README.md](modules/ga4-bridge/README.md) |
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
*   **Classic checkout selector:** accessible delivery cards, placed before the billing fields automatically or with `[ffla_delivery_choice]` inside a custom classic checkout form. Addresses, prices, tax and carrier requests stay with WooCommerce and your existing providers, and the module never makes a method free. Checkout Blocks and the Store API are not modified; an admin notice says so.
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

Optional GA4 compatibility for stores that track with MonsterInsights and its eCommerce Addon but use custom Bricks product templates and AJAX side-carts such as Merchant. It has no settings page.

*   **One analytics owner:** MonsterInsights loads the Google tag, applies its excluded roles, and owns `begin_checkout`, purchases and refunds. The module never loads a Google tag and never sends purchase revenue.
*   **Narrow coverage:** it fills in only a missing `view_item` and an AJAX `add_to_cart` of the page's own product, on single product pages only.
*   **Deduplication:** before sending a fallback it checks the page's `dataLayer`, and an event MonsterInsights already sent is left untouched.
*   **Opt-in:** MonsterInsights works by itself on standard WooCommerce templates, so switch this module on only where those events are actually missing. Stores still on Google Analytics for WooCommerce keep the earlier compatibility path.

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

## Changelog

This section is a historical summary up to v1.43.1. Entries describe the plugin as it was at that release and can differ from the current module guides. For releases since then, see [CHANGELOG.md](CHANGELOG.md).

### v1.43.1

*   **Sales Tax Reports — complete release:** Dedicated WooCommerce workspace with Overview, States, Jurisdictions, Orders, Reconciliation, Nexus Monitor, Delivery & History, and Tools.
*   **Filing assistance:** State and local taxable sales, tax collected, calculated tax, and over/under collection totals, with taxed shipping included in the filing base.
*   **Order audit:** Optional order-level workpapers include customer and shipping-address details for accountant review.
*   **Reconciliation and nexus:** WooCommerce Analytics comparisons plus advisory state nexus monitoring with thresholds explicitly marked for state-authority verification.
*   **Delivery and consolidation:** Monthly scheduled email delivery, generation history, secure multi-site report combining, and state-template mapping.
*   **Tax exclusions:** Individual WooCommerce customer exclusions in addition to role-based exclusions, using request-level caching.
*   **Hardening:** Bounded order/CSV processing, streamed downloads, ZIP validation, spreadsheet-formula neutralization, atomic tool locks, refund allocation, and taxed-shipping reversal coverage.

### v1.43.0

*   **White Label:** Per-mode wp-admin branding, menu ordering, client restrictions, staff exemptions, branded dashboard, and sanitized settings import/export.
*   **Dashboard Analytics:** Lazy Google Analytics/Google Search Console reporting through Rank Math PRO alongside SnapFind search analytics, with shared 7/30/90-day ranges and per-user source/range preferences.
*   **Performance:** WooCommerce dashboard metrics process paid orders in bounded pages instead of loading every order object at once.
*   **Import correctness:** Imported White Label settings now replace the destination configuration rather than retaining omitted sections.

### v1.42.0

*   **Tax Reports:** Workpapers, monthly email delivery, and shipping-address reporting.
*   **Releases:** Automated clean updater ZIPs, package verification, checksums, GitHub assets, and release commit statuses.

### v1.22.0

*   **WooBooster Bundles — Complete 5-phase overhaul:** (1) Correctness: hash-bound cart grouping, server-side price snapshots, single fee-per-bundle with full-set validation. (2) Performance: batch condition loading (eliminates N+1), InnoDB transactions, split cache (decisions hourly/global, items per-request/session-aware), role-aware keys. (3) UX/a11y: polite inline errors, localized strings, ARIA labels, keyboard navigation, timezone-aware dates. (4) Testing: 8 PHPUnit test cases, pure discount math extraction, shared price calculator. (5) Schema: `quantity` column, optimized indexes, User Role condition type, safe migration v1.7.0 → v1.8.0.

### v1.21.0

*   **Product Reviews — Cloudflare Turnstile:** Removed in-module component; now integrates with **Simple Cloudflare Turnstile** plugin for rendering and validation.

### v1.20.1

*   **Product Reviews — Order review hub:** Layout and tokens matched to the standard review form (card per product, typography variables).

### v1.20.0

*   **Product Reviews:** Refined spacing and typography on the reviews tab (including **1rem = 10px** root-friendly sizing); redesigned optional media section with **Choose files**, list of selected files, and **remove** before submit; more robust multi-file upload handling in PHP.

### v1.19.0

*   **Product Reviews:** Optional **Replace WooCommerce reviews tab with FFL form** — the default product Reviews tab can show the FFL list and form without extra Bricks blocks; shared renderer with Bricks elements; filters for tab list/form settings.
*   **Wishlist + SnapFind:** Wishlist ranking boost on search is **opt-in** (default off); toggle under Wishlist when SnapFind is active.

### v1.18.0

*   **WooBooster AI:** Multi-provider support (OpenAI, DeepSeek, NVIDIA NIM), model override, thinking mode, tool execution for `create_rule` / `update_rule`, chain-of-thought UI and provider badge in the chat modal.
*   **Wishlist + SnapFind:** Automatic heart buttons and wishlist-based ranking boost on Typesense search results; optional `wishlist_count` field for index popularity; admin documentation under Wishlist settings.

### v1.17.0

Full rewrite of Smart Recommendations so results are relevant and no slot ever renders empty.

*   **`similar` products** — new weighted multi-signal scoring: brand, key attributes (`pa_caliber-gauge` / `pa_manufacturer` / `pa_platform` by default), shared categories, shared tags, price proximity (Gaussian), recent popularity from `wc_order_product_lookup`, publish-date recency, shipping class match, and an OOS penalty. Candidate pool capped at 500, all signals loaded in ~4 batched queries (no N+1). Tunable via `woobooster_similar_weights`, `woobooster_similar_brand_taxonomies`, `woobooster_similar_key_attributes`.
*   **`trending`** — category-level trending transients are merged with a rank-aware score so products that rank high in more than one category bubble up.
*   **`copurchase`** / **`recently_viewed`** — every strategy now runs through a shared `fallback_fill()` cascade (same-category bestsellers → global trending → recent products) so empty slots only happen when the store is literally empty.
*   **Candidate validation** — shared `validate_candidates()` helper preserves rank, filters out unpublished / OOS, and caps to the limit.

### v1.16.1

Follow-up pass from the 1.16.0 audit: status filter whitelist + bulk-action cap check in WooBooster, paginated bundle index rebuild, stricter `INFORMATION_SCHEMA` scoping, analytics range swap, `HttpOnly` recently-viewed cookie, Tax Normalizer requires a canonical US state + ZIP + street, address cache flushes on TTL reduction, coverage reconcile now has a 30 s lock, Reviews list renders the body through a restricted kses allowlist and helpful votes get a per-comment daily cap, Woo Sheets Sync REST batch body capped at 2 MB, OAuth state keyed per user, admin logger redacts sensitive keys, sheet `batch_update` retries with exponential backoff, Wishlist AJAX has a per-IP rate limit + max 200 items, updater differentiates 403 rate-limit vs forbidden.

### v1.16.0

Hardening pass across every module. Highlights:

*   **WooBooster Bundles (CRITICAL):** `ajax_add_bundle_to_cart` validates that the submitted product IDs actually belong to the bundle; `discount_type = fixed` is now applied once per bundle and the Bricks element prorates it across items.
*   **WooBooster analytics (HIGH):** rule attribution for add-to-cart no longer trusts `$_POST['wb_rule_id']`; the server stores the mapping in the WC session at render time.
*   **Tax Rates REST (HIGH):** the client IP used for rate limiting ignores proxy headers by default (filter `ffla_tax_trust_proxy_headers` to re-enable); `/quote/batch` gets its own 30/min limit and a 25-address cap.
*   **FFL Checkout (HIGH):** `ajax_update_vendor` matches `shipping_class` against the API option, not just `warehouse_id` / `price` / `sku`.
*   **Woo Sheets Sync:** removed the hardcoded fallback OAuth proxy secret. `WSS_PROXY_SECRET` is now required for the proxy flow.
*   **Doofinder Sync:** `wp_json_encode_options` filter merges flags instead of overwriting.
*   **WooBooster internals:** bundle matcher no longer uses reflection, bundle scheduling stored in GMT, rule import preserves `not_equals` and advanced action fields, trending fast path filters by order status, object cache invalidated on every rule/bundle write, trending / index diagnostics unchanged.

### v1.15.2

*   **WooBooster — HPOS:** Index Diagnostics and co-purchase SQL now detect **actual** HPOS usage via WooCommerce `OrderUtil`, not merely whether `wp_wc_orders` exists. Stores still authoritative on `wp_posts` (or mid-migration) no longer show false zero order counts.

### v1.15.1

*   **WooBooster — HPOS:** Correct order-status matching when WooCommerce stores orders in `wp_wc_orders` (status values without the `wc-` prefix). Co-purchase and trending Smart index builds, plus **Index Diagnostics**, now count orders reliably; diagnostics show storage (`hpos` vs `posts`) and which statuses were queried.

### v1.15.0

*   **WooBooster — Bricks:** New **WooBooster Smart Recommendations** query type: pick one Smart strategy (similar, co-purchase, trending, recently viewed) with product source, limit, out-of-stock filter, and fallbacks — no rule matching. Existing **WooBooster Recommendations** (rules) unchanged.
*   **WooBooster — Smart index:** Admin **Index Diagnostics** (orders in window, multi-line vs single-line orders); filterable order statuses via `woobooster_copurchase_order_statuses` and `woobooster_trending_order_statuses`; Rebuild AJAX surfaces a reason when a build returns zero products.
*   **WooBooster — AI:** System prompt includes the current date; Tavily `search_web` accepts `time_range`, `topic`, and `search_depth` for fresher web answers.
*   **WooBooster — Analytics:** Smart Bricks loops use pseudo rule id `-1` and appear as a single **Smart (all)** row in Top Rules; cart/order attribution uses signed integers so `-1` is preserved.

### v1.14.1

*   **Tax Rates — role gate semantics flipped (exemption list):** The role gate card now models **exemptions** instead of an allow-list, which matches how storeowners actually think about the feature ("everyone pays tax except these roles"). The card was renamed to "Tax exemptions by user role" and the toggle to "Exempt certain user roles from tax". Checked roles are **exempt** (see `$0` tax); unchecked roles (and guests, unless you check "Guest") are taxed normally. Users with multiple roles are exempt as long as *any* of their roles is checked. When the toggle is off, every customer is taxed — identical to a fresh install.
*   **Tax Rates — setting key renamed:** The persisted option moved from `ffla_tax_resolver_settings[taxed_roles]` to `ffla_tax_resolver_settings[tax_exempt_roles]` to reflect the new meaning. `Tax_Role_Gate` now exposes `get_exempt_roles()` and its `should_charge_for_current_customer()` returns `false` only when at least one of the customer's roles appears in the exempt list. The "gate on but list empty" case is a harmless no-op (every customer is taxed normally) instead of the previous everyone-sees-$0 footgun. The legacy `taxed_roles` key is intentionally ignored — its semantics are the opposite of the new key and v1.14.0 shipped for less than a day, so no migration is applied.

### v1.14.0

*   **Tax Rates — role-based charging:** New opt-in setting under Tax Resolver → Settings ("Tax charges by user role") that lets stores charge tax only to specific WordPress user roles. The card shows every role on the site plus a "Guest (not logged in)" row; checked roles pay tax, unchecked roles (and guests, unless Guest is checked) see `$0` tax at checkout. When the feature is off the plugin behaves exactly like before — every customer is taxed. Common use case: a wholesale store that taxes retail customers but not B2B accounts.
*   **Tax Rates — role gate helper:** New `Tax_Role_Gate` class (`includes/class-tax-role-gate.php`) exposing `is_active()`, `get_allowed_roles()`, `get_role_choices()`, and `should_charge_for_current_customer()`. The WooCommerce integration now short-circuits `woocommerce_matched_tax_rates` to an empty rate set when the gate is active and the current customer's role isn't in the allowed list; the synthetic runtime tax metadata is cleared at the same time so stale rates from a previous request don't leak through. Users with multiple roles pay tax as long as *any* of their roles is checked.

### v1.13.0

*   **Tax Rates (BYOK):** "Bring your own USGeocoder key" flow is now productionized. Leaving the key empty keeps the shared monthly-refreshed Google Sheet dataset (free); pasting a key upgrades the matching states to live address-level precision via the USGeocoder JSON API.
*   **Runtime fallback (critical):** `Tax_Quote_Engine::quote()` now retries failed USGeocoder attempts against the Sheet ZIP dataset when the outcome is `SOURCE_UNAVAILABLE`, `RATE_NOT_DETERMINABLE`, or `INTERNAL_ERROR`. Both attempts are recorded in `trace.fallbackChain` and the fallback result is tagged `sourceVersion = "sheet_fallback"` so the admin audit row makes the fallback visible.
*   **Coverage reconcile:** The per-request `Tax_Coverage` write loop that used to run inside `boot()` was replaced by `Tax_Coverage::reconcile_from_settings()`, invoked on activation and through `update_option_ffla_tax_resolver_settings`. Writes are diffed so only rows whose resolver/status/notes actually changed are touched.
*   **Respect restrict_states for USGeocoder:** With `restrict_states = 1`, only states listed in `enabled_states` route to `usgeocoder_api`; the rest fall back to `sheet_zip_dataset`. The resolver itself also refuses to hit the paid endpoint for disabled states as a defense in depth.
*   **Cache invalidation:** The address cache (`wp_ffla_tax_address_cache`) is now flushed via `Tax_Resolver_DB::flush_address_cache()` whenever `usgeocoder_auth_key`, `restrict_states`, or `enabled_states` change; the stored transient from the Test-key button is cleared at the same time. The settings page surfaces an info notice listing the reasons so the admin understands why cached quotes were dropped.
*   **Admin UX:** New mode badge ("Sheet Mode (free)" / "USGeocoder Mode (live API)" / "USGeocoder Mode (key invalid)") on the settings card, reworded help text that explains both modes, external link to usgeocoder.com, and a clear note about per-call pricing + runtime fallback.
*   **Test-key validator:** New `wp_ajax_ffla_tax_test_usgeocoder` action and "Test key" button next to the auth key field. It calls the USGeocoder sample address from the official docs and reports `ok` / `network_error` / `http_error` / `empty_payload` / `no_rate` with the specific reason. The result is cached in a `ffla_tax_key_validation` transient for 1 hour so the page can show a persistent status badge without re-hitting the paid API on every reload.
*   **USGeocoder call counter:** New `Tax_USGeocoder_Usage` class. Every real HTTP call increments both a `YYYY-MM` bucket in `ffla_tax_usgeocoder_usage` (trimmed to 24 months, split into success/failed) and is visible through a live rolling-30d query against `wp_ffla_tax_quotes_audit` filtered on `source_code = 'usgeocoder_api'` + `cache_hit = 0`. An "API Usage" card on the settings page renders the rolling-30d badge and the last 6 months. Cache hits never reach the resolver so they cannot inflate the counter.
*   **Resolver cleanup:** Removed hardcoded `SUPPORTED_WITH_REMOTE_LOOKUP` from the USGeocoder resolver — the result now reads `Tax_Coverage::get_state($state)['coverage_status']` so per-state configuration drives the response shape. Extracted a shared `USGeocoder_API_Resolver::fetch_api()` helper reused by both `resolve()` and the admin Test-key action.

### v1.12.0

*   **i18n (Admin Docs):** Every literal string in the Woo Sheets Sync docs tab (`WSS_Admin::render_docs_page()`) is now wrapped with `esc_html_e()` / `wp_kses()` + `sprintf()` so the whole onboarding/troubleshooting guide becomes translatable while preserving the existing `<strong>`, `<em>` and `<code>` markup.
*   **i18n (JS):** Added `t(key, fallback)` helpers to `woo-sheets-sync-module.js`, `tax-rates-admin.js` and `woobooster-ai.js`; every previously hardcoded confirm/alert/status/button label now resolves through `wssDashboard.i18n`, `FflaTaxResolver.i18n` and `wooboosterAdmin.i18n` (expanded via `wp_localize_script`). The WooBooster AI "Create This Rule/Bundle" label is driven by an `fmt()` helper using a translatable `%s` format so the entity label flows from a single localized source.
*   **REST:** Added a full "REST API" section to `README.md` documenting both the `wss/v1` (products/variations/attributes/batch upsert) and `ffl-tax/v1` (quote/quote batch/coverage/health/datasets/admin sync/admin audit) namespaces, their capability requirements, and the `ffl-tax/v1/quote` 60 req/min/IP rate limit.
*   **Performance (Woo Sheets Sync):** Introduced `WSS_Google_Sheets::read_range_paginated()` that walks the target tab in 2000-row chunks (configurable via `wss_sheet_read_chunk_size`) and stops on the first short chunk — `WSS_Sync_Engine::run()` now uses it so large sheets no longer risk hitting the Sheets API ~10MB single-range response cap.
*   **Async sync:** New `WSS_Sync_Job` helper. When Action Scheduler is available (bundled with WooCommerce) the admin "Sync Now" button enqueues an async `wss_run_sync_job` action and returns a `job_id`; the admin JS then polls `wp_ajax_wss_sync_status` with a progress bar until completion. Environments without Action Scheduler keep the previous synchronous behavior automatically.
*   **Options:** New `FFLA_Options` helper (`includes/class-ffla-options.php`) with `get()` / `update()` / `delete()` that prefer a canonical `ffla_*` key but transparently fall back to legacy names (`wss_*`, `alg_wishlist_*`, …) — new code can migrate key-by-key without touching existing production data.
*   **Tooling:** Added `composer.json` (wp-coding-standards, PHPCompatibilityWP, PHPStan + phpstan-wordpress), `.phpcs.xml.dist`, `phpstan.neon.dist` with a lightweight `tests/phpstan/bootstrap.php`, `package.json` + `.eslintrc.json`, and an opt-in `.github/workflows/lint.yml` that runs PHPCS, PHPStan and ESLint on push/PR (using `|| true` so existing non-compliant code doesn't block merges until the codebase is tightened).

### v1.11.0

*   **Security:** WooBooster AI chat now HTML-escapes assistant output before applying a strictly limited markdown renderer (bold + line breaks), preventing stray HTML/script execution in admin even if it slipped past server-side `wp_kses_post`.
*   **Security:** Wishlist empty-state message is rendered via `textContent` on a dedicated DOM node instead of assigning the translated string into `innerHTML`, removing a latent XSS path if a translation/i18n entry were ever hostile.
*   **Admin capabilities:** Updater notice display and the `ffla_dismiss_api_notice` handler now consistently require `manage_woocommerce`, matching the capability used by every other FFLA admin surface.
*   **Performance (WooBooster):** `woobooster_get_option()` memoizes `woobooster_settings` per request and auto-refreshes the cache on `update_option_woobooster_settings` / `add_option_woobooster_settings`, collapsing repeated reads into a single DB fetch per request.
*   **Performance (WooBooster Matcher):** Added per-request caches for rule rows (`$rule_row_cache`), conditions (`$conditions_cache`), and `get_term_by()` slug lookups (`$term_slug_cache`), plus a bulk `prefetch_rules()` that loads all candidate rules in a single `IN(...)` query — eliminating the per-candidate `SELECT` and per-condition term lookups that showed up as N+1 on stores with many rules.
*   **Performance (Tax Rates):** `Tax_Dataset_Pipeline` no longer loads the full Google-Sheets CSV export into memory. The HTTP response is streamed to a `wp_tempnam()` file and parsed line-by-line via `fgetcsv`, grouping rows by state incrementally and freeing each state's slice as soon as it is imported.
*   **Performance (Woo Sheets Sync):** `sync_woo_to_sheet()` replaces the unbounded `get_posts(posts_per_page=-1)` with a direct `wpdb->get_col()` over `posts`+`postmeta`, and both sync directions now call `_prime_post_caches()` once on the full ID set so the subsequent `wc_get_product()` loop hits the object cache instead of issuing per-product SELECTs.
*   **Performance (Woo Sheets Sync):** `WSS_Sync_Orchestrator::run_all()` tracks processed tab names and skips any later group pointing at a tab that has already been synced in the same run, with an explicit `skipped` entry in the report — avoids duplicate full-sheet reads when groups are misconfigured.
*   **i18n (PHP):** All module `get_name()` / `get_description()` strings (Woo Sheets Sync, Tax Rates, Product Reviews, WooBooster, Wishlist, FFL Checkout, Doofinder Sync) and the USGeocoder / Sheet ZIP dataset resolver labels are now wrapped in `__(..., 'ffl-funnels-addons')` and translatable.
*   **i18n (JS):** WooBooster admin (`woobooster-module.js`) gained a `t(key, fallback)` helper and all previously hardcoded confirm/alert/status strings (`Deleting…`, `Delete All`, `Importing…`, `Rebuild Now`, `Clear All Data`, `Network error.`, `Please fix the following:`, `At least one action is required in a group.`, `Are you sure you want to delete this bundle?`, etc.) now resolve through `wooboosterAdmin.i18n`, populated via `wp_localize_script`. Wishlist empty-state and fallback labels read from `AlgWishlistSettings.i18n`.

### v1.10.4

*   **Woo Sheets Sync:** REST endpoints (`wss/v1`) now declare `args` with `validate_callback`/`sanitize_callback`; `/batch/upsert` caps items at 200; OAuth debug logs redact state/payload/tokens and require explicit `WSS_OAUTH_DEBUG`; file logging requires `WSS_OAUTH_DEBUG_FILE` and protects the directory with `.htaccess` + `web.config`.
*   **Woo Sheets Sync:** Per-request cache for label→taxonomy resolution and for Google Sheets tab metadata; HTTP client retries 429/5xx with exponential backoff (and honors `Retry-After`).
*   **Woo Sheets Sync:** `sync_enabled_meta_from_groups()` replaces the full enabled-products scan with a diff-based SQL query; group resolution is memoized per request.
*   **WooBooster:** `import_rules` AJAX enforces a 2 MB payload cap, strict top-level field allowlist, and per-rule limits (≤50 conditions per group, ≤50 actions); settings option is persisted with `autoload=no` and migrated on activation.
*   **Tax Resolver:** `/quote` rate limit uses atomic `wp_cache_add`+`wp_cache_incr` when a persistent object cache is available; transient fallback preserved.
*   **Uninstall:** Cleans up the correct keys (`wss_google_tokens`, `wss_last_sync`), removes `_wss_sync_enabled` post meta, unschedules `wss_daily_sync`, deletes `wss_oauth_state` transient.

### v1.9.6

*   **Woo Sheets Sync:** Sheet **tab groups** on the Dashboard — multiple Google Sheet tabs, per-tab product rules (search, categories, tags, link all / clear tab rules), migration from legacy single-tab + `_wss_sync_enabled`, orchestrated sync with per-tab stats and `wss_last_sync` group summary; product metabox shows which tabs include the product; docs updated for multi-tab behavior and Sheet→Woo priority.
*   **Tax Address Resolver:** USGeocoder **live API** resolver class and related wiring (coverage, resolver DB, admin/JS updates).

### v1.9.5

*   **WooBooster:** **Entire store** condition for rules and bundles (match all products); admin defaults to that instead of an empty type row; clearer list column text.

### v1.9.4

*   **Product Reviews:** Bricks **Reviews Rating Badge** — optional “Hide when no reviews”; minor core cleanup.

### v1.9.3

*   **Product Reviews:** Order review hub — shortcode `[ffla_order_reviews]` + Bricks element, signed email links (query or pretty URLs), optional bundle email per order, global moderation toggle, admin hub/pretty URL settings, Turnstile bypass for valid token flows, verified purchase from order token, uninstall clears bundle hooks.

### v1.9.2

*   **Wishlist:** Wishlist JS/CSS load on all public storefront pages (with WooCommerce active) so the header counter and guest session work everywhere, not only on shop/product/wishlist templates.
*   **Wishlist:** Bricks Wishlist Counter and `[alg_wishlist_count]` render the correct count from PHP on first load; optional filter `ffla_wishlist_enqueue_assets` to disable global enqueue for advanced setups.
*   **Product Reviews:** Bricks Review Form — fixed star-rating JS so overall/quality/value groups all update correctly; checkbox “Show quality & value” hides optional rows when off; Style tab for layout/colors/typography (container, stars, fields, button, notices).

### v1.9.1

*   **Product Reviews:** Stricter CSRF on the Bricks/custom review form (`admin-post`), safe redirects with `wp_validate_redirect`, honeypot/Turnstile failures redirect instead of a blank `wp_die` screen.
*   **Product Reviews:** Frontend CSS/JS (and Turnstile) load on single product pages by default; Bricks builder/preview can still load assets automatically; optional filters for custom templates.
*   **Wishlist:** Frontend assets load only on relevant WooCommerce pages, the configured wishlist page, shortcodes, or Bricks builder — reduces global page weight.
*   **Wishlist:** User-facing strings use the `ffl-funnels-addons` text domain; Bricks query label simplified to “Wishlist”.
*   **Bricks:** Wishlist, WooBooster bundle, FFL Dealer Finder, and Product Reviews elements use one category: **`FFL Funnels`**.
*   **Tax Resolver:** `GET /ffl-tax/v1/coverage` and `/health` REST routes now require `manage_woocommerce` (no anonymous operational metadata).
*   **Updater:** Registered `wp_ajax_ffla_dismiss_api_notice` so the GitHub API warning can be dismissed; removed unused admin-post dismiss URL; dismiss checks `manage_options`.
*   **Uninstall:** Tax module drops resolver tables/options and clears crons; Product Reviews clears settings and scheduled actions; FFL Checkout vendor meta removed from HPOS `wc_orders_meta` when present.
*   **WooBooster:** AI error logging only when `WP_DEBUG` is enabled.
*   **CI:** GitHub Actions workflow runs `php -l` on all PHP files on push/PR.

### v1.9.0

*   Feature: Added new **Product Reviews** module with Bricks-native elements (Rating Badge, Reviews List, Review Form).
*   Feature: Added post-purchase review request scheduling and customizable review email template.
*   Feature: Added review helpful-vote system with nonce and rate-limit protection.
*   Feature: Added review media uploads (images/videos), verified-buyer meta, and product-specific extra criteria.
*   Feature: Added optional **Cloudflare Turnstile** integration for review form protection with server-side validation.
*   Enhancement: Added admin moderation shortcuts for review media and helpful counts in the comments list.
*   Maintenance: Bumped plugin version and assets to `1.9.0`.

### v1.8.1

*   Maintenance: bumped the plugin asset version to `1.8.1` so WordPress and browsers invalidate cached admin CSS/JS after the final 1.8 styling fixes.

### v1.8.0
*   Feature: Released the Tax Address Resolver as a stable module powered by a shared Google Sheets CSV source.
*   Feature: The tax module now uses a single source-of-truth flow: Google Sheet CSV -> local WordPress tax tables -> WooCommerce runtime tax calculation.
*   Feature: Added local dataset sync that imports ZIP rows, city fallback rows, and state floor rows for the states your store selects.
*   Feature: Added monthly automatic refresh so selected states can rebuild from the shared sheet without live scraping at checkout.
*   Feature: WooCommerce cart and checkout now resolve taxes from the locally imported dataset instead of external web calls.
*   Feature: Added Quote Lookup for manual verification of ZIP, city, and state-floor matches from the local dataset.
*   Feature: Added Coverage Matrix, Datasets, Audit Log, and Settings screens for tax operations inside WordPress admin.
*   Feature: Added state selection controls so each store can limit the resolver to only the states it actively uses.
*   Enhancement: Removing a state from the selected list now purges that state's local dataset and clears its cached quotes.
*   Enhancement: The resolver keeps zero-tax states working through the imported local model and coverage tracking.
*   Enhancement: The admin UI now reflects the local-sheet workflow rather than the previous beta experiments.
*   Cleanup: Removed beta-era API key access, manual override UI, AI/import experiments, and legacy resolver/source paths from the active tax flow.
*   Fix: Stabilized the Tax Resolver admin tabs and layout inside the shared FFL admin shell for the final 1.8.0 build.

### v1.8.0-beta.5
*   Feature: Cobertura nacional del resolver fiscal completada para los 50 estados + DC.
*   Feature: Nuevos resolvers oficiales exactos para CT, DC, HI, MA, MD, ME, MS, PA y VA.
*   Feature: Nuevos resolvers conservadores de tasa base estatal para AL, AZ, CA, CO, FL, ID, IL, MO, NM, NY y SC.
*   Enhancement: Las respuestas ahora distinguen mejor entre tasas exactas por direcci&oacute;n y tasas base oficiales cuando a&uacute;n faltan capas locales.

### v1.8.0-beta.4
*   Fix: El checkout usa ahora la direcci&oacute;n viva posteada por WooCommerce para cotizar impuestos, evitando c&aacute;lculos en `0` por datos desincronizados.
*   Fix: Los quotes servidos desde cache ahora tambi&eacute;n se registran en el Audit Log.
*   Fix: Integraci&oacute;n de impuestos runtime alineada con el comportamiento interno de WooCommerce para labels, rate codes, tax items y c&aacute;lculo no-compound.

### v1.8.0-beta.3
*   Fix: Compatibilidad corregida con `woocommerce_matched_tax_rates` para evitar fatal errors en checkout/cart.
*   Fix: Fallback por FIPS para Louisiana y Texas cuando Census no entrega bien county/parish.
*   Fix: `Tax Quote Lookup` ahora apila todos los campos en columna para una UI m&aacute;s limpia.

### v1.8.0-beta.2
*   Fix: Integración directa con WooCommerce para cálculo runtime de impuestos en checkout/cart.
*   Feature: Resolutor oficial para Louisiana vía Parish E-File.
*   Feature: Resolutor oficial para Texas vía rate file del Comptroller.
*   Fix: Extracción robusta de county/parish desde Census para Louisiana/Texas.
*   Fix: Mejoras visuales en los campos de **Tax Quote Lookup**.

### v1.8.0-beta.1
*   Feature: Nuevo módulo **US Tax Rates** — importa automáticamente las tasas de impuestos de USA por estado/condado directamente en WooCommerce.
*   Feature: Investigación vía Tavily (búsqueda web) + OpenAI (estructuración de datos) usando las claves ya configuradas en WooBooster.
*   Feature: Panel de admin con selector de estados, barra de progreso animada y log en tiempo real durante la importación.
*   Feature: Cron mensual automático para mantener las tasas actualizadas.
*   Feature: Las tasas se insertan en las tablas nativas de WooCommerce (prefijo `FFLA_`) — sin llamadas externas en el checkout.

### v1.7.4
*   Enhancement: Wishlist Counter badge rediseñado — ahora aparece arriba a la derecha del icono (estilo notificación).
*   Feature: Nuevo control **Label text** en el elemento Wishlist Counter — texto opcional junto al icono.
*   Feature: Nuevo control **Label position** — elige si el texto aparece a la izquierda o derecha del icono.
*   Feature: Nuevo control **Show label** con soporte por breakpoint — muestra u oculta el texto en cada dispositivo.

### v1.7.3
*   Bug Fix: Se solucionó el error al asignar atributos a nuevas variaciones mediante update_post_meta.
*   Bug Fix: Registro dinámico automático de términos de taxonomía de atributos en el producto padre al crear la variación.
*   Feature: Nuevo módulo Woo Sheets Sync con sincronización bidireccional entre WooCommerce y Google Sheets.
*   Feature: Conexión OAuth 2.0 via proxy stateless (HMAC-signed).
*   Feature: Sheet→Woo para editar precios, stock, SKU directamente desde Google Sheets.
*   Feature: Woo→Sheet para escribir datos actuales de WooCommerce al sheet.
*   Feature: Crear productos simples y variaciones (combinando atributos) desde el sheet.
*   Feature: Protección contra SKU duplicados.
*   Feature: Cron diario automático y botón Sync Now manual.

### v1.6.4
*   Feature: Added 10 new control groups for FFL Dealer Finder Bricks element.
*   Feature: Added CSS variables (`--ffl-notice-bg`, `--ffl-notice-color`) for checkout notice styling.
*   Fix: Refactored FFL Dealer Finder CSS to use class-based rules and removed `!important` flags.
*   Enhancement: Added `ffl-dealer-card` class to dynamically created dealer buttons.

### v1.6.3
*   Feature: Mapbox address autocomplete in FFL Checkout.
*   Feature: New FFL Dealer Finder Bricks element.
*   Fix: Removed Mapbox web component to prevent bindTo errors.
*   Fix: Prevented fatal errors in standard WooCommerce checkout by guarding database and builder API calls.
*   Enhancement: Refactored wishlist controls to Content tab.

### v1.6.1
*   Feature: Replaced wishlist shortcodes with Native Bricks Elements (Wishlist Button and Wishlist Counter).
*   Fix: Resolved "The plugin does not have a valid header" activation error on new installations by removing the `ffl-zip-build` directory that was causing WordPress extraction mapping issues.

### v1.6.0 — Security Audit
*   **30+ security and performance fixes** from two comprehensive audits across all modules.
*   HIGH: N+1 query caches (matcher, coupon, trending), import DoS limit, XSS escaping, wishlist info disclosure, SVG/CSS field sanitization, AI redirect origin check, API key masking.
*   MEDIUM: Doofinder inline script externalized (CSP), module activation race condition, wishlist cache pre-warming, analytics pagination, updater input sanitization.
*   LOW: OpenAI error masking, CSS injection patterns, nonce wp_die(), headers_sent() guards, UTC consistency, price HTML escaping.
*   Cleanup: Removed unused constants, bumped WP requirement to 6.2, added `Requires Plugins: woocommerce` header.

### v1.5.2
*   Fix: **CRITICAL** — Coupon auto-apply system was broken due to incorrect iteration of grouped actions array. Coupons now apply correctly on cart pages.
*   Fix: Hide meaningless "Limit" and "Order By" fields when the action type is "Apply Coupon".
*   Fix: Add spacing above "Custom Cart Message" field in coupon rule panel for better visual separation.
*   Fix: When AI creates rules with specific products, automatically set quantity limit to match the number of products found.

### v1.5.1
*   Feature: AI chat now fully interactive — step-by-step confirmation before creating any rule.
*   Feature: "Create This Rule" button appears after AI proposes a rule, so the user explicitly approves.
*   Fix: AI no longer asks the user for product IDs — it searches the store automatically and presents results for confirmation.
*   Fix: When multiple products match a search, AI lists them and asks the user to choose.
*   Fix: Specific product IDs found by AI are now correctly populated in the `action_products` field when creating rules.
*   Fix: Animated loading indicator during AI requests so the chat doesn't appear frozen on long operations.
*   Fix: WordPress sidebar "FFL Funnels" menu item now always visible.

### v1.5.0
*   Fix: AI Rule Generator hallucination. Introduced the `search_store` tool allowing the AI to query actual product IDs, category slugs, and attributes.
*   Feature: AI Rule Editing. The AI can now fetch and update existing rules.
*   Feature: Persistent Chat History. The AI chat modal now preserves conversation flow via `localStorage` and includes a "Clear Chat" button.
*   Enhancement: Cleaned up the WordPress Admin Menu by safely removing the duplicate "Dashboard" submenu item under FFL Funnels.

### v1.4.0
*   Feature: AI Rule Generator! Generate Woobooster recommendations automatically using OpenAI and Tavily Web Search.
*   Enhancement: Added fields for OpenAI API Key and Tavily API Key in General Settings.
*   Enhancement: Recursive AI tool loop allows searching real-time web compatibility data before rule generation.

### v1.3.1
*   Fix: Prevent fatal error (Cannot redeclare class/function) when multiple plugin directories exist during updates.

### v1.3.0
*   Format rule lists and admin configuration to use strict design system classes (Tailwind equivalents).
*   Add issue and PR templates (.github/ISSUE_TEMPLATE) to enforce contribution standards.
*   Add `.editorconfig` for formatting unification.
*   Add standard `LICENSE.md` file.

### v1.2.3
*   Fix updater not detecting updates via WP-Cron (moved initialization outside `is_admin()`).
*   Fix potential TypeError in updater by removing strict object type hint in `check_update`.
*   Various rule UI style and template updates.

### v1.2.2
*   UI Style overhaul for the rule form.

### v1.2.1
*   Add rule scheduling — set start/end dates for time-limited rules (promotions, seasonal campaigns).
*   Add search/filter on the rules list page.
*   Add `not_equals` operator for conditions ("Category is not X").
*   Add rule duplicate button (creates inactive copy with conditions and actions).
*   Add sticky save bar on rule form.
*   Improve rule list columns: human-readable condition summaries with operator, resolved term names, and action labels for all source types.
*   Improve exclusion panels: visual distinction between Condition Exclusions (blue) and Action Exclusions (green).
*   Fix `min_quantity` tooltip to clarify it only applies to coupon/cart rules.
*   Fix `specific_product` single-condition rules not found via index lookup.
*   Fix scheduling enforced in both product matcher and coupon auto-apply engine.
*   Bump DB version to 1.7.0 (adds `start_date`/`end_date` columns with safe migration).

### v1.2.0
*   Add Conditional Coupon System — auto-apply/remove WooCommerce coupons when rule conditions match cart contents.
*   Add "Apply Coupon" action type with coupon search and expiry/usage guard.
*   Add "Specific Products" action type for hand-picked product recommendations.
*   Add "Specific Product" condition type to trigger rules for individual products.
*   Add condition-level exclusions: exclude by category, product, or price range.
*   Add minimum quantity threshold per condition for coupon and recommendation matching.
*   Add custom cart notice when a coupon is auto-applied.
*   New `WooBooster_Coupon` class with WC session-based tracking.

### v1.1.1
*   Analytics dashboard overhaul: single-pass queries, trend indicators, donut chart, funnel visualization, product thumbnails.
*   Add expanded date range presets (today, yesterday, 7d, 30d, 90d, year, all-time).
*   Add Revenue Chart to analytics dashboard.
*   Fix updater API notice dismiss.

### v1.1.0
*   Add WooBooster Analytics dashboard — track revenue, conversion, and top rules/products from recommendations.
*   Add JS attribution tracking: intercepts WooCommerce AJAX add-to-cart to tag items from WooBooster recommendations.
*   Add `_wb_source_rule` order line item meta for recommendation attribution persistence.
*   Add add-to-cart counter and conversion rate metrics.
*   Add date range filter with 7d/30d/90d presets.
*   Expose `WooBooster_Matcher::$last_matched_rule` for cross-class context sharing.

### v1.0.22
*   Fix wishlist count badge not updating via AJAX (class mismatch `.ffla-wishlist-count` vs `.alg-wishlist-count`).
*   Fix wishlist empty page showing plain text instead of styled "Return to Shop" block.
*   Fix button title attributes using past-tense toast messages instead of action text.
*   Update Doofinder documentation snippet to match working production code (`window.AlgWishlist.toggle`).
*   Add `[alg_wishlist_button_aws]` shortcode to admin documentation.

### v1.0.21
*   Security audit: fix CSS injection in wishlist color settings (validate with `sanitize_hex_color` pattern).
*   Security audit: fix XSS in wishlist shortcode `icon` attribute (sanitize SVG with `wp_kses`).
*   Security audit: escape `$product->get_name()` in wishlist page shortcode.
*   Add ABSPATH guards to all wishlist include files.
*   Clean up dead code in WooBooster `ajax_delete_all_rules`.
*   Add `.gitattributes` for clean GitHub release zips (exclude dev files).
*   Add GitHub Actions workflow for automated release builds.
*   Remove stale `build/` directory from git tracking.

### v1.0.20
*   Switched to `upgrader_source_selection` to fix plugin folder rename during updates.

### v1.0.17
*   Added `[alg_wishlist_button_aws]` shortcode with text toggles.

### v1.0.15
*   Wishlist JS sync for Doofinder shadow DOM layers.
*   Toast notification improvements.

### v1.0.3
*   Removed global category rules to prevent redundancy.
*   Added "Delete All Rules" bulk action in admin.
*   Improved rule efficiency.

### v1.0.0
*   Initial release.
*   Added WooBooster module with Rules Engine and Smart Recommendations.
*   Added Wishlist module.
*   Added Doofinder Sync module.
*   Implemented modular architecture and GitHub Updater.

## Author

**Alejandro Aruca** — [github.com/aaruca](https://github.com/aaruca)

---
*For internal use by FFL Funnels clients.*
