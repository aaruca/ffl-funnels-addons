# Sales Tax Resolver

Looks up the US sales tax rate for the customer's address and applies it in the WooCommerce cart, at checkout and when WooCommerce recalculates an order's taxes (for example a payment-plan renewal), so the store does not have to maintain WooCommerce's tax rate table by hand. Rates come from a shared Google Sheet that is imported into WordPress every month, or, with your own USGeocoder key, from a live address lookup that falls back to the sheet. Module ID: `tax-rates`, off until it is switched on in **FFL Funnels → Dashboard**. Requires WooCommerce with taxes enabled (**Enable tax rates and calculations**); the settings page shows a warning while they are off.

> **Read this first.** The module finds a rate for an address. It does not decide where your store must collect tax, and it is not tax or legal advice. When it cannot produce a rate, WooCommerce's own tax table is used instead, which means **no tax** if that table is empty. Check the Audit Log after setup, and confirm rates and registrations with the states or your accountant. Filing reports are a separate module, [Sales Tax Reports](../tax-reports/README.md).

## What it does

- Replaces WooCommerce's matched rates with one combined **Sales Tax** line for US addresses (state, county, city and special districts added together). The full breakdown is saved on the order.
- Two rate sources:
  - **Google Sheet ZIP dataset** (default, no account needed): a shared sheet imported into local tables. Matches by ZIP code, then city, then a statewide rate.
  - **USGeocoder live API** (paid, your own key): address-level lookup by street and ZIP. If the API fails or finds no rate, the sheet is tried automatically.
- Caches every address so repeat checkouts do not trigger new lookups, and keeps an audit log of lookups.
- Can be limited to the states you choose.
- Taxes local pickup at the store's address.
- Tax exemptions: whole orders for selected customers or roles, and selected categories or tags for selected customers or roles.
- Tax holidays: date windows that make matching products non-taxable, with optional shipping exemption.
- Renewals, admin **Recalculate** and Split Payment (FPPC) plan orders are taxed from the order itself, reusing the rate saved at checkout.
- Admin tools: Quote Lookup, Coverage Matrix, Datasets, Audit Log, Settings, and a REST API.

## Setup

