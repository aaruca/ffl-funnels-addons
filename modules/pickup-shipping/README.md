# Pickup & Shipping

Lets customers choose store pickup or shipping on the classic WooCommerce checkout and hides the WooCommerce shipping methods that do not match the choice. Optional FFL rules do the same for firearm carts, based on the dealer the customer selects: the store's own FFL gets the pickup methods, any other dealer gets the shipping methods. Module ID: `pickup-shipping`, off until it is switched on in **FFL Funnels → Dashboard**. After that it changes nothing at checkout until you select shipping methods in its settings.

What it needs:

- **WooCommerce**, with your pickup and shipping methods set up in shipping zones. Prices, addresses and taxes stay with WooCommerce.
- **Classic checkout.** If the checkout page contains the Checkout block, the module leaves it alone and shows an admin warning. There is no Checkout block (Store API) support.
- **g-FFL Checkout**, active, only for the FFL rules.

> **Read this first.** The module only removes rates from the list WooCommerce built. It never creates a rate, changes a price or makes pickup free, and it does not touch addresses or taxes. Pickup costs whatever its WooCommerce method says.

## What it does

- **Delivery choice.** Two cards, "Pick up in store" and "Ship my order" (texts are editable), above the billing fields or wherever you place `[ffla_delivery_choice]`. The card decides which WooCommerce methods stay on the order.
- **Your WooCommerce methods.** You tick which WooCommerce method instances (listed with their zone) count as pickup and which count as shipping. The module never adds a method of its own, and prices stay as set in the zones.
- **Optional FFL rules.** With g-FFL Checkout, the dealer chosen in its selector decides: the store's own FFL gets the pickup methods, every other dealer gets the shipping methods. The module draws no dealer interface of its own.
- **Final check.** When the order is placed, each shipping package is checked again on the server. A method that is not allowed for the package is refused with a message.
- **Order record.** The shipping line stores whether the order is pickup or shipping and, for pickup, the store name, address and instructions. They show on the order screen, the customer's order view and order emails.
- **Site styling.** Colors, corner radii and card spacing accept HEX values or your site's CSS variables, with a desktop and mobile preview in the settings.
- **Pauses itself.** With incomplete settings, a Checkout block page, or the old Camarillo snippet active, checkout is left as WooCommerce built it and an admin warning explains why.

## Setup

