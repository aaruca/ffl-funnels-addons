# FFL Checkout

Checkout helpers for FFL stores that run the g-FFL Checkout plugin, mainly on custom Bricks checkout templates: Mapbox address suggestions on the billing and shipping address fields, an FFL dealer finder you can place anywhere in the checkout template, and a vendor (warehouse) selector for eligible products. Module ID: `ffl-checkout`, off until it is switched on in **FFL Funnels → Dashboard**. After that, address suggestions and the vendor selector stay off until you turn them on in the module settings. Everything here targets the classic checkout form; there is no Checkout block integration.

What each part needs:

- **Address autocomplete:** a Mapbox public token, or the g-FFL Checkout plugin with its API key saved (the module then borrows a token through it).
- **Dealer finder:** the g-FFL Checkout plugin, active, with its API key saved. This module only places g-FFL Checkout's own widget; the dealer search itself is g-FFL Checkout's.
- **Vendor selector:** a g-FFL Cockpit API key (option `g_ffl_cockpit_key`), and products flagged `automated_listing` with a `pa_upc` attribute.
- Bricks Builder only for the **FFL Dealer Finder** element; the shortcodes work in any template.

## What it does

- **Address autocomplete.** As the customer types a street address, up to six US address suggestions appear under the field. Picking one fills street, address line 2 (when Mapbox has one), city, state, ZIP and country, then recalculates the checkout.
- **Your token or a borrowed one.** Enter your own Mapbox token, or leave it blank and the module fetches one through g-FFL Checkout. The settings page says which source is active.
- **Dealer finder.** The `[ffl_dealer_finder]` shortcode and the **FFL Dealer Finder** Bricks element output g-FFL Checkout's dealer widget (with its messages, colours and document upload) inside templates where g-FFL Checkout's own checkout hook does not run. Shown only when the cart needs an FFL. The selected dealer is highlighted.
- **Vendor selector.** The `[ffl_vendor_selector]` shortcode lists the vendors that can supply each eligible cart item, with stock and price. Choosing one changes that item's price, SKU and shipping class in the cart, and the choice is saved on the order line.

## Setup

### Set up address autocomplete

1. Switch the module on in **FFL Funnels → Dashboard**.
2. Get a token: in the Mapbox Dashboard open **Account → Access Tokens** and copy a public token (it starts with `pk.`). To borrow g-FFL Checkout's token instead, skip this step.
3. Go to **FFL Funnels → FFL Checkout**. Paste the token into **Mapbox Public Token** (or leave it blank), turn on **Enable Address Autocomplete** and click **Save Settings**.
4. Check the line under the token field: "Active source: your own Mapbox token.", "Active source: borrowed from g-FFL Checkout (no token entered).", or a warning that no token is available or none could be borrowed right now.
5. On the checkout page, type at least three characters of a US street address.

### Set up the dealer finder

