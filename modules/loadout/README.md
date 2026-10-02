# Loadout

Tiered "build your loadout" offers on product pages: a main product plus tiers (for example Essential, Performance, Elite) of recommended accessories, each tier with its own discounts, a perks list and an optional free bonus product. It is for stores that sell a firearm together with curated accessory packages, and for the developer who builds the product template. Module ID: `loadout`, off until it is switched on in **FFL Funnels → Dashboard**. Requires WooCommerce. The storefront is rendered by Bricks elements, so Bricks Builder is needed for them and for the dynamic tag; the `[loadout]` shortcode works without Bricks.

> **Read this first.** Loadout discounts are taken off the product's **regular** price, and a loadout price never goes above the product's current price: when a sale is deeper than the loadout discount, the sale price is kept. Everything the cart charges is worked out on the server from the saved configuration, never from what the browser sends. Place a test order before launch.

## What it does

- **Global loadouts** (FFL Funnels → Loadout): reusable configurations with a headline, branding images, an anchor ("hero") product, tiers, tier items and cross-sell tiles. One loadout can be linked to many products.
- **Per-product loadouts** (product editor → Loadout tab): link a global loadout, or build tiers that belong to that product only.
- **Storefront**: tier tabs; under each tab a "Recommended *Tier* Setup" list with image, name, star rating, "% OFF" badge, struck regular price, loadout price and an **Add** button per item, an **Add cart** button that adds the whole tier as one cart line, the tier's perks and its bonus product.
- **Cart**: loadout pricing, a single "entire tier" line with an itemised breakdown and savings, a free bonus product once the tier's threshold is reached, component stock for the "entire tier" line, and loadout details saved on the order.
- **Bricks**: four elements and the `{ffla_product_has_loadout}` dynamic tag. **Shortcode**: `[loadout]`.

## Setup

1. Switch on **Loadout** in **FFL Funnels → Dashboard**. Its four database tables are created.
2. Open **FFL Funnels → Loadout** → **Add New**. Enter a **Name**, optionally a **Headline**, pick the **Hero Product**, then **+ Add Tier** for each tier and **+ Add Item** for its products. Click **Create Loadout**.
3. Edit a product → **Product data → Loadout**. Either pick a loadout in **Link to Global Loadout**, or leave it on "— Use per-product config below —" and build tiers under **Per-Product Configuration**. Click **Update**.
4. In Bricks, open the single product template and add **Loadout: Tier Tabs** (tabs and products in one element) or **Loadout** (the full widget). Leave **Loadout** on "— Auto-detect from current product —". Optionally add **Loadout: Progress Bar** and **Loadout: Cart Mirror**, and show the section only when `{ffla_product_has_loadout}` is `1`.
5. Without Bricks, put `[loadout id="12"]` or `[loadout slug="ar-15-build"]` on a page.
6. Add items and a whole tier to the cart and place a test order to check prices and stock.

## Settings

Loadouts are set up on the loadout form and in the product editor. The only module-wide setting is the uninstall option under **Data** on the Loadouts list.

### Loadout form (FFL Funnels → Loadout → Add New / Edit)

