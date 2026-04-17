# Changelog

All notable changes to FFL Funnels Addons are documented in this file.

## [1.14.0] - 2026-04-14

### Tax Rates — Role-based tax charging
- **New opt-in setting:** Tax Resolver → Settings now exposes a "Tax charges by user role" card with a toggle plus a checklist of every WordPress role on the site, including a special "Guest (not logged in)" row. When the toggle is off (default) every customer is taxed exactly like before — nothing changes for existing installs.
- **When the gate is on:** only the roles that are checked get charged tax at checkout; unchecked roles (and guests, unless Guest is checked) see `$0` tax. Users that have multiple roles pay tax as long as any of their roles is in the checked list.
- **Safe fallback:** if the gate is turned on but nothing is selected, the UI surfaces a red warning and every customer sees `$0` tax — the admin explicitly opted into restriction, so we honor it instead of silently charging everyone.
- **Implementation:** new `Tax_Role_Gate` helper (`includes/class-tax-role-gate.php`) with `is_active()`, `get_allowed_roles()`, `get_role_choices()`, and `should_charge_for_current_customer()`. `Tax_WooCommerce_Integration::filter_matched_tax_rates()` now short-circuits to an empty matched-rate array when the gate is active and the current customer isn't in the allowed list, and clears the synthetic `ffla_runtime_tax_rates` session entry at the same time so stale rates from a previous request can't leak through. Settings key: `tax_role_restrict` (`'0'`/`'1'`) + `taxed_roles` (string[] of role slugs, with `guest` as a pseudo-role for non-logged-in visitors).
- **Default values:** activation seeds `tax_role_restrict = '0'` and `taxed_roles = []` for brand-new installs; existing installs keep their current settings untouched.

## [1.13.0] - 2026-04-14

### Tax Rates — Bring Your Own USGeocoder Key
- **BYOK flow:** With the key empty, Tax Resolver keeps using the shared monthly-refreshed Google Sheet dataset (free, ZIP-level precision). Paste a `usgeocoder_auth_key` to upgrade affected states to live address-level precision via the USGeocoder JSON API. The key is entered once in Tax Resolver → Settings; nothing else to configure.
- **Runtime fallback (critical):** `Tax_Quote_Engine::quote()` now retries failed USGeocoder attempts against the Sheet ZIP dataset when the outcome is `SOURCE_UNAVAILABLE`, `RATE_NOT_DETERMINABLE`, or `INTERNAL_ERROR`. Both attempts land in `trace.fallbackChain` and the fallback result is tagged `sourceVersion = "sheet_fallback"` with an explicit limitations string so the admin audit makes the fallback visible.
- **Coverage reconcile moved out of boot():** The per-request loop that used to rewrite every state's coverage row on every page load is gone. `Tax_Coverage::reconcile_from_settings()` is now invoked on activation, on `Tax_Resolver_DB::install()`, and via `update_option_ffla_tax_resolver_settings` — writes are diffed so only rows whose resolver/status/notes actually changed are touched.
- **restrict_states respected:** With `restrict_states = 1`, only states in `enabled_states` route to `usgeocoder_api`; the rest fall back to `sheet_zip_dataset`. The USGeocoder resolver itself also refuses to hit the paid endpoint for disabled states.

### Tax Rates — Admin UX
- **Mode badge:** New `Sheet Mode (free)` / `USGeocoder Mode (live API)` / `USGeocoder Mode (key invalid)` badge at the top of the USGeocoder card.
- **Reworded help text + external link:** The card now explains both modes, links to usgeocoder.com and calls out the automatic runtime fallback so the admin knows failures won't break checkout.
- **"Test key" button:** New `wp_ajax_ffla_tax_test_usgeocoder` action validates the pending key against the USGeocoder sample address from the official docs and reports `ok` / `network_error` / `http_error` / `empty_payload` / `no_rate` with a specific message. Result is cached in a `ffla_tax_key_validation` transient for 1 hour so the page can show a persistent status without re-hitting the paid API on every reload.
- **Cache invalidation notice:** When `usgeocoder_auth_key`, `restrict_states`, or `enabled_states` change, `Tax_Resolver_DB::flush_address_cache()` truncates the address cache and the settings page surfaces an info notice listing the reasons so the admin understands why cached quotes were dropped. The `ffla_tax_key_validation` transient is cleared whenever the key changes.

