# Smart Coupons

Extends WooCommerce coupons for FFL stores. It works on the regular WooCommerce coupon screens, the classic cart and checkout, and the Cart / Checkout blocks (Store API). Module ID: `smart-coupons`, off until it is switched on in **FFL Funnels → Dashboard**.

> **Read this first.** With the module on, **firearms are left out of every coupon by default** (setting *Never discount firearms*). Existing coupons keep working on everything else. Store credit is never limited. Turn the setting off, or allow it per coupon or per category, if your agreements let you discount firearms.

## Where things are

| Screen | What it does |
|---|---|
| **Marketing → Coupons** → edit a coupon → **Smart Coupons** tab | Per-coupon options (below). |
| **Marketing → Coupon categories** | Categories, colours and their rules. |
| **FFL Funnels → Smart Coupons → Coupon Settings** | Store-wide guardrails, links, stacking, guessing protection, store credit defaults. |
| **… → Bulk Codes** | Generate single-use codes from a template coupon, download CSV. |
| **… → Store Credit** | Issue credit and see every balance. |
| **… → Coupon Report** | Orders, revenue, discount cost and new customers by coupon and category. |
| **Product data → General** | *Minimum price (MAP)*, also on each variation. |

## Guardrails

- **Never discount firearms** (on by default). Products with `_firearm_product = yes` (the flag g-FFL Checkout uses) are left out of every coupon, including fixed-cart coupons. Add more under **Also never discount** (categories include their subcategories; tags), e.g. NFA items, transfer fees, special orders.
  - A coupon can opt in with **This coupon may discount firearms and protected items**, or through its category (*Store credit* is seeded that way).
  - A cart holding only protected items gets "Coupons cannot be used on firearms or the other items in your cart."
- **Respect minimum (MAP) prices** (on by default). No coupon, or several coupons together, takes a product below its *Minimum price (MAP)*; the rest of that line's discount is dropped. Fixed spend-tier amounts move what MAP holds back onto other qualifying items.
- **Maximum discount** per coupon (e.g. 20% off up to $100), and per category. The smaller one applies.

## Which products (Smart Coupons tab)

One rule, read top to bottom:

```
(Categories: Any | All)   AND | OR   (Tags: Any | All)      then      Never for: categories, tags
```

| Part | Behaviour |
|---|---|
| Categories + **Any / All** | **Any** (default): in at least one, *Rifles* or *Shotguns*. **All**: in every one, *Rifles* + *Used Guns* = used rifles only. Subcategories count (a used rifle in *Bolt Action* under *Rifles* is in *Rifles*). Categories are listed as *Parent › Child*. |
| **AND / OR** | Used when both categories and tags are set. **AND** (default): both must match. **OR**: either is enough, e.g. *Rifles* OR tagged *Sale*. |
| Tags + **Any / All** | Products with **any** (default) or **all** of these tags. Tags are searched as you type. |
| **Never for** | Products in any of these categories (subcategories count) or with any of these tags are left out, even when they match above, e.g. *NFA* or *Consignment*. |

- A side left empty is not part of the rule; leave everything empty for every product.
- WooCommerce's own **Usage restriction** (products, categories, exclusions, *Exclude sale items*) still applies on top.
- The tab shows the whole rule as a live summary, including what the Usage restriction tab adds and whether firearms are left out by the guardrail.
- Applies to every discount type: percentage and fixed product per line, fixed cart spread only over matching items, spend tiers counted only on matching items, buy X get Y.
- If nothing in the cart matches, the customer is told why: "This coupon is only for products in Rifles or Shotguns and tagged Sale." / "…in Rifles, or tagged Sale." / "This coupon cannot be used on products tagged Consignment." Matching items that are firearms still need the coupon (or its category) to allow firearms — otherwise the firearm message is shown.
- Coupons saved before 1.55.2 with **In all of these categories** open as Categories + **All** and behave as before; **With tags**, **Tag match** and **Without tags** carry over unchanged.

## Conditions (Smart Coupons tab → When it works)

| Option | Behaviour |
|---|---|
| Starts on | Not usable before this date (store time). The end is WooCommerce's expiry date. |
| First order only | No previous paid order by account or billing email. |
| Only for | Customer roles; "Guests" is a choice. Guests are asked to sign in. |
| Uses per person | Counted across orders by **email** (Gmail dots and `+tags` ignored), **phone** (last 10 digits) and **shipping address** (address line + 5-digit ZIP), so a new account does not get it again. Checked in the cart once the customer is known and again when the order is placed. |
| Minimum quantity / Counted from | e.g. at least 3 items from Ammunition. |
| Delivery | In-store pickup only, or shipped orders only. |
| States | Two-letter codes of the shipping (else billing) state. |
| Payment methods | e.g. a cash / ACH discount. Checked when the order is placed, so changing the payment method afterwards cannot keep the discount. |

## Discount types

Added to **Discount type** in the General tab:

