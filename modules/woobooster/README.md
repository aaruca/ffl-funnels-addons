# WooBooster

Product recommendations, product bundles and cart coupon rules for WooCommerce. You write rules ("when a shopper views a 9mm handgun, show 9mm ammo"), or let the Smart strategies (bought together, trending, recently viewed, similar) pick products from your catalog and order history. Module ID: `woobooster`. It is off until it is switched on in **FFL Funnels → Dashboard**, except on the plugin's first activation, when it is switched on automatically if WooBooster tables from an earlier install exist. Needs WooCommerce. Bricks Builder is optional for recommendations but is the only way to show bundles on the storefront. The MCP abilities need WordPress 6.9+ and the MCP Adapter plugin.

## What it does

- **Rules:** conditions on the product being viewed (category, tag, attribute, specific products, entire store) decide when; actions decide what to show (a category, tag, attribute term, hand-picked products or a Smart strategy). AND/OR groups, priorities, schedules and exclusions.
- **Smart Recommendations:** Bought Together, Trending, Recently Viewed and Similar Products, as rule actions or on their own in a Bricks loop.
- **Storefront output:** replaces WooCommerce's related products on classic product templates; three Bricks query loop types; the `[woobooster]` shortcode anywhere else.
- **Apply Coupon rules:** apply an existing WooCommerce coupon automatically while the cart matches, and remove it when it no longer does.
- **Bundles:** "Frequently bought together" sets with a discount or a fixed total, shown by a Bricks element and added to the cart as one line.
- **AI assistant:** drafts rules and bundles from plain language using your real catalog; everything it creates starts inactive.
- **MCP abilities:** your own AI client (Claude, ChatGPT, Cursor) can search the catalog and manage rules.
- **Analytics:** revenue, items, orders and add-to-carts attributed to the rule that recommended them.
- **Tools:** Rule Tester, index diagnostics, JSON export and import of rules.

## Setup

1. **FFL Funnels → Dashboard:** switch on **WooBooster**.
2. **FFL Funnels → WB Settings:** leave **Enable Recommendations** on, set **Frontend Section Title**, switch on the Smart strategies you want and click **Save Settings**.
3. If you switched on **Bought Together** or **Trending Products**, click **Rebuild Now** once instead of waiting for WP-Cron, and read **Index Diagnostics** on the same screen.
4. **WB Rules → Add Rule:** name it, set a condition and an action, click **Create Rule**. Or use **Generate with AI** and review the inactive rule it creates.
5. **WB Diagnostics:** enter a product ID or SKU and click **Test** to see the matching rule and the products shoppers get.
6. Show them:
   - **Classic product template:** nothing to do; they appear below the product summary.
   - **Bricks:** in the single product template, add a Query Loop with type **WooBooster Recommendations** or **WooBooster Smart Recommendations**.
   - **Anywhere else:** use `[woobooster]`.
7. Optional: **Products Bundles → Add Bundle**, then add the **WooBooster Bundle** element to the Bricks product template.

## Settings

**FFL Funnels → WB Settings.** All three cards are saved by **Save Settings**.

