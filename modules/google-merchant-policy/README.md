# Google Merchant Policy

Decides which WooCommerce products Google for WooCommerce may send to Google Merchant Center. You give each product category a rule, can decide single products by hand, and the module blocks products flagged as firearms or ammunition and products whose text matches restricted-content keywords. In **Audit only** it records a decision for every product and changes nothing in Google. In **Enforce** it sets Google for WooCommerce's Channel visibility to match each decision and asks Google for WooCommerce to upload or remove products. It is meant for FFL stores whose Merchant Center feed must not carry firearms, ammunition and other restricted items. Module ID: `google-merchant-policy`, off until it is switched on in **FFL Funnels → Dashboard**. It needs WooCommerce, and Google for WooCommerce (`google-listings-and-ads`) connected to Merchant Center for anything to change in Google. Its integration points were checked against Google for WooCommerce 3.9.4.

> **Read this first.** In Enforce, only products that come out **Allowed** stay in Google; **Pending** is removed like **Blocked**. Top-level categories start as Pending and child categories inherit from them, so switching to Enforce before you have set **Allow** on your categories takes the whole catalog out of Google. Start in Audit only and read the totals first.

## What it does

- **Category rules.** Each product category gets **Allow**, **Block**, **Pending** or **Inherit parent**. A product in several categories is Allowed only when every one of them resolves to Allow.
- **Per-product decisions.** **Follow policy rules** (default), **Always include** or **Always exclude**, from a box on the product page or from bulk actions in **Products**.
- **Hard blocks.** Products with `_firearm_product` or `_ammunition_product` set are always Blocked, even with Always include.
- **Restricted-content safety scan** (on by default). Product names, descriptions and category names are matched against keyword lists; a match blocks the product even in an Allow category.
- **Catalog scan.** A resumable background scan re-evaluates every published product and variation in batches, stores the decision and its reason on each, and counts the results.
- **Two-way sync in Enforce.** Allowed products are set to `sync-and-show`, Blocked and Pending products to `dont-sync-and-show`. Product saves, category changes, per-product decisions and the scan reach Google through Google for WooCommerce's own upload and removal flow, and its own Channel visibility controls are taken over while Enforce is on.
- **Existing exclusions are kept.** In Enforce, a product someone hid in Google for WooCommerce is kept out as **Always exclude**.

What it does **not** do:

- It does not connect a Google account or Merchant Center, and it changes nothing in Google without Google for WooCommerce.
- It does not create its own feed. Products sent to Merchant Center by other plugins or feed sources are not controlled.
- It does not get a disapproved or suspended Merchant Center account approved, and it does not request a review. Google decides.
- It does not confirm anything with Google. Uploads and removals are handed to Google for WooCommerce, which runs them as background jobs; "Complete" means the local scan finished.
- It does not hide, unpublish or delete anything in your store.
- It does not set the firearm/ammunition flags. It only reads them; nothing in FFL Funnels Addons sets them.
- The keyword checks are an aid, not a compliance check.

## Setup

1. Install Google for WooCommerce and connect Merchant Center. You can prepare rules and run an audit without it; the settings page warns that it is not active. Make sure WP-Cron or Action Scheduler runs on the site, since the scan runs in the background.
2. Switch on **Google Merchant Policy** in **FFL Funnels → Dashboard**. It starts in **Audit only**, with 50 products per batch and the safety scan on.
3. Check that products which must never reach Google have `_firearm_product` or `_ammunition_product` set to `yes` (`1` and `true` also count).
4. Open **FFL Funnels → Google Merchant Policy** → **Category policies** and choose a **Rule** for every relevant category: **Allow** only after reviewing its products, **Block** for categories to keep out, **Pending** when not reviewed yet, **Inherit parent** for children that should follow an ancestor. Use **Search categories…** to find them; hidden rows are still saved.
5. Leave **Operating mode** on **Audit only — no feed changes**, keep **Restricted-content safety scan** checked and click **Save policies & start catalog scan**. Reload the page to follow progress.
6. Review the result. In **Products**, filter by **Google: Blocked** or **Google: Pending** (or use the buttons on the settings page) and open products: the **Google Merchant Policy** box shows why. Adjust category rules, flags or product text, or set **Always include** / **Always exclude** per product. Save the policies again to rescan.
7. When the totals look right, set **Operating mode** to **Enforce — this addon decides what reaches Google**, click **Save policies & start catalog scan** and confirm the prompt. The new scan sets Channel visibility on every product, requests uploads for products it moves back to `sync-and-show`, and requests removal of excluded products that are already in Google.
8. Verify outside the plugin: Google for WooCommerce's scheduled jobs (hooks starting with `gla/jobs/`) and the products in Merchant Center. Request any account review in Merchant Center only after that.

