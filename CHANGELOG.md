# Changelog

All notable changes to FFL Funnels Addons are documented in this file.

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
