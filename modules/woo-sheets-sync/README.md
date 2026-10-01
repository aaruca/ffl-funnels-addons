# Woo Sheets Sync

Keeps WooCommerce products and a Google Sheet in step, in both directions. Staff edit prices, sale prices, SKUs and stock in the sheet; WooCommerce writes its current values back, including stock sold through orders. Built for stores that manage inventory in a spreadsheet. Module ID: `woo-sheets-sync`, off until it is switched on in **FFL Funnels → Dashboard**.

You need a Google Sheet and a way for the site to reach it: a Google Cloud **service account** with the Google Sheets API enabled (recommended), or the **Connect with Google** OAuth flow, which is only offered when `WSS_PROXY_SECRET` is defined in `wp-config.php`. PHP's OpenSSL extension is used to sign Google requests and to encrypt stored credentials.

> **Read this first.**
>
> - **Removing a tab group on the WSS Dashboard also deletes that tab from the Google Sheet** (unless it is the spreadsheet's only tab).
> - The module owns columns A–L and row 1 of every synced tab. It rewrites the header row whenever it differs, so do not rename or reorder those columns.
> - A product that manages stock should live in **one** tab only. See [Several tabs](#several-tabs).

## What it does

- **Two-way sync** of SKU, regular price, sale price, stock quantity, stock status and "manage stock", one row per simple product or per variation.
- **Several tabs**, each with its own products: picked one by one, by category, by tag, or all at once.
- **Stock-aware conflict handling**: a sale in WooCommerce is not undone by an older quantity in the sheet; if both sides changed, WooCommerce wins.
- **Real-time stock push**: orders, cancellations and refunds that change stock update the sheet right away instead of at the next sync.
- **Create from the sheet**: a new row creates a simple product, or a variation under an existing variable product.
- A daily automatic sync at a time you choose, a **Sync Now** button, and a sync log.
- Internal REST endpoints (`wss/v1`) for creating or updating products, variations and attribute terms.

## Setup

1. Switch the module on in **FFL Funnels → Dashboard**.
2. Create the service account (Google Cloud Console):
   1. Create a project, or pick an existing one.
   2. **APIs & Services → Library**: search for **Google Sheets API** and enable it.
   3. **APIs & Services → Credentials → Create credentials → Service account**. Any name; no roles are needed.
   4. Open the service account, **Keys** tab → **Add key → Create new key → JSON**, and download the file.
3. Go to **FFL Funnels → Woo Sheets Sync → WSS Settings**. Paste the whole JSON file into **Service account JSON key** and click **Save Settings**. The service account email appears in the card.
4. Open the Google Sheet, click **Share**, paste that email, set the role to **Editor**, untick **Notify people**, and share. Without this the sync fails with a permission error from Google.
5. Back in WSS Settings, paste the sheet link into **Google Sheet URL or ID**, check **Automatic Sync Time** and **Real-time stock push**, and click **Save Settings**.
6. Go to **WSS Dashboard → Sheet tab groups**. Set **Tab name (Google Sheet)** and add products (search, **Add by category**, **Add by tag** or **Link all**). Use **Add sheet tab** for more tabs. A tab that does not exist yet is created at the first sync.
7. Click **Sync Now**. The first run writes the header row and one row per product or variation. Check the **Sync Log** at the bottom of the Dashboard.

Other ways to connect:

- **Key in `wp-config.php`** instead of the database: `define('WSS_SERVICE_ACCOUNT_JSON', '…the JSON…');` or `define('WSS_SERVICE_ACCOUNT_FILE', '/absolute/path/key.json');`. Either one takes precedence over a pasted key, and the Settings page then hides the paste field.
- **OAuth (Connect with Google)**: shown only when `WSS_PROXY_SECRET` is defined, no service account is active, and no constant is set. Click **Connect with Google**, sign in with an account that can edit the sheet, and approve. Consent runs through the module's OAuth proxy; token refreshes go straight to Google. OAuth connections can need re-authorizing; a service account does not.

## Settings

### WSS Settings

| Setting | What it does | Default |
|---|---|---|
| Service account JSON key | Paste the downloaded key. It is validated (`"type": "service_account"`, `client_email`, `private_key`), stored encrypted and never shown again. Once saved the field becomes **Replace service account key (paste new JSON)**. | Empty |
| Remove this service account and revert to OAuth. | Shown when a pasted key is active. Deletes the key on save. | Off |
| Connect with Google / Disconnect | OAuth alternative (see above). **Disconnect** revokes the token at Google and deletes it and the stored client credentials. | Not connected |
| Google Sheet URL or ID | The full sheet link or just its ID; the ID is taken from `/spreadsheets/d/<id>/`. Every tab group uses this one spreadsheet. | Empty |
| Automatic Sync Time | Time of the daily sync, in the site timezone. Saving reschedules it. | 02:00 |
| Real-time stock push | Push stock changes from orders, refunds and cancellations to the sheet immediately. | On |

### WSS Dashboard → Sheet tab groups

Each change is saved as you make it.

| Setting | What it does | Default |
|---|---|---|
| Tab name (Google Sheet) | Tab this group syncs. Case-sensitive; `[ ] * / \ ? :` are removed. Saved automatically as you type. | `Inventory` for the first group, `Sheet tab N` for added ones |
| Link all | Replaces the group's rules with every product published at that moment. Products published later are not added. | — |
| Clear tab rules | Removes all products, categories and tags from the group. | — |
| Add by category / Add by tag | Adds a rule: every published product in that category (subcategories included) or tag, worked out at each sync. | — |
| Individual products | Search published products by name and add them. | — |
| Remove tab group | Deletes the group **and the tab in the Google Sheet**. At least one group must remain. | — |

### Product edit screen → Google Sheets Sync box

| Setting | What it does | Default |
|---|---|---|
| Sync with Google Sheets | Sets only the product's `_wss_sync_enabled` flag. Which products sync is decided by the tab groups, and the next change to any group resets the flag to match them. Use the Dashboard instead. | Current flag |

## How it works

### Sheet layout

| Column | Header | In the sheet |
|---|---|---|
| A | `product_id` | Parent product ID. Set it by hand only on new variation rows. |
| B | `variation_id` | Variation ID; same as A for simple products. Leave empty or `0` on new rows. |
| C | `product_name` | Parent product name. Required on new rows; not copied to WooCommerce for existing products. |
| D | `attributes` | `Label: Value \| Label: Value`. Used to create or match variations. |
| E | `sku` | Editable. |
| F | `regular_price` | Editable. |
| G | `sale_price` | Editable. `0` removes the sale price. |
| H | `stock_qty` | Editable. Blank when the item does not manage stock. |
| I | `stock_status` | `instock`, `outofstock` or `onbackorder`. |
| J | `manage_stock` | `TRUE` or `FALSE`. |
| K | `woo_updated_at` | Written by the module when it changes the row. |
| L | `sheet_updated_at` | Not used; always empty. |

Values are written with Google's "user entered" mode, so Google parses them as if typed. Format the `sku` column as plain text if SKUs start with zeros.

### Each sync

Each tab runs in two passes:

1. **Sheet → WooCommerce.** For every row of a product in the tab's group, the sheet is compared with WooCommerce and differences are applied (rules below). New rows create products. Rows of products not in the group are skipped.
2. **WooCommerce → sheet.** For every product in the group, a row is built from WooCommerce. If SKU, prices, stock quantity, stock status or manage stock differ from the sheet, the whole row (A–L) is rewritten; products with no row yet are appended at the bottom. A name or attribute change alone does not rewrite a row.

Rows are never deleted. Taking a product out of a group leaves its rows in place; they are skipped from then on.

### What wins

- **SKU, regular price, sale price, manage stock:** the sheet wins whenever it differs.
- **Stock quantity** (items that manage stock): the module remembers the quantity both sides last agreed on, per variation.
  - Only the sheet changed → applied to WooCommerce.
  - Only WooCommerce changed (an order, a refund) → kept, and written to the sheet.
  - Both changed → **WooCommerce wins**; the log records "Stock conflict: Sheet and Woo both changed since last sync; Woo wins…".
  - First sync of a row (nothing remembered yet) → the sheet wins if it differs.
- **Stock status:** for items that don't manage stock, the sheet wins. For items that manage stock, WooCommerce's status is kept and written back; changing only the status cell is not applied after the first sync. Change the quantity instead.
- **Empty cells change nothing** in WooCommerce. If WooCommerce has a value, the next write-back fills the cell again. To remove a sale price, enter `0`.
- A negative price, or a SKU that already belongs to another product, is refused for that row and logged. A stock status other than the three above is ignored.
- **Attributes (D)** on an existing variation row are applied only when something else in the row is applied too.

### Creating products from the sheet

- **Simple product:** leave A and B empty (or `0`), fill C, and ideally E (SKU), F and H–J. If the SKU already belongs to a simple product, that product is linked and updated with the row's name, prices and stock instead of creating a duplicate; a SKU used by a variation or variable product is refused. Otherwise a new published simple product is created.
- **Variation:** put the parent variable product's ID in A, leave B empty, and give a SKU, the attribute set in D, or both. When D is filled it must name every attribute the parent uses for variations. A SKU or attribute match under that parent links and updates the existing variation. New values are added to the parent: new terms on existing global attributes, new options on custom attributes. Global attributes themselves are never created. The parent must be in the tab's group.
- After the run the new IDs are written to A and B, with a timestamp in K. If Google rejects that write, the next run recognises the products it already created instead of creating them again, as long as the row was not edited in between.
- A simple product created from the sheet is **not added to the tab group**. Unless a category or tag rule covers it, it is skipped from the next sync on. Add it to the group on the Dashboard.

### Rows that point to the wrong product

If B no longer matches the product in A (deleted variation, wrong parent, a typo), the module repairs the row without guessing: a simple product is relinked to A; a variation is relinked by SKU, then by its complete attribute set, and recreated only when the row has a SKU or complete attributes. Anything ambiguous is logged and left for you. Two rows pointing at the same product are not both linked.

### Several tabs

- Tabs run top to bottom as listed on the Dashboard. A second group with the same tab name (any case) is skipped in that run.
- A product in two tabs gets a row in each. When the tabs disagree, the later tab's value ends up in WooCommerce; the earlier tab is not corrected and keeps re-applying its value at the start of every run.
- The remembered stock quantity is per variation, not per tab. A stock-managed product in two tabs can have a sale undone and its tabs never settle. Keep stock-managed products in one tab.
- If one tab fails (for example Google refuses the read), the run stops; later tabs wait for the next run.

### Real-time stock push

- Runs when WooCommerce reduces stock for an order, restores it on cancellation, or restocks a refunded item. It does not depend on which checkout placed the order. Stock edited by hand in the product screen goes at the next sync.
- Only for products that are in a tab group and already have a row from an earlier full sync. A variation in several tabs is pushed to the last tab only.
- At the end of the request it checks that column B of the remembered row still holds that variation, then writes H and I for all of the order's items in one call. A moved row is skipped and fixed at the next sync. Failures are logged as "Real-time push: …" and never stop the checkout.

### Scheduling and Sync Now

- The daily sync uses WP-Cron (`wss_daily_sync`), so it needs site traffic or a server cron calling WP-Cron.
- **Sync Now** runs in the background through Action Scheduler (bundled with WooCommerce) and the page polls for about six minutes. Without Action Scheduler it runs in the request itself.
- Large tabs are read 2,000 rows at a time. Google requests time out after 60 seconds and are retried on rate limits, server errors and network errors (three tries for reads, two for writes).

## Where it shows up

- **FFL Funnels → Woo Sheets Sync → WSS Settings**: connection, sheet, schedule, real-time push, and a list of the internal API endpoints with a **Test API now** button (it creates or reuses a dated "Demo Manufacturer" term on a global Manufacturer attribute).
- **… → WSS Dashboard**: **Sync Now**, last sync totals and **Per-tab results**, next scheduled sync, **Sheet tab groups**, and the **Sync Log** (latest 50 entries, **Clear Log**).
- **… → WSS Docs**: a short in-admin guide.
- **Product edit screen**: the **Google Sheets Sync** box with the tabs that include the product, its variation count, and **Last synced** (set when the sheet changes the item; it stays empty on variable products because the date is stored on their variations).
- Nothing is shown to shoppers.

## Data and uninstall

| Stored | Where |
|---|---|
| Settings, tab groups, encrypted service account key and OAuth client credentials | Option `wss_settings` |
| OAuth tokens (encrypted, not autoloaded) | Option `wss_google_tokens` |
| Last sync result; row positions for the real-time push | Options `wss_last_sync`, `wss_row_map` |
| Sync log | Table `{prefix}wss_log` |
| Per product/variation | Meta `_wss_sync_enabled`, `_wss_last_synced`, `_wss_snap_woo`, `_wss_snap_sheet`, and while a new row is unconfirmed `_wss_unconfirmed_sheet_row`, `_wss_unconfirmed_sheet_fingerprint`, `_wss_unconfirmed_sheet_context` |
| Short-lived | Transients `wss_sa_access_token`, `wss_oauth_state_{user_id}`, `wss_sync_job_{id}` (10 minutes); option `_wss_variation_upsert_lock_{parent_id}` during a variation upsert |

- Encryption keys are derived from `AUTH_KEY`. If `AUTH_KEY` changes, the stored key and tokens can no longer be read; paste the key or reconnect again.
- **Switching the module off** stops the daily schedule and the real-time push. Everything else is kept.
- **Uninstalling the plugin** while the module is on deletes `wss_settings`, `wss_google_tokens`, `wss_last_sync`, `wss_row_map` and older legacy keys, the OAuth and token transients, the daily schedule, every `_wss_sync_enabled` meta, and the `uploads/wss-logs/` debug folder. The log table, the other product meta, products created from the sheet, and the Google Sheet itself stay. The OAuth token is not revoked at Google. If the module is off at uninstall, nothing of it is removed.

## Troubleshooting

| Problem | What to do |
|---|---|
| "No Google Sheet ID configured." | Fill **Google Sheet URL or ID** in WSS Settings. |
| "Not connected to Google. Configure a service account or connect with Google first." (Sync Now), or "Not connected to Google. Please authorize first." (Dashboard after a nightly run) | No usable credentials. Paste the service account key again; this is also what happens after `AUTH_KEY` changed or the site moved with different salts. |
| Permission error from Google | Share the sheet with the service account email as **Editor**. |
| Google says the Sheets API is disabled or has not been used | Enable **Google Sheets API** in the service account's Cloud project. |
| "That JSON is not a service account key…" / "…not valid JSON." / "…missing client_email or private_key." | Paste the whole downloaded key file, not an OAuth client file. |
| A product never appears in the sheet | Check it is in that tab's group (category/tag rules only cover published products), then **Sync Now**. |
| A product created from the sheet stopped syncing | Add it to the tab group (see [Creating products](#creating-products-from-the-sheet)). |
| A sheet edit was not applied | Only columns E–J are compared with WooCommerce; empty cells are ignored; stock follows [What wins](#what-wins). Check the log for a refusal. |
| Stock in two tabs keeps changing | Keep the product in one tab. |
| "A variation without a SKU must include its complete attribute set." / "Attribute "…" is unknown, ambiguous…" | Give a SKU, or every variation attribute of the parent with labels matching the parent's attributes. |
| "Cannot create variation for row N: product #X is unavailable or is not variable." | Column A must be a variable product ID. |
| "Product not found." in the log | A product in the group was deleted. Remove it from the group and its rows from the sheet. |
| Header row or a renamed column keeps changing back | Expected: row 1 is rewritten to the 12 module headers. |
| Real-time push does nothing | Run one full sync first, keep **Real-time stock push** on, and make sure the product is in a group. |
| "Sync status was lost. It may still be running in the background — reload to see results." | Reload the Dashboard later; the job may still be running. |
| The nightly sync does not run, or **Next scheduled sync** is in the past | WP-Cron is not being triggered. Make sure the site's cron (or a server cron calling `wp-cron.php`) runs. Saving WSS Settings reschedules the event. |
| OAuth connection problems | Define `WSS_OAUTH_DEBUG` (and optionally `WSS_OAUTH_DEBUG_FILE`) and check the PHP error log. |

## For developers

### Hooks and constants

- **Filter** `wss_sheet_read_chunk_size` (`int` rows per read, default 2000; `string` tab name). Clamped to 100–5000.
- **Cron:** `wss_daily_sync` (daily, at the **Automatic Sync Time**). **Action Scheduler:** `wss_run_sync_job` (arg: job ID, group `wss-sync`) for Sync Now.
- **Constants:** `WSS_SERVICE_ACCOUNT_JSON`, `WSS_SERVICE_ACCOUNT_FILE` (service account key, in that order of precedence over the pasted key), `WSS_PROXY_SECRET` (shared secret with the OAuth proxy; without it OAuth is off), `WSS_OAUTH_DEBUG` (OAuth debug to the PHP error log; also on when `WP_DEBUG` and `WP_DEBUG_LOG` are both on), `WSS_OAUTH_DEBUG_FILE` (also write `uploads/wss-logs/wss-oauth-debug.log`).

### REST endpoints

`/wp-json/wss/v1/`, all `POST`, capability `manage_woocommerce`, JSON body (the handlers read the JSON body only). Cookie-authenticated calls need an `X-WP-Nonce` header.

| Endpoint | Body | Result |
|---|---|---|
| `/products/upsert` | `name` (required), `sku`, `regular_price`, `sale_price`, `stock_qty`, `stock_status`, `manage_stock`, `attributes` | Updates the simple product with that SKU, or creates a published one. `{status: created\|existing, data: {product_id, variation_id, action}}` |
| `/variations/upsert` | `parent_id` (required), `sku` and/or `attributes`, plus the price and stock fields | Updates the variation matched by SKU or complete attributes under the parent, or creates it. Same response shape. |
| `/attributes/upsert` | `value` (required, max 200 characters), `taxonomy` (`pa_*`) or `label` | Finds or creates the term on an existing global attribute. `{status: upserted, data: {taxonomy, term_id, slug, name}}` |
| `/batch/upsert` | `items`: `[{kind: product\|variation\|attribute, payload: {…}}]`, max 200 items and 2 MB | `{results: [{index, kind, status, data\|message}]}`; an unknown `kind` returns `skipped` |

- Field formats follow the sheet: `manage_stock` as the string `"TRUE"` or `"FALSE"` (a JSON boolean is ignored), `attributes` as one string `"Label: Value | Label: Value"`.
- Single endpoints return HTTP 400 `{error}` on failure; an oversized batch returns 413.
- Upserted products get `_wss_sync_enabled` but are not added to a tab group.
- `product_id`, `type` and `status` on `/products/upsert`, and `variation_id` on `/variations/upsert`, are accepted but not used.
- A concurrent upsert on the same parent returns "Another variation update is already running for this product. Please retry."

### AJAX, PHP and external requests

- **AJAX** (`admin-ajax.php`, `manage_woocommerce`, nonce `ffla_admin_nonce`): `wss_manual_sync`, `wss_sync_status` (`job_id`), `wss_sync_groups` (`op`: `get`, `add_group`, `remove_group`, `set_tab`, `add_product`, `remove_product`, `link_category`, `unlink_category`, `link_tag`, `unlink_tag`, `link_all`, `unlink_all`), `wss_clear_log`, `wss_disconnect`, `wss_search_products`. Also still registered from the single-tab version and unused by the current screens: `wss_resolve_product_names`, `wss_save_sync_products`, `wss_link_by_taxonomy`.
- **PHP:** `WSS_Sync_Job::enqueue()` starts a sync like Sync Now; `WSS_Sync_Orchestrator::run_all($sheets, $logger)` runs every tab; `WSS_Auth::get_provider()` returns the active `WSS_Token_Provider`; `WSS_Sync_Engine::is_applying()` is true while the engine writes sheet values to WooCommerce; `WSS_Sync_Engine::decide_stock_direction()` is the pure stock decision, covered by `tests/unit/StockDirectionTest.php`.
- **External requests:** `https://sheets.googleapis.com/v4/spreadsheets`, `https://oauth2.googleapis.com/token` (and `/revoke`), and the OAuth proxy `https://alearuca.com/wss-proxy/` (consent only).
