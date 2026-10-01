# Loadout

Tiered "build your loadout" offers on product pages: a main product plus tiers (for example Essential, Performance, Elite) of recommended accessories, each tier with its own discounts, a perks list and an optional free bonus product. It is for stores that sell a firearm together with curated accessory packages, and for the developer who builds the product template. Module ID: `loadout`, off until it is switched on in **FFL Funnels → Dashboard**. Requires WooCommerce. The storefront is rendered by Bricks elements, so Bricks Builder is needed for them and for the dynamic tag; the `[loadout]` shortcode works without Bricks.

> **Read this first.** Loadout discounts are taken off the product's **regular** price, so a product on sale loses its sale price whenever a loadout discount applies, and every product inside an "add entire tier" line is priced from its regular price. Some admin help texts describe behaviour the storefront does not have; check [Known limitations](#known-limitations) and place a test order before launch.

## What it does

- **Global loadouts** (FFL Funnels → Loadout): reusable configurations with a headline, branding images, an anchor ("hero") product, tiers, tier items and cross-sell tiles. One loadout can be linked to many products.
- **Per-product loadouts** (product editor → Loadout tab): link a global loadout, or build tiers that belong to that product only.
- **Storefront**: tier tabs; under each tab a "Recommended *Tier* Setup" list with image, name, star rating, "% OFF" badge, struck regular price, loadout price and an **Add** button per item, an **Add cart** button that adds the whole tier as one cart line, the tier's perks and its bonus product.
- **Cart**: loadout pricing, a single "entire tier" line with an itemised breakdown and savings, an automatic bonus product, and loadout details saved on the order.
- **Bricks**: four elements and the `{ffla_product_has_loadout}` dynamic tag. **Shortcode**: `[loadout]`.

## Setup

