# Pickup & Shipping: integration and QA

The module is registered but never automatically activated or configured. Production sites are not changed by building this code.

## Activation

1. Keep the site on its current release until staging verification is complete.
2. Enable **Pickup & Shipping** under FFL Funnels modules.
3. Select enabled shipping-zone method instances in General. Empty/incomplete settings leave checkout unchanged.
4. Select automatic placement or use `[ffla_delivery_choice]` inside the classic `form.checkout`. A shortcode outside the form cannot submit a choice.
5. Optionally enable FFL rules with g-FFL Checkout active. Add own license numbers and map each to an enabled pickup instance that is also selected in General.
6. Configure the existing provider's own local-pickup button separately. The addon does not mutate its stored options.
7. Disable the old Camarillo shipping snippet before enabling these rules. If taxes are in the same legacy snippet, separate them first; do not discard tax code.
8. Confirm pickup cost in WooCommerce. The addon never makes a method free.

## Site colors

Accent, background and text settings accept HEX colors or site CSS variables, such as `var(--primary)`, `var(--surface)` and `var(--text)`. A bare `--primary` is normalized to `var(--primary)`. Optional fallbacks work too: `var(--primary, #2271b1)` or `var(--primary, var(--brand, #2271b1))`.

References remain live, so changing the site's variables updates the checkout without saving the addon again. Blank settings retain the default site inheritance. Variables must resolve to full CSS colors, not partial RGB/HSL channel values. A theme variable defined only on the storefront cannot resolve in the admin preview; use an explicit fallback if needed. Theme styles are not loaded into wp-admin by this module.

## Support boundaries

- Classic checkout and compatible custom templates only. The standard WooCommerce checkout script/form and order-review fragments are required.
- Checkout Blocks and Store API requests are left untouched; an admin notice explains the limitation.
- Existing distinct FFL and customer packages are handled separately. An unsplit mixed package is rejected instead of choosing a destination for its items. No package splitting or inventory allocation is performed.
- Package classification uses parent-aware firearm flags, the provider's required-selector check and its state-compliance helper. The server filter `ffla_pickup_shipping_package_scope` can classify an existing package as `ffl`, `regular` or `mixed`. Integrators must preserve actual destinations and provider validation.
- License normalization accepts a full formatted/unformatted FFL number. It is identification matching, not license verification. The FFL provider retains its authorization/restriction checks.
- The g-FFL single-local-license option is overridden only during checkout validation, only when the posted license matches an explicitly configured own location. Nothing is written to the provider's options. Other validations remain installed.
- The addon does not override billing/shipping addresses, fees, tax addresses or tax calculations. Stores with per-package pickup/delivery taxation need the relevant provider integration verified separately.
- No cron jobs, schema changes, remote services or stored customer-address copies in module session state. Delivery/location snapshots are stored through shipping-item CRUD.

## Offline tests

```text
php tests/smoke/pickup-shipping-smoke.php
node tests/smoke/pickup-shipping-ui-smoke.js [screenshot-directory]
```

Browser tests require Playwright and installed Chrome (or set FFLA_TEST_BROWSER_CHANNEL).
Set FFLA_TEST_JQUERY to an existing WordPress jquery.min.js. The default temporary fixture is tmp/pickup-jquery.min.js; it is a test dependency, not a plugin asset.
The browser tests block all network requests and use synthetic PHP fixtures. They exercise the real UI JS/CSS with a simulated WooCommerce review transport, not a live payment flow.

## Staging acceptance matrix

- Guest and logged-in non-FFL order; no default, default shipping, default pickup; missing address and unavailable zones.
- Shipping-only, pickup-only and both; paid pickup must keep its price and tax.
- Own FFL A, own FFL B, external FFL, clearing/changing dealer; no fallback based on name/cookies.
- Variation firearm flags; provider state-compliance carts; provider-specific verified exemptions/selector overrides.
- Multiple regular packages, separated mixed packages, and unsupported unsplit mixed packages.
- Cart → checkout, address edits, coupons, quantity changes, emptied cart, retry after payment failure, restored checkout.
- Check actual server-selected methods match each package after AJAX, not only the visible cards.
- Confirm carrier quotes and taxable addresses remain correct for the site's configured shipping/tax providers.
- Metadata in administration, customer order view, HTML/plain-text emails and HPOS-enabled orders.
- Classic custom templates: shortcode inside form, correct checkout script, no duplicate selector, no old snippet active.
- Disable the module: existing WooCommerce delivery flow remains available.