### Tax Rates — Usage counter
- **`Tax_USGeocoder_Usage`:** New class tracking monthly API usage (in `ffla_tax_usgeocoder_usage`, capped at 24 trailing months, split into `total`/`success`/`failed`) plus a live rolling 30-day count from `wp_ffla_tax_quotes_audit` filtered on `source_code = 'usgeocoder_api'` + `cache_hit = 0`. Every real HTTP call increments; cache hits never reach the resolver so they can't inflate the counter.
- **API Usage card:** Settings page renders a rolling-30d badge and the last 6 months (total + failed count per month) with a clear note that cached quotes are not counted.

### Tax Rates — Resolver cleanup
- **Coverage status from DB:** Removed hardcoded `SUPPORTED_WITH_REMOTE_LOOKUP` in `USGeocoder_API_Resolver` — per-state rules now drive the response shape.
- **Shared HTTP helper:** Extracted `USGeocoder_API_Resolver::fetch_api()` reused by both `resolve()` and the Test-key AJAX action, returning a normalized `ok/http_code/payload/error/wp_error/body` envelope.
- **`Tax_Resolver_DB::flush_address_cache()`:** New helper returning the number of rows cleared.

## [1.12.0] - 2026-04-14

### Internationalization (i18n)
- **Woo Sheets Sync docs page:** Every literal string in `WSS_Admin::render_docs_page()` is now wrapped with `esc_html_e()` or `wp_kses()` + `sprintf()`, preserving the existing `<strong>`, `<em>` and `<code>` markup while making the whole onboarding/troubleshooting guide translatable.
- **JS helpers:** Added a `t(key, fallback)` helper to `woo-sheets-sync-module.js`, `tax-rates-admin.js` and `woobooster-ai.js`. Every previously hardcoded confirm/alert/status/button label now resolves through `wssDashboard.i18n`, `FflaTaxResolver.i18n` and `wooboosterAdmin.i18n`, which are expanded via `wp_localize_script`.
- **WooBooster AI entity label:** The "Create This Rule/Bundle" button now uses an `fmt()` helper against a translatable `%s` format so the entity label flows from a single localized source for both Rules and Bundles.

### REST API
- **README:** New "REST API" section documenting both the `wss/v1` namespace (products/variations/attributes/batch upsert) and the `ffl-tax/v1` namespace (quote, quote/batch, coverage, health, datasets, admin/sync, admin/audit), their capability requirements (`manage_woocommerce` + `X-WP-Nonce`), and the 60 req/min/IP rate limit on `ffl-tax/v1/quote`.

### Performance
- **Woo Sheets Sync — paginated reads:** `WSS_Google_Sheets::read_range_paginated()` walks the target tab in 2000-row chunks (configurable via the `wss_sheet_read_chunk_size` filter) and stops on the first short chunk. `WSS_Sync_Engine::run()` uses it so very large sheets no longer risk hitting the Sheets API's ~10MB single-range response cap.

### Async
- **Woo Sheets Sync — background Sync Now:** New `WSS_Sync_Job` helper. When Action Scheduler is available (bundled with WooCommerce) the admin "Sync Now" button enqueues a single async `wss_run_sync_job` action and returns a `job_id`; the admin JS polls `wp_ajax_wss_sync_status` every ~1.5s (up to ~6 minutes) with a queued/running progress indicator. Environments without Action Scheduler fall back automatically to the previous synchronous flow.

### Options
- **Legacy key fallback:** New `FFLA_Options` helper (`includes/class-ffla-options.php`) with `get()` / `update()` / `delete()` methods that prefer a canonical `ffla_*` key but transparently read from the legacy key (`wss_*`, `alg_wishlist_*`, …) when the canonical one isn't set. Writes mirror both keys so rollbacks and gradual per-module migrations are painless without a forced data migration.