1. Make sure g-FFL Checkout is active and its API key is saved.
2. In the Bricks checkout template, add the **FFL Dealer Finder** element (category **FFL Funnels**), or a shortcode element with `[ffl_dealer_finder]`. One is enough; only the first copy on a page renders (see [Dealer finder](#dealer-finder)).
3. Test with a firearm in the cart. With only items that need no FFL, nothing is shown.

### Set up the vendor selector

1. Make sure the g-FFL Cockpit API key is saved.
2. Eligible products need the `automated_listing` product meta and a `pa_upc` attribute holding the UPC.
3. In **FFL Funnels → FFL Checkout**, turn on **Enable Vendor Selector** and click **Save Settings**.
4. Add `[ffl_vendor_selector]` to the checkout template.

## Settings

All on **FFL Funnels → FFL Checkout**.

| Setting | What it does | Default |
|---|---|---|
| Mapbox Public Token | Your Mapbox public token. Leave blank to borrow one through g-FFL Checkout. Shown in a masked field. | Blank |
| Enable Address Autocomplete | Loads address suggestions on the checkout page. | Off |
| Enable Vendor Selector | Turns the whole vendor feature on: the selector script on the checkout page, `[ffl_vendor_selector]`, its AJAX endpoint, and re-applying and saving the chosen vendor in the cart and on orders. | Off |

The **Shortcodes** card only lists the two shortcodes. The dealer finder has no settings of its own: its texts, colours, map and document upload follow g-FFL Checkout's settings.

## How it works

### Address autocomplete

- Loads only on the WooCommerce checkout page, only when **Enable Address Autocomplete** is on and a token is available.
- Attaches to the classic checkout fields `#billing_address_1` and `#shipping_address_1`, and again after every checkout refresh.
- The customer's browser calls the Mapbox Search Box API (`/suggest`, then `/retrieve` for the picked address): US addresses only, English, up to six results, starting at three characters after a short pause.
- Fills street, address line 2 when present, city, state (the two-letter code is matched to the state dropdown), ZIP and country, then triggers a checkout update so shipping and tax recalculate.
- Picking a suggestion closes the list and does not start another search; typing again does.

### Mapbox token: your own or borrowed

- An entered token always wins.
- With the field blank, the server asks g-FFL Checkout's vendor API (`https://ffl-api.garidium.com`, action `get_mapbox_token`) with g-FFL Checkout's API key (option `ffl_api_key_option`). This happens while the checkout page is built: 5-second timeout, token cached for 50 minutes, and after a failure no new attempt for 60 seconds. Meanwhile the page loads without suggestions.
- The cached token belongs to the key it was borrowed with. Saving a different g-FFL key clears the cache and the back-off, and a token borrowed with an older key is never served.
- The "Active source" line on the settings page actually borrows (or reads the cached token), so it reports a key that cannot get a token as a warning.
- The borrowing request runs on the server; only the resulting Mapbox token is sent to the browser.

### Dealer finder

- g-FFL Checkout adds its dealer widget through a WooCommerce checkout hook that does not run inside Bricks checkout elements. The shortcode and element output the same container and settings and start g-FFL Checkout's own script (`initFFLJs`), which g-FFL Checkout loads on checkout pages.
- If g-FFL Checkout is not active, it shows "[FFL Dealer Finder: g-FFL Checkout plugin is not active]".
- It shows nothing when g-FFL Checkout's API key is empty, when the cart needs no FFL, or when the signed-in customer has a verified C&R license on file.
- **Does the cart need an FFL?** g-FFL Checkout's own check (`order_requires_ffl_selector()`) decides. If that function is missing: any item (or its parent) with `_firearm_product = yes`, or an ammunition/compliance mode (`?ammo_compliance=1`, or g-FFL Checkout's compliance mode).
- **C&R:** user meta `_ffl_cr_license_verified`, an array whose `expiry` timestamp is in the future. Cookies are not accepted. Nothing in this plugin writes that meta.
- **Texts and colours** come from g-FFL Checkout's options (checkout message, ammunition and non-firearm messages, mixed-cart and name notices, restricted-states message, their colours, map, local pickup, C&R override, mixed-cart support), with built-in English messages when the message options are empty.
- **Document upload:** when g-FFL Checkout's document upload is enabled and the cart has firearms or ammunition, g-FFL Checkout's upload section is added after the widget.
- Each render clears the browser's saved `selectedFFL` value and passes the customer's favourite-FFL cookie (`g_ffl_checkout_favorite_ffl`) to the widget.
- **One widget per page.** Only the first shortcode or element on a page renders; later copies output nothing. If g-FFL Checkout prints its own widget on the same page, this one removes itself and lets g-FFL Checkout's run. The widget's settings are set as window properties, so they never clash with g-FFL Checkout's own script variables.
- **API key in the page.** The widget passes g-FFL Checkout's API key (`aKey`) to g-FFL Checkout's script, exactly as g-FFL Checkout's own widget does: its script will not start without it and sends it with C&R document uploads. It cannot be kept server-side without changing g-FFL Checkout.
- In the Bricks builder the element shows a placeholder.

### Vendor selector

- Only while **Enable Vendor Selector** is on. A table is shown for each cart item whose product has `automated_listing` set, a `pa_upc` attribute (its first term is the UPC), and at least one vendor option. Columns: Select, Vendor ("Vendor" + warehouse ID), Stock, Price (in the store's currency format).
- Options come from the g-FFL vendor API (`get_warehouse_options` for the UPC) while the page renders: 10-second timeout, cached 5 minutes per UPC, and after a failure no new request for 60 seconds. Items without options are left out.
- **Pre-selected:** the vendor already chosen for that cart item (IDs compared as text, so a numeric ID from the API matches); otherwise the one whose distributor ID matches the part of the product SKU before `|`. If neither matches, nothing is ticked, because the cart still has its original vendor.
- **On a change** the server fetches the current options again and accepts the choice only if warehouse, price (to the cent), SKU and shipping class all match one of them. It then sets the cart item's price, SKU and shipping class (creating the shipping class if it does not exist yet) and refreshes the checkout. If the change is refused, the radio buttons go back to the vendor the cart has.
- The choice stays in the cart session and is re-applied when the cart loads. At checkout the order line gets `Vendor` (visible) and hidden `_SKU`, `_Price` and `_ShippingClass` (the class name).
- These cart and order steps, and the AJAX endpoint behind the radio buttons, use the same keys as g-FFL Cockpit, run at priority 20, and are only active while **Enable Vendor Selector** is on.
- A second change to the same item within five seconds gets "Another vendor update is in progress. Please retry."

### Classic checkout and Checkout blocks

The module has no Checkout block (Store API) integration. Autocomplete looks for the classic field IDs, the vendor selector relies on the classic `update_checkout` / `updated_checkout` events, and the dealer finder is a shortcode or Bricks element that depends on g-FFL Checkout's checkout scripts.

## Where it shows up

- **FFL Funnels → FFL Checkout**: the settings page.
- **Checkout page**: the suggestion list under the street address fields; the dealer widget and vendor tables wherever you placed them.
- **Bricks builder**: the **FFL Dealer Finder** element in the **FFL Funnels** category.
- **Orders**: the `Vendor` line-item meta.

## Data and uninstall

| Stored | Where |
|---|---|
| Settings | Option `ffl_checkout_settings` (`mapbox_public_token`, `autocomplete_enabled`, `vendor_selector_enabled`), created on first activation with everything off |
| Borrowed token and back-off | Transients `ffla_borrowed_mapbox_token` (50 minutes), `ffla_borrowed_mapbox_token_fail` (60 seconds) |
| Vendor options, back-off and update lock | Transients `ffl_vendor_opts_{md5(upc)}` (5 minutes), `ffl_vendor_opts_err_{md5(upc)}` (60 seconds), `ffl_vendor_lock_{hash}` (5 seconds) |
| Vendor choice in the cart | Cart item keys `custom_product_option`, `custom_product_option_price`, `custom_product_option_sku`, `custom_product_option_shipping_class` |
| Vendor choice on the order | Line-item meta `Vendor`, `_SKU`, `_Price`, `_ShippingClass` |
| New shipping classes | Created by vendor choices when the API names a class the store does not have |

- **Switching the module off** keeps the settings.
- **Uninstalling the plugin** while the module is on deletes `ffl_checkout_settings` and the module's transients. Order data is never touched: the vendor line-item meta stays with the orders, and order meta written by other plugins (such as g-FFL Checkout's dealer details) is left alone. Shipping classes created by vendor choices stay.

## Troubleshooting

| Problem | What to do |
|---|---|
| No address suggestions | Turn on **Enable Address Autocomplete**; check the "Active source" line; type three or more characters of a US address in the classic checkout's street address field. |
| "g-FFL Checkout is set up, but no token could be borrowed right now…" | The borrow request failed or returned no token (it is retried after 60 seconds). Reload later, or enter your own token. |
| "No token available — enter your own above, or configure the g-FFL Checkout plugin so a token can be borrowed." | Enter a token, or save g-FFL Checkout's API key. |
| "[FFL Dealer Finder: g-FFL Checkout plugin is not active]" | Activate g-FFL Checkout. |
| Dealer finder is empty | Save g-FFL Checkout's API key; check the cart has an item that needs an FFL; make sure the widget is on the checkout page, where g-FFL Checkout loads its scripts. A second copy on the page, or one next to g-FFL Checkout's own widget, stays empty by design. |
| Vendor table missing for a product | Turn on **Enable Vendor Selector**; check the product's `automated_listing` meta and `pa_upc` attribute and the g-FFL Cockpit API key. After an API error the table is hidden for 60 seconds. |
| No vendor is ticked | The cart item has no vendor chosen yet and none matches its SKU. The customer's pick is applied when they click one. |
| "Invalid vendor selection." | The vendor's price, SKU or shipping class changed since the page loaded. The selection goes back; reload the checkout. |
| "Could not verify vendor options." | The vendor API did not answer; try again shortly. |
| "Another vendor update is in progress. Please retry." | Wait a moment and choose again. |

## For developers

- No filters or actions of its own.
- **Shortcodes:** `[ffl_dealer_finder]`, `[ffl_vendor_selector]` (no attributes).
- **Bricks element:** `ffl-dealer-finder` (`Bricks\FFL_Dealer_Finder_Element`), registered on `init` (priority 11) when Bricks is active.
- **AJAX** `ffl_update_cart_vendor` (logged-in and guests; registered only while **Enable Vendor Selector** is on): POST `security` (nonce `ffl_checkout_nonce`), `cart_item_key`, `warehouse_id`, `price`, `sku`, `shipping_class`. Success returns `data.message` = "Vendor updated."; failures return the message string as `data`. There is no AJAX endpoint for the Mapbox token or vendor options.
- **Scripts:** `ffl-checkout-mapbox` (global `fflCheckoutMapbox.accessToken`) and `ffl-checkout-vendor` (jQuery; `fflVendor.ajaxUrl`, `fflVendor.nonce`), enqueued on `wp_enqueue_scripts` (priority 20) on the checkout page.
- **WooCommerce hooks:** `woocommerce_get_cart_item_from_session` and `woocommerce_checkout_create_order_line_item`, both at priority 20, only while the vendor selector is on.
- **Option hooks:** `update_option_ffl_api_key_option` (and add/delete) flush the borrowed Mapbox token.
- **PHP:** `FFL_Checkout_Mapbox::resolve_token()`, `::borrowed_token()`, `::is_borrow_available()`, `::flush_cache()`; `FFL_Checkout_Vendor_Api::selector_enabled()`, `::get_warehouse_options($upc)`, `::get_upc_for_product($product_id)`, `::is_eligible($product_id)`, `::resolve_shipping_class_id($name)`.
- **Vendor API option fields** used: `warehouse_id`, `distid`, `sku`, `price`, `qty`, `shipping_class`.
- **Read from other plugins:** `ffl_api_key_option`, `g_ffl_cockpit_key`, the constant `G_FFL_API_VERSION`, g-FFL Checkout functions such as `order_requires_ffl_selector()`, and its display options (`ffl_init_map_location`, `ffl_checkout_message`, `ffl_ammo_checkout_message`, `ffl_non_firearms_checkout_message`, `ffl_local_pickup`, `ffl_candr_override`, `ffl_include_map`, `ffl_mixed_cart_support`, `ffl_document_upload_enabled` and the notice text and colour options).
- **Smoke tests:** `node tests/smoke/ffl-checkout-mapbox-smoke.js` checks that typing opens suggestions, picking one fills the billing fields with a single retrieve and closes the list, and later typing searches again. `php tests/smoke/ffl-checkout-smoke.php` covers the vendor selector (setting, pre-selection, output buffering), the vendor hooks following the setting, the borrowed-token cache following the g-FFL key, and the dealer finder rendering once per page.
