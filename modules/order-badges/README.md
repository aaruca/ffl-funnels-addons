# Order Badges

Shows colour-coded badges on WooCommerce orders, taken from the product tags of the items, so staff can tell at a glance what an order contains: for example *Firearm*, *Ammo* or *In Store*, and *Online Only* for products that are not stocked in the shop. Badges appear on the Orders list and the Edit Order screen. Module ID: `order-badges`, off until it is switched on in **FFL Funnels → Dashboard**. Requires WooCommerce and at least one product tag.

> **Read this first.** Badges are worked out from each product's **current** tags every time an order is shown. Nothing is saved on the order, so retagging a product also changes the badges on its past orders.

## What it does

- **Badge tags**: any product tags you choose appear as badges, each in its own colour.
- **Online Only**: once you choose in-store tags, a product that carries none of them gets an **Online Only** badge.
- **Product Type** column on the Orders list (HPOS and legacy order storage).
- On the Edit Order screen: the order's badges under the order details, and each product line's own badges.

## Setup

1. In **Products → Tags**, create the tags you want to see (for example *In Store*, *Firearm*, *Ammo*) and tag your products. For variable products, tag the parent product: order lines are classified by the parent.
2. Switch on **Order Badges** in **FFL Funnels → Dashboard**, then open **FFL Funnels → Order Badges**.
3. **Badge tags**: choose the tags to show. A colour row appears for each; adjust the colours.
4. **In-store tags**: choose the tags that mean "physically in store", or leave it empty to never show Online Only. To also show an *In Store* badge, add that tag to **Badge tags** as well; in-store tags on their own produce no badge.
5. Pick the **Online Only badge colour** and click **Save Settings**.
6. Open **WooCommerce → Orders** to check the **Product Type** column.

## Settings

**FFL Funnels → Order Badges**. Both lists offer all product tags on the site, searchable when WooCommerce's select box script is available.

| Setting | What it does | Default |
|---|---|---|
| Badge tags | Product tags shown as badges. A product shows a badge for each of its tags selected here. | None |
| Colour (one row per badge tag) | Colour of that tag's badge. New rows take the next colour from the palette `#2271b1`, `#e02424`, `#0f766e`, `#7c3aed`, `#b45309`, `#be185d`, `#0891b2`, `#4d7c0f`. | Next palette colour |
| In-store tags | Tags that mean a product is in store. A product with none of them is Online Only. Leave empty to turn Online Only off. | None (Online Only off) |
| Online Only badge colour | Colour of the Online Only badge. | `#0f766e` |

- Colours must be hex (`#rgb` or `#rrggbb`). An invalid tag colour falls back to the palette; an invalid Online Only colour falls back to `#0f766e`.
- Only tags that still exist are saved. With no product tags on the site, the page shows "No product tags exist yet. Add product tags in Products → Tags, then choose them here." and no form.

## How it works

- **Per product line**: a badge for every selected badge tag the product has, plus Online Only when in-store tags are set and the product has none of them.
- **Per order**: all badges of its product lines combined, each once. Online Only shows when **any** product line is Online Only. Fees and shipping lines are ignored.
- **Variations** use the parent product's tags.
- **Deleted products** get no badge and do not count as Online Only.
- **Same label, one badge**: labels are compared ignoring case, spaces and punctuation, so *Online only*, *Online-Only* and the Online Only badge appear once (the first one keeps its colour). Accents, symbols and non-Latin letters are kept, so *Tienda Física* and *Tienda Fisica*, or *日本* and *中国*, stay separate.
- **Look**: a soft tint of the chosen colour with darker text in the same hue. Labels are shown exactly as the tag is written.
- **Speed**: the products and tags of an order are loaded in one go, and each product is classified once per page load.
- Badges are admin-only. Nothing is added to the storefront, My Account or emails.

## Where it shows up

| Place | What |
|---|---|
| **WooCommerce → Orders** (HPOS and legacy list) | **Product Type** column right after the order number column, with the order's badges. |
| Edit Order → order details | **Product Type** with the order's badges, under the General fields. Not shown when the order has no badges. |
| Edit Order → each product line | That product's badges, under the line's details. |
| **FFL Funnels → Order Badges** | Settings (needs the `manage_woocommerce` capability). |

## Data and uninstall

- One option, `ffla_order_badges_settings`. Nothing is written to orders, order items or products.
- Switching the module off keeps the settings, so switching it on again restores them.
- Deleting the plugin removes the option, whether or not the module is on.

## Troubleshooting

- **No Product Type column.** Check that the module is on and that the column is not unticked in **Screen Options** on the Orders screen.
- **An order shows no badges.** None of its products carry a selected badge tag, and either no in-store tags are set or every product has one. Lines whose product was deleted never get a badge.
- **Everything is Online Only.** The products (for variations, the parent product) do not carry any of the selected in-store tags.
- **No In Store badge.** In-store tags only decide Online Only; add the tag to **Badge tags** to display it.
- **Two tags show as one badge.** Their labels only differ by case, spacing or punctuation.
- **Old orders changed badges.** Expected: badges follow the products' current tags.

## For developers

- **No filters or actions** of its own. Hooks used: `manage_woocommerce_page_wc-orders_columns` / `manage_woocommerce_page_wc-orders_custom_column` (HPOS), `manage_edit-shop_order_columns` / `manage_shop_order_posts_custom_column` (legacy), `woocommerce_admin_order_data_after_order_details`, `woocommerce_after_order_itemmeta`, `admin_head` (styles), `admin_enqueue_scripts`. All are registered in wp-admin only.
- **Option shape** (`ffla_order_badges_settings`):
  ```
  badge_tags:   [term_id, …]          (product_tag IDs, in chosen order)
  colors:       { term_id: '#hex' }   (only for selected badge tags)
  instore_tags: [term_id, …]
  online_color: '#hex'
  ```
- **Column ID**: `ffla_order_badges`.
- **Markup**: `<span class="ffla-ob-badges">` wrapping `<span class="ffla-ob-badge" style="--ffla-c:#hex">Label</span>`; the order-details block is `p.ffla-ob-order-badge`. The styles are printed inline only on the `woocommerce_page_wc-orders`, `shop_order` and `edit-shop_order` screens.
- **Saving**: `admin-post.php` action `ffla_ob_save_settings`, nonce `ffla_ob_settings` in `_ffla_ob_nonce`, requires `manage_woocommerce`.
- **Settings assets**: script `ffla-order-badges-settings` (depends on `jquery`, `wp-color-picker`, and `selectWoo` when registered); `admin/css/order-badges-module.css` is loaded by the shared admin shell.
- **Tests**: `php tests/smoke/order-badges-smoke.php` (31 checks: save validation, classification, de-duplication, escaping, assets).