### Tooling
- **PHP:** Added `composer.json` with dev dependencies on wp-coding-standards/wpcs, phpcompatibility/phpcompatibility-wp, dealerdirect/phpcodesniffer-composer-installer, phpstan/phpstan and szepeviktor/phpstan-wordpress. New `.phpcs.xml.dist` (pragmatic WordPress-Core subset + WordPress.Security / DB / I18n + PHPCompatibilityWP) and `phpstan.neon.dist` (level 5, phpstan-wordpress extension) with a lightweight `tests/phpstan/bootstrap.php` that defines plugin constants for static analysis.
- **JS:** Added `package.json` with ESLint ^8.57 and `.eslintrc.json` tuned for browser + jQuery with `wp`, `jQuery`, `ajaxurl`, `wssDashboard`, `wooboosterAdmin`, `FflaTaxResolver`, `AlgWishlistSettings`, `fflaAdmin` and `WooBoosterTracking` as known globals.
- **CI:** New opt-in `.github/workflows/lint.yml` running PHPCS (PHP 7.4), PHPStan (PHP 8.1) and ESLint (Node 20) on push/PR. Each step is guarded with `|| true` so existing non-compliant code won't block merges while the codebase is incrementally tightened.

## [1.11.0] - 2026-04-14

### Security
- **WooBooster AI (admin):** Assistant responses are HTML-escaped before the limited markdown renderer re-introduces only `<strong>` / `<br>`, so any HTML that slipped past server-side `wp_kses_post` can no longer execute inside the admin modal.
- **Wishlist (frontend):** The "empty wishlist" message is now written via `textContent` on a dedicated DOM node instead of being injected into `innerHTML`, eliminating a theoretical XSS path via hostile translations.

### Changed
- **Updater capabilities:** `maybe_show_token_notice()` and the `ffla_dismiss_api_notice` handler both require `manage_woocommerce` now, matching every other FFLA admin surface (previously `manage_options`).

### Performance
- **WooBooster options:** `woobooster_get_option()` memoizes `woobooster_settings` per request and refreshes its cache on `update_option_woobooster_settings` / `add_option_woobooster_settings`, collapsing repeated reads into a single DB fetch per request.
- **WooBooster Matcher:** New per-request caches for rule rows, conditions and term-slug lookups, plus a bulk `prefetch_rules()` that loads every candidate rule with one `IN(...)` query — removes the N+1 `SELECT`s that previously ran per candidate and per category-exclusion slug.
- **Tax Rates — Dataset Pipeline:** The Google Sheets CSV is now streamed to `wp_tempnam()` via `wp_remote_get([ 'stream' => true, 'filename' => $tmp ])` and parsed line-by-line with `fgetcsv`, grouping rows by state incrementally. Each state's slice is freed immediately after import; large national datasets no longer load into memory.
- **Woo Sheets Sync — Engine:** Replaced `get_posts([ 'posts_per_page' => -1 ])` with a direct `wpdb->get_col()` over `posts`+`postmeta` to list sync-enabled products; both sync directions now call `_prime_post_caches()` once over the full ID set so subsequent `wc_get_product()` calls resolve from the object cache instead of issuing per-product `SELECT`s.
- **Woo Sheets Sync — Orchestrator:** `run_all()` now tracks processed tab names and skips any later group that targets a tab already synced in the same run, recording an explicit `skipped` entry. Avoids duplicate full-sheet reads from misconfigured groups.

### Internationalization
- **PHP module metadata:** All module `get_name()` / `get_description()` strings (Woo Sheets Sync, Tax Rates, Product Reviews, WooBooster, Wishlist, FFL Checkout, Doofinder Sync) and the USGeocoder / Sheet ZIP dataset resolver labels are wrapped in `__(..., 'ffl-funnels-addons')` and translatable.
- **WooBooster admin JS:** New `t(key, fallback)` helper in `woobooster-module.js`; previously hardcoded confirmation dialogs, status messages and button labels (`Deleting…`, `Delete All`, `Importing…`, `Rebuild Now`, `Clear All Data`, `Network error.`, `Please fix the following:`, `At least one action is required in a group.`, `Are you sure you want to delete this bundle?`, etc.) now resolve through `wooboosterAdmin.i18n` populated via `wp_localize_script`.
- **Wishlist frontend JS:** Empty-state text and fallback strings read from `AlgWishlistSettings.i18n` instead of being hardcoded in English.