| Setting | What it does | Default |
|---|---|---|
| Name | Required. Admin label. Customers also see it as the title when **Headline** is empty and as the "Loadout" line under cart items. | — |
| Status | *Active* or *Inactive*. An inactive loadout is shown nowhere: not in Bricks elements (even where it was picked), not on linked product pages, not in the shortcode, and its items can no longer be added to the cart. | Active |
| Headline | Title at the top of the widget. Falls back to **Name**. | empty |
| Subheadline | Small text under the headline. | empty |
| Hero Image | Large image in the anchor column. Falls back to the anchor product's own image. | none |
| Brand Logo | Small logo above the title. | none |
| Hero Product | The anchor product: shown with its price and an **Add hero** button, and the main item of every **Add cart** line when the loadout is shown on its own (see [What the cart charges](#what-the-cart-charges)). | none |

### Tier fields

| Setting | What it does | Default |
|---|---|---|
| Tier Name | Required. Tab label. A slug is made from the name when the tier is first saved; it is kept when the tier is renamed (cart lines refer to it) and made unique within the loadout. | — |
| Accessory Discount % | Added to each item's own **Discount %**, whether the item is added on its own or through **Add cart**. | 0 |
| Set Discount % | Added on top for the tier's items inside an **Add cart** line, only when every item of the tier is available. Never given to items added one at a time. | 0 |
| Perk Threshold | Units from this tier the cart must hold before the **Bonus Product** is added (items inside an **Add cart** line count). `0` means no bonus. Also the target of the progress bar. | 0 |
| Perks | One per line, listed in the tier panel under "Perks Unlocked at Threshold:" (just "Perks:" when the threshold is 0). Display only. | empty |
| Bonus Product (Free Gift) | One free unit added to the cart once the threshold is reached, removed when the cart drops below it. Needs a **Perk Threshold** above 0. | none |
| Bonus Label | Heading of the bonus block. Without one the block reads "FREE Bonus Item". | empty |
| Bonus Display Value | Shown as "Valued at $X". Display only. | empty |

### Tier item fields

| Setting | What it does | Default |
|---|---|---|
| Product | Search field (type two or more characters). Lists the first 20 matching published simple products and fully specified variations that are not out of stock, with SKU, price and a stock chip. Variable parents and "any attribute" variations are left out because they cannot go in the cart directly. | — |
| Qty | Quantity added by the item's **Add** button and inside **Add cart** lines. | 1 |
| Discount % | The item's own discount. Added to the tier's discounts; the combined total is capped at 100%. | 0 |

### Cross-sell tiles

| Setting | What it does | Default |
|---|---|---|
| Label | Tile text. Tiles without a label are dropped on save. | — |
| Image | Tile image. | none |
| Link type + value | *Category*: a product category slug or ID. *URL*: a full address. *Loadout*: a loadout slug or ID; the tile opens that loadout's hero product page (or jumps to its block on the same page when it has no hero product). Used by the Bricks **Loadout** element and the shortcode. | Category |

### Product editor (Product data → Loadout)

| Setting | What it does | Default |
|---|---|---|
| Link to Global Loadout | A loadout to use for this product. When set, the per-product tiers below are ignored. A linked loadout that was later made inactive stays selected (marked "(inactive)") so saving the product keeps the link. | "— Use per-product config below —" |
| Per-Product Configuration | Tiers with the same fields as a global tier (**Tier Name**, **Accessory Discount %**, **Set Discount %**, **Perk Threshold**, **Perks (one per line)**, **Bonus Product**, **Bonus Label**, **Display Value**) and items (**Product**, **Qty**, **Discount %**). A tier with items but no name is saved as "Tier 1", "Tier 2"…; items without a product are dropped. Tier slugs are kept across renames and made unique per product. | none |

### Data (FFL Funnels → Loadout, below the list)

| Setting | What it does | Default |
|---|---|---|
| Delete loadout data on uninstall | When on, deleting the plugin while the Loadout module is switched on removes the Loadout tables, this setting and the products' Loadout tab settings. Orders keep their loadout details. | off |

## How it works

### Which loadout an element shows

All elements and the dynamic tag resolve the configuration in this order:

1. The loadout picked in the element's **Loadout** dropdown, while it is active (an inactive pick shows nothing).
2. The product's **Link to Global Loadout**, if that loadout exists and is active.
3. The product's per-product tiers.
4. Otherwise the element shows "No loadout configured for this context."

The current product is taken from the single product page, WooCommerce's current `$product`, or the post being rendered (e.g. inside a Bricks product loop).

Tabs switch every panel on the page with the same tier slug, so the tabs and the product panels can sit in different elements. A **Default Tier Index** that does not exist falls back to the first tier.

### Prices shown in the tier panels

Item price = regular price × (1 − (item **Discount %** + tier **Accessory Discount %**)), capped at 100%, and never more than the product's current price. A product without a regular price uses its current price. The badge shows the real saving against the regular price. **Set Discount %** is not shown in the panel. Items that cannot be bought on their own (out of stock, a variable parent) have a disabled button.

### What the cart charges

| Customer action | Cart line | Price |
|---|---|---|
| **Add** on a tier item (global or per-product tier) | Its own line at the item's **Qty**. Every click adds a new line; loadout lines never merge. | Regular price × (1 − (item % + accessory %)), never above the current price. With 0% the normal price stays. |
| **Add hero** (anchor) | Its own line, quantity 1. | Normal price. |
| **Add cart** (entire tier) | One line that cannot merge or be split, carried by the main item (or the first tier item when there is none), quantity 1. | Main item at its normal price, plus each available tier item at regular price × (1 − (item % + accessory % + set %)) × **Qty**, never above the item's current price. |

Every add is checked on the server: the item must belong to the tier, the tier to the loadout, and the loadout must be active. Anything else is refused with "This product is not part of that loadout." The browser never sends a price or a discount that the server uses.

About the **Add cart** line:

- **Main item**: on a product page that uses the loadout (linked global loadout or per-product tiers), the product being viewed. Elsewhere (shortcode, or an element with a loadout picked), the loadout's **Hero Product**. A tier item that is the same product as the main item is not added twice.
- Items that cannot be bought (not purchasable, out of stock, not enough stock) are left out. **Set Discount %** is then not given, and the response lists the items left out.
- Prices are fixed when the line is added and signed by the server. A line whose breakdown does not match its signature (for example a line added before this check existed) is rebuilt from the current configuration, or charged at full current prices when that is not possible. Later price edits do not change lines already in a cart.
- The cart and checkout show **Includes** with each product (linked), its quantity, the struck original price, the final price, "Save $X", and a "Total Loadout Savings" line. The main item carries a "Main" badge. The order line gets an **Includes** meta listing the products and quantities.
- **Stock**: WooCommerce reduces the product that carries the line. When the order's stock is reduced, the module reduces every other component by its quantity × the line quantity (and the extra units of the carrying product, if its quantity is above 1), records what it changed on the order line and adds an order note. A refund with "Restock refunded items" gives back the share of the refunded quantity; restoring the order's stock (cancel, failed) gives back the rest. Each step runs once, even if WooCommerce calls it again. Orders placed before this accounting existed are restored the way they were reduced.

### Bonus product

For tiers with a **Bonus Product** and a **Perk Threshold** above 0 (global and per-product tiers): whenever the cart is loaded, a loadout item is added, a line is removed or a quantity changes, the module counts the units in the cart from that tier (bonus excluded; an **Add cart** line counts the tier items it holds × its quantity). At or above the threshold it adds the bonus product (one unit, $0, shown as "Bonus: Free Gift", quantity fixed at 1); below it, it removes it. A bonus product that is out of stock is not added. The price is only $0 while the threshold is met and the line holds the configured bonus product.

### After a click

The button shows "Adding...", then "Added!" and stays disabled until the page reloads. WooCommerce's mini-cart fragments are refreshed and every cart panel and progress bar on the page reloads. On the cart or checkout page the browser goes to the cart page instead. On any failure the button shows "Could not add item." for two seconds, with the server's reason as its tooltip.

### Cart panel and Cart Mirror

Loaded by AJAX when the page opens and after each add (one request per loadout on the page): each loadout line with "×quantity" and its current price, then "Savings:" (regular minus current price for each line, plus the breakdown savings of **Add cart** lines) and "Total:" (the cart total). The list shows the lines of the resolved global loadout, or of the product page's per-product tiers; with nothing resolved (a page that is not a product, no loadout picked) it shows every loadout line. Products added outside a loadout are never listed.

### Progress bar

Fill = units in the cart from the active tier ÷ that tier's **Perk Threshold** (the same count as the bonus). The label changes from the placeholder to "N more item(s) to unlock perks" and then "Perks unlocked!". The bar follows the tier selected in the tabs of the same loadout on the page, or the first tier when there are no tabs. It works for global and per-product tiers.

### Caching and loading

- The front-end script and stylesheet load on product pages, on pages whose content holds the shortcode or loadout markup, in the Bricks builder, and wherever a Loadout element or the shortcode renders.
- Configuration is not cached; each render reads the tables (one query per tier and per item).
- Panel prices are part of the page HTML. The cart panel and progress bar load by AJAX, so they are current on cached pages, but every add request carries a security token printed in the page. A cached page older than WordPress's token lifetime makes every button fail.

## Where it shows up

### Admin

| Screen | What it does |
|---|---|
| **FFL Funnels → Loadout** | The *Loadouts* list: search, sort by Name or Status, 20 per page, columns Name, Anchor Product, Tiers, Status and Edit / Delete, bulk **Delete**, **Activate**, **Deactivate**. **Add New** opens the form. Below the list: **Data** (uninstall option). |
| **Products → edit → Product data → Loadout** | Link a global loadout or build per-product tiers. Item and bonus rows show price and a stock chip ("Out of stock", "On backorder", "N in stock", "In stock"). |
| **Products** list | Filter "All loadouts / With loadout / Without loadout". *With loadout* = a linked loadout ID or saved per-product tiers. |

### Bricks elements

All four are in the **FFL Funnels** element category. **Default Tier Index** counts from 0 (first tier).

| Element | Main controls | What it renders |
|---|---|---|
| Loadout | **Loadout** (auto-detect or a loadout), **Default Tier Index** (0), **Show Cart Panel** (on), **Show Cross-Sells** (on); Style: **Accent Color**, **Background Color** | The full widget: header (global loadouts only), tabs, main item column (the hero product with **Add hero**, or on a product page the product being viewed), tier panels, "Your Cart" panel, "Complete Your Loadout" tiles (global only) and a **Proceed to checkout** button. |
| Loadout: Tier Tabs | **Loadout**, **Default Tier Index** (0), **Show products under tabs** (on) | Tabs, and the tier panels below them unless switched off. |
| Loadout: Progress Bar | **Loadout**, **Placeholder Label** ("Add items to unlock perks"); Style: **Bar Color** | Progress bar and label. |
| Loadout: Cart Mirror | **Filter by Loadout** ("— Auto-detect / all loadouts —"), **Heading** ("Your Cart") | The cart panel on its own. |

### Dynamic tag

`{ffla_product_has_loadout}` (picker group *FFL Funnels*, label "Product has Loadout") returns `1` when the product resolves to an active linked loadout with at least one tier, or has per-product tiers, and an empty value otherwise. It works inline in text and when Bricks resolves the tag on its own (with or without braces). Typical use: an element condition with dynamic data `{ffla_product_has_loadout}` equal to `1`.

### Shortcode

`[loadout id="12"]` or `[loadout slug="ar-15-build"]` (slug wins when both are given).

| Attribute | What it does | Default |
|---|---|---|
| `id` / `slug` | Which global loadout. | — |
| `default_tier` | Tier shown first, counting from 0 (first tier when it does not exist). | `0` |
| `show_cart` | `no` hides the "Your Cart" panel. | `yes` |
| `show_cross_sells` | `no` hides the cross-sell tiles. | `yes` |

It renders the header, progress bar, tabs, the hero product with **Add hero**, the same tier panels as the Bricks elements (ratings, perks, bonus block), cart panel, cross-sell tiles and **Proceed to checkout**. It outputs nothing for an inactive loadout or one without tiers.

## Data and uninstall

- Tables: `{prefix}ffla_loadouts`, `{prefix}ffla_loadout_tiers`, `{prefix}ffla_loadout_tier_items`, `{prefix}ffla_loadout_cross_sells`. Options: `ffla_loadout_db_version`, `ffla_loadout_settings` (the uninstall option).
- Product meta: `_ffla_product_loadout_link` (linked loadout ID, `0` for none), `_ffla_product_loadout_tiers` (per-product tiers as JSON), `_ffla_loadout_last_save` (time of the last save from the product editor). `_ffla_product_loadout_enable_tab` is a retired key that is no longer read or written.
- Cart and order line meta: `_ffla_loadout_id`, `_ffla_loadout_tier_id`, `_ffla_loadout_tier_slug`, `_ffla_loadout_source`, `_ffla_product_loadout_id`, `_ffla_loadout_item_id`, `_ffla_loadout_is_bonus`, `_ffla_tier_bundle`, `_ffla_tier_bundle_items` (the breakdown used for stock), plus the visible **Includes** meta. Cart lines also hold `_ffla_tier_bundle_sig` (signature of the breakdown). Order lines of an **Add cart** line get `_ffla_tier_bundle_stock` (the component stock the module changed). Only non-empty values are copied to the order.
- The tier item column `is_required` (the former "Pre-checked" box) is kept but no longer shown or used.
- Deleting a loadout deletes its tiers, items and cross-sells. Products linked to it keep the link meta and fall back to their per-product tiers.
- Deactivating the module changes nothing. **Uninstall keeps all Loadout data** unless **Delete loadout data on uninstall** is on and the module is active at uninstall time. Orders always keep their line meta.
- Media Cleaner treats loadout hero images, brand logos and cross-sell images as in use.

## Troubleshooting

- **"No loadout configured for this context."** The product has no active linked loadout and no per-product tiers, the element's picked loadout is inactive, or the element sits outside a product context with no loadout picked. Check the product's Loadout tab and the loadout's **Status**.
- **"Could not add item."** Hover the button for the reason. Common causes: the product is out of stock or not purchasable, the loadout was deactivated, or the page came from a cache older than the security token (keep loadout pages out of long-lived page caches).
- **Add cart left some items out.** They were out of stock or not purchasable; the tier was not complete, so **Set Discount %** was not given either.
- **The bonus product does not appear.** The tier needs a **Bonus Product** and a **Perk Threshold** above 0, the cart must hold that many units from the tier, and the bonus product must be in stock.
- **A product shows its sale price, not the loadout price.** The sale is deeper than the loadout discount; the lower price is kept.
- **The progress bar stays empty.** The tier needs a **Perk Threshold** above 0.

## For developers

- Loadout has no filters or actions of its own. It runs WooCommerce's `woocommerce_add_to_cart_fragments` filter when it builds its AJAX responses.
- Front-end AJAX (`admin-ajax.php`, guests and logged-in, nonce action `loadout_frontend` sent as `nonce`):

  | Action | Parameters | Returns (`data`) |
  |---|---|---|
  | `loadout_add_item` | `product_id`, `quantity`, and one of: `item_id` (global tier item), `product_loadout_id` + `tier_slug` (per-product tier item), `loadout_id` (hero product). `tier_id` / `loadout_id` must match the item when sent. | `cart_key`, `fragments`, `cart_hash`, `cart_count`, `cart_total`, `cart_url` |
  | `loadout_add_tier` | `tier_id` (global) or `product_loadout_id` + `tier_slug` (per-product); optional `loadout_id`, and `product_loadout_id` for the product page the widget is on | the above plus `items_added`, `bundle_total` (formatted), `tier_name`, `skipped` (names left out) |
  | `loadout_get_cart_summary` | `loadout_id`, `product_loadout_id` (both `0` = every loadout line) | `items[]` (`name`, `quantity`, `regular`, `current`, `is_bonus`, `cart_key`), `savings`, `count`, `tier_counts` (units per global tier ID), `slug_counts` (units per per-product tier slug), `total` |

  Errors return `{ success: false, data: { message } }`. Prices and discounts are always derived on the server; loadout data is attached to a cart line only by these handlers, never from request fields on a regular add-to-cart.
- Admin AJAX (nonce action `loadout_admin`, capability `manage_woocommerce`): `loadout_search_products` (`search`, `page`).
- Markup hooks for custom layouts: the root `.ffla-loadout` carries `data-loadout-id` and/or `data-product-loadout-id` (and `data-tiers`, a JSON list of `id`, `slug`, `threshold`, on the progress bar and shortcode); `.ffla-loadout__panel` carries `data-tier-slug`, `data-tier-id`, `data-threshold`; `.ffla-loadout__add-btn` carries `data-product-id`, `data-quantity`, `data-item-id` (and optional `data-tier-id`, `data-tier-slug`, `data-loadout-id`, `data-product-loadout-id`); `.ffla-loadout__add-tier-btn` carries `data-tier-id`, `data-tier-slug`. Clicks are handled by delegation on the whole document.
- After a successful add the script triggers `wc_fragments_refreshed`, `added_to_cart` (fragments, cart hash) and `wc_fragment_refresh` on `document.body`. Script data is in `window.loadoutFrontend` (`nonce`, `ajaxUrl`, `cartUrl`, `strings`).
- PHP helpers: `Loadout_Product_Admin::get_product_config( $product_id )` returns `['type' => 'global'|'custom'|'disabled', 'loadout' => Loadout|null, 'tiers' => array]`; `Loadout_Product_Admin::find_custom_tier( $product_id, $slug )`; `Loadout_Element_Helpers::resolve_full_tiers_for_current_context( $loadout_id = 0 )` returns the normalised tiers the elements render; `Loadout_Pricing::unit_price( $regular, $current, $percent )` is the price rule used everywhere; `Loadout_Frontend::enqueue()` loads the assets from custom templates.
- Theming: `.ffla-loadout` maps `--ffla-loadout-accent`, `-success`, `-danger`, `-bg`, `-fg`, `-muted`, `-card` and `-border` to the site tokens `--primary`, `--success`, `--danger`, `--bg`, `--fg`, `--muted`, `--card`, `--border`, with dark fallbacks. Define those tokens at `:root` to restyle every loadout.
- Regression tests: `php tests/smoke/loadout-cart-smoke.php`.