## Settings

On **FFL Funnels → Google Merchant Policy** (needs the `manage_woocommerce` capability). Changes apply when you click **Save policies & start catalog scan**, which saves the whole form and always starts a new scan.

| Setting | What it does | Default |
|---|---|---|
| **Operating mode** | **Audit only — no feed changes**: decisions are recorded, Google is not changed. **Enforce — this addon decides what reaches Google**: Allowed products are uploaded, Blocked and Pending products are removed, through Google for WooCommerce. Saving with Enforce selected asks for confirmation. | Audit only |
| **Products per batch** | Most products the scan handles in one batch, 10–250. Lower it if the scan strains the site. It is not a limit on catalog size. | 50 |
| **Restricted-content safety scan** | Matches names, descriptions and category names against restricted-content keywords (see [Restricted-content safety scan](#restricted-content-safety-scan)). Turning it off never lifts a firearm/ammunition flag block. In Enforce, products that were blocked only by these checks are uploaded by the next scan. | On |
| **Category policies → Rule** (one per category; also **Google Merchant policy** on the category edit screen) | **Inherit parent**, **Allow**, **Block** or **Pending — not eligible in Enforce**. | New top-level categories: Pending. New child categories: Inherit parent. Categories without a saved rule behave the same way. |
| **Decision for this product** (product page box; same choices as the Products bulk actions) | **Follow policy rules**, **Always include** or **Always exclude**. Needs permission to edit the product. | Follow policy rules |

## How it works

### Decision order

The module checks each product in this order and stops at the first step that decides:

1. **Firearm/ammunition flag.** Post meta `_firearm_product` or `_ammunition_product` equal to `yes`, `1` or `true` (any case) → **Blocked**. Nothing overrides it.
2. **Per-product decision.** **Always exclude** → **Blocked**. **Always include** → **Allowed**, and steps 3 and 4 are skipped.
3. **Restricted-content safety scan** (when on). Any keyword match → **Blocked**.
4. **Category rules.** No category → **Pending**. Otherwise each assigned category's effective rule is worked out: any Block → **Blocked**; else any Pending → **Pending**; else **Allowed**. So Allow + Pending = Pending, Allow + Block = Blocked.

The reason is stored with the decision and shown in the product box, for example *Category “Holsters” resolves to allow.*, *Hard block: product is marked as firearm.* or *Hard block: restricted regulated part content detected.*

### Category rules and inheritance

- **Allow** makes the category eligible. Flags, the safety scan, the product's other categories and per-product decisions still apply.
- **Block** excludes its products.
- **Pending** means not reviewed yet. It is not eligible in Enforce.
- **Inherit parent** uses the first Allow, Block or Pending found walking up the parent chain. If none is found, the result is Pending. A child category with no saved rule behaves like Inherit parent; a top-level one like Pending.
- An explicit rule on a child replaces inheritance for that child only. If a product is also assigned to the parent category, the parent's rule counts as well.
- The **Saved effective category rule** column shows what each category resolves to under the saved rules. It changes only after you save, and it is not a list of approved products.

### Variations

A variation uses its parent's categories and its parent's per-product decision. A flag or safety-scan match on either the parent or the variation blocks it. Google for WooCommerce reads Channel visibility from the parent product, so in Enforce a variation blocked on its own under an Allowed parent is kept out by the module's other checks: Google for WooCommerce's upload jobs and its lists of sync-ready products, and Google's pull API (the WPCOM proxy, requests with `gla_syncable=1`), where list requests leave the variation out and single requests get the proxy's own 403 "Item not syncable". Ordinary REST clients are not affected.

### Restricted-content safety scan

Scanned text: the product name, short description, description (HTML removed) and the names of the categories assigned to it; for a variation, the parent's text and the variation's own. Matching is whole-word and case-insensitive, and most words also match their plural.

| Label in the reason | Words |
|---|---|
| ammunition | ammunition, ammo, cartridge, round, primer, gunpowder, smokeless powder |
| firearm | firearm, handgun, pistol, revolver, rifle, shotgun, machine gun, short-barreled (or short barreled), SBR, NFA |
| regulated part | receiver (including upper and lower receiver), frame, barrel, trigger, bolt carrier, magazine, suppressor, silencer |
| weapon accessory | rifle scope, gun scope, weapon sight, night vision scope, thermal scope, less-lethal weapon, taser, tazer, stun gun |

For the firearm list only, accessory phrases are ignored: *gun, firearm, handgun, pistol, revolver, rifle* or *shotgun* directly followed by *case, safe, cabinet, vault, lock, rack, bag, holster, sling* or *cleaning mat* (optionally with *carrying, storage, hard* or *soft* in between). "Rifle Case" passes; "Precision Rifle with case" is still blocked. The exception does not apply to the other lists: "Range Bag with magazine pouch" is blocked as a regulated part.

Limits:

- It is keyword matching. Everyday senses of *round, frame, barrel, trigger, magazine, primer* or *cartridge* cause blocks, and anything not in the lists (brand or model names, for example) is not detected.
- Category names are scanned, so while the scan is on every product in a category whose name matches (for example *Magazines*) is blocked, whatever that category's rule.
- **Always include** skips the scan for that product. Flag blocks still apply.
- Developers can change the lists with `ffla_google_merchant_hard_block_patterns`.

### Enforce: keeping Google for WooCommerce in step

- **Channel visibility.** Whenever the module stores a decision in Enforce it sets Google for WooCommerce's `_wc_gla_visibility`: `sync-and-show` for Allowed, `dont-sync-and-show` for Blocked and Pending. An empty value counts as `sync-and-show`.
- **Product saves.** The module decides at priority 80 on WooCommerce's product and variation create/update hooks, before Google for WooCommerce's own handler on `woocommerce_update_product` (priority 90), which then queues the upload or removal in the same request.
- **Sync-ready lists.** Through `woocommerce_gla_get_sync_ready_products_pre_filter`, Google for WooCommerce only sees Allowed products as ready to sync.
- **Upload jobs.** Google for WooCommerce's `update_products`, `update_all_products` and `resubmit_expiring_products` jobs go through the same check: products that are not Allowed are dropped from the batch, their decision is stored and their removal requested. A batch with only excluded products completes without calling Google for WooCommerce's job handler. If a removal request fails inside a job, the rest of the batch still syncs and a warning is logged.
- **Removals** use Google for WooCommerce's own removal flow. They are requested only for products set to `dont-sync-and-show` that have Google product IDs (`_wc_gla_google_ids`). Variable parent products are skipped; their variations are handled one by one. If Google for WooCommerce cannot take the request, the scan stops with an error instead of reporting a removal.
- **Uploads** use Google for WooCommerce's product update flow. The scan requests one only when it moves a product from `dont-sync-and-show` to `sync-and-show`. If Google for WooCommerce's sync service is not running (it is registered only once Merchant Center is connected), no upload is requested and nothing fails.
- **Google for WooCommerce's controls.** The **Channel visibility** box on the product page is replaced by the **Google Merchant Policy** box, and bulk visibility edits from its Product Feed page are refused with a message pointing here.
- **Exclusions made elsewhere are kept.** When Enforce first evaluates a simple or parent product that is set to `dont-sync-and-show` by someone other than this module and has no per-product decision, it stores **Always exclude** for it. The product box then says "Kept from the Channel visibility setting in Google for WooCommerce." Only exclusions are kept; a product set to show in Google for WooCommerce follows the rules.
- **Your decisions take over.** Setting a decision in the product box or with a bulk action, in either mode, hands the product's current Channel visibility to this module. Choosing **Follow policy rules** on a kept exclusion releases it: the product is uploaded if the rules allow it.

### Catalog scan

- **Starts** when you click **Save policies & start catalog scan**, when any product category is saved (edit screen or Quick Edit, even if its rule did not change), and from **Resume / run next batch** when the scan is Idle or Complete. Every start resets the counters. Creating or deleting a category does not start a scan.
- **Covers** published products and variations up to the highest ID that existed when the scan started, in ID order. Drafts, private products and products added later are not visited; they are decided when they are saved.
- **For each product** it re-evaluates and stores the decision and reason; in Enforce it also sets Channel visibility and requests a removal (not Allowed and already in Google) or an upload (moved back to `sync-and-show`). Progress is saved after every product.
- **Batches** hold up to **Products per batch** products and stop early after about 20 seconds. The next batch is queued 10 seconds later through Action Scheduler, or WP-Cron if Action Scheduler cannot queue it. While a scan is Running, page loads and an hourly watchdog re-queue a lost batch, and a 5-minute lock keeps two batches from running at once.
- **Pause scan** stops the scan and keeps its position. It does not turn off Enforce, product-save syncing or Google jobs already handed over, and it does not save the form.
- **Resume / run next batch** uses the saved settings and does not save the form. Paused or Failed: continues from the saved position. Running: attempts the next batch. Idle or Complete: starts a new scan. In each case one batch runs straight away in your browser request.
- **Failed**: the message appears as **Last scan error** at the bottom of the page. The failed product is not counted (its decision may already be stored), and Resume tries it again.
- **Counters** are local. **Processed** and **Allowed / Blocked / Pending** count products and variations, so they will not match Merchant Center or your number of parent products. **Google uploads / removals requested** counts requests handed to Google for WooCommerce, not confirmed changes; both stay at 0 in Audit only. Skipped (unloadable) products are shown in the **Last progress (UTC)** line. The page does not refresh by itself.

### Audit only, switching back and switching the module off

- **Audit only** stores the decision and reason on product saves and in scans. It does not write Channel visibility, request uploads or removals, filter Google for WooCommerce's lists, jobs or pull requests, replace its controls, or turn its exclusions into Always exclude.
- **Switching from Enforce to Audit only** leaves Channel visibility as the module last set it, and Google for WooCommerce keeps using it. Change it with Google for WooCommerce's own controls, which are available again.
- **Switching the module off** stops all of the above and removes its queued scan batches and the watchdog. Settings, rules, decisions, scan state and Channel visibility values stay. A scan that was Running continues when the module is switched back on.

## Where it shows up

| Place | What you see |
|---|---|
| **FFL Funnels → Google Merchant Policy** | Cards for **Mode**, **Catalog scan**, **Processed**, **Allowed / Blocked / Pending** and **Google uploads / removals requested**; a warning when Google for WooCommerce is not active; buttons for Blocked, Pending, Always excluded and Always included products; the **How to use Google Merchant Policy** guide; **Policy settings**; the **Category policies** table; **Save policies & start catalog scan**, **Resume / run next batch**, **Pause scan**; **Last scan error**. |
| **Products → Categories** | Add screen: a note that the rule is assigned automatically. Edit screen: the **Google Merchant policy** select; saving the category starts a new scan. |
| Product edit page, **Google Merchant Policy** box | Decision badge and reason (worked out live when the page loads), **Decision for this product**, Google for WooCommerce sync status and Merchant Center status, a link to the settings page. In Enforce, with Google for WooCommerce active, it replaces the **Channel visibility** box. |
| **Products** list | **Google** column: stored decision, per-product decision, short sync status such as "Synced · approved". Filter **All Google decisions** with **Google: Allowed / Blocked / Pending / Always include / Always exclude**. Bulk actions **Google: always include**, **Google: always exclude**, **Google: follow policy rules**: parent products only, each product is saved, so in Enforce Google for WooCommerce queues the upload or removal in the same request. |
| Google for WooCommerce Product Feed page | In Enforce, bulk visibility changes fail with "Google Merchant Policy (FFL Funnels) is in Enforce mode and decides which products reach Google…". |
| **WooCommerce → Status → Logs** | Source `ffla-google-merchant-policy`: removal requests that failed inside a Google for WooCommerce upload job. |
| Scheduled actions | `ffla_google_merchant_policy_reconcile` (group `ffla-google-merchant-policy`) and the hourly WP-Cron event `ffla_google_merchant_policy_watchdog`. |

The **Google** column and the Allowed / Blocked / Pending filters use the decision stored at the last save or scan; the column works it out live only for products that have none. Products not saved or scanned since the module was switched on do not appear under those filters.

## Data and uninstall

| Stored in | Key | Content |
|---|---|---|
| Option | `ffla_google_merchant_policy_settings` | `mode` (`audit` or `enforce`), `batch_size`, `content_safety` (`1` or `0`) |
| Option | `ffla_google_merchant_policy_reconcile_state` | Scan status, position, counters, timestamps, last error |
| Option | `ffla_google_merchant_policy_reconcile_lock_v2` | Short-lived batch lock |
| Term meta on `product_cat` | `_ffla_google_merchant_rule` | `allow`, `block`, `pending` or `inherit` |
| Post meta, products and variations | `_ffla_gmp_status`, `_ffla_gmp_reason`, `_ffla_gmp_version`, `_ffla_gmp_checked_at` | Last decision, its reason, engine version, time checked (UTC) |
| Post meta, products | `_ffla_gmp_override`, `_ffla_gmp_override_source` | `include` or `exclude`; `manual` or `google-for-woocommerce` (kept exclusion) |
| Post meta, products and variations | `_ffla_gmp_visibility_applied` | The Channel visibility value this module last wrote or took over |

Google for WooCommerce data written (Enforce only): `_wc_gla_visibility`. Read only: `_wc_gla_google_ids`, `_wc_gla_synced_at`, `_wc_gla_sync_status`, `_wc_gla_mc_status`, and the flags `_firearm_product` and `_ammunition_product`.

**Uninstall:** `uninstall.php` has no step for this module. Deleting FFL Funnels Addons leaves everything above in place, including the Channel visibility values the module wrote, which Google for WooCommerce keeps using. Queued scan batches and the watchdog are removed when the module is switched off or the plugin is deactivated. Remove the options and meta by hand if you need a clean database.

## Troubleshooting

| Problem | What to check |
|---|---|
| Everything is Pending | No category resolves to Allow yet: top-level categories start Pending and children inherit. Products with no category stay Pending. |
| A product is Blocked and you don't know why | Open it: the **Google Merchant Policy** box shows the reason. Check the flags, the keyword lists (category names count), a variation's own name, and every category the product is in; one Block or Pending is enough. |
| Allowed, but not in Google | Is Enforce on? Audit only never uploads. Does the box show **Always exclude**, including one kept from Google for WooCommerce? What does its Google for WooCommerce status line say? The scan only requests an upload when it changes a product to `sync-and-show`; an Allowed product that already was is left to Google for WooCommerce's own sync. Saving the product runs it through Google for WooCommerce's save handler again. |
| **Catalog scan** stays Running and **Processed** does not change | Reload the page. Check that WP-Cron and Action Scheduler run (`ffla_google_merchant_policy_reconcile`). **Resume / run next batch** runs one batch immediately. |
| Failed: "Could not schedule the next catalog batch…" | Neither Action Scheduler nor WP-Cron accepted the next batch. Fix scheduling, then Resume. |
| Failed: "Google product IDs exist without a sync timestamp…" | That product has `_wc_gla_google_ids` but no `_wc_gla_synced_at`. Review it in Google for WooCommerce. Resume retries the same product, so the scan cannot move past it until it is fixed. |
| Failed: "Feed exclusion saved, but Google removal could not be requested…" | Google for WooCommerce's sync service is not available, usually because Merchant Center is not connected. Fix the connection, then Resume. |
| Failed: "Could not read the product catalog." or "Could not read the next product batch." | A database error while listing products. |
| The same removals are requested on every scan | Each scan asks again for every excluded product that still has Google product IDs. Check Google for WooCommerce's jobs and Merchant Center. |
| The scan restarted by itself | A product category was saved; any category save starts a new scan. |
| Category badges did not change after editing a dropdown | Badges show saved rules. Save. |
| Products list filters show too few products | They use the stored decision. Run a scan. |
| The Channel visibility box is missing on the product page | Expected in Enforce. Use the **Google Merchant Policy** box. |
| "…is in Enforce mode and decides which products reach Google…" on the Product Feed page | Expected in Enforce. Use the bulk actions in **Products**. |
| Notice asking you to run **Save policies & start catalog scan** once | Shown in Enforce when no scan has run yet or the last one predates upload counting (added in 1.48.0). Run it once so two-way sync and kept exclusions apply to the whole catalog. |

## For developers

**Filter**

- `ffla_google_merchant_hard_block_patterns` (`array $patterns`, `WC_Product $product`): the safety-scan keyword lists as label => regex. Default labels: `ammunition`, `firearm`, `regulated_part`, `weapon_accessory`. Runs only while the safety scan is on, for the product (a variation's parent) and again for a variation itself. The text is lowercased; the `firearm` pattern runs on the text with accessory phrases removed, every other pattern on the full text. Each match adds "Hard block: restricted {label} content detected." (underscores become spaces). Non-string values are ignored.

```php
add_filter('ffla_google_merchant_hard_block_patterns', function ($patterns, $product) {
    $patterns['knife'] = '/\b(switchblades?|gravity knives?)\b/i';
    return $patterns;
}, 10, 2);
```

The module fires no actions or other filters of its own.

**Google for WooCommerce integration** (verified by `tests/smoke/google-merchant-gla-contract.php`)

| Hook or API | Use |
|---|---|
| `woocommerce_gla_get_sync_ready_products_pre_filter` (priority 5) | In Enforce, keeps only Allowed products. |
| `gla/jobs/update_products/process_item`, `gla/jobs/update_all_products/process_item`, `gla/jobs/resubmit_expiring_products/process_item` | Google for WooCommerce's `handle_process_items_action` callback is swapped for a wrapper (on `wp_loaded` priority 100, and at priority -100 of each hook) that filters the item IDs in Enforce. |
| `SyncerHooks::update_by_object()`, `pre_delete()`, `delete()` | Upload and removal requests. The instance is looked up on `woocommerce_update_product`. |
| `rest_pre_dispatch` (priority 10) | In Enforce, refuses POST/PUT/PATCH `/wc/gla/mc/product-visibility` with `WP_Error` `ffla_gmp_visibility_managed`, status 409. |
| `woocommerce_rest_product_variation_object_query` (20), `woocommerce_rest_prepare_product_variation_object` (`PHP_INT_MAX`) | For `gla_syncable=1` requests in Enforce: non-Allowed variations are left out of lists; single requests get 403 `gla_rest_item_no_syncable`. |
| `add_meta_boxes` (priority 100) | In Enforce, removes the `channel_visibility` box. |

**WooCommerce and WordPress hooks:** `woocommerce_new_product`, `woocommerce_update_product`, `woocommerce_new_product_variation`, `woocommerce_update_product_variation` and `woocommerce_process_product_meta` (all priority 80); `created_product_cat` (sets the default rule); `edited_product_cat` (priority 30 saves the edit-screen rule, 40 starts a scan); `woocommerce_admin_process_product_object` (product box); `manage_edit-product_columns`, `manage_product_posts_custom_column`, `restrict_manage_posts`, `pre_get_posts`, `bulk_actions-edit-product`, `handle_bulk_actions-edit-product`.

**Scheduled work**

- `ffla_google_merchant_policy_reconcile`: one batch; argument is the scan ID, and batches for an older scan ID are ignored. Action Scheduler group `ffla-google-merchant-policy`, WP-Cron single event as fallback.
- `ffla_google_merchant_policy_watchdog`: WP-Cron, hourly; re-queues a lost batch while a scan is Running. `wp_loaded` (priority 110) runs the same check on every request while Running.

**Admin requests:** `admin-post.php` actions `ffla_gmp_save`, `ffla_gmp_pause` and `ffla_gmp_run_batch` (`manage_woocommerce` plus nonce). Products list query arg `ffla_gmp_filter` (`allowed`, `blocked`, `pending`, `include`, `exclude`); bulk actions `ffla_gmp_include`, `ffla_gmp_exclude`, `ffla_gmp_follow` (`edit_product` checked per product).

**PHP API** (static methods)

- `Google_Merchant_Policy_Engine::evaluate_product($product_or_id)`: the decision without writing anything: `status` (`allowed`, `blocked` or `pending`), `reason`, `reasons`, `product_id`, `parent_id`. Cached per request; `reset_runtime_cache()` clears it.
- `Google_Merchant_Policy_Engine::apply_to_product($product)`: stores the decision (and in Enforce the Channel visibility) and adds `visibility_change` (`included`, `excluded` or empty). It does not request uploads or removals.
- `Google_Merchant_Policy_Engine::get_effective_category_policy($term_id)`, `set_category_policy($term_id, $policy)` (does not start a scan), `get_override($product)`, `set_override($product, $override)` (the caller saves the product).
- `Google_Merchant_Policy_Reconciler::start()`, `pause()`, `resume()`, `get_state()`.

**Tests**

```bash
php tests/smoke/google-merchant-policy-smoke.php                                  # offline, 90 checks
php tests/smoke/google-merchant-policy-smoke.php /path/to/wp-includes/class-wp-hook.php    # adds real WP_Hook dispatch checks
php tests/smoke/google-merchant-gla-contract.php /path/to/google-listings-and-ads          # 54 checks against a real copy (or set FFLA_GLA_ROOT)
node tests/smoke/google-merchant-policy-ui-smoke.js [screenshot-dir]                        # Playwright + Chrome, settings page
```
