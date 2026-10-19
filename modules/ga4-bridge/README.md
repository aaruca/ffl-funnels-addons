# MonsterInsights Compatibility

Fixes the GA4 data that MonsterInsights and its eCommerce Addon send from WooCommerce stores built on custom Bricks product templates. It does two things:

1. **Fills in a missing `view_item`** on single product pages, where a custom Bricks product template skips the WooCommerce hook MonsterInsights prints it from.
2. **Corrects MonsterInsights' server-side eCommerce events** before they are sent:
   - product names and other item text without HTML entities;
   - `add_to_cart` and `remove_from_cart` values with cents;
   - a value and currency on `begin_checkout`.

Module ID: `ga4-bridge` (earlier releases called it GA4 Bridge). It is off until switched on in **FFL Funnels → Dashboard**, and it has no settings page.

Requirements: WooCommerce, MonsterInsights with the eCommerce Addon active, and a GA4 measurement ID set in MonsterInsights.

> **Read this first.** MonsterInsights stays the analytics owner. This module never loads a Google tag and never sends a cart or order event (`add_to_cart`, `remove_from_cart`, `begin_checkout`, `purchase`, refunds). MonsterInsights already sends those itself, from the server.

## What it does

### `view_item` on product pages (browser)

- Sent about 0.9 seconds after the page is ready. The script then waits up to about 10 seconds for MonsterInsights' tracker; if the tracker never appears, nothing is sent.
- Skipped in two cases:
  - the page's `dataLayer` already has a `view_item` addressed to MonsterInsights' measurement ID;
  - the `dataLayer` has a `view_item` with no `send_to` (an event without `send_to` reaches every destination, MonsterInsights included).
- The item is the page's product:
  - ID, name and quantity 1;
  - price: the lowest variation price for a variable product;
  - up to five product categories (`item_category` … `item_category5`);
  - a brand from the `product_brand` or `pwb-brand` taxonomy, when one exists.
- Currency is the store currency. Events carry the parameter `ffla_bridge` = `monsterinsights_compatibility`.

### MonsterInsights server-side events (Measurement Protocol)

The eCommerce Addon sends `add_to_cart`, `remove_from_cart`, `begin_checkout`, `purchase` and refunds from the server, on WooCommerce's own hooks. These events never appear in the browser. The module adjusts them through MonsterInsights' `monsterinsights_mp_v4_api_call` filter:

| Problem in MonsterInsights | Correction |
|---|---|
| Item names come from `get_the_title()`, HTML-encoded (`H&amp;R … 22&#8243;`). | `item_name`, `item_category`…`item_category5`, `item_variant`, `item_brand` and `item_list_name` are decoded to plain text. |
| `add_to_cart` / `remove_from_cart` compute `value` from `(int) price × quantity`, dropping the cents (174.99 becomes 174.00). | `value` is recomputed as the sum of `price × quantity`, rounded to the store's price decimals. If an item has no numeric price, the value is left as sent. |
| `begin_checkout` is sent with items only. | `value` (sum of `price × quantity`) and the store `currency` are added when missing. |

Purchase totals (`value`, `tax`, `shipping`), refunds, `transaction_id`, `client_id` and `session_id` are never changed.

## Why there is no browser `add_to_cart`

Releases before 1.55.8 also sent `add_to_cart` from the browser after an AJAX add to cart, when none was found in the `dataLayer`. MonsterInsights' own `add_to_cart` is sent from the server, so it is never in the `dataLayer`. The fallback could therefore never see it, and every add to cart was counted twice in GA4. MonsterInsights sends `add_to_cart` for every add (product page, shop loop, AJAX side-cart), so no browser copy is needed.

## Setup

1. In MonsterInsights, connect the GA4 property and activate the eCommerce Addon.
2. Switch on **MonsterInsights Compatibility** in **FFL Funnels → Dashboard**. There is nothing to configure.
3. **Check `view_item`.** Open a product page as a visitor MonsterInsights tracks (for example in a private window, logged out). The page's `dataLayer` and GA4 **Realtime** should show one `view_item`. If the module sent it, it carries `ffla_bridge`.
4. **Check the cart and checkout events.** Add the product to the cart and open the checkout. `add_to_cart` and `begin_checkout` arrive once each in GA4 **Realtime**, both from MonsterInsights. They do not show in the browser or in **DebugView**, because they are sent from the server.
5. If a warning appears on the FFL Funnels screens, MonsterInsights was not detected (see Troubleshooting).

## How it works

The module treats MonsterInsights as ready when all three are true:

- MonsterInsights is loaded;
- the eCommerce Addon is active;
- MonsterInsights returns a GA4 measurement ID in the `G-XXXXXXX` format.

When MonsterInsights is ready, the `view_item` script is added to single product pages (never in wp-admin). The payload filter runs whenever MonsterInsights sends a Measurement Protocol request. It only touches events that carry items, and leaves every other request unchanged.

### Who does what