1. In WooCommerce settings, turn on **Enable tax rates and calculations**.
2. Switch on **Sales Tax Resolver** in **FFL Funnels → Dashboard**. Activation creates the tables and default settings. With **Auto sheet sync** on (default), the first sheet import is scheduled for the next WP-Cron run.
3. Open **FFL Funnels → Sales Tax Resolver → Settings** (page *Tax Resolver*).
   - If the resolver should only run in some states, turn on **Limit resolver to selected states** and check those states. Read [Limiting states](#limiting-states) first: other states fall back to WooCommerce's own tax table, they are not set to zero.
   - Optional: paste a **USGeocoder Auth Key**, click **Test key**, then choose the **Tax rate source**.
   - Click **Save Settings**.
4. Open **Datasets** and click **Sync Sheet Data** if you do not want to wait for the scheduled import. When it finishes, **Active Imported Datasets** lists one row per state. The sheet is also the fallback in USGeocoder mode, so keep it imported.
5. If you offer local pickup, check the store address in WooCommerce → Settings → General (address line 1, city, state, postcode). Pickup orders are taxed at that address.
6. Use **Quote Lookup** with a few real addresses (street, city, state, ZIP). In USGeocoder mode each uncached lookup is a billed API call.
7. On staging, place a test order with a product in the standard tax class. The order should show one **Sales Tax** line, and **Audit Log** should show a `SUCCESS` row.
8. Optional: set up [exemptions](#tax-exemption-rules) and [tax holidays](#tax-holidays), then switch on **Sales Tax Reports** for filing reports.

## Settings

All settings are on **FFL Funnels → Sales Tax Resolver → Settings** and are saved with **Save Settings**.

### General

| Setting | What it does | Default |
|---|---|---|
| **Cache TTL** | How long a resolved address is reused without a new lookup: 1 hour, 6 hours, 24 hours, 7 days or 30 days. Saving a shorter value empties the cache. | 24 hours (recommended) |
| **Auto-clear cache** | Empties the whole address cache every day, week or month. The first run is one full interval after you save. Each cleared address costs a new lookup. | Never — clear manually (recommended) |
| **Auto sheet sync** | Re-imports the Google Sheet every 30 days for the states in use (all states, or only the checked ones when states are limited). | On |
| **Tax rate source** | *Automatic*: each state uses the source shown in the Coverage Matrix. *Google Sheet ZIP dataset*: always the sheet. *USGeocoder API (live) → Sheet fallback*: always try USGeocoder first. See [Where a rate comes from](#where-a-rate-comes-from). Changing it empties the cache. | Automatic (recommended) |

### USGeocoder API

| Setting | What it does | Default |
|---|---|---|
| **USGeocoder Auth Key** | Your USGeocoder key (masked field). Empty means sheet mode. Adding, changing or removing it empties the cache. The card badge shows *Sheet Mode (free)*, *USGeocoder Mode (live API)* or *USGeocoder Mode (key invalid)* after a failed test. | Empty |
| **Test key** (button) | Looks up USGeocoder's documented sample address with the key typed in the field. This is a real, billed call; a successful result for the same key is reused for one hour. | — |

With a key saved, an **API Usage** card shows the calls of the last 30 days and the last six months (with failed calls). Cached quotes are not counted.

### Google Sheet source

| Setting | What it does | Default |
|---|---|---|
| **Sheet URL** | The sheet to import. A Google Sheets share link is converted to its CSV export link; a direct CSV URL is used as is. The sheet must be readable without signing in. Blank falls back to the built-in shared sheet. | The shared sheet built into the plugin |

### Store state access

| Setting | What it does | Default |
|---|---|---|
| **Limit resolver to selected states** | When on, only checked states are quoted, synced and shown as *On* in the Coverage Matrix. | Off |
| State checkboxes (**Select All**, **Select Covered**, **Clear**) | The states the resolver runs for. **Unchecking a previously checked state and saving deletes that state's imported sheet data**, whether or not the toggle is on. Changes empty the cache. | None checked |

### Tax holidays

| Setting | What it does | Default |
|---|---|---|
| **Enable tax holidays** | Master switch for the rules below. | Off |
| **Add tax holiday** / **Duplicate** / **Remove** | Up to 100 rules. Each rule shows its status: *Active now*, *Scheduled*, *Expired*, *Disabled* or *Needs dates or products*. | No rules |
| **Enabled** | Turns one rule on or off. | On for a new rule |
| **Starts** / **Ends** | Date and time window, in the WordPress site timezone. | New rule: in 1 hour / in 24 hours |
| **Destination states** | Only these destination states. Empty means every state. | All states |
| **Product scope** | *Selected products, categories, or tags*, or *All products*. | Selected |
| **Maximum item price** | Optional. A product whose current unit price is above it is not exempt. | No limit |
| **Shipping tax** | *Keep shipping taxable*, *Exempt all shipping*, or *Exempt shipping proportionally*. | Keep shipping taxable |
| **Products**, **Categories**, **Tags** | What the rule covers. Categories include all child categories; variations follow their parent product. | Empty |

With holidays enabled, an enabled rule without valid dates or products blocks saving.

### Tax exemption rules

| Setting | What it does | Default |
|---|---|---|
| **Enable tax exemptions** | Master switch for both kinds of exemption below. Off means everyone is taxed. | Off |
| **Customers exempt from the full order** | WooCommerce customer search; up to 500 customers. Their whole order, shipping included, is not taxed. | None |
| Role checkboxes (*Full-order exemptions*) | Every WordPress role plus **Guest (not logged in)**. A customer with any checked role is exempt from the whole order. | None checked |
| **Add exemption rule** | Conditional rule (up to 100): **Active**, a name, **Customers**, **Roles**, **Categories**, **Tags**. A product line is exempt when the customer matches a selected customer *or* role *and* the product matches a selected category *or* tag. Shipping and other lines stay taxable. | No rules |

With exemptions on, an active rule without a customer or role, or without a category or tag, blocks saving.

### Tools on the Settings tab

| Tool | What it does |
|---|---|
| **Clear cache now** | Empties the address cache after a confirmation. Every address is looked up again on its next checkout. |
| **Delete Old Tax Database** | Deletes **all** imported sheet datasets (including the current ones the sheet source and the USGeocoder fallback use), the address cache and the audit log. Sheet lookups fail until the next sync. |

## How it works

### Where a rate comes from

| Tax rate source | No USGeocoder key | With a key |
|---|---|---|
| Automatic | Sheet for every state | USGeocoder for every state, or only for checked states when states are limited; the state routing is stored in the Coverage Matrix |
| Google Sheet ZIP dataset | Sheet | Sheet (the key is not used) |
| USGeocoder API (live) → Sheet fallback | Sheet (the USGeocoder step fails without a key and falls back) | USGeocoder, then sheet |

The sheet fallback runs when USGeocoder is unreachable, returns an invalid response, rejects the request, finds no match or returns no usable rate. A fallback result is marked `sheet_fallback` in the audit log. If the sheet also fails, the USGeocoder error stands.

**Sheet dataset.** Each state is imported from the CSV into local tables:

- One row per 5-digit ZIP. When the sheet has several rows for a ZIP, the highest combined rate is kept (with a note).
- A city fallback per normalized city name, using the highest ZIP rate in that city.
- A statewide floor: the most common state rate across the state's ZIPs.

Lookups try the ZIP, then the city, then the state floor. The street is not used. Imported data counts as current for 45 days after it was loaded; older data is not used (`DATASET_STALE`). An import whose content is unchanged keeps the existing version. A state whose imported rates are all zero is marked *No sales tax* in the Coverage Matrix; the resolver does not quote states with that status, so WooCommerce's own table applies.

**USGeocoder.** Sends the street line and the 5-digit ZIP (the city is not sent). The breakdown comes from the response's Total Collection data, or Mandatory Collection when that is all the plan returns. Every real HTTP call is counted in **API Usage**.

**Cache and audit.** Quotes are cached per normalized street, city, state and ZIP:

- Successful quotes for **Cache TTL**; "no match" and "no usable rate" answers for 1 hour; outages are not cached.
- Expired rows are removed daily.
- The cache is also emptied by the setting changes noted above, by **Clear cache now** and **Auto-clear cache**, and per state on every sheet sync (including unchanged imports and addresses resolved by USGeocoder).
- Every lookup that is not a cache hit (Quote Lookup, REST, cart and checkout) writes an audit row; rows older than 90 days are deleted weekly.

### At checkout

WooCommerce asks for tax rates for each calculation. The module only handles US destinations with a state and the standard tax class; products and shipping in other tax classes keep WooCommerce's rates. For those:

1. A customer with a full-order exemption gets no tax at all.
2. A state that is not enabled, or not supported in the Coverage Matrix, keeps WooCommerce's rates.
3. Otherwise the address is quoted. The street follows WooCommerce's **Calculate tax based on**: shipping street (billing street when empty), billing street, or none for the shop base address.
4. A rate above zero becomes one rate labelled **Sales Tax** (rate ID `990000`, code `US-{STATE}-FFLA-TOTAL`, not compound, also applied to shipping). A zero rate adds no tax line. If the quote fails, WooCommerce's own matched rates are kept.

The last quote of the checkout session is saved on the order (classic checkout and Checkout Blocks).

A quote needs a street and a ZIP. Until the customer has entered a street (for example in the cart's shipping calculator), the quote fails with `VALIDATION_ERROR` and WooCommerce's own rates apply. With **Calculate tax based on** set to the shop base address, no street is passed, so the resolver never quotes and WooCommerce's own rates apply.

### Local pickup

When the chosen shipping method is a local pickup method (WooCommerce's pickup methods; the Checkout Blocks pickup method when WooCommerce registers it), the whole address — country, state, ZIP, city and street — is replaced by the store address from WooCommerce settings before any check runs. WooCommerce's own `woocommerce_apply_base_tax_for_local_pickup` switch is respected. Multi-location stores that want WooCommerce's per-location address can turn this off with `ffla_tax_local_pickup_use_store_base`.

### Order recalculations and renewals

When WooCommerce recalculates a stored order — subscription or Split Payment renewals built in the background, admin **Recalculate**, FPPC final-shipping and early-payoff orders — the module uses the order instead of the browser session. This applies to orders that carry a saved quote and to FPPC plan orders, never to the order currently being checked out.

- The address is WooCommerce's taxable address for that order. The street is taken from the order's address only when its state and ZIP match. Pickup orders use the store address.
- Exemptions are decided for the order's own customer; the order's exemption evidence is updated.
- If the order's saved quote was successful and is for the same address, it is reused with no new lookup, so every payment of a plan uses the deposit's rate. Otherwise the order is quoted once and the new quote is saved.
- If that fails, WooCommerce's own rates are kept and a warning is written to the WooCommerce log (source `ffla-tax`).
- Existing orders are not recalculated by the module; this applies the next time WooCommerce calculates an order. Other orders keep the session-based behaviour.

### Limiting states

With **Limit resolver to selected states** on:

- Unchecked states are not quoted. Quote Lookup and REST return `STATE_DISABLED`; checkout uses WooCommerce's own tax table for them, so they get no tax only when that table has no rate for them.
- Only checked states are imported by the monthly sync.
- With the toggle on and nothing checked, nothing is quoted and the sync imports nothing.

### Exemptions

- **Full-order exemptions** (customers and roles) give the order no tax at a US address in the standard tax class, shipping included. The order stores `_ffla_tax_full_order_exempt = yes` and the customer ID and roles used.
- **Conditional rules** make only the matching product lines non-taxable. Shipping and unrelated products keep their tax. Each exempt line stores the rule IDs, names and a snapshot of the match, so reports stay stable if a rule is later renamed or deleted.
- The customer is the WooCommerce customer, else the logged-in user. Recalculating an order in the admin uses that order's customer.
- Conditional rules and tax holidays mark products non-taxable for WooCommerce itself, so they also apply where WooCommerce's own rates are used. Product edit screens always show the stored tax status.

### Tax holidays

- A rule is active between **Starts** and **Ends** (site timezone).
- The destination state follows **Calculate tax based on**; local pickup and the shop base address use the store's state.
- Matching product lines are non-taxable; other lines are taxed normally.
- Shipping:
  - *Exempt all shipping*: removes all shipping tax when any line in the cart matches such a rule.
  - *Exempt shipping proportionally*: removes the same share of shipping tax as the matching lines' share of the cart total.
- Exempt lines and the order store the rule IDs, names, dates and a snapshot; shipping lines store the exempt amount. Holidays work whether or not **Enable tax exemptions** is on.

### Accuracy and limits

- One general-goods rate per address. Quotes carry the limitation "Quote for general goods profile". Product-specific taxes are not applied (excise taxes, special rates for certain goods, or which products a state taxes). Use WooCommerce tax classes, exemption rules or holidays for those.
- Sheet data is ZIP-level. A ZIP that spans several jurisdictions uses its highest rate, so it can over-collect. The state fallback, and the city fallback for cities with several ZIP rates, are less precise and are marked *medium* confidence.
- The shared sheet is maintained outside the plugin. Its accuracy and update timing are not checked by the module.
- USGeocoder results depend on your USGeocoder plan and on the street and ZIP entered.
- The module does not check nexus, registrations or exemption certificates.

### Compatibility

- Classic checkout and Checkout Blocks (Store API).
- WooCommerce order storage in both modes (the plugin declares HPOS compatibility).
- Subscription and FPPC (Split Payment) renewals.

## Where it shows up

- **Cart and checkout**: one **Sales Tax** line.
- **Orders**: the same tax line with code `US-{STATE}-FFLA-TOTAL` and its rate. The quote and exemption evidence are stored as hidden meta (keys start with `_`).
- **FFL Funnels → Sales Tax Resolver** (page *Tax Resolver*), with these tabs:
  - **Quote Lookup**: total rate, breakdown, coverage, source, version, confidence, cache hit and limitations for an address.
  - **Tax Reports**: link to WooCommerce → Sales Tax Reports, shown only when that module is on.
  - **Coverage Matrix**: one cell per state. *+* imported and ready, *~* awaiting sync, *R* USGeocoder live, *0* no sales tax, *!* degraded. *On* / *Off* is shown when states are limited. The summary counts USGeocoder states under *Awaiting Sync*.
  - **Datasets**: the CSV source, which states the monthly rebuild covers, **Sync Sheet Data**, and active datasets with their age against the 45-day limit (marked stale when older).
  - **Audit Log**: the last 50 lookups.
  - **Settings**.
- **Admin notices**: WooCommerce taxes disabled; settings saved; datasets removed for unchecked states; cache emptied and why.
- **WooCommerce → Status → Logs**, source `ffla-tax`: rate fallbacks during order recalculations.
- **Sales Tax Reports**: reads the saved quote and exemption evidence.

## Data and uninstall

| Data | Where | Kept for |
|---|---|---|
| Settings | Option `ffla_tax_resolver_settings` | Until uninstall |
| State routing | Table `{prefix}ffla_tax_coverage_rules` | Until uninstall |
| Imported sheet data | Tables `{prefix}ffla_tax_dataset_versions`, `{prefix}ffla_tax_jurisdiction_rates`. Replaced versions are kept as *superseded*. | Until the state is unchecked, **Delete Old Tax Database**, or uninstall |
| Address cache (normalized address including street, quote) | Table `{prefix}ffla_tax_address_cache` | Cache TTL; emptied as described above |
| Audit log (address entered, matched address, result) | Table `{prefix}ffla_tax_quotes_audit` | 90 days |
| USGeocoder call counts | Option `ffla_tax_usgeocoder_usage` (24 months) | Until uninstall |
| Order evidence: quote (with the address quoted), exemption and holiday evidence | Order, line and shipping-line meta (see [For developers](#for-developers)) | Permanently, with the order |

- **Switching the module off** stops it at once. Checkout and recalculations use WooCommerce's own tax table again. The scheduled jobs are removed and all data stays.
- **Uninstalling the plugin** drops the five tables and deletes the settings, the database version, the usage counter, the last-flush time, the key-test result and the scheduled jobs. It runs when the module is active, or its tables or settings still exist. Order meta is not removed, so past orders keep their tax evidence.

## Troubleshooting

| Symptom | Check |
|---|---|
| No tax at checkout | Is **Enable tax rates and calculations** on? Is the state enabled (Coverage Matrix *On*) and imported (**Datasets**)? Is the product in the standard tax class? Is the customer exempt, or a holiday active? Has the customer entered a street? Then read the outcome in **Audit Log**. |
| Tax differs from the expected rate | Run **Quote Lookup** for the same address and compare the breakdown and source. Sheet results are ZIP-level and may use the city or state fallback (*medium* confidence). After rate changes, **Clear cache now**. |
| Wrong rate on pickup orders | Check the store address in WooCommerce → Settings → General. Pickup is taxed there. |
| Dataset marked stale, `DATASET_STALE` | The state's data was loaded more than 45 days ago. Click **Sync Sheet Data**. An unchanged import keeps the old load date, so if it stays stale the shared sheet has not been updated for that state. |
| Sync errors | The message names the state and cause. "The shared tax sheet returned HTTP …": check that the sheet is shared publicly and that **Sheet URL** is right. "has no usable ZIP rows for XX": the sheet has no rows with that state code, a ZIP and a combined rate. |
| States that should use USGeocoder show *Sheet* in the Coverage Matrix | A sheet sync marks every synced state as a sheet state. Under **Tax rate source** = *Automatic*, those states then use the sheet until the routing is rebuilt (on activation, or when a saved setting actually changes). Choose *USGeocoder API (live) → Sheet fallback* to always try USGeocoder first. |
| **Test key** fails | `http_error`: the key is invalid or inactive. `network_error`: the site cannot reach api.usgeocoder.com. `no_rate`: the account returned no rate. |
| More USGeocoder calls than expected | Each uncached address is a call, including Quote Lookup and REST. The cache is emptied by key, state or source changes, a shorter TTL, **Clear cache now**, **Auto-clear cache** and every sheet sync. A longer **Cache TTL** reduces calls. |
| Quote Lookup says `STATE_UNSUPPORTED` for a state without sales tax | States whose imported sheet data is all zero are marked *No sales tax* and are not quoted. WooCommerce's own table applies. |

Outcome codes (Quote Lookup, Audit Log, REST):

| Code | Meaning | What happens at checkout |
|---|---|---|
| `SUCCESS` | Rate found | **Sales Tax** at that rate |
| `NO_SALES_TAX` | Rate found and it is zero | No tax |
| `STATE_DISABLED` | State not checked while states are limited | WooCommerce's own rates |
| `STATE_UNSUPPORTED` | The Coverage Matrix does not mark the state as supported (this includes states marked *No sales tax*) | WooCommerce's own rates |
| `SOURCE_UNAVAILABLE` | No dataset imported for the state, or USGeocoder unavailable and the sheet fallback failed too | WooCommerce's own rates |
| `DATASET_STALE` | Sheet data older than 45 days | WooCommerce's own rates |
| `RATE_NOT_DETERMINABLE` | No match and no state floor, or USGeocoder found no rate | WooCommerce's own rates |
| `VALIDATION_ERROR` | Missing street, ZIP or valid state | WooCommerce's own rates |
| `INTERNAL_ERROR` | Unexpected resolver error | WooCommerce's own rates |

## For developers

**Filters**

| Hook | Arguments | Use |
|---|---|---|
| `ffla_tax_local_pickup_use_store_base` | `bool` (default `true`) | Return `false` to keep WooCommerce's own pickup address. |
| `ffla_tax_order_context_enabled` | `bool $applies`, `WC_Order $order` | Opt an order in or out of order-based recalculation. |
| `ffla_tax_exemption_customer_user_id` | `int $user_id` | Customer used for exemptions in the cart and at checkout. |
| `ffla_tax_exemption_order_customer_user_id` | `int $user_id`, `WC_Order $order` | Customer used for exemptions on a stored order. |
| `ffla_tax_trust_proxy_headers` | `bool` (default `false`) | Return `true` to take the REST rate-limit IP from `CF-Connecting-IP`, `X-Real-IP` or `X-Forwarded-For`. |

The module also honours WooCommerce's `woocommerce_apply_base_tax_for_local_pickup` and `woocommerce_local_pickup_methods`.

**REST API** (`/wp-json/ffl-tax/v1/`, every route requires a user with `manage_woocommerce`). Checkout does not call these routes; it calls the quote engine directly.

| Method | Route | Notes |
|---|---|---|
| POST | `/quote` | JSON body with `street`, `city`, `state`, `zip`, or a one-line `address`. 200 on success, 422 when the outcome is not a success, 429 above 60 requests per minute per IP. `totalRate` is a decimal (`0.0825` = 8.25%). |
| POST | `/quote/batch` | Body `{"addresses": [...]}`, at most 25; 30 requests per minute per IP. Returns `{count, results}`. |
| GET | `/coverage` | Coverage Matrix per state: status, resolver, notes, enabled for store, source strategy. |
| GET | `/health` | Active datasets with age and freshness, coverage summary, sheet source, resolvers, and 24-hour lookup stats. Cache hits are not audited, so `cacheHitRatio24h` stays 0. |
| GET | `/datasets` | Up to 50 sheet dataset versions, sorted by state, then newest load. |
| POST | `/admin/sync` | Runs the sheet sync now, like **Sync Sheet Data**. |
| GET | `/admin/audit` | Recent audit rows; `?limit=1..100` (default 25), `?state=XX`. |

**Scheduled jobs (WP-Cron)**

| Hook | When |
|---|---|
| `ffla_tax_dataset_sync` | Every 30 days (`ffla_monthly`) while **Auto sheet sync** is on |
| `ffla_tax_cache_cleanup` | Daily; removes expired cache rows |
| `ffla_tax_audit_purge` | Weekly; removes audit rows older than 90 days |
| `ffla_tax_cache_flush` | Daily, weekly or monthly per **Auto-clear cache**; not scheduled for *Never* |

**Sheet CSV columns** (header row, rates in percent): `State`, `ZipCode`, `City`, `NormalizedCity`, `TaxRegionName`, `County`, `AdjustedCombinedRate` or `CombinedRate`, `StateRate`, `CountyRate`, `CityRate`, `SpecialRate`, `Year`, `Month`. A row needs a state, a 5-digit ZIP, a city or region name and a combined rate. The latest `Year`/`Month` becomes the version label and effective date.

**Stored data**

- Order meta:
  - `_ffla_tax_quote` (JSON quote), `_ffla_tax_query_id`, `_ffla_tax_source`.
  - `_ffla_tax_full_order_exempt`, `_ffla_tax_full_order_exempt_context`.
  - `_ffla_conditional_tax_exempt_items`, `_ffla_conditional_tax_exempt_sales`, `_ffla_conditional_tax_exemption_rules`.
  - `_ffla_tax_holiday_exempt_items`, `_ffla_tax_holiday_exempt_sales`, `_ffla_tax_holiday_exempt_shipping`, `_ffla_tax_holiday_rules`, `_ffla_tax_holiday_snapshot`.
- Line-item meta: `_ffla_tax_exempt`, `_ffla_tax_exemption_rule_ids`, `_ffla_tax_exemption_rule_names`, `_ffla_tax_exemption_snapshot`, `_ffla_tax_exemption_type` (`audience`, `holiday`, `audience+holiday`), `_ffla_tax_audience_rule_names`, `_ffla_tax_holiday_rule_ids`, `_ffla_tax_holiday_rule_names`, `_ffla_tax_holiday_snapshot`.
- Shipping-line meta: `_ffla_tax_holiday_exempt_amount`, `_ffla_tax_holiday_rule_names`, `_ffla_tax_holiday_snapshot`, plus `_ffla_tax_exempt` / `_ffla_tax_exemption_type = holiday` when fully exempt.
- WooCommerce session: `ffla_last_tax_quote`, `ffla_runtime_tax_rates`.
- Options and transients: `ffla_tax_resolver_settings`, `ffla_tax_resolver_db_version` (schema `1.4.0`), `ffla_tax_usgeocoder_usage`, `ffla_tax_last_cache_flush`; transients `ffla_tax_key_validation` (1 hour), `ffla_tax_reconcile_lock` (30 seconds).

**PHP entry points**

- `Tax_Quote_Engine::quote(['street' => …, 'city' => …, 'state' => …, 'zip' => …])` returns a `Tax_Quote_Result` (`->is_success()`, `->to_array()`).
- `Tax_Dataset_Pipeline::sync('google_sheet_zip_rates')` imports the sheet.
- `Tax_Coverage::reconcile_from_settings()` rebuilds the state routing.

**Code layout.** The Sales Tax Reports classes (`includes/class-tax-report-*.php`, `includes/class-tax-nexus-monitor.php`, `admin/class-tax-reports-admin.php`, `assets/nexus-thresholds.csv`) live in this folder but are loaded only by the `tax-reports` module.

**Tests** (run from the plugin folder): `php tests/smoke/tax-order-context-smoke.php` covers renewals, admin Recalculate, pickup, exemptions on stored orders and the native fallback, and runs in CI. `php tests/smoke/tax-policy-smoke.php` covers the holiday engine (category inheritance, state and price limits) and is not part of CI.