| Setting | What it does | Default |
|---|---|---|
| Enable Recommendations | Turns rule and Smart recommendations on or off. When off, classic product pages show WooCommerce's related products and Bricks loops and the shortcode use their fallback. Bundles and coupon rules keep working. | On |
| Frontend Section Title | Heading above recommendations on classic product pages and in the shortcode. Bricks loops do not use it. | You May Also Like |
| Exclude Out of Stock | Leaves out-of-stock products out of rule results, Smart results, bundle dynamic items and the shortcode. Bricks loops have their own checkbox. | On |
| Debug Mode | Writes rule matching, cache hits and query arguments to **WooCommerce → Status → Logs** (source `woobooster`). | Off |
| Delete Data on Uninstall | Deletes WooBooster's rules, bundles, settings and Smart data when the plugin is deleted ([details](#data-and-uninstall)). | Off |
| AI Provider | OpenAI, DeepSeek or NVIDIA NIM. | OpenAI |
| API Key | Key for the selected provider. Replaced by a note when `FFLA_WOOBOOSTER_AI_KEY` is set in `wp-config.php`. | Empty |
| Model Override | Model ID to use instead of the provider default (`gpt-5.6-luna`, `deepseek-chat`, `deepseek-ai/deepseek-v3.2`). | Blank |
| Thinking Mode | Shows the model's chain of thought in the chat. DeepSeek and NVIDIA NIM only. | Off |
| Tavily API Key | Optional. Lets the assistant search the web. Can also be set with `FFLA_WOOBOOSTER_TAVILY_KEY`. | Empty |
| Use with your own AI (MCP) | Status line: whether the MCP abilities are available, and the endpoint. | — |
| Bought Together | Schedules the daily co-purchase build. | Off |
| Trending Products | Schedules the trending build every 6 hours. | Off |
| Recently Viewed | Records the products each visitor views in a cookie. Without it the Recently Viewed source has nothing to show. | Off |
| Similar: key attributes | Comma-separated attribute taxonomies that make two products "the same kind", e.g. `pa_caliber, pa_platform`. Blank uses whichever of `pa_caliber`, `pa_caliber-gauge`, `pa_gauge`, `pa_cartridge`, `pa_platform`, `pa_model`, `pa_action`, `pa_manufacturer` exist. | Blank (auto-detect) |
| Days to Analyze | Order-history window for Bought Together, Trending and Similar's popularity signal. Field accepts 7–365. | 90 |
| Max Relations Per Product | Bought Together products stored per product. Field accepts 5–50. | 20 |

Similar Products needs no index or tracking, so it is always available (it has no switch). Classic product templates always use the WooCommerce-hook output and Bricks templates use the query loops, so there is no rendering-method setting.

Below the settings: **Rebuild Now** runs the enabled builds immediately (always a full rebuild, and it says why a build came back empty); **Clear All Data** deletes the co-purchase lists, trending lists, build stats and cached recommendation results.

## How it works

### Rule matching

- Candidate rules come from a lookup index. Only active rules inside their **Schedule** are checked, lowest **Priority** number first (the oldest rule first when priorities are equal); the first rule whose conditions match is used. Schedule times are entered and shown in the store's time zone.
- Condition groups are joined with OR, conditions inside a group with AND. Action groups work the same way: OR groups are merged and de-duplicated, an AND group returns only products matching every action in it.
- Each action has its own **Limit**. A Bricks **Max Products (override)** or the shortcode `limit` replaces every action's limit and caps the total.

| Condition type | Matches the viewed product when it… |
|---|---|
| Entire store (all products) | is any product (default for a new rule). |
| Category / Tag | has that term; **+ Children** on categories also matches subcategories. |
| Attribute | has that attribute term (pick the attribute, then the value). |
| Specific Product | is one of the chosen products. |

Each condition is **is** or **is not**. **Condition Exclusions** (**Exclude Categories**, **Exclude Products**, **Price Range Filter**): an excluded product, or one priced outside the range, does not satisfy the condition.