| Event or task | Handled by |
|---|---|
| Google tag, excluded roles | MonsterInsights. If MonsterInsights excludes the current user (`mi_track_user` is `false`), the module sends nothing. |
| `view_item` | MonsterInsights first; this module fills in a missing one. |
| `add_to_cart`, `remove_from_cart`, `begin_checkout`, `purchase`, refunds | MonsterInsights only (server side). This module corrects their item text and cart values. |

### Google Analytics for WooCommerce stores

When MonsterInsights is not ready but Google Analytics for WooCommerce is active, the module keeps its earlier repairs:

- **`view_item`:** after the add-to-cart form on a single product page, it runs WooCommerce's `woocommerce_before_single_product` and `woocommerce_after_single_product` actions if the template did not, each at most once. Products without an add-to-cart form get no repair.
- **`add_to_cart`:** during an AJAX add to cart, it stores the product in the customer's session, formatted by Google Analytics for WooCommerce. That plugin then sends it on the next page view. Only one pending event is kept at a time.

As soon as MonsterInsights is ready, these repairs stop, so having both plugins during a migration does not double the events. On this path:

- `view_cart` is not supported by that plugin;
- Site Kit's Analytics module must stay disconnected (one GA4 tag per site).

### Safety

- The payload filter, the server-side add-to-cart step and the browser tracker call are all wrapped, so an analytics error never interrupts add to cart, checkout or the product page.
- Only the public measurement ID reaches the page. No credentials are exposed.

## Where it shows up

| Place | What |
|---|---|
| Single product pages (storefront) | The `view_item` script, when MonsterInsights is ready. |
| MonsterInsights server-side requests | Item text and cart values corrected before sending. |
| FFL Funnels screens | A warning for users with `manage_woocommerce` when neither MonsterInsights (as above) nor Google Analytics for WooCommerce is detected. |
| **FFL Funnels → Dashboard** | The module card only. There is no settings page. |

## Data and uninstall

- The module stores nothing: no options, meta or tables.
- On the Google Analytics for WooCommerce path, it writes the pending event to the WooCommerce session key `_ga_pending_added_to_cart`. That plugin's own restore step sends it on the next page view.
- Switching the module off stops the script, the payload corrections and the repairs. Deleting the plugin leaves nothing to remove for this module.

## Troubleshooting

- **Warning: "MonsterInsights Compatibility is active, but MonsterInsights with its eCommerce Addon and a GA4 measurement ID was not detected."** One of these is missing: MonsterInsights is not loaded, the eCommerce Addon is not active, or no valid `G-` measurement ID is set. The module does nothing until that is fixed, and it never loads its own tag.
- **No `view_item` fallback.**
  - Check that you are on a single product page.
  - Test as a visitor MonsterInsights tracks; excluded roles get nothing.
  - MonsterInsights' tracker must load within about 10 seconds.
  - A `view_item` may already be in the `dataLayer`. That is the intended result.
- **`add_to_cart` or `begin_checkout` not in DebugView.** Expected. MonsterInsights sends them from the server, so use GA4 **Realtime** or the eCommerce reports.
- **`add_to_cart` still doubled.** Check that no other tag (Site Kit Analytics, Google Analytics for WooCommerce, Google Tag Manager, a theme snippet) also sends ecommerce events to the same property.
- **Purchases or refunds are missing or doubled.** That is MonsterInsights' order tracking. This module only decodes their item names.

## For developers

- **Hooks used:**
  - `woocommerce_after_add_to_cart_form` (99) and `woocommerce_add_to_cart` (99, 5 args), both for the legacy path;
  - `wp_enqueue_scripts` (99);
  - `admin_notices`;
  - `monsterinsights_mp_v4_api_call` (20).

  The module has no filters or actions of its own.
- **Payload helpers:** `Ga4_Bridge_Payload` (`includes/class-ga4-bridge-payload.php`). Pure static methods with no WordPress dependency: `clean_text()`, `clean_item()`, `items_value()` and `normalize_body($body, $currency, $decimals)`.
- **Script:** handle `ffla-monsterinsights-bridge` (`assets/js/monsterinsights-bridge.js`), no dependencies, loaded in the footer.
- **Config:** `window.fflaMonsterInsightsBridge` = `{ measurementId, currency, value, items }`.
  - Each item has `item_id`, `item_name`, `price` and `quantity`.
  - When available, it also has `item_category` … `item_category5` and `item_brand`.
- **Sent parameters:** `currency`, `value`, `items`, `send_to` (the measurement ID) and `ffla_bridge: "monsterinsights_compatibility"`, through `window.__gtagTracker('event', 'view_item', params)`.
- **Legacy session key:** `_ga_pending_added_to_cart` (Google Analytics for WooCommerce's own pending-event key).
- **Tests:**
  - `node tests/smoke/monsterinsights-bridge-smoke.js`: `view_item` fallback, duplicate, broadcast, other-property and excluded-user checks, plus a regression check that no `add_to_cart` code path remains.
  - `php tests/smoke/monsterinsights-payload-smoke.php`: decoding and cart-value corrections against MonsterInsights' real payload shapes; purchase totals untouched.