1. If the store still runs the old Camarillo pickup/shipping snippet, disable it first and keep any separate tax code. While the snippet is active the module pauses itself.
2. In **WooCommerce → Settings → Shipping**, check that each zone has the methods you want, with their prices: WooCommerce Local pickup for pickup, your carrier or flat-rate methods for shipping. Only enabled methods are listed in the module.
3. Switch the module on in **FFL Funnels → Dashboard**, then click **Open settings** on its card (or use **Pickup & Shipping** in the sidebar).
4. On the **General** tab choose **Available delivery options**. Tick the method instances under **Pickup methods** and **Shipping methods**. With *Pickup & Shipping* you need at least one of each; the module stays off until you have.
5. Choose **Default selection** and **Selector placement**. For shortcode placement, put `[ffla_delivery_choice]` inside the classic checkout form.
6. Fill in **Store name**, **Pickup address** and **Pickup instructions**.
7. Optional, FFL rules: set the Local Pickup FFL in g-FFL Checkout, then on the **FFL Integration** tab tick **Enable FFL delivery rules**. The detected license appears under **Local Pickup FFL — managed by g-FFL Checkout**. Make sure the pickup methods you ticked in step 4 are the ones for that store.
8. On **Appearance & Text** set the wording and colors. Use **Desktop** and **Mobile** to preview.
9. Click **Save settings**. The page confirms with "Settings saved."
10. Test on staging first (see [Testing and QA](#testing-and-qa)), then confirm pickup cost in WooCommerce.

## Settings

Under **FFL Funnels → Pickup & Shipping**, in three tabs. Needs the `manage_woocommerce` capability.

**General**

| Setting | What it does | Default |
|---|---|---|
| Available delivery options | *Pickup & Shipping*: customers choose. *Pickup only* or *Shipping only*: that option is applied automatically and only its card is shown. | Pickup & Shipping |
| Default selection | Which card is selected when checkout loads: *Ask the customer* (none), *Pickup* or *Shipping*. Ignored when only one delivery option is available. | Ask the customer |
| Selector placement | *Before billing fields* prints the selector automatically. *Shortcode in a custom checkout* prints nothing until you place `[ffla_delivery_choice]`. | Before billing fields |
| Pickup methods | Method instances that count as pickup, shown as "Zone — Title (id:instance)". Lists the enabled instances of WooCommerce pickup methods (those in the `woocommerce_local_pickup_methods` filter, `local_pickup` and `legacy_local_pickup` by default, or any method that declares `local-pickup` support). The "Search methods or zones" box only filters the list. | None ticked |
| Shipping methods | Every other enabled method instance, including "Rest of the world" zone methods. | None ticked |
| Store name | Shown to the customer as the pickup location. | Blank |
| Pickup address | Shown under the cards while Pickup is selected (with the store name and instructions), saved on the order. Up to 1,500 characters. | Blank |
| Pickup instructions | Saved on the order and shown in the customer's order view and emails. Up to 1,500 characters. | Blank |

The pickup details block under the cards appears while Pickup is selected and any of **Store name**, **Pickup address** or **Pickup instructions** is filled in.

**FFL Integration**

| Setting | What it does | Default |
|---|---|---|
| Enable FFL delivery rules | Applies the dealer rules to carts that need an FFL. Requires g-FFL Checkout to be active; without it the whole module pauses. | Off |
| Local Pickup FFL — managed by g-FFL Checkout | Read-only (it refers to the g-FFL Checkout plugin, not this plugin's FFL Checkout module). Shows the license read from g-FFL Checkout's `ffl_local_pickup` option, or "No valid Local Pickup FFL detected." It is not a setting of this module and is never written. | Detected |

**Appearance & Text**

| Setting | What it does | Default |
|---|---|---|
| Selector heading | Heading above the cards. Up to 400 characters. | How would you like to receive your order? |
| Pickup title | Title of the pickup card. | Pick up in store |
| Pickup description | Text under the pickup title. | Collect your order at our store. |
| Shipping title | Title of the shipping card. | Ship my order |
| Shipping description | Text under the shipping title. | Deliver to your shipping address. |
| Heading and card title letters | *UPPERCASE* or *As typed* for the heading and the two card titles. | UPPERCASE |
| Container background | Background of the whole selector. | Blank (transparent) |
| Heading and container text | Heading and general text color. | Blank (inherits the site's text color) |
| Unselected card background | Background of cards that are not selected. | Blank (transparent) |
| Unselected card text | Text color of cards that are not selected. | Blank (container text color) |
| Selected card background | Solid fill of the selected card. Also used for its border and the keyboard focus ring. | Blank (site variable `--primary` if it exists, else `#2271b1`) |
| Selected card text | Text color of the selected card. | Blank (white) |
| Container and unselected card border | Border of the container and of unselected cards. | Blank (site variable `--border-color` if it exists, else `#d0d0d0`) |
| Container border radius | Corner radius of the container. | Blank (square, 0px) |
| Card border radius | Corner radius of each card. | Blank (square, 0px) |
| Gap between cards | Space between the two cards, desktop and mobile. | 16px |

Value formats:

- **Colors:** HEX with 3, 4, 6 or 8 digits (`#fff`, `#2271b1`, `#2271b1cc`), `transparent`, `currentColor`, or a site CSS variable: `var(--primary)`, or just `--primary` (saved as `var(--primary)`). A variable can have a fallback: `var(--primary, #2271b1)` or `var(--primary, var(--brand, #2271b1))`. Fallbacks can nest up to four `var()` levels. Named colors (`red`), `rgb()` and `hsl()` are not accepted, in the field or as a fallback.
- **Radius and gap:** `0`, or a number with `px`, `rem`, `em` or `%` (`12px`, `.5rem`, `50%`), or a variable with an optional fallback in the same formats (`var(--radius, 0px)`). Negative values, unitless numbers, `vw` and `calc()` are not accepted.
- Values longer than 256 characters are rejected. The settings page flags an invalid value in red and the browser blocks the save. The server also drops any invalid value silently, so the default applies.
- Single-line texts are limited to 400 characters and HTML is stripped. A cleared heading or card title goes back to its default text; a cleared description stays empty and is not shown.

More on live variables and a worked example: [Site colors](#site-colors).

## How it works

### When the module acts

The module changes checkout only when all of this is true:

1. The module is on and configured: at least one pickup method **and** one shipping method for *Pickup & Shipping*, one pickup method for *Pickup only*, one shipping method for *Shipping only*, and g-FFL Checkout active when FFL rules are on.
2. The request is the classic checkout page (not the order-received or order-pay pages), or one of its two AJAX calls: `update_order_review` (every checkout refresh) and `checkout` (placing the order).
3. The cart has at least one item that needs shipping.
4. The checkout page has no Checkout block, the request is not a REST/Store API request, and the old Camarillo snippet (the function `cgs_filter_ffl_shipping_rates`) is not present.

Everywhere else WooCommerce behaves as it would without the module. In particular the cart page lists every method WooCommerce offers; filtering starts at checkout.

### Regular carts: the customer chooses

- Clicking a card refreshes the checkout. The module stores the choice in the WooCommerce session, drops that cart's cached shipping rates and recalculates. Only rates from the matching list remain, and WooCommerce then selects among them by its usual rules, including free-shipping coupons.
- A configured method keeps its own services: `ups:4` also keeps `ups:4:ground`, but never `ups:40:ground`.
- With **Available delivery options** set to *Pickup only* or *Shipping only*, that list always applies and one card is shown, already selected.
- With **Default selection** on *Ask the customer*, no card is selected at first. Until one is chosen, WooCommerce shows the rates from both lists (so the totals have a provisional shipping line) and the order cannot be placed: "Choose pickup or shipping before placing your order." Choose *Pickup* or *Shipping* as the default if you prefer one list from the start.
- A card is greyed out with "Unavailable for the current address or package." when the last calculation found no allowed rate for it in a package.
- The saved choice belongs to one cart and is cleared when the products or quantities change, when the cart is emptied and when the order is placed.
- The pickup address block is shown while Pickup is selected.

### FFL carts: the dealer decides

A cart counts as an FFL cart when g-FFL Checkout says an FFL selector is required (`order_requires_ffl_selector()`), its compliance mode is on (`ffl_get_checkout_compliance_type()`), a product is flagged `_firearm_product = yes` (a variation counts when its own flag or its parent's is `yes`, the same rule as Customer & Order Management, and g-FFL Checkout's `product_is_firearm()` is honored), or the checkout review posts `compliance_mode`, `ammo_compliance=1` or `non_firearms_compliance=1`.

With **Enable FFL delivery rules** on, an FFL package ignores the delivery cards. The selected dealer decides:

| Dealer state | Mode | Methods kept |
|---|---|---|
| No dealer selected, or the value is cleared, malformed or conflicting | Pending | None. Placing the order shows "Select your FFL dealer to see the available delivery methods." |
| The store's own FFL (the license in g-FFL Checkout's `ffl_local_pickup`) | Pickup | Pickup methods |
| Any other valid license | Shipping | Shipping methods |

- **Same store.** Two licenses are the same store when the first three and last five digits match, so a renewal (the middle segment changes) stays the same store. This is identification matching, not license verification. Names, cookies and old addon location mappings never authorize pickup.
- **Delivery options still apply.** With *Pickup only*, an external dealer gets no methods; with *Shipping only*, the store's own FFL gets none. The order cannot be placed until the dealer changes or the setting does.
- **Dealer source.** The module reads the posted `shipping_fflno`. When that field is absent (for example disabled), it reads `backup_fflno`, `ffl_license_backup` and `ffl_id`, but only if every one of them agrees. Nothing is read from cookies or browser storage. When the order is placed, the submitted fields are read again, never the session.
- **Placeholder.** On FFL-only carts the module outputs only an empty hidden `#ffla-delivery-choice` element. It lets checkout refreshes bring the cards back if regular items are added.
- **Rules off.** With **Enable FFL delivery rules** off, firearm carts use the customer cards like any other cart. g-FFL Checkout's own checks still run.
- **Conflict message.** g-FFL Checkout 2.2.5 compares the submitted license with its Local Pickup FFL as plain text, so a renewed or reformatted license of the same store can trigger its error `ffl_local_pickup_conflict`. After every package passes the module's checks and the submitted fields agree on one full license that matches the store, the module removes that one error. All other errors stay. Details are in [Support boundaries](#support-boundaries).

### Packages and mixed carts

- Rules apply per shipping package. If another plugin has already split a cart into an FFL package and a customer package, each is handled on its own: the FFL package follows the dealer, the customer package follows the cards. Destinations are never changed.
- A package that contains both firearm and non-firearm items that need shipping is blocked with "This cart needs separate FFL and customer shipping packages. Please contact the store or place separate orders." The module does not split packages or allocate inventory.
- With FFL rules on, a compliance-only cart (the provider requires an FFL but no product is firearm-flagged) is treated as FFL for every package.

### Payment-plan shipping rates

Some payment-plan plugins add their own zero-cost shipping rate. The module removes any rate that is not in your lists, so such a rate would vanish. It keeps one only when another plugin approves it through the `ffla_pickup_shipping_keep_internal_rate` filter. Split Payment (FPPC) 1.2.3 uses this filter for its deferred or included shipping rate. The filter runs only when a delivery mode is resolved and allowed, and only for an offered rate whose cost is 0. It cannot bypass a missing dealer, a *Pickup only* or *Shipping only* restriction, or a blocked mixed package, and it does not change the rate's price or destination. The order still records pickup as pickup and shipping as shipping.

### In the browser

`delivery.js` loads on checkout when the module is active.

- Choosing a card, or changing a dealer field, requests a checkout refresh after a short pause (120 ms). A refresh triggered by g-FFL Checkout itself cancels the module's own pending one.
- After each refresh the script aligns WooCommerce's shipping-method control (radio buttons, a dropdown or a single hidden field) with the method the server confirmed. It notifies checkout once per dealer and method, so theme redraws cannot start a loop.
- If a response is older than the dealer now in the form, the script disables that package's method controls and asks for one fresh review.
- If a redraw empties g-FFL Checkout's hidden dealer fields while exactly one dealer card is selected, the script restores the license from that card and requests one review. A click on the store-search button alone never selects a dealer.
- While updating, the status line reads "Updating delivery options…". After a failed refresh it reads "Delivery could not be updated. Please try again before placing your order."
- The cards are native radio inputs, hidden visually but reachable by keyboard, with a visible focus ring. The stylesheet shows the heading and card titles in capitals; there is no setting for that.

### What it does not change

- Prices, free-shipping rules and carrier quotes. Rates are kept or removed as WooCommerce built them.
- Billing and shipping addresses, fees, tax addresses and tax calculation. With the Tax Rates module on, its own local-pickup rule is independent of this module.
- g-FFL Checkout's settings, its `ffl_local_pickup` option and its validators (apart from the one error above).
- Package splitting and inventory allocation.

### Classic checkout and Checkout blocks

Classic checkout (the `[woocommerce_checkout]` form or a compatible custom template) only. The module needs the standard WooCommerce checkout script and form and the order-review fragments. If the checkout page contains the Checkout block, or the request is a Store API request, the module does nothing and an admin notice says so.

## Where it shows up

- **FFL Funnels → Pickup & Shipping** (`admin.php?page=ffla-pickup-shipping`): the settings page. A link on it goes to **WooCommerce → Settings → Shipping** for zones and prices.
- **Checkout page**: the selector section before the billing fields, or where you placed the shortcode. Not shown for carts with nothing to ship, and only as an empty placeholder for FFL-only carts.
- **Admin notices**: shown on every admin screen to users with `manage_woocommerce`: Checkout block page, old Camarillo snippet active, or settings incomplete. Each links to the settings.
- **Order screen**: shipping-line meta *Delivery* ("Store pickup" or "Shipping"), and for pickup *Pickup location*, *Pickup address*, *Pickup instructions* (each only when filled in). A "Delivery information" block also appears after the shipping address.
- **Customer order view and emails**: the same "Delivery information" block after the order table, in HTML and plain-text emails. Store emails (New order) include it on purpose, so staff see pickup orders at a glance.
- **Customer & Order Management module**: its Ready for Pickup check accepts a shipping line that is `local_pickup` or carries `_ffla_delivery` with mode `pickup`.

## Data and uninstall

| Stored | Where |
|---|---|
| Settings | Option `ffla_pickup_shipping` (not autoloaded), created with defaults on first activation. Keys: `delivery`, `default`, `placement`, `pickup_methods`, `shipping_methods`, `ffl_enabled`, `store_name`, `store_address`, `instructions`, the text and appearance fields, and `locations` |
| Legacy locations | `locations` is kept on every save for rollback only. It is not shown in the settings and is never used to authorize pickup |
| Checkout choice | WooCommerce session keys `ffla_pickup_shipping_state` (mode, normalized dealer license, a requires-FFL flag, a hash of the cart, a hash of the settings) and `ffla_pickup_shipping_available` (pickup/ship availability flags per package). No customer addresses |
| Rate cache | The cart's `shipping_for_package_{key}` session entries are removed when the choice, dealer or settings change |
| Package context | An `ffla_delivery` entry on each shipping package once the settings are complete and the request is supported (classic checkout flow, no old snippet). It is part of WooCommerce's package hash, so rates are recalculated when the policy, the cart context or the dealer change. Before setup is complete, packages, sessions and cached rates are left exactly as WooCommerce built them |
| Order data | On the shipping line: meta `_ffla_delivery` (`mode`, `name`, `address`, `instructions`) and the visible *Delivery*, *Pickup location*, *Pickup address*, *Pickup instructions*. Written through WooCommerce's item API, with no direct post-meta writes |

- No tables, cron jobs, remote requests or product/user meta.
- **Switching the module off** keeps the settings and all order data. WooCommerce's own delivery flow works as before.
- **Uninstalling the plugin** deletes the option `ffla_pickup_shipping`. Order data stays with the orders.
- Only orders placed through the classic checkout carry the delivery data. Orders created in the admin, by REST or by other plugins do not.

## Troubleshooting

| Problem | What to do |
|---|---|
| Checkout looks unchanged | Look for a warning at the top of the admin screens. Then check: the module is on; both method lists (or the one list your delivery option needs) have a method ticked; the checkout page is classic; the cart has an item that ships; and you are on the checkout page, not the cart page, which lists every method. |
| "Pickup & Shipping needs its delivery methods and optional FFL integration configured…" | Finish **General**. If **Enable FFL delivery rules** is on, g-FFL Checkout must be active. This warning is expected right after switching the module on. |
| The whole module stopped after you ticked **Enable FFL delivery rules** | g-FFL Checkout is not active (the module looks for its `order_requires_ffl_selector()` function). The module pauses completely, regular cards included. Activate g-FFL Checkout or untick the setting. |
| "Pickup & Shipping is paused: disable the old Camarillo pickup/shipping snippet…" | Disable the old snippet. Keep any separate tax code. |
| "Pickup & Shipping supports classic checkout and its shortcode only. Checkout Blocks are unchanged." | The checkout page contains the Checkout block. Use the classic checkout form or a custom classic template. |
| "A selected method is disabled or missing: …" | Enable the method in its WooCommerce zone, or untick it. A method that was deleted and added again is a new instance and must be ticked again. |
| No selector, but the module is configured | With shortcode placement, add `[ffla_delivery_choice]` inside the checkout form; outside the form it cannot submit a choice. With automatic placement, the template must run `woocommerce_checkout_billing`. Only one selector is printed per page. FFL-only carts and carts with nothing to ship show none by design. |
| A card is greyed out: "Unavailable for the current address or package." | No allowed rate exists for that choice at the customer's address. In WooCommerce, check that the ticked instance is enabled and in the zone matching the address, and its conditions. Selecting an instance here does not add it to other zones. |
| "Choose pickup or shipping before placing your order." | The customer has not picked a card. Set **Default selection** if you want one pre-selected. |
| "Select your FFL dealer to see the available delivery methods." | No valid dealer is selected yet. If one is selected, see the dealer-field rules in [Support boundaries](#support-boundaries). |
| The store's own dealer gets shipping instead of pickup | Compare the license under **FFL Integration** with the dealer's (first three and last five digits must match). If it reads "No valid Local Pickup FFL detected.", set it in g-FFL Checkout. Also check that **Available delivery options** is not *Shipping only*. |
| An external dealer sees no methods | **Available delivery options** is *Pickup only*, or none of the ticked shipping methods exists in the zone that matches the customer's address. |
| "This cart needs separate FFL and customer shipping packages…" | One package holds firearm and non-firearm items. The module does not split packages; a separate plugin must, or the customer must place separate orders. |
| "No compatible delivery method is available for this package. Review your address and selection, or contact the store." | The package has no allowed rate, or the posted method is not one of them. Check address, zone and the ticked methods. |
| Pickup shows a price | That is the WooCommerce method's cost. Change it in the shipping zone; the module never makes a method free. |
| Pickup details are missing under the cards | **Store name**, **Pickup address** and **Pickup instructions** are all empty, or Pickup is not the selected card. |
| A color or size is ignored | The value is not in an accepted format (see [Settings](#settings)), or the variable does not exist on the storefront. Fix the red field; the browser will not save an invalid value. |
| Admin preview differs from checkout | Theme variables are not loaded in wp-admin, so only their fallback shows. Add a fallback, such as `var(--primary, #2271b1)`. |
| Heading and card titles are in capitals | The stylesheet does that. There is no setting for it. |
| "Delivery could not be updated. Please try again before placing your order." | The checkout refresh failed. Try again; if it persists, check the `update_order_review` request for an error. |
| No delivery information on an order | Only orders placed through the classic checkout while the module was on carry it. |

## For developers

**Filters**

| Filter | Arguments | Notes |
|---|---|---|
| `ffla_pickup_shipping_package_scope` | `string $scope`, `array $package` | Return `ffl`, `regular` or `mixed`; any other value becomes `mixed`. Runs for every package of a cart the provider says needs an FFL, after the module's own classification (compliance-only carts start as `ffl`). Carts that need no FFL are always `regular`. Preserve real destinations and the provider's validation. |
| `ffla_pickup_shipping_keep_internal_rate` | `bool $keep` (false), `string $rate_id`, `object $rate`, `array $package`, `array $decision` | Return exactly `true` to keep an offered rate that is not in the configured lists. Only called when the package carries delivery context, `$decision['policy_permitted']` is `true`, the mode is `pickup` or `ship`, `$rate->get_id() === $rate_id`, and the cost is numeric 0. An ID alone is not authorization: verify the package and plan on your side. Does not change price, destination or mode. Added in 1.47.7. |

The module also reads WooCommerce's `woocommerce_local_pickup_methods` filter to decide which methods are pickup.

**Context on each package** (`$package['ffla_delivery']`): `active` (bool), `scope` (`ffl`, `regular`, `mixed`), `decision` (empty when not active), `version` (hash of the settings), `package_key`, `dealer` (normalized license or empty).

**Decision array**: `mode` (`pickup`, `ship`, `none`, `pending`, `blocked`), `methods` (configured instance IDs), `reason` (`customer`, `ffl`, `dealer`, `mixed`), `policy_permitted` (bool), and `location` (store details for FFL pickup, otherwise `null`).

**Hooks used**

| Hook | Priority | What the module does |
|---|---|---|
| `woocommerce_checkout_update_order_review` | 5 | Reads the posted choice and dealer into session state; clears cached package rates when it changed |
| `woocommerce_checkout_process` | 1 | Same on place order; a missing dealer field counts as cleared |
| `woocommerce_cart_shipping_packages` | 999 | Adds the `ffla_delivery` context to each package |
| `woocommerce_package_rates` | 999 | Removes rates that are not allowed; records pickup/ship availability |
| `woocommerce_shipping_packages` | 999 | Filters cached rates again and lets WooCommerce pick the chosen method before totals |
| `woocommerce_after_checkout_validation` | 999 | Final per-package check; removes `ffl_local_pickup_conflict` in the one case above |
| `woocommerce_checkout_create_order_shipping_item` | 20 | Writes the delivery meta on the shipping line |
| `woocommerce_checkout_billing` | 5 | Automatic placement |
| `woocommerce_update_order_review_fragments` | 10 | Replaces `#ffla-delivery-choice` |
| `wp_enqueue_scripts` | 40 | Loads `ffla-delivery` CSS and JS on checkout |
| `woocommerce_cart_emptied`, `woocommerce_checkout_order_processed` (999) | | Clears the module's session keys |
| `woocommerce_cart_updated` | 10 | Clears them when the cart contents changed |
| `woocommerce_email_after_order_table` | 20 | "Delivery information" in emails |
| `woocommerce_order_details_after_order_table`, `woocommerce_admin_order_data_after_shipping_address` | 10 | "Delivery information" on order views |
| `admin_notices`, `admin_post_ffla_pickup_shipping_save`, `admin_enqueue_scripts` | 10 | Warnings, settings save, settings assets |

- **Shortcode:** `[ffla_delivery_choice]`, no attributes. Prints once per request, only while the module is active for that request.
- **Markup:** `#ffla-delivery-choice` carries `data-ffla-shipping-policy`, JSON keyed by package: `mode`, `methods`, `selected`, `dealer`. The radios are named `ffla_delivery_mode` (`pickup`, `ship`).
- **Settings save:** `admin-post.php?action=ffla_pickup_shipping_save`, nonce action `ffla_pickup_shipping_save`, capability `manage_woocommerce`, values in the POST array `ps`. No AJAX endpoints and no REST routes.
- **Assets:** front end `ffla-delivery` (CSS; JS with jQuery and `wc-checkout`, localized as `fflaDelivery.updating` and `fflaDelivery.error`); admin `ffla-delivery-admin` (CSS and JS) and `ffla-delivery-preview` (the front-end CSS), only on `?page=ffla-pickup-shipping`.
- **CSS custom properties** set inline on `.ffla-delivery` from the settings: `--ffla-delivery-bg`, `-text`, `-card-bg`, `-card-text`, `-accent`, `-selected-text`, `-border`, `-radius`, `-card-radius`, `-gap`, `-text-transform` (all prefixed `--ffla-delivery`). Classes: `.ffla-delivery__options`, `__option`, `__card`, `__details`, `__status`; `aria-busy` is set while updating.
- **Order meta:** shipping-line `_ffla_delivery` array (`mode` is `pickup` or `ship`; `name`, `address`, `instructions` are empty for `ship`).
- **Constants and keys:** `Pickup_Shipping_Settings::OPTION` (`ffla_pickup_shipping`), `Pickup_Shipping_Checkout::SESSION` (`ffla_pickup_shipping_state`), `Pickup_Shipping_Checkout::AVAILABLE` (`ffla_pickup_shipping_available`). The module defines no global constants; assets use `FFLA_URL` and `FFLA_VERSION`.
- **Classes:** `Pickup_Shipping_Module`, `Pickup_Shipping_Settings` (`get()`, `defaults()`, `methods()`, `sanitize()`, `configured()`, `provider_license()`, `license()`, `appearance_value()`), `Pickup_Shipping_Engine` (`decision()`, `filter()`, `allows()`, `package_scope()`, `product_requires_ffl()`, `licensee_key()`, `same_licensee()`), `Pickup_Shipping_Checkout`. `Pickup_Shipping_Settings::license()` returns the 15-character uppercase license (nine digits, one letter, five digits) or an empty string; Customer & Order Management reuses it to normalize FFL licenses when this module is loaded.
- **Read from g-FFL Checkout:** option `ffl_local_pickup`; functions `order_requires_ffl_selector()`, `product_is_firearm()`, `ffl_get_checkout_compliance_type()`; product meta `_firearm_product`; posted fields `shipping_fflno`, `backup_fflno`, `ffl_license_backup`, `ffl_id`, `compliance_mode`, `ammo_compliance`, `non_firearms_compliance`; its JavaScript event `ffl-dealer-selected` and the elements `#ffl_container` and `#ffl-list`; the error code `ffl_local_pickup_conflict`. When the script restores a lost dealer selection in the browser it also sets the hidden field `ffl_selection_confirmed` to `1`.
- **Combined with the FFL Checkout module:** this module does not depend on it. That module's dealer finder places g-FFL Checkout's own widget (`#ffl_container`), but the combination is not covered by this module's tests.

## Testing and QA

The notes below are the integration and QA notes the module shipped with. In this section "FFL Checkout" and "the provider" mean the g-FFL Checkout plugin, not this plugin's FFL Checkout module.

The module is registered but never automatically activated or configured. Production sites are not changed by building this code.

### Activation checklist

1. Keep the site on its current release until staging verification is complete.
2. Enable **Pickup & Shipping** in **FFL Funnels → Dashboard**.
3. Select enabled shipping-zone method instances in General. Empty/incomplete settings leave checkout unchanged.
4. Select automatic placement or use `[ffla_delivery_choice]` inside the classic `form.checkout`. A shortcode outside the form cannot submit a choice.
5. Optionally enable FFL rules with g-FFL Checkout active. The addon reads the native `ffl_local_pickup` setting directly and displays the detected license read-only. Configure the local FFL only in FFL Checkout, not in this addon.
6. Select the corresponding WooCommerce pickup method instances in General. The existing FFL Checkout local-pickup selector stays in control: its configured local FFL permits pickup rates; another selected FFL permits shipping rates. Prices remain under WooCommerce.
7. Disable the old Camarillo shipping snippet before enabling these rules. If taxes are in the same legacy snippet, separate them first; do not discard tax code.
8. Confirm pickup cost in WooCommerce. The addon never makes a method free.

### Site colors

Appearance & Text provides separate container background/text, unselected card background/text, selected card background/text and border colors. Selected cards use a solid fill; keyboard-accessible native radio inputs are visually hidden. The component inherits the site's font.

Colors accept HEX (including alpha), transparent, currentColor or site CSS variables, such as `var(--primary)`, `var(--surface)` and `var(--text)`. A bare `--primary` is normalized to `var(--primary)`. Optional fallbacks work too: `var(--primary, #2271b1)` or `var(--primary, var(--brand, #2271b1))`.

Container radius, card radius and gap between cards are independent settings. Corners default to square; gap defaults to 16px. Use nonnegative px/rem/em/% values, 0, or variables with optional fallbacks, such as `var(--radius, 0px)` and `var(--space-m, 16px)`. The addon defines its grid gap explicitly for both desktop and mobile; generic checkout grid rules must not replace it.

References remain live, so changing the site's variables updates the checkout without saving the addon again. Blank settings retain the default site inheritance. Variables must resolve to full CSS colors, not partial RGB/HSL channel values. A theme variable defined only on the storefront cannot resolve in the admin preview; use an explicit fallback if needed. Theme styles are not loaded into wp-admin by this module.

Example dark styling: container #050505, heading #ffffff, card #111111, card text #dddddd, selected background var(--primary, #ff1616), selected text #ffffff, border #333333, both radii 0px, gap 16px. These are optional per-site settings, not hardcoded store branding.

### Support boundaries

- Native FFL Checkout behavior varies by release and store configuration: the in-store pickup button (**CLICK HERE FOR IN STORE PICKUP**) may search for the store and may also complete its dealer selection automatically. This addon does not infer intent from the button click. It follows the final license that FFL Checkout posts in its primary or documented backup fields. If a classic WooCommerce fragment redraw empties those hidden fields while FFL Checkout still exposes exactly one selected dealer card, the addon restores the license from that native card and performs one new checkout review. The configured store and its renewed license are matched by ATF's abbreviated identity (first three plus last five digits), so expiration-segment changes do not turn local pickup into shipping. Any other selected FFL receives shipping.
- Review and final validation accept native `backup_fflno`, `ffl_license_backup` and `ffl_id` fields only when `shipping_fflno` is absent (for example, disabled fields are omitted from form serialization). Present primary values, including an explicit clear, take priority. Conflicting or malformed backups fail closed. No cookie/localStorage identity fallback is used. Native `update_checkout` requests cancel redundant pending addon refreshes.
- Classic checkout and compatible custom templates only. The standard WooCommerce checkout script/form and order-review fragments are required.
- FFL-only delivery displays no duplicate heading, cards or dealer notices from this addon; use the native FFL Checkout selector. An empty hidden fragment target allows AJAX to restore controls when regular-item packages appear. Server-side delivery rules and final validation remain active.
- Checkout Blocks and Store API requests are left untouched; an admin notice explains the limitation.
- Existing distinct FFL and customer packages are handled separately. An unsplit mixed package is rejected instead of choosing a destination for its items. No package splitting or inventory allocation is performed.
- Package classification uses parent-aware firearm flags, the provider's required-selector check and its state-compliance helper. The server filter `ffla_pickup_shipping_package_scope` can classify an existing package as `ffl`, `regular` or `mixed`. Integrators must preserve actual destinations and provider validation.
- License normalization accepts a full formatted/unformatted FFL number. Local identity comparison uses ATF's first-three/last-five abbreviated FFL identifier so routine license renewals remain attached to the configured store. This is identification matching, not license verification. The FFL provider retains its authorization/restriction checks.
- The native local-pickup option is never overridden or written. Provider changes are read automatically and enter WooCommerce's package-cache fingerprint. Missing/malformed native configuration cannot fall back to an old addon mapping. Legacy mappings are retained on settings saves for rollback only, not used for authorization. The native FFL Checkout validators remain installed.
- The addon does not override billing/shipping addresses, fees, tax addresses or tax calculations. Stores with per-package pickup/delivery taxation need the relevant provider integration verified separately.
- No cron jobs, schema changes, remote services or stored customer-address copies in module session state. Delivery/location snapshots are stored through shipping-item CRUD.

### Offline tests

Run from the plugin folder:

```text
php tests/smoke/pickup-shipping-smoke.php
php tests/smoke/pickup-shipping-smoke.php missing-provider
php tests/smoke/pickup-shipping-internal-rates-smoke.php
php tests/smoke/pickup-shipping-native-conflict-smoke.php
php tests/smoke/pickup-shipping-transitions-smoke.php
node tests/smoke/pickup-shipping-ui-smoke.js [screenshot-directory]
node tests/smoke/pickup-shipping-transitions-ui-smoke.js
```

- `pickup-shipping-smoke.php`: settings sanitizing, the color and radius fixtures, ATF identity matching, delivery decisions, session state, final validation, native backup fields, provider-setting changes, order meta and emails, Checkout blocks, registered hooks and the FFL placeholder. With `missing-provider` it checks that FFL rules pause without g-FFL Checkout.
- `pickup-shipping-internal-rates-smoke.php`: the `ffla_pickup_shipping_keep_internal_rate` contract, with a synthetic payment-plan rate and no payment-plan plugin.
- `pickup-shipping-native-conflict-smoke.php`: the raw-string local-pickup conflict and which errors may and may not be removed.
- `pickup-shipping-transitions-smoke.php`: store → external dealer → store, cache invalidation, mixed packages, and unchanged behavior for Checkout blocks and non-checkout requests.
- The two `.js` tests run the real admin and checkout CSS/JS in a browser. `pickup-shipping-ui-smoke.js` covers the settings page (tabs, method search, preview, color and size validation) and checkout refreshes against simulated theme styles and native dealer events. `pickup-shipping-transitions-ui-smoke.js` covers dealer changes, stale responses, late theme redraws and radio, dropdown and hidden shipping controls.
- The PHP tests, including `missing-provider`, run on push and pull requests (`php-syntax` workflow) and in the release workflow before publishing. The browser tests are not part of CI; both honor `FFLA_TEST_BROWSER_CHANNEL` (for example `chromium`).
- Fixtures: `tests/smoke/pickup-shipping-colors.json` and `pickup-shipping-radii.json` list accepted inputs with their saved form and rejected inputs, and both the PHP and browser tests read them. `php tests/smoke/pickup-shipping-smoke.php fixture "<post data>" "<ffl or empty>" "<variables or empty>"` prints the admin HTML, the checkout HTML, the filtered rates and the selected methods as JSON; the browser tests use it as their server.

Browser tests require Playwright and installed Chrome (or set FFLA_TEST_BROWSER_CHANNEL).
Set FFLA_TEST_JQUERY to an existing WordPress jquery.min.js. The default temporary fixture is tmp/pickup-jquery.min.js; it is a test dependency, not a plugin asset.
The browser tests block all network requests and use synthetic PHP fixtures. They exercise the real UI JS/CSS with a simulated WooCommerce review transport, not a live payment flow.

### Staging acceptance matrix

- Guest and logged-in non-FFL order; no default, default shipping, default pickup; missing address and unavailable zones.
- Shipping-only, pickup-only and both; paid pickup must keep its price and tax.
- Native local FFL, external FFL, clearing/changing dealer; change or clear the native pickup setting without saving the addon and verify cache invalidation. No fallback based on names, cookies or legacy mappings.
- Variation firearm flags; provider state-compliance carts; provider-specific verified exemptions/selector overrides.
- Multiple regular packages, separated mixed packages, and unsupported unsplit mixed packages.
- Cart → checkout, address edits, coupons, quantity changes, emptied cart, retry after payment failure, restored checkout.
- Check actual server-selected methods match each package after AJAX, not only the visible cards.
- Confirm carrier quotes and taxable addresses remain correct for the site's configured shipping/tax providers.
- Metadata in administration, customer order view, HTML/plain-text emails and HPOS-enabled orders.
- Classic custom templates: shortcode inside form, correct checkout script, no duplicate selector, no old snippet active.
- Disable the module: existing WooCommerce delivery flow remains available.