| Action source | Recommends |
|---|---|
| Category / Tag | Products in that term; **+ Children** on categories includes subcategories. |
| Attribute | Products with a chosen attribute term, e.g. Caliber = 9mm. |
| Same Attribute | Products that share the viewed product's value of the chosen attribute (pick the attribute, e.g. Caliber: a 9mm product gets other 9mm products). Nothing when the product has no value for it. |
| Bought Together, Trending, Recently Viewed, Similar Products | See [Smart strategies](#smart-strategies). |
| Specific Products | Hand-picked products, in the order chosen. |
| Apply Coupon | No products; applies a coupon to the cart (below). |

- **Order By:** Random, Newest, Price (Low to High), Price (High to Low), Bestselling, Rating. *Random* picks at random among the best sellers of the matching set (a pool of at least 40), not the whole catalog.
- **Action Exclusions:** **Exclude Categories** and **Exclude Products** remove products; **Price Range Filter** keeps only products inside the range.
- Out-of-stock products follow the global **Exclude Out of Stock** setting, or the Bricks loop's own checkbox. (The old per-rule toggle never had an effect and is no longer shown.)

### Apply Coupon rules

- Pick an existing coupon (**Marketing → Coupons**) and optionally a **Custom Cart Message**, shown when the coupon is applied (default: "Coupon "CODE" has been automatically applied based on your cart!"). These rules are checked against the cart on every totals calculation, not against the product page.
- For each condition, the quantities of matching, non-excluded cart items are added up and must reach the small **Qty** box beside it ("Min cart qty"), e.g. 3 boxes of ammo. Every matching rule applies its coupon; priority does not pick one winner. A coupon WooBooster applied is removed when the cart stops matching.
- With **Entire store**, every cart item counts (minus condition exclusions), so "any 3 items" works. Unpublished, expired or used-up coupons are skipped, and WooCommerce's own coupon checks still run.

### Smart strategies

- **Bought Together:** ranks pairs by how much more often they sell together than chance, so items in most carts anyway (ammo, fees) do not top every list, and drops pairs that do no better than chance. Reads completed and processing orders within **Days to Analyze**; large orders weigh less and orders with more than 40 products are skipped; with 200+ multi-item orders a pair needs 2 shared orders; virtual products (transfer fees, gift cards, protection plans) are left out. The daily run skips itself when no order in the window changed.
- **Trending:** each order counts once whatever the quantity, and its weight halves every 14 days. Ranked per category, with parent categories including their subcategories' sales; the top 50 per category plus a store-wide list are kept for 2 days. Products whose categories have no list use the store-wide list.
- **Recently Viewed:** the visitor's cookie (last 20 products, kept 30 days). Never cached.
- **Similar Products:** up to 500 candidates that share a term with the viewed product, scored on brand (`product_brand`, `pa_brand`, `pa_manufacturer`), key attributes, categories, tags, price closeness, recent sales, newness and shipping class.
- **Fallback:** when a Smart source finds fewer products than its limit, it fills the gap with best sellers from the viewed product's categories, then the store-wide trending list, then the newest products. Inside an AND group with other sources, Bought Together, Trending and Recently Viewed do not fill, so "bought together AND holsters" returns only holsters actually bought together.

### Bundles

| Field | Behaviour |
|---|---|
| Bundle Items (Manual) | Products in the bundle; the picker hides out-of-stock products. **Qty** sets units per item; the widget shows "2 × Name" and its totals, like the cart, count every unit. Items not marked **Optional** are required: their box is ticked and locked, and the cart refuses the bundle without them. |
| Bundle Items (Dynamic) | Same sources as rule actions except Apply Coupon, resolved for the product being viewed. The shopper may untick them. |
| Conditions | Which product pages show the bundle: the rule condition types plus **User Role** (Guest or any WordPress role). Leave empty for a bundle you only show by pinning it in Bricks. |
| Pricing Mode | **Discount on items**: *Percentage* off each item, or *Fixed Amount* off the whole set (every unit), split across items by price. **Fixed bundle price**: one total for the whole set, quantities included, split across items by price; a total at or above the set's combined price gives no discount. Prices are rounded per unit, so totals can differ by a cent. |
| Bundle Image | Replaces the first product's thumbnail in the cart and checkout. |
| Priority, Status, Schedule | Lowest priority wins when several bundles match (the oldest on a tie). Schedule times are in the store's time zone. |

- Every item shows ticked; the shopper can untick optional and dynamic items. Prices are always worked out over the full bundle, so unticking one does not change the others. If a required item is out of stock or needs a variation choice, the bundle cannot be added.
- The bundle goes into the cart as one line on the first selected product (the "representative"), named after the bundle, with an **Includes** list, priced at the bundle total. Raising its quantity buys whole bundles; the line cannot be split.
- WooCommerce tracks stock, tax class and shipping for the representative product only; the others are recorded on the line as **Bundle contents**. Variable products need default attributes. If a bundle is switched off, deleted or its schedule ends while in a cart, the line is removed with a notice.

### AI assistant

- **Generate with AI** on **WB Rules** or **Products Bundles**. The assistant searches your catalog (products by title, description or SKU; categories, tags, attribute terms), lists existing rules or bundles, and searches the web when a Tavily key is set.
- Rules are checked against the catalog before they are proposed; **Create This Rule** validates again and saves the rule inactive. **Create This Bundle** checks the products and condition against the catalog and saves the bundle inactive. A proposal carrying a `rule_id` updates that rule, rewriting it as one condition and one action.
- At most 5 model calls per message (reply "continue" if it stops there); searches are reused for 15 minutes per user; AI-created rules get a limit of 1–24. Chat history stays in your browser (last 20 messages); **Clear** erases it.

### Caching and performance

- Rule and Smart results are cached for 1 hour (including "no rule matched"), Similar Products for 6 hours. Without Redis or Memcached they are also stored as transients so the cache outlives the request. Results that use Recently Viewed are never cached, and neither are pinned bundles with a Recently Viewed source.
- Saving, switching or deleting a rule or bundle, saving settings and **Clear All Data** clear the cache at once. Product edits (stock, price, categories) do not: results catch up within the cache time.
- A **+ Children** condition indexes up to 500 child terms. Bought Together and Trending work with HPOS and posts order storage, using WooCommerce's analytics lookup table when present and order item meta otherwise.

### Analytics attribution

- Products shown by a rule (or a Smart loop) are remembered in the shopper's WooCommerce session, up to 500. When one of them is added to the cart later in that session, from any page, that successful add is counted in that day's add-to-carts. This is session-based exposure attribution, not click tracking.
- Attribution is attached **after** WooCommerce chooses the cart line, so analytics never change cart-item identity. Identical ordinary/recommended adds merge; variations, bundle unique keys and fulfillment options still participate in identity normally. `_wb_attribution_quantities` records attributed units per rule (`-1` = Smart); organic units are the remainder. `_wb_source_rule` is retained as a compatibility tag naming the rule with the most attributed units, not the source of truth for new orders.
- Cart quantity reductions scale credits proportionally (units are fungible); manual increases are organic. This can produce fractional attribution credits, kept to four decimals and shown with one decimal in **WB Analytics** when not whole. Removing/undoing a cart line preserves its snapshot. Subsequent successful recommended adds receive fresh credits. Add-to-cart counters remain event counts, not quantities.
- **WB Analytics** credits only attributed quantities and their proportional line subtotal/tax in completed and processing orders, scanning at most 5,000 orders per period. Historic orders with only `_wb_source_rule` still credit the full line; a new quantity map takes precedence, even if empty. Bundle widgets do not register recommendations.
- Existing split cart keys and historic orders are not automatically consolidated or rewritten. Test with a fresh cart after updating; customers with pre-update split lines can remove and re-add them.

## Where it shows up

| Place | What appears |
|---|---|
| **FFL Funnels → WB Settings** | Settings, Index Diagnostics (orders in window, multi-item vs single-item orders), **Rebuild Now**, **Clear All Data**, last build stats. |
| **… → WB Rules** | Rule list: search, sort, edit, duplicate (copies start inactive), activate/deactivate, delete, and the same as bulk actions. **Export** (every rule), **Import** (adds to existing rules; up to 500 per file, 2 MB), **Delete All** (rules only), **Generate with AI**. |
| **… → Products Bundles** | Bundle list and form, **Generate with AI**. |
| **… → WB Diagnostics** | Rule Tester for a product ID or SKU: matched rule with its condition groups, what each action found, the final recommendations, time taken and condition keys. |
| **… → WB Analytics** | Completed and processing orders in a date range (default last 30 days, with presets): WB revenue, tax, items, share of revenue, WB orders, average order, add-to-carts and conversion rate, with trends against the previous period; revenue charts; top 10 rules and products. |
| **… → WB Docs** | Short in-admin guide. |
| Classic product template | Replaces WooCommerce's related products on `woocommerce_after_single_product_summary` with `<section class="woobooster-related products">` using the theme's product cards. With no match, WooCommerce's related products (4, random). |
| Bricks Query Loop | **WooBooster Recommendations**, **WooBooster Smart Recommendations**, **WooBooster Bundles**. Their settings appear on Container, Block and Div elements. |
| Bricks element | **WooBooster Bundle** (category *FFL Funnels*): checkbox widget with totals, savings and **Add Selected to Cart**. |
| Shortcode | `[woobooster product_id="" limit="" fallback=""]` |
| Cart and checkout | Coupons applied by Apply Coupon rules; bundle lines with name, Includes list and image. |
| Orders | `_wb_source_rule` and `_wb_attribution_quantities` on attributed lines; `_woobooster_bundle_id` and **Bundle contents** on bundle lines. No emails of its own. |

- **WooBooster Recommendations loop:** **Specific Rule** (*Auto* matches by priority; a pinned rule skips conditions but still needs to be active and in schedule), **Product Source** (Current Product, Manual Product ID, Last Added to Cart), **Max Products (override)**, **Exclude Out of Stock** (on by default; replaces the global setting for this loop), **Fallback if No Match** (Show Nothing, WooCommerce Related (default), Recent Products, Bestselling Products).
- **WooBooster Smart Recommendations loop:** **Smart Strategy** (Similar (default), Frequently Bought Together, Trending, Recently Viewed), Product Source, **Max Products** (default 4), Exclude Out of Stock, **Fallback if Empty**. No rule is involved; analytics shows these loops as one **Smart (all)** row.
- **WooBooster Bundles loop and WooBooster Bundle element:** **Specific Bundle** or *Auto* (first matching bundle by priority). The element's Product Source also offers *None (display bundle as-is)* for home or landing pages.
- **Shortcode:** `product_id` defaults to the current product; without `limit` the rule's own limits apply; `fallback` is `none` (default), `woo_related`, `recent` or `bestselling`.

## Data and uninstall

| Data | Contents |
|---|---|
| Tables | `{prefix}woobooster_rules`, `_rule_conditions`, `_rule_actions`, `_rule_index`; `{prefix}woobooster_bundles`, `_bundle_items`, `_bundle_actions`, `_bundle_conditions`, `_bundle_index`. |
| Options | `woobooster_settings` (not autoloaded; may hold API keys; also holds the `rule_dates_gmt` migration flag), `woobooster_version`, `woobooster_db_version` (`1.10.0`), `woobooster_last_build`, `woobooster_atc_counter` (add-to-carts per rule per day; older versions wrote per month), `woobooster_cache_version`. |
| Meta | Product: `_woobooster_copurchased`. Order item: `_wb_source_rule`, `_wb_attribution_quantities`, `_woobooster_bundle_id`, **Bundle contents**. |
| Transients | `wbrc_*` (cached results), `wb_trending_cat_{term_id}` and `wb_trending_global` (2 days), `wb_ai_*` (15 minutes). |
| Session, cookie, browser | WooCommerce session keys `woobooster_recommendations` and `woobooster_auto_coupons`; cookie `woobooster_recently_viewed`; localStorage `wb_ai_chat_history`, `wb_ai_bundle_history`. |
| Cron | `woobooster_copurchase_event` (daily) and `woobooster_trending_event` (schedule `woobooster_6hours`), added or removed to match the Smart toggles. |

- Switching the module off only unschedules the cron events. Schema updates run when it is switched on and on the next admin page load after an update; missing tables are recreated when a WooBooster screen is opened. Rule schedules saved by older versions (stored as typed and read as UTC) are converted to GMT once, on the first admin page load, so they now mean store time.
- Deleting the plugin cleans up WooBooster only if the module is on at that moment. The cron events are always cleared. With **Delete Data on Uninstall** on, it also drops the nine tables and deletes the options above, the `_woobooster_copurchased` meta and two legacy update transients. Order item meta stays; the other transients expire on their own.

## Troubleshooting

- **No recommendations on a product:** test it in **WB Diagnostics**. "No rule matched." means no active rule's conditions fit: check status, schedule, exclusions and priorities. If a rule matches but returns nothing, check stock and the action's value and exclusions. **Debug Mode** logs the full query.
- **Changes not showing yet:** results are cached up to an hour (6 hours for Similar). Saving a rule or the settings clears the cache; product edits do not.
- **Bought Together or Trending empty:** **Index Diagnostics** says "No eligible orders." (raise **Days to Analyze** or add your order status with the status filters) or that all orders have a single product (Bought Together cannot pair anything; Trending is unaffected). **Rebuild Now** shows the reason next to its counts. Without working WP-Cron the indexes are built only by that button.
- **"OpenAI API Key is required…"** (or DeepSeek / NVIDIA NIM): add the key under **AI Assistant** or define `FFLA_WOOBOOSTER_AI_KEY`.
- **"AI service error. Please try again.":** the provider rejected the call; with `WP_DEBUG` on, its message goes to the PHP error log.
- **MCP status "Needs WordPress 6.9 or newer" or "no MCP server is running":** update WordPress, or install the MCP Adapter plugin (or one that includes it, such as Novamira).
- **Import fails:** "Import payload too large (max 2 MB).", "Invalid JSON file." or "Maximum 500 rules per import." Imported rules keep everything in the file, including their status (active rules go live at once), schedules, exclusions, min quantities and action groups. Apply Coupon actions are matched by coupon code first, so the coupon must exist on the importing site.
- **Coupon not applied:** the coupon must be published, unexpired and not used up, pass WooCommerce's own checks, and the cart must reach each condition's Qty.
- **Bundle add-to-cart errors:** "This bundle offer is not currently available." means it is outside its schedule; "Bundle requires all items to be selected." means a required item was left out; "…requires choosing a variation…" means the variable product has no default attributes; "Product #… is not available." means out of stock or not purchasable (fatal for a required item).
- **"This range contains more than 5,000 orders" in WB Analytics:** narrow the range; the figures shown are partial. Add-to-carts are counted per day; those recorded by older versions are per month and count when their month overlaps the range.
- **"WooBooster admin could not be loaded.":** switch the module off and on in **FFL Funnels → Dashboard**.

## For developers

**Filters**

| Filter | Arguments (default) | Purpose |
|---|---|---|
| `woobooster_copurchase_order_statuses` | `string[]` (`wc-completed`, `wc-processing`) | Orders read for Bought Together and Index Diagnostics. |
| `woobooster_trending_order_statuses` | `string[]` (same) | Orders read for Trending. |
| `woobooster_copurchase_bulk_order_cap` | `int` (40) | Orders with more distinct products are skipped. |
| `woobooster_copurchase_min_support` | `int` (2 with 200+ multi-item orders, else 1), `int` multi-item orders | Minimum shared orders per pair. |
| `woobooster_smart_exclude_virtual` | `bool` (true) | Leave virtual products out of Bought Together and Trending. |
| `woobooster_smart_excluded_products` | `int[]` | More product IDs to leave out of both. |
| `woobooster_trending_half_life_days` | `float` (14) | Days for a sale's weight to halve. |
| `woobooster_similar_weights` | `array` (brand 5, key_attr 3, category 2, tag 1, price_max 2, popularity 1, recency 0.5, shipping 1, oos_penalty -1) | Similar Products scoring. |
| `woobooster_similar_brand_taxonomies` | `string[]` (`product_brand`, `pa_brand`, `pa_manufacturer`) | Taxonomies treated as brand. |
| `woobooster_similar_key_attributes` | `string[]` (setting or auto-detect) | Key attribute taxonomies. |
| `woobooster_similar_ignored_taxonomies` | `string[]` (`product_type`, `product_visibility`, `product_shipping_class`) | Ignored when finding candidates. |
| `woobooster_max_index_children` | `int` (500) | Child terms indexed per **+ Children** rule condition. |
| `woobooster_ai_max_turns` | `int` (5) | Model calls per assistant message. |
| `woobooster_ai_reasoning_effort` | `string` (`low`), `string` model | Sent to OpenAI `gpt-5*` models; return `''` to omit. |

No public actions, WP-CLI commands or REST routes of its own. The cron hooks can be run with `wp cron event run`; a scheduled co-purchase run skips itself when the order window is unchanged (only **Rebuild Now** forces it).

**PHP API**

- `( new WooBooster_Matcher() )->get_recommendations( $product_id, [ 'limit' => 4, 'exclude_outofstock' => true ] )` returns product IDs; the matched rule is then in `WooBooster_Matcher::$last_matched_rule`.
- `->get_recommendations_by_rule( $rule_id, $product_id, $args )` and `->get_smart_recommendations( $product_id, $source, $args )` with `$source` = `copurchase`, `trending`, `recently_viewed` or `similar`.
- `WooBooster_Matcher::invalidate_recommendation_cache()` clears every cached result.
- `( new WooBooster_Bundle_Matcher() )->get_bundles_for_product( $product_id )` returns matching bundles with `->resolved_items`.
- `WooBooster_Bundle::calculate_item_prices( $bundle, $product_ids, $quantities = null )` returns per-unit original and discounted prices (quantities default to the bundle's item quantities); `WooBooster_Bundle::get_required_product_ids( $bundle_id )` lists the items not marked Optional.
- `woobooster_get_option( $key, $default )` reads one setting.

**AJAX** (`admin-ajax.php`)

- Admin, `woobooster_admin` nonce and `manage_woocommerce`: `woobooster_export_rules`, `woobooster_import_rules`, `woobooster_delete_all_rules`, `woobooster_rebuild_index`, `woobooster_purge_index`, `woobooster_ai_generate`, `woobooster_ai_create_rule`, `woobooster_ai_create_bundle`, `woobooster_search_terms`, `woobooster_search_products` (`context=bundle` hides out-of-stock products), `woobooster_search_coupons`, `woobooster_resolve_product_names`, `woobooster_toggle_rule`, `woobooster_toggle_bundle`, `woobooster_test_rule`.
- Storefront (guests and logged-in), `woobooster_bundle_cart` nonce: `woobooster_add_bundle_to_cart` with `bundle_id`, `source_product_id`, `product_ids[]`.

**MCP abilities** (WordPress 6.9+, category `woobooster`, `manage_woocommerce`, marked public for MCP)

| Ability | Does |
|---|---|
| `woobooster/search-catalog` | Searches products (title, description, SKU), categories, tags or attribute terms (`type`, `query`, `limit` up to 20). |
| `woobooster/list-rules` | Every rule with its groups, priority and status. |
| `woobooster/validate-rule` | Checks a rule against the catalog without saving; `rule_id` for changes. |
| `woobooster/create-rule` | Validates and creates the rule inactive, with one condition and one action. |
| `woobooster/update-rule` | Changes the given fields; rewrites the rule as one condition and one action. |
| `woobooster/set-rule-status` | Turns a rule on or off (`rule_id`, `active`). |
| `woobooster/diagnose-product` | Matched rule, per-action counts and final recommendations. |

AI and MCP rule fields: `condition_attribute` is `product_cat`, `product_tag`, `specific_product` or a `pa_*` taxonomy; `condition_operator` is `equals` or `not_equals` (a legacy `contains` is accepted, saved as `equals` with a warning); `action_source` is `category`, `tag`, `attribute_value` (`pa_x:term`), `specific_products` (IDs in `action_products`), `copurchase`, `trending`, `similar` or `recently_viewed`.

**Constants:** `FFLA_WOOBOOSTER_AI_KEY` and `FFLA_WOOBOOSTER_TAVILY_KEY` in `wp-config.php` take priority over the saved keys. Compatibility constants: `WOOBOOSTER_VERSION` (= `FFLA_VERSION`), `WOOBOOSTER_DB_VERSION`, `WOOBOOSTER_PATH`, `WOOBOOSTER_URL`, `WOOBOOSTER_FILE`, `WOOBOOSTER_BASENAME`.

**Tests:** `php tests/smoke/woobooster-smart-smoke.php` (Bought Together and Trending scoring), `php tests/smoke/woobooster-bundle-pricing-smoke.php` (bundle pricing with quantities) and `tests/unit/BundleDiscountMathTest.php` (PHPUnit, bundle discount math).