## [1.9.5] - 2026-04-10

### Added
- **WooBooster — Conditions:** **Entire store (all products)** condition (`__store_all`) so rules and bundles can match every product without choosing a category, tag, or search value. Product matchers always carry the corresponding index key for correct candidate lookup.

### Changed
- **WooBooster — Admin:** Condition type selector defaults to **Entire store** for new rules and new condition rows; removed the inert “Type…” placeholder. Operator and value autocomplete stay hidden for that mode; list view shows a readable “Entire store” label.

## [1.9.4] - 2026-04-12

### Added
- **Product Reviews (Bricks Reviews Rating Badge):** Optional **Hide when no reviews** — no output on the front end when the product has zero approved reviews; Bricks builder still shows a small placeholder while editing.

### Changed
- **Product Reviews:** Minor cleanup in `Product_Reviews_Core` (duplicate docblock / comment).

## [1.9.3] - 2026-04-11

### Added
- **Product Reviews — Order review hub:** Shortcode `[ffla_order_reviews]` and Bricks element **Order reviews hub** for one page with a form per line item (parent products), signed links from email (`?ffla_ro=` or optional pretty `/slug/{token}/`), duplicate detection, billing name/email on submit, Turnstile bypass when the order token and product match.
- **Product Reviews — Email:** Optional **one email per order** (bundle) with `{review_order_url}` and `{product_names_list}`; per-product mode now uses the same signed hub link. New scheduled action `ffla_send_order_review_bundle` (WP-Cron + Action Scheduler group `ffla-product-reviews`).
- **Product Reviews — Admin:** Hold all reviews for moderation, hub page selector, pretty URL slug, optional extra criteria on hub forms, email mode (per product vs bundle), link to moderated comments queue.

### Changed
- **Product Reviews:** Core handles token validation, rewrites, `pre_comment_approved` for global moderation, and stricter `admin-post` flow for token-based submissions.
- **Product Reviews:** Frontend assets also load on the configured hub page and when the hub shortcode is present.
- **Uninstall:** Clears bundle review email hooks alongside existing Product Reviews cleanup.

## [1.9.2] - 2026-04-11

### Fixed
- **Wishlist:** Header / global Bricks counter showed `0` off product and wishlist pages because wishlist JS/CSS were not enqueued there, so `AlgWishlist.init()` never ran. Assets now load on the full WooCommerce storefront (public front). Opt out with `add_filter( 'ffla_wishlist_enqueue_assets', '__return_false' );` if needed.
- **Wishlist:** Bricks **Wishlist Counter** and `[alg_wishlist_count]` now output the server-side item count on first paint (still synced via AJAX after toggles).
- **Product Reviews (Bricks Review Form):** Star rating UI updated all groups to the last row only (JS closure over loop variable); fixed by scoping listeners per star block.
- **Product Reviews (Bricks Review Form):** “Show quality & value” respected Bricks checkbox off-state (`array_key_exists` + `FILTER_VALIDATE_BOOLEAN`); same for collapse-media and login hint.

### Added
- **Product Reviews (Bricks Review Form):** Style tab — container, stars (colors/size/legends), fields (labels, inputs, media box), submit button, and notices (success/error/info).

## [1.9.1] - 2026-04-10

### Security
- **Product Reviews:** Require review form nonce on the dedicated `admin-post` submission path; validate redirect target with `wp_validate_redirect`.
- **Tax REST:** `GET /ffl-tax/v1/coverage` and `GET /ffl-tax/v1/health` now require `manage_woocommerce` instead of being public.

### Fixed
- **Updater:** Register `wp_ajax_ffla_dismiss_api_notice` so dismissing the GitHub API notice actually clears the error transient; enforce `manage_options` on dismiss.
- **Uninstall:** Remove Tax Resolver data using `Tax_Resolver_DB::uninstall()` and correct option keys; clear tax cron hooks; remove Product Reviews options and scheduled hooks (including Action Scheduler group); delete FFL Checkout vendor meta from `wc_orders_meta` when HPOS is enabled.