- **Spend tiers** — one line per tier, `spend=discount`: `500=25` is $25 off from $500, `2000=10%` is 10% off from $2,000. The highest tier reached applies, on qualifying (non-protected) items. Below the first tier the customer sees "Spend $X more…".
- **Buy X get Y** — buy N from categories or products, get M at a percentage off (100 = free) from the same or other items. The cheapest qualifying units are discounted; **Repeat** applies it again for every X bought. Too few items: "Add N qualifying items…".
- **Free gift** (any discount type) — a product (not a firearm) added to the cart at $0 while the coupon is applied, and removed with it. Use a $0 amount for a gift-only coupon.

## Combining coupons

- Per coupon: **Combine with** *any other coupon*, *no other coupon*, or *only the coupons listed*. Store credit always combines.
- A coupon is only checked against coupons applied before it, so two coupons never knock each other out.
- **Best discount wins** (setting, off by default): instead of an error, keep whichever coupon gives the bigger discount and tell the customer.

## Coupon categories

Seeded once: Promotions, Email campaigns, Social & influencers, Gun shows & events, Partners & ranges, Employees (*one per order*), Loyalty & members, Customer service, Store credit (*firearms allowed*). Rename, delete or add your own; they can be nested.

- **Colour** badge in the coupon list, a **category filter**, and assignment from the coupon screen, **Quick edit** or **Bulk edit**.
- **Rules**, shown in the Rules column:
  - **One per order** — only one coupon from the category per order.
  - **Cannot be combined with** other categories — works both ways and is saved on both categories.
  - **Maximum discount per coupon**, **Firearms and protected items** allowed, **Only for** roles.
  - **Default expiry (days)** — set when a coupon in the category is saved without an expiry date, and on generated codes.
  - **Monthly budget** — total discount the category may give per calendar month (store time). Counted from the coupon lines of paid, processing and on-hold orders, so it is current as soon as an order is placed (cached up to 10 minutes, cleared whenever an order changes status). Once it is reached the category's coupons answer "This promotion has reached its limit for this month."
- A coupon in several categories follows all of them: any "allowed" wins, the smallest cap applies, lists are combined.
- Store credit is filed under *Store credit*; Bulk Codes can add a category to every generated code.

## Store credit

A fixed-cart coupon tied to the customer's email, with a running balance:

- Issue it from **Store Credit** (email, amount, validity, internal reason, optional message, email the code), or when closing a customer request with the **Store credit** resolution (enter the amount; the code goes into the resolution note and the customer's email).
- Used across orders: the order deducts what it used; a cancelled, failed or refunded order gives it back; it expires when it reaches $0 or after the validity (default 365 days, setting).
- Never limited by the firearm guardrails or MAP — it is a payment, not a price cut.
- Signed-in customers see their balance on the My Account dashboard and an **Apply it** button above the cart and checkout (classic and blocks).

## Bulk codes and links

- **Bulk Codes** generates up to 500 unique codes (prefix + 6–16 random characters without look-alikes such as 0/O, 1/I) from a template coupon: discount, restrictions, Smart Coupons options and categories are copied; uses per code, expiry and an extra category are set on the screen. Each batch can be downloaded as CSV with its usage.
- **Coupon links**: any URL with `?coupon=CODE` applies the coupon — right away when the cart has items, otherwise when the first product is added — and redirects to the clean URL. Each coupon shows its link with a Copy button. The parameter name is a setting.

## Guessing protection

**Block coupon guessing** (on by default): after too many unknown codes from one visitor in 10 minutes (default 10), every coupon attempt from that visitor is refused for 10 minutes with the same message, valid codes included, so codes cannot be found by trial and error.

## Report

**Coupon Report** (last 30 / 90 days / 12 months): orders with a coupon and their share, discount given, revenue, average order with and without a coupon, new customers, then tables by category (with the month's budget meter) and by coupon. It reads WooCommerce Analytics' order tables, which WooCommerce fills in the background, so the newest orders can take a few minutes to appear.

## For developers

- Filters: `ffla_coupons_is_protected` (`bool`, `WC_Product`), `ffla_coupons_map_price` (`float`, `WC_Product`), `ffla_coupons_is_pickup` (`bool`, chosen shipping methods), `ffla_coupons_client_ip` (visitor IP used for guessing protection, e.g. behind a proxy).
- Action: `ffla_store_credit_created` (`WC_Coupon`, email, amount, args).
- Per-coupon options are one meta array, `_ffla_coupon`; category rules are term meta `_ffla_rules` on the `ffla_coupon_cat` taxonomy; MAP is `_ffla_map_price`; store credit coupons carry `_ffla_credit = yes`.
- Uninstall removes the settings, batch list and caches. Coupons, store credits, categories and their options stay with WooCommerce.

## Verification

Tested on WordPress 7.1 + WooCommerce 10.2 (posts order storage) with Store API cart/checkout and the classic screens: guardrails and MAP, the AND / OR product rule (any / all categories and tags, never for, old options), every condition, both custom types, gifts, stacking and best-wins, per-person limits, categories (one per order, no-combine both ways, monthly budget), store credit (partial use, restore on cancel, email lock, request close), bulk codes, links, throttling, the report, and the admin screens in a browser.
