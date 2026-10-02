# MonsterInsights Compatibility

Fills in two GA4 storefront events, `view_item` and AJAX `add_to_cart`, that can go missing on WooCommerce stores using custom Bricks product templates and AJAX side-carts (such as Merchant), and only when MonsterInsights has not already sent them. It is for stores that track with MonsterInsights and its eCommerce Addon. Module ID: `ga4-bridge` (earlier releases called it GA4 Bridge), off until it is switched on in **FFL Funnels → Dashboard**. Requires WooCommerce, MonsterInsights with the eCommerce Addon active, and a GA4 measurement ID set in MonsterInsights. It has no settings page.

> **Read this first.** MonsterInsights stays the analytics owner. This module never loads a Google tag and never sends `begin_checkout`, `purchase` or refund events. MonsterInsights works without it on standard WooCommerce templates, so switch it on only on stores where product views or side-cart add-to-carts are actually missing.

## What it does

- **`view_item`** on single product pages, when no `view_item` is already in the page's `dataLayer`.
- **`add_to_cart`** after an AJAX add to cart of the page's product on a single product page, when MonsterInsights has not just sent one.
- Sends both through MonsterInsights' own tracker, to MonsterInsights' GA4 property only.
- Keeps an older path for stores still on Google Analytics for WooCommerce (see below).

## Setup

1. In MonsterInsights, connect the GA4 property and activate the eCommerce Addon.
2. Check in Google Analytics whether `view_item` and side-cart `add_to_cart` already arrive from your product pages. If they do, you do not need this module.
3. Switch on **MonsterInsights Compatibility** in **FFL Funnels → Dashboard**. There is nothing to configure.
4. Open a product page as a visitor MonsterInsights tracks (for example logged out) and add the product to the cart. You should see one `view_item` and one `add_to_cart`. Events sent by this module carry the parameter `ffla_bridge` = `monsterinsights_compatibility`.
5. If a warning appears on the FFL Funnels screens, MonsterInsights was not detected (see Troubleshooting).

## Settings

There are no settings. Switching the module on or off in **FFL Funnels → Dashboard** is the only control. The measurement ID comes from MonsterInsights; currency and product data come from WooCommerce.

## How it works

### When it runs

The module treats MonsterInsights as ready when all three are true:

- MonsterInsights is loaded;
- the eCommerce Addon is active;
- MonsterInsights returns a GA4 measurement ID in the `G-XXXXXXX` format.

When MonsterInsights is ready, a small script is added to single product pages (never in wp-admin). Otherwise nothing is added to the storefront.

### `view_item`

- Sent about 0.9 seconds after the page is ready. The script then waits up to about 10 seconds for MonsterInsights' tracker; if it does not appear, nothing is sent.
- Skipped when the page's `dataLayer` already has a `view_item` event addressed to MonsterInsights' measurement ID, or with no `send_to` (an event without `send_to` reaches every destination, MonsterInsights included).
- The item is the page's product: ID, name, price (lowest variation price for a variable product), quantity 1, up to five product categories (`item_category` … `item_category5`) and a brand from the `product_brand` or `pwb-brand` taxonomy when one exists. Currency is the store currency.

### `add_to_cart`

- Triggered by WooCommerce's `added_to_cart` browser event on the product page and sent about 0.2 seconds later, through the same tracker wait.
- Quantity comes from the button's `data-quantity` or the quantity field; the value is price × quantity. When a variation is chosen, the item ID and price switch to that variation and `item_variant` lists the chosen options as shown to the shopper (for example "Red, XL"); clearing the choice goes back to the parent product.
- Only the page's own product is reported. When the button or form names a different product (`data-product_id`, `add-to-cart` or `product_id`), as related-product and upsell buttons do, no fallback is sent, because that product's details are not on the page. An `added_to_cart` that names no product (a side-cart that passes no button) counts as the page's product.
- Skipped when an `add_to_cart` event addressed to MonsterInsights (or with no `send_to`) is among the last six `dataLayer` entries before the cart event, or is added before the fallback is sent. The module's own earlier fallbacks do not count, so adding to the cart twice in a row sends two events. The check is not tied to the item, because MonsterInsights may identify items differently (for example by SKU).
- Only single product pages are covered. Adding to cart from the shop, category or search pages gets no fallback.

### Who does what