### Changed
- **Product Reviews:** Honeypot and Turnstile failures on the custom form use redirects with user-visible messages.
- **Product Reviews / Wishlist:** Load frontend assets only where needed (single product, Woo pages, wishlist page, shortcodes, Bricks builder); filters `ffla_product_reviews_enqueue_assets` and `ffla_wishlist_force_enqueue_assets` for edge cases.
- **Wishlist:** Consolidate translations under text domain `ffl-funnels-addons` for listed UI strings.
- **WooBooster:** Write AI request errors to `error_log` only when `WP_DEBUG` is true.

### Added
- **CI:** `.github/workflows/php-syntax.yml` runs `php -l` on all plugin PHP files.

### Maintenance
- **Bricks:** Unified element category label `FFL Funnels` for Wishlist, WooBooster bundle, FFL Dealer Finder, and Product Reviews elements.
- README badge and changelog sections updated for `1.9.1`.

## [1.9.0] - 2026-04-09

### Features
- **New Module: Product Reviews**
  - Added a new modular WooCommerce Product Reviews addon inside FFL Funnels Addons.
  - Added native Bricks elements: Reviews Rating Badge, Reviews List, and Review Form.
  - Added post-purchase review request scheduling after order completion.
  - Added configurable review email subject, heading, and template placeholders.
- **Review UX & Data Enhancements**
  - Added multi-criteria review fields (`Quality` and `Value`).
  - Added verified buyer tagging through WooCommerce purchase checks.
  - Added helpful-vote interactions with nonce validation and anti-abuse rate limiting.
  - Added optional media uploads on reviews (images/videos) with file type and size limits.
- **Security Hardening**
  - Added honeypot and nonce validation on review submissions.
  - Added moderation default for media reviews.
  - Added cleanup flow to delete uploaded media attachments when a review is deleted.
- **Cloudflare Integration**
  - Added optional Cloudflare Turnstile support for review forms.
  - Added server-side Turnstile token verification against Cloudflare siteverify API.
  - Added Product Reviews admin settings for Turnstile toggle + site/secret keys.
- **Admin Improvements**
  - Added review media and helpful columns to the WordPress comments admin list for faster moderation.

### Maintenance
- Bumped plugin version to `1.9.0`.
- Updated README feature set and release notes for Product Reviews module.

## [1.7.3] - 2026-03-28

### Features
- **Woo Sheets Sync:** Nuevo módulo para sincronización bidireccional entre WooCommerce y Google Sheets.
- Conexión OAuth 2.0 via proxy stateless (HMAC-signed).
- **Sheet→Woo:** Edita precios, stock, SKU directamente desde Google Sheets.
- **Woo→Sheet:** Escribe datos actuales de WooCommerce al sheet para mantenerlo actualizado.
- Crear productos simples desde el sheet (product_id=0, variation_id=0).
- Crear variaciones con combinación de atributos desde la columna D (ej. Color: Red | Size: L).
- Protección contra SKU duplicados al crear productos.
- Layout de 12 columnas optimizado con columna de atributos legible.
- Cron diario automático + botón "Sync Now" manual en el panel de administración.
- Dashboard admin con sync log, selector de productos, y link por taxonomía o categoría.
- Página de documentación in-app en inglés para clientes.
- Metabox por producto para activar/desactivar la sincronización individualmente.

### Bug Fixes
- **Woo Sheets Sync:** Se solucionó el error al asignar atributos a nuevas variaciones mediante `update_post_meta`.
- **Woo Sheets Sync:** Registro dinámico automático de términos de taxonomía y opciones de atributos locales en el producto padre al crear la variación.

## [1.6.6] - 2026-03-11

### Bug Fixes
- Fixed address autocomplete suggestion dropdown showing white text on white background — added explicit text/background colors to prevent theme inheritance

## [1.6.5] - 2026-03-11

