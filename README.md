# FFL Funnels Addons

**Custom addons and integrations for FFL Funnels WooCommerce stores.**

![Version](https://img.shields.io/badge/version-1.20.0-brightgreen.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.2+-blue.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0+-violet.svg)
![PHP](https://img.shields.io/badge/PHP-7.4+-green.svg)

## Features

This plugin is a modular suite of tools designed to enhance FFL Funnels stores. It includes:

### 1. WooBooster Module
An intelligent product recommendation engine that goes beyond simple "related products".
*   **AI Rule Generator:** Create robust recommendation rules using natural language. Choose the **LLM provider** in WooBooster settings (OpenAI, DeepSeek, or NVIDIA NIM), optional model override, and **thinking mode** where supported. **Tavily** remains optional for web search; SnapFind is unrelated to this feature.
*   **Targeted Rules:** Create specific recommendation rules based on Categories, Tags, and Attributes (e.g., recommend specific holsters for Glock 19).
*   **Smart Recommendations:** Automatically display "Bought Together", "Trending", "Recently Viewed", and "Similar Products" without manual curation. WB Settings includes **index diagnostics** (orders in window, multi-line vs single-line orders) and filterable order statuses for co-purchase / trending builds.
*   **High Performance:** Uses custom index tables and aggressive caching to ensure zero impact on page load speed.
*   **Bricks Integration:** Fully compatible with Bricks Builder via **WooBooster Recommendations** (rules-based) and **WooBooster Smart Recommendations** (pick one Smart strategy with fallbacks, no rule required). Smart loops roll up attribution to a single **Smart (all)** row in WooBooster analytics.

### 2. Wishlist Module
A lightweight wishlist implementation optimized for performance.
*   Item toggling via AJAX.
*   Bricks Builder integration (with native elements: Button and Counter).
*   Guest wishlist support.
*   Doofinder shadow DOM integration.
*   **SnapFind (Typesense):** when the SnapFind search plugin is active, wishlist heart buttons on search results; optional **ranking boost** for wishlisted products (off by default; enable in Wishlist settings). See **FFL Funnels → Wishlist → Documentation** for the optional `wishlist_count` index field.

### 3. FFL Checkout Module
A smart, compliance-focused checkout flow for firearms.
*   **Mapbox Integration:** Replaces default address fields with ultra-fast Mapbox address autocomplete.
*   **Dealer Selection:** Specialized FFL Dealer selection step injected into the standard WooCommerce checkout.
*   **Conditional Logic:** Automatically shows or hides FFL-specific checkout steps based on what is in the user's cart.

### 4. FFL Dealer Finder (Bricks Element)
A visual element for Bricks Builder to help customers locate nearby FFL dealers before checkout.
*   **Interactive Search:** Search by Zip Code or City to find registered FFL dealers nearby.
*   **Extensive Customization:** 10+ control groups for typography, colors, layouts, icons, and more.
*   **Dynamic Data:** Fetches live dealer data points to display in a customized interface.

### 5. Doofinder Sync
*   Automatically injects product metadata for Doofinder search indexing.
*   Ensures your search engine always has the latest product data.

### 6. Woo Sheets Sync
*   Bidirectional synchronization between WooCommerce inventory and Google Sheets.
*   OAuth 2.0 connection.
*   **Multiple sheet tabs:** configure groups (tab name + products, categories, and tags per tab); the same product can sync to more than one tab; Sheet→Woo conflicts use **last tab in list wins** when the same variation appears in multiple tabs.
*   Edit prices, stock, and SKU directly from Google Sheets.
*   Create simple products and variations from the sheet.

### 7. Tax Address Resolver
US sales tax resolution for WooCommerce using live USGeocoder API lookups (JSON), with optional legacy local sheet tooling still available.
*   **Live API mode:** Checkout and Quote Lookup can resolve from USGeocoder in real time.
*   **Legacy sheet mode (optional):** Existing Google Sheet local dataset flow can remain as fallback if no API key is configured.
*   **WooCommerce runtime taxes:** Applies resolved taxes directly in cart and checkout.
*   **State controls:** Limit the resolver to only the states your store uses, and purge local datasets when a state is removed from selection.
*   **Admin tooling:** Includes Quote Lookup, Coverage Matrix, Datasets, Audit Log, and Settings screens.
*   **Cleanup tool:** Includes a one-click button to delete old legacy local tax database rows after migrating to USGeocoder.

### 8. Product Reviews
Advanced WooCommerce product reviews with native Bricks elements and post-purchase review workflows.
*   **WooCommerce tab (optional):** In **FFL Funnels → Product Reviews**, enable **Replace WooCommerce reviews tab with FFL form** to use the advanced list and form inside the standard product **Reviews** tab (no duplicate Bricks blocks required).
*   **Bricks Native Elements:** Rating Badge, Reviews List, Review Form, and **Order reviews hub** under the **`FFL Funnels`** element category (with partial star styling and style controls).
*   **Post-Purchase Requests:** Schedules review reminders after order completion — per product or **one bundled email** with a signed link to a hub page (`[ffla_order_reviews]`).
*   **Review Enrichment:** Multi-criteria fields (quality/value), verified-buyer tagging, and helpful votes.
*   **Media Reviews:** Optional image/video uploads on reviews with moderation safeguards.
*   **Cloudflare Turnstile:** Optional bot protection with server-side token verification.
*   **Admin Moderation UX:** Extra review media/helpful columns in WordPress comments list.
*   **Reviews Rating Badge:** Optional control to hide the badge when a product has no reviews yet.

## Installation

1.  Download the `ffl-funnels-addons.zip` file from the [Releases](https://github.com/aaruca/ffl-funnels-addons/releases) page.
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
2.  Toggle the switches for the modules you want to use (e.g., WooBooster, Wishlist).
3.  Click the "Settings" button on active cards to configure specific options.

### Tax Address Resolver
1.  Go to **FFL Funnels > Tax Resolver > Settings**.
2.  Set **USGeocoder Auth Key** to enable live API mode.
3.  Optionally enable **Limit resolver to selected states** and choose only the states your store uses.
4.  Save settings.
5.  Use **Quote Lookup** to verify results before testing in WooCommerce checkout.
6.  (Optional) If you are fully migrated, use **Delete Old Tax Database** in Settings to purge old legacy local tax data.

Notes:
*   If no USGeocoder key is configured, the legacy local-sheet flow remains available.
*   Removing a state from the selected list deletes its local imported dataset in legacy mode.
*   The REST quote endpoint is admin-only.

### WooBooster Rules
1.  Go to **FFL Funnels > WooBooster > Rules**.
2.  Click **Add Rule**.
3.  **Conditions:** Define *when* this rule applies (e.g., "Product Category is Firearms").
4.  **Actions:** Define *what* to show (e.g., "Show products from Category: Ammo" OR "Show Related Products from Attribute: Caliber").
5.  **Priority:** Rules are processed top-to-bottom. The first matching rule wins.

## Requirements

*   WordPress 6.2 or higher
*   WooCommerce 8.0 or higher
*   PHP 7.4 or higher
*   (Optional) Bricks Builder for visual layout customization

## REST API

All FFL Funnels Addons REST endpoints are registered under site-local
namespaces and require the `manage_woocommerce` capability with a
standard WordPress REST nonce in the `X-WP-Nonce` header. They are
intended to be consumed from the same site (admin tooling, Sheet→Woo
flow, dealer integrations you write yourself) and are not public APIs.

### Woo Sheets Sync — `wss/v1`

Private endpoints used by the admin JS, the Sheet→Woo import flow, and
any site-local integrations that need to create or update Woo products
programmatically.

Base URL: `https://<your-site>/wp-json/wss/v1/`

| Method | Endpoint                  | Capability          | Purpose                                                              |
| ------ | ------------------------- | ------------------- | -------------------------------------------------------------------- |
| POST   | `/products/upsert`        | `manage_woocommerce`| Create or update a simple product (by `product_id` or `sku`).        |
| POST   | `/variations/upsert`      | `manage_woocommerce`| Create or update a variation (requires `parent_id`).                 |
| POST   | `/attributes/upsert`      | `manage_woocommerce`| Resolve a global attribute (`pa_*`) and ensure a term exists/reused. |
| POST   | `/batch/upsert`           | `manage_woocommerce`| Array of `{kind, payload}` — max 200 items per call.                 |

Example — ensure a `pa_manufacturer` term:

```json
POST /wss/v1/attributes/upsert
{ "label": "Manufacturer", "value": "Demo Manufacturer" }
```

### Tax Address Resolver — `ffl-tax/v1`

Used by WooCommerce's cart/checkout integration and the admin dashboard
for quoting sales tax against the local dataset + optional external
resolvers. `/quote` is rate-limited to 60 requests per minute per IP
(via object cache when available, transients as a fallback).

Base URL: `https://<your-site>/wp-json/ffl-tax/v1/`

| Method | Endpoint          | Capability          | Purpose                                                                                         |
| ------ | ----------------- | ------------------- | ----------------------------------------------------------------------------------------------- |
| POST   | `/quote`          | `manage_woocommerce`| Resolve the total sales-tax rate and breakdown for a single address (rate-limited, 60/min/IP). |
| POST   | `/quote/batch`    | `manage_woocommerce`| Resolve a batch of addresses at once. Body: `{ "addresses": [ ... ] }`.                         |
| GET    | `/coverage`       | `manage_woocommerce`| State coverage matrix (which states have local datasets, resolver priority, freshness).         |
| GET    | `/health`         | `manage_woocommerce`| Datasets freshness, resolver health, and 24h usage stats.                                       |
| GET    | `/datasets`       | `manage_woocommerce`| Last 50 dataset versions loaded from the sheet source.                                          |
| POST   | `/admin/sync`     | `manage_woocommerce`| Re-run the sheet→local dataset sync (same operation as the admin "Sync Sheet Data" button).   |
| GET    | `/admin/audit`    | `manage_woocommerce`| Recent quote audit entries. Supports `?limit=1..100` (default 25) and `?state=XX`.             |

Example — quote a single address:

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

OAuth debug output is disabled by default. To enable it temporarily, add
these constants to `wp-config.php` — file logging is opt-in so nothing is
written to `wp-content/uploads` unless you explicitly ask for it:

```php
define('WSS_OAUTH_DEBUG', true);       // error_log only
define('WSS_OAUTH_DEBUG_FILE', true);  // also write wp-content/uploads/wss-logs/
```

## Changelog

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

**Ale Aruca**

---
*For internal use by FFL Funnels clients.*