| Event or task | Handled by |
|---|---|
| Google tag, excluded roles | MonsterInsights. If MonsterInsights excludes the current user (`mi_track_user` is `false`), the module sends nothing. |
| `view_item`, `add_to_cart` | MonsterInsights first; this module only fills a missing one. |
| `begin_checkout`, `purchase`, refunds | MonsterInsights only. |

### Google Analytics for WooCommerce stores

When MonsterInsights is not ready but Google Analytics for WooCommerce is active, the module keeps its earlier repairs:

- **`view_item`**: after the add-to-cart form on a single product page, it runs WooCommerce's `woocommerce_before_single_product` and `woocommerce_after_single_product` actions if the template did not (each at most once). Products without an add-to-cart form get no repair.
- **`add_to_cart`**: during an AJAX add to cart it stores the product, formatted by Google Analytics for WooCommerce, in the customer's session so that plugin sends it on the next page view. Only one pending event is kept at a time.

As soon as MonsterInsights is ready, these repairs stop, so having both plugins during a migration does not double the events. On this path `view_cart` is not supported by that plugin, and Site Kit's Analytics module must stay disconnected (one GA4 tag per site).

### Safety

- The server-side add-to-cart step and the browser tracker call are both wrapped, so an analytics error never interrupts add to cart.
- Only the public measurement ID reaches the page. No credentials are exposed.

## Where it shows up

| Place | What |
|---|---|
| Single product pages (storefront) | The fallback script, when MonsterInsights is ready. |
| FFL Funnels screens | A warning for users with `manage_woocommerce` when neither MonsterInsights (as above) nor Google Analytics for WooCommerce is detected. |
| **FFL Funnels → Dashboard** | The module card only. There is no settings page. |

## Data and uninstall

- The module stores nothing: no options, meta or tables.
- On the Google Analytics for WooCommerce path it writes the pending event to the WooCommerce session key `_ga_pending_added_to_cart`, which that plugin's own restore step sends on the next page view.
- Switching the module off stops the script and the repairs. Deleting the plugin has nothing to remove for this module.

## Troubleshooting

- **"MonsterInsights Compatibility is active, but MonsterInsights with its eCommerce Addon and a GA4 measurement ID was not detected."** MonsterInsights is not loaded, the eCommerce Addon is not active, or no valid `G-` measurement ID is set. The module does nothing until that is fixed and never loads its own tag.
- **No fallback event.**
  - Check that you are on a single product page.
  - Test as a visitor MonsterInsights tracks; excluded roles get nothing.
  - MonsterInsights' tracker must load within about 10 seconds.
  - The event may simply already be in the `dataLayer`, which is the intended result.
- **Duplicate events.** The module only sees events in `window.dataLayer`. An event sent another way, or a `view_item` sent with `send_to` to a different property, is not recognised as a duplicate.
- **No `add_to_cart` for a related product or upsell added from a product page.** Expected: only the page's own product is reported (see above).
- **Add to cart from category pages is missing.** Not covered by this module.
- **Purchases or refunds are missing or doubled.** That is MonsterInsights' order tracking; this module does not touch it.

## For developers

- **No filters or actions** of its own. Hooks used: `woocommerce_after_add_to_cart_form` (99), `woocommerce_add_to_cart` (99, 5 args), `wp_enqueue_scripts` (99), `admin_notices`.
- **Script**: handle `ffla-monsterinsights-bridge` (`assets/js/monsterinsights-bridge.js`), depends on `jquery`, loaded in the footer.
- **Config**: `window.fflaMonsterInsightsBridge` = `{ measurementId, productId, currency, value, items }`. Each item has `item_id`, `item_name`, `price`, `quantity`, and when available `item_category`…`item_category5` and `item_brand`; `item_variant` is added in the browser when a variation is chosen.
- **Sent parameters**: `currency`, `value`, `items`, `send_to` (the measurement ID) and `ffla_bridge: "monsterinsights_compatibility"`, through `window.__gtagTracker('event', name, params)`.
- **Browser events listened to**: `found_variation` and `reset_data` on `form.variations_form`, and `added_to_cart` on `document.body`, all in the `.fflaMonsterInsightsBridge` jQuery namespace.
- **Legacy session key**: `_ga_pending_added_to_cart` (Google Analytics for WooCommerce's own pending-event key).
- **Tests**: `node tests/smoke/monsterinsights-bridge-smoke.js` (fallback, duplicate, broadcast, other-product buttons, repeated adds and variation checks).