### Features
- **WooBooster:** Added "Specific Rule" selector to Bricks query loop — pin each loop to a specific rule for Bronze/Silver/Gold-style bundles
- **FFL Dealer Finder:** Added 10 style control groups (~40 controls) on Content tab for full Bricks styling

### Bug Fixes
- **FFL Dealer Finder:** Fixed "No FFLs found" false alert when g-ffl-checkout plugin is also active (scoped all JS DOM queries to Bricks container)
- **FFL Dealer Finder:** Fixed JS not loading on Bricks native checkout (removed hard Mapbox CDN dependency, added fallback asset enqueuing via Bricks template detection)

### Refactoring
- Refactored FFL Dealer Finder CSS from ID-based to class-based selectors, removed `!important` overrides
- Replaced inline styles with CSS classes in FFL Dealer Finder render method

## [1.6.4] - 2026-03-11

### Features
- Added 10 new control groups for FFL Dealer Finder Bricks element
- Added CSS variables (`--ffl-notice-bg`, `--ffl-notice-color`) for checkout notice styling

### Fixes & Refactoring
- Refactored FFL Dealer Finder CSS to use class-based rules (`.ffl-required-notice`, etc.) and removed `!important` flags
- Updated responsive media queries to use new class names
- Updated FFL Dealer Finder element render method to use classes instead of inline styles
- Added `ffl-dealer-card` class to dynamically created dealer buttons

## [1.6.3] - 2026-03-10

### Features
- Replaced Radar.com with Mapbox address autocomplete in FFL Checkout
- Added new FFL Dealer Finder Bricks element

### Fixes & Refactoring
- Removed Mapbox web component in favor of REST API to prevent bindTo errors
- Guarded `bricks_is_builder()` and Database calls to prevent fatal errors in standard WooCommerce checkout
- Refactored wishlist controls by moving them to the Content tab and using native Bricks icon controls

## [1.6.2] - 2026-03-10

### Bug Fixes

- Various bug fixes and improvements

---

## [1.6.1] - 2026-03-08

### Bug Fixes

- Replaced wishlist shortcodes with Native Bricks Elements
- Resolved plugin activation error

---

## [1.6.0] - 2026-02-26

### 🔒 Security — Full Plugin Audit (v1 + v2)

Two comprehensive security audits were performed across all modules. This release addresses every finding.

#### HIGH

- **N+1 `get_term_by()` in matcher.php** — Added static per-request term slug cache to avoid 1000+ queries on sites with many rules
- **Import rules without size limit (DoS)** — Capped rule import at 500 rules per batch
- **XSS in build status dates (admin)** — Escaped all interpolated values inside `sprintf()` with `esc_html()` / `absint()`
- **Info disclosure in wishlist AJAX** — Response now returns only `status` + `count`, not the full product ID array
- **SVG/CSS over-sanitized in wishlist admin** — Field-specific sanitization: `wp_kses()` for SVG, `wp_strip_all_tags()` for CSS, `sanitize_text_field()` for colors
- **URL redirect without origin check (AI JS)** — `window.location.href` now validates `startsWith(window.location.origin)` before redirect
- **API keys displayed in plain text** — Changed API key fields from `type="text"` to `type="password"` with new `render_password_field()` helper

#### MEDIUM

- **Inline `<script>` in Doofinder violates CSP** — Moved price-structure-fix JS to external file enqueued via `wp_enqueue_script()`
- **Race condition in module activation** — `activate_module()` now re-reads `ffla_active_modules` from DB before write
- **N+1 in wishlist page render** — Pre-warm WP object cache with `WP_Query` before product loop
- **`json_decode` without error check (AI chat)** — Added `is_array()` fallback for malformed chat history
- **`$_GET['ffla_checked']` unsanitized** — Now passes through `sanitize_text_field(wp_unslash())`
- **Wishlist query without LIMIT** — Added `LIMIT 500` to prevent memory exhaustion on large wishlists
- **N+1 `wp_get_post_terms()` in coupon matcher** — Added static per-request product term cache
- **N+1 `wp_get_post_terms()` in trending builder** — Replaced loop with single SQL JOIN query
- **Analytics unbounded order query** — Replaced `limit: -1` with paginated 500-order batches

