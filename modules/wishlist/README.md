# Wishlist

Lets shoppers save products with a heart button, guests included, and come back to them on a wishlist page; a header counter shows how many they have saved. It is for store managers who want a simple save-for-later list, and for developers placing the buttons in Bricks or custom templates. Module ID: `wishlist`, off until it is switched on in **FFL Funnels → Dashboard** (when the plugin is first activated on a site that already has wishlist tables, the module is switched on automatically). Requires WooCommerce. The Bricks elements and query type need Bricks Builder; the search-results integration needs the SnapFind plugin.

## What it does

- **Heart buttons** add or remove a product without reloading the page and show a short "Added to Wishlist" / "Removed from Wishlist" message.
- **One list per shopper.** Signed-in customers keep it on their account; guests keep it through a browser cookie for 30 days. When a guest signs in, the guest list is merged into the account.
- **Counter** for the header, updated after every change.
- **Wishlist page** with image, name, price, a remove button and **Add to Cart** for each product.
- **Bricks**: *Wishlist Button* and *Wishlist Counter* elements and a *Wishlist* query type for custom layouts. **Shortcodes** for everything else.
- **SnapFind (Typesense)**: hearts on search results, an optional ranking boost for the visitor's saved products, and a `wishlist_count` value for the search index.
- **Look**: three colours, a custom icon (Media Library or pasted SVG) and a custom CSS box.

## Setup