1. Switch on **Loadout** in **FFL Funnels → Dashboard**. Its four database tables are created.
2. Open **FFL Funnels → Loadout** → **Add New**. Enter a **Name**, optionally a **Headline**, pick the **Hero Product**, then **+ Add Tier** for each tier and **+ Add Item** for its products. Click **Create Loadout**.
   - Add new tiers, save, then add items to them. Items added to a tier that has not been saved yet are posted into the first tier (see [Known limitations](#known-limitations)).
3. Edit a product → **Product data → Loadout**. Either pick a loadout in **Link to Global Loadout**, or leave it on "— Use per-product config below —" and build tiers under **Per-Product Configuration**. Click **Update**.
4. In Bricks, open the single product template and add **Loadout: Tier Tabs** (tabs and products in one element) or **Loadout** (the full widget). Leave **Loadout** on "— Auto-detect from current product —". Optionally add **Loadout: Progress Bar** and **Loadout: Cart Mirror**, and show the section only when `{ffla_product_has_loadout}` is `1`.
5. Without Bricks, put `[loadout id="12"]` or `[loadout slug="ar-15-build"]` on a page.
6. Add items and a whole tier to the cart and place a test order to check prices and stock.

## Settings

There is no settings screen. Everything is set on the loadout form and in the product editor.

### Loadout form (FFL Funnels → Loadout → Add New / Edit)

| Setting | What it does | Default |
|---|---|---|
| Name | Required. Admin label. Customers also see it as the title when **Headline** is empty and as the "Loadout" line under cart items. | — |
| Status | *Active* or *Inactive*. Inactive loadouts disappear from the Bricks element dropdowns and the product editor dropdown, stop resolving for linked products, and the shortcode outputs nothing. | Active |
| Headline | Title at the top of the widget. Falls back to **Name**. | empty |
| Subheadline | Small text under the headline. | empty |
| Hero Image | Large image in the anchor column. Falls back to the anchor product's own image. | none |
| Brand Logo | Small logo above the title. | none |
| Hero Product | The anchor product: shown with its price and an **Add hero** button, and placed first in every **Add cart** line. | none |

### Tier fields

| Setting | What it does | Default |
|---|---|---|
| Tier Name | Required. Tab label. A slug is made from the name when the tier is first saved; renaming the tier later keeps the old slug. | — |
| Accessory Discount % | Added to each item's own **Discount %** when the item is added from this tier, one at a time or through **Add cart**. | 0 |
| Set Discount % | Added on top for the tier's items inside an **Add cart** line. | 0 |
| Perk Threshold | Units from this tier the cart must hold before the **Bonus Product** is added. `0` means no bonus. Also the target of the progress bar. | 0 |
| Perks | Entered one per line, listed under "Perks Unlocked at Threshold:" in the tier panel (always shown, whatever the cart holds). Display only. Global tiers currently store them as one line, see [Known limitations](#known-limitations). | empty |
| Bonus Product (Free Gift) | Product added to the cart once the threshold is reached and removed when the cart drops below it. | none |
| Bonus Label | Heading of the bonus block. Without one the block reads "FREE Bonus Item". | empty |
| Bonus Display Value | Shown as "Valued at $X". Display only. | empty |

### Tier item fields

| Setting | What it does | Default |
|---|---|---|
| Product | Search field (type two or more characters). Lists the first 20 published products that are not out of stock, with SKU, price and a stock chip. | — |
| Qty | Quantity added by the item's **Add** button and inside **Add cart** lines. | 1 |
| Discount % | The item's own discount. Added to the tier's discounts; the combined total is capped at 100%. | 0 |
| Pre-checked | Saved, but not used by the storefront. | off |

### Cross-sell tiles

| Setting | What it does | Default |
|---|---|---|
| Label | Tile text. Tiles without a label are dropped on save. | — |
| Image | Tile image. | none |
| Link type + value | *Category*: a product category **slug**. *URL*: a full address. *Loadout*: a loadout **slug**, linked as `#loadout-<id>` on the same page. Only the Bricks **Loadout** element uses the link; shortcode tiles link to `#`. | Category |

### Product editor (Product data → Loadout)

| Setting | What it does | Default |
|---|---|---|
| Link to Global Loadout | An active loadout to use for this product. When set, the per-product tiers below are ignored. | "— Use per-product config below —" |
| Per-Product Configuration | Tiers with the same fields as a global tier (**Tier Name**, **Accessory Discount %**, **Set Discount %**, **Perk Threshold**, **Perks (one per line)**, **Bonus Product**, **Bonus Label**, **Display Value**) and items (**Product**, **Qty**, **Discount %**, **Pre-checked**). A tier with items but no name is saved as "Tier 1", "Tier 2"…; items without a product are dropped. Some fields behave differently from global tiers, see [Known limitations](#known-limitations). | none |

## How it works

### Which loadout an element shows

All elements and the dynamic tag resolve the configuration in this order:

1. The loadout picked in the element's **Loadout** dropdown. This is used even if the loadout is later set to *Inactive*.
2. The product's **Link to Global Loadout**, if that loadout exists and is active.
3. The product's per-product tiers.
4. Otherwise the element shows "No loadout configured for this context."

The current product is taken from the single product page, WooCommerce's current `$product`, or the post being rendered (e.g. inside a Bricks product loop).

Tabs switch every panel on the page with the same tier slug, so the tabs and the product panels can sit in different elements.

### Prices shown in the tier panels

Item price = regular price × (1 − (item **Discount %** + tier **Accessory Discount %**)), capped at 100%. A product without a regular price uses its current price. The badge shows the rounded combined percentage. **Set Discount %** is not shown in the panel.

### What the cart charges

| Customer action | Cart line | Price |
|---|---|---|
| **Add** on an item from a global loadout tier | Its own line at the item's **Qty**. Every click adds a new line; loadout lines never merge. | Regular price × (1 − (item % + accessory %)). With 0% the normal (sale) price stays. |
| **Add** on an item from a per-product tier | Its own line. | Normal price, no loadout discount (see [Known limitations](#known-limitations)). |
| **Add hero** (anchor) | Its own line, quantity 1. | Normal price. |
| **Add cart** (entire tier) | One line that cannot merge or be split: the anchor product, or the first tier item when there is no anchor, quantity 1. | Anchor at its regular price, plus each tier item at regular price × (1 − (item % + accessory % + set %)) × **Qty**. |

About the **Add cart** line:

- The anchor is the global loadout's **Hero Product**. On a product linked to a global loadout this is the loadout's anchor, not necessarily the product being viewed. Per-product tiers have no anchor, so the line holds only the tier items.
- A tier item that is the same product as the anchor is not added twice. Items that are not purchasable are left out silently. Item stock is not checked: only the product that carries the line goes through WooCommerce's stock check.
- Prices are fixed when the line is added; the server recalculates the line total from its stored breakdown, never from a total sent by the browser. Later price edits do not change lines already in a cart.
- The cart and checkout show **Includes** with each product (linked), its quantity, the struck original price, the final price, "Save $X", and a "Total Loadout Savings" line. The anchor carries a "Main" badge. The order line gets an **Includes** meta listing the products and quantities.
- When WooCommerce reduces stock for the order, each product in the breakdown is reduced by its quantity; when the order's stock is restored (for example on cancel), it is increased again.

### Bonus product

For **global** tiers with a **Bonus Product** and a **Perk Threshold** above 0: each time the cart is loaded and each time a line is removed, the module counts the units in the cart from that loadout tier (bonus excluded). At or above the threshold it adds the bonus product (quantity 1, shown as "Bonus: Free Gift"); below it, it removes it. Per-product tiers never add a bonus. See [Known limitations](#known-limitations) for the bonus price.

### After a click

The button shows "Adding...", then "Added!" and stays disabled until the page reloads. WooCommerce's mini-cart fragments are refreshed and every cart panel and progress bar on the page reloads. On the cart or checkout page the browser goes to the cart page instead. On any failure the button shows "Could not add item." for two seconds.

### Cart panel and Cart Mirror

Loaded by AJAX when the page opens and after each add: each cart line with "×quantity" and its current price, then "Savings:" (regular minus current price for each line, plus the breakdown savings of **Add cart** lines) and "Total:" (the cart total). The list is limited to lines from the resolved global loadout. When no global loadout resolves (per-product tiers, or a page that is not a product), it lists the **whole cart**, including products that have nothing to do with a loadout.

### Progress bar

Fill = cart lines from the active tier ÷ the active tier's **Perk Threshold**. The label changes from the placeholder to "N more item(s) to unlock perks" and then "Perks unlocked!". The bar reads the active tier and threshold from a tier panel on the same page, so it needs **Loadout: Tier Tabs** (with products) or **Loadout** on that page. It counts cart lines, not quantities, and does not move for per-product tiers.

### Caching and loading

- The front-end script and stylesheet load on every front-end page.
- Configuration is not cached; each render reads the tables (one query per tier and per item).
- Panel prices are part of the page HTML. The cart panel and progress bar load by AJAX, so they are current on cached pages, but every add request carries a security token printed in the page. A cached page older than WordPress's token lifetime makes every button fail.

## Where it shows up

### Admin

| Screen | What it does |
|---|---|
| **FFL Funnels → Loadout** | The *Loadouts* list: search, sort by Name or Status, 20 per page, columns Name, Anchor Product, Tiers, Status and Edit / Delete. **Add New** opens the form. |
| **Products → edit → Product data → Loadout** | Link a global loadout or build per-product tiers. Item and bonus rows show price and a stock chip ("Out of stock", "On backorder", "N in stock", "In stock"). |
| **Products** list | Filter "All loadouts / With loadout / Without loadout". *With loadout* = a linked loadout ID or saved per-product tiers. |

### Bricks elements

All four are in the **WooCommerce** element category. **Default Tier Index** counts from 0 (first tier).

| Element | Main controls | What it renders |
|---|---|---|
| Loadout | **Loadout** (auto-detect or a loadout), **Default Tier Index** (0), **Show Cart Panel** (on), **Show Cross-Sells** (on); Style: **Accent Color**, **Background Color** | The full widget: header (global loadouts only), tabs, anchor column (global: hero image, name, price, **Add hero**; per-product: the current product's image, name and price, no button), tier panels, "Your Cart" panel, "Complete Your Loadout" tiles (global only) and a **Proceed to checkout** button. |
| Loadout: Tier Tabs | **Loadout**, **Default Tier Index** (0), **Show products under tabs** (on) | Tabs, and the tier panels below them unless switched off. |
| Loadout: Progress Bar | **Loadout**, **Placeholder Label** ("Add items to unlock perks"); Style: **Bar Color** | Progress bar and label. |
| Loadout: Cart Mirror | **Filter by Loadout** ("— Auto-detect / all loadouts —"), **Heading** ("Your Cart") | The cart panel on its own. |

### Dynamic tag

`{ffla_product_has_loadout}` (picker group *FFL Funnels*, label "Product has Loadout") returns `1` when the product resolves to an active linked loadout or has per-product tiers, and an empty value otherwise. Typical use: an element condition with dynamic data `{ffla_product_has_loadout}` equal to `1`. It returns `1` for a linked loadout even when that loadout has no tiers.

### Shortcode

`[loadout id="12"]` or `[loadout slug="ar-15-build"]` (slug wins when both are given).

| Attribute | What it does | Default |
|---|---|---|
| `id` / `slug` | Which global loadout. | — |
| `default_tier` | Tier shown first, counting from 0. | `0` |
| `show_cart` | `no` hides the "Your Cart" panel. | `yes` |
| `show_cross_sells` | `no` hides the cross-sell tiles. | `yes` |

It renders the header, progress bar, tabs, anchor with **Add hero**, tier panels (without ratings, perks or bonus block), cart panel, cross-sell tiles (linked to `#`) and **Proceed to checkout**. It outputs nothing for an inactive loadout or one without tiers.

## Data and uninstall

- Tables: `{prefix}ffla_loadouts`, `{prefix}ffla_loadout_tiers`, `{prefix}ffla_loadout_tier_items`, `{prefix}ffla_loadout_cross_sells`. Option: `ffla_loadout_db_version`.
- Product meta: `_ffla_product_loadout_link` (linked loadout ID, `0` for none), `_ffla_product_loadout_tiers` (per-product tiers as JSON), `_ffla_loadout_last_save` (time of the last save from the product editor). `_ffla_product_loadout_enable_tab` is a retired key that is no longer read or written.
- Cart and order line meta: `_ffla_loadout_id`, `_ffla_loadout_tier_id`, `_ffla_loadout_tier_slug`, `_ffla_loadout_source`, `_ffla_product_loadout_id`, `_ffla_loadout_item_id`, `_ffla_loadout_is_bonus`, `_ffla_tier_bundle`, `_ffla_tier_bundle_items` (the breakdown used for stock), plus the visible **Includes** meta. Only non-empty values are copied to the order.
- Deleting a loadout deletes its tiers, items and cross-sells. Products linked to it keep the link meta and fall back to their per-product tiers.
- Deactivating the module changes nothing. **Uninstall keeps all Loadout data** unless the module is active at uninstall time and the option `ffla_loadout_settings` holds `delete_data_uninstall` set to a true value. There is no admin setting for this; set the option yourself. Then the four tables, `ffla_loadout_settings` and `ffla_loadout_db_version` are removed. Product meta and order line meta are never removed.
- Media Cleaner treats loadout hero images, brand logos and cross-sell images as in use.

## Troubleshooting

- **"No loadout configured for this context."** The product has no active linked loadout and no per-product tiers, or the element sits outside a product context with no loadout picked. Check the product's Loadout tab and the loadout's **Status**.
- **Panels are empty until a tab is clicked.** **Default Tier Index** is higher than the number of tiers minus one.
- **Two panels show at once.** Two tiers have the same slug (same name when created). Rename and re-create one of them.
- **"Could not add item."** The product is a variable product (the product search lists parent products, which WooCommerce cannot add without a variation), is not purchasable, is out of stock, or the page came from a cache older than the security token. Use simple products in tiers and keep loadout pages out of long-lived page caches.
- **The linked loadout disappeared from a product.** The loadout was *Inactive* when the product was saved; the dropdown only lists active loadouts, so saving stored "no link". Activate the loadout and link it again.
- **The sale price is gone in the cart.** Loadout discounts start from the regular price (see *Read this first*).
- **The Cart Mirror lists products outside the loadout.** No global loadout resolved, so it shows the whole cart. Pick a loadout in **Filter by Loadout**.
- **The progress bar stays empty.** The page needs a tier panel, the tier needs a **Perk Threshold** above 0, and per-product tiers are not supported.
- **Bulk actions on the Loadouts list do nothing.** Use the row **Delete** button or change **Status** on the form.
- **After Delete the list page stops rendering.** The loadout was deleted; reload **FFL Funnels → Loadout**.
- **Removing a tier in the product editor asks twice.** Two scripts each confirm; answer both.

### Known limitations

These are current behaviours that differ from the admin help texts:

- Items added one at a time from a **per-product** tier get no loadout discount, although the panel shows one. Their discounts apply only inside an **Add cart** line.
- **Set Discount %** applies only inside **Add cart** lines. The help text about a product page tab and reverting when an item is removed refers to a removed feature.
- The **bonus product** is added and labelled "Free Gift" but is charged at its normal price. Per-product tiers never add a bonus. A **Perk Threshold** of 0 disables the bonus (the help texts say "always unlocked").
- **Perks** on a global tier are saved as one line; line breaks are lost. Per-product perks keep one perk per line.
- In an **Add cart** line, the stock of the product that carries the line is reduced twice; component stock ignores the line quantity if the customer changes it in the cart; and refunds with restock do not restock the components.
- **Pre-checked** is not used. **Bonus Label** does not fall back to the product name. Cross-sell **Link value** does not accept an ID.

## For developers

- Loadout has no filters or actions of its own. It runs WooCommerce's `woocommerce_add_to_cart_fragments` filter when it builds its AJAX responses.
- Front-end AJAX (`admin-ajax.php`, guests and logged-in, nonce action `loadout_frontend` sent as `nonce`):

  | Action | Parameters | Returns (`data`) |
  |---|---|---|
  | `loadout_add_item` | `product_id`, `quantity`, `loadout_id`, `tier_id`, `tier_slug`, `item_id`, `product_loadout_id`, `source` | `cart_key`, `fragments`, `cart_hash`, `cart_count`, `cart_total`, `cart_url` |
  | `loadout_add_tier` | `tier_id` (global) or `product_loadout_id` + `tier_slug` (per-product), `loadout_id`, `source` | the above plus `items_added`, `bundle_total` (formatted), `tier_name` |
  | `loadout_get_cart_summary` | `loadout_id` (`0` = whole cart) | `items[]` (`name`, `quantity`, `regular`, `current`, `is_bonus`, `cart_key`), `savings`, `count`, `tier_counts` (lines per tier ID), `total` |

  Discounts are re-read from the stored configuration on the server; a `discount_pct` sent by the browser is ignored.
- Admin AJAX (nonce action `loadout_admin`, capability `manage_woocommerce`): `loadout_search_products` (`search`, `page`) and `loadout_toggle_status` (`loadout_id`).
- Markup hooks for custom layouts: the root `.ffla-loadout` carries `data-loadout-id` and/or `data-product-loadout-id`; `.ffla-loadout__panel` carries `data-tier-slug`, `data-tier-id`, `data-threshold`; `.ffla-loadout__add-btn` carries `data-product-id`, `data-quantity`, `data-item-id` (and an optional `data-source`, `data-tier-id`, `data-loadout-id`); `.ffla-loadout__add-tier-btn` carries `data-tier-id`, `data-tier-slug`. Clicks are handled by delegation on the whole document.
- After a successful add the script triggers `wc_fragments_refreshed`, `added_to_cart` (fragments, cart hash) and `wc_fragment_refresh` on `document.body`. Script data is in `window.loadoutFrontend` (`nonce`, `ajaxUrl`, `cartUrl`, `strings`).
- PHP helpers: `Loadout_Product_Admin::get_product_config( $product_id )` returns `['type' => 'global'|'custom'|'disabled', 'loadout' => Loadout|null, 'tiers' => array]`; `Loadout_Element_Helpers::resolve_full_tiers_for_current_context( $loadout_id = 0 )` returns the normalised tiers the elements render.
- Theming: `.ffla-loadout` maps `--ffla-loadout-accent`, `-success`, `-danger`, `-bg`, `-fg`, `-muted`, `-card` and `-border` to the site tokens `--primary`, `--success`, `--danger`, `--bg`, `--fg`, `--muted`, `--card`, `--border`, with dark fallbacks. Define those tokens at `:root` to restyle every loadout.