#### LOW

- **OpenAI API errors exposed to frontend** — Now logged server-side with generic user-facing message
- **CSS injection via custom CSS in wishlist** — Strips `@import`, `expression()`, `javascript:`, `url(data:)` patterns
- **Nonce/capability failures silent in rule form** — Changed `return` to `wp_die()` with error message
- **`headers_sent()` guard on wishlist cookies** — Prevents PHP warnings when output already started
- **UTC consistency** — Changed `current_time('mysql')` to `current_time('mysql', true)` in matcher and coupon for consistent timezone handling
- **`$product->get_price_html()` unescaped in wishlist** — Wrapped with `wp_kses_post()`

### 🧹 Cleanup

- Removed unused `FFLA_DB_VERSION` and `FFLA_PLUGIN_DIR` constants
- Bumped `Requires at least` to WordPress 6.2
- Added `Requires Plugins: woocommerce` header

---

## [1.5.2] - 2026-02-24

### Bug Fixes

- Fix coupon auto-apply + UI improvements for rule form
- Fix fatal error on rule save — pass `clean_action_groups` not `clean_actions`
- Restore page access — hide submenu flyout via CSS instead of `remove_submenu_page()`
- Fix Check for Updates button + API notice scope
- Fix `[RULE]` block format + menu via `remove_submenu_page()`

---

## [1.5.1] - 2026-02-22

### 🎯 Features - Interactive AI Chat & One-Click Rule Creation

- **Interactive AI workflow**: Removed auto-rule creation, now fully conversational
  - AI asks clarifying questions when multiple options found ("Which Glock model?")
  - User controls every step, no automatic actions
  - Chat waits for user confirmation before creating rules

- **Smart product filtering**: AI suggestions based on store inventory
  - Web search results filtered to products you actually have
  - Only recommends products confirmed in store
  - Never suggests unavailable items

- **One-click rule creation**: New "Create Rule" button in chat
  - AI generates rule JSON with complete metadata
  - Button appears when AI suggests a rule
  - Click to create rule as inactive draft
  - User reviews in editor before activation

### 🔧 Technical - Backend & Frontend Improvements

- **New AJAX endpoint**: `woobooster_ai_create_rule()`
  - Accepts rule data from frontend
  - Creates rules via existing logic
  - Returns rule ID and editor URL

- **Enhanced message parsing**: Frontend now detects rule suggestions
  - Finds `[RULE]...[/RULE]` blocks in AI messages
  - Extracts JSON rule data automatically
  - Renders "Create This Rule" button with data

- **Improved CSS styling**
  - New button styles matching design system
  - Info message type for feedback
  - Proper spacing and typography

### 🐛 Bugs Fixed

- **WordPress sidebar menu now always visible**
  - Fixed CSS selector to ensure "FFL Funnels" menu never hidden
  - Module dropdowns work correctly

### 📋 Known Improvements Over v1.5.0

- ✅ No more auto-rule creation
- ✅ User controls each step
- ✅ Only suggests products in inventory
- ✅ One-click rule creation from chat
- ✅ Safe draft-based workflow

## [1.5.0] - 2026-02-22

### 🎯 Features - AI Chat Assistant Complete Rewrite

- **Multi-turn tool orchestration**: Proper while-loop supports 8+ sequential tool calls with parallel execution
  - Previous: Only first tool call executed per turn (blocking search_store → search_web chains)
  - Now: Full sequential and parallel tool support with up to 8 turns

- **FFL-specific domain knowledge**: Enhanced system prompt
  - Includes firearms terminology, caliber compatibility, holster types, optics mounting standards
  - Caliber/caliber mappings, product type categories
  - Better reasoning about cross-sell/upsell opportunities for FFL stores

- **Smart tool workflow**: AI orchestration improvements
  - Search store for products → Search web for compatibility → Create rule with real products
  - Tool step feedback: Shows "Searching store for holsters...", "Searching web for compatibility...", etc.