1. Switch on **Wishlist** in **FFL Funnels → Dashboard**. Its two database tables are created.
2. Create a page (e.g. "Wishlist") with the `[alg_wishlist_page]` shortcode, or build it in Bricks with a query loop set to the *Wishlist* query type.
3. Open **FFL Funnels → Wishlist**, choose that page under **Wishlist Page**, adjust colours and icon, and click **Save Settings**. Saving needs an administrator account (`manage_options`).
4. Add heart buttons: the **Wishlist Button** element in your product card and single product templates, or `[alg_wishlist_button]`.
5. Add the **Wishlist Counter** element (or `[alg_wishlist_count]`) to the header.
6. With SnapFind active: hearts appear on search results on their own. Optionally switch on **Boost wishlisted products in search**, and add the `wishlist_count` field in SnapFind (see [SnapFind](#snapfind-typesense)).

## Settings

**FFL Funnels → Wishlist**:

| Setting | What it does | Default |
|---|---|---|
| Primary Color (Heart) | Colour of the heart buttons. | `#ff4b4b` |
| Hover Color | Colour on hover. | `#ff0000` |
| Active Color (Filled) | Colour (filled heart) when the product is in the list. | `#cc0000` |
| Custom Icon | Media Library image that replaces the heart everywhere. An SVG is inlined, so the colours above still apply; other image types are shown as a 48 px image and cannot be recoloured. | none (heart) |
| Custom Icon SVG (advanced) | Raw SVG used only when no **Custom Icon** is selected. Must include a `viewBox`. Scripts and unknown tags are stripped. | empty |
| Wishlist Page | Page the counter links to. Put `[alg_wishlist_page]` on it. | "-- Select Page --" |
| Custom CSS | CSS added to every page with the wishlist styles. HTML tags, `@import`, `expression(`, `javascript:` and `url(data:` are removed. | empty |
| Boost wishlisted products in search | Shown only when SnapFind is active. Moves the visitor's saved products up in SnapFind results. | off |
| Delete wishlist data on uninstall | Drops the wishlist tables and settings when the plugin is deleted. | off |

The settings page also has a **Documentation** card summarising the elements, shortcodes and the SnapFind setup.

## How it works

### Where lists are stored

- Signed-in customers: one default list tied to the account. Guests: a random ID in the `alg_wishlist_session` cookie (30 days from the first visit, HttpOnly, SameSite Lax, Secure on HTTPS). The cookie is set on a guest's first page view; the list itself is only created when they save their first product, so visits by bots do not create rows.
- **Sign-in merge**: on the first request after a guest signs in, their guest list is moved to the account (or merged into the existing account list, dropping duplicates) and the cookie is cleared.
- Products are saved per product. The storefront buttons do not send a variation, so a variable product is saved as the parent product.
- Only published products are counted or shown. Deleted or unpublished products stay in the table but drop out of the count, the page and the query loop. At most 500 products are read per list.

### Adding and removing

- A button toggles by default: add when the product is not in the list, remove when it is. Buttons set to *Add only* / *Remove only* (or `action="add"` / `action="remove"`) always do that one thing.
- The heart changes immediately and is put back if the request fails. Failures are logged to the browser console only; the shopper sees no message.
- **Limits**: 60 requests per IP address and account; the counter only resets after a minute without wishlist requests. Adding is refused at 200 products ("Wishlist is full."), but only for add-only requests; the default toggle does not check this limit.
- After each change every counter (`.alg-wishlist-count`) on the page is updated and hidden at 0, and every element on the page with the same `data-product-id` gets or loses the `active` class.

### Loading and caching

- The script and styles load on every front-end page while WooCommerce is active, so the header counter works everywhere. They also load inside the Bricks builder.
- The visitor's saved product IDs and a security token are printed into each page to set the hearts and counter without an extra request. A full-page cache can therefore show one visitor's hearts and count to another, and serve a token that has expired. Exclude wishlist pages from the cache, or have the cache skip or vary on the `alg_wishlist_session` cookie.
- An inlined Media Library SVG is cached for a day (transient `alg_wl_icon_<attachment ID>`) and cleared when you choose a different icon. SVG files over 256 KB are ignored.

### Icons

Each heart uses the first available of: the element's own **Custom icon** (Bricks) → **Custom Icon** from the settings → **Custom Icon SVG (advanced)** → the default heart. This applies to the Bricks elements, the button and counter shortcodes and the SnapFind buttons. `[alg_wishlist_button]` also accepts its own `icon` SVG.

### SnapFind (Typesense)

Active when the SnapFind plugin is installed and active, and only on pages where SnapFind's own script and the wishlist script both load.

- **Hearts on results**: a round 34 px heart is added to the top-right corner of each search hit after SnapFind renders or updates its results. It uses the primary and active colours and the configured icon (shown at 18 px).
- **Boost** (setting, off by default): adds a rule to SnapFind's boost configuration that ranks the visitor's saved products higher (weight 5). Nothing happens when the list is empty or SnapFind exposes no boost configuration.
- **`wishlist_count`**: when SnapFind builds a product's search document, the number of wishlists (guest and account) containing that product is added as `wishlist_count`. To use it, add a field in **SnapFind → Schema Builder** for *product*: slug `wishlist_count`, type `int32`, index yes, sort yes, then run a full reindex. The value is only refreshed when a product is reindexed.

## Where it shows up

### Admin

**FFL Funnels → Wishlist**: the settings above plus the **Documentation** card.

### Bricks elements

Both are in the **FFL Funnels** element category.

| Element | Main controls | Notes |
|---|---|---|
| Wishlist Button | **Product ID** (empty = current product, supports dynamic data), **Button action** (*Toggle*, *Add only*, *Remove only*), **Show label text** (off), **Add text** ("Add to Wishlist"), **Remove text** ("Remove from Wishlist"), colours **Default / Hover / Active color**, **Custom icon**, **Icon size** (24), **Label typography** | Rendered as a `<button>`, already marked active when the product is saved. *Remove only* suits wishlist query loops. |
| Wishlist Counter | **Wishlist page** (link; empty = **Wishlist Page** setting, else `#`), **Make link** (on; off renders a `<div>`, useful to avoid nested links), **Hide badge when zero** (on), **Show icon** (on), **Label text**, **Label position** (*Right of icon* / *Left of icon*), **Show label** (responsive show/hide), **Custom icon**, **Icon color**, **Icon size** (20), **Badge background**, **Badge text color**, **Badge typography** | Prints the current count on page load; the badge sits on the icon's top-right corner. |

### Bricks query type

**Wishlist** (in a query loop's *Type* list) returns every published product in the visitor's list, without pagination, and sets up the post and product for each item so product dynamic data and the Wishlist Button work inside the loop.

### Shortcodes

| Shortcode | Attributes | Output |
|---|---|---|
| `[alg_wishlist_button]` | `product_id` (current post), `text`, `class`, `color`, `hover_color`, `active_color`, `icon` (raw SVG), `action` (`toggle`, `add`, `remove`) | Heart `<button>`. The active state is set by the script after the page loads. |
| `[alg_wishlist_button_aws]` | `product_id` (current post), `action` | Link with the heart and the text "Add to wishlist" / "Remove from wishlist", active state set on the server, `data-type` `ADD` / `REMOVE`. |
| `[alg_wishlist_count]` | `class`, `color` (text colour), `icon_color`, `icon` (`heart` shows the configured icon; any other value shows none), `is_link` (`yes` links to the **Wishlist Page**, `no` renders a `<div>`) | Icon and count badge, hidden at 0. |
| `[alg_wishlist_page]` | none | Grid of saved products with image, × remove button, name, price and **Add to Cart** (WooCommerce's add-to-cart link for the product). Empty list: "Your wishlist is currently empty." and **Return to Shop**. |

## Data and uninstall

- Tables: `{prefix}alg_wishlists` (one row per list; `user_id` or `session_id`) and `{prefix}alg_wishlist_items` (`wishlist_id`, `product_id`, `variation_id`, `date_added`; one row per product per list). Options: `alg_wishlist_settings`, `alg_wishlist_version`, `alg_wishlist_db_version`.
- Transients: `alg_wl_icon_<id>` (icon cache, 1 day) and `alg_wl_rl_<hash>` (rate limit, 1 minute).
- Guest lists are never cleaned up; when a guest's cookie expires, their list stays in the table.
- If a table is missing, it is recreated the next time an admin page loads, and pending schema changes are applied after plugin updates.
- Deactivating the module changes nothing. On uninstall, wishlist data is removed **only** if the module is active and **Delete wishlist data on uninstall** is on: both tables and the three options are dropped. Otherwise everything is kept, and a later reinstall switches the module back on automatically because the tables exist.

## Troubleshooting

- **Hearts or the count show someone else's list, or clicks do nothing on some pages.** A page cache is serving HTML built for another visitor or with an expired token; see [Loading and caching](#loading-and-caching).
- **A heart fills, then empties again.** The request failed: rate limit reached (behind a proxy or CDN that hides visitor IPs, all guests can share one limit), security token expired, or the list is full on an add-only button. Check the browser console.
- **"Added to Wishlist" appears but the product is gone after a reload (guests).** The browser does not keep the `alg_wishlist_session` cookie, so every request starts a new guest list.
- **"Permission denied." when saving settings.** Saving needs `manage_options` (administrator); shop managers can open the page but not save it.
- **Custom label text on the Wishlist Button switches to "Add to wishlist" / "Remove from wishlist".** When the page loads (for saved products) and after each click, the script writes its own text into the button's label. Only the server-rendered state uses **Add text** / **Remove text**.
- **The counter badge hides at 0 even with Hide badge when zero off.** The script hides it at 0 after every update.
- **Custom CSS does not change the wishlist page cards or the message.** The built-in grid and card rules are printed after your CSS, and the message box uses inline styles. Use a more specific selector (and `!important` for `#alg-wishlist-toast`).
- **A custom SVG renders huge or blank.** Add a `viewBox` to the SVG. The `icon` attribute of `[alg_wishlist_button]` removes `viewBox`; use the **Custom Icon** setting instead.
- **No hearts on SnapFind results.** SnapFind's script must be on the page and the wishlist assets must not be switched off with the `ffla_wishlist_enqueue_assets` filter.
- **Hearts on SnapFind results show the wrong state after paging or filtering.** They are drawn from the list as it was when the page loaded; reload the page.

## For developers

- **Filters**

  | Filter | Arguments | Default | Use |
  |---|---|---|---|
  | `ffla_wishlist_enqueue_assets` | `bool` | `true` | Return `false` to stop loading the script and styles on the front end. |
  | `ffla_wishlist_force_enqueue_assets` | `bool` | `false` | Return `true` to load them even where they would not load (the module uses it for the Bricks builder). |
  | `alg_wishlist_rate_limit` | `int` | `60` | Requests allowed per IP and account before the one-minute block. |
  | `alg_wishlist_max_items` | `int` | `200` | Maximum products for add-only requests. |
  | `alg_wishlist_snapfind_boost_enabled` | `bool` (the setting) | setting | Force the SnapFind boost on or off. |

  The module also hooks SnapFind's `snapfind_formatted_document` filter to add `wishlist_count`.
- **AJAX** — `admin-ajax.php`, action `alg_add_to_wishlist`, guests and logged-in. Parameters: `nonce` (action `alg_wishlist_nonce`), `product_id`, optional `variation_id`, optional `todo` (`add`, `remove`; anything else toggles). Success: `{status: "added"|"removed", count}`. Errors: "Invalid Product ID", "Too many wishlist requests. Please wait a moment.", "Wishlist is full.".
- **JavaScript** — `window.AlgWishlist.toggle(element)` toggles the product in the element's `data-product-id` (honouring `data-todo`); call it from an `onclick` for buttons inside shadow DOM. Clicks on `.alg-add-to-wishlist`, `.aws-wishlist--trigger` and `.alg-remove-btn` are handled by delegation, so buttons added later work without setup. `AlgWishlist.updateCount(n)`, `markAsActive(id)`, `markAsInactive(id)` and `showToast(message)` are also available. Page data is in `window.AlgWishlistSettings` (`ajax_url`, `nonce`, `initial_items`, `i18n`). No custom events are fired.
- **PHP** — `Alg_Wishlist_Core::get_wishlist_items()` (IDs of published products in the current visitor's list), `is_in_wishlist( $product_id, $variation_id = 0 )`, `add_item( $product_id, $variation_id = 0 )` (returns `added`, `exists` or `false`), `remove_item( $product_id, $variation_id = 0 )`, `default_icon_svg( $css_class )`.
- **Bricks** — query type ID `alg_wishlist`; element names `ffla-wishlist-button` and `ffla-wishlist-count`.
- **CSS** — colours are exposed at `:root` as `--alg-btn-color`, `--alg-btn-hover-color`, `--alg-btn-active-color` (also `--alg-wishlist-primary`, `--alg-wishlist-active`). Main classes: `.alg-add-to-wishlist` (`.active` when saved), `.alg-wishlist-counter-link`, `.alg-wishlist-count` (`.hidden` at 0), `.alg-wishlist-grid`, `.alg-wishlist-card`, `.snaf-wishlist-btn`.