- **Draft rule creation**: Security & review-focused
  - AI-generated rules now created as **inactive (status=0)** by default
  - Store owners must manually activate after review
  - Reduces risk of incorrect recommendations going live immediately

- **Direct editor redirect**: Improved UX
  - Rules redirect to edit form instead of full page reload
  - Faster iteration and adjustment workflow

- **Better UX feedback**: Tool step indicators
  - Visual icons for each tool (search store, search web, get rules, create rule)
  - Shows intermediate progress during long operations
  - Reduces user confusion during multi-step AI requests

### 🔧 Technical - Backend Refactoring

- **Proper conditions/actions saving** (Bug fix)
  - **Previous bug**: `conditions[]` and `actions[]` arrays were silently ignored during create/update
  - Rules only saved main table but not child tables (`wp_woobooster_rule_conditions`, `wp_woobooster_rule_actions`)
  - Now: Calls `WooBooster_Rule::save_conditions()` and `WooBooster_Rule::save_actions()` correctly

- **Refactored `ajax_ai_generate()`**: ~400 → ~900 lines with proper abstraction
  - Extracted tool execution into separate methods: `ai_tool_search_store()`, `ai_tool_search_web()`, `ai_tool_get_rules()`, `ai_tool_create_rule()`, `ai_tool_update_rule()`
  - Separated system prompt building: `build_ai_system_prompt()`
  - Separated tool schema definition: `get_ai_tools()`
  - Cleaner error handling with defensive checks

- **Improved error handling**
  - Defensive `is_object()` checks on transient data
  - Better error messages for OpenAI/Tavily API failures
  - Proper handling of rate limits and connection errors

### 🔒 Security

- **XSS vulnerability fixed**
  - User messages now use `textContent` (no HTML injection possible)
  - Assistant messages use `innerHTML` but pre-escaped by server with `wp_kses_post()`
  - No more direct string concatenation in DOM

### 🎨 UI/UX

- **Improved suggestion prompts**: FFL-context examples
  - "Recommend holsters for the Glock 19"
  - "Cross-sell safety gear with 9mm ammo"
  - "Suggest optics for AR-15 rifles"
  - "Cleaning kits & cases for shotguns"
  - (Previous: Very specific firearm models only)

- **Sparkle AI icon**: Better visual identity
  - Replaced generic dollar sign ($) icon
  - New icon better represents "AI" / "magic" / "automation"

- **Clear Chat button**: Added to modal header
  - One-click conversation reset
  - Maintains focus on current task

- **System message styling**
  - Success messages: Green background with checkmark-like styling
  - Error messages: Red background for visibility
  - Better visual distinction from chat messages

### 📦 Code Quality

- **Removed inline styles in JS**: All moved to CSS
  - Eliminated `style.opacity`, `style.cursor`, `style.height` manipulations
  - Cleaner JavaScript, maintainability improved

- **New CSS utilities**: Added to `woobooster-module.css`
  - `.wb-ai-steps` and `.wb-ai-step`: Tool step container and items
  - `.wb-ai-system-msg`: System message styling
  - `.wb-ai-modal__clear`: Clear button styling
  - `.wb-ai-modal__header-actions`: Header action container

### 🚀 Performance

- **No performance regression**
  - AI operations are user-initiated (admin-only)
  - Tool loop max 8 turns prevents infinite loops
  - Web search optional (Tavily API) can be disabled to reduce latency

### 📋 Known Limitations

- Rules created from AI are simplified:
  - Single condition (can be extended manually)
  - Single action (can be extended manually)
  - Multi-condition/AND-OR logic requires manual UI

- Web search (Tavily API):
  - Optional but recommended for compatibility queries
  - Requires Tavily API key in settings
  - Rate limits apply (5 requests/month on free tier)

- Chat history:
  - Stored only in browser localStorage (ephemeral)
  - Limited to last 20 messages to prevent bloat

## [1.3.1] - Earlier Release

(See GitHub releases for earlier changelog entries)

---

## Versioning

This project follows [Semantic Versioning](https://semver.org/):
- **MAJOR** version for breaking changes
- **MINOR** version for new features
- **PATCH** version for bug fixes
